<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import SalesStats from '@/Components/UI/SalesStats.vue';
import SalesTable from '@/Pages/Sales/UI/SalesTable.vue';

const props = defineProps({
    sales: {
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
        <Head title="Ventes" />

        <div class="space-y-4">
            <SalesStats :stats="stats" />

            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="space-y-1">
                    <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">Ventes</h1>
                    <p class="text-sm text-stone-600 dark:text-neutral-400">
                        Suivez les commandes et encaissements produits.
                    </p>
                </div>
                <Link
                    :href="route('sales.create')"
                    class="rounded-sm border border-transparent bg-green-600 px-3 py-2 text-sm font-medium text-white hover:bg-green-700"
                >
                    Nouvelle vente
                </Link>
            </div>

            <SalesTable :sales="sales" :filters="filters" :customers="customers" />
        </div>
    </AuthenticatedLayout>
</template>
