<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import axios from 'axios';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import PlanPriceDisplay from '@/Components/Billing/PlanPriceDisplay.vue';
import {
    displayIntervalKeyForBillingPeriod,
    hasActiveSubscriptionPromotion,
    planPricingForBillingDisplay,
} from '@/utils/subscriptionPricing';
import Modal from '@/Components/Modal.vue';
import SettingsLayout from '@/Layouts/SettingsLayout.vue';
import SettingsTabs from '@/Components/SettingsTabs.vue';

const props = defineProps({
    billing: {
        type: Object,
        default: () => ({}),
    },
    availableMethods: {
        type: Array,
        default: () => [],
    },
    paymentMethods: {
        type: Array,
        default: () => [],
    },
    defaultPaymentMethod: {
        type: String,
        default: null,
    },
    cashAllowedContexts: {
        type: Array,
        default: () => [],
    },
    tipSettings: {
        type: Object,
        default: () => ({}),
    },
    loyaltyProgram: {
        type: Object,
        default: () => ({}),
    },
    plans: {
        type: Array,
        default: () => [],
    },
    assistantAddon: {
        type: Object,
        default: () => ({}),
    },
    subscription: {
        type: Object,
        default: () => ({}),
    },
    seatQuantity: {
        type: Number,
        default: 1,
    },
    checkoutStatus: {
        type: String,
        default: null,
    },
    checkoutPlanKey: {
        type: String,
        default: null,
    },
    checkoutBillingPeriod: {
        type: String,
        default: null,
    },
    activePlanKey: {
        type: String,
        default: null,
    },
    creditStatus: {
        type: String,
        default: null,
    },
    paddle: {
        type: Object,
        default: () => ({}),
    },
    stripeConnect: {
        type: Object,
        default: () => ({}),
    },
    connectStatus: {
        type: String,
        default: null,
    },
});

const { t, locale } = useI18n();
const page = usePage();

const ALLOWED_PAYMENT_METHOD_IDS = ['cash', 'card', 'bank_transfer', 'check'];
const FALLBACK_PAYMENT_METHOD_OPTIONS = [
    { id: 'cash', name: 'Cash' },
    { id: 'card', name: 'Card' },
    { id: 'bank_transfer', name: 'Bank transfer' },
    { id: 'check', name: 'Check' },
];
const DEFAULT_CASH_CONTEXTS = ['reservation', 'invoice', 'store_order', 'tip', 'walk_in'];
const CASH_CONTEXT_OPTIONS = [
    { id: 'reservation', fallback: 'Reservation' },
    { id: 'invoice', fallback: 'Invoice' },
    { id: 'store_order', fallback: 'Store order' },
    { id: 'tip', fallback: 'Tip' },
    { id: 'walk_in', fallback: 'Walk-in' },
];

const normalizeId = (value) => (typeof value === 'string' ? value.trim().toLowerCase() : '');

const sanitizePaymentMethods = (methods) => {
    if (!Array.isArray(methods)) {
        return [];
    }

    const sanitized = [];
    methods.forEach((method) => {
        const normalized = normalizeId(method);
        if (!ALLOWED_PAYMENT_METHOD_IDS.includes(normalized)) {
            return;
        }
        if (sanitized.includes(normalized)) {
            return;
        }
        sanitized.push(normalized);
    });

    return sanitized;
};

const sanitizeCashContexts = (contexts) => {
    if (!Array.isArray(contexts)) {
        return [];
    }

    const sanitized = [];
    contexts.forEach((context) => {
        const normalized = normalizeId(context);
        if (!DEFAULT_CASH_CONTEXTS.includes(normalized)) {
            return;
        }
        if (sanitized.includes(normalized)) {
            return;
        }
        sanitized.push(normalized);
    });

    return sanitized;
};

const initialPaymentMethods = (() => {
    const selected = sanitizePaymentMethods(props.paymentMethods);
    return selected.length ? selected : ['cash', 'card'];
})();

const initialDefaultPaymentMethod = (() => {
    const normalized = normalizeId(props.defaultPaymentMethod);
    if (normalized && initialPaymentMethods.includes(normalized)) {
        return normalized;
    }
    return initialPaymentMethods[0] || 'cash';
})();

const initialCashContexts = (() => {
    const selected = sanitizeCashContexts(props.cashAllowedContexts);
    return selected.length ? selected : [...DEFAULT_CASH_CONTEXTS];
})();

const form = useForm({
    payment_methods: initialPaymentMethods,
    default_payment_method: initialDefaultPaymentMethod,
    cash_allowed_contexts: initialCashContexts,
    tips: {
        max_percent: Number(props.tipSettings?.max_percent ?? 30),
        max_fixed_amount: Number(props.tipSettings?.max_fixed_amount ?? 200),
        default_percent: Number(props.tipSettings?.default_percent ?? 10),
        allocation_strategy: props.tipSettings?.allocation_strategy || 'primary',
        partial_refund_rule: props.tipSettings?.partial_refund_rule || 'prorata',
    },
    loyalty: {
        is_enabled: Boolean(props.loyaltyProgram?.is_enabled ?? true),
        points_per_currency_unit: Number(props.loyaltyProgram?.points_per_currency_unit ?? 1),
        minimum_spend: Number(props.loyaltyProgram?.minimum_spend ?? 0),
        rounding_mode: props.loyaltyProgram?.rounding_mode || 'floor',
        points_label: props.loyaltyProgram?.points_label || 'points',
    },
});

const paddleUiError = ref('');
const paymentMethodIsLoading = ref(false);
const connectIsLoading = ref(false);
const connectError = ref('');
const assistantAddonIsLoading = ref(false);
const assistantAddonError = ref('');
const assistantCreditIsLoading = ref(false);
const assistantCreditError = ref('');

const billingProvider = computed(() => (props.billing?.provider_effective || props.billing?.provider || 'paddle').toLowerCase());
const isPaddleProvider = computed(() => billingProvider.value === 'paddle');
const isStripeProvider = computed(() => billingProvider.value === 'stripe');
const providerReady = computed(() => props.billing?.provider_ready ?? true);
const tenantCurrencyCode = computed(() => String(props.billing?.tenant_currency_code || 'CAD').toUpperCase());
const stripeConnectEnabled = computed(() => Boolean(props.stripeConnect?.enabled));
const stripeConnectHasAccount = computed(() => Boolean(props.stripeConnect?.account_id));
const stripeConnectReady = computed(() => Boolean(props.stripeConnect?.charges_enabled && props.stripeConnect?.payouts_enabled));
const stripeConnectRequirements = computed(() => props.stripeConnect?.requirements || {});
const stripeConnectDueFields = computed(() => {
    const requirements = stripeConnectRequirements.value;
    const fields = [];
    ['currently_due', 'past_due'].forEach((key) => {
        const values = requirements?.[key];
        if (Array.isArray(values)) {
            fields.push(...values);
        }
    });
    return [...new Set(fields)];
});
const stripeConnectDisabledReason = computed(() => stripeConnectRequirements.value?.disabled_reason || '');
const stripeConnectActionRequired = computed(() =>
    stripeConnectHasAccount.value && (stripeConnectDueFields.value.length > 0 || Boolean(stripeConnectDisabledReason.value))
);
const stripeConnectNeedsAction = computed(() => stripeConnectEnabled.value && !stripeConnectReady.value);
const assistantAddon = computed(() => props.assistantAddon || {});
const assistantIncluded = computed(() => Boolean(assistantAddon.value.included));
const assistantEnabled = computed(() => Boolean(assistantAddon.value.enabled));
const assistantAddonEnabled = computed(() => Boolean(assistantAddon.value.addon_enabled));
const assistantAddonAvailable = computed(() => Boolean(assistantAddon.value.available));
const assistantAddonMode = computed(() => assistantAddon.value.mode || 'none');
const assistantUsage = computed(() => assistantAddon.value.usage || {});
const assistantCredits = computed(() => assistantAddon.value.credits || {});
const assistantCreditBalance = computed(() => Number(assistantCredits.value.balance || 0));
const assistantCreditPackSize = computed(() => Number(assistantCredits.value.pack_size || 0));
const assistantCreditAvailable = computed(() => Boolean(assistantCredits.value.enabled));
const assistantCreditMode = computed(() => assistantAddonMode.value === 'credit');
const assistantAddonSubtitle = computed(() =>
    assistantCreditMode.value
        ? t('settings.billing.assistant_addon.subtitle_credit')
        : t('settings.billing.assistant_addon.subtitle')
);
const loyaltyFeatureEnabled = computed(() => {
    const featureFlag = page.props.auth?.account?.features?.loyalty;
    if (typeof featureFlag === 'boolean') {
        return featureFlag;
    }

    return Boolean(props.loyaltyProgram?.feature_enabled ?? false);
});

const isSubscribed = computed(() => Boolean(props.subscription?.active));
const hasSubscription = computed(() => Boolean(props.subscription?.provider_id));
const currentSubscriptionBillingPeriod = computed(() =>
    props.subscription?.billing_period === 'yearly' ? 'yearly' : 'monthly'
);
const selectedBillingPeriod = ref(
    props.checkoutBillingPeriod === 'yearly'
        ? 'yearly'
        : currentSubscriptionBillingPeriod.value
);
const priceForBillingPeriod = (plan, billingPeriod = selectedBillingPeriod.value) => {
    const explicit = plan?.prices_by_period?.[billingPeriod];
    if (explicit) {
        return explicit;
    }

    if (billingPeriod === 'monthly' && (plan?.price_id || plan?.display_price || plan?.price)) {
        return {
            billing_period: 'monthly',
            stripe_price_id: plan?.price_id || null,
            display_price: plan?.display_price || plan?.price || null,
            amount: plan?.amount || null,
            original_display_price: plan?.original_display_price || plan?.display_price || plan?.price || null,
            discounted_display_price: plan?.discounted_display_price || plan?.display_price || plan?.price || null,
            is_discounted: Boolean(plan?.is_discounted),
            promotion: plan?.promotion || { is_active: false, discount_percent: null },
        };
    }

    return null;
};
const displayPriceForBillingPeriod = (plan, billingPeriod = selectedBillingPeriod.value) =>
    planPricingForBillingDisplay(
        plan,
        billingPeriod,
        priceForBillingPeriod(plan, billingPeriod)
        || plan?.prices_by_period?.monthly
        || null
    );
const yearlyPromotionActive = computed(() =>
    availablePlanOptions.value.some((plan) => hasActiveSubscriptionPromotion(displayPriceForBillingPeriod(plan, 'yearly')))
);
const hasPlans = computed(() => props.plans.some((plan) => {
    if (plan?.contact_only) {
        return true;
    }

    return ['monthly', 'yearly'].some((period) => Boolean(priceForBillingPeriod(plan, period)?.stripe_price_id));
}));
const canUsePaddle = computed(() => Boolean(isPaddleProvider.value && props.paddle?.js_enabled && props.paddle?.api_enabled && !props.paddle?.error));
const activePlanKey = computed(() => props.activePlanKey || null);

const availablePaymentMethodOptions = computed(() => {
    const fromProps = Array.isArray(props.availableMethods) ? props.availableMethods : [];
    const normalized = [];

    fromProps.forEach((method) => {
        const id = normalizeId(method?.id);
        if (!ALLOWED_PAYMENT_METHOD_IDS.includes(id)) {
            return;
        }
        if (normalized.some((item) => item.id === id)) {
            return;
        }
        const name = typeof method?.name === 'string' && method.name.trim() ? method.name.trim() : id;
        normalized.push({ id, name });
    });

    return normalized.length ? normalized : FALLBACK_PAYMENT_METHOD_OPTIONS;
});

const paymentMethodLabelMap = {
    cash: {
        label: 'settings.billing.payment_methods.methods.cash.label',
        description: 'settings.billing.payment_methods.methods.cash.description',
    },
    card: {
        label: 'settings.billing.payment_methods.methods.card.label',
        description: 'settings.billing.payment_methods.methods.card.description',
    },
    bank_transfer: {
        label: 'settings.billing.payment_methods.methods.bank_transfer.label',
        description: 'settings.billing.payment_methods.methods.bank_transfer.description',
    },
    check: {
        label: 'settings.billing.payment_methods.methods.check.label',
        description: 'settings.billing.payment_methods.methods.check.description',
    },
};

const resolveTranslated = (key, fallback) => {
    const translated = t(key);
    return translated === key ? fallback : translated;
};

const paymentMethodLabel = (method) => {
    const id = normalizeId(method?.id);
    const fallback = typeof method?.name === 'string' && method.name.trim()
        ? method.name.trim()
        : (id || 'Payment');
    const key = paymentMethodLabelMap[id]?.label;
    return key ? resolveTranslated(key, fallback) : fallback;
};

const paymentMethodDescription = (method) => {
    const id = normalizeId(method?.id);
    const key = paymentMethodLabelMap[id]?.description;
    const fallbackMap = {
        cash: 'Payment collected on site.',
        card: 'Card payment using Stripe.',
        bank_transfer: 'Manual bank transfer payment.',
        check: 'Payment by check.',
    };
    return key ? resolveTranslated(key, fallbackMap[id] || '') : (fallbackMap[id] || '');
};

