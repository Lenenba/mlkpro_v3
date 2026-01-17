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

const formatRatio = (value) =>
    Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

</script>

<template>
    <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-2 md:gap-3 lg:gap-5">
        <div
            class="p-4 sm:p-5 bg-white border border-t-4 border-t-emerald-600 border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
            <div class="sm:flex sm:gap-x-3">
                <svg class="sm:order-2 mb-2 sm:mb-0 shrink-0 size-6 text-stone-400 dark:text-neutral-600"
                    xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M3 3h18v4H3z" />
                    <path d="M3 9h18v12H3z" />
                    <path d="M7 13h10" />
                    <path d="M7 17h6" />
                </svg>
                <div class="sm:order-1 grow space-y-1">
                    <h2 class="sm:mb-2 text-sm text-stone-500 dark:text-neutral-400">
                        {{ $t('products.stats.total') }}
                    </h2>
                    <p class="text-lg md:text-xl font-semibold text-stone-800 dark:text-neutral-200">
                        {{ formatNumber(stats.total) }}
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
                        {{ $t('products.stats.inventory_value') }}
                    </h2>
                    <p class="text-lg md:text-xl font-semibold text-stone-800 dark:text-neutral-200">
                        {{ formatCurrency(stats.inventory_value) }}
                    </p>
                </div>
            </div>
        </div>

        <div
            class="p-4 sm:p-5 bg-white border border-t-4 border-t-blue-600 border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
            <div class="sm:flex sm:gap-x-3">
                <svg class="sm:order-2 mb-2 sm:mb-0 shrink-0 size-6 text-stone-400 dark:text-neutral-600"
                    xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z" />
                    <path d="M3.3 7 12 12l8.7-5" />
                    <path d="M12 22V12" />
                </svg>
                <div class="sm:order-1 grow space-y-1">
                    <h2 class="sm:mb-2 text-sm text-stone-500 dark:text-neutral-400">
                        {{ $t('products.stats.in_stock') }}
                    </h2>
                    <p class="text-lg md:text-xl font-semibold text-stone-800 dark:text-neutral-200">
                        {{ formatNumber(stats.in_stock) }}
                    </p>
                </div>
            </div>
        </div>

        <div
            class="p-4 sm:p-5 bg-white border border-t-4 border-t-amber-600 border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
            <div class="sm:flex sm:gap-x-3">
                <svg class="sm:order-2 mb-2 sm:mb-0 shrink-0 size-6 text-stone-400 dark:text-neutral-600"
                    xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z" />
                    <line x1="12" x2="12" y1="9" y2="13" />
                    <line x1="12" x2="12.01" y1="17" y2="17" />
                </svg>
                <div class="sm:order-1 grow space-y-1">
                    <h2 class="sm:mb-2 text-sm text-stone-500 dark:text-neutral-400">
                        {{ $t('products.stats.low_stock') }}
                    </h2>
                    <p class="text-lg md:text-xl font-semibold text-stone-800 dark:text-neutral-200">
                        {{ formatNumber(stats.low_stock) }}
                    </p>
                </div>
            </div>
        </div>

        <div
            class="p-4 sm:p-5 bg-white border border-t-4 border-t-red-600 border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
            <div class="sm:flex sm:gap-x-3">
                <svg class="sm:order-2 mb-2 sm:mb-0 shrink-0 size-6 text-stone-400 dark:text-neutral-600"
                    xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10" />
                    <path d="M12 8v4" />
                    <path d="M12 16h.01" />
                </svg>
                <div class="sm:order-1 grow space-y-1">
                    <h2 class="sm:mb-2 text-sm text-stone-500 dark:text-neutral-400">
                        {{ $t('products.stats.out_of_stock') }}
                    </h2>
                    <p class="text-lg md:text-xl font-semibold text-stone-800 dark:text-neutral-200">
                        {{ formatNumber(stats.out_of_stock) }}
                    </p>
                </div>
            </div>
        </div>

        <div
            class="p-4 sm:p-5 bg-white border border-t-4 border-t-indigo-600 border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
            <div class="sm:flex sm:gap-x-3">
                <svg class="sm:order-2 mb-2 sm:mb-0 shrink-0 size-6 text-stone-400 dark:text-neutral-600"
                    xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M3 3v18h18" />
                    <path d="m7 13 4 4 6-8" />
                </svg>
                <div class="sm:order-1 grow space-y-1">
                    <h2 class="sm:mb-2 text-sm text-stone-500 dark:text-neutral-400">
                        {{ $t('products.stats.rotation') }}
                    </h2>
                    <p class="text-lg md:text-xl font-semibold text-stone-800 dark:text-neutral-200">
                        {{ formatRatio(stats.rotation) }}x
                    </p>
                </div>
            </div>
        </div>
    </div>
</template>
