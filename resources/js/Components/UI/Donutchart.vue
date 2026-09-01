<script setup>
import { computed } from 'vue';
import BaseApexChart from '@/Components/Charts/BaseApexChart.vue';
import ChartFrame from '@/Components/Charts/ChartFrame.vue';
import {
    buildAccessibleSeriesFill,
    mergeChartOptions,
    normalizeDonutSeries,
} from '@/utils/chartTheme';

const props = defineProps({
    series: { type: Array, default: () => [] },
    categories: { type: Array, default: () => [] },
    height: { type: [Number, String], default: 260 },
    colors: { type: Array, default: () => [] },
    colorTones: { type: Array, default: () => [] },
    options: { type: Object, default: () => ({}) },
    title: { type: String, default: '' },
    subtitle: { type: String, default: '' },
    periodLabel: { type: String, default: '' },
    categoryLabel: { type: String, default: '' },
    valueLabel: { type: String, default: '' },
    unitLabel: { type: String, default: '' },
    totalLabel: { type: String, default: '' },
    tableCaption: { type: String, default: '' },
    valueFormatter: { type: Function, default: null },
    loading: { type: Boolean, default: false },
    error: { type: [Boolean, String, Error], default: false },
    loadingMessage: { type: String, default: '' },
    emptyMessage: { type: String, default: '' },
    errorMessage: { type: String, default: '' },
    tableOpenByDefault: { type: Boolean, default: false },
    framed: { type: Boolean, default: true },
});

const emit = defineEmits(['ready', 'render-error']);
const normalizedSeries = computed(() => normalizeDonutSeries(props.series));

const formatValue = (value) => {
    if (props.valueFormatter) {
        const formatted = props.valueFormatter(value);

        if (formatted !== null && formatted !== undefined && formatted !== '') {
            return formatted;
        }
    }

    const numericValue = Number(value);

    return Number.isFinite(numericValue) ? numericValue.toLocaleString() : '—';
};

const total = computed(() => normalizedSeries.value.reduce((sum, value) => sum + value, 0));

const resolvedOptions = computed(() => mergeChartOptions(
    {
        labels: props.categories,
        legend: {
            show: true,
            position: 'bottom',
            horizontalAlign: 'center',
        },
        dataLabels: {
            enabled: true,
            formatter: (percentage) => `${Math.round(Number(percentage) || 0)}%`,
            dropShadow: {
                enabled: false,
            },
            style: {
                fontSize: '11px',
                fontWeight: 600,
            },
        },
        plotOptions: {
            pie: {
                expandOnClick: false,
                dataLabels: {
                    minAngleToShowLabel: 12,
                },
                donut: {
                    size: '68%',
                    labels: {
                        show: true,
                        name: {
                            show: true,
                        },
                        value: {
                            show: true,
                            formatter: formatValue,
                        },
                        total: {
                            show: true,
                            showAlways: true,
                            label: props.totalLabel,
                            formatter: () => formatValue(total.value),
                        },
                    },
                },
            },
        },
        stroke: {
            width: 2,
            colors: ['transparent'],
        },
    },
    buildAccessibleSeriesFill(normalizedSeries.value.length),
    props.valueFormatter
        ? { tooltip: { y: { formatter: props.valueFormatter } } }
        : {},
    props.options,
));
</script>

<template>
    <ChartFrame
        :title="title"
        :subtitle="subtitle"
        :period-label="periodLabel"
        :series="normalizedSeries"
        :categories="categories"
        :loading="loading"
        :error="error"
        :loading-message="loadingMessage"
        :empty-message="emptyMessage"
        :error-message="errorMessage"
        :table-open-by-default="tableOpenByDefault"
        :table-caption="tableCaption"
        :category-label="categoryLabel"
        :value-label="valueLabel"
        :unit-label="unitLabel"
        :value-formatter="valueFormatter"
        :framed="framed"
    >
        <BaseApexChart
            :series="normalizedSeries"
            :height="height"
            :colors="colors"
            :color-tones="colorTones"
            :options="resolvedOptions"
            type="donut"
            @ready="emit('ready', $event)"
            @render-error="emit('render-error', $event)"
        />
    </ChartFrame>
</template>
