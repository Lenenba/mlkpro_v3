<script setup>
import { computed } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AnnouncementsPanel from '@/Components/Dashboard/AnnouncementsPanel.vue';
import KpiSparkline from '@/Components/Dashboard/KpiSparkline.vue';
import KpiTrendBadge from '@/Components/Dashboard/KpiTrendBadge.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { humanizeDate } from '@/utils/date';
import { buildSparklinePoints, buildTrend } from '@/utils/kpi';

const props = defineProps({
    stats: {
        type: Object,
        required: true,
    },
    recentQuotes: {
        type: Array,
        default: () => [],
    },
    upcomingJobs: {
        type: Array,
        default: () => [],
    },
    outstandingInvoices: {
        type: Array,
        default: () => [],
    },
    activity: {
        type: Array,
        default: () => [],
    },
    revenueSeries: {
        type: Object,
        default: () => ({ labels: [], values: [] }),
    },
    kpiSeries: {
        type: Object,
        default: () => ({}),
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
});

const page = usePage();
const userName = computed(() => page.props.auth?.user?.name || 'there');
const companyType = computed(() => page.props.auth?.account?.company?.type ?? null);
const showServices = computed(() => companyType.value !== 'products');
const isOwner = computed(() => Boolean(page.props.auth?.account?.is_owner));
const hasTopAnnouncements = computed(() => (props.announcements || []).length > 0);
const hasQuickAnnouncements = computed(() => (props.quickAnnouncements || []).length > 0);
const billing = computed(() => props.billing || {});
const billingPlans = computed(() => billing.value.plans || []);
const billingSubscription = computed(() => billing.value.subscription || {});
const hasPlanChoices = computed(() => isOwner.value && billingPlans.value.length > 0);
const usageItems = computed(() => props.usage_limits?.items || []);
const usageAlerts = computed(() => usageItems.value.filter((item) => item.status !== 'ok'));
const hasUsageAlerts = computed(() => usageAlerts.value.length > 0);
const planName = computed(() => props.usage_limits?.plan_name || props.usage_limits?.plan_key || '');
const limitLabelMap = {
    quotes: 'Quotes',
    invoices: 'Invoices',
    jobs: 'Jobs',
    products: 'Products',
    services: 'Services',
    tasks: 'Tasks',
    team_members: 'Team members',
};

const isPlanActive = (plan) =>
    Boolean(billingSubscription.value?.price_id && plan?.price_id === billingSubscription.value.price_id);

const stat = (key) => props.stats?.[key] ?? 0;

const formatNumber = (value) =>
    Number(value || 0).toLocaleString(undefined, { maximumFractionDigits: 0 });

const formatCurrency = (value) =>
    `$${Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

const formatDate = (value) => humanizeDate(value);

const revenueMax = computed(() => {
    const values = props.revenueSeries?.values || [];
    const maxValue = Math.max(...values, 0);
    return maxValue > 0 ? maxValue : 1;
});

const revenuePoints = computed(() => {
    const labels = props.revenueSeries?.labels || [];
    const values = props.revenueSeries?.values || [];
    return labels.map((label, index) => {
        const value = Number(values[index] || 0);
        const height = Math.max(6, Math.round((value / revenueMax.value) * 120));
        return {
            label,
            value,
            height: `${height}px`,
        };
    });
});

const kpiSeries = computed(() => props.kpiSeries || {});
const kpiConfig = {
    revenue_paid: { direction: 'up' },
    revenue_outstanding: { direction: 'down' },
    quotes_open: { direction: 'up' },
    works_in_progress: { direction: 'up' },
    customers_total: { direction: 'up' },
    products_low_stock: { direction: 'down' },
    invoices_paid: { direction: 'up' },
    inventory_value: { direction: 'up' },
};
const kpiData = computed(() => {
    const data = {};
    Object.entries(kpiConfig).forEach(([key, config]) => {
        const values = kpiSeries.value?.[key] || [];
        data[key] = {
            points: buildSparklinePoints(values),
            trend: buildTrend(values, config.direction),
        };
    });
    return data;
});

const displayCustomer = (customer) =>
    customer?.company_name ||
    `${customer?.first_name || ''} ${customer?.last_name || ''}`.trim() ||
    'Unknown';

const quoteStatusClass = (status) => {
    switch (status) {
        case 'accepted':
            return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-400';
        case 'declined':
            return 'bg-red-100 text-red-800 dark:bg-red-500/10 dark:text-red-400';
        case 'sent':
            return 'bg-blue-100 text-blue-800 dark:bg-blue-500/10 dark:text-blue-400';
        default:
            return 'bg-stone-100 text-stone-700 dark:bg-neutral-700 dark:text-neutral-200';
    }
};

const jobStatusClass = (status) => {
    switch (status) {
        case 'completed':
            return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-400';
        case 'in_progress':
            return 'bg-blue-100 text-blue-800 dark:bg-blue-500/10 dark:text-blue-400';
        case 'cancelled':
            return 'bg-red-100 text-red-800 dark:bg-red-500/10 dark:text-red-400';
        default:
            return 'bg-amber-100 text-amber-800 dark:bg-amber-500/10 dark:text-amber-400';
    }
};

const invoiceStatusClass = (status) => {
    switch (status) {
        case 'paid':
            return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-400';
        case 'partial':
            return 'bg-amber-100 text-amber-800 dark:bg-amber-500/10 dark:text-amber-400';
        case 'overdue':
            return 'bg-red-100 text-red-800 dark:bg-red-500/10 dark:text-red-400';
        case 'sent':
            return 'bg-blue-100 text-blue-800 dark:bg-blue-500/10 dark:text-blue-400';
        default:
            return 'bg-stone-100 text-stone-700 dark:bg-neutral-700 dark:text-neutral-200';
    }
};

const displayLimitLabel = (item) => limitLabelMap[item.key] || item.label || item.key;
const displayLimitValue = (item) => {
    if (item.limit === null || item.limit === undefined) {
        return 'Unlimited';
    }
    if (Number(item.limit) <= 0) {
        return 'Not available';
    }
    return item.limit;
};

const onboardingChecklist = computed(() => {
    const isServices = showServices.value;
    return [
        {
            key: 'customer',
            label: 'Add your first customer',
            route: 'customer.create',
            completed: stat('customers_total') > 0,
        },
        {
            key: 'catalog',
            label: isServices ? 'Add your first service' : 'Add your first product',
            route: isServices ? 'service.index' : 'product.index',
            completed: stat('products_total') > 0,
        },
        {
            key: 'quote',
            label: 'Create your first quote',
            route: 'quote.index',
            completed: stat('quotes_total') > 0,
        },
        {
            key: 'workflow',
            label: isServices ? 'Plan your first job' : 'Send your first invoice',
            route: isServices ? 'jobs.index' : 'invoice.index',
            completed: (isServices ? stat('works_total') : stat('invoices_total')) > 0,
        },
    ];
});

const checklistCompleted = computed(() =>
    onboardingChecklist.value.filter((item) => item.completed).length
);
const checklistTotal = computed(() => onboardingChecklist.value.length);
const checklistProgress = computed(() => {
    if (!checklistTotal.value) {
        return 0;
    }
    return Math.round((checklistCompleted.value / checklistTotal.value) * 100);
});
const showChecklist = computed(() => checklistCompleted.value < checklistTotal.value);
</script>

<template>
    <Head title="Dashboard" />

    <AuthenticatedLayout>
        <div class="space-y-6">
            <section
                class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div class="space-y-1">
                        <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">
                            Dashboard
                        </h1>
                        <p class="text-sm text-stone-600 dark:text-neutral-400">
                            Welcome back, {{ userName }}. Here is your business snapshot.
                        </p>
                        <div class="flex flex-wrap gap-3 text-xs text-stone-500 dark:text-neutral-400">
                            <span>Quotes this month: {{ formatNumber(stat('quotes_month')) }}</span>
                            <span>Payments this month: {{ formatCurrency(stat('payments_month')) }}</span>
                        </div>
                    </div>
                    <!-- <div class="flex flex-wrap items-center gap-2">
                        <button type="button" data-hs-overlay="#hs-quick-create-quote"
                            class="py-2 px-3 inline-flex items-center gap-x-2 text-xs font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700">
                            New quote
                        </button>
                        <button v-if="showServices && isOwner" type="button" data-hs-overlay="#hs-quick-create-request"
                            class="py-2 px-3 inline-flex items-center gap-x-2 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200">
                            New request
                        </button>
                        <button type="button" data-hs-overlay="#hs-quick-create-customer"
                            class="py-2 px-3 inline-flex items-center gap-x-2 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200">
                            New customer
                        </button>
                        <button type="button" data-hs-overlay="#hs-quick-create-product"
                            class="py-2 px-3 inline-flex items-center gap-x-2 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200">
                            New product
                        </button>
                    </div> -->
                </div>
            </section>

            <section v-if="hasUsageAlerts" class="rounded-sm border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-200">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <div class="font-semibold">Usage alerts</div>
                        <p class="text-xs text-amber-700 dark:text-amber-200">
                            Some modules are close to their limits{{ planName ? ` for plan ${planName}` : '' }}.
                        </p>
                    </div>
                    <Link :href="route('settings.company.edit')" class="text-xs font-semibold text-amber-800 hover:underline dark:text-amber-200">
                        View limits
                    </Link>
                </div>
                <div class="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                    <div v-for="item in usageAlerts" :key="item.key" class="rounded-sm border border-amber-200 bg-white px-3 py-2 text-xs text-amber-800 dark:border-amber-500/30 dark:bg-neutral-900 dark:text-amber-200">
                        <div class="font-semibold">{{ displayLimitLabel(item) }}</div>
                        <div class="mt-1 text-[11px] text-amber-700 dark:text-amber-200">
                            {{ item.used }} / {{ displayLimitValue(item) }}
                            <span v-if="item.percent !== null">({{ item.percent }}%)</span>
                        </div>
                    </div>
                </div>
            </section>

            <div :class="['grid gap-4', hasTopAnnouncements ? 'xl:grid-cols-[minmax(0,1fr)_320px]' : 'grid-cols-1']">
                <section class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-3">
                <div
                    class="p-4 bg-white border border-t-4 border-t-emerald-600 border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                    <div class="space-y-1">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-xs text-stone-500 dark:text-neutral-400">Revenue paid</p>
                            <KpiTrendBadge :trend="kpiData.revenue_paid.trend" />
                        </div>
                        <p class="text-lg font-semibold text-stone-800 dark:text-neutral-100">
                            {{ formatCurrency(stat('revenue_paid')) }}
                        </p>
                        <p class="text-xs text-stone-500 dark:text-neutral-400">
                            Billed {{ formatCurrency(stat('revenue_billed')) }}
                        </p>
                        <KpiSparkline
                            :points="kpiData.revenue_paid.points"
                            color-class="bg-emerald-500/70 dark:bg-emerald-400/50"
                        />
                    </div>
                </div>
                <div
                    class="p-4 bg-white border border-t-4 border-t-amber-600 border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                    <div class="space-y-1">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-xs text-stone-500 dark:text-neutral-400">Outstanding balance</p>
                            <KpiTrendBadge :trend="kpiData.revenue_outstanding.trend" />
                        </div>
                        <p class="text-lg font-semibold text-stone-800 dark:text-neutral-100">
                            {{ formatCurrency(stat('revenue_outstanding')) }}
                        </p>
                        <p class="text-xs text-stone-500 dark:text-neutral-400">
                            Partial invoices {{ formatNumber(stat('invoices_partial')) }}
                        </p>
                        <KpiSparkline
                            :points="kpiData.revenue_outstanding.points"
                            color-class="bg-amber-500/70 dark:bg-amber-400/50"
                        />
                    </div>
                </div>
                <div
                    class="p-4 bg-white border border-t-4 border-t-blue-600 border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                    <div class="space-y-1">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-xs text-stone-500 dark:text-neutral-400">Open quotes</p>
                            <KpiTrendBadge :trend="kpiData.quotes_open.trend" />
                        </div>
                        <p class="text-lg font-semibold text-stone-800 dark:text-neutral-100">
                            {{ formatNumber(stat('quotes_open')) }}
                        </p>
                        <p class="text-xs text-stone-500 dark:text-neutral-400">
                            Accepted {{ formatNumber(stat('quotes_accepted')) }}
                        </p>
                        <KpiSparkline
                            :points="kpiData.quotes_open.points"
                            color-class="bg-blue-500/70 dark:bg-blue-400/50"
                        />
                    </div>
                </div>
                <div
                    class="p-4 bg-white border border-t-4 border-t-indigo-600 border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                    <div class="space-y-1">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-xs text-stone-500 dark:text-neutral-400">Jobs in progress</p>
                            <KpiTrendBadge :trend="kpiData.works_in_progress.trend" />
                        </div>
                        <p class="text-lg font-semibold text-stone-800 dark:text-neutral-100">
                            {{ formatNumber(stat('works_in_progress')) }}
                        </p>
                        <p class="text-xs text-stone-500 dark:text-neutral-400">
                            Scheduled {{ formatNumber(stat('works_scheduled')) }}
                        </p>
                        <KpiSparkline
                            :points="kpiData.works_in_progress.points"
                            color-class="bg-indigo-500/70 dark:bg-indigo-400/50"
                        />
                    </div>
                </div>
                <div
                    class="p-4 bg-white border border-t-4 border-t-sky-600 border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                    <div class="space-y-1">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-xs text-stone-500 dark:text-neutral-400">Customers</p>
                            <KpiTrendBadge :trend="kpiData.customers_total.trend" />
                        </div>
                        <p class="text-lg font-semibold text-stone-800 dark:text-neutral-100">
                            {{ formatNumber(stat('customers_total')) }}
                        </p>
                        <p class="text-xs text-stone-500 dark:text-neutral-400">
                            New last 30 days {{ formatNumber(stat('customers_new')) }}
                        </p>
                        <KpiSparkline
                            :points="kpiData.customers_total.points"
                            color-class="bg-sky-500/70 dark:bg-sky-400/50"
                        />
                    </div>
                </div>
                <div
                    class="p-4 bg-white border border-t-4 border-t-red-600 border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                    <div class="space-y-1">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-xs text-stone-500 dark:text-neutral-400">Low stock</p>
                            <KpiTrendBadge :trend="kpiData.products_low_stock.trend" />
                        </div>
                        <p class="text-lg font-semibold text-stone-800 dark:text-neutral-100">
                            {{ formatNumber(stat('products_low_stock')) }}
                        </p>
                        <p class="text-xs text-stone-500 dark:text-neutral-400">
                            Out of stock {{ formatNumber(stat('products_out')) }}
                        </p>
                        <KpiSparkline
                            :points="kpiData.products_low_stock.points"
                            color-class="bg-red-500/70 dark:bg-red-400/50"
                        />
                    </div>
                </div>
                <div
                    class="p-4 bg-white border border-t-4 border-t-teal-600 border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                    <div class="space-y-1">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-xs text-stone-500 dark:text-neutral-400">Invoices paid</p>
                            <KpiTrendBadge :trend="kpiData.invoices_paid.trend" />
                        </div>
                        <p class="text-lg font-semibold text-stone-800 dark:text-neutral-100">
                            {{ formatNumber(stat('invoices_paid')) }}
                        </p>
                        <p class="text-xs text-stone-500 dark:text-neutral-400">
                            Total invoices {{ formatNumber(stat('invoices_total')) }}
                        </p>
                        <KpiSparkline
                            :points="kpiData.invoices_paid.points"
                            color-class="bg-teal-500/70 dark:bg-teal-400/50"
                        />
                    </div>
                </div>
                <div
                    class="p-4 bg-white border border-t-4 border-t-stone-600 border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                    <div class="space-y-1">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-xs text-stone-500 dark:text-neutral-400">Inventory value</p>
                            <KpiTrendBadge :trend="kpiData.inventory_value.trend" />
                        </div>
                        <p class="text-lg font-semibold text-stone-800 dark:text-neutral-100">
                            {{ formatCurrency(stat('inventory_value')) }}
                        </p>
                        <p class="text-xs text-stone-500 dark:text-neutral-400">
                            Products {{ formatNumber(stat('products_total')) }}
                        </p>
                        <KpiSparkline
                            :points="kpiData.inventory_value.points"
                            color-class="bg-stone-500/70 dark:bg-stone-400/50"
                        />
                    </div>
                </div>
                </section>
                <AnnouncementsPanel
                    v-if="hasTopAnnouncements"
                    :announcements="announcements"
                    variant="side"
                    title="Announcements"
                    subtitle="Active notices for your team."
                    :limit="3"
                />
            </div>

            <section class="grid grid-cols-1 xl:grid-cols-3 gap-4">
                <div class="xl:col-span-2 space-y-4">
                    <div class="bg-white border border-stone-200 rounded-sm p-5 shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                    Revenue trend
                                </h2>
                                <p class="text-xs text-stone-500 dark:text-neutral-400">
                                    Last 6 months payments
                                </p>
                            </div>
                            <Link :href="route('invoice.index')"
                                class="text-xs font-medium text-green-600 hover:text-green-700">
                                View invoices
                            </Link>
                        </div>
                        <div class="mt-4 flex items-end gap-2 h-36">
                            <div v-for="point in revenuePoints" :key="point.label"
                                class="flex-1 flex flex-col items-center gap-2">
                                <div class="w-full rounded-sm bg-emerald-200 dark:bg-emerald-500/30"
                                    :style="{ height: point.height }"></div>
                                <div class="text-[11px] text-stone-500 dark:text-neutral-400">
                                    {{ point.label }}
                                </div>
                            </div>
                        </div>
                        <div class="mt-4 grid grid-cols-1 md:grid-cols-3 gap-3 text-sm">
                            <div>
                                <div class="text-xs text-stone-500 dark:text-neutral-400">Paid to date</div>
                                <div class="font-semibold text-stone-800 dark:text-neutral-100">
                                    {{ formatCurrency(stat('revenue_paid')) }}
                                </div>
                            </div>
                            <div>
                                <div class="text-xs text-stone-500 dark:text-neutral-400">Outstanding</div>
                                <div class="font-semibold text-stone-800 dark:text-neutral-100">
                                    {{ formatCurrency(stat('revenue_outstanding')) }}
                                </div>
                            </div>
                            <div>
                                <div class="text-xs text-stone-500 dark:text-neutral-400">Overdue invoices</div>
                                <div class="font-semibold text-stone-800 dark:text-neutral-100">
                                    {{ formatNumber(stat('invoices_overdue')) }}
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white border border-stone-200 rounded-sm p-5 shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                        <div class="flex items-center justify-between">
                            <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                Recent quotes
                            </h2>
                            <Link :href="route('quote.index')" class="text-xs font-medium text-green-600 hover:text-green-700">
                                View all
                            </Link>
                        </div>
                        <div class="mt-3 overflow-x-auto">
                            <table class="min-w-full divide-y divide-stone-200 dark:divide-neutral-700">
                                <thead>
                                    <tr>
                                        <th class="py-2 text-left text-xs font-medium text-stone-500 dark:text-neutral-400">
                                            Quote
                                        </th>
                                        <th class="py-2 text-left text-xs font-medium text-stone-500 dark:text-neutral-400">
                                            Customer
                                        </th>
                                        <th class="py-2 text-left text-xs font-medium text-stone-500 dark:text-neutral-400">
                                            Status
                                        </th>
                                        <th class="py-2 text-right text-xs font-medium text-stone-500 dark:text-neutral-400">
                                            Total
                                        </th>
                                        <th class="py-2 text-right text-xs font-medium text-stone-500 dark:text-neutral-400">
                                            Created
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-stone-200 dark:divide-neutral-700">
                                    <tr v-for="quote in recentQuotes" :key="quote.id">
                                        <td class="py-2 text-sm text-stone-700 dark:text-neutral-200">
                                            <Link :href="route('customer.quote.show', quote.id)" class="hover:underline">
                                                {{ quote.number || 'Quote' }}
                                            </Link>
                                        </td>
                                        <td class="py-2 text-sm text-stone-600 dark:text-neutral-300">
                                            {{ displayCustomer(quote.customer) }}
                                        </td>
                                        <td class="py-2">
                                            <span class="px-2 py-1 text-xs font-medium rounded-full"
                                                :class="quoteStatusClass(quote.status)">
                                                {{ quote.status || 'draft' }}
                                            </span>
                                        </td>
                                        <td class="py-2 text-sm text-right text-stone-600 dark:text-neutral-300">
                                            {{ formatCurrency(quote.total) }}
                                        </td>
                                        <td class="py-2 text-sm text-right text-stone-500 dark:text-neutral-400">
                                            {{ formatDate(quote.created_at) }}
                                        </td>
                                    </tr>
                                    <tr v-if="!recentQuotes.length">
                                        <td colspan="5" class="py-4 text-sm text-center text-stone-500 dark:text-neutral-400">
                                            No quotes yet.
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="bg-white border border-stone-200 rounded-sm p-5 shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                        <div class="flex items-center justify-between">
                            <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                Upcoming jobs
                            </h2>
                            <Link :href="route('jobs.index')" class="text-xs font-medium text-green-600 hover:text-green-700">
                                View all
                            </Link>
                        </div>
                        <div class="mt-3 space-y-3">
                            <div v-for="job in upcomingJobs" :key="job.id"
                                class="flex flex-wrap items-center justify-between gap-3 rounded-sm border border-stone-200 px-3 py-2 text-sm dark:border-neutral-700">
                                <div>
                                    <Link :href="route('work.show', job.id)" class="font-medium text-stone-800 hover:underline dark:text-neutral-200">
                                        {{ job.job_title }}
                                    </Link>
                                    <div class="text-xs text-stone-500 dark:text-neutral-400">
                                        {{ displayCustomer(job.customer) }}
                                    </div>
                                </div>
                                <div class="flex items-center gap-3">
                                    <span class="text-xs text-stone-500 dark:text-neutral-400">
                                        {{ formatDate(job.start_date) }} {{ job.start_time || '' }}
                                    </span>
                                    <span class="px-2 py-1 text-xs font-medium rounded-full"
                                        :class="jobStatusClass(job.status)">
                                        {{ job.status || 'scheduled' }}
                                    </span>
                                </div>
                            </div>
                            <div v-if="!upcomingJobs.length" class="text-sm text-stone-500 dark:text-neutral-400">
                                No upcoming jobs scheduled.
                            </div>
                        </div>
                    </div>
                </div>

                <div class="space-y-4">
                    <div
                        v-if="showChecklist"
                        class="bg-white border border-stone-200 rounded-sm p-5 shadow-sm dark:bg-neutral-800 dark:border-neutral-700"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                    Getting started checklist
                                </h2>
                                <p class="text-xs text-stone-500 dark:text-neutral-400">
                                    Complete these steps to get comfortable with the platform.
                                </p>
                            </div>
                            <div class="text-xs font-semibold text-emerald-700 dark:text-emerald-300">
                                {{ checklistCompleted }}/{{ checklistTotal }}
                            </div>
                        </div>
                        <div class="mt-3 h-1.5 w-full rounded-full bg-stone-100 dark:bg-neutral-700">
                            <div
                                class="h-1.5 rounded-full bg-emerald-500 dark:bg-emerald-400"
                                :style="{ width: `${checklistProgress}%` }"
                            ></div>
                        </div>
                        <ul class="mt-4 space-y-2 text-sm">
                            <li
                                v-for="item in onboardingChecklist"
                                :key="item.key"
                                class="flex items-start gap-3 rounded-sm border px-3 py-2"
                                :class="item.completed
                                    ? 'border-emerald-200 bg-emerald-50 dark:border-emerald-500/30 dark:bg-emerald-500/10'
                                    : 'border-stone-200 bg-white dark:border-neutral-700 dark:bg-neutral-800'"
                            >
                                <span
                                    class="mt-1 size-2 rounded-full"
                                    :class="item.completed ? 'bg-emerald-600' : 'bg-stone-300 dark:bg-neutral-500'"
                                ></span>
                                <Link
                                    :href="route(item.route)"
                                    class="flex-1 text-sm font-medium"
                                    :class="item.completed
                                        ? 'text-emerald-800 dark:text-emerald-200'
                                        : 'text-stone-700 dark:text-neutral-200'"
                                >
                                    {{ item.label }}
                                </Link>
                                <span v-if="item.completed" class="text-[11px] font-semibold text-emerald-700 dark:text-emerald-300">
                                    Done
                                </span>
                            </li>
                        </ul>
                    </div>
                    <AnnouncementsPanel
                        v-if="hasQuickAnnouncements"
                        :announcements="quickAnnouncements"
                        variant="side"
                        :fill-height="false"
                        title="Announcements"
                        subtitle="Displayed in the quick actions slot."
                        :limit="3"
                    />
                    <div v-else class="bg-white border border-stone-200 rounded-sm p-5 shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                        <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                            Quick actions
                        </h2>
                        <div class="mt-4 grid grid-cols-1 gap-2 text-sm">
                            <button type="button" data-hs-overlay="#hs-quick-create-quote"
                                class="py-2 px-3 rounded-sm border border-stone-200 bg-stone-100 text-stone-700 hover:bg-stone-200 dark:bg-neutral-700 dark:border-neutral-600 dark:text-neutral-200">
                                Create quote
                            </button>
                            <button v-if="showServices && isOwner" type="button" data-hs-overlay="#hs-quick-create-request"
                                class="py-2 px-3 rounded-sm border border-stone-200 bg-stone-100 text-stone-700 hover:bg-stone-200 dark:bg-neutral-700 dark:border-neutral-600 dark:text-neutral-200">
                                Create request
                            </button>
                            <button type="button" data-hs-overlay="#hs-quick-create-customer"
                                class="py-2 px-3 rounded-sm border border-stone-200 bg-stone-100 text-stone-700 hover:bg-stone-200 dark:bg-neutral-700 dark:border-neutral-600 dark:text-neutral-200">
                                Add customer
                            </button>
                            <button type="button" data-hs-overlay="#hs-quick-create-product"
                                class="py-2 px-3 rounded-sm border border-stone-200 bg-stone-100 text-stone-700 hover:bg-stone-200 dark:bg-neutral-700 dark:border-neutral-600 dark:text-neutral-200">
                                Add product
                            </button>
                            <Link :href="route('jobs.index')"
                                class="py-2 px-3 rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200">
                                Review jobs
                            </Link>
                        </div>
                    </div>

                    <div class="bg-white border border-stone-200 rounded-sm p-5 shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                        <div class="flex items-center justify-between">
                            <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                Outstanding invoices
                            </h2>
                            <Link :href="route('invoice.index')" class="text-xs font-medium text-green-600 hover:text-green-700">
                                View all
                            </Link>
                        </div>
                        <div class="mt-3 space-y-3">
                            <div v-for="invoice in outstandingInvoices" :key="invoice.id"
                                class="flex items-center justify-between gap-3 rounded-sm border border-stone-200 px-3 py-2 text-sm dark:border-neutral-700">
                                <div>
                                    <Link :href="route('invoice.show', invoice.id)" class="font-medium text-stone-800 hover:underline dark:text-neutral-200">
                                        {{ invoice.number || 'Invoice' }}
                                    </Link>
                                    <div class="text-xs text-stone-500 dark:text-neutral-400">
                                        {{ displayCustomer(invoice.customer) }}
                                    </div>
                                </div>
                                <div class="text-right">
                                    <div class="text-sm font-medium text-stone-800 dark:text-neutral-200">
                                        {{ formatCurrency(invoice.balance_due) }}
                                    </div>
                                    <span class="px-2 py-0.5 text-xs font-medium rounded-full"
                                        :class="invoiceStatusClass(invoice.status)">
                                        {{ invoice.status }}
                                    </span>
                                </div>
                            </div>
                            <div v-if="!outstandingInvoices.length" class="text-sm text-stone-500 dark:text-neutral-400">
                                All invoices are settled.
                            </div>
                        </div>
                    </div>

                    <div class="bg-white border border-stone-200 rounded-sm p-5 shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                        <div class="flex items-center justify-between">
                            <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                Recent activity
                            </h2>
                        </div>
                        <div class="mt-3 space-y-3 text-sm">
                            <div v-for="log in activity" :key="log.id"
                                class="rounded-sm border border-stone-200 px-3 py-2 dark:border-neutral-700">
                                <div class="text-xs uppercase text-stone-500 dark:text-neutral-400">
                                    {{ log.subject }} · {{ formatDate(log.created_at) }}
                                </div>
                                <div class="text-sm text-stone-700 dark:text-neutral-200">
                                    {{ log.description || log.action }}
                                </div>
                            </div>
                            <div v-if="!activity.length" class="text-sm text-stone-500 dark:text-neutral-400">
                                No recent activity yet.
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
