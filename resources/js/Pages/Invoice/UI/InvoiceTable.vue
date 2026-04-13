<script setup>
import { computed, ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AdminDataTable from '@/Components/DataTable/AdminDataTable.vue';
import AdminDataTableToolbar from '@/Components/DataTable/AdminDataTableToolbar.vue';
import AdminPaginationLinks from '@/Components/DataTable/AdminPaginationLinks.vue';
import InvoiceActionsMenu from '@/Pages/Invoice/UI/InvoiceActionsMenu.vue';
import StarRating from '@/Components/UI/StarRating.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import DatePicker from '@/Components/DatePicker.vue';
import { humanizeDate } from '@/utils/date';
import { resolveDataTablePerPage } from '@/Components/DataTable/pagination';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    invoices: {
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
});

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
const isViewSwitching = ref(false);
const sendingInvoiceId = ref(null);
const allowedViews = ['table', 'cards'];
const viewMode = ref('table');
const isBusy = computed(() => isLoading.value || isViewSwitching.value);
let viewSwitchTimeout;

const { t } = useI18n();

if (typeof window !== 'undefined') {
    const storedView = window.localStorage.getItem('invoice_view_mode');
    if (allowedViews.includes(storedView)) {
        viewMode.value = storedView;
    }
}

const setViewMode = (mode) => {
    if (!allowedViews.includes(mode) || viewMode.value === mode) {
        return;
    }
    viewMode.value = mode;
    if (typeof window !== 'undefined') {
        window.localStorage.setItem('invoice_view_mode', mode);
    }
    isViewSwitching.value = true;
    if (viewSwitchTimeout) {
        clearTimeout(viewSwitchTimeout);
    }
    viewSwitchTimeout = setTimeout(() => {
        isViewSwitching.value = false;
    }, 220);
};

const statusOptions = computed(() => ([
    { value: '', label: t('invoices.filters.status.all') },
    { value: 'draft', label: t('invoices.status.draft') },
    { value: 'sent', label: t('invoices.status.sent') },
    { value: 'partial', label: t('invoices.status.partial') },
    { value: 'paid', label: t('invoices.status.paid') },
    { value: 'overdue', label: t('invoices.status.overdue') },
    { value: 'void', label: t('invoices.status.void') },
]));

const customerOptions = computed(() => ([
    { value: '', label: t('invoices.filters.customer.all') },
    ...(props.customers || []).map((customer) => ({
        value: String(customer.id),
        label: customer.company_name || `${customer.first_name} ${customer.last_name}`,
    })),
]));

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

