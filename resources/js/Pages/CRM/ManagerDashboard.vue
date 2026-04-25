<script setup>
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import AppBreadcrumbs from '@/Components/UI/AppBreadcrumbs.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { useCurrencyFormatter } from '@/utils/currency';
import { crmButtonClass } from '@/utils/crmButtonStyles';
import { humanizeDate } from '@/utils/date';

const props = defineProps({
    reference_time: {
        type: String,
        default: '',
    },
    filters: {
        type: Object,
        default: () => ({}),
    },
    summary: {
        type: Object,
        default: () => ({}),
    },
    weighted_pipeline: {
        type: Array,
        default: () => [],
    },
    stage_aging: {
        type: Array,
        default: () => [],
    },
    next_actions: {
        type: Array,
        default: () => [],
    },
    wins: {
        type: Array,
        default: () => [],
    },
    queues: {
        type: Array,
        default: () => [],
    },
    attention_items: {
        type: Array,
        default: () => [],
    },
    options: {
        type: Object,
        default: () => ({}),
    },
});

const { t } = useI18n();
const { formatCurrency } = useCurrencyFormatter();

const filterForm = reactive({
    search: props.filters?.search || '',
    customer_id: Number(props.filters?.customer_id || 0),
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
        key: 'manager-dashboard',
        label: t('crm_manager_dashboard.page_title'),
    },
]));

const referenceTimeForFilters = computed(() => props.reference_time || props.filters?.reference_time || '');
const customerOptions = computed(() => ([
    { value: 0, label: t('crm_manager_dashboard.filters.all_customers') },
    ...((props.options?.customers || []).map((customer) => ({
        value: Number(customer.value),
        label: customer.label,
    }))),
]));

const weightedPipelineItems = computed(() => (Array.isArray(props.weighted_pipeline) ? props.weighted_pipeline : []));
const stageAgingItems = computed(() => (Array.isArray(props.stage_aging) ? props.stage_aging : []));
const nextActionItems = computed(() => (Array.isArray(props.next_actions) ? props.next_actions : []));
const winItems = computed(() => (Array.isArray(props.wins) ? props.wins : []));
const queueItems = computed(() => (Array.isArray(props.queues) ? props.queues : []));
const attentionItems = computed(() => (Array.isArray(props.attention_items) ? props.attention_items : []));

const summaryCards = computed(() => ([
    {
        key: 'weighted_open_amount',
        label: t('crm_manager_dashboard.summary.weighted_open_amount'),
        value: formatCurrency(props.summary?.weighted_open_amount || 0),
        detail: t('crm_manager_dashboard.summary.open_count_detail', {
            count: Number(props.summary?.open_count || 0),
        }),
        tone: 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300',
    },
    {
        key: 'month_to_date_won_amount',
        label: t('crm_manager_dashboard.summary.month_to_date_won_amount'),
        value: formatCurrency(props.summary?.month_to_date_won_amount || 0),
        detail: t('crm_manager_dashboard.summary.wins_detail', {
            count: Number(props.summary?.month_to_date_won_count || 0),
        }),
        tone: 'border-sky-200 bg-sky-50 text-sky-700 dark:border-sky-500/20 dark:bg-sky-500/10 dark:text-sky-300',
    },
    {
        key: 'overdue_next_actions',
        label: t('crm_manager_dashboard.summary.overdue_next_actions'),
        value: Number(props.summary?.overdue_next_actions || 0),
        detail: t('crm_manager_dashboard.summary.overdue_detail', {
            count: Number(props.summary?.overdue_next_actions || 0),
        }),
        tone: 'border-rose-200 bg-rose-50 text-rose-700 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-300',
    },
    {
        key: 'quote_pull_through',
        label: t('crm_manager_dashboard.summary.quote_pull_through'),
        value: formatPercent(props.summary?.quote_pull_through?.rate || 0),
        detail: t('crm_manager_dashboard.summary.quote_pull_through_detail', {
            won: Number(props.summary?.quote_pull_through?.won || 0),
            total: Number(props.summary?.quote_pull_through?.total || 0),
        }),
        tone: 'border-violet-200 bg-violet-50 text-violet-700 dark:border-violet-500/20 dark:bg-violet-500/10 dark:text-violet-300',
    },
]));

