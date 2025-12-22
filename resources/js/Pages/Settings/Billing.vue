<script setup>
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
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
});

const form = useForm({
    payment_methods: Array.isArray(props.paymentMethods) ? props.paymentMethods : [],
});

const subscribeForm = useForm({
    price_id: '',
});

const portalForm = useForm({});

const activePlan = computed(() => {
    if (!props.subscription?.stripe_price) {
        return null;
    }

    return props.plans.find((plan) => plan.price_id === props.subscription.stripe_price) || null;
});

const hasPlans = computed(() => props.plans.some((plan) => Boolean(plan.price_id)));

const submit = () => {
    form.put(route('settings.billing.update'), { preserveScroll: true });
};

const startCheckout = (plan) => {
    if (!plan?.price_id) {
        return;
    }

    subscribeForm.price_id = plan.price_id;
    subscribeForm.post(route('settings.billing.subscribe'), { preserveScroll: true });
};

const openPortal = () => {
    portalForm.post(route('settings.billing.portal'), { preserveScroll: true });
};
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

                    <div v-if="checkoutStatus === 'success'"
                        class="rounded-sm border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-700">
                        Abonnement active. Merci !
                    </div>
                    <div v-else-if="checkoutStatus === 'cancel'"
                        class="rounded-sm border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-700">
                        Paiement annule. Choisissez un plan pour continuer.
                    </div>

                    <div class="flex flex-wrap items-center gap-2 text-sm text-gray-600 dark:text-neutral-400">
                        <span v-if="subscription?.active">
                            Statut: {{ subscription.status || 'active' }}
                        </span>
                        <span v-if="activePlan">Plan: {{ activePlan.name }}</span>
                        <span v-if="subscription?.on_trial">Essai en cours</span>
                    </div>

                    <div v-if="!hasPlans"
                        class="rounded-sm border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                        Aucun plan configure. Ajoutez vos PRICE_ID Stripe dans l'environnement.
                    </div>

                    <div class="grid gap-3 md:grid-cols-3">
                        <div v-for="plan in plans" :key="plan.key"
                            class="rounded-sm border border-gray-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                            <div class="flex items-center justify-between">
                                <h3 class="text-sm font-semibold text-gray-800 dark:text-neutral-100">{{ plan.name }}</h3>
                                <span v-if="subscription?.stripe_price === plan.price_id"
                                    class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs text-emerald-700">
                                    Actif
                                </span>
                            </div>
                            <div class="mt-2 text-2xl font-semibold text-gray-900 dark:text-white">
                                <span v-if="plan.price">{{ plan.price }}</span>
                                <span v-else>--</span>
                                <span class="text-sm font-normal text-gray-500 dark:text-neutral-400">/mois</span>
                            </div>
                            <ul class="mt-3 space-y-1 text-xs text-gray-600 dark:text-neutral-400">
                                <li v-for="feature in plan.features" :key="feature">- {{ feature }}</li>
                            </ul>
                            <button type="button" @click="startCheckout(plan)"
                                :disabled="!plan.price_id || subscribeForm.processing"
                                class="mt-4 w-full rounded-sm border border-transparent bg-green-600 px-3 py-2 text-sm font-medium text-white hover:bg-green-700 disabled:opacity-50 disabled:pointer-events-none">
                                Choisir ce plan
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
