<script setup>
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Card from '@/Components/UI/Card.vue';

const props = defineProps({
    employee: {
        type: Object,
        default: () => ({}),
    },
    performance: {
        type: Object,
        default: () => ({ periods: {} }),
    },
});

const { t } = useI18n();

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

const emptyPeriod = {
    range: { start: '', end: '' },
    orders: 0,
    revenue: 0,
    avg_order: 0,
    items_sold: 0,
    customers: 0,
    top_products: [],
    top_customers: [],
};

const periodStats = computed(() => props.performance?.periods?.[activePeriod.value] || emptyPeriod);

const roleLabel = computed(() => {
    if (props.employee?.role === 'owner') {
        return t('performance.employee.role_owner');
    }
    return String(props.employee?.role || '').replace(/_/g, ' ');
});

const statusLabel = computed(() =>
    props.employee?.is_active ? t('performance.employee.status_active') : t('performance.employee.status_inactive'),
);

const initials = (label) => {
    const clean = String(label || '').trim();
    if (!clean) {
        return '--';
    }
    const parts = clean.split(/\s+/).slice(0, 2);
    return parts.map((part) => part[0]?.toUpperCase()).join('');
};

const kpiCards = computed(() => ([
    { label: t('performance.kpi.revenue'), value: formatCurrency(periodStats.value.revenue) },
    { label: t('performance.kpi.orders'), value: formatNumber(periodStats.value.orders) },
    { label: t('performance.kpi.items_sold'), value: formatNumber(periodStats.value.items_sold) },
    { label: t('performance.kpi.avg_order'), value: formatCurrency(periodStats.value.avg_order) },
    { label: t('performance.kpi.customers'), value: formatNumber(periodStats.value.customers) },
]));

const rangeLabel = computed(() => {
    const range = periodStats.value.range;
    if (!range?.start) {
        return '';
    }
    return t('performance.range', { start: range.start, end: range.end });
});

const customerDisplayName = (customer) => customer?.name || t('performance.clients.customer_fallback');
</script>

<template>
    <AuthenticatedLayout>
        <Head :title="t('performance.employee.title')" />

        <div class="space-y-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-3">
                    <div class="h-14 w-14 overflow-hidden rounded-full border border-stone-200 bg-stone-100 dark:border-neutral-700 dark:bg-neutral-800">
                        <img
                            v-if="employee.profile_picture_url"
                            :src="employee.profile_picture_url"
                            :alt="employee.name"
                            class="h-full w-full object-cover"
                            loading="lazy"
                            decoding="async"
                        />
                        <div v-else class="flex h-full w-full items-center justify-center text-sm font-semibold text-stone-600 dark:text-neutral-300">
                            {{ initials(employee.name) }}
                        </div>
                    </div>
                    <div>
                        <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">
                            {{ employee.name }}
                        </h1>
                        <div class="flex flex-wrap items-center gap-2 text-xs text-stone-500 dark:text-neutral-400">
                            <span
                                v-if="roleLabel"
                                class="rounded-full bg-stone-100 px-2 py-0.5 font-semibold text-stone-600 dark:bg-neutral-800 dark:text-neutral-300"
                            >
                                {{ roleLabel }}
                            </span>
                            <span
                                class="rounded-full px-2 py-0.5 font-semibold"
                                :class="employee.is_active
                                    ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-200'
                                    : 'bg-stone-100 text-stone-600 dark:bg-neutral-700 dark:text-neutral-300'"
                            >
                                {{ statusLabel }}
                            </span>
                            <span v-if="employee.title">{{ employee.title }}</span>
                            <span v-if="employee.email">{{ employee.email }}</span>
                            <span v-if="employee.phone">{{ employee.phone }}</span>
                        </div>
                    </div>
                </div>
                <Link
                    :href="route('performance.index', { tab: 'employees' })"
                    class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                >
                    {{ t('performance.employee.back') }}
                </Link>
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
            </div>

            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-5">
                <div
                    v-for="card in kpiCards"
                    :key="card.label"
                    class="rounded-sm border border-stone-200 bg-white p-4 text-xs text-stone-500 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-400"
                >
                    <p class="uppercase">{{ card.label }}</p>
                    <p class="mt-1 text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ card.value }}</p>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                <Card>
                    <template #title>{{ t('performance.employee.top_products') }}</template>
                    <div v-if="!periodStats.top_products?.length" class="text-sm text-stone-500 dark:text-neutral-400">
                        {{ t('performance.employee.no_products') }}
                    </div>
                    <div v-else class="space-y-3">
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
                                    {{ t('performance.employee.product_line', {
                                        revenue: formatCurrency(product.revenue),
                                        quantity: formatNumber(product.quantity),
                                    }) }}
                                </p>
                            </div>
                        </div>
                    </div>
                </Card>

                <Card>
                    <template #title>{{ t('performance.employee.top_customers') }}</template>
                    <div v-if="!periodStats.top_customers?.length" class="text-sm text-stone-500 dark:text-neutral-400">
                        {{ t('performance.employee.no_customers') }}
                    </div>
                    <div v-else class="space-y-3">
                        <div
                            v-for="customer in periodStats.top_customers"
                            :key="customer.id"
                            class="flex items-center gap-3 rounded-sm border border-stone-200 bg-white p-3 text-sm dark:border-neutral-700 dark:bg-neutral-900"
                        >
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
                                    {{ t('performance.employee.customer_line', {
                                        revenue: formatCurrency(customer.revenue),
                                        orders: formatNumber(customer.orders),
                                        items: formatNumber(customer.items),
                                    }) }}
                                </p>
                            </div>
                        </div>
                    </div>
                </Card>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
