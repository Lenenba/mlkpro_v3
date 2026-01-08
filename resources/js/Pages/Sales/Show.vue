<script setup>
import { computed } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { humanizeDate } from '@/utils/date';

const props = defineProps({
    sale: {
        type: Object,
        required: true,
    },
});

const page = usePage();
const companyName = computed(() => page.props.auth?.account?.company?.name || 'Entreprise');
const companyLogo = computed(() => page.props.auth?.account?.company?.logo_url || null);
const createdBy = computed(() => props.sale?.created_by || null);
const sellerName = computed(() => createdBy.value?.name || page.props.auth?.user?.name || 'Vendeur');
const sellerEmail = computed(() => createdBy.value?.email || page.props.auth?.user?.email || null);
const sellerPhone = computed(() => createdBy.value?.phone_number || page.props.auth?.user?.phone_number || null);

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

const lineCount = computed(() => props.sale?.items?.length ?? 0);
const totalQty = computed(() =>
    (props.sale?.items ?? []).reduce((sum, item) => sum + Number(item.quantity || 0), 0)
);

const productImage = (item) => item?.product?.image_url || item?.product?.image || null;
const productFallback = (item) => {
    const label = `${item?.product?.name || item?.description || 'P'}`.trim();
    if (!label) {
        return 'P';
    }
    return label.slice(0, 2).toUpperCase();
};

const companyInitials = computed(() => {
    const name = companyName.value || '';
    const parts = name.split(' ').filter(Boolean).slice(0, 2);
    if (!parts.length) {
        return 'CO';
    }
    return parts.map((part) => part[0]).join('').toUpperCase();
});

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

const formatDateTime = (value) => {
    if (!value) {
        return '-';
    }
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return '-';
    }
    return date.toLocaleString();
};

const handlePrint = () => {
    window.print();
};

const fulfillmentLabel = computed(() => {
    if (props.sale?.fulfillment_method === 'delivery') {
        return 'Livraison';
    }
    if (props.sale?.fulfillment_method === 'pickup') {
        return 'Retrait';
    }
    return 'Commande';
});

const fulfillmentStatusLabels = {
    pending: 'En attente',
    preparing: 'Preparation',
    out_for_delivery: 'En cours de livraison',
    ready_for_pickup: 'Pret a retirer',
    completed: 'Terminee',
};

const pickupCode = computed(() => props.sale?.pickup_code || null);
const pickupQrUrl = computed(() => {
    if (!pickupCode.value) {
        return null;
    }
    return `https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=${encodeURIComponent(pickupCode.value)}`;
});
const showPickupQr = computed(() =>
    props.sale?.fulfillment_method === 'pickup'
    && props.sale?.fulfillment_status === 'ready_for_pickup'
    && pickupQrUrl.value
);
const pickupConfirmedBy = computed(() => props.sale?.pickup_confirmed_by || null);
const canConfirmPickup = computed(() =>
    props.sale?.fulfillment_method === 'pickup'
    && props.sale?.fulfillment_status === 'ready_for_pickup'
    && !props.sale?.pickup_confirmed_at
);
</script>

