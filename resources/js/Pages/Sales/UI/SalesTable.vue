<script setup>
import { computed, ref, watch } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AdminDataTable from '@/Components/DataTable/AdminDataTable.vue';
import AdminDataTableActions from '@/Components/DataTable/AdminDataTableActions.vue';
import AdminDataTableToolbar from '@/Components/DataTable/AdminDataTableToolbar.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import DatePicker from '@/Components/DatePicker.vue';
import { humanizeDate } from '@/utils/date';
import { resolveDataTablePerPage } from '@/Components/DataTable/pagination';
import { useCurrencyFormatter } from '@/utils/currency';

const props = defineProps({
    sales: {
        type: Object,
        required: true,
    },
    filters: {
        type: Object,
        default: () => ({}),
    },
    customers: {
        type: Array,
        default: () => [],
    },
    routeName: {
        type: String,
        default: 'sales.index',
    },
    statusOptions: {
        type: Array,
        default: () => [],
    },
    showFulfillmentStatus: {
        type: Boolean,
        default: false,
    },
    enableStatusUpdate: {
        type: Boolean,
        default: false,
    },
});

const { t } = useI18n();

const filterForm = useForm({
    search: props.filters?.search ?? '',
    status: props.filters?.status ?? '',
    customer_id: props.filters?.customer_id ?? '',
    total_min: props.filters?.total_min ?? '',
    total_max: props.filters?.total_max ?? '',
    created_from: props.filters?.created_from ?? '',
    created_to: props.filters?.created_to ?? '',
    sort: props.filters?.sort ?? 'created_at',
    direction: props.filters?.direction ?? 'desc',
});

const showAdvanced = ref(false);
const isLoading = ref(false);
const customerOptions = computed(() => ([
    { value: '', label: t('sales.table.filters.all_customers') },
    ...(props.customers || []).map((customer) => ({
        value: String(customer.id),
        label: customer.company_name || `${customer.first_name} ${customer.last_name}`,
    })),
]));

const defaultStatusOptions = computed(() => [
    { value: '', label: t('sales.table.filters.all_statuses') },
    { value: 'draft', label: t('sales.status.draft') },
    { value: 'pending', label: t('sales.status.pending') },
    { value: 'paid', label: t('sales.status.paid') },
    { value: 'canceled', label: t('sales.status.canceled') },
]);
const statusOptions = computed(() =>
    props.statusOptions.length ? props.statusOptions : defaultStatusOptions.value
);

const statusLabels = computed(() => ({
    draft: t('sales.status.draft'),
    pending: t('sales.status.pending'),
    paid: t('sales.status.paid'),
    canceled: t('sales.status.canceled'),
}));

const paymentStatusLabels = computed(() => ({
    unpaid: t('sales.payment.unpaid'),
    deposit_required: t('sales.payment.deposit_required'),
    partial: t('sales.payment.partial'),
    paid: t('sales.payment.paid'),
    canceled: t('sales.status.canceled'),
    pending: t('sales.status.pending'),
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
    pending: t('sales.fulfillment.pending'),
    preparing: t('sales.fulfillment.preparing'),
    out_for_delivery: t('sales.fulfillment.out_for_delivery'),
    ready_for_pickup: t('sales.fulfillment.ready_for_pickup'),
    completed: t('sales.fulfillment.completed'),
    confirmed: t('sales.fulfillment.confirmed'),
}));

const fulfillmentClasses = {
    pending: 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-200',
    preparing: 'bg-sky-100 text-sky-700 dark:bg-sky-500/10 dark:text-sky-200',
    out_for_delivery: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-200',
    ready_for_pickup: 'bg-indigo-100 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-200',
    completed: 'bg-stone-100 text-stone-600 dark:bg-neutral-800 dark:text-neutral-300',
    confirmed: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-200',
};