const selectedPaymentMethods = computed(() => sanitizePaymentMethods(form.payment_methods));
const cashMethodEnabled = computed(() => selectedPaymentMethods.value.includes('cash'));
const selectedPaymentMethodOptions = computed(() =>
    availablePaymentMethodOptions.value.filter((method) => selectedPaymentMethods.value.includes(method.id))
);

const methodIsLocked = (methodId) =>
    selectedPaymentMethods.value.length === 1 && selectedPaymentMethods.value[0] === methodId;

const paymentMethodErrors = computed(() =>
    Object.entries(form.errors)
        .filter(([key]) => key === 'payment_methods' || key.startsWith('payment_methods.'))
        .map(([, value]) => String(value))
);

const cashContextErrors = computed(() =>
    Object.entries(form.errors)
        .filter(([key]) => key === 'cash_allowed_contexts' || key.startsWith('cash_allowed_contexts.'))
        .map(([, value]) => String(value))
);

const cashContextLabel = (contextOption) => {
    const key = `settings.billing.payment_methods.cash_contexts.${contextOption.id}`;
    return resolveTranslated(key, contextOption.fallback);
};

const activePlan = computed(() => {
    const subscriptionPlanCode = typeof props.subscription?.plan_code === 'string'
        ? props.subscription.plan_code
        : null;
    if (subscriptionPlanCode) {
        return props.plans.find((plan) => plan.key === subscriptionPlanCode) || null;
    }
    if (activePlanKey.value) {
        return props.plans.find((plan) => plan.key === activePlanKey.value) || null;
    }
    if (!props.subscription?.price_id) {
        return null;
    }

    return props.plans.find((plan) =>
        ['monthly', 'yearly'].some((period) => priceForBillingPeriod(plan, period)?.stripe_price_id === props.subscription.price_id)
    ) || null;
});

const displayedPlan = computed(() => {
    if (!activePlan.value) {
        return null;
    }

    return activePlan.value.key === 'free' ? null : activePlan.value;
});

const PLAN_RECOMMENDATION_ORDER = [
    'solo_essential',
    'solo_pro',
    'solo_growth',
    'starter',
    'growth',
    'scale',
    'enterprise',
];

const planOrder = (planKey) => {
    const index = PLAN_RECOMMENDATION_ORDER.indexOf(planKey);
    return index === -1 ? PLAN_RECOMMENDATION_ORDER.length : index;
};

const isCurrentPlan = (plan) => Boolean(plan?.key && activePlan.value?.key === plan.key);
const isCurrentPlanSelection = (plan, billingPeriod = selectedBillingPeriod.value) =>
    Boolean(plan?.key && activePlan.value?.key === plan.key && currentSubscriptionBillingPeriod.value === billingPeriod);

const resolveInitialAdvisorBusiness = () => {
    if (activePlan.value?.audience === 'solo') {
        return 'solo';
    }

    if (activePlan.value?.key === 'enterprise') {
        return 'custom_scale';
    }

    const seats = Number(props.seatQuantity || 0);
    if (seats > 50) {
        return 'custom_scale';
    }
    if (seats > 25) {
        return 'large_team';
    }
    if (seats > 10) {
        return 'growing_team';
    }

    return 'small_team';
};

const resolveInitialAdvisorFocus = () => {
    switch (activePlan.value?.key) {
    case 'solo_essential':
        return 'quotes';
    case 'solo_pro':
    case 'starter':
        return 'operations';
    case 'solo_growth':
    case 'growth':
    case 'scale':
    case 'enterprise':
        return 'automation';
    default:
        return 'operations';
    }
};

const resolveInitialAdvisorAi = () => {
    if (assistantIncluded.value || ['solo_growth', 'scale', 'enterprise'].includes(activePlan.value?.key || '')) {
        return 'included';
    }

    if (assistantAddonEnabled.value || ['starter', 'growth'].includes(activePlan.value?.key || '')) {
        return 'optional';
    }

    return 'none';
};

const planAdvisor = reactive({
    business: resolveInitialAdvisorBusiness(),
    focus: resolveInitialAdvisorFocus(),
    ai: resolveInitialAdvisorAi(),
});

const planAdvisorDialogOpen = ref(false);
const planAdvisorStep = ref(0);
const planActionLoadingKey = ref(null);
const planActionError = ref('');

const availablePlanOptions = computed(() =>
    props.plans.filter((plan) => {
        if (plan.key === 'free') {
            return false;
        }

        if (isCurrentPlan(plan)) {
            return true;
        }

        if (plan.contact_only) {
            return true;
        }

        return Boolean(priceForBillingPeriod(plan)?.stripe_price_id);
    })
);

const planAdvisorQuestions = computed(() => [
    {
        id: 'business',
        label: t('settings.billing.plans.advisor.questions.business'),
        options: [
            {
                value: 'solo',
                label: t('settings.billing.plans.advisor.options.business.solo.label'),
                description: t('settings.billing.plans.advisor.options.business.solo.description'),
            },
            {
                value: 'small_team',
                label: t('settings.billing.plans.advisor.options.business.small_team.label'),
                description: t('settings.billing.plans.advisor.options.business.small_team.description'),
            },
            {
                value: 'growing_team',
                label: t('settings.billing.plans.advisor.options.business.growing_team.label'),
                description: t('settings.billing.plans.advisor.options.business.growing_team.description'),
            },
            {
                value: 'large_team',
                label: t('settings.billing.plans.advisor.options.business.large_team.label'),
                description: t('settings.billing.plans.advisor.options.business.large_team.description'),
            },
            {
                value: 'custom_scale',
                label: t('settings.billing.plans.advisor.options.business.custom_scale.label'),
                description: t('settings.billing.plans.advisor.options.business.custom_scale.description'),
            },
        ],
    },
    {
        id: 'focus',
        label: t('settings.billing.plans.advisor.questions.focus'),
        options: [
            {
                value: 'quotes',
                label: t('settings.billing.plans.advisor.options.focus.quotes.label'),
                description: t('settings.billing.plans.advisor.options.focus.quotes.description'),
            },
            {
                value: 'operations',
                label: t('settings.billing.plans.advisor.options.focus.operations.label'),
                description: t('settings.billing.plans.advisor.options.focus.operations.description'),
            },
            {
                value: 'automation',
                label: t('settings.billing.plans.advisor.options.focus.automation.label'),
                description: t('settings.billing.plans.advisor.options.focus.automation.description'),
            },
        ],
    },
    {
        id: 'ai',
        label: t('settings.billing.plans.advisor.questions.ai'),
        options: [
            {
                value: 'none',
                label: t('settings.billing.plans.advisor.options.ai.none.label'),
                description: t('settings.billing.plans.advisor.options.ai.none.description'),
            },
            {
                value: 'optional',
                label: t('settings.billing.plans.advisor.options.ai.optional.label'),
                description: t('settings.billing.plans.advisor.options.ai.optional.description'),
            },
            {
                value: 'included',
                label: t('settings.billing.plans.advisor.options.ai.included.label'),
                description: t('settings.billing.plans.advisor.options.ai.included.description'),
            },
        ],
    },
]);

const planAdvisorSteps = computed(() => [
    { id: 'business', label: t('settings.billing.plans.advisor.steps.business') },
    { id: 'focus', label: t('settings.billing.plans.advisor.steps.focus') },
    { id: 'ai', label: t('settings.billing.plans.advisor.steps.ai') },
    { id: 'results', label: t('settings.billing.plans.advisor.steps.results') },
]);

const planAdvisorActiveQuestion = computed(() => planAdvisorQuestions.value[planAdvisorStep.value] || null);
const planAdvisorOnResults = computed(() => planAdvisorStep.value >= planAdvisorQuestions.value.length);
const planAdvisorCanMoveNext = computed(() => {
    if (planAdvisorOnResults.value) {
        return false;
    }

    const question = planAdvisorActiveQuestion.value;
    return Boolean(question?.id && planAdvisor[question.id]);
});

const planAdvisorTriggerLabel = computed(() =>
    displayedPlan.value
        ? t('settings.billing.plans.advisor.trigger_change')
        : t('settings.billing.plans.advisor.trigger_choose')
);

const openPlanAdvisor = () => {
    planActionError.value = '';
    planAdvisorStep.value = 0;
    planAdvisorDialogOpen.value = true;
};

const closePlanAdvisor = () => {
    planAdvisorDialogOpen.value = false;
};

const nextPlanAdvisorStep = () => {
    if (!planAdvisorCanMoveNext.value) {
        return;
    }

    planAdvisorStep.value = Math.min(planAdvisorStep.value + 1, planAdvisorSteps.value.length - 1);
};

const previousPlanAdvisorStep = () => {
    planAdvisorStep.value = Math.max(planAdvisorStep.value - 1, 0);
};

const goToPlanAdvisorStep = (index) => {
    if (!Number.isInteger(index)) {
        return;
    }

    if (index < 0 || index >= planAdvisorSteps.value.length) {
        return;
    }

    if (index > planAdvisorStep.value) {
        return;
    }

    planAdvisorStep.value = index;
};

const addRecommendationReason = (reasons, key, params = {}) => {
    const label = t(key, params);
    if (!reasons.includes(label)) {
        reasons.push(label);
    }
};

const resolveBillingPeriodLabel = (billingPeriod) => (
    billingPeriod === 'yearly'
        ? t('settings.billing.plan.yearly')
        : t('settings.billing.plan.monthly')
);

const resolveBillingIntervalLabel = (billingPeriod) => (
    t(displayIntervalKeyForBillingPeriod(
        billingPeriod,
        'settings.billing.plan.interval_month'
    ))
);

const previewPlanFeatures = (plan) => {
    if (!Array.isArray(plan?.features)) {
        return [];
    }

    return plan.features.slice(0, 4);
};

const recommendationBadgeLabel = (recommendation, index) => {
    if (recommendation.isCurrent) {
        return t('settings.billing.plan.badge_active');
    }

    return index === 0
        ? t('settings.billing.plans.advisor.top_match')
        : t('settings.billing.plans.advisor.alternative');
};

const recommendationScore = (plan) => {
    let score = 0;
    const reasons = [];

    switch (planAdvisor.business) {
    case 'solo':
        if (plan.audience === 'solo') {
            score += 9;
            addRecommendationReason(reasons, 'settings.billing.plans.advisor.reasons.solo');
        } else {
            score -= 8;
        }
        break;
    case 'small_team':
        if (plan.key === 'starter') {
            score += 9;
            addRecommendationReason(reasons, 'settings.billing.plans.advisor.reasons.small_team');
        } else if (plan.key === 'growth') {
            score += 4;
            addRecommendationReason(reasons, 'settings.billing.plans.advisor.reasons.room_to_grow');
        } else if (plan.key === 'scale') {
            score += 1;
        } else if (plan.key === 'enterprise') {
            score -= 3;
        } else {
            score -= 6;
        }
        break;
    case 'growing_team':
        if (plan.key === 'growth') {
            score += 9;
            addRecommendationReason(reasons, 'settings.billing.plans.advisor.reasons.growing_team');
        } else if (plan.key === 'scale') {
            score += 5;
            addRecommendationReason(reasons, 'settings.billing.plans.advisor.reasons.large_team');
        } else if (plan.key === 'starter') {
            score += 3;
            addRecommendationReason(reasons, 'settings.billing.plans.advisor.reasons.small_team');
        } else if (plan.key === 'enterprise') {
            score += 0;
        } else {
            score -= 8;
        }
        break;
    case 'large_team':
        if (plan.key === 'scale') {
            score += 10;
            addRecommendationReason(reasons, 'settings.billing.plans.advisor.reasons.large_team');
        } else if (plan.key === 'enterprise') {
            score += 5;
            addRecommendationReason(reasons, 'settings.billing.plans.advisor.reasons.custom_scale');
        } else if (plan.key === 'growth') {
            score += 4;
            addRecommendationReason(reasons, 'settings.billing.plans.advisor.reasons.growing_team');
        } else {
            score -= 9;
        }
        break;
    case 'custom_scale':
        if (plan.key === 'enterprise') {
            score += 12;
            addRecommendationReason(reasons, 'settings.billing.plans.advisor.reasons.custom_scale');
        } else if (plan.key === 'scale') {
            score += 5;
            addRecommendationReason(reasons, 'settings.billing.plans.advisor.reasons.large_team');
        } else {
            score -= 10;
        }
        break;
    default:
        break;
    }

    switch (planAdvisor.focus) {
    case 'quotes':
        if (['solo_essential', 'starter'].includes(plan.key)) {
            score += 5;
            addRecommendationReason(reasons, 'settings.billing.plans.advisor.reasons.quotes');
        } else if (['solo_pro', 'growth'].includes(plan.key)) {
            score += 2;
        }
        break;
    case 'operations':
        if (['solo_pro', 'starter', 'growth'].includes(plan.key)) {
            score += 5;
            addRecommendationReason(reasons, 'settings.billing.plans.advisor.reasons.operations');
        } else if (['solo_growth', 'scale'].includes(plan.key)) {
            score += 3;
            addRecommendationReason(reasons, 'settings.billing.plans.advisor.reasons.automation');
        }
        break;
    case 'automation':
        if (['solo_growth', 'growth', 'scale', 'enterprise'].includes(plan.key)) {
            score += 6;
            addRecommendationReason(reasons, 'settings.billing.plans.advisor.reasons.automation');
        } else if (['solo_pro', 'starter'].includes(plan.key)) {
            score += 1;
        }
        break;
    default:
        break;
    }

    switch (planAdvisor.ai) {
    case 'none':
        if (['solo_essential', 'solo_pro', 'starter'].includes(plan.key)) {
            score += 2;
        } else if (plan.key === 'enterprise') {
            score -= 1;
        }
        break;
    case 'optional':
        if (['starter', 'growth'].includes(plan.key)) {
            score += 4;
            addRecommendationReason(reasons, 'settings.billing.plans.advisor.reasons.ai_optional');
        } else if (['solo_growth', 'scale'].includes(plan.key)) {
            score += 2;
            addRecommendationReason(reasons, 'settings.billing.plans.advisor.reasons.ai_included');
        }
        break;
    case 'included':
        if (['solo_growth', 'scale'].includes(plan.key)) {
            score += 7;
            addRecommendationReason(reasons, 'settings.billing.plans.advisor.reasons.ai_included');
        } else if (plan.key === 'enterprise') {
            score += 4;
            addRecommendationReason(reasons, 'settings.billing.plans.advisor.reasons.custom_support');
        } else if (['starter', 'growth'].includes(plan.key)) {
            score += 1;
            addRecommendationReason(reasons, 'settings.billing.plans.advisor.reasons.ai_optional');
        }
        break;
    default:
        break;
    }

    const teamLabel = resolveTeamLimitLabel(plan);
    if (teamLabel) {
        reasons.push(teamLabel);
    }

    if (plan.contact_only) {
        addRecommendationReason(reasons, 'settings.billing.plans.advisor.reasons.custom_support');
    }

    if (plan.recommended) {
        score += 0.5;
    }

    if (isCurrentPlan(plan)) {
        score += 0.25;
    }

    return {
        plan,
        score,
        reasons: reasons.slice(0, 3),
        isCurrent: isCurrentPlan(plan),
    };
};

