export const CUSTOMER_ACTIVITY_DEFAULT_PERIOD = 'last_90_days';

export const CUSTOMER_ACTIVITY_PERIODS = Object.freeze([
    'last_7_days',
    'last_30_days',
    'last_90_days',
    'last_6_months',
    'current_year',
    'previous_year',
    'all',
    'custom',
]);

export const CUSTOMER_ACTIVITY_TYPES = Object.freeze([
    'appointments',
    'invoices',
    'payments',
    'notes',
    'communications',
    'profile_changes',
]);

const TYPE_ALIASES = Object.freeze({
    appointment: 'appointments',
    appointments: 'appointments',
    booking: 'appointments',
    bookings: 'appointments',
    reservation: 'appointments',
    reservations: 'appointments',
    invoice: 'invoices',
    invoices: 'invoices',
    payment: 'payments',
    payments: 'payments',
    refund: 'payments',
    refunds: 'payments',
    note: 'notes',
    notes: 'notes',
    communication: 'communications',
    communications: 'communications',
    call: 'communications',
    email: 'communications',
    message: 'communications',
    meeting: 'communications',
    profile: 'profile_changes',
    profile_change: 'profile_changes',
    profile_changes: 'profile_changes',
    status_change: 'profile_changes',
    vip_change: 'profile_changes',
});

const isPlainObject = (value) => value !== null && typeof value === 'object' && !Array.isArray(value);

const unique = (values) => [...new Set(values)];

const normalizeString = (value) => typeof value === 'string' ? value.trim() : '';

const humanizeToken = (value) => {
    const normalized = normalizeString(value).replace(/[_-]+/g, ' ');

    return normalized
        ? normalized.charAt(0).toUpperCase() + normalized.slice(1)
        : '';
};

const normalizeDateInput = (value) => {
    const normalized = normalizeString(value);
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(normalized);

    if (!match) {
        return '';
    }

    const year = Number(match[1]);
    const month = Number(match[2]);
    const day = Number(match[3]);
    const date = new Date(Date.UTC(year, month - 1, day));

    return date.getUTCFullYear() === year
        && date.getUTCMonth() === month - 1
        && date.getUTCDate() === day
        ? normalized
        : '';
};

const arrayValue = (value) => {
    if (Array.isArray(value)) {
        return value;
    }

    if (typeof value === 'string') {
        return value.split(',');
    }

    return [];
};

export const normalizeCustomerActivityType = (value) => TYPE_ALIASES[normalizeString(value).toLowerCase()] || '';

export const normalizeCustomerActivityTypes = (value, available = CUSTOMER_ACTIVITY_TYPES) => {
    const allowed = new Set(
        arrayValue(available)
            .map((option) => normalizeCustomerActivityType(isPlainObject(option) ? option.value ?? option.id : option))
            .filter(Boolean),
    );

    return unique(
        arrayValue(value)
            .map((type) => normalizeCustomerActivityType(type))
            .filter((type) => type && (!allowed.size || allowed.has(type))),
    );
};

export const normalizeCustomerActivityFilters = (value = {}, available = CUSTOMER_ACTIVITY_TYPES) => {
    const source = isPlainObject(value) ? value : {};
    const period = CUSTOMER_ACTIVITY_PERIODS.includes(source.period)
        ? source.period
        : CUSTOMER_ACTIVITY_DEFAULT_PERIOD;

    return {
        period,
        from: period === 'custom' ? normalizeDateInput(source.from) : '',
        to: period === 'custom' ? normalizeDateInput(source.to) : '',
        types: normalizeCustomerActivityTypes(source.types, available),
    };
};

export const validateCustomerActivityFilters = (value = {}) => {
    const filters = normalizeCustomerActivityFilters(value);

    if (filters.period !== 'custom') {
        return null;
    }

    if (!filters.from || !filters.to) {
        return 'dates_required';
    }

    return filters.from > filters.to ? 'invalid_range' : null;
};

export const serializeCustomerActivityFilters = (value = {}, options = {}) => {
    const filters = normalizeCustomerActivityFilters(value);
    const perPage = Number(options.perPage);
    const params = {
        period: filters.period,
        per_page: Number.isInteger(perPage) && perPage > 0 ? Math.min(perPage, 50) : 20,
    };

    if (filters.types.length) {
        params.types = filters.types;
    }

    if (filters.period === 'custom') {
        params.from = filters.from;
        params.to = filters.to;
    }

    if (normalizeString(options.cursor)) {
        params.cursor = normalizeString(options.cursor);
    }

    return params;
};

export const toggleCustomerActivityType = (types, type) => {
    const normalizedType = normalizeCustomerActivityType(type);
    const current = normalizeCustomerActivityTypes(types);

    if (!normalizedType) {
        return current;
    }

    return current.includes(normalizedType)
        ? current.filter((candidate) => candidate !== normalizedType)
        : [...current, normalizedType];
};

export const customerActivityFilterCount = (value = {}) => {
    const filters = normalizeCustomerActivityFilters(value);

    return filters.types.length + (filters.period === CUSTOMER_ACTIVITY_DEFAULT_PERIOD ? 0 : 1);
};

