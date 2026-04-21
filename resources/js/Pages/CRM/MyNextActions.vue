<script setup>
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AdminPaginationLinks from '@/Components/DataTable/AdminPaginationLinks.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import AppBreadcrumbs from '@/Components/UI/AppBreadcrumbs.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
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

const { t, te } = useI18n();
const defaultPerPage = Number(props.options?.per_page_options?.[1] || props.options?.per_page_options?.[0] || 9);

const filterForm = reactive({
    search: props.filters?.search || '',
    source: props.filters?.source || '',
    subject_type: props.filters?.subject_type || '',
    due_state: props.filters?.due_state || 'all',
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
        key: 'next-actions',
        label: t('crm_next_actions.page_title'),
    },
]));

const sourceOptions = computed(() => ([
    { value: '', label: t('crm_next_actions.filters.all_sources') },
    ...((props.options?.sources || []).map((value) => ({
        value,
        label: t(`crm_next_actions.sources.${value}`),
    }))),
]));

const subjectTypeOptions = computed(() => ([
    { value: '', label: t('crm_next_actions.filters.all_subject_types') },
    ...((props.options?.subject_types || []).map((value) => ({
        value,
        label: t(`crm_next_actions.subject_types.${value}`),
    }))),
]));

const dueStateOptions = computed(() => (
    (props.options?.due_states || ['all', 'overdue', 'today', 'upcoming']).map((value) => ({
        value,
        label: t(`crm_next_actions.due_states.${value}`),
    }))
));

const activeSourceCount = computed(() => Object.keys(props.stats?.by_source || {}).length);
const displayedItems = computed(() => (Array.isArray(props.items) ? props.items : []));
const paginationLinks = computed(() => (Array.isArray(props.pagination?.links) ? props.pagination.links : []));
const currentPage = computed(() => Number(props.pagination?.current_page || 1));
const lastPage = computed(() => Number(props.pagination?.last_page || 1));
const hasMultiplePages = computed(() => lastPage.value > 1);
const currentPerPage = computed(() => Number(filterForm.per_page || defaultPerPage));
const perPageOptions = computed(() => (
    (props.options?.per_page_options || [6, 9, 12, 18]).map((value) => ({
        value: Number(value),
        label: String(value),
    }))
));
const paginationSummary = computed(() => t('crm_next_actions.pagination.showing', {
    from: Number(props.pagination?.from || 0),
    to: Number(props.pagination?.to || 0),
    total: Number(props.pagination?.total || props.count || 0),
}));
const pageLabel = computed(() => t('crm_next_actions.pagination.page_of', {
    page: currentPage.value,
    total: lastPage.value,
}));
const referenceTimeLabel = computed(() => {
    if (!props.reference_time) {
        return '-';
    }

    const date = new Date(props.reference_time);

    return Number.isNaN(date.getTime()) ? '-' : date.toLocaleString();
});

