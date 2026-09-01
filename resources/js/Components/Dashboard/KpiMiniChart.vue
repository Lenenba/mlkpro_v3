<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import {
    KPI_MINI_CHART_TYPES,
    buildKpiChartGeometry,
    normalizeKpiMiniChart,
    resolveKpiChartTone,
} from '@/utils/kpiChart.js';

const VIEWBOX_WIDTH = 160;
const MIN_HEIGHT = 40;
const MAX_HEIGHT = 48;
const GEOMETRY_PADDING = 4;
const EMPTY_CHART = {
    type: 'column',
    values: [],
    isLegacy: false,
};

const props = defineProps({
    label: {
        type: String,
        default: '',
    },
    chart: {
        type: Object,
        default: null,
    },
    points: {
        type: Array,
        default: () => [],
    },
    tone: {
        type: String,
        default: 'neutral',
    },
    height: {
        type: Number,
        default: MIN_HEIGHT,
    },
});

const { locale, t } = useI18n();

const normalizedChart = computed(() => (
    normalizeKpiMiniChart({
        chart: props.chart,
        points: props.points,
    }) ?? EMPTY_CHART
));
const chartHeight = computed(() => Math.min(
    MAX_HEIGHT,
    Math.max(MIN_HEIGHT, Number(props.height) || MIN_HEIGHT),
));
const supportedTypes = Array.isArray(KPI_MINI_CHART_TYPES)
    ? KPI_MINI_CHART_TYPES
    : Object.values(KPI_MINI_CHART_TYPES);
const chartType = computed(() => supportedTypes.includes(normalizedChart.value.type)
    ? normalizedChart.value.type
    : 'column');
const chartTone = computed(() => resolveKpiChartTone(
    props.chart?.tone ?? props.tone,
));
const geometry = computed(() => normalizedChart.value.values.length
    ? buildKpiChartGeometry(
        normalizedChart.value.values,
        chartType.value,
        {
            width: VIEWBOX_WIDTH,
            height: chartHeight.value,
            padding: GEOMETRY_PADDING,
        },
    )
    : null);
const hasData = computed(() => geometry.value !== null);
const hasSignedDomain = computed(() => geometry.value !== null
    && geometry.value.domain.min < 0
    && geometry.value.domain.max > 0);
const showReferenceLine = computed(() => geometry.value !== null
    && geometry.value.zeroY !== null
    && (chartType.value !== 'line' || hasSignedDomain.value));
const lastPoint = computed(() => geometry.value?.points.at(-1) ?? null);
const accessibleSummary = computed(() => {
    const explicitLabel = String(normalizedChart.value.ariaLabel ?? '').trim();
    if (explicitLabel !== '') {
        return explicitLabel;
    }

    const values = normalizedChart.value.values;
    if (!values.length) {
        return '';
    }

    const formatter = new Intl.NumberFormat(locale.value, {
        maximumFractionDigits: 2,
    });

    return t('charts.mini_summary', {
        label: String(props.label).trim() || t('charts.default_title'),
        count: values.length,
        first: formatter.format(values[0]),
        last: formatter.format(values.at(-1)),
    });
});
const rootStyle = computed(() => ({
    color: `var(--chart-series-${chartTone.value})`,
    height: `${chartHeight.value}px`,
}));
const strokeStyle = {
    strokeWidth: 'var(--chart-stroke-width)',
};
const gridStrokeStyle = {
    stroke: 'var(--chart-grid)',
    strokeWidth: 'var(--chart-grid-stroke-width)',
};
</script>

<template>
    <div
        v-if="hasData"
        class="block w-full min-w-0 overflow-hidden"
        :style="rootStyle"
        :data-chart-type="chartType"
        :data-chart-tone="chartTone"
        :data-chart-source="normalizedChart.isLegacy ? 'legacy' : 'structured'"
    >
        <span v-if="accessibleSummary" class="sr-only">{{ accessibleSummary }}</span>
        <svg
            class="block size-full"
            :viewBox="`0 0 ${geometry.width} ${geometry.height}`"
            preserveAspectRatio="none"
            role="presentation"
            focusable="false"
            aria-hidden="true"
        >
            <line
                v-if="showReferenceLine"
                :x1="geometry.padding"
                :x2="geometry.width - geometry.padding"
                :y1="geometry.zeroY"
                :y2="geometry.zeroY"
                vector-effect="non-scaling-stroke"
                :style="gridStrokeStyle"
            />

            <template v-if="chartType === 'column'">
                <rect
                    v-for="(column, index) in geometry.columns"
                    :key="index"
                    :x="column.x"
                    :y="column.y"
                    :width="column.width"
                    :height="column.height"
                    rx="1"
                    fill="currentColor"
                />
            </template>

            <template v-else>
                <path
                    v-if="chartType === 'area' && geometry.areaPath"
                    :d="geometry.areaPath"
                    fill="currentColor"
                    :style="{ fillOpacity: 'var(--chart-area-opacity)' }"
                />
                <path
                    v-if="geometry.linePath"
                    :d="geometry.linePath"
                    fill="none"
                    stroke="currentColor"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    vector-effect="non-scaling-stroke"
                    :style="strokeStyle"
                />
                <circle
                    v-if="lastPoint"
                    :cx="lastPoint.x"
                    :cy="lastPoint.y"
                    r="2"
                    fill="currentColor"
                />
            </template>
        </svg>
    </div>
</template>
