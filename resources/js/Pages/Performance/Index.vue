<script setup>
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Card from '@/Components/UI/Card.vue';

const props = defineProps({
    employeePerformance: {
        type: Object,
        default: () => ({ periods: {}, seller_of_year: null }),
    },
    clientPerformance: {
        type: Object,
        default: () => ({ periods: {}, customer_of_year: null }),
    },
    tab: {
        type: String,
        default: 'clients',
    },
});

const { t } = useI18n();

const activeTab = ref(props.tab === 'employees' ? 'employees' : 'clients');
const activePeriod = ref('month');

const periodOptions = computed(() => ([
    { key: 'day', label: t('performance.period.day') },
    { key: 'week', label: t('performance.period.week') },
    { key: 'month', label: t('performance.period.month') },
    { key: 'year', label: t('performance.period.year') },
]));

const formatCurrency = (value) =>
    `$${Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

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

const sellerOfYear = computed(() => props.employeePerformance?.seller_of_year || null);
const customerOfYear = computed(() => props.clientPerformance?.customer_of_year || null);

const clientKpis = computed(() => ([
    { label: t('performance.kpi.revenue'), value: formatCurrency(clientPeriod.value.revenue) },
    { label: t('performance.kpi.orders'), value: formatNumber(clientPeriod.value.orders) },
    { label: t('performance.kpi.items_sold'), value: formatNumber(clientPeriod.value.items_sold) },
    { label: t('performance.kpi.avg_order'), value: formatCurrency(clientPeriod.value.avg_order) },
    { label: t('performance.kpi.customers'), value: formatNumber(clientPeriod.value.customers) },
    { label: t('performance.kpi.avg_customer_value'), value: formatCurrency(clientPeriod.value.avg_customer_value) },
]));

const employeeKpis = computed(() => ([
    { label: t('performance.kpi.revenue'), value: formatCurrency(employeePeriod.value.revenue) },
    { label: t('performance.kpi.orders'), value: formatNumber(employeePeriod.value.orders) },
    { label: t('performance.kpi.items_sold'), value: formatNumber(employeePeriod.value.items_sold) },
    { label: t('performance.kpi.avg_order'), value: formatCurrency(employeePeriod.value.avg_order) },
    { label: t('performance.kpi.customers'), value: formatNumber(employeePeriod.value.customers) },
    { label: t('performance.kpi.revenue_per_seller'), value: formatCurrency(employeePeriod.value.revenue_per_seller) },
]));

const sellerDisplayName = (seller) => {
    if (seller?.type === 'online') {
        return t('performance.employees.online_label');
    }
    return seller?.name || t('performance.employees.seller_fallback');
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
    t('performance.employees.active_sellers', { count: formatNumber(employeePeriod.value.active_sellers) }),
);
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
                        {{ t('performance.subtitle') }}
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
                    {{ t('performance.tabs.clients') }}
                </button>
                <button
                    type="button"
                    class="rounded-full px-3 py-1.5 text-xs font-semibold transition"
                    :class="activeTab === 'employees'
                        ? 'bg-green-600 text-white shadow-sm'
                        : 'bg-stone-100 text-stone-600 hover:bg-stone-200 dark:bg-neutral-800 dark:text-neutral-300 dark:hover:bg-neutral-700'"
                    @click="activeTab = 'employees'"
                >
                    {{ t('performance.tabs.employees') }}
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
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-6">
                    <div
                        v-for="card in clientKpis"
                        :key="card.label"
                        class="rounded-sm border border-stone-200 bg-white p-4 text-xs text-stone-500 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-400"
                    >
                        <p class="uppercase">{{ card.label }}</p>
                        <p class="mt-1 text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ card.value }}</p>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
                    <Card class="lg:col-span-2">
                        <template #title>{{ t('performance.clients.top_customers') }}</template>
                        <div v-if="!clientPeriod.top_customers?.length" class="text-sm text-stone-500 dark:text-neutral-400">
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
                                        {{ t('performance.clients.line', {
                                            revenue: formatCurrency(customer.revenue),
                                            orders: formatNumber(customer.orders),
                                            items: formatNumber(customer.items),
                                        }) }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </Card>

                    <div class="space-y-3">
                        <div class="rounded-sm border border-amber-200 bg-amber-50/60 p-4 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
                            <p class="text-xs uppercase tracking-wide text-amber-700 dark:text-amber-200">
                                {{ t('performance.clients.customer_of_year') }}
                            </p>
                            <div v-if="customerOfYear" class="mt-3 flex items-center gap-3">
                                <div class="h-14 w-14 overflow-hidden rounded-full border border-amber-200 bg-white dark:border-amber-500/40 dark:bg-neutral-900">
                                    <img
                                        v-if="customerOfYear.logo_url"
                                        :src="customerOfYear.logo_url"
                                        :alt="customerDisplayName(customerOfYear)"
                                        class="h-full w-full object-cover"
                                        loading="lazy"
                                        decoding="async"
                                    />
                                    <div v-else class="flex h-full w-full items-center justify-center text-sm font-semibold text-amber-700 dark:text-amber-200">
                                        {{ initials(customerDisplayName(customerOfYear)) }}
                                    </div>
                                </div>
                                <div>
                                    <p class="text-sm font-semibold">{{ customerDisplayName(customerOfYear) }}</p>
                                    <p class="text-xs text-amber-700/80 dark:text-amber-200/80">
                                        {{ t('performance.clients.line', {
                                            revenue: formatCurrency(customerOfYear.revenue),
                                            orders: formatNumber(customerOfYear.orders),
                                            items: formatNumber(customerOfYear.items),
                                        }) }}
                                    </p>
                                </div>
                            </div>
                            <p v-else class="mt-3 text-sm text-amber-700/80 dark:text-amber-200/80">
                                {{ t('performance.clients.no_customer_year') }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <div v-else class="space-y-4">
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-6">
                    <div
                        v-for="card in employeeKpis"
                        :key="card.label"
                        class="rounded-sm border border-stone-200 bg-white p-4 text-xs text-stone-500 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-400"
                    >
                        <p class="uppercase">{{ card.label }}</p>
                        <p class="mt-1 text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ card.value }}</p>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
                    <div class="lg:col-span-2 space-y-4">
                        <Card>
                            <template #title>{{ t('performance.employees.top_sellers') }}</template>
                            <div v-if="!employeePeriod.top_sellers?.length" class="text-sm text-stone-500 dark:text-neutral-400">
                                {{ t('performance.employees.no_sellers') }}
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
                                                v-if="seller.type === 'online'"
                                                class="rounded-full bg-sky-100 px-2 py-0.5 text-[10px] font-semibold text-sky-700 dark:bg-sky-500/10 dark:text-sky-200"
                                            >
                                                {{ t('performance.employees.online_badge') }}
                                            </span>
                                        </div>
                                        <p class="text-xs text-stone-500 dark:text-neutral-400">
                                            {{ t('performance.employees.seller_line', {
                                                revenue: formatCurrency(seller.revenue),
                                                orders: formatNumber(seller.orders),
                                                items: formatNumber(seller.items),
                                            }) }}
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
                            <template #title>{{ t('performance.employees.top_products') }}</template>
                            <div v-if="!employeePeriod.top_products?.length" class="text-sm text-stone-500 dark:text-neutral-400">
                                {{ t('performance.employees.no_products') }}
                            </div>
                            <div v-else class="grid grid-cols-1 gap-3 md:grid-cols-2">
                                <div
                                    v-for="product in employeePeriod.top_products"
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
                                            {{ t('performance.employees.product_line', {
                                                revenue: formatCurrency(product.revenue),
                                                quantity: formatNumber(product.quantity),
                                            }) }}
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </Card>
                    </div>

                    <div class="space-y-3">
                        <div class="rounded-sm border border-emerald-200 bg-emerald-50/60 p-4 text-sm text-emerald-900 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200">
                            <p class="text-xs uppercase tracking-wide text-emerald-700 dark:text-emerald-200">
                                {{ t('performance.employees.seller_of_year') }}
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
                                        {{ initials(sellerDisplayName(sellerOfYear)) }}
                                    </div>
                                </div>
                                <div>
                                    <p class="text-sm font-semibold">{{ sellerDisplayName(sellerOfYear) }}</p>
                                    <p class="text-xs text-emerald-700/80 dark:text-emerald-200/80">
                                        {{ t('performance.employees.seller_line', {
                                            revenue: formatCurrency(sellerOfYear.revenue),
                                            orders: formatNumber(sellerOfYear.orders),
                                            items: formatNumber(sellerOfYear.items),
                                        }) }}
                                    </p>
                                </div>
                            </div>
                            <p v-else class="mt-3 text-sm text-emerald-700/80 dark:text-emerald-200/80">
                                {{ t('performance.employees.no_seller_year') }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
