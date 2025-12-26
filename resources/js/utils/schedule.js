import dayjs from 'dayjs';

const WEEKDAY_MAP = {
    su: 0,
    mo: 1,
    tu: 2,
    we: 3,
    th: 4,
    fr: 5,
    sa: 6,
};

const normalizeRepeats = (repeatsOn) => {
    if (!Array.isArray(repeatsOn)) {
        return { weekDays: [], monthDays: [] };
    }

    const weekDays = [];
    const monthDays = [];

    repeatsOn.forEach((value) => {
        const key = String(value).toLowerCase();
        if (Object.prototype.hasOwnProperty.call(WEEKDAY_MAP, key)) {
            weekDays.push(WEEKDAY_MAP[key]);
            return;
        }

        const dayNumber = Number(value);
        if (Number.isFinite(dayNumber) && dayNumber > 0) {
            monthDays.push(dayNumber);
        }
    });

    return { weekDays, monthDays };
};

export const buildOccurrenceDates = ({
    startDate,
    endDate,
    frequency,
    repeatsOn,
    totalVisits,
    maxDays = 365 * 3,
}) => {
    if (!startDate) {
        return [];
    }

    const start = dayjs(startDate).startOf('day');
    if (!start.isValid()) {
        return [];
    }

    const end = endDate ? dayjs(endDate).startOf('day') : null;
    const maxVisits = Math.max(0, Number(totalVisits) || 0);
    const cadence = String(frequency || 'weekly').toLowerCase();

    const { weekDays, monthDays } = normalizeRepeats(repeatsOn);
    const defaultWeekDays = weekDays.length ? weekDays : [start.day()];
    const defaultMonthDays = monthDays.length ? monthDays : [start.date()];

    let iterations = end ? end.diff(start, 'day') + 1 : Math.max(1, maxVisits);
    if (!end && maxVisits > 0) {
        const multiplier = cadence === 'monthly' ? 31 : cadence === 'weekly' ? 7 : 1;
        iterations = Math.max(1, maxVisits * multiplier);
    }

    iterations = Math.min(iterations, maxDays);

    const dates = [];
    let cursor = start.clone();

    while (iterations > 0) {
        if (end && cursor.isAfter(end)) {
            break;
        }

        let shouldAdd = false;
        switch (cadence) {
            case 'daily':
                shouldAdd = true;
                break;
            case 'monthly':
                shouldAdd = defaultMonthDays.includes(cursor.date());
                break;
            case 'yearly':
                shouldAdd = cursor.date() === start.date() && cursor.month() === start.month();
                break;
            case 'weekly':
            default:
                shouldAdd = defaultWeekDays.includes(cursor.day());
                break;
        }

        if (shouldAdd) {
            dates.push(cursor.clone());
            if (maxVisits > 0 && dates.length >= maxVisits) {
                break;
            }
        }

        cursor = cursor.add(1, 'day');
        iterations -= 1;
    }

    return dates;
};

export const buildPreviewEvents = ({
    startDate,
    endDate,
    frequency,
    repeatsOn,
    totalVisits,
    startTime,
    endTime,
    title,
    workId,
    assignees = [],
    preview = true,
}) => {
    const dates = buildOccurrenceDates({
        startDate,
        endDate,
        frequency,
        repeatsOn,
        totalVisits,
    });

    if (!dates.length) {
        return [];
    }

    const normalizedAssignees = Array.isArray(assignees)
        ? assignees
              .map((assignee) => ({
                  id: Number(assignee?.id),
                  name: assignee?.name || '',
              }))
              .filter((assignee) => Number.isFinite(assignee.id))
        : [];

    const assigneeCount = normalizedAssignees.length;
    const safeTitle = title || 'Job';
    const safeStartTime = startTime ? String(startTime).slice(0, 8) : '';
    const safeEndTime = endTime ? String(endTime).slice(0, 8) : '';

    return dates.map((date, index) => {
        const assignee = assigneeCount ? normalizedAssignees[index % assigneeCount] : null;
        const assigneeLabel = assignee?.name ? ` - ${assignee.name}` : '';
        const start = safeStartTime
            ? `${date.format('YYYY-MM-DD')}T${safeStartTime}`
            : date.format('YYYY-MM-DD');
        const end = safeEndTime
            ? `${date.format('YYYY-MM-DD')}T${safeEndTime}`
            : null;

        return {
            id: `${workId || 'preview'}-${date.format('YYYYMMDD')}-${assignee?.id || 'na'}`,
            title: `${safeTitle}${assigneeLabel}`,
            start,
            end,
            allDay: !safeStartTime,
            extendedProps: {
                preview,
                assigned_team_member_id: assignee?.id || null,
                work_id: workId || null,
            },
        };
    });
};
