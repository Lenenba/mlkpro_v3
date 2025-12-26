<script setup>
const props = defineProps({
    stats: {
        type: Object,
        required: true,
    },
});

const formatNumber = (value) =>
    Number(value || 0).toLocaleString(undefined, { maximumFractionDigits: 0 });
</script>

<template>
    <div class="grid grid-cols-2 md:grid-cols-4 gap-2 md:gap-3 lg:gap-5">
        <div
            class="p-4 sm:p-5 bg-white border border-t-4 border-t-indigo-600 border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700"
        >
            <div class="space-y-1">
                <h2 class="text-sm text-stone-500 dark:text-neutral-400">Total scans</h2>
                <p class="text-lg md:text-xl font-semibold text-stone-800 dark:text-neutral-200">
                    {{ formatNumber(stats.total) }}
                </p>
            </div>
        </div>

        <div
            class="p-4 sm:p-5 bg-white border border-t-4 border-t-emerald-600 border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700"
        >
            <div class="space-y-1">
                <h2 class="text-sm text-stone-500 dark:text-neutral-400">Ready</h2>
                <p class="text-lg md:text-xl font-semibold text-stone-800 dark:text-neutral-200">
                    {{ formatNumber(stats.ready) }}
                </p>
            </div>
        </div>

        <div
            class="p-4 sm:p-5 bg-white border border-t-4 border-t-amber-600 border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700"
        >
            <div class="space-y-1">
                <h2 class="text-sm text-stone-500 dark:text-neutral-400">Processing</h2>
                <p class="text-lg md:text-xl font-semibold text-stone-800 dark:text-neutral-200">
                    {{ formatNumber(stats.processing) }}
                </p>
            </div>
        </div>

        <div
            class="p-4 sm:p-5 bg-white border border-t-4 border-t-rose-600 border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700"
        >
            <div class="space-y-1">
                <h2 class="text-sm text-stone-500 dark:text-neutral-400">Failed</h2>
                <p class="text-lg md:text-xl font-semibold text-stone-800 dark:text-neutral-200">
                    {{ formatNumber(stats.failed) }}
                </p>
            </div>
        </div>
    </div>
</template>
