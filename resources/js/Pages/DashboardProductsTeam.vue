<script setup>
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AnnouncementsPanel from '@/Components/Dashboard/AnnouncementsPanel.vue';
import KpiCompositePanel from '@/Components/Dashboard/KpiCompositePanel.vue';
import Card from '@/Components/UI/Card.vue';
import { humanizeDate } from '@/utils/date';
import { useCurrencyFormatter } from '@/utils/currency';

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
    announcements: {
        type: Array,
        default: () => [],
    },
});

const { t } = useI18n();
const page = usePage();
const userName = computed(() => page.props.auth?.user?.name || '');
const greeting = computed(() => (
    userName.value
        ? t('dashboard.welcome_named', { name: userName.value })
        : t('dashboard.welcome_generic')
));
const hasAnnouncements = computed(() => (props.announcements || []).length > 0);

const isHydrating = ref(true);

onMounted(() => {
    setTimeout(() => {
        isHydrating.value = false;
    }, 450);
});

const { formatCurrency } = useCurrencyFormatter();

const formatNumber = (value) =>
    Number(value || 0).toLocaleString(undefined, { maximumFractionDigits: 0 });

const overviewMetrics = computed(() => ([
    {
        key: 'sales-today',
        label: t('dashboard_products.team.kpi.sales_today'),
        value: formatNumber(props.stats.sales_today),
        colorClass: 'bg-emerald-500/70 dark:bg-emerald-400/50',
    },
    {
        key: 'revenue-today',
        label: t('dashboard_products.team.kpi.revenue_today'),
        value: formatCurrency(props.stats.revenue_today),
        colorClass: 'bg-sky-500/70 dark:bg-sky-400/50',
    },
    {
        key: 'low-stock',
        label: t('dashboard_products.team.kpi.low_stock'),
        value: formatNumber(props.stats.low_stock),
        colorClass: 'bg-amber-500/70 dark:bg-amber-400/50',
    },
    {
        key: 'out-of-stock',
        label: t('dashboard_products.team.kpi.out_of_stock'),
        value: formatNumber(props.stats.out_of_stock),
        colorClass: 'bg-rose-500/70 dark:bg-rose-400/50',
    },
]));

