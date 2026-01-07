<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { humanizeDate } from '@/utils/date';

const props = defineProps({
    sale: {
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

const customerLabel = (customer) => {
    if (customer?.company_name) {
        return customer.company_name;
    }
    const name = [customer?.first_name, customer?.last_name].filter(Boolean).join(' ');
    return name || 'Client';
};
</script>

<template>
    <AuthenticatedLayout>
        <Head title="Vente" />

        <div class="space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="space-y-1">
                    <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">
                        {{ sale.number || `Sale #${sale.id}` }}
                    </h1>
                    <p class="text-sm text-stone-600 dark:text-neutral-400">
                        {{ customerLabel(sale.customer) }} · {{ humanizeDate(sale.created_at) }}
                    </p>
                </div>
                <Link
                    :href="route('sales.index')"
                    class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                >
                    Retour aux ventes
                </Link>
            </div>

            <div class="grid gap-4 lg:grid-cols-[2fr_1fr]">
                <div class="rounded-sm border border-stone-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="border-b border-stone-200 px-4 py-3 text-sm font-semibold text-stone-800 dark:border-neutral-700 dark:text-neutral-100">
                        Produits
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="bg-stone-50 text-xs uppercase text-stone-500 dark:bg-neutral-800 dark:text-neutral-400">
                                <tr>
                                    <th class="px-4 py-3 text-left">Produit</th>
                                    <th class="px-4 py-3 text-right">Quantite</th>
                                    <th class="px-4 py-3 text-right">Prix</th>
                                    <th class="px-4 py-3 text-right">Total</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-stone-200 dark:divide-neutral-800">
                                <tr v-for="item in sale.items" :key="item.id">
                                    <td class="px-4 py-3 text-stone-700 dark:text-neutral-200">
                                        {{ item.product?.name || item.description }}
                                    </td>
                                    <td class="px-4 py-3 text-right text-stone-700 dark:text-neutral-200">
                                        {{ item.quantity }}
                                    </td>
                                    <td class="px-4 py-3 text-right text-stone-700 dark:text-neutral-200">
                                        {{ formatCurrency(item.price) }}
                                    </td>
                                    <td class="px-4 py-3 text-right font-medium text-stone-800 dark:text-neutral-100">
                                        {{ formatCurrency(item.total) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="space-y-4">
                    <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <div class="flex items-center justify-between">
                            <span class="text-sm text-stone-500 dark:text-neutral-400">Statut</span>
                            <span
                                class="rounded-full px-2 py-1 text-xs font-semibold"
                                :class="statusClasses[sale.status] || statusClasses.draft"
                            >
                                {{ statusLabels[sale.status] || sale.status }}
                            </span>
                        </div>
                        <div class="mt-3 space-y-2 text-sm text-stone-700 dark:text-neutral-200">
                            <div class="flex items-center justify-between">
                                <span>Sous-total</span>
                                <span class="font-medium">{{ formatCurrency(sale.subtotal) }}</span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span>Taxes</span>
                                <span class="font-medium">{{ formatCurrency(sale.tax_total) }}</span>
                            </div>
                            <div class="flex items-center justify-between border-t border-stone-200 pt-2 dark:border-neutral-700">
                                <span class="font-semibold">Total</span>
                                <span class="font-semibold">{{ formatCurrency(sale.total) }}</span>
                            </div>
                        </div>
                    </div>

                    <div v-if="sale.notes" class="rounded-sm border border-stone-200 bg-white p-4 text-sm text-stone-700 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200">
                        <p class="text-xs uppercase text-stone-500 dark:text-neutral-400">Notes</p>
                        <p class="mt-2">{{ sale.notes }}</p>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
