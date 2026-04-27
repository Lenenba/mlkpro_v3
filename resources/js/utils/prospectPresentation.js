const humanizeToken = (value) =>
    String(value || '')
        .replace(/[_-]+/g, ' ')
        .trim()
        .replace(/\b\w/g, (character) => character.toUpperCase());

export const prospectIsAnonymized = (lead) => Boolean(lead?.meta?.privacy?.anonymized_at);

export const prospectSourceKey = (channel) => {
    if (!channel) {
        return 'unknown';
    }

    const value = String(channel).toLowerCase();
    const aliases = {
        web: 'web_form',
        website: 'web_form',
        form: 'web_form',
    };

    return aliases[value] || value || 'unknown';
};

export const prospectSourceLabel = (channel, t) => {
    const key = prospectSourceKey(channel);
    const translationKey = `requests.sources.${key}`;
    const translated = t(translationKey);

    return translated === translationKey ? humanizeToken(key) : translated;
};

export const prospectRequestTypeValue = (lead) =>
    String(lead?.meta?.request_type || '').trim();

export const prospectRequestTypeLabel = (lead, t) => {
    const value = prospectRequestTypeValue(lead);
    if (!value) {
        return t('requests.labels.no_type');
    }

    const normalized = value.toLowerCase();
    const translationKey = `requests.request_types.${normalized}`;
    const translated = t(translationKey);

    return translated === translationKey ? humanizeToken(value) : translated;
};

export const prospectCompanyLabel = (lead, t) => {
    if (prospectIsAnonymized(lead)) {
        return t('requests.labels.anonymized_company');
    }

    return lead?.customer?.company_name
        || lead?.meta?.company_name
        || lead?.meta?.company
        || lead?.meta?.business_name
        || t('requests.labels.unknown_company');
};

export const prospectCustomerLabel = (lead, t) => {
    if (prospectIsAnonymized(lead)) {
        return t('requests.labels.anonymized_customer');
    }

    return lead?.customer?.company_name
        || `${lead?.customer?.first_name || ''} ${lead?.customer?.last_name || ''}`.trim()
        || lead?.contact_name
        || t('requests.labels.unknown_customer');
};

export const prospectPrimaryLabel = (lead, t) => {
    if (prospectIsAnonymized(lead)) {
        return t('requests.labels.anonymized_request_number', { id: lead?.id });
    }

    return lead?.contact_name
        || lead?.title
        || lead?.service_type
        || t('requests.labels.request_number', { id: lead?.id });
};

export const prospectSecondaryLabel = (lead, t) => {
    const primary = prospectPrimaryLabel(lead, t);
    const title = String(lead?.title || '').trim();
    const serviceType = String(lead?.service_type || '').trim();

    if (prospectIsAnonymized(lead)) {
        return serviceType && serviceType !== primary ? serviceType : '';
    }

    if (title && title !== primary) {
        return title;
    }

    if (serviceType && serviceType !== primary) {
        return serviceType;
    }

    return '';
};

export const prospectPriorityKey = (priority) => {
    const value = Number(priority || 0);

    if (value >= 90) {
        return 'urgent';
    }

    if (value >= 70) {
        return 'high';
    }

    if (value > 0) {
        return 'normal';
    }

    return 'low';
};

export const prospectPriorityLabel = (priority, t) => {
    const key = prospectPriorityKey(priority);
    const translationKey = `requests.priorities.${key}`;
    const translated = t(translationKey);

    return translated === translationKey ? humanizeToken(key) : translated;
};

export const prospectConsentLabel = (value, t) => {
    if (value === true) {
        return t('requests.labels.consent_yes');
    }

    if (value === false) {
        return t('requests.labels.consent_no');
    }

    return t('requests.labels.consent_unknown');
};