const recommendedPlans = computed(() =>
    availablePlanOptions.value
        .map(recommendationScore)
        .sort((left, right) => {
            if (right.score !== left.score) {
                return right.score - left.score;
            }

            if (left.isCurrent !== right.isCurrent) {
                return left.isCurrent ? -1 : 1;
            }

            return planOrder(left.plan.key) - planOrder(right.plan.key);
        })
        .slice(0, 3)
);

const resolveTeamLimitLabel = (plan) => {
    if (plan?.owner_only) {
        return null;
    }
    const limit = Number(plan?.team_members_limit);
    if (Number.isFinite(limit) && limit > 0) {
        return t('settings.billing.plan.team_limit', { count: limit });
    }
    if (plan?.contact_only) {
        const min = Number(plan?.team_members_min || 50);
        return t('settings.billing.plan.team_limit_plus', { count: min });
    }
    return null;
};

const checkoutPlanName = computed(() => {
    if (!props.checkoutPlanKey) {
        return null;
    }

    const plan = props.plans.find((item) => item.key === props.checkoutPlanKey);
    return plan?.name || null;
});

const formatDisplayDate = (value) => {
    if (!value) {
        return null;
    }

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return null;
    }

    const localeCode = typeof locale?.value === 'string' && locale.value.trim()
        ? locale.value
        : 'fr';

    return new Intl.DateTimeFormat(localeCode, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    }).format(date);
};

const subscriptionStatusLabel = computed(() => {
    const rawStatus = props.subscription?.status || (props.subscription?.active ? 'active' : 'inactive');
    const statusMap = {
        active: t('settings.billing.status.active'),
        trialing: t('settings.billing.status.trialing'),
        past_due: t('settings.billing.status.past_due'),
        paused: t('settings.billing.status.paused'),
        canceled: t('settings.billing.status.canceled'),
        unpaid: t('settings.billing.status.unpaid'),
        inactive: t('settings.billing.status.inactive'),
    };
    return statusMap[rawStatus] || rawStatus;
});

const trialStatusLabel = computed(() => {
    if (!props.subscription?.on_trial) {
        return null;
    }

    const formattedDate = formatDisplayDate(props.subscription?.trial_ends_at);

    return formattedDate
        ? t('settings.billing.summary.trialing_until', { date: formattedDate })
        : t('settings.billing.summary.trialing');
});

const stripeConnectStatusLabel = computed(() => {
    if (stripeConnectReady.value) {
        return t('settings.billing.connect.status_connected');
    }
    if (stripeConnectActionRequired.value) {
        return t('settings.billing.connect.status_action_required');
    }
    if (stripeConnectHasAccount.value) {
        return t('settings.billing.connect.status_pending');
    }
    return t('settings.billing.connect.status_not_connected');
});

const stripeConnectActionHint = computed(() => {
    if (!stripeConnectActionRequired.value) {
        return '';
    }
    const count = stripeConnectDueFields.value.length;
    return count
        ? t('settings.billing.connect.action_required_hint', { count })
        : t('settings.billing.connect.action_required_hint_generic');
});

const stripeConnectActionLabel = computed(() => {
    if (stripeConnectActionRequired.value) {
        return t('settings.billing.connect.action_complete');
    }
    if (stripeConnectHasAccount.value) {
        return t('settings.billing.connect.action_resume');
    }
    return t('settings.billing.connect.action_connect');
});

const tabPrefix = 'settings-billing';
const tabs = computed(() => [
    { id: 'plans', label: t('settings.billing.tabs.plans.label'), description: t('settings.billing.tabs.plans.description') },
    { id: 'payment', label: t('settings.billing.tabs.payment.label'), description: t('settings.billing.tabs.payment.description') },
]);

const resolveInitialTab = () => {
    if (typeof window === 'undefined') {
        return tabs.value[0].id;
    }
    const stored = window.sessionStorage.getItem(`${tabPrefix}-tab`);
    return tabs.value.some((tab) => tab.id === stored) ? stored : tabs.value[0].id;
};

const activeTab = ref(resolveInitialTab());

watch(activeTab, (value) => {
    if (typeof window === 'undefined') {
        return;
    }
    window.sessionStorage.setItem(`${tabPrefix}-tab`, value);
});

watch(
    () => [planAdvisor.business, planAdvisor.focus, planAdvisor.ai],
    () => {
        planActionError.value = '';
    }
);

const submit = () => {
    form.transform((data) => {
        const payload = { ...data };
        if (!loyaltyFeatureEnabled.value) {
            delete payload.loyalty;
        }
        return payload;
    }).put(route('settings.billing.update'), { preserveScroll: true });
};

const resolveStripeError = (error, fallbackKey) => {
    const response = error?.response;
    if (response?.data) {
        if (typeof response.data === 'string') {
            return response.data;
        }
        if (response.data.message) {
            return response.data.message;
        }
    }
    if (response?.status) {
        return `${t(fallbackKey)} (HTTP ${response.status})`;
    }
    return t(fallbackKey);
};

const startStripeConnect = async () => {
    if (!stripeConnectEnabled.value) {
        connectError.value = t('settings.billing.errors.stripe_not_configured');
        return;
    }

    connectError.value = '';
    connectIsLoading.value = true;
    try {
        const response = await axios.post(route('settings.billing.connect'));
        const url = response?.data?.url;
        if (!url) {
            throw new Error(t('settings.billing.connect.error_start'));
        }
        window.location.href = url;
    } catch (error) {
        connectError.value = resolveStripeError(error, 'settings.billing.connect.error_start');
    } finally {
        connectIsLoading.value = false;
    }
};

const startPaymentMethodUpdate = async () => {
    paddleUiError.value = '';
    if (isStripeProvider.value) {
        if (!providerReady.value) {
            paddleUiError.value = t('settings.billing.errors.stripe_not_configured');
            return;
        }

        paymentMethodIsLoading.value = true;
        try {
            const response = await axios.post(route('settings.billing.portal'));
            const url = response?.data?.url;
            if (!url) {
                throw new Error(t('settings.billing.errors.stripe_portal_failed'));
            }
            window.location.href = url;
        } catch (error) {
            paddleUiError.value = resolveStripeError(error, 'settings.billing.errors.stripe_portal_failed');
        } finally {
            paymentMethodIsLoading.value = false;
        }
        return;
    }

    if (!canUsePaddle.value) {
        paddleUiError.value = props.paddle?.error || t('settings.billing.errors.paddle_not_configured');
        return;
    }

    if (!hasSubscription.value) {
        paddleUiError.value = t('settings.billing.errors.no_active_subscription');
        return;
    }

    paymentMethodIsLoading.value = true;

    const ready = await ensurePaddleReady();
    if (!ready) {
        paymentMethodIsLoading.value = false;
        paddleUiError.value = paddleUiError.value || t('settings.billing.errors.paddle_not_ready');
        return;
    }

    try {
        const response = await axios.post(route('settings.billing.payment-method'));
        const transactionId = response?.data?.transaction_id;

        if (!transactionId) {
            throw new Error(t('settings.billing.errors.missing_transaction'));
        }

        const successUrl = route('settings.billing.edit', { checkout: 'payment-method' });

        window.Paddle.Checkout.open({
            transactionId,
            settings: {
                displayMode: 'overlay',
                successUrl,
                allowLogout: false,
            },
        });
    } catch (error) {
        const message = error?.response?.data?.message || t('settings.billing.errors.payment_update_failed');
        paddleUiError.value = message;
    } finally {
        paymentMethodIsLoading.value = false;
    }
};

const ensurePaddleReady = async () => {
    if (!props.paddle?.js_enabled) {
        return false;
    }

    if (window.Paddle?.Checkout?.open) {
        return true;
    }

    if (!window.__mlkproPaddleScriptPromise) {
        window.__mlkproPaddleScriptPromise = new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = 'https://cdn.paddle.com/paddle/v2/paddle.js';
            script.async = true;
            script.onload = resolve;
            script.onerror = reject;
            document.head.appendChild(script);
        });
    }

    try {
        await window.__mlkproPaddleScriptPromise;
    } catch (error) {
        paddleUiError.value = t('settings.billing.errors.paddle_load_failed');
        return false;
    }

    if (!window.Paddle?.Initialize) {
        paddleUiError.value = t('settings.billing.errors.paddle_unavailable');
        return false;
    }

    if (props.paddle?.sandbox) {
        window.Paddle.Environment?.set?.('sandbox');
    }

    const initConfig = {};

    if (props.paddle?.retain_key) {
        initConfig.pwAuth = Number(props.paddle.retain_key);
    }

    if (props.paddle?.client_side_token) {
        initConfig.token = props.paddle.client_side_token;
    } else if (props.paddle?.seller_id) {
        initConfig.seller = Number(props.paddle.seller_id);
    }

    if (initConfig.pwAuth && props.paddle?.customer_id) {
        initConfig.pwCustomer = { id: props.paddle.customer_id };
    }

    if (!initConfig.token && !initConfig.seller) {
        paddleUiError.value = t('settings.billing.errors.paddle_missing_keys');
        return false;
    }

    const signature = JSON.stringify({ sandbox: Boolean(props.paddle?.sandbox), initConfig });
    if (window.__mlkproPaddleSignature !== signature) {
        window.Paddle.Initialize(initConfig);
        window.__mlkproPaddleSignature = signature;
    }

    return Boolean(window.Paddle?.Checkout?.open);
};

const updateAssistantAddon = async (enabled) => {
    assistantAddonError.value = '';
    if (!assistantAddonAvailable.value) {
        assistantAddonError.value = t('settings.billing.assistant_addon.not_available');
        return;
    }

    assistantAddonIsLoading.value = true;
    try {
        await axios.post(route('settings.billing.assistant-addon'), { enabled });
        router.reload();
    } catch (error) {
        assistantAddonError.value = resolveStripeError(error, 'settings.billing.assistant_addon.error');
    } finally {
        assistantAddonIsLoading.value = false;
    }
};

const startAssistantCreditCheckout = async (packs = 1) => {
    assistantCreditError.value = '';
    if (!assistantCreditAvailable.value || !assistantCreditMode.value) {
        assistantCreditError.value = t('settings.billing.assistant_addon.credit_not_available');
        return;
    }

    if (!assistantAddonEnabled.value) {
        assistantCreditError.value = t('settings.billing.assistant_addon.credit_enable_required');
        return;
    }

    assistantCreditIsLoading.value = true;
    try {
        const response = await axios.post(route('settings.billing.assistant-credits'), { packs });
        const url = response?.data?.url;
        if (!url) {
            throw new Error(t('settings.billing.assistant_addon.credit_error'));
        }
        window.location.href = url;
    } catch (error) {
        assistantCreditError.value = resolveStripeError(error, 'settings.billing.assistant_addon.credit_error');
    } finally {
        assistantCreditIsLoading.value = false;
    }
};

const featureClass = (feature) =>
    feature?.toLowerCase?.().includes('option')
        ? 'plan-card__feature plan-card__feature--optional'
        : 'plan-card__feature';

const planActionLabel = (plan) => {
    if (isCurrentPlanSelection(plan)) {
        return t('settings.billing.plan.cta_active');
    }

    if (plan?.contact_only) {
        return t('settings.billing.actions.contact_sales');
    }

    return hasSubscription.value
        ? t('settings.billing.actions.switch_plan')
        : t('settings.billing.actions.choose_plan');
};

