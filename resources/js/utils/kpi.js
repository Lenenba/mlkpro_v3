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

    return Number.isFinite(number) ? number : null;
};

export const buildSparklinePoints = (values, maxHeight = 28, minHeight = 4) => {
    if (!Array.isArray(values) || !values.length) {
        return [];
    }

    const numbers = values.map(finiteNumber);

    if (numbers.some((value) => value === null)) {
        return [];
    }

    const maxValue = Math.max(...numbers, 0);
    const scale = maxValue > 0 ? maxValue : 1;

    return numbers.map((value) => ({
        value,
        height: `${Math.max(minHeight, Math.round((value / scale) * maxHeight))}px`,
    }));
};

export const buildKpiProgress = (value, maximum, label = undefined) => {
    if (value === null || value === undefined) {
        return null;
    }

    const current = finiteNumber(value);
    const max = finiteNumber(maximum);

    if (current === null || max === null || max <= 0) {
        return null;
    }

    return {
        value: Math.min(max, Math.max(0, current)),
        max,
        ...(label ? { label } : {}),
    };
};

export const buildTrend = (values, positiveDirection = 'up') => {
    if (!Array.isArray(values) || values.length < 2) {
        return null;
    }

    const numbers = values.map(finiteNumber);

    if (numbers.some((value) => value === null)) {
        return null;
    }

    const last = numbers[numbers.length - 1] ?? 0;
    const prev = numbers[numbers.length - 2] ?? 0;
    const diff = last - prev;

    const direction = diff === 0 ? 'flat' : diff > 0 ? 'up' : 'down';
    const isPositive = positiveDirection === 'down' ? diff <= 0 : diff >= 0;

    let percent = 0;
    if (prev === 0) {
        percent = last === 0 ? 0 : null;
    } else {
        percent = Math.abs((diff / prev) * 100);
    }

    return {
        diff,
        direction,
        isPositive,
        percent,
    };
};

const favorableDirection = (semanticDirection, fallbackDirection) => {
    if (semanticDirection === 'lower_is_better') {
        return 'down';
    }

    if (semanticDirection === 'higher_is_better') {
        return 'up';
    }

    return fallbackDirection;
};

export const buildKpiSeriesData = (series, fallbackDirection = 'up') => {
    const isLegacy = Array.isArray(series);
    const source = !isLegacy && series && typeof series === 'object' ? series : {};
    const rawValues = isLegacy ? series : source.values;
    const values = Array.isArray(rawValues)
        ? rawValues.map((value) => finiteNumber(value))
        : [];
    const rawLabels = Array.isArray(source.labels) ? source.labels : [];
    const labels = values.map((_, index) => String(rawLabels[index] ?? ''));
    const isTemporal = isLegacy ? true : source.isTemporal !== false;
    const points = isTemporal
        ? buildSparklinePoints(values).map((point, index) => ({
            ...point,
            label: labels[index],
        }))
        : [];

    if (isLegacy) {
        return {
            labels,
            values,
            isTemporal,
            points,
            trend: buildTrend(values, fallbackDirection),
        };
    }

    const comparison = source.comparison && typeof source.comparison === 'object'
        ? source.comparison
        : null;
    let trend = null;

    if (isTemporal && comparison) {
        const diff = finiteNumber(comparison.delta);
        const comparisonPercent = comparison.percent === null
            ? null
            : finiteNumber(comparison.percent);
        const hasValidComparison = diff !== null
            && (comparison.percent === null || comparisonPercent !== null);

        if (hasValidComparison) {
            const direction = ['up', 'down', 'flat'].includes(comparison.direction)
                ? comparison.direction
                : (diff === 0 ? 'flat' : diff > 0 ? 'up' : 'down');
            const positiveDirection = favorableDirection(source.semanticDirection, fallbackDirection);

            trend = {
                diff,
                direction,
                isPositive: typeof comparison.isFavorable === 'boolean'
                    ? comparison.isFavorable
                    : positiveDirection === 'down' ? diff <= 0 : diff >= 0,
                percent: comparisonPercent,
            };
        }
    }

    return {
        ...source,
        labels,
        values,
        isTemporal,
        points,
        trend,
    };
};
