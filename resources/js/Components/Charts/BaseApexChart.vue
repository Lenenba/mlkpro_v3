<script setup>
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';
import { useI18n } from 'vue-i18n';
import {
    buildChartThemeOptions,
    hasChartData,
    mergeChartOptions,
    resolveChartSeriesColors,
    resolveChartTheme,
} from '@/utils/chartTheme';
import { createChartSynchronization } from '@/utils/chartSynchronization';

const props = defineProps({
    series: {
        type: Array,
        default: () => [],
    },
    categories: {
        type: Array,
        default: () => [],
    },
    options: {
        type: Object,
        default: () => ({}),
    },
    colors: {
        type: Array,
        default: () => [],
    },
    colorTones: {
        type: Array,
        default: () => [],
    },
    type: {
        type: String,
        default: 'line',
    },
    height: {
        type: [Number, String],
        default: 300,
    },
    loading: {
        type: Boolean,
        default: false,
    },
    error: {
        type: [Boolean, String, Error],
        default: false,
    },
    loadingMessage: {
        type: String,
        default: '',
    },
    emptyMessage: {
        type: String,
        default: '',
    },
    errorMessage: {
        type: String,
        default: '',
    },
});

const emit = defineEmits(['ready', 'render-error']);
const { t } = useI18n();
const chartElement = ref(null);
const internalError = ref('');
const themeRevision = ref(0);

let apexChartsPromise = null;
let chartInstance = null;
let renderedType = null;
let isUnmounted = false;
let appearanceObserver = null;
let reducedMotionQuery = null;

const loadApexCharts = async () => {
    if (typeof window === 'undefined') {
        return null;
    }

    if (!apexChartsPromise) {
        apexChartsPromise = import('apexcharts')
            .then((module) => module.default)
            .catch((error) => {
                apexChartsPromise = null;
                throw error;
            });
    }

    return apexChartsPromise;
};

const externalErrorMessage = computed(() => {
    if (!props.error) {
        return '';
    }

    if (props.error instanceof Error) {
        return props.error.message || props.errorMessage || t('charts.error');
    }

    return typeof props.error === 'string'
        ? props.error
        : props.errorMessage || t('charts.error');
});
const resolvedErrorMessage = computed(() => internalError.value || externalErrorMessage.value);
const hasData = computed(() => hasChartData(props.series));
const shouldRender = computed(() => !props.loading && !resolvedErrorMessage.value && hasData.value);
const resolvedLoadingMessage = computed(() => props.loadingMessage || t('charts.loading'));
const resolvedEmptyMessage = computed(() => props.emptyMessage || t('charts.empty'));
const containerHeight = computed(() => typeof props.height === 'number'
    ? `${props.height}px`
    : props.height);
const theme = computed(() => {
    themeRevision.value;

    return resolveChartTheme();
});
const resolvedColors = computed(() => props.colors.length
    ? props.colors
    : resolveChartSeriesColors(props.colorTones, theme.value.palette));
const resolvedOptions = computed(() => mergeChartOptions(
    buildChartThemeOptions({
        type: props.type,
        height: props.height,
        theme: theme.value,
    }),
    props.options,
    {
        chart: {
            type: props.type,
            height: props.height,
        },
        series: props.series,
    },
    props.categories.length
        ? {
            xaxis: {
                categories: props.categories,
            },
        }
        : {},
    resolvedColors.value.length ? { colors: resolvedColors.value } : {},
));

const destroyChart = () => {
    if (chartInstance) {
        chartInstance.destroy();
        chartInstance = null;
    }

    renderedType = null;
};

const recordRenderError = (error, failedChart = null) => {
    failedChart?.destroy();
    internalError.value = props.errorMessage || t('charts.error');
    destroyChart();
    emit('render-error', error);
};

const createChart = async () => {
    destroyChart();
    await nextTick();

    if (isUnmounted || !shouldRender.value || !chartElement.value) {
        return;
    }

    const nextType = props.type;
    const nextOptions = resolvedOptions.value;
    const nextElement = chartElement.value;
    let nextChart = null;

    try {
        const ApexCharts = await loadApexCharts();

        if (!ApexCharts || isUnmounted || !shouldRender.value || chartElement.value !== nextElement) {
            return;
        }

        nextChart = new ApexCharts(nextElement, nextOptions);
        await nextChart.render();

        if (isUnmounted || !shouldRender.value || chartElement.value !== nextElement || props.type !== nextType) {
            nextChart.destroy();

            return;
        }

        chartInstance = nextChart;
        renderedType = nextType;
        emit('ready', nextChart);
    } catch (error) {
        if (isUnmounted) {
            nextChart?.destroy();

            return;
        }

        recordRenderError(error, nextChart);
    }
};

