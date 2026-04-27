<script setup>
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AdminDataTable from '@/Components/DataTable/AdminDataTable.vue';
import AdminDataTableToolbar from '@/Components/DataTable/AdminDataTableToolbar.vue';
import AdminPaginationLinks from '@/Components/DataTable/AdminPaginationLinks.vue';
import { resolveDataTablePerPage } from '@/Components/DataTable/pagination';
import { crmButtonClass, crmSegmentedControlButtonClass, crmSegmentedControlClass } from '@/utils/crmButtonStyles';
import { humanizeDate } from '@/utils/date';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import {
    serviceRequestCustomerLabel,
    serviceRequestProspectLabel,
    serviceRequestRelationLabel,
    serviceRequestRequesterLabel,
    serviceRequestSourceLabel,
    serviceRequestStatusClass,
    serviceRequestStatusLabel,
    serviceRequestTitle,
} from '@/utils/serviceRequestPresentation';

const props = defineProps({
    serviceRequests: {
        type: Object,
        required: true,
    },
    filters: {
        type: Object,
        default: () => ({}),
    },
    stats: {
        type: Object,
        default: () => ({}),
    },
    sourceBreakdown: {
        type: Array,
        default: () => [],
    },
    relationBreakdown: {
        type: Array,
        default: () => [],
    },
    statusOptions: {
        type: Array,
        default: () => [],
    },
    sourceOptions: {
        type: Array,
        default: () => [],
    },
    perPageOptions: {
        type: Array,
        default: () => [],
    },
});

const { t } = useI18n();

const filterForm = reactive({
    search: props.filters?.search || '',
    status: props.filters?.status || '',
    source: props.filters?.source || '',
    relation: props.filters?.relation || '',
    sort: props.filters?.sort || 'submitted_at',
    direction: props.filters?.direction || 'desc',
});

const showAdvanced = ref(false);
const isLoading = ref(false);
const isViewSwitching = ref(false);
const suppressFilterWatch = ref(false);
const allowedViews = ['table', 'cards'];
const viewMode = ref('table');
let filterTimeout = null;
let viewSwitchTimeout = null;

if (typeof window !== 'undefined') {
    const storedView = window.localStorage.getItem('service_request_view_mode');
    if (allowedViews.includes(storedView)) {
        viewMode.value = storedView;
    }
}

const rows = computed(() => (Array.isArray(props.serviceRequests?.data) ? props.serviceRequests.data : []));
const requestTableRows = computed(() => (
    isLoading.value && rows.value.length === 0
        ? Array.from({ length: 6 }, (_, index) => ({ id: `service-request-skeleton-${index}`, __skeleton: true }))
        : rows.value
));
const paginationLinks = computed(() => (Array.isArray(props.serviceRequests?.links) ? props.serviceRequests.links : []));
const hasRows = computed(() => rows.value.length > 0);
const currentPerPage = computed(() => resolveDataTablePerPage(props.serviceRequests?.per_page, props.filters?.per_page));
const currentPage = computed(() => Number(props.serviceRequests?.current_page || 1));
const lastPage = computed(() => Number(props.serviceRequests?.last_page || 1));
const hasMultiplePages = computed(() => lastPage.value > 1);
const resultLabel = computed(() => t('service_requests.pagination.showing', {
    from: Number(props.serviceRequests?.from || 0),
    to: Number(props.serviceRequests?.to || 0),
    total: Number(props.serviceRequests?.total || rows.value.length || 0),
}));
const currentPageLabel = computed(() => t('service_requests.pagination.page_of', {
    page: currentPage.value,
    total: lastPage.value,
}));

const statusOptions = computed(() => [
    { value: '', label: t('service_requests.filters.all_statuses') },
    ...props.statusOptions.map((status) => ({
        value: status,
        label: serviceRequestStatusLabel(status, t),
    })),
]);

