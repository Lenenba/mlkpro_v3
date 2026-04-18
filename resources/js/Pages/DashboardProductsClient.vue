<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ClientPortalTabs from '@/Components/Portal/ClientPortalTabs.vue';
import { humanizeDate } from '@/utils/date';
import { useI18n } from 'vue-i18n';
import { useCurrencyFormatter } from '@/utils/currency';

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

const { formatCurrency } = useCurrencyFormatter();
const { t } = useI18n();

const formatNumber = (value) =>
    Number(value || 0).toLocaleString(undefined, { maximumFractionDigits: 0 });

const companyName = computed(() => props.company?.name || t('portal_shop.header.company_fallback'));

const productTabs = computed(() => ([
    {
        id: 'orders',
        label: t('client_orders.title'),
        description: props.company?.name
            ? t('client_orders.subtitle_company', { name: props.company.name })
            : t('client_orders.subtitle_default'),
        href: route('dashboard'),
        badge: formatNumber(props.stats.orders_total),
        tone: 'orange',
        active: true,
    },
    {
        id: 'shop',
        label: t('portal_shop.header.section'),
        description: t('portal_shop.header.create_subtitle'),
        href: route('portal.orders.index'),
        tone: 'indigo',
    },
]));

const heroCards = computed(() => ([
    { key: 'orders', label: t('client_orders.kpi.orders'), value: formatNumber(props.stats.orders_total), tone: 'orange' },
    { key: 'pending', label: t('client_orders.kpi.pending'), value: formatNumber(props.stats.orders_pending), tone: 'amber' },
    { key: 'paid', label: t('client_orders.kpi.paid'), value: formatNumber(props.stats.orders_paid), tone: 'emerald' },
]));

const statusLabels = computed(() => ({
    draft: t('client_orders.status.draft'),
    pending: t('client_orders.status.pending'),
    paid: t('client_orders.status.paid'),
    canceled: t('client_orders.status.canceled'),
}));

const paymentStatusLabels = computed(() => ({
    unpaid: t('client_orders.status.unpaid'),
    deposit_required: t('client_orders.status.deposit_required'),
    partial: t('client_orders.status.partial'),
    paid: t('client_orders.status.paid'),
    canceled: t('client_orders.status.canceled'),
    pending: t('client_orders.status.pending'),
}));

const statusClasses = {
    draft: 'bg-stone-100 text-stone-600 dark:bg-neutral-800 dark:text-neutral-300',
    pending: 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-200',
    paid: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-200',
    canceled: 'bg-red-100 text-red-700 dark:bg-red-500/10 dark:text-red-200',
};

const paymentStatusClasses = {
    unpaid: 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-200',
    deposit_required: 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-200',
    partial: 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-200',
    paid: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-200',
    canceled: 'bg-red-100 text-red-700 dark:bg-red-500/10 dark:text-red-200',
    pending: 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-200',
};

const fulfillmentLabels = computed(() => ({
    pending: t('client_orders.fulfillment.pending'),
    preparing: t('client_orders.fulfillment.preparing'),
    out_for_delivery: t('client_orders.fulfillment.out_for_delivery'),
    ready_for_pickup: t('client_orders.fulfillment.ready_for_pickup'),
    completed: t('client_orders.fulfillment.completed'),
    confirmed: t('client_orders.fulfillment.confirmed'),
}));

const fulfillmentBadge = {
    pending: 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-200',
    preparing: 'bg-sky-100 text-sky-700 dark:bg-sky-500/10 dark:text-sky-200',
    out_for_delivery: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-200',
    ready_for_pickup: 'bg-indigo-100 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-200',
    completed: 'bg-stone-100 text-stone-600 dark:bg-neutral-800 dark:text-neutral-300',
    confirmed: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-200',
};

