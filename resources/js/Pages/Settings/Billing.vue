<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import axios from 'axios';
import { Head, router, useForm } from '@inertiajs/vue3';
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

const planActionLabel = computed(() => (isSubscribed.value ? 'Changer pour ce plan' : 'Choisir ce plan'));

const checkoutPlanName = computed(() => {
    if (!props.checkoutPlanKey) {
        return null;
    }

    const plan = props.plans.find((item) => item.key === props.checkoutPlanKey);
    return plan?.name || null;
});

const subscriptionStatusLabel = computed(() => {
    if (props.subscription?.status) {
        return props.subscription.status;
    }

    if (props.subscription?.active) {
        return 'active';
    }

    return 'inactif';
});

const tabPrefix = 'settings-billing';
const tabs = [
    { id: 'plans', label: 'Abonnement', description: 'Plans et statut' },
    { id: 'payment', label: 'Paiement', description: 'Carte et factures' },
];

const resolveInitialTab = () => {
    if (typeof window === 'undefined') {
        return tabs[0].id;
    }
    const stored = window.sessionStorage.getItem(`${tabPrefix}-tab`);
    return tabs.some((tab) => tab.id === stored) ? stored : tabs[0].id;
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
        paddleUiError.value = props.paddle?.error || "Paddle n'est pas configure.";
        return;
    }

    if (!hasSubscription.value) {
        paddleUiError.value = 'Aucun abonnement actif.';
        return;
    }

    paymentMethodIsLoading.value = true;

    const ready = await ensurePaddleReady();
    if (!ready) {
        paymentMethodIsLoading.value = false;
        paddleUiError.value = paddleUiError.value || "Paddle.js n'est pas pret. Rechargez la page.";
        return;
    }

    try {
        const response = await axios.post(route('settings.billing.payment-method'));
        const transactionId = response?.data?.transaction_id;

        if (!transactionId) {
            throw new Error('Missing transaction id');
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
        const message = error?.response?.data?.message || 'Impossible de lancer la mise a jour du paiement.';
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
        paddleUiError.value = 'Impossible de charger Paddle.js. Vérifiez votre connexion.';
        return false;
    }

    if (!window.Paddle?.Initialize) {
        paddleUiError.value = 'Paddle.js est chargé mais indisponible. Rechargez la page.';
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
        paddleUiError.value = 'Paddle n’est pas configuré (token/seller manquant).';
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
        paddleUiError.value = props.paddle?.error || "Paddle n'est pas configure.";
        return;
    }

    paddleIsLoading.value = true;

    const ready = await ensurePaddleReady();
    if (!ready) {
        paddleIsLoading.value = false;
        paddleUiError.value = paddleUiError.value || 'Paddle.js n’est pas prêt. Rechargez la page.';
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
    <Head title="Facturation" />

    <SettingsLayout active="billing">
        <div class="w-full max-w-6xl space-y-4">
            <SettingsTabs
                v-model="activeTab"
                :tabs="tabs"
                :id-prefix="tabPrefix"
                aria-label="Sections de facturation"
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
                            <h2 class="text-lg font-semibold text-stone-800 dark:text-neutral-100">Abonnement plateforme</h2>
                            <p class="mt-1 text-sm text-stone-600 dark:text-neutral-400">
                                Choisissez un plan mensuel pour activer toutes les fonctionnalites.
                            </p>
                        </div>
                    </div>

                    <div v-if="!paddle?.api_enabled" class="rounded-sm border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-700">
                        Paddle API non configure. Ajoutez `PADDLE_API_KEY` dans `.env`.
                    </div>
                    <div v-else-if="!paddle?.js_enabled" class="rounded-sm border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-700">
                        Paddle.js non configure. Ajoutez `PADDLE_CLIENT_SIDE_TOKEN` (ou `PADDLE_SELLER_ID`) dans `.env`.
                    </div>
                    <div v-else-if="paddle?.error" class="rounded-sm border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                        {{ paddle.error }}
                    </div>
                    <div v-if="paddleUiError" class="rounded-sm border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                        {{ paddleUiError }}
                    </div>

                    <div v-if="checkoutStatus === 'success'"
                        class="rounded-sm border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-700">
                        <p>Abonnement activé. Merci !</p>
                        <p v-if="checkoutPlanName" class="text-xs text-emerald-700/80">
                            Vous êtes maintenant sur {{ checkoutPlanName }}.
                        </p>
                    </div>
                    <div v-else-if="checkoutStatus === 'swapped'"
                        class="rounded-sm border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-700">
                        Plan mis à jour vers <strong class="text-emerald-700">{{ checkoutPlanName || 'le nouveau plan' }}</strong>.
                    </div>
                    <div v-else-if="checkoutStatus === 'payment-method'"
                        class="rounded-sm border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-700">
                        Carte mise a jour. Merci !
                    </div>
                    <div v-else-if="checkoutStatus === 'cancel'"
                        class="rounded-sm border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-700">
                        Paiement annule. Choisissez un plan pour continuer.
                    </div>

                    <div
                        class="rounded-sm border border-stone-100 bg-stone-50 px-3 py-2 text-sm text-stone-600 dark:border-neutral-700 dark:bg-neutral-900/40 dark:text-neutral-200">
                        <p v-if="activePlan">
                            Vous êtes sur <strong>{{ activePlan.name }}</strong> (Statut: {{ subscriptionStatusLabel }})
                            <span v-if="subscription?.on_trial" class="text-emerald-700 dark:text-emerald-300">· Essai en cours</span>
                        </p>
                        <p v-else>
                            Aucun abonnement actif. Sélectionnez un plan pour démarrer.
                        </p>
                    </div>

                    <div v-if="!hasPlans"
                        class="rounded-sm border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                        Aucun plan configure. Ajoutez vos PRICE_ID Paddle dans l'environnement.
                    </div>

                    <div class="billing-plans">
                        <div class="billing-plans__grid">
                            <div v-for="plan in plans" :key="plan.key" class="plan-card"
                                :data-active="plan.price_id === subscription?.price_id">
                                <div class="plan-card__top">
                                    <div>
                                        <h3 class="plan-card__name">{{ plan.name }}</h3>
                                        <p class="plan-card__meta">Plan mensuel</p>
                                    </div>
                                    <span v-if="subscription?.price_id === plan.price_id"
                                        class="plan-card__badge plan-card__badge--active">
                                        Actif
                                    </span>
                                    <span v-else-if="plan.key === 'growth'" class="plan-card__badge">Populaire</span>
                                </div>
                                <div class="plan-card__price">
                                    <span class="plan-card__amount">{{ plan.display_price || '--' }}</span>
                                    <span class="plan-card__interval">/mois</span>
                                </div>
                                <p v-if="subscription?.active && plan.price_id === subscription?.price_id"
                                    class="plan-card__status">
                                    Vous etes sur ce plan.
                                </p>
                                <ul class="plan-card__features">
                                    <li v-for="feature in plan.features" :key="feature" class="plan-card__feature">
                                        {{ feature }}
                                    </li>
                                </ul>
                                <button type="button" @click="startCheckout(plan)" class="plan-card__cta"
                                    :disabled="paddleIsLoading || !plan.price_id || plan.price_id === subscription?.price_id"
                                    :class="{ 'is-active': plan.price_id === subscription?.price_id }">
                                    <span v-if="plan.price_id === subscription?.price_id">Plan actif</span>
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
                            <h2 class="text-lg font-semibold text-stone-800 dark:text-neutral-100">Moyen de paiement</h2>
                            <p class="mt-1 text-sm text-stone-600 dark:text-neutral-400">
                                Gere la carte associee a votre abonnement.
                            </p>
                        </div>
                        <button v-if="hasSubscription" type="button" @click="startPaymentMethodUpdate"
                            :disabled="paymentMethodIsLoading"
                            class="py-2 px-3 text-sm font-medium rounded-sm border border-stone-200 text-stone-700 hover:bg-stone-50 disabled:opacity-60 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-700">
                            Mettre a jour la carte
                        </button>
                    </div>

                    <div v-if="!paddle?.api_enabled" class="rounded-sm border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-700">
                        Paddle API non configure. Ajoutez `PADDLE_API_KEY` dans `.env`.
                    </div>
                    <div v-else-if="!paddle?.js_enabled" class="rounded-sm border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-700">
                        Paddle.js non configure. Ajoutez `PADDLE_CLIENT_SIDE_TOKEN` (ou `PADDLE_SELLER_ID`) dans `.env`.
                    </div>
                    <div v-else-if="paddle?.error" class="rounded-sm border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                        {{ paddle.error }}
                    </div>
                    <div v-if="paddleUiError" class="rounded-sm border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                        {{ paddleUiError }}
                    </div>

                    <div v-if="checkoutStatus === 'payment-method'"
                        class="rounded-sm border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-700">
                        Carte mise a jour. Merci !
                    </div>

                    <div
                        class="rounded-sm border border-stone-100 bg-stone-50 px-3 py-2 text-sm text-stone-600 dark:border-neutral-700 dark:bg-neutral-900/40 dark:text-neutral-200">
                        <p v-if="activePlan">
                            Abonnement actif: <strong>{{ activePlan.name }}</strong> (Statut: {{ subscriptionStatusLabel }})
                        </p>
                        <p v-else>
                            Aucun abonnement actif pour le moment.
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
