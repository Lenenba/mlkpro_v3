import { usePage } from '@inertiajs/vue3';
import { computed, unref } from 'vue';

const DEFAULT_CURRENCY_CODE = 'CAD';

const LOCALE_BY_CURRENCY = {
    CAD: 'en-CA',
    EUR: 'fr-FR',
    USD: 'en-US',
};

const formatterCache = new Map();

export const normalizeCurrencyCode = (value) => {
    const normalized = typeof value === 'string' ? value.trim().toUpperCase() : '';

    return Object.prototype.hasOwnProperty.call(LOCALE_BY_CURRENCY, normalized)
        ? normalized
        : DEFAULT_CURRENCY_CODE;
};

export const resolveCurrencyLocale = (currencyCode) => {
    const normalized = normalizeCurrencyCode(currencyCode);

    return LOCALE_BY_CURRENCY[normalized] || LOCALE_BY_CURRENCY[DEFAULT_CURRENCY_CODE];
};

const resolveFormatter = (currencyCode, options = {}) => {
    const normalized = normalizeCurrencyCode(currencyCode);
    const key = JSON.stringify([normalized, options]);

    if (!formatterCache.has(key)) {
        formatterCache.set(key, new Intl.NumberFormat(resolveCurrencyLocale(normalized), {
            style: 'currency',
            currency: normalized,
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
            ...options,
        }));
    }

    return formatterCache.get(key);
};

const resolveNumericValue = (value) => {
    const numeric = Number(value ?? 0);

    return Number.isFinite(numeric) ? numeric : 0;
};

const resolvePageCurrencyCode = (pageProps, preferredCurrency = null) => normalizeCurrencyCode(
    unref(preferredCurrency)
    ?? pageProps?.billing?.tenant_currency_code
    ?? pageProps?.tenantCurrencyCode
    ?? pageProps?.tenant_currency_code
    ?? pageProps?.auth?.account?.currency_code
    ?? pageProps?.company?.currency_code
    ?? pageProps?.invoice?.currency_code
    ?? pageProps?.quote?.currency_code
    ?? pageProps?.order?.currency_code
    ?? pageProps?.auth?.user?.currency_code
    ?? DEFAULT_CURRENCY_CODE
);

export const formatCurrencyAmount = (value, currencyCode = DEFAULT_CURRENCY_CODE, options = {}) =>
    resolveFormatter(currencyCode, options).format(resolveNumericValue(value));

export const useCurrencyFormatter = (preferredCurrency = null) => {
    const page = usePage();

    const currencyCode = computed(() => resolvePageCurrencyCode(page.props, preferredCurrency));

    const formatCurrency = (value, currencyOverride = null, options = {}) =>
        formatCurrencyAmount(value, currencyOverride ?? currencyCode.value, options);

    return {
        currencyCode,
        formatCurrency,
    };
};
