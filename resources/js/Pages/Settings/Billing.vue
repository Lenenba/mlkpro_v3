<script setup>
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
});

const form = useForm({
    payment_methods: Array.isArray(props.paymentMethods) ? props.paymentMethods : [],
});

const submit = () => {
    form.put(route('settings.billing.update'), { preserveScroll: true });
};
</script>

<template>
    <Head title="Facturation" />

    <AuthenticatedLayout>
        <div class="mx-auto w-full max-w-4xl space-y-5">
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

