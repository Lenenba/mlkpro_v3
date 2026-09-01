const finiteNumber = (value) => {
    if (
        value === null
        || value === undefined
        || typeof value === 'boolean'
        || (typeof value === 'string' && value.trim() === '')
    ) {
        return null;
    }

    const numeric = Number(value);

    return Number.isFinite(numeric) ? numeric : null;
};

const minorUnits = (value) => Math.round(value * 100);
const hasEqualMinorUnits = (left, right) => minorUnits(left) === minorUnits(right);

const invalidChartData = () => ({
    isValid: false,
    categories: [],
    values: [],
    series: [],
    rows: [],
    total: 0,
});

export const buildExpenseBreakdownChartData = (breakdown, {
    expectedTotal,
    labelForItem = (item) => item?.label || item?.key || '',
    seriesLabel = '',
} = {}) => {
    const rows = Array.isArray(breakdown?.rows) ? breakdown.rows : null;
    const total = finiteNumber(expectedTotal);
    const coveredTotal = finiteNumber(breakdown?.covered_total);
    const otherTotal = finiteNumber(breakdown?.other_total);

    if (!rows || total === null || total < 0 || coveredTotal === null || coveredTotal < 0
        || otherTotal === null || otherTotal < 0 || typeof breakdown?.is_truncated !== 'boolean') {
        return invalidChartData();
    }

    const normalizedRows = [];
    const identifiers = new Set();

    for (const item of rows) {
        const key = typeof item?.key === 'string' ? item.key.trim() : '';
        const label = String(labelForItem(item) || '').trim();
        const value = finiteNumber(item?.total);
        const count = finiteNumber(item?.count);

        if (!key || identifiers.has(key) || !label || value === null || value < 0
            || count === null || count < 0 || !Number.isInteger(count)) {
            return invalidChartData();
        }

        identifiers.add(key);
        normalizedRows.push({
            key,
            label,
            value,
            count,
            isRemainder: item?.is_remainder === true,
        });
    }

    const displayedTotal = normalizedRows.reduce((sum, row) => sum + row.value, 0);
    const remainderRows = normalizedRows.filter((row) => row.isRemainder);

    if (!hasEqualMinorUnits(displayedTotal, total)
        || !hasEqualMinorUnits(coveredTotal + otherTotal, total)
        || (breakdown.is_truncated && (remainderRows.length !== 1
            || !hasEqualMinorUnits(remainderRows[0].value, otherTotal)))
        || (!breakdown.is_truncated && (remainderRows.length > 0 || !hasEqualMinorUnits(otherTotal, 0)))) {
        return invalidChartData();
    }

    const categories = normalizedRows.map((row) => row.label);
    const values = normalizedRows.map((row) => row.value);

    return {
        isValid: true,
        categories,
        values,
        series: [{ name: seriesLabel, data: values }],
        rows: normalizedRows,
        total,
    };
};
