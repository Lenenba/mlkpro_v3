<script setup>
import { computed } from 'vue';
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

const canEdit = computed(() => ['draft', 'pending'].includes(props.sale?.status));

const formatDate = (value) => {
    if (!value) {
        return '-';
    }
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return '-';
    }
    return date.toLocaleDateString();
};

const handlePrint = () => {
    window.print();
};
</script>

<template>
    <AuthenticatedLayout>
        <Head title="Vente" />

        <div class="space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="space-y-1">
                    <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">
                        Facture {{ sale.number || `Sale #${sale.id}` }}
                    </h1>
                    <div class="flex flex-wrap items-center gap-2 text-sm text-stone-600 dark:text-neutral-400">
                        <span
                            class="rounded-full px-2 py-0.5 text-xs font-semibold"
                            :class="statusClasses[sale.status] || statusClasses.draft"
                        >
                            {{ statusLabels[sale.status] || sale.status }}
                        </span>
                        <span>Cree le {{ formatDate(sale.created_at) }}</span>
                        <span v-if="sale.paid_at">Payee le {{ formatDate(sale.paid_at) }}</span>
                    </div>
                    <p class="text-sm text-stone-600 dark:text-neutral-400">
                        {{ sale.customer ? customerLabel(sale.customer) : 'Client anonyme' }}
                    </p>
                </div>
                <div class="no-print flex flex-wrap items-center gap-2">
                    <Link
                        v-if="canEdit"
                        :href="route('sales.edit', sale.id)"
                        class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                    >
                        Modifier
                    </Link>
                    <button
                        type="button"
                        class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                        @click="handlePrint"
                    >
                        Imprimer
                    </button>
                    <Link
                        :href="route('sales.index')"
                        class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                    >
                        Retour aux ventes
                    </Link>
                </div>
            </div>

            <div class="grid gap-4 lg:grid-cols-[2fr_1fr]">
                <div class="print-card rounded-sm border border-stone-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="border-b border-stone-200 px-4 py-3 text-sm font-semibold text-stone-800 dark:border-neutral-700 dark:text-neutral-100">
                        Produits
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="bg-stone-50 text-xs uppercase text-stone-500 dark:bg-neutral-800 dark:text-neutral-400">
                                <tr>
                                    <th class="px-4 py-3 text-left">Produit</th>
                                    <th class="px-4 py-3 text-left">SKU</th>
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
                                    <td class="px-4 py-3 text-stone-500 dark:text-neutral-400">
                                        {{ item.product?.sku || '-' }}
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
                    <div class="print-card rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <div class="flex items-center justify-between">
                            <span class="text-sm text-stone-500 dark:text-neutral-400">Client</span>
                            <span class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                {{ sale.customer ? customerLabel(sale.customer) : 'Client anonyme' }}
                            </span>
                        </div>
                        <div class="mt-3 space-y-1 text-sm text-stone-700 dark:text-neutral-200">
                            <div v-if="sale.customer?.email">{{ sale.customer.email }}</div>
                            <div v-if="sale.customer?.phone">{{ sale.customer.phone }}</div>
                            <div v-if="!sale.customer">Aucun client attache.</div>
                        </div>
                    </div>

                    <div class="print-card rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <div class="flex items-center justify-between">
                            <span class="text-sm text-stone-500 dark:text-neutral-400">Resume</span>
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
                            <div class="flex items-center justify-between text-xs text-stone-500 dark:text-neutral-400">
                                <span>Cree</span>
                                <span>{{ humanizeDate(sale.created_at) }}</span>
                            </div>
                            <div v-if="sale.paid_at" class="flex items-center justify-between text-xs text-stone-500 dark:text-neutral-400">
                                <span>Payee</span>
                                <span>{{ humanizeDate(sale.paid_at) }}</span>
                            </div>
                        </div>
                    </div>

                    <div v-if="sale.notes" class="print-card rounded-sm border border-stone-200 bg-white p-4 text-sm text-stone-700 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200">
                        <p class="text-xs uppercase text-stone-500 dark:text-neutral-400">Notes</p>
                        <p class="mt-2">{{ sale.notes }}</p>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<style scoped>
@media print {
    :global(header),
    :global(aside) {
        display: none !important;
    }
    :global(body),
    :global(#content) {
        background: white !important;
    }
    :global(#content) {
        padding: 0 !important;
    }
    :global(main#content) {
        min-height: auto !important;
    }
    .no-print {
        display: none !important;
    }
    .print-card {
        box-shadow: none !important;
    }
}
</style>
