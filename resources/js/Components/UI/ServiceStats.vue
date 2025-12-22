<script setup>
const props = defineProps({
    stats: {
        type: Object,
        required: true,
    },
});

const formatNumber = (value) =>
    Number(value || 0).toLocaleString(undefined, { maximumFractionDigits: 0 });

const formatCurrency = (value) =>
    `$${Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
</script>

<template>
    <div class="grid grid-cols-2 md:grid-cols-4 gap-2 md:gap-3 lg:gap-5">
        <div
            class="p-4 sm:p-5 bg-white border border-t-4 border-t-indigo-600 border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
            <div class="sm:flex sm:gap-x-3">
                <svg class="sm:order-2 mb-2 sm:mb-0 shrink-0 size-6 text-stone-400 dark:text-neutral-600"
                    xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" />
                    <path d="m9 11 3 3L22 4" />
                </svg>
                <div class="sm:order-1 grow space-y-1">
                    <h2 class="sm:mb-2 text-sm text-stone-500 dark:text-neutral-400">
                        Total services
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
                <svg class="sm:order-2 mb-2 sm:mb-0 shrink-0 size-6 text-stone-400 dark:text-neutral-600"
                    xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10" />
                    <path d="m9 12 2 2 4-4" />
                </svg>
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
            class="p-4 sm:p-5 bg-white border border-t-4 border-t-stone-500 border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
            <div class="sm:flex sm:gap-x-3">
                <svg class="sm:order-2 mb-2 sm:mb-0 shrink-0 size-6 text-stone-400 dark:text-neutral-600"
                    xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M3 3h18v18H3z" />
                    <path d="M7 7h10v10H7z" />
                </svg>
                <div class="sm:order-1 grow space-y-1">
                    <h2 class="sm:mb-2 text-sm text-stone-500 dark:text-neutral-400">
                        Archived
                    </h2>
                    <p class="text-lg md:text-xl font-semibold text-stone-800 dark:text-neutral-200">
                        {{ formatNumber(stats.archived) }}
                    </p>
                </div>
            </div>
        </div>

        <div
            class="p-4 sm:p-5 bg-white border border-t-4 border-t-sky-600 border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
            <div class="sm:flex sm:gap-x-3">
                <svg class="sm:order-2 mb-2 sm:mb-0 shrink-0 size-6 text-stone-400 dark:text-neutral-600"
                    xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 1v22" />
                    <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7H14a3.5 3.5 0 0 1 0 7H6" />
                </svg>
                <div class="sm:order-1 grow space-y-1">
                    <h2 class="sm:mb-2 text-sm text-stone-500 dark:text-neutral-400">
                        Avg price
                    </h2>
                    <p class="text-lg md:text-xl font-semibold text-stone-800 dark:text-neutral-200">
                        {{ formatCurrency(stats.average_price) }}
                    </p>
                </div>
            </div>
        </div>
    </div>
</template>

