<script setup>
import { computed } from 'vue';
import { useCurrencyFormatter } from '@/utils/currency';

const props = defineProps({
    stats: {
        type: Object,
        required: true,
    },
    tenantCurrencyCode: {
        type: String,
        default: 'CAD',
    },
});

const formatNumber = (value) =>
    Number(value || 0).toLocaleString(undefined, { maximumFractionDigits: 0 });

const preferredCurrency = computed(() => props.tenantCurrencyCode);
const { formatCurrency } = useCurrencyFormatter(preferredCurrency);
</script>

<template>
    <div class="grid grid-cols-2 xl:grid-cols-5 gap-2 md:gap-3 lg:gap-5">
        <div
            class="p-4 sm:p-5 bg-white border border-t-4 border-t-red-600 border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
            <div class="space-y-1">
                <h2 class="text-sm text-stone-500 dark:text-neutral-400">
                    {{ $t('expenses.stats.total') }}
                </h2>
                <p class="text-lg md:text-xl font-semibold text-stone-800 dark:text-neutral-200">
                    {{ formatNumber(stats.total) }}
                </p>
            </div>
        </div>

        <div
            class="p-4 sm:p-5 bg-white border border-t-4 border-t-amber-500 border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
            <div class="space-y-1">
                <h2 class="text-sm text-stone-500 dark:text-neutral-400">
                    {{ $t('expenses.stats.draft') }}
                </h2>
                <p class="text-lg md:text-xl font-semibold text-stone-800 dark:text-neutral-200">
                    {{ formatNumber(stats.draft) }}
                </p>
            </div>
        </div>

        <div
            class="p-4 sm:p-5 bg-white border border-t-4 border-t-rose-500 border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
            <div class="space-y-1">
                <h2 class="text-sm text-stone-500 dark:text-neutral-400">
                    {{ $t('expenses.stats.overdue') }}
                </h2>
                <p class="text-lg md:text-xl font-semibold text-stone-800 dark:text-neutral-200">
                    {{ formatNumber(stats.overdue) }}
                </p>
            </div>
        </div>

        <div
            class="p-4 sm:p-5 bg-white border border-t-4 border-t-orange-500 border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
            <div class="space-y-1">
                <h2 class="text-sm text-stone-500 dark:text-neutral-400">
                    {{ $t('expenses.stats.due_total') }}
                </h2>
                <p class="text-lg md:text-xl font-semibold text-stone-800 dark:text-neutral-200">
                    {{ formatCurrency(stats.due_total) }}
                </p>
            </div>
        </div>

        <div
            class="p-4 sm:p-5 bg-white border border-t-4 border-t-emerald-600 border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
            <div class="space-y-1">
                <h2 class="text-sm text-stone-500 dark:text-neutral-400">
                    {{ $t('expenses.stats.paid_this_month') }}
                </h2>
                <p class="text-lg md:text-xl font-semibold text-stone-800 dark:text-neutral-200">
                    {{ formatCurrency(stats.paid_this_month) }}
                </p>
            </div>
        </div>
    </div>
</template>