const applyFilters = (overrides = {}) => {
    const payload = {
        search: filterForm.search,
        source: filterForm.source,
        subject_type: filterForm.subject_type,
        due_state: filterForm.due_state,
        per_page: currentPerPage.value,
        reference_time: props.filters?.reference_time || '',
        ...overrides,
    };

    Object.keys(payload).forEach((key) => {
        if (payload[key] === '' || payload[key] === null || payload[key] === undefined || payload[key] === 'all') {
            delete payload[key];
        }
    });

    if (Number(payload.page || 1) <= 1) {
        delete payload.page;
    }

    isFiltering.value = true;
    router.get(route('crm.next-actions.index'), payload, {
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
    filterForm.source = '';
    filterForm.subject_type = '';
    filterForm.due_state = 'all';

    if (filterTimeout) {
        clearTimeout(filterTimeout);
    }

    applyFilters({ page: 1 });

    queueMicrotask(() => {
        suppressFilterWatch.value = false;
    });
};

watch(
    () => [filterForm.search, filterForm.source, filterForm.subject_type, filterForm.due_state],
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

const statCards = computed(() => ([
    {
        key: 'total',
        label: t('crm_next_actions.cards.total'),
        value: Number(props.stats?.total || props.count || 0),
        tone: 'border-stone-200 bg-stone-50 text-stone-700 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200',
    },
    {
        key: 'overdue',
        label: t('crm_next_actions.cards.overdue'),
        value: Number(props.stats?.overdue || 0),
        tone: 'border-rose-200 bg-rose-50 text-rose-700 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-300',
    },
    {
        key: 'due_today',
        label: t('crm_next_actions.cards.due_today'),
        value: Number(props.stats?.due_today || 0),
        tone: 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-300',
    },
    {
        key: 'active_sources',
        label: t('crm_next_actions.cards.active_sources'),
        value: activeSourceCount.value,
        tone: 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300',
    },
]));

const subjectLabel = (value) => t(`crm_next_actions.subject_types.${value || 'customer'}`);

const sourceLabel = (item) => {
    const key = String(item?.source || '');

    return te(`crm_next_actions.sources.${key}`)
        ? t(`crm_next_actions.sources.${key}`)
        : (item?.source_label || key);
};

const normalizeComparableLabel = (value) => String(value || '')
    .trim()
    .toLowerCase();

const shouldShowSubjectBadge = (item) => (
    normalizeComparableLabel(subjectLabel(item?.subject_type)) !== normalizeComparableLabel(sourceLabel(item))
);

const humanizeValue = (value) => String(value || '')
    .replaceAll('_', ' ')
    .trim()
    .replace(/\b\w/g, (char) => char.toUpperCase());

const statusLabel = (item) => {
    const type = String(item?.subject_type || '');
    const status = String(item?.status || '');
    if (!status) {
        return t('crm_next_actions.labels.no_status');
    }

    const key = type === 'request'
        ? `requests.status.${status}`
        : type === 'quote'
            ? `quotes.status.${status}`
            : type === 'task'
                ? `tasks.status.${status}`
                : '';

    return key && te(key) ? t(key) : humanizeValue(status);
};

const routeForItem = (item) => {
    switch (String(item?.subject_type || '')) {
        case 'request':
            return route('request.show', item.subject_id);
        case 'quote':
            return route('customer.quote.show', item.subject_id);
        case 'task':
            return route('task.show', item.subject_id);
        case 'customer':
            return route('customer.show', item.subject_id);
        default:
            return null;
    }
};

const customerRoute = (item) => {
    const customerId = item?.customer?.id;

    return customerId ? route('customer.show', customerId) : null;
};

const subjectActionLabel = (item) => {
    switch (String(item?.subject_type || '')) {
        case 'request':
            return t('crm_next_actions.actions.open_request');
        case 'quote':
            return t('crm_next_actions.actions.open_quote');
        case 'task':
            return t('crm_next_actions.actions.open_task');
        case 'customer':
            return t('crm_next_actions.actions.open_customer');
        default:
            return t('crm_next_actions.actions.open_subject');
    }
};

const dueBadgeClass = (item) => {
    if (item?.is_overdue) {
        return 'border-rose-200 bg-rose-50 text-rose-700 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-300';
    }

    if (item?.is_due_today) {
        return 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-300';
    }

    return 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300';
};

const sourceBadgeClass = (source) => {
    switch (String(source || '')) {
        case 'sales_activity':
            return 'border-violet-200 bg-violet-50 text-violet-700 dark:border-violet-500/20 dark:bg-violet-500/10 dark:text-violet-300';
        case 'task':
            return 'border-sky-200 bg-sky-50 text-sky-700 dark:border-sky-500/20 dark:bg-sky-500/10 dark:text-sky-300';
        case 'request_follow_up':
            return 'border-cyan-200 bg-cyan-50 text-cyan-700 dark:border-cyan-500/20 dark:bg-cyan-500/10 dark:text-cyan-300';
        case 'quote_follow_up':
            return 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-300';
        default:
            return 'border-stone-200 bg-stone-50 text-stone-700 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200';
    }
};

const dueStateLabel = (item) => {
    if (item?.is_overdue) {
        return t('crm_next_actions.labels.overdue');
    }

    if (item?.is_due_today) {
        return t('crm_next_actions.labels.due_today');
    }

    return t('crm_next_actions.labels.upcoming');
};

const normalizeDateValue = (value) => {
    if (!value) {
        return null;
    }

    if (typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value)) {
        return `${value}T12:00:00`;
    }

    return value;
};

const formatAbsoluteDue = (item) => {
    const rawValue = item?.is_all_day ? item?.due_date : item?.due_at;
    const normalized = normalizeDateValue(rawValue);

    if (!normalized) {
        return '-';
    }

    const date = new Date(normalized);
    if (Number.isNaN(date.getTime())) {
        return '-';
    }

    return item?.is_all_day
        ? date.toLocaleDateString()
        : date.toLocaleString();
};

const formatRelativeDue = (item) => {
    const rawValue = item?.is_all_day ? item?.due_date : item?.due_at;

    return humanizeDate(normalizeDateValue(rawValue), { now: props.reference_time }) || '-';
};

const latestActivityLabel = (item) => {
    const activity = item?.activity;
    if (!activity) {
        return '';
    }

    return activity.description || activity.label || sourceLabel(item);
};
</script>

<template>
    <Head :title="t('crm_next_actions.page_title')" />

    <AuthenticatedLayout>
        <template #breadcrumb>
            <div class="px-4 pt-6 sm:px-6 lg:px-8">
                <AppBreadcrumbs :items="breadcrumbItems" />
            </div>
        </template>

        <div class="space-y-6 px-4 pb-6 sm:px-6 lg:px-8">
            <section class="rounded-sm border border-stone-200 border-t-4 border-t-emerald-600 bg-white p-5 shadow-sm dark:border-neutral-800 dark:bg-neutral-950">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="space-y-2">
                        <div class="text-xs font-semibold uppercase tracking-[0.24em] text-emerald-700 dark:text-emerald-300">
                            {{ t('crm_next_actions.eyebrow') }}
                        </div>
                        <h1 class="text-2xl font-semibold tracking-tight text-stone-900 dark:text-white">
                            {{ t('crm_next_actions.page_title') }}
                        </h1>
                        <p class="max-w-2xl text-sm leading-6 text-stone-600 dark:text-neutral-300">
                            {{ t('crm_next_actions.page_description') }}
                        </p>
                    </div>

                    <div class="rounded-sm border border-stone-200 bg-stone-50 px-3 py-2 text-xs text-stone-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300">
                        <span class="font-medium">{{ t('crm_next_actions.reference_time') }}:</span>
                        {{ referenceTimeLabel }}
                    </div>
                </div>
            </section>

            <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <article
                    v-for="card in statCards"
                    :key="card.key"
                    class="rounded-sm border p-4 shadow-sm"
                    :class="card.tone"
                >
                    <div class="text-xs font-semibold uppercase tracking-[0.16em]">
                        {{ card.label }}
                    </div>
                    <div class="mt-2 text-2xl font-semibold">
                        {{ card.value }}
                    </div>
                </article>
            </section>

            <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-800 dark:bg-neutral-950">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="space-y-1">
                        <h2 class="text-sm font-semibold text-stone-900 dark:text-white">
                            {{ t('crm_next_actions.list.title') }}
                        </h2>
                        <p class="text-xs text-stone-500 dark:text-neutral-400">
                            {{ t('crm_next_actions.list.subtitle', { count }) }}
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
                                :label="t('crm_next_actions.filters.per_page')"
                                :options="perPageOptions"
                                option-value="value"
                                option-label="label"
                                data-testid="my-next-actions-filter-per-page"
                            />
                        </div>
                        <button
                            type="button"
                            :class="crmButtonClass('secondary', 'toolbar')"
                            @click="clearFilters"
                        >
                            {{ t('crm_next_actions.filters.clear') }}
                        </button>
                    </div>
                </div>

                <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                    <FloatingInput
                        v-model="filterForm.search"
                        :label="t('crm_next_actions.filters.search')"
                        :placeholder="t('crm_next_actions.filters.search_placeholder')"
                        autocomplete="off"
                        data-testid="my-next-actions-filter-search"
                    />
                    <FloatingSelect
                        v-model="filterForm.source"
                        :label="t('crm_next_actions.filters.source')"
                        :options="sourceOptions"
                        option-value="value"
                        option-label="label"
                        data-testid="my-next-actions-filter-source"
                    />
                    <FloatingSelect
                        v-model="filterForm.subject_type"
                        :label="t('crm_next_actions.filters.subject_type')"
                        :options="subjectTypeOptions"
                        option-value="value"
                        option-label="label"
                        data-testid="my-next-actions-filter-subject-type"
                    />
                    <FloatingSelect
                        v-model="filterForm.due_state"
                        :label="t('crm_next_actions.filters.due_state')"
                        :options="dueStateOptions"
                        option-value="value"
                        option-label="label"
                        data-testid="my-next-actions-filter-due-state"
                    />
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

            <section v-if="displayedItems.length" class="grid gap-4 xl:grid-cols-2 2xl:grid-cols-3">
                <article
                    v-for="item in displayedItems"
                    :key="item.id"
                    class="flex h-full flex-col rounded-sm border border-stone-200 bg-white p-4 shadow-sm transition dark:border-neutral-800 dark:bg-neutral-950"
                    :class="isFiltering ? 'opacity-70' : ''"
                    :data-testid="`my-next-actions-item-${item.id}`"
                >
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0 space-y-3">
                            <div class="flex flex-wrap items-center gap-2">
                                <span
                                    class="inline-flex items-center rounded-full border px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.14em]"
                                    :class="sourceBadgeClass(item.source)"
                                >
                                    {{ sourceLabel(item) }}
                                </span>
                                <span
                                    class="inline-flex items-center rounded-full border px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.14em]"
                                    :class="dueBadgeClass(item)"
                                >
                                    {{ dueStateLabel(item) }}
                                </span>
                                <span
                                    v-if="shouldShowSubjectBadge(item)"
                                    class="inline-flex items-center rounded-full border border-stone-200 bg-stone-50 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.14em] text-stone-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300"
                                >
                                    {{ subjectLabel(item.subject_type) }}
                                </span>
                            </div>

                            <div class="min-w-0">
                                <div class="truncate text-lg font-semibold text-stone-900 dark:text-white" :title="item.subject_title">
                                    {{ item.subject_title }}
                                </div>
                                <div class="mt-1 text-sm text-stone-500 dark:text-neutral-400">
                                    {{ t('crm_next_actions.labels.due') }}:
                                    <span class="font-medium text-stone-700 dark:text-neutral-200">{{ formatAbsoluteDue(item) }}</span>
                                    <span class="mx-1 text-stone-300 dark:text-neutral-600">|</span>
                                    <span>{{ formatRelativeDue(item) }}</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="mt-4 grid gap-2 sm:grid-cols-2">
                        <div class="rounded-sm border border-stone-200 bg-stone-50 px-3 py-2 dark:border-neutral-800 dark:bg-neutral-900">
                            <div class="text-[11px] font-semibold uppercase tracking-[0.14em] text-stone-500 dark:text-neutral-500">
                                {{ t('crm_next_actions.labels.customer') }}
                            </div>
                            <div class="mt-1 text-sm text-stone-800 dark:text-neutral-100">
                                {{ item.customer?.name || t('crm_next_actions.labels.no_customer') }}
                            </div>
                        </div>

                        <div class="rounded-sm border border-stone-200 bg-stone-50 px-3 py-2 dark:border-neutral-800 dark:bg-neutral-900">
                            <div class="text-[11px] font-semibold uppercase tracking-[0.14em] text-stone-500 dark:text-neutral-500">
                                {{ t('crm_next_actions.labels.assignee') }}
                            </div>
                            <div class="mt-1 text-sm text-stone-800 dark:text-neutral-100">
                                {{ item.assignee?.name || t('crm_next_actions.labels.no_assignee') }}
                            </div>
                        </div>

                        <div class="rounded-sm border border-stone-200 bg-stone-50 px-3 py-2 dark:border-neutral-800 dark:bg-neutral-900">
                            <div class="text-[11px] font-semibold uppercase tracking-[0.14em] text-stone-500 dark:text-neutral-500">
                                {{ t('crm_next_actions.labels.status') }}
                            </div>
                            <div class="mt-1 text-sm text-stone-800 dark:text-neutral-100">
                                {{ statusLabel(item) }}
                            </div>
                        </div>

                        <div class="rounded-sm border border-stone-200 bg-stone-50 px-3 py-2 dark:border-neutral-800 dark:bg-neutral-900">
                            <div class="text-[11px] font-semibold uppercase tracking-[0.14em] text-stone-500 dark:text-neutral-500">
                                {{ t('crm_next_actions.labels.latest_activity') }}
                            </div>
                            <div class="mt-1 text-sm text-stone-800 dark:text-neutral-100">
                                {{ latestActivityLabel(item) || t('crm_next_actions.labels.no_activity') }}
                            </div>
                            <div
                                v-if="item.activity?.actor || item.activity?.logged_at"
                                class="mt-1 text-xs text-stone-500 dark:text-neutral-400"
                            >
                                {{ item.activity?.actor || t('crm_next_actions.labels.system_actor') }}
                                <span v-if="item.activity?.logged_at">
                                    · {{ humanizeDate(item.activity.logged_at, { now: reference_time }) || '' }}
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="mt-auto flex flex-wrap items-center justify-end gap-2 pt-4">
                        <div class="flex flex-wrap items-center gap-2">
                            <Link
                                v-if="customerRoute(item) && customerRoute(item) !== routeForItem(item)"
                                :href="customerRoute(item)"
                                :class="crmButtonClass('secondary', 'compact')"
                            >
                                {{ t('crm_next_actions.actions.open_customer') }}
                            </Link>
                            <Link
                                v-if="routeForItem(item)"
                                :href="routeForItem(item)"
                                :class="crmButtonClass('primary', 'compact')"
                            >
                                {{ subjectActionLabel(item) }}
                            </Link>
                        </div>
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
                    {{ t('crm_next_actions.empty_title') }}
                </div>
                <p class="mt-2 text-sm text-stone-500 dark:text-neutral-400">
                    {{ t('crm_next_actions.empty_body') }}
                </p>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
