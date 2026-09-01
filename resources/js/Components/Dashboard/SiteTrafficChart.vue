<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseApexChart from '@/Components/Charts/BaseApexChart.vue';
import ChartFrame from '@/Components/Charts/ChartFrame.vue';
import { buildSuperAdminTrafficChartData } from '@/utils/superAdminTrafficChart';

const props = defineProps({
    rows: {
        type: Array,
        default: () => [],
    },
});

const { locale, t } = useI18n();

const chartData = computed(() => buildSuperAdminTrafficChartData(props.rows, {
    totalLabel: t('super_admin.dashboard.site_traffic.legend_total'),
    uniqueLabel: t('super_admin.dashboard.site_traffic.legend_unique'),
}));
const chartError = computed(() => chartData.value.isValid
    ? false
    : t('super_admin.dashboard.site_traffic.invalid'));
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
const formatCount = (value) => Number.isFinite(Number(value))
    ? numberFormatter.value.format(Number(value))
    : '—';
const formatCompactCount = (value) => Number.isFinite(Number(value))
    ? compactNumberFormatter.value.format(Number(value))
    : '—';
const periodLabel = computed(() => {
    const period = chartData.value.period;

    if (!period) {
        return '';
    }

    return t('super_admin.dashboard.site_traffic.period', {
        start: formatDate(period.start),
        end: formatDate(period.end),
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
        tickAmount: Math.min(6, chartData.value.categories.length),
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
        :title="$t('super_admin.dashboard.site_traffic.trend_title')"
        :subtitle="$t('super_admin.dashboard.site_traffic.trend_subtitle')"
        :period-label="periodLabel"
        :series="chartData.series"
        :categories="chartData.categories"
        :error="chartError"
        :empty-message="$t('super_admin.dashboard.site_traffic.empty')"
        :error-message="$t('super_admin.dashboard.site_traffic.invalid')"
        :table-caption="$t('super_admin.dashboard.site_traffic.table_caption')"
        :category-label="$t('super_admin.dashboard.site_traffic.date_label')"
        :value-label="$t('super_admin.dashboard.site_traffic.value_label')"
        :value-formatter="formatCount"
        :framed="false"
        data-testid="superadmin-site-traffic-chart"
    >
        <template #legend>
            <ul
                class="flex flex-wrap items-center gap-x-4 gap-y-2 text-[11px] text-stone-600 dark:text-neutral-300"
                :aria-label="$t('super_admin.dashboard.site_traffic.legend_label')"
            >
                <li class="inline-flex items-center gap-2">
                    <span class="inline-flex w-5 items-center" aria-hidden="true">
                        <span class="w-full border-t-[3px]" style="border-color: var(--chart-series-emerald)"></span>
                        <span class="-ml-3 size-1.5 rounded-full" style="background-color: var(--chart-series-emerald)"></span>
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
            :color-tones="['emerald', 'violet']"
        />
    </ChartFrame>
</template>