const applyFilters = (overrides = {}) => {
    const payload = {
        search: filterForm.search,
        customer_id: Number(filterForm.customer_id || 0),
        reference_time: referenceTimeForFilters.value,
        ...overrides,
    };

    Object.keys(payload).forEach((key) => {
        if (payload[key] === '' || payload[key] === null || payload[key] === undefined || payload[key] === 0) {
            delete payload[key];
        }
    });

    isFiltering.value = true;
    router.get(route('crm.manager-dashboard.index'), payload, {
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
    filterForm.customer_id = 0;

    if (filterTimeout) {
        clearTimeout(filterTimeout);
    }

    applyFilters();

    queueMicrotask(() => {
        suppressFilterWatch.value = false;
    });
};

watch(
    () => [filterForm.search, filterForm.customer_id],
    () => {
        if (suppressFilterWatch.value) {
            return;
        }

        if (filterTimeout) {
            clearTimeout(filterTimeout);
        }

        filterTimeout = setTimeout(() => applyFilters(), 250);
    },
);

onBeforeUnmount(() => {
    if (filterTimeout) {
        clearTimeout(filterTimeout);
    }
});

const formatPercent = (value) => {
    const numericValue = Number(value || 0);
    const digits = Number.isInteger(numericValue) ? 0 : 1;

    return `${numericValue.toFixed(digits)}%`;
};

const formatAbsoluteDate = (value) => {
    if (!value) {
        return '-';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? '-' : date.toLocaleString();
};

const formatRelativeDate = (value) => humanizeDate(value, { now: props.reference_time }) || '-';

const progressWidth = (value) => {
    const numericValue = Math.max(0, Math.min(100, Number(value || 0)));

    return {
        width: numericValue > 0 ? `${Math.max(4, numericValue)}%` : '0%',
    };
};

const itemCurrency = (item) => item?.quote?.currency_code || item?.opportunity?.amount?.currency_code || null;

const formatAmount = (value, currencyCode = null) => (
    value === null || value === undefined
        ? '-'
        : formatCurrency(value, currencyCode)
);

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

const categoryCardClass = (key) => {
    switch (String(key || '')) {
        case 'pipeline':
            return 'border-cyan-200 bg-cyan-50 text-cyan-700 dark:border-cyan-500/20 dark:bg-cyan-500/10 dark:text-cyan-300';
        case 'best_case':
            return 'border-violet-200 bg-violet-50 text-violet-700 dark:border-violet-500/20 dark:bg-violet-500/10 dark:text-violet-300';
        case 'closed_won':
            return 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300';
        case 'closed_lost':
            return 'border-stone-200 bg-stone-50 text-stone-700 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300';
        default:
            return 'border-stone-200 bg-stone-50 text-stone-700 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300';
    }
};

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

const customerRoute = (item) => (
    item?.customer?.id ? route('customer.show', item.customer.id) : null
);

const primaryActionLabel = (item) => (
    primarySubjectType(item) === 'quote'
        ? t('crm_manager_dashboard.actions.open_quote')
        : t('crm_manager_dashboard.actions.open_request')
);

const hasSecondaryRequestAction = (item) => Boolean(requestRoute(item) && requestRoute(item) !== primaryRoute(item));

const hasSecondaryQuoteAction = (item) => Boolean(quoteRoute(item) && quoteRoute(item) !== primaryRoute(item));

const queueRoute = (queueKey = '') => {
    const payload = {
        search: filterForm.search || undefined,
        queue: queueKey || undefined,
        reference_time: referenceTimeForFilters.value || undefined,
    };

    return route('crm.sales-inbox.index', payload);
};

const nextActionsRoute = computed(() => route('crm.next-actions.index', {
    search: filterForm.search || undefined,
    reference_time: referenceTimeForFilters.value || undefined,
}));

const salesInboxRoute = computed(() => route('crm.sales-inbox.index', {
    search: filterForm.search || undefined,
    reference_time: referenceTimeForFilters.value || undefined,
}));

const referenceTimeLabel = computed(() => formatAbsoluteDate(props.reference_time));

const quoteOrServiceLabel = (item) => {
    if (item?.quote?.number) {
        return t('crm_manager_dashboard.labels.quote', { number: item.quote.number });
    }

    return item?.request?.service_type || '-';
};

const openedLabel = (item) => {
    if (item?.age_days === null || item?.age_days === undefined) {
        return formatAbsoluteDate(item?.opened_at);
    }

    return `${formatAbsoluteDate(item?.opened_at)} · ${t('crm_manager_dashboard.labels.days', { count: item.age_days })}`;
};
</script>

<template>
    <Head :title="t('crm_manager_dashboard.page_title')" />

    <AuthenticatedLayout>
        <template #breadcrumb>
            <div class="px-4 pt-6 sm:px-6 lg:px-8">
                <AppBreadcrumbs :items="breadcrumbItems" />
            </div>
        </template>

        <div class="space-y-5 px-4 pb-6 sm:px-6 lg:px-8">
            <section class="overflow-hidden rounded-sm border border-stone-200 border-t-4 border-t-teal-600 bg-[linear-gradient(135deg,#f0fdfa_0%,#ccfbf1_38%,#ffffff_100%)] p-4 shadow-sm dark:border-neutral-800 dark:bg-[linear-gradient(135deg,#042f2e_0%,#0f172a_58%,#020617_100%)]">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="space-y-2">
                        <div class="text-xs font-semibold uppercase tracking-[0.24em] text-teal-700 dark:text-teal-300">
                            {{ t('crm_manager_dashboard.eyebrow') }}
                        </div>
                        <h1 class="text-2xl font-semibold tracking-tight text-stone-900 dark:text-white">
                            {{ t('crm_manager_dashboard.page_title') }}
                        </h1>
                        <p class="max-w-3xl text-sm leading-6 text-stone-600 dark:text-neutral-300">
                            {{ t('crm_manager_dashboard.page_description') }}
                        </p>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <div class="rounded-sm border border-stone-200 bg-white/80 px-3 py-2 text-xs text-stone-600 shadow-sm backdrop-blur dark:border-neutral-700 dark:bg-neutral-950/80 dark:text-neutral-300">
                            <span class="font-medium">{{ t('crm_manager_dashboard.reference_time') }}:</span>
                            {{ referenceTimeLabel }}
                        </div>
                        <Link :href="salesInboxRoute" :class="crmButtonClass('secondary', 'compact')">
                            {{ t('crm_manager_dashboard.actions.open_sales_inbox') }}
                        </Link>
                        <Link :href="nextActionsRoute" :class="crmButtonClass('primary', 'compact')">
                            {{ t('crm_manager_dashboard.actions.open_next_actions') }}
                        </Link>
                    </div>
                </div>
            </section>

            <section class="rounded-sm border border-stone-200 bg-white p-3.5 shadow-sm dark:border-neutral-800 dark:bg-neutral-950">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="text-sm font-semibold text-stone-900 dark:text-white">
                            {{ t('crm_manager_dashboard.filters.title') }}
                        </h2>
                        <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                            {{ t('crm_manager_dashboard.filters.subtitle') }}
                        </p>
                    </div>

                    <button
                        type="button"
                        :class="crmButtonClass('secondary', 'toolbar')"
                        @click="clearFilters"
                    >
                        {{ t('crm_manager_dashboard.filters.clear') }}
                    </button>
                </div>

                <div class="mt-4 grid gap-3 md:grid-cols-2">
                    <FloatingInput
                        v-model="filterForm.search"
                        :label="t('crm_manager_dashboard.filters.search')"
                        :placeholder="t('crm_manager_dashboard.filters.search_placeholder')"
                        autocomplete="off"
                        data-testid="manager-dashboard-filter-search"
                    />
                    <FloatingSelect
                        v-model="filterForm.customer_id"
                        :label="t('crm_manager_dashboard.filters.customer')"
                        :options="customerOptions"
                        option-value="value"
                        option-label="label"
                        data-testid="manager-dashboard-filter-customer"
                    />
                </div>
            </section>

            <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-[repeat(auto-fit,minmax(12rem,1fr))]">
                <article
                    v-for="card in summaryCards"
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
                    <div class="mt-1.5 text-xs opacity-80">
                        {{ card.detail }}
                    </div>
                </article>
            </section>

            <section class="grid gap-5 xl:grid-cols-[1.15fr_0.85fr]">
                <article class="rounded-sm border border-stone-200 bg-white p-3.5 shadow-sm dark:border-neutral-800 dark:bg-neutral-950">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h2 class="text-sm font-semibold text-stone-900 dark:text-white">
                                {{ t('crm_manager_dashboard.sections.weighted_pipeline') }}
                            </h2>
                            <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                {{ t('crm_manager_dashboard.sections.weighted_pipeline_description') }}
                            </p>
                        </div>
                        <div class="text-right text-xs text-stone-500 dark:text-neutral-400">
                            <div>{{ t('crm_manager_dashboard.labels.open_amount') }}</div>
                            <div class="mt-1 font-semibold text-stone-800 dark:text-neutral-100">
                                {{ formatCurrency(props.summary?.open_amount || 0) }}
                            </div>
                        </div>
                    </div>

                    <div class="mt-4 grid gap-3 md:grid-cols-2 2xl:grid-cols-4">
                        <article
                            v-for="category in weightedPipelineItems"
                            :key="category.key"
                            class="rounded-sm border p-3"
                            :class="categoryCardClass(category.key)"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <div class="text-[11px] font-semibold uppercase tracking-[0.14em] leading-4">
                                        {{ t(`crm_manager_dashboard.categories.${category.key}`) }}
                                    </div>
                                    <div class="mt-1.5 text-lg font-semibold lg:text-xl">
                                        {{ formatCurrency(category.weighted_amount || 0) }}
                                    </div>
                                </div>
                                <div class="text-right text-xs opacity-80">
                                    <div>{{ category.count }} {{ t('crm_manager_dashboard.labels.count').toLowerCase() }}</div>
                                    <div class="mt-1">{{ formatCurrency(category.amount_total || 0) }}</div>
                                </div>
                            </div>

                            <div class="mt-2.5">
                                <div class="flex items-center justify-between text-[11px] font-medium opacity-80">
                                    <span>{{ t('crm_manager_dashboard.labels.weighted_share') }}</span>
                                    <span>{{ formatPercent(category.share_percent || 0) }}</span>
                                </div>
                                <div class="mt-2 h-2 rounded-full bg-white/70 dark:bg-neutral-900/70">
                                    <div
                                        class="h-2 rounded-full bg-current transition-all"
                                        :style="progressWidth(category.share_percent)"
                                    />
                                </div>
                            </div>
                        </article>
                    </div>
                </article>

                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-1 2xl:grid-cols-2">
                    <article class="rounded-sm border border-stone-200 bg-white p-3.5 shadow-sm dark:border-neutral-800 dark:bg-neutral-950">
                        <h2 class="text-sm font-semibold text-stone-900 dark:text-white">
                            {{ t('crm_manager_dashboard.sections.next_actions') }}
                        </h2>
                        <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                            {{ t('crm_manager_dashboard.sections.next_actions_description') }}
                        </p>

                        <div class="mt-4 grid gap-2.5">
                            <div
                                v-for="bucket in nextActionItems"
                                :key="bucket.key"
                                class="rounded-sm border border-stone-200 bg-stone-50 p-2.5 dark:border-neutral-800 dark:bg-neutral-900"
                            >
                                <div class="flex items-center justify-between gap-3">
                                    <div class="text-sm font-semibold text-stone-900 dark:text-white">
                                        {{ t(`crm_manager_dashboard.next_action_buckets.${bucket.key}`) }}
                                    </div>
                                    <div class="text-sm font-semibold text-stone-900 dark:text-white">
                                        {{ bucket.count }}
                                    </div>
                                </div>
                                <div class="mt-1.5 flex items-center justify-between text-xs text-stone-500 dark:text-neutral-400">
                                    <span>{{ t('crm_manager_dashboard.labels.amount') }}</span>
                                    <span>{{ formatCurrency(bucket.amount_total || 0) }}</span>
                                </div>
                                <div class="mt-1 flex items-center justify-between text-xs text-stone-500 dark:text-neutral-400">
                                    <span>{{ t('crm_manager_dashboard.labels.weighted') }}</span>
                                    <span>{{ formatCurrency(bucket.weighted_amount || 0) }}</span>
                                </div>
                            </div>
                        </div>
                    </article>

                    <article class="rounded-sm border border-stone-200 bg-white p-3.5 shadow-sm dark:border-neutral-800 dark:bg-neutral-950">
                        <h2 class="text-sm font-semibold text-stone-900 dark:text-white">
                            {{ t('crm_manager_dashboard.sections.wins') }}
                        </h2>
                        <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                            {{ t('crm_manager_dashboard.sections.wins_description') }}
                        </p>

                        <div class="mt-4 grid gap-2.5">
                            <div
                                v-for="window in winItems"
                                :key="window.key"
                                class="rounded-sm border border-stone-200 bg-stone-50 p-2.5 dark:border-neutral-800 dark:bg-neutral-900"
                            >
                                <div class="flex items-center justify-between gap-3">
                                    <div class="text-sm font-semibold text-stone-900 dark:text-white">
                                        {{ t(`crm_manager_dashboard.wins_windows.${window.key}`) }}
                                    </div>
                                    <div class="text-sm font-semibold text-stone-900 dark:text-white">
                                        {{ window.count }}
                                    </div>
                                </div>
                                <div class="mt-1.5 text-xs text-stone-500 dark:text-neutral-400">
                                    {{ formatCurrency(window.amount_total || 0) }}
                                </div>
                            </div>
                        </div>
                    </article>
                </div>
            </section>

            <section class="rounded-sm border border-stone-200 bg-white p-3.5 shadow-sm dark:border-neutral-800 dark:bg-neutral-950">
                <h2 class="text-sm font-semibold text-stone-900 dark:text-white">
                    {{ t('crm_manager_dashboard.sections.stage_aging') }}
                </h2>
                <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                    {{ t('crm_manager_dashboard.sections.stage_aging_description') }}
                </p>

                <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                    <article
                        v-for="stage in stageAgingItems"
                        :key="stage.key"
                        class="rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-800 dark:bg-neutral-900"
                    >
                        <div class="flex items-center justify-between gap-3">
                            <span
                                class="inline-flex items-center rounded-full border px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.14em]"
                                :class="stageBadgeClass(stage.key)"
                            >
                                {{ stage.label }}
                            </span>
                            <span class="text-sm font-semibold text-stone-900 dark:text-white">
                                {{ stage.count }}
                            </span>
                        </div>

                        <div class="mt-3 space-y-1.5 text-sm text-stone-700 dark:text-neutral-200">
                            <div class="flex items-center justify-between gap-3">
                                <span class="text-stone-500 dark:text-neutral-400">{{ t('crm_manager_dashboard.labels.weighted') }}</span>
                                <span class="font-medium">{{ formatCurrency(stage.weighted_amount || 0) }}</span>
                            </div>
                            <div class="flex items-center justify-between gap-3">
                                <span class="text-stone-500 dark:text-neutral-400">{{ t('crm_manager_dashboard.labels.avg_age') }}</span>
                                <span class="font-medium">
                                    {{ stage.average_age_days === null ? '-' : t('crm_manager_dashboard.labels.days', { count: stage.average_age_days }) }}
                                </span>
                            </div>
                            <div class="flex items-center justify-between gap-3">
                                <span class="text-stone-500 dark:text-neutral-400">{{ t('crm_manager_dashboard.labels.overdue') }}</span>
                                <span class="font-medium">{{ stage.overdue_next_actions }}</span>
                            </div>
                        </div>

                        <div class="mt-3">
                            <div class="flex items-center justify-between text-[11px] font-medium text-stone-500 dark:text-neutral-400">
                                <span>{{ t('crm_manager_dashboard.labels.weighted_share') }}</span>
                                <span>{{ formatPercent(stage.share_percent || 0) }}</span>
                            </div>
                            <div class="mt-2 h-2 rounded-full bg-stone-200 dark:bg-neutral-800">
                                <div
                                    class="h-2 rounded-full bg-teal-500 transition-all dark:bg-teal-400"
                                    :style="progressWidth(stage.share_percent)"
                                />
                            </div>
                        </div>
                    </article>
                </div>
            </section>

            <section class="grid gap-5 xl:grid-cols-[0.82fr_1.18fr]">
                <article class="rounded-sm border border-stone-200 bg-white p-3.5 shadow-sm dark:border-neutral-800 dark:bg-neutral-950">
                    <h2 class="text-sm font-semibold text-stone-900 dark:text-white">
                        {{ t('crm_manager_dashboard.sections.queues') }}
                    </h2>
                    <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                        {{ t('crm_manager_dashboard.sections.queues_description') }}
                    </p>

                    <div class="mt-4 grid gap-2.5 sm:grid-cols-2 xl:grid-cols-1">
                        <Link
                            v-for="queue in queueItems"
                            :key="queue.key"
                            :href="queueRoute(queue.key)"
                            class="rounded-sm border p-2.5 transition shadow-sm hover:border-stone-300 dark:hover:border-neutral-700"
                            :class="queueBadgeClass(queue.key)"
                        >
                            <div class="flex items-center justify-between gap-3">
                                <div class="text-sm font-semibold">
                                    {{ t(`crm_manager_dashboard.queues.${queue.key}`) }}
                                </div>
                                <div class="text-lg font-semibold">
                                    {{ queue.count }}
                                </div>
                            </div>
                            <div class="mt-1.5 flex items-center justify-between text-xs opacity-80">
                                <span>{{ t('crm_manager_dashboard.labels.weighted') }}</span>
                                <span>{{ formatCurrency(queue.weighted_amount || 0) }}</span>
                            </div>
                        </Link>
                    </div>
                </article>

                <article class="rounded-sm border border-stone-200 bg-white p-3.5 shadow-sm dark:border-neutral-800 dark:bg-neutral-950">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h2 class="text-sm font-semibold text-stone-900 dark:text-white">
                                {{ t('crm_manager_dashboard.sections.attention') }}
                            </h2>
                            <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                {{ t('crm_manager_dashboard.sections.attention_description') }}
                            </p>
                        </div>
                        <Link :href="salesInboxRoute" :class="crmButtonClass('secondary', 'compact')">
                            {{ t('crm_manager_dashboard.attention.open_sales_inbox') }}
                        </Link>
                    </div>

                    <div v-if="attentionItems.length" class="mt-4 grid gap-3 xl:max-h-[46rem] xl:grid-cols-2 xl:overflow-y-auto xl:pr-1">
                        <article
                            v-for="item in attentionItems"
                            :key="item.id"
                            class="min-w-0 rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-800 dark:bg-neutral-900"
                            :class="isFiltering ? 'opacity-70' : ''"
                        >
                            <div class="flex flex-wrap items-start justify-between gap-2.5">
                                <div class="min-w-0 space-y-2.5">
                                    <div class="flex flex-wrap items-center gap-1.5">
                                        <span
                                            class="inline-flex items-center rounded-full border px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.14em]"
                                            :class="queueBadgeClass(item.queue)"
                                        >
                                            {{ t(`crm_manager_dashboard.queues.${item.queue}`) }}
                                        </span>
                                        <span
                                            class="inline-flex items-center rounded-full border px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.14em]"
                                            :class="stageBadgeClass(item.stage_key)"
                                        >
                                            {{ item.stage_label }}
                                        </span>
                                    </div>

                                    <div class="min-w-0">
                                        <div class="truncate text-[17px] font-semibold leading-5 text-stone-900 dark:text-white" :title="item.title">
                                            {{ item.title || '-' }}
                                        </div>
                                        <div class="mt-1 truncate text-[13px] text-stone-500 dark:text-neutral-400">
                                            {{ item.customer?.name || t('crm_manager_dashboard.labels.no_customer') }}
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-3 grid gap-2 sm:grid-cols-2">
                                <div class="rounded-sm border border-stone-200 bg-white px-2.5 py-2 dark:border-neutral-800 dark:bg-neutral-950">
                                    <div class="text-[11px] font-semibold uppercase tracking-[0.14em] text-stone-500 dark:text-neutral-500">
                                        {{ t('crm_manager_dashboard.labels.next_action') }}
                                    </div>
                                    <div class="mt-1 text-sm text-stone-800 dark:text-neutral-100">
                                        {{ item.next_action_at ? formatAbsoluteDate(item.next_action_at) : t('crm_manager_dashboard.labels.no_next_action') }}
                                    </div>
                                    <div v-if="item.next_action_at" class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                        {{ formatRelativeDate(item.next_action_at) }}
                                    </div>
                                </div>

                                <div class="rounded-sm border border-stone-200 bg-white px-2.5 py-2 dark:border-neutral-800 dark:bg-neutral-950">
                                    <div class="text-[11px] font-semibold uppercase tracking-[0.14em] text-stone-500 dark:text-neutral-500">
                                        {{ t('crm_manager_dashboard.labels.amount') }}
                                    </div>
                                    <div class="mt-1 text-sm text-stone-800 dark:text-neutral-100">
                                        {{ formatAmount(item.amount_total, itemCurrency(item)) }}
                                    </div>
                                    <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                        {{ t('crm_manager_dashboard.labels.weighted') }}:
                                        {{ formatAmount(item.weighted_amount, itemCurrency(item)) }}
                                    </div>
                                </div>

                                <div class="rounded-sm border border-stone-200 bg-white px-2.5 py-2 dark:border-neutral-800 dark:bg-neutral-950">
                                    <div class="text-[11px] font-semibold uppercase tracking-[0.14em] text-stone-500 dark:text-neutral-500">
                                        {{ t('crm_manager_dashboard.labels.opened') }}
                                    </div>
                                    <div class="mt-1 text-sm text-stone-800 dark:text-neutral-100">
                                        {{ openedLabel(item) }}
                                    </div>
                                </div>

                                <div class="rounded-sm border border-stone-200 bg-white px-2.5 py-2 dark:border-neutral-800 dark:bg-neutral-950">
                                    <div class="text-[11px] font-semibold uppercase tracking-[0.14em] text-stone-500 dark:text-neutral-500">
                                        {{ item.quote?.number ? t('crm_manager_dashboard.labels.quote_label') : t('crm_manager_dashboard.labels.service_label') }}
                                    </div>
                                    <div class="mt-1 truncate text-sm text-stone-800 dark:text-neutral-100">
                                        {{ quoteOrServiceLabel(item) }}
                                    </div>
                                </div>
                            </div>

                            <div class="mt-3 flex flex-wrap items-center justify-end gap-1.5">
                                <Link
                                    v-if="customerRoute(item)"
                                    :href="customerRoute(item)"
                                    :class="crmButtonClass('secondary', 'compact')"
                                >
                                    {{ t('crm_manager_dashboard.actions.open_customer') }}
                                </Link>
                                <Link
                                    v-if="hasSecondaryRequestAction(item)"
                                    :href="requestRoute(item)"
                                    :class="crmButtonClass('secondary', 'compact')"
                                >
                                    {{ t('crm_manager_dashboard.actions.open_request') }}
                                </Link>
                                <Link
                                    v-if="hasSecondaryQuoteAction(item)"
                                    :href="quoteRoute(item)"
                                    :class="crmButtonClass('secondary', 'compact')"
                                >
                                    {{ t('crm_manager_dashboard.actions.open_quote') }}
                                </Link>
                                <Link
                                    :href="queueRoute(item.queue)"
                                    :class="crmButtonClass('secondary', 'compact')"
                                >
                                    {{ t('crm_manager_dashboard.attention.open_sales_inbox_queue') }}
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
                    </div>

                    <div
                        v-else
                        class="mt-4 rounded-sm border border-dashed border-stone-300 bg-stone-50 px-6 py-10 text-center dark:border-neutral-700 dark:bg-neutral-900"
                    >
                        <div class="text-lg font-semibold text-stone-900 dark:text-white">
                            {{ t('crm_manager_dashboard.attention.empty_title') }}
                        </div>
                        <p class="mt-2 text-sm text-stone-500 dark:text-neutral-400">
                            {{ t('crm_manager_dashboard.attention.empty_body') }}
                        </p>
                    </div>
                </article>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
