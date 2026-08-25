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
    <section
        class="h-full min-w-0 rounded-sm border border-stone-200 border-t-4 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-800"
        :class="accentClass"
        :aria-label="title"
    >
        <div class="bg-stone-50 p-4 dark:bg-neutral-900">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0 flex-[1_1_14rem]">
                    <h2 class="break-words text-sm font-semibold text-stone-800 dark:text-neutral-100">
                        {{ title }}
                    </h2>
                    <p v-if="subtitle" class="mt-1 max-w-2xl break-words text-xs leading-5 text-stone-500 dark:text-neutral-400">
                        {{ subtitle }}
                    </p>
                </div>

                <Link
                    v-if="actionHref && actionLabel"
                    :href="actionHref"
                    class="inline-flex max-w-full items-center justify-center whitespace-normal rounded-sm bg-white px-3 py-1 text-center text-[11px] font-semibold text-green-700 ring-1 ring-stone-200 transition [overflow-wrap:anywhere] hover:bg-stone-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-green-600 dark:bg-neutral-800 dark:text-green-300 dark:ring-neutral-700 dark:hover:bg-neutral-700"
                >
                    {{ actionLabel }}
                </Link>
            </div>

            <div class="mt-4 grid min-w-0 gap-3" :class="metricsGridClass">
                <article
                    v-for="metric in metrics"
                    :key="metric.key"
                    class="min-w-0 rounded-sm border border-stone-200 bg-white dark:border-neutral-700 dark:bg-neutral-800"
                    :class="compactMetrics ? 'p-2.5' : 'p-3'"
                >
                    <div class="flex min-w-0 flex-wrap items-start justify-between gap-2">
                        <div class="min-w-0 flex-[1_1_8rem]">
                            <div class="flex min-w-0 items-start gap-2">
                                <span
                                    class="mt-1 size-2 shrink-0 rounded-full"
                                    :class="metric.colorClass || 'bg-stone-400/70 dark:bg-neutral-500/50'"
                                    aria-hidden="true"
                                ></span>
                                <p class="min-w-0 break-words text-xs font-medium leading-4 text-stone-500 [overflow-wrap:anywhere] dark:text-neutral-400">
                                    {{ metric.label }}
                                </p>
                            </div>
                            <p
                                class="max-w-full whitespace-nowrap font-semibold tabular-nums text-stone-800 dark:text-neutral-100"
                                :class="compactMetrics ? 'mt-1.5 text-base' : 'mt-2 text-lg'"
                            >
                                {{ metric.value }}
                            </p>
                        </div>
                        <KpiTrendBadge
                            v-if="metric.trend"
                            class="shrink-0 whitespace-nowrap"
                            :trend="metric.trend"
                        />
                    </div>

                    <p
                        v-if="metric.context"
                        class="break-words text-stone-500 [overflow-wrap:anywhere] dark:text-neutral-400"
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
                class="mt-4 grid min-w-0 gap-2"
                :class="summaryGridClass"
            >
                <div
                    v-for="item in summaryItems"
                    :key="item.key"
                    class="min-w-0 rounded-sm border border-stone-200 bg-white px-3 py-2 dark:border-neutral-700 dark:bg-neutral-800"
                    :class="item.toneClass"
                >
                    <div class="break-words text-[11px] uppercase tracking-[0.08em] text-stone-500 [overflow-wrap:anywhere] dark:text-neutral-400">
                        {{ item.label }}
                    </div>
                    <div class="mt-1 max-w-full whitespace-nowrap text-sm font-semibold tabular-nums text-stone-800 dark:text-neutral-100">
                        {{ item.value }}
                    </div>
                </div>
            </div>
        </div>
    </section>
</template>
