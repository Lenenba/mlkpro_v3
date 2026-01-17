<script setup>
import { computed, ref, watch } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import StarRating from '@/Components/UI/StarRating.vue';
import { humanizeDate } from '@/utils/date';

const props = defineProps({
    quotes: {
        type: Object,
        required: true,
    },
    filters: {
        type: Object,
        default: () => ({}),
    },
    count: {
        type: Number,
        required: true,
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
    has_deposit: props.filters?.has_deposit ?? '',
    has_tax: props.filters?.has_tax ?? '',
    sort: props.filters?.sort ?? 'created_at',
    direction: props.filters?.direction ?? 'desc',
});

const { t } = useI18n();

const showAdvanced = ref(false);
const newQuoteCustomerId = ref('');
const isLoading = ref(false);
const isViewSwitching = ref(false);
const allowedViews = ['table', 'cards'];
const viewMode = ref('table');
const isBusy = computed(() => isLoading.value || isViewSwitching.value);
let viewSwitchTimeout;

if (typeof window !== 'undefined') {
    const storedView = window.localStorage.getItem('quote_view_mode');
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
        window.localStorage.setItem('quote_view_mode', mode);
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
        search: filterForm.search,
        status: filterForm.status,
        customer_id: filterForm.customer_id,
        total_min: filterForm.total_min,
        total_max: filterForm.total_max,
        created_from: filterForm.created_from,
        created_to: filterForm.created_to,
        has_deposit: filterForm.has_deposit,
        has_tax: filterForm.has_tax,
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
        router.get(route('quote.index'), filterPayload(), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onFinish: () => {
                isLoading.value = false;
            },
        });
    }, 300);
};

watch(() => filterForm.search, autoFilter);
watch(() => [
    filterForm.status,
    filterForm.customer_id,
    filterForm.total_min,
    filterForm.total_max,
    filterForm.created_from,
    filterForm.created_to,
    filterForm.has_deposit,
    filterForm.has_tax,
    filterForm.sort,
    filterForm.direction,
], autoFilter);

const clearFilters = () => {
    filterForm.search = '';
    filterForm.status = '';
    filterForm.customer_id = '';
    filterForm.total_min = '';
    filterForm.total_max = '';
    filterForm.created_from = '';
    filterForm.created_to = '';
    filterForm.has_deposit = '';
    filterForm.has_tax = '';
    filterForm.sort = 'created_at';
    filterForm.direction = 'desc';
    autoFilter();
};

