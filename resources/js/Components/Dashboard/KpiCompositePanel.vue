<script setup>
import { Link } from '@inertiajs/vue3';
import KpiMetricGrid from '@/Components/Dashboard/KpiMetricGrid.vue';

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

            <KpiMetricGrid
                class="mt-4"
                :metrics="metrics"
                :grid-class="metricsGridClass"
                :compact="compactMetrics"
                variant="dashboard"
            />

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
