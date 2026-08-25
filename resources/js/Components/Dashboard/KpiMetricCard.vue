<script setup>
import { computed } from 'vue';
import KpiSparkline from '@/Components/Dashboard/KpiSparkline.vue';
import KpiTrendBadge from '@/Components/Dashboard/KpiTrendBadge.vue';
import { buildKpiProgress } from '@/utils/kpi';

const props = defineProps({
    metric: {
        type: Object,
        required: true,
    },
    compact: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['activate']);

const toneClasses = {
    amber: 'bg-amber-500',
    blue: 'bg-blue-600',
    cyan: 'bg-cyan-600',
    emerald: 'bg-emerald-600',
    fuchsia: 'bg-fuchsia-600',
    green: 'bg-green-600',
    indigo: 'bg-indigo-600',
    lime: 'bg-lime-600',
    orange: 'bg-orange-500',
    red: 'bg-red-600',
    rose: 'bg-rose-600',
    sky: 'bg-sky-600',
    slate: 'bg-slate-500',
    stone: 'bg-stone-500 dark:bg-neutral-500',
    teal: 'bg-teal-600',
    violet: 'bg-violet-600',
};

const interactive = computed(() => Boolean(props.metric?.interactive));
const rootElement = computed(() => interactive.value ? 'button' : 'article');
const hasContext = computed(() => String(props.metric?.context ?? '').trim() !== '');
const colorClass = computed(() => (
    props.metric?.colorClass
    || toneClasses[props.metric?.tone]
    || 'bg-stone-400/70 dark:bg-neutral-500/50'
));
const progress = computed(() => {
    const source = props.metric?.progress;
    return buildKpiProgress(
        source?.value ?? source,
        source?.max ?? 100,
        source?.label,
    );
});
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
        class="flex h-full min-w-0 flex-col overflow-hidden rounded-lg border border-stone-200 bg-white text-start shadow-sm transition dark:border-neutral-700 dark:bg-neutral-800"
        :class="[
            compact ? 'min-h-32' : 'min-h-40',
            interactive
                ? 'hover:border-stone-300 hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-green-600 focus-visible:ring-offset-2 motion-reduce:transition-none dark:hover:border-neutral-600 dark:focus-visible:ring-offset-neutral-900'
                : '',
            metric.active
                ? 'ring-2 ring-green-600 ring-offset-2 dark:ring-green-400 dark:ring-offset-neutral-900'
                : '',
        ]"
        :disabled="interactive ? Boolean(metric.disabled) : undefined"
        :aria-label="interactive ? metric.ariaLabel : undefined"
        :aria-pressed="interactive && metric.active !== undefined ? String(Boolean(metric.active)) : undefined"
        :aria-busy="metric.loading ? 'true' : undefined"
        @click="activate"
    >
        <div :class="compact ? 'p-3' : 'p-4'">
            <div class="grid min-w-0 grid-cols-[auto_minmax(0,1fr)] gap-3">
                <span
                    class="w-1 rounded-full"
                    :class="colorClass"
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
                        class="mt-1.5 h-6 w-24 max-w-full animate-pulse rounded-sm bg-stone-200 dark:bg-neutral-700"
                        aria-hidden="true"
                    ></div>

                    <div v-else class="mt-1.5 flex min-h-7 min-w-0 flex-wrap items-center gap-2">
                        <p
                            class="max-w-full whitespace-normal break-words font-semibold leading-7 tabular-nums text-stone-800 [overflow-wrap:anywhere] dark:text-neutral-100"
                            :class="compact ? 'text-lg' : 'text-xl sm:text-2xl'"
                        >
                            {{ metric.value }}
                        </p>
                        <KpiTrendBadge v-if="metric.trend" class="shrink-0 whitespace-nowrap" :trend="metric.trend" />
                    </div>
                </div>
            </div>
        </div>

        <div
            v-if="hasContext || metric.points?.length || progress"
            class="mt-auto border-t border-stone-100 bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900"
            :class="compact ? 'px-3 py-2.5' : 'px-4 py-3'"
        >
            <p
                class="break-words text-stone-500 [overflow-wrap:anywhere] dark:text-neutral-400"
                :class="[
                    compact
                        ? 'min-h-4 text-[11px] leading-4 line-clamp-1'
                        : 'min-h-10 text-xs leading-5 line-clamp-2',
                    hasContext ? '' : 'invisible',
                ]"
                :title="hasContext ? String(metric.context) : undefined"
                :aria-hidden="hasContext ? undefined : 'true'"
            >
                {{ hasContext ? metric.context : '—' }}
            </p>

            <KpiSparkline
                v-if="metric.points?.length"
                class="mt-2"
                :points="metric.points"
                :color-class="colorClass"
            />

            <div
                v-else-if="progress"
                class="flex h-10 items-end"
                role="progressbar"
                aria-valuemin="0"
                :aria-valuemax="progress.max"
                :aria-valuenow="progress.value"
                :aria-label="progress.label || metric.label"
            >
                <span
                    class="block h-2 w-full overflow-hidden rounded-sm bg-stone-100 dark:bg-neutral-700"
                    aria-hidden="true"
                >
                    <span
                        class="block h-full rounded-sm"
                        :class="colorClass"
                        :style="{ width: `${(progress.value / progress.max) * 100}%` }"
                    ></span>
                </span>
            </div>

            <div v-else class="h-10" aria-hidden="true"></div>
        </div>
    </component>
</template>
