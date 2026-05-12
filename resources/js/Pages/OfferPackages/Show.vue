<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { humanizeDate } from '@/utils/date';
import { crmButtonClass } from '@/utils/crmButtonStyles';

const props = defineProps({
    offer: {
        type: Object,
        required: true,
    },
    kpis: {
        type: Object,
        default: () => ({}),
    },
    customers: {
        type: Array,
        default: () => [],
    },
    recentUsages: {
        type: Array,
        default: () => [],
    },
    tenantCurrencyCode: {
        type: String,
        default: 'CAD',
    },
});

const typeLabels = {
    pack: 'Pack',
    forfait: 'Forfait',
};

const statusLabels = {
    draft: 'Brouillon',
    active: 'Actif',
    archived: 'Archive',
    consumed: 'Consomme',
    expired: 'Expire',
    cancelled: 'Annule',
    sent: 'Envoyee',
    paid: 'Payee',
    void: 'Annulee',
    payment_due: 'Paiement du',
    suspended: 'Suspendu',
};

const unitLabels = {
    session: 'seance',
    hour: 'heure',
    visit: 'visite',
    credit: 'credit',
    month: 'mois',
};

const recurrenceLabels = {
    monthly: 'Mensuel',
    quarterly: 'Trimestriel',
    yearly: 'Annuel',
};

const money = (value, currency = props.offer.currency_code || props.tenantCurrencyCode) => {
    const amount = Number(value || 0);

    return new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency: currency || props.tenantCurrencyCode,
    }).format(amount);
};

const formatDate = (value) => humanizeDate(value) || '-';
const labelFor = (labels, value) => labels[value] || String(value || '-');
const unitLabel = (value) => labelFor(unitLabels, value);
const statusLabel = (value) => labelFor(statusLabels, value);
const typeLabel = (value) => labelFor(typeLabels, value);
const recurrenceLabel = (value) => labelFor(recurrenceLabels, value);

const usageRate = computed(() => Number(props.kpis.usage_rate || 0));
const usageProgressStyle = computed(() => ({
    width: `${Math.min(100, Math.max(0, usageRate.value))}%`,
}));

const kpiCards = computed(() => [
    {
        label: 'CA total',
        value: money(props.kpis.total_revenue || 0),
        helper: `${props.kpis.sold_count || 0} vente${Number(props.kpis.sold_count || 0) > 1 ? 's' : ''}`,
    },
    {
        label: 'Clients lies',
        value: props.kpis.assigned_customers || 0,
        helper: `${props.kpis.active_customers || 0} actif${Number(props.kpis.active_customers || 0) > 1 ? 's' : ''}`,
    },
    {
        label: 'Forfaits actifs',
        value: props.kpis.active_count || 0,
        helper: `${props.kpis.payment_due_count || 0} paiement du`,
    },
    {
        label: 'Solde restant',
        value: props.kpis.remaining_quantity || 0,
        helper: unitLabel(props.offer.unit_type),
    },
    {
        label: 'Consommation',
        value: `${usageRate.value}%`,
        helper: `${props.kpis.consumed_quantity || 0}/${props.kpis.initial_quantity || 0}`,
    },
    {
        label: 'Recurrent',
        value: props.kpis.recurring_count || 0,
        helper: `${props.kpis.suspended_count || 0} suspendu${Number(props.kpis.suspended_count || 0) > 1 ? 's' : ''}`,
    },
]);

const statusBreakdown = computed(() => Object.entries(props.kpis.status_breakdown || {}).map(([status, count]) => ({
    status,
    count,
})));

