<script setup>
import { computed, ref, watchEffect } from 'vue';
import PlanPriceDisplay from '@/Components/Billing/PlanPriceDisplay.vue';
import {
    displayIntervalKeyForBillingPeriod,
    hasActiveSubscriptionPromotion,
    planPricingForBillingDisplay,
} from '@/utils/subscriptionPricing';
import PublicFooterMenu from '@/Components/Public/PublicFooterMenu.vue';
import PublicSiteHeader from '@/Components/Public/PublicSiteHeader.vue';
import { Head, Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    canLogin: {
        type: Boolean,
        default: true,
    },
    canRegister: {
        type: Boolean,
        default: true,
    },
    pricingCatalogs: {
        type: Object,
        default: () => ({}),
    },
    defaultAudience: {
        type: String,
        default: null,
    },
    pricingPlans: {
        type: Array,
        default: () => [],
    },
    highlightedPlanKey: {
        type: String,
        default: null,
    },
    comparisonSections: {
        type: Array,
        default: () => [],
    },
    megaMenu: {
        type: Object,
        default: () => ({}),
    },
    footerMenu: {
        type: Object,
        default: () => ({}),
    },
    footerSection: {
        type: Object,
        default: () => ({}),
    },
});

const { t } = useI18n();
const fallbackCatalog = computed(() => ({
    team: {
        plans: Array.isArray(props.pricingPlans) ? props.pricingPlans : [],
        highlightedPlanKey: props.highlightedPlanKey,
        comparisonSections: Array.isArray(props.comparisonSections) ? props.comparisonSections : [],
    },
}));
const pricingCatalogs = computed(() => {
    if (props.pricingCatalogs && Object.keys(props.pricingCatalogs).length > 0) {
        return props.pricingCatalogs;
    }

    return fallbackCatalog.value;
});
const audienceOrder = ['solo', 'team'];
const audienceKeys = computed(() => {
    const keys = Object.keys(pricingCatalogs.value);

    return audienceOrder.filter((key) => keys.includes(key)).concat(
        keys.filter((key) => !audienceOrder.includes(key))
    );
});
const activeAudience = ref(props.defaultAudience || audienceKeys.value[0] || 'team');
const activeBillingPeriod = ref('monthly');

watchEffect(() => {
    if (! audienceKeys.value.includes(activeAudience.value)) {
        activeAudience.value = props.defaultAudience && audienceKeys.value.includes(props.defaultAudience)
            ? props.defaultAudience
            : (audienceKeys.value[0] || 'team');
    }
});

const activeCatalog = computed(() => pricingCatalogs.value[activeAudience.value]
    || pricingCatalogs.value[audienceKeys.value[0]]
    || {
        plans: [],
        highlightedPlanKey: null,
        comparisonSections: [],
    });
const plans = computed(() => (Array.isArray(activeCatalog.value?.plans) ? activeCatalog.value.plans : []));
const highlightedKey = computed(() => activeCatalog.value?.highlightedPlanKey || props.highlightedPlanKey || plans.value[2]?.key || plans.value[1]?.key || plans.value[0]?.key || null);
const comparisonSections = computed(() => (Array.isArray(activeCatalog.value?.comparisonSections) ? activeCatalog.value.comparisonSections : []));
const heroBaseKey = computed(() => `pricing.hero.${activeAudience.value}`);
const featuresBaseKey = computed(() => `pricing.features.${activeAudience.value}`);
const supportBaseKey = computed(() => `pricing.enterprise.${activeAudience.value}`);
const featureItems = computed(() => ['one', 'two', 'three', 'four', 'five']
    .map((key) => t(`${featuresBaseKey.value}.items.${key}`))
    .filter((value) => !!value && !value.startsWith('pricing.')));
const planGridClass = computed(() => {
    if (plans.value.length <= 3) {
        return 'md:grid-cols-3';
    }

    if (plans.value.length === 4) {
        return 'md:grid-cols-2 xl:grid-cols-4';
    }

    return 'md:grid-cols-2 xl:grid-cols-5';
});

const headerMenuItems = computed(() => ([
    {
        label: t('public_pages.actions.home'),
        resolved_href: route('welcome'),
        link_target: '_self',
        panel_type: 'link',
    },
    {
        label: t('legal.links.pricing'),
        resolved_href: route('pricing'),
        link_target: '_self',
        panel_type: 'link',
    },
]));

