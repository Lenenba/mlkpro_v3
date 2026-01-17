<script setup>
import { useI18n } from 'vue-i18n';

const props = defineProps({
    stats: {
        type: Object,
        required: true,
    },
});

const { t } = useI18n();

const formatNumber = (value) =>
    Number(value || 0).toLocaleString(undefined, { maximumFractionDigits: 0 });
</script>

<template>
    <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-5 gap-2 md:gap-3 lg:gap-5">
        <div
            class="p-4 sm:p-5 bg-white border border-t-4 border-t-indigo-600 border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
            <div class="sm:flex sm:gap-x-3">
                <svg class="sm:order-2 mb-2 sm:mb-0 shrink-0 size-6 text-stone-400 dark:text-neutral-600"
                    xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" />
                    <circle cx="9" cy="7" r="4" />
                    <path d="M22 21v-2a4 4 0 0 0-3-3.87" />
                    <path d="M16 3.13a4 4 0 0 1 0 7.75" />
                </svg>
                <div class="sm:order-1 grow space-y-1">
                    <h2 class="sm:mb-2 text-sm text-stone-500 dark:text-neutral-400">
                        {{ t('customers.stats.total') }}
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
                    <path d="M12 8v8" />
                    <path d="M8 12h8" />
                    <circle cx="12" cy="12" r="10" />
                </svg>
                <div class="sm:order-1 grow space-y-1">
                    <h2 class="sm:mb-2 text-sm text-stone-500 dark:text-neutral-400">
                        {{ t('customers.stats.new') }}
                    </h2>
                    <p class="text-lg md:text-xl font-semibold text-stone-800 dark:text-neutral-200">
                        {{ formatNumber(stats.new) }}
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
                    <path d="M3 3h18v18H3z" />
                    <path d="M7 7h10v10H7z" />
                </svg>
                <div class="sm:order-1 grow space-y-1">
                    <h2 class="sm:mb-2 text-sm text-stone-500 dark:text-neutral-400">
                        {{ t('customers.stats.active') }}
                    </h2>
                    <p class="text-lg md:text-xl font-semibold text-stone-800 dark:text-neutral-200">
                        {{ formatNumber(stats.active) }}
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
                    <path d="M4 4h16v4H4z" />
                    <path d="M4 12h16v8H4z" />
                </svg>
                <div class="sm:order-1 grow space-y-1">
                    <h2 class="sm:mb-2 text-sm text-stone-500 dark:text-neutral-400">
                        {{ t('customers.stats.with_quotes') }}
                    </h2>
                    <p class="text-lg md:text-xl font-semibold text-stone-800 dark:text-neutral-200">
                        {{ formatNumber(stats.with_quotes) }}
                    </p>
                </div>
            </div>
        </div>

        <div
            class="p-4 sm:p-5 bg-white border border-t-4 border-t-rose-600 border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
            <div class="sm:flex sm:gap-x-3">
                <svg class="sm:order-2 mb-2 sm:mb-0 shrink-0 size-6 text-stone-400 dark:text-neutral-600"
                    xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M3 12h18" />
                    <path d="M7 12l4-4" />
                    <path d="M7 12l4 4" />
                </svg>
                <div class="sm:order-1 grow space-y-1">
                    <h2 class="sm:mb-2 text-sm text-stone-500 dark:text-neutral-400">
                        {{ t('customers.stats.with_jobs') }}
                    </h2>
                    <p class="text-lg md:text-xl font-semibold text-stone-800 dark:text-neutral-200">
                        {{ formatNumber(stats.with_works) }}
                    </p>
                </div>
            </div>
        </div>
    </div>
</template>