let filterTimeout;
const autoFilter = () => {
    if (filterTimeout) {
        clearTimeout(filterTimeout);
    }
    filterTimeout = setTimeout(() => {
        isLoading.value = true;
        router.get(route('invoice.index'), filterPayload(), {
            only: ['invoices', 'filters', 'stats', 'count'],
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

const getCustomerName = (invoice) => {
    const customer = invoice.customer;
    if (!customer) {
        return t('invoices.labels.unknown_customer');
    }
    return customer.company_name || `${customer.first_name} ${customer.last_name}`;
};

const statusMeta = computed(() => ({
    draft: {
        label: t('invoices.status.draft'),
        classes: 'bg-slate-100 text-slate-700 dark:bg-slate-500/15 dark:text-slate-200',
        icon: 'draft',
        iconClass: '',
        accent: 'border-l-slate-400/80',
    },
    sent: {
        label: t('invoices.status.sent'),
        classes: 'bg-sky-100 text-sky-800 dark:bg-sky-500/15 dark:text-sky-200',
        icon: 'sent',
        iconClass: '',
        accent: 'border-l-sky-500/80',
    },
    partial: {
        label: t('invoices.status.partial'),
        classes: 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-200',
        icon: 'partial',
        iconClass: '',
        accent: 'border-l-amber-500/80',
    },
    paid: {
        label: t('invoices.status.paid'),
        classes: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-200',
        icon: 'paid',
        iconClass: 'animate-micro-pop',
        accent: 'border-l-emerald-500/80',
    },
    overdue: {
        label: t('invoices.status.overdue'),
        classes: 'bg-rose-100 text-rose-800 dark:bg-rose-500/15 dark:text-rose-200',
        icon: 'overdue',
        iconClass: 'animate-micro-pulse',
        accent: 'border-l-rose-500/80',
    },
    void: {
        label: t('invoices.status.void'),
        classes: 'bg-stone-100 text-stone-700 dark:bg-neutral-700 dark:text-neutral-300',
        icon: 'void',
        iconClass: '',
        accent: 'border-l-stone-300',
    },
}));

const getStatusMeta = (invoice) => statusMeta.value[invoice?.status] || statusMeta.value.draft;

const canSendInvoice = (invoice) => Boolean(invoice?.customer?.email) && invoice?.status !== 'void';

const invoiceActionLabel = (invoice) => (
    invoice?.status === 'draft'
        ? t('invoices.actions.send_invoice')
        : t('invoices.actions.resend_invoice')
);

const sendInvoice = (invoice) => {
    if (!invoice?.id || !canSendInvoice(invoice) || sendingInvoiceId.value !== null) {
        return;
    }

    sendingInvoiceId.value = invoice.id;

    router.post(route('invoice.send.email', invoice.id), {}, {
        preserveState: true,
        preserveScroll: true,
        onFinish: () => {
            sendingInvoiceId.value = null;
        },
    });
};

const invoiceRows = computed(() => props.invoices?.data || []);
const invoiceTableRows = computed(() => (isBusy.value
    ? Array.from({ length: 6 }, (_, index) => ({ id: `invoice-skeleton-${index}`, __skeleton: true }))
    : invoiceRows.value));
const invoiceLinks = computed(() => props.invoices?.links || []);
const currentPerPage = computed(() => resolveDataTablePerPage(props.invoices?.per_page, props.filters?.per_page));
const invoiceResultsLabel = computed(() => `${props.invoices?.total ?? props.invoices?.data?.length ?? 0} ${t('invoices.table.results')}`);
</script>

<template>
    <div
        class="p-5 space-y-4 flex flex-col border-t-4 border-t-zinc-600 bg-white border border-stone-200 shadow-sm rounded-sm dark:bg-neutral-800 dark:border-neutral-700">
        <AdminDataTableToolbar
            :show-filters="showAdvanced"
            :show-apply="false"
            :busy="isBusy"
            :filters-label="$t('invoices.actions.filters')"
            :clear-label="$t('invoices.actions.clear')"
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
                    <input type="text" v-model="filterForm.search"
                        class="py-[7px] ps-10 pe-8 block w-full bg-white border border-stone-200 rounded-sm text-sm placeholder:text-stone-500 focus:border-green-500 focus:ring-green-600 disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200 dark:placeholder:text-neutral-400 dark:focus:ring-neutral-600"
                        :placeholder="$t('invoices.filters.search_placeholder')">
                </div>
            </template>

            <template #filters>
                <FloatingSelect
                    v-model="filterForm.status"
                    :label="$t('invoices.table.status')"
                    :options="statusOptions"
                    dense
                />
                <FloatingSelect
                    v-model="filterForm.customer_id"
                    :label="$t('invoices.table.customer')"
                    :options="customerOptions"
                    dense
                />
                <input type="number" v-model="filterForm.total_min" min="0" step="0.01"
                    class="py-2 px-3 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                    :placeholder="$t('invoices.filters.total_min')">
                <input type="number" v-model="filterForm.total_max" min="0" step="0.01"
                    class="py-2 px-3 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                    :placeholder="$t('invoices.filters.total_max')">
                <DatePicker v-model="filterForm.created_from" :label="$t('invoices.filters.created_from')" />
                <DatePicker v-model="filterForm.created_to" :label="$t('invoices.filters.created_to')" />
            </template>

            <template #actions>
                <div class="inline-flex items-center rounded-sm border border-stone-200 bg-white p-0.5 text-xs font-semibold text-stone-600 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300">
                    <button
                        type="button"
                        @click="setViewMode('table')"
                        class="inline-flex items-center gap-1.5 rounded-sm px-3 py-1.5"
                        :class="viewMode === 'table'
                            ? 'bg-green-600 text-white shadow-sm dark:bg-white dark:text-stone-900'
                            : 'text-stone-600 hover:text-stone-800 dark:text-neutral-300 dark:hover:text-neutral-100'"
                    >
                        <svg class="size-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M3 3h18v6H3z" />
                            <path d="M3 13h18v8H3z" />
                        </svg>
                        {{ $t('invoices.view.table') }}
                    </button>
                    <button
                        type="button"
                        @click="setViewMode('cards')"
                        class="inline-flex items-center gap-1.5 rounded-sm px-3 py-1.5"
                        :class="viewMode === 'cards'
                            ? 'bg-green-600 text-white shadow-sm dark:bg-white dark:text-stone-900'
                            : 'text-stone-600 hover:text-stone-800 dark:text-neutral-300 dark:hover:text-neutral-100'"
                    >
                        <svg class="size-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="3" width="7" height="7" rx="1" />
                            <rect x="14" y="3" width="7" height="7" rx="1" />
                            <rect x="3" y="14" width="7" height="7" rx="1" />
                            <rect x="14" y="14" width="7" height="7" rx="1" />
                        </svg>
                        {{ $t('invoices.view.cards') }}
                    </button>
                </div>
            </template>
        </AdminDataTableToolbar>

        <AdminDataTable
            v-if="viewMode === 'table'"
            embedded
            :rows="invoiceTableRows"
            :links="invoiceLinks"
            :show-pagination="invoiceRows.length > 0"
            show-per-page
            :per-page="currentPerPage"
        >
            <template #empty>
                <div
                    class="rounded-sm border border-dashed border-stone-200 bg-white px-4 py-10 text-center text-stone-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300"
                >
                    {{ $t('invoices.empty.invoices') }}
                </div>
            </template>

            <template #head>
                <tr>
                    <th scope="col" class="min-w-[180px]">
                        <button type="button" @click="toggleSort('number')"
                            class="flex w-full items-center gap-x-1 px-5 py-2.5 text-start text-sm font-normal text-stone-500 hover:text-stone-700 focus:outline-none dark:text-neutral-500 dark:hover:text-neutral-300">
                            {{ $t('invoices.table.invoice') }}
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
                            {{ $t('invoices.table.customer') }}
                        </div>
                    </th>
                    <th scope="col" class="min-w-32">
                        <button type="button" @click="toggleSort('status')"
                            class="flex w-full items-center gap-x-1 px-5 py-2.5 text-start text-sm font-normal text-stone-500 hover:text-stone-700 focus:outline-none dark:text-neutral-500 dark:hover:text-neutral-300">
                            {{ $t('invoices.table.status') }}
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
                            {{ $t('invoices.table.rating') }}
                        </div>
                    </th>
                    <th scope="col" class="min-w-32">
                        <button type="button" @click="toggleSort('total')"
                            class="flex w-full items-center gap-x-1 px-5 py-2.5 text-start text-sm font-normal text-stone-500 hover:text-stone-700 focus:outline-none dark:text-neutral-500 dark:hover:text-neutral-300">
                            {{ $t('invoices.table.total') }}
                            <svg v-if="filterForm.sort === 'total'" class="size-3" xmlns="http://www.w3.org/2000/svg"
                                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                stroke-linecap="round" stroke-linejoin="round"
                                :class="filterForm.direction === 'asc' ? 'rotate-180' : ''">
                                <path d="m6 9 6 6 6-6" />
                            </svg>
                        </button>
                    </th>
                    <th scope="col" class="min-w-32">
                        <div class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                            {{ $t('invoices.table.balance_due') }}
                        </div>
                    </th>
                    <th scope="col" class="min-w-32">
                        <button type="button" @click="toggleSort('created_at')"
                            class="flex w-full items-center gap-x-1 px-5 py-2.5 text-start text-sm font-normal text-stone-500 hover:text-stone-700 focus:outline-none dark:text-neutral-500 dark:hover:text-neutral-300">
                            {{ $t('invoices.table.created') }}
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

            <template #row="{ row: invoice }">
                <tr v-if="invoice.__skeleton">
                    <td colspan="8" class="px-4 py-3">
                        <div class="grid grid-cols-6 gap-4 animate-pulse">
                            <div class="h-3 w-32 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                            <div class="h-3 w-28 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                            <div class="h-3 w-24 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                            <div class="h-3 w-20 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                            <div class="h-3 w-16 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                            <div class="h-3 w-20 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                        </div>
                    </td>
                </tr>
                <tr v-else>
                    <td class="size-px whitespace-nowrap px-4 py-2 text-start">
                        <div class="flex flex-col">
                            <span class="text-sm text-stone-600 dark:text-neutral-300">
                                {{ invoice.number || $t('invoices.labels.invoice_number', { id: invoice.id }) }}
                            </span>
                            <span class="text-xs text-stone-500 dark:text-neutral-500">
                                {{ invoice.work?.job_title ?? '-' }}
                            </span>
                        </div>
                    </td>
                    <td class="size-px whitespace-nowrap px-4 py-2">
                        <span class="text-sm text-stone-600 dark:text-neutral-300">
                            {{ getCustomerName(invoice) }}
                        </span>
                    </td>
                    <td class="size-px whitespace-nowrap px-4 py-2">
                        <span class="py-1.5 px-2 inline-flex items-center gap-x-1.5 text-xs font-semibold rounded-full"
                            :class="getStatusMeta(invoice).classes">
                            <span class="inline-flex size-3.5 items-center justify-center" :class="getStatusMeta(invoice).iconClass">
                                <svg v-if="getStatusMeta(invoice).icon === 'draft'" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                                    fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                    class="size-3.5">
                                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7z" />
                                    <path d="M14 2v6h6" />
                                    <path d="M8 13h8" />
                                </svg>
                                <svg v-else-if="getStatusMeta(invoice).icon === 'sent'" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                                    fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                    class="size-3.5">
                                    <path d="m22 2-7 20-4-9-9-4Z" />
                                    <path d="M22 2 11 13" />
                                </svg>
                                <svg v-else-if="getStatusMeta(invoice).icon === 'partial'" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                                    fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                    class="size-3.5">
                                    <path d="M21 12A9 9 0 1 1 12 3v9z" />
                                </svg>
                                <svg v-else-if="getStatusMeta(invoice).icon === 'paid'" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                                    fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                    class="size-3.5">
                                    <circle cx="12" cy="12" r="9" />
                                    <path d="m8.5 12.5 2.5 2.5 4.5-5" />
                                </svg>
                                <svg v-else-if="getStatusMeta(invoice).icon === 'overdue'" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                                    fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                    class="size-3.5">
                                    <circle cx="12" cy="12" r="9" />
                                    <path d="M12 8v4" />
                                    <path d="M12 16h.01" />
                                </svg>
                                <svg v-else-if="getStatusMeta(invoice).icon === 'void'" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                                    fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                    class="size-3.5">
                                    <circle cx="12" cy="12" r="9" />
                                    <path d="m5 5 14 14" />
                                </svg>
                            </span>
                            <span>{{ getStatusMeta(invoice).label }}</span>
                        </span>
                    </td>
                    <td class="size-px whitespace-nowrap px-4 py-2">
                        <StarRating :value="invoice.work?.ratings_avg_rating" icon-class="h-3.5 w-3.5" empty-label="-" />
                    </td>
                    <td class="size-px whitespace-nowrap px-4 py-2">
                        <span class="text-sm text-stone-600 dark:text-neutral-300">
                            ${{ Number(invoice.total || 0).toFixed(2) }}
                        </span>
                    </td>
                    <td class="size-px whitespace-nowrap px-4 py-2">
                        <span class="text-sm text-stone-600 dark:text-neutral-300">
                            ${{ Number(invoice.balance_due || 0).toFixed(2) }}
                        </span>
                    </td>
                    <td class="size-px whitespace-nowrap px-4 py-2">
                        <span class="text-xs text-stone-500 dark:text-neutral-500">
                            {{ formatDate(invoice.created_at) }}
                        </span>
                    </td>
                    <td class="size-px whitespace-nowrap px-4 py-2 text-end">
                        <InvoiceActionsMenu
                            :invoice="invoice"
                            :can-send="canSendInvoice(invoice)"
                            :sending="sendingInvoiceId === invoice.id"
                            :send-label="sendingInvoiceId === invoice.id ? $t('invoices.actions.sending_invoice') : invoiceActionLabel(invoice)"
                            @send="sendInvoice(invoice)"
                        />
                    </td>
                </tr>
            </template>

            <template #pagination_prefix>
                <p class="text-sm text-stone-800 dark:text-neutral-200">{{ invoiceResultsLabel }}</p>
            </template>
        </AdminDataTable>

        <div v-else class="space-y-3">
            <div v-if="isBusy" class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                <div v-for="row in 6" :key="`card-skeleton-${row}`"
                    class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                    <div class="space-y-4 animate-pulse">
                        <div class="flex items-start justify-between gap-2">
                            <div class="space-y-2">
                                <div class="h-3 w-32 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                                <div class="h-3 w-24 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                            </div>
                            <div class="h-5 w-20 rounded-full bg-stone-200 dark:bg-neutral-700"></div>
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <div class="h-3 w-full rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                            <div class="h-3 w-full rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <div class="h-4 w-20 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                            <div class="h-4 w-24 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                        </div>
                    </div>
                </div>
            </div>
            <div v-else-if="!invoiceRows.length"
                class="rounded-sm border border-dashed border-stone-200 bg-white px-4 py-10 text-center text-stone-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300">
                {{ $t('invoices.empty.invoices') }}
            </div>
            <div v-else class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                <div
                    v-for="invoice in invoiceRows"
                    :key="invoice.id"
                    class="rounded-sm border border-stone-200 border-l-4 bg-white p-4 shadow-sm transition-shadow hover:shadow-md dark:border-neutral-700 dark:bg-neutral-800"
                    :class="getStatusMeta(invoice).accent"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="text-sm font-semibold text-stone-800 dark:text-neutral-100 line-clamp-1">
                                {{ invoice.number || $t('invoices.labels.invoice_number', { id: invoice.id }) }}
                            </div>
                            <div class="text-xs text-stone-500 dark:text-neutral-400">
                                {{ getCustomerName(invoice) }}
                            </div>
                            <div class="text-[11px] text-stone-400 dark:text-neutral-500">
                                {{ invoice.work?.job_title ?? '-' }}
                            </div>
                        </div>
                        <div class="flex items-start gap-2">
                            <span class="py-1.5 px-2 inline-flex items-center gap-x-1.5 text-xs font-semibold rounded-full"
                                :class="getStatusMeta(invoice).classes">
                                <span class="inline-flex size-3.5 items-center justify-center" :class="getStatusMeta(invoice).iconClass">
                                    <svg v-if="getStatusMeta(invoice).icon === 'draft'" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                                        fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                        class="size-3.5">
                                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7z" />
                                        <path d="M14 2v6h6" />
                                        <path d="M8 13h8" />
                                    </svg>
                                    <svg v-else-if="getStatusMeta(invoice).icon === 'sent'" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                                        fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                        class="size-3.5">
                                        <path d="m22 2-7 20-4-9-9-4Z" />
                                        <path d="M22 2 11 13" />
                                    </svg>
                                    <svg v-else-if="getStatusMeta(invoice).icon === 'partial'" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                                        fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                        class="size-3.5">
                                        <path d="M21 12A9 9 0 1 1 12 3v9z" />
                                    </svg>
                                    <svg v-else-if="getStatusMeta(invoice).icon === 'paid'" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                                        fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                        class="size-3.5">
                                        <circle cx="12" cy="12" r="9" />
                                        <path d="m8.5 12.5 2.5 2.5 4.5-5" />
                                    </svg>
                                    <svg v-else-if="getStatusMeta(invoice).icon === 'overdue'" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                                        fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                        class="size-3.5">
                                        <circle cx="12" cy="12" r="9" />
                                        <path d="M12 8v4" />
                                        <path d="M12 16h.01" />
                                    </svg>
                                    <svg v-else-if="getStatusMeta(invoice).icon === 'void'" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                                        fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                        class="size-3.5">
                                        <circle cx="12" cy="12" r="9" />
                                        <path d="m5 5 14 14" />
                                    </svg>
                                </span>
                                <span>{{ getStatusMeta(invoice).label }}</span>
                            </span>
                            <InvoiceActionsMenu
                                :invoice="invoice"
                                :can-send="canSendInvoice(invoice)"
                                :sending="sendingInvoiceId === invoice.id"
                                :send-label="sendingInvoiceId === invoice.id ? $t('invoices.actions.sending_invoice') : invoiceActionLabel(invoice)"
                                @send="sendInvoice(invoice)"
                            />
                        </div>
                    </div>

                    <div class="mt-4 grid grid-cols-2 gap-3 text-xs text-stone-500 dark:text-neutral-400">
                        <div class="flex items-center gap-2">
                            <svg class="size-3.5 text-stone-400 dark:text-neutral-500" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="3" y="4" width="18" height="18" rx="2" />
                                <path d="M8 2v4" />
                                <path d="M16 2v4" />
                                <path d="M3 10h18" />
                            </svg>
                            <span class="text-stone-700 dark:text-neutral-200">
                                {{ formatDate(invoice.created_at) }}
                            </span>
                        </div>
                        <div class="flex items-center gap-2 justify-end">
                            <span class="text-stone-500 dark:text-neutral-400">{{ $t('invoices.table.rating') }}</span>
                            <StarRating :value="invoice.work?.ratings_avg_rating" icon-class="h-3.5 w-3.5" empty-label="-" />
                        </div>
                    </div>

                    <div class="mt-3 grid grid-cols-2 gap-3 text-xs text-stone-500 dark:text-neutral-400">
                        <div class="flex flex-col gap-1">
                            <span>{{ $t('invoices.table.total') }}</span>
                            <span class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                ${{ Number(invoice.total || 0).toFixed(2) }}
                            </span>
                        </div>
                        <div class="flex flex-col gap-1 text-right">
                            <span>{{ $t('invoices.table.balance_due') }}</span>
                            <span class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                ${{ Number(invoice.balance_due || 0).toFixed(2) }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div v-if="viewMode !== 'table' && invoiceRows.length > 0" class="mt-5 flex flex-wrap items-center justify-between gap-2">
            <p class="text-sm text-stone-800 dark:text-neutral-200">{{ invoiceResultsLabel }}</p>

            <AdminPaginationLinks :links="invoiceLinks" />
        </div>
    </div>
</template>
