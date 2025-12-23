<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Checkbox from '@/Components/Checkbox.vue';

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

const isSubscribed = computed(() => Boolean(props.subscription?.active));
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

const submit = () => {
    form.put(route('settings.billing.update'), { preserveScroll: true });
};

const openPortal = () => {
    router.post(route('settings.billing.portal'), {}, { preserveScroll: true });
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

    <AuthenticatedLayout>
        <div class="mx-auto w-full max-w-4xl space-y-5">
            <div class="flex flex-col bg-white border border-gray-200 shadow-sm rounded-sm overflow-hidden dark:bg-neutral-800 dark:border-neutral-700">
                <div class="p-4 space-y-4">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <h2 class="text-lg font-semibold text-gray-800 dark:text-neutral-100">Abonnement plateforme</h2>
                            <p class="mt-1 text-sm text-gray-600 dark:text-neutral-400">
                                Choisissez un plan mensuel pour activer toutes les fonctionnalites.
                            </p>
                        </div>
                        <button v-if="subscription?.active" type="button" @click="openPortal"
                            class="py-2 px-3 text-sm font-medium rounded-sm border border-gray-200 text-gray-700 hover:bg-gray-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-700">
                            Gerer le paiement
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
                    <div v-else-if="checkoutStatus === 'cancel'"
                        class="rounded-sm border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-700">
                        Paiement annule. Choisissez un plan pour continuer.
                    </div>

                    <div
                        class="rounded-sm border border-gray-100 bg-gray-50 px-3 py-2 text-sm text-gray-600 dark:border-neutral-700 dark:bg-neutral-900/40 dark:text-neutral-200">
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

                    <div class="grid gap-3 md:grid-cols-3">
                        <div v-for="plan in plans" :key="plan.key"
                            :class="[
                                'rounded-sm p-4 shadow-sm transition',
                                plan.price_id === subscription?.price_id
                                    ? 'border border-emerald-400 bg-emerald-50/70 dark:border-emerald-500 dark:bg-emerald-900/30'
                                    : 'border border-gray-200 bg-white dark:border-neutral-700 dark:bg-neutral-900'
                            ]">
                            <div class="flex items-center justify-between">
                                <h3 class="text-sm font-semibold text-gray-800 dark:text-neutral-100">{{ plan.name }}</h3>
                                <span v-if="subscription?.price_id === plan.price_id"
                                    class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs text-emerald-700">
                                    Actif
                                </span>
                            </div>
                            <div class="mt-2 text-2xl font-semibold text-gray-900 dark:text-white">
                                <span v-if="plan.display_price">{{ plan.display_price }}</span>
                                <span v-else>--</span>
                                <span class="text-sm font-normal text-gray-500 dark:text-neutral-400">/mois</span>
                            </div>
                            <p v-if="subscription?.active && plan.price_id === subscription?.price_id"
                                class="mt-1 text-xs text-emerald-700 dark:text-emerald-200">
                                Vous êtes sur ce plan.
                            </p>
                            <ul class="mt-3 space-y-1 text-xs text-gray-600 dark:text-neutral-400">
                                <li v-for="feature in plan.features" :key="feature">- {{ feature }}</li>
                            </ul>
                            <button type="button" @click="startCheckout(plan)"
                                :disabled="paddleIsLoading || !plan.price_id || plan.price_id === subscription?.price_id"
                                :class="[
                                    'mt-4 w-full rounded-sm px-3 py-2 text-sm font-medium transition',
                                    plan.price_id === subscription?.price_id
                                        ? 'border border-gray-200 bg-gray-200 text-gray-500 cursor-not-allowed dark:border-neutral-600 dark:bg-neutral-800 dark:text-neutral-400'
                                        : 'border border-transparent bg-green-600 text-white hover:bg-green-700'
                                ]">
                                <span v-if="plan.price_id === subscription?.price_id">Plan actif</span>
                                <span v-else>{{ planActionLabel }}</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div>
                <h1 class="text-xl font-semibold text-gray-800 dark:text-neutral-100">Parametres de paiement</h1>
                <p class="mt-1 text-sm text-gray-600 dark:text-neutral-400">
                    Definissez les moyens de paiement disponibles dans l'application.
                </p>
            </div>

            <div class="flex flex-col bg-white border border-gray-200 shadow-sm rounded-sm overflow-hidden dark:bg-neutral-800 dark:border-neutral-700">
                <div class="p-4 space-y-3">
                    <div class="space-y-2">
                        <label v-for="method in availableMethods" :key="method.id"
                            class="flex items-center gap-2 text-sm text-gray-700 dark:text-neutral-200">
                            <Checkbox v-model:checked="form.payment_methods" :value="method.id" />
                            <span>{{ method.name }}</span>
                        </label>
                    </div>

                    <div class="flex justify-end">
                        <button type="button" @click="submit" :disabled="form.processing"
                            class="py-2 px-3 text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50 disabled:pointer-events-none">
                            Enregistrer
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
