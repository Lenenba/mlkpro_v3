<script setup>
import { computed } from 'vue';
import { useCurrencyFormatter } from '@/utils/currency';
import { useI18n } from 'vue-i18n';

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

const { t, te } = useI18n();

const formatNumber = (value) =>
    Number(value || 0).toLocaleString(undefined, { maximumFractionDigits: 0 });

const preferredCurrency = computed(() => props.tenantCurrencyCode);
const { formatCurrency } = useCurrencyFormatter(preferredCurrency);
const topCategories = computed(() => Array.isArray(props.stats?.top_categories) ? props.stats.top_categories : []);
const topSuppliers = computed(() => Array.isArray(props.stats?.top_suppliers) ? props.stats.top_suppliers : []);

const categoryLabel = (item) => {
    const key = item?.key;

    if (!key) {
        return item?.label || '-';
    }

    return te(`expenses.categories.${key}`)
        ? t(`expenses.categories.${key}`)
        : (item?.label || key);
};
</script>

<template>
    <div class="space-y-3 md:space-y-4">
        <div class="grid grid-cols-2 gap-2 md:gap-3 xl:grid-cols-6 lg:gap-5">
            <div
                class="rounded-sm border border-stone-200 border-t-4 border-t-red-600 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800 sm:p-5">
                <div class="space-y-1">
                    <h2 class="text-sm text-stone-500 dark:text-neutral-400">
                        {{ $t('expenses.stats.total') }}
                    </h2>
                    <p class="text-lg font-semibold text-stone-800 dark:text-neutral-200 md:text-xl">
                        {{ formatNumber(stats.total) }}
                    </p>
                </div>
            </div>

            <div
                class="rounded-sm border border-stone-200 border-t-4 border-t-amber-500 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800 sm:p-5">
                <div class="space-y-1">
                    <h2 class="text-sm text-stone-500 dark:text-neutral-400">
                        {{ $t('expenses.stats.draft') }}
                    </h2>
                    <p class="text-lg font-semibold text-stone-800 dark:text-neutral-200 md:text-xl">
                        {{ formatNumber(stats.draft) }}
                    </p>
                </div>
            </div>

            <div
                class="rounded-sm border border-stone-200 border-t-4 border-t-rose-500 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800 sm:p-5">
                <div class="space-y-1">
                    <h2 class="text-sm text-stone-500 dark:text-neutral-400">
                        {{ $t('expenses.stats.overdue') }}
                    </h2>
                    <p class="text-lg font-semibold text-stone-800 dark:text-neutral-200 md:text-xl">
                        {{ formatNumber(stats.overdue) }}
                    </p>
                </div>
            </div>

            <div
                class="rounded-sm border border-stone-200 border-t-4 border-t-orange-500 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800 sm:p-5">
                <div class="space-y-1">
                    <h2 class="text-sm text-stone-500 dark:text-neutral-400">
                        {{ $t('expenses.stats.due_total') }}
                    </h2>
                    <p class="text-lg font-semibold text-stone-800 dark:text-neutral-200 md:text-xl">
                        {{ formatCurrency(stats.due_total) }}
                    </p>
                </div>
            </div>

            <div
                class="rounded-sm border border-stone-200 border-t-4 border-t-emerald-600 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800 sm:p-5">
                <div class="space-y-1">
                    <h2 class="text-sm text-stone-500 dark:text-neutral-400">
                        {{ $t('expenses.stats.paid_this_month') }}
                    </h2>
                    <p class="text-lg font-semibold text-stone-800 dark:text-neutral-200 md:text-xl">
                        {{ formatCurrency(stats.paid_this_month) }}
                    </p>
                </div>
            </div>

            <div
                class="rounded-sm border border-stone-200 border-t-4 border-t-sky-600 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800 sm:p-5">
                <div class="space-y-1">
                    <h2 class="text-sm text-stone-500 dark:text-neutral-400">
                        {{ $t('expenses.stats.linked_total') }}
                    </h2>
                    <p class="text-lg font-semibold text-stone-800 dark:text-neutral-200 md:text-xl">
                        {{ formatCurrency(stats.linked_total) }}
                    </p>
                </div>
            </div>
        </div>

        <div class="grid gap-3 lg:grid-cols-2">
            <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                <h3 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                    {{ $t('expenses.stats.top_categories') }}
                </h3>

                <div v-if="topCategories.length" class="mt-3 space-y-3">
                    <div
                        v-for="item in topCategories"
                        :key="item.key"
                        class="flex items-start justify-between gap-3 text-sm"
                    >
                        <div>
                            <p class="font-medium text-stone-800 dark:text-neutral-100">
                                {{ categoryLabel(item) }}
                            </p>
                            <p class="text-xs text-stone-500 dark:text-neutral-500">
                                {{ formatNumber(item.count) }}
                            </p>
                        </div>
                        <p class="font-medium text-stone-700 dark:text-neutral-200">
                            {{ formatCurrency(item.total) }}
                        </p>
                    </div>
                </div>
                <p v-else class="mt-3 text-sm text-stone-500 dark:text-neutral-500">
                    {{ $t('expenses.stats.no_breakdown') }}
                </p>
            </div>

            <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                <h3 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                    {{ $t('expenses.stats.top_suppliers') }}
                </h3>

                <div v-if="topSuppliers.length" class="mt-3 space-y-3">
                    <div
                        v-for="item in topSuppliers"
                        :key="item.name"
                        class="flex items-start justify-between gap-3 text-sm"
                    >
                        <div>
                            <p class="font-medium text-stone-800 dark:text-neutral-100">
                                {{ item.name }}
                            </p>
                            <p class="text-xs text-stone-500 dark:text-neutral-500">
                                {{ formatNumber(item.count) }}
                            </p>
                        </div>
                        <p class="font-medium text-stone-700 dark:text-neutral-200">
                            {{ formatCurrency(item.total) }}
                        </p>
                    </div>
                </div>
                <p v-else class="mt-3 text-sm text-stone-500 dark:text-neutral-500">
                    {{ $t('expenses.stats.no_breakdown') }}
                </p>
            </div>
        </div>
    </div>
</template>
