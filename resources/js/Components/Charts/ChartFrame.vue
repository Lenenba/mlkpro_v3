<script setup>
import { computed, useId } from 'vue';
import { useI18n } from 'vue-i18n';
import ChartDataTable from '@/Components/Charts/ChartDataTable.vue';
import { hasChartData } from '@/utils/chartTheme';

const props = defineProps({
    title: {
        type: String,
        default: '',
    },
    subtitle: {
        type: String,
        default: '',
    },
    periodLabel: {
        type: String,
        default: '',
    },
    series: {
        type: Array,
        default: () => [],
    },
    categories: {
        type: Array,
        default: () => [],
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
    showDataTable: {
        type: Boolean,
        default: true,
    },
    tableOpenByDefault: {
        type: Boolean,
        default: false,
    },
    tableCaption: {
        type: String,
        default: '',
    },
    categoryLabel: {
        type: String,
        default: '',
    },
    valueLabel: {
        type: String,
        default: '',
    },
    unitLabel: {
        type: String,
        default: '',
    },
    valueFormatter: {
        type: Function,
        default: null,
    },
    framed: {
        type: Boolean,
        default: true,
    },
});

const { t } = useI18n();
const identifier = useId().replaceAll(':', '');
const titleId = `chart-title-${identifier}`;
const subtitleId = `chart-subtitle-${identifier}`;
const periodId = `chart-period-${identifier}`;
const hasData = computed(() => hasChartData(props.series));
const resolvedTitle = computed(() => props.title || t('charts.default_title'));
const resolvedLoadingMessage = computed(() => props.loadingMessage || t('charts.loading'));
const resolvedEmptyMessage = computed(() => props.emptyMessage || t('charts.empty'));
const resolvedErrorMessage = computed(() => {
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
const describedBy = computed(() => [
    props.subtitle ? subtitleId : null,
    props.periodLabel ? periodId : null,
].filter(Boolean).join(' ') || undefined);
const resolvedTableCaption = computed(() => props.tableCaption
    || t('charts.named_data_table_caption', { title: resolvedTitle.value }));
</script>

<template>
    <figure
        class="min-w-0"
        :class="framed
            ? 'grid gap-4 rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900'
            : 'grid gap-3'"
        :aria-labelledby="titleId"
        :aria-describedby="describedBy"
        :aria-busy="loading ? 'true' : undefined"
    >
        <figcaption class="flex min-w-0 flex-wrap items-start justify-between gap-3">
            <div class="min-w-0 flex-[1_1_16rem]">
                <h3 :id="titleId" class="break-words text-sm font-semibold text-stone-800 dark:text-neutral-100">
                    {{ resolvedTitle }}
                </h3>
                <p
                    v-if="subtitle"
                    :id="subtitleId"
                    class="mt-1 break-words text-xs leading-5 text-stone-500 dark:text-neutral-400"
                >
                    {{ subtitle }}
                </p>
                <p
                    v-if="periodLabel"
                    :id="periodId"
                    class="mt-1 text-[11px] font-medium text-stone-500 dark:text-neutral-400"
                >
                    {{ periodLabel }}
                </p>
            </div>

            <div v-if="$slots.action" class="shrink-0">
                <slot name="action"></slot>
            </div>
        </figcaption>

        <div v-if="$slots.legend && !loading && !resolvedErrorMessage && hasData" class="min-w-0">
            <slot name="legend"></slot>
        </div>

        <div
            v-if="loading"
            class="flex min-h-48 items-center justify-center rounded-sm bg-stone-50 dark:bg-neutral-800"
            role="status"
            aria-live="polite"
        >
            <span class="sr-only">{{ resolvedLoadingMessage }}</span>
            <span class="h-32 w-full rounded-sm bg-stone-100 motion-safe:animate-pulse dark:bg-neutral-700" aria-hidden="true"></span>
        </div>

        <div
            v-else-if="resolvedErrorMessage"
            class="flex min-h-48 items-center justify-center rounded-sm border border-rose-200 bg-rose-50 px-4 text-center text-sm text-rose-700 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-200"
            role="alert"
        >
            {{ resolvedErrorMessage }}
        </div>

        <div
            v-else-if="!hasData"
            class="flex min-h-48 items-center justify-center rounded-sm bg-stone-50 px-4 text-center text-sm text-stone-500 dark:bg-neutral-800 dark:text-neutral-400"
            role="status"
        >
            {{ resolvedEmptyMessage }}
        </div>

        <div v-else class="min-w-0">
            <slot :has-data="hasData"></slot>
        </div>

        <ChartDataTable
            v-if="showDataTable && hasData && !loading && !resolvedErrorMessage"
            :categories="categories"
            :series="series"
            :caption="resolvedTableCaption"
            :category-label="categoryLabel"
            :value-label="valueLabel"
            :unit-label="unitLabel"
            :formatter="valueFormatter"
            :open-by-default="tableOpenByDefault"
            :chart-title="resolvedTitle"
        />

        <div v-if="$slots.footer" class="min-w-0">
            <slot name="footer"></slot>
        </div>
    </figure>
</template>
