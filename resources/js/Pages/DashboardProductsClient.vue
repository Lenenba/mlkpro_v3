<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
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
