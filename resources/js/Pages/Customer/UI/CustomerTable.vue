<script setup>
import { computed, ref, watch } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import { humanizeDate } from '@/utils/date';
import Checkbox from '@/Components/Checkbox.vue';

const props = defineProps({
    filters: Object,
    customers: {
        type: Object,
        required: true,
    },
    count: {
        type: Number,
        required: true,
    },
    canEdit: {
        type: Boolean,
        default: false,
    },
});

const canEdit = computed(() => Boolean(props.canEdit));

const filterForm = useForm({
    name: props.filters?.name ?? '',
    city: props.filters?.city ?? '',
    country: props.filters?.country ?? '',
    has_quotes: props.filters?.has_quotes ?? '',
    has_works: props.filters?.has_works ?? '',
    status: props.filters?.status ?? '',
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
    const storedView = window.localStorage.getItem('customer_view_mode');
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
        window.localStorage.setItem('customer_view_mode', mode);
    }
    isViewSwitching.value = true;
    if (viewSwitchTimeout) {
        clearTimeout(viewSwitchTimeout);
    }
    viewSwitchTimeout = setTimeout(() => {
        isViewSwitching.value = false;
    }, 220);
};

const filterPayload = () => {
    const payload = {
        name: filterForm.name,
        city: filterForm.city,
        country: filterForm.country,
        has_quotes: filterForm.has_quotes,
        has_works: filterForm.has_works,
        status: filterForm.status,
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
        router.get(route('customer.index'), filterPayload(), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onFinish: () => {
                isLoading.value = false;
            },
        });
    }, 300);
};

watch(() => filterForm.name, () => {
    autoFilter();
});

watch(() => [
    filterForm.city,
    filterForm.country,
    filterForm.has_quotes,
    filterForm.has_works,
    filterForm.status,
    filterForm.created_from,
    filterForm.created_to,
    filterForm.sort,
    filterForm.direction,
], () => {
    autoFilter();
});

