const toNumber = (value) => {
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : 0;
};

const normalizeCode = (value) => {
    const normalized = String(value || '').trim().toUpperCase();
    return normalized || null;
};

const todayString = (value = new Date()) => {
    const date = value instanceof Date ? value : new Date(value);
    if (Number.isNaN(date.getTime())) {
        return new Date().toISOString().slice(0, 10);
    }
    return date.toISOString().slice(0, 10);
};

const isPromotionCurrentlyValid = (promotion, date = new Date()) => {
    if (!promotion || promotion.status !== 'active') {
        return false;
    }

    const currentDate = todayString(date);
    const startDate = String(promotion.start_date || '');
    const endDate = String(promotion.end_date || '');

    return startDate !== ''
        && endDate !== ''
        && startDate <= currentDate
        && endDate >= currentDate;
};

const buildLines = (items = [], productMap = {}) => (
    items
        .map((item, index) => {
            const product = productMap[item.product_id];
            if (!product) {
                return null;
            }

            const quantity = Math.max(1, toNumber(item.quantity));
            const price = Math.max(0, toNumber(item.price));
            const subtotal = Number((quantity * price).toFixed(2));
            const taxRate = Math.max(0, toNumber(product.tax_rate));

            return {
                index,
                product_id: toNumber(item.product_id),
                item_type: String(product.item_type || 'product'),
                subtotal,
                tax_rate: taxRate,
                tax_total: Number((subtotal * (taxRate / 100)).toFixed(2)),
            };
        })
        .filter(Boolean)
);

const matchedLines = (promotion, lines, customerId) => {
    if (!promotion) {
        return [];
    }

    if (promotion.target_type === 'global') {
        return lines;
    }

    if (promotion.target_type === 'client') {
        return toNumber(customerId) > 0 && toNumber(customerId) === toNumber(promotion.target_id)
            ? lines
            : [];
    }

    return lines.filter((line) => {
        if (toNumber(line.product_id) !== toNumber(promotion.target_id)) {
            return false;
        }

        if (promotion.target_type === 'product') {
            return line.item_type === 'product';
        }

        if (promotion.target_type === 'service') {
            return line.item_type === 'service';
        }

        return false;
    });
};

const allocatePercentageDiscounts = (lines, rate, expectedTotal) => {
    const discounts = {};
    let remaining = Number(expectedTotal.toFixed(2));
    const lastIndex = lines.length - 1;

    lines.forEach((line, position) => {
        if (position === lastIndex) {
            discounts[line.index] = Number(Math.max(0, remaining).toFixed(2));
            return;
        }

        const discount = Number((line.subtotal * (rate / 100)).toFixed(2));
        discounts[line.index] = discount;
        remaining = Number((remaining - discount).toFixed(2));
    });

    return discounts;
};

const allocateFixedDiscounts = (lines, expectedTotal) => {
    const discounts = {};
    const eligibleSubtotal = lines.reduce((sum, line) => sum + toNumber(line.subtotal), 0);
    let remaining = Number(expectedTotal.toFixed(2));
    const lastIndex = lines.length - 1;

    lines.forEach((line, position) => {
        if (position === lastIndex) {
            discounts[line.index] = Number(Math.max(0, remaining).toFixed(2));
            return;
        }

        const share = eligibleSubtotal > 0 ? line.subtotal / eligibleSubtotal : 0;
        const discount = Number((expectedTotal * share).toFixed(2));
        discounts[line.index] = discount;
        remaining = Number((remaining - discount).toFixed(2));
    });

    return discounts;
};

const recalculateTaxTotal = (lines, discounts = {}) => Number(lines.reduce((sum, line) => {
    const discountedSubtotal = Math.max(0, toNumber(line.subtotal) - toNumber(discounts[line.index]));
    return sum + Number((discountedSubtotal * (toNumber(line.tax_rate) / 100)).toFixed(2));
}, 0).toFixed(2));

const emptyResult = (subtotal, baseTaxTotal, error = null) => ({
    source: null,
    error,
    promotion: null,
    subtotal,
    base_tax_total: baseTaxTotal,
    tax_total: baseTaxTotal,
    discount_rate: 0,
    pricing_discount_total: 0,
    total_before_loyalty: Number((subtotal + baseTaxTotal).toFixed(2)),
    discount_label: null,
});

const promotionResult = (promotion, evaluation, subtotal, baseTaxTotal) => {
    const discountTotal = Number(toNumber(evaluation.discount_total).toFixed(2));
    const taxTotal = Number(toNumber(evaluation.tax_total ?? baseTaxTotal).toFixed(2));

    return {
        source: 'promotion',
        error: null,
        promotion,
        subtotal,
        base_tax_total: baseTaxTotal,
        tax_total: taxTotal,
        discount_rate: Number(toNumber(evaluation.effective_rate).toFixed(2)),
        pricing_discount_total: discountTotal,
        total_before_loyalty: Number((Math.max(0, subtotal - discountTotal) + taxTotal).toFixed(2)),
        discount_label: promotion.code ? `${promotion.name} (${promotion.code})` : promotion.name,
    };
};

