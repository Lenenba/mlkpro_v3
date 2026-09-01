<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Barchart from '@/Components/UI/Barchart.vue';

const props = defineProps({
    chartData: {
        type: Object,
        default: () => ({
            available: false,
            categories: [],
            entryCounts: [],
            batchCounts: [],
        }),
    },
});

const { locale, t } = useI18n();

const series = computed(() => ([
    {
        name: t('accounting.journal.summary.entry_count'),
        data: props.chartData.entryCounts || [],
    },
    {
        name: t('accounting.journal.summary.batch_count'),
        data: props.chartData.batchCounts || [],
    },
]));
const periodLabel = computed(() => {
    const categories = props.chartData.categories || [];

    if (categories.length < 2) {
        return '';
    }

    return t('accounting.periods.activity_chart.period_range', {
        start: categories[0],
        end: categories[categories.length - 1],
    });
});
const formatCount = (value) => {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    const numeric = Number(value);

    return Number.isFinite(numeric)
        ? numeric.toLocaleString(locale.value || undefined, { maximumFractionDigits: 0 })
        : '—';
};
const chartOptions = computed(() => ({
    dataLabels: {
        enabled: false,
    },
    xaxis: {
        labels: {
            rotate: 0,
            hideOverlappingLabels: true,
            trim: true,
        },
        tooltip: {
            enabled: false,
        },
    },
    yaxis: {
        min: 0,
        forceNiceScale: true,
        decimalsInFloat: 0,
        labels: {
            formatter: formatCount,
        },
    },
    tooltip: {
        shared: true,
        intersect: false,
        y: {
            formatter: formatCount,
        },
    },
}));
</script>

<template>
    <Barchart
        :series="series"
        :categories="chartData.categories"
        :height="280"
        :options="chartOptions"
        :color-tones="['blue', 'violet']"
        :title="$t('accounting.periods.activity_chart.title')"
        :subtitle="$t('accounting.periods.activity_chart.subtitle')"
        :period-label="periodLabel"
        :category-label="$t('accounting.periods.activity_chart.period_label')"
        :value-label="$t('accounting.periods.activity_chart.count_label')"
        :table-caption="$t('accounting.periods.activity_chart.table_caption')"
        :value-formatter="formatCount"
        :stacked="false"
        :framed="false"
        data-testid="accounting-activity-chart"
    />
</template>