const clearFilters = () => {
    filterForm.name = '';
    filterForm.city = '';
    filterForm.country = '';
    filterForm.has_quotes = '';
    filterForm.has_works = '';
    filterForm.status = '';
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

const selected = ref([]);
const selectAllRef = ref(null);
const allSelected = computed(() =>
    props.customers.data.length > 0 && selected.value.length === props.customers.data.length
);
const someSelected = computed(() =>
    selected.value.length > 0 && !allSelected.value
);

watch(() => props.customers.data, () => {
    selected.value = [];
}, { deep: true });

watch([allSelected, someSelected], () => {
    if (selectAllRef.value) {
        selectAllRef.value.indeterminate = someSelected.value;
    }
});

const toggleAll = (event) => {
    selected.value = event.target.checked
        ? props.customers.data.map((customer) => customer.id)
        : [];
};

const bulkForm = useForm({
    action: '',
    ids: [],
});

const runBulk = (action) => {
    if (!selected.value.length) {
        return;
    }
    if (action === 'delete' && !confirm('Delete selected customers?')) {
        return;
    }
    bulkForm.action = action;
    bulkForm.ids = selected.value;
    bulkForm.post(route('customer.bulk'), {
        preserveScroll: true,
        onSuccess: () => {
            selected.value = [];
        },
    });
};

const toggleArchive = (customer) => {
    if (!customer) {
        return;
    }
    const label = customer.is_active ? 'Archive' : 'Restore';
    const name = customer.company_name || `${customer.first_name} ${customer.last_name}`.trim() || 'Customer';
    if (!confirm(`${label} "${name}"?`)) {
        return;
    }
    const action = customer.is_active ? 'archive' : 'restore';
    router.post(route('customer.bulk'), { action, ids: [customer.id] }, { preserveScroll: true });
};

const destroyCustomer = (customer) => {
    const label = customer.company_name || `${customer.first_name} ${customer.last_name}`;
    if (!confirm(`Delete "${label}"?`)) {
        return;
    }

    router.delete(route('customer.destroy', customer.id), {
        preserveScroll: true,
    });
};

const getPrimaryProperty = (customer) => {
    if (!customer.properties || !customer.properties.length) {
        return null;
    }
    return customer.properties.find((property) => property.is_default) || customer.properties[0];
};

const getCity = (customer) => {
    const property = getPrimaryProperty(customer);
    return property ? property.city : '';
};

const formatDate = (value) => humanizeDate(value);

const hasCustomerLogo = (customer) => Boolean(customer?.logo_url || customer?.logo);

const getCustomerInitials = (customer) => {
    const name = customer?.company_name
        || `${customer?.first_name || ''} ${customer?.last_name || ''}`.trim();
    if (!name) {
        return 'C';
    }
    const parts = name.split(' ').filter(Boolean);
    const first = parts[0]?.[0] || '';
    const second = parts[1]?.[0] || '';
    return `${first}${second}`.toUpperCase();
};
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
                        <input type="text" v-model="filterForm.name" data-testid="demo-customer-search"
                            class="py-[7px] ps-10 pe-8 block w-full bg-white border border-stone-200 rounded-sm text-sm placeholder:text-stone-500 focus:border-green-500 focus:ring-green-600 disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200 dark:placeholder:text-neutral-400 dark:focus:ring-neutral-600"
                            placeholder="Search name, company, email, or phone">
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2 justify-end">
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
                            Table
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
                    <Link :href="route('customer.create')" data-testid="demo-add-customer"
                        class="py-2 px-2.5 inline-flex items-center gap-x-1.5 text-xs font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:ring-2 focus:ring-green-500">
                        <svg class="hidden sm:block shrink-0 size-3.5" xmlns="http://www.w3.org/2000/svg" width="24"
                            height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                            stroke-linecap="round" stroke-linejoin="round">
                            <path d="M5 12h14" />
                            <path d="M12 5v14" />
                        </svg>
                        Add customer
                    </Link>
                </div>
            </div>

            <div v-if="canEdit && selected.length" class="flex items-center gap-2">
                <span class="text-xs text-stone-500 dark:text-neutral-400">
                    {{ selected.length }} selected
                </span>
                <div class="hs-dropdown [--auto-close:inside] [--placement:bottom-right] relative inline-flex">
                    <button type="button"
                        class="py-2 px-2.5 inline-flex items-center gap-x-1.5 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 focus:outline-none focus:bg-stone-100 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-700 action-feedback"
                        aria-haspopup="menu" aria-expanded="false" aria-label="Dropdown">
                        Bulk actions
                    </button>
                    <div class="hs-dropdown-menu hs-dropdown-open:opacity-100 w-44 transition-[opacity,margin] duration opacity-0 hidden z-10 bg-white rounded-sm shadow-[0_10px_40px_10px_rgba(0,0,0,0.08)] dark:shadow-[0_10px_40px_10px_rgba(0,0,0,0.2)] dark:bg-neutral-900"
                        role="menu" aria-orientation="vertical">
                        <div class="p-1">
                            <button type="button" @click="runBulk('portal_enable')"
                                class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-emerald-700 hover:bg-emerald-50 dark:text-emerald-300 dark:hover:bg-neutral-800 action-feedback">
                                Enable portal access
                            </button>
                            <button type="button" @click="runBulk('portal_disable')"
                                class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-amber-700 hover:bg-amber-50 dark:text-amber-300 dark:hover:bg-neutral-800 action-feedback">
                                Disable portal access
                            </button>
                            <button type="button" @click="runBulk('archive')"
                                class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-slate-700 hover:bg-slate-100 dark:text-neutral-300 dark:hover:bg-neutral-800 action-feedback" data-tone="warning">
                                Archive
                            </button>
                            <button type="button" @click="runBulk('restore')"
                                class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-emerald-700 hover:bg-emerald-50 dark:text-emerald-300 dark:hover:bg-neutral-800 action-feedback">
                                Restore
                            </button>
                            <div class="my-1 border-t border-stone-200 dark:border-neutral-800"></div>
                            <button type="button" @click="runBulk('delete')"
                                class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-neutral-800 action-feedback" data-tone="danger">
                                Delete
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div v-if="showAdvanced" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-6 gap-2">
                <input type="text" v-model="filterForm.city"
                    class="py-2 px-3 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                    placeholder="City">
                <input type="text" v-model="filterForm.country"
                    class="py-2 px-3 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                    placeholder="Country">
                <select v-model="filterForm.has_quotes"
                    class="py-2 ps-3 pe-8 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                    <option value="">Quotes</option>
                    <option value="1">With quotes</option>
                    <option value="0">No quotes</option>
                </select>
                <select v-model="filterForm.has_works"
                    class="py-2 ps-3 pe-8 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                    <option value="">Jobs</option>
                    <option value="1">With jobs</option>
                    <option value="0">No jobs</option>
                </select>
                <select v-model="filterForm.status"
                    class="py-2 ps-3 pe-8 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                    <option value="">Status</option>
                    <option value="active">Active</option>
                    <option value="archived">Archived</option>
                </select>
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
                            <th scope="col" class="w-10 px-4 py-2">
                                <input v-if="canEdit" ref="selectAllRef" type="checkbox" :checked="allSelected" @change="toggleAll"
                                    class="rounded border-stone-300 text-green-600 shadow-sm focus:ring-green-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-green-400 dark:focus:ring-green-400" />
                            </th>
                            <th scope="col" class="min-w-[240px]">
                                <button type="button" @click="toggleSort('company_name')"
                                    class="px-5 py-2.5 text-start w-full flex items-center gap-x-1 text-sm font-normal text-stone-500 hover:text-stone-700 focus:outline-none dark:text-neutral-500 dark:hover:text-neutral-300">
                                    Company
                                    <svg v-if="filterForm.sort === 'company_name'" class="size-3" xmlns="http://www.w3.org/2000/svg"
                                        viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                        stroke-linecap="round" stroke-linejoin="round"
                                        :class="filterForm.direction === 'asc' ? 'rotate-180' : ''">
                                        <path d="m6 9 6 6 6-6" />
                                    </svg>
                                </button>
                            </th>
                            <th scope="col" class="min-w-40">
                                <button type="button" @click="toggleSort('first_name')"
                                    class="px-5 py-2.5 text-start w-full flex items-center gap-x-1 text-sm font-normal text-stone-500 hover:text-stone-700 focus:outline-none dark:text-neutral-500 dark:hover:text-neutral-300">
                                    Contact
                                    <svg v-if="filterForm.sort === 'first_name'" class="size-3" xmlns="http://www.w3.org/2000/svg"
                                        viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                        stroke-linecap="round" stroke-linejoin="round"
                                        :class="filterForm.direction === 'asc' ? 'rotate-180' : ''">
                                        <path d="m6 9 6 6 6-6" />
                                    </svg>
                                </button>
                            </th>
                            <th scope="col" class="min-w-40">
                                <div class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                                    Phone
                                </div>
                            </th>
                            <th scope="col" class="min-w-36">
                                <div class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                                    City
                                </div>
                            </th>
                            <th scope="col" class="min-w-28">
                                <button type="button" @click="toggleSort('quotes_count')"
                                    class="px-5 py-2.5 text-start w-full flex items-center gap-x-1 text-sm font-normal text-stone-500 hover:text-stone-700 focus:outline-none dark:text-neutral-500 dark:hover:text-neutral-300">
                                    Quotes
                                    <svg v-if="filterForm.sort === 'quotes_count'" class="size-3" xmlns="http://www.w3.org/2000/svg"
                                        viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                        stroke-linecap="round" stroke-linejoin="round"
                                        :class="filterForm.direction === 'asc' ? 'rotate-180' : ''">
                                        <path d="m6 9 6 6 6-6" />
                                    </svg>
                                </button>
                            </th>
                            <th scope="col" class="min-w-28">
                                <button type="button" @click="toggleSort('works_count')"
                                    class="px-5 py-2.5 text-start w-full flex items-center gap-x-1 text-sm font-normal text-stone-500 hover:text-stone-700 focus:outline-none dark:text-neutral-500 dark:hover:text-neutral-300">
                                    Jobs
                                    <svg v-if="filterForm.sort === 'works_count'" class="size-3" xmlns="http://www.w3.org/2000/svg"
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
                                    <div class="grid grid-cols-7 gap-4 animate-pulse">
                                        <div class="h-3 w-32 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                                        <div class="h-3 w-28 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                                        <div class="h-3 w-24 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                                        <div class="h-3 w-20 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                                        <div class="h-3 w-16 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                                        <div class="h-3 w-20 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                                        <div class="h-3 w-16 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                                    </div>
                                </td>
                            </tr>
                        </template>
                        <template v-else>
                        <tr v-if="!customers.data.length">
                            <td colspan="9" class="px-4 py-10 text-center text-stone-600 dark:text-neutral-300">
                                <div class="space-y-2">
                                    <div class="text-sm font-semibold text-stone-700 dark:text-neutral-200">
                                        Aucun client
                                    </div>
                                    <div class="text-xs text-stone-500 dark:text-neutral-400">
                                        Ajoutez un client pour demarrer votre base.
                                    </div>
                                    <div class="flex justify-center pt-2">
                                        <Link
                                            :href="route('customer.create')"
                                            class="inline-flex items-center rounded-sm border border-green-600 bg-green-600 px-3 py-2 text-xs font-semibold text-white hover:bg-green-700"
                                        >
                                            Ajouter un client
                                        </Link>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <tr v-for="customer in customers.data" :key="customer.id">
                            <td class="size-px whitespace-nowrap px-4 py-2">
                                <Checkbox v-if="canEdit" v-model:checked="selected" :value="customer.id" />
                            </td>
                            <td class="size-px whitespace-nowrap px-4 py-2 text-start">
                                <Link :href="route('customer.show', customer)">
                                    <div class="w-full flex items-center gap-x-3">
                                        <img class="shrink-0 size-10 rounded-sm" :src="customer.logo_url || customer.logo"
                                            alt="Customer logo">
                                        <div class="flex flex-col">
                                            <div class="flex items-center gap-2">
                                                <span class="text-sm text-stone-600 dark:text-neutral-300">
                                                    {{ customer.company_name || `${customer.first_name} ${customer.last_name}` }}
                                                </span>
                                                <span v-if="!customer.is_active"
                                                    class="inline-flex items-center rounded-full bg-stone-100 px-2 py-0.5 text-[11px] font-semibold text-stone-600 dark:bg-neutral-700 dark:text-neutral-300">
                                                    Archived
                                                </span>
                                            </div>
                                            <span class="text-xs text-stone-500 dark:text-neutral-500">
                                                {{ customer.number }}
                                            </span>
                                        </div>
                                    </div>
                                </Link>
                            </td>
                            <td class="size-px whitespace-nowrap px-4 py-2">
                                <div class="flex flex-col">
                                    <span class="text-sm text-stone-600 dark:text-neutral-300">
                                        {{ customer.first_name }} {{ customer.last_name }}
                                    </span>
                                    <span class="text-xs text-stone-500 dark:text-neutral-500">
                                        {{ customer.email }}
                                    </span>
                                </div>
                            </td>
                            <td class="size-px whitespace-nowrap px-4 py-2">
                                <span class="text-sm text-stone-600 dark:text-neutral-300">
                                    {{ customer.phone || '-' }}
                                </span>
                            </td>
                            <td class="size-px whitespace-nowrap px-4 py-2">
                                <span class="text-sm text-stone-600 dark:text-neutral-300">
                                    {{ getCity(customer) || '-' }}
                                </span>
                            </td>
                            <td class="size-px whitespace-nowrap px-4 py-2">
                                <span
                                    class="py-1.5 px-2 inline-flex items-center gap-x-1.5 text-xs font-medium bg-stone-100 text-stone-800 rounded-full dark:bg-neutral-700 dark:text-neutral-200">
                                    {{ customer.quotes_count ?? 0 }}
                                </span>
                            </td>
                            <td class="size-px whitespace-nowrap px-4 py-2">
                                <span
                                    class="py-1.5 px-2 inline-flex items-center gap-x-1.5 text-xs font-medium bg-stone-100 text-stone-800 rounded-full dark:bg-neutral-700 dark:text-neutral-200">
                                    {{ customer.works_count ?? 0 }}
                                </span>
                            </td>
                            <td class="size-px whitespace-nowrap px-4 py-2">
                                <span class="text-xs text-stone-500 dark:text-neutral-500">
                                    {{ formatDate(customer.created_at) }}
                                </span>
                            </td>
                            <td class="size-px whitespace-nowrap px-4 py-2 text-end">
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

                                    <div class="hs-dropdown-menu hs-dropdown-open:opacity-100 w-28 transition-[opacity,margin] duration opacity-0 hidden z-10 bg-white rounded-sm shadow-[0_10px_40px_10px_rgba(0,0,0,0.08)] dark:shadow-[0_10px_40px_10px_rgba(0,0,0,0.2)] dark:bg-neutral-900"
                                        role="menu" aria-orientation="vertical">
                                        <div class="p-1">
                                            <Link :href="route('customer.show', customer)"
                                                class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800">
                                                View
                                            </Link>
                                            <Link v-if="canEdit" :href="route('customer.edit', customer)"
                                                class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800">
                                                Edit
                                            </Link>
                                            <button v-if="canEdit" type="button" @click="toggleArchive(customer)"
                                                class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800 action-feedback" data-tone="warning">
                                                {{ customer.is_active ? 'Archive' : 'Restore' }}
                                            </button>
                                            <div class="my-1 border-t border-stone-200 dark:border-neutral-800"></div>
                                            <button v-if="canEdit" type="button" @click="destroyCustomer(customer)"
                                                class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-neutral-800 action-feedback" data-tone="danger">
                                                Delete
                                            </button>
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
                        <div class="flex items-center gap-3">
                            <div class="size-11 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                            <div class="flex-1 space-y-2">
                                <div class="h-3 w-3/4 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                                <div class="h-3 w-1/2 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <div class="h-3 w-full rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                            <div class="h-3 w-full rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                            <div class="h-3 w-full rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                            <div class="h-3 w-full rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <div class="h-5 w-20 rounded-full bg-stone-200 dark:bg-neutral-700"></div>
                            <div class="h-5 w-16 rounded-full bg-stone-200 dark:bg-neutral-700"></div>
                        </div>
                    </div>
                </div>
            </div>
            <div v-else-if="!customers.data.length"
                class="rounded-sm border border-dashed border-stone-200 bg-white px-4 py-10 text-center text-stone-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300">
                <div class="space-y-2">
                    <div class="text-sm font-semibold text-stone-700 dark:text-neutral-200">
                        Aucun client
                    </div>
                    <div class="text-xs text-stone-500 dark:text-neutral-400">
                        Ajoutez un client pour demarrer votre base.
                    </div>
                    <div class="flex justify-center pt-2">
                        <Link
                            :href="route('customer.create')"
                            class="inline-flex items-center rounded-sm border border-green-600 bg-green-600 px-3 py-2 text-xs font-semibold text-white hover:bg-green-700"
                        >
                            Ajouter un client
                        </Link>
                    </div>
                </div>
            </div>
            <div v-else class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                <div
                    v-for="customer in customers.data"
                    :key="customer.id"
                    class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm transition-shadow hover:shadow-md dark:border-neutral-700 dark:bg-neutral-800"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex items-start gap-3 min-w-0">
                            <div class="size-11 rounded-sm border border-stone-200 bg-stone-100 text-stone-600 flex items-center justify-center text-sm font-semibold dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200">
                                <img
                                    v-if="hasCustomerLogo(customer)"
                                    class="size-11 rounded-sm object-cover"
                                    :src="customer.logo_url || customer.logo"
                                    alt="Customer logo"
                                >
                                <span v-else>{{ getCustomerInitials(customer) }}</span>
                            </div>
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <Link
                                        :href="route('customer.show', customer)"
                                        class="text-sm font-semibold text-stone-800 hover:text-emerald-700 dark:text-neutral-100 dark:hover:text-emerald-300 line-clamp-1"
                                    >
                                        {{ customer.company_name || `${customer.first_name} ${customer.last_name}` }}
                                    </Link>
                                    <span
                                        class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold"
                                        :class="customer.is_active
                                            ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-200'
                                            : 'bg-stone-100 text-stone-600 dark:bg-neutral-700 dark:text-neutral-300'"
                                    >
                                        {{ customer.is_active ? 'Active' : 'Archived' }}
                                    </span>
                                </div>
                                <div class="text-xs text-stone-500 dark:text-neutral-400">
                                    {{ customer.number || 'Client' }}
                                </div>
                                <div class="mt-1 text-[11px] text-stone-400 dark:text-neutral-500">
                                    {{ getCity(customer) || 'Unknown city' }}
                                </div>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <Checkbox v-if="canEdit" v-model:checked="selected" :value="customer.id" />
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

                                <div class="hs-dropdown-menu hs-dropdown-open:opacity-100 w-28 transition-[opacity,margin] duration opacity-0 hidden z-10 bg-white rounded-sm shadow-[0_10px_40px_10px_rgba(0,0,0,0.08)] dark:shadow-[0_10px_40px_10px_rgba(0,0,0,0.2)] dark:bg-neutral-900"
                                    role="menu" aria-orientation="vertical">
                                    <div class="p-1">
                                        <Link :href="route('customer.show', customer)"
                                            class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800">
                                            View
                                        </Link>
                                        <Link v-if="canEdit" :href="route('customer.edit', customer)"
                                            class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800">
                                            Edit
                                        </Link>
                                        <button v-if="canEdit" type="button" @click="toggleArchive(customer)"
                                            class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800 action-feedback" data-tone="warning">
                                            {{ customer.is_active ? 'Archive' : 'Restore' }}
                                        </button>
                                        <div class="my-1 border-t border-stone-200 dark:border-neutral-800"></div>
                                        <button v-if="canEdit" type="button" @click="destroyCustomer(customer)"
                                            class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-neutral-800 action-feedback" data-tone="danger">
                                            Delete
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="mt-4 grid gap-3 md:grid-cols-2 text-xs text-stone-500 dark:text-neutral-400">
                        <div class="flex items-center gap-2">
                            <svg class="size-3.5 text-stone-400 dark:text-neutral-500" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
                                <circle cx="12" cy="7" r="4" />
                            </svg>
                            <span class="text-stone-700 dark:text-neutral-200">
                                {{ customer.first_name }} {{ customer.last_name }}
                            </span>
                        </div>
                        <div class="flex items-center gap-2">
                            <svg class="size-3.5 text-stone-400 dark:text-neutral-500" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M4 4h16v16H4z" />
                                <path d="m22 6-10 7L2 6" />
                            </svg>
                            <span class="text-stone-700 dark:text-neutral-200 truncate">
                                {{ customer.email || '-' }}
                            </span>
                        </div>
                        <div class="flex items-center gap-2">
                            <svg class="size-3.5 text-stone-400 dark:text-neutral-500" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.86 19.86 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.86 19.86 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.12.81.3 1.6.54 2.37a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.71-1.11a2 2 0 0 1 2.11-.45c.77.24 1.56.42 2.37.54a2 2 0 0 1 1.72 2.03z" />
                            </svg>
                            <span class="text-stone-700 dark:text-neutral-200">
                                {{ customer.phone || '-' }}
                            </span>
                        </div>
                        <div class="flex items-center gap-2">
                            <svg class="size-3.5 text-stone-400 dark:text-neutral-500" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M12 22s8-4 8-10a8 8 0 1 0-16 0c0 6 8 10 8 10z" />
                                <circle cx="12" cy="12" r="3" />
                            </svg>
                            <span class="text-stone-700 dark:text-neutral-200">
                                {{ getCity(customer) || '-' }}
                            </span>
                        </div>
                    </div>

                    <div class="mt-4 flex flex-wrap items-center gap-2 text-xs text-stone-500 dark:text-neutral-400">
                        <span
                            class="py-1.5 px-2 inline-flex items-center gap-x-1.5 font-medium bg-stone-100 text-stone-800 rounded-full dark:bg-neutral-700 dark:text-neutral-200">
                            Quotes {{ customer.quotes_count ?? 0 }}
                        </span>
                        <span
                            class="py-1.5 px-2 inline-flex items-center gap-x-1.5 font-medium bg-stone-100 text-stone-800 rounded-full dark:bg-neutral-700 dark:text-neutral-200">
                            Jobs {{ customer.works_count ?? 0 }}
                        </span>
                        <span class="text-[11px]">
                            Created {{ formatDate(customer.created_at) }}
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <div v-if="customers.data.length > 0" class="mt-5 flex flex-wrap justify-between items-center gap-2">
            <p class="text-sm text-stone-800 dark:text-neutral-200">
                <span class="font-medium"> {{ count }} </span>
                <span class="text-stone-500 dark:text-neutral-500"> results</span>
            </p>

            <nav class="flex justify-end items-center gap-x-1" aria-label="Pagination">
                <Link :href="customers.prev_page_url" v-if="customers.prev_page_url">
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
                        aria-current="page">{{ customers.from }}</span>
                    <span
                        class="min-h-[38px] flex justify-center items-center text-stone-500 py-2 px-1.5 text-sm dark:text-neutral-500">of</span>
                    <span
                        class="min-h-[38px] flex justify-center items-center text-stone-500 py-2 px-1.5 text-sm dark:text-neutral-500">{{
                            customers.to }}</span>
                </div>

                <Link :href="customers.next_page_url" v-if="customers.next_page_url">
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
