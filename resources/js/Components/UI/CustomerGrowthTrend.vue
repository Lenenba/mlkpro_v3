<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseApexChart from '@/Components/Charts/BaseApexChart.vue';
import ChartFrame from '@/Components/Charts/ChartFrame.vue';
import { buildCustomerGrowthChartData } from '@/utils/customerGrowthChart';

const props = defineProps({
    trend: {
        type: Object,
        default: () => ({}),
    },
});

const { locale, t } = useI18n();
const chartData = computed(() => buildCustomerGrowthChartData(props.trend, {
    currentLabel: t('customers.growth.current_series'),
    previousLabel: t('customers.growth.previous_series'),
}));
const chartError = computed(() => chartData.value.isValid
    ? false
    : t('customers.growth.invalid'));
const numberFormatter = computed(() => new Intl.NumberFormat(locale.value));
const compactNumberFormatter = computed(() => new Intl.NumberFormat(locale.value, {
    notation: 'compact',
    maximumFractionDigits: 1,
}));
const dateFormatter = computed(() => new Intl.DateTimeFormat(locale.value, {
    dateStyle: 'medium',
    timeZone: 'UTC',
}));
const shortDateFormatter = computed(() => new Intl.DateTimeFormat(locale.value, {
    month: 'short',
    day: 'numeric',
    timeZone: 'UTC',
}));

const dateValue = (value) => {
    const date = new Date(`${value}T00:00:00Z`);

    return Number.isNaN(date.getTime()) ? null : date;
};
const formatDate = (value) => {
    const date = dateValue(value);

    return date ? dateFormatter.value.format(date) : String(value || '');
};
const formatShortDate = (value) => {
    const date = dateValue(value);

    return date ? shortDateFormatter.value.format(date) : String(value || '');
};
const formatCount = (value) => value !== null
    && value !== undefined
    && value !== ''
    && Number.isSafeInteger(Number(value))
    ? numberFormatter.value.format(Number(value))
    : '—';
const formatCompactCount = (value) => Number.isFinite(Number(value))
    ? compactNumberFormatter.value.format(Number(value))
    : '—';
const periodLabel = computed(() => {
    const current = chartData.value.periods?.current;
    const previous = chartData.value.periods?.previous;

    if (!current || !previous) {
        return '';
    }

    return t('customers.growth.period', {
        currentStart: formatDate(current.start),
        currentEnd: formatDate(current.end),
        previousStart: formatDate(previous.start),
        previousEnd: formatDate(previous.end),
    });
});
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
        width: [3, 2],
        dashArray: [0, 6],
    },
    markers: {
        size: [3, 4],
        shape: ['circle', 'square'],
        strokeWidth: 0,
        hover: {
            sizeOffset: 2,
        },
    },
    legend: {
        show: false,
    },
    xaxis: {
        tickAmount: 12,
        tooltip: {
            enabled: false,
        },
        labels: {
            rotate: 0,
            hideOverlappingLabels: true,
            trim: true,
            formatter: formatShortDate,
        },
    },
    yaxis: {
        min: 0,
        forceNiceScale: true,
        decimalsInFloat: 0,
        labels: {
            formatter: formatCompactCount,
        },
    },
    tooltip: {
        shared: true,
        intersect: false,
        x: {
            formatter: formatDate,
        },
        y: {
            formatter: formatCount,
        },
    },
}));
</script>

<template>
    <ChartFrame
        :title="$t('customers.growth.title')"
        :subtitle="$t('customers.growth.subtitle')"
        :period-label="periodLabel"
        :series="chartData.series"
        :categories="chartData.categories"
        :error="chartError"
        :empty-message="$t('customers.growth.empty')"
        :error-message="$t('customers.growth.invalid')"
        :table-caption="$t('customers.growth.table_caption')"
        :category-label="$t('customers.growth.week_label')"
        :value-label="$t('customers.growth.value_label')"
        :value-formatter="formatCount"
        data-testid="customer-growth-trend"
    >
        <template #legend>
            <ul
                class="flex flex-wrap items-center gap-x-4 gap-y-2 text-[11px] text-stone-600 dark:text-neutral-300"
                :aria-label="$t('customers.growth.legend_label')"
            >
                <li class="inline-flex items-center gap-2">
                    <span class="inline-flex w-5 items-center" aria-hidden="true">
                        <span class="w-full border-t-[3px]" style="border-color: var(--chart-series-blue)"></span>
                        <span class="-ml-3 size-1.5 rounded-full" style="background-color: var(--chart-series-blue)"></span>
                    </span>
                    {{ chartData.series[0]?.name }}
                </li>
                <li class="inline-flex items-center gap-2">
                    <span class="inline-flex w-5 items-center" aria-hidden="true">
                        <span class="w-full border-t-2 border-dashed" style="border-color: var(--chart-series-violet)"></span>
                        <span class="-ml-3 size-1.5" style="background-color: var(--chart-series-violet)"></span>
                    </span>
                    {{ chartData.series[1]?.name }}
                </li>
            </ul>
        </template>

        <BaseApexChart
            type="line"
            :height="280"
            :series="chartData.series"
            :categories="chartData.categories"
            :options="chartOptions"
            :color-tones="['blue', 'violet']"
        />
    </ChartFrame>
</template>