const isHighlighted = (plan) => Boolean(plan?.key && plan.key === highlightedKey.value);
const resolvePricingOption = (plan, billingPeriod = activeBillingPeriod.value) =>
    plan?.prices_by_period?.[billingPeriod]
    || plan?.prices_by_period?.monthly
    || null;
const displayedPricing = (plan, billingPeriod = activeBillingPeriod.value) => planPricingForBillingDisplay(
    plan,
    billingPeriod,
    resolvePricingOption(plan, billingPeriod) || {
        display_price: plan?.display_price || null,
        original_display_price: plan?.original_display_price || plan?.display_price || null,
        discounted_display_price: plan?.discounted_display_price || plan?.display_price || null,
        is_discounted: Boolean(plan?.is_discounted),
        promotion: plan?.promotion || { is_active: false, discount_percent: null },
    }
);
const yearlyPromotionActive = computed(() =>
    plans.value.some((plan) => hasActiveSubscriptionPromotion(displayedPricing(plan, 'yearly')))
);
const hasCatalogYearlyDiscount = (plan) => Number(plan?.annual_discount_percent ?? 0) > 0;
const resolvePriceInterval = (plan) => {
    if (plan?.contact_only) {
        return null;
    }

    return t(displayIntervalKeyForBillingPeriod(
        activeBillingPeriod.value,
        'pricing.billing_cycle.interval_month'
    ));
};
const resolveFeatures = (plan) => (Array.isArray(plan?.features) ? plan.features.filter((feature) => !!feature) : []);
const isIncludedCell = (value) => value?.type === 'included';
const isExcludedCell = (value) => value?.type === 'excluded';
const showTrialCta = (plan) => Boolean(props.canRegister && plan?.onboarding_enabled && !plan?.contact_only);
const resolveTrialHref = (plan) => {
    if (!plan?.key) {
        return route('onboarding.index');
    }

    const query = { plan: plan.key };
    if ((plan?.audience || activeAudience.value) === 'team') {
        query.team_size = 2;
    }

    query.billing_period = activeBillingPeriod.value;

    return route('onboarding.index', query);
};
</script>

