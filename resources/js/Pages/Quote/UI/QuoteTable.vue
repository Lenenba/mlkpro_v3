<script setup>
import { computed, ref, watch } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AdminDataTable from '@/Components/DataTable/AdminDataTable.vue';
import AdminDataTableToolbar from '@/Components/DataTable/AdminDataTableToolbar.vue';
import AdminPaginationLinks from '@/Components/DataTable/AdminPaginationLinks.vue';
import SavedSegmentBar from '@/Components/CRM/SavedSegmentBar.vue';
import QuoteActionsMenu from '@/Pages/Quote/UI/QuoteActionsMenu.vue';
import StarRating from '@/Components/UI/StarRating.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import DatePicker from '@/Components/DatePicker.vue';
import { humanizeDate } from '@/utils/date';
import { resolveDataTablePerPage } from '@/Components/DataTable/pagination';
import { crmButtonClass, crmSegmentedControlButtonClass, crmSegmentedControlClass } from '@/utils/crmButtonStyles';
import { useCurrencyFormatter } from '@/utils/currency';
import { useAccountFeatures } from '@/Composables/useAccountFeatures';

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
    stats: {
        type: Object,
        default: () => ({}),
    },
    customers: {
        type: Array,
        default: () => [],
    },
    savedSegments: {
        type: Array,
        default: () => [],
    },
    canManageSavedSegments: {
        type: Boolean,
        default: false,
    },
});

const filterForm = useForm({
    search: props.filters?.search ?? '',
    status: props.filters?.status ?? '',
    customer_id: props.filters?.customer_id ?? '',
    queue: props.filters?.queue ?? '',
    total_min: props.filters?.total_min ?? '',
    total_max: props.filters?.total_max ?? '',
    created_from: props.filters?.created_from ?? '',
    created_to: props.filters?.created_to ?? '',
    has_deposit: props.filters?.has_deposit ?? '',
    has_tax: props.filters?.has_tax ?? '',
    sort: props.filters?.sort ?? 'recovery_priority',
    direction: props.filters?.direction ?? 'desc',
});

const { t } = useI18n();
const { hasFeature } = useAccountFeatures();

const showAdvanced = ref(false);
const newQuoteCustomerId = ref('');
const isLoading = ref(false);
const compactObject = (payload) => Object.fromEntries(
    Object.entries(payload || {}).filter(([, value]) => value !== '' && value !== null && value !== undefined)
);
const isViewSwitching = ref(false);
const processingId = ref(null);
const allowedViews = ['table', 'cards'];
const viewMode = ref('table');
const isBusy = computed(() => isLoading.value || isViewSwitching.value);
const shouldShowSavedSegments = computed(() =>
    Boolean(props.canManageSavedSegments) || (Array.isArray(props.savedSegments) && props.savedSegments.length > 0)
);
const savedSegmentFilters = computed(() => compactObject({
    status: filterForm.status,
    customer_id: filterForm.customer_id,
    queue: filterForm.queue,
    total_min: filterForm.total_min,
    total_max: filterForm.total_max,
    created_from: filterForm.created_from,
    created_to: filterForm.created_to,
    has_deposit: filterForm.has_deposit,
    has_tax: filterForm.has_tax,
}));
const savedSegmentSort = computed(() => compactObject({
    sort: filterForm.sort,
    direction: filterForm.direction,
}));
const savedSegmentSearchTerm = computed(() => String(filterForm.search || '').trim());
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
        queue: filterForm.queue,
        total_min: filterForm.total_min,
        total_max: filterForm.total_max,
        created_from: filterForm.created_from,
        created_to: filterForm.created_to,
        has_deposit: filterForm.has_deposit,
        has_tax: filterForm.has_tax,
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
        router.get(route('quote.index'), filterPayload(), {
            only: ['quotes', 'filters', 'stats', 'count', 'topQuotes'],
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
    filterForm.queue,
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
    filterForm.queue = '';
    filterForm.total_min = '';
    filterForm.total_max = '';
    filterForm.created_from = '';
    filterForm.created_to = '';
    filterForm.has_deposit = '';
    filterForm.has_tax = '';
    filterForm.sort = 'recovery_priority';
    filterForm.direction = 'desc';
    autoFilter();
};

const applySavedSegment = (segment) => {
    const filters = segment?.filters && typeof segment.filters === 'object' ? segment.filters : {};
    const sort = segment?.sort && typeof segment.sort === 'object' ? segment.sort : {};

    filterForm.search = String(segment?.search_term || '');
    filterForm.status = String(filters.status || '');
    filterForm.customer_id = String(filters.customer_id || '');
    filterForm.queue = String(filters.queue || '');
    filterForm.total_min = String(filters.total_min || '');
    filterForm.total_max = String(filters.total_max || '');
    filterForm.created_from = String(filters.created_from || '');
    filterForm.created_to = String(filters.created_to || '');
    filterForm.has_deposit = String(filters.has_deposit || '');
    filterForm.has_tax = String(filters.has_tax || '');
    filterForm.sort = String(sort.sort || 'recovery_priority');
    filterForm.direction = String(sort.direction || 'desc');
    autoFilter();
};

const { formatCurrency } = useCurrencyFormatter();

const formatDate = (value) => humanizeDate(value);
const formatAbsoluteDate = (value) => {
    if (!value) {
        return '';
    }

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return '';
    }

    return date.toLocaleString();
};

