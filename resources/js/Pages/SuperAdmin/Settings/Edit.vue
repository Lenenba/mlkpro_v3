<script setup>
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PlanPriceDisplay from '@/Components/Billing/PlanPriceDisplay.vue';
import { planPricingForBillingDisplay } from '@/utils/subscriptionPricing';
import Checkbox from '@/Components/Checkbox.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import InputError from '@/Components/InputError.vue';
import Modal from '@/Components/Modal.vue';

const props = defineProps({
    maintenance: {
        type: Object,
        default: () => ({ enabled: false, message: '' }),
    },
    templates: {
        type: Object,
        default: () => ({ email_default: '', quote_default: '', invoice_default: '' }),
    },
    public_navigation: {
        type: Object,
        default: () => ({ contact_form_url: '' }),
    },
    plans: {
        type: Array,
        default: () => [],
    },
    plan_limits: {
        type: Object,
        default: () => ({}),
    },
    plan_modules: {
        type: Object,
        default: () => ({}),
    },
    plan_display: {
        type: Object,
        default: () => ({}),
    },
    plan_prices: {
        type: Object,
        default: () => ({}),
    },
    subscription_promotion: {
        type: Object,
        default: () => ({
            enabled: false,
            monthly_discount_percent: null,
            yearly_discount_percent: null,
            monthly_stripe_coupon_id: null,
            yearly_stripe_coupon_id: null,
            last_synced_at: null,
        }),
    },
    promotion_discount_options: {
        type: Array,
        default: () => [20, 25, 30, 35, 40, 45, 50],
    },
    annual_discount_percent: {
        type: Number,
        default: 20,
    },
});

const { t } = useI18n();
const page = usePage();
const isSuperadmin = computed(() => Boolean(page.props.auth?.account?.is_superadmin));

const limitKeys = computed(() => [
    { key: 'quotes', label: t('super_admin.settings.limits.quotes') },
    { key: 'requests', label: t('super_admin.settings.limits.requests') },
    { key: 'plan_scan_quotes', label: t('super_admin.settings.limits.plan_scan_quotes') },
    { key: 'invoices', label: t('super_admin.settings.limits.invoices') },
    { key: 'jobs', label: t('super_admin.settings.limits.jobs') },
    { key: 'products', label: t('super_admin.settings.limits.products') },
    { key: 'services', label: t('super_admin.settings.limits.services') },
    { key: 'tasks', label: t('super_admin.settings.limits.tasks') },
    { key: 'team_members', label: t('super_admin.settings.limits.team_members') },
    { key: 'assistant_requests', label: t('super_admin.settings.limits.assistant_requests') },
]);

const moduleKeys = computed(() => [
    { key: 'quotes', label: t('super_admin.settings.modules.quotes') },
    { key: 'requests', label: t('super_admin.settings.modules.requests') },
    { key: 'reservations', label: t('super_admin.settings.modules.reservations') },
    { key: 'plan_scans', label: t('super_admin.settings.modules.plan_scans') },
    { key: 'invoices', label: t('super_admin.settings.modules.invoices') },
    { key: 'jobs', label: t('super_admin.settings.modules.jobs') },
    { key: 'products', label: t('super_admin.settings.modules.products') },
    { key: 'performance', label: t('super_admin.settings.modules.performance') },
    { key: 'presence', label: t('super_admin.settings.modules.presence') },
    { key: 'planning', label: t('super_admin.settings.modules.planning') },
    { key: 'expenses', label: t('super_admin.settings.modules.expenses') },
    { key: 'accounting', label: t('super_admin.settings.modules.accounting') },
    { key: 'services', label: t('super_admin.settings.modules.services') },
    { key: 'tasks', label: t('super_admin.settings.modules.tasks') },
    { key: 'team_members', label: t('super_admin.settings.modules.team_members') },
    { key: 'assistant', label: t('super_admin.settings.modules.assistant') },
    { key: 'loyalty', label: t('super_admin.settings.modules.loyalty') },
    { key: 'campaigns', label: t('super_admin.settings.modules.campaigns') },
]);

const form = useForm({
    maintenance: {
        enabled: props.maintenance?.enabled ?? false,
        message: props.maintenance?.message ?? '',
    },
    templates: {
        email_default: props.templates?.email_default ?? '',
        quote_default: props.templates?.quote_default ?? '',
        invoice_default: props.templates?.invoice_default ?? '',
    },
    public_navigation: {
        contact_form_url: props.public_navigation?.contact_form_url ?? '',
    },
    subscription_promotion: {
        enabled: props.subscription_promotion?.enabled ?? false,
        monthly_discount_percent: props.subscription_promotion?.monthly_discount_percent ?? null,
        yearly_discount_percent: props.subscription_promotion?.yearly_discount_percent ?? null,
    },
    plan_limits: props.plans.reduce((acc, plan) => {
        const existing = props.plan_limits?.[plan.key] || {};
        acc[plan.key] = limitKeys.value.reduce((limits, item) => {
            limits[item.key] = existing[item.key] ?? '';
            return limits;
        }, {});
        return acc;
    }, {}),
    plan_modules: props.plans.reduce((acc, plan) => {
        const existing = props.plan_modules?.[plan.key] || {};
        acc[plan.key] = moduleKeys.value.reduce((modules, item) => {
            modules[item.key] = typeof existing[item.key] === 'boolean' ? existing[item.key] : true;
            return modules;
        }, {});
        return acc;
    }, {}),
    plan_display: props.plans.reduce((acc, plan) => {
        const existing = props.plan_display?.[plan.key] || {};
        acc[plan.key] = {
            name: existing.name ?? plan.name ?? plan.key,
            price: existing.price ?? '',
            badge: existing.badge ?? '',
            features: Array.isArray(existing.features) ? [...existing.features] : [],
        };
        return acc;
    }, {}),
    plan_prices: props.plans.reduce((acc, plan) => {
        const existing = props.plan_prices?.[plan.key] || {};
        ['CAD', 'EUR', 'USD'].forEach((currency) => {
            const row = existing[currency] || {};
            if (!acc[plan.key]) {
                acc[plan.key] = {};
            }
            acc[plan.key][currency] = {
                currency_code: row.currency_code ?? currency,
                billing_period: row.billing_period ?? 'monthly',
                amount: row.amount ?? '',
                stripe_price_id: row.stripe_price_id ?? '',
                is_active: row.is_active ?? true,
            };
        });
        return acc;
    }, {}),
});