<template>
    <Head :title="$t('pricing.meta.title')" />

    <div class="public-pricing-page front-public-page">
        <PublicSiteHeader
            :mega-menu="megaMenu"
            :fallback-items="headerMenuItems"
            :can-login="canLogin"
            :can-register="canRegister"
        />

        <main>
            <section class="public-pricing-hero">
                <div class="public-pricing-container">
                    <div class="mx-auto max-w-3xl space-y-4 text-center">
                        <div class="public-pricing-kicker">
                            {{ $t(`${heroBaseKey}.eyebrow`) }}
                        </div>
                        <h1 class="public-pricing-title text-4xl font-semibold tracking-tight sm:text-5xl">
                            {{ $t(`${heroBaseKey}.title`) }}
                        </h1>
                        <p class="text-base text-stone-600 sm:text-lg">
                            {{ $t(`${heroBaseKey}.subtitle`) }}
                        </p>
                        <p class="text-sm text-stone-500">
                            {{ $t(`${heroBaseKey}.note`) }}
                        </p>

                        <div v-if="audienceKeys.length > 1" class="public-pricing-audience-switch">
                            <span class="public-pricing-audience-switch__label">
                                {{ $t('pricing.switch.label') }}
                            </span>
                            <div class="public-pricing-audience-switch__buttons">
                                <button
                                    v-for="audienceKey in audienceKeys"
                                    :key="audienceKey"
                                    type="button"
                                    :class="[
                                        'public-pricing-audience-switch__button',
                                        activeAudience === audienceKey ? 'public-pricing-audience-switch__button--active' : ''
                                    ]"
                                    @click="activeAudience = audienceKey"
                                >
                                    {{ $t(`pricing.audiences.${audienceKey}`) }}
                                </button>
                            </div>
                        </div>

                        <div class="public-pricing-cycle-switch">
                            <span class="public-pricing-cycle-switch__label">
                                {{ $t('pricing.billing_cycle.label') }}
                            </span>
                            <div class="public-pricing-cycle-switch__buttons">
                                <button
                                    type="button"
                                    :class="[
                                        'public-pricing-cycle-switch__button',
                                        activeBillingPeriod === 'monthly' ? 'public-pricing-cycle-switch__button--active' : ''
                                    ]"
                                    @click="activeBillingPeriod = 'monthly'"
                                >
                                    {{ $t('pricing.billing_cycle.monthly') }}
                                </button>
                                <button
                                    type="button"
                                    :class="[
                                        'public-pricing-cycle-switch__button',
                                        activeBillingPeriod === 'yearly' ? 'public-pricing-cycle-switch__button--active' : ''
                                    ]"
                                    @click="activeBillingPeriod = 'yearly'"
                                >
                                    {{ $t('pricing.billing_cycle.yearly') }}
                                </button>
                            </div>
                            <p v-if="activeBillingPeriod === 'yearly'" class="public-pricing-cycle-switch__note">
                                {{ yearlyPromotionActive
                                    ? $t('pricing.billing_cycle.billed_yearly')
                                    : (hasCatalogYearlyDiscount(plans[0])
                                        ? $t('pricing.billing_cycle.save_badge', { percent: plans[0]?.annual_discount_percent ?? 0 })
                                        : $t('pricing.billing_cycle.billed_yearly')) }}
                            </p>
                        </div>
                    </div>

                    <div :class="['mt-10 grid grid-cols-1 gap-4', planGridClass]">
                        <article v-for="plan in plans" :key="plan.key"
                            :class="[
                                'public-pricing-card',
                                isHighlighted(plan) ? 'public-pricing-card--highlighted' : ''
                            ]">
                            <div class="flex items-center justify-between gap-3">
                                <div
                                    :class="[
                                        'text-xs uppercase tracking-[0.18em]',
                                        isHighlighted(plan) ? 'text-emerald-700' : 'text-stone-500'
                                    ]">
                                    {{ plan.name }}
                                </div>
                                <span v-if="plan.badge || isHighlighted(plan)"
                                    :class="[
                                        'rounded-sm px-2 py-1 text-[10px] font-semibold uppercase tracking-[0.14em]',
                                        isHighlighted(plan) ? 'bg-emerald-600 text-white' : 'bg-stone-100 text-stone-600'
                                    ]">
                                    {{ plan.badge || $t('pricing.badges.recommended') }}
                                </span>
                            </div>
                            <div class="mt-4">
                                <PlanPriceDisplay
                                    :pricing="displayedPricing(plan)"
                                    :contact-only="plan.contact_only"
                                    :interval-label="resolvePriceInterval(plan)"
                                    price-class="text-2xl font-semibold text-stone-900"
                                    original-price-class="text-sm font-medium text-stone-400 line-through"
                                    interval-class="text-sm font-medium text-stone-500"
                                    badge-class="rounded-sm bg-emerald-100 px-2 py-1 text-[10px] font-semibold uppercase tracking-[0.14em] text-emerald-700"
                                />
                                <p v-if="activeBillingPeriod === 'yearly' && !plan.contact_only" class="mt-1 text-xs font-medium text-emerald-700">
                                    {{ hasActiveSubscriptionPromotion(displayedPricing(plan))
                                        ? $t('pricing.billing_cycle.billed_yearly')
                                        : (hasCatalogYearlyDiscount(plan)
                                            ? $t('pricing.billing_cycle.yearly_note', { percent: plan.annual_discount_percent ?? 0 })
                                            : $t('pricing.billing_cycle.billed_yearly')) }}
                                </p>
                            </div>
                            <ul v-if="resolveFeatures(plan).length" class="mt-4 space-y-2 text-sm text-stone-600">
                                <li v-for="feature in resolveFeatures(plan).slice(0, 4)" :key="feature">
                                    {{ feature }}
                                </li>
                            </ul>
                            <p v-else class="mt-4 text-sm text-stone-500">
                                {{ $t(`${heroBaseKey}.note`) }}
                            </p>
                            <div v-if="showTrialCta(plan)" class="mt-auto pt-5 space-y-2">
                                <Link :href="resolveTrialHref(plan)"
                                    class="inline-flex w-full items-center justify-center rounded-sm border border-transparent bg-green-600 px-4 py-2 text-sm font-semibold text-white hover:bg-green-700">
                                    {{ $t('pricing.actions.plan_trial') }}
                                </Link>
                                <p class="text-xs text-stone-500">
                                    {{ $t('pricing.actions.plan_trial_note') }}
                                </p>
                            </div>
                        </article>
                    </div>
                </div>
            </section>

            <section class="public-pricing-section public-pricing-section--comparison">
                <div class="public-pricing-container">
                    <article class="public-pricing-surface">
                        <div class="space-y-1">
                            <h2 class="text-xl font-semibold text-stone-900">
                                {{ $t('pricing.comparison.title') }}
                            </h2>
                            <p class="text-sm text-stone-600">
                                {{ $t('pricing.comparison.subtitle') }}
                            </p>
                        </div>

                        <div class="mt-5 overflow-x-auto">
                            <table class="min-w-full border-separate border-spacing-0 text-sm">
                                <thead>
                                    <tr>
                                        <th class="public-pricing-table__feature">
                                            {{ $t('pricing.comparison.columns.feature') }}
                                        </th>
                                        <th v-for="plan in plans" :key="`comparison-${plan.key}`"
                                            class="public-pricing-table__plan">
                                            {{ plan.name }}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template v-for="section in comparisonSections" :key="section.label">
                                        <tr>
                                            <td :colspan="plans.length + 1" class="public-pricing-table__section">
                                                {{ section.label }}
                                            </td>
                                        </tr>
                                        <tr v-for="row in section.rows" :key="`${section.label}-${row.label}`">
                                            <td class="public-pricing-table__label">
                                                {{ row.label }}
                                            </td>
                                            <td v-for="value in row.values" :key="`${row.label}-${value.plan_key}`"
                                                class="public-pricing-table__value">
                                                <span v-if="isIncludedCell(value)" class="text-lg font-semibold text-emerald-600">
                                                    ✓
                                                </span>
                                                <span v-else-if="isExcludedCell(value)" class="text-lg text-stone-300">
                                                    -
                                                </span>
                                                <span v-else class="font-medium text-stone-700">
                                                    {{ value.text || '--' }}
                                                </span>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                    </article>
                </div>
            </section>

            <section class="public-pricing-section public-pricing-section--support">
                <div class="public-pricing-container">
                    <div class="grid grid-cols-1 gap-6 lg:grid-cols-[1.4fr_0.9fr]">
                        <article class="public-pricing-surface">
                            <div class="text-sm font-semibold text-stone-900">
                                {{ $t(`${featuresBaseKey}.title`) }}
                            </div>
                            <ul class="mt-4 grid gap-3 text-sm text-stone-600 sm:grid-cols-2">
                                <li v-for="item in featureItems" :key="item" class="public-pricing-bullet">
                                    {{ item }}
                                </li>
                            </ul>
                        </article>

                        <article class="public-pricing-surface public-pricing-surface--tinted">
                            <div class="text-sm font-semibold text-stone-900">
                                {{ $t(`${supportBaseKey}.title`) }}
                            </div>
                            <p class="mt-3 text-sm text-stone-600">{{ $t(`${supportBaseKey}.body`) }}</p>
                            <div class="mt-5 flex flex-wrap gap-2">
                                <Link v-if="canRegister" :href="route('onboarding.index')"
                                    class="rounded-sm border border-transparent bg-green-600 px-4 py-2 text-sm font-semibold text-white hover:bg-green-700">
                                    {{ $t('pricing.actions.primary') }}
                                </Link>
                                <Link v-if="canLogin" :href="route('login')"
                                    class="rounded-sm border border-stone-200 bg-white px-4 py-2 text-sm font-semibold text-stone-800 hover:bg-stone-50">
                                    {{ $t('pricing.actions.secondary') }}
                                </Link>
                            </div>
                        </article>
                    </div>
                </div>
            </section>
        </main>

        <PublicFooterMenu :menu="footerMenu" :section="footerSection" />
    </div>
