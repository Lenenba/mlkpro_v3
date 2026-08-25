<script setup>
import { computed } from 'vue';
import KpiMetricGrid from '@/Components/Dashboard/KpiMetricGrid.vue';
import { useCurrencyFormatter } from '@/utils/currency';
import { buildKpiProgress } from '@/utils/kpi';
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
const metrics = computed(() => [
    {
        key: 'total',
        label: t('expenses.stats.total'),
        value: formatNumber(props.stats.total),
        tone: 'red',
    },
    {
        key: 'draft',
        label: t('expenses.stats.draft'),
        value: formatNumber(props.stats.draft),
        tone: 'amber',
        progress: buildKpiProgress(props.stats.draft, props.stats.total),
    },
    {
        key: 'overdue',
        label: t('expenses.stats.overdue'),
        value: formatNumber(props.stats.overdue),
        tone: 'rose',
        progress: buildKpiProgress(props.stats.overdue, props.stats.total),
    },
    {
        key: 'due-total',
        label: t('expenses.stats.due_total'),
        value: formatCurrency(props.stats.due_total),
        tone: 'orange',
    },
    {
        key: 'paid-this-month',
        label: t('expenses.stats.paid_this_month'),
        value: formatCurrency(props.stats.paid_this_month),
        tone: 'emerald',
    },
    {
        key: 'linked-total',
        label: t('expenses.stats.linked_total'),
        value: formatCurrency(props.stats.linked_total),
        tone: 'sky',
    },
]);

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
        <KpiMetricGrid :metrics="metrics" />

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
