<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import axios from 'axios';
import { Head, router, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import SettingsLayout from '@/Layouts/SettingsLayout.vue';
import SettingsTabs from '@/Components/SettingsTabs.vue';

const props = defineProps({
    availableMethods: {
        type: Array,
        default: () => [],
    },
    paymentMethods: {
        type: Array,
        default: () => [],
    },
    plans: {
        type: Array,
        default: () => [],
    },
    subscription: {
        type: Object,
        default: () => ({}),
    },
    checkoutStatus: {
        type: String,
        default: null,
    },
    checkoutPlanKey: {
        type: String,
        default: null,
    },
    paddle: {
        type: Object,
        default: () => ({}),
    },
});

const { t } = useI18n();

const form = useForm({
    payment_methods: Array.isArray(props.paymentMethods) ? props.paymentMethods : [],
});

const paddleUiError = ref('');
const paddleIsLoading = ref(false);
const paymentMethodIsLoading = ref(false);

const isSubscribed = computed(() => Boolean(props.subscription?.active));
const hasSubscription = computed(() => Boolean(props.subscription?.paddle_id));
const hasPlans = computed(() => props.plans.some((plan) => Boolean(plan.price_id)));
const canUsePaddle = computed(() => Boolean(props.paddle?.js_enabled && props.paddle?.api_enabled && !props.paddle?.error));

const activePlan = computed(() => {
    if (!props.subscription?.price_id) {
        return null;
    }

    return props.plans.find((plan) => plan.price_id === props.subscription.price_id) || null;
});

const planActionLabel = computed(() =>
    isSubscribed.value
        ? t('settings.billing.actions.switch_plan')
        : t('settings.billing.actions.choose_plan')
);

const checkoutPlanName = computed(() => {
    if (!props.checkoutPlanKey) {
        return null;
    }

    const plan = props.plans.find((item) => item.key === props.checkoutPlanKey);
    return plan?.name || null;
});

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

const submit = () => {
    form.put(route('settings.billing.update'), { preserveScroll: true });
};

const startPaymentMethodUpdate = async () => {
    paddleUiError.value = '';

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

const openPaddleCheckout = async (plan) => {
    paddleUiError.value = '';

    if (!canUsePaddle.value) {
        paddleUiError.value = props.paddle?.error || t('settings.billing.errors.paddle_not_configured');
        return;
    }

    paddleIsLoading.value = true;

    const ready = await ensurePaddleReady();
    if (!ready) {
        paddleIsLoading.value = false;
        paddleUiError.value = paddleUiError.value || t('settings.billing.errors.paddle_not_ready');
        return;
    }

    const successUrl = route('settings.billing.edit', { checkout: 'success', plan: plan.key });

    const options = {
        settings: {
            displayMode: 'overlay',
            successUrl,
            allowLogout: false,
        },
        items: [
            {
                priceId: plan.price_id,
                quantity: 1,
            },
        ],
        customData: {
            subscription_type: 'default',
            plan_key: plan.key,
        },
    };

    if (props.paddle?.customer_id) {
        options.customer = { id: props.paddle.customer_id };
    }

    window.Paddle.Checkout.open(options);
    paddleIsLoading.value = false;
};

const startCheckout = (plan) => {
    if (!plan?.price_id || plan.price_id === props.subscription?.price_id) {
        return;
    }

    if (isSubscribed.value) {
        router.post(route('settings.billing.swap'), { price_id: plan.price_id }, { preserveScroll: true });
        return;
    }

    openPaddleCheckout(plan);
};

onMounted(() => {
    if (props.paddle?.js_enabled) {
        ensurePaddleReady();
    }
});

watch(
    () => [props.paddle?.sandbox, props.paddle?.client_side_token, props.paddle?.seller_id, props.paddle?.retain_key, props.paddle?.customer_id],
    () => {
        if (props.paddle?.js_enabled) {
            ensurePaddleReady();
        }
    }
);
</script>

<template>
    <Head :title="$t('settings.billing.meta_title')" />

    <SettingsLayout active="billing" content-class="w-full max-w-6xl">
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
                        </div>
                    </div>

                    <div v-if="!paddle?.api_enabled" class="rounded-sm border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-700">
                        {{ $t('settings.billing.errors.paddle_api_missing') }}
                    </div>
                    <div v-else-if="!paddle?.js_enabled" class="rounded-sm border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-700">
                        {{ $t('settings.billing.errors.paddle_js_missing') }}
                    </div>
                    <div v-else-if="paddle?.error" class="rounded-sm border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
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
                        <p v-if="activePlan">
                            {{ $t('settings.billing.summary.active_plan', { plan: activePlan.name, status: subscriptionStatusLabel }) }}
                            <span v-if="subscription?.on_trial" class="text-emerald-700 dark:text-emerald-300">
                                {{ $t('settings.billing.summary.trialing') }}
                            </span>
                        </p>
                        <p v-else>
                            {{ $t('settings.billing.summary.no_subscription') }}
                        </p>
                    </div>

                    <div v-if="!hasPlans"
                        class="rounded-sm border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                        {{ $t('settings.billing.errors.no_plans_configured') }}
                    </div>

                    <div class="billing-plans">
                        <div class="billing-plans__grid">
                            <div v-for="plan in plans" :key="plan.key" class="plan-card"
                                :data-active="plan.price_id === subscription?.price_id">
                                <div class="plan-card__top">
                                    <div>
                                        <h3 class="plan-card__name">{{ plan.name }}</h3>
                                        <p class="plan-card__meta">{{ $t('settings.billing.plan.monthly') }}</p>
                                    </div>
                                    <span v-if="subscription?.price_id === plan.price_id"
                                        class="plan-card__badge plan-card__badge--active">
                                        {{ $t('settings.billing.plan.badge_active') }}
                                    </span>
                                    <span v-else-if="plan.key === 'growth'" class="plan-card__badge">
                                        {{ $t('settings.billing.plan.badge_popular') }}
                                    </span>
                                </div>
                                <div class="plan-card__price">
                                    <span class="plan-card__amount">{{ plan.display_price || '--' }}</span>
                                    <span class="plan-card__interval">{{ $t('settings.billing.plan.interval_month') }}</span>
                                </div>
                                <p v-if="subscription?.active && plan.price_id === subscription?.price_id"
                                    class="plan-card__status">
                                    {{ $t('settings.billing.plan.current_plan') }}
                                </p>
                                <ul class="plan-card__features">
                                    <li v-for="feature in plan.features" :key="feature" class="plan-card__feature">
                                        {{ feature }}
                                    </li>
                                </ul>
                                <button type="button" @click="startCheckout(plan)" class="plan-card__cta"
                                    :disabled="paddleIsLoading || !plan.price_id || plan.price_id === subscription?.price_id"
                                    :class="{ 'is-active': plan.price_id === subscription?.price_id }">
                                    <span v-if="plan.price_id === subscription?.price_id">{{ $t('settings.billing.plan.cta_active') }}</span>
                                    <span v-else>{{ planActionLabel }}</span>
                                </button>
                            </div>
                        </div>
                    </div>
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

                    <div v-if="!paddle?.api_enabled" class="rounded-sm border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-700">
                        {{ $t('settings.billing.errors.paddle_api_missing') }}
                    </div>
                    <div v-else-if="!paddle?.js_enabled" class="rounded-sm border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-700">
                        {{ $t('settings.billing.errors.paddle_js_missing') }}
                    </div>
                    <div v-else-if="paddle?.error" class="rounded-sm border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                        {{ paddle.error }}
                    </div>
                    <div v-if="paddleUiError" class="rounded-sm border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                        {{ paddleUiError }}
                    </div>

                    <div v-if="checkoutStatus === 'payment-method'"
                        class="rounded-sm border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-700">
                        {{ $t('settings.billing.checkout.payment_method') }}
                    </div>

                    <div
                        class="rounded-sm border border-stone-100 bg-stone-50 px-3 py-2 text-sm text-stone-600 dark:border-neutral-700 dark:bg-neutral-900/40 dark:text-neutral-200">
                        <p v-if="activePlan">
                            {{ $t('settings.billing.payment.summary_active', { plan: activePlan.name, status: subscriptionStatusLabel }) }}
                        </p>
                        <p v-else>
                            {{ $t('settings.billing.payment.summary_none') }}
                        </p>
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

.billing-plans__grid {
    display: grid;
    gap: 16px;
}

@media (min-width: 768px) {
    .billing-plans__grid {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }
}

.plan-card {
    position: relative;
    display: flex;
    flex-direction: column;
    gap: 14px;
    padding: 20px;
    border-radius: 2px;
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

.plan-card__interval {
    font-size: 0.9rem;
    color: var(--plan-muted);
}

.plan-card__status {
    font-size: 0.78rem;
    color: var(--plan-status);
}

.plan-card__features {
    display: grid;
    gap: 8px;
    margin: 0;
    padding: 0;
    list-style: none;
    color: rgba(226, 232, 240, 0.85);
    font-size: 0.8rem;
}

.plan-card__feature {
    position: relative;
    padding-left: 18px;
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

.plan-card__cta {
    width: 100%;
    border-radius: 2px;
    border: 1px solid var(--plan-cta-border);
    background: var(--plan-cta-bg);
    color: var(--plan-cta-text);
    padding: 10px 14px;
    font-size: 0.85rem;
    font-weight: 600;
    transition: transform 150ms ease, border-color 150ms ease, background 150ms ease;
}

.plan-card__cta:hover:not(:disabled) {
    transform: translateY(-1px);
    border-color: var(--plan-cta-hover-border);
    background: var(--plan-cta-hover-bg);
}

.plan-card__cta:disabled,
.plan-card__cta.is-active {
    cursor: not-allowed;
    color: var(--plan-cta-disabled);
    background: var(--plan-cta-disabled-bg);
    border-color: var(--plan-cta-disabled-border);
}
</style>
