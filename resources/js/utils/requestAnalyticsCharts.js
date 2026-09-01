const emptyChartData = () => ({
    categories: [],
    series: [],
    details: [],
});

const normalizedKey = (value) => {
    if (typeof value !== 'string') {
        return null;
    }

    const key = value.trim();

    return key || null;
};

const nonNegativeInteger = (value) => {
    return typeof value === 'number' && Number.isInteger(value) && value >= 0
        ? value
        : null;
};

const percentage = (value) => {
    return typeof value === 'number' && Number.isFinite(value) && value >= 0 && value <= 100
        ? value
        : null;
};

const expectedRate = (part, total) => total > 0
    ? Math.round((part / total) * 1000) / 10
    : 0;

const hasMatchingRate = (part, total, rate) => expectedRate(part, total) === rate;

const resolvedLabel = (resolver, key, row) => {
    const value = typeof resolver === 'function'
        ? resolver(key, row)
        : row?.label || key;

    return normalizedKey(value);
};

const normalizeRows = (rows, normalizer, requireUniqueCategories = true) => {
    if (!Array.isArray(rows) || !rows.length) {
        return [];
    }

    const normalized = Array.from(rows, normalizer);

    if (normalized.some((row) => row === null)) {
        return null;
    }

    const keys = normalized.map((row) => row.key);
    const categories = normalized.map((row) => row.category);

    if (
        new Set(keys).size !== keys.length
        || (requireUniqueCategories && new Set(categories).size !== categories.length)
    ) {
        return null;
    }

    return normalized;
};

export const buildProspectStatusChartData = (rows, {
    labelForStatus,
    totalLabel = 'Prospects',
} = {}) => {
    const normalized = normalizeRows(rows, (row) => {
        const key = normalizedKey(row?.status);
        const total = nonNegativeInteger(row?.total);
        const category = key ? resolvedLabel(labelForStatus, key, row) : null;

        return key && category && total !== null
            ? { key, category, total }
            : null;
    });

    if (!normalized?.length) {
        return emptyChartData();
    }

    return {
        categories: normalized.map((row) => row.category),
        series: [{ name: totalLabel, data: normalized.map((row) => row.total) }],
        details: normalized,
    };
};

export const buildProspectSourceChartData = (rows, {
    labelForSource,
    totalLabel = 'Prospects',
    convertedLabel = 'Converted',
} = {}) => {
    const normalized = normalizeRows(rows, (row) => {
        const key = normalizedKey(row?.source);
        const total = nonNegativeInteger(row?.total);
        const converted = nonNegativeInteger(row?.converted);
        const won = nonNegativeInteger(row?.won);
        const lost = nonNegativeInteger(row?.lost);
        const rate = percentage(row?.rate);
        const category = key ? resolvedLabel(labelForSource, key, row) : null;
        const countsAreConsistent = total !== null
            && converted !== null
            && won !== null
            && lost !== null
            && converted <= total
            && won <= total
            && lost <= total
            && converted + won + lost <= total;

        if (
            !key
            || !category
            || !countsAreConsistent
            || rate === null
            || !hasMatchingRate(converted, total, rate)
        ) {
            return null;
        }

        return { key, category, total, converted, won, lost, rate };
    });

    if (!normalized?.length) {
        return emptyChartData();
    }

    return {
        categories: normalized.map((row) => row.category),
        series: [
            { name: totalLabel, data: normalized.map((row) => row.total) },
            { name: convertedLabel, data: normalized.map((row) => row.converted) },
        ],
        details: normalized,
    };
};

export const buildProspectAssigneeChartData = (rows, {
    labelForAssignee,
    totalLabel = 'Prospects',
    overdueLabel = 'Overdue',
} = {}) => {
    const normalized = normalizeRows(rows, (row) => {
        const key = row?.assignee_id === null
            ? 'unassigned'
            : (nonNegativeInteger(row?.assignee_id) !== null
                ? String(row.assignee_id)
                : null);
        const total = nonNegativeInteger(row?.total);
        const dueToday = nonNegativeInteger(row?.due_today);
        const overdue = nonNegativeInteger(row?.overdue);
        const won = nonNegativeInteger(row?.won);
        const lost = nonNegativeInteger(row?.lost);
        const converted = nonNegativeInteger(row?.converted);
        const category = key
            ? normalizedKey(typeof labelForAssignee === 'function'
                ? labelForAssignee(key, row)
                : row?.name)
            : null;
        const countsAreConsistent = total !== null
            && dueToday !== null
            && overdue !== null
            && won !== null
            && lost !== null
            && converted !== null
            && dueToday <= total
            && overdue <= total
            && won + lost + converted <= total;

        if (!key || !category || !countsAreConsistent) {
            return null;
        }

        return {
            key,
            category,
            total,
            dueToday,
            overdue,
            won,
            lost,
            converted,
        };
    }, false);

    if (!normalized?.length) {
        return emptyChartData();
    }

    const categoryCounts = normalized.reduce((counts, row) => {
        counts.set(row.category, (counts.get(row.category) ?? 0) + 1);

        return counts;
    }, new Map());
    const disambiguated = normalized.map((row) => categoryCounts.get(row.category) > 1
        ? { ...row, category: `${row.category} (#${row.key})` }
        : row);

    return {
        categories: disambiguated.map((row) => row.category),
        series: [
            { name: totalLabel, data: disambiguated.map((row) => row.total) },
            { name: overdueLabel, data: disambiguated.map((row) => row.overdue) },
        ],
        details: disambiguated,
    };
};

export const buildRequestSourceChartData = (rows, {
    labelForSource,
    rateLabel = 'Conversion rate',
} = {}) => {
    const normalized = normalizeRows(rows, (row) => {
        const key = normalizedKey(row?.source);
        const total = nonNegativeInteger(row?.total);
        const won = nonNegativeInteger(row?.won);
        const rate = percentage(row?.rate);
        const category = key ? resolvedLabel(labelForSource, key, row) : null;
        const countsAreConsistent = total !== null && won !== null && won <= total;

        if (
            !key
            || !category
            || !countsAreConsistent
            || rate === null
            || !hasMatchingRate(won, total, rate)
        ) {
            return null;
        }

        return { key, category, total, won, rate };
    });

    if (!normalized?.length) {
        return emptyChartData();
    }

    return {
        categories: normalized.map((row) => row.category),
        series: [{ name: rateLabel, data: normalized.map((row) => row.rate) }],
        details: normalized,
    };
};
