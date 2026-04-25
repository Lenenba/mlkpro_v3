<script setup>
import { computed, reactive, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AdminPaginationLinks from '@/Components/DataTable/AdminPaginationLinks.vue';
import { resolveDataTablePerPage } from '@/Components/DataTable/pagination';
import { humanizeDate } from '@/utils/date';
import {
    serviceRequestCustomerLabel,
    serviceRequestRelationLabel,
    serviceRequestRequesterLabel,
    serviceRequestSourceLabel,
    serviceRequestStatusClass,
    serviceRequestStatusLabel,
    serviceRequestTitle,
} from '@/utils/serviceRequestPresentation';
import { useI18n } from 'vue-i18n';

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
    per_page: Number(resolveDataTablePerPage(props.serviceRequests?.per_page, props.filters?.per_page)),
});

const rows = computed(() => Array.isArray(props.serviceRequests?.data) ? props.serviceRequests.data : []);
const paginationLinks = computed(() => Array.isArray(props.serviceRequests?.links) ? props.serviceRequests.links : []);
const hasRows = computed(() => rows.value.length > 0);

const statusOptions = computed(() => [
    { id: '', label: t('service_requests.filters.all_statuses') },
    ...props.statusOptions.map((status) => ({
        id: status,
        label: serviceRequestStatusLabel(status, t),
    })),
]);

const sourceOptions = computed(() => [
    { id: '', label: t('service_requests.filters.all_sources') },
    ...props.sourceOptions.map((source) => ({
        id: source,
        label: serviceRequestSourceLabel(source, t),
    })),
]);

const relationOptions = computed(() => ([
    { id: '', label: t('service_requests.filters.all_relations') },
    { id: 'customer', label: t('service_requests.relations.customer') },
    { id: 'prospect', label: t('service_requests.relations.prospect') },
    { id: 'unlinked', label: t('service_requests.relations.unlinked') },
]));

const sortOptions = computed(() => ([
    { id: 'submitted_at', label: t('service_requests.sort.submitted_at') },
    { id: 'created_at', label: t('service_requests.sort.created_at') },
    { id: 'title', label: t('service_requests.sort.title') },
    { id: 'status', label: t('service_requests.sort.status') },
    { id: 'source', label: t('service_requests.sort.source') },
]));

const perPageOptions = computed(() => (props.perPageOptions || []).map((value) => ({
    id: Number(value),
    label: String(value),
})));