const displayCustomer = (customer) =>
    customer?.company_name ||
    `${customer?.first_name || ''} ${customer?.last_name || ''}`.trim() ||
    t('quotes.labels.unknown_customer');

const statusFilterOptions = computed(() => ([
    { value: 'draft', label: t('quotes.status.draft') },
    { value: 'sent', label: t('quotes.status.sent') },
    { value: 'accepted', label: t('quotes.status.accepted') },
    { value: 'declined', label: t('quotes.status.declined') },
    { value: 'archived', label: t('quotes.status.archived') },
]));
const customerSelectOptions = computed(() =>
    (props.customers || []).map((customer) => ({
        value: String(customer.id),
        label: displayCustomer(customer),
    }))
);
const depositFilterOptions = computed(() => ([
    { value: '1', label: t('quotes.filters.deposit.with') },
    { value: '0', label: t('quotes.filters.deposit.none') },
]));
const taxFilterOptions = computed(() => ([
    { value: '1', label: t('quotes.filters.tax.with') },
    { value: '0', label: t('quotes.filters.tax.none') },
]));
const recoveryQueueLabel = (queue) => {
    switch (queue) {
        case 'active':
            return t('quotes.recovery.queues.active');
        case 'closed':
            return t('quotes.recovery.queues.closed');
        case 'due':
            return t('quotes.recovery.queues.due');
        case 'expired':
            return t('quotes.recovery.queues.expired');
        case 'high_value':
            return t('quotes.recovery.queues.high_value');
        case 'never_followed':
            return t('quotes.recovery.queues.never_followed');
        case 'viewed_not_accepted':
            return t('quotes.recovery.queues.viewed_not_accepted');
        default:
            return queue || t('quotes.recovery.queues.unknown');
    }
};
const recoveryQueueClass = (queue) => {
    switch (queue) {
        case 'viewed_not_accepted':
            return 'bg-rose-100 text-rose-800 dark:bg-rose-500/10 dark:text-rose-300';
        case 'due':
            return 'bg-amber-100 text-amber-800 dark:bg-amber-500/10 dark:text-amber-300';
        case 'high_value':
            return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300';
        case 'never_followed':
            return 'bg-sky-100 text-sky-800 dark:bg-sky-500/10 dark:text-sky-300';
        case 'expired':
            return 'bg-orange-100 text-orange-800 dark:bg-orange-500/10 dark:text-orange-300';
        case 'active':
            return 'bg-stone-100 text-stone-700 dark:bg-neutral-700 dark:text-neutral-200';
        case 'closed':
            return 'bg-stone-200 text-stone-700 dark:bg-neutral-700 dark:text-neutral-200';
        default:
            return 'bg-stone-100 text-stone-700 dark:bg-neutral-700 dark:text-neutral-200';
    }
};
const recoveryPriorityLabel = (label) => {
    switch (label) {
        case 'urgent':
            return t('quotes.recovery.priority_labels.urgent');
        case 'high':
            return t('quotes.recovery.priority_labels.high');
        case 'medium':
            return t('quotes.recovery.priority_labels.medium');
        case 'low':
            return t('quotes.recovery.priority_labels.low');
        case 'closed':
            return t('quotes.recovery.priority_labels.closed');
        default:
            return label || t('quotes.recovery.priority_labels.unknown');
    }
};
const recoveryPriorityClass = (priority) => {
    const value = Number(priority || 0);

    if (value >= 90) {
        return 'border-rose-200 bg-rose-50 text-rose-700 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-300';
    }

    if (value >= 75) {
        return 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300';
    }

    if (value >= 50) {
        return 'border-sky-200 bg-sky-50 text-sky-700 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-300';
    }

    if (value > 0) {
        return 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300';
    }

    return 'border-stone-200 bg-stone-50 text-stone-700 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300';
};
const quoteRowClass = (quote) => {
    switch (quote?.recovery_queue) {
        case 'viewed_not_accepted':
            return 'bg-rose-50/45 dark:bg-rose-500/5';
        case 'due':
            return 'bg-amber-50/45 dark:bg-amber-500/5';
        case 'high_value':
            return 'bg-emerald-50/40 dark:bg-emerald-500/5';
        case 'never_followed':
            return 'bg-sky-50/35 dark:bg-sky-500/5';
        case 'expired':
            return 'bg-orange-50/35 dark:bg-orange-500/5';
        default:
            return '';
    }
};
const quoteAgeLabel = (quote) => {
    const ageDays = Number(quote?.quote_age_days ?? 0);

    if (!Number.isFinite(ageDays) || ageDays <= 0) {
        return null;
    }

    return t('quotes.recovery.age_days_short', { count: ageDays });
};
const isFollowUpOverdue = (quote) => {
    if (!quote?.next_follow_up_at) {
        return false;
    }

    const nextFollowUpAt = new Date(quote.next_follow_up_at);

    return !Number.isNaN(nextFollowUpAt.getTime()) && nextFollowUpAt.getTime() < Date.now();
};
const recoveryQueueOptions = computed(() => ([
    {
        id: '',
        name: t('quotes.recovery.queues.all'),
        count: Number(props.stats?.total || 0),
    },
    {
        id: 'viewed_not_accepted',
        name: recoveryQueueLabel('viewed_not_accepted'),
        count: Number(props.stats?.viewed_not_accepted || 0),
    },
    {
        id: 'due',
        name: recoveryQueueLabel('due'),
        count: Number(props.stats?.due || 0),
    },
    {
        id: 'high_value',
        name: recoveryQueueLabel('high_value'),
        count: Number(props.stats?.high_value || 0),
    },
    {
        id: 'never_followed',
        name: recoveryQueueLabel('never_followed'),
        count: Number(props.stats?.never_followed || 0),
    },
    {
        id: 'expired',
        name: recoveryQueueLabel('expired'),
        count: Number(props.stats?.expired || 0),
    },
]));
const quickFollowUpOptions = computed(() => ([
    { id: 'tomorrow', label: t('quotes.recovery.follow_up_tomorrow'), days: 1 },
    { id: 'three_days', label: t('quotes.recovery.follow_up_three_days'), days: 3 },
    { id: 'seven_days', label: t('quotes.recovery.follow_up_seven_days'), days: 7 },
]));

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
const setQueueFilter = (queue) => {
    filterForm.queue = filterForm.queue === queue ? '' : queue;
};
const buildQuickFollowUpAt = (days) => {
    const date = new Date();
    date.setHours(9, 0, 0, 0);
    date.setDate(date.getDate() + days);

    if (date.getTime() <= Date.now()) {
        date.setDate(date.getDate() + 1);
    }

    return date.toISOString();
};
const canManageRecovery = (quote) => Boolean(quote?.recovery_is_open) && !isArchived(quote);
const canCreateRecoveryTask = computed(() => hasFeature('tasks'));
const runQuickQuoteRecoveryUpdate = (quote, payload, options = {}) => {
    if (!quote?.id || processingId.value) {
        return;
    }

    processingId.value = quote.id;

    router.patch(route('quote.recovery.update', quote.id), payload, {
        preserveScroll: true,
        only: ['quotes', 'filters', 'stats', 'count', 'topQuotes', 'flash'],
        ...options,
        onFinish: (...args) => {
            processingId.value = null;
            options.onFinish?.(...args);
        },
    });
};
const setQuoteFollowUp = (quote, days) => {
    if (!canManageRecovery(quote)) {
        return;
    }

    runQuickQuoteRecoveryUpdate(quote, {
        next_follow_up_at: buildQuickFollowUpAt(days),
    });
};
const clearQuoteFollowUp = (quote) => {
    if (!quote?.next_follow_up_at || !canManageRecovery(quote)) {
        return;
    }

    runQuickQuoteRecoveryUpdate(quote, {
        next_follow_up_at: null,
    });
};
const completeQuoteFollowUp = (quote) => {
    if (!canManageRecovery(quote)) {
        return;
    }

    runQuickQuoteRecoveryUpdate(quote, {
        mark_followed_up: true,
        next_follow_up_at: null,
    });
};
const createQuoteRecoveryTask = (quote) => {
    if (!quote?.id || processingId.value || !canManageRecovery(quote) || !canCreateRecoveryTask.value) {
        return;
    }

    processingId.value = quote.id;

    router.post(route('quote.recovery.task.store', quote.id), {}, {
        preserveScroll: true,
        only: ['quotes', 'filters', 'stats', 'count', 'topQuotes', 'flash'],
        onFinish: () => {
            processingId.value = null;
        },
    });
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

const quoteRows = computed(() => props.quotes?.data || []);
const quoteTableRows = computed(() => (isBusy.value
    ? Array.from({ length: 6 }, (_, index) => ({ id: `quote-skeleton-${index}`, __skeleton: true }))
    : quoteRows.value));
const quoteLinks = computed(() => props.quotes?.links || []);
const currentPerPage = computed(() => resolveDataTablePerPage(props.quotes?.per_page, props.filters?.per_page));
const quoteResultsLabel = computed(() => `${props.count} ${t('quotes.table.results')}`);
</script>

<template>
    <div
        class="p-5 space-y-4 flex flex-col border-t-4 border-t-sky-600 bg-white border border-stone-200 shadow-sm rounded-sm dark:bg-neutral-800 dark:border-neutral-700">
        <SavedSegmentBar
            v-if="shouldShowSavedSegments"
            module="quote"
            :segments="savedSegments"
            :can-manage="canManageSavedSegments"
            :current-filters="savedSegmentFilters"
            :current-sort="savedSegmentSort"
            :current-search-term="savedSegmentSearchTerm"
            :history-href="route('crm.playbook-runs.index', { module: 'quote' })"
            :history-label="t('marketing.playbook_runs.actions.open_history')"
            i18n-prefix="quotes"
            @apply="applySavedSegment"
        />
        <AdminDataTableToolbar
            :show-filters="showAdvanced"
            :show-apply="false"
            :busy="isBusy"
            :filters-label="$t('quotes.actions.filters')"
            :clear-label="$t('quotes.actions.clear')"
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
                    <input type="text" v-model="filterForm.search" data-testid="demo-quote-search"
                        class="py-[7px] ps-10 pe-8 block w-full bg-white border border-stone-200 rounded-sm text-sm placeholder:text-stone-500 focus:border-green-500 focus:ring-green-600 disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200 dark:placeholder:text-neutral-400 dark:focus:ring-neutral-600"
                        :placeholder="$t('quotes.filters.search_placeholder')">
                </div>
            </template>

            <template #filters>
                <input type="number" step="0.01" v-model="filterForm.total_min"
                    class="py-2 px-3 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                    :placeholder="$t('quotes.filters.total_min')">
                <input type="number" step="0.01" v-model="filterForm.total_max"
                    class="py-2 px-3 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                    :placeholder="$t('quotes.filters.total_max')">
                <DatePicker v-model="filterForm.created_from" :label="$t('quotes.filters.created_from')" />
                <DatePicker v-model="filterForm.created_to" :label="$t('quotes.filters.created_to')" />
                <FloatingSelect
                    v-model="filterForm.has_deposit"
                    :label="$t('quotes.filters.deposit.label')"
                    :options="depositFilterOptions"
                    :placeholder="$t('quotes.filters.deposit.label')"
                    dense
                />
                <FloatingSelect
                    v-model="filterForm.has_tax"
                    :label="$t('quotes.filters.tax.label')"
                    :options="taxFilterOptions"
                    :placeholder="$t('quotes.filters.tax.label')"
                    dense
                />
            </template>

            <template #actions>
                <div class="flex flex-wrap items-center gap-2 justify-end">
                    <div :class="crmSegmentedControlClass()">
                        <button
                            type="button"
                            @click="setViewMode('table')"
                            :class="crmSegmentedControlButtonClass(viewMode === 'table')"
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
                            :class="crmSegmentedControlButtonClass(viewMode === 'cards')"
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
                    <FloatingSelect
                        v-model="filterForm.status"
                        :label="$t('quotes.form.status')"
                        :options="statusFilterOptions"
                        :placeholder="$t('quotes.filters.status.all')"
                        dense
                        class="min-w-[150px]"
                    />
                    <FloatingSelect
                        v-model="filterForm.customer_id"
                        :label="$t('quotes.table.customer')"
                        :options="customerSelectOptions"
                        :placeholder="$t('quotes.filters.customer.all')"
                        dense
                        class="min-w-[170px]"
                    />
                    <div class="flex items-center gap-2">
                        <FloatingSelect
                            v-model="newQuoteCustomerId"
                            :label="$t('quotes.table.customer')"
                            :options="customerSelectOptions"
                            :placeholder="$t('quotes.actions.new_quote_for')"
                            dense
                            class="min-w-[190px]"
                        />
                        <button type="button" @click="startQuote" :disabled="!newQuoteCustomerId"
                            :class="crmButtonClass('primary', 'toolbar')">
                            {{ $t('quotes.actions.new_quote') }}
                        </button>
                    </div>
                </div>
            </template>
        </AdminDataTableToolbar>

        <div class="flex flex-wrap items-center gap-2">
            <span class="text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                {{ $t('quotes.recovery.label') }}
            </span>
            <button
                v-for="queue in recoveryQueueOptions"
                :key="queue.id || 'all'"
                type="button"
                class="inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-xs font-medium transition"
                :class="filterForm.queue === queue.id
                    ? `${recoveryQueueClass(queue.id || 'active')} border-transparent`
                    : 'border-stone-200 bg-white text-stone-600 hover:border-stone-300 hover:text-stone-800 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300 dark:hover:text-neutral-100'"
                :data-testid="`quote-queue-filter-${queue.id || 'all'}`"
                @click="setQueueFilter(queue.id)"
            >
                <span>{{ queue.name }}</span>
                <span class="rounded-full bg-white/70 px-1.5 py-0.5 text-[11px] font-semibold text-current dark:bg-neutral-950/30">
                    {{ queue.count }}
                </span>
            </button>
        </div>

        <AdminDataTable
            v-if="viewMode === 'table'"
            embedded
            :rows="quoteTableRows"
            :links="quoteLinks"
            :show-pagination="quoteRows.length > 0"
            show-per-page
            :per-page="currentPerPage"
        >
            <template #empty>
                <div
                    class="rounded-sm border border-dashed border-stone-200 bg-white px-4 py-10 text-center text-stone-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300"
                >
                    {{ $t('quotes.empty.quotes') }}
                </div>
            </template>

            <template #head>
                <tr>
                    <th class="px-4 py-3 text-start">
                        <button type="button" @click="toggleSort('number')"
                            class="flex items-center gap-x-1 text-sm font-medium text-stone-800 dark:text-neutral-200">
                            {{ $t('quotes.table.quote') }}
                        </button>
                    </th>
                    <th class="px-4 py-3 text-start">
                        <button type="button" @click="toggleSort('job_title')"
                            class="flex items-center gap-x-1 text-sm font-medium text-stone-800 dark:text-neutral-200">
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
                            class="flex items-center gap-x-1 text-sm font-medium text-stone-800 dark:text-neutral-200">
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
                            class="flex items-center gap-x-1 text-sm font-medium text-stone-800 dark:text-neutral-200">
                            {{ $t('quotes.table.total') }}
                        </button>
                    </th>
                    <th class="px-4 py-3 text-start">
                        <button type="button" @click="toggleSort('created_at')"
                            class="flex items-center gap-x-1 text-sm font-medium text-stone-800 dark:text-neutral-200">
                            {{ $t('quotes.table.created') }}
                        </button>
                    </th>
                    <th class="px-4 py-3 text-end"></th>
                </tr>
            </template>

            <template #row="{ row: quote }">
                <tr v-if="quote.__skeleton">
                    <td colspan="8" class="px-4 py-3">
                        <div class="grid animate-pulse grid-cols-6 gap-4">
                            <div class="h-3 w-32 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                            <div class="h-3 w-28 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                            <div class="h-3 w-24 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                            <div class="h-3 w-20 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                            <div class="h-3 w-16 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                            <div class="h-3 w-20 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                        </div>
                    </td>
                </tr>
                <tr v-else :class="quoteRowClass(quote)" :data-testid="`quote-row-${quote.id}`">
                    <td class="px-4 py-3">
                        <Link :href="route('customer.quote.show', quote)"
                            class="text-sm font-semibold text-stone-800 hover:underline dark:text-neutral-200">
                            {{ quote.number || $t('quotes.labels.quote_fallback') }}
                        </Link>
                    </td>
                    <td class="px-4 py-3 text-sm text-stone-600 dark:text-neutral-300">
                        <div class="text-sm text-stone-700 dark:text-neutral-200">
                            {{ quote.job_title || $t('quotes.labels.job_fallback') }}
                        </div>
                        <div class="mt-2 flex flex-wrap items-center gap-1.5">
                            <span
                                class="inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-medium"
                                :class="recoveryPriorityClass(quote.recovery_priority)"
                                :data-testid="`quote-priority-${quote.id}`"
                            >
                                {{ $t('quotes.recovery.priority_short', { value: quote.recovery_priority || 0 }) }}
                            </span>
                            <span
                                class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium bg-stone-100 text-stone-700 dark:bg-neutral-700 dark:text-neutral-200"
                            >
                                {{ recoveryPriorityLabel(quote.recovery_priority_label) }}
                            </span>
                            <span
                                v-if="quoteAgeLabel(quote)"
                                class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium bg-stone-100 text-stone-700 dark:bg-neutral-700 dark:text-neutral-200"
                            >
                                {{ quoteAgeLabel(quote) }}
                            </span>
                        </div>
                    </td>
                    <td class="px-4 py-3 text-sm text-stone-600 dark:text-neutral-300">
                        {{ displayCustomer(quote.customer) }}
                    </td>
                    <td class="px-4 py-3">
                        <div class="flex flex-col items-start gap-1.5">
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
                            <span
                                v-if="quote.recovery_queue"
                                class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium"
                                :class="recoveryQueueClass(quote.recovery_queue)"
                                :data-testid="`quote-recovery-queue-${quote.id}`"
                            >
                                {{ recoveryQueueLabel(quote.recovery_queue) }}
                            </span>
                        </div>
                    </td>
                    <td class="px-4 py-3">
                        <StarRating :value="quote.ratings_avg_rating" icon-class="h-3.5 w-3.5" empty-label="-" />
                    </td>
                    <td class="px-4 py-3 text-sm text-stone-600 dark:text-neutral-300">
                        {{ formatCurrency(quote.total) }}
                    </td>
                    <td class="px-4 py-3 text-sm text-stone-600 dark:text-neutral-300">
                        <div class="flex flex-col gap-1.5">
                            <span>{{ formatDate(quote.created_at) }}</span>
                            <span
                                v-if="quote.next_follow_up_at"
                                class="text-xs"
                                :class="isFollowUpOverdue(quote) ? 'text-rose-600 dark:text-rose-300' : 'text-stone-500 dark:text-neutral-400'"
                                :title="formatAbsoluteDate(quote.next_follow_up_at)"
                            >
                                {{ $t('quotes.recovery.next_follow_up') }} {{ formatDate(quote.next_follow_up_at) }}
                            </span>
                            <span
                                v-if="quote.last_viewed_at && quote.recovery_queue === 'viewed_not_accepted'"
                                class="text-xs text-stone-500 dark:text-neutral-400"
                                :title="formatAbsoluteDate(quote.last_viewed_at)"
                            >
                                {{ $t('quotes.recovery.last_viewed') }} {{ formatDate(quote.last_viewed_at) }}
                            </span>
                            <div v-if="canManageRecovery(quote)" class="flex flex-wrap items-center gap-1 pt-1">
                                <button
                                    v-for="preset in quickFollowUpOptions"
                                    :key="`${quote.id}-${preset.id}`"
                                    type="button"
                                    class="rounded-full border border-stone-200 bg-white px-2 py-0.5 text-[11px] font-medium text-stone-600 hover:border-emerald-200 hover:text-emerald-700 disabled:cursor-not-allowed disabled:opacity-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300 dark:hover:border-emerald-500/40 dark:hover:text-emerald-300"
                                    :disabled="processingId === quote.id"
                                    :data-testid="`quote-follow-up-${preset.id}-${quote.id}`"
                                    @click="setQuoteFollowUp(quote, preset.days)"
                                >
                                    {{ preset.label }}
                                </button>
                                <button
                                    v-if="quote.next_follow_up_at"
                                    type="button"
                                    class="rounded-full border border-transparent px-2 py-0.5 text-[11px] font-medium text-stone-500 hover:text-rose-600 disabled:cursor-not-allowed disabled:opacity-50 dark:text-neutral-400 dark:hover:text-rose-300"
                                    :disabled="processingId === quote.id"
                                    :data-testid="`quote-follow-up-clear-${quote.id}`"
                                    @click="clearQuoteFollowUp(quote)"
                                >
                                    {{ $t('quotes.actions.clear') }}
                                </button>
                            </div>
                        </div>
                    </td>
                    <td class="px-4 py-3 text-end">
                        <QuoteActionsMenu
                            :quote="quote"
                            :archived="isArchived(quote)"
                            @send-email="sendEmail(quote)"
                            @accept="acceptQuote(quote)"
                            @convert="convertToJob(quote)"
                            @archive="archiveQuote(quote)"
                            @restore="restoreQuote(quote)"
                        />
                        <div class="mt-2 flex flex-wrap items-center justify-end gap-1">
                            <button
                                v-if="canManageRecovery(quote)"
                                type="button"
                                class="rounded-full border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-[11px] font-semibold text-emerald-700 hover:bg-emerald-100 disabled:cursor-not-allowed disabled:opacity-50 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300 dark:hover:bg-emerald-500/20"
                                :disabled="processingId === quote.id"
                                :data-testid="`quote-follow-up-done-${quote.id}`"
                                @click="completeQuoteFollowUp(quote)"
                            >
                                {{ $t('quotes.recovery.mark_done') }}
                            </button>
                            <button
                                v-if="canManageRecovery(quote) && canCreateRecoveryTask"
                                type="button"
                                class="rounded-full border border-sky-200 bg-sky-50 px-2 py-0.5 text-[11px] font-semibold text-sky-700 hover:bg-sky-100 disabled:cursor-not-allowed disabled:opacity-50 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-300 dark:hover:bg-sky-500/20"
                                :disabled="processingId === quote.id"
                                :data-testid="`quote-follow-up-task-${quote.id}`"
                                @click="createQuoteRecoveryTask(quote)"
                            >
                                {{ $t('quotes.recovery.create_task') }}
                            </button>
                            <button
                                v-if="!isArchived(quote)"
                                type="button"
                                class="rounded-full border border-rose-200 bg-rose-50 px-2 py-0.5 text-[11px] font-semibold text-rose-700 hover:bg-rose-100 disabled:cursor-not-allowed disabled:opacity-50 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-300 dark:hover:bg-rose-500/20"
                                :disabled="processingId === quote.id"
                                :data-testid="`quote-archive-inline-${quote.id}`"
                                @click="archiveQuote(quote)"
                            >
                                {{ $t('quotes.actions.archive') }}
                            </button>
                        </div>
                    </td>
                </tr>
            </template>

            <template #pagination_prefix>
                <p class="text-sm text-stone-800 dark:text-neutral-200">{{ quoteResultsLabel }}</p>
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
                        <div class="flex items-center justify-between">
                            <div class="h-4 w-20 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                            <div class="h-3 w-16 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                        </div>
                    </div>
                </div>
            </div>
            <div v-else-if="!quoteRows.length"
                class="rounded-sm border border-dashed border-stone-200 bg-white px-4 py-10 text-center text-stone-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300">
                {{ $t('quotes.empty.quotes') }}
            </div>
            <div v-else class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                <div
                    v-for="quote in quoteRows"
                    :key="quote.id"
                    class="rounded-sm border border-stone-200 border-l-4 bg-white p-4 shadow-sm transition-shadow hover:shadow-md dark:border-neutral-700 dark:bg-neutral-800"
                    :class="[getStatusMeta(quote).accent, quoteRowClass(quote)]"
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
                            <QuoteActionsMenu
                                :quote="quote"
                                :archived="isArchived(quote)"
                                @send-email="sendEmail(quote)"
                                @accept="acceptQuote(quote)"
                                @convert="convertToJob(quote)"
                                @archive="archiveQuote(quote)"
                                @restore="restoreQuote(quote)"
                            />
                        </div>
                    </div>

                    <div class="mt-3 flex flex-wrap items-center gap-1.5">
                        <span
                            class="inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-medium"
                            :class="recoveryPriorityClass(quote.recovery_priority)"
                        >
                            {{ $t('quotes.recovery.priority_short', { value: quote.recovery_priority || 0 }) }}
                        </span>
                        <span
                            class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium bg-stone-100 text-stone-700 dark:bg-neutral-700 dark:text-neutral-200"
                        >
                            {{ recoveryPriorityLabel(quote.recovery_priority_label) }}
                        </span>
                        <span
                            v-if="quote.recovery_queue"
                            class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium"
                            :class="recoveryQueueClass(quote.recovery_queue)"
                        >
                            {{ recoveryQueueLabel(quote.recovery_queue) }}
                        </span>
                        <span
                            v-if="quoteAgeLabel(quote)"
                            class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium bg-stone-100 text-stone-700 dark:bg-neutral-700 dark:text-neutral-200"
                        >
                            {{ quoteAgeLabel(quote) }}
                        </span>
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

                    <div class="mt-3 flex flex-col gap-1 text-xs text-stone-500 dark:text-neutral-400">
                        <span
                            v-if="quote.next_follow_up_at"
                            :class="isFollowUpOverdue(quote) ? 'text-rose-600 dark:text-rose-300' : 'text-stone-500 dark:text-neutral-400'"
                            :title="formatAbsoluteDate(quote.next_follow_up_at)"
                        >
                            {{ $t('quotes.recovery.next_follow_up') }} {{ formatDate(quote.next_follow_up_at) }}
                        </span>
                        <span
                            v-if="quote.last_viewed_at && quote.recovery_queue === 'viewed_not_accepted'"
                            :title="formatAbsoluteDate(quote.last_viewed_at)"
                        >
                            {{ $t('quotes.recovery.last_viewed') }} {{ formatDate(quote.last_viewed_at) }}
                        </span>
                    </div>

                    <div v-if="canManageRecovery(quote)" class="mt-3 flex flex-wrap items-center gap-1">
                        <button
                            v-for="preset in quickFollowUpOptions"
                            :key="`card-${quote.id}-${preset.id}`"
                            type="button"
                            class="rounded-full border border-stone-200 bg-white px-2 py-0.5 text-[11px] font-medium text-stone-600 hover:border-emerald-200 hover:text-emerald-700 disabled:cursor-not-allowed disabled:opacity-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300 dark:hover:border-emerald-500/40 dark:hover:text-emerald-300"
                            :disabled="processingId === quote.id"
                            @click="setQuoteFollowUp(quote, preset.days)"
                        >
                            {{ preset.label }}
                        </button>
                        <button
                            type="button"
                            class="rounded-full border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-[11px] font-semibold text-emerald-700 hover:bg-emerald-100 disabled:cursor-not-allowed disabled:opacity-50 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300 dark:hover:bg-emerald-500/20"
                            :disabled="processingId === quote.id"
                            @click="completeQuoteFollowUp(quote)"
                        >
                            {{ $t('quotes.recovery.mark_done') }}
                        </button>
                        <button
                            v-if="canCreateRecoveryTask"
                            type="button"
                            class="rounded-full border border-sky-200 bg-sky-50 px-2 py-0.5 text-[11px] font-semibold text-sky-700 hover:bg-sky-100 disabled:cursor-not-allowed disabled:opacity-50 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-300 dark:hover:bg-sky-500/20"
                            :disabled="processingId === quote.id"
                            @click="createQuoteRecoveryTask(quote)"
                        >
                            {{ $t('quotes.recovery.create_task') }}
                        </button>
                        <button
                            v-if="quote.next_follow_up_at"
                            type="button"
                            class="rounded-full border border-transparent px-2 py-0.5 text-[11px] font-medium text-stone-500 hover:text-rose-600 disabled:cursor-not-allowed disabled:opacity-50 dark:text-neutral-400 dark:hover:text-rose-300"
                            :disabled="processingId === quote.id"
                            @click="clearQuoteFollowUp(quote)"
                        >
                            {{ $t('quotes.actions.clear') }}
                        </button>
                        <button
                            v-if="!isArchived(quote)"
                            type="button"
                            class="rounded-full border border-rose-200 bg-rose-50 px-2 py-0.5 text-[11px] font-semibold text-rose-700 hover:bg-rose-100 disabled:cursor-not-allowed disabled:opacity-50 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-300 dark:hover:bg-rose-500/20"
                            :disabled="processingId === quote.id"
                            @click="archiveQuote(quote)"
                        >
                            {{ $t('quotes.actions.archive') }}
                        </button>
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

        <div v-if="viewMode !== 'table' && quoteRows.length > 0" class="mt-5 flex flex-wrap items-center justify-between gap-2">
            <p class="text-sm text-stone-800 dark:text-neutral-200">{{ quoteResultsLabel }}</p>

            <AdminPaginationLinks :links="quoteLinks" />
        </div>
    </div>
</template>
