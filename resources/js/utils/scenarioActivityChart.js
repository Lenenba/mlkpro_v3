export const SCENARIO_ACTIVITY_MIN_PERIODS = 4;

const periodKeyPattern = /^(\d{4})-(0[1-9]|1[0-2])$/u;

const emptyScenarioActivity = () => ({
    categories: [],
    series: [],
    rows: [],
});

const followingPeriodKey = (periodKey) => {
    const [, rawYear, rawMonth] = periodKey.match(periodKeyPattern) || [];
    const year = Number(rawYear);
    const month = Number(rawMonth);

    if (!year || !month) {
        return null;
    }

    const nextYear = month === 12 ? year + 1 : year;
    const nextMonth = month === 12 ? 1 : month + 1;

    return `${nextYear}-${String(nextMonth).padStart(2, '0')}`;
};

export const buildScenarioActivityChartData = (monthly, {
    labelForPeriod = (periodKey) => periodKey,
    seriesLabel = '',
} = {}) => {
    const periodKeys = Array.isArray(monthly?.labels) ? monthly.labels : null;
    const values = Array.isArray(monthly?.reservations) ? monthly.reservations : null;
    const normalizedSeriesLabel = typeof seriesLabel === 'string' ? seriesLabel.trim() : '';

    if (
        !periodKeys
        || !values
        || !normalizedSeriesLabel
        || periodKeys.length < SCENARIO_ACTIVITY_MIN_PERIODS
        || periodKeys.length !== values.length
    ) {
        return emptyScenarioActivity();
    }

    const rows = periodKeys.map((rawPeriodKey, index) => {
        const periodKey = typeof rawPeriodKey === 'string' ? rawPeriodKey.trim() : '';
        const value = values[index];
        const label = periodKeyPattern.test(periodKey)
            ? String(labelForPeriod(periodKey) || '').trim()
            : '';

        return label && Number.isSafeInteger(value) && value >= 0
            ? { periodKey, label, value }
            : null;
    });

    if (rows.some((row) => row === null)) {
        return emptyScenarioActivity();
    }

    const uniquePeriodKeys = new Set(rows.map((row) => row.periodKey));
    const hasContinuousPeriods = rows.every((row, index) => (
        index === 0
        || followingPeriodKey(rows[index - 1].periodKey) === row.periodKey
    ));

    if (uniquePeriodKeys.size !== rows.length || !hasContinuousPeriods) {
        return emptyScenarioActivity();
    }

    return {
        categories: rows.map((row) => row.label),
        series: [{ name: normalizedSeriesLabel, data: rows.map((row) => row.value) }],
        rows,
    };
};
