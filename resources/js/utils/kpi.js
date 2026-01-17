export const buildSparklinePoints = (values, maxHeight = 28, minHeight = 4, fallbackLength = 6) => {
    const safeValues = Array.isArray(values) && values.length
        ? values
        : Array.from({ length: fallbackLength }, () => 0);

    const numbers = safeValues.map((value) => Number(value || 0));
    const maxValue = Math.max(...numbers, 0);
    const scale = maxValue > 0 ? maxValue : 1;

    return numbers.map((value) => ({
        value,
        height: `${Math.max(minHeight, Math.round((value / scale) * maxHeight))}px`,
    }));
};

export const buildTrend = (values, positiveDirection = 'up') => {
    const safeValues = Array.isArray(values) && values.length ? values : [0, 0];
    const numbers = safeValues.map((value) => Number(value || 0));
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

export const formatTrendValue = (trend, options = {}) => {
    const newLabel = options.newLabel || 'New';
    if (!trend) {
        return '0%';
    }
    if (trend.percent === null) {
        return newLabel;
    }
    return `${trend.percent.toFixed(1)}%`;
};
