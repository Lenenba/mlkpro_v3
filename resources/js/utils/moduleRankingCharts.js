const emptyChartData = () => ({
    categories: [],
    series: [],
    details: [],
});

const normalizedIdentifier = (value) => {
    if (typeof value === 'number' && Number.isInteger(value) && value >= 0) {
        return String(value);
    }

    if (typeof value !== 'string') {
        return null;
    }

    const identifier = value.trim();

    return identifier || null;
};

const normalizedLabel = (value) => {
    if (typeof value !== 'string') {
        return null;
    }

    const label = value.trim();

    return label || null;
};

const nonNegativeInteger = (value) => (
    typeof value === 'number' && Number.isInteger(value) && value >= 0
        ? value
        : null
);

const normalizeRows = (rows, normalizer) => {
    if (!Array.isArray(rows) || !rows.length) {
        return [];
    }

    const normalized = rows.map(normalizer);

    if (normalized.some((row) => row === null)) {
        return null;
    }

    const keys = normalized.map((row) => row.key);

    return new Set(keys).size === keys.length ? normalized : null;
};

const resolvedRowLabel = (resolver, row) => normalizedLabel(
    typeof resolver === 'function' ? resolver(row) : row?.name,
);

const disambiguateDuplicateCategories = (rows) => {
    const categoryCounts = rows.reduce((counts, row) => {
        counts.set(row.category, (counts.get(row.category) ?? 0) + 1);

        return counts;
    }, new Map());

    return rows.map((row) => categoryCounts.get(row.category) > 1
        ? { ...row, category: `${row.category} (#${row.key})` }
        : row);
};

export const buildCustomerActivityChartData = (rows, {
    labelForCustomer,
    quotesEnabled = true,
    jobsEnabled = true,
    quotesLabel = 'Quotes',
    jobsLabel = 'Jobs',
} = {}) => {
    if (!quotesEnabled && !jobsEnabled) {
        return emptyChartData();
    }

    const normalizedQuotesLabel = normalizedLabel(quotesLabel);
    const normalizedJobsLabel = normalizedLabel(jobsLabel);

    if (
        (quotesEnabled && !normalizedQuotesLabel)
        || (jobsEnabled && !normalizedJobsLabel)
    ) {
        return emptyChartData();
    }

    const normalized = normalizeRows(rows, (row) => {
        const key = normalizedIdentifier(row?.id);
        const category = resolvedRowLabel(labelForCustomer, row);
        const quotes = quotesEnabled ? nonNegativeInteger(row?.quotes_count) : 0;
        const jobs = jobsEnabled ? nonNegativeInteger(row?.works_count) : 0;

        return key && category && quotes !== null && jobs !== null
            ? {
                key,
                category,
                quotes,
                jobs,
                total: quotes + jobs,
            }
            : null;
    });

    if (!normalized?.length) {
        return emptyChartData();
    }

    const disambiguated = disambiguateDuplicateCategories(normalized);
    const series = [];

    if (quotesEnabled) {
        series.push({
            name: normalizedQuotesLabel,
            data: disambiguated.map((row) => row.quotes),
        });
    }

    if (jobsEnabled) {
        series.push({
            name: normalizedJobsLabel,
            data: disambiguated.map((row) => row.jobs),
        });
    }

    return {
        categories: disambiguated.map((row) => row.category),
        series,
        details: disambiguated,
    };
};

export const buildProductUsageChartData = (rows, {
    labelForProduct,
    usageLabel = 'Used quantity',
} = {}) => {
    const normalizedUsageLabel = normalizedLabel(usageLabel);

    if (!normalizedUsageLabel) {
        return emptyChartData();
    }

    const normalized = normalizeRows(rows, (row) => {
        const key = normalizedIdentifier(row?.id);
        const category = resolvedRowLabel(labelForProduct, row);
        const quantity = nonNegativeInteger(row?.quantity);

        return key && category && quantity !== null
            ? { key, category, quantity }
            : null;
    });

    if (!normalized?.length) {
        return emptyChartData();
    }

    const disambiguated = disambiguateDuplicateCategories(normalized);

    return {
        categories: disambiguated.map((row) => row.category),
        series: [{
            name: normalizedUsageLabel,
            data: disambiguated.map((row) => row.quantity),
        }],
        details: disambiguated,
    };
};
