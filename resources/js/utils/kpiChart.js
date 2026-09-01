export const KPI_MINI_CHART_TYPES = Object.freeze(['column', 'line', 'area']);
export const KPI_MINI_CHART_MIN_POINTS = 4;
export { resolveKpiChartTone } from './kpiTone.js';

const isRecord = (value) => value !== null && typeof value === 'object' && !Array.isArray(value);
const monthKeyPattern = /^(\d{4})-(0[1-9]|1[0-2])$/u;

const followingMonthKey = (monthKey) => {
    const [, rawYear, rawMonth] = monthKey.match(monthKeyPattern) || [];
    const year = Number(rawYear);
    const month = Number(rawMonth);

    if (!year || !month) {
        return null;
    }

    return `${month === 12 ? year + 1 : year}-${String(month === 12 ? 1 : month + 1).padStart(2, '0')}`;
};

const hasContinuousMonthlyLabels = (labels) => labels.every((label, index) => (
    monthKeyPattern.test(label)
    && (index === 0 || followingMonthKey(labels[index - 1]) === label)
));

const finiteNumber = (value) => {
    if (
        value === null
        || value === undefined
        || typeof value === 'boolean'
        || (typeof value === 'string' && value.trim() === '')
    ) {
        return null;
    }

    const number = Number(value);

    if (!Number.isFinite(number)) {
        return null;
    }

    return Object.is(number, -0) ? 0 : number;
};

const finiteEntries = (values) => {
    if (!Array.isArray(values)) {
        return [];
    }

    return values.flatMap((value, index) => {
        const number = finiteNumber(value);

        return number === null ? [] : [{ index, value: number }];
    });
};

const modelExtent = (values) => ({
    minValue: Math.min(...values),
    maxValue: Math.max(...values),
    hasNegativeValues: values.some((value) => value < 0),
    hasPositiveValues: values.some((value) => value > 0),
    hasZeroValues: values.some((value) => value === 0),
});

const structuredChartModel = (chart) => {
    if (!isRecord(chart) || !KPI_MINI_CHART_TYPES.includes(chart.type)) {
        return null;
    }

    const series = isRecord(chart.series) ? chart.series : chart;
    if (series.isTemporal !== true || series.historyStatus !== 'available') {
        return null;
    }

    const rawValues = Array.isArray(series.values) ? series.values : [];
    const rawLabels = Array.isArray(series.labels) ? series.labels : [];
    const values = rawValues.map((value) => finiteNumber(value));
    const labels = rawLabels.map((label) => typeof label === 'string' ? label.trim() : '');
    const hasCompleteValues = values.length >= KPI_MINI_CHART_MIN_POINTS
        && values.every((value) => value !== null);
    const hasAlignedLabels = labels.length === values.length
        && labels.every((label) => label !== '')
        && new Set(labels).size === labels.length;
    const hasValidTimeline = series.granularity !== 'month'
        || hasContinuousMonthlyLabels(labels);

    if (!hasCompleteValues || !hasAlignedLabels || !hasValidTimeline) {
        return null;
    }

    return {
        type: chart.type,
        values,
        labels,
        unit: series.unit ?? null,
        period: series.period ?? null,
        granularity: series.granularity ?? null,
        measurement: series.measurement ?? null,
        historyStatus: series.historyStatus,
        semanticDirection: series.semanticDirection ?? null,
        comparison: series.comparison ?? null,
        ariaLabel: chart.ariaLabel ?? null,
        isTemporal: true,
        isLegacy: false,
        pointCount: values.length,
        ...modelExtent(values),
    };
};

const legacyChartModel = (points) => {
    if (!Array.isArray(points)) {
        return null;
    }

    const entries = points.map((point, index) => {
        const source = isRecord(point) ? point.value : point;
        const value = finiteNumber(source);

        if (value === null) {
            return null;
        }

        return {
            index,
            value,
            label: isRecord(point) ? String(point.label ?? '') : '',
        };
    });

    if (
        entries.length < KPI_MINI_CHART_MIN_POINTS
        || entries.some((entry) => entry === null)
    ) {
        return null;
    }

    const values = entries.map((entry) => entry.value);

    return {
        type: 'column',
        values,
        labels: entries.map((entry) => entry.label),
        unit: null,
        period: null,
        granularity: null,
        measurement: null,
        historyStatus: null,
        semanticDirection: null,
        comparison: null,
        ariaLabel: null,
        isTemporal: null,
        isLegacy: true,
        pointCount: values.length,
        ...modelExtent(values),
    };
};

