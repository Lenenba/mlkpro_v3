<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Card from '@/Components/UI/Card.vue';
import { humanizeDate } from '@/utils/date';

const props = defineProps({
    stats: {
        type: Object,
        default: () => ({}),
    },
    recentSales: {
        type: Array,
        default: () => [],
    },
    stockAlerts: {
        type: Array,
        default: () => [],
    },
    topProducts: {
        type: Array,
        default: () => [],
    },
});

const formatCurrency = (value) =>
    `$${Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

const formatNumber = (value) =>
    Number(value || 0).toLocaleString(undefined, { maximumFractionDigits: 0 });

const kpiCards = computed(() => ([
    {
        label: 'Ventes aujourd hui',
        value: formatNumber(props.stats.sales_today),
        tone: 'emerald',
    },
    {
        label: 'Revenu aujourd hui',
        value: formatCurrency(props.stats.revenue_today),
        tone: 'sky',
    },
    {
        label: 'Bas stock',
        value: formatNumber(props.stats.low_stock),
        tone: 'amber',
    },
    {
        label: 'Rupture',
        value: formatNumber(props.stats.out_of_stock),
        tone: 'red',
    },
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

const customerLabel = (sale) => {
    const customer = sale?.customer;
    if (customer?.company_name) {
        return customer.company_name;
    }
    const name = [customer?.first_name, customer?.last_name].filter(Boolean).join(' ');
    return name || 'Client';
};

const formatDate = (value) => humanizeDate(value);

const kpiTone = {
    red: 'bg-red-100 text-red-700 dark:bg-red-500/10 dark:text-red-300',
    amber: 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300',
    sky: 'bg-sky-100 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300',
    emerald: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300',
};
</script>

<template>
    <AuthenticatedLayout>
        <Head title="Dashboard ventes" />

        <div class="space-y-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">Dashboard ventes</h1>
                    <p class="text-sm text-stone-500 dark:text-neutral-400">Suivi rapide pour les vendeurs.</p>
                </div>
                <Link
                    :href="route('sales.create')"
                    class="rounded-sm bg-green-600 px-3 py-2 text-xs font-semibold text-white hover:bg-green-700"
                >
                    Nouvelle vente
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
                <Card class="rise-in lg:col-span-2" :style="{ animationDelay: '120ms' }">
                    <template #title>Ventes recentes</template>
                    <div v-if="!recentSales.length" class="text-sm text-stone-500 dark:text-neutral-400">
                        Aucune vente recente.
                    </div>
                    <div v-else class="divide-y divide-stone-200 dark:divide-neutral-700">
                        <div v-for="sale in recentSales" :key="sale.id" class="flex items-center justify-between gap-3 py-3 text-sm">
                            <div>
                                <p class="font-semibold text-stone-800 dark:text-neutral-200">
                                    {{ sale.number || `Sale #${sale.id}` }}
                                </p>
                                <p class="text-xs text-stone-500 dark:text-neutral-400">
                                    {{ customerLabel(sale) }} - {{ formatDate(sale.created_at) }}
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
                            </div>
                        </div>
                    </div>
                </Card>

                <Card class="rise-in" :style="{ animationDelay: '160ms' }">
                    <template #title>Alertes stock</template>
                    <div v-if="!stockAlerts.length" class="text-sm text-stone-500 dark:text-neutral-400">
                        Aucun produit critique.
                    </div>
                    <div v-else class="space-y-2 text-sm">
                        <div v-for="product in stockAlerts" :key="product.id" class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-semibold text-stone-800 dark:text-neutral-200">{{ product.name }}</p>
                                <p class="text-xs text-stone-500 dark:text-neutral-400">Stock {{ product.stock }} / min {{ product.minimum_stock }}</p>
                            </div>
                            <span
                                class="rounded-full px-2 py-1 text-[10px] font-semibold"
                                :class="product.stock <= 0
                                    ? 'bg-red-100 text-red-700 dark:bg-red-500/10 dark:text-red-300'
                                    : 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300'"
                            >
                                {{ product.stock <= 0 ? 'Rupture' : 'Bas' }}
                            </span>
                        </div>
                    </div>
                </Card>
            </div>

            <Card v-if="topProducts.length" class="rise-in" :style="{ animationDelay: '200ms' }">
                <template #title>Produits les plus vendus</template>
                <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
                    <div v-for="product in topProducts" :key="product.id" class="flex items-center gap-3 rounded-sm border border-stone-200 p-3 dark:border-neutral-700">
                        <img
                            :src="product.image_url"
                            :alt="product.name"
                            class="h-12 w-12 rounded-sm border border-stone-200 object-cover dark:border-neutral-700"
                        />
                        <div class="flex-1">
                            <p class="text-sm font-semibold text-stone-800 dark:text-neutral-200">{{ product.name }}</p>
                            <p class="text-xs text-stone-500 dark:text-neutral-400">Quantite {{ product.quantity }}</p>
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