const openRecommendedPlan = async (plan) => {
    if (!plan || isCurrentPlanSelection(plan)) {
        return;
    }

    if (plan.contact_only) {
        if (plan.cta_url) {
            window.location.href = plan.cta_url;
            return;
        }

        const supportPhone = props.billing?.support_phone;
        if (supportPhone) {
            window.location.href = `tel:${supportPhone}`;
            return;
        }
    }

    planActionError.value = '';
    planActionLoadingKey.value = plan.key;

    try {
        if (hasSubscription.value) {
            await axios.post(route('settings.billing.swap'), {
                plan_key: plan.key,
                billing_period: selectedBillingPeriod.value,
            });
            router.visit(route('settings.billing.edit', {
                checkout: 'swapped',
                plan: plan.key,
                billing_period: selectedBillingPeriod.value,
            }), {
                preserveScroll: true,
            });
            return;
        }

        if (!isStripeProvider.value) {
            planActionError.value = t('settings.billing.errors.no_active_subscription');
            return;
        }

        if (!providerReady.value) {
            planActionError.value = t('settings.billing.errors.stripe_not_configured');
            return;
        }

        const response = await axios.post(route('settings.billing.checkout'), {
            plan_key: plan.key,
            billing_period: selectedBillingPeriod.value,
        });
        const url = response?.data?.url;
        if (!url) {
            throw new Error(t('settings.billing.errors.stripe_checkout_failed'));
        }

        window.location.href = url;
    } catch (error) {
        const fallbackKey = hasSubscription.value
            ? 'settings.billing.errors.plan_change_failed'
            : 'settings.billing.errors.stripe_checkout_failed';
        planActionError.value = resolveStripeError(error, fallbackKey);
    } finally {
        planActionLoadingKey.value = null;
    }
};

const selectPlanAdvisorOption = (questionId, value) => {
    if (!questionId) {
        return;
    }

    planAdvisor[questionId] = value;
};

onMounted(() => {
    if (isPaddleProvider.value && props.paddle?.js_enabled) {
        ensurePaddleReady();
    }
});

watch(
    () => form.payment_methods,
    (value) => {
        const sanitized = sanitizePaymentMethods(value);
        if (sanitized.length === 0) {
            form.payment_methods = [initialPaymentMethods[0] || 'cash'];
            return;
        }

        if (JSON.stringify(sanitized) !== JSON.stringify(value)) {
            form.payment_methods = sanitized;
            return;
        }

        if (!sanitized.includes(form.default_payment_method)) {
            form.default_payment_method = sanitized[0];
        }
    }
);

watch(
    () => form.default_payment_method,
    (value) => {
        const normalized = normalizeId(value);
        const selected = sanitizePaymentMethods(form.payment_methods);
        if (!selected.length) {
            return;
        }

        if (!selected.includes(normalized)) {
            form.default_payment_method = selected[0];
            return;
        }

        if (normalized !== value) {
            form.default_payment_method = normalized;
        }
    }
);

watch(
    () => form.cash_allowed_contexts,
    (value) => {
        const sanitized = sanitizeCashContexts(value);
        if (JSON.stringify(sanitized) !== JSON.stringify(value)) {
            form.cash_allowed_contexts = sanitized;
        }
    }
);

watch(
    () => form.loyalty.points_per_currency_unit,
    (value) => {
        const numeric = Number(value);
        if (!Number.isFinite(numeric)) {
            form.loyalty.points_per_currency_unit = 1;
            return;
        }
        if (numeric <= 0) {
            form.loyalty.points_per_currency_unit = 0.0001;
        }
    }
);

watch(
    () => form.loyalty.minimum_spend,
    (value) => {
        const numeric = Number(value);
        if (!Number.isFinite(numeric)) {
            form.loyalty.minimum_spend = 0;
            return;
        }
        if (numeric < 0) {
            form.loyalty.minimum_spend = 0;
        }
    }
);

watch(
    () => form.loyalty.rounding_mode,
    (value) => {
        const allowed = ['floor', 'round', 'ceil'];
        if (!allowed.includes(value)) {
            form.loyalty.rounding_mode = 'floor';
        }
    }
);

watch(
    () => [props.paddle?.sandbox, props.paddle?.client_side_token, props.paddle?.seller_id, props.paddle?.retain_key, props.paddle?.customer_id],
    () => {
        if (isPaddleProvider.value && props.paddle?.js_enabled) {
            ensurePaddleReady();
        }
    }
);
</script>

