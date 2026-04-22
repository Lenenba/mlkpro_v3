export const CUSTOMER_CLIENT_TYPE_COMPANY = 'company';
export const CUSTOMER_CLIENT_TYPE_INDIVIDUAL = 'individual';

export const resolveCustomerClientType = (customer = {}, fallback = CUSTOMER_CLIENT_TYPE_INDIVIDUAL) => {
    const rawValue = typeof customer?.client_type === 'string'
        ? customer.client_type.trim().toLowerCase()
        : '';

    if (rawValue === CUSTOMER_CLIENT_TYPE_COMPANY || rawValue === CUSTOMER_CLIENT_TYPE_INDIVIDUAL) {
        return rawValue;
    }

    return String(customer?.company_name || '').trim() !== ''
        ? CUSTOMER_CLIENT_TYPE_COMPANY
        : fallback;
};

export const buildCustomerClientTypeOptions = (t) => ([
    { id: CUSTOMER_CLIENT_TYPE_COMPANY, name: t('customers.form.client_types.company') },
    { id: CUSTOMER_CLIENT_TYPE_INDIVIDUAL, name: t('customers.form.client_types.individual') },
]);