const sourceOptions = computed(() => [
    { value: '', label: t('service_requests.filters.all_sources') },
    ...props.sourceOptions.map((source) => ({
        value: source,
        label: serviceRequestSourceLabel(source, t),
    })),
]);

const relationOptions = computed(() => ([
    { value: '', label: t('service_requests.filters.all_relations') },
    { value: 'customer', label: t('service_requests.relations.customer') },
    { value: 'prospect', label: t('service_requests.relations.prospect') },
    { value: 'unlinked', label: t('service_requests.relations.unlinked') },
]));

const sortOptions = computed(() => ([
    { value: 'submitted_at', label: t('service_requests.sort.submitted_at') },
    { value: 'created_at', label: t('service_requests.sort.created_at') },
    { value: 'title', label: t('service_requests.sort.title') },
    { value: 'status', label: t('service_requests.sort.status') },
    { value: 'source', label: t('service_requests.sort.source') },
]));

const directionOptions = computed(() => ([
    { value: 'desc', label: t('service_requests.filters.newest_first') },
    { value: 'asc', label: t('service_requests.filters.oldest_first') },
]));

const statCards = computed(() => ([
    {
        key: 'total',
        label: t('service_requests.stats.total'),
        value: props.stats?.total || 0,
        tone: 'border-stone-200 bg-stone-50 text-stone-700 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200',
    },
    {
        key: 'new',
        label: t('service_requests.stats.new'),
        value: props.stats?.new || 0,
        tone: 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-300',
    },
    {
        key: 'active',
        label: t('service_requests.stats.active'),
        value: props.stats?.active || 0,
        tone: 'border-sky-200 bg-sky-50 text-sky-700 dark:border-sky-500/20 dark:bg-sky-500/10 dark:text-sky-300',
    },
    {
        key: 'resolved',
        label: t('service_requests.stats.resolved'),
        value: props.stats?.resolved || 0,
        tone: 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300',
    },
    {
        key: 'closed',
        label: t('service_requests.stats.closed'),
        value: props.stats?.closed || 0,
        tone: 'border-rose-200 bg-rose-50 text-rose-700 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-300',
    },
]));

const relationBreakdownMap = computed(() => {
    const map = new Map();

    for (const item of props.relationBreakdown || []) {
        map.set(item.relation, item.total);
    }

    return map;
});

const requestDate = (serviceRequest) => serviceRequest?.submitted_at || serviceRequest?.created_at;
const formatDate = (value) => humanizeDate(value) || '-';

const excerpt = (value, limit = 110) => {
    const normalized = String(value || '').trim();
    if (normalized.length <= limit) {
        return normalized;
    }

    return `${normalized.slice(0, limit - 1).trim()}…`;
};

const relationLinkLabel = (serviceRequest) => {
    if (serviceRequest?.customer) {
        return serviceRequestCustomerLabel(serviceRequest.customer) || t('service_requests.labels.none');
    }

    if (serviceRequest?.prospect) {
        return serviceRequestProspectLabel(serviceRequest.prospect) || t('service_requests.labels.none');
    }

    return t('service_requests.labels.none');
};

const relationHref = (serviceRequest) => {
    if (serviceRequest?.customer?.id) {
        return route('customer.show', serviceRequest.customer.id);
    }

    if (serviceRequest?.prospect?.id) {
        return route('prospects.show', serviceRequest.prospect.id);
    }

    return null;
};

const applyFilters = (overrides = {}) => {
    const payload = {
        search: filterForm.search,
        status: filterForm.status,
        source: filterForm.source,
        relation: filterForm.relation,
        sort: filterForm.sort,
        direction: filterForm.direction,
        per_page: currentPerPage.value,
        ...overrides,
    };

    Object.keys(payload).forEach((key) => {
        if (payload[key] === '' || payload[key] === null || payload[key] === undefined) {
            delete payload[key];
        }
    });

    if (Number(payload.page || 1) <= 1) {
        delete payload.page;
    }

    isLoading.value = true;
    router.get(route('service-requests.index'), payload, {
        preserveScroll: true,
        preserveState: true,
        replace: true,
        onFinish: () => {
            isLoading.value = false;
        },
    });
};

