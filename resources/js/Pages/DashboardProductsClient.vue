<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Card from '@/Components/UI/Card.vue';
import { humanizeDate } from '@/utils/date';

const props = defineProps({
    company: {
        type: Object,
        default: () => ({}),
    },
    stats: {
        type: Object,
        default: () => ({}),
    },
    sales: {
        type: Array,
        default: () => [],
    },
    pendingOrders: {
        type: Array,
        default: () => [],
    },
    inDeliveryOrders: {
        type: Array,
        default: () => [],
    },
    deliveryAlerts: {
        type: Array,
        default: () => [],
    },
});

const formatCurrency = (value) =>
    `$${Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

const formatNumber = (value) =>
    Number(value || 0).toLocaleString(undefined, { maximumFractionDigits: 0 });

const kpiCards = computed(() => ([
    { label: 'Commandes', value: formatNumber(props.stats.orders_total), tone: 'emerald' },
    { label: 'En attente', value: formatNumber(props.stats.orders_pending), tone: 'amber' },
    { label: 'Payees', value: formatNumber(props.stats.orders_paid), tone: 'sky' },
    { label: 'Total paye', value: formatCurrency(props.stats.amount_paid), tone: 'emerald' },
]));

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

const kpiTone = {
    emerald: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300',
    amber: 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300',
    sky: 'bg-sky-100 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300',
};

const formatDate = (value) => humanizeDate(value);
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

const orderLabel = (sale) => sale?.number || `Commande #${sale?.id || '-'}`;

const fulfillmentLabels = {
    pending: 'En attente',
    preparing: 'Preparation',
    out_for_delivery: 'En cours de livraison',
    ready_for_pickup: 'Pret a retirer',
    completed: 'Terminee',
};

const fulfillmentBadge = {
    pending: 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-200',
    preparing: 'bg-sky-100 text-sky-700 dark:bg-sky-500/10 dark:text-sky-200',
    out_for_delivery: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-200',
    ready_for_pickup: 'bg-indigo-100 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-200',
    completed: 'bg-stone-100 text-stone-600 dark:bg-neutral-800 dark:text-neutral-300',
};

const fulfillmentLabel = (sale) =>
    fulfillmentLabels[sale?.fulfillment_status] || (sale?.fulfillment_method === 'delivery' ? 'Livraison' : 'En attente');

const canEditOrder = (sale) => {
    if (!sale || sale.status === 'canceled') {
        return false;
    }
    const blocked = ['out_for_delivery', 'ready_for_pickup', 'completed'];
    return !blocked.includes(sale.fulfillment_status);
};
</script>

