<script setup>
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { Head, Link, router } from '@inertiajs/vue3';
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
    performance: {
        type: Object,
        default: () => ({ periods: {}, seller_of_year: null }),
    },
});

const { t } = useI18n();

const formatCurrency = (value) =>
    `$${Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

const formatNumber = (value) =>
    Number(value || 0).toLocaleString(undefined, { maximumFractionDigits: 0 });

const performanceData = computed(() => (props.performance && typeof props.performance === 'object'
    ? props.performance
    : { periods: {}, seller_of_year: null }));

const periodOptions = computed(() => ([
    { key: 'day', label: t('dashboard_products.owner.performance.period.day') },
    { key: 'week', label: t('dashboard_products.owner.performance.period.week') },
    { key: 'month', label: t('dashboard_products.owner.performance.period.month') },
    { key: 'year', label: t('dashboard_products.owner.performance.period.year') },
]));

const activePeriod = ref('month');
const emptyPeriod = {
    range: { start: '', end: '' },
    orders: 0,
    revenue: 0,
    avg_order: 0,
    revenue_per_seller: 0,
    items_sold: 0,
    customers: 0,
    active_sellers: 0,
    top_sellers: [],
    top_products: [],
};

const periodStats = computed(() => {
    const periods = performanceData.value?.periods || {};
    return periods[activePeriod.value] || emptyPeriod;
});

const sellerOfYear = computed(() => performanceData.value?.seller_of_year || null);

const sellerDisplayName = (seller) => {
    if (seller?.type === 'online') {
        return t('dashboard_products.owner.performance.online_label');
    }
    return seller?.name || t('dashboard_products.owner.performance.seller_fallback');
};

const sellerInitials = (seller) => {
    const clean = String(sellerDisplayName(seller) || '').trim();
    if (!clean) {
        return '--';
    }
    const parts = clean.split(/\s+/).slice(0, 2);
    return parts.map((part) => part[0]?.toUpperCase()).join('');
};

const kpiCards = computed(() => ([
    {
        label: t('dashboard_products.owner.kpi.sales_today'),
        value: formatNumber(props.stats.sales_today),
        icon: 'bag',
        tone: 'emerald',
    },
    {
        label: t('dashboard_products.owner.kpi.revenue_today'),
        value: formatCurrency(props.stats.revenue_today),
        icon: 'cash',
        tone: 'sky',
    },
    {
        label: t('dashboard_products.owner.kpi.sales_month'),
        value: formatNumber(props.stats.sales_month),
        icon: 'trend',
        tone: 'amber',
    },
    {
        label: t('dashboard_products.owner.kpi.inventory_value'),
        value: formatCurrency(props.stats.inventory_value),
        icon: 'box',
        tone: 'emerald',
    },
    {
        label: t('dashboard_products.owner.kpi.low_stock'),
        value: formatNumber(props.stats.low_stock),
        icon: 'alert',
        tone: 'amber',
    },
    {
        label: t('dashboard_products.owner.kpi.out_of_stock'),
        value: formatNumber(props.stats.out_of_stock),
        icon: 'warning',
        tone: 'red',
    },
]));

const statusLabels = computed(() => ({
    draft: t('client_orders.status.draft'),
    pending: t('client_orders.status.pending'),
    paid: t('client_orders.status.paid'),
    canceled: t('client_orders.status.canceled'),
}));

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
    return name || t('dashboard_products.common.customer_fallback');
};

const stockSignals = computed(() => ([
    { label: t('dashboard_products.owner.stock_signals.reserved', { count: formatNumber(props.stats.reserved_total) }), tone: 'sky' },
    { label: t('dashboard_products.owner.stock_signals.damaged', { count: formatNumber(props.stats.damaged_total) }), tone: 'red' },
    { label: t('dashboard_products.owner.stock_signals.expired', { count: formatNumber(props.stats.expired_lots) }), tone: 'red' },
    { label: t('dashboard_products.owner.stock_signals.expiring', { count: formatNumber(props.stats.expiring_lots) }), tone: 'amber' },
]));

const stockSignalClasses = {
    red: 'bg-red-100 text-red-700 dark:bg-red-500/10 dark:text-red-300',
    amber: 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300',
    sky: 'bg-sky-100 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300',
    emerald: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300',
};

const formatDate = (value) => humanizeDate(value);

const requestSupplierStock = (product) => {
    if (!product?.supplier_email) {
        return;
    }
    if (!confirm(t('dashboard_products.common.confirm_supplier_request'))) {
        return;
    }
    router.post(route('product.supplier-email', product.id), {}, {
        preserveScroll: true,
    });
};
</script>

<template>
    <AuthenticatedLayout>
        <Head :title="$t('dashboard_products.owner.page_title')" />

        <div class="space-y-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">
                        {{ $t('dashboard_products.owner.title') }}
                    </h1>
                    <p class="text-sm text-stone-500 dark:text-neutral-400">
                        {{ $t('dashboard_products.owner.subtitle') }}
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <Link
                        :href="route('sales.create')"
                        class="rounded-sm bg-green-600 px-3 py-2 text-xs font-semibold text-white hover:bg-green-700"
                    >
                        {{ $t('dashboard_products.owner.actions.new_sale') }}
                    </Link>
                    <Link
                        :href="route('product.index')"
                        class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                    >
                        {{ $t('dashboard_products.owner.actions.products') }}
                    </Link>
                    <Link
                        :href="route('customer.index')"
                        class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                    >
                        {{ $t('dashboard_products.owner.actions.customers') }}
                    </Link>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
                <div
                    v-for="(card, index) in kpiCards"
                    :key="card.label"
                    class="rise-in rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900"
                    :style="{ animationDelay: `${index * 80}ms` }"
                >
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <p class="text-xs uppercase text-stone-400">{{ card.label }}</p>
                            <p class="mt-1 text-lg font-semibold text-stone-800 dark:text-neutral-100">{{ card.value }}</p>
                        </div>
                        <span
                            class="flex h-10 w-10 items-center justify-center rounded-full"
                            :class="stockSignalClasses[card.tone]"
                        >
                            <svg v-if="card.icon === 'bag'" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="m6 2 1.5 6h9L18 2" />
                                <path d="M4 8h16l-1 12H5z" />
                                <path d="M9 12h6" />
                            </svg>
                            <svg v-else-if="card.icon === 'cash'" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="2" y="5" width="20" height="14" rx="2" />
                                <path d="M16 10a4 4 0 1 1-8 0 4 4 0 0 1 8 0Z" />
                            </svg>
                            <svg v-else-if="card.icon === 'trend'" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="m3 17 6-6 4 4 7-7" />
                                <path d="M14 7h7v7" />
                            </svg>
                            <svg v-else-if="card.icon === 'box'" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="m7.5 4.27 9 5.15" />
                                <path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z" />
                                <path d="m3.3 7 8.7 5 8.7-5" />
                                <path d="M12 22V12" />
                            </svg>
                            <svg v-else-if="card.icon === 'alert'" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="m21.73 18-8-14a2 2 0 0 0-3.46 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z" />
                                <path d="M12 9v4" />
                                <path d="M12 17h.01" />
                            </svg>
                            <svg v-else xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M12 9v4" />
                                <path d="M12 17h.01" />
                                <path d="m21.73 18-8-14a2 2 0 0 0-3.46 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z" />
                            </svg>
                        </span>
                    </div>
                </div>
            </div>

            <Card class="rise-in" :style="{ animationDelay: '100ms' }">
                <template #title>{{ $t('dashboard_products.owner.performance.title') }}</template>
                <div class="space-y-4">
                    <div class="flex flex-wrap items-center gap-2">
                        <button
                            v-for="period in periodOptions"
                            :key="period.key"
                            type="button"
                            class="rounded-full px-3 py-1.5 text-xs font-semibold transition"
                            :class="activePeriod === period.key
                                ? 'bg-green-600 text-white shadow-sm'
                                : 'bg-stone-100 text-stone-600 hover:bg-stone-200 dark:bg-neutral-800 dark:text-neutral-300 dark:hover:bg-neutral-700'"
                            @click="activePeriod = period.key"
                        >
                            {{ period.label }}
                        </button>
                        <span v-if="periodStats.range?.start" class="text-xs text-stone-500 dark:text-neutral-400 self-center">
                            {{ $t('dashboard_products.owner.performance.range', { start: periodStats.range.start, end: periodStats.range.end }) }}
                        </span>
                        <span class="text-xs text-stone-500 dark:text-neutral-400 self-center">
                            {{ $t('dashboard_products.owner.performance.active_sellers', { count: formatNumber(periodStats.active_sellers) }) }}
                        </span>
                        <a
                            :href="route('dashboard.products.sellers-export', { period: activePeriod })"
                            class="ml-auto rounded-sm border border-stone-200 bg-white px-2.5 py-1.5 text-xs font-semibold text-stone-600 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300 dark:hover:bg-neutral-800"
                        >
                            {{ $t('dashboard_products.owner.performance.export') }}
                        </a>
                    </div>

                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-6">
                        <div class="rounded-sm border border-stone-200 bg-white p-3 text-xs text-stone-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-400">
                            <p class="uppercase">{{ $t('dashboard_products.owner.performance.kpi.revenue') }}</p>
                            <p class="mt-1 text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                {{ formatCurrency(periodStats.revenue) }}
                            </p>
                        </div>
                        <div class="rounded-sm border border-stone-200 bg-white p-3 text-xs text-stone-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-400">
                            <p class="uppercase">{{ $t('dashboard_products.owner.performance.kpi.orders') }}</p>
                            <p class="mt-1 text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                {{ formatNumber(periodStats.orders) }}
                            </p>
                        </div>
                        <div class="rounded-sm border border-stone-200 bg-white p-3 text-xs text-stone-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-400">
                            <p class="uppercase">{{ $t('dashboard_products.owner.performance.kpi.items_sold') }}</p>
                            <p class="mt-1 text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                {{ formatNumber(periodStats.items_sold) }}
                            </p>
                        </div>
                        <div class="rounded-sm border border-stone-200 bg-white p-3 text-xs text-stone-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-400">
                            <p class="uppercase">{{ $t('dashboard_products.owner.performance.kpi.avg_order') }}</p>
                            <p class="mt-1 text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                {{ formatCurrency(periodStats.avg_order) }}
                            </p>
                        </div>
                        <div class="rounded-sm border border-stone-200 bg-white p-3 text-xs text-stone-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-400">
                            <p class="uppercase">{{ $t('dashboard_products.owner.performance.kpi.customers') }}</p>
                            <p class="mt-1 text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                {{ formatNumber(periodStats.customers) }}
                            </p>
                        </div>
                        <div class="rounded-sm border border-stone-200 bg-white p-3 text-xs text-stone-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-400">
                            <p class="uppercase">{{ $t('dashboard_products.owner.performance.kpi.revenue_per_seller') }}</p>
                            <p class="mt-1 text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                {{ formatCurrency(periodStats.revenue_per_seller) }}
                            </p>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
                        <div class="lg:col-span-2 space-y-4">
                            <div>
                                <h3 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                    {{ $t('dashboard_products.owner.performance.top_sellers') }}
                                </h3>
                                <div v-if="!periodStats.top_sellers?.length" class="mt-2 text-sm text-stone-500 dark:text-neutral-400">
                                    {{ $t('dashboard_products.owner.performance.no_sellers') }}
                                </div>
                                <div v-else class="mt-3 space-y-2">
                                    <div
                                        v-for="(seller, index) in periodStats.top_sellers"
                                        :key="seller.id"
                                        class="flex items-center gap-3 rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900"
                                    >
                                        <span class="w-6 text-xs font-semibold text-stone-400">#{{ index + 1 }}</span>
                                        <div class="h-10 w-10 overflow-hidden rounded-full border border-stone-200 bg-stone-100 dark:border-neutral-700 dark:bg-neutral-800">
                                            <img
                                                v-if="seller.profile_picture_url"
                                                :src="seller.profile_picture_url"
                                                :alt="sellerDisplayName(seller)"
                                                class="h-full w-full object-cover"
                                                loading="lazy"
                                                decoding="async"
                                            />
                                            <div v-else class="flex h-full w-full items-center justify-center text-xs font-semibold text-stone-600 dark:text-neutral-300">
                                                {{ sellerInitials(seller) }}
                                            </div>
                                        </div>
                                        <div class="flex-1">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <p class="font-semibold text-stone-800 dark:text-neutral-100">{{ sellerDisplayName(seller) }}</p>
                                                <span
                                                    v-if="seller.type === 'online'"
                                                    class="rounded-full bg-sky-100 px-2 py-0.5 text-[10px] font-semibold text-sky-700 dark:bg-sky-500/10 dark:text-sky-200"
                                                >
                                                    {{ $t('dashboard_products.owner.performance.online_badge') }}
                                                </span>
                                            </div>
                                            <p class="text-xs text-stone-500 dark:text-neutral-400">
                                                {{ $t('dashboard_products.owner.performance.seller_line', {
                                                    revenue: formatCurrency(seller.revenue),
                                                    orders: formatNumber(seller.orders),
                                                    items: formatNumber(seller.items),
                                                }) }}
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div>
                                <h3 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                    {{ $t('dashboard_products.owner.performance.top_products') }}
                                </h3>
                                <div v-if="!periodStats.top_products?.length" class="mt-2 text-sm text-stone-500 dark:text-neutral-400">
                                    {{ $t('dashboard_products.owner.performance.no_products') }}
                                </div>
                                <div v-else class="mt-3 grid grid-cols-1 gap-3 md:grid-cols-2">
                                    <div
                                        v-for="product in periodStats.top_products"
                                        :key="product.id"
                                        class="flex items-center gap-3 rounded-sm border border-stone-200 bg-white p-3 text-sm dark:border-neutral-700 dark:bg-neutral-900"
                                    >
                                        <img
                                            :src="product.image_url"
                                            :alt="product.name"
                                            class="h-12 w-12 rounded-sm border border-stone-200 object-cover dark:border-neutral-700"
                                            loading="lazy"
                                            decoding="async"
                                        />
                                        <div class="flex-1">
                                            <p class="font-semibold text-stone-800 dark:text-neutral-100">{{ product.name }}</p>
                                            <p class="text-xs text-stone-500 dark:text-neutral-400">
                                                {{ $t('dashboard_products.owner.performance.product_line', {
                                                    revenue: formatCurrency(product.revenue),
                                                    quantity: formatNumber(product.quantity),
                                                }) }}
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="space-y-3">
                            <div class="rounded-sm border border-emerald-200 bg-emerald-50/50 p-4 text-sm text-emerald-900 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200">
                                <p class="text-xs uppercase tracking-wide text-emerald-700 dark:text-emerald-200">
                                    {{ $t('dashboard_products.owner.performance.seller_of_year') }}
                                </p>
                                <div v-if="sellerOfYear" class="mt-3 flex items-center gap-3">
                                    <div class="h-14 w-14 overflow-hidden rounded-full border border-emerald-200 bg-white dark:border-emerald-500/40 dark:bg-neutral-900">
                                        <img
                                            v-if="sellerOfYear.profile_picture_url"
                                            :src="sellerOfYear.profile_picture_url"
                                            :alt="sellerDisplayName(sellerOfYear)"
                                            class="h-full w-full object-cover"
                                            loading="lazy"
                                            decoding="async"
                                        />
                                        <div v-else class="flex h-full w-full items-center justify-center text-sm font-semibold text-emerald-700 dark:text-emerald-200">
                                            {{ sellerInitials(sellerOfYear) }}
                                        </div>
                                    </div>
                                    <div>
                                        <p class="text-sm font-semibold">{{ sellerDisplayName(sellerOfYear) }}</p>
                                        <p class="text-xs text-emerald-700/80 dark:text-emerald-200/80">
                                            {{ $t('dashboard_products.owner.performance.seller_line', {
                                                revenue: formatCurrency(sellerOfYear.revenue),
                                                orders: formatNumber(sellerOfYear.orders),
                                                items: formatNumber(sellerOfYear.items),
                                            }) }}
                                        </p>
                                    </div>
                                </div>
                                <p v-else class="mt-3 text-sm text-emerald-700/80 dark:text-emerald-200/80">
                                    {{ $t('dashboard_products.owner.performance.no_seller_year') }}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </Card>

            <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <Card class="rise-in lg:col-span-2" :style="{ animationDelay: '120ms' }">
                    <template #title>{{ $t('dashboard_products.common.recent_sales_title') }}</template>
                    <div v-if="!recentSales.length" class="text-sm text-stone-500 dark:text-neutral-400">
                        {{ $t('dashboard_products.common.recent_sales_empty') }}
                    </div>
                    <div v-else class="divide-y divide-stone-200 dark:divide-neutral-700">
                        <div v-for="sale in recentSales" :key="sale.id" class="flex items-center justify-between gap-3 py-3 text-sm">
                            <div>
                                <p class="font-semibold text-stone-800 dark:text-neutral-200">
                                    {{ sale.number || $t('dashboard_products.common.sale_label', { id: sale.id }) }}
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
                    <template #title>{{ $t('dashboard_products.common.stock_alerts_title') }}</template>
                    <div class="flex flex-wrap gap-2 text-xs">
                        <span
                            v-for="signal in stockSignals"
                            :key="signal.label"
                            class="rounded-full px-2 py-1 font-semibold"
                            :class="stockSignalClasses[signal.tone]"
                        >
                            {{ signal.label }}
                        </span>
                    </div>
                    <div class="mt-4 space-y-2 text-sm">
                        <div v-if="!stockAlerts.length" class="text-stone-500 dark:text-neutral-400">
                            {{ $t('dashboard_products.common.stock_alerts_empty') }}
                        </div>
                        <div v-else class="space-y-3">
                            <div v-for="product in stockAlerts" :key="product.id" class="space-y-2">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <p class="text-sm font-semibold text-stone-800 dark:text-neutral-200">{{ product.name }}</p>
                                        <p class="text-xs text-stone-500 dark:text-neutral-400">
                                            {{ $t('dashboard_products.common.stock_line', { stock: product.stock, min: product.minimum_stock }) }}
                                        </p>
                                    </div>
                                    <span
                                        class="rounded-full px-2 py-1 text-[10px] font-semibold"
                                        :class="product.stock <= 0
                                            ? 'bg-red-100 text-red-700 dark:bg-red-500/10 dark:text-red-300'
                                            : 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300'"
                                    >
                                        {{ product.stock <= 0 ? $t('dashboard_products.common.stock_out') : $t('dashboard_products.common.stock_low') }}
                                    </span>
                                </div>
                                <div class="flex items-center justify-between text-xs text-stone-500 dark:text-neutral-400">
                                    <span>{{ product.supplier_name || $t('dashboard_products.common.supplier_unknown') }}</span>
                                    <button
                                        type="button"
                                        class="rounded-sm border border-stone-200 bg-white px-2 py-1 text-[11px] font-semibold text-stone-700 hover:bg-stone-50 disabled:opacity-60 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                                        :disabled="!product.supplier_email"
                                        @click="requestSupplierStock(product)"
                                    >
                                        {{ $t('dashboard_products.common.request_stock') }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </Card>
            </div>

            <Card class="rise-in" :style="{ animationDelay: '200ms' }">
                <template #title>{{ $t('dashboard_products.common.top_products_title') }}</template>
                <div v-if="!topProducts.length" class="text-sm text-stone-500 dark:text-neutral-400">
                    {{ $t('dashboard_products.common.top_products_empty') }}
                </div>
                <div v-else class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
                    <div v-for="product in topProducts" :key="product.id" class="flex items-center gap-3 rounded-sm border border-stone-200 p-3 dark:border-neutral-700">
                        <img
                            :src="product.image_url"
                            :alt="product.name"
                            class="h-12 w-12 rounded-sm border border-stone-200 object-cover dark:border-neutral-700"
                            loading="lazy"
                            decoding="async"
                        />
                        <div class="flex-1">
                            <p class="text-sm font-semibold text-stone-800 dark:text-neutral-200">{{ product.name }}</p>
                            <p class="text-xs text-stone-500 dark:text-neutral-400">
                                {{ $t('dashboard_products.common.quantity', { count: product.quantity }) }}
                            </p>
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
