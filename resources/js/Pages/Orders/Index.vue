<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import OrdersStats from '@/Components/UI/OrdersStats.vue';
import SalesTable from '@/Pages/Sales/UI/SalesTable.vue';

const props = defineProps({
    orders: {
        type: Object,
        required: true,
    },
    filters: {
        type: Object,
        default: () => ({}),
    },
    customers: {
        type: Array,
        default: () => [],
    },
    stats: {
        type: Object,
        required: true,
    },
});
</script>

<template>
    <AuthenticatedLayout>
        <Head title="Commandes" />

        <div class="space-y-4">
            <OrdersStats :stats="stats" />

            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="space-y-1">
                    <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">Commandes</h1>
                    <p class="text-sm text-stone-600 dark:text-neutral-400">
                        Suivez les commandes clients jusqu a la livraison.
                    </p>
                </div>
                <Link
                    :href="route('sales.create')"
                    class="rounded-sm border border-transparent bg-green-600 px-3 py-2 text-sm font-medium text-white hover:bg-green-700"
                >
                    Nouvelle commande
                </Link>
            </div>

            <SalesTable
                :sales="orders"
                :filters="filters"
                :customers="customers"
                route-name="orders.index"
                :show-fulfillment-status="true"
                :enable-status-update="true"
                :status-options="[
                    { value: '', label: 'Tous les statuts' },
                    { value: 'pending', label: 'En attente' },
                    { value: 'draft', label: 'Brouillon' },
                    { value: 'canceled', label: 'Annulee' },
                ]"
            />
        </div>
    </AuthenticatedLayout>
</template>