const customerDiscountResult = (lines, subtotal, baseTaxTotal, customerDiscountRate) => {
    const rate = Math.min(100, Math.max(0, toNumber(customerDiscountRate)));
    if (rate <= 0 || subtotal <= 0) {
        return emptyResult(subtotal, baseTaxTotal);
    }

    const discountTotal = Number((subtotal * (rate / 100)).toFixed(2));
    const lineDiscounts = allocatePercentageDiscounts(lines, rate, discountTotal);
    const taxTotal = recalculateTaxTotal(lines, lineDiscounts);

    return {
        source: 'customer',
        error: null,
        promotion: null,
        subtotal,
        base_tax_total: baseTaxTotal,
        tax_total: taxTotal,
        discount_rate: Number(rate.toFixed(2)),
        pricing_discount_total: discountTotal,
        total_before_loyalty: Number((Math.max(0, subtotal - discountTotal) + taxTotal).toFixed(2)),
        discount_label: null,
    };
};

const evaluatePromotion = (promotion, lines, customerId, subtotal, baseTaxTotal, date = new Date()) => {
    if (!isPromotionCurrentlyValid(promotion, date)) {
        return { eligible: false, error: 'invalid' };
    }

    const usageLimit = promotion.usage_limit !== null ? toNumber(promotion.usage_limit) : null;
    if (usageLimit !== null && toNumber(promotion.usage_count) >= usageLimit) {
        return { eligible: false, error: 'usage_limit' };
    }

    const minimumOrderAmount = promotion.minimum_order_amount !== null
        ? toNumber(promotion.minimum_order_amount)
        : null;
    if (minimumOrderAmount !== null && subtotal < minimumOrderAmount) {
        return { eligible: false, error: 'minimum_order_amount' };
    }

    const eligibleLines = matchedLines(promotion, lines, customerId);
    if (!eligibleLines.length) {
        return { eligible: false, error: 'target' };
    }

    const eligibleSubtotal = Number(eligibleLines.reduce((sum, line) => sum + toNumber(line.subtotal), 0).toFixed(2));
    if (eligibleSubtotal <= 0) {
        return { eligible: false, error: 'target' };
    }

    let discountTotal = 0;
    let lineDiscounts = {};
    if (promotion.discount_type === 'percentage') {
        const rate = Math.min(100, Math.max(0, toNumber(promotion.discount_value)));
        discountTotal = Number((eligibleSubtotal * (rate / 100)).toFixed(2));
        lineDiscounts = allocatePercentageDiscounts(eligibleLines, rate, discountTotal);
    } else {
        discountTotal = Number(Math.min(eligibleSubtotal, toNumber(promotion.discount_value)).toFixed(2));
        lineDiscounts = allocateFixedDiscounts(eligibleLines, discountTotal);
    }

    const taxTotal = recalculateTaxTotal(lines, lineDiscounts);

    return {
        eligible: true,
        discount_total: discountTotal,
        eligible_subtotal: eligibleSubtotal,
        tax_total: taxTotal,
        effective_rate: subtotal > 0 ? Number(((discountTotal / subtotal) * 100).toFixed(2)) : 0,
    };
};

const isBetterPromotion = (candidate, candidateEvaluation, current, currentEvaluation) => {
    if (toNumber(candidateEvaluation.discount_total) !== toNumber(currentEvaluation.discount_total)) {
        return toNumber(candidateEvaluation.discount_total) > toNumber(currentEvaluation.discount_total);
    }

    const rank = (promotion) => (promotion.target_type === 'global' ? 1 : 2);
    if (rank(candidate) !== rank(current)) {
        return rank(candidate) > rank(current);
    }

    return toNumber(candidate.id) < toNumber(current.id);
};

export const resolveSalePromotionPreview = ({
    items = [],
    promotions = [],
    customerId = null,
    customerDiscountRate = 0,
    productMap = {},
    promotionCode = null,
    date = new Date(),
} = {}) => {
    const lines = buildLines(items, productMap);
    const subtotal = Number(lines.reduce((sum, line) => sum + toNumber(line.subtotal), 0).toFixed(2));
    const baseTaxTotal = Number(lines.reduce((sum, line) => sum + toNumber(line.tax_total), 0).toFixed(2));
    const requestedCode = normalizeCode(promotionCode);

    if (requestedCode) {
        const codedPromotion = promotions.find((promotion) => normalizeCode(promotion.code) === requestedCode);
        if (!codedPromotion) {
            return emptyResult(subtotal, baseTaxTotal, 'invalid');
        }

        const evaluation = evaluatePromotion(codedPromotion, lines, customerId, subtotal, baseTaxTotal, date);
        if (!evaluation.eligible) {
            return emptyResult(subtotal, baseTaxTotal, evaluation.error);
        }

        return promotionResult(codedPromotion, evaluation, subtotal, baseTaxTotal);
    }

    let bestPromotion = null;
    let bestEvaluation = null;

    promotions
        .filter((promotion) => !normalizeCode(promotion.code))
        .forEach((promotion) => {
            const evaluation = evaluatePromotion(promotion, lines, customerId, subtotal, baseTaxTotal, date);
            if (!evaluation.eligible) {
                return;
            }

            if (!bestPromotion || isBetterPromotion(promotion, evaluation, bestPromotion, bestEvaluation)) {
                bestPromotion = promotion;
                bestEvaluation = evaluation;
            }
        });

    if (bestPromotion && bestEvaluation) {
        return promotionResult(bestPromotion, bestEvaluation, subtotal, baseTaxTotal);
    }

    return customerDiscountResult(lines, subtotal, baseTaxTotal, customerDiscountRate);
};
