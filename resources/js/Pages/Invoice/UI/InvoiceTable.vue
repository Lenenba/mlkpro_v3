<script setup>
import { computed, ref, watch } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import StarRating from '@/Components/UI/StarRating.vue';
import { humanizeDate } from '@/utils/date';

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
const allowedViews = ['table', 'cards'];
const viewMode = ref('table');
const isBusy = computed(() => isLoading.value || isViewSwitching.value);
let viewSwitchTimeout;

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

const statusOptions = [
    { value: '', label: 'All statuses' },
    { value: 'draft', label: 'Draft' },
    { value: 'sent', label: 'Sent' },
    { value: 'partial', label: 'Partial' },
    { value: 'paid', label: 'Paid' },
    { value: 'overdue', label: 'Overdue' },
    { value: 'void', label: 'Void' },
];

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
        router.get(route('invoice.index'), filterPayload(), {
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
        return '-';
    }
    return customer.company_name || `${customer.first_name} ${customer.last_name}`;
};

const statusMeta = {
    draft: {
        label: 'Draft',
        classes: 'bg-slate-100 text-slate-700 dark:bg-slate-500/15 dark:text-slate-200',
        icon: 'draft',
        iconClass: '',
        accent: 'border-l-slate-400/80',
    },
    sent: {
        label: 'Sent',
        classes: 'bg-sky-100 text-sky-800 dark:bg-sky-500/15 dark:text-sky-200',
        icon: 'sent',
        iconClass: '',
        accent: 'border-l-sky-500/80',
    },
    partial: {
        label: 'Partial',
        classes: 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-200',
        icon: 'partial',
        iconClass: '',
        accent: 'border-l-amber-500/80',
    },
    paid: {
        label: 'Paid',
        classes: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-200',
        icon: 'paid',
        iconClass: 'animate-micro-pop',
        accent: 'border-l-emerald-500/80',
    },
    overdue: {
        label: 'Overdue',
        classes: 'bg-rose-100 text-rose-800 dark:bg-rose-500/15 dark:text-rose-200',
        icon: 'overdue',
        iconClass: 'animate-micro-pulse',
        accent: 'border-l-rose-500/80',
    },
    void: {
        label: 'Void',
        classes: 'bg-stone-100 text-stone-700 dark:bg-neutral-700 dark:text-neutral-300',
        icon: 'void',
        iconClass: '',
        accent: 'border-l-stone-300',
    },
};

const getStatusMeta = (invoice) => statusMeta[invoice?.status] || statusMeta.draft;
</script>

