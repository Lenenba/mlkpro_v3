export const ACCOUNTING_ACTIVITY_MIN_PERIODS = 4;

const emptyAccountingActivity = () => ({
    available: false,
    periodKeys: [],
    categories: [],
    entryCounts: [],
    batchCounts: [],
});

const periodKeyPattern = /^(\d{4})-(0[1-9]|1[0-2])$/u;

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

const normalizeCount = (value) => Number.isSafeInteger(value) && value >= 0
    ? value
    : null;

const normalizePeriod = (period) => {
    if (!period || typeof period !== 'object' || Array.isArray(period)) {
        return null;
    }

    const periodKey = String(period.period_key || '').trim();
    const label = String(period.label || '').trim();
    const entryCount = normalizeCount(period.entry_count);
    const batchCount = normalizeCount(period.batch_count);

    if (
        !periodKeyPattern.test(periodKey)
        || !label
        || entryCount === null
        || batchCount === null
    ) {
        return null;
    }

    return {
        periodKey,
        label,
        entryCount,
        batchCount,
    };
};

export const buildAccountingActivityChartData = (periods) => {
    if (!Array.isArray(periods)) {
        return emptyAccountingActivity();
    }

    const normalized = periods.map(normalizePeriod);

    if (normalized.some((period) => period === null)) {
        return emptyAccountingActivity();
    }

    const periodKeys = normalized.map((period) => period.periodKey);

    if (
        normalized.length < ACCOUNTING_ACTIVITY_MIN_PERIODS
        || new Set(periodKeys).size !== periodKeys.length
    ) {
        return emptyAccountingActivity();
    }

    const chronological = normalized
        .slice()
        .sort((left, right) => left.periodKey.localeCompare(right.periodKey));
    const hasContinuousPeriods = chronological.every((period, index) => (
        index === 0
        || followingPeriodKey(chronological[index - 1].periodKey) === period.periodKey
    ));

    if (!hasContinuousPeriods) {
        return emptyAccountingActivity();
    }

    return {
        available: true,
        periodKeys: chronological.map((period) => period.periodKey),
        categories: chronological.map((period) => period.label),
        entryCounts: chronological.map((period) => period.entryCount),
        batchCounts: chronological.map((period) => period.batchCount),
    };
};
