export const CUSTOMER_QUICK_FILTER_KEYS = Object.freeze([
    'vip',
    'new',
    'new_this_month',
    'no_next_appointment',
    'upcoming_appointment',
    'outstanding_balance',
    'inactive',
    'follow_up_90',
    'package_low',
    'birthday_upcoming',
]);

export const CUSTOMER_ADVANCED_FILTER_DEFAULTS = Object.freeze({
    city: '',
    country: '',
    has_quotes: '',
    has_works: '',
    status: '',
    client_type: '',
    is_vip: '',
    vip_tier_id: '',
    acquisition_source: '',
    tags: [],
    has_active_package: '',
    package_status: '',
    package_remaining_lte: '',
    package_expires_within_days: '',
    package_is_recurring: '',
    package_recurrence_status: '',
    has_upcoming_appointment: '',
    last_appointment_from: '',
    last_appointment_to: '',
    next_appointment_from: '',
    next_appointment_to: '',
    appointments_min: '',
    appointments_max: '',
    cancellations_min: '',
    no_shows_min: '',
    has_outstanding_balance: '',
    outstanding_min: '',
    outstanding_max: '',
    total_invoiced_min: '',
    total_invoiced_max: '',
    last_invoice_from: '',
    last_invoice_to: '',
    payment_statuses: [],
    created_from: '',
    created_to: '',
});

export const CUSTOMER_ADVANCED_FILTER_KEYS = Object.freeze(
    Object.keys(CUSTOMER_ADVANCED_FILTER_DEFAULTS)
);

const LEGACY_QUICK_FILTER_ALIASES = Object.freeze({
    unpaid: 'outstanding_balance',
});

const scalar = (value) => (value === null || value === undefined ? '' : String(value));

export const compactCustomerFilterPayload = (payload = {}) => Object.fromEntries(
    Object.entries(payload).filter(([, value]) => {
        if (Array.isArray(value)) {
            return value.length > 0;
        }

        return value !== '' && value !== null && value !== undefined;
    })
);

export const normalizeCustomerQuickFilterMode = (value) => (
    value === 'any' ? 'any' : 'all'
);

export const normalizeAvailableCustomerFilters = (availableFilters) => {
    if (Array.isArray(availableFilters)) {
        return [...new Set(availableFilters
            .map((entry) => (typeof entry === 'object' ? entry?.key ?? entry?.value : entry))
            .map(scalar)
            .filter(Boolean))];
    }

    if (availableFilters && typeof availableFilters === 'object') {
        return Object.entries(availableFilters)
            .filter(([, enabled]) => Boolean(enabled))
            .map(([key]) => key);
    }

    return [];
};

export const normalizeCustomerQuickFilters = (value, availableFilters = []) => {
    const rawValues = Array.isArray(value)
        ? value
        : (value === '' || value === null || value === undefined ? [] : [value]);
    const available = new Set(normalizeAvailableCustomerFilters(availableFilters));

    return [...new Set(rawValues
        .map(scalar)
        .map((key) => LEGACY_QUICK_FILTER_ALIASES[key] ?? key)
        .filter((key) => CUSTOMER_QUICK_FILTER_KEYS.includes(key))
        .filter((key) => available.size === 0 || available.has(key)))];
};

export const initialCustomerQuickFilters = (filters = {}, availableFilters = []) => {
    const canonical = normalizeCustomerQuickFilters(filters.quick_filters, availableFilters);

    if (canonical.length > 0 || !filters.operational_filter) {
        return canonical;
    }

    return normalizeCustomerQuickFilters([filters.operational_filter], availableFilters);
};

export const createCustomerAdvancedFilters = (filters = {}) => Object.fromEntries(
    CUSTOMER_ADVANCED_FILTER_KEYS.map((key) => {
        const fallback = CUSTOMER_ADVANCED_FILTER_DEFAULTS[key];
        const value = filters[key];

        if (Array.isArray(fallback)) {
            const values = Array.isArray(value)
                ? value
                : (value === '' || value === null || value === undefined ? [] : [value]);

            return [key, [...new Set(values.map(scalar).map((entry) => entry.trim()).filter(Boolean))]];
        }

        return [key, scalar(value)];
    })
);

export const isCustomerFilterValueActive = (value) => (
    Array.isArray(value)
        ? value.length > 0
        : value !== '' && value !== null && value !== undefined
);

export const countActiveCustomerAdvancedFilters = (filters = {}) => (
    CUSTOMER_ADVANCED_FILTER_KEYS.reduce(
        (count, key) => count + Number(isCustomerFilterValueActive(filters[key])),
        0
    )
);

export const toggleCustomerQuickFilter = (filters, key) => {
    const normalizedKey = LEGACY_QUICK_FILTER_ALIASES[scalar(key)] ?? scalar(key);
    const current = normalizeCustomerQuickFilters(filters);

    if (!CUSTOMER_QUICK_FILTER_KEYS.includes(normalizedKey)) {
        return current;
    }

    return current.includes(normalizedKey)
        ? current.filter((entry) => entry !== normalizedKey)
        : [...current, normalizedKey];
};

export const serializeCustomerTags = (value) => {
    const entries = Array.isArray(value) ? value : scalar(value).split(',');

    return [...new Set(entries
        .map(scalar)
        .map((entry) => entry.trim())
        .filter(Boolean))];
};
