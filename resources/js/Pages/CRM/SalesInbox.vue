<script setup>
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AdminPaginationLinks from '@/Components/DataTable/AdminPaginationLinks.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import AppBreadcrumbs from '@/Components/UI/AppBreadcrumbs.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { useCurrencyFormatter } from '@/utils/currency';
import { crmButtonClass } from '@/utils/crmButtonStyles';
import { humanizeDate } from '@/utils/date';

const props = defineProps({
    items: {
        type: Array,
        default: () => [],
    },
    count: {
        type: Number,
        default: 0,
    },
    stats: {
        type: Object,
        default: () => ({}),
    },
    queues: {
        type: Array,
        default: () => [],
    },
    reference_time: {
        type: String,
        default: '',
    },
    filters: {
        type: Object,
        default: () => ({}),
    },
    options: {
        type: Object,
        default: () => ({}),
    },
    pagination: {
        type: Object,
        default: () => ({}),
    },
});

const { t } = useI18n();
const { formatCurrency } = useCurrencyFormatter();
const defaultPerPage = Number(props.options?.per_page_options?.[0] || 10);

const filterForm = reactive({
    search: props.filters?.search || '',
    queue: props.filters?.queue || '',
    stage: props.filters?.stage || '',
    per_page: Number(props.filters?.per_page || defaultPerPage),
});

const isFiltering = ref(false);
const suppressFilterWatch = ref(false);
let filterTimeout = null;

const breadcrumbItems = computed(() => ([
    {
        key: 'dashboard',
        label: t('nav.dashboard'),
        href: route('dashboard'),
        icon: 'home',
    },
    {
        key: 'revenue',
        label: t('nav.revenue'),
        href: route('workspace.hubs.show', { category: 'revenue' }),
    },
    {
        key: 'sales-inbox',
        label: t('crm_sales_inbox.page_title'),
    },
]));

const displayedItems = computed(() => (Array.isArray(props.items) ? props.items : []));
const queueSummary = computed(() => (Array.isArray(props.queues) ? props.queues : []));
const paginationLinks = computed(() => (Array.isArray(props.pagination?.links) ? props.pagination.links : []));
const currentPage = computed(() => Number(props.pagination?.current_page || 1));
const lastPage = computed(() => Number(props.pagination?.last_page || 1));
const hasMultiplePages = computed(() => lastPage.value > 1);
const currentPerPage = computed(() => Number(filterForm.per_page || defaultPerPage));
const referenceTimeForFilters = computed(() => props.reference_time || props.filters?.reference_time || '');
const perPageOptions = computed(() => (
    (props.options?.per_page_options || [10, 25, 50]).map((value) => ({
        value: Number(value),
        label: String(value),
    }))
));
const queueOptions = computed(() => ([
    { value: '', label: t('crm_sales_inbox.queues.all') },
    ...((props.options?.queues || []).map((value) => ({
        value,
        label: t(`crm_sales_inbox.queues.${value}`),
    }))),
]));
const stageOptions = computed(() => ([
    { value: '', label: t('crm_sales_inbox.filters.all_stages') },
    ...((props.options?.stages || []).map((value) => ({
        value,
        label: humanizeValue(value),
    }))),
]));
const paginationSummary = computed(() => t('crm_sales_inbox.pagination.showing', {
    from: Number(props.pagination?.from || 0),
    to: Number(props.pagination?.to || 0),
    total: Number(props.pagination?.total || props.count || 0),
}));
const pageLabel = computed(() => t('crm_sales_inbox.pagination.page_of', {
    page: currentPage.value,
    total: lastPage.value,
}));
const referenceTimeLabel = computed(() => formatAbsoluteDate(props.reference_time));

const statCards = computed(() => ([
    {
        key: 'total',
        label: t('crm_sales_inbox.cards.total'),
        value: Number(props.stats?.total || 0),
        tone: 'border-stone-200 bg-stone-50 text-stone-700 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200',
    },
    {
        key: 'overdue',
        label: t('crm_sales_inbox.cards.overdue'),
        value: Number(props.stats?.overdue || 0),
        tone: 'border-rose-200 bg-rose-50 text-rose-700 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-300',
    },
    {
        key: 'no_next_action',
        label: t('crm_sales_inbox.cards.no_next_action'),
        value: Number(props.stats?.no_next_action || 0),
        tone: 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-300',
    },
    {
        key: 'quoted',
        label: t('crm_sales_inbox.cards.quoted'),
        value: Number(props.stats?.quoted || 0),
        tone: 'border-sky-200 bg-sky-50 text-sky-700 dark:border-sky-500/20 dark:bg-sky-500/10 dark:text-sky-300',
    },
    {
        key: 'needs_quote',
        label: t('crm_sales_inbox.cards.needs_quote'),
        value: Number(props.stats?.needs_quote || 0),
        tone: 'border-violet-200 bg-violet-50 text-violet-700 dark:border-violet-500/20 dark:bg-violet-500/10 dark:text-violet-300',
    },
    {
        key: 'weighted_open_amount',
        label: t('crm_sales_inbox.cards.weighted_open_amount'),
        value: formatCurrency(props.stats?.weighted_open_amount || 0),
        tone: 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300',
    },
]));

