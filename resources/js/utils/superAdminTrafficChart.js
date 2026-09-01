export const SUPER_ADMIN_TRAFFIC_MINIMUM_POINTS = 8;

const emptyChartData = (isValid = true) => ({
    categories: [],
    series: [],
    period: null,
    isValid,
});

const isCalendarDate = (value) => {
    if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}$/u.test(value)) {
        return false;
    }

    const date = new Date(`${value}T00:00:00Z`);

    return !Number.isNaN(date.getTime())
        && date.toISOString().slice(0, 10) === value;
};

const isCount = (value) => Number.isSafeInteger(value) && value >= 0;

export const buildSuperAdminTrafficChartData = (
    rows,
    {
        totalLabel = 'Visits',
        uniqueLabel = 'Unique visitors',
    } = {},
) => {
    if (!Array.isArray(rows)) {
        return emptyChartData(false);
    }

    if (!rows.length) {
        return emptyChartData();
    }

    if (rows.length < SUPER_ADMIN_TRAFFIC_MINIMUM_POINTS) {
        return emptyChartData(false);
    }

    const normalized = [];
    const seenDates = new Set();
    let previousDate = '';

    for (const row of rows) {
        const date = row?.date;
        const total = row?.total;
        const unique = row?.unique;

        if (
            !isCalendarDate(date)
            || !isCount(total)
            || !isCount(unique)
            || unique > total
            || seenDates.has(date)
            || (previousDate && date <= previousDate)
        ) {
            return emptyChartData(false);
        }

        normalized.push({ date, total, unique });
        seenDates.add(date);
        previousDate = date;
    }

    return {
        categories: normalized.map((row) => row.date),
        series: [
            {
                name: totalLabel,
                data: normalized.map((row) => row.total),
            },
            {
                name: uniqueLabel,
                data: normalized.map((row) => row.unique),
            },
        ],
        period: {
            start: normalized[0].date,
            end: normalized.at(-1).date,
        },
        isValid: true,
    };
};
