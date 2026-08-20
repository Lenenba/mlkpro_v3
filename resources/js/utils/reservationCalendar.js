import dayjs from 'dayjs';

export const RESERVATION_WEEK_STARTS_ON = 1;

export const currentReservationDay = (now = dayjs()) => dayjs(now).startOf('day');

export const reservationWeekStart = (
    value,
    weekStartsOn = RESERVATION_WEEK_STARTS_ON
) => {
    const date = dayjs(value).startOf('day');
    const offset = (date.day() - weekStartsOn + 7) % 7;

    return date.subtract(offset, 'day');
};

export const reservationMonthGridStart = (
    value,
    weekStartsOn = RESERVATION_WEEK_STARTS_ON
) => {
    const firstDay = dayjs(value).startOf('month');
    const offset = (firstDay.day() - weekStartsOn + 7) % 7;

    return firstDay.subtract(offset, 'day');
};

export const reservationMonthGridDates = (value, totalDays = 42) => {
    const start = reservationMonthGridStart(value);

    return Array.from({ length: totalDays }, (_, index) => start.add(index, 'day'));
};

export const resolveReservationViewAnchor = ({
    currentView,
    nextView,
    anchor,
    now = dayjs(),
}) => {
    if (currentView === 'month' && nextView === 'week') {
        return currentReservationDay(now);
    }

    return dayjs(anchor);
};