<template>
    <AuthenticatedLayout>
        <Head title="Mes achats" />

        <div class="space-y-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">Mes achats</h1>
                    <p class="text-sm text-stone-500 dark:text-neutral-400">
                        {{ company?.name ? `Ventes chez ${company.name}` : 'Vos ventes recentes' }}
                    </p>
                </div>
                <Link
                    :href="route('portal.orders.index')"
                    class="rounded-sm bg-green-600 px-3 py-2 text-xs font-semibold text-white hover:bg-green-700"
                >
                    Commander
                </Link>
            </div>

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <div
                    v-for="(card, index) in kpiCards"
                    :key="card.label"
                    class="rise-in rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900"
                    :style="{ animationDelay: `${index * 80}ms` }"
                >
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs uppercase text-stone-400">{{ card.label }}</p>
                            <p class="mt-1 text-lg font-semibold text-stone-800 dark:text-neutral-100">{{ card.value }}</p>
                        </div>
                        <span class="h-9 w-9 rounded-full" :class="kpiTone[card.tone]"></span>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <Card class="rise-in lg:col-span-1" :style="{ animationDelay: '120ms' }">
                    <template #title>Commandes en attente</template>
                    <div v-if="!pendingOrders.length" class="text-sm text-stone-500 dark:text-neutral-400">
                        Aucune commande en attente.
                    </div>
                    <div v-else class="divide-y divide-stone-200 dark:divide-neutral-700">
                        <div v-for="sale in pendingOrders" :key="sale.id" class="flex items-center justify-between gap-3 py-3 text-sm">
                            <div>
                                <p class="font-semibold text-stone-800 dark:text-neutral-200">
                                    {{ orderLabel(sale) }}
                                </p>
                                <p class="text-xs text-stone-500 dark:text-neutral-400">
                                    {{ formatDate(sale.created_at) }}
                                </p>
                            </div>
                            <div class="text-right">
                                <p class="font-semibold text-stone-800 dark:text-neutral-200">
                                    {{ formatCurrency(sale.total) }}
                                </p>
                                <span
                                    class="rounded-full px-2 py-1 text-[10px] font-semibold"
                                    :class="fulfillmentBadge[sale.fulfillment_status] || fulfillmentBadge.pending"
                                >
                                    {{ fulfillmentLabel(sale) }}
                                </span>
                                <Link
                                    v-if="canEditOrder(sale)"
                                    :href="route('portal.orders.edit', sale.id)"
                                    class="mt-2 block text-[11px] font-semibold text-green-700 hover:underline dark:text-green-400"
                                >
                                    Modifier
                                </Link>
                            </div>
                        </div>
                    </div>
                </Card>

                <Card class="rise-in lg:col-span-1" :style="{ animationDelay: '160ms' }">
                    <template #title>Livraisons en cours</template>
                    <div v-if="!inDeliveryOrders.length" class="text-sm text-stone-500 dark:text-neutral-400">
                        Aucune livraison en cours.
                    </div>
                    <div v-else class="divide-y divide-stone-200 dark:divide-neutral-700">
                        <div v-for="sale in inDeliveryOrders" :key="sale.id" class="flex items-center justify-between gap-3 py-3 text-sm">
                            <div>
                                <p class="font-semibold text-stone-800 dark:text-neutral-200">
                                    {{ orderLabel(sale) }}
                                </p>
                                <p class="text-xs text-stone-500 dark:text-neutral-400">
                                    {{ sale.scheduled_for ? `Livraison prevue: ${formatDateTime(sale.scheduled_for)}` : 'Livraison en cours' }}
                                </p>
                            </div>
                            <div class="text-right">
                                <p class="font-semibold text-stone-800 dark:text-neutral-200">
                                    {{ formatCurrency(sale.total) }}
                                </p>
                                <span
                                    class="rounded-full px-2 py-1 text-[10px] font-semibold"
                                    :class="fulfillmentBadge.out_for_delivery"
                                >
                                    En cours
                                </span>
                            </div>
                        </div>
                    </div>
                </Card>

                <Card class="rise-in border border-amber-200 bg-amber-50 dark:border-amber-500/20 dark:bg-amber-500/10" :style="{ animationDelay: '200ms' }">
                    <template #title>Alertes livraison</template>
                    <div v-if="!deliveryAlerts.length" class="text-sm text-stone-600 dark:text-amber-200">
                        Aucune alerte de livraison.
                    </div>
                    <div v-else class="space-y-3 text-sm text-stone-700 dark:text-amber-100">
                        <div v-for="sale in deliveryAlerts" :key="sale.id" class="rounded-sm border border-amber-200 bg-white p-3 dark:border-amber-500/30 dark:bg-neutral-900">
                            <p class="font-semibold text-stone-800 dark:text-neutral-100">
                                {{ orderLabel(sale) }}
                            </p>
                            <p class="text-xs text-stone-600 dark:text-amber-200">
                                {{ sale.scheduled_for ? `Livraison prevue: ${formatDateTime(sale.scheduled_for)}` : 'Livraison en cours' }}
                            </p>
                            <div class="mt-2 flex items-center justify-between text-xs text-stone-500 dark:text-amber-200">
                                <span>{{ fulfillmentLabel(sale) }}</span>
                                <span class="font-semibold">{{ formatCurrency(sale.total) }}</span>
                            </div>
                        </div>
                    </div>
                </Card>
            </div>

            <Card class="rise-in" :style="{ animationDelay: '160ms' }">
                <template #title>Dernieres ventes</template>
                <div v-if="!sales.length" class="text-sm text-stone-500 dark:text-neutral-400">
                    Aucune vente recente.
                </div>
                <div v-else class="divide-y divide-stone-200 dark:divide-neutral-700">
                    <div v-for="sale in sales" :key="sale.id" class="flex items-center justify-between gap-3 py-3 text-sm">
                        <div>
                            <p class="font-semibold text-stone-800 dark:text-neutral-200">
                                {{ sale.number || `Sale #${sale.id}` }}
                            </p>
                            <p class="text-xs text-stone-500 dark:text-neutral-400">
                                {{ formatDate(sale.created_at) }}
                            </p>
                        </div>
                        <div class="text-right">
                            <p class="font-semibold text-stone-800 dark:text-neutral-200">
                                {{ formatCurrency(sale.total) }}
                            </p>
                            <span
                                class="rounded-full px-2 py-1 text-[10px] font-semibold"
                                :class="statusClasses[sale.status] || statusClasses.draft"
                            >
                                {{ statusLabels[sale.status] || sale.status }}
                            </span>
                            <Link
                                v-if="sale.status === 'paid'"
                                :href="route('portal.orders.reorder', sale.id)"
                                method="post"
                                class="mt-2 block text-[11px] font-semibold text-green-700 hover:underline dark:text-green-400"
                            >
                                Recommander
                            </Link>
                        </div>
                    </div>
                </div>
            </Card>
        </div>
    </AuthenticatedLayout>
</template>

<style scoped>
@keyframes rise {
    from {
        opacity: 0;
        transform: translateY(6px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.rise-in {
    animation: rise 0.45s ease both;
}
</style>
