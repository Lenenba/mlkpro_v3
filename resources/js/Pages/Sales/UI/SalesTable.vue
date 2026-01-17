<script setup>
import { computed, ref, watch } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { humanizeDate } from '@/utils/date';

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

const statusClasses = {
    draft: 'bg-stone-100 text-stone-600 dark:bg-neutral-800 dark:text-neutral-300',
    pending: 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-200',
    paid: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-200',
    canceled: 'bg-red-100 text-red-700 dark:bg-red-500/10 dark:text-red-200',
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
    };

    Object.keys(payload).forEach((key) => {
        const value = payload[key];
        if (value === '' || value === null || value === undefined) {
            delete payload[key];
        }
    });

    return payload;
};

let filterTimeout;
const autoFilter = () => {
    if (filterTimeout) {
        clearTimeout(filterTimeout);
    }
    filterTimeout = setTimeout(() => {
        isLoading.value = true;
        router.get(route(props.routeName), filterPayload(), {
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
const formatCurrency = (value) =>
    `$${Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

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

const paymentLabel = (sale) => statusLabels.value[sale?.status] || sale?.status || '';

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
</script>

<template>
    <div
        class="p-5 space-y-4 flex flex-col border-t-4 border-t-zinc-600 bg-white border border-stone-200 shadow-sm rounded-sm dark:bg-neutral-800 dark:border-neutral-700"
    >
        <div class="space-y-3">
            <div class="flex flex-col lg:flex-row lg:items-center gap-2">
                <div class="flex-1">
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
                </div>

                <div class="flex flex-wrap items-center gap-2 justify-end">
                    <button type="button" @click="showAdvanced = !showAdvanced"
                        class="py-2 px-2.5 inline-flex items-center gap-x-1.5 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 focus:outline-none focus:bg-stone-100 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-700">
                        {{ $t('sales.table.filters.toggle') }}
                    </button>
                    <button type="button" @click="clearFilters"
                        class="py-2 px-2.5 inline-flex items-center gap-x-1.5 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 focus:outline-none focus:bg-stone-100 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-700">
                        {{ $t('sales.table.filters.reset') }}
                    </button>
                </div>
            </div>

            <div v-if="showAdvanced" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-6 gap-2">
                <select v-model="filterForm.status"
                    class="py-2 ps-3 pe-8 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                    <option v-for="option in statusOptions" :key="option.value" :value="option.value">
                        {{ option.label }}
                    </option>
                </select>
                <select v-model="filterForm.customer_id"
                    class="py-2 ps-3 pe-8 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                    <option value="">{{ $t('sales.table.filters.all_customers') }}</option>
                    <option v-for="customer in customers" :key="customer.id" :value="customer.id">
                        {{ customer.company_name || `${customer.first_name} ${customer.last_name}` }}
                    </option>
                </select>
                <input type="number" v-model="filterForm.total_min" min="0" step="0.01"
                    class="py-2 px-3 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                    :placeholder="$t('sales.table.filters.total_min')">
                <input type="number" v-model="filterForm.total_max" min="0" step="0.01"
                    class="py-2 px-3 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                    :placeholder="$t('sales.table.filters.total_max')">
                <input type="date" v-model="filterForm.created_from"
                    class="py-2 px-3 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                    :placeholder="$t('sales.table.filters.date_from')">
                <input type="date" v-model="filterForm.created_to"
                    class="py-2 px-3 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                    :placeholder="$t('sales.table.filters.date_to')">
            </div>
        </div>

        <div
            class="overflow-x-auto [&::-webkit-scrollbar]:h-2 [&::-webkit-scrollbar-thumb]:rounded-full [&::-webkit-scrollbar-track]:bg-stone-100 [&::-webkit-scrollbar-thumb]:bg-stone-300 dark:[&::-webkit-scrollbar-track]:bg-neutral-700 dark:[&::-webkit-scrollbar-thumb]:bg-neutral-500">
            <div class="min-w-full inline-block align-middle">
                <table class="min-w-full divide-y divide-stone-200 dark:divide-neutral-700">
                    <thead>
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
                    </thead>

                    <tbody class="divide-y divide-stone-200 dark:divide-neutral-700">
                        <template v-if="isLoading">
                            <tr v-for="row in 6" :key="`skeleton-${row}`">
                                <td colspan="7" class="px-4 py-3">
                                    <div class="grid grid-cols-6 gap-4 animate-pulse">
                                        <div class="h-3 w-32 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                                        <div class="h-3 w-28 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                                        <div class="h-3 w-24 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                                        <div class="h-3 w-16 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                                        <div class="h-3 w-20 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                                        <div class="h-3 w-24 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                                    </div>
                                </td>
                            </tr>
                        </template>
                        <template v-else>
                            <tr v-if="!sales.data.length">
                                <td colspan="7" class="px-4 py-8 text-center text-stone-600 dark:text-neutral-300">
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
                                </td>
                            </tr>
                            <tr v-for="sale in sales.data" :key="sale.id">
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
                                        <select
                                            class="w-full rounded-sm border border-stone-200 bg-white px-2 py-1 text-[11px] text-stone-700 focus:border-green-500 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                                            :value="sale.fulfillment_status || ''"
                                            :disabled="isUpdating(sale) || !canChangeFulfillment(sale)"
                                            @change="updateFulfillment(sale, $event.target.value)"
                                        >
                                            <option value="">{{ $t('sales.table.fulfillment_placeholder') }}</option>
                                            <option
                                                v-for="option in fulfillmentOptionsFor(sale)"
                                                :key="option.value"
                                                :value="option.value"
                                            >
                                                {{ option.label }}
                                            </option>
                                        </select>
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
                                <div
                                    class="hs-dropdown [--auto-close:inside] [--placement:bottom-right] relative inline-flex">
                                    <button type="button"
                                        class="size-7 inline-flex justify-center items-center gap-x-2 rounded-sm border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-700 dark:focus:bg-neutral-700"
                                        aria-haspopup="menu" aria-expanded="false" aria-label="Dropdown">
                                        <svg class="shrink-0 size-3.5" xmlns="http://www.w3.org/2000/svg" width="24"
                                            height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                            stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <circle cx="12" cy="12" r="1" />
                                            <circle cx="12" cy="5" r="1" />
                                            <circle cx="12" cy="19" r="1" />
                                        </svg>
                                    </button>

                                    <div class="hs-dropdown-menu hs-dropdown-open:opacity-100 w-40 transition-[opacity,margin] duration opacity-0 hidden z-10 bg-white rounded-sm shadow-[0_10px_40px_10px_rgba(0,0,0,0.08)] dark:shadow-[0_10px_40px_10px_rgba(0,0,0,0.2)] dark:bg-neutral-900"
                                        role="menu" aria-orientation="vertical">
                                        <div class="p-1">
                                            <Link :href="route('sales.show', sale.id)"
                                                class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800">
                                                {{ $t('sales.table.actions.view') }}
                                            </Link>
                                            <Link v-if="canEdit(sale)" :href="route('sales.edit', sale.id)"
                                                class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800">
                                                {{ $t('sales.table.actions.edit') }}
                                            </Link>
                                        </div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>

        <div v-if="sales.data.length > 0" class="mt-5 flex flex-wrap justify-between items-center gap-2">
            <p class="text-sm text-stone-800 dark:text-neutral-200">
                <span class="font-medium"> {{ sales.total ?? sales.data.length }} </span>
                <span class="text-stone-500 dark:text-neutral-500"> {{ $t('sales.table.pagination.results') }}</span>
            </p>

            <nav class="flex justify-end items-center gap-x-1" :aria-label="$t('sales.table.pagination.label')">
                <Link :href="sales.prev_page_url" v-if="sales.prev_page_url">
                <button type="button"
                    class="min-h-[38px] min-w-[38px] py-2 px-2.5 inline-flex justify-center items-center gap-x-2 text-sm rounded-sm text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 dark:text-white dark:hover:bg-white/10 dark:focus:bg-neutral-700"
                    :aria-label="$t('sales.table.pagination.previous')">
                    <svg class="shrink-0 size-3.5" xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                        viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                        stroke-linejoin="round">
                        <path d="m15 18-6-6 6-6" />
                    </svg>
                    <span class="sr-only">{{ $t('sales.table.pagination.previous') }}</span>
                </button>
                </Link>
                <div class="flex items-center gap-x-1">
                    <span
                        class="min-h-[38px] min-w-[38px] flex justify-center items-center bg-stone-100 text-stone-800 py-2 px-3 text-sm rounded-sm disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-700 dark:text-white"
                        aria-current="page">{{ sales.from }}</span>
                    <span
                        class="min-h-[38px] flex justify-center items-center text-stone-500 py-2 px-1.5 text-sm dark:text-neutral-500">{{ $t('sales.table.pagination.of') }}</span>
                    <span
                        class="min-h-[38px] flex justify-center items-center text-stone-500 py-2 px-1.5 text-sm dark:text-neutral-500">{{ sales.to }}</span>
                </div>

                <Link :href="sales.next_page_url" v-if="sales.next_page_url">
                <button type="button"
                    class="min-h-[38px] min-w-[38px] py-2 px-2.5 inline-flex justify-center items-center gap-x-2 text-sm rounded-sm text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 dark:text-white dark:hover:bg-white/10 dark:focus:bg-neutral-700"
                    :aria-label="$t('sales.table.pagination.next')">
                    <span class="sr-only">{{ $t('sales.table.pagination.next') }}</span>
                    <svg class="shrink-0 size-3.5" xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                        viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                        stroke-linejoin="round">
                        <path d="m9 18 6-6-6-6" />
                    </svg>
                </button>
                </Link>
            </nav>
        </div>
    </div>
</template>