<template>
    <Head :title="$t('settings.billing.meta_title')" />

    <SettingsLayout active="billing" content-class="w-[1400px] max-w-full">
        <div class="w-full space-y-4">
            <SettingsTabs
                v-model="activeTab"
                :tabs="tabs"
                :id-prefix="tabPrefix"
                :aria-label="$t('settings.billing.aria_sections')"
            />

            <div
                v-show="activeTab === 'plans'"
                :id="`${tabPrefix}-panel-plans`"
                role="tabpanel"
                :aria-labelledby="`${tabPrefix}-tab-plans`"
                class="flex flex-col bg-white border border-stone-200 shadow-sm rounded-sm overflow-hidden dark:bg-neutral-800 dark:border-neutral-700"
            >
                <div class="p-4 space-y-4">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <h2 class="text-lg font-semibold text-stone-800 dark:text-neutral-100">
                                {{ $t('settings.billing.plans.title') }}
                            </h2>
                            <p class="mt-1 text-sm text-stone-600 dark:text-neutral-400">
                                {{ $t('settings.billing.plans.subtitle') }}
                            </p>
                            <div class="mt-3 inline-flex rounded-sm border border-stone-200 bg-white p-1 dark:border-neutral-700 dark:bg-neutral-900">
                                <button
                                    type="button"
                                    class="rounded-sm px-3 py-2 text-xs font-semibold transition"
                                    :class="selectedBillingPeriod === 'monthly'
                                        ? 'bg-green-600 text-white'
                                        : 'text-stone-600 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800'"
                                    @click="selectedBillingPeriod = 'monthly'"
                                >
                                    {{ $t('settings.billing.plan.monthly') }}
                                </button>
                                <button
                                    type="button"
                                    class="rounded-sm px-3 py-2 text-xs font-semibold transition"
                                    :class="selectedBillingPeriod === 'yearly'
                                        ? 'bg-green-600 text-white'
                                        : 'text-stone-600 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800'"
                                    @click="selectedBillingPeriod = 'yearly'"
                                >
                                    {{ $t('settings.billing.plan.yearly') }}
                                </button>
                            </div>
                            <p v-if="selectedBillingPeriod === 'yearly'" class="mt-2 text-xs font-semibold text-green-700 dark:text-green-400">
                                {{ yearlyPromotionActive
                                    ? $t('settings.billing.plan.billed_yearly')
                                    : $t('settings.billing.plan.yearly_note', { percent: billing?.annual_discount_percent || 20 }) }}
                            </p>
                        </div>
                        <button
                            type="button"
                            :disabled="!availablePlanOptions.length"
                            @click="openPlanAdvisor"
                            class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm font-semibold text-stone-700 hover:bg-stone-50 disabled:opacity-60 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200 dark:hover:bg-neutral-700"
                        >
                            {{ planAdvisorTriggerLabel }}
                        </button>
                    </div>

                    <div v-if="isPaddleProvider && !paddle?.api_enabled" class="rounded-sm border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-700">
                        {{ $t('settings.billing.errors.paddle_api_missing') }}
                    </div>
                    <div v-else-if="isPaddleProvider && !paddle?.js_enabled" class="rounded-sm border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-700">
                        {{ $t('settings.billing.errors.paddle_js_missing') }}
                    </div>
                    <div v-else-if="isPaddleProvider && paddle?.error" class="rounded-sm border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                        {{ paddle.error }}
                    </div>
                    <div v-if="paddleUiError" class="rounded-sm border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                        {{ paddleUiError }}
                    </div>

                    <div v-if="checkoutStatus === 'success'"
                        class="rounded-sm border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-700">
                        <p>{{ $t('settings.billing.checkout.success_title') }}</p>
                        <p v-if="checkoutPlanName" class="text-xs text-emerald-700/80">
                            {{ $t('settings.billing.checkout.success_subtitle', { plan: checkoutPlanName }) }}
                        </p>
                    </div>
                    <div v-else-if="checkoutStatus === 'swapped'"
                        class="rounded-sm border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-700">
                        {{ $t('settings.billing.checkout.swapped', { plan: checkoutPlanName || $t('settings.billing.checkout.plan_fallback') }) }}
                    </div>
                    <div v-else-if="checkoutStatus === 'payment-method'"
                        class="rounded-sm border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-700">
                        {{ $t('settings.billing.checkout.payment_method') }}
                    </div>
                    <div v-else-if="checkoutStatus === 'cancel'"
                        class="rounded-sm border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-700">
                        {{ $t('settings.billing.checkout.cancelled') }}
                    </div>

                    <div
                        class="rounded-sm border border-stone-100 bg-stone-50 px-3 py-2 text-sm text-stone-600 dark:border-neutral-700 dark:bg-neutral-900/40 dark:text-neutral-200">
                        <p v-if="displayedPlan">
                            {{ $t('settings.billing.summary.active_plan', { plan: displayedPlan.name, status: subscriptionStatusLabel }) }}
                            <span v-if="trialStatusLabel" class="text-emerald-700 dark:text-emerald-300">
                                {{ trialStatusLabel }}
                            </span>
                        </p>
                        <p v-else>
                            {{ $t('settings.billing.summary.no_subscription') }}
                        </p>
                        <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                            Charged currency: {{ tenantCurrencyCode }}
                        </p>
                    </div>

                    <div v-if="!hasPlans"
                        class="rounded-sm border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                        {{ $t('settings.billing.errors.no_plans_configured') }}
                    </div>

                    <div v-if="displayedPlan" class="billing-plans">
                        <div class="billing-plans__layout">
                            <div v-if="displayedPlan" class="billing-plans__current">
                                <div class="plan-card" data-active="true">
                                    <div class="plan-card__top">
                                        <div>
                                            <h3 class="plan-card__name">{{ displayedPlan.name }}</h3>
                                            <p class="plan-card__meta">{{ resolveBillingPeriodLabel(currentSubscriptionBillingPeriod) }}</p>
                                        </div>
                                        <span class="plan-card__badge plan-card__badge--active">
                                            {{ $t('settings.billing.plan.badge_active') }}
                                        </span>
                                    </div>
                                    <div class="plan-card__price">
                                        <PlanPriceDisplay
                                            :pricing="displayPriceForBillingPeriod(displayedPlan, currentSubscriptionBillingPeriod)"
                                            :contact-only="displayedPlan.contact_only"
                                            :custom-label="$t('settings.billing.plan.custom_pricing')"
                                            :interval-label="resolveBillingIntervalLabel(currentSubscriptionBillingPeriod)"
                                            :price-class="displayedPlan.contact_only ? 'plan-card__amount plan-card__amount--text' : 'plan-card__amount'"
                                            original-price-class="text-sm font-medium text-stone-400 line-through dark:text-neutral-500"
                                            interval-class="plan-card__interval"
                                            badge-class="rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300"
                                        />
                                    </div>
                                    <p v-if="currentSubscriptionBillingPeriod === 'yearly' && !displayedPlan.contact_only" class="plan-card__status">
                                        {{ hasActiveSubscriptionPromotion(displayPriceForBillingPeriod(displayedPlan, currentSubscriptionBillingPeriod))
                                            ? $t('settings.billing.plan.billed_yearly')
                                            : $t('settings.billing.plan.yearly_note', { percent: billing?.annual_discount_percent || 20 }) }}
                                    </p>
                                    <p v-if="resolveTeamLimitLabel(displayedPlan)" class="plan-card__limit">
                                        {{ resolveTeamLimitLabel(displayedPlan) }}
                                    </p>
                                    <p class="plan-card__status">
                                        {{ $t('settings.billing.plan.current_plan') }}
                                    </p>
                                    <p v-if="trialStatusLabel" class="plan-card__status">
                                        {{ trialStatusLabel }}
                                    </p>
                                    <ul class="plan-card__features">
                                        <li v-for="feature in displayedPlan.features" :key="feature" :class="featureClass(feature)">
                                            {{ feature }}
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="assistant-addon">
                        <div class="assistant-addon__header">
                            <div class="space-y-1">
                                <h3 class="assistant-addon__title">{{ $t('settings.billing.assistant_addon.title') }}</h3>
                                <p class="assistant-addon__subtitle">
                                    {{ assistantAddonSubtitle }}
                                </p>
                            </div>
                            <div class="assistant-addon__actions">
                                <span v-if="assistantIncluded"
                                    class="assistant-addon__badge assistant-addon__badge--included">
                                    {{ $t('settings.billing.assistant_addon.included_badge') }}
                                </span>
                                <span v-else class="assistant-addon__badge">
                                    {{ $t('settings.billing.assistant_addon.optional_badge') }}
                                </span>
                                <button v-if="!assistantIncluded" type="button"
                                    :disabled="assistantAddonIsLoading || !assistantAddonAvailable || !hasSubscription"
                                    @click="updateAssistantAddon(!assistantAddonEnabled)"
                                    class="assistant-addon__cta">
                                    <span v-if="assistantAddonEnabled">
                                        {{ $t('settings.billing.assistant_addon.cta_disable') }}
                                    </span>
                                    <span v-else>
                                        {{ $t('settings.billing.assistant_addon.cta_enable') }}
                                    </span>
                                </button>
                                <span v-else class="assistant-addon__cta assistant-addon__cta--included">
                                    {{ $t('settings.billing.assistant_addon.cta_included') }}
                                </span>
                            </div>
                        </div>

                        <div v-if="assistantAddonError" class="assistant-addon__error">
                            {{ assistantAddonError }}
                        </div>
                        <div v-else-if="!assistantAddonAvailable && !assistantIncluded" class="assistant-addon__hint">
                            {{ $t('settings.billing.assistant_addon.not_available') }}
                        </div>
                        <div v-else-if="creditStatus === 'success'" class="assistant-addon__success">
                            {{ $t('settings.billing.assistant_addon.credit_success') }}
                        </div>
                        <div v-else-if="creditStatus === 'cancel'" class="assistant-addon__hint">
                            {{ $t('settings.billing.assistant_addon.credit_cancel') }}
                        </div>
                        <div v-if="assistantCreditError" class="assistant-addon__error">
                            {{ assistantCreditError }}
                        </div>

                        <div class="assistant-addon__usage">
                            <div class="assistant-addon__usage-item">
                                <span>{{ $t('settings.billing.assistant_addon.usage_title') }}</span>
                                <strong>{{ assistantUsage.requests || 0 }}</strong>
                                <em>{{ $t('settings.billing.assistant_addon.usage_requests') }}</em>
                            </div>
                            <div class="assistant-addon__usage-item" v-if="assistantUsage.tokens !== undefined">
                                <span>{{ $t('settings.billing.assistant_addon.usage_tokens_label') }}</span>
                                <strong>{{ assistantUsage.tokens || 0 }}</strong>
                                <em>{{ $t('settings.billing.assistant_addon.usage_tokens') }}</em>
                            </div>
                            <div class="assistant-addon__usage-item" v-if="assistantUsage.billed_units !== undefined">
                                <span>{{ $t('settings.billing.assistant_addon.usage_units_label') }}</span>
                                <strong>{{ assistantUsage.billed_units || 0 }}</strong>
                                <em>{{ $t('settings.billing.assistant_addon.usage_units') }}</em>
                            </div>
                        </div>

                        <div v-if="assistantCreditMode" class="assistant-addon__credits">
                            <div class="assistant-addon__usage-item">
                                <span>{{ $t('settings.billing.assistant_addon.credit_balance_label') }}</span>
                                <strong>{{ assistantCreditBalance }}</strong>
                                <em>{{ $t('settings.billing.assistant_addon.credit_balance_suffix') }}</em>
                            </div>
                            <div class="assistant-addon__credit-actions">
                                <button type="button"
                                    :disabled="assistantCreditIsLoading || !assistantCreditAvailable || !assistantAddonEnabled"
                                    @click="startAssistantCreditCheckout(1)"
                                    class="assistant-addon__cta">
                                    {{ $t('settings.billing.assistant_addon.credit_cta') }}
                                </button>
                                <span v-if="assistantCreditPackSize" class="assistant-addon__credit-pack">
                                    {{ $t('settings.billing.assistant_addon.credit_pack', { count: assistantCreditPackSize }) }}
                                </span>
                            </div>
                        </div>
                    </div>

                    <Modal :show="planAdvisorDialogOpen" max-width="4xl" @close="closePlanAdvisor">
                        <div class="plan-advisor-modal">
                            <div class="plan-advisor-modal__header">
                                <div>
                                    <p class="plan-advisor-modal__eyebrow">
                                        {{ $t('settings.billing.plans.advisor.title') }}
                                    </p>
                                    <h3 class="plan-advisor-modal__title">
                                        {{ $t('settings.billing.plans.advisor.dialog_title') }}
                                    </h3>
                                    <p class="plan-advisor-modal__subtitle">
                                        {{ $t('settings.billing.plans.advisor.subtitle') }}
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    class="plan-advisor-modal__close"
                                    @click="closePlanAdvisor"
                                >
                                    {{ $t('settings.billing.plans.advisor.close') }}
                                </button>
                            </div>

                            <div class="plan-advisor-stepper">
                                <button
                                    v-for="(step, index) in planAdvisorSteps"
                                    :key="step.id"
                                    type="button"
                                    class="plan-advisor-stepper__item"
                                    :class="{
                                        'is-active': planAdvisorStep === index,
                                        'is-complete': planAdvisorStep > index,
                                    }"
                                    @click="goToPlanAdvisorStep(index)"
                                >
                                    <span class="plan-advisor-stepper__index">
                                        {{ index + 1 }}
                                    </span>
                                    <span class="plan-advisor-stepper__label">
                                        {{ step.label }}
                                    </span>
                                </button>
                            </div>

                            <div v-if="!planAdvisorOnResults && planAdvisorActiveQuestion" class="plan-advisor">
                                <div class="plan-advisor__question-card">
                                    <p class="plan-advisor__question-label">
                                        {{ planAdvisorActiveQuestion.label }}
                                    </p>
                                    <div class="plan-advisor__options plan-advisor__options--stacked">
                                        <button
                                            v-for="option in planAdvisorActiveQuestion.options"
                                            :key="option.value"
                                            type="button"
                                            class="plan-advisor__option"
                                            :class="{ 'is-selected': planAdvisor[planAdvisorActiveQuestion.id] === option.value }"
                                            @click="selectPlanAdvisorOption(planAdvisorActiveQuestion.id, option.value)"
                                        >
                                            <strong>{{ option.label }}</strong>
                                            <span>{{ option.description }}</span>
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <div v-else class="plan-advisor">
                                <div v-if="planActionError" class="plan-advisor__error">
                                    {{ planActionError }}
                                </div>

                                <div class="plan-advisor__results">
                                    <div
                                        v-for="(recommendation, index) in recommendedPlans"
                                        :key="recommendation.plan.key"
                                        class="plan-card"
                                        :data-active="recommendation.isCurrent"
                                        :data-recommended="index === 0"
                                    >
                                        <div class="plan-card__top">
                                            <div>
                                                <h3 class="plan-card__name">{{ recommendation.plan.name }}</h3>
                                                <p class="plan-card__meta">{{ resolveBillingPeriodLabel(selectedBillingPeriod) }}</p>
                                            </div>
                                            <div class="plan-card__badges">
                                                <span
                                                    class="plan-card__badge"
                                                    :class="recommendation.isCurrent ? 'plan-card__badge--active' : (index === 0 ? 'plan-card__badge--match' : '')"
                                                >
                                                    {{ recommendationBadgeLabel(recommendation, index) }}
                                                </span>
                                            </div>
                                        </div>
                                        <div class="plan-card__price">
                                            <PlanPriceDisplay
                                                :pricing="displayPriceForBillingPeriod(recommendation.plan, selectedBillingPeriod)"
                                                :contact-only="recommendation.plan.contact_only"
                                                :custom-label="$t('settings.billing.plan.custom_pricing')"
                                                :interval-label="resolveBillingIntervalLabel(selectedBillingPeriod)"
                                                :price-class="recommendation.plan.contact_only ? 'plan-card__amount plan-card__amount--text' : 'plan-card__amount'"
                                                original-price-class="text-sm font-medium text-stone-400 line-through dark:text-neutral-500"
                                                interval-class="plan-card__interval"
                                                badge-class="rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300"
                                            />
                                        </div>
                                        <p v-if="selectedBillingPeriod === 'yearly' && !recommendation.plan.contact_only" class="plan-card__status">
                                            {{ hasActiveSubscriptionPromotion(displayPriceForBillingPeriod(recommendation.plan, selectedBillingPeriod))
                                                ? $t('settings.billing.plan.billed_yearly')
                                                : $t('settings.billing.plan.yearly_note', { percent: billing?.annual_discount_percent || 20 }) }}
                                        </p>
                                        <p v-if="resolveTeamLimitLabel(recommendation.plan)" class="plan-card__limit">
                                            {{ resolveTeamLimitLabel(recommendation.plan) }}
                                        </p>
                                        <p v-if="recommendation.isCurrent" class="plan-card__status">
                                            {{ $t('settings.billing.plans.advisor.current_match') }}
                                        </p>
                                        <ul v-if="recommendation.reasons.length" class="plan-card__reasons">
                                            <li v-for="reason in recommendation.reasons" :key="reason">
                                                {{ reason }}
                                            </li>
                                        </ul>
                                        <ul class="plan-card__features">
                                            <li
                                                v-for="feature in previewPlanFeatures(recommendation.plan)"
                                                :key="feature"
                                                :class="featureClass(feature)"
                                            >
                                                {{ feature }}
                                            </li>
                                        </ul>
                                        <button
                                            type="button"
                                            class="plan-card__cta"
                                            :class="{ 'plan-card__cta--contact': recommendation.plan.contact_only }"
                                            :disabled="planActionLoadingKey !== null || isCurrentPlanSelection(recommendation.plan)"
                                            @click="openRecommendedPlan(recommendation.plan)"
                                        >
                                            {{
                                                planActionLoadingKey === recommendation.plan.key
                                                    ? $t('settings.billing.actions.processing')
                                                    : planActionLabel(recommendation.plan)
                                            }}
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <div class="plan-advisor-modal__footer">
                                <button
                                    type="button"
                                    class="plan-advisor-modal__footer-btn"
                                    :disabled="planAdvisorStep === 0"
                                    @click="previousPlanAdvisorStep"
                                >
                                    {{ $t('settings.billing.plans.advisor.back') }}
                                </button>
                                <div class="plan-advisor-modal__footer-actions">
                                    <button
                                        type="button"
                                        class="plan-advisor-modal__footer-btn"
                                        @click="closePlanAdvisor"
                                    >
                                        {{ $t('settings.billing.plans.advisor.close') }}
                                    </button>
                                    <button
                                        v-if="!planAdvisorOnResults"
                                        type="button"
                                        class="plan-advisor-modal__footer-btn plan-advisor-modal__footer-btn--primary"
                                        :disabled="!planAdvisorCanMoveNext"
                                        @click="nextPlanAdvisorStep"
                                    >
                                        {{
                                            planAdvisorStep === planAdvisorQuestions.length - 1
                                                ? $t('settings.billing.plans.advisor.show_results')
                                                : $t('settings.billing.plans.advisor.next')
                                        }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </Modal>
                </div>
            </div>

            <div
                v-show="activeTab === 'payment'"
                :id="`${tabPrefix}-panel-payment`"
                role="tabpanel"
                :aria-labelledby="`${tabPrefix}-tab-payment`"
                class="flex flex-col bg-white border border-stone-200 shadow-sm rounded-sm overflow-hidden dark:bg-neutral-800 dark:border-neutral-700"
            >
                <div class="p-4 space-y-4">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <h2 class="text-lg font-semibold text-stone-800 dark:text-neutral-100">
                                {{ $t('settings.billing.payment.title') }}
                            </h2>
                            <p class="mt-1 text-sm text-stone-600 dark:text-neutral-400">
                                {{ $t('settings.billing.payment.subtitle') }}
                            </p>
                        </div>
                        <button v-if="hasSubscription" type="button" @click="startPaymentMethodUpdate"
                            :disabled="paymentMethodIsLoading"
                            class="py-2 px-3 text-sm font-medium rounded-sm border border-stone-200 text-stone-700 hover:bg-stone-50 disabled:opacity-60 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-700">
                            {{ $t('settings.billing.payment.update_card') }}
                        </button>
                    </div>

                    <div v-if="isPaddleProvider && !paddle?.api_enabled" class="rounded-sm border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-700">
                        {{ $t('settings.billing.errors.paddle_api_missing') }}
                    </div>
                    <div v-else-if="isPaddleProvider && !paddle?.js_enabled" class="rounded-sm border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-700">
                        {{ $t('settings.billing.errors.paddle_js_missing') }}
                    </div>
                    <div v-else-if="isPaddleProvider && paddle?.error" class="rounded-sm border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                        {{ paddle.error }}
                    </div>
                    <div v-if="paddleUiError" class="rounded-sm border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                        {{ paddleUiError }}
                    </div>
                    <div v-if="connectError" class="rounded-sm border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                        {{ connectError }}
                    </div>

                    <div v-if="checkoutStatus === 'payment-method'"
                        class="rounded-sm border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-700">
                        {{ $t('settings.billing.checkout.payment_method') }}
                    </div>
                    <div v-if="connectStatus === 'success'"
                        class="rounded-sm border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-700">
                        {{ $t('settings.billing.connect.success') }}
                    </div>

                    <div
                        class="rounded-sm border border-stone-100 bg-stone-50 px-3 py-2 text-sm text-stone-600 dark:border-neutral-700 dark:bg-neutral-900/40 dark:text-neutral-200">
                        <p v-if="displayedPlan">
                            {{ $t('settings.billing.payment.summary_active', { plan: displayedPlan.name, status: subscriptionStatusLabel }) }}
                        </p>
                        <p v-else>
                            {{ $t('settings.billing.payment.summary_none') }}
                        </p>
                    </div>

                    <div
                        v-if="loyaltyFeatureEnabled"
                        class="rounded-sm border border-stone-200 bg-white px-4 py-4 dark:border-neutral-700 dark:bg-neutral-900"
                    >
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h3 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                    {{ $t('settings.billing.payment_methods.title') }}
                                </h3>
                                <p class="text-xs text-stone-500 dark:text-neutral-400">
                                    {{ $t('settings.billing.payment_methods.subtitle') }}
                                </p>
                            </div>
                            <button
                                type="button"
                                @click="submit"
                                :disabled="form.processing"
                                class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-semibold text-stone-700 hover:bg-stone-50 disabled:opacity-60 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200 dark:hover:bg-neutral-700"
                            >
                                {{ $t('settings.billing.payment_methods.save') }}
                            </button>
                        </div>

                        <div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-2">
                            <label
                                v-for="method in availablePaymentMethodOptions"
                                :key="method.id"
                                class="flex items-start gap-3 rounded-sm border border-stone-200 bg-stone-50 px-3 py-3 text-sm dark:border-neutral-700 dark:bg-neutral-800/50"
                            >
                                <input
                                    v-model="form.payment_methods"
                                    type="checkbox"
                                    :value="method.id"
                                    :disabled="methodIsLocked(method.id)"
                                    class="mt-0.5 h-4 w-4 rounded border-stone-300 text-emerald-600 focus:ring-emerald-500 dark:border-neutral-600 dark:bg-neutral-800"
                                />
                                <span class="space-y-1">
                                    <span class="block font-medium text-stone-800 dark:text-neutral-100">
                                        {{ paymentMethodLabel(method) }}
                                    </span>
                                    <span class="block text-xs text-stone-500 dark:text-neutral-400">
                                        {{ paymentMethodDescription(method) }}
                                    </span>
                                </span>
                            </label>
                        </div>
                        <p
                            v-for="error in paymentMethodErrors"
                            :key="`payment-method-error-${error}`"
                            class="mt-2 text-xs text-rose-600"
                        >
                            {{ error }}
                        </p>

                        <div class="mt-4 space-y-1 text-xs text-stone-600 dark:text-neutral-300">
                            <label for="billing-default-payment-method" class="block">
                                {{ $t('settings.billing.payment_methods.default_label') }}
                            </label>
                            <select
                                id="billing-default-payment-method"
                                v-model="form.default_payment_method"
                                class="w-full rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm text-stone-700 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200"
                            >
                                <option
                                    v-for="method in selectedPaymentMethodOptions"
                                    :key="`default-${method.id}`"
                                    :value="method.id"
                                >
                                    {{ paymentMethodLabel(method) }}
                                </option>
                            </select>
                            <p class="text-[11px] text-stone-500 dark:text-neutral-400">
                                {{ selectedPaymentMethodOptions.length <= 1
                                    ? $t('settings.billing.payment_methods.default_hint_single')
                                    : $t('settings.billing.payment_methods.default_hint_multi') }}
                            </p>
                            <p v-if="form.errors.default_payment_method" class="text-xs text-rose-600">
                                {{ form.errors.default_payment_method }}
                            </p>
                        </div>

                        <div v-if="cashMethodEnabled" class="mt-4">
                            <p class="text-xs font-semibold text-stone-700 dark:text-neutral-200">
                                {{ $t('settings.billing.payment_methods.cash_context_title') }}
                            </p>
                            <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                {{ $t('settings.billing.payment_methods.cash_context_subtitle') }}
                            </p>

                            <div class="mt-3 grid grid-cols-1 gap-2 md:grid-cols-2">
                                <label
                                    v-for="contextOption in CASH_CONTEXT_OPTIONS"
                                    :key="`cash-context-${contextOption.id}`"
                                    class="flex items-center gap-2 rounded-sm border border-stone-200 bg-stone-50 px-3 py-2 text-xs text-stone-700 dark:border-neutral-700 dark:bg-neutral-800/50 dark:text-neutral-200"
                                >
                                    <input
                                        v-model="form.cash_allowed_contexts"
                                        type="checkbox"
                                        :value="contextOption.id"
                                        class="h-4 w-4 rounded border-stone-300 text-emerald-600 focus:ring-emerald-500 dark:border-neutral-600 dark:bg-neutral-800"
                                    />
                                    <span>{{ cashContextLabel(contextOption) }}</span>
                                </label>
                            </div>
                            <p
                                v-for="error in cashContextErrors"
                                :key="`cash-context-error-${error}`"
                                class="mt-2 text-xs text-rose-600"
                            >
                                {{ error }}
                            </p>
                        </div>
                    </div>

                    <div class="rounded-sm border border-stone-200 bg-white px-4 py-4 dark:border-neutral-700 dark:bg-neutral-900">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h3 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                    {{ $t('settings.billing.tips.title') }}
                                </h3>
                                <p class="text-xs text-stone-500 dark:text-neutral-400">
                                    {{ $t('settings.billing.tips.subtitle') }}
                                </p>
                            </div>
                            <button
                                type="button"
                                @click="submit"
                                :disabled="form.processing"
                                class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-semibold text-stone-700 hover:bg-stone-50 disabled:opacity-60 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200 dark:hover:bg-neutral-700"
                            >
                                {{ $t('settings.billing.tips.save') }}
                            </button>
                        </div>

                        <div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-2">
                            <label class="space-y-1 text-xs text-stone-600 dark:text-neutral-300">
                                <span>{{ $t('settings.billing.tips.max_percent') }}</span>
                                <input
                                    v-model.number="form.tips.max_percent"
                                    type="number"
                                    min="1"
                                    max="100"
                                    step="0.01"
                                    class="w-full rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm text-stone-700 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200"
                                />
                                <p v-if="form.errors['tips.max_percent']" class="text-xs text-rose-600">{{ form.errors['tips.max_percent'] }}</p>
                            </label>

                            <label class="space-y-1 text-xs text-stone-600 dark:text-neutral-300">
                                <span>{{ $t('settings.billing.tips.max_fixed_amount') }}</span>
                                <input
                                    v-model.number="form.tips.max_fixed_amount"
                                    type="number"
                                    min="1"
                                    step="0.01"
                                    class="w-full rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm text-stone-700 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200"
                                />
                                <p v-if="form.errors['tips.max_fixed_amount']" class="text-xs text-rose-600">{{ form.errors['tips.max_fixed_amount'] }}</p>
                            </label>

                            <label class="space-y-1 text-xs text-stone-600 dark:text-neutral-300">
                                <span>{{ $t('settings.billing.tips.default_percent') }}</span>
                                <input
                                    v-model.number="form.tips.default_percent"
                                    type="number"
                                    min="0"
                                    max="100"
                                    step="0.01"
                                    class="w-full rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm text-stone-700 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200"
                                />
                                <p v-if="form.errors['tips.default_percent']" class="text-xs text-rose-600">{{ form.errors['tips.default_percent'] }}</p>
                            </label>

                            <label class="space-y-1 text-xs text-stone-600 dark:text-neutral-300">
                                <span>{{ $t('settings.billing.tips.allocation_strategy') }}</span>
                                <select
                                    v-model="form.tips.allocation_strategy"
                                    class="w-full rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm text-stone-700 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200"
                                >
                                    <option value="primary">{{ $t('settings.billing.tips.allocation_primary') }}</option>
                                    <option value="split">{{ $t('settings.billing.tips.allocation_split') }}</option>
                                </select>
                                <p v-if="form.errors['tips.allocation_strategy']" class="text-xs text-rose-600">{{ form.errors['tips.allocation_strategy'] }}</p>
                            </label>

                            <label class="space-y-1 text-xs text-stone-600 dark:text-neutral-300">
                                <span>{{ $t('settings.billing.tips.partial_refund_rule') }}</span>
                                <select
                                    v-model="form.tips.partial_refund_rule"
                                    class="w-full rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm text-stone-700 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200"
                                >
                                    <option value="prorata">{{ $t('settings.billing.tips.refund_prorata') }}</option>
                                    <option value="manual">{{ $t('settings.billing.tips.refund_manual') }}</option>
                                </select>
                                <p v-if="form.errors['tips.partial_refund_rule']" class="text-xs text-rose-600">{{ form.errors['tips.partial_refund_rule'] }}</p>
                            </label>
                        </div>
                    </div>

                    <div class="rounded-sm border border-stone-200 bg-white px-4 py-4 dark:border-neutral-700 dark:bg-neutral-900">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h3 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                    {{ $t('settings.billing.loyalty.title') }}
                                </h3>
                                <p class="text-xs text-stone-500 dark:text-neutral-400">
                                    {{ $t('settings.billing.loyalty.subtitle') }}
                                </p>
                            </div>
                            <button
                                type="button"
                                @click="submit"
                                :disabled="form.processing"
                                class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-semibold text-stone-700 hover:bg-stone-50 disabled:opacity-60 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200 dark:hover:bg-neutral-700"
                            >
                                {{ $t('settings.billing.loyalty.save') }}
                            </button>
                        </div>

                        <div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-2">
                            <label class="flex items-center gap-2 text-xs text-stone-600 dark:text-neutral-300">
                                <input
                                    v-model="form.loyalty.is_enabled"
                                    type="checkbox"
                                    class="h-4 w-4 rounded border-stone-300 text-emerald-600 focus:ring-emerald-500 dark:border-neutral-600 dark:bg-neutral-800"
                                />
                                <span>{{ $t('settings.billing.loyalty.enabled') }}</span>
                            </label>

                            <label class="space-y-1 text-xs text-stone-600 dark:text-neutral-300">
                                <span>{{ $t('settings.billing.loyalty.points_per_currency_unit') }}</span>
                                <input
                                    v-model.number="form.loyalty.points_per_currency_unit"
                                    type="number"
                                    min="0.0001"
                                    step="0.0001"
                                    class="w-full rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm text-stone-700 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200"
                                />
                                <p v-if="form.errors['loyalty.points_per_currency_unit']" class="text-xs text-rose-600">{{ form.errors['loyalty.points_per_currency_unit'] }}</p>
                            </label>

                            <label class="space-y-1 text-xs text-stone-600 dark:text-neutral-300">
                                <span>{{ $t('settings.billing.loyalty.minimum_spend') }}</span>
                                <input
                                    v-model.number="form.loyalty.minimum_spend"
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    class="w-full rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm text-stone-700 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200"
                                />
                                <p v-if="form.errors['loyalty.minimum_spend']" class="text-xs text-rose-600">{{ form.errors['loyalty.minimum_spend'] }}</p>
                            </label>

                            <label class="space-y-1 text-xs text-stone-600 dark:text-neutral-300">
                                <span>{{ $t('settings.billing.loyalty.rounding_mode') }}</span>
                                <select
                                    v-model="form.loyalty.rounding_mode"
                                    class="w-full rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm text-stone-700 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200"
                                >
                                    <option value="floor">{{ $t('settings.billing.loyalty.rounding_modes.floor') }}</option>
                                    <option value="round">{{ $t('settings.billing.loyalty.rounding_modes.round') }}</option>
                                    <option value="ceil">{{ $t('settings.billing.loyalty.rounding_modes.ceil') }}</option>
                                </select>
                                <p v-if="form.errors['loyalty.rounding_mode']" class="text-xs text-rose-600">{{ form.errors['loyalty.rounding_mode'] }}</p>
                            </label>

                            <label class="space-y-1 text-xs text-stone-600 dark:text-neutral-300 md:col-span-2">
                                <span>{{ $t('settings.billing.loyalty.points_label') }}</span>
                                <input
                                    v-model.trim="form.loyalty.points_label"
                                    type="text"
                                    maxlength="40"
                                    class="w-full rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm text-stone-700 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200"
                                />
                                <p v-if="form.errors['loyalty.points_label']" class="text-xs text-rose-600">{{ form.errors['loyalty.points_label'] }}</p>
                            </label>
                        </div>
                    </div>

                    <div v-if="stripeConnectEnabled"
                        class="rounded-sm border border-stone-200 bg-white px-4 py-3 text-sm text-stone-700 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200">
                        <div class="flex flex-wrap items-start justify-between gap-4">
                            <div class="space-y-2">
                                <div>
                                    <h3 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                        {{ $t('settings.billing.connect.title') }}
                                    </h3>
                                    <p class="text-xs text-stone-500 dark:text-neutral-400">
                                        {{ $t('settings.billing.connect.subtitle') }}
                                    </p>
                                </div>
                                <div class="text-xs text-stone-500 dark:text-neutral-400">
                                    {{ $t('settings.billing.connect.fee_note', { fee: stripeConnect?.fee_percent || 0 }) }}
                                </div>
                                <div class="text-xs font-semibold text-stone-700 dark:text-neutral-200">
                                    {{ stripeConnectStatusLabel }}
                                </div>
                                <div v-if="stripeConnectActionHint" class="text-xs text-emerald-700 dark:text-emerald-300">
                                    {{ stripeConnectActionHint }}
                                </div>
                            </div>
                            <button v-if="stripeConnectNeedsAction" type="button" @click="startStripeConnect"
                                :disabled="connectIsLoading"
                                class="py-2 px-3 text-xs font-semibold rounded-sm border border-stone-200 text-stone-700 hover:bg-stone-50 disabled:opacity-60 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-700">
                                {{ stripeConnectActionLabel }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </SettingsLayout>
</template>

<style scoped>
.billing-plans {
    --plan-bg: #ffffff;
    --plan-card: #ffffff;
    --plan-border: rgba(15, 23, 42, 0.08);
    --plan-border-hover: rgba(15, 23, 42, 0.18);
    --plan-text: #0f172a;
    --plan-muted: rgba(15, 23, 42, 0.6);
    --plan-feature-text: rgba(15, 23, 42, 0.78);
    --plan-feature-muted: rgba(15, 23, 42, 0.45);
    --plan-accent: rgba(16, 185, 129, 0.85);
    --plan-status: rgba(15, 118, 110, 0.85);
    --plan-shadow: 0 1px 2px rgba(15, 23, 42, 0.08);
    --plan-shadow-hover: 0 2px 6px rgba(15, 23, 42, 0.12);
    --plan-active-shadow: 0 0 0 1px rgba(16, 185, 129, 0.35);
    --plan-badge-bg: rgba(15, 23, 42, 0.06);
    --plan-badge-border: rgba(15, 23, 42, 0.14);
    --plan-badge-text: #0f172a;
    --plan-badge-active-bg: rgba(16, 185, 129, 0.16);
    --plan-badge-active-border: rgba(16, 185, 129, 0.5);
    --plan-badge-active-text: #0f766e;
    --plan-dot: rgba(148, 163, 184, 0.9);
    --plan-dot-ring: rgba(148, 163, 184, 0.18);
    --plan-cta-bg: #ffffff;
    --plan-cta-border: rgba(15, 23, 42, 0.14);
    --plan-cta-text: #0f172a;
    --plan-cta-hover-bg: rgba(15, 23, 42, 0.04);
    --plan-cta-hover-border: rgba(15, 23, 42, 0.3);
    --plan-cta-disabled: rgba(15, 23, 42, 0.35);
    --plan-cta-disabled-bg: rgba(15, 23, 42, 0.03);
    --plan-cta-disabled-border: rgba(15, 23, 42, 0.1);
    margin-top: 16px;
    padding: 20px;
    border-radius: 2px;
    border: 1px solid var(--plan-border);
    background: var(--plan-bg);
    font-family: inherit;
}

:global(.dark) .billing-plans {
    --plan-bg: #0b0f14;
    --plan-card: #0f1116;
    --plan-border: rgba(255, 255, 255, 0.08);
    --plan-border-hover: rgba(255, 255, 255, 0.18);
    --plan-text: #e2e8f0;
    --plan-muted: rgba(226, 232, 240, 0.7);
    --plan-feature-text: rgba(226, 232, 240, 0.85);
    --plan-feature-muted: rgba(226, 232, 240, 0.55);
    --plan-accent: rgba(16, 185, 129, 0.8);
    --plan-status: rgba(167, 243, 208, 0.85);
    --plan-shadow: 0 1px 2px rgba(0, 0, 0, 0.5);
    --plan-shadow-hover: 0 2px 6px rgba(0, 0, 0, 0.6);
    --plan-active-shadow: 0 0 0 1px rgba(16, 185, 129, 0.45);
    --plan-badge-bg: rgba(15, 23, 42, 0.5);
    --plan-badge-border: rgba(255, 255, 255, 0.16);
    --plan-badge-text: #e2e8f0;
    --plan-badge-active-bg: rgba(16, 185, 129, 0.18);
    --plan-badge-active-border: rgba(16, 185, 129, 0.6);
    --plan-badge-active-text: #d1fae5;
    --plan-dot: rgba(148, 163, 184, 0.9);
    --plan-dot-ring: rgba(148, 163, 184, 0.12);
    --plan-cta-bg: rgba(15, 23, 42, 0.8);
    --plan-cta-border: rgba(255, 255, 255, 0.18);
    --plan-cta-text: #f8fafc;
    --plan-cta-hover-bg: rgba(15, 23, 42, 0.95);
    --plan-cta-hover-border: rgba(255, 255, 255, 0.32);
    --plan-cta-disabled: rgba(226, 232, 240, 0.6);
    --plan-cta-disabled-bg: rgba(15, 23, 42, 0.5);
    --plan-cta-disabled-border: rgba(255, 255, 255, 0.08);
    background: var(--plan-bg);
}

:global(.dark) .plan-card__badge--match {
    border-color: rgba(56, 189, 248, 0.35);
    color: #bae6fd;
    background: rgba(14, 165, 233, 0.16);
}

:global(.dark) .plan-card__reasons {
    border-color: rgba(255, 255, 255, 0.08);
    background: rgba(15, 23, 42, 0.55);
}

:global(.dark) .plan-advisor__option.is-selected {
    background: rgba(16, 185, 129, 0.12);
}

:global(.dark) .plan-advisor__error {
    border-color: rgba(248, 113, 113, 0.18);
    background: rgba(69, 10, 10, 0.45);
    color: #fecaca;
}

:global(.dark) .plan-advisor-modal__eyebrow {
    color: #6ee7b7;
}

:global(.dark) .plan-advisor-modal {
    --plan-bg: #0b0f14;
    --plan-card: #0f1116;
    --plan-border: rgba(255, 255, 255, 0.08);
    --plan-border-hover: rgba(255, 255, 255, 0.18);
    --plan-text: #e2e8f0;
    --plan-muted: rgba(226, 232, 240, 0.7);
    --plan-feature-text: rgba(226, 232, 240, 0.85);
    --plan-feature-muted: rgba(226, 232, 240, 0.55);
    --plan-accent: rgba(16, 185, 129, 0.8);
    --plan-status: rgba(167, 243, 208, 0.85);
    --plan-shadow: 0 1px 2px rgba(0, 0, 0, 0.5);
    --plan-shadow-hover: 0 2px 6px rgba(0, 0, 0, 0.6);
    --plan-active-shadow: 0 0 0 1px rgba(16, 185, 129, 0.45);
    --plan-badge-bg: rgba(15, 23, 42, 0.5);
    --plan-badge-border: rgba(255, 255, 255, 0.16);
    --plan-badge-text: #e2e8f0;
    --plan-badge-active-bg: rgba(16, 185, 129, 0.18);
    --plan-badge-active-border: rgba(16, 185, 129, 0.6);
    --plan-badge-active-text: #d1fae5;
    --plan-dot: rgba(148, 163, 184, 0.9);
    --plan-dot-ring: rgba(148, 163, 184, 0.12);
    --plan-cta-bg: rgba(15, 23, 42, 0.8);
    --plan-cta-border: rgba(255, 255, 255, 0.18);
    --plan-cta-text: #f8fafc;
    --plan-cta-hover-bg: rgba(15, 23, 42, 0.95);
    --plan-cta-hover-border: rgba(255, 255, 255, 0.32);
    --plan-cta-disabled: rgba(226, 232, 240, 0.6);
    --plan-cta-disabled-bg: rgba(15, 23, 42, 0.5);
    --plan-cta-disabled-border: rgba(255, 255, 255, 0.08);
}

:global(.dark) .plan-advisor-modal__title {
    color: #f8fafc;
}

:global(.dark) .plan-advisor-modal__subtitle {
    color: rgba(226, 232, 240, 0.72);
}

:global(.dark) .plan-advisor-modal__close,
:global(.dark) .plan-advisor-modal__footer-btn,
:global(.dark) .plan-advisor-stepper__item,
:global(.dark) .plan-advisor__question-card {
    border-color: rgba(255, 255, 255, 0.08);
    background: rgba(15, 23, 42, 0.72);
    color: #f8fafc;
}

:global(.dark) .plan-advisor-stepper__item.is-active {
    border-color: rgba(16, 185, 129, 0.48);
    background: rgba(16, 185, 129, 0.14);
}

:global(.dark) .plan-advisor-stepper__item.is-complete {
    border-color: rgba(56, 189, 248, 0.22);
    background: rgba(14, 165, 233, 0.12);
}

:global(.dark) .plan-advisor-stepper__index {
    background: rgba(255, 255, 255, 0.1);
    color: #f8fafc;
}

:global(.dark) .plan-advisor-modal__footer-btn--primary {
    border-color: rgba(16, 185, 129, 0.42);
    background: rgba(16, 185, 129, 0.18);
    color: #d1fae5;
}

:global(.dark) .plan-advisor__results {
    border-color: rgba(255, 255, 255, 0.08);
    background:
        linear-gradient(180deg, rgba(14, 165, 233, 0.08), rgba(14, 165, 233, 0) 24%),
        rgba(15, 23, 42, 0.72);
}

.billing-plans__grid {
    display: grid;
    gap: 16px;
}

.billing-plans__layout {
    display: grid;
    gap: 20px;
}

.billing-plans__current {
    display: grid;
    gap: 16px;
    max-width: 32rem;
}

@media (min-width: 768px) {
    .billing-plans__grid {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }
}

@media (min-width: 1100px) {
    .billing-plans__layout {
        grid-template-columns: minmax(18rem, 30rem) minmax(0, 1fr);
        align-items: start;
    }
}

.plan-card {
    position: relative;
    display: flex;
    flex-direction: column;
    gap: 14px;
    padding: 20px;
    min-height: 100%;
    border-radius: 6px;
    border: 1px solid var(--plan-border);
    background: var(--plan-card);
    color: var(--plan-text);
    box-shadow: var(--plan-shadow);
    transition: transform 150ms ease, box-shadow 150ms ease, border-color 150ms ease;
}

.plan-card:hover {
    transform: translateY(-2px);
    border-color: var(--plan-border-hover);
    box-shadow: var(--plan-shadow-hover);
}

.plan-card[data-active="true"] {
    border-color: var(--plan-accent);
    box-shadow: var(--plan-active-shadow);
}

.plan-card__top {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
}

.plan-card__name {
    font-size: 1rem;
    font-weight: 600;
    color: var(--plan-text);
}

.plan-card__meta {
    margin-top: 2px;
    font-size: 0.75rem;
    color: var(--plan-muted);
    letter-spacing: 0.04em;
    text-transform: uppercase;
}

.plan-card__badge {
    border-radius: 2px;
    border: 1px solid var(--plan-badge-border);
    padding: 4px 10px;
    font-size: 0.65rem;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: var(--plan-badge-text);
    background: var(--plan-badge-bg);
}

.plan-card__badge--active {
    border-color: var(--plan-badge-active-border);
    color: var(--plan-badge-active-text);
    background: var(--plan-badge-active-bg);
}

.plan-card__badge--match {
    border-color: rgba(14, 165, 233, 0.32);
    color: #075985;
    background: rgba(14, 165, 233, 0.12);
}

.plan-card__badges {
    display: flex;
    justify-content: flex-end;
}

.plan-card__price {
    display: flex;
    align-items: baseline;
    gap: 6px;
}

.plan-card__amount {
    font-size: 2.25rem;
    font-weight: 600;
    color: var(--plan-text);
}

.plan-card__amount--text {
    font-size: 1.35rem;
    line-height: 1.2;
}

.plan-card__interval {
    font-size: 0.9rem;
    color: var(--plan-muted);
}

.plan-card__limit {
    margin-top: 6px;
    font-size: 0.78rem;
    color: var(--plan-muted);
}

.plan-card__status {
    font-size: 0.78rem;
    color: var(--plan-status);
}

.plan-card__reasons {
    display: grid;
    gap: 8px;
    margin: 0;
    padding: 12px;
    list-style: none;
    border: 1px solid rgba(15, 23, 42, 0.08);
    background: rgba(248, 250, 252, 0.8);
    border-radius: 4px;
    font-size: 0.8rem;
    color: var(--plan-feature-text);
}

.plan-card__reasons li {
    position: relative;
    padding-left: 16px;
    line-height: 1.35;
}

.plan-card__reasons li::before {
    content: "";
    position: absolute;
    left: 0;
    top: 0.35rem;
    width: 6px;
    height: 6px;
    border-radius: 999px;
    background: var(--plan-accent);
}

.plan-card__features {
    display: grid;
    gap: 10px;
    margin: 0;
    padding: 0;
    list-style: none;
    color: var(--plan-feature-text);
    font-size: 0.85rem;
    align-content: start;
    flex: 1 1 auto;
}

.plan-card__feature {
    position: relative;
    padding-left: 18px;
    line-height: 1.35;
    font-weight: 500;
}

.plan-card__feature::before {
    content: "";
    position: absolute;
    left: 0;
    top: 0.35rem;
    width: 6px;
    height: 6px;
    border-radius: 2px;
    background: var(--plan-dot);
    box-shadow: 0 0 0 3px var(--plan-dot-ring);
}

.plan-card__feature--optional {
    color: var(--plan-feature-muted);
    font-weight: 500;
}

.plan-card__cta {
    width: 100%;
    margin-top: auto;
    border-radius: 2px;
    border: 1px solid var(--plan-cta-border);
    background: var(--plan-cta-bg);
    color: var(--plan-cta-text);
    padding: 10px 14px;
    font-size: 0.85rem;
    font-weight: 600;
    min-height: 44px;
    transition: transform 150ms ease, border-color 150ms ease, background 150ms ease;
}

.plan-card__cta:hover:not(:disabled) {
    transform: translateY(-1px);
    border-color: var(--plan-cta-hover-border);
    background: var(--plan-cta-hover-bg);
}

.plan-card__cta--contact {
    background: transparent;
    border-color: var(--plan-accent);
    color: var(--plan-accent);
    text-align: center;
}

.plan-card__cta--contact:hover {
    background: rgba(16, 185, 129, 0.08);
    box-shadow: none;
}

.plan-card__contact {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.plan-card__phone {
    font-size: 0.78rem;
    text-align: center;
    color: var(--plan-muted);
}

.plan-card__phone a {
    color: var(--plan-muted);
    text-decoration: none;
}

.plan-card__phone a:hover {
    color: var(--plan-text);
}

.plan-card__cta:disabled,
.plan-card__cta.is-active {
    cursor: not-allowed;
    color: var(--plan-cta-disabled);
    background: var(--plan-cta-disabled-bg);
    border-color: var(--plan-cta-disabled-border);
}

.plan-advisor {
    display: grid;
    gap: 18px;
}

.plan-advisor-modal {
    --plan-bg: #ffffff;
    --plan-card: #ffffff;
    --plan-border: rgba(15, 23, 42, 0.08);
    --plan-border-hover: rgba(15, 23, 42, 0.18);
    --plan-text: #0f172a;
    --plan-muted: rgba(15, 23, 42, 0.6);
    --plan-feature-text: rgba(15, 23, 42, 0.78);
    --plan-feature-muted: rgba(15, 23, 42, 0.45);
    --plan-accent: rgba(16, 185, 129, 0.85);
    --plan-status: rgba(15, 118, 110, 0.85);
    --plan-shadow: 0 1px 2px rgba(15, 23, 42, 0.08);
    --plan-shadow-hover: 0 2px 6px rgba(15, 23, 42, 0.12);
    --plan-active-shadow: 0 0 0 1px rgba(16, 185, 129, 0.35);
    --plan-badge-bg: rgba(15, 23, 42, 0.06);
    --plan-badge-border: rgba(15, 23, 42, 0.14);
    --plan-badge-text: #0f172a;
    --plan-badge-active-bg: rgba(16, 185, 129, 0.16);
    --plan-badge-active-border: rgba(16, 185, 129, 0.5);
    --plan-badge-active-text: #0f766e;
    --plan-dot: rgba(148, 163, 184, 0.9);
    --plan-dot-ring: rgba(148, 163, 184, 0.18);
    --plan-cta-bg: #ffffff;
    --plan-cta-border: rgba(15, 23, 42, 0.14);
    --plan-cta-text: #0f172a;
    --plan-cta-hover-bg: rgba(15, 23, 42, 0.04);
    --plan-cta-hover-border: rgba(15, 23, 42, 0.3);
    --plan-cta-disabled: rgba(15, 23, 42, 0.35);
    --plan-cta-disabled-bg: rgba(15, 23, 42, 0.03);
    --plan-cta-disabled-border: rgba(15, 23, 42, 0.1);
    display: grid;
    gap: 20px;
    padding: 24px;
}

.plan-advisor-modal__header {
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    align-items: flex-start;
    gap: 16px;
}

.plan-advisor-modal__eyebrow {
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: #0f766e;
}

.plan-advisor-modal__title {
    margin-top: 6px;
    font-size: 1.25rem;
    font-weight: 700;
    color: #0f172a;
}

.plan-advisor-modal__subtitle {
    margin-top: 6px;
    max-width: 42rem;
    font-size: 0.9rem;
    color: rgba(15, 23, 42, 0.68);
}

.plan-advisor-modal__close {
    border-radius: 4px;
    border: 1px solid rgba(15, 23, 42, 0.14);
    background: #ffffff;
    color: #0f172a;
    padding: 10px 14px;
    font-size: 0.8rem;
    font-weight: 600;
}

.plan-advisor-stepper {
    display: grid;
    gap: 10px;
}

.plan-advisor-stepper__item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 12px;
    border-radius: 4px;
    border: 1px solid rgba(15, 23, 42, 0.1);
    background: #f8fafc;
    color: #0f172a;
    text-align: left;
}

.plan-advisor-stepper__item.is-active {
    border-color: rgba(16, 185, 129, 0.45);
    background: rgba(16, 185, 129, 0.08);
}

.plan-advisor-stepper__item.is-complete {
    border-color: rgba(14, 165, 233, 0.2);
    background: rgba(14, 165, 233, 0.06);
}

.plan-advisor-stepper__index {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 1.75rem;
    height: 1.75rem;
    border-radius: 999px;
    background: rgba(15, 23, 42, 0.08);
    font-size: 0.8rem;
    font-weight: 700;
}

.plan-advisor-stepper__item.is-active .plan-advisor-stepper__index {
    background: rgba(16, 185, 129, 0.16);
    color: #0f766e;
}

.plan-advisor-stepper__label {
    font-size: 0.84rem;
    font-weight: 600;
}

.plan-advisor__header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 12px;
}

.plan-advisor__title {
    font-size: 1rem;
    font-weight: 600;
    color: var(--plan-text);
}

.plan-advisor__subtitle {
    margin-top: 4px;
    font-size: 0.85rem;
    color: var(--plan-muted);
    max-width: 52rem;
}

.plan-advisor__questions {
    display: grid;
    gap: 16px;
}

.plan-advisor__question {
    display: grid;
    gap: 10px;
}

.plan-advisor__question-card {
    display: grid;
    gap: 14px;
    padding: 18px;
    border-radius: 4px;
    border: 1px solid rgba(15, 23, 42, 0.08);
    background: #f8fafc;
}

.plan-advisor__question-label {
    font-size: 0.78rem;
    font-weight: 600;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: var(--plan-muted);
}

.plan-advisor__options {
    display: grid;
    gap: 10px;
}

.plan-advisor__options.plan-advisor__options--stacked {
    grid-template-columns: 1fr;
}

.plan-advisor__option {
    display: grid;
    gap: 4px;
    width: 100%;
    padding: 12px 14px;
    border-radius: 4px;
    border: 1px solid var(--plan-border);
    background: var(--plan-card);
    color: var(--plan-text);
    text-align: left;
    transition: border-color 150ms ease, box-shadow 150ms ease, transform 150ms ease;
}

.plan-advisor__option strong {
    font-size: 0.9rem;
    font-weight: 600;
}

.plan-advisor__option span {
    font-size: 0.8rem;
    color: var(--plan-muted);
    line-height: 1.35;
}

.plan-advisor__option:hover {
    transform: translateY(-1px);
    border-color: var(--plan-border-hover);
    box-shadow: var(--plan-shadow);
}

.plan-advisor__option.is-selected {
    border-color: rgba(16, 185, 129, 0.5);
    box-shadow: 0 0 0 1px rgba(16, 185, 129, 0.2);
    background: rgba(16, 185, 129, 0.05);
}

.plan-advisor__results {
    display: grid;
    gap: 16px;
    padding: 18px;
    border-radius: 8px;
    border: 1px solid rgba(15, 23, 42, 0.08);
    background:
        linear-gradient(180deg, rgba(14, 165, 233, 0.05), rgba(14, 165, 233, 0) 24%),
        #f8fafc;
    align-items: stretch;
}

.plan-advisor__error {
    border: 1px solid rgba(239, 68, 68, 0.18);
    background: rgba(254, 242, 242, 0.9);
    color: #b91c1c;
    border-radius: 4px;
    padding: 12px 14px;
    font-size: 0.85rem;
}

.plan-advisor-modal__footer {
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    gap: 12px;
    padding-top: 4px;
}

.plan-advisor-modal__footer-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}