<template>
    <AuthenticatedLayout>
        <Head title="Vente" />

        <div class="space-y-4">
            <div class="print-card rounded-sm border border-stone-200 bg-stone-50 p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div class="flex items-center gap-3">
                        <div class="h-12 w-12 overflow-hidden rounded-sm border border-stone-200 bg-white dark:border-neutral-700 dark:bg-neutral-800">
                            <img
                                v-if="companyLogo"
                                :src="companyLogo"
                                :alt="companyName"
                                class="h-full w-full object-cover"
                            >
                            <div v-else class="flex h-full w-full items-center justify-center text-xs font-semibold text-stone-500 dark:text-neutral-400">
                                {{ companyInitials }}
                            </div>
                        </div>
                        <div class="space-y-1">
                            <p class="text-xs uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                {{ companyName }}
                            </p>
                            <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">
                                Facture {{ sale.number || `Sale #${sale.id}` }}
                            </h1>
                            <p class="text-sm text-stone-600 dark:text-neutral-400">
                                {{ sale.customer ? customerLabel(sale.customer) : 'Client anonyme' }}
                            </p>
                        </div>
                    </div>
                    <div class="space-y-1 text-sm text-stone-600 dark:text-neutral-400">
                        <div class="flex items-center justify-end gap-2">
                            <span
                                class="rounded-full px-2 py-0.5 text-xs font-semibold"
                                :class="statusClasses[sale.status] || statusClasses.draft"
                            >
                                {{ statusLabels[sale.status] || sale.status }}
                            </span>
                            <span>Cree le {{ formatDate(sale.created_at) }}</span>
                        </div>
                        <div v-if="sale.paid_at" class="text-right text-xs text-stone-500 dark:text-neutral-400">
                            Payee le {{ formatDate(sale.paid_at) }}
                        </div>
                        <div class="text-right">
                            <div class="text-xs uppercase tracking-wide text-stone-500 dark:text-neutral-400">Vendeur</div>
                            <div class="text-sm font-medium text-stone-800 dark:text-neutral-100">{{ sellerName }}</div>
                            <div v-if="sellerEmail" class="text-xs text-stone-500 dark:text-neutral-400">{{ sellerEmail }}</div>
                            <div v-if="sellerPhone" class="text-xs text-stone-500 dark:text-neutral-400">{{ sellerPhone }}</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="no-print flex flex-wrap items-center justify-end gap-2">
                <Link
                    v-if="canEdit"
                    :href="route('sales.edit', sale.id)"
                    class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                >
                    Modifier
                </Link>
                <Link
                    v-if="canConfirmPickup"
                    :href="route('sales.pickup.confirm', sale.id)"
                    method="post"
                    as="button"
                    class="rounded-sm bg-emerald-600 px-3 py-2 text-xs font-semibold text-white hover:bg-emerald-700"
                >
                    Confirmer retrait
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
                                    <th class="px-4 py-3 text-left">Unite</th>
                                    <th class="px-4 py-3 text-right">Quantite</th>
                                    <th class="px-4 py-3 text-right">Prix</th>
                                    <th class="px-4 py-3 text-right">Total</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-stone-200 dark:divide-neutral-800">
                                <tr v-for="item in sale.items" :key="item.id">
                                    <td class="px-4 py-3 text-stone-700 dark:text-neutral-200">
                                        <div class="flex items-center gap-3">
                                            <div
                                                class="h-10 w-10 overflow-hidden rounded-sm bg-stone-100 text-[10px] font-semibold text-stone-400 dark:bg-neutral-800 dark:text-neutral-500"
                                            >
                                                <img
                                                    v-if="productImage(item)"
                                                    :src="productImage(item)"
                                                    :alt="item.product?.name || 'Produit'"
                                                    class="h-full w-full object-cover"
                                                >
                                                <div v-else class="flex h-full w-full items-center justify-center">
                                                    {{ productFallback(item) }}
                                                </div>
                                            </div>
                                            <div>
                                                <div class="text-sm font-medium text-stone-800 dark:text-neutral-100">
                                                    {{ item.product?.name || item.description }}
                                                </div>
                                                <div
                                                    v-if="item.description && item.description !== item.product?.name"
                                                    class="text-xs text-stone-500 dark:text-neutral-400"
                                                >
                                                    {{ item.description }}
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 text-stone-500 dark:text-neutral-400">
                                        {{ item.product?.sku || '-' }}
                                    </td>
                                    <td class="px-4 py-3 text-stone-500 dark:text-neutral-400">
                                        {{ item.product?.unit || '-' }}
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
                            <div v-if="Number(sale.delivery_fee || 0) > 0" class="flex items-center justify-between">
                                <span>Livraison</span>
                                <span class="font-medium">{{ formatCurrency(sale.delivery_fee) }}</span>
                            </div>
                            <div class="flex items-center justify-between border-t border-stone-200 pt-2 dark:border-neutral-700">
                                <span class="font-semibold">Total</span>
                                <span class="font-semibold">{{ formatCurrency(sale.total) }}</span>
                            </div>
                            <div class="grid grid-cols-2 gap-2 pt-2 text-xs text-stone-500 dark:text-neutral-400">
                                <div class="flex items-center justify-between">
                                    <span>Lignes</span>
                                    <span class="font-medium text-stone-700 dark:text-neutral-200">{{ lineCount }}</span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span>Articles</span>
                                    <span class="font-medium text-stone-700 dark:text-neutral-200">{{ totalQty }}</span>
                                </div>
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

                    <div
                        v-if="sale.fulfillment_method || sale.delivery_address || sale.pickup_notes || sale.delivery_notes || sale.scheduled_for"
                        class="print-card rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900"
                    >
                        <div class="flex items-center justify-between">
                            <span class="text-sm text-stone-500 dark:text-neutral-400">Livraison</span>
                            <span class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                {{ fulfillmentLabel }}
                            </span>
                        </div>
                        <div class="mt-3 space-y-1 text-sm text-stone-700 dark:text-neutral-200">
                            <div v-if="sale.fulfillment_status" class="text-xs text-stone-500 dark:text-neutral-400">
                                Statut: {{ fulfillmentStatusLabels[sale.fulfillment_status] || sale.fulfillment_status }}
                            </div>
                            <div v-if="sale.fulfillment_method === 'delivery' && sale.delivery_address">
                                Adresse: {{ sale.delivery_address }}
                            </div>
                            <div v-if="sale.fulfillment_method === 'delivery' && sale.delivery_notes">
                                Notes: {{ sale.delivery_notes }}
                            </div>
                            <div v-if="sale.fulfillment_method === 'pickup' && sale.pickup_notes">
                                Notes: {{ sale.pickup_notes }}
                            </div>
                            <div v-if="sale.scheduled_for">
                                Horaire souhaite: {{ formatDateTime(sale.scheduled_for) }}
                            </div>
                        </div>
                    </div>

                    <div
                        v-if="sale.fulfillment_method === 'pickup'"
                        class="print-card rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900"
                    >
                        <div class="flex items-center justify-between">
                            <span class="text-sm text-stone-500 dark:text-neutral-400">Retrait</span>
                            <span class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                {{ fulfillmentStatusLabels[sale.fulfillment_status] || 'Retrait' }}
                            </span>
                        </div>
                        <div class="mt-3 space-y-2 text-sm text-stone-700 dark:text-neutral-200">
                            <div v-if="pickupCode" class="flex items-center justify-between">
                                <span class="text-xs uppercase text-stone-500 dark:text-neutral-400">Code</span>
                                <span class="font-semibold text-stone-800 dark:text-neutral-100">{{ pickupCode }}</span>
                            </div>
                            <div v-else class="text-xs text-stone-500 dark:text-neutral-400">
                                Code de retrait en attente.
                            </div>
                            <div v-if="sale.pickup_confirmed_at">
                                Retire le {{ formatDateTime(sale.pickup_confirmed_at) }}
                            </div>
                            <div v-if="pickupConfirmedBy?.name" class="text-xs text-stone-500 dark:text-neutral-400">
                                Confirme par {{ pickupConfirmedBy.name }}
                            </div>
                        </div>
                        <div v-if="showPickupQr" class="mt-3 flex justify-center">
                            <img
                                :src="pickupQrUrl"
                                :alt="`QR ${pickupCode}`"
                                class="h-40 w-40 rounded-sm border border-stone-200 bg-white object-contain p-2 dark:border-neutral-700"
                            >
                        </div>
                    </div>

                    <div
                        v-if="sale.source === 'portal' || sale.customer_notes || sale.substitution_notes"
                        class="print-card rounded-sm border border-stone-200 bg-white p-4 text-sm text-stone-700 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                    >
                        <p class="text-xs uppercase text-stone-500 dark:text-neutral-400">Notes client</p>
                        <div class="mt-2 space-y-2">
                            <p v-if="sale.customer_notes">{{ sale.customer_notes }}</p>
                            <p v-else class="text-xs text-stone-500 dark:text-neutral-400">Aucune note specifique.</p>
                            <div class="text-xs text-stone-500 dark:text-neutral-400">
                                Substitutions:
                                <span class="font-semibold text-stone-700 dark:text-neutral-200">
                                    {{ sale.substitution_allowed === false ? 'Non autorisees' : 'Autorisees' }}
                                </span>
                            </div>
                            <p v-if="sale.substitution_notes">{{ sale.substitution_notes }}</p>
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
