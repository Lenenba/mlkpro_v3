<script setup>
import { Link } from '@inertiajs/vue3';
import KpiSparkline from '@/Components/Dashboard/KpiSparkline.vue';
import KpiTrendBadge from '@/Components/Dashboard/KpiTrendBadge.vue';

defineProps({
    title: {
        type: String,
        required: true,
    },
    subtitle: {
        type: String,
        default: '',
    },
    actionHref: {
        type: String,
        default: '',
    },
    actionLabel: {
        type: String,
        default: '',
    },
    accentClass: {
        type: String,
        default: 'border-t-emerald-600',
    },
    metrics: {
        type: Array,
        default: () => [],
    },
    metricsGridClass: {
        type: String,
        default: 'sm:grid-cols-2',
    },
    summaryItems: {
        type: Array,
        default: () => [],
    },
    summaryGridClass: {
        type: String,
        default: 'sm:grid-cols-3',
    },
    compactMetrics: {
        type: Boolean,
        default: false,
    },
});
</script>

<template>
    <div
        class="h-full overflow-hidden rounded-sm border border-stone-200 border-t-4 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-800"
        :class="accentClass"
    >
        <div class="bg-gradient-to-br from-stone-50 via-white to-white p-4 dark:from-neutral-900 dark:via-neutral-900 dark:to-neutral-800">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                        {{ title }}
                    </h2>
                    <p v-if="subtitle" class="mt-1 max-w-2xl text-xs leading-5 text-stone-500 dark:text-neutral-400">
                        {{ subtitle }}
                    </p>
                </div>

                <Link
                    v-if="actionHref && actionLabel"
                    :href="actionHref"
                    class="inline-flex shrink-0 items-center rounded-full bg-white px-3 py-1 text-[11px] font-semibold text-green-700 ring-1 ring-stone-200 transition hover:bg-stone-50 dark:bg-neutral-900 dark:text-green-300 dark:ring-neutral-700 dark:hover:bg-neutral-800"
                >
                    {{ actionLabel }}
                </Link>
            </div>

            <div class="mt-4 grid gap-3" :class="metricsGridClass">
                <article
                    v-for="metric in metrics"
                    :key="metric.key"
                    class="rounded-sm border border-stone-200 bg-white/90 dark:border-neutral-700 dark:bg-neutral-900/80"
                    :class="compactMetrics ? 'p-2.5' : 'p-3'"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <span
                                    class="size-2 rounded-full"
                                    :class="metric.colorClass || 'bg-stone-400/70 dark:bg-neutral-500/50'"
                                ></span>
                                <p class="truncate text-xs font-medium text-stone-500 dark:text-neutral-400">
                                    {{ metric.label }}
                                </p>
                            </div>
                            <p
                                class="font-semibold text-stone-800 dark:text-neutral-100"
                                :class="compactMetrics ? 'mt-1.5 text-lg' : 'mt-2 text-xl'"
                            >
                                {{ metric.value }}
                            </p>
                        </div>
                        <KpiTrendBadge v-if="metric.trend" :trend="metric.trend" />
                    </div>

                    <p
                        v-if="metric.context"
                        class="text-stone-500 dark:text-neutral-400"
                        :class="compactMetrics ? 'mt-1 min-h-0 text-[11px] leading-4' : 'mt-2 min-h-10 text-xs leading-5'"
                    >
                        {{ metric.context }}
                    </p>

                    <KpiSparkline
                        v-if="metric.points?.length"
                        :points="metric.points"
                        :color-class="metric.colorClass || 'bg-stone-400/70 dark:bg-neutral-500/50'"
                    />
                </article>
            </div>

            <div
                v-if="summaryItems.length"
                class="mt-4 grid gap-2"
                :class="summaryGridClass"
            >
                <div
                    v-for="item in summaryItems"
                    :key="item.key"
                    class="rounded-sm border border-stone-200/80 bg-stone-50/80 px-3 py-2 dark:border-neutral-700 dark:bg-neutral-900/70"
                    :class="item.toneClass"
                >
                    <div class="text-[11px] uppercase tracking-[0.08em] text-stone-500 dark:text-neutral-400">
                        {{ item.label }}
                    </div>
                    <div class="mt-1 text-sm font-semibold text-stone-800 dark:text-neutral-100">
                        {{ item.value }}
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
