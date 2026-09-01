<script setup>
import { computed } from 'vue';
import BaseApexChart from '@/Components/Charts/BaseApexChart.vue';
import ChartFrame from '@/Components/Charts/ChartFrame.vue';
import { mergeChartOptions } from '@/utils/chartTheme';

const props = defineProps({
    series: { type: Array, default: () => [] },
    categories: { type: Array, default: () => [] },
    height: { type: [Number, String], default: 250 },
    colors: { type: Array, default: () => [] },
    colorTones: { type: Array, default: () => [] },
    options: { type: Object, default: () => ({}) },
    title: { type: String, default: '' },
    subtitle: { type: String, default: '' },
    periodLabel: { type: String, default: '' },
    categoryLabel: { type: String, default: '' },
    valueLabel: { type: String, default: '' },
    unitLabel: { type: String, default: '' },
    tableCaption: { type: String, default: '' },
    valueFormatter: { type: Function, default: null },
    loading: { type: Boolean, default: false },
    error: { type: [Boolean, String, Error], default: false },
    loadingMessage: { type: String, default: '' },
    emptyMessage: { type: String, default: '' },
    errorMessage: { type: String, default: '' },
    tableOpenByDefault: { type: Boolean, default: false },
    framed: { type: Boolean, default: true },
    curve: { type: String, default: 'smooth' },
});

const emit = defineEmits(['ready', 'render-error']);

const resolvedOptions = computed(() => mergeChartOptions(
    {
        legend: {
            show: props.series.length > 1,
            position: 'top',
            horizontalAlign: 'center',
        },
        stroke: {
            curve: props.curve,
            width: 2,
        },
        markers: {
            size: props.series.length === 1 ? 3 : 0,
        },
        xaxis: {
            axisBorder: { show: false },
            axisTicks: { show: false },
        },
    },
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
        :series="series"
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
            :series="series"
            :categories="categories"
            :height="height"
            :colors="colors"
            :color-tones="colorTones"
            :options="resolvedOptions"
            type="line"
            @ready="emit('ready', $event)"
            @render-error="emit('render-error', $event)"
        />
    </ChartFrame>
</template>