<template>
    <div
        class="p-5 space-y-4 flex flex-col border-t-4 border-t-zinc-600 bg-white border border-stone-200 shadow-sm rounded-sm dark:bg-neutral-800 dark:border-neutral-700">
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
                        <input type="text" v-model="filterForm.search"
                            class="py-[7px] ps-10 pe-8 block w-full bg-white border border-stone-200 rounded-sm text-sm placeholder:text-stone-500 focus:border-green-500 focus:ring-green-600 disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200 dark:placeholder:text-neutral-400 dark:focus:ring-neutral-600"
                            placeholder="Search invoice number or customer">
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2 justify-end">
                    <div class="inline-flex items-center rounded-sm border border-stone-200 bg-white p-0.5 text-xs font-semibold text-stone-600 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300">
                        <button
                            type="button"
                            @click="setViewMode('table')"
                            class="inline-flex items-center gap-1.5 rounded-sm px-3 py-1.5"
                            :class="viewMode === 'table'
                                ? 'bg-stone-900 text-white shadow-sm dark:bg-white dark:text-stone-900'
                                : 'text-stone-600 hover:text-stone-800 dark:text-neutral-300 dark:hover:text-neutral-100'"
                        >
                            <svg class="size-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M3 3h18v6H3z" />
                                <path d="M3 13h18v8H3z" />
                            </svg>
                            Table
                        </button>
                        <button
                            type="button"
                            @click="setViewMode('cards')"
                            class="inline-flex items-center gap-1.5 rounded-sm px-3 py-1.5"
                            :class="viewMode === 'cards'
                                ? 'bg-stone-900 text-white shadow-sm dark:bg-white dark:text-stone-900'
                                : 'text-stone-600 hover:text-stone-800 dark:text-neutral-300 dark:hover:text-neutral-100'"
                        >
                            <svg class="size-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="3" y="3" width="7" height="7" rx="1" />
                                <rect x="14" y="3" width="7" height="7" rx="1" />
                                <rect x="3" y="14" width="7" height="7" rx="1" />
                                <rect x="14" y="14" width="7" height="7" rx="1" />
                            </svg>
                            Cards
                        </button>
                    </div>
                    <button type="button" @click="showAdvanced = !showAdvanced"
                        class="py-2 px-2.5 inline-flex items-center gap-x-1.5 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 focus:outline-none focus:bg-stone-100 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-700">
                        Filters
                    </button>
                    <button type="button" @click="clearFilters"
                        class="py-2 px-2.5 inline-flex items-center gap-x-1.5 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 focus:outline-none focus:bg-stone-100 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-700">
                        Clear
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
                    <option value="">All customers</option>
                    <option v-for="customer in customers" :key="customer.id" :value="customer.id">
                        {{ customer.company_name || `${customer.first_name} ${customer.last_name}` }}
                    </option>
                </select>
                <input type="number" v-model="filterForm.total_min" min="0" step="0.01"
                    class="py-2 px-3 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                    placeholder="Min total">
                <input type="number" v-model="filterForm.total_max" min="0" step="0.01"
                    class="py-2 px-3 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                    placeholder="Max total">
                <input type="date" v-model="filterForm.created_from"
                    class="py-2 px-3 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                    placeholder="Created from">
                <input type="date" v-model="filterForm.created_to"
                    class="py-2 px-3 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                    placeholder="Created to">
            </div>
        </div>

        <div
            v-if="viewMode === 'table'"
            class="overflow-x-auto [&::-webkit-scrollbar]:h-2 [&::-webkit-scrollbar-thumb]:rounded-full [&::-webkit-scrollbar-track]:bg-stone-100 [&::-webkit-scrollbar-thumb]:bg-stone-300 dark:[&::-webkit-scrollbar-track]:bg-neutral-700 dark:[&::-webkit-scrollbar-thumb]:bg-neutral-500">
            <div class="min-w-full inline-block align-middle">
                <table class="min-w-full divide-y divide-stone-200 dark:divide-neutral-700">
                    <thead>
                        <tr>
                            <th scope="col" class="min-w-[180px]">
                                <button type="button" @click="toggleSort('number')"
                                    class="px-5 py-2.5 text-start w-full flex items-center gap-x-1 text-sm font-normal text-stone-500 hover:text-stone-700 focus:outline-none dark:text-neutral-500 dark:hover:text-neutral-300">
                                    Invoice
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
                                    Customer
                                </div>
                            </th>
                            <th scope="col" class="min-w-32">
                                <button type="button" @click="toggleSort('status')"
                                    class="px-5 py-2.5 text-start w-full flex items-center gap-x-1 text-sm font-normal text-stone-500 hover:text-stone-700 focus:outline-none dark:text-neutral-500 dark:hover:text-neutral-300">
                                    Status
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
                                    Rating
                                </div>
                            </th>
                            <th scope="col" class="min-w-32">
                                <button type="button" @click="toggleSort('total')"
                                    class="px-5 py-2.5 text-start w-full flex items-center gap-x-1 text-sm font-normal text-stone-500 hover:text-stone-700 focus:outline-none dark:text-neutral-500 dark:hover:text-neutral-300">
                                    Total
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
                                    Balance due
                                </div>
                            </th>
                            <th scope="col" class="min-w-32">
                                <button type="button" @click="toggleSort('created_at')"
                                    class="px-5 py-2.5 text-start w-full flex items-center gap-x-1 text-sm font-normal text-stone-500 hover:text-stone-700 focus:outline-none dark:text-neutral-500 dark:hover:text-neutral-300">
                                    Created
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
                        <template v-if="isBusy">
                            <tr v-for="row in 6" :key="`skeleton-${row}`">
                                <td colspan="9" class="px-4 py-3">
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
                        </template>
                        <template v-else>
                        <tr v-for="invoice in invoices.data" :key="invoice.id">
                            <td class="size-px whitespace-nowrap px-4 py-2 text-start">
                                <div class="flex flex-col">
                                    <span class="text-sm text-stone-600 dark:text-neutral-300">
                                        {{ invoice.number || `Invoice #${invoice.id}` }}
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
                                            <Link :href="route('invoice.show', invoice.id)"
                                                class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800">
                                                View invoice
                                            </Link>
                                            <Link v-if="invoice.work?.id" :href="route('work.show', invoice.work.id)"
                                                class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800">
                                                View job
                                            </Link>
                                            <Link v-if="invoice.customer?.id" :href="route('customer.show', invoice.customer.id)"
                                                class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800">
                                                View customer
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
            <div v-else-if="!invoices.data.length"
                class="rounded-sm border border-dashed border-stone-200 bg-white px-4 py-10 text-center text-stone-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300">
                No invoices yet.
            </div>
            <div v-else class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                <div
                    v-for="invoice in invoices.data"
                    :key="invoice.id"
                    class="rounded-sm border border-stone-200 border-l-4 bg-white p-4 shadow-sm transition-shadow hover:shadow-md dark:border-neutral-700 dark:bg-neutral-800"
                    :class="getStatusMeta(invoice).accent"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="text-sm font-semibold text-stone-800 dark:text-neutral-100 line-clamp-1">
                                {{ invoice.number || `Invoice #${invoice.id}` }}
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
                            <div class="hs-dropdown [--auto-close:inside] [--placement:bottom-right] relative inline-flex">
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
                                        <Link :href="route('invoice.show', invoice.id)"
                                            class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800">
                                            View invoice
                                        </Link>
                                        <Link v-if="invoice.work?.id" :href="route('work.show', invoice.work.id)"
                                            class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800">
                                            View job
                                        </Link>
                                        <Link v-if="invoice.customer?.id" :href="route('customer.show', invoice.customer.id)"
                                            class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800">
                                            View customer
                                        </Link>
                                    </div>
                                </div>
                            </div>
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
                            <span class="text-stone-500 dark:text-neutral-400">Rating</span>
                            <StarRating :value="invoice.work?.ratings_avg_rating" icon-class="h-3.5 w-3.5" empty-label="-" />
                        </div>
                    </div>

                    <div class="mt-3 grid grid-cols-2 gap-3 text-xs text-stone-500 dark:text-neutral-400">
                        <div class="flex flex-col gap-1">
                            <span>Total</span>
                            <span class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                ${{ Number(invoice.total || 0).toFixed(2) }}
                            </span>
                        </div>
                        <div class="flex flex-col gap-1 text-right">
                            <span>Balance due</span>
                            <span class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                ${{ Number(invoice.balance_due || 0).toFixed(2) }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div v-if="invoices.data.length > 0" class="mt-5 flex flex-wrap justify-between items-center gap-2">
            <p class="text-sm text-stone-800 dark:text-neutral-200">
                <span class="font-medium"> {{ invoices.total ?? invoices.data.length }} </span>
                <span class="text-stone-500 dark:text-neutral-500"> results</span>
            </p>

            <nav class="flex justify-end items-center gap-x-1" aria-label="Pagination">
                <Link :href="invoices.prev_page_url" v-if="invoices.prev_page_url">
                <button type="button"
                    class="min-h-[38px] min-w-[38px] py-2 px-2.5 inline-flex justify-center items-center gap-x-2 text-sm rounded-sm text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 dark:text-white dark:hover:bg-white/10 dark:focus:bg-neutral-700"
                    aria-label="Previous">
                    <svg class="shrink-0 size-3.5" xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                        viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                        stroke-linejoin="round">
                        <path d="m15 18-6-6 6-6" />
                    </svg>
                    <span class="sr-only">Previous</span>
                </button>
                </Link>
                <div class="flex items-center gap-x-1">
                    <span
                        class="min-h-[38px] min-w-[38px] flex justify-center items-center bg-stone-100 text-stone-800 py-2 px-3 text-sm rounded-sm disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-700 dark:text-white"
                        aria-current="page">{{ invoices.from }}</span>
                    <span
                        class="min-h-[38px] flex justify-center items-center text-stone-500 py-2 px-1.5 text-sm dark:text-neutral-500">of</span>
                    <span
                        class="min-h-[38px] flex justify-center items-center text-stone-500 py-2 px-1.5 text-sm dark:text-neutral-500">{{ invoices.to }}</span>
                </div>

                <Link :href="invoices.next_page_url" v-if="invoices.next_page_url">
                <button type="button"
                    class="min-h-[38px] min-w-[38px] py-2 px-2.5 inline-flex justify-center items-center gap-x-2 text-sm rounded-sm text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 dark:text-white dark:hover:bg-white/10 dark:focus:bg-neutral-700"
                    aria-label="Next">
                    <span class="sr-only">Next</span>
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


