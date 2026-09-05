export const RESERVATION_QUEUE_QUICK_FILTERS = [
    'all',
    'not_arrived',
    'waiting',
    'called',
    'in_service',
    'awaiting_payment',
    'done',
];

const QUEUE_FILTER_STATUSES = {
    not_arrived: ['not_arrived'],
    waiting: ['checked_in', 'pre_called', 'skipped'],
    called: ['called'],
    in_service: ['in_service'],
    awaiting_payment: ['awaiting_payment'],
    done: ['done'],
};

const QUEUE_PRIMARY_ACTIONS = new Set([
    'check_in',
    'pre_call',
    'call',
    'start',
    'finish',
    'checkout',
]);

export const normalizeReservationQueueQuickFilter = (value) => (
    RESERVATION_QUEUE_QUICK_FILTERS.includes(String(value || '')) ? String(value) : 'all'
);

export const reservationQueueMatchesQuickFilter = (item, filter) => {
    const normalizedFilter = normalizeReservationQueueQuickFilter(filter);

    return normalizedFilter === 'all'
        || (QUEUE_FILTER_STATUSES[normalizedFilter] || []).includes(String(item?.status || ''));
};

export const reservationQueueQuickCounts = (rows) => {
    const sourceRows = Array.isArray(rows) ? rows : [];

    return RESERVATION_QUEUE_QUICK_FILTERS.reduce((counts, filter) => ({
        ...counts,
        [filter]: sourceRows.filter((row) => reservationQueueMatchesQuickFilter(row, filter)).length,
    }), {});
};

const normalizeQueueAction = (action) => String(action || '').trim().replaceAll('-', '_');

export const reservationQueuePrimaryAction = (item) => {
    if (!item?.can_update_status) {
        return null;
    }

    const serverAction = normalizeQueueAction(item?.primary_action);
    if (QUEUE_PRIMARY_ACTIONS.has(serverAction)) {
        return serverAction;
    }

    const status = String(item?.status || '');
    if (status === 'not_arrived') {
        return 'check_in';
    }
    if (['checked_in', 'skipped'].includes(status)) {
        return item?.callable ? 'call' : 'pre_call';
    }
    if (status === 'pre_called') {
        return 'call';
    }
    if (status === 'called') {
        return 'start';
    }
    if (status === 'in_service') {
        return 'finish';
    }
    if (status === 'awaiting_payment') {
        return 'checkout';
    }

    return null;
};