const applyFilters = (overrides = {}) => {
    const payload = {
        search: filterForm.search,
        queue: filterForm.queue,
        stage: filterForm.stage,
        per_page: currentPerPage.value,
        reference_time: referenceTimeForFilters.value,
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

    isFiltering.value = true;
    router.get(route('crm.sales-inbox.index'), payload, {
        preserveScroll: true,
        preserveState: true,
        replace: true,
        onFinish: () => {
            isFiltering.value = false;
        },
    });
};

const clearFilters = () => {
    suppressFilterWatch.value = true;

    filterForm.search = '';
    filterForm.queue = '';
    filterForm.stage = '';

    if (filterTimeout) {
        clearTimeout(filterTimeout);
    }

    applyFilters({ page: 1 });

    queueMicrotask(() => {
        suppressFilterWatch.value = false;
    });
};

const selectQueue = (queueKey) => {
    suppressFilterWatch.value = true;
    filterForm.queue = queueKey;
    applyFilters({ queue: queueKey || '', page: 1 });

    queueMicrotask(() => {
        suppressFilterWatch.value = false;
    });
};

watch(
    () => [filterForm.search, filterForm.queue, filterForm.stage],
    () => {
        if (suppressFilterWatch.value) {
            return;
        }

        if (filterTimeout) {
            clearTimeout(filterTimeout);
        }

        filterTimeout = setTimeout(() => applyFilters({ page: 1 }), 250);
    },
);

watch(
    () => filterForm.per_page,
    (value, previousValue) => {
        if (suppressFilterWatch.value || value === previousValue) {
            return;
        }

        applyFilters({ page: 1 });
    },
);

onBeforeUnmount(() => {
    if (filterTimeout) {
        clearTimeout(filterTimeout);
    }
});

const humanizeValue = (value) => String(value || '')
    .replaceAll('_', ' ')
    .trim()
    .replace(/\b\w/g, (char) => char.toUpperCase());

const itemCurrency = (item) => item?.quote?.currency_code || item?.opportunity?.amount?.currency_code || null;

const formatAmount = (value, currencyCode = null) => (
    value === null || value === undefined
        ? '-'
        : formatCurrency(value, currencyCode)
);

const formatAbsoluteDate = (value) => {
    if (!value) {
        return '-';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? '-' : date.toLocaleString();
};

const formatRelativeDate = (value) => humanizeDate(value, { now: props.reference_time }) || '-';

const queueBadgeClass = (queue) => {
    switch (String(queue || '')) {
        case 'overdue':
            return 'border-rose-200 bg-rose-50 text-rose-700 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-300';
        case 'no_next_action':
            return 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-300';
        case 'quoted':
            return 'border-sky-200 bg-sky-50 text-sky-700 dark:border-sky-500/20 dark:bg-sky-500/10 dark:text-sky-300';
        case 'needs_quote':
            return 'border-violet-200 bg-violet-50 text-violet-700 dark:border-violet-500/20 dark:bg-violet-500/10 dark:text-violet-300';
        default:
            return 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300';
    }
};

const stageBadgeClass = (stageKey) => {
    switch (String(stageKey || '')) {
        case 'quoted':
            return 'border-sky-200 bg-sky-50 text-sky-700 dark:border-sky-500/20 dark:bg-sky-500/10 dark:text-sky-300';
        case 'qualified':
            return 'border-violet-200 bg-violet-50 text-violet-700 dark:border-violet-500/20 dark:bg-violet-500/10 dark:text-violet-300';
        case 'contacted':
            return 'border-cyan-200 bg-cyan-50 text-cyan-700 dark:border-cyan-500/20 dark:bg-cyan-500/10 dark:text-cyan-300';
        default:
            return 'border-stone-200 bg-stone-50 text-stone-700 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300';
    }
};

const nextActionBadgeClass = (state) => {
    switch (String(state || '')) {
        case 'overdue':
            return 'border-rose-200 bg-rose-50 text-rose-700 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-300';
        case 'none':
            return 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-300';
        default:
            return 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300';
    }
};

const shouldShowNextActionBadge = (item) => {
    const queue = String(item?.queue || '');
    const nextActionState = String(item?.next_action_state || '');

    if (queue === 'overdue' && nextActionState === 'overdue') {
        return false;
    }

    if (queue === 'no_next_action' && nextActionState === 'none') {
        return false;
    }

    return Boolean(nextActionState);
};

const queueCardClass = (queueKey) => (
    filterForm.queue === queueKey
        ? `${queueBadgeClass(queueKey)} ring-2 ring-offset-2 ring-offset-white dark:ring-offset-neutral-950`
        : 'border-stone-200 bg-white text-stone-700 hover:border-stone-300 dark:border-neutral-800 dark:bg-neutral-950 dark:text-neutral-200 dark:hover:border-neutral-700'
);

const requestRoute = (item) => {
    const requestId = item?.crm_links?.request?.id || item?.request?.id;

    return requestId ? route('prospects.show', requestId) : null;
};

const quoteRoute = (item) => {
    const quoteId = item?.crm_links?.quote?.id || item?.quote?.id;

    return quoteId ? route('customer.quote.show', quoteId) : null;
};

const primarySubjectType = (item) => item?.crm_links?.subject?.type || item?.primary_subject_type || null;

const primaryRoute = (item) => {
    if (primarySubjectType(item) === 'quote') {
        return quoteRoute(item);
    }

    if (primarySubjectType(item) === 'request') {
        return requestRoute(item);
    }

    return quoteRoute(item) || requestRoute(item);
};

const pipelineRoute = (item) => {
    const subjectType = item?.crm_links?.subject?.type;
    const subjectId = item?.crm_links?.subject?.id;

    if ((subjectType === 'quote' || subjectType === 'request') && subjectId) {
        return route('pipeline.timeline', { entityType: subjectType, entityId: subjectId });
    }

    if (item?.quote?.id) {
        return route('pipeline.timeline', { entityType: 'quote', entityId: item.quote.id });
    }

    if (item?.request?.id) {
        return route('pipeline.timeline', { entityType: 'request', entityId: item.request.id });
    }

    return null;
};

const customerRoute = (item) => (
    item?.customer?.id ? route('customer.show', item.customer.id) : null
);

const primaryActionLabel = (item) => (
    primarySubjectType(item) === 'quote'
        ? t('crm_sales_inbox.actions.open_quote')
        : t('crm_sales_inbox.actions.open_request')
);

const hasSecondaryRequestAction = (item) => Boolean(requestRoute(item) && requestRoute(item) !== primaryRoute(item));

const hasSecondaryQuoteAction = (item) => Boolean(quoteRoute(item) && quoteRoute(item) !== primaryRoute(item));

const quoteOrServiceLabel = (item) => {
    if (item?.quote?.number) {
        return t('crm_sales_inbox.labels.quote', { number: item.quote.number });
    }

    return item?.request?.service_type || '-';
};

const openedLabel = (item) => {
    if (item?.age_days === null || item?.age_days === undefined) {
        return formatAbsoluteDate(item?.opened_at);
    }

    return `${formatAbsoluteDate(item?.opened_at)} · ${t('crm_sales_inbox.labels.days_old', { count: item.age_days })}`;
};
</script>

<template>
    <Head :title="t('crm_sales_inbox.page_title')" />

    <AuthenticatedLayout>
        <template #breadcrumb>
            <div class="px-4 pt-6 sm:px-6 lg:px-8">
                <AppBreadcrumbs :items="breadcrumbItems" />
            </div>
        </template>

        <div class="space-y-5 px-4 pb-6 sm:px-6 lg:px-8">
            <section class="rounded-sm border border-stone-200 border-t-4 border-t-sky-600 bg-white p-4 shadow-sm dark:border-neutral-800 dark:bg-neutral-950">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="space-y-2">
                        <div class="text-xs font-semibold uppercase tracking-[0.24em] text-sky-700 dark:text-sky-300">
                            {{ t('crm_sales_inbox.eyebrow') }}
                        </div>
                        <h1 class="text-2xl font-semibold tracking-tight text-stone-900 dark:text-white">
                            {{ t('crm_sales_inbox.page_title') }}
                        </h1>
                        <p class="max-w-2xl text-sm leading-6 text-stone-600 dark:text-neutral-300">
                            {{ t('crm_sales_inbox.page_description') }}
                        </p>
                    </div>

                    <div class="rounded-sm border border-stone-200 bg-stone-50 px-3 py-2 text-xs text-stone-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300">
                        <span class="font-medium">{{ t('crm_sales_inbox.reference_time') }}:</span>
                        {{ referenceTimeLabel }}
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
                    <div class="text-[11px] font-semibold uppercase tracking-[0.14em] leading-4">
                        {{ card.label }}
                    </div>
                    <div class="mt-1.5 text-xl font-semibold lg:text-2xl">
                        {{ card.value }}
                    </div>
                </article>
            </section>

            <section class="rounded-sm border border-stone-200 bg-white p-3.5 shadow-sm dark:border-neutral-800 dark:bg-neutral-950">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="space-y-1">
                        <h2 class="text-sm font-semibold text-stone-900 dark:text-white">
                            {{ t('crm_sales_inbox.list.title') }}
                        </h2>
                        <p class="text-xs text-stone-500 dark:text-neutral-400">
                            {{ t('crm_sales_inbox.list.subtitle', { count }) }}
                        </p>
                        <p class="text-xs text-stone-500 dark:text-neutral-400">
                            {{ paginationSummary }}
                            <span v-if="hasMultiplePages" class="mx-1 text-stone-300 dark:text-neutral-600">|</span>
                            <span v-if="hasMultiplePages">{{ pageLabel }}</span>
                        </p>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <div class="min-w-[8.5rem]">
                            <FloatingSelect
                                v-model="filterForm.per_page"
                                :label="t('crm_sales_inbox.filters.per_page')"
                                :options="perPageOptions"
                                option-value="value"
                                option-label="label"
                                data-testid="sales-inbox-filter-per-page"
                            />
                        </div>
                        <button
                            type="button"
                            :class="crmButtonClass('secondary', 'toolbar')"
                            @click="clearFilters"
                        >
                            {{ t('crm_sales_inbox.filters.clear') }}
                        </button>
                    </div>
                </div>

                <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-[minmax(0,1.35fr)_minmax(0,0.85fr)_minmax(0,0.85fr)]">
                    <FloatingInput
                        v-model="filterForm.search"
                        :label="t('crm_sales_inbox.filters.search')"
                        :placeholder="t('crm_sales_inbox.filters.search_placeholder')"
                        autocomplete="off"
                        data-testid="sales-inbox-filter-search"
                    />
                    <FloatingSelect
                        v-model="filterForm.queue"
                        :label="t('crm_sales_inbox.filters.queue')"
                        :options="queueOptions"
                        option-value="value"
                        option-label="label"
                        data-testid="sales-inbox-filter-queue"
                    />
                    <FloatingSelect
                        v-model="filterForm.stage"
                        :label="t('crm_sales_inbox.filters.stage')"
                        :options="stageOptions"
                        option-value="value"
                        option-label="label"
                        data-testid="sales-inbox-filter-stage"
                    />
                </div>

                <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-[repeat(auto-fit,minmax(10rem,1fr))]">
                    <button
                        type="button"
                        class="rounded-sm border p-2.5 text-left shadow-sm transition"
                        :class="queueCardClass('')"
                        @click="selectQueue('')"
                    >
                        <div class="text-[11px] font-semibold uppercase tracking-[0.16em]">
                            {{ t('crm_sales_inbox.queues.all') }}
                        </div>
                        <div class="mt-2 text-xl font-semibold">
                            {{ Number(props.stats?.total || 0) }}
                        </div>
                        <div class="mt-1 text-xs opacity-80">
                            {{ formatCurrency(props.stats?.weighted_open_amount || 0) }}
                        </div>
                    </button>

                    <button
                        v-for="queue in queueSummary"
                        :key="queue.key"
                        type="button"
                        class="rounded-sm border p-2.5 text-left shadow-sm transition"
                        :class="queueCardClass(queue.key)"
                        @click="selectQueue(queue.key)"
                    >
                        <div class="text-[11px] font-semibold uppercase tracking-[0.16em]">
                            {{ t(`crm_sales_inbox.queues.${queue.key}`) }}
                        </div>
                        <div class="mt-2 text-xl font-semibold">
                            {{ queue.count }}
                        </div>
                        <div class="mt-1 text-xs opacity-80">
                            {{ formatCurrency(queue.weighted_amount || 0) }}
                        </div>
                    </button>
                </div>

                <div
                    v-if="hasMultiplePages"
                    class="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-sm border border-stone-200 bg-stone-50 px-3 py-2 dark:border-neutral-800 dark:bg-neutral-900"
                >
                    <div class="text-xs font-medium text-stone-600 dark:text-neutral-300">
                        {{ paginationSummary }}
                    </div>
                    <AdminPaginationLinks :links="paginationLinks" />
                </div>
            </section>

            <section v-if="displayedItems.length" class="grid gap-4 xl:grid-cols-[repeat(auto-fit,minmax(24rem,1fr))]">
                <article
                    v-for="item in displayedItems"
                    :key="item.id"
                    class="min-w-0 flex h-full flex-col rounded-sm border border-stone-200 bg-white p-3.5 shadow-sm transition dark:border-neutral-800 dark:bg-neutral-950"
                    :class="isFiltering ? 'opacity-70' : ''"
                    :data-testid="`sales-inbox-item-${item.id}`"
                >
                    <div class="flex flex-wrap items-start justify-between gap-2.5">
                        <div class="min-w-0 space-y-2.5">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <span
                                    class="inline-flex items-center rounded-full border px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.14em]"
                                    :class="queueBadgeClass(item.queue)"
                                >
                                    {{ t(`crm_sales_inbox.queues.${item.queue}`) }}
                                </span>
                                <span
                                    class="inline-flex items-center rounded-full border px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.14em]"
                                    :class="stageBadgeClass(item.stage_key)"
                                >
                                    {{ item.stage_label || humanizeValue(item.stage_key) }}
                                </span>
                                <span
                                    v-if="shouldShowNextActionBadge(item)"
                                    class="inline-flex items-center rounded-full border px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.14em]"
                                    :class="nextActionBadgeClass(item.next_action_state)"
                                >
                                    {{ t(`crm_sales_inbox.next_action_states.${item.next_action_state}`) }}
                                </span>
                            </div>

                            <div class="min-w-0">
                                <div class="truncate text-[17px] font-semibold leading-5 text-stone-900 dark:text-white" :title="item.title">
                                    {{ item.title || '-' }}
                                </div>
                                <div class="mt-1 truncate text-[13px] text-stone-500 dark:text-neutral-400">
                                    {{ item.customer?.name || t('crm_sales_inbox.labels.no_customer') }}
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="mt-3 grid gap-2 sm:grid-cols-2">
                        <div class="rounded-sm border border-stone-200 bg-stone-50 px-2.5 py-2 dark:border-neutral-800 dark:bg-neutral-900">
                            <div class="text-[11px] font-semibold uppercase tracking-[0.14em] text-stone-500 dark:text-neutral-500">
                                {{ t('crm_sales_inbox.labels.customer') }}
                            </div>
                            <div class="mt-1 truncate text-sm text-stone-800 dark:text-neutral-100">
                                {{ item.customer?.name || t('crm_sales_inbox.labels.no_customer') }}
                            </div>
                        </div>

                        <div class="rounded-sm border border-stone-200 bg-stone-50 px-2.5 py-2 dark:border-neutral-800 dark:bg-neutral-900">
                            <div class="text-[11px] font-semibold uppercase tracking-[0.14em] text-stone-500 dark:text-neutral-500">
                                {{ t('crm_sales_inbox.labels.assignee') }}
                            </div>
                            <div class="mt-1 truncate text-sm text-stone-800 dark:text-neutral-100">
                                {{ item.assignee?.name || t('crm_sales_inbox.labels.unassigned') }}
                            </div>
                        </div>

                        <div class="rounded-sm border border-stone-200 bg-stone-50 px-2.5 py-2 dark:border-neutral-800 dark:bg-neutral-900">
                            <div class="text-[11px] font-semibold uppercase tracking-[0.14em] text-stone-500 dark:text-neutral-500">
                                {{ t('crm_sales_inbox.labels.next_action') }}
                            </div>
                            <div class="mt-1 text-sm text-stone-800 dark:text-neutral-100">
                                {{ item.next_action_at ? formatAbsoluteDate(item.next_action_at) : t('crm_sales_inbox.labels.no_next_action') }}
                            </div>
                            <div v-if="item.next_action_at" class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                {{ formatRelativeDate(item.next_action_at) }}
                            </div>
                        </div>

                        <div class="rounded-sm border border-stone-200 bg-stone-50 px-2.5 py-2 dark:border-neutral-800 dark:bg-neutral-900">
                            <div class="text-[11px] font-semibold uppercase tracking-[0.14em] text-stone-500 dark:text-neutral-500">
                                {{ t('crm_sales_inbox.labels.amount') }}
                            </div>
                            <div class="mt-1 text-sm text-stone-800 dark:text-neutral-100">
                                {{ formatAmount(item.amount_total, itemCurrency(item)) }}
                            </div>
                            <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                {{ t('crm_sales_inbox.labels.weighted_amount') }}:
                                {{ formatAmount(item.weighted_amount, itemCurrency(item)) }}
                            </div>
                        </div>

                        <div class="rounded-sm border border-stone-200 bg-stone-50 px-2.5 py-2 dark:border-neutral-800 dark:bg-neutral-900">
                            <div class="text-[11px] font-semibold uppercase tracking-[0.14em] text-stone-500 dark:text-neutral-500">
                                {{ t('crm_sales_inbox.labels.opened') }}
                            </div>
                            <div class="mt-1 text-sm text-stone-800 dark:text-neutral-100">
                                {{ openedLabel(item) }}
                            </div>
                        </div>

                        <div class="rounded-sm border border-stone-200 bg-stone-50 px-2.5 py-2 dark:border-neutral-800 dark:bg-neutral-900">
                            <div class="text-[11px] font-semibold uppercase tracking-[0.14em] text-stone-500 dark:text-neutral-500">
                                {{ item.quote?.number ? t('crm_sales_inbox.labels.quote_label') : t('crm_sales_inbox.labels.service_label') }}
                            </div>
                            <div class="mt-1 truncate text-sm text-stone-800 dark:text-neutral-100">
                                {{ quoteOrServiceLabel(item) }}
                            </div>
                        </div>
                    </div>

                    <div class="mt-auto flex flex-wrap items-center justify-end gap-1.5 pt-3">
                        <Link
                            v-if="customerRoute(item)"
                            :href="customerRoute(item)"
                            :class="crmButtonClass('secondary', 'compact')"
                        >
                            {{ t('crm_sales_inbox.actions.open_customer') }}
                        </Link>
                        <Link
                            v-if="hasSecondaryRequestAction(item)"
                            :href="requestRoute(item)"
                            :class="crmButtonClass('secondary', 'compact')"
                        >
                            {{ t('crm_sales_inbox.actions.open_request') }}
                        </Link>
                        <Link
                            v-if="hasSecondaryQuoteAction(item)"
                            :href="quoteRoute(item)"
                            :class="crmButtonClass('secondary', 'compact')"
                        >
                            {{ t('crm_sales_inbox.actions.open_quote') }}
                        </Link>
                        <Link
                            v-if="pipelineRoute(item)"
                            :href="pipelineRoute(item)"
                            :class="crmButtonClass('secondary', 'compact')"
                        >
                            {{ t('crm_sales_inbox.actions.open_pipeline') }}
                        </Link>
                        <Link
                            v-if="primaryRoute(item)"
                            :href="primaryRoute(item)"
                            :class="crmButtonClass('primary', 'compact')"
                        >
                            {{ primaryActionLabel(item) }}
                        </Link>
                    </div>
                </article>
            </section>

            <section
                v-if="displayedItems.length && hasMultiplePages"
                class="rounded-sm border border-stone-200 bg-white px-4 py-3 shadow-sm dark:border-neutral-800 dark:bg-neutral-950"
            >
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="text-sm text-stone-600 dark:text-neutral-300">
                        {{ paginationSummary }}
                    </div>
                    <div class="flex flex-wrap items-center gap-3">
                        <span class="text-xs text-stone-500 dark:text-neutral-400">{{ pageLabel }}</span>
                        <AdminPaginationLinks :links="paginationLinks" />
                    </div>
                </div>
            </section>

            <section
                v-else
                class="rounded-sm border border-dashed border-stone-300 bg-white px-6 py-12 text-center shadow-sm dark:border-neutral-700 dark:bg-neutral-950"
            >
                <div class="text-lg font-semibold text-stone-900 dark:text-white">
                    {{ t('crm_sales_inbox.empty_title') }}
                </div>
                <p class="mt-2 text-sm text-stone-500 dark:text-neutral-400">
                    {{ t('crm_sales_inbox.empty_body') }}
                </p>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
