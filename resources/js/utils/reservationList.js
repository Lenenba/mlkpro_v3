const firstString = (...values) =>
    values.find((value) => typeof value === 'string' && value.trim())?.trim() || '';

export const reservationListEntityName = (entity, fallback = '') => {
    const nestedUser = entity?.user || {};
    const composedName = [entity?.first_name, entity?.last_name].filter(Boolean).join(' ').trim();

    return firstString(
        entity?.display_name,
        entity?.name,
        entity?.contact_name,
        entity?.company_name,
        composedName,
        nestedUser?.display_name,
        nestedUser?.name,
        fallback,
    );
};

export const reservationListService = (reservation) =>
    reservation?.service || reservation?.product || reservation?.item || {};

export const reservationListClient = (reservation) =>
    reservation?.client
    || reservation?.customer
    || reservation?.contact
    || reservation?.prospect
    || {};

export const reservationListTeamMember = (reservation) =>
    reservation?.team_member || reservation?.teamMember || reservation?.employee || {};

export const reservationListServiceName = (reservation, fallback = '') => {
    const service = reservationListService(reservation);

    return firstString(
        service?.name,
        service?.title,
        reservation?.service_name,
        reservation?.item_name,
        fallback,
    );
};

export const reservationListImageSource = (entity, options = {}) => {
    const source = firstString(
        entity?.image_url,
        entity?.avatar_url,
        entity?.logo_url,
        entity?.user?.avatar_url,
        entity?.user?.profile_picture_url,
    );
    const isSafeSource = /^(?:https?:\/\/|\/(?!\/)|data:image\/(?:png|jpe?g|webp|gif);base64,)/iu.test(source);

    if (!isSafeSource) {
        return '';
    }

    if (options.requireImageFlag) {
        const explicitlyMissingImage = [false, 0, '0'].includes(entity?.has_image);

        return explicitlyMissingImage ? '' : source;
    }

    return source;
};

export const reservationListSourceKey = (reservation) => {
    const source = firstString(
        reservation?.source,
        reservation?.booking_source,
        reservation?.origin,
        reservation?.channel,
    ).toLowerCase();

    if (['staff', 'admin', 'internal', 'backoffice', 'back_office'].includes(source)) {
        return 'staff';
    }

    if (['client', 'customer', 'portal', 'client_portal'].includes(source)) {
        return 'client';
    }

    if (['api', 'integration', 'webhook'].includes(source)) {
        return 'api';
    }

    if (['public', 'public_booking', 'widget', 'kiosk', 'online'].includes(source)) {
        return 'public_booking';
    }

    return 'unknown';
};

const reservationCapability = (reservation, action) => {
    const capabilities = reservation?.capabilities;

    if (Array.isArray(capabilities)) {
        return capabilities.includes(action) || capabilities.includes(`can_${action}`);
    }

    if (capabilities && typeof capabilities === 'object') {
        const value = capabilities[`can_${action}`] ?? capabilities[action];

        if (typeof value === 'boolean') {
            return value;
        }
    }

    const legacyPermission = reservation?.permissions?.[`can_${action}`];

    return typeof legacyPermission === 'boolean' ? legacyPermission : null;
};

export const reservationListCanView = (reservation) => reservationCapability(reservation, 'view') !== false;

export const reservationListCanEdit = (reservation, canManage) => {
    const capability = reservationCapability(reservation, 'edit');

    return reservationListCanView(reservation) && Boolean(canManage) && capability !== false;
};

export const reservationListCanDelete = (reservation, canManage) => {
    const capability = reservationCapability(reservation, 'delete');

    return reservationListCanView(reservation) && Boolean(canManage) && capability !== false;
};

export const reservationListCanUpdateStatus = (reservation) => (
    reservationListCanView(reservation)
    && reservationCapability(reservation, 'update_status') === true
);

export const reservationListAllowedStatusTransitions = (reservation) => {
    if (!reservationListCanUpdateStatus(reservation)) {
        return [];
    }

    const transitions = reservation?.permissions?.allowed_status_transitions
        ?? reservation?.capabilities?.allowed_status_transitions
        ?? reservation?.allowed_status_transitions
        ?? [];

    return Array.isArray(transitions)
        ? [...new Set(transitions.map((status) => String(status || '').trim()).filter(Boolean))]
        : [];
};

const validReservationDate = (value) => {
    const date = value ? new Date(value) : null;

    return date && !Number.isNaN(date.getTime()) ? date : null;
};

export const reservationListQuickStatusAction = (reservation, now = new Date()) => {
    const allowed = reservationListAllowedStatusTransitions(reservation);
    const status = String(reservation?.status || '');

    if (!allowed.length) {
        return null;
    }

    if (status === 'pending' && allowed.includes('confirmed')) {
        return { status: 'confirmed', labelKey: 'confirm', destructive: false };
    }

    if (status === 'rescheduled' && allowed.includes('confirmed')) {
        return { status: 'confirmed', labelKey: 'confirm', destructive: false };
    }

    const endsAt = validReservationDate(reservation?.ends_at || reservation?.end);
    const referenceNow = validReservationDate(now) || new Date();
    if (endsAt && endsAt <= referenceNow && allowed.includes('completed')) {
        return { status: 'completed', labelKey: 'complete', destructive: false };
    }

    if (allowed.includes('cancelled')) {
        return { status: 'cancelled', labelKey: 'cancel', destructive: true };
    }

    const fallbackStatus = allowed[0];
    const fallbackLabelKeys = {
        confirmed: 'confirm',
        pending: 'set_pending',
        completed: 'complete',
        no_show: 'no_show',
        cancelled: 'cancel',
    };

    return fallbackLabelKeys[fallbackStatus]
        ? {
            status: fallbackStatus,
            labelKey: fallbackLabelKeys[fallbackStatus],
            destructive: fallbackStatus === 'cancelled',
        }
        : null;
};

export const reservationListSecondaryStatusActions = (reservation, now = new Date()) => {
    const primaryStatus = reservationListQuickStatusAction(reservation, now)?.status;
    const labelKeys = {
        confirmed: 'confirm',
        pending: 'set_pending',
        completed: 'complete',
        no_show: 'no_show',
        cancelled: 'cancel',
    };

    return reservationListAllowedStatusTransitions(reservation)
        .filter((status) => status !== primaryStatus && labelKeys[status])
        .map((status) => ({
            status,
            labelKey: labelKeys[status],
            destructive: status === 'cancelled',
        }));
};

const RESERVATION_LIST_SORT_COLUMNS = new Set([
    'date',
    'status',
    'client',
    'service',
    'team_member',
]);

export const nextReservationListSort = (currentSort, column) => {
    const normalizedColumn = RESERVATION_LIST_SORT_COLUMNS.has(column) ? column : 'date';
    const ascending = `${normalizedColumn}_asc`;
    const descending = `${normalizedColumn}_desc`;

    return [ascending, normalizedColumn].includes(String(currentSort || ''))
        ? descending
        : ascending;
};

export const reservationListSortColumn = (sort) => {
    const value = String(sort || '');
    const column = value.replace(/_(?:asc|desc)$/u, '');

    return RESERVATION_LIST_SORT_COLUMNS.has(column) ? column : 'date';
};

export const reservationListSortDirection = (sort) => (
    String(sort || '').endsWith('_desc') ? 'desc' : 'asc'
);

export const reservationListSortValue = (column, direction) => {
    const normalizedColumn = RESERVATION_LIST_SORT_COLUMNS.has(column) ? column : 'date';
    const normalizedDirection = direction === 'desc' ? 'desc' : 'asc';

    return `${normalizedColumn}_${normalizedDirection}`;
};
