const emptyChartData = (isValid = true) => ({
    categories: [],
    series: [],
    details: [],
    isValid,
});

const isCount = (value) => Number.isSafeInteger(value) && value >= 0;

const percentage = (value, total) => (
    total > 0 ? Number(((value / total) * 100).toFixed(1)) : 0
);

export const buildServiceRequestSourceChartData = (
    rows,
    {
        expectedTotal = 0,
        labelForSource = (source) => source,
        totalLabel = 'Requests',
    } = {},
) => {
    if (!Array.isArray(rows) || !isCount(expectedTotal)) {
        return emptyChartData(false);
    }

    if (!rows.length) {
        return emptyChartData(expectedTotal === 0);
    }

    const normalized = [];
    const seenSources = new Set();

    for (const row of rows) {
        const source = typeof row?.source === 'string' ? row.source.trim() : '';
        const total = row?.total;
        const category = source ? String(labelForSource(source, row) ?? '').trim() : '';

        if (!source || !category || !isCount(total) || seenSources.has(source)) {
            return emptyChartData(false);
        }

        normalized.push({
            key: source,
            category,
            total,
        });
        seenSources.add(source);
    }

    const observedTotal = normalized.reduce((sum, row) => sum + row.total, 0);

    if (observedTotal !== expectedTotal || expectedTotal === 0) {
        return emptyChartData(false);
    }

    const details = normalized.map((row) => ({
        ...row,
        share: percentage(row.total, expectedTotal),
    }));

    return {
        categories: details.map((row) => row.category),
        series: [{
            name: totalLabel,
            data: details.map((row) => row.total),
        }],
        details,
        isValid: true,
    };
};