const clearFilters = () => {
    suppressFilterWatch.value = true;

    filterForm.search = '';
    filterForm.status = '';
    filterForm.source = '';
    filterForm.relation = '';
    filterForm.sort = 'submitted_at';
    filterForm.direction = 'desc';

    if (filterTimeout) {
        clearTimeout(filterTimeout);
    }

    applyFilters({ page: 1 });

    queueMicrotask(() => {
        suppressFilterWatch.value = false;
    });
};

watch(
    () => [
        filterForm.search,
        filterForm.status,
        filterForm.source,
        filterForm.relation,
        filterForm.sort,
        filterForm.direction,
    ],
    () => {
        if (suppressFilterWatch.value) {
            return;
        }

        if (filterTimeout) {
            clearTimeout(filterTimeout);
        }

        filterTimeout = setTimeout(() => applyFilters({ page: 1 }), 260);
    },
);

onBeforeUnmount(() => {
    if (filterTimeout) {
        clearTimeout(filterTimeout);
    }

    if (viewSwitchTimeout) {
        clearTimeout(viewSwitchTimeout);
    }
});

const setViewMode = (mode) => {
    if (!allowedViews.includes(mode) || viewMode.value === mode) {
        return;
    }

    viewMode.value = mode;

    if (typeof window !== 'undefined') {
        window.localStorage.setItem('service_request_view_mode', mode);
    }

    isViewSwitching.value = true;
    if (viewSwitchTimeout) {
        clearTimeout(viewSwitchTimeout);
    }
    viewSwitchTimeout = setTimeout(() => {
        isViewSwitching.value = false;
    }, 220);
};
</script>