.plan-advisor-modal__footer-btn {
    border-radius: 4px;
    border: 1px solid rgba(15, 23, 42, 0.14);
    background: #ffffff;
    color: #0f172a;
    padding: 10px 14px;
    font-size: 0.82rem;
    font-weight: 600;
}

.plan-advisor-modal__footer-btn:disabled {
    opacity: 0.55;
    cursor: not-allowed;
}

.plan-advisor-modal__footer-btn--primary {
    border-color: rgba(16, 185, 129, 0.4);
    background: rgba(16, 185, 129, 0.12);
    color: #0f766e;
}

@media (min-width: 768px) {
    .plan-advisor-stepper {
        grid-template-columns: repeat(4, minmax(0, 1fr));
    }

    .plan-advisor__options {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }

    .plan-advisor__options.plan-advisor__options--stacked {
        grid-template-columns: 1fr;
    }

    .plan-advisor__results {
        grid-template-columns: repeat(3, minmax(0, 1fr));
        grid-auto-rows: 1fr;
    }
}

.assistant-addon {
    margin-top: 18px;
    border: 1px solid rgba(15, 23, 42, 0.08);
    background: #f8fafc;
    border-radius: 4px;
    padding: 16px;
}

.assistant-addon__header {
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    align-items: flex-start;
    gap: 12px;
}