const applyFilters = () => {
    const query = {
        search: filterForm.search || undefined,
        status: filterForm.status || undefined,
        source: filterForm.source || undefined,
        relation: filterForm.relation || undefined,
        sort: filterForm.sort || undefined,
        direction: filterForm.direction || undefined,
        per_page: filterForm.per_page || undefined,
    };

    router.get(route('service-requests.index'), query, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
};

const resetFilters = () => {
    filterForm.search = '';
    filterForm.status = '';
    filterForm.source = '';
    filterForm.relation = '';
    filterForm.sort = 'submitted_at';
    filterForm.direction = 'desc';
    filterForm.per_page = Number(resolveDataTablePerPage(props.serviceRequests?.per_page, props.filters?.per_page));
    applyFilters();
};

watch(() => filterForm.per_page, () => {
    applyFilters();
});

const formatDate = (value) => humanizeDate(value);
const requestDate = (serviceRequest) => serviceRequest?.submitted_at || serviceRequest?.created_at;
const statCards = computed(() => ([
    { key: 'total', label: t('service_requests.stats.total'), value: props.stats?.total || 0, tone: 'border-stone-500' },
    { key: 'new', label: t('service_requests.stats.new'), value: props.stats?.new || 0, tone: 'border-amber-500' },
    { key: 'active', label: t('service_requests.stats.active'), value: props.stats?.active || 0, tone: 'border-sky-500' },
    { key: 'resolved', label: t('service_requests.stats.resolved'), value: props.stats?.resolved || 0, tone: 'border-emerald-500' },
    { key: 'closed', label: t('service_requests.stats.closed'), value: props.stats?.closed || 0, tone: 'border-rose-500' },
]));

const relationBreakdownMap = computed(() => {
    const map = new Map();

    for (const item of props.relationBreakdown || []) {
        map.set(item.relation, item.total);
    }

    return map;
});
</script>

<template>
    <Head :title="$t('service_requests.page_title')" />

    <AuthenticatedLayout>
        <div class="space-y-4">
            <section class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.22em] text-stone-400 dark:text-neutral-500">
                            {{ $t('service_requests.eyebrow') }}
                        </p>
                        <h1 class="mt-2 text-2xl font-semibold text-stone-800 dark:text-neutral-100">
                            {{ $t('service_requests.workspace.title') }}
                        </h1>
                        <p class="mt-1 max-w-3xl text-sm text-stone-500 dark:text-neutral-400">
                            {{ $t('service_requests.workspace.subtitle') }}
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <Link
                            :href="route('customer.index')"
                            class="inline-flex items-center gap-2 rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                        >
                            {{ $t('service_requests.actions.open_customers') }}
                        </Link>
                        <Link
                            :href="route('prospects.index')"
                            class="inline-flex items-center gap-2 rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                        >
                            {{ $t('service_requests.actions.open_prospects') }}
                        </Link>
                    </div>
                </div>
            </section>

            <section class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-5">
                <article
                    v-for="card in statCards"
                    :key="card.key"
                    class="rounded-sm border border-stone-200 border-t-4 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900"
                    :class="card.tone"
                >
                    <div class="text-xs uppercase tracking-wide text-stone-400">
                        {{ card.label }}
                    </div>
                    <div class="mt-2 text-2xl font-semibold text-stone-800 dark:text-neutral-100">
                        {{ Number(card.value || 0).toLocaleString() }}
                    </div>
                </article>
            </section>

            <section class="grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,1fr),320px]">
                <div class="space-y-4">
                    <form
                        class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900"
                        @submit.prevent="applyFilters"
                    >
                        <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-6">
                            <div class="xl:col-span-2">
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-stone-400">
                                    {{ $t('service_requests.filters.search') }}
                                </label>
                                <input
                                    v-model="filterForm.search"
                                    type="text"
                                    class="w-full rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm text-stone-700 shadow-sm outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100 dark:border-neutral-700 dark:bg-neutral-950 dark:text-neutral-100 dark:focus:ring-emerald-500/20"
                                    :placeholder="$t('service_requests.filters.search_placeholder')"
                                >
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-stone-400">
                                    {{ $t('service_requests.filters.status') }}
                                </label>
                                <select
                                    v-model="filterForm.status"
                                    class="w-full rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm text-stone-700 shadow-sm outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100 dark:border-neutral-700 dark:bg-neutral-950 dark:text-neutral-100 dark:focus:ring-emerald-500/20"
                                >
                                    <option v-for="option in statusOptions" :key="option.id || 'all-status'" :value="option.id">
                                        {{ option.label }}
                                    </option>
                                </select>
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-stone-400">
                                    {{ $t('service_requests.filters.source') }}
                                </label>
                                <select
                                    v-model="filterForm.source"
                                    class="w-full rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm text-stone-700 shadow-sm outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100 dark:border-neutral-700 dark:bg-neutral-950 dark:text-neutral-100 dark:focus:ring-emerald-500/20"
                                >
                                    <option v-for="option in sourceOptions" :key="option.id || 'all-source'" :value="option.id">
                                        {{ option.label }}
                                    </option>
                                </select>
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-stone-400">
                                    {{ $t('service_requests.filters.relation') }}
                                </label>
                                <select
                                    v-model="filterForm.relation"
                                    class="w-full rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm text-stone-700 shadow-sm outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100 dark:border-neutral-700 dark:bg-neutral-950 dark:text-neutral-100 dark:focus:ring-emerald-500/20"
                                >
                                    <option v-for="option in relationOptions" :key="option.id || 'all-relation'" :value="option.id">
                                        {{ option.label }}
                                    </option>
                                </select>
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-stone-400">
                                    {{ $t('service_requests.filters.sort') }}
                                </label>
                                <select
                                    v-model="filterForm.sort"
                                    class="w-full rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm text-stone-700 shadow-sm outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100 dark:border-neutral-700 dark:bg-neutral-950 dark:text-neutral-100 dark:focus:ring-emerald-500/20"
                                >
                                    <option v-for="option in sortOptions" :key="option.id" :value="option.id">
                                        {{ option.label }}
                                    </option>
                                </select>
                            </div>
                        </div>
                        <div class="mt-3 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                            <div class="flex flex-wrap items-center gap-2">
                                <label class="text-xs font-semibold uppercase tracking-wide text-stone-400">
                                    {{ $t('service_requests.filters.direction') }}
                                </label>
                                <button
                                    type="button"
                                    class="rounded-full border px-3 py-1 text-xs font-semibold transition"
                                    :class="filterForm.direction === 'desc' ? 'border-emerald-500 bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' : 'border-stone-200 bg-white text-stone-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300'"
                                    @click="filterForm.direction = 'desc'"
                                >
                                    {{ $t('service_requests.filters.newest_first') }}
                                </button>
                                <button
                                    type="button"
                                    class="rounded-full border px-3 py-1 text-xs font-semibold transition"
                                    :class="filterForm.direction === 'asc' ? 'border-emerald-500 bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' : 'border-stone-200 bg-white text-stone-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300'"
                                    @click="filterForm.direction = 'asc'"
                                >
                                    {{ $t('service_requests.filters.oldest_first') }}
                                </button>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <label class="text-xs font-semibold uppercase tracking-wide text-stone-400">
                                    {{ $t('service_requests.filters.per_page') }}
                                </label>
                                <select
                                    v-model="filterForm.per_page"
                                    class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm text-stone-700 shadow-sm outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100 dark:border-neutral-700 dark:bg-neutral-950 dark:text-neutral-100 dark:focus:ring-emerald-500/20"
                                >
                                    <option v-for="option in perPageOptions" :key="option.id" :value="option.id">
                                        {{ option.label }}
                                    </option>
                                </select>
                                <button
                                    type="submit"
                                    class="inline-flex items-center gap-2 rounded-sm bg-emerald-600 px-3 py-2 text-sm font-medium text-white hover:bg-emerald-700"
                                >
                                    {{ $t('service_requests.actions.apply_filters') }}
                                </button>
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-2 rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                                    @click="resetFilters"
                                >
                                    {{ $t('service_requests.actions.reset_filters') }}
                                </button>
                            </div>
                        </div>
                    </form>

                    <section v-if="hasRows" class="space-y-3">
                        <article
                            v-for="serviceRequest in rows"
                            :key="serviceRequest.id"
                            class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm transition hover:border-emerald-300 dark:border-neutral-700 dark:bg-neutral-900"
                        >
                            <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <Link
                                            :href="route('service-requests.show', serviceRequest.id)"
                                            class="text-base font-semibold text-stone-800 hover:text-emerald-700 dark:text-neutral-100 dark:hover:text-emerald-300"
                                        >
                                            {{ serviceRequestTitle(serviceRequest, t) }}
                                        </Link>
                                        <span
                                            class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold"
                                            :class="serviceRequestStatusClass(serviceRequest.status)"
                                        >
                                            {{ serviceRequestStatusLabel(serviceRequest.status, t) }}
                                        </span>
                                        <span class="inline-flex items-center rounded-full bg-stone-100 px-2 py-0.5 text-xs font-medium text-stone-700 dark:bg-neutral-800 dark:text-neutral-300">
                                            {{ serviceRequestSourceLabel(serviceRequest.source, t) }}
                                        </span>
                                    </div>
                                    <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-sm text-stone-500 dark:text-neutral-400">
                                        <span>{{ serviceRequestRequesterLabel(serviceRequest, t) }}</span>
                                        <span>{{ serviceRequestRelationLabel(serviceRequest, t) }}</span>
                                        <span>{{ formatDate(requestDate(serviceRequest)) }}</span>
                                    </div>
                                    <p v-if="serviceRequest.description" class="mt-3 text-sm text-stone-600 dark:text-neutral-300">
                                        {{ serviceRequest.description }}
                                    </p>
                                </div>

                                <div class="flex flex-wrap items-center gap-2">
                                    <Link
                                        :href="route('service-requests.show', serviceRequest.id)"
                                        class="inline-flex items-center gap-2 rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                                    >
                                        {{ $t('service_requests.actions.view') }}
                                    </Link>
                                </div>
                            </div>

                            <div class="mt-4 grid grid-cols-1 gap-3 border-t border-stone-200 pt-3 text-sm text-stone-600 dark:border-neutral-700 dark:text-neutral-300 md:grid-cols-3">
                                <div>
                                    <div class="text-xs uppercase tracking-wide text-stone-400">
                                        {{ $t('service_requests.list.customer') }}
                                    </div>
                                    <div class="mt-1">
                                        <Link
                                            v-if="serviceRequest.customer"
                                            :href="route('customer.show', serviceRequest.customer.id)"
                                            class="font-medium text-stone-800 hover:text-emerald-700 dark:text-neutral-100 dark:hover:text-emerald-300"
                                        >
                                            {{ serviceRequestCustomerLabel(serviceRequest.customer) }}
                                        </Link>
                                        <span v-else>{{ $t('service_requests.labels.none') }}</span>
                                    </div>
                                </div>
                                <div>
                                    <div class="text-xs uppercase tracking-wide text-stone-400">
                                        {{ $t('service_requests.list.prospect') }}
                                    </div>
                                    <div class="mt-1">
                                        <Link
                                            v-if="serviceRequest.prospect"
                                            :href="route('prospects.show', serviceRequest.prospect.id)"
                                            class="font-medium text-stone-800 hover:text-emerald-700 dark:text-neutral-100 dark:hover:text-emerald-300"
                                        >
                                            {{ serviceRequest.prospect.title || serviceRequest.prospect.contact_name || $t('service_requests.labels.none') }}
                                        </Link>
                                        <span v-else>{{ $t('service_requests.labels.none') }}</span>
                                    </div>
                                </div>
                                <div>
                                    <div class="text-xs uppercase tracking-wide text-stone-400">
                                        {{ $t('service_requests.list.service') }}
                                    </div>
                                    <div class="mt-1 font-medium text-stone-800 dark:text-neutral-100">
                                        {{ serviceRequest.service_type || serviceRequest.request_type || $t('service_requests.labels.none') }}
                                    </div>
                                </div>
                            </div>
                        </article>
                    </section>

                    <section
                        v-else
                        class="rounded-sm border border-dashed border-stone-300 bg-white px-6 py-12 text-center shadow-sm dark:border-neutral-700 dark:bg-neutral-900"
                    >
                        <h2 class="text-lg font-semibold text-stone-800 dark:text-neutral-100">
                            {{ $t('service_requests.empty.title') }}
                        </h2>
                        <p class="mt-2 text-sm text-stone-500 dark:text-neutral-400">
                            {{ $t('service_requests.empty.body') }}
                        </p>
                    </section>

                    <div v-if="paginationLinks.length > 3" class="rounded-sm border border-stone-200 bg-white px-4 py-3 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <AdminPaginationLinks :links="paginationLinks" />
                    </div>
                </div>

                <div class="space-y-4">
                    <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
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

                    <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
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