<template>
    <Head :title="$t('service_requests.page_title')" />

    <AuthenticatedLayout>
        <div class="space-y-5 px-4 pb-6 sm:px-6 lg:px-8">
            <section class="rounded-sm border border-stone-200 border-t-4 border-t-emerald-600 bg-white p-4 shadow-sm dark:border-neutral-800 dark:bg-neutral-950">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="space-y-2">
                        <div class="text-xs font-semibold uppercase tracking-[0.24em] text-emerald-700 dark:text-emerald-300">
                            {{ $t('service_requests.eyebrow') }}
                        </div>
                        <h1 class="text-2xl font-semibold tracking-tight text-stone-900 dark:text-white">
                            {{ $t('service_requests.workspace.title') }}
                        </h1>
                        <p class="max-w-3xl text-sm leading-6 text-stone-600 dark:text-neutral-300">
                            {{ $t('service_requests.workspace.subtitle') }}
                        </p>
                    </div>

                    <div class="rounded-sm border border-stone-200 bg-stone-50 px-3 py-2 text-xs text-stone-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300">
                        <span class="font-medium">{{ $t('service_requests.stats.total') }}:</span>
                        {{ Number(props.stats?.total || 0).toLocaleString() }}
                    </div>
                </div>
            </section>

            <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-[repeat(auto-fit,minmax(11rem,1fr))]">
                <article
                    v-for="card in statCards"
                    :key="card.key"
                    class="rounded-sm border p-3 shadow-sm"
                    :class="card.tone"
                >
                    <div class="text-[11px] font-semibold uppercase tracking-[0.16em]">
                        {{ card.label }}
                    </div>
                    <div class="mt-1.5 text-xl font-semibold lg:text-2xl">
                        {{ Number(card.value || 0).toLocaleString() }}
                    </div>
                </article>
            </section>

            <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-800 dark:bg-neutral-950">
                <div class="space-y-1">
                    <h2 class="text-sm font-semibold text-stone-900 dark:text-white">
                        {{ $t('service_requests.list.title') }}
                    </h2>
                    <p class="text-xs text-stone-500 dark:text-neutral-400">
                        {{ $t('service_requests.list.subtitle') }}
                    </p>
                    <p class="text-xs text-stone-500 dark:text-neutral-400">
                        {{ resultLabel }}
                        <span v-if="hasMultiplePages" class="mx-1 text-stone-300 dark:text-neutral-600">|</span>
                        <span v-if="hasMultiplePages">{{ currentPageLabel }}</span>
                    </p>
                </div>

                <div class="mt-4">
                    <AdminDataTableToolbar
                        :show-filters="showAdvanced"
                        :show-apply="false"
                        :busy="isLoading"
                        :filters-label="$t('service_requests.actions.filters')"
                        :clear-label="$t('service_requests.actions.reset_filters')"
                        @toggle-filters="showAdvanced = !showAdvanced"
                        @apply="applyFilters({ page: 1 })"
                        @clear="clearFilters"
                    >
                        <template #search>
                            <div class="relative">
                                <div class="pointer-events-none absolute inset-y-0 start-0 z-20 flex items-center ps-3.5">
                                    <svg
                                        class="size-4 shrink-0 text-stone-500 dark:text-neutral-400"
                                        xmlns="http://www.w3.org/2000/svg"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="2"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                    >
                                        <circle cx="11" cy="11" r="8" />
                                        <path d="m21 21-4.3-4.3" />
                                    </svg>
                                </div>
                                <input
                                    v-model="filterForm.search"
                                    type="text"
                                    class="block w-full rounded-sm border border-stone-200 bg-white py-[7px] pe-8 ps-10 text-sm text-stone-700 placeholder:text-stone-500 focus:border-green-500 focus:ring-green-600 disabled:pointer-events-none disabled:opacity-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:placeholder:text-neutral-400 dark:focus:ring-neutral-600"
                                    :placeholder="$t('service_requests.filters.search_placeholder')"
                                >
                            </div>
                        </template>

                        <template #filters>
                            <FloatingSelect
                                v-model="filterForm.status"
                                :label="$t('service_requests.filters.status')"
                                :options="statusOptions"
                                option-value="value"
                                option-label="label"
                                dense
                            />
                            <FloatingSelect
                                v-model="filterForm.source"
                                :label="$t('service_requests.filters.source')"
                                :options="sourceOptions"
                                option-value="value"
                                option-label="label"
                                dense
                            />
                            <FloatingSelect
                                v-model="filterForm.relation"
                                :label="$t('service_requests.filters.relation')"
                                :options="relationOptions"
                                option-value="value"
                                option-label="label"
                                dense
                            />
                            <FloatingSelect
                                v-model="filterForm.sort"
                                :label="$t('service_requests.filters.sort')"
                                :options="sortOptions"
                                option-value="value"
                                option-label="label"
                                dense
                            />
                            <FloatingSelect
                                v-model="filterForm.direction"
                                :label="$t('service_requests.filters.direction')"
                                :options="directionOptions"
                                option-value="value"
                                option-label="label"
                                dense
                            />
                        </template>

                        <template #actions>
                            <div :class="crmSegmentedControlClass()">
                                <button
                                    type="button"
                                    @click="setViewMode('table')"
                                    :class="crmSegmentedControlButtonClass(viewMode === 'table')"
                                >
                                    <svg
                                        class="size-3.5"
                                        xmlns="http://www.w3.org/2000/svg"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="2"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                    >
                                        <path d="M3 3h18v6H3z" />
                                        <path d="M3 13h18v8H3z" />
                                    </svg>
                                    {{ $t('service_requests.view.table') }}
                                </button>
                                <button
                                    type="button"
                                    @click="setViewMode('cards')"
                                    :class="crmSegmentedControlButtonClass(viewMode === 'cards')"
                                >
                                    <svg
                                        class="size-3.5"
                                        xmlns="http://www.w3.org/2000/svg"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="2"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                    >
                                        <rect x="3" y="3" width="7" height="7" rx="1" />
                                        <rect x="14" y="3" width="7" height="7" rx="1" />
                                        <rect x="3" y="14" width="7" height="7" rx="1" />
                                        <rect x="14" y="14" width="7" height="7" rx="1" />
                                    </svg>
                                    {{ $t('service_requests.view.cards') }}
                                </button>
                            </div>

                            <Link
                                :href="route('customer.index')"
                                :class="crmButtonClass('secondary', 'toolbar')"
                            >
                                {{ $t('service_requests.actions.open_customers') }}
                            </Link>

                            <Link
                                :href="route('prospects.index')"
                                :class="crmButtonClass('secondary', 'toolbar')"
                            >
                                {{ $t('service_requests.actions.open_prospects') }}
                            </Link>
                        </template>
                    </AdminDataTableToolbar>
                </div>
            </section>

            <section class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_320px]">
                <div class="space-y-4">
                    <section
                        v-if="viewMode === 'table'"
                        class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-800 dark:bg-neutral-950"
                    >
                        <AdminDataTable
                            embedded
                            :rows="requestTableRows"
                            :links="paginationLinks"
                            :show-pagination="hasRows"
                            show-per-page
                            :per-page="currentPerPage"
                            :per-page-options="perPageOptions"
                            :loading="isLoading && !hasRows"
                        >
                            <template #head>
                                <tr class="text-left text-xs uppercase text-stone-500 dark:text-neutral-400">
                                    <th class="px-4 py-3 font-medium">{{ $t('service_requests.table.request') }}</th>
                                    <th class="px-4 py-3 font-medium">{{ $t('service_requests.table.requester') }}</th>
                                    <th class="px-4 py-3 font-medium">{{ $t('service_requests.table.relation') }}</th>
                                    <th class="px-4 py-3 font-medium">{{ $t('service_requests.table.source') }}</th>
                                    <th class="px-4 py-3 font-medium">{{ $t('service_requests.table.status') }}</th>
                                    <th class="px-4 py-3 font-medium">{{ $t('service_requests.table.submitted') }}</th>
                                    <th class="px-4 py-3 text-right font-medium">{{ $t('service_requests.table.actions') }}</th>
                                </tr>
                            </template>

                            <template #row="{ row: serviceRequest }">
                                <tr class="text-stone-700 dark:text-neutral-200">
                                    <template v-if="serviceRequest.__skeleton">
                                        <td
                                            v-for="column in 7"
                                            :key="`service-request-skeleton-cell-${serviceRequest.id}-${column}`"
                                            class="px-4 py-3"
                                        >
                                            <div class="h-3 w-full animate-pulse rounded-sm bg-stone-200 dark:bg-neutral-700" />
                                        </td>
                                    </template>

                                    <template v-else>
                                        <td class="px-4 py-3 align-top">
                                            <div class="min-w-0">
                                                <Link
                                                    :href="route('service-requests.show', serviceRequest.id)"
                                                    class="font-semibold text-stone-900 hover:text-emerald-700 dark:text-white dark:hover:text-emerald-300"
                                                >
                                                    {{ serviceRequestTitle(serviceRequest, t) }}
                                                </Link>
                                                <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                                    {{ serviceRequest.service_type || serviceRequest.request_type || $t('service_requests.labels.none') }}
                                                </div>
                                                <p
                                                    v-if="serviceRequest.description"
                                                    class="mt-2 max-w-md text-sm text-stone-600 dark:text-neutral-300"
                                                >
                                                    {{ excerpt(serviceRequest.description) }}
                                                </p>
                                            </div>
                                        </td>

                                        <td class="px-4 py-3 align-top">
                                            <div class="font-medium text-stone-800 dark:text-neutral-100">
                                                {{ serviceRequestRequesterLabel(serviceRequest, t) }}
                                            </div>
                                            <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                                {{ serviceRequest.requester_email || serviceRequest.requester_phone || '—' }}
                                            </div>
                                        </td>

                                        <td class="px-4 py-3 align-top">
                                            <div class="text-xs font-semibold uppercase tracking-[0.14em] text-stone-400">
                                                {{ serviceRequestRelationLabel(serviceRequest, t) }}
                                            </div>
                                            <div class="mt-1">
                                                <Link
                                                    v-if="relationHref(serviceRequest)"
                                                    :href="relationHref(serviceRequest)"
                                                    class="font-medium text-stone-800 hover:text-emerald-700 dark:text-neutral-100 dark:hover:text-emerald-300"
                                                >
                                                    {{ relationLinkLabel(serviceRequest) }}
                                                </Link>
                                                <span v-else class="text-sm text-stone-500 dark:text-neutral-400">
                                                    {{ relationLinkLabel(serviceRequest) }}
                                                </span>
                                            </div>
                                        </td>

                                        <td class="px-4 py-3 align-top">
                                            <div class="font-medium text-stone-800 dark:text-neutral-100">
                                                {{ serviceRequestSourceLabel(serviceRequest.source, t) }}
                                            </div>
                                            <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                                {{ serviceRequest.channel || '—' }}
                                            </div>
                                        </td>

                                        <td class="px-4 py-3 align-top">
                                            <span
                                                class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold"
                                                :class="serviceRequestStatusClass(serviceRequest.status)"
                                            >
                                                {{ serviceRequestStatusLabel(serviceRequest.status, t) }}
                                            </span>
                                        </td>

                                        <td class="px-4 py-3 align-top text-sm text-stone-600 dark:text-neutral-300">
                                            {{ formatDate(requestDate(serviceRequest)) }}
                                        </td>

                                        <td class="px-4 py-3 align-top">
                                            <div class="flex justify-end">
                                                <Link
                                                    :href="route('service-requests.show', serviceRequest.id)"
                                                    :class="crmButtonClass('secondary', 'compact')"
                                                >
                                                    {{ $t('service_requests.actions.view') }}
                                                </Link>
                                            </div>
                                        </td>
                                    </template>
                                </tr>
                            </template>

                            <template #empty>
                                <div class="px-4 py-8 text-center text-stone-500 dark:text-neutral-400">
                                    <div class="text-lg font-semibold text-stone-900 dark:text-white">
                                        {{ $t('service_requests.empty.title') }}
                                    </div>
                                    <p class="mt-2 text-sm">
                                        {{ $t('service_requests.empty.body') }}
                                    </p>
                                </div>
                            </template>

                            <template #pagination_prefix>
                                <div class="text-sm text-stone-800 dark:text-neutral-200">
                                    {{ resultLabel }}
                                    <span v-if="hasMultiplePages" class="mx-1 text-stone-300 dark:text-neutral-600">|</span>
                                    <span v-if="hasMultiplePages">{{ currentPageLabel }}</span>
                                </div>
                            </template>
                        </AdminDataTable>
                    </section>

                    <template v-else>
                        <section
                            v-if="hasRows"
                            :class="[
                                'grid gap-4 md:grid-cols-2 2xl:grid-cols-3',
                                isLoading || isViewSwitching ? 'opacity-70' : '',
                            ]"
                        >
                            <article
                                v-for="serviceRequest in rows"
                                :key="serviceRequest.id"
                                class="flex h-full flex-col rounded-sm border border-stone-200 bg-white p-4 shadow-sm transition hover:border-emerald-300 dark:border-neutral-800 dark:bg-neutral-950 dark:hover:border-emerald-500/40"
                            >
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div class="min-w-0 space-y-3">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span
                                                class="inline-flex rounded-full px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.14em]"
                                                :class="serviceRequestStatusClass(serviceRequest.status)"
                                            >
                                                {{ serviceRequestStatusLabel(serviceRequest.status, t) }}
                                            </span>
                                            <span class="inline-flex rounded-full border border-stone-200 bg-stone-50 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.14em] text-stone-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300">
                                                {{ serviceRequestSourceLabel(serviceRequest.source, t) }}
                                            </span>
                                            <span class="inline-flex rounded-full border border-stone-200 bg-white px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.14em] text-stone-600 dark:border-neutral-700 dark:bg-neutral-950 dark:text-neutral-300">
                                                {{ serviceRequestRelationLabel(serviceRequest, t) }}
                                            </span>
                                        </div>

                                        <div class="min-w-0">
                                            <Link
                                                :href="route('service-requests.show', serviceRequest.id)"
                                                class="block truncate text-lg font-semibold text-stone-900 hover:text-emerald-700 dark:text-white dark:hover:text-emerald-300"
                                            >
                                                {{ serviceRequestTitle(serviceRequest, t) }}
                                            </Link>
                                            <div class="mt-1 text-sm text-stone-500 dark:text-neutral-400">
                                                {{ serviceRequestRequesterLabel(serviceRequest, t) }}
                                            </div>
                                            <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                                {{ formatDate(requestDate(serviceRequest)) }}
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <p
                                    v-if="serviceRequest.description"
                                    class="mt-4 text-sm leading-6 text-stone-600 dark:text-neutral-300"
                                >
                                    {{ excerpt(serviceRequest.description, 180) }}
                                </p>

                                <div class="mt-4 grid gap-2 sm:grid-cols-2">
                                    <div class="rounded-sm border border-stone-200 bg-stone-50 px-3 py-2 dark:border-neutral-800 dark:bg-neutral-900">
                                        <div class="text-[11px] font-semibold uppercase tracking-[0.14em] text-stone-500 dark:text-neutral-500">
                                            {{ $t('service_requests.list.customer') }}
                                        </div>
                                        <div class="mt-1 text-sm text-stone-800 dark:text-neutral-100">
                                            {{ serviceRequest.customer ? serviceRequestCustomerLabel(serviceRequest.customer) : $t('service_requests.labels.none') }}
                                        </div>
                                    </div>

                                    <div class="rounded-sm border border-stone-200 bg-stone-50 px-3 py-2 dark:border-neutral-800 dark:bg-neutral-900">
                                        <div class="text-[11px] font-semibold uppercase tracking-[0.14em] text-stone-500 dark:text-neutral-500">
                                            {{ $t('service_requests.list.prospect') }}
                                        </div>
                                        <div class="mt-1 text-sm text-stone-800 dark:text-neutral-100">
                                            {{ serviceRequest.prospect ? serviceRequestProspectLabel(serviceRequest.prospect) : $t('service_requests.labels.none') }}
                                        </div>
                                    </div>

                                    <div class="rounded-sm border border-stone-200 bg-stone-50 px-3 py-2 dark:border-neutral-800 dark:bg-neutral-900">
                                        <div class="text-[11px] font-semibold uppercase tracking-[0.14em] text-stone-500 dark:text-neutral-500">
                                            {{ $t('service_requests.list.service') }}
                                        </div>
                                        <div class="mt-1 text-sm text-stone-800 dark:text-neutral-100">
                                            {{ serviceRequest.service_type || serviceRequest.request_type || $t('service_requests.labels.none') }}
                                        </div>
                                    </div>

                                    <div class="rounded-sm border border-stone-200 bg-stone-50 px-3 py-2 dark:border-neutral-800 dark:bg-neutral-900">
                                        <div class="text-[11px] font-semibold uppercase tracking-[0.14em] text-stone-500 dark:text-neutral-500">
                                            {{ $t('service_requests.table.source') }}
                                        </div>
                                        <div class="mt-1 text-sm text-stone-800 dark:text-neutral-100">
                                            {{ serviceRequest.channel || serviceRequest.source_ref || '—' }}
                                        </div>
                                    </div>
                                </div>

                                <div class="mt-auto flex flex-wrap items-center justify-end gap-2 pt-4">
                                    <Link
                                        v-if="serviceRequest.customer"
                                        :href="route('customer.show', serviceRequest.customer.id)"
                                        :class="crmButtonClass('secondary', 'compact')"
                                    >
                                        {{ $t('service_requests.actions.open_customer') }}
                                    </Link>
                                    <Link
                                        v-if="!serviceRequest.customer && serviceRequest.prospect"
                                        :href="route('prospects.show', serviceRequest.prospect.id)"
                                        :class="crmButtonClass('secondary', 'compact')"
                                    >
                                        {{ $t('service_requests.actions.open_prospect') }}
                                    </Link>
                                    <Link
                                        :href="route('service-requests.show', serviceRequest.id)"
                                        :class="crmButtonClass('primary', 'compact')"
                                    >
                                        {{ $t('service_requests.actions.view') }}
                                    </Link>
                                </div>
                            </article>
                        </section>

                        <section
                            v-else
                            class="rounded-sm border border-dashed border-stone-300 bg-white px-6 py-12 text-center shadow-sm dark:border-neutral-700 dark:bg-neutral-950"
                        >
                            <div class="text-lg font-semibold text-stone-900 dark:text-white">
                                {{ $t('service_requests.empty.title') }}
                            </div>
                            <p class="mt-2 text-sm text-stone-500 dark:text-neutral-400">
                                {{ $t('service_requests.empty.body') }}
                            </p>
                        </section>

                        <section
                            v-if="hasRows && hasMultiplePages"
                            class="rounded-sm border border-stone-200 bg-white px-4 py-3 shadow-sm dark:border-neutral-800 dark:bg-neutral-950"
                        >
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <div class="text-sm text-stone-600 dark:text-neutral-300">
                                    {{ resultLabel }}
                                </div>
                                <div class="flex flex-wrap items-center gap-3">
                                    <span class="text-xs text-stone-500 dark:text-neutral-400">{{ currentPageLabel }}</span>
                                    <AdminPaginationLinks :links="paginationLinks" />
                                </div>
                            </div>
                        </section>
                    </template>
                </div>

                <div class="space-y-4">
                    <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-800 dark:bg-neutral-950">
                        <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                            {{ $t('service_requests.side.relations') }}
                        </h2>
                        <div class="mt-3 space-y-3 text-sm text-stone-600 dark:text-neutral-300">
                            <div class="flex items-center justify-between">
                                <span>{{ $t('service_requests.relations.customer') }}</span>
                                <span class="font-semibold text-stone-800 dark:text-neutral-100">
                                    {{ Number(relationBreakdownMap.get('customer') || 0).toLocaleString() }}
                                </span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span>{{ $t('service_requests.relations.prospect') }}</span>
                                <span class="font-semibold text-stone-800 dark:text-neutral-100">
                                    {{ Number(relationBreakdownMap.get('prospect') || 0).toLocaleString() }}
                                </span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span>{{ $t('service_requests.relations.unlinked') }}</span>
                                <span class="font-semibold text-stone-800 dark:text-neutral-100">
                                    {{ Number(relationBreakdownMap.get('unlinked') || 0).toLocaleString() }}
                                </span>
                            </div>
                        </div>
                    </section>

                    <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-800 dark:bg-neutral-950">
                        <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                            {{ $t('service_requests.side.sources') }}
                        </h2>
                        <div class="mt-3 space-y-3">
                            <div
                                v-for="item in sourceBreakdown"
                                :key="item.source"
                                class="space-y-1"
                            >
                                <div class="flex items-center justify-between text-xs uppercase tracking-wide text-stone-400">
                                    <span>{{ serviceRequestSourceLabel(item.source, t) }}</span>
                                    <span>{{ Number(item.total || 0).toLocaleString() }}</span>
                                </div>
                                <div class="h-2 overflow-hidden rounded-full bg-stone-100 dark:bg-neutral-800">
                                    <div
                                        class="h-full rounded-full bg-emerald-500"
                                        :style="{ width: `${Math.max(8, Math.round(((item.total || 0) / Math.max(1, props.stats?.total || 0)) * 100))}%` }"
                                    />
                                </div>
                            </div>
                        </div>
                    </section>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
