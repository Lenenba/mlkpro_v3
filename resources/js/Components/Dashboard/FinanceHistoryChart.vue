<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseApexChart from '@/Components/Charts/BaseApexChart.vue';
import ChartFrame from '@/Components/Charts/ChartFrame.vue';
import { useCurrencyFormatter } from '@/utils/currency';
import { buildFinanceHistoryChartData, buildZeroInclusiveFinanceDomain } from '@/utils/financeChart';

const props = defineProps({
    revenueSeries: {
        type: Object,
        default: () => ({}),
    },
    expenseSeries: {
        type: Object,
        default: () => ({}),
    },
    showExpenses: {
        type: Boolean,
        default: false,
    },
});

const { locale, t } = useI18n();
const { formatCurrency } = useCurrencyFormatter();

const chartData = computed(() => buildFinanceHistoryChartData({
    revenueSeries: props.revenueSeries,
    expenseSeries: props.expenseSeries,
    includeExpenses: props.showExpenses,
    revenueLabel: t('dashboard.revenue.legend.revenue'),
    expenseLabel: t('dashboard.revenue.legend.expenses'),
}));

const currencyCode = computed(() => chartData.value.currencyCode || 'CAD');
const chartTitle = computed(() => t(chartData.value.series.length > 1
    ? 'dashboard.revenue.title'
    : 'dashboard.revenue.revenue_only_title'));
const tableCaption = computed(() => t(chartData.value.series.length > 1
    ? 'dashboard.revenue.table_caption'
    : 'dashboard.revenue.revenue_only_table_caption'));
const monthLabel = (value) => {
    const [year, month] = String(value || '').split('-').map(Number);

    if (!year || !month) {
        return String(value || '');
    }

    return new Intl.DateTimeFormat(locale.value, {
        month: 'short',
        year: 'numeric',
        timeZone: 'UTC',
    }).format(new Date(Date.UTC(year, month - 1, 1)));
};
const dateLabel = (value) => {
    if (!value) {
        return '—';
    }

    return new Intl.DateTimeFormat(locale.value, {
        dateStyle: 'medium',
        timeZone: 'UTC',
    }).format(new Date(`${value}T00:00:00Z`));
};
const categories = computed(() => chartData.value.labels.map(monthLabel));
const periodLabel = computed(() => {
    const period = chartData.value.period;

    if (!period?.start || !period?.end) {
        return '';
    }

    return t('dashboard.revenue.period', {
        start: dateLabel(period.start),
        end: dateLabel(period.end),
        timezone: period.timezone || 'UTC',
    });
});
const isFiniteValue = (value) => value !== null
    && value !== undefined
    && value !== ''
    && Number.isFinite(Number(value));
const exactCurrency = (value) => isFiniteValue(value)
    ? formatCurrency(value, currencyCode.value)
    : '—';
const compactCurrency = (value) => isFiniteValue(value)
    ? formatCurrency(value, currencyCode.value, {
        notation: 'compact',
        minimumFractionDigits: 0,
        maximumFractionDigits: 1,
    })
    : '—';
const colorTones = computed(() => chartData.value.series.length > 1
    ? ['emerald', 'amber']
    : ['emerald']);
const chartDomain = computed(() => buildZeroInclusiveFinanceDomain(chartData.value.series));
const chartOptions = computed(() => ({
    chart: {
        type: 'line',
        stacked: false,
    },
    fill: {
        type: 'solid',
        opacity: 1,
    },
    stroke: {
        curve: 'straight',
        dashArray: chartData.value.series.length > 1 ? [0, 6] : 0,
    },
    markers: {
        size: 3,
        shape: chartData.value.series.length > 1 ? ['circle', 'square'] : 'circle',
        strokeWidth: 0,
        hover: {
            sizeOffset: 2,
        },
    },
    legend: {
        show: false,
    },
    grid: {
        padding: {
            left: 8,
            right: 8,
        },
    },
    xaxis: {
        tickAmount: Math.min(6, categories.value.length),
        tooltip: {
            enabled: false,
        },
        labels: {
            rotate: 0,
            hideOverlappingLabels: true,
            trim: true,
        },
    },
    yaxis: {
        min: chartDomain.value.min,
        max: chartDomain.value.max,
        forceNiceScale: true,
        labels: {
            formatter: compactCurrency,
        },
    },
    tooltip: {
        shared: true,
        intersect: false,
        y: {
            formatter: exactCurrency,
        },
    },
}));
</script>

<template>
    <ChartFrame
        :title="chartTitle"
        :subtitle="$t('dashboard.revenue.subtitle', { currency: currencyCode })"
        :period-label="periodLabel"
        :series="chartData.series"
        :categories="categories"
        :table-caption="tableCaption"
        :category-label="$t('dashboard.revenue.month_label')"
        :value-label="$t('dashboard.revenue.amount_label')"
        :unit-label="currencyCode"
        :value-formatter="exactCurrency"
        :framed="false"
        data-testid="finance-history-chart"
    >
        <template #legend>
            <ul
                class="flex flex-wrap items-center gap-x-4 gap-y-2 text-[11px] text-stone-600 dark:text-neutral-300"
                :aria-label="$t('dashboard.revenue.legend_label')"
            >
                <li class="inline-flex items-center gap-2">
                    <span class="inline-flex w-5 items-center" aria-hidden="true">
                        <span class="w-full border-t-2" style="border-color: var(--chart-series-emerald)"></span>
                        <span class="-ml-3 size-1.5 rounded-full" style="background-color: var(--chart-series-emerald)"></span>
                    </span>
                    {{ chartData.series[0]?.name }}
                </li>
                <li v-if="chartData.series[1]" class="inline-flex items-center gap-2">
                    <span class="inline-flex w-5 items-center" aria-hidden="true">
                        <span class="w-full border-t-2 border-dashed" style="border-color: var(--chart-series-amber)"></span>
                        <span class="-ml-3 size-1.5" style="background-color: var(--chart-series-amber)"></span>
                    </span>
                    {{ chartData.series[1].name }}
                </li>
            </ul>
        </template>

        <BaseApexChart
            type="line"
            :height="260"
            :series="chartData.series"
            :categories="categories"
            :options="chartOptions"
            :color-tones="colorTones"
        />
    </ChartFrame>
</template>