</template>

<style scoped>
.public-pricing-page {
    min-height: 100vh;
    background: #ffffff;
    color: #0f172a;
    font-family: var(--page-font-body);
}

.public-pricing-container {
    width: min(88rem, 100%);
    margin-inline: auto;
    padding-inline: 1.25rem;
}

.public-pricing-hero {
    padding-block: clamp(3.5rem, 8vw, 7.5rem);
    background: #f8fafc;
}

.public-pricing-section {
    padding-bottom: clamp(3.5rem, 8vw, 6rem);
}

.public-pricing-section--comparison {
    padding-top: clamp(2rem, 4vw, 3rem);
}

.public-pricing-section--support {
    padding-top: 0;
}

.public-pricing-title {
    font-family: var(--page-font-heading);
    letter-spacing: -0.02em;
}

.public-pricing-kicker {
    display: inline-flex;
    align-items: center;
    padding: 0.35rem 0.75rem;
    border-radius: 999px;
    background: rgba(16, 185, 129, 0.12);
    color: #065f46;
    font-size: 0.75rem;
    font-weight: 600;
}

.public-pricing-audience-switch {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.75rem;
}

.public-pricing-cycle-switch {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.75rem;
}

.public-pricing-audience-switch__label {
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.14em;
    color: #64748b;
}

