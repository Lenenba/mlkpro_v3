export const FINANCE_TREND_POINT_COUNT = 12;

const finiteChartValue = (value) => {
    if (value === null || value === undefined || value === '') {
        return null;
    }

    const number = Number(value);

    return Number.isFinite(number) ? number : null;
};

const normalizeTemporalFlow = (series, pointCount) => {
    if (!series || typeof series !== 'object' || Array.isArray(series)) {
        return null;
    }

    const labels = Array.isArray(series.labels) ? series.labels.map(String) : [];
    const values = Array.isArray(series.values) ? series.values.map(finiteChartValue) : [];
    const hasExpectedPoints = labels.length === pointCount && values.length === pointCount;
    const hasCompleteValues = values.every((value) => value !== null);
    const hasUniqueLabels = new Set(labels).size === labels.length;

    if (
        series.isTemporal !== true
        || series.historyStatus !== 'available'
        || series.measurement !== 'flow'
        || series.granularity !== 'month'
        || series.period?.comparisonMode !== 'aligned_month_to_date'
        || series.unit?.type !== 'currency'
        || !String(series.unit?.code || '')
        || !hasExpectedPoints
        || !hasCompleteValues
        || !hasUniqueLabels
    ) {
        return null;
    }

    return {
        labels,
        values,
        period: series.period && typeof series.period === 'object' ? series.period : {},
        unit: series.unit && typeof series.unit === 'object' ? series.unit : {},
    };
};

const sameCurrency = (firstUnit, secondUnit) => firstUnit?.type === 'currency'
    && secondUnit?.type === 'currency'
    && String(firstUnit.code || '') === String(secondUnit.code || '');

export const buildZeroInclusiveFinanceDomain = (series) => {
    const values = Array.isArray(series)
        ? series.flatMap((item) => Array.isArray(item?.data)
            ? item.data.map(finiteChartValue).filter((value) => value !== null)
            : [])
        : [];

    if (!values.length || values.every((value) => value === 0)) {
        return { min: 0, max: 1 };
    }

    return {
        min: Math.min(0, ...values),
        max: Math.max(0, ...values),
    };
};

export const buildFinanceHistoryChartData = ({
    revenueSeries,
    expenseSeries,
    includeExpenses = false,
    revenueLabel = 'Revenue',
    expenseLabel = 'Expenses',
    pointCount = FINANCE_TREND_POINT_COUNT,
} = {}) => {
    const revenue = normalizeTemporalFlow(revenueSeries, pointCount);

    if (!revenue) {
        return {
            labels: [],
            series: [],
            period: {},
            currencyCode: null,
        };
    }

    const chartSeries = [{ name: revenueLabel, data: revenue.values }];
    const expenses = includeExpenses
        ? normalizeTemporalFlow(expenseSeries, pointCount)
        : null;

    if (
        expenses
        && sameCurrency(revenue.unit, expenses.unit)
        && expenses.labels.every((label, index) => label === revenue.labels[index])
    ) {
        chartSeries.push({ name: expenseLabel, data: expenses.values });
    }

    return {
        labels: revenue.labels,
        series: chartSeries,
        period: revenue.period,
        currencyCode: revenue.unit?.code || null,
    };
};
