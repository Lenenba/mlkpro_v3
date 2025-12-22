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
            class="p-4 sm:p-5 bg-white border border-t-4 border-t-indigo-600 border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
            <div class="sm:flex sm:gap-x-3">
                <div class="sm:order-1 grow space-y-1">
                    <h2 class="sm:mb-2 text-sm text-stone-500 dark:text-neutral-400">
                        Total members
                    </h2>
                    <p class="text-lg md:text-xl font-semibold text-stone-800 dark:text-neutral-200">
                        {{ formatNumber(stats.total) }}
                    </p>
                </div>
            </div>
        </div>

        <div
            class="p-4 sm:p-5 bg-white border border-t-4 border-t-emerald-600 border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
            <div class="sm:flex sm:gap-x-3">
                <div class="sm:order-1 grow space-y-1">
                    <h2 class="sm:mb-2 text-sm text-stone-500 dark:text-neutral-400">
                        Active
                    </h2>
                    <p class="text-lg md:text-xl font-semibold text-stone-800 dark:text-neutral-200">
                        {{ formatNumber(stats.active) }}
                    </p>
                </div>
            </div>
        </div>

        <div
            class="p-4 sm:p-5 bg-white border border-t-4 border-t-sky-600 border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
            <div class="sm:flex sm:gap-x-3">
                <div class="sm:order-1 grow space-y-1">
                    <h2 class="sm:mb-2 text-sm text-stone-500 dark:text-neutral-400">
                        Administrators
                    </h2>
                    <p class="text-lg md:text-xl font-semibold text-stone-800 dark:text-neutral-200">
                        {{ formatNumber(stats.admins) }}
                    </p>
                </div>
            </div>
        </div>

        <div
            class="p-4 sm:p-5 bg-white border border-t-4 border-t-amber-600 border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
            <div class="sm:flex sm:gap-x-3">
                <div class="sm:order-1 grow space-y-1">
                    <h2 class="sm:mb-2 text-sm text-stone-500 dark:text-neutral-400">
                        Team members
                    </h2>
                    <p class="text-lg md:text-xl font-semibold text-stone-800 dark:text-neutral-200">
                        {{ formatNumber(stats.members) }}
                    </p>
                </div>
            </div>
        </div>
    </div>
</template>