const activePlanKey = ref(null);
const activePlan = computed(() => props.plans.find((plan) => plan.key === activePlanKey.value) || null);
const showPlanModal = computed(() => Boolean(activePlan.value));

const activeModulePlanKey = ref(null);
const activeModulePlan = computed(() => props.plans.find((plan) => plan.key === activeModulePlanKey.value) || null);
const showModuleModal = computed(() => Boolean(activeModulePlan.value));

const activeDisplayPlanKey = ref(null);
const activeDisplayPlan = computed(() => props.plans.find((plan) => plan.key === activeDisplayPlanKey.value) || null);
const showDisplayModal = computed(() => Boolean(activeDisplayPlan.value));
const activePricingPlanKey = ref(null);
const activePricingPlan = computed(() => props.plans.find((plan) => plan.key === activePricingPlanKey.value) || null);
const showPricingModal = computed(() => Boolean(activePricingPlan.value));

const limitValue = (planKey, limitKey) => {
    const value = form.plan_limits?.[planKey]?.[limitKey];
    if (value === '' || value === null || typeof value === 'undefined') {
        return t('super_admin.common.unlimited');
    }
    return value;
};

const moduleValue = (planKey, moduleKey) =>
    form.plan_modules?.[planKey]?.[moduleKey] === false
        ? t('super_admin.common.disabled')
        : t('super_admin.common.enabled');

const openPlan = (plan) => {
    activePlanKey.value = plan.key;
};

const closePlan = () => {
    activePlanKey.value = null;
};

const openModulePlan = (plan) => {
    activeModulePlanKey.value = plan.key;
};

const closeModulePlan = () => {
    activeModulePlanKey.value = null;
};

const openPricingPlan = (plan) => {
    activePricingPlanKey.value = plan.key;
};

const closePricingPlan = () => {
    activePricingPlanKey.value = null;
};

const openDisplayPlan = (plan) => {
    activeDisplayPlanKey.value = plan.key;
    if (form.plan_display?.[plan.key] && !Array.isArray(form.plan_display[plan.key].features)) {
        form.plan_display[plan.key].features = [];
    }
};

const closeDisplayPlan = () => {
    activeDisplayPlanKey.value = null;
};

const addDisplayFeature = (planKey) => {
    if (!form.plan_display?.[planKey]) {
        return;
    }
    if (!Array.isArray(form.plan_display[planKey].features)) {
        form.plan_display[planKey].features = [];
    }
    form.plan_display[planKey].features.push('');
};

const removeDisplayFeature = (planKey, index) => {
    if (!form.plan_display?.[planKey]?.features) {
        return;
    }
    form.plan_display[planKey].features.splice(index, 1);
};

const displayFeatureCount = (planKey) =>
    (form.plan_display?.[planKey]?.features || [])
        .filter((feature) => typeof feature === 'string' && feature.trim() !== '')
        .length;
const supportedCurrencies = ['CAD', 'EUR', 'USD'];
const priceSummary = (planKey, currency) => {
    const amount = form.plan_prices?.[planKey]?.[currency]?.amount;
    return amount === '' || amount === null || typeof amount === 'undefined'
        ? '--'
        : `${amount} ${currency}`;
};

const normalizePromotionDiscount = (value) => {
    const raw = Number(value ?? 0);

    return Number.isFinite(raw) && raw > 0 ? raw : null;
};

const monthlyPromotionDiscountPercent = computed(() =>
    normalizePromotionDiscount(form.subscription_promotion?.monthly_discount_percent)
);

const yearlyPromotionDiscountPercent = computed(() =>
    normalizePromotionDiscount(form.subscription_promotion?.yearly_discount_percent)
);

const billingPeriodPromotionPercent = (billingPeriod = 'monthly') => (
    billingPeriod === 'yearly'
        ? yearlyPromotionDiscountPercent.value
        : monthlyPromotionDiscountPercent.value
);

const subscriptionPromotionEnabled = computed(() =>
    Boolean(
        form.subscription_promotion?.enabled
        && (monthlyPromotionDiscountPercent.value || yearlyPromotionDiscountPercent.value)
    )
);

const formatMoney = (amount, currency) => {
    const numericAmount = Number(amount);

    if (!Number.isFinite(numericAmount)) {
        return null;
    }

    try {
        return new Intl.NumberFormat(undefined, {
            style: 'currency',
            currency,
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        }).format(numericAmount);
    } catch (error) {
        return `${numericAmount.toFixed(2)} ${currency}`;
    }
};

const emptyPromotionPreviewPricing = (billingPeriod, currency) => ({
    billing_period: billingPeriod,
    currency_code: currency,
    amount: null,
    original_amount: null,
    discounted_amount: null,
    display_price: null,
    original_display_price: null,
    discounted_display_price: null,
    is_discounted: false,
    promotion: {
        is_active: false,
        discount_percent: billingPeriodPromotionPercent(billingPeriod),
    },
});

const deriveYearlyAmount = (monthlyAmount) => (
    monthlyAmount * 12 * ((100 - Number(props.annual_discount_percent || 0)) / 100)
);