.assistant-addon__title {
    font-size: 1rem;
    font-weight: 600;
    color: #0f172a;
}

.assistant-addon__subtitle {
    font-size: 0.85rem;
    color: rgba(15, 23, 42, 0.6);
}

.assistant-addon__actions {
    display: flex;
    align-items: center;
    gap: 8px;
}

.assistant-addon__badge {
    border-radius: 999px;
    padding: 4px 10px;
    font-size: 0.65rem;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    border: 1px solid rgba(15, 23, 42, 0.12);
    color: rgba(15, 23, 42, 0.8);
    background: rgba(15, 23, 42, 0.04);
}

.assistant-addon__badge--included {
    border-color: rgba(16, 185, 129, 0.5);
    color: #0f766e;
    background: rgba(16, 185, 129, 0.16);
}

.assistant-addon__cta {
    border-radius: 4px;
    border: 1px solid rgba(15, 23, 42, 0.16);
    background: #ffffff;
    color: #0f172a;
    padding: 8px 12px;
    font-size: 0.8rem;
    font-weight: 600;
}

.assistant-addon__cta:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

.assistant-addon__cta--included {
    border: 1px dashed rgba(16, 185, 129, 0.5);
    background: transparent;
    color: #0f766e;
    padding: 8px 12px;
    font-size: 0.75rem;
}