const statusBadgeClass = (status) => {
    if (status === 'active' || status === 'paid') {
        return 'bg-green-100 text-green-800 dark:bg-green-500/10 dark:text-green-300';
    }
    if (status === 'payment_due' || status === 'sent') {
        return 'bg-amber-100 text-amber-800 dark:bg-amber-500/10 dark:text-amber-300';
    }
    if (status === 'suspended' || status === 'expired' || status === 'cancelled' || status === 'void') {
        return 'bg-rose-100 text-rose-800 dark:bg-rose-500/10 dark:text-rose-300';
    }
    if (status === 'consumed') {
        return 'bg-sky-100 text-sky-800 dark:bg-sky-500/10 dark:text-sky-300';
    }

    return 'bg-stone-100 text-stone-700 dark:bg-neutral-700 dark:text-neutral-300';
};

const packageProgressStyle = (customerPackage) => {
    const initial = Number(customerPackage.initial_quantity || 0);
    const consumed = Number(customerPackage.consumed_quantity || 0);
    const percent = initial > 0 ? (consumed / initial) * 100 : 0;

    return {
        width: `${Math.min(100, Math.max(0, percent))}%`,
    };
};

const customerContact = (customer) => [customer?.email, customer?.phone].filter(Boolean).join(' - ') || '-';
</script>

