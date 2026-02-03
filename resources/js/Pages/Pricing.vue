<script setup>
import { computed } from 'vue';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import { Head, Link } from '@inertiajs/vue3';

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
});

const plans = computed(() => (Array.isArray(props.pricingPlans) ? props.pricingPlans : []));
const highlightedKey = computed(() => props.highlightedPlanKey || plans.value[1]?.key || plans.value[0]?.key || null);

const isHighlighted = (plan) => Boolean(plan?.key && plan.key === highlightedKey.value);
const resolvePrice = (plan) => plan?.display_price || plan?.price || '--';
const resolveFeatures = (plan) => (Array.isArray(plan?.features) ? plan.features.filter((feature) => !!feature) : []);
</script>

<template>
    <Head :title="$t('pricing.meta.title')" />

    <div class="min-h-screen bg-stone-50 text-stone-900 dark:bg-neutral-900 dark:text-neutral-100">
        <header class="mx-auto flex w-full max-w-6xl items-center justify-between px-4 py-6">
            <Link :href="route('welcome')" class="flex items-center gap-3">
                <ApplicationLogo class="h-8 w-28 sm:h-10 sm:w-32" />
                <div class="leading-tight">
                    <div class="text-sm font-semibold">MLK Pro</div>
                    <div class="text-xs text-stone-500 dark:text-neutral-400">{{ $t('legal.header.tagline') }}</div>
                </div>
            </Link>

            <div class="flex items-center gap-2">
                <Link v-if="canLogin" :href="route('login')"
                    class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm font-medium text-stone-800 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800">
                    {{ $t('legal.actions.sign_in') }}
                </Link>
                <Link v-if="canRegister" :href="route('onboarding.index')"
                    class="rounded-sm border border-transparent bg-green-600 px-3 py-2 text-sm font-medium text-white hover:bg-green-700">
                    {{ $t('legal.actions.create_account') }}
                </Link>
            </div>
        </header>

        <main class="mx-auto w-full max-w-6xl px-4 pb-16 pt-8">
            <section class="rounded-sm border border-stone-200 bg-white p-6 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                <div class="space-y-6">
                    <div class="space-y-2 text-center">
                        <div class="text-xs uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                            {{ $t('pricing.hero.eyebrow') }}
                        </div>
                        <h1 class="text-2xl font-semibold tracking-tight text-stone-900 dark:text-neutral-100 sm:text-3xl">
                            {{ $t('pricing.hero.title') }}
                        </h1>
                        <p class="text-sm text-stone-600 dark:text-neutral-300">
                            {{ $t('pricing.hero.subtitle') }}
                        </p>
                        <p class="text-xs text-stone-500 dark:text-neutral-400">
                            {{ $t('pricing.hero.note') }}
                        </p>
                    </div>

                    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                        <div v-for="plan in plans" :key="plan.key"
                            :class="[
                                'rounded-sm border p-4 text-sm',
                                isHighlighted(plan)
                                    ? 'border-emerald-200 bg-emerald-50 text-stone-700 dark:border-emerald-900/40 dark:bg-neutral-900 dark:text-neutral-200'
                                    : 'border-stone-200 bg-stone-50 text-stone-700 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200'
                            ]">
                            <div class="flex items-center justify-between">
                                <div
                                    :class="[
                                        'text-xs uppercase tracking-wide',
                                        isHighlighted(plan)
                                            ? 'text-emerald-700 dark:text-emerald-300'
                                            : 'text-stone-500 dark:text-neutral-400'
                                    ]">
                                    {{ plan.name }}
                                </div>
                                <span v-if="plan.badge || isHighlighted(plan)"
                                    :class="[
                                        'rounded-sm px-2 py-0.5 text-[10px] font-semibold uppercase',
                                        isHighlighted(plan)
                                            ? 'bg-emerald-600 text-white'
                                            : 'bg-stone-200 text-stone-700 dark:bg-neutral-700 dark:text-neutral-200'
                                    ]">
                                    {{ plan.badge || $t('pricing.plans.pro.badge') }}
                                </span>
                            </div>
                            <div class="mt-2 text-xl font-semibold text-stone-900 dark:text-neutral-100">
                                {{ resolvePrice(plan) }}
                            </div>
                            <ul v-if="resolveFeatures(plan).length" class="mt-3 space-y-1 text-xs text-stone-600 dark:text-neutral-300">
                                <li v-for="feature in resolveFeatures(plan).slice(0, 4)" :key="feature">
                                    {{ feature }}
                                </li>
                            </ul>
                            <p v-else class="mt-2 text-sm text-stone-600 dark:text-neutral-300">
                                {{ $t('pricing.hero.note') }}
                            </p>
                        </div>
                    </div>

                    <div class="rounded-sm border border-stone-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900">
                        <div class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                            {{ $t('pricing.features.title') }}
                        </div>
                        <ul class="mt-3 grid gap-2 text-sm text-stone-600 dark:text-neutral-300 sm:grid-cols-2">
                            <li>{{ $t('pricing.features.items.one') }}</li>
                            <li>{{ $t('pricing.features.items.two') }}</li>
                            <li>{{ $t('pricing.features.items.three') }}</li>
                            <li>{{ $t('pricing.features.items.four') }}</li>
                            <li>{{ $t('pricing.features.items.five') }}</li>
                        </ul>
                    </div>

                    <div class="rounded-sm border border-stone-200 bg-stone-50 p-4 text-sm text-stone-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300">
                        <div class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                            {{ $t('pricing.enterprise.title') }}
                        </div>
                        <p class="mt-2">{{ $t('pricing.enterprise.body') }}</p>
                    </div>

                    <div class="flex flex-wrap justify-center gap-2">
                        <Link v-if="canRegister" :href="route('onboarding.index')"
                            class="rounded-sm border border-transparent bg-green-600 px-4 py-2 text-sm font-semibold text-white hover:bg-green-700">
                            {{ $t('pricing.actions.primary') }}
                        </Link>
                        <Link v-if="canLogin" :href="route('login')"
                            class="rounded-sm border border-stone-200 bg-white px-4 py-2 text-sm font-semibold text-stone-800 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800">
                            {{ $t('pricing.actions.secondary') }}
                        </Link>
                    </div>
                </div>
            </section>
        </main>

        <footer class="border-t border-stone-200 dark:border-neutral-800">
            <div class="mx-auto w-full max-w-6xl px-4 py-6 text-xs text-stone-500 dark:text-neutral-400">
                <div class="flex flex-wrap items-center justify-center gap-4">
                    <Link :href="route('terms')" class="hover:text-stone-900 dark:hover:text-neutral-100">
                        {{ $t('legal.links.terms') }}
                    </Link>
                    <Link :href="route('privacy')" class="hover:text-stone-900 dark:hover:text-neutral-100">
                        {{ $t('legal.links.privacy') }}
                    </Link>
                    <Link :href="route('refund')" class="hover:text-stone-900 dark:hover:text-neutral-100">
                        {{ $t('legal.links.refund') }}
                    </Link>
                    <Link :href="route('pricing')" class="hover:text-stone-900 dark:hover:text-neutral-100">
                        {{ $t('legal.links.pricing') }}
                    </Link>
                </div>
                <div class="mt-2 text-center">
                    {{ $t('legal.footer', { year: new Date().getFullYear() }) }}
                </div>
            </div>
        </footer>
    </div>
</template>
