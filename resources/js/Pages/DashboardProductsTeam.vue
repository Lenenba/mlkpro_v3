<script setup>
import { computed, onMounted, ref } from 'vue';
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
});

const { t } = useI18n();

const isHydrating = ref(true);

onMounted(() => {
    setTimeout(() => {
        isHydrating.value = false;
    }, 450);
});

const formatCurrency = (value) =>
    `$${Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

const formatNumber = (value) =>
    Number(value || 0).toLocaleString(undefined, { maximumFractionDigits: 0 });

const kpiCards = computed(() => ([
    {
        label: t('dashboard_products.team.kpi.sales_today'),
        value: formatNumber(props.stats.sales_today),
        icon: 'bag',
        tone: 'emerald',
    },
    {
        label: t('dashboard_products.team.kpi.revenue_today'),
        value: formatCurrency(props.stats.revenue_today),
        icon: 'cash',
        tone: 'sky',
    },
    {
        label: t('dashboard_products.team.kpi.low_stock'),
        value: formatNumber(props.stats.low_stock),
        icon: 'alert',
        tone: 'amber',
    },
    {
        label: t('dashboard_products.team.kpi.out_of_stock'),
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

const kpiIconStyles = {
    emerald: 'bg-emerald-500/90 text-white shadow-emerald-500/30',
    sky: 'bg-sky-500/90 text-white shadow-sky-500/30',
    amber: 'bg-amber-500/90 text-white shadow-amber-500/30',
    red: 'bg-red-500/90 text-white shadow-red-500/30',
};

const skeletonKpis = Array.from({ length: 4 }, (_, index) => index);
const skeletonRows = Array.from({ length: 4 }, (_, index) => index);
</script>

<template>
    <AuthenticatedLayout>
        <Head :title="$t('dashboard_products.team.page_title')" />

        <div class="space-y-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">
                        {{ $t('dashboard_products.team.title') }}
                    </h1>
                    <p class="text-sm text-stone-500 dark:text-neutral-400">
                        {{ $t('dashboard_products.team.subtitle') }}
                    </p>
                </div>
                <Link
                    :href="route('sales.create')"
                    class="rounded-sm bg-green-600 px-3 py-2 text-xs font-semibold text-white hover:bg-green-700"
                >
                    {{ $t('dashboard_products.team.actions.new_sale') }}
                </Link>
            </div>

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <div
                    v-if="isHydrating"
                    v-for="index in skeletonKpis"
                    :key="`team-kpi-skeleton-${index}`"
                    class="rise-in rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900"
                    :style="{ animationDelay: `${index * 80}ms` }"
                >
                    <div class="flex items-center justify-between gap-3 animate-pulse">
                        <div class="space-y-2">
                            <div class="h-3 w-20 rounded-full bg-stone-200 dark:bg-neutral-700"></div>
                            <div class="h-5 w-24 rounded-full bg-stone-200 dark:bg-neutral-700"></div>
                        </div>
                        <div class="h-9 w-9 rounded-full bg-stone-200 dark:bg-neutral-700"></div>
                    </div>
                </div>
                <div
                    v-else
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
                        <span
                            class="flex h-9 w-9 items-center justify-center rounded-full shadow-lg ring-1 ring-white/20 animate-[pulse_3s_ease-in-out_infinite]"
                            :class="kpiIconStyles[card.tone] || 'bg-stone-600/90 text-white shadow-stone-500/30'"
                        >
                            <svg v-if="card.icon === 'bag'" xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="m6 2 1.5 6h9L18 2" />
                                <path d="M4 8h16l-1 12H5z" />
                                <path d="M9 12h6" />
                            </svg>
                            <svg v-else-if="card.icon === 'cash'" xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="2" y="5" width="20" height="14" rx="2" />
                                <path d="M16 10a4 4 0 1 1-8 0 4 4 0 0 1 8 0Z" />
                            </svg>
                            <svg v-else-if="card.icon === 'alert'" xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="m21.73 18-8-14a2 2 0 0 0-3.46 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z" />
                                <path d="M12 9v4" />
                                <path d="M12 17h.01" />
                            </svg>
                            <svg v-else xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M12 9v4" />
                                <path d="M12 17h.01" />
                                <path d="m21.73 18-8-14a2 2 0 0 0-3.46 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z" />
                            </svg>
                        </span>
                    </div>
                </div>
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
