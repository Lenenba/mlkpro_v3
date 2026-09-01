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

const normalizedCurrency = (value) => typeof value === 'string'
    ? value.trim().toUpperCase()
    : '';

const minorUnits = (value) => Math.round(value * 100);
const hasEqualMinorUnits = (left, right) => minorUnits(left) === minorUnits(right);

const invalidChartData = () => ({
    isValid: false,
    categories: [],
    series: [],
    rows: [],
    displayedCount: 0,
});

export const buildQuoteValueChartData = (items, {
    filteredCount,
    filteredTotal,
    filteredCurrencyCodes,
    currencyCode,
    labelForItem = (item) => item?.number || '',
    seriesLabel = '',
} = {}) => {
    const count = finiteNumber(filteredCount);
    const total = finiteNumber(filteredTotal);
    const expectedCurrency = normalizedCurrency(currencyCode);
    const rawCurrencyCodes = Array.isArray(filteredCurrencyCodes) ? filteredCurrencyCodes : null;
    const currencyCodes = rawCurrencyCodes?.map(normalizedCurrency) ?? [];
    const hasValidCurrencyContract = rawCurrencyCodes !== null
        && currencyCodes.every((code) => code !== '')
        && new Set(currencyCodes).size === currencyCodes.length
        && (count === 0
            ? currencyCodes.length === 0
            : currencyCodes.length === 1 && currencyCodes[0] === expectedCurrency);

    if (!Array.isArray(items) || count === null || count < 0 || !Number.isInteger(count)
        || total === null || total < 0 || !expectedCurrency || !hasValidCurrencyContract
        || items.length !== Math.min(5, count)) {
        return invalidChartData();
    }

    const identifiers = new Set();
    const rows = [];

    for (const item of items) {
        const identifier = String(item?.id ?? '').trim();
        const value = finiteNumber(item?.total);
        const label = String(labelForItem(item) || '').trim();

        if (!identifier || identifiers.has(identifier) || value === null || value < 0 || !label
            || normalizedCurrency(item?.currency_code) !== expectedCurrency) {
            return invalidChartData();
        }

        identifiers.add(identifier);
        rows.push({
            id: identifier,
            label,
            value,
        });
    }

    if (rows.some((row, index) => index > 0 && rows[index - 1].value < row.value)) {
        return invalidChartData();
    }

    const topTotal = rows.reduce((sum, row) => sum + row.value, 0);

    if (minorUnits(topTotal) > minorUnits(total)
        || (count <= 5 && !hasEqualMinorUnits(topTotal, total))) {
        return invalidChartData();
    }

    return {
        isValid: true,
        categories: rows.map((row) => row.label),
        series: [{ name: seriesLabel, data: rows.map((row) => row.value) }],
        rows,
        displayedCount: rows.length,
    };
};
