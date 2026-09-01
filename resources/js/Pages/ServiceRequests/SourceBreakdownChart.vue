<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Barchart from '@/Components/UI/Barchart.vue';
import { serviceRequestSourceLabel } from '@/utils/serviceRequestPresentation';
import { buildServiceRequestSourceChartData } from '@/utils/serviceRequestSourceChart';

const props = defineProps({
    rows: {
        type: Array,
        default: () => [],
    },
    total: {
        type: Number,
        default: 0,
    },
});

const { locale, t } = useI18n();
const chartData = computed(() => buildServiceRequestSourceChartData(props.rows, {
    expectedTotal: props.total,
    labelForSource: (source) => serviceRequestSourceLabel(source, t),
    totalLabel: t('service_requests.source_chart.series'),
}));
const chartError = computed(() => chartData.value.isValid
    ? false
    : t('service_requests.source_chart.invalid'));
const chartHeight = computed(() => Math.min(
    420,
    Math.max(220, (chartData.value.categories.length * 52) + 80),
));
const countFormatter = computed(() => new Intl.NumberFormat(locale.value));
const percentFormatter = computed(() => new Intl.NumberFormat(locale.value, {
    maximumFractionDigits: 1,
}));
const formatCount = (value) => Number.isFinite(Number(value))
    ? countFormatter.value.format(Number(value))
    : '—';
const formatValue = (value, context = {}) => {
    const count = formatCount(value);
    const detail = Number.isInteger(context.rowIndex)
        ? chartData.value.details[context.rowIndex]
        : null;

    if (!detail) {
        return count;
    }

    return t('service_requests.source_chart.count_and_share', {
        count,
        share: percentFormatter.value.format(detail.share),
    });
};
const chartOptions = computed(() => ({
    legend: {
        show: false,
    },
    dataLabels: {
        enabled: false,
    },
    plotOptions: {
        bar: {
            horizontal: true,
            barHeight: '56%',
        },
    },
    xaxis: {
        min: 0,
        forceNiceScale: true,
        tickAmount: 4,
        labels: {
            formatter: formatCount,
        },
    },
    tooltip: {
        y: {
            formatter: formatCount,
        },
    },
}));
</script>

<template>
    <Barchart
        :title="$t('service_requests.side.sources')"
        :subtitle="$t('service_requests.source_chart.subtitle', { count: formatCount(total) })"
        :series="chartData.series"
        :categories="chartData.categories"
        :height="chartHeight"
        :options="chartOptions"
        :color-tones="['emerald']"
        :value-formatter="formatValue"
        :category-label="$t('service_requests.source_chart.category_label')"
        :value-label="$t('service_requests.source_chart.value_label')"
        :table-caption="$t('service_requests.source_chart.table_caption')"
        :empty-message="$t('service_requests.source_chart.empty')"
        :error="chartError"
        :error-message="$t('service_requests.source_chart.invalid')"
        :framed="false"
        horizontal
        data-testid="service-request-source-chart"
    />
</template>
