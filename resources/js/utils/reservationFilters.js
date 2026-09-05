export const RESERVATION_QUICK_FILTERS = ['pending', 'today', 'upcoming', 'past', 'completed', 'no_show', 'cancelled'];
export const RESERVATION_ADVANCED_FILTERS = ['status', 'service_id', 'team_member_id', 'date_from', 'date_to'];

export const normalizeReservationQuickFilters = (values) => [...new Set(
    (Array.isArray(values) ? values : [values]).filter((value) => RESERVATION_QUICK_FILTERS.includes(value)),
)];

export const initialReservationQuickFilters = (filters = {}) => normalizeReservationQuickFilters(
    Object.hasOwn(filters, 'quick_filters') ? filters.quick_filters : filters.quick,
);

export const normalizeReservationQuickFilterMode = (mode) => mode === 'any' ? 'any' : 'all';

export const toggleReservationQuickFilter = (values, value) => {
    const current = normalizeReservationQuickFilters(values);
    if (!RESERVATION_QUICK_FILTERS.includes(value)) {
        return current;
    }
    return current.includes(value) ? current.filter((entry) => entry !== value) : [...current, value];
};

export const createReservationAdvancedFilters = (filters = {}, ownTeamMemberId = '') => Object.fromEntries(
    RESERVATION_ADVANCED_FILTERS.map((field) => [field,
        field === 'team_member_id' && filters.scope === 'mine'
            ? String(ownTeamMemberId || filters.team_member_id || '')
            : String(filters[field] ?? ''),
    ]),
);

export const countReservationAdvancedFilters = (filters = {}) => RESERVATION_ADVANCED_FILTERS.filter(
    (field) => Boolean(filters[field]) && !(field === 'team_member_id' && filters.scope === 'mine'),
).length;

export const reservationFilterPayload = (filters = {}) => ({
    ...Object.fromEntries(['search', ...RESERVATION_ADVANCED_FILTERS, 'scope'].map((field) => [field, filters[field] || undefined])),
    quick_filters: initialReservationQuickFilters(filters),
    quick_filter_mode: normalizeReservationQuickFilterMode(filters.quick_filter_mode),
});