const formatCurrency = (value) =>
    `$${Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

const formatDate = (value) => humanizeDate(value);

const displayCustomer = (customer) =>
    customer?.company_name ||
    `${customer?.first_name || ''} ${customer?.last_name || ''}`.trim() ||
    t('quotes.labels.unknown_customer');

const isArchived = (quote) => Boolean(quote?.archived_at);
const displayStatus = (quote) => (isArchived(quote) ? 'archived' : (quote?.status || 'draft'));

const statusMeta = computed(() => ({
    draft: {
        label: t('quotes.status.draft'),
        classes: 'bg-slate-100 text-slate-700 dark:bg-slate-500/15 dark:text-slate-200',
        icon: 'draft',
        iconClass: '',
        accent: 'border-l-slate-400/80',
    },
    sent: {
        label: t('quotes.status.sent'),
        classes: 'bg-sky-100 text-sky-800 dark:bg-sky-500/15 dark:text-sky-200',
        icon: 'sent',
        iconClass: '',
        accent: 'border-l-sky-500/80',
    },
    accepted: {
        label: t('quotes.status.accepted'),
        classes: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-200',
        icon: 'accepted',
        iconClass: 'animate-micro-pop',
        accent: 'border-l-emerald-500/80',
    },
    declined: {
        label: t('quotes.status.declined'),
        classes: 'bg-rose-100 text-rose-800 dark:bg-rose-500/15 dark:text-rose-200',
        icon: 'declined',
        iconClass: '',
        accent: 'border-l-rose-500/80',
    },
    archived: {
        label: t('quotes.status.archived'),
        classes: 'bg-stone-100 text-stone-700 dark:bg-neutral-700 dark:text-neutral-300',
        icon: 'archived',
        iconClass: '',
        accent: 'border-l-stone-300',
    },
}));

const getStatusMeta = (quote) => {
    const status = displayStatus(quote);
    return statusMeta.value[status] || statusMeta.value.draft;
};

const dispatchDemoEvent = (eventName) => {
    if (typeof window === 'undefined') {
        return;
    }
    window.dispatchEvent(new CustomEvent(eventName));
};

const toggleSort = (column) => {
    if (filterForm.sort === column) {
        filterForm.direction = filterForm.direction === 'asc' ? 'desc' : 'asc';
        return;
    }
    filterForm.sort = column;
    filterForm.direction = 'asc';
};

const sendEmail = (quote) => {
    router.post(route('quote.send.email', quote), {}, {
        preserveScroll: true,
        onSuccess: () => {
            dispatchDemoEvent('demo:quote_sent');
        },
    });
};

const acceptQuote = (quote) => {
    router.post(route('quote.accept', quote), {}, {
        preserveScroll: true,
        onSuccess: () => {
            dispatchDemoEvent('demo:quote_accepted');
        },
    });
};

const archiveQuote = (quote) => {
    if (!confirm(t('quotes.actions.archive_confirm', { number: quote.number || '' }))) {
        return;
    }
    router.delete(route('customer.quote.destroy', quote), { preserveScroll: true });
};

const restoreQuote = (quote) => {
    router.post(route('customer.quote.restore', quote), {}, { preserveScroll: true });
};

const convertToJob = (quote) => {
    router.post(route('quote.convert', quote), {}, {
        preserveScroll: true,
        onSuccess: () => {
            dispatchDemoEvent('demo:quote_converted');
        },
    });
};

const startQuote = () => {
    if (!newQuoteCustomerId.value) {
        return;
    }
    router.get(route('customer.quote.create', newQuoteCustomerId.value));
};
</script>

<template>
    <div
        class="p-5 space-y-4 flex flex-col border-t-4 border-t-sky-600 bg-white border border-stone-200 shadow-sm rounded-sm dark:bg-neutral-800 dark:border-neutral-700">
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
                        <input type="text" v-model="filterForm.search" data-testid="demo-quote-search"
                            class="py-[7px] ps-10 pe-8 block w-full bg-white border border-stone-200 rounded-sm text-sm placeholder:text-stone-500 focus:border-green-500 focus:ring-green-600 disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200 dark:placeholder:text-neutral-400 dark:focus:ring-neutral-600"
                            :placeholder="$t('quotes.filters.search_placeholder')">
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
                            {{ $t('quotes.view.table') }}
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
                            {{ $t('quotes.view.cards') }}
                        </button>
                    </div>
                    <select v-model="filterForm.status"
                        class="py-2 ps-3 pe-8 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200 dark:focus:ring-neutral-600">
                        <option value="">{{ $t('quotes.filters.status.all') }}</option>
                        <option value="draft">{{ $t('quotes.status.draft') }}</option>
                        <option value="sent">{{ $t('quotes.status.sent') }}</option>
                        <option value="accepted">{{ $t('quotes.status.accepted') }}</option>
                        <option value="declined">{{ $t('quotes.status.declined') }}</option>
                        <option value="archived">{{ $t('quotes.status.archived') }}</option>
                    </select>

                    <select v-model="filterForm.customer_id"
                        class="py-2 ps-3 pe-8 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200 dark:focus:ring-neutral-600">
                        <option value="">{{ $t('quotes.filters.customer.all') }}</option>
                        <option v-for="customer in customers" :key="customer.id" :value="customer.id">
                            {{ customer.company_name || `${customer.first_name || ''} ${customer.last_name || ''}`.trim() }}
                        </option>
                    </select>

                    <button type="button" @click="showAdvanced = !showAdvanced"
                        class="py-2 px-2.5 inline-flex items-center gap-x-1.5 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 focus:outline-none focus:ring-2 focus:ring-green-500 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-700">
                        {{ $t('quotes.actions.filters') }}
                    </button>
                    <button type="button" @click="clearFilters"
                        class="py-2 px-3 inline-flex items-center gap-x-1.5 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 focus:outline-none focus:ring-2 focus:ring-green-500 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-700">
                        {{ $t('quotes.actions.clear') }}
                    </button>

                    <div class="flex items-center gap-2">
                        <select v-model="newQuoteCustomerId"
                            class="py-2 ps-3 pe-8 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200 dark:focus:ring-neutral-600">
                            <option value="">{{ $t('quotes.actions.new_quote_for') }}</option>
                            <option v-for="customer in customers" :key="customer.id" :value="customer.id">
                                {{ customer.company_name || `${customer.first_name || ''} ${customer.last_name || ''}`.trim() }}
                            </option>
                        </select>
                        <button type="button" @click="startQuote" :disabled="!newQuoteCustomerId"
                            class="py-2 px-2.5 inline-flex items-center gap-x-1.5 text-xs font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:ring-2 focus:ring-green-500">
                            {{ $t('quotes.actions.new_quote') }}
                        </button>
                    </div>
                </div>
            </div>

            <div v-if="showAdvanced" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-6 gap-2">
                <input type="number" step="0.01" v-model="filterForm.total_min"
                    class="py-2 px-3 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                    :placeholder="$t('quotes.filters.total_min')">
                <input type="number" step="0.01" v-model="filterForm.total_max"
                    class="py-2 px-3 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                    :placeholder="$t('quotes.filters.total_max')">
                <input type="date" v-model="filterForm.created_from"
                    class="py-2 px-3 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                    :placeholder="$t('quotes.filters.created_from')">
                <input type="date" v-model="filterForm.created_to"
                    class="py-2 px-3 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                    :placeholder="$t('quotes.filters.created_to')">
                <select v-model="filterForm.has_deposit"
                    class="py-2 ps-3 pe-8 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200 dark:focus:ring-neutral-600">
                    <option value="">{{ $t('quotes.filters.deposit.label') }}</option>
                    <option value="1">{{ $t('quotes.filters.deposit.with') }}</option>
                    <option value="0">{{ $t('quotes.filters.deposit.none') }}</option>
                </select>
                <select v-model="filterForm.has_tax"
                    class="py-2 ps-3 pe-8 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200 dark:focus:ring-neutral-600">
                    <option value="">{{ $t('quotes.filters.tax.label') }}</option>
                    <option value="1">{{ $t('quotes.filters.tax.with') }}</option>
                    <option value="0">{{ $t('quotes.filters.tax.none') }}</option>
                </select>
            </div>
        </div>

        <div v-if="viewMode === 'table'" class="overflow-x-auto">
            <table class="min-w-full divide-y divide-stone-200 dark:divide-neutral-700">
                <thead>
                    <tr>
                        <th class="px-4 py-3 text-start">
                            <button type="button" @click="toggleSort('number')"
                                class="text-sm font-medium text-stone-800 dark:text-neutral-200 flex items-center gap-x-1">
                                {{ $t('quotes.table.quote') }}
                            </button>
                        </th>
                        <th class="px-4 py-3 text-start">
                            <button type="button" @click="toggleSort('job_title')"
                                class="text-sm font-medium text-stone-800 dark:text-neutral-200 flex items-center gap-x-1">
                                {{ $t('quotes.table.job') }}
                            </button>
                        </th>
                        <th class="px-4 py-3 text-start">
                            <span class="text-sm font-medium text-stone-800 dark:text-neutral-200">
                                {{ $t('quotes.table.customer') }}
                            </span>
                        </th>
                        <th class="px-4 py-3 text-start">
                            <button type="button" @click="toggleSort('status')"
                                class="text-sm font-medium text-stone-800 dark:text-neutral-200 flex items-center gap-x-1">
                                {{ $t('quotes.table.status') }}
                            </button>
                        </th>
                        <th class="px-4 py-3 text-start">
                            <span class="text-sm font-medium text-stone-800 dark:text-neutral-200">
                                {{ $t('quotes.table.rating') }}
                            </span>
                        </th>
                        <th class="px-4 py-3 text-start">
                            <button type="button" @click="toggleSort('total')"
                                class="text-sm font-medium text-stone-800 dark:text-neutral-200 flex items-center gap-x-1">
                                {{ $t('quotes.table.total') }}
                            </button>
                        </th>
                        <th class="px-4 py-3 text-start">
                            <button type="button" @click="toggleSort('created_at')"
                                class="text-sm font-medium text-stone-800 dark:text-neutral-200 flex items-center gap-x-1">
                                {{ $t('quotes.table.created') }}
                            </button>
                        </th>
                        <th class="px-4 py-3 text-end"></th>
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
                    <tr v-for="quote in quotes.data" :key="quote.id">
                        <td class="px-4 py-3">
                            <Link :href="route('customer.quote.show', quote)"
                                class="text-sm font-semibold text-stone-800 hover:underline dark:text-neutral-200">
                                {{ quote.number || $t('quotes.labels.quote_fallback') }}
                            </Link>
                        </td>
                        <td class="px-4 py-3 text-sm text-stone-600 dark:text-neutral-300">
                            {{ quote.job_title || $t('quotes.labels.job_fallback') }}
                        </td>
                        <td class="px-4 py-3 text-sm text-stone-600 dark:text-neutral-300">
                            {{ displayCustomer(quote.customer) }}
                        </td>
                        <td class="px-4 py-3">
                            <span class="py-1.5 px-2 inline-flex items-center gap-x-1.5 text-xs font-semibold rounded-full"
                                :class="getStatusMeta(quote).classes">
                                <span class="inline-flex size-3.5 items-center justify-center" :class="getStatusMeta(quote).iconClass">
                                    <svg v-if="getStatusMeta(quote).icon === 'draft'" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                                        fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                        class="size-3.5">
                                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7z" />
                                        <path d="M14 2v6h6" />
                                        <path d="M8 13h8" />
                                    </svg>
                                    <svg v-else-if="getStatusMeta(quote).icon === 'sent'" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                                        fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                        class="size-3.5">
                                        <path d="m22 2-7 20-4-9-9-4Z" />
                                        <path d="M22 2 11 13" />
                                    </svg>
                                    <svg v-else-if="getStatusMeta(quote).icon === 'accepted'" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                                        fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                        class="size-3.5">
                                        <circle cx="12" cy="12" r="9" />
                                        <path d="m8.5 12.5 2.5 2.5 4.5-5" />
                                    </svg>
                                    <svg v-else-if="getStatusMeta(quote).icon === 'declined'" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                                        fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                        class="size-3.5">
                                        <circle cx="12" cy="12" r="9" />
                                        <path d="m15 9-6 6" />
                                        <path d="m9 9 6 6" />
                                    </svg>
                                    <svg v-else-if="getStatusMeta(quote).icon === 'archived'" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                                        fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                        class="size-3.5">
                                        <rect x="3" y="4" width="18" height="4" rx="1" />
                                        <path d="M5 8v12h14V8" />
                                        <path d="M10 12h4" />
                                    </svg>
                                </span>
                                <span>{{ getStatusMeta(quote).label }}</span>
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <StarRating :value="quote.ratings_avg_rating" icon-class="h-3.5 w-3.5" empty-label="-" />
                        </td>
                        <td class="px-4 py-3 text-sm text-stone-600 dark:text-neutral-300">
                            {{ formatCurrency(quote.total) }}
                        </td>
                        <td class="px-4 py-3 text-sm text-stone-600 dark:text-neutral-300">
                            {{ formatDate(quote.created_at) }}
                        </td>
                        <td class="px-4 py-3 text-end">
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

                                <div class="hs-dropdown-menu hs-dropdown-open:opacity-100 w-44 transition-[opacity,margin] duration opacity-0 hidden z-10 bg-white rounded-sm shadow-[0_10px_40px_10px_rgba(0,0,0,0.08)] dark:shadow-[0_10px_40px_10px_rgba(0,0,0,0.2)] dark:bg-neutral-900"
                                    role="menu" aria-orientation="vertical">
                                    <div class="p-1">
                                        <Link :href="route('customer.quote.show', quote)"
                                            class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800">
                                            {{ $t('quotes.actions.view') }}
                                        </Link>
                                        <Link v-if="!isArchived(quote) && quote.status !== 'accepted'"
                                            :href="route('customer.quote.edit', quote)"
                                            class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800">
                                            {{ $t('quotes.actions.edit') }}
                                        </Link>
                                        <div class="my-1 border-t border-stone-200 dark:border-neutral-800"></div>
                                        <button v-if="!isArchived(quote)" type="button" @click="sendEmail(quote)" data-testid="demo-quote-send"
                                            class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-blue-600 hover:bg-blue-50 dark:text-blue-400 dark:hover:bg-neutral-800 action-feedback" data-tone="info">
                                            {{ $t('quotes.actions.send_email') }}
                                        </button>
                                        <button v-if="!isArchived(quote) && quote.status !== 'accepted' && quote.status !== 'declined'" type="button" @click="acceptQuote(quote)" data-testid="demo-quote-accept"
                                            class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-emerald-600 hover:bg-emerald-50 dark:text-emerald-400 dark:hover:bg-neutral-800 action-feedback">
                                            {{ $t('quotes.actions.accept_quote') }}
                                        </button>
                                        <button v-if="!isArchived(quote)" type="button" @click="convertToJob(quote)" data-testid="demo-quote-convert"
                                            class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-emerald-600 hover:bg-emerald-50 dark:text-emerald-400 dark:hover:bg-neutral-800 action-feedback">
                                            {{ $t('quotes.actions.create_job') }}
                                        </button>
                                        <div class="my-1 border-t border-stone-200 dark:border-neutral-800"></div>
                                        <button v-if="!isArchived(quote)" type="button" @click="archiveQuote(quote)"
                                            class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-neutral-800 action-feedback" data-tone="danger">
                                            {{ $t('quotes.actions.archive') }}
                                        </button>
                                        <button v-else type="button" @click="restoreQuote(quote)"
                                            class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-emerald-600 hover:bg-emerald-50 dark:text-emerald-400 dark:hover:bg-neutral-800 action-feedback">
                                            {{ $t('quotes.actions.restore') }}
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
                        <div class="flex items-center justify-between">
                            <div class="h-4 w-20 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                            <div class="h-3 w-16 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                        </div>
                    </div>
                </div>
            </div>
            <div v-else-if="!quotes.data.length"
                class="rounded-sm border border-dashed border-stone-200 bg-white px-4 py-10 text-center text-stone-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300">
                {{ $t('quotes.empty.quotes') }}
            </div>
            <div v-else class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                <div
                    v-for="quote in quotes.data"
                    :key="quote.id"
                    class="rounded-sm border border-stone-200 border-l-4 bg-white p-4 shadow-sm transition-shadow hover:shadow-md dark:border-neutral-700 dark:bg-neutral-800"
                    :class="getStatusMeta(quote).accent"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <Link :href="route('customer.quote.show', quote)"
                                class="text-sm font-semibold text-stone-800 hover:text-emerald-700 dark:text-neutral-100 dark:hover:text-emerald-300 line-clamp-1">
                                {{ quote.number || $t('quotes.labels.quote_fallback') }}
                            </Link>
                            <div class="text-xs text-stone-500 dark:text-neutral-400">
                                {{ displayCustomer(quote.customer) }}
                            </div>
                            <div class="text-[11px] text-stone-400 dark:text-neutral-500">
                                {{ quote.job_title || $t('quotes.labels.job_fallback') }}
                            </div>
                        </div>
                        <div class="flex items-start gap-2">
                            <span class="py-1.5 px-2 inline-flex items-center gap-x-1.5 text-xs font-semibold rounded-full"
                                :class="getStatusMeta(quote).classes">
                                <span class="inline-flex size-3.5 items-center justify-center" :class="getStatusMeta(quote).iconClass">
                                    <svg v-if="getStatusMeta(quote).icon === 'draft'" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                                        fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                        class="size-3.5">
                                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7z" />
                                        <path d="M14 2v6h6" />
                                        <path d="M8 13h8" />
                                    </svg>
                                    <svg v-else-if="getStatusMeta(quote).icon === 'sent'" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                                        fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                        class="size-3.5">
                                        <path d="m22 2-7 20-4-9-9-4Z" />
                                        <path d="M22 2 11 13" />
                                    </svg>
                                    <svg v-else-if="getStatusMeta(quote).icon === 'accepted'" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                                        fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                        class="size-3.5">
                                        <circle cx="12" cy="12" r="9" />
                                        <path d="m8.5 12.5 2.5 2.5 4.5-5" />
                                    </svg>
                                    <svg v-else-if="getStatusMeta(quote).icon === 'declined'" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                                        fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                        class="size-3.5">
                                        <circle cx="12" cy="12" r="9" />
                                        <path d="m15 9-6 6" />
                                        <path d="m9 9 6 6" />
                                    </svg>
                                    <svg v-else-if="getStatusMeta(quote).icon === 'archived'" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                                        fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                        class="size-3.5">
                                        <rect x="3" y="4" width="18" height="4" rx="1" />
                                        <path d="M5 8v12h14V8" />
                                        <path d="M10 12h4" />
                                    </svg>
                                </span>
                                <span>{{ getStatusMeta(quote).label }}</span>
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

                                <div class="hs-dropdown-menu hs-dropdown-open:opacity-100 w-44 transition-[opacity,margin] duration opacity-0 hidden z-10 bg-white rounded-sm shadow-[0_10px_40px_10px_rgba(0,0,0,0.08)] dark:shadow-[0_10px_40px_10px_rgba(0,0,0,0.2)] dark:bg-neutral-900"
                                    role="menu" aria-orientation="vertical">
                                    <div class="p-1">
                                        <Link :href="route('customer.quote.show', quote)"
                                            class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800">
                                            {{ $t('quotes.actions.view') }}
                                        </Link>
                                        <Link v-if="!isArchived(quote) && quote.status !== 'accepted'"
                                            :href="route('customer.quote.edit', quote)"
                                            class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800">
                                            {{ $t('quotes.actions.edit') }}
                                        </Link>
                                        <div class="my-1 border-t border-stone-200 dark:border-neutral-800"></div>
                                        <button v-if="!isArchived(quote)" type="button" @click="sendEmail(quote)" data-testid="demo-quote-send"
                                            class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-blue-600 hover:bg-blue-50 dark:text-blue-400 dark:hover:bg-neutral-800 action-feedback" data-tone="info">
                                            {{ $t('quotes.actions.send_email') }}
                                        </button>
                                        <button v-if="!isArchived(quote) && quote.status !== 'accepted' && quote.status !== 'declined'" type="button" @click="acceptQuote(quote)" data-testid="demo-quote-accept"
                                            class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-emerald-600 hover:bg-emerald-50 dark:text-emerald-400 dark:hover:bg-neutral-800 action-feedback">
                                            {{ $t('quotes.actions.accept_quote') }}
                                        </button>
                                        <button v-if="!isArchived(quote)" type="button" @click="convertToJob(quote)" data-testid="demo-quote-convert"
                                            class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-emerald-600 hover:bg-emerald-50 dark:text-emerald-400 dark:hover:bg-neutral-800 action-feedback">
                                            {{ $t('quotes.actions.create_job') }}
                                        </button>
                                        <div class="my-1 border-t border-stone-200 dark:border-neutral-800"></div>
                                        <button v-if="!isArchived(quote)" type="button" @click="archiveQuote(quote)"
                                            class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-neutral-800 action-feedback" data-tone="danger">
                                            {{ $t('quotes.actions.archive') }}
                                        </button>
                                        <button v-else type="button" @click="restoreQuote(quote)"
                                            class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-emerald-600 hover:bg-emerald-50 dark:text-emerald-400 dark:hover:bg-neutral-800 action-feedback">
                                            {{ $t('quotes.actions.restore') }}
                                        </button>
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
                                {{ formatDate(quote.created_at) }}
                            </span>
                        </div>
                        <div class="flex items-center gap-2 justify-end">
                            <span class="text-stone-500 dark:text-neutral-400">
                                {{ $t('quotes.table.rating') }}
                            </span>
                            <StarRating :value="quote.ratings_avg_rating" icon-class="h-3.5 w-3.5" empty-label="-" />
                        </div>
                    </div>

                    <div class="mt-3 flex items-center justify-between">
                        <span class="text-xs text-stone-500 dark:text-neutral-400">
                            {{ $t('quotes.table.total') }}
                        </span>
                        <span class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                            {{ formatCurrency(quote.total) }}
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-5 flex flex-wrap justify-between items-center gap-2">
            <p class="text-sm text-stone-800 dark:text-neutral-200">
                <span class="font-medium">{{ count }}</span>
                <span class="text-stone-500 dark:text-neutral-500">{{ $t('quotes.table.results') }}</span>
            </p>

            <nav class="flex justify-end items-center gap-x-1" aria-label="Pagination">
                <Link :href="quotes.prev_page_url" v-if="quotes.prev_page_url">
                    <button type="button"
                        class="min-h-[38px] min-w-[38px] py-2 px-2.5 inline-flex justify-center items-center gap-x-2 text-sm rounded-sm text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 dark:text-white dark:hover:bg-white/10 dark:focus:bg-neutral-700"
                        :aria-label="$t('quotes.pagination.previous')">
                        <svg class="shrink-0 size-3.5" xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                            stroke-linecap="round" stroke-linejoin="round">
                            <path d="m15 18-6-6 6-6" />
                        </svg>
                        <span class="sr-only">{{ $t('quotes.pagination.previous') }}</span>
                    </button>
                </Link>
                <div class="flex items-center gap-x-1">
                    <span
                        class="min-h-[38px] min-w-[38px] flex justify-center items-center bg-stone-100 text-stone-800 py-2 px-3 text-sm rounded-sm disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-700 dark:text-white"
                        aria-current="page">{{ quotes.from }}</span>
                    <span
                        class="min-h-[38px] flex justify-center items-center text-stone-500 py-2 px-1.5 text-sm dark:text-neutral-500">
                        {{ $t('quotes.pagination.of') }}
                    </span>
                    <span
                        class="min-h-[38px] flex justify-center items-center text-stone-500 py-2 px-1.5 text-sm dark:text-neutral-500">{{
                            quotes.to }}</span>
                </div>

                <Link :href="quotes.next_page_url" v-if="quotes.next_page_url">
                    <button type="button"
                        class="min-h-[38px] min-w-[38px] py-2 px-2.5 inline-flex justify-center items-center gap-x-2 text-sm rounded-sm text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 dark:text-white dark:hover:bg-white/10 dark:focus:bg-neutral-700"
                        :aria-label="$t('quotes.pagination.next')">
                        <span class="sr-only">{{ $t('quotes.pagination.next') }}</span>
                        <svg class="shrink-0 size-3.5" xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                            stroke-linecap="round" stroke-linejoin="round">
                            <path d="m9 18 6-6-6-6" />
                        </svg>
                    </button>
                </Link>
            </nav>
        </div>
    </div>
</template>
