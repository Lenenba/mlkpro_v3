export const reservationFilterFields = [
    'search', 'status', 'team_member_id', 'service_id', 'date_from', 'date_to', 'scope', 'quick_filters', 'quick_filter_mode',
];

export const reservationReloadProps = ({ tab, view, reason = 'mutation', changedFilters = [] }) => {
    const props = new Set(['filters']);
    const mutation = reason === 'mutation';
    const summaryChanged = changedFilters.some((field) => ['search', 'scope', 'team_member_id', 'service_id'].includes(field));
    const performanceChanged = changedFilters.some((field) => ['scope', 'team_member_id', 'service_id'].includes(field));

    if (tab === 'queue') {
        props.add('queueItems').add('queueStats');
    } else if (tab === 'waitlist') {
        props.add('waitlists').add('waitlistStats');
    } else {
        props.add('reservationCount');
        if (view === 'list') {
            props.add('reservations');
        }
        if (reason !== 'ordering' || changedFilters.length > 0) {
            props.add('quickCounts');
        }
    }

    if (mutation || summaryChanged) {
        props.add('stats');
    }
    if (mutation || performanceChanged) {
        props.add('performance');
    }
    if (mutation) {
        props.add('reservationCount').add('quickCounts').add('queueItems').add('queueStats').add('waitlists').add('waitlistStats');
    }

    return [...props];
};

export const reservationCalendarUrl = (href, { view, date }) => {
    const url = new URL(href);
    url.searchParams.set('calendar_view', view);
    url.searchParams.set('calendar_date', date);

    return `${url.pathname}${url.search}${url.hash}`;
};