const filterPayload = () => {
    const payload = {
        search: filterForm.search,
        status: filterForm.status,
        customer_id: filterForm.customer_id,
        total_min: filterForm.total_min,
        total_max: filterForm.total_max,
        created_from: filterForm.created_from,
        created_to: filterForm.created_to,
        sort: filterForm.sort,
        direction: filterForm.direction,
        per_page: currentPerPage.value,
    };

    Object.keys(payload).forEach((key) => {
        const value = payload[key];
        if (value === '' || value === null || value === undefined) {
            delete payload[key];
        }
    });

    return payload;
};

const partialReloadKeys = () => (
    props.routeName === 'orders.index'
        ? ['orders', 'filters', 'stats']
        : ['sales', 'filters', 'stats']
);

let filterTimeout;
const autoFilter = () => {
    if (filterTimeout) {
        clearTimeout(filterTimeout);
    }
    filterTimeout = setTimeout(() => {
        isLoading.value = true;
        router.get(route(props.routeName), filterPayload(), {
            only: partialReloadKeys(),
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onFinish: () => {
                isLoading.value = false;
            },
        });
    }, 300);
};

watch(() => filterForm.search, () => {
    autoFilter();
});

watch(() => [
    filterForm.status,
    filterForm.customer_id,
    filterForm.total_min,
    filterForm.total_max,
    filterForm.created_from,
    filterForm.created_to,
    filterForm.sort,
    filterForm.direction,
], () => {
    autoFilter();
});

const clearFilters = () => {
    filterForm.search = '';
    filterForm.status = '';
    filterForm.customer_id = '';
    filterForm.total_min = '';
    filterForm.total_max = '';
    filterForm.created_from = '';
    filterForm.created_to = '';
    filterForm.sort = 'created_at';
    filterForm.direction = 'desc';
    autoFilter();
};

const toggleSort = (column) => {
    if (filterForm.sort === column) {
        filterForm.direction = filterForm.direction === 'asc' ? 'desc' : 'asc';
        return;
    }
    filterForm.sort = column;
    filterForm.direction = 'asc';
};

const formatDate = (value) => humanizeDate(value);
const { formatCurrency } = useCurrencyFormatter();

const customerLabel = (sale) => {
    const customer = sale?.customer;
    if (customer?.company_name) {
        return customer.company_name;
    }
    const name = [customer?.first_name, customer?.last_name].filter(Boolean).join(' ');
    return name || t('sales.labels.customer_anonymous');
};

const canEdit = (sale) => ['draft', 'pending'].includes(sale?.status);

const statusLabel = (sale) => {
    if (!sale) {
        return '';
    }
    if (sale.status === 'paid' || sale.status === 'canceled') {
        return statusLabels.value[sale.status] || sale.status;
    }
    if (sale.fulfillment_status) {
        return fulfillmentLabels.value[sale.fulfillment_status] || sale.fulfillment_status;
    }
    return statusLabels.value[sale.status] || sale.status;
};

const statusBadgeClass = (sale) => {
    if (!sale) {
        return statusClasses.draft;
    }
    if (sale.status === 'paid' || sale.status === 'canceled') {
        return statusClasses[sale.status] || statusClasses.draft;
    }
    if (sale.fulfillment_status) {
        return fulfillmentClasses[sale.fulfillment_status] || statusClasses.pending;
    }
    return statusClasses[sale.status] || statusClasses.draft;
};

const paymentLabel = (sale) => {
    const key = sale?.payment_status || sale?.status || '';
    return paymentStatusLabels.value[key] || key;
};

const paymentBadgeClass = (sale) => {
    const key = sale?.payment_status || sale?.status || '';
    return paymentStatusClasses[key] || statusClasses.draft;
};

const canQuickUpdate = (sale) =>
    props.enableStatusUpdate
    && sale
    && !['paid', 'canceled'].includes(sale.status);

const canChangeFulfillment = (sale) =>
    canQuickUpdate(sale)
    && !['completed', 'confirmed'].includes(sale.fulfillment_status);