<template>
    <Head :title="offer.name" />

    <AuthenticatedLayout>
        <div class="space-y-4">
            <section class="rounded-sm border border-stone-200 border-t-4 border-t-green-600 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0 space-y-3">
                        <Link
                            :href="route('offer-packages.index')"
                            class="inline-flex items-center gap-2 text-sm font-medium text-stone-500 transition hover:text-green-700 dark:text-neutral-400 dark:hover:text-green-300"
                        >
                            <svg class="size-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="m15 18-6-6 6-6" />
                            </svg>
                            <span>Retour au catalogue</span>
                        </Link>

                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                <h1 class="text-2xl font-semibold text-stone-900 dark:text-neutral-50">
                                    {{ offer.name }}
                                </h1>
                                <span class="inline-flex rounded-full bg-stone-100 px-2 py-0.5 text-xs font-semibold text-stone-700 dark:bg-neutral-700 dark:text-neutral-300">
                                    {{ typeLabel(offer.type) }}
                                </span>
                                <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold" :class="statusBadgeClass(offer.status)">
                                    {{ statusLabel(offer.status) }}
                                </span>
                                <span v-if="offer.is_public" class="inline-flex rounded-full bg-green-100 px-2 py-0.5 text-xs font-semibold text-green-800 dark:bg-green-500/10 dark:text-green-300">
                                    Public
                                </span>
                                <span v-if="offer.is_recurring" class="inline-flex rounded-full bg-sky-100 px-2 py-0.5 text-xs font-semibold text-sky-800 dark:bg-sky-500/10 dark:text-sky-300">
                                    {{ recurrenceLabel(offer.recurrence_frequency) }}
                                </span>
                                <span v-if="offer.carry_over_unused_balance" class="inline-flex rounded-full bg-green-100 px-2 py-0.5 text-xs font-semibold text-green-800 dark:bg-green-500/10 dark:text-green-300">
                                    Reliquat reporte
                                </span>
                            </div>
                            <p class="mt-2 max-w-3xl text-sm text-stone-500 dark:text-neutral-400">
                                {{ offer.description || 'Aucune description.' }}
                            </p>
                        </div>
                    </div>

                    <div class="rounded-sm border border-stone-200 bg-stone-50 px-4 py-3 text-right dark:border-neutral-700 dark:bg-neutral-800">
                        <div class="text-xs text-stone-500 dark:text-neutral-400">
                            Prix catalogue
                        </div>
                        <div class="mt-1 text-2xl font-semibold text-stone-900 dark:text-neutral-50">
                            {{ money(offer.price, offer.currency_code) }}
                        </div>
                        <div class="text-xs text-stone-500 dark:text-neutral-400">
                            Cree le {{ formatDate(offer.created_at) }}
                        </div>
                    </div>
                </div>
            </section>

            <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-6">
                <div
                    v-for="card in kpiCards"
                    :key="card.label"
                    class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900"
                >
                    <div class="text-xs font-medium uppercase text-stone-500 dark:text-neutral-400">
                        {{ card.label }}
                    </div>
                    <div class="mt-2 text-2xl font-semibold text-stone-900 dark:text-neutral-50">
                        {{ card.value }}
                    </div>
                    <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                        {{ card.helper }}
                    </div>
                </div>
            </section>

            <section class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_360px]">
                <div class="space-y-4">
                    <div class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <h2 class="text-base font-semibold text-stone-900 dark:text-neutral-50">
                                    Clients rattaches
                                </h2>
                                <p class="mt-1 text-sm text-stone-500 dark:text-neutral-400">
                                    Derniers forfaits client crees a partir de cette offre.
                                </p>
                            </div>
                            <div class="text-sm font-medium text-stone-600 dark:text-neutral-300">
                                {{ customers.length }} affiches
                            </div>
                        </div>

                        <div class="mt-4 overflow-x-auto">
                            <table class="min-w-full divide-y divide-stone-200 text-sm dark:divide-neutral-700">
                                <thead>
                                    <tr class="text-left text-xs font-medium uppercase text-stone-500 dark:text-neutral-400">
                                        <th class="py-2 pr-4">Client</th>
                                        <th class="px-4 py-2">Statut</th>
                                        <th class="px-4 py-2">Solde</th>
                                        <th class="px-4 py-2">Periode</th>
                                        <th class="px-4 py-2">Facture</th>
                                        <th class="py-2 pl-4 text-right">CA</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-stone-100 dark:divide-neutral-800">
                                    <tr v-for="customerPackage in customers" :key="customerPackage.id" class="text-stone-700 dark:text-neutral-200">
                                        <td class="py-3 pr-4">
                                            <Link
                                                v-if="customerPackage.customer"
                                                :href="route('customer.show', customerPackage.customer.id)"
                                                class="font-semibold text-stone-900 transition hover:text-green-700 dark:text-neutral-50 dark:hover:text-green-300"
                                            >
                                                {{ customerPackage.customer.name }}
                                            </Link>
                                            <div v-else class="font-semibold text-stone-900 dark:text-neutral-50">
                                                Client supprime
                                            </div>
                                            <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                                {{ customerContact(customerPackage.customer) }}
                                            </div>
                                        </td>
                                        <td class="px-4 py-3">
                                            <div class="flex flex-wrap gap-1.5">
                                                <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold" :class="statusBadgeClass(customerPackage.status)">
                                                    {{ statusLabel(customerPackage.status) }}
                                                </span>
                                                <span v-if="customerPackage.recurrence_status" class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold" :class="statusBadgeClass(customerPackage.recurrence_status)">
                                                    {{ statusLabel(customerPackage.recurrence_status) }}
                                                </span>
                                            </div>
                                        </td>
                                        <td class="px-4 py-3">
                                            <div class="font-medium text-stone-900 dark:text-neutral-50">
                                                {{ customerPackage.remaining_quantity }} / {{ customerPackage.initial_quantity }}
                                                {{ unitLabel(customerPackage.unit_type) }}
                                            </div>
                                            <div class="mt-2 h-1.5 w-32 overflow-hidden rounded-full bg-stone-100 dark:bg-neutral-800">
                                                <div class="h-full rounded-full bg-green-600" :style="packageProgressStyle(customerPackage)"></div>
                                            </div>
                                            <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                                {{ customerPackage.usages_count }} usage{{ Number(customerPackage.usages_count || 0) > 1 ? 's' : '' }}
                                            </div>
                                        </td>
                                        <td class="px-4 py-3 text-sm text-stone-600 dark:text-neutral-300">
                                            <div>{{ formatDate(customerPackage.starts_at) }}</div>
                                            <div class="text-xs text-stone-500 dark:text-neutral-400">
                                                Expire: {{ formatDate(customerPackage.expires_at) }}
                                            </div>
                                            <div v-if="customerPackage.next_renewal_at" class="text-xs text-stone-500 dark:text-neutral-400">
                                                Renouv.: {{ formatDate(customerPackage.next_renewal_at) }}
                                            </div>
                                        </td>
                                        <td class="px-4 py-3">
                                            <Link
                                                v-if="customerPackage.renewal_invoice"
                                                :href="route('invoice.show', customerPackage.renewal_invoice.id)"
                                                class="font-medium text-green-700 hover:text-green-800 dark:text-green-300"
                                            >
                                                {{ customerPackage.renewal_invoice.number || 'Facture liee' }}
                                            </Link>
                                            <Link
                                                v-else-if="customerPackage.invoice"
                                                :href="route('invoice.show', customerPackage.invoice.id)"
                                                class="font-medium text-stone-700 hover:text-green-700 dark:text-neutral-200 dark:hover:text-green-300"
                                            >
                                                {{ customerPackage.invoice.number || 'Facture initiale' }}
                                            </Link>
                                            <span v-else class="text-stone-400 dark:text-neutral-500">-</span>
                                        </td>
                                        <td class="py-3 pl-4 text-right font-semibold text-stone-900 dark:text-neutral-50">
                                            {{ money(customerPackage.price_paid, customerPackage.currency_code) }}
                                        </td>
                                    </tr>
                                    <tr v-if="!customers.length">
                                        <td colspan="6" class="py-8 text-center text-sm text-stone-500 dark:text-neutral-400">
                                            Aucun client n est encore rattache a cette offre.
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <h2 class="text-base font-semibold text-stone-900 dark:text-neutral-50">
                            Consommations recentes
                        </h2>
                        <div class="mt-4 space-y-3">
                            <div
                                v-for="usage in recentUsages"
                                :key="usage.id"
                                class="flex flex-wrap items-center justify-between gap-3 rounded-sm border border-stone-200 bg-stone-50 px-4 py-3 dark:border-neutral-700 dark:bg-neutral-800"
                            >
                                <div>
                                    <Link
                                        v-if="usage.customer"
                                        :href="route('customer.show', usage.customer.id)"
                                        class="font-semibold text-stone-900 transition hover:text-green-700 dark:text-neutral-50 dark:hover:text-green-300"
                                    >
                                        {{ usage.customer.name }}
                                    </Link>
                                    <div class="text-xs text-stone-500 dark:text-neutral-400">
                                        {{ usage.note || usage.source || 'Consommation forfait' }}
                                    </div>
                                </div>
                                <div class="text-right">
                                    <div class="font-semibold text-stone-900 dark:text-neutral-50">
                                        -{{ usage.quantity }} {{ unitLabel(offer.unit_type) }}
                                    </div>
                                    <div class="text-xs text-stone-500 dark:text-neutral-400">
                                        {{ formatDate(usage.used_at) }}
                                    </div>
                                </div>
                            </div>
                            <div v-if="!recentUsages.length" class="rounded-sm border border-dashed border-stone-200 px-4 py-8 text-center text-sm text-stone-500 dark:border-neutral-700 dark:text-neutral-400">
                                Aucune consommation enregistree pour cette offre.
                            </div>
                        </div>
                    </div>
                </div>

                <aside class="space-y-4">
                    <div class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <h2 class="text-base font-semibold text-stone-900 dark:text-neutral-50">
                            Sante du forfait
                        </h2>
                        <div class="mt-4">
                            <div class="flex items-center justify-between text-sm">
                                <span class="text-stone-500 dark:text-neutral-400">Taux consomme</span>
                                <span class="font-semibold text-stone-900 dark:text-neutral-50">{{ usageRate }}%</span>
                            </div>
                            <div class="mt-2 h-2 overflow-hidden rounded-full bg-stone-100 dark:bg-neutral-800">
                                <div class="h-full rounded-full bg-green-600" :style="usageProgressStyle"></div>
                            </div>
                        </div>

                        <div class="mt-5 space-y-2">
                            <div
                                v-for="item in statusBreakdown"
                                :key="item.status"
                                class="flex items-center justify-between rounded-sm bg-stone-50 px-3 py-2 text-sm dark:bg-neutral-800"
                            >
                                <span class="text-stone-600 dark:text-neutral-300">{{ statusLabel(item.status) }}</span>
                                <span class="font-semibold text-stone-900 dark:text-neutral-50">{{ item.count }}</span>
                            </div>
                        </div>
                    </div>

                    <div class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <h2 class="text-base font-semibold text-stone-900 dark:text-neutral-50">
                            Configuration
                        </h2>
                        <dl class="mt-4 space-y-3 text-sm">
                            <div class="flex justify-between gap-4">
                                <dt class="text-stone-500 dark:text-neutral-400">Quantite incluse</dt>
                                <dd class="font-medium text-stone-900 dark:text-neutral-50">
                                    {{ offer.included_quantity || '-' }} {{ unitLabel(offer.unit_type) }}
                                </dd>
                            </div>
                            <div class="flex justify-between gap-4">
                                <dt class="text-stone-500 dark:text-neutral-400">Validite</dt>
                                <dd class="font-medium text-stone-900 dark:text-neutral-50">
                                    {{ offer.validity_days ? `${offer.validity_days} jours` : 'Sans limite' }}
                                </dd>
                            </div>
                            <div class="flex justify-between gap-4">
                                <dt class="text-stone-500 dark:text-neutral-400">Recurrence</dt>
                                <dd class="font-medium text-stone-900 dark:text-neutral-50">
                                    {{ offer.is_recurring ? recurrenceLabel(offer.recurrence_frequency) : 'Non recurrent' }}
                                </dd>
                            </div>
                            <div class="flex justify-between gap-4">
                                <dt class="text-stone-500 dark:text-neutral-400">Rappel renouvellement</dt>
                                <dd class="font-medium text-stone-900 dark:text-neutral-50">
                                    {{ offer.renewal_notice_days ? `${offer.renewal_notice_days} jours` : '-' }}
                                </dd>
                            </div>
                            <div class="flex justify-between gap-4">
                                <dt class="text-stone-500 dark:text-neutral-400">Delai suspension</dt>
                                <dd class="font-medium text-stone-900 dark:text-neutral-50">
                                    {{ offer.payment_grace_days ? `${offer.payment_grace_days} jours` : '-' }}
                                </dd>
                            </div>
                            <div class="flex justify-between gap-4">
                                <dt class="text-stone-500 dark:text-neutral-400">Relances impayees</dt>
                                <dd class="font-medium text-stone-900 dark:text-neutral-50">
                                    {{ (offer.payment_reminder_days || []).length ? `${offer.payment_reminder_days.join(', ')} jours` : '-' }}
                                </dd>
                            </div>
                            <div class="flex justify-between gap-4">
                                <dt class="text-stone-500 dark:text-neutral-400">Reliquat</dt>
                                <dd class="font-medium text-stone-900 dark:text-neutral-50">
                                    {{ offer.carry_over_unused_balance ? 'Reporte' : 'Non reporte' }}
                                </dd>
                            </div>
                        </dl>
                    </div>

                    <div class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <h2 class="text-base font-semibold text-stone-900 dark:text-neutral-50">
                            Elements inclus
                        </h2>
                        <div class="mt-4 space-y-3">
                            <div
                                v-for="item in offer.items"
                                :key="item.id"
                                class="rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800"
                            >
                                <div class="font-semibold text-stone-900 dark:text-neutral-50">
                                    {{ item.name_snapshot || item.product_name || 'Element' }}
                                </div>
                                <div class="mt-1 flex items-center justify-between gap-3 text-xs text-stone-500 dark:text-neutral-400">
                                    <span>{{ item.quantity }} x {{ money(item.unit_price, offer.currency_code) }}</span>
                                    <span>{{ item.product_type || item.item_type_snapshot || '-' }}</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <Link
                        :href="route('offer-packages.index')"
                        :class="[crmButtonClass('secondary', 'dialog'), 'w-full justify-center']"
                    >
                        Retour a la liste
                    </Link>
                </aside>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