const overviewSummaryItems = computed(() => ([
    {
        key: 'sales-month',
        label: t('dashboard_products.owner.kpi.sales_month'),
        value: formatNumber(props.stats.sales_month),
    },
    {
        key: 'revenue-month',
        label: t('dashboard_products.common.metrics.revenue_month'),
        value: formatCurrency(props.stats.revenue_month),
    },
    {
        key: 'products-total',
        label: t('dashboard.limits.products'),
        value: formatNumber(props.stats.products_total),
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

const skeletonRows = Array.from({ length: 4 }, (_, index) => index);
</script>

<template>
    <AuthenticatedLayout>
        <Head :title="$t('dashboard_products.team.page_title')" />

        <div class="mx-auto w-full max-w-6xl space-y-5">
            <section class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div class="space-y-1">
                        <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">
                            {{ $t('dashboard_products.team.title') }}
                        </h1>
                        <p class="text-sm text-stone-600 dark:text-neutral-400">
                            {{ greeting }}
                        </p>
                        <div class="flex flex-wrap gap-3 text-xs text-stone-500 dark:text-neutral-400">
                            <span>{{ $t('dashboard_products.team.subtitle') }}</span>
                            <span>
                                {{ $t('dashboard_products.owner.kpi.sales_month') }}:
                                {{ formatNumber(stats.sales_month) }}
                            </span>
                            <span>
                                {{ $t('dashboard_products.common.metrics.revenue_month') }}:
                                {{ formatCurrency(stats.revenue_month) }}
                            </span>
                        </div>
                    </div>
                    <Link
                        :href="route('sales.create')"
                        class="rounded-sm bg-green-600 px-3 py-2 text-xs font-semibold text-white hover:bg-green-700"
                    >
                        {{ $t('dashboard_products.team.actions.new_sale') }}
                    </Link>
                </div>
            </section>

            <div :class="['grid gap-4 items-start', hasAnnouncements ? 'xl:grid-cols-[minmax(0,1fr)_320px]' : 'grid-cols-1']">
                <KpiCompositePanel
                    class="rise-in"
                    :style="{ animationDelay: '40ms' }"
                    :title="$t('dashboard.kpi_panels.overview_title')"
                    :subtitle="$t('dashboard_products.team.subtitle')"
                    :metrics="overviewMetrics"
                    metrics-grid-class="sm:grid-cols-2 xl:grid-cols-4"
                    :summary-items="overviewSummaryItems"
                    summary-grid-class="sm:grid-cols-3"
                    compact-metrics
                />
                <AnnouncementsPanel
                    v-if="hasAnnouncements"
                    :announcements="announcements"
                    variant="side"
                    :title="$t('dashboard.announcements.title')"
                    :subtitle="$t('dashboard.announcements.subtitle')"
                    :limit="3"
                />
            </div>

            <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <Card class="rise-in lg:col-span-2" :style="{ animationDelay: '120ms' }">
                    <template #title>{{ $t('dashboard_products.common.recent_sales_title') }}</template>
                    <div v-if="isHydrating" class="divide-y divide-stone-200 dark:divide-neutral-700">
                        <div
                            v-for="index in skeletonRows"
                            :key="`team-sale-skeleton-${index}`"
                            class="flex items-center justify-between gap-3 py-3 text-sm animate-pulse"
                        >
                            <div class="space-y-2">
                                <div class="h-3 w-32 rounded-full bg-stone-200 dark:bg-neutral-700"></div>
                                <div class="h-3 w-40 rounded-full bg-stone-200 dark:bg-neutral-700"></div>
                            </div>
                            <div class="space-y-2 text-right">
                                <div class="h-3 w-16 rounded-full bg-stone-200 dark:bg-neutral-700"></div>
                                <div class="h-4 w-14 rounded-full bg-stone-200 dark:bg-neutral-700"></div>
                            </div>
                        </div>
                    </div>
                    <div v-else-if="!recentSales.length" class="text-sm text-stone-500 dark:text-neutral-400">
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
                    <div v-if="isHydrating" class="space-y-3">
                        <div
                            v-for="index in skeletonRows"
                            :key="`team-stock-skeleton-${index}`"
                            class="space-y-2 animate-pulse"
                        >
                            <div class="flex items-center justify-between">
                                <div class="space-y-2">
                                    <div class="h-3 w-32 rounded-full bg-stone-200 dark:bg-neutral-700"></div>
                                    <div class="h-3 w-40 rounded-full bg-stone-200 dark:bg-neutral-700"></div>
                                </div>
                                <div class="h-4 w-16 rounded-full bg-stone-200 dark:bg-neutral-700"></div>
                            </div>
                            <div class="flex items-center justify-between">
                                <div class="h-3 w-24 rounded-full bg-stone-200 dark:bg-neutral-700"></div>
                                <div class="h-6 w-20 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                            </div>
                        </div>
                    </div>
                    <div v-else-if="!stockAlerts.length" class="text-sm text-stone-500 dark:text-neutral-400">
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
                </Card>
            </div>

            <Card v-if="isHydrating || topProducts.length" class="rise-in" :style="{ animationDelay: '200ms' }">
                <template #title>{{ $t('dashboard_products.common.top_products_title') }}</template>
                <div v-if="isHydrating" class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
                    <div
                        v-for="index in skeletonRows"
                        :key="`team-top-product-skeleton-${index}`"
                        class="flex items-center gap-3 rounded-sm border border-stone-200 p-3 animate-pulse dark:border-neutral-700"
                    >
                        <div class="h-12 w-12 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                        <div class="flex-1 space-y-2">
                            <div class="h-3 w-28 rounded-full bg-stone-200 dark:bg-neutral-700"></div>
                            <div class="h-3 w-24 rounded-full bg-stone-200 dark:bg-neutral-700"></div>
                        </div>
                    </div>
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
