<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Barchart from '@/Components/UI/Barchart.vue';
import Donutchart from '@/Components/UI/Donutchart.vue';
import { useCurrencyFormatter } from '@/utils/currency';
import { buildExpenseBreakdownChartData } from '@/utils/expenseRecapChart';

const props = defineProps({
    categoryBreakdown: {
        type: Object,
        default: () => ({}),
    },
    paymentBreakdown: {
        type: Object,
        default: () => ({}),
    },
    expectedTotal: {
        type: Number,
        default: 0,
    },
    currencyCode: {
        type: String,
        default: 'CAD',
    },
    periodLabel: {
        type: String,
        default: '',
    },
});

const { t, te } = useI18n();
const preferredCurrency = computed(() => props.currencyCode);
const { formatCurrency } = useCurrencyFormatter(preferredCurrency);
const categoryLabel = (item) => {
    if (item?.is_remainder) {
        return t('expenses.recap.charts.remaining_categories');
    }

    const key = item?.key;

    return key && te(`expenses.categories.${key}`)
        ? t(`expenses.categories.${key}`)
        : (item?.label || key || t('expenses.labels.uncategorized'));
};
const paymentMethodLabel = (item) => {
    if (item?.is_remainder) {
        return t('expenses.recap.charts.remaining_payment_methods');
    }

    const key = item?.key;

    return key && te(`expenses.payment_methods.${key}`)
        ? t(`expenses.payment_methods.${key}`)
        : (item?.label || key || t('expenses.labels.not_set'));
};
const categoryChart = computed(() => buildExpenseBreakdownChartData(props.categoryBreakdown, {
    expectedTotal: props.expectedTotal,
    labelForItem: categoryLabel,
    seriesLabel: t('expenses.recap.charts.amount_series'),
}));
const paymentChart = computed(() => buildExpenseBreakdownChartData(props.paymentBreakdown, {
    expectedTotal: props.expectedTotal,
    labelForItem: paymentMethodLabel,
    seriesLabel: t('expenses.recap.charts.amount_series'),
}));
const categoryError = computed(() => categoryChart.value.isValid
    ? false
    : t('expenses.recap.charts.invalid'));
const paymentError = computed(() => paymentChart.value.isValid
    ? false
    : t('expenses.recap.charts.invalid'));
const categoryHeight = computed(() => Math.min(
    430,
    Math.max(250, (categoryChart.value.categories.length * 48) + 90),
));
const formatExactCurrency = (value) => Number.isFinite(Number(value))
    ? formatCurrency(Number(value), props.currencyCode)
    : '—';
const formatCompactCurrency = (value) => Number.isFinite(Number(value))
    ? formatCurrency(Number(value), props.currencyCode, {
        notation: 'compact',
        minimumFractionDigits: 0,
        maximumFractionDigits: 1,
    })
    : '—';
const categoryOptions = computed(() => ({
    legend: {
        show: false,
    },
    xaxis: {
        min: 0,
        forceNiceScale: true,
        tickAmount: 4,
        labels: {
            formatter: formatCompactCurrency,
        },
    },
    plotOptions: {
        bar: {
            horizontal: true,
            barHeight: '56%',
        },
    },
}));
</script>

<template>
    <div class="grid gap-4 lg:grid-cols-2" data-testid="expense-recap-charts">
        <Barchart
            :title="$t('expenses.recap.charts.categories_title')"
            :subtitle="$t('expenses.recap.charts.categories_subtitle', { currency: currencyCode })"
            :period-label="periodLabel"
            :series="categoryChart.series"
            :categories="categoryChart.categories"
            :height="categoryHeight"
            :options="categoryOptions"
            :color-tones="['rose']"
            :value-formatter="formatExactCurrency"
            :category-label="$t('expenses.recap.charts.category_label')"
            :value-label="$t('expenses.recap.charts.amount_label')"
            :unit-label="currencyCode"
            :table-caption="$t('expenses.recap.charts.categories_table_caption')"
            :empty-message="$t('expenses.recap.charts.categories_empty')"
            :error="categoryError"
            :error-message="$t('expenses.recap.charts.invalid')"
            horizontal
        />

        <Donutchart
            :title="$t('expenses.recap.charts.payment_methods_title')"
            :subtitle="$t('expenses.recap.charts.payment_methods_subtitle', { currency: currencyCode })"
            :period-label="periodLabel"
            :series="paymentChart.values"
            :categories="paymentChart.categories"
            :height="300"
            :color-tones="['rose', 'amber', 'emerald', 'blue', 'violet']"
            :value-formatter="formatExactCurrency"
            :category-label="$t('expenses.recap.charts.payment_method_label')"
            :value-label="$t('expenses.recap.charts.amount_label')"
            :unit-label="currencyCode"
            :total-label="$t('expenses.recap.charts.total_label')"
            :table-caption="$t('expenses.recap.charts.payment_methods_table_caption')"
            :empty-message="$t('expenses.recap.charts.payment_methods_empty')"
            :error="paymentError"
            :error-message="$t('expenses.recap.charts.invalid')"
        />
    </div>
</template>