.assistant-addon__error {
    margin-top: 10px;
    font-size: 0.8rem;
    color: #b91c1c;
}

.assistant-addon__success {
    margin-top: 10px;
    font-size: 0.8rem;
    color: #047857;
}

.assistant-addon__hint {
    margin-top: 10px;
    font-size: 0.8rem;
    color: rgba(15, 23, 42, 0.6);
}

.assistant-addon__usage {
    margin-top: 12px;
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
}

.assistant-addon__usage-item {
    display: flex;
    flex-direction: column;
    gap: 2px;
    padding: 10px 12px;
    border-radius: 4px;
    background: #ffffff;
    border: 1px solid rgba(15, 23, 42, 0.08);
    min-width: 140px;
}

.assistant-addon__usage-item span {
    font-size: 0.7rem;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: rgba(15, 23, 42, 0.45);
}

.assistant-addon__usage-item strong {
    font-size: 1.1rem;
    color: #0f172a;
}

.assistant-addon__usage-item em {
    font-size: 0.75rem;
    color: rgba(15, 23, 42, 0.6);
}

.assistant-addon__credits {
    margin-top: 12px;
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    align-items: center;
}

.assistant-addon__credit-actions {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.assistant-addon__credit-pack {
    font-size: 0.75rem;
    color: rgba(15, 23, 42, 0.55);
}

:global(.dark) .assistant-addon {
    border-color: rgba(255, 255, 255, 0.08);
    background: rgba(15, 23, 42, 0.6);
}

:global(.dark) .assistant-addon__title {
    color: #e2e8f0;
}

:global(.dark) .assistant-addon__subtitle {
    color: rgba(226, 232, 240, 0.65);
}

:global(.dark) .assistant-addon__badge {
    border-color: rgba(255, 255, 255, 0.2);
    color: rgba(226, 232, 240, 0.85);
    background: rgba(15, 23, 42, 0.4);
}

:global(.dark) .assistant-addon__badge--included {
    border-color: rgba(16, 185, 129, 0.6);
    color: #d1fae5;
    background: rgba(16, 185, 129, 0.18);
}

:global(.dark) .assistant-addon__cta {
    border-color: rgba(255, 255, 255, 0.14);
    background: rgba(15, 23, 42, 0.8);
    color: #f8fafc;
}

:global(.dark) .assistant-addon__usage-item {
    background: rgba(15, 23, 42, 0.8);
    border-color: rgba(255, 255, 255, 0.08);
}

:global(.dark) .assistant-addon__usage-item span {
    color: rgba(226, 232, 240, 0.6);
}

:global(.dark) .assistant-addon__usage-item strong {
    color: #f8fafc;
}

:global(.dark) .assistant-addon__usage-item em {
    color: rgba(226, 232, 240, 0.7);
}

:global(.dark) .assistant-addon__success {
    color: #6ee7b7;
}

:global(.dark) .assistant-addon__credit-pack {
    color: rgba(226, 232, 240, 0.6);
}
</style>