const fulfillmentOptionsFor = (sale) => {
    if (sale?.fulfillment_method === 'pickup') {
        return [
            { value: 'pending', label: t('sales.fulfillment.pending') },
            { value: 'preparing', label: t('sales.fulfillment.preparing') },
            { value: 'ready_for_pickup', label: t('sales.fulfillment.ready_for_pickup') },
            { value: 'completed', label: t('sales.fulfillment.completed') },
        ];
    }
    if (sale?.fulfillment_method === 'delivery') {
        return [
            { value: 'pending', label: t('sales.fulfillment.pending') },
            { value: 'preparing', label: t('sales.fulfillment.preparing') },
            { value: 'out_for_delivery', label: t('sales.fulfillment.out_for_delivery') },
            { value: 'completed', label: t('sales.fulfillment.completed') },
        ];
    }
    return [
        { value: 'pending', label: t('sales.fulfillment.pending') },
        { value: 'preparing', label: t('sales.fulfillment.preparing') },
        { value: 'out_for_delivery', label: t('sales.fulfillment.out_for_delivery') },
        { value: 'ready_for_pickup', label: t('sales.fulfillment.ready_for_pickup') },
        { value: 'completed', label: t('sales.fulfillment.completed') },
    ];
};

const updating = ref({});

const isUpdating = (sale) => Boolean(updating.value[sale?.id]);

const refreshTable = () => {
    isLoading.value = true;
    router.get(route(props.routeName), filterPayload(), {
        only: partialReloadKeys(),
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onFinish: () => {
            isLoading.value = false;
        },
    });
};

const updateStatus = (sale, payload) => {
    if (!sale?.id) {
        return;
    }
    updating.value = { ...updating.value, [sale.id]: true };
    router.patch(route('sales.status.update', sale.id), payload, {
        preserveScroll: true,
        onSuccess: () => refreshTable(),
        onFinish: () => {
            updating.value = { ...updating.value, [sale.id]: false };
        },
    });
};

const emptyState = computed(() => {
    if (props.routeName === 'orders.index') {
        return {
            title: t('sales.table.empty.orders.title'),
            description: t('sales.table.empty.orders.description'),
            cta: null,
        };
    }
    return {
        title: t('sales.table.empty.sales.title'),
        description: t('sales.table.empty.sales.description'),
        cta: {
            label: t('sales.index.new_sale'),
            routeName: 'sales.create',
        },
    };
});

const updateFulfillment = (sale, value) => {
    const nextValue = value || null;
    if ((sale?.fulfillment_status || null) === nextValue) {
        return;
    }
    updateStatus(sale, { fulfillment_status: nextValue });
};

const markPaid = (sale) => {
    if (!sale || sale.status === 'paid') {
        return;
    }
    updateStatus(sale, { status: 'paid' });
};

const markCanceled = (sale) => {
    if (!sale || sale.status === 'canceled') {
        return;
    }
    updateStatus(sale, { status: 'canceled' });
};

const canMarkPaid = (sale) =>
    sale
    && !['paid', 'canceled'].includes(sale.status)
    && (!sale.fulfillment_method || ['completed', 'confirmed'].includes(sale.fulfillment_status));

const canMarkCanceled = (sale) =>
    sale
    && !['paid', 'canceled'].includes(sale.status)
    && !['completed', 'confirmed'].includes(sale.fulfillment_status);

const salesRows = computed(() => (Array.isArray(props.sales?.data) ? props.sales.data : []));
const salesTableRows = computed(() => (isLoading.value
    ? Array.from({ length: 6 }, (_, index) => ({ id: `sale-skeleton-${index}`, __skeleton: true }))
    : salesRows.value));
const salesLinks = computed(() => props.sales?.links || []);
const currentPerPage = computed(() => resolveDataTablePerPage(props.sales?.per_page, props.filters?.per_page));
const salesResultsLabel = computed(() => `${props.sales?.total ?? props.sales?.data?.length ?? 0} ${t('sales.table.pagination.results')}`);
</script>

