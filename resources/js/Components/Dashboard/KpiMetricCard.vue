<script setup>
import { computed, defineAsyncComponent } from 'vue';
import KpiTrendBadge from '@/Components/Dashboard/KpiTrendBadge.vue';
import { buildKpiProgress } from '@/utils/kpi';
import { resolveKpiChartTone } from '@/utils/kpiTone';

const KpiMiniChart = defineAsyncComponent(() => import('@/Components/Dashboard/KpiMiniChart.vue'));

const props = defineProps({
    metric: {
        type: Object,
        required: true,
    },
    compact: {
        type: Boolean,
        default: false,
    },
    variant: {
        type: String,
        default: 'module',
    },
});

const emit = defineEmits(['activate']);

const tones = 'amber blue cyan emerald fuchsia green indigo lime orange red rose sky slate stone teal violet'.split(' ');

const interactive = computed(() => Boolean(props.metric?.interactive));
const rootElement = computed(() => interactive.value ? 'button' : 'div');
const hasContext = computed(() => String(props.metric?.context ?? '').trim() !== '');
const colorClass = computed(() => {
    const tone = props.metric?.tone;

    return props.metric?.colorClass
        || (tones.includes(tone) ? `bg-${tone}-600` : 'bg-stone-400/70 dark:bg-neutral-500/50');
});
const progress = computed(() => {
    const source = props.metric?.progress;
    return buildKpiProgress(
        source?.value ?? source,
        source?.max ?? 100,
        source?.label,
    );
});
const chartTone = computed(() => resolveKpiChartTone(props.metric?.tone));
const progressTrackStyle = {
    backgroundColor: 'var(--chart-grid)',
};
const progressFillStyle = computed(() => ({
    backgroundColor: `var(--chart-series-${chartTone.value})`,
    width: `${(progress.value?.value / progress.value?.max) * 100}%`,
}));
const hasMiniChartCandidate = computed(() => Boolean(props.metric?.chart)
    || (Array.isArray(props.metric?.points) && props.metric.points.length >= 4));
const showMiniChart = computed(() => !props.metric?.loading
    && props.variant === 'dashboard'
    && hasMiniChartCandidate.value);
const showProgress = computed(() => !props.metric?.loading
    && props.variant !== 'record'
    && progress.value);
const activate = () => {
    if (interactive.value && !props.metric?.disabled) {
        emit('activate', props.metric?.action ?? props.metric);
    }
};
</script>

<template>
    <component
        :is="rootElement"
        :type="interactive ? 'button' : undefined"
        :data-testid="metric.testId"
        :data-measurement-status="metric.measurementStatus"
        class="flex h-full min-w-0 flex-col overflow-hidden bg-white text-start dark:bg-neutral-800"
        :class="[
            variant === 'dashboard'
                ? (compact ? 'min-h-32' : 'min-h-40')
                : (compact ? 'min-h-24' : 'min-h-28'),
            variant === 'dashboard'
                ? 'rounded-lg border border-stone-200 shadow-sm dark:border-neutral-700'
                : variant === 'record'
                    ? ''
                    : 'rounded-md border border-stone-200 dark:border-neutral-700',
            interactive
                ? 'focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-green-600'
                : '',
            metric.active
                ? 'ring-2 ring-inset ring-green-600'
                : '',
        ]"
        :disabled="interactive ? Boolean(metric.disabled) : undefined"
        :aria-label="interactive ? metric.ariaLabel : undefined"
        :aria-pressed="interactive && metric.active !== undefined ? String(Boolean(metric.active)) : undefined"
        :aria-busy="metric.loading ? 'true' : undefined"
        @click="activate"
    >
        <div :class="variant === 'dashboard' && !compact ? 'p-4' : 'p-3'">
            <div class="grid min-w-0 grid-cols-[auto_minmax(0,1fr)] gap-3">
                <span
                    :class="[
                        colorClass,
                        variant === 'dashboard'
                            ? 'w-1 rounded-full'
                            : variant === 'record'
                                ? 'mt-1 size-2 rounded-sm'
                                : 'mt-1 size-2 rounded-full',
                    ]"
                    aria-hidden="true"
                ></span>

                <div class="min-w-0">
                    <p
                        class="line-clamp-2 min-w-0 break-words min-h-8 text-xs font-medium leading-4 text-stone-500 [hyphens:auto] [overflow-wrap:anywhere] dark:text-neutral-400"
                        :title="metric.label"
                    >
                        {{ metric.label }}
                    </p>

                    <div
                        v-if="metric.loading"
                        class="mt-1.5 h-6 w-24 max-w-full motion-safe:animate-pulse rounded-sm bg-stone-200 dark:bg-neutral-700"
                        aria-hidden="true"
                    ></div>

                    <div v-else class="mt-1.5 flex min-h-7 min-w-0 flex-wrap items-center gap-2">
                        <p
                            class="max-w-full whitespace-normal break-words font-semibold leading-7 tabular-nums text-stone-800 [overflow-wrap:anywhere] dark:text-neutral-100"
                            :class="variant === 'dashboard' && !compact ? 'text-xl sm:text-2xl' : variant === 'record' ? 'text-lg' : 'text-xl'"
                        >
                            {{ metric.value }}
                        </p>
                        <KpiTrendBadge v-if="metric.trend" class="shrink-0 whitespace-nowrap" :trend="metric.trend" />
                    </div>
                </div>
            </div>
        </div>

        <div
            v-if="hasContext || showMiniChart || showProgress"
            class="mt-auto border-t border-stone-100 dark:border-neutral-700"
            :class="[
                variant === 'dashboard'
                    ? 'bg-stone-50 dark:bg-neutral-900'
                    : '',
                variant === 'dashboard' && !compact ? 'px-4 py-3' : 'px-3 py-2.5',
            ]"
        >
            <p
                v-if="hasContext"
                class="break-words text-stone-500 [overflow-wrap:anywhere] dark:text-neutral-400"
                :class="compact || variant !== 'dashboard'
                    ? 'text-[11px] leading-4 line-clamp-2'
                    : 'text-xs leading-5 line-clamp-2'"
                :title="String(metric.context)"
            >
                {{ metric.context }}
            </p>

            <div
                v-if="showMiniChart"
                :class="hasContext ? 'mt-2' : ''"
                class="h-10"
            >
                <KpiMiniChart
                    class="h-full"
                    :label="metric.label"
                    :chart="metric.chart"
                    :points="metric.points"
                    :tone="metric.tone"
                />
            </div>

            <div
                v-else-if="showProgress"
                class="flex"
                :class="[
                    variant === 'dashboard' ? 'h-10 items-end' : 'h-3 items-center',
                    hasContext ? 'mt-2' : '',
                ]"
                role="progressbar"
                aria-valuemin="0"
                :aria-valuemax="progress.max"
                :aria-valuenow="progress.value"
                :aria-label="progress.label || metric.label"
            >
                <span
                    class="block h-2 w-full overflow-hidden rounded-sm"
                    :style="progressTrackStyle"
                    aria-hidden="true"
                >
                    <span
                        class="block h-full rounded-sm"
                        :style="progressFillStyle"
                    ></span>
                </span>
            </div>
        </div>
    </component>
</template>