const buildPromotionPreviewPricing = (amount, currency, billingPeriod = 'monthly') => {
    const numericAmount = Number(amount);
    if (!Number.isFinite(numericAmount)) {
        return emptyPromotionPreviewPricing(billingPeriod, currency);
    }

    const originalAmount = numericAmount.toFixed(2);
    const discountPercent = billingPeriodPromotionPercent(billingPeriod);
    const isPromotionActive = Boolean(form.subscription_promotion?.enabled && discountPercent);
    const discountedAmount = isPromotionActive
        ? (numericAmount * (1 - (discountPercent / 100))).toFixed(2)
        : originalAmount;
    const isDiscounted = isPromotionActive && originalAmount !== discountedAmount;

    return {
        billing_period: billingPeriod,
        currency_code: currency,
        amount: originalAmount,
        original_amount: originalAmount,
        discounted_amount: discountedAmount,
        display_price: formatMoney(discountedAmount, currency),
        original_display_price: formatMoney(originalAmount, currency),
        discounted_display_price: formatMoney(discountedAmount, currency),
        is_discounted: isDiscounted,
        promotion: {
            is_active: isDiscounted,
            discount_percent: discountPercent,
        },
    };
};

const promotionPreviewPricing = (planKey, currency, billingPeriod = 'monthly') => {
    const row = form.plan_prices?.[planKey]?.[currency];
    const amount = row?.amount;

    if (row?.is_active === false || amount === '' || amount === null || typeof amount === 'undefined') {
        return emptyPromotionPreviewPricing(billingPeriod, currency);
    }

    const numericAmount = Number(amount);
    if (!Number.isFinite(numericAmount)) {
        return emptyPromotionPreviewPricing(billingPeriod, currency);
    }

    const previewPlan = {
        prices_by_period: {
            monthly: buildPromotionPreviewPricing(numericAmount, currency, 'monthly'),
            yearly: buildPromotionPreviewPricing(deriveYearlyAmount(numericAmount), currency, 'yearly'),
        },
    };

    return planPricingForBillingDisplay(
        previewPlan,
        billingPeriod,
        previewPlan.prices_by_period[billingPeriod]
    );
};

const promotionStatusLabel = computed(() => {
    if (!props.subscription_promotion?.last_synced_at) {
        return 'Not synced to Stripe yet';
    }

    const parsed = new Date(props.subscription_promotion.last_synced_at);
    if (Number.isNaN(parsed.getTime())) {
        return String(props.subscription_promotion.last_synced_at);
    }

    return new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(parsed);
});

const putSettings = (options = {}) => {
    form.transform((data) => {
        const payload = { ...data };
        if (!isSuperadmin.value) {
            delete payload.plan_modules;
        }
        return payload;
    }).put(route('superadmin.settings.update'), {
        preserveScroll: true,
        ...options,
    });
};

const submit = () => {
    putSettings();
};

const submitPlanLimits = () => {
    putSettings({
        onSuccess: () => closePlan(),
    });
};

const submitPlanModules = () => {
    if (!isSuperadmin.value) {
        return;
    }
    putSettings({
        onSuccess: () => closeModulePlan(),
    });
};

const submitPlanDisplay = () => {
    putSettings({
        onSuccess: () => closeDisplayPlan(),
    });
};

const submitPlanPricing = () => {
    putSettings({
        onSuccess: () => closePricingPlan(),
    });
};

const submitPromotion = () => {
    putSettings();
};
</script>

