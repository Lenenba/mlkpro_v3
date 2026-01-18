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
                <h2 class="text-sm text-stone-500 dark:text-neutral-400">{{ $t('requests.stats.total') }}</h2>
                <p class="text-lg md:text-xl font-semibold text-stone-800 dark:text-neutral-200">
                    {{ formatNumber(stats.total) }}
                </p>
            </div>
        </div>

        <div
            class="p-4 sm:p-5 bg-white border border-t-4 border-t-amber-600 border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700"
        >
            <div class="space-y-1">
                <h2 class="text-sm text-stone-500 dark:text-neutral-400">{{ $t('requests.stats.new') }}</h2>
                <p class="text-lg md:text-xl font-semibold text-stone-800 dark:text-neutral-200">
                    {{ formatNumber(stats.new) }}
                </p>
            </div>
        </div>

        <div
            class="p-4 sm:p-5 bg-white border border-t-4 border-t-emerald-600 border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700"
        >
            <div class="space-y-1">
                <h2 class="text-sm text-stone-500 dark:text-neutral-400">{{ $t('requests.stats.converted') }}</h2>
                <p class="text-lg md:text-xl font-semibold text-stone-800 dark:text-neutral-200">
                    {{ formatNumber(stats.converted) }}
                </p>
            </div>
        </div>

        <div
            class="p-4 sm:p-5 bg-white border border-t-4 border-t-sky-600 border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700"
        >
            <div class="space-y-1">
                <h2 class="text-sm text-stone-500 dark:text-neutral-400">{{ $t('requests.stats.unassigned') }}</h2>
                <p class="text-lg md:text-xl font-semibold text-stone-800 dark:text-neutral-200">
                    {{ formatNumber(stats.unassigned) }}
                </p>
            </div>
        </div>
    </div>
</template>
