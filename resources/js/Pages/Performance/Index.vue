<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import KpiMetricGrid from '@/Components/Dashboard/KpiMetricGrid.vue';
import ModuleKpiSection from '@/Components/Dashboard/ModuleKpiSection.vue';
import Card from '@/Components/UI/Card.vue';
import { useCurrencyFormatter } from '@/utils/currency';

const props = defineProps({
    employeePerformance: {
        type: Object,
        default: () => ({ periods: {}, seller_of_periods: {}, seller_of_year: null }),
    },
    clientPerformance: {
        type: Object,
        default: () => ({ periods: {}, customer_of_periods: {}, customer_of_year: null }),
    },
    tab: {
        type: String,
        default: 'clients',
    },
    performanceMode: {
        type: String,
        default: null,
    },
});

const { t } = useI18n();
const page = usePage();
const companyType = computed(() => page.props.auth?.account?.company?.type ?? null);
const isServiceCompany = computed(() =>
    props.performanceMode
        ? props.performanceMode !== 'products'
        : companyType.value !== 'products'
);
const isReservationPerformance = computed(() => props.performanceMode === 'reservations');

const activeTab = ref(props.tab === 'employees' ? 'employees' : 'clients');
const activePeriod = ref('month');
const isHydrating = ref(true);

onMounted(() => {
    setTimeout(() => {
        isHydrating.value = false;
    }, 450);
});

const periodOptions = computed(() => ([
    { key: 'day', label: t('performance.period.day') },
    { key: 'week', label: t('performance.period.week') },
    { key: 'month', label: t('performance.period.month') },
    { key: 'year', label: t('performance.period.year') },
]));

const { formatCurrency } = useCurrencyFormatter();

const formatNumber = (value) =>
    Number(value || 0).toLocaleString(undefined, { maximumFractionDigits: 0 });