export const normalizeKpiMiniChart = ({ chart = null, points = [] } = {}) => {
    if (chart !== null && chart !== undefined) {
        return structuredChartModel(chart);
    }

    return legacyChartModel(points);
};

const rounded = (value) => Number(value.toFixed(3));

const positiveDimension = (value, fallback) => {
    const number = finiteNumber(value);

    return number !== null && number > 0 ? number : fallback;
};

const geometryDomain = (values, type) => {
    const minimum = Math.min(...values);
    const maximum = Math.max(...values);

    if (type === 'column' || type === 'area') {
        const min = Math.min(0, minimum);
        const max = Math.max(0, maximum);

        return min === max ? { min: 0, max: 1 } : { min, max };
    }

    if (minimum === maximum) {
        const offset = Math.max(Math.abs(minimum) * 0.05, 1);

        return {
            min: minimum - offset,
            max: maximum + offset,
        };
    }

    const offset = (maximum - minimum) * 0.05;

    return {
        min: minimum - offset,
        max: maximum + offset,
    };
};

export const buildKpiChartGeometry = (
    values,
    type,
    { width = 120, height = 40, padding = 2 } = {},
) => {
    if (!KPI_MINI_CHART_TYPES.includes(type)) {
        return null;
    }

    const numbers = finiteEntries(values).map((entry) => entry.value);
    if (!numbers.length) {
        return null;
    }

    const resolvedWidth = positiveDimension(width, 120);
    const resolvedHeight = positiveDimension(height, 40);
    const requestedPadding = Math.max(0, finiteNumber(padding) ?? 2);
    const maximumPadding = Math.max(0, (Math.min(resolvedWidth, resolvedHeight) - 1) / 2);
    const resolvedPadding = Math.min(requestedPadding, maximumPadding);
    const plotWidth = resolvedWidth - (resolvedPadding * 2);
    const plotHeight = resolvedHeight - (resolvedPadding * 2);
    const domain = geometryDomain(numbers, type);
    const domainSpan = domain.max - domain.min;
    const scaleY = (value) => resolvedPadding + (((domain.max - value) / domainSpan) * plotHeight);
    const zeroY = domain.min <= 0 && domain.max >= 0 ? rounded(scaleY(0)) : null;
    const xStep = numbers.length > 1 ? plotWidth / (numbers.length - 1) : 0;
    const points = numbers.map((value, index) => ({
        x: rounded(numbers.length === 1
            ? resolvedPadding + (plotWidth / 2)
            : resolvedPadding + (xStep * index)),
        y: rounded(scaleY(value)),
        value,
    }));
    const linePath = type === 'line' || type === 'area'
        ? points.map((point, index) => `${index === 0 ? 'M' : 'L'} ${point.x} ${point.y}`).join(' ')
        : '';
    const areaPath = type === 'area' && zeroY !== null
        ? `M ${points[0].x} ${zeroY} ${linePath.replace(/^M/u, 'L')} L ${points[points.length - 1].x} ${zeroY} Z`
        : '';

    let columns = [];
    if (type === 'column') {
        const slotWidth = plotWidth / numbers.length;
        const gap = Math.min(2, slotWidth * 0.25);
        const columnWidth = Math.max(0, slotWidth - gap);

        columns = numbers.map((value, index) => {
            const valueY = scaleY(value);
            const baselineY = zeroY ?? scaleY(0);

            return {
                x: rounded(resolvedPadding + (slotWidth * index) + (gap / 2)),
                y: rounded(Math.min(valueY, baselineY)),
                width: rounded(columnWidth),
                height: rounded(Math.abs(valueY - baselineY)),
                value,
            };
        });
    }

    return {
        width: resolvedWidth,
        height: resolvedHeight,
        padding: resolvedPadding,
        domain,
        zeroY,
        columns,
        points,
        linePath,
        areaPath,
    };
};