.public-pricing-audience-switch__buttons {
    display: inline-flex;
    gap: 0.35rem;
    padding: 0.3rem;
    border: 1px solid #dfe7ef;
    border-radius: 0.125rem;
    background: rgba(255, 255, 255, 0.92);
}

.public-pricing-audience-switch__button {
    border: none;
    border-radius: 0.125rem;
    background: transparent;
    color: #475569;
    padding: 0.55rem 1rem;
    font-size: 0.875rem;
    font-weight: 600;
    transition: background-color 0.2s ease, color 0.2s ease, box-shadow 0.2s ease;
}

.public-pricing-audience-switch__button--active {
    background: #16a34a;
    color: #ffffff;
    box-shadow: 0 12px 30px -20px rgba(22, 163, 74, 0.8);
}

.public-pricing-cycle-switch__label {
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.14em;
    color: #64748b;
}

.public-pricing-cycle-switch__buttons {
    display: inline-flex;
    gap: 0.35rem;
    padding: 0.3rem;
    border: 1px solid #dfe7ef;
    border-radius: 0.125rem;
    background: rgba(255, 255, 255, 0.92);
}

.public-pricing-cycle-switch__button {
    border: none;
    border-radius: 0.125rem;
    background: transparent;
    color: #475569;
    padding: 0.55rem 1rem;
    font-size: 0.875rem;
    font-weight: 600;
    transition: background-color 0.2s ease, color 0.2s ease, box-shadow 0.2s ease;
}

.public-pricing-cycle-switch__button--active {
    background: #16a34a;
    color: #ffffff;
    box-shadow: 0 12px 30px -20px rgba(22, 163, 74, 0.8);
}

.public-pricing-cycle-switch__note {
    font-size: 0.8rem;
    font-weight: 600;
    color: #047857;
}

.public-pricing-card,
.public-pricing-surface {
    border: 1px solid #e2e8f0;
    border-radius: 0.125rem;
    background: #ffffff;
    box-shadow: 0 24px 45px -38px rgba(15, 23, 42, 0.22);
}

.public-pricing-card {
    padding: 1.2rem;
    display: flex;
    flex-direction: column;
    height: 100%;
}

.public-pricing-card--highlighted {
    border-color: #a7f3d0;
    background: #f0fdf4;
}

.public-pricing-surface {
    padding: 1.5rem;
}

.public-pricing-surface--tinted {
    background: #f8fafc;
}

.public-pricing-table__feature,
.public-pricing-table__plan,
.public-pricing-table__label,
.public-pricing-table__value,
.public-pricing-table__section {
    padding: 0.9rem 1rem;
}

.public-pricing-table__feature {
    position: sticky;
    left: 0;
    z-index: 10;
    min-width: 16rem;
    border-bottom: 1px solid #cbd5e1;
    background: #ffffff;
    text-align: left;
    font-weight: 600;
    color: #334155;
}

.public-pricing-table__plan {
    min-width: 8.75rem;
    border-bottom: 1px solid #cbd5e1;
    background: #ffffff;
    text-align: center;
    font-size: 1rem;
    font-weight: 600;
    color: #0f172a;
}

.public-pricing-table__section {
    border-bottom: 1px solid #e2e8f0;
    background: #f8fafc;
    text-align: left;
    font-size: 0.95rem;
    font-weight: 600;
    color: #0f172a;
}

.public-pricing-table__label {
    position: sticky;
    left: 0;
    z-index: 10;
    border-bottom: 1px solid #f1f5f9;
    background: #ffffff;
    font-weight: 500;
    color: #334155;
}

.public-pricing-table__value {
    border-bottom: 1px solid #f1f5f9;
    text-align: center;
    color: #475569;
}

.public-pricing-bullet {
    position: relative;
    padding-left: 1.1rem;
}

.public-pricing-bullet::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0.42rem;
    width: 0.42rem;
    height: 0.42rem;
    border-radius: 999px;
    background: #16a34a;
}

</style>