<template>
    <div
        class="p-5 space-y-4 flex flex-col border-t-4 border-t-zinc-600 bg-white border border-stone-200 shadow-sm rounded-sm dark:bg-neutral-800 dark:border-neutral-700"
    >
        <AdminDataTableToolbar
            :show-filters="showAdvanced"
            :show-apply="false"
            :busy="isLoading"
            :filters-label="$t('sales.table.filters.toggle')"
            :clear-label="$t('sales.table.filters.reset')"
            @toggle-filters="showAdvanced = !showAdvanced"
            @apply="autoFilter"
            @clear="clearFilters"
        >
            <template #search>
                <div class="relative">
                    <div class="absolute inset-y-0 start-0 flex items-center pointer-events-none z-20 ps-3.5">
                        <svg class="shrink-0 size-4 text-stone-500 dark:text-neutral-400"
                            xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="11" cy="11" r="8" />
                            <path d="m21 21-4.3-4.3" />
                        </svg>
                    </div>
                    <input
                        type="text"
                        v-model="filterForm.search"
                        class="py-[7px] ps-10 pe-8 block w-full bg-white border border-stone-200 rounded-sm text-sm placeholder:text-stone-500 focus:border-green-500 focus:ring-green-600 disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200 dark:placeholder:text-neutral-400 dark:focus:ring-neutral-600"
                        :placeholder="$t('sales.table.filters.search_placeholder')"
                    >
                </div>
            </template>

            <template #filters>
                <FloatingSelect
                    v-model="filterForm.status"
                    :label="$t('sales.table.headings.status')"
                    :options="statusOptions"
                    dense
                />
                <FloatingSelect
                    v-model="filterForm.customer_id"
                    :label="$t('sales.table.headings.customer')"
                    :options="customerOptions"
                    dense
                />
                <input type="number" v-model="filterForm.total_min" min="0" step="0.01"
                    class="py-2 px-3 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                    :placeholder="$t('sales.table.filters.total_min')">
                <input type="number" v-model="filterForm.total_max" min="0" step="0.01"
                    class="py-2 px-3 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                    :placeholder="$t('sales.table.filters.total_max')">
                <DatePicker v-model="filterForm.created_from" :label="$t('sales.table.filters.date_from')" />
                <DatePicker v-model="filterForm.created_to" :label="$t('sales.table.filters.date_to')" />
            </template>
        </AdminDataTableToolbar>

        <AdminDataTable
            embedded
            :rows="salesTableRows"
            :links="salesLinks"
            :show-pagination="salesRows.length > 0"
            show-per-page
            :per-page="currentPerPage"
        >
            <template #empty>
                <div class="px-4 py-8 text-center text-stone-600 dark:text-neutral-300">
                    <div class="space-y-2">
                        <div class="text-sm font-semibold text-stone-700 dark:text-neutral-200">
                            {{ emptyState.title }}
                        </div>
                        <div class="text-xs text-stone-500 dark:text-neutral-400">
                            {{ emptyState.description }}
                        </div>
                        <Link
                            v-if="emptyState.cta"
                            :href="route(emptyState.cta.routeName)"
                            class="inline-flex items-center justify-center rounded-sm border border-green-600 bg-green-600 px-3 py-2 text-xs font-semibold text-white hover:bg-green-700"
                        >
                            {{ emptyState.cta.label }}
                        </Link>
                    </div>
                </div>
            </template>

            <template #head>
                <tr>
                    <th scope="col" class="min-w-[200px]">
                        <button type="button" @click="toggleSort('number')"
                            class="px-5 py-2.5 text-start w-full flex items-center gap-x-1 text-sm font-normal text-stone-500 hover:text-stone-700 focus:outline-none dark:text-neutral-500 dark:hover:text-neutral-300">
                            {{ $t('sales.table.headings.sale') }}
                            <svg v-if="filterForm.sort === 'number'" class="size-3" xmlns="http://www.w3.org/2000/svg"
                                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                stroke-linecap="round" stroke-linejoin="round"
                                :class="filterForm.direction === 'asc' ? 'rotate-180' : ''">
                                <path d="m6 9 6 6 6-6" />
                            </svg>
                        </button>
                    </th>
                    <th scope="col" class="min-w-40">
                        <div class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                            {{ $t('sales.table.headings.customer') }}
                        </div>
                    </th>
                    <th scope="col" class="min-w-32">
                        <button type="button" @click="toggleSort('status')"
                            class="px-5 py-2.5 text-start w-full flex items-center gap-x-1 text-sm font-normal text-stone-500 hover:text-stone-700 focus:outline-none dark:text-neutral-500 dark:hover:text-neutral-300">
                            {{ $t('sales.table.headings.status') }}
                            <svg v-if="filterForm.sort === 'status'" class="size-3" xmlns="http://www.w3.org/2000/svg"
                                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                stroke-linecap="round" stroke-linejoin="round"
                                :class="filterForm.direction === 'asc' ? 'rotate-180' : ''">
                                <path d="m6 9 6 6 6-6" />
                            </svg>
                        </button>
                    </th>
                    <th scope="col" class="min-w-32">
                        <div class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                            {{ $t('sales.table.headings.payment') }}
                        </div>
                    </th>
                    <th scope="col" class="min-w-28">
                        <div class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                            {{ $t('sales.table.headings.items') }}
                        </div>
                    </th>
                    <th scope="col" class="min-w-32">
                        <button type="button" @click="toggleSort('total')"
                            class="px-5 py-2.5 text-start w-full flex items-center gap-x-1 text-sm font-normal text-stone-500 hover:text-stone-700 focus:outline-none dark:text-neutral-500 dark:hover:text-neutral-300">
                            {{ $t('sales.table.headings.total') }}
                            <svg v-if="filterForm.sort === 'total'" class="size-3" xmlns="http://www.w3.org/2000/svg"
                                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                stroke-linecap="round" stroke-linejoin="round"
                                :class="filterForm.direction === 'asc' ? 'rotate-180' : ''">
                                <path d="m6 9 6 6 6-6" />
                            </svg>
                        </button>
                    </th>
                    <th scope="col" class="min-w-32">
                        <button type="button" @click="toggleSort('created_at')"
                            class="px-5 py-2.5 text-start w-full flex items-center gap-x-1 text-sm font-normal text-stone-500 hover:text-stone-700 focus:outline-none dark:text-neutral-500 dark:hover:text-neutral-300">
                            {{ $t('sales.table.headings.date') }}
                            <svg v-if="filterForm.sort === 'created_at'" class="size-3" xmlns="http://www.w3.org/2000/svg"
                                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                stroke-linecap="round" stroke-linejoin="round"
                                :class="filterForm.direction === 'asc' ? 'rotate-180' : ''">
                                <path d="m6 9 6 6 6-6" />
                            </svg>
                        </button>
                    </th>
                    <th scope="col"></th>
                </tr>
            </template>

            <template #body="{ rows }">
                <tbody class="divide-y divide-stone-200 dark:divide-neutral-700">
                    <tr v-for="sale in rows" :key="sale.id">
                        <template v-if="sale.__skeleton">
                            <td colspan="8" class="px-4 py-3">
                                <div class="grid grid-cols-7 gap-4 animate-pulse">
                                    <div class="h-3 w-32 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                                    <div class="h-3 w-28 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                                    <div class="h-3 w-24 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                                    <div class="h-3 w-20 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                                    <div class="h-3 w-16 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                                    <div class="h-3 w-20 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                                    <div class="h-3 w-24 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                                </div>
                            </td>
                        </template>
                        <template v-else>
                            <td class="size-px whitespace-nowrap px-4 py-2 text-start">
                                <Link :href="route('sales.show', sale.id)" class="flex flex-col hover:underline">
                                    <span class="text-sm text-stone-600 dark:text-neutral-300">
                                        {{ sale.number || $t('sales.table.sale_label', { id: sale.id }) }}
                                    </span>
                                    <span class="text-xs text-stone-500 dark:text-neutral-500">
                                        {{ $t('sales.table.items_count', { count: sale.items_count || 0 }) }}
                                    </span>
                                </Link>
                            </td>
                            <td class="size-px whitespace-nowrap px-4 py-2">
                                <span class="text-sm text-stone-600 dark:text-neutral-300">
                                    {{ customerLabel(sale) }}
                                </span>
                            </td>
                            <td class="size-px whitespace-nowrap px-4 py-2">
                                <div class="space-y-1">
                                    <span
                                        class="py-1.5 px-2 inline-flex items-center gap-x-1.5 text-xs font-medium rounded-full"
                                        :class="statusBadgeClass(sale)"
                                    >
                                        {{ statusLabel(sale) }}
                                    </span>
                                    <div
                                        v-if="showFulfillmentStatus && sale.status !== 'paid' && sale.status !== 'canceled'"
                                        class="text-[10px] text-stone-500 dark:text-neutral-400"
                                    >
                                        {{ $t('sales.table.payment_label', { status: paymentLabel(sale) }) }}
                                    </div>
                                    <div v-if="canQuickUpdate(sale)" class="mt-2 space-y-1">
                                        <FloatingSelect
                                            :model-value="sale.fulfillment_status || ''"
                                            :label="$t('sales.table.fulfillment_placeholder')"
                                            :options="fulfillmentOptionsFor(sale)"
                                            :placeholder="$t('sales.table.fulfillment_placeholder')"
                                            :disabled="isUpdating(sale) || !canChangeFulfillment(sale)"
                                            dense
                                            class="text-[11px]"
                                            @update:modelValue="(value) => updateFulfillment(sale, value)"
                                        />
                                        <div class="flex flex-wrap items-center gap-1">
                                            <button
                                                type="button"
                                                class="rounded-sm border border-emerald-200 bg-emerald-50 px-2 py-1 text-[11px] font-semibold text-emerald-700 hover:bg-emerald-100 disabled:opacity-60"
                                                :disabled="isUpdating(sale) || !canMarkPaid(sale)"
                                                @click="markPaid(sale)"
                                            >
                                                {{ $t('sales.table.actions.mark_paid') }}
                                            </button>
                                            <button
                                                type="button"
                                                class="rounded-sm border border-red-200 bg-red-50 px-2 py-1 text-[11px] font-semibold text-red-600 hover:bg-red-100 disabled:opacity-60"
                                                :disabled="isUpdating(sale) || !canMarkCanceled(sale)"
                                                @click="markCanceled(sale)"
                                            >
                                                {{ $t('sales.table.actions.cancel') }}
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td class="size-px whitespace-nowrap px-4 py-2">
                                <span
                                    class="py-1.5 px-2 inline-flex items-center gap-x-1.5 text-xs font-medium rounded-full"
                                    :class="paymentBadgeClass(sale)"
                                >
                                    {{ paymentLabel(sale) }}
                                </span>
                            </td>
                            <td class="size-px whitespace-nowrap px-4 py-2">
                                <span class="text-sm text-stone-600 dark:text-neutral-300">
                                    {{ sale.items_count || 0 }}
                                </span>
                            </td>
                            <td class="size-px whitespace-nowrap px-4 py-2">
                                <span class="text-sm text-stone-600 dark:text-neutral-300">
                                    {{ formatCurrency(sale.total) }}
                                </span>
                            </td>
                            <td class="size-px whitespace-nowrap px-4 py-2">
                                <span class="text-xs text-stone-500 dark:text-neutral-500">
                                    {{ formatDate(sale.created_at) }}
                                </span>
                            </td>
                            <td class="size-px whitespace-nowrap px-4 py-2 text-end">
                                <AdminDataTableActions :label="$t('sales.table.actions.view')">
                                    <Link :href="route('sales.show', sale.id)"
                                        class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800">
                                        {{ $t('sales.table.actions.view') }}
                                    </Link>
                                    <Link v-if="canEdit(sale)" :href="route('sales.edit', sale.id)"
                                        class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800">
                                        {{ $t('sales.table.actions.edit') }}
                                    </Link>
                                </AdminDataTableActions>
                            </td>
                        </template>
                    </tr>
                </tbody>
            </template>

            <template #pagination_prefix>
                <p class="text-sm text-stone-800 dark:text-neutral-200">
                    {{ salesResultsLabel }}
                </p>
            </template>
        </AdminDataTable>
    </div>
</template>
