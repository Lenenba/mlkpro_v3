export const buildSparklinePoints = (values, maxHeight = 28, minHeight = 4) => {
    if (!Array.isArray(values) || !values.length) {
        return [];
    }

    const numbers = values.map((value) => Number(value || 0));
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

    const current = Number(value);
    const max = Number(maximum);

    if (!Number.isFinite(current) || !Number.isFinite(max) || max <= 0) {
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

    const numbers = values.map((value) => Number(value || 0));
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
