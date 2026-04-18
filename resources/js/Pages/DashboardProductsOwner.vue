<script setup>
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AnnouncementsPanel from '@/Components/Dashboard/AnnouncementsPanel.vue';
import KpiCompositePanel from '@/Components/Dashboard/KpiCompositePanel.vue';
import Card from '@/Components/UI/Card.vue';
import Modal from '@/Components/Modal.vue';
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
    performance: {
        type: Object,
        default: () => ({ periods: {}, seller_of_year: null }),
    },
    marketingKpis: {
        type: Object,
        default: null,
    },
    announcements: {
        type: Array,
        default: () => [],
    },
    quickAnnouncements: {
        type: Array,
        default: () => [],
    },
    usage_limits: {
        type: Object,
        default: () => ({ items: [] }),
    },
    billing: {
        type: Object,
        default: () => ({}),
    },
    financeSummary: {
        type: Object,
        default: () => ({}),
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
const hasTopAnnouncements = computed(() => (props.announcements || []).length > 0);
const hasQuickAnnouncements = computed(() => (props.quickAnnouncements || []).length > 0);

const isHydrating = ref(true);

onMounted(() => {
    if (typeof window !== 'undefined') {
        const storedValue = window.localStorage.getItem(marketingPanelStorageKey);
        if (storedValue === '1' || storedValue === '0') {
            showMarketingPanel.value = storedValue === '1';
        }
    }

    setTimeout(() => {
        isHydrating.value = false;
    }, 450);
});

const { formatCurrency } = useCurrencyFormatter();

const formatNumber = (value) =>
    Number(value || 0).toLocaleString(undefined, { maximumFractionDigits: 0 });
const formatPercent = (value, maxFractionDigits = 2) => {
    if (value === null || value === undefined || Number.isNaN(Number(value))) {
        return '-';
    }

    return `${Number(value).toLocaleString(undefined, {
        minimumFractionDigits: 0,
        maximumFractionDigits: maxFractionDigits,
    })}%`;
};

const performanceData = computed(() => (props.performance && typeof props.performance === 'object'
    ? props.performance
    : { periods: {}, seller_of_year: null }));
const marketingKpiPayload = computed(() => props.marketingKpis || null);
const marketingRange = computed(() => marketingKpiPayload.value?.range || null);
const marketingMetrics = computed(() => marketingKpiPayload.value?.marketing || null);
const marketingCrossModule = computed(() => marketingKpiPayload.value?.cross_module || null);
const hasMarketingKpis = computed(() => Boolean(marketingMetrics.value));
const marketingPanelStorageKey = 'dashboard:marketing-panel:products';
const showMarketingPanel = ref(false);
const marketingCards = computed(() => {
    if (!marketingMetrics.value) {
        return [];
    }

    return [
        {
            key: 'campaigns_sent',
            label: t('dashboard.marketing_panel.cards.campaigns_sent'),
            value: formatNumber(marketingMetrics.value.campaigns_sent || 0),
        },
        {
            key: 'delivery_success_rate',
            label: t('dashboard.marketing_panel.cards.delivery_success_rate'),
            value: formatPercent(marketingMetrics.value.delivery_success_rate),
        },
        {
            key: 'click_rate',
            label: t('dashboard.marketing_panel.cards.click_rate'),
            value: marketingMetrics.value.click_rate === null
                ? t('dashboard.marketing_panel.cards.tracking_off')
                : formatPercent(marketingMetrics.value.click_rate),
        },
        {
            key: 'conversions_attributed',
            label: t('dashboard.marketing_panel.cards.conversions_attributed'),
            value: formatNumber(marketingMetrics.value.conversions_attributed || 0),
        },
    ];
});
const audienceGrowthDelta = computed(() => {
    const delta = Number(marketingMetrics.value?.audience_growth?.delta || 0);
    if (delta > 0) {
        return `+${formatNumber(delta)}`;
    }

    return formatNumber(delta);
});
const audienceGrowthDeltaClass = computed(() => {
    const delta = Number(marketingMetrics.value?.audience_growth?.delta || 0);
    if (delta > 0) {
        return 'text-emerald-700 dark:text-emerald-300';
    }
    if (delta < 0) {
        return 'text-rose-700 dark:text-rose-300';
    }

    return 'text-stone-600 dark:text-neutral-300';
});
const setMarketingPanelVisibility = (visible) => {
    showMarketingPanel.value = visible;

    if (typeof window === 'undefined') {
        return;
    }

    window.localStorage.setItem(marketingPanelStorageKey, visible ? '1' : '0');
};
const toggleMarketingPanel = () => setMarketingPanelVisibility(!showMarketingPanel.value);

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

const panelMetricColors = {
    emerald: 'bg-emerald-500/70 dark:bg-emerald-400/50',
    sky: 'bg-sky-500/70 dark:bg-sky-400/50',
    amber: 'bg-amber-500/70 dark:bg-amber-400/50',
    red: 'bg-rose-500/70 dark:bg-rose-400/50',
};

const overviewMetrics = computed(() => (
    kpiCards.value
        .slice(0, 4)
        .map((card, index) => ({
            key: `overview-${card.icon || index}`,
            label: card.label,
            value: card.value,
            colorClass: panelMetricColors[card.tone] || 'bg-stone-400/70 dark:bg-neutral-500/50',
        }))
));

const overviewSummaryItems = computed(() => ([
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

const inventoryMetrics = computed(() => ([
    {
        key: 'low-stock',
        label: t('dashboard_products.owner.kpi.low_stock'),
        value: formatNumber(props.stats.low_stock),
        colorClass: panelMetricColors.amber,
    },
    {
        key: 'out-of-stock',
        label: t('dashboard_products.owner.kpi.out_of_stock'),
        value: formatNumber(props.stats.out_of_stock),
        colorClass: panelMetricColors.red,
    },
    {
        key: 'reserved-stock',
        label: t('dashboard_products.common.metrics.reserved'),
        value: formatNumber(props.stats.reserved_total),
        colorClass: panelMetricColors.sky,
    },
    {
        key: 'damaged-stock',
        label: t('dashboard_products.common.metrics.damaged'),
        value: formatNumber(props.stats.damaged_total),
        colorClass: panelMetricColors.red,
    },
]));

const inventorySummaryItems = computed(() => ([
    {
        key: 'expired-lots',
        label: t('dashboard_products.common.metrics.expired_lots'),
        value: formatNumber(props.stats.expired_lots),
        toneClass: 'border-rose-200 bg-rose-50/70 dark:border-rose-500/30 dark:bg-rose-500/10',
    },
    {
        key: 'expiring-lots',
        label: t('dashboard_products.common.metrics.expiring_lots'),
        value: formatNumber(props.stats.expiring_lots),
        toneClass: 'border-amber-200 bg-amber-50/70 dark:border-amber-500/30 dark:bg-amber-500/10',
    },
]));

const kpiIconStyles = {
    emerald: 'bg-emerald-500/90 text-white shadow-emerald-500/30',
    sky: 'bg-sky-500/90 text-white shadow-sky-500/30',
    amber: 'bg-amber-500/90 text-white shadow-amber-500/30',
    red: 'bg-red-500/90 text-white shadow-red-500/30',
};

const kpiBorderStyles = {
    emerald: 'border-t-emerald-500 dark:border-t-emerald-400',
    sky: 'border-t-sky-500 dark:border-t-sky-400',
    amber: 'border-t-amber-500 dark:border-t-amber-400',
    red: 'border-t-red-500 dark:border-t-red-400',
};

const performanceKpiIcons = {
    revenue: ['M12 6v12', 'M8.5 9.5a3.5 3.5 0 117 0c0 1.933-1.567 3.5-3.5 3.5S8.5 11.433 8.5 9.5z'],
    orders: ['M3 3h2l.4 2', 'M7 13h10l4-8H5.4', 'M7 13L5.4 5', 'M7 13l-2 6', 'M17 13l2 6'],
    items_sold: ['M12 3l8 4-8 4-8-4 8-4z', 'M4 7v10l8 4 8-4V7'],
    avg_order: ['M6 4h12v16l-3-1.5-3 1.5-3-1.5-3 1.5V4z', 'M9 9h6', 'M9 13h6'],
    customers: ['M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2', 'M9 7a4 4 0 100 8 4 4 0 000-8z'],
    revenue_per_seller: ['M3 17l6-6 4 4 7-7', 'M14 7h7v7'],
};

const performanceKpis = computed(() => ([
    {
        key: 'revenue',
        label: t('dashboard_products.owner.performance.kpi.revenue'),
        value: formatCurrency(periodStats.value.revenue),
        tone: 'emerald',
    },
    {
        key: 'orders',
        label: t('dashboard_products.owner.performance.kpi.orders'),
        value: formatNumber(periodStats.value.orders),
        tone: 'sky',
    },
    {
        key: 'items_sold',
        label: t('dashboard_products.owner.performance.kpi.items_sold'),
        value: formatNumber(periodStats.value.items_sold),
        tone: 'amber',
    },
    {
        key: 'avg_order',
        label: t('dashboard_products.owner.performance.kpi.avg_order'),
        value: formatCurrency(periodStats.value.avg_order),
        tone: 'emerald',
    },
    {
        key: 'customers',
        label: t('dashboard_products.owner.performance.kpi.customers'),
        value: formatNumber(periodStats.value.customers),
        tone: 'sky',
    },
    {
        key: 'revenue_per_seller',
        label: t('dashboard_products.owner.performance.kpi.revenue_per_seller'),
        value: formatCurrency(periodStats.value.revenue_per_seller),
        tone: 'amber',
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

const insightToneClasses = {
    emerald: {
        summary: 'border-emerald-200 bg-emerald-50/80 text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200',
        section: 'border-emerald-200/80 bg-emerald-50/40 dark:border-emerald-500/20 dark:bg-emerald-500/5',
        icon: 'border-emerald-200 bg-white text-emerald-700 dark:border-emerald-500/30 dark:bg-neutral-900 dark:text-emerald-200',
        button: 'border-emerald-200 bg-white text-emerald-700 hover:bg-emerald-50 dark:border-emerald-500/30 dark:bg-neutral-900 dark:text-emerald-200 dark:hover:bg-emerald-500/10',
    },
    amber: {
        summary: 'border-amber-200 bg-amber-50/80 text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200',
        section: 'border-amber-200/80 bg-amber-50/40 dark:border-amber-500/20 dark:bg-amber-500/5',
        icon: 'border-amber-200 bg-white text-amber-700 dark:border-amber-500/30 dark:bg-neutral-900 dark:text-amber-200',
        button: 'border-amber-200 bg-white text-amber-700 hover:bg-amber-50 dark:border-amber-500/30 dark:bg-neutral-900 dark:text-amber-200 dark:hover:bg-amber-500/10',
    },
    sky: {
        summary: 'border-sky-200 bg-sky-50/80 text-sky-700 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-200',
        section: 'border-sky-200/80 bg-sky-50/40 dark:border-sky-500/20 dark:bg-sky-500/5',
        icon: 'border-sky-200 bg-white text-sky-700 dark:border-sky-500/30 dark:bg-neutral-900 dark:text-sky-200',
        button: 'border-sky-200 bg-white text-sky-700 hover:bg-sky-50 dark:border-sky-500/30 dark:bg-neutral-900 dark:text-sky-200 dark:hover:bg-sky-500/10',
    },
};

const insightDetailKey = ref(null);

const insightSections = computed(() => {
    const recentSalesItems = (props.recentSales || []).map((sale) => ({
        id: sale.id,
        title: sale.number || t('dashboard_products.common.sale_label', { id: sale.id }),
        meta: `${customerLabel(sale)} - ${formatDate(sale.created_at)}`,
        sideText: formatCurrency(sale.total),
        badge: statusLabels.value[sale.status] || sale.status,
        badgeClass: statusClasses[sale.status] || statusClasses.draft,
        raw: sale,
    }));

    const stockAlertItems = (props.stockAlerts || []).map((product) => ({
        id: product.id,
        title: product.name,
        meta: t('dashboard_products.common.stock_line', {
            stock: formatNumber(product.stock),
            min: formatNumber(product.minimum_stock),
        }),
        sideText: product.supplier_name || t('dashboard_products.common.supplier_unknown'),
        badge: product.stock <= 0 ? t('dashboard_products.common.stock_out') : t('dashboard_products.common.stock_low'),
        badgeClass: product.stock <= 0
            ? 'bg-red-100 text-red-700 dark:bg-red-500/10 dark:text-red-300'
            : 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300',
        raw: product,
    }));

    const topProductItems = (props.topProducts || []).map((product) => ({
        id: product.id,
        title: product.name,
        meta: t('dashboard_products.common.quantity', {
            count: formatNumber(product.quantity),
        }),
        sideText: null,
        badge: null,
        badgeClass: '',
        imageUrl: product.image_url,
        raw: product,
    }));

    return [
        {
            key: 'recent-sales',
            title: t('dashboard_products.common.recent_sales_title'),
            summary: t('dashboard_products.owner.insights.counts.recent_sales', {
                count: formatNumber(recentSalesItems.length),
            }),
            emptyLabel: t('dashboard_products.common.recent_sales_empty'),
            tone: 'emerald',
            symbol: 'V',
            items: recentSalesItems,
            previewItems: recentSalesItems.slice(0, 3),
        },
        {
            key: 'stock-alerts',
            title: t('dashboard_products.common.stock_alerts_title'),
            summary: t('dashboard_products.owner.insights.counts.stock_alerts', {
                count: formatNumber(stockAlertItems.length),
            }),
            emptyLabel: t('dashboard_products.common.stock_alerts_empty'),
            tone: 'amber',
            symbol: '!',
            items: stockAlertItems,
            previewItems: stockAlertItems.slice(0, 3),
        },
        {
            key: 'top-products',
            title: t('dashboard_products.common.top_products_title'),
            summary: t('dashboard_products.owner.insights.counts.top_products', {
                count: formatNumber(topProductItems.length),
            }),
            emptyLabel: t('dashboard_products.common.top_products_empty'),
            tone: 'sky',
            symbol: 'P',
            items: topProductItems,
            previewItems: topProductItems.slice(0, 3),
        },
    ].filter((section) => section.items.length > 0);
});

const hasInsightSections = computed(() => insightSections.value.length > 0);
const hasProductSideColumn = computed(() => hasInsightSections.value || hasQuickAnnouncements.value);

const activeInsightSection = computed(() => (
    insightSections.value.find((section) => section.key === insightDetailKey.value) || null
));

const openInsightDialog = (key) => {
    insightDetailKey.value = key;
};

const closeInsightDialog = () => {
    insightDetailKey.value = null;
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

const skeletonKpis = Array.from({ length: 6 }, (_, index) => index);
const skeletonRows = Array.from({ length: 4 }, (_, index) => index);
</script>

<template>
    <AuthenticatedLayout>
        <Head :title="$t('dashboard_products.owner.page_title')" />

        <div class="space-y-6">
            <section class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div class="space-y-1">
                        <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">
                            {{ $t('dashboard_products.owner.title') }}
                        </h1>
                        <p class="text-sm text-stone-600 dark:text-neutral-400">
                            {{ greeting }}
                        </p>
                        <div class="flex flex-wrap gap-3 text-xs text-stone-500 dark:text-neutral-400">
                            <span>{{ $t('dashboard_products.owner.subtitle') }}</span>
                            <span>
                                {{ $t('dashboard_products.owner.kpi.sales_month') }}:
                                {{ formatNumber(stats.sales_month) }}
                            </span>
                            <span>
                                {{ $t('dashboard_products.owner.kpi.inventory_value') }}:
                                {{ formatCurrency(stats.inventory_value) }}
                            </span>
                        </div>
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
            </section>

            <div :class="['grid gap-4 items-start', hasTopAnnouncements ? 'xl:grid-cols-[minmax(0,1fr)_320px]' : 'grid-cols-1']">
                <section class="grid grid-cols-1 gap-4 xl:grid-cols-12">
                    <KpiCompositePanel
                        class="rise-in xl:col-span-6"
                        :style="{ animationDelay: '40ms' }"
                        :title="$t('dashboard.kpi_panels.overview_title')"
                        :subtitle="$t('dashboard_products.owner.subtitle')"
                        :metrics="overviewMetrics"
                        metrics-grid-class="sm:grid-cols-2 xl:grid-cols-2"
                        :summary-items="overviewSummaryItems"
                        summary-grid-class="sm:grid-cols-2"
                        :action-href="route('product.index')"
                        :action-label="$t('dashboard_products.owner.actions.products')"
                        accent-class="border-t-emerald-600"
                        compact-metrics
                    />
                    <KpiCompositePanel
                        class="rise-in xl:col-span-6"
                        :style="{ animationDelay: '80ms' }"
                        :title="$t('dashboard_products.common.inventory_title')"
                        :subtitle="$t('dashboard_products.common.stock_panel_subtitle')"
                        :metrics="inventoryMetrics"
                        metrics-grid-class="sm:grid-cols-2 xl:grid-cols-2"
                        :summary-items="inventorySummaryItems"
                        summary-grid-class="sm:grid-cols-2"
                        :action-href="route('product.index')"
                        :action-label="$t('dashboard_products.owner.actions.products')"
                        accent-class="border-t-amber-600"
                        compact-metrics
                    />
                </section>
                <AnnouncementsPanel
                    v-if="hasTopAnnouncements"
                    :announcements="announcements"
                    variant="side"
                    :title="$t('dashboard.announcements.title')"
                    :subtitle="$t('dashboard.announcements.subtitle')"
                    :limit="3"
                />
            </div>

            <section
                v-if="hasMarketingKpis"
                class="rise-in rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900"
                :style="{ animationDelay: '95ms' }"
            >
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ $t('dashboard.marketing_panel.title') }}</h2>
                        <p class="text-xs text-stone-500 dark:text-neutral-400">
                            {{ $t('dashboard.marketing_panel.range', {
                                label: marketingRange?.label || '30d',
                                start: marketingRange?.start || '-',
                                end: marketingRange?.end || '-',
                            }) }}
                        </p>
                        <p v-if="!showMarketingPanel" class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                            {{ $t('dashboard.marketing_panel.collapsed_hint') }}
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <button
                            type="button"
                            class="rounded-sm border border-stone-200 bg-stone-50 px-3 py-1.5 text-xs font-semibold text-stone-700 hover:bg-stone-100 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200 dark:hover:bg-neutral-700"
                            @click="toggleMarketingPanel"
                        >
                            {{ showMarketingPanel ? $t('dashboard.marketing_panel.hide') : $t('dashboard.marketing_panel.show') }}
                        </button>
                        <Link
                            :href="route('campaigns.index')"
                            class="rounded-sm border border-stone-200 bg-white px-2.5 py-1.5 text-xs font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                        >
                            {{ $t('dashboard.marketing_panel.open_campaigns') }}
                        </Link>
                    </div>
                </div>

                <div v-if="showMarketingPanel" class="mt-3 space-y-3">
                    <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-4">
                        <div
                            v-for="card in marketingCards"
                            :key="`marketing-kpi-${card.key}`"
                            class="rounded-sm border border-stone-200 bg-stone-50 px-3 py-3 dark:border-neutral-700 dark:bg-neutral-800"
                        >
                            <div class="text-xs uppercase text-stone-500 dark:text-neutral-400">{{ card.label }}</div>
                            <div class="mt-1 text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ card.value }}</div>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-4">
                        <div class="rounded-sm border border-stone-200 bg-white px-3 py-3 dark:border-neutral-700 dark:bg-neutral-900">
                            <div class="text-xs uppercase text-stone-500 dark:text-neutral-400">{{ $t('dashboard.marketing_panel.top_campaign') }}</div>
                            <div class="mt-1 text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                {{ marketingMetrics?.top_performing_campaign?.name || $t('dashboard.marketing_panel.no_data') }}
                            </div>
                        </div>
                        <div class="rounded-sm border border-stone-200 bg-white px-3 py-3 dark:border-neutral-700 dark:bg-neutral-900">
                            <div class="text-xs uppercase text-stone-500 dark:text-neutral-400">{{ $t('dashboard.marketing_panel.audience_growth') }}</div>
                            <div class="mt-1 text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                {{ formatNumber(marketingMetrics?.audience_growth?.current || 0) }}
                            </div>
                            <div class="mt-1 text-xs" :class="audienceGrowthDeltaClass">{{ $t('dashboard.marketing_panel.delta') }}: {{ audienceGrowthDelta }}</div>
                        </div>
                        <div class="rounded-sm border border-stone-200 bg-white px-3 py-3 dark:border-neutral-700 dark:bg-neutral-900">
                            <div class="text-xs uppercase text-stone-500 dark:text-neutral-400">{{ $t('dashboard.marketing_panel.vip_customers') }}</div>
                            <div class="mt-1 text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                {{ formatNumber(marketingMetrics?.vip_count || 0) }}
                            </div>
                        </div>
                        <div class="rounded-sm border border-stone-200 bg-white px-3 py-3 dark:border-neutral-700 dark:bg-neutral-900">
                            <div class="text-xs uppercase text-stone-500 dark:text-neutral-400">{{ $t('dashboard.marketing_panel.mailing_lists') }}</div>
                            <div class="mt-1 text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                {{ $t('dashboard.marketing_panel.list_count', {
                                    count: formatNumber(marketingMetrics?.mailing_lists?.count || 0),
                                }) }}
                            </div>
                            <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                {{ $t('dashboard.marketing_panel.customers_count', {
                                    count: formatNumber(marketingMetrics?.mailing_lists?.customers_total || 0),
                                }) }}
                            </div>
                        </div>
                    </div>

                    <div v-if="marketingCrossModule" class="grid grid-cols-1 gap-2 sm:grid-cols-3">
                        <div class="rounded-sm border border-stone-200 bg-stone-50 px-3 py-2 text-xs text-stone-700 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200">
                            {{ $t('dashboard.marketing_panel.reservations_created', {
                                count: formatNumber(marketingCrossModule.reservations_created || 0),
                            }) }}
                        </div>
                        <div class="rounded-sm border border-stone-200 bg-stone-50 px-3 py-2 text-xs text-stone-700 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200">
                            {{ $t('dashboard.marketing_panel.invoices_paid', {
                                count: formatNumber(marketingCrossModule.invoices_paid || 0),
                            }) }}
                        </div>
                        <div class="rounded-sm border border-stone-200 bg-stone-50 px-3 py-2 text-xs text-stone-700 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200">
                            {{ $t('dashboard.marketing_panel.quotes_accepted', {
                                count: formatNumber(marketingCrossModule.quotes_accepted || 0),
                            }) }}
                        </div>
                    </div>
                </div>
            </section>

            <section :class="[
                'grid grid-cols-1 gap-4 xl:items-start',
                hasProductSideColumn ? 'xl:grid-cols-[minmax(0,1.45fr)_minmax(340px,420px)]' : '',
            ]">
            <Card class="rise-in" :style="{ animationDelay: '100ms' }">
                <template #title>{{ $t('dashboard_products.owner.performance.title') }}</template>
                <div v-if="hasProductSideColumn" class="space-y-4">
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
                        <div
                            v-if="isHydrating"
                            v-for="index in skeletonKpis"
                            :key="`perf-kpi-skeleton-${index}`"
                            class="rounded-sm border border-t-4 border-stone-200 bg-white p-3 text-xs text-stone-500 animate-pulse dark:border-neutral-700 dark:border-t-neutral-600 dark:bg-neutral-900 dark:text-neutral-400"
                        >
                            <div class="flex items-start justify-between gap-2">
                                <div class="space-y-2">
                                    <div class="h-3 w-16 rounded-full bg-stone-200 dark:bg-neutral-700"></div>
                                    <div class="h-4 w-20 rounded-full bg-stone-200 dark:bg-neutral-700"></div>
                                </div>
                                <div class="h-8 w-8 rounded-full bg-stone-200 dark:bg-neutral-700"></div>
                            </div>
                        </div>
                        <div
                            v-else
                            v-for="card in performanceKpis"
                            :key="card.label"
                            class="rounded-sm border border-t-4 border-stone-200 bg-white p-3 text-xs text-stone-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-400"
                            :class="kpiBorderStyles[card.tone] || 'border-t-stone-300 dark:border-t-neutral-600'"
                        >
                            <div class="flex items-start justify-between gap-2">
                                <div>
                                    <p class="uppercase">{{ card.label }}</p>
                                    <p class="mt-1 text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                        {{ card.value }}
                                    </p>
                                </div>
                                <span
                                    class="flex h-8 w-8 items-center justify-center rounded-full shadow-md ring-1 ring-white/20 animate-[pulse_3s_ease-in-out_infinite]"
                                    :class="kpiIconStyles[card.tone] || 'bg-stone-600/90 text-white shadow-stone-500/30'"
                                >
                                    <svg
                                        v-if="performanceKpiIcons[card.key]"
                                        xmlns="http://www.w3.org/2000/svg"
                                        class="h-4 w-4"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="1.8"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                    >
                                        <path v-for="path in performanceKpiIcons[card.key]" :key="path" :d="path" />
                                    </svg>
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
                        <div class="lg:col-span-2 space-y-4">
                            <div>
                                <h3 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                    {{ $t('dashboard_products.owner.performance.top_sellers') }}
                                </h3>
                                <div v-if="isHydrating" class="mt-3 space-y-2">
                                    <div
                                        v-for="index in skeletonRows"
                                        :key="`seller-skeleton-${index}`"
                                        class="flex items-center gap-3 rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm animate-pulse dark:border-neutral-700 dark:bg-neutral-900"
                                    >
                                        <div class="h-4 w-6 rounded-full bg-stone-200 dark:bg-neutral-700"></div>
                                        <div class="h-10 w-10 rounded-full bg-stone-200 dark:bg-neutral-700"></div>
                                        <div class="flex-1 space-y-2">
                                            <div class="h-3 w-32 rounded-full bg-stone-200 dark:bg-neutral-700"></div>
                                            <div class="h-3 w-40 rounded-full bg-stone-200 dark:bg-neutral-700"></div>
                                        </div>
                                    </div>
                                </div>
                                <div v-else-if="!periodStats.top_sellers?.length" class="mt-2 text-sm text-stone-500 dark:text-neutral-400">
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

                        </div>

                        <div class="space-y-3">
                            <div class="rounded-sm border border-emerald-200 bg-emerald-50/50 p-4 text-sm text-emerald-900 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200">
                                <p class="text-xs uppercase tracking-wide text-emerald-700 dark:text-emerald-200">
                                    {{ $t('dashboard_products.owner.performance.seller_of_year') }}
                                </p>
                                <div v-if="isHydrating" class="mt-3 flex items-center gap-3 animate-pulse">
                                    <div class="h-14 w-14 rounded-full bg-stone-200 dark:bg-neutral-700"></div>
                                    <div class="flex-1 space-y-2">
                                        <div class="h-3 w-32 rounded-full bg-stone-200 dark:bg-neutral-700"></div>
                                        <div class="h-3 w-40 rounded-full bg-stone-200 dark:bg-neutral-700"></div>
                                    </div>
                                </div>
                                <div v-else-if="sellerOfYear" class="mt-3 flex items-center gap-3">
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

                <div class="space-y-4">
                    <Card v-if="hasInsightSections" class="rise-in" :style="{ animationDelay: '120ms' }">
                        <template #title>{{ $t('dashboard_products.owner.insights.title') }}</template>
                        <div class="space-y-4">
                            <div class="rounded-sm border border-stone-200 bg-gradient-to-br from-stone-50 via-white to-emerald-50/50 p-4 dark:border-neutral-700 dark:from-neutral-900 dark:via-neutral-900 dark:to-emerald-500/10">
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-stone-500 dark:text-neutral-400">
                                            {{ $t('dashboard_products.owner.insights.live') }}
                                        </p>
                                        <p class="mt-1 text-sm text-stone-600 dark:text-neutral-300">
                                            {{ $t('dashboard_products.owner.insights.subtitle') }}
                                        </p>
                                    </div>
                                </div>

                                <div class="mt-4 grid grid-cols-1 gap-2 sm:grid-cols-3">
                                    <div
                                        v-for="section in insightSections"
                                        :key="`insight-summary-${section.key}`"
                                        class="rounded-sm border px-3 py-2"
                                        :class="insightToneClasses[section.tone]?.summary"
                                    >
                                        <p class="text-lg font-semibold leading-none">
                                            {{ formatNumber(section.items.length) }}
                                        </p>
                                        <p class="mt-1 text-[11px] font-semibold uppercase tracking-wide">
                                            {{ section.title }}
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <div class="space-y-3">
                                <section
                                    v-for="section in insightSections"
                                    :key="section.key"
                                    class="rounded-sm border p-3"
                                    :class="insightToneClasses[section.tone]?.section"
                                >
                                    <div class="flex items-start justify-between gap-3">
                                        <div class="min-w-0">
                                            <h3 class="truncate text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                                {{ section.title }}
                                            </h3>
                                            <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                                {{ section.summary }}
                                            </p>
                                        </div>
                                        <button
                                            type="button"
                                            class="shrink-0 rounded-sm border px-2.5 py-1.5 text-[11px] font-semibold transition disabled:cursor-not-allowed disabled:opacity-50"
                                            :class="insightToneClasses[section.tone]?.button"
                                            :disabled="!section.items.length"
                                            @click="openInsightDialog(section.key)"
                                        >
                                            {{ $t('dashboard_products.owner.insights.view_details') }}
                                        </button>
                                    </div>

                                    <div class="mt-3 space-y-2">
                                        <div v-if="isHydrating" class="space-y-2">
                                            <div
                                                v-for="index in skeletonRows.slice(0, 2)"
                                                :key="`insight-skeleton-${section.key}-${index}`"
                                                class="flex items-center gap-3 rounded-sm border border-stone-200 bg-white/80 px-3 py-2 animate-pulse dark:border-neutral-700 dark:bg-neutral-900/80"
                                            >
                                                <div class="h-10 w-10 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                                                <div class="flex-1 space-y-2">
                                                    <div class="h-3 w-24 rounded-full bg-stone-200 dark:bg-neutral-700"></div>
                                                    <div class="h-3 w-32 rounded-full bg-stone-200 dark:bg-neutral-700"></div>
                                                </div>
                                            </div>
                                        </div>
                                        <div v-else class="space-y-2">
                                            <div
                                                v-for="item in section.previewItems"
                                                :key="`${section.key}-${item.id}`"
                                                class="flex items-center gap-3 rounded-sm border border-stone-200 bg-white/80 px-3 py-2 dark:border-neutral-700 dark:bg-neutral-900/80"
                                            >
                                                <div
                                                    class="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-sm border text-xs font-semibold"
                                                    :class="insightToneClasses[section.tone]?.icon"
                                                >
                                                    <img
                                                        v-if="item.imageUrl"
                                                        :src="item.imageUrl"
                                                        :alt="item.title"
                                                        class="h-full w-full object-cover"
                                                        loading="lazy"
                                                        decoding="async"
                                                    />
                                                    <span v-else>{{ section.symbol }}</span>
                                                </div>
                                                <div class="min-w-0 flex-1">
                                                    <p class="truncate text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                                        {{ item.title }}
                                                    </p>
                                                    <p class="truncate text-xs text-stone-500 dark:text-neutral-400">
                                                        {{ item.meta }}
                                                    </p>
                                                </div>
                                                <div
                                                    v-if="item.sideText || item.badge"
                                                    class="shrink-0 text-right"
                                                >
                                                    <p
                                                        v-if="item.sideText"
                                                        class="text-xs font-semibold text-stone-700 dark:text-neutral-200"
                                                    >
                                                        {{ item.sideText }}
                                                    </p>
                                                    <span
                                                        v-if="item.badge"
                                                        class="mt-1 inline-flex rounded-full px-2 py-1 text-[10px] font-semibold"
                                                        :class="item.badgeClass"
                                                    >
                                                        {{ item.badge }}
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </section>
                            </div>
                        </div>
                    </Card>

                    <AnnouncementsPanel
                        v-if="hasQuickAnnouncements"
                        :announcements="quickAnnouncements"
                        variant="side"
                        :fill-height="false"
                        :title="$t('dashboard.announcements.quick_title')"
                        :subtitle="$t('dashboard.announcements.quick_subtitle')"
                        :limit="3"
                    />
                </div>
            </section>

            <Modal :show="Boolean(activeInsightSection)" max-width="3xl" @close="closeInsightDialog">
                <div v-if="activeInsightSection">
                    <div class="flex items-start justify-between gap-4 border-b border-stone-200 px-5 py-4 dark:border-neutral-700">
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-stone-500 dark:text-neutral-400">
                                {{ $t('dashboard_products.owner.insights.title') }}
                            </p>
                            <h3 class="mt-1 text-lg font-semibold text-stone-900 dark:text-neutral-100">
                                {{ activeInsightSection.title }}
                            </h3>
                            <p class="mt-1 text-sm text-stone-500 dark:text-neutral-400">
                                {{ activeInsightSection.summary }}
                            </p>
                        </div>
                        <button
                            type="button"
                            class="rounded-sm border border-stone-200 bg-white px-3 py-1.5 text-xs font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                            @click="closeInsightDialog"
                        >
                            {{ $t('dashboard_products.owner.insights.close') }}
                        </button>
                    </div>

                    <div class="space-y-4 px-5 py-5">
                        <div
                            v-if="activeInsightSection.key === 'stock-alerts'"
                            class="flex flex-wrap gap-2 text-xs"
                        >
                            <span
                                v-for="signal in stockSignals"
                                :key="signal.label"
                                class="rounded-full px-2 py-1 font-semibold"
                                :class="stockSignalClasses[signal.tone]"
                            >
                                {{ signal.label }}
                            </span>
                        </div>

                        <div v-if="!activeInsightSection.items.length" class="text-sm text-stone-500 dark:text-neutral-400">
                            {{ activeInsightSection.emptyLabel }}
                        </div>
                        <div v-else class="space-y-3">
                            <div
                                v-for="item in activeInsightSection.items"
                                :key="`dialog-${activeInsightSection.key}-${item.id}`"
                                class="rounded-sm border border-stone-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900"
                            >
                                <div class="flex items-start gap-3">
                                    <div
                                        class="flex h-12 w-12 shrink-0 items-center justify-center overflow-hidden rounded-sm border text-sm font-semibold"
                                        :class="insightToneClasses[activeInsightSection.tone]?.icon"
                                    >
                                        <img
                                            v-if="item.imageUrl"
                                            :src="item.imageUrl"
                                            :alt="item.title"
                                            class="h-full w-full object-cover"
                                            loading="lazy"
                                            decoding="async"
                                        />
                                        <span v-else>{{ activeInsightSection.symbol }}</span>
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <div class="flex flex-wrap items-start justify-between gap-3">
                                            <div class="min-w-0">
                                                <p class="truncate text-sm font-semibold text-stone-900 dark:text-neutral-100">
                                                    {{ item.title }}
                                                </p>
                                                <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                                    {{ item.meta }}
                                                </p>
                                            </div>
                                            <div
                                                v-if="item.sideText || item.badge"
                                                class="text-right"
                                            >
                                                <p
                                                    v-if="item.sideText"
                                                    class="text-sm font-semibold text-stone-800 dark:text-neutral-100"
                                                >
                                                    {{ item.sideText }}
                                                </p>
                                                <span
                                                    v-if="item.badge"
                                                    class="mt-1 inline-flex rounded-full px-2 py-1 text-[10px] font-semibold"
                                                    :class="item.badgeClass"
                                                >
                                                    {{ item.badge }}
                                                </span>
                                            </div>
                                        </div>

                                        <div
                                            v-if="activeInsightSection.key === 'stock-alerts'"
                                            class="mt-3 flex justify-end"
                                        >
                                            <button
                                                type="button"
                                                class="rounded-sm border border-stone-200 bg-white px-2.5 py-1.5 text-[11px] font-semibold text-stone-700 hover:bg-stone-50 disabled:cursor-not-allowed disabled:opacity-60 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                                                :disabled="!item.raw?.supplier_email"
                                                @click="requestSupplierStock(item.raw)"
                                            >
                                                {{ $t('dashboard_products.common.request_stock') }}
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </Modal>
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
