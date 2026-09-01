export const CUSTOMER_GROWTH_WEEK_COUNT = 12;
export const CUSTOMER_GROWTH_CATEGORY_COUNT = CUSTOMER_GROWTH_WEEK_COUNT * 2;

const DAY_IN_MILLISECONDS = 24 * 60 * 60 * 1000;
const WEEK_IN_MILLISECONDS = 7 * DAY_IN_MILLISECONDS;

const emptyChartData = (isValid = true) => ({
    categories: [],
    series: [],
    periods: {},
    timezone: null,
    isValid,
});

const dateTimestamp = (value) => {
    if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}$/u.test(value)) {
        return null;
    }

    const date = new Date(`${value}T00:00:00Z`);

    return !Number.isNaN(date.getTime()) && date.toISOString().slice(0, 10) === value
        ? date.getTime()
        : null;
};

const shiftDate = (value, days) => {
    const timestamp = dateTimestamp(value);

    return timestamp === null
        ? null
        : new Date(timestamp + days * DAY_IN_MILLISECONDS).toISOString().slice(0, 10);
};

const hasContinuousWeeks = (categories) => categories.every((category, index) => {
    const timestamp = dateTimestamp(category);

    if (timestamp === null) {
        return false;
    }

    return index === 0 || timestamp - dateTimestamp(categories[index - 1]) === WEEK_IN_MILLISECONDS;
});

const isCount = (value) => Number.isSafeInteger(value) && value >= 0;

export const buildCustomerGrowthChartData = (
    trend,
    {
        currentLabel = 'Current 12 weeks',
        previousLabel = 'Previous 12 weeks',
    } = {},
) => {
    if (!trend || typeof trend !== 'object' || Array.isArray(trend)) {
        return emptyChartData(false);
    }

    const categories = Array.isArray(trend.categories) ? [...trend.categories] : [];
    const rawSeries = Array.isArray(trend.series) ? trend.series : [];
    const currentSeries = rawSeries.find((series) => series?.key === 'current');
    const previousSeries = rawSeries.find((series) => series?.key === 'previous');
    const currentPeriod = trend.periods?.current;
    const previousPeriod = trend.periods?.previous;
    const timezone = typeof trend.timezone === 'string' ? trend.timezone.trim() : '';
    const currentData = Array.isArray(currentSeries?.data) ? currentSeries.data : [];
    const previousData = Array.isArray(previousSeries?.data) ? previousSeries.data : [];
    const hasExactSeries = rawSeries.length === 2
        && currentSeries
        && previousSeries
        && currentSeries !== previousSeries;
    const hasExactValues = hasExactSeries
        && currentData.length === CUSTOMER_GROWTH_CATEGORY_COUNT
        && previousData.length === CUSTOMER_GROWTH_CATEGORY_COUNT
        && currentData.slice(0, CUSTOMER_GROWTH_WEEK_COUNT).every((value) => value === null)
        && currentData.slice(CUSTOMER_GROWTH_WEEK_COUNT).every(isCount)
        && previousData.slice(0, CUSTOMER_GROWTH_WEEK_COUNT).every(isCount)
        && previousData.slice(CUSTOMER_GROWTH_WEEK_COUNT).every((value) => value === null);
    const hasExactPeriods = previousPeriod?.start === categories[0]
        && previousPeriod?.end === shiftDate(categories[CUSTOMER_GROWTH_WEEK_COUNT - 1], 6)
        && currentPeriod?.start === categories[CUSTOMER_GROWTH_WEEK_COUNT]
        && currentPeriod?.end === shiftDate(categories.at(-1), 6);

    if (
        categories.length !== CUSTOMER_GROWTH_CATEGORY_COUNT
        || new Set(categories).size !== CUSTOMER_GROWTH_CATEGORY_COUNT
        || !hasContinuousWeeks(categories)
        || !hasExactValues
        || !hasExactPeriods
        || !timezone
    ) {
        return emptyChartData(false);
    }

    return {
        categories,
        series: [
            { name: currentLabel, data: [...currentData] },
            { name: previousLabel, data: [...previousData] },
        ],
        periods: {
            current: { ...currentPeriod },
            previous: { ...previousPeriod },
        },
        timezone,
        isValid: true,
    };
};
