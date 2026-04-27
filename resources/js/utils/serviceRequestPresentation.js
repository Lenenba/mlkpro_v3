export const serviceRequestTitle = (item, t) => (
    item?.title
    || item?.service_type
    || item?.requester_name
    || t('service_requests.labels.request_fallback')
);

export const serviceRequestRequesterLabel = (item, t) => (
    item?.requester_name
    || item?.requester_email
    || item?.requester_phone
    || t('service_requests.labels.unknown_requester')
);

export const serviceRequestSourceLabel = (source, t) => {
    switch (source) {
        case 'manual_admin':
            return t('service_requests.sources.manual_admin');
        case 'customer_portal':
            return t('service_requests.sources.customer_portal');
        case 'public_form':
            return t('service_requests.sources.public_form');
        case 'campaign':
            return t('service_requests.sources.campaign');
        case 'api':
            return t('service_requests.sources.api');
        case 'import':
            return t('service_requests.sources.import');
        default:
            return source || t('service_requests.sources.other');
    }
};

export const serviceRequestStatusLabel = (status, t) => {
    switch (status) {
        case 'new':
            return t('service_requests.status.new');
        case 'in_progress':
            return t('service_requests.status.in_progress');
        case 'pending':
            return t('service_requests.status.pending');
        case 'accepted':
            return t('service_requests.status.accepted');
        case 'refused':
            return t('service_requests.status.refused');
        case 'completed':
            return t('service_requests.status.completed');
        case 'cancelled':
            return t('service_requests.status.cancelled');
        default:
            return status || t('service_requests.labels.unknown_status');
    }
};

export const serviceRequestStatusClass = (status) => {
    switch (status) {
        case 'new':
            return 'bg-amber-100 text-amber-800 dark:bg-amber-500/10 dark:text-amber-300';
        case 'in_progress':
            return 'bg-sky-100 text-sky-800 dark:bg-sky-500/10 dark:text-sky-300';
        case 'pending':
            return 'bg-stone-100 text-stone-700 dark:bg-neutral-700 dark:text-neutral-200';
        case 'accepted':
            return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300';
        case 'refused':
            return 'bg-rose-100 text-rose-800 dark:bg-rose-500/10 dark:text-rose-300';
        case 'completed':
            return 'bg-blue-100 text-blue-800 dark:bg-blue-500/10 dark:text-blue-300';
        case 'cancelled':
            return 'bg-stone-100 text-stone-700 dark:bg-neutral-700 dark:text-neutral-200';
        default:
            return 'bg-stone-100 text-stone-700 dark:bg-neutral-700 dark:text-neutral-200';
    }
};

export const serviceRequestRelationKind = (item) => {
    if (item?.customer) {
        return 'customer';
    }

    if (item?.prospect) {
        return 'prospect';
    }

    return 'unlinked';
};

export const serviceRequestRelationLabel = (item, t) => {
    switch (serviceRequestRelationKind(item)) {
        case 'customer':
            return t('service_requests.relations.customer');
        case 'prospect':
            return t('service_requests.relations.prospect');
        default:
            return t('service_requests.relations.unlinked');
    }
};

export const serviceRequestCustomerLabel = (customer) => (
    customer?.company_name
    || `${customer?.first_name || ''} ${customer?.last_name || ''}`.trim()
    || customer?.email
    || (customer?.id ? `#${customer.id}` : null)
);

export const serviceRequestProspectLabel = (prospect) => (
    prospect?.title
    || prospect?.contact_name
    || prospect?.service_type
    || (prospect?.id ? `#${prospect.id}` : null)
);

export const serviceRequestAddressLabel = (item, t) => {
    const parts = [
        item?.street1,
        item?.street2,
        item?.city,
        item?.state,
        item?.postal_code,
        item?.country,
    ].filter(Boolean);

    return parts.length > 0
        ? parts.join(', ')
        : t('service_requests.labels.no_address');
};