const emptyEmployeePeriod = {
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

const emptyClientPeriod = {
    range: { start: '', end: '' },
    orders: 0,
    revenue: 0,
    avg_order: 0,
    avg_customer_value: 0,
    items_sold: 0,
    customers: 0,
    top_customers: [],
};

const employeePeriod = computed(() => props.employeePerformance?.periods?.[activePeriod.value] || emptyEmployeePeriod);
const clientPeriod = computed(() => props.clientPerformance?.periods?.[activePeriod.value] || emptyClientPeriod);

const sellerOfPeriod = computed(() => {
    const periodKey = activePeriod.value;
    const periodSeller = props.employeePerformance?.seller_of_periods?.[periodKey];
    if (periodSeller !== undefined) {
        return periodSeller || null;
    }
    const topSellers = employeePeriod.value.top_sellers || [];
    return topSellers.find((seller) => seller?.type === 'user') ?? topSellers[0] ?? null;
});

const customerOfPeriod = computed(() => {
    const periodKey = activePeriod.value;
    const periodCustomer = props.clientPerformance?.customer_of_periods?.[periodKey];
    if (periodCustomer !== undefined) {
        return periodCustomer || null;
    }
    return clientPeriod.value.top_customers?.[0] ?? null;
});

const sellerHighlightLabel = computed(() =>
    isServiceCompany.value
        ? t(`performance.employees.member_of_${activePeriod.value}`)
        : t(`performance.employees.seller_of_${activePeriod.value}`)
);
const customerHighlightLabel = computed(() => t(`performance.clients.customer_of_${activePeriod.value}`));
const sellerEmptyLabel = computed(() =>
    isServiceCompany.value
        ? t('performance.employees.no_member_period')
        : t('performance.employees.no_seller_period')
);
const customerEmptyLabel = computed(() => t('performance.clients.no_customer_period'));
const periodBadgeText = computed(() => periodOptions.value.find((option) => option.key === activePeriod.value)?.label || '');
const subtitleLabel = computed(() =>
    isServiceCompany.value ? t('performance.subtitle_services') : t('performance.subtitle')
);
const clientTabLabel = computed(() =>
    isServiceCompany.value ? t('performance.tabs.clients_services') : t('performance.tabs.clients')
);
const employeeTabLabel = computed(() =>
    isServiceCompany.value ? t('performance.tabs.employees_services') : t('performance.tabs.employees')
);
const topSellersLabel = computed(() =>
    isServiceCompany.value ? t('performance.employees.top_team') : t('performance.employees.top_sellers')
);
const noSellersLabel = computed(() =>
    isServiceCompany.value ? t('performance.employees.no_members') : t('performance.employees.no_sellers')
);
const topProductsLabel = computed(() =>
    isReservationPerformance.value
        ? t('dashboard.scenario.top_services')
        : (isServiceCompany.value ? t('performance.employees.top_jobs') : t('performance.employees.top_products'))
);
const noProductsLabel = computed(() =>
    isReservationPerformance.value
        ? t('services.empty')
        : (isServiceCompany.value ? t('performance.employees.no_jobs') : t('performance.employees.no_products'))
);

const reservationLine = (revenue, reservations, services) =>
    `${formatCurrency(revenue)} · ${formatNumber(reservations)} ${t('nav.reservations')} · ${formatNumber(services)} ${t('nav.services')}`;

const clientLine = (customer) => {
    if (isReservationPerformance.value) {
        return reservationLine(customer.revenue, customer.orders, customer.items);
    }

    return t(
        isServiceCompany.value ? 'performance.clients.line_services' : 'performance.clients.line',
        {
            revenue: formatCurrency(customer.revenue),
            orders: formatNumber(customer.orders),
            items: formatNumber(customer.items),
        },
    );
};

const employeeLine = (employee) => {
    if (isReservationPerformance.value) {
        return reservationLine(employee.revenue, employee.orders, employee.items);
    }

    return t(
        isServiceCompany.value ? 'performance.employees.member_line' : 'performance.employees.seller_line',
        {
            revenue: formatCurrency(employee.revenue),
            orders: formatNumber(employee.orders),
            items: formatNumber(employee.items),
        },
    );
};

const productLine = (product) => {
    if (isReservationPerformance.value) {
        return `${formatCurrency(product.revenue)} · ${formatNumber(product.quantity)} ${t('nav.services')}`;
    }

    return t(
        isServiceCompany.value ? 'performance.employees.job_line' : 'performance.employees.product_line',
        {
            revenue: formatCurrency(product.revenue),
            quantity: formatNumber(product.quantity),
        },
    );
};

const highlightThemes = {
    day: {
        seller: {
            container: 'border-emerald-200 bg-emerald-50/70 dark:border-emerald-500/30 dark:bg-emerald-500/10',
            accent: 'bg-emerald-500',
            badge: 'bg-emerald-600 text-white',
            text: 'text-emerald-900 dark:text-emerald-200',
            label: 'text-emerald-700 dark:text-emerald-200',
            subtle: 'text-emerald-700/80 dark:text-emerald-200/80',
            avatarBorder: 'border-emerald-200 dark:border-emerald-500/40',
        },
        client: {
            container: 'border-amber-200 bg-amber-50/70 dark:border-amber-500/30 dark:bg-amber-500/10',
            accent: 'bg-amber-500',
            badge: 'bg-amber-600 text-white',
            text: 'text-amber-900 dark:text-amber-200',
            label: 'text-amber-700 dark:text-amber-200',
            subtle: 'text-amber-700/80 dark:text-amber-200/80',
            avatarBorder: 'border-amber-200 dark:border-amber-500/40',
        },
    },
    week: {
        seller: {
            container: 'border-sky-200 bg-sky-50/70 dark:border-sky-500/30 dark:bg-sky-500/10',
            accent: 'bg-sky-500',
            badge: 'bg-sky-600 text-white',
            text: 'text-sky-900 dark:text-sky-200',
            label: 'text-sky-700 dark:text-sky-200',
            subtle: 'text-sky-700/80 dark:text-sky-200/80',
            avatarBorder: 'border-sky-200 dark:border-sky-500/40',
        },
        client: {
            container: 'border-orange-200 bg-orange-50/70 dark:border-orange-500/30 dark:bg-orange-500/10',
            accent: 'bg-orange-500',
            badge: 'bg-orange-600 text-white',
            text: 'text-orange-900 dark:text-orange-200',
            label: 'text-orange-700 dark:text-orange-200',
            subtle: 'text-orange-700/80 dark:text-orange-200/80',
            avatarBorder: 'border-orange-200 dark:border-orange-500/40',
        },
    },
    month: {
        seller: {
            container: 'border-teal-200 bg-teal-50/70 dark:border-teal-500/30 dark:bg-teal-500/10',
            accent: 'bg-teal-500',
            badge: 'bg-teal-600 text-white',
            text: 'text-teal-900 dark:text-teal-200',
            label: 'text-teal-700 dark:text-teal-200',
            subtle: 'text-teal-700/80 dark:text-teal-200/80',
            avatarBorder: 'border-teal-200 dark:border-teal-500/40',
        },
        client: {
            container: 'border-yellow-200 bg-yellow-50/70 dark:border-yellow-500/30 dark:bg-yellow-500/10',
            accent: 'bg-yellow-500',
            badge: 'bg-yellow-600 text-white',
            text: 'text-yellow-900 dark:text-yellow-200',
            label: 'text-yellow-700 dark:text-yellow-200',
            subtle: 'text-yellow-700/80 dark:text-yellow-200/80',
            avatarBorder: 'border-yellow-200 dark:border-yellow-500/40',
        },
    },
    year: {
        seller: {
            container: 'border-stone-200 bg-stone-50/70 dark:border-stone-700 dark:bg-neutral-900',
            accent: 'bg-stone-500',
            badge: 'bg-stone-700 text-white',
            text: 'text-stone-900 dark:text-neutral-100',
            label: 'text-stone-600 dark:text-neutral-300',
            subtle: 'text-stone-600/80 dark:text-neutral-400',
            avatarBorder: 'border-stone-200 dark:border-stone-700',
        },
        client: {
            container: 'border-stone-200 bg-stone-50/70 dark:border-stone-700 dark:bg-neutral-900',
            accent: 'bg-stone-500',
            badge: 'bg-stone-700 text-white',
            text: 'text-stone-900 dark:text-neutral-100',
            label: 'text-stone-600 dark:text-neutral-300',
            subtle: 'text-stone-600/80 dark:text-neutral-400',
            avatarBorder: 'border-stone-200 dark:border-stone-700',
        },
    },
};

const sellerHighlightStyles = computed(() => highlightThemes[activePeriod.value]?.seller || highlightThemes.month.seller);
const customerHighlightStyles = computed(() => highlightThemes[activePeriod.value]?.client || highlightThemes.month.client);

const kpiTones = {
    revenue: 'emerald',
    orders: 'sky',
    items_sold: 'amber',
    jobs: 'indigo',
    tasks: 'rose',
    avg_order: 'violet',
    avg_job: 'violet',
    customers: 'rose',
    avg_customer_value: 'teal',
    revenue_per_seller: 'cyan',
    revenue_per_member: 'cyan',
};

const buildKpis = (items) =>
    items.map((item) => {
        const iconKey = item.iconKey || item.key;
        return {
            ...item,
            tone: kpiTones[iconKey] || 'stone',
        };
    });

const clientKpis = computed(() => buildKpis([
    { key: 'revenue', label: t('performance.kpi.revenue'), value: formatCurrency(clientPeriod.value.revenue) },
    {
        key: isServiceCompany.value ? 'jobs' : 'orders',
        iconKey: isServiceCompany.value ? 'jobs' : 'orders',
        label: isReservationPerformance.value
            ? t('nav.reservations')
            : (isServiceCompany.value ? t('performance.kpi.jobs') : t('performance.kpi.orders')),
        value: formatNumber(clientPeriod.value.orders),
    },
    {
        key: isServiceCompany.value ? 'tasks' : 'items_sold',
        iconKey: isServiceCompany.value ? 'tasks' : 'items_sold',
        label: isReservationPerformance.value
            ? t('nav.services')
            : (isServiceCompany.value ? t('performance.kpi.tasks') : t('performance.kpi.items_sold')),
        value: formatNumber(clientPeriod.value.items_sold),
    },
    {
        key: isServiceCompany.value ? 'avg_job' : 'avg_order',
        iconKey: isServiceCompany.value ? 'avg_job' : 'avg_order',
        label: isReservationPerformance.value
            ? t('performance.kpi.avg_service')
            : (isServiceCompany.value ? t('performance.kpi.avg_job') : t('performance.kpi.avg_order')),
        value: formatCurrency(clientPeriod.value.avg_order),
    },
    { key: 'customers', label: t('performance.kpi.customers'), value: formatNumber(clientPeriod.value.customers) },
    { key: 'avg_customer_value', label: t('performance.kpi.avg_customer_value'), value: formatCurrency(clientPeriod.value.avg_customer_value) },
]));

const employeeKpis = computed(() => buildKpis([
    { key: 'revenue', label: t('performance.kpi.revenue'), value: formatCurrency(employeePeriod.value.revenue) },
    {
        key: isServiceCompany.value ? 'jobs' : 'orders',
        iconKey: isServiceCompany.value ? 'jobs' : 'orders',
        label: isReservationPerformance.value
            ? t('nav.reservations')
            : (isServiceCompany.value ? t('performance.kpi.jobs') : t('performance.kpi.orders')),
        value: formatNumber(employeePeriod.value.orders),
    },
    {
        key: isServiceCompany.value ? 'tasks' : 'items_sold',
        iconKey: isServiceCompany.value ? 'tasks' : 'items_sold',
        label: isReservationPerformance.value
            ? t('nav.services')
            : (isServiceCompany.value ? t('performance.kpi.tasks') : t('performance.kpi.items_sold')),
        value: formatNumber(employeePeriod.value.items_sold),
    },
    {
        key: isServiceCompany.value ? 'avg_job' : 'avg_order',
        iconKey: isServiceCompany.value ? 'avg_job' : 'avg_order',
        label: isReservationPerformance.value
            ? t('performance.kpi.avg_service')
            : (isServiceCompany.value ? t('performance.kpi.avg_job') : t('performance.kpi.avg_order')),
        value: formatCurrency(employeePeriod.value.avg_order),
    },
    { key: 'customers', label: t('performance.kpi.customers'), value: formatNumber(employeePeriod.value.customers) },
    {
        key: isServiceCompany.value ? 'revenue_per_member' : 'revenue_per_seller',
        iconKey: isServiceCompany.value ? 'revenue_per_member' : 'revenue_per_seller',
        label: isServiceCompany.value ? t('performance.kpi.revenue_per_member') : t('performance.kpi.revenue_per_seller'),
        value: formatCurrency(employeePeriod.value.revenue_per_seller),
    },
]));

const sellerDisplayName = (seller) => {
    if (!isServiceCompany.value && seller?.type === 'online') {
        return t('performance.employees.online_label');
    }
    return seller?.name || (isServiceCompany.value
        ? t('performance.employees.member_fallback')
        : t('performance.employees.seller_fallback'));
};

const customerDisplayName = (customer) => customer?.name || t('performance.clients.customer_fallback');

const initials = (label) => {
    const clean = String(label || '').trim();
    if (!clean) {
        return '--';
    }
    const parts = clean.split(/\s+/).slice(0, 2);
    return parts.map((part) => part[0]?.toUpperCase()).join('');
};

const rangeLabel = computed(() => {
    const range = activeTab.value === 'employees' ? employeePeriod.value.range : clientPeriod.value.range;
    if (!range?.start) {
        return '';
    }
    return t('performance.range', { start: range.start, end: range.end });
});

const activeSellersLabel = computed(() =>
    isServiceCompany.value
        ? t('performance.employees.active_members', { count: formatNumber(employeePeriod.value.active_sellers) })
        : t('performance.employees.active_sellers', { count: formatNumber(employeePeriod.value.active_sellers) }),
);

const skeletonKpis = Array.from({ length: 6 }, (_, index) => index);
const skeletonRows = Array.from({ length: 4 }, (_, index) => index);
</script>

<template>
    <AuthenticatedLayout>
        <Head :title="t('performance.title')" />

        <div class="space-y-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">
                        {{ t('performance.title') }}
                    </h1>
                    <p class="text-sm text-stone-500 dark:text-neutral-400">
                        {{ subtitleLabel }}
                    </p>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <button
                    type="button"
                    class="rounded-full px-3 py-1.5 text-xs font-semibold transition"
                    :class="activeTab === 'clients'
                        ? 'bg-green-600 text-white shadow-sm'
                        : 'bg-stone-100 text-stone-600 hover:bg-stone-200 dark:bg-neutral-800 dark:text-neutral-300 dark:hover:bg-neutral-700'"
                    @click="activeTab = 'clients'"
                >
                    {{ clientTabLabel }}
                </button>
                <button
                    type="button"
                    class="rounded-full px-3 py-1.5 text-xs font-semibold transition"
                    :class="activeTab === 'employees'
                        ? 'bg-green-600 text-white shadow-sm'
                        : 'bg-stone-100 text-stone-600 hover:bg-stone-200 dark:bg-neutral-800 dark:text-neutral-300 dark:hover:bg-neutral-700'"
                    @click="activeTab = 'employees'"
                >
                    {{ employeeTabLabel }}
                </button>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <button
                    v-for="period in periodOptions"
                    :key="period.key"
                    type="button"
                    class="rounded-full px-3 py-1.5 text-xs font-semibold transition"
                    :class="activePeriod === period.key
                        ? 'bg-stone-800 text-white dark:bg-neutral-100 dark:text-neutral-900'
                        : 'bg-stone-100 text-stone-600 hover:bg-stone-200 dark:bg-neutral-800 dark:text-neutral-300 dark:hover:bg-neutral-700'"
                    @click="activePeriod = period.key"
                >
                    {{ period.label }}
                </button>
                <span v-if="rangeLabel" class="text-xs text-stone-500 dark:text-neutral-400">
                    {{ rangeLabel }}
                </span>
                <span v-if="activeTab === 'employees'" class="text-xs text-stone-500 dark:text-neutral-400">
                    {{ activeSellersLabel }}
                </span>
            </div>

            <div v-if="activeTab === 'clients'" class="space-y-4">
                <ModuleKpiSection module-key="performance">
                    <div v-if="isHydrating" class="grid grid-cols-[repeat(auto-fit,minmax(min(100%,12rem),1fr))] gap-3">
                        <div
                            v-for="index in skeletonKpis"
                            :key="`client-kpi-skeleton-${index}`"
                            class="rounded-sm border border-t-4 border-stone-200 bg-white p-4 text-xs text-stone-500 shadow-sm animate-pulse dark:border-neutral-700 dark:border-t-neutral-600 dark:bg-neutral-900 dark:text-neutral-400"
                        >
                            <div class="flex items-start justify-between gap-2">
                                <div class="space-y-2">
                                    <div class="h-3 w-16 rounded-full bg-stone-200 dark:bg-neutral-700"></div>
                                    <div class="h-4 w-20 rounded-full bg-stone-200 dark:bg-neutral-700"></div>
                                </div>
                                <div class="h-9 w-9 rounded-full bg-stone-200 dark:bg-neutral-700"></div>
                            </div>
                        </div>
                    </div>
                    <KpiMetricGrid v-else :metrics="clientKpis" />
                </ModuleKpiSection>

                <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
                    <Card class="lg:col-span-2">
                        <template #title>{{ t('performance.clients.top_customers') }}</template>
                        <div v-if="isHydrating" class="space-y-2">
                            <div
                                v-for="index in skeletonRows"
                                :key="`client-row-skeleton-${index}`"
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
                        <div v-else-if="!clientPeriod.top_customers?.length" class="text-sm text-stone-500 dark:text-neutral-400">
                            {{ t('performance.clients.no_customers') }}
                        </div>
                        <div v-else class="space-y-2">
                            <div
                                v-for="(customer, index) in clientPeriod.top_customers"
                                :key="customer.id"
                                class="flex items-center gap-3 rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900"
                            >
                                <span class="w-6 text-xs font-semibold text-stone-400">#{{ index + 1 }}</span>
                                <div class="h-10 w-10 overflow-hidden rounded-full border border-stone-200 bg-stone-100 dark:border-neutral-700 dark:bg-neutral-800">
                                    <img
                                        v-if="customer.logo_url"
                                        :src="customer.logo_url"
                                        :alt="customerDisplayName(customer)"
                                        class="h-full w-full object-cover"
                                        loading="lazy"
                                        decoding="async"
                                    />
                                    <div v-else class="flex h-full w-full items-center justify-center text-xs font-semibold text-stone-600 dark:text-neutral-300">
                                        {{ initials(customerDisplayName(customer)) }}
                                    </div>
                                </div>
                                <div class="flex-1">
                                    <p class="font-semibold text-stone-800 dark:text-neutral-100">{{ customerDisplayName(customer) }}</p>
                                    <p class="text-xs text-stone-500 dark:text-neutral-400">
                                        {{ clientLine(customer) }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </Card>

                    <div class="space-y-3">
                        <div
                            class="relative overflow-hidden rounded-sm border p-4 ps-5 text-sm"
                            :class="[customerHighlightStyles.container, customerHighlightStyles.text]"
                        >
                            <span
                                class="absolute -top-6 -end-6 size-20 rounded-full opacity-20 blur-2xl animate-[pulse_6s_ease-in-out_infinite]"
                                :class="customerHighlightStyles.accent"
                            ></span>
                            <span class="absolute inset-y-0 start-0 w-1" :class="customerHighlightStyles.accent"></span>
                            <div class="flex items-center justify-between gap-2">
                                <p class="text-xs uppercase tracking-wide" :class="customerHighlightStyles.label">
                                    {{ customerHighlightLabel }}
                                </p>
                                <span
                                    v-if="periodBadgeText"
                                    class="rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide"
                                    :class="customerHighlightStyles.badge"
                                >
                                    {{ periodBadgeText }}
                                </span>
                            </div>
                            <p v-if="rangeLabel" class="mt-1 text-[11px]" :class="customerHighlightStyles.subtle">
                                {{ rangeLabel }}
                            </p>
                            <div v-if="isHydrating" class="mt-3 flex items-center gap-3 animate-pulse">
                                <div class="h-14 w-14 rounded-full bg-stone-200 dark:bg-neutral-700"></div>
                                <div class="flex-1 space-y-2">
                                    <div class="h-3 w-32 rounded-full bg-stone-200 dark:bg-neutral-700"></div>
                                    <div class="h-3 w-40 rounded-full bg-stone-200 dark:bg-neutral-700"></div>
                                </div>
                            </div>
                            <div v-else-if="customerOfPeriod" class="mt-3 flex items-center gap-3">
                                <div
                                    class="h-14 w-14 overflow-hidden rounded-full border bg-white dark:bg-neutral-900"
                                    :class="customerHighlightStyles.avatarBorder"
                                >
                                    <img
                                        v-if="customerOfPeriod.logo_url"
                                        :src="customerOfPeriod.logo_url"
                                        :alt="customerDisplayName(customerOfPeriod)"
                                        class="h-full w-full object-cover"
                                        loading="lazy"
                                        decoding="async"
                                    />
                                    <div v-else class="flex h-full w-full items-center justify-center text-sm font-semibold" :class="customerHighlightStyles.label">
                                        {{ initials(customerDisplayName(customerOfPeriod)) }}
                                    </div>
                                </div>
                                <div>
                                    <p class="text-sm font-semibold">{{ customerDisplayName(customerOfPeriod) }}</p>
                                        <p class="text-xs" :class="customerHighlightStyles.subtle">
                                            {{ clientLine(customerOfPeriod) }}
                                        </p>
                                </div>
                            </div>
                            <p v-else class="mt-3 text-sm" :class="customerHighlightStyles.subtle">
                                {{ customerEmptyLabel }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <div v-else class="space-y-4">
                <ModuleKpiSection module-key="performance">
                    <div v-if="isHydrating" class="grid grid-cols-[repeat(auto-fit,minmax(min(100%,12rem),1fr))] gap-3">
                        <div
                            v-for="index in skeletonKpis"
                            :key="`employee-kpi-skeleton-${index}`"
                            class="rounded-sm border border-t-4 border-stone-200 bg-white p-4 text-xs text-stone-500 shadow-sm animate-pulse dark:border-neutral-700 dark:border-t-neutral-600 dark:bg-neutral-900 dark:text-neutral-400"
                        >
                            <div class="flex items-start justify-between gap-2">
                                <div class="space-y-2">
                                    <div class="h-3 w-16 rounded-full bg-stone-200 dark:bg-neutral-700"></div>
                                    <div class="h-4 w-20 rounded-full bg-stone-200 dark:bg-neutral-700"></div>
                                </div>
                                <div class="h-9 w-9 rounded-full bg-stone-200 dark:bg-neutral-700"></div>
                            </div>
                        </div>
                    </div>
                    <KpiMetricGrid v-else :metrics="employeeKpis" />
                </ModuleKpiSection>

                <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
                    <div class="lg:col-span-2 space-y-4">
                        <Card>
                            <template #title>{{ topSellersLabel }}</template>
                            <div v-if="isHydrating" class="space-y-2">
                                <div
                                    v-for="index in skeletonRows"
                                    :key="`seller-row-skeleton-${index}`"
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
                            <div v-else-if="!employeePeriod.top_sellers?.length" class="text-sm text-stone-500 dark:text-neutral-400">
                                {{ noSellersLabel }}
                            </div>
                            <div v-else class="space-y-2">
                                <div
                                    v-for="(seller, index) in employeePeriod.top_sellers"
                                    :key="seller.id"
                                    class="flex flex-wrap items-center gap-3 rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900"
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
                                            {{ initials(sellerDisplayName(seller)) }}
                                        </div>
                                    </div>
                                    <div class="flex-1">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <p class="font-semibold text-stone-800 dark:text-neutral-100">{{ sellerDisplayName(seller) }}</p>
                                            <span
                                                v-if="!isServiceCompany && seller.type === 'online'"
                                                class="rounded-full bg-sky-100 px-2 py-0.5 text-[10px] font-semibold text-sky-700 dark:bg-sky-500/10 dark:text-sky-200"
                                            >
                                                {{ t('performance.employees.online_badge') }}
                                            </span>
                                        </div>
                                        <p class="text-xs text-stone-500 dark:text-neutral-400">
                                            {{ employeeLine(seller) }}
                                        </p>
                                    </div>
                                    <Link
                                        v-if="seller.type === 'user'"
                                        :href="route('performance.employee.show', seller.id)"
                                        class="ml-auto rounded-sm border border-stone-200 bg-white px-2 py-1 text-[11px] font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                                    >
                                        {{ t('performance.employees.view_employee') }}
                                    </Link>
                                </div>
                            </div>
                        </Card>

                        <Card>
                            <template #title>{{ topProductsLabel }}</template>
                            <div v-if="isHydrating" class="grid grid-cols-1 gap-3 md:grid-cols-2">
                                <div
                                    v-for="index in skeletonRows"
                                    :key="`product-row-skeleton-${index}`"
                                    class="flex items-center gap-3 rounded-sm border border-stone-200 bg-white p-3 text-sm animate-pulse dark:border-neutral-700 dark:bg-neutral-900"
                                >
                                    <div class="h-12 w-12 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                                    <div class="flex-1 space-y-2">
                                        <div class="h-3 w-28 rounded-full bg-stone-200 dark:bg-neutral-700"></div>
                                        <div class="h-3 w-32 rounded-full bg-stone-200 dark:bg-neutral-700"></div>
                                    </div>
                                </div>
                            </div>
                            <div v-else-if="!employeePeriod.top_products?.length" class="text-sm text-stone-500 dark:text-neutral-400">
                                {{ noProductsLabel }}
                            </div>
                            <div v-else class="grid grid-cols-1 gap-3 md:grid-cols-2">
                                <div
                                    v-for="product in employeePeriod.top_products"
                                    :key="product.id"
                                    class="flex items-center gap-3 rounded-sm border border-stone-200 bg-white p-3 text-sm dark:border-neutral-700 dark:bg-neutral-900"
                                >
                                    <div class="h-12 w-12 overflow-hidden rounded-sm border border-stone-200 bg-stone-100 dark:border-neutral-700 dark:bg-neutral-800">
                                        <img
                                            v-if="product.image_url"
                                            :src="product.image_url"
                                            :alt="product.name"
                                            class="h-full w-full object-cover"
                                            loading="lazy"
                                            decoding="async"
                                        />
                                        <div v-else class="flex h-full w-full items-center justify-center text-xs font-semibold text-stone-600 dark:text-neutral-300">
                                            {{ initials(product.name) }}
                                        </div>
                                    </div>
                                    <div class="flex-1">
                                        <p class="font-semibold text-stone-800 dark:text-neutral-100">{{ product.name }}</p>
                                        <p class="text-xs text-stone-500 dark:text-neutral-400">
                                            {{ productLine(product) }}
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </Card>
                    </div>

                    <div class="space-y-3">
                        <div
                            class="relative overflow-hidden rounded-sm border p-4 ps-5 text-sm"
                            :class="[sellerHighlightStyles.container, sellerHighlightStyles.text]"
                        >
                            <span
                                class="absolute -top-6 -end-6 size-20 rounded-full opacity-20 blur-2xl animate-[pulse_6s_ease-in-out_infinite]"
                                :class="sellerHighlightStyles.accent"
                            ></span>
                            <span class="absolute inset-y-0 start-0 w-1" :class="sellerHighlightStyles.accent"></span>
                            <div class="flex items-center justify-between gap-2">
                                <p class="text-xs uppercase tracking-wide" :class="sellerHighlightStyles.label">
                                    {{ sellerHighlightLabel }}
                                </p>
                                <span
                                    v-if="periodBadgeText"
                                    class="rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide"
                                    :class="sellerHighlightStyles.badge"
                                >
                                    {{ periodBadgeText }}
                                </span>
                            </div>
                            <p v-if="rangeLabel" class="mt-1 text-[11px]" :class="sellerHighlightStyles.subtle">
                                {{ rangeLabel }}
                            </p>
                            <div v-if="isHydrating" class="mt-3 flex items-center gap-3 animate-pulse">
                                <div class="h-14 w-14 rounded-full bg-stone-200 dark:bg-neutral-700"></div>
                                <div class="flex-1 space-y-2">
                                    <div class="h-3 w-32 rounded-full bg-stone-200 dark:bg-neutral-700"></div>
                                    <div class="h-3 w-40 rounded-full bg-stone-200 dark:bg-neutral-700"></div>
                                </div>
                            </div>
                            <div v-else-if="sellerOfPeriod" class="mt-3 flex items-center gap-3">
                                <div
                                    class="h-14 w-14 overflow-hidden rounded-full border bg-white dark:bg-neutral-900"
                                    :class="sellerHighlightStyles.avatarBorder"
                                >
                                    <img
                                        v-if="sellerOfPeriod.profile_picture_url"
                                        :src="sellerOfPeriod.profile_picture_url"
                                        :alt="sellerDisplayName(sellerOfPeriod)"
                                        class="h-full w-full object-cover"
                                        loading="lazy"
                                        decoding="async"
                                    />
                                    <div v-else class="flex h-full w-full items-center justify-center text-sm font-semibold" :class="sellerHighlightStyles.label">
                                        {{ initials(sellerDisplayName(sellerOfPeriod)) }}
                                    </div>
                                </div>
                                <div>
                                    <p class="text-sm font-semibold">{{ sellerDisplayName(sellerOfPeriod) }}</p>
                                    <p class="text-xs" :class="sellerHighlightStyles.subtle">
                                        {{ employeeLine(sellerOfPeriod) }}
                                    </p>
                                </div>
                            </div>
                            <p v-else class="mt-3 text-sm" :class="sellerHighlightStyles.subtle">
                                {{ sellerEmptyLabel }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