<template>
    <Head :title="$t('super_admin.settings.page_title')" />

    <AuthenticatedLayout>
        <div class="space-y-6">
            <section class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="space-y-1">
                    <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">
                        {{ $t('super_admin.settings.title') }}
                    </h1>
                    <p class="text-sm text-stone-600 dark:text-neutral-400">
                        {{ $t('super_admin.settings.subtitle') }}
                    </p>
                </div>
            </section>

            <div class="rounded-sm border border-stone-200 border-t-4 border-t-amber-600 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                    {{ $t('super_admin.settings.maintenance.title') }}
                </h2>
                <form class="mt-4 space-y-4" @submit.prevent="submit">
                    <label class="flex items-center gap-2 text-sm text-stone-700 dark:text-neutral-200">
                        <Checkbox v-model:checked="form.maintenance.enabled" :value="true" />
                        <span>{{ $t('super_admin.settings.maintenance.enable') }}</span>
                    </label>
                    <div>
                        <FloatingInput v-model="form.maintenance.message" :label="$t('super_admin.settings.maintenance.message')" />
                        <InputError class="mt-1" :message="form.errors['maintenance.message']" />
                    </div>
                    <div class="flex justify-end">
                        <button type="submit" :disabled="form.processing"
                            class="py-2 px-3 text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50 disabled:pointer-events-none">
                            {{ $t('super_admin.settings.maintenance.save') }}
                        </button>
                    </div>
                </form>
            </div>

            <div class="rounded-sm border border-stone-200 border-t-4 border-t-sky-600 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                    {{ $t('super_admin.settings.templates.title') }}
                </h2>
                <form class="mt-4 space-y-4" @submit.prevent="submit">
                    <div>
                        <label class="block text-xs text-stone-500 dark:text-neutral-400">
                            {{ $t('super_admin.settings.templates.email_default') }}
                        </label>
                        <textarea v-model="form.templates.email_default" rows="3"
                            class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"></textarea>
                        <InputError class="mt-1" :message="form.errors['templates.email_default']" />
                    </div>
                    <div>
                        <label class="block text-xs text-stone-500 dark:text-neutral-400">
                            {{ $t('super_admin.settings.templates.quote_default') }}
                        </label>
                        <textarea v-model="form.templates.quote_default" rows="3"
                            class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"></textarea>
                        <InputError class="mt-1" :message="form.errors['templates.quote_default']" />
                    </div>
                    <div>
                        <label class="block text-xs text-stone-500 dark:text-neutral-400">
                            {{ $t('super_admin.settings.templates.invoice_default') }}
                        </label>
                        <textarea v-model="form.templates.invoice_default" rows="3"
                            class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"></textarea>
                        <InputError class="mt-1" :message="form.errors['templates.invoice_default']" />
                    </div>
                    <div class="flex justify-end">
                        <button type="submit" :disabled="form.processing"
                            class="py-2 px-3 text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50 disabled:pointer-events-none">
                            {{ $t('super_admin.settings.templates.save') }}
                        </button>
                    </div>
                </form>
            </div>

            <div class="rounded-sm border border-stone-200 border-t-4 border-t-teal-600 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                    Public navigation
                </h2>
                <p class="mt-1 text-sm text-stone-600 dark:text-neutral-400">
                    Use this to control where the public header item <span class="font-semibold">Contact us</span> sends visitors. Leave it empty to keep the fallback page <span class="font-semibold">/pages/contact-us</span>.
                </p>
                <form class="mt-4 space-y-4" @submit.prevent="submit">
                    <div>
                        <FloatingInput
                            v-model="form.public_navigation.contact_form_url"
                            label="Contact form URL or internal path"
                        />
                        <p class="mt-2 text-xs text-stone-500 dark:text-neutral-400">
                            Examples: <span class="font-medium">https://tally.so/r/xxxxxx</span> or <span class="font-medium">/pages/contact-us</span>
                        </p>
                        <InputError class="mt-1" :message="form.errors['public_navigation.contact_form_url']" />
                    </div>
                    <div class="flex justify-end">
                        <button type="submit" :disabled="form.processing"
                            class="py-2 px-3 text-sm font-medium rounded-sm border border-transparent bg-teal-600 text-white hover:bg-teal-700 disabled:opacity-50 disabled:pointer-events-none">
                            Save public navigation
                        </button>
                    </div>
                </form>
            </div>

            <div class="rounded-sm border border-stone-200 border-t-4 border-t-rose-600 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                    Subscription promotion
                </h2>
                <p class="mt-1 text-sm text-stone-600 dark:text-neutral-400">
                    Turn the global subscription promotion on or off, choose different discount percentages for monthly and yearly billing, and preview the final customer-facing prices before saving.
                </p>
                <form class="mt-4 space-y-4" @submit.prevent="submitPromotion">
                    <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(320px,420px)]">
                        <div class="space-y-4">
                            <label class="flex items-center gap-2 text-sm text-stone-700 dark:text-neutral-200">
                                <Checkbox v-model:checked="form.subscription_promotion.enabled" :value="true" />
                                <span>Enable promotion</span>
                            </label>
                            <div class="grid gap-4 md:grid-cols-2">
                                <div class="max-w-xs">
                                    <label class="block text-xs text-stone-500 dark:text-neutral-400">
                                        Monthly discount percent
                                    </label>
                                    <select
                                        v-model="form.subscription_promotion.monthly_discount_percent"
                                        class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-rose-600 focus:ring-rose-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                                    >
                                        <option :value="null">No discount</option>
                                        <option
                                            v-for="discount in promotion_discount_options"
                                            :key="`promotion-monthly-discount-${discount}`"
                                            :value="discount"
                                        >
                                            {{ discount }}%
                                        </option>
                                    </select>
                                    <InputError class="mt-1" :message="form.errors['subscription_promotion.monthly_discount_percent']" />
                                </div>
                                <div class="max-w-xs">
                                    <label class="block text-xs text-stone-500 dark:text-neutral-400">
                                        Yearly discount percent
                                    </label>
                                    <select
                                        v-model="form.subscription_promotion.yearly_discount_percent"
                                        class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-rose-600 focus:ring-rose-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                                    >
                                        <option :value="null">No discount</option>
                                        <option
                                            v-for="discount in promotion_discount_options"
                                            :key="`promotion-yearly-discount-${discount}`"
                                            :value="discount"
                                        >
                                            {{ discount }}%
                                        </option>
                                    </select>
                                    <InputError class="mt-1" :message="form.errors['subscription_promotion.yearly_discount_percent']" />
                                </div>
                            </div>
                            <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 text-xs text-stone-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300">
                                <p>
                                    Available discounts: {{ promotion_discount_options.map((discount) => `${discount}%`).join(', ') }}
                                </p>
                                <p class="mt-2">
                                    Stripe sync status: {{ promotionStatusLabel }}
                                </p>
                                <p v-if="props.subscription_promotion?.monthly_stripe_coupon_id" class="mt-2">
                                    Current monthly Stripe coupon:
                                    <span class="font-semibold">{{ props.subscription_promotion.monthly_stripe_coupon_id }}</span>
                                </p>
                                <p v-if="props.subscription_promotion?.yearly_stripe_coupon_id" class="mt-2">
                                    Current yearly Stripe coupon:
                                    <span class="font-semibold">{{ props.subscription_promotion.yearly_stripe_coupon_id }}</span>
                                </p>
                            </div>
                        </div>
                        <div class="rounded-sm border border-stone-200 bg-stone-50 p-4 dark:border-neutral-700 dark:bg-neutral-900">
                            <div class="text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                Live preview summary
                            </div>
                            <div class="mt-3 space-y-2 text-sm text-stone-700 dark:text-neutral-200">
                                <p>
                                    Promotion status:
                                    <span class="font-semibold">{{ subscriptionPromotionEnabled ? 'Active' : 'Disabled' }}</span>
                                </p>
                                <p>
                                    Monthly discount:
                                    <span class="font-semibold">{{ monthlyPromotionDiscountPercent ? `${monthlyPromotionDiscountPercent}%` : 'None' }}</span>
                                </p>
                                <p>
                                    Yearly discount:
                                    <span class="font-semibold">{{ yearlyPromotionDiscountPercent ? `${yearlyPromotionDiscountPercent}%` : 'None' }}</span>
                                </p>
                                <p>
                                    Base yearly billing reduction:
                                    <span class="font-semibold">{{ props.annual_discount_percent > 0 ? `${props.annual_discount_percent}%` : 'None' }}</span>
                                </p>
                                <p class="text-xs text-stone-500 dark:text-neutral-400">
                                    Stripe coupons are created or reused automatically when you save.
                                </p>
                            </div>
                        </div>
                    </div>
                    <div v-if="plans.length > 0" class="space-y-3">
                        <div class="flex items-center justify-between gap-3">
                            <h3 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                Final billing preview
                            </h3>
                            <span class="text-xs text-stone-500 dark:text-neutral-400">
                                Inactive prices show as unavailable.
                            </span>
                        </div>
                        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                            <div
                                v-for="plan in plans"
                                :key="`promotion-preview-${plan.key}`"
                                class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900/40"
                            >
                                <div class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                    {{ form.plan_display?.[plan.key]?.name || plan.name }}
                                </div>
                                <div class="mt-3 space-y-3">
                                    <div
                                        v-for="currency in supportedCurrencies"
                                        :key="`promotion-preview-${plan.key}-${currency}`"
                                        class="rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-900"
                                    >
                                        <div class="flex items-center justify-between gap-2 text-[11px] font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                            <span>{{ currency }}</span>
                                            <span v-if="form.plan_prices?.[plan.key]?.[currency]?.is_active === false">Inactive</span>
                                        </div>
                                        <div class="mt-3 grid gap-3">
                                            <div>
                                                <div class="text-[11px] font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                                    Monthly
                                                </div>
                                                <PlanPriceDisplay
                                                    :pricing="promotionPreviewPricing(plan.key, currency, 'monthly')"
                                                    interval-label="/month"
                                                    empty-label="Not set"
                                                    container-class="mt-2 flex flex-wrap items-baseline gap-x-2 gap-y-1"
                                                    price-class="text-lg font-semibold text-stone-900 dark:text-neutral-100"
                                                    original-price-class="text-xs font-medium text-stone-400 line-through dark:text-neutral-500"
                                                    interval-class="text-xs font-medium text-stone-500 dark:text-neutral-400"
                                                />
                                            </div>
                                            <div>
                                                <div class="text-[11px] font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                                    Yearly
                                                </div>
                                                <PlanPriceDisplay
                                                    :pricing="promotionPreviewPricing(plan.key, currency, 'yearly')"
                                                    interval-label="/month"
                                                    empty-label="Not set"
                                                    container-class="mt-2 flex flex-wrap items-baseline gap-x-2 gap-y-1"
                                                    price-class="text-lg font-semibold text-stone-900 dark:text-neutral-100"
                                                    original-price-class="text-xs font-medium text-stone-400 line-through dark:text-neutral-500"
                                                    interval-class="text-xs font-medium text-stone-500 dark:text-neutral-400"
                                                />
                                                <p class="mt-1 text-[11px] font-medium text-emerald-700 dark:text-emerald-300">
                                                    For 12 months, billed annually.
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="flex justify-end">
                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="py-2 px-3 text-sm font-medium rounded-sm border border-transparent bg-rose-600 text-white hover:bg-rose-700 disabled:opacity-50 disabled:pointer-events-none"
                        >
                            Save promotion
                        </button>
                    </div>
                </form>
            </div>

            <div class="rounded-sm border border-stone-200 border-t-4 border-t-zinc-600 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                    {{ $t('super_admin.settings.plan_limits.title') }}
                </h2>
                <p class="mt-1 text-sm text-stone-600 dark:text-neutral-400">
                    {{ $t('super_admin.settings.plan_limits.subtitle') }}
                </p>
                <form class="mt-4 space-y-4" @submit.prevent="submit">
                    <div v-if="plans.length === 0" class="text-sm text-stone-500 dark:text-neutral-400">
                        {{ $t('super_admin.settings.plan_limits.empty') }}
                    </div>
                    <div v-else class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        <div v-for="plan in plans" :key="plan.key"
                            class="cursor-pointer rounded-sm border border-stone-200 bg-white p-4 shadow-sm transition hover:border-green-500 hover:shadow-md dark:border-neutral-700 dark:bg-neutral-900/40 dark:hover:border-green-500"
                            role="button"
                            tabindex="0"
                            @click="openPlan(plan)"
                            @keydown.enter="openPlan(plan)"
                            @keydown.space.prevent="openPlan(plan)">
                            <div class="flex items-start justify-between gap-3">
                                <div class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                    {{ plan.name }}
                                </div>
                                <span class="text-xs text-stone-500 dark:text-neutral-400">
                                    {{ $t('super_admin.settings.plan_limits.edit_limits') }}
                                </span>
                            </div>
                            <div class="mt-3 grid gap-2 text-xs sm:grid-cols-2">
                                <div v-for="limit in limitKeys" :key="limit.key"
                                    class="flex items-center justify-between gap-2 rounded-sm border border-stone-200 bg-stone-50 px-2 py-1 dark:border-neutral-700 dark:bg-neutral-900">
                                    <span class="text-stone-500 dark:text-neutral-400">{{ limit.label }}</span>
                                    <span class="font-semibold text-stone-800 dark:text-neutral-100">
                                        {{ limitValue(plan.key, limit.key) }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <p class="text-xs text-stone-500 dark:text-neutral-400">
                            {{ $t('super_admin.settings.plan_limits.helper') }}
                        </p>
                        <button type="submit" :disabled="form.processing"
                            class="py-2 px-3 text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50 disabled:pointer-events-none">
                            {{ $t('super_admin.settings.plan_limits.save') }}
                        </button>
                    </div>
                </form>

                <Modal :show="showPlanModal" @close="closePlan" maxWidth="2xl">
                    <div v-if="activePlan" class="p-5">
                        <div class="flex items-center justify-between">
                            <h3 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                {{ $t('super_admin.settings.plan_limits.edit_limits_title', { plan: activePlan.name }) }}
                            </h3>
                            <button type="button" @click="closePlan"
                                class="text-sm text-stone-500 dark:text-neutral-400">
                                {{ $t('super_admin.common.close') }}
                            </button>
                        </div>
                        <form class="mt-4 space-y-4" @submit.prevent="submitPlanLimits">
                            <p class="text-xs text-stone-500 dark:text-neutral-400">
                                {{ $t('super_admin.settings.plan_limits.modal_hint') }}
                            </p>
                            <div class="grid gap-3 md:grid-cols-3">
                                <div v-for="limit in limitKeys" :key="limit.key">
                                    <label class="block text-xs text-stone-500 dark:text-neutral-400">{{ limit.label }}</label>
                                    <input v-model="form.plan_limits[activePlan.key][limit.key]" type="number" min="0"
                                        :placeholder="$t('super_admin.common.unlimited')"
                                        class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200" />
                                    <InputError class="mt-1" :message="form.errors[`plan_limits.${activePlan.key}.${limit.key}`]" />
                                </div>
                            </div>
                            <div class="flex justify-end gap-2">
                                <button type="button" @click="closePlan"
                                    class="py-2 px-3 text-sm font-medium rounded-sm border border-stone-200 text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-700">
                                    {{ $t('super_admin.common.cancel') }}
                                </button>
                                <button type="submit" :disabled="form.processing"
                                    class="py-2 px-3 text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50 disabled:pointer-events-none">
                                    {{ $t('super_admin.settings.plan_limits.save') }}
                                </button>
                            </div>
                        </form>
                    </div>
                </Modal>
            </div>

            <div v-if="isSuperadmin" class="rounded-sm border border-stone-200 border-t-4 border-t-emerald-600 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                    {{ $t('super_admin.settings.plan_modules.title') }}
                </h2>
                <p class="mt-1 text-sm text-stone-600 dark:text-neutral-400">
                    {{ $t('super_admin.settings.plan_modules.subtitle') }}
                </p>
                <form class="mt-4 space-y-4" @submit.prevent="submit">
                    <div v-if="plans.length === 0" class="text-sm text-stone-500 dark:text-neutral-400">
                        {{ $t('super_admin.settings.plan_modules.empty') }}
                    </div>
                    <div v-else class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        <div v-for="plan in plans" :key="plan.key"
                            class="cursor-pointer rounded-sm border border-stone-200 bg-white p-4 shadow-sm transition hover:border-green-500 hover:shadow-md dark:border-neutral-700 dark:bg-neutral-900/40 dark:hover:border-green-500"
                            role="button"
                            tabindex="0"
                            @click="openModulePlan(plan)"
                            @keydown.enter="openModulePlan(plan)"
                            @keydown.space.prevent="openModulePlan(plan)">
                            <div class="flex items-start justify-between gap-3">
                                <div class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                    {{ plan.name }}
                                </div>
                                <span class="text-xs text-stone-500 dark:text-neutral-400">
                                    {{ $t('super_admin.settings.plan_modules.edit_modules') }}
                                </span>
                            </div>
                            <div class="mt-3 grid gap-2 text-xs sm:grid-cols-2">
                                <div v-for="module in moduleKeys" :key="module.key"
                                    class="flex items-center justify-between gap-2 rounded-sm border border-stone-200 bg-stone-50 px-2 py-1 dark:border-neutral-700 dark:bg-neutral-900">
                                    <span class="text-stone-500 dark:text-neutral-400">{{ module.label }}</span>
                                    <span class="font-semibold text-stone-800 dark:text-neutral-100">
                                        {{ moduleValue(plan.key, module.key) }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <p class="text-xs text-stone-500 dark:text-neutral-400">
                            {{ $t('super_admin.settings.plan_modules.helper') }}
                        </p>
                        <button type="submit" :disabled="form.processing"
                            class="py-2 px-3 text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50 disabled:pointer-events-none">
                            {{ $t('super_admin.settings.plan_modules.save') }}
                        </button>
                    </div>
                </form>

                <Modal :show="showModuleModal" @close="closeModulePlan" maxWidth="2xl">
                    <div v-if="activeModulePlan" class="p-5">
                        <div class="flex items-center justify-between">
                            <h3 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                {{ $t('super_admin.settings.plan_modules.edit_modules_title', { plan: activeModulePlan.name }) }}
                            </h3>
                            <button type="button" @click="closeModulePlan"
                                class="text-sm text-stone-500 dark:text-neutral-400">
                                {{ $t('super_admin.common.close') }}
                            </button>
                        </div>
                        <form class="mt-4 space-y-4" @submit.prevent="submitPlanModules">
                            <p class="text-xs text-stone-500 dark:text-neutral-400">
                                {{ $t('super_admin.settings.plan_modules.modal_hint') }}
                            </p>
                            <div class="grid gap-3 md:grid-cols-2">
                                <label v-for="module in moduleKeys" :key="module.key"
                                    class="flex items-center gap-2 text-sm text-stone-700 dark:text-neutral-200">
                                    <Checkbox v-model:checked="form.plan_modules[activeModulePlan.key][module.key]" :value="true" />
                                    <span>{{ module.label }}</span>
                                </label>
                            </div>
                            <div class="flex justify-end gap-2">
                                <button type="button" @click="closeModulePlan"
                                    class="py-2 px-3 text-sm font-medium rounded-sm border border-stone-200 text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-700">
                                    {{ $t('super_admin.common.cancel') }}
                                </button>
                                <button type="submit" :disabled="form.processing"
                                    class="py-2 px-3 text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50 disabled:pointer-events-none">
                                    {{ $t('super_admin.settings.plan_modules.save') }}
                                </button>
                            </div>
                        </form>
                    </div>
                </Modal>
            </div>

            <div class="rounded-sm border border-stone-200 border-t-4 border-t-blue-600 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                    Plan Prices
                </h2>
                <p class="mt-1 text-sm text-stone-600 dark:text-neutral-400">
                    Define explicit monthly prices per supported currency. These values are the billing source of truth.
                </p>
                <form class="mt-4 space-y-4" @submit.prevent="submitPlanPricing">
                    <div v-if="plans.length === 0" class="text-sm text-stone-500 dark:text-neutral-400">
                        No plans are configured.
                    </div>
                    <div v-else class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        <div
                            v-for="plan in plans"
                            :key="`${plan.key}-pricing`"
                            class="cursor-pointer rounded-sm border border-stone-200 bg-white p-4 shadow-sm transition hover:border-blue-500 hover:shadow-md dark:border-neutral-700 dark:bg-neutral-900/40 dark:hover:border-blue-500"
                            role="button"
                            tabindex="0"
                            @click="openPricingPlan(plan)"
                            @keydown.enter="openPricingPlan(plan)"
                            @keydown.space.prevent="openPricingPlan(plan)"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <div class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                    {{ plan.name }}
                                </div>
                                <span class="text-xs text-stone-500 dark:text-neutral-400">
                                    Edit pricing
                                </span>
                            </div>
                            <div class="mt-3 grid gap-2 text-xs">
                                <div
                                    v-for="currency in supportedCurrencies"
                                    :key="`${plan.key}-${currency}`"
                                    class="flex items-center justify-between gap-2 rounded-sm border border-stone-200 bg-stone-50 px-2 py-1 dark:border-neutral-700 dark:bg-neutral-900"
                                >
                                    <span class="text-stone-500 dark:text-neutral-400">{{ currency }}</span>
                                    <span class="font-semibold text-stone-800 dark:text-neutral-100">
                                        {{ priceSummary(plan.key, currency) }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <p class="text-xs text-stone-500 dark:text-neutral-400">
                            Leave Stripe price IDs blank until the corresponding prices exist in Stripe.
                        </p>
                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="py-2 px-3 text-sm font-medium rounded-sm border border-transparent bg-blue-600 text-white hover:bg-blue-700 disabled:opacity-50 disabled:pointer-events-none"
                        >
                            Save pricing
                        </button>
                    </div>
                </form>

                <Modal :show="showPricingModal" @close="closePricingPlan" maxWidth="3xl">
                    <div v-if="activePricingPlan" class="p-5">
                        <div class="flex items-center justify-between">
                            <h3 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                Edit pricing for {{ activePricingPlan.name }}
                            </h3>
                            <button type="button" @click="closePricingPlan" class="text-sm text-stone-500 dark:text-neutral-400">
                                {{ $t('super_admin.common.close') }}
                            </button>
                        </div>
                        <form class="mt-4 space-y-4" @submit.prevent="submitPlanPricing">
                            <div class="grid gap-4 md:grid-cols-3">
                                <div
                                    v-for="currency in supportedCurrencies"
                                    :key="`${activePricingPlan.key}-modal-${currency}`"
                                    class="rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-900"
                                >
                                    <div class="text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                        {{ currency }}
                                    </div>
                                    <div class="mt-3 space-y-3">
                                        <div>
                                            <FloatingInput
                                                v-model="form.plan_prices[activePricingPlan.key][currency].amount"
                                                :label="'Amount'"
                                            />
                                            <InputError class="mt-1" :message="form.errors[`plan_prices.${activePricingPlan.key}.${currency}.amount`]" />
                                        </div>
                                        <div>
                                            <FloatingInput
                                                v-model="form.plan_prices[activePricingPlan.key][currency].stripe_price_id"
                                                :label="'Stripe price ID'"
                                            />
                                            <InputError class="mt-1" :message="form.errors[`plan_prices.${activePricingPlan.key}.${currency}.stripe_price_id`]" />
                                        </div>
                                        <label class="flex items-center gap-2 text-sm text-stone-700 dark:text-neutral-200">
                                            <Checkbox v-model:checked="form.plan_prices[activePricingPlan.key][currency].is_active" :value="true" />
                                            <span>Active</span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                            <div class="flex justify-end gap-2">
                                <button
                                    type="button"
                                    @click="closePricingPlan"
                                    class="py-2 px-3 text-sm font-medium rounded-sm border border-stone-200 text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-700"
                                >
                                    {{ $t('super_admin.common.cancel') }}
                                </button>
                                <button
                                    type="submit"
                                    :disabled="form.processing"
                                    class="py-2 px-3 text-sm font-medium rounded-sm border border-transparent bg-blue-600 text-white hover:bg-blue-700 disabled:opacity-50 disabled:pointer-events-none"
                                >
                                    Save pricing
                                </button>
                            </div>
                        </form>
                    </div>
                </Modal>
            </div>

            <div class="rounded-sm border border-stone-200 border-t-4 border-t-indigo-600 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                    {{ $t('super_admin.settings.plan_display.title') }}
                </h2>
                <p class="mt-1 text-sm text-stone-600 dark:text-neutral-400">
                    {{ $t('super_admin.settings.plan_display.subtitle') }}
                </p>
                <form class="mt-4 space-y-4" @submit.prevent="submitPlanDisplay">
                    <div v-if="plans.length === 0" class="text-sm text-stone-500 dark:text-neutral-400">
                        {{ $t('super_admin.settings.plan_display.empty') }}
                    </div>
                    <div v-else class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        <div v-for="plan in plans" :key="plan.key"
                            class="cursor-pointer rounded-sm border border-stone-200 bg-white p-4 shadow-sm transition hover:border-indigo-500 hover:shadow-md dark:border-neutral-700 dark:bg-neutral-900/40 dark:hover:border-indigo-500"
                            role="button"
                            tabindex="0"
                            @click="openDisplayPlan(plan)"
                            @keydown.enter="openDisplayPlan(plan)"
                            @keydown.space.prevent="openDisplayPlan(plan)">
                            <div class="flex items-start justify-between gap-3">
                                <div class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                    {{ form.plan_display[plan.key].name }}
                                </div>
                                <span class="text-xs text-stone-500 dark:text-neutral-400">
                                    {{ $t('super_admin.settings.plan_display.edit_display') }}
                                </span>
                            </div>
                            <div class="mt-3 grid gap-2 text-xs sm:grid-cols-2">
                                <div class="flex items-center justify-between gap-2 rounded-sm border border-stone-200 bg-stone-50 px-2 py-1 dark:border-neutral-700 dark:bg-neutral-900">
                                    <span class="text-stone-500 dark:text-neutral-400">{{ $t('super_admin.settings.plan_display.fields.price') }}</span>
                                    <span class="font-semibold text-stone-800 dark:text-neutral-100">
                                        {{ form.plan_display[plan.key].price || '--' }}
                                    </span>
                                </div>
                                <div class="flex items-center justify-between gap-2 rounded-sm border border-stone-200 bg-stone-50 px-2 py-1 dark:border-neutral-700 dark:bg-neutral-900">
                                    <span class="text-stone-500 dark:text-neutral-400">{{ $t('super_admin.settings.plan_display.fields.features') }}</span>
                                    <span class="font-semibold text-stone-800 dark:text-neutral-100">
                                        {{ displayFeatureCount(plan.key) }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <p class="text-xs text-stone-500 dark:text-neutral-400">
                            {{ $t('super_admin.settings.plan_display.helper') }}
                        </p>
                        <button type="submit" :disabled="form.processing"
                            class="py-2 px-3 text-sm font-medium rounded-sm border border-transparent bg-indigo-600 text-white hover:bg-indigo-700 disabled:opacity-50 disabled:pointer-events-none">
                            {{ $t('super_admin.settings.plan_display.save') }}
                        </button>
                    </div>
                </form>

                <Modal :show="showDisplayModal" @close="closeDisplayPlan" maxWidth="3xl">
                    <div v-if="activeDisplayPlan" class="p-5">
                        <div class="flex items-center justify-between">
                            <h3 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                {{ $t('super_admin.settings.plan_display.edit_display_title', { plan: activeDisplayPlan.name }) }}
                            </h3>
                            <button type="button" @click="closeDisplayPlan"
                                class="text-sm text-stone-500 dark:text-neutral-400">
                                {{ $t('super_admin.common.close') }}
                            </button>
                        </div>
                        <form class="mt-4 space-y-4" @submit.prevent="submitPlanDisplay">
                            <div class="grid gap-3 md:grid-cols-3">
                                <div>
                                    <FloatingInput v-model="form.plan_display[activeDisplayPlan.key].name"
                                        :label="$t('super_admin.settings.plan_display.fields.name')" />
                                    <InputError class="mt-1" :message="form.errors[`plan_display.${activeDisplayPlan.key}.name`]" />
                                </div>
                                <div>
                                    <FloatingInput v-model="form.plan_display[activeDisplayPlan.key].price"
                                        :label="$t('super_admin.settings.plan_display.fields.price')" />
                                    <InputError class="mt-1" :message="form.errors[`plan_display.${activeDisplayPlan.key}.price`]" />
                                </div>
                                <div>
                                    <FloatingInput v-model="form.plan_display[activeDisplayPlan.key].badge"
                                        :label="$t('super_admin.settings.plan_display.fields.badge')" />
                                    <InputError class="mt-1" :message="form.errors[`plan_display.${activeDisplayPlan.key}.badge`]" />
                                </div>
                            </div>
                            <div>
                                <label class="block text-xs text-stone-500 dark:text-neutral-400">
                                    {{ $t('super_admin.settings.plan_display.fields.features') }}
                                </label>
                                <div class="mt-2 space-y-2">
                                    <div v-for="(feature, index) in form.plan_display[activeDisplayPlan.key].features"
                                        :key="`${activeDisplayPlan.key}-feature-${index}`"
                                        class="flex items-center gap-2">
                                        <input v-model="form.plan_display[activeDisplayPlan.key].features[index]" type="text"
                                            class="block w-full rounded-sm border-stone-200 text-sm focus:border-indigo-600 focus:ring-indigo-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200" />
                                        <button type="button" @click="removeDisplayFeature(activeDisplayPlan.key, index)"
                                            class="px-2 py-1 text-xs font-semibold text-stone-600 hover:text-rose-600 dark:text-neutral-400">
                                            {{ $t('super_admin.settings.plan_display.fields.remove') }}
                                        </button>
                                    </div>
                                </div>
                                <div class="mt-2">
                                    <button type="button" @click="addDisplayFeature(activeDisplayPlan.key)"
                                        class="text-xs font-semibold text-indigo-600 hover:text-indigo-700">
                                        {{ $t('super_admin.settings.plan_display.fields.add_feature') }}
                                    </button>
                                </div>
                                <InputError class="mt-1" :message="form.errors[`plan_display.${activeDisplayPlan.key}.features`]" />
                            </div>
                            <div class="flex justify-end gap-2">
                                <button type="button" @click="closeDisplayPlan"
                                    class="py-2 px-3 text-sm font-medium rounded-sm border border-stone-200 text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-700">
                                    {{ $t('super_admin.common.cancel') }}
                                </button>
                                <button type="submit" :disabled="form.processing"
                                    class="py-2 px-3 text-sm font-medium rounded-sm border border-transparent bg-indigo-600 text-white hover:bg-indigo-700 disabled:opacity-50 disabled:pointer-events-none">
                                    {{ $t('super_admin.settings.plan_display.save') }}
                                </button>
                            </div>
                        </form>
                    </div>
                </Modal>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