const toneAccent = {
    orange: 'from-orange-500/15 via-orange-100 to-white text-orange-700 dark:from-orange-500/15 dark:via-orange-500/10 dark:to-neutral-900 dark:text-orange-200',
    amber: 'from-amber-500/15 via-amber-100 to-white text-amber-700 dark:from-amber-500/15 dark:via-amber-500/10 dark:to-neutral-900 dark:text-amber-200',
    emerald: 'from-emerald-500/15 via-emerald-100 to-white text-emerald-700 dark:from-emerald-500/15 dark:via-emerald-500/10 dark:to-neutral-900 dark:text-emerald-200',
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

const orderLabel = (sale) =>
    sale?.number || t('client_orders.labels.order_label', { id: sale?.id || '-' });

const fulfillmentLabel = (sale) => {
    if (!sale?.fulfillment_status) {
        return t('client_orders.fulfillment.pending');
    }
    if (sale.fulfillment_status === 'completed' && !sale.delivery_confirmed_at) {
        return t('client_orders.fulfillment.completed_unconfirmed');
    }
    return fulfillmentLabels.value[sale.fulfillment_status] || sale.fulfillment_status;
};

const orderActionLabel = (sale) => {
    if (!sale) {
        return null;
    }
    if (sale.fulfillment_status === 'completed' && !sale.delivery_confirmed_at) {
        return t('client_orders.actions.confirm_delivery');
    }
    if (sale.fulfillment_status === 'preparing') {
        return t('client_orders.actions.view_invoice');
    }
    if (canEditOrder(sale)) {
        return t('client_orders.actions.edit');
    }
    return t('client_orders.actions.view');
};

const paymentLabel = (sale) => {
    const key = sale?.payment_status || sale?.status || '';
    return paymentStatusLabels.value[key] || key;
};

const paymentBadgeClass = (sale) => {
    const key = sale?.payment_status || sale?.status || '';
    return paymentStatusClasses[key] || statusClasses.draft;
};

const canEditOrder = (sale) => {
    if (!sale || sale.status === 'canceled') {
        return false;
    }

    const blocked = ['out_for_delivery', 'ready_for_pickup', 'completed', 'confirmed'];
    return !blocked.includes(sale.fulfillment_status);
};

const orderActionRoute = (sale) => {
    if (!sale) {
        return route('dashboard');
    }
    if (sale.fulfillment_status === 'preparing' || !canEditOrder(sale)) {
        return route('portal.orders.show', sale.id);
    }
    return route('portal.orders.edit', sale.id);
};

const paidSummary = (sale) => {
    const paid = Number(sale?.payments_sum_amount || 0);
    if (paid <= 0) {
        return null;
    }

    return `${formatCurrency(paid)} / ${formatCurrency(sale?.total || 0)}`;
};
</script>

<template>
    <AuthenticatedLayout>
        <Head :title="$t('client_orders.title')" />

        <div class="mx-auto max-w-6xl space-y-5">
            <section class="overflow-hidden rounded-[2rem] border border-stone-200/80 bg-white shadow-[0_30px_80px_-50px_rgba(15,23,42,0.45)] dark:border-neutral-800 dark:bg-neutral-900">
                <div class="grid gap-0 lg:grid-cols-[1.45fr_0.95fr]">
                    <div class="relative overflow-hidden bg-gradient-to-br from-orange-500 via-amber-400 to-orange-300 px-6 py-7 text-white sm:px-8">
                        <div class="absolute -right-10 top-8 h-40 w-40 rounded-full bg-white/10 blur-2xl"></div>
                        <div class="absolute bottom-0 right-16 h-28 w-28 rounded-full border border-white/15"></div>

                        <div class="relative flex h-full flex-col justify-between gap-6">
                            <div class="flex items-start justify-between gap-4">
                                <div class="space-y-4">
                                    <div class="inline-flex items-center gap-2 rounded-full border border-white/20 bg-white/10 px-3 py-1 text-xs font-semibold uppercase tracking-[0.18em]">
                                        <span class="inline-flex h-8 w-8 items-center justify-center rounded-full bg-white/16">
                                            <svg class="size-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path d="M6 2v4" />
                                                <path d="M18 2v4" />
                                                <rect width="18" height="18" x="3" y="4" rx="2" />
                                                <path d="M3 10h18" />
                                                <path d="M8 14h8" />
                                                <path d="M8 18h5" />
                                            </svg>
                                        </span>
                                        {{ companyName }}
                                    </div>

                                    <div>
                                        <h1 class="text-3xl font-semibold tracking-tight sm:text-[2.15rem]">
                                            {{ $t('client_orders.title') }}
                                        </h1>
                                        <p class="mt-2 max-w-xl text-sm leading-6 text-white/85 sm:text-base">
                                            {{ props.company?.name ? $t('client_orders.subtitle_company', { name: props.company.name }) : $t('client_orders.subtitle_default') }}
                                        </p>
                                    </div>
                                </div>

                                <div class="rounded-[1.35rem] border border-white/20 bg-white/10 px-4 py-3 text-right backdrop-blur">
                                    <p class="text-xs uppercase tracking-[0.18em] text-white/70">
                                        {{ $t('client_orders.kpi.orders') }}
                                    </p>
                                    <p class="mt-2 text-3xl font-semibold">
                                        {{ formatNumber(props.stats.orders_total) }}
                                    </p>
                                </div>
                            </div>

                            <div class="flex flex-wrap items-center gap-3">
                                <Link
                                    :href="route('portal.orders.index')"
                                    class="inline-flex items-center gap-2 rounded-full bg-white px-4 py-2 text-sm font-semibold text-orange-700 transition hover:-translate-y-0.5 hover:shadow-md"
                                >
                                    {{ $t('client_orders.actions.order') }}
                                </Link>
                                <span class="rounded-full border border-white/20 bg-white/10 px-3 py-2 text-xs font-medium text-white/80">
                                    {{ $t('client_orders.kpi.amount_paid') }}: {{ formatCurrency(props.stats.amount_paid) }}
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="flex flex-col justify-between gap-5 bg-stone-50/80 p-5 dark:bg-neutral-950/70">
                        <div class="space-y-2">
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-stone-500 dark:text-neutral-400">
                                {{ $t('client_orders.sections.pending_orders') }}
                            </p>
                            <p class="text-2xl font-semibold text-stone-900 dark:text-neutral-100">
                                {{ formatNumber(props.stats.orders_pending) }}
                            </p>
                            <p class="text-sm text-stone-600 dark:text-neutral-400">
                                {{ $t('client_orders.kpi.paid') }}: {{ formatNumber(props.stats.orders_paid) }}
                            </p>
                        </div>

                        <div class="rounded-[1.5rem] border border-stone-200/80 bg-white px-4 py-4 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-stone-500 dark:text-neutral-400">
                                {{ $t('client_orders.kpi.amount_paid') }}
                            </p>
                            <p class="mt-2 text-2xl font-semibold text-stone-900 dark:text-neutral-100">
                                {{ formatCurrency(props.stats.amount_paid) }}
                            </p>
                            <p class="mt-2 text-xs text-stone-500 dark:text-neutral-400">
                                {{ props.sales.length ? formatDate(props.sales[0]?.created_at) : $t('client_orders.empty.recent_sales') }}
                            </p>
                        </div>
                    </div>
                </div>
            </section>

            <ClientPortalTabs
                :tabs="productTabs"
                aria-label="Product client sections"
                :columns="2"
            />

            <section class="grid gap-3 md:grid-cols-3">
                <article
                    v-for="card in heroCards"
                    :key="card.key"
                    class="rounded-[1.5rem] border border-stone-200/80 bg-gradient-to-br px-4 py-4 shadow-sm dark:border-neutral-800"
                    :class="toneAccent[card.tone]"
                >
                    <p class="text-xs font-semibold uppercase tracking-[0.18em]">
                        {{ card.label }}
                    </p>
                    <p class="mt-3 text-3xl font-semibold tracking-tight">
                        {{ card.value }}
                    </p>
                </article>
            </section>

            <div class="grid gap-4 xl:grid-cols-[1.3fr_1fr]">
                <section class="space-y-4">
                    <article class="rounded-[1.75rem] border border-stone-200/80 bg-white p-5 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <h2 class="text-lg font-semibold text-stone-900 dark:text-neutral-100">
                                    {{ $t('client_orders.sections.pending_orders') }}
                                </h2>
                                <p class="mt-1 text-sm text-stone-500 dark:text-neutral-400">
                                    {{ $t('client_orders.subtitle_default') }}
                                </p>
                            </div>
                            <span class="rounded-full border border-orange-200 bg-orange-50 px-3 py-1 text-xs font-semibold uppercase tracking-[0.16em] text-orange-700 dark:border-orange-500/20 dark:bg-orange-500/10 dark:text-orange-200">
                                {{ formatNumber(props.pendingOrders.length) }}
                            </span>
                        </div>

                        <div v-if="!pendingOrders.length" class="mt-4 rounded-[1.25rem] border border-dashed border-stone-200 bg-stone-50 px-4 py-5 text-sm text-stone-500 dark:border-neutral-800 dark:bg-neutral-950 dark:text-neutral-400">
                            {{ $t('client_orders.empty.pending_orders') }}
                        </div>

                        <div v-else class="mt-4 space-y-3">
                            <article
                                v-for="sale in pendingOrders"
                                :key="sale.id"
                                class="rounded-[1.4rem] border border-stone-200/80 bg-stone-50/80 p-4 transition hover:-translate-y-0.5 hover:shadow-md dark:border-neutral-800 dark:bg-neutral-950/70"
                            >
                                <div class="flex items-start justify-between gap-4">
                                    <div>
                                        <p class="text-base font-semibold text-stone-900 dark:text-neutral-100">
                                            {{ orderLabel(sale) }}
                                        </p>
                                        <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                            {{ formatDate(sale.created_at) }}
                                        </p>
                                    </div>
                                    <div class="text-right">
                                        <p class="text-lg font-semibold text-stone-900 dark:text-neutral-100">
                                            {{ formatCurrency(sale.total) }}
                                        </p>
                                        <p v-if="paidSummary(sale)" class="mt-1 text-[11px] text-stone-500 dark:text-neutral-400">
                                            {{ paidSummary(sale) }}
                                        </p>
                                    </div>
                                </div>

                                <div class="mt-4 flex flex-wrap items-center gap-2">
                                    <span
                                        class="rounded-full px-2.5 py-1 text-[11px] font-semibold"
                                        :class="fulfillmentBadge[sale.fulfillment_status] || fulfillmentBadge.pending"
                                    >
                                        {{ fulfillmentLabel(sale) }}
                                    </span>
                                    <span
                                        class="rounded-full px-2.5 py-1 text-[11px] font-semibold"
                                        :class="paymentBadgeClass(sale)"
                                    >
                                        {{ paymentLabel(sale) }}
                                    </span>
                                    <Link
                                        :href="orderActionRoute(sale)"
                                        class="ms-auto inline-flex items-center gap-2 rounded-full border border-stone-200 bg-white px-3 py-1.5 text-[11px] font-semibold text-stone-700 transition hover:border-stone-300 hover:bg-stone-100 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                                    >
                                        {{ orderActionLabel(sale) }}
                                        <svg class="size-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <path d="M5 12h14" />
                                            <path d="m12 5 7 7-7 7" />
                                        </svg>
                                    </Link>
                                </div>
                            </article>
                        </div>
                    </article>

                    <article class="rounded-[1.75rem] border border-stone-200/80 bg-white p-5 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <h2 class="text-lg font-semibold text-stone-900 dark:text-neutral-100">
                                    {{ $t('client_orders.sections.recent_sales') }}
                                </h2>
                                <p class="mt-1 text-sm text-stone-500 dark:text-neutral-400">
                                    {{ companyName }}
                                </p>
                            </div>
                            <Link
                                :href="route('portal.orders.index')"
                                class="rounded-full border border-stone-200 bg-stone-50 px-3 py-1.5 text-xs font-semibold text-stone-700 transition hover:bg-stone-100 dark:border-neutral-700 dark:bg-neutral-950 dark:text-neutral-200 dark:hover:bg-neutral-800"
                            >
                                {{ $t('client_orders.actions.order') }}
                            </Link>
                        </div>

                        <div v-if="!sales.length" class="mt-4 rounded-[1.25rem] border border-dashed border-stone-200 bg-stone-50 px-4 py-5 text-sm text-stone-500 dark:border-neutral-800 dark:bg-neutral-950 dark:text-neutral-400">
                            {{ $t('client_orders.empty.recent_sales') }}
                        </div>

                        <div v-else class="mt-4 space-y-3">
                            <article
                                v-for="sale in sales"
                                :key="sale.id"
                                class="rounded-[1.4rem] border border-stone-200/80 bg-white p-4 shadow-sm dark:border-neutral-800 dark:bg-neutral-950/70"
                            >
                                <div class="flex items-start justify-between gap-4">
                                    <div>
                                        <p class="text-base font-semibold text-stone-900 dark:text-neutral-100">
                                            {{ sale.number || $t('client_orders.labels.sale_label', { id: sale.id }) }}
                                        </p>
                                        <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                            {{ formatDate(sale.created_at) }}
                                        </p>
                                    </div>
                                    <div class="text-right">
                                        <p class="text-lg font-semibold text-stone-900 dark:text-neutral-100">
                                            {{ formatCurrency(sale.total) }}
                                        </p>
                                        <span
                                            class="mt-2 inline-flex rounded-full px-2.5 py-1 text-[11px] font-semibold"
                                            :class="statusClasses[sale.status] || statusClasses.draft"
                                        >
                                            {{ statusLabels[sale.status] || sale.status }}
                                        </span>
                                    </div>
                                </div>

                                <div class="mt-4 flex flex-wrap items-center gap-2">
                                    <span
                                        class="rounded-full px-2.5 py-1 text-[11px] font-semibold"
                                        :class="fulfillmentBadge[sale.fulfillment_status] || fulfillmentBadge.pending"
                                    >
                                        {{ fulfillmentLabel(sale) }}
                                    </span>

                                    <Link
                                        :href="orderActionRoute(sale)"
                                        class="ms-auto inline-flex items-center gap-2 rounded-full border border-stone-200 bg-stone-50 px-3 py-1.5 text-[11px] font-semibold text-stone-700 transition hover:bg-stone-100 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                                    >
                                        {{ orderActionLabel(sale) }}
                                    </Link>

                                    <Link
                                        v-if="sale.status === 'paid'"
                                        :href="route('portal.orders.reorder', sale.id)"
                                        method="post"
                                        as="button"
                                        type="button"
                                        class="inline-flex items-center rounded-full bg-emerald-600 px-3 py-1.5 text-[11px] font-semibold text-white transition hover:bg-emerald-700"
                                    >
                                        {{ $t('client_orders.actions.reorder') }}
                                    </Link>
                                </div>
                            </article>
                        </div>
                    </article>
                </section>

                <section class="space-y-4">
                    <article class="rounded-[1.75rem] border border-stone-200/80 bg-white p-5 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <h2 class="text-lg font-semibold text-stone-900 dark:text-neutral-100">
                                    {{ $t('client_orders.sections.deliveries') }}
                                </h2>
                                <p class="mt-1 text-sm text-stone-500 dark:text-neutral-400">
                                    {{ $t('client_orders.labels.delivery_in_progress') }}
                                </p>
                            </div>
                            <span class="rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1 text-xs font-semibold uppercase tracking-[0.16em] text-emerald-700 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-200">
                                {{ formatNumber(props.inDeliveryOrders.length) }}
                            </span>
                        </div>

                        <div v-if="!inDeliveryOrders.length" class="mt-4 rounded-[1.25rem] border border-dashed border-stone-200 bg-stone-50 px-4 py-5 text-sm text-stone-500 dark:border-neutral-800 dark:bg-neutral-950 dark:text-neutral-400">
                            {{ $t('client_orders.empty.deliveries') }}
                        </div>

                        <div v-else class="mt-4 space-y-3">
                            <article
                                v-for="sale in inDeliveryOrders"
                                :key="sale.id"
                                class="rounded-[1.4rem] border border-emerald-200/70 bg-emerald-50/70 p-4 dark:border-emerald-500/20 dark:bg-emerald-500/10"
                            >
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <p class="text-base font-semibold text-stone-900 dark:text-neutral-100">
                                            {{ orderLabel(sale) }}
                                        </p>
                                        <p class="mt-1 text-xs text-stone-600 dark:text-neutral-300">
                                            {{ sale.scheduled_for
                                                ? $t('client_orders.labels.scheduled_delivery', { date: formatDateTime(sale.scheduled_for) })
                                                : $t('client_orders.labels.delivery_in_progress')
                                            }}
                                        </p>
                                    </div>
                                    <div class="text-right">
                                        <p class="text-lg font-semibold text-stone-900 dark:text-neutral-100">
                                            {{ formatCurrency(sale.total) }}
                                        </p>
                                        <span class="mt-2 inline-flex rounded-full bg-emerald-100 px-2.5 py-1 text-[11px] font-semibold text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-200">
                                            {{ $t('client_orders.labels.in_transit') }}
                                        </span>
                                    </div>
                                </div>
                            </article>
                        </div>
                    </article>

                    <article class="rounded-[1.75rem] border border-amber-200/80 bg-amber-50/90 p-5 shadow-sm dark:border-amber-500/20 dark:bg-amber-500/10">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <h2 class="text-lg font-semibold text-stone-900 dark:text-neutral-100">
                                    {{ $t('client_orders.sections.delivery_alerts') }}
                                </h2>
                                <p class="mt-1 text-sm text-amber-700/80 dark:text-amber-100/80">
                                    {{ $t('client_orders.fulfillment.completed_unconfirmed') }}
                                </p>
                            </div>
                            <span class="rounded-full border border-amber-300 bg-white/70 px-3 py-1 text-xs font-semibold uppercase tracking-[0.16em] text-amber-800 dark:border-amber-300/20 dark:bg-white/5 dark:text-amber-100">
                                {{ formatNumber(props.deliveryAlerts.length) }}
                            </span>
                        </div>

                        <div v-if="!deliveryAlerts.length" class="mt-4 rounded-[1.25rem] border border-dashed border-amber-300/70 bg-white/70 px-4 py-5 text-sm text-amber-900 dark:border-amber-300/20 dark:bg-white/5 dark:text-amber-100">
                            {{ $t('client_orders.empty.delivery_alerts') }}
                        </div>

                        <div v-else class="mt-4 space-y-3">
                            <article
                                v-for="sale in deliveryAlerts"
                                :key="sale.id"
                                class="rounded-[1.35rem] border border-amber-200 bg-white/80 p-4 shadow-sm dark:border-amber-500/20 dark:bg-neutral-900/70"
                            >
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <p class="text-sm font-semibold text-stone-900 dark:text-neutral-100">
                                            {{ orderLabel(sale) }}
                                        </p>
                                        <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                            {{ sale.scheduled_for
                                                ? $t('client_orders.labels.scheduled_delivery', { date: formatDateTime(sale.scheduled_for) })
                                                : $t('client_orders.labels.delivery_in_progress')
                                            }}
                                        </p>
                                    </div>
                                    <span class="text-sm font-semibold text-stone-900 dark:text-neutral-100">
                                        {{ formatCurrency(sale.total) }}
                                    </span>
                                </div>

                                <div class="mt-3 flex items-center justify-between gap-2 text-xs text-stone-500 dark:text-neutral-400">
                                    <span>{{ fulfillmentLabel(sale) }}</span>
                                    <Link
                                        :href="orderActionRoute(sale)"
                                        class="font-semibold text-amber-800 hover:underline dark:text-amber-100"
                                    >
                                        {{ orderActionLabel(sale) }}
                                    </Link>
                                </div>
                            </article>
                        </div>
                    </article>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
