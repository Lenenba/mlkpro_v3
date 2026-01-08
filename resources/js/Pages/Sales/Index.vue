<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { humanizeDate } from '@/utils/date';
import SalesStats from '@/Components/UI/SalesStats.vue';

const props = defineProps({
    sales: {
        type: Object,
        required: true,
    },
    stats: {
        type: Object,
        required: true,
    },
});

const formatCurrency = (value) =>
    `$${Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

const statusLabels = {
    draft: 'Brouillon',
    pending: 'En attente',
    paid: 'Payee',
    canceled: 'Annulee',
};

const statusClasses = {
    draft: 'bg-stone-100 text-stone-600 dark:bg-neutral-800 dark:text-neutral-300',
    pending: 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-200',
    paid: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-200',
    canceled: 'bg-red-100 text-red-700 dark:bg-red-500/10 dark:text-red-200',
};

const customerLabel = (sale) => {
    const customer = sale?.customer;
    if (customer?.company_name) {
        return customer.company_name;
    }
    const name = [customer?.first_name, customer?.last_name].filter(Boolean).join(' ');
    return name || 'Client';
};
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

            <div class="rounded-sm border border-stone-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="border-b border-stone-200 px-4 py-3 text-sm font-semibold text-stone-800 dark:border-neutral-700 dark:text-neutral-100">
                    Liste des ventes
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-stone-50 text-xs uppercase text-stone-500 dark:bg-neutral-800 dark:text-neutral-400">
                            <tr>
                                <th class="px-4 py-3 text-left">Numero</th>
                                <th class="px-4 py-3 text-left">Client</th>
                                <th class="px-4 py-3 text-left">Statut</th>
                                <th class="px-4 py-3 text-right">Total</th>
                                <th class="px-4 py-3 text-left">Date</th>
                                <th class="px-4 py-3 text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-200 dark:divide-neutral-800">
                            <tr v-if="!sales.data.length">
                                <td colspan="6" class="px-4 py-6 text-center text-stone-500 dark:text-neutral-400">
                                    Aucune vente pour le moment.
                                </td>
                            </tr>
                            <tr v-for="sale in sales.data" :key="sale.id">
                                <td class="px-4 py-3 font-medium text-stone-800 dark:text-neutral-100">
                                    {{ sale.number || `Sale #${sale.id}` }}
                                </td>
                                <td class="px-4 py-3 text-stone-700 dark:text-neutral-200">
                                    {{ customerLabel(sale) }}
                                </td>
                                <td class="px-4 py-3">
                                    <span
                                        class="rounded-full px-2 py-1 text-xs font-semibold"
                                        :class="statusClasses[sale.status] || statusClasses.draft"
                                    >
                                        {{ statusLabels[sale.status] || sale.status }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-right font-medium text-stone-800 dark:text-neutral-100">
                                    {{ formatCurrency(sale.total) }}
                                </td>
                                <td class="px-4 py-3 text-stone-600 dark:text-neutral-400">
                                    {{ humanizeDate(sale.created_at) }}
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <div class="flex items-center justify-end gap-3">
                                        <Link
                                            :href="route('sales.show', sale.id)"
                                            class="text-xs font-semibold text-green-700 hover:underline dark:text-green-400"
                                        >
                                            Voir
                                        </Link>
                                        <Link
                                            v-if="sale.status === 'draft' || sale.status === 'pending'"
                                            :href="route('sales.edit', sale.id)"
                                            class="text-xs font-semibold text-stone-600 hover:underline dark:text-neutral-300"
                                        >
                                            Reprendre
                                        </Link>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div v-if="sales.prev_page_url || sales.next_page_url" class="flex justify-end gap-2">
                <Link
                    v-if="sales.prev_page_url"
                    :href="sales.prev_page_url"
                    class="rounded-sm border border-stone-200 bg-white px-3 py-1.5 text-xs text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                >
                    Precedent
                </Link>
                <Link
                    v-if="sales.next_page_url"
                    :href="sales.next_page_url"
                    class="rounded-sm border border-stone-200 bg-white px-3 py-1.5 text-xs text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                >
                    Suivant
                </Link>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