const synchronizeChart = async () => {
    if (!shouldRender.value) {
        destroyChart();

        return;
    }

    if (!chartInstance || renderedType !== props.type) {
        await createChart();

        return;
    }

    try {
        await chartInstance.updateOptions(
            resolvedOptions.value,
            false,
            !theme.value.isReducedMotion,
            true,
        );
    } catch (error) {
        if (!isUnmounted) {
            recordRenderError(error);
        }
    }
};

const chartSynchronization = createChartSynchronization(synchronizeChart);

const retryChart = () => {
    internalError.value = '';
    void chartSynchronization.request();
};

const refreshAppearance = () => {
    themeRevision.value += 1;
};

const addAppearanceListeners = () => {
    const root = document.documentElement;

    appearanceObserver = new MutationObserver(refreshAppearance);
    appearanceObserver.observe(root, {
        attributes: true,
        attributeFilter: ['class', 'data-contrast', 'data-reduce-motion'],
    });

    window.addEventListener('on-hs-appearance-change', refreshAppearance);

    if (typeof window.matchMedia === 'function') {
        reducedMotionQuery = window.matchMedia('(prefers-reduced-motion: reduce)');

        if (typeof reducedMotionQuery.addEventListener === 'function') {
            reducedMotionQuery.addEventListener('change', refreshAppearance);
        } else if (typeof reducedMotionQuery.addListener === 'function') {
            reducedMotionQuery.addListener(refreshAppearance);
        }
    }
};

const removeAppearanceListeners = () => {
    appearanceObserver?.disconnect();
    appearanceObserver = null;
    window.removeEventListener('on-hs-appearance-change', refreshAppearance);

    if (typeof reducedMotionQuery?.removeEventListener === 'function') {
        reducedMotionQuery.removeEventListener('change', refreshAppearance);
    } else if (typeof reducedMotionQuery?.removeListener === 'function') {
        reducedMotionQuery.removeListener(refreshAppearance);
    }

    reducedMotionQuery = null;
};

watch(
    () => [
        props.series,
        props.categories,
        props.options,
        props.colors,
        props.colorTones,
        props.type,
        props.height,
    ],
    () => {
        internalError.value = '';
    },
    { deep: true },
);

watch(
    [resolvedOptions, shouldRender],
    () => {
        void chartSynchronization.request();
    },
    { deep: true, flush: 'post' },
);

onMounted(() => {
    addAppearanceListeners();
    void chartSynchronization.request();
});

onBeforeUnmount(() => {
    isUnmounted = true;
    chartSynchronization.dispose();
    removeAppearanceListeners();
    destroyChart();
});
</script>

<template>
    <div class="min-w-0" :style="{ minHeight: containerHeight }">
        <div
            v-if="loading"
            class="flex h-full min-h-40 items-center justify-center rounded-sm bg-stone-50 dark:bg-neutral-900"
            role="status"
            aria-live="polite"
        >
            <span class="sr-only">{{ resolvedLoadingMessage }}</span>
            <span class="h-24 w-full rounded-sm bg-stone-100 motion-safe:animate-pulse dark:bg-neutral-800" aria-hidden="true"></span>
        </div>

        <div
            v-else-if="resolvedErrorMessage"
            class="flex h-full min-h-40 flex-col items-center justify-center gap-3 rounded-sm border border-rose-200 bg-rose-50 px-4 text-center text-sm text-rose-700 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-200"
            role="alert"
        >
            <span>{{ resolvedErrorMessage }}</span>
            <button
                v-if="internalError"
                type="button"
                class="inline-flex items-center rounded-sm border border-rose-300 bg-white px-3 py-1.5 text-xs font-semibold text-rose-700 hover:bg-rose-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rose-600 dark:border-rose-500/40 dark:bg-neutral-900 dark:text-rose-200 dark:hover:bg-rose-500/10"
                @click="retryChart"
            >
                {{ $t('charts.retry') }}
            </button>
        </div>

        <div
            v-else-if="!hasData"
            class="flex h-full min-h-40 items-center justify-center rounded-sm bg-stone-50 px-4 text-center text-sm text-stone-500 dark:bg-neutral-900 dark:text-neutral-400"
            role="status"
        >
            {{ resolvedEmptyMessage }}
        </div>

        <div v-else ref="chartElement" class="min-w-0" aria-hidden="true"></div>
    </div>
</template>
