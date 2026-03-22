<script setup>
import { computed } from 'vue';
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
const plans = computed(() => (Array.isArray(props.pricingPlans) ? props.pricingPlans : []));
const highlightedKey = computed(() => props.highlightedPlanKey || plans.value[2]?.key || plans.value[1]?.key || plans.value[0]?.key || null);
const comparisonSections = computed(() => (Array.isArray(props.comparisonSections) ? props.comparisonSections : []));

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
const resolvePrice = (plan) => plan?.display_price || plan?.price || '--';
const resolveFeatures = (plan) => (Array.isArray(plan?.features) ? plan.features.filter((feature) => !!feature) : []);
const isIncludedCell = (value) => value?.type === 'included';
const isExcludedCell = (value) => value?.type === 'excluded';
</script>

<template>
    <Head :title="$t('pricing.meta.title')" />

    <div class="public-pricing-page">
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
                            {{ $t('pricing.hero.eyebrow') }}
                        </div>
                        <h1 class="public-pricing-title text-4xl font-semibold tracking-tight sm:text-5xl">
                            {{ $t('pricing.hero.title') }}
                        </h1>
                        <p class="text-base text-stone-600 sm:text-lg">
                            {{ $t('pricing.hero.subtitle') }}
                        </p>
                        <p class="text-sm text-stone-500">
                            {{ $t('pricing.hero.note') }}
                        </p>
                    </div>

                    <div class="mt-10 grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-5">
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
                                    {{ plan.badge || $t('pricing.plans.pro.badge') }}
                                </span>
                            </div>
                            <div class="mt-4 text-2xl font-semibold text-stone-900">
                                {{ resolvePrice(plan) }}
                            </div>
                            <ul v-if="resolveFeatures(plan).length" class="mt-4 space-y-2 text-sm text-stone-600">
                                <li v-for="feature in resolveFeatures(plan).slice(0, 4)" :key="feature">
                                    {{ feature }}
                                </li>
                            </ul>
                            <p v-else class="mt-4 text-sm text-stone-500">
                                {{ $t('pricing.hero.note') }}
                            </p>
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
                                {{ $t('pricing.features.title') }}
                            </div>
                            <ul class="mt-4 grid gap-3 text-sm text-stone-600 sm:grid-cols-2">
                                <li class="public-pricing-bullet">{{ $t('pricing.features.items.one') }}</li>
                                <li class="public-pricing-bullet">{{ $t('pricing.features.items.two') }}</li>
                                <li class="public-pricing-bullet">{{ $t('pricing.features.items.three') }}</li>
                                <li class="public-pricing-bullet">{{ $t('pricing.features.items.four') }}</li>
                                <li class="public-pricing-bullet">{{ $t('pricing.features.items.five') }}</li>
                            </ul>
                        </article>

                        <article class="public-pricing-surface public-pricing-surface--tinted">
                            <div class="text-sm font-semibold text-stone-900">
                                {{ $t('pricing.enterprise.title') }}
                            </div>
                            <p class="mt-3 text-sm text-stone-600">{{ $t('pricing.enterprise.body') }}</p>
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
@import url('https://fonts.bunny.net/css?family=Space+Grotesk:400,500,600,700&family=Work+Sans:400,500,600,700&display=swap');

.public-pricing-page {
    min-height: 100vh;
    background: #ffffff;
    color: #0f172a;
    font-family: 'Work Sans', 'Figtree', sans-serif;
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
    font-family: 'Space Grotesk', 'Figtree', sans-serif;
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

.public-pricing-card,
.public-pricing-surface {
    border: 1px solid #e2e8f0;
    border-radius: 0.125rem;
    background: #ffffff;
    box-shadow: 0 24px 45px -38px rgba(15, 23, 42, 0.22);
}

.public-pricing-card {
    padding: 1.2rem;
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
