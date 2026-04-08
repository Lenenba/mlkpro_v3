import { formatCurrencyAmount } from '@/utils/currency';

const YEARLY_MONTHS = 12;

const toNumeric = (value) => {
    if (value === null || value === '' || typeof value === 'undefined') {
        return null;
    }

    const numeric = Number(value);

    return Number.isFinite(numeric) ? numeric : null;
};

const toFixedAmount = (value) => (
    value === null
        ? null
        : value.toFixed(2)
);

const monthlyEquivalent = (value) => (
    value === null
        ? null
        : (value / YEARLY_MONTHS)
);

const formatDisplayPrice = (amount, currencyCode, fallback) => (
    amount === null || !currencyCode
        ? fallback
        : formatCurrencyAmount(amount, currencyCode)
);

export const pricingForBillingDisplay = (pricing, billingPeriod = 'monthly') => {
    if (!pricing || billingPeriod !== 'yearly') {
        return pricing;
    }

    const currencyCode = pricing.currency_code || null;
    const originalAmount = toNumeric(pricing.original_amount ?? pricing.amount);
    const discountedAmount = toNumeric(pricing.discounted_amount ?? pricing.amount);
    const baseAmount = toNumeric(pricing.amount);

    if (!currencyCode || originalAmount === null || discountedAmount === null) {
        return pricing;
    }

    const originalMonthlyAmount = monthlyEquivalent(originalAmount);
    const discountedMonthlyAmount = monthlyEquivalent(discountedAmount);
    const baseMonthlyAmount = monthlyEquivalent(baseAmount);

    return {
        ...pricing,
        amount: toFixedAmount(baseMonthlyAmount),
        original_amount: toFixedAmount(originalMonthlyAmount),
        discounted_amount: toFixedAmount(discountedMonthlyAmount),
        display_price: formatDisplayPrice(discountedMonthlyAmount, currencyCode, pricing.display_price),
        original_display_price: formatDisplayPrice(originalMonthlyAmount, currencyCode, pricing.original_display_price),
        discounted_display_price: formatDisplayPrice(discountedMonthlyAmount, currencyCode, pricing.discounted_display_price),
    };
};

export const planPricingForBillingDisplay = (plan, billingPeriod = 'monthly', fallbackPricing = null) => {
    const selectedPricing = plan?.prices_by_period?.[billingPeriod]
        || fallbackPricing
        || null;

    if (!selectedPricing) {
        return null;
    }

    if (billingPeriod !== 'yearly') {
        return selectedPricing;
    }

    const yearlyDisplayPricing = pricingForBillingDisplay(selectedPricing, billingPeriod);
    const monthlyReferencePricing = plan?.prices_by_period?.monthly || null;
    const monthlyReferenceOriginalAmount = toNumeric(
        monthlyReferencePricing?.original_amount
        ?? monthlyReferencePricing?.amount
    );
    const yearlyBaseOriginalAmount = toNumeric(
        yearlyDisplayPricing?.original_amount
        ?? yearlyDisplayPricing?.amount
    );
    const promotionActive = hasActiveSubscriptionPromotion(yearlyDisplayPricing);

    if (monthlyReferenceOriginalAmount === null && yearlyBaseOriginalAmount === null) {
        return yearlyDisplayPricing;
    }

    const currencyCode = yearlyDisplayPricing?.currency_code
        || monthlyReferencePricing?.currency_code
        || null;
    const referenceOriginalAmount = promotionActive
        ? (yearlyBaseOriginalAmount ?? monthlyReferenceOriginalAmount)
        : (monthlyReferenceOriginalAmount ?? yearlyBaseOriginalAmount);
    const originalDisplayPrice = formatDisplayPrice(
        referenceOriginalAmount,
        currencyCode,
        monthlyReferencePricing?.original_display_price
            || monthlyReferencePricing?.display_price
            || yearlyDisplayPricing?.original_display_price
    );
    const currentDisplayPrice = yearlyDisplayPricing?.discounted_display_price
        || yearlyDisplayPricing?.display_price
        || null;

    return {
        ...yearlyDisplayPricing,
        original_amount: toFixedAmount(referenceOriginalAmount),
        original_display_price: originalDisplayPrice,
        is_discounted: Boolean(
            originalDisplayPrice
            && currentDisplayPrice
            && originalDisplayPrice !== currentDisplayPrice
        ),
    };
};

export const displayIntervalKeyForBillingPeriod = (billingPeriod, monthlyKey, yearlyKey = monthlyKey) => (
    billingPeriod === 'yearly'
        ? yearlyKey
        : monthlyKey
);

export const hasActiveSubscriptionPromotion = (pricing) => Boolean(
    pricing?.promotion?.is_active
    && Number(pricing?.promotion?.discount_percent || 0) > 0
);