const legacyCategory = (item) => {
    if (item?.message_event) {
        return 'communications';
    }

    if (item?.sales_activity) {
        return item.sales_activity.type === 'note' ? 'notes' : 'communications';
    }

    if (item?.meeting_event) {
        return 'communications';
    }

    const subjectType = normalizeString(item?.subject_type).toLowerCase();
    const action = normalizeString(item?.action).toLowerCase();

    if (subjectType.includes('reservation') || action.includes('reservation') || action.includes('appointment')) {
        return 'appointments';
    }
    if (subjectType.includes('invoice') || action.includes('invoice')) {
        return 'invoices';
    }
    if (subjectType.includes('payment') || action.includes('payment') || action.includes('refund')) {
        return 'payments';
    }
    if (action.includes('note')) {
        return 'notes';
    }
    if (action.includes('message') || action.includes('email') || action.includes('call') || action.includes('meeting')) {
        return 'communications';
    }

    return 'profile_changes';
};

const normalizeAmount = (item) => {
    const amount = item?.amount;

    if (isPlainObject(amount) && Number.isFinite(Number(amount.value))) {
        return {
            value: Number(amount.value),
            currency_code: normalizeString(amount.currency_code || amount.currency) || 'CAD',
        };
    }

    if (Number.isFinite(Number(amount)) && amount !== null && amount !== '') {
        return {
            value: Number(amount),
            currency_code: normalizeString(item?.currency_code) || 'CAD',
        };
    }

    return null;
};

export const normalizeCustomerActivityItem = (item, index = 0) => {
    const source = isPlainObject(item) ? item : {};
    const directCategory = normalizeCustomerActivityType(source.type || source.category);
    const category = directCategory || legacyCategory(source);
    const occurredAt = source.occurred_at || source.created_at || source.date || null;
    const explicitTitle = normalizeString(source.title);
    const legacyDescription = normalizeString(source.description);
    const metadata = isPlainObject(source.metadata)
        ? source.metadata
        : (isPlainObject(source.properties) ? source.properties : {});
    const note = normalizeString(metadata.note);
    const actor = isPlainObject(source.actor)
        ? source.actor
        : (isPlainObject(source.user) ? source.user : null);
    const resource = isPlainObject(source.resource)
        ? source.resource
        : (normalizeString(source.href) ? { href: normalizeString(source.href) } : null);

    return {
        ...source,
        id: source.id === null || source.id === undefined
            ? `${category}:${occurredAt || index}:${index}`
            : (String(source.id).includes(':') ? String(source.id) : `${category}:${source.id}`),
        occurred_at: occurredAt,
        category,
        type: normalizeString(source.type) || category,
        status: normalizeString(source.status)
            || normalizeString(source.message_event?.delivery_state)
            || normalizeString(source.meeting_event?.lifecycle_state),
        title: explicitTitle || legacyDescription || humanizeToken(source.action) || humanizeToken(category),
        description: explicitTitle
            ? legacyDescription
            : (note && note !== legacyDescription ? note : ''),
        amount: normalizeAmount(source),
        resource,
        actor,
        metadata,
        icon_key: normalizeString(source.icon_key),
    };
};

const unwrapCustomerActivityPayload = (value) => {
    if (Array.isArray(value)) {
        return { data: value };
    }

    if (!isPlainObject(value)) {
        return {};
    }

    for (const key of ['customerActivity', 'customer_activity', 'activity']) {
        if (isPlainObject(value[key]) || Array.isArray(value[key])) {
            return unwrapCustomerActivityPayload(value[key]);
        }
    }

    return value;
};

const normalizeAvailableTypes = (value) => {
    if (!Array.isArray(value)) {
        return CUSTOMER_ACTIVITY_TYPES.map((type) => ({ value: type, label: '' }));
    }

    return unique(value
        .map((option) => {
            const rawValue = isPlainObject(option) ? option.value ?? option.id : option;
            const normalized = normalizeCustomerActivityType(rawValue);

            if (!normalized) {
                return null;
            }

            return {
                value: normalized,
                label: isPlainObject(option) ? normalizeString(option.label || option.name) : '',
            };
        })
        .filter(Boolean)
        .map((option) => option.value))
        .map((type) => {
            const original = value.find((option) => normalizeCustomerActivityType(
                isPlainObject(option) ? option.value ?? option.id : option,
            ) === type);

            return {
                value: type,
                label: isPlainObject(original) ? normalizeString(original.label || original.name) : '',
            };
        });
};

export const normalizeCustomerActivityPayload = (value, fallbackItems = []) => {
    const payload = unwrapCustomerActivityPayload(value);
    const rawData = Array.isArray(payload.data)
        ? payload.data
        : (Array.isArray(payload.items) ? payload.items : (Array.isArray(payload.events) ? payload.events : fallbackItems));
    const meta = isPlainObject(payload.meta) ? payload.meta : {};
    const links = isPlainObject(payload.links) ? payload.links : {};
    const availableTypes = normalizeAvailableTypes(meta.available_types);
    const filters = normalizeCustomerActivityFilters(meta, availableTypes.map((option) => option.value));
    const nextCursor = normalizeString(meta.next_cursor || payload.next_cursor);
    const hasMore = Boolean(meta.has_more ?? payload.has_more ?? (nextCursor || links.next));

    return {
        data: rawData.map(normalizeCustomerActivityItem),
        meta: {
            ...meta,
            ...filters,
            available_types: availableTypes,
            timezone: normalizeString(meta.timezone) || 'UTC',
            per_page: Number(meta.per_page) || 20,
            has_more: hasMore,
            next_cursor: nextCursor,
        },
        links: {
            ...links,
            next: links.next || null,
        },
    };
};

export const mergeCustomerActivityItems = (current = [], incoming = []) => {
    const seen = new Set();

    return [...current, ...incoming]
        .map(normalizeCustomerActivityItem)
        .filter((item) => {
            if (seen.has(item.id)) {
                return false;
            }

            seen.add(item.id);
            return true;
        });
};
