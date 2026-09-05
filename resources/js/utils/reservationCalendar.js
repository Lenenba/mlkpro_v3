import dayjs from 'dayjs';
import utc from 'dayjs/plugin/utc.js';
import timezonePlugin from 'dayjs/plugin/timezone.js';

dayjs.extend(utc);
dayjs.extend(timezonePlugin);

export const RESERVATION_WEEK_STARTS_ON = 1;
export const RESERVATION_CALENDAR_VIEWS = ['day', 'week', 'month', 'year'];

const RESERVATION_WALL_CLOCK_FORMAT = 'YYYY-MM-DDTHH:mm:ss.SSS';

const hasTimezone = (timezone) => Boolean(
    String(timezone || '').trim()
    && typeof dayjs.tz === 'function'
);

const timezoneName = (timezone) => String(timezone || '').trim();

const fromReservationWallClock = (value, timezone) => dayjs.tz(
    value.format(RESERVATION_WALL_CLOCK_FORMAT),
    timezoneName(timezone)
);

export const reservationCalendarDate = (value = dayjs(), timezone = null) => {
    const date = dayjs(value);

    return hasTimezone(timezone)
        ? date.tz(timezoneName(timezone))
        : date;
};

export const reservationCalendarWallDate = (value = dayjs(), timezone = null) => {
    const date = dayjs(value);

    return hasTimezone(timezone)
        ? dayjs.tz(date.format(RESERVATION_WALL_CLOCK_FORMAT), timezoneName(timezone))
        : date;
};

export const addReservationCalendarTime = (
    value,
    amount,
    unit,
    timezone = null
) => {
    if (!hasTimezone(timezone)) {
        return dayjs(value).add(amount, unit);
    }

    const date = reservationCalendarDate(value, timezone);
    const wallClock = dayjs.utc(date.format(RESERVATION_WALL_CLOCK_FORMAT));

    return fromReservationWallClock(wallClock.add(amount, unit), timezone);
};

export const reservationCalendarStartOf = (value, unit, timezone = null) => {
    if (!hasTimezone(timezone)) {
        return dayjs(value).startOf(unit);
    }

    const date = reservationCalendarDate(value, timezone);
    const wallClock = dayjs.utc(date.format(RESERVATION_WALL_CLOCK_FORMAT));

    return fromReservationWallClock(wallClock.startOf(unit), timezone);
};

export const reservationCalendarEndOf = (value, unit, timezone = null) => {
    if (!hasTimezone(timezone)) {
        return dayjs(value).endOf(unit);
    }

    const date = reservationCalendarDate(value, timezone);
    const wallClock = dayjs.utc(date.format(RESERVATION_WALL_CLOCK_FORMAT));

    return fromReservationWallClock(wallClock.endOf(unit), timezone);
};

export const currentReservationDay = (now = dayjs(), timezone = null) => (
    reservationCalendarStartOf(now, 'day', timezone)
);

export const reservationCalendarDay = (value, timezone = null) => (
    reservationCalendarStartOf(
        reservationCalendarWallDate(value, timezone),
        'day',
        timezone
    )
);

export const parseReservationCalendarAnchor = (value, timezone = null) => {
    if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(value)) {
        return null;
    }

    const parsed = dayjs(value);
    if (!parsed.isValid() || parsed.format('YYYY-MM-DD') !== value) {
        return null;
    }

    return reservationCalendarDay(value, timezone);
};

export const reservationWeekStart = (
    value,
    weekStartsOn = RESERVATION_WEEK_STARTS_ON,
    timezone = null
) => {
    const date = reservationCalendarStartOf(value, 'day', timezone);
    const offset = (date.day() - weekStartsOn + 7) % 7;

    return addReservationCalendarTime(date, -offset, 'day', timezone);
};

export const reservationMonthGridStart = (
    value,
    weekStartsOn = RESERVATION_WEEK_STARTS_ON,
    timezone = null
) => {
    const firstDay = reservationCalendarStartOf(value, 'month', timezone);
    const offset = (firstDay.day() - weekStartsOn + 7) % 7;

    return addReservationCalendarTime(firstDay, -offset, 'day', timezone);
};

export const reservationMonthGridDates = (value, totalDays = 42, timezone = null) => {
    const start = reservationMonthGridStart(
        value,
        RESERVATION_WEEK_STARTS_ON,
        timezone
    );

    return Array.from(
        { length: totalDays },
        (_, index) => addReservationCalendarTime(start, index, 'day', timezone)
    );
};

export const reservationCalendarRange = ({ view, anchor, timezone = null }) => {
    const startOf = (value, unit) => reservationCalendarStartOf(value, unit, timezone);
    const endOf = (value, unit) => reservationCalendarEndOf(value, unit, timezone);
    const add = (value, amount, unit) => addReservationCalendarTime(value, amount, unit, timezone);

    if (view === 'day') {
        return { start: startOf(anchor, 'day'), end: endOf(anchor, 'day') };
    }

    if (view === 'week') {
        const start = reservationWeekStart(anchor, undefined, timezone);
        return { start, end: endOf(add(start, 6, 'day'), 'day') };
    }

    if (view === 'month') {
        const start = reservationMonthGridStart(anchor, undefined, timezone);
        return { start, end: endOf(add(start, 41, 'day'), 'day') };
    }

    const start = startOf(anchor, 'year');
    return { start, end: endOf(start, 'year') };
};

export const createReservationCalendarRangeNotifier = (notify) => {
    let previousRangeKey = null;

    return ({ view, anchor, timezone = null }) => {
        const range = reservationCalendarRange({ view, anchor, timezone });
        const payload = {
            start: range.start.toISOString(),
            end: range.end.toISOString(),
            view,
            anchorDate: reservationCalendarDate(anchor, timezone).format('YYYY-MM-DD'),
        };
        const rangeKey = `${payload.view}|${payload.start}|${payload.end}`;

        if (rangeKey === previousRangeKey) {
            return;
        }

        previousRangeKey = rangeKey;
        notify(payload);
    };
};

export const resolveReservationViewAnchor = ({
    currentView,
    nextView,
    anchor,
    now = dayjs(),
    timezone = null,
    preserveAnchor = false,
}) => {
    if (!preserveAnchor && currentView === 'month' && nextView === 'week') {
        return currentReservationDay(now, timezone);
    }

    return reservationCalendarDate(anchor, timezone);
};
