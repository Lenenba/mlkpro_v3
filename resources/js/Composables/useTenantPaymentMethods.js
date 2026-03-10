import { computed, unref } from 'vue';

const ALLOWED_INTERNAL_METHODS = ['cash', 'card', 'bank_transfer', 'check'];

const normalizeMethod = (method) => (typeof method === 'string' ? method.trim().toLowerCase() : '');

export const paymentMethodLabel = (method, labels = {}) => {
    const normalized = normalizeMethod(method);

    if (normalized === 'cash') {
        return labels.cash || 'Cash';
    }
    if (normalized === 'card' || normalized === 'stripe') {
        return labels.card || 'Card';
    }
    if (normalized === 'bank_transfer') {
        return labels.bankTransfer || 'Bank transfer';
    }
    if (normalized === 'check') {
        return labels.check || 'Check';
    }

    return normalized || '-';
};

export const paymentMethodDescription = (method, descriptions = {}) => {
    const normalized = normalizeMethod(method);

    if (normalized === 'cash') {
        return descriptions.cash || '';
    }
    if (normalized === 'card' || normalized === 'stripe') {
        return descriptions.card || '';
    }
    if (normalized === 'bank_transfer') {
        return descriptions.bankTransfer || '';
    }
    if (normalized === 'check') {
        return descriptions.check || '';
    }

    return '';
};

export const useTenantPaymentMethods = (settingsSource) => {
    const settings = computed(() => unref(settingsSource) || {});

    const allowedPaymentMethods = computed(() => {
        const raw = Array.isArray(settings.value?.enabled_methods_internal)
            ? settings.value.enabled_methods_internal
            : [];

        const normalized = raw
            .map((method) => normalizeMethod(method))
            .filter((method, index, array) => method && array.indexOf(method) === index)
            .filter((method) => ALLOWED_INTERNAL_METHODS.includes(method));

        return normalized.length ? normalized : ['cash', 'card'];
    });

    const defaultPaymentMethod = computed(() => {
        const configured = normalizeMethod(settings.value?.default_method_internal);

        if (configured && allowedPaymentMethods.value.includes(configured)) {
            return configured;
        }

        return allowedPaymentMethods.value[0] || 'cash';
    });

    const hasMultiplePaymentMethods = computed(() => allowedPaymentMethods.value.length > 1);
    const singlePaymentMethod = computed(() =>
        hasMultiplePaymentMethods.value ? null : (allowedPaymentMethods.value[0] || defaultPaymentMethod.value)
    );
    const hasCardMethodEnabled = computed(() => allowedPaymentMethods.value.includes('card'));

    return {
        allowedPaymentMethods,
        defaultPaymentMethod,
        hasMultiplePaymentMethods,
        singlePaymentMethod,
        hasCardMethodEnabled,
    };
};
