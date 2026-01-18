<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AnnouncementsPanel from '@/Components/Dashboard/AnnouncementsPanel.vue';
import KpiSparkline from '@/Components/Dashboard/KpiSparkline.vue';
import KpiTrendBadge from '@/Components/Dashboard/KpiTrendBadge.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { humanizeDate } from '@/utils/date';
import { buildSparklinePoints, buildTrend } from '@/utils/kpi';
import { isFeatureEnabled } from '@/utils/features';

const props = defineProps({
    stats: {
        type: Object,
        required: true,
    },
    recentQuotes: {
        type: Array,
        default: () => [],
    },
    upcomingJobs: {
        type: Array,
        default: () => [],
    },
    tasksToday: {
        type: Array,
        default: () => [],
    },
    worksToday: {
        type: Array,
        default: () => [],
    },
    agendaAlerts: {
        type: Object,
        default: () => ({}),
    },
    outstandingInvoices: {
        type: Array,
        default: () => [],
    },
    activity: {
        type: Array,
        default: () => [],
    },
    revenueSeries: {
        type: Object,
        default: () => ({ labels: [], values: [] }),
    },
    kpiSeries: {
        type: Object,
        default: () => ({}),
    },
    announcements: {
        type: Array,
        default: () => [],
    },
    quickAnnouncements: {
        type: Array,
        default: () => [],
    },
    usage_limits: {
        type: Object,
        default: () => ({ items: [] }),
    },
    billing: {
        type: Object,
        default: () => ({}),
    },
});

const page = usePage();
const { t } = useI18n();
const userName = computed(() => page.props.auth?.user?.name || '');
const greeting = computed(() =>
    userName.value
        ? t('dashboard.welcome_named', { name: userName.value })
        : t('dashboard.welcome_generic')
);
const companyType = computed(() => page.props.auth?.account?.company?.type ?? null);
const showServices = computed(() => companyType.value !== 'products');
const isOwner = computed(() => Boolean(page.props.auth?.account?.is_owner));
const featureFlags = computed(() => page.props.auth?.account?.features || {});
const hasFeature = (key) => isFeatureEnabled(featureFlags.value, key);
const hasCatalogFeature = computed(() =>
    showServices.value ? hasFeature('services') : hasFeature('products')
);
const hasPlanScans = computed(() => showServices.value && hasFeature('quotes') && hasFeature('plan_scans'));
const hasTopAnnouncements = computed(() => (props.announcements || []).length > 0);
const hasQuickAnnouncements = computed(() => (props.quickAnnouncements || []).length > 0);
const billing = computed(() => props.billing || {});
const billingPlans = computed(() => billing.value.plans || []);
const billingSubscription = computed(() => billing.value.subscription || {});
const hasPlanChoices = computed(() => isOwner.value && billingPlans.value.length > 0);
const usageItems = computed(() => props.usage_limits?.items || []);
const usageAlerts = computed(() => usageItems.value.filter((item) => item.status !== 'ok'));
const hasUsageAlerts = computed(() => usageAlerts.value.length > 0);
const planName = computed(() => props.usage_limits?.plan_name || props.usage_limits?.plan_key || '');
const usagePlanSuffix = computed(() =>
    planName.value ? t('dashboard.usage.plan_suffix', { plan: planName.value }) : ''
);
const limitLabelMap = {
    quotes: 'dashboard.limits.quotes',
    requests: 'dashboard.limits.requests',
    invoices: 'dashboard.limits.invoices',
    jobs: 'dashboard.limits.jobs',
    products: 'dashboard.limits.products',
    services: 'dashboard.limits.services',
    tasks: 'dashboard.limits.tasks',
    team_members: 'dashboard.limits.team_members',
};

const isPlanActive = (plan) =>
    Boolean(billingSubscription.value?.price_id && plan?.price_id === billingSubscription.value.price_id);

const stat = (key) => props.stats?.[key] ?? 0;

const formatNumber = (value) =>
    Number(value || 0).toLocaleString(undefined, { maximumFractionDigits: 0 });

const formatCurrency = (value) =>
    `$${Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

const formatDate = (value) => humanizeDate(value);
const tasksToday = computed(() => props.tasksToday || []);
const worksToday = computed(() => props.worksToday || []);
const agendaAlerts = computed(() => props.agendaAlerts || {});
const formatTime = (value) => {
    if (!value) {
        return '';
    }
    const [hours, minutes] = value.split(':');
    if (!hours || !minutes) {
        return value;
    }
    return `${hours}:${minutes}`;
};
const formatTimeRange = (task) => {
    const start = formatTime(task.start_time);
    const end = formatTime(task.end_time);
    if (start && end) {
        return `${start} - ${end}`;
    }
    if (start) {
        return start;
    }
    if (end) {
        return end;
    }
    return t('dashboard.time.any');
};
const buildItemDateTime = (item) => {
    if (!item?.due_date) {
        return null;
    }
    const timeValue = item.start_time || item.end_time || '23:59';
    const [year, month, day] = item.due_date.split('-').map(Number);
    const [hour, minute] = timeValue.split(':').map(Number);
    if (!year || !month || !day) {
        return null;
    }
    return new Date(year, (month - 1), day, Number.isFinite(hour) ? hour : 0, Number.isFinite(minute) ? minute : 0, 0);
};
const resolvePriorityKey = (item) => {
    const dateTime = buildItemDateTime(item);
    if (!dateTime) {
        return 'low';
    }
    const diffMinutes = Math.round((dateTime.getTime() - Date.now()) / 60000);
    if (diffMinutes <= 120) {
        return 'high';
    }
    if (diffMinutes <= 360) {
        return 'medium';
    }
    return 'low';
};
const priorityConfig = computed(() => ({
    high: {
        label: t('dashboard.priority.high'),
        class: 'bg-rose-100 text-rose-700 dark:bg-rose-500/20 dark:text-rose-200',
    },
    medium: {
        label: t('dashboard.priority.medium'),
        class: 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-200',
    },
    low: {
        label: t('dashboard.priority.low'),
        class: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-200',
    },
}));
const autoBadgeConfig = computed(() => ({
    started: {
        label: t('dashboard.auto.started'),
        class: 'bg-sky-100 text-sky-700 dark:bg-sky-500/20 dark:text-sky-200',
    },
    completed: {
        label: t('dashboard.auto.completed'),
        class: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-200',
    },
}));
const resolvePriority = (task) => priorityConfig.value[resolvePriorityKey(task)];
const resolveAutoBadges = (item) => {
    const badges = [];
    if (item?.auto_started_at) {
        badges.push({
            key: `${item.key}-auto-start`,
            label: autoBadgeConfig.value.started.label,
            class: autoBadgeConfig.value.started.class,
        });
    }
    if (item?.auto_completed_at) {
        badges.push({
            key: `${item.key}-auto-complete`,
            label: autoBadgeConfig.value.completed.label,
            class: autoBadgeConfig.value.completed.class,
        });
    }
    return badges;
};
const resolveStatusLabel = (key, fallback) => {
    const label = t(key);
    return label === key ? fallback : label;
};
const quoteStatusLabel = (status) => {
    const key = status || 'draft';
    return resolveStatusLabel(`dashboard.status.quote.${key}`, key.replace('_', ' '));
};
const workStatusLabel = (status) => {
    const key = status || 'scheduled';
    return resolveStatusLabel(`dashboard.status.work.${key}`, key.replace('_', ' '));
};
const taskStatusLabel = (status) => {
    const key = status || 'todo';
    return resolveStatusLabel(`dashboard.status.task.${key}`, key.replace('_', ' '));
};
const invoiceStatusLabel = (status) => {
    const key = status || 'draft';
    return resolveStatusLabel(`dashboard.status.invoice.${key}`, key.replace('_', ' '));
};
const formatStatus = (item) => {
    if (!item?.status) {
        return '-';
    }
    return item.type === 'work' ? workStatusLabel(item.status) : taskStatusLabel(item.status);
};
const todayItems = computed(() => {
    const taskItems = (tasksToday.value || []).map((task) => ({
        ...task,
        type: 'task',
        key: `task-${task.id}`,
    }));
    const workItems = (worksToday.value || []).map((work) => ({
        ...work,
        type: 'work',
        key: `work-${work.id}`,
    }));
    const items = [...taskItems, ...workItems];
    return items.sort((a, b) => {
        const dateA = buildItemDateTime(a);
        const dateB = buildItemDateTime(b);
        if (!dateA && !dateB) {
            return 0;
        }
        if (!dateA) {
            return 1;
        }
        if (!dateB) {
            return -1;
        }
        return dateA.getTime() - dateB.getTime();
    });
});
const formatAgendaCount = (count, singularKey, pluralKey) => {
    const label = count === 1 ? t(singularKey) : t(pluralKey);
    return `${count} ${label}`;
};
const agendaAlertItems = computed(() => {
    const alerts = agendaAlerts.value || {};
    const tasksStarted = Number(alerts.tasks_started || 0);
    const worksStarted = Number(alerts.works_started || 0);
    const tasksCompleted = Number(alerts.tasks_completed || 0);
    const worksCompleted = Number(alerts.works_completed || 0);
    const items = [];

    if (tasksStarted > 0) {
        items.push({
            key: 'tasks-started',
            label: t('dashboard.agenda.auto_started', {
                count: formatAgendaCount(tasksStarted, 'dashboard.agenda.task', 'dashboard.agenda.tasks'),
            }),
            class: autoBadgeConfig.value.started.class,
        });
    }
    if (worksStarted > 0) {
        items.push({
            key: 'works-started',
            label: t('dashboard.agenda.auto_started', {
                count: formatAgendaCount(worksStarted, 'dashboard.agenda.job', 'dashboard.agenda.jobs'),
            }),
            class: autoBadgeConfig.value.started.class,
        });
    }
    if (tasksCompleted > 0) {
        items.push({
            key: 'tasks-completed',
            label: t('dashboard.agenda.auto_completed_at', {
                count: formatAgendaCount(tasksCompleted, 'dashboard.agenda.task', 'dashboard.agenda.tasks'),
                time: '18:00',
            }),
            class: autoBadgeConfig.value.completed.class,
        });
    }
    if (worksCompleted > 0) {
        items.push({
            key: 'works-completed',
            label: t('dashboard.agenda.auto_completed', {
                count: formatAgendaCount(worksCompleted, 'dashboard.agenda.job', 'dashboard.agenda.jobs'),
            }),
            class: autoBadgeConfig.value.completed.class,
        });
    }

    return items;
});
const hasAgendaAlerts = computed(() => agendaAlertItems.value.length > 0);
const customersEmpty = computed(() => stat('customers_total') <= 0);
const catalogEmpty = computed(() => stat('products_total') <= 0);
const quotesEmpty = computed(() => stat('quotes_total') <= 0);
const planScansEmpty = computed(() => stat('plan_scans_total') <= 0);

const revenueMax = computed(() => {
    const values = props.revenueSeries?.values || [];
    const maxValue = Math.max(...values, 0);
    return maxValue > 0 ? maxValue : 1;
});

const revenuePoints = computed(() => {
    const labels = props.revenueSeries?.labels || [];
    const values = props.revenueSeries?.values || [];
    return labels.map((label, index) => {
        const value = Number(values[index] || 0);
        const height = Math.max(6, Math.round((value / revenueMax.value) * 120));
        return {
            label,
            value,
            height: `${height}px`,
        };
    });
});

const kpiSeries = computed(() => props.kpiSeries || {});
const kpiConfig = {
    revenue_paid: { direction: 'up' },
    revenue_outstanding: { direction: 'down' },
    quotes_open: { direction: 'up' },
    works_in_progress: { direction: 'up' },
    customers_total: { direction: 'up' },
    products_low_stock: { direction: 'down' },
    invoices_paid: { direction: 'up' },
    inventory_value: { direction: 'up' },
};
const kpiData = computed(() => {
    const data = {};
    Object.entries(kpiConfig).forEach(([key, config]) => {
        const values = kpiSeries.value?.[key] || [];
        data[key] = {
            points: buildSparklinePoints(values),
            trend: buildTrend(values, config.direction),
        };
    });
    return data;
});

const displayCustomer = (customer) =>
    customer?.company_name ||
    `${customer?.first_name || ''} ${customer?.last_name || ''}`.trim() ||
    t('dashboard.labels.unknown_customer');

const quoteStatusClass = (status) => {
    switch (status) {
        case 'accepted':
            return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-400';
        case 'declined':
            return 'bg-red-100 text-red-800 dark:bg-red-500/10 dark:text-red-400';
        case 'sent':
            return 'bg-blue-100 text-blue-800 dark:bg-blue-500/10 dark:text-blue-400';
        default:
            return 'bg-stone-100 text-stone-700 dark:bg-neutral-700 dark:text-neutral-200';
    }
};

const jobStatusClass = (status) => {
    switch (status) {
        case 'to_schedule':
            return 'bg-amber-100 text-amber-800 dark:bg-amber-500/10 dark:text-amber-400';
        case 'scheduled':
            return 'bg-yellow-100 text-yellow-800 dark:bg-yellow-500/10 dark:text-yellow-400';
        case 'en_route':
            return 'bg-sky-100 text-sky-800 dark:bg-sky-500/10 dark:text-sky-400';
        case 'completed':
            return 'bg-lime-100 text-lime-800 dark:bg-lime-500/10 dark:text-lime-400';
        case 'in_progress':
            return 'bg-blue-100 text-blue-800 dark:bg-blue-500/10 dark:text-blue-400';
        case 'tech_complete':
            return 'bg-indigo-100 text-indigo-800 dark:bg-indigo-500/10 dark:text-indigo-400';
        case 'pending_review':
            return 'bg-violet-100 text-violet-800 dark:bg-violet-500/10 dark:text-violet-400';
        case 'validated':
            return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-400';
        case 'auto_validated':
            return 'bg-teal-100 text-teal-800 dark:bg-teal-500/10 dark:text-teal-400';
        case 'dispute':
            return 'bg-rose-100 text-rose-800 dark:bg-rose-500/10 dark:text-rose-400';
        case 'closed':
            return 'bg-slate-200 text-slate-800 dark:bg-slate-500/10 dark:text-slate-300';
        case 'cancelled':
            return 'bg-red-100 text-red-800 dark:bg-red-500/10 dark:text-red-400';
        default:
            return 'bg-stone-100 text-stone-700 dark:bg-neutral-700 dark:text-neutral-200';
    }
};

const invoiceStatusClass = (status) => {
    switch (status) {
        case 'paid':
            return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-400';
        case 'partial':
            return 'bg-amber-100 text-amber-800 dark:bg-amber-500/10 dark:text-amber-400';
        case 'overdue':
            return 'bg-red-100 text-red-800 dark:bg-red-500/10 dark:text-red-400';
        case 'sent':
            return 'bg-blue-100 text-blue-800 dark:bg-blue-500/10 dark:text-blue-400';
        default:
            return 'bg-stone-100 text-stone-700 dark:bg-neutral-700 dark:text-neutral-200';
    }
};

const displayLimitLabel = (item) => {
    const translationKey = limitLabelMap[item.key];
    if (translationKey) {
        const label = t(translationKey);
        if (label !== translationKey) {
            return label;
        }
    }
    return item.label || item.key;
};
const displayLimitValue = (item) => {
    if (item.limit === null || item.limit === undefined) {
        return t('dashboard.usage.unlimited');
    }
    if (Number(item.limit) <= 0) {
        return t('dashboard.usage.not_available');
    }
    return item.limit;
};

const onboardingChecklist = computed(() => {
    const steps = [];
    const isServices = showServices.value;

    if (hasCatalogFeature.value) {
        steps.push({
            key: 'catalog',
            label: isServices
                ? t('dashboard.onboarding.add_first_service')
                : t('dashboard.onboarding.add_first_product'),
            route: isServices ? 'service.index' : 'product.index',
            completed: stat('products_total') > 0,
        });
    }

    steps.push({
        key: 'customer',
        label: t('dashboard.onboarding.add_first_customer'),
        route: 'customer.create',
        completed: stat('customers_total') > 0,
    });

    if (hasFeature('quotes')) {
        steps.push({
            key: 'quote',
            label: t('dashboard.onboarding.create_first_quote'),
            route: 'quote.index',
            completed: stat('quotes_total') > 0,
        });
    }

    return steps;
});

const checklistCompleted = computed(() =>
    onboardingChecklist.value.filter((item) => item.completed).length
);
const checklistTotal = computed(() => onboardingChecklist.value.length);
const checklistProgress = computed(() => {
    if (!checklistTotal.value) {
        return 0;
    }
    return Math.round((checklistCompleted.value / checklistTotal.value) * 100);
});
const showChecklist = computed(() =>
    checklistTotal.value > 0 && checklistCompleted.value < checklistTotal.value
);
const showPlanScanCta = computed(() => hasPlanScans.value);

const suggestionActions = computed(() => {
    const actions = [];

    actions.push({
        key: 'customer',
        label: t('dashboard.suggestions.create_customer'),
        type: 'overlay',
        overlay: '#hs-quick-create-customer',
        priority: customersEmpty.value ? 1 : 5,
    });

    if (hasCatalogFeature.value) {
        actions.push({
            key: 'catalog',
            label: showServices.value
                ? t('dashboard.suggestions.add_service')
                : t('dashboard.suggestions.add_product'),
            type: showServices.value ? 'link' : 'overlay',
            route: showServices.value ? 'service.index' : null,
            overlay: showServices.value ? null : '#hs-quick-create-product',
            priority: catalogEmpty.value ? 2 : 6,
        });
    }

    if (hasFeature('quotes')) {
        actions.push({
            key: 'quote',
            label: t('dashboard.suggestions.create_quote'),
            type: 'overlay',
            overlay: '#hs-quick-create-quote',
            priority: quotesEmpty.value ? 3 : 7,
        });
    }

    if (hasPlanScans.value) {
        actions.push({
            key: 'plan_scan',
            label: t('dashboard.suggestions.import_plan'),
            type: 'link',
            route: 'plan-scans.create',
            priority: planScansEmpty.value ? 4 : 8,
        });
    }

    if (showServices.value && isOwner.value && hasFeature('requests')) {
        actions.push({
            key: 'request',
            label: t('dashboard.suggestions.create_request'),
            type: 'overlay',
            overlay: '#hs-quick-create-request',
            priority: 9,
        });
    }

    if (hasFeature('jobs')) {
        actions.push({
            key: 'jobs',
            label: t('dashboard.suggestions.review_jobs'),
            type: 'link',
            route: 'jobs.index',
            priority: 10,
        });
    }

    return actions.sort((a, b) => a.priority - b.priority);
});

const primaryAction = computed(() => suggestionActions.value[0] || null);
const secondaryActions = computed(() => suggestionActions.value.slice(1, 5));
</script>

<template>
    <Head :title="$t('dashboard.title')" />

    <AuthenticatedLayout>
        <div class="space-y-6">
            <section
                class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div class="space-y-1">
                        <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">
                            {{ $t('dashboard.title') }}
                        </h1>
                        <p class="text-sm text-stone-600 dark:text-neutral-400">
                            {{ greeting }}
                        </p>
                        <div class="flex flex-wrap gap-3 text-xs text-stone-500 dark:text-neutral-400">
                            <span v-if="hasFeature('quotes')">
                                {{ $t('dashboard.meta.quotes_month', { count: formatNumber(stat('quotes_month')) }) }}
                            </span>
                            <span v-if="hasFeature('invoices')">
                                {{ $t('dashboard.meta.payments_month', { amount: formatCurrency(stat('payments_month')) }) }}
                            </span>
                        </div>
                    </div>
                    <!-- <div class="flex flex-wrap items-center gap-2">
                        <button type="button" data-hs-overlay="#hs-quick-create-quote"
                            class="py-2 px-3 inline-flex items-center gap-x-2 text-xs font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700">
                            New quote
                        </button>
                        <button v-if="showServices && isOwner" type="button" data-hs-overlay="#hs-quick-create-request"
                            class="py-2 px-3 inline-flex items-center gap-x-2 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200">
                            New request
                        </button>
                        <button type="button" data-hs-overlay="#hs-quick-create-customer"
                            class="py-2 px-3 inline-flex items-center gap-x-2 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200">
                            New customer
                        </button>
                        <button type="button" data-hs-overlay="#hs-quick-create-product"
                            class="py-2 px-3 inline-flex items-center gap-x-2 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200">
                            New product
                        </button>
                    </div> -->
                </div>
            </section>

            <section v-if="hasUsageAlerts" class="rounded-sm border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-200">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <div class="font-semibold">{{ $t('dashboard.usage.title') }}</div>
                        <p class="text-xs text-amber-700 dark:text-amber-200">
                            {{ $t('dashboard.usage.description', { planSuffix: usagePlanSuffix }) }}
                        </p>
                    </div>
                    <Link :href="route('settings.company.edit')" class="text-xs font-semibold text-amber-800 hover:underline dark:text-amber-200">
                        {{ $t('dashboard.usage.view_limits') }}
                    </Link>
                </div>
                <div class="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                    <div v-for="item in usageAlerts" :key="item.key" class="rounded-sm border border-amber-200 bg-white px-3 py-2 text-xs text-amber-800 dark:border-amber-500/30 dark:bg-neutral-900 dark:text-amber-200">
                        <div class="font-semibold">{{ displayLimitLabel(item) }}</div>
                        <div class="mt-1 text-[11px] text-amber-700 dark:text-amber-200">
                            {{ item.used }} / {{ displayLimitValue(item) }}
                            <span v-if="item.percent !== null">({{ item.percent }}%)</span>
                        </div>
                    </div>
                </div>
            </section>

            <div :class="['grid gap-4', hasTopAnnouncements ? 'xl:grid-cols-[minmax(0,1fr)_320px]' : 'grid-cols-1']">
                <section class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-3" data-testid="demo-dashboard-overview">
                <div v-if="hasFeature('invoices')"
                    class="p-4 bg-white border border-t-4 border-t-emerald-600 border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                    <div class="space-y-1">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-xs text-stone-500 dark:text-neutral-400">
                                {{ $t('dashboard.kpi.revenue_paid') }}
                            </p>
                            <KpiTrendBadge :trend="kpiData.revenue_paid.trend" />
                        </div>
                        <p class="text-lg font-semibold text-stone-800 dark:text-neutral-100">
                            {{ formatCurrency(stat('revenue_paid')) }}
                        </p>
                        <p class="text-xs text-stone-500 dark:text-neutral-400">
                            {{ $t('dashboard.kpi.revenue_billed', { amount: formatCurrency(stat('revenue_billed')) }) }}
                        </p>
                        <KpiSparkline
                            :points="kpiData.revenue_paid.points"
                            color-class="bg-emerald-500/70 dark:bg-emerald-400/50"
                        />
                    </div>
                </div>
                <div v-if="hasFeature('invoices')"
                    class="p-4 bg-white border border-t-4 border-t-amber-600 border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                    <div class="space-y-1">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-xs text-stone-500 dark:text-neutral-400">
                                {{ $t('dashboard.kpi.outstanding_balance') }}
                            </p>
                            <KpiTrendBadge :trend="kpiData.revenue_outstanding.trend" />
                        </div>
                        <p class="text-lg font-semibold text-stone-800 dark:text-neutral-100">
                            {{ formatCurrency(stat('revenue_outstanding')) }}
                        </p>
                        <p class="text-xs text-stone-500 dark:text-neutral-400">
                            {{ $t('dashboard.kpi.partial_invoices', { count: formatNumber(stat('invoices_partial')) }) }}
                        </p>
                        <KpiSparkline
                            :points="kpiData.revenue_outstanding.points"
                            color-class="bg-amber-500/70 dark:bg-amber-400/50"
                        />
                    </div>
                </div>
                <div v-if="hasFeature('quotes')"
                    class="p-4 bg-white border border-t-4 border-t-blue-600 border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                    <div class="space-y-1">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-xs text-stone-500 dark:text-neutral-400">
                                {{ $t('dashboard.kpi.open_quotes') }}
                            </p>
                            <KpiTrendBadge :trend="kpiData.quotes_open.trend" />
                        </div>
                        <p class="text-lg font-semibold text-stone-800 dark:text-neutral-100">
                            {{ formatNumber(stat('quotes_open')) }}
                        </p>
                        <p class="text-xs text-stone-500 dark:text-neutral-400">
                            {{ $t('dashboard.kpi.accepted_quotes', { count: formatNumber(stat('quotes_accepted')) }) }}
                        </p>
                        <KpiSparkline
                            :points="kpiData.quotes_open.points"
                            color-class="bg-blue-500/70 dark:bg-blue-400/50"
                        />
                    </div>
                </div>
                <div v-if="hasFeature('jobs')"
                    class="p-4 bg-white border border-t-4 border-t-indigo-600 border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                    <div class="space-y-1">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-xs text-stone-500 dark:text-neutral-400">
                                {{ $t('dashboard.kpi.jobs_in_progress') }}
                            </p>
                            <KpiTrendBadge :trend="kpiData.works_in_progress.trend" />
                        </div>
                        <p class="text-lg font-semibold text-stone-800 dark:text-neutral-100">
                            {{ formatNumber(stat('works_in_progress')) }}
                        </p>
                        <p class="text-xs text-stone-500 dark:text-neutral-400">
                            {{ $t('dashboard.kpi.jobs_scheduled', { count: formatNumber(stat('works_scheduled')) }) }}
                        </p>
                        <KpiSparkline
                            :points="kpiData.works_in_progress.points"
                            color-class="bg-indigo-500/70 dark:bg-indigo-400/50"
                        />
                    </div>
                </div>
                <div
                    class="p-4 bg-white border border-t-4 border-t-sky-600 border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                    <div class="space-y-1">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-xs text-stone-500 dark:text-neutral-400">
                                {{ $t('dashboard.kpi.customers') }}
                            </p>
                            <KpiTrendBadge :trend="kpiData.customers_total.trend" />
                        </div>
                        <p class="text-lg font-semibold text-stone-800 dark:text-neutral-100">
                            {{ formatNumber(stat('customers_total')) }}
                        </p>
                        <p class="text-xs text-stone-500 dark:text-neutral-400">
                            {{ $t('dashboard.kpi.customers_new', { count: formatNumber(stat('customers_new')) }) }}
                        </p>
                        <KpiSparkline
                            :points="kpiData.customers_total.points"
                            color-class="bg-sky-500/70 dark:bg-sky-400/50"
                        />
                    </div>
                </div>
                <div v-if="hasFeature('products')"
                    class="p-4 bg-white border border-t-4 border-t-red-600 border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                    <div class="space-y-1">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-xs text-stone-500 dark:text-neutral-400">
                                {{ $t('dashboard.kpi.low_stock') }}
                            </p>
                            <KpiTrendBadge :trend="kpiData.products_low_stock.trend" />
                        </div>
                        <p class="text-lg font-semibold text-stone-800 dark:text-neutral-100">
                            {{ formatNumber(stat('products_low_stock')) }}
                        </p>
                        <p class="text-xs text-stone-500 dark:text-neutral-400">
                            {{ $t('dashboard.kpi.out_of_stock', { count: formatNumber(stat('products_out')) }) }}
                        </p>
                        <KpiSparkline
                            :points="kpiData.products_low_stock.points"
                            color-class="bg-red-500/70 dark:bg-red-400/50"
                        />
                    </div>
                </div>
                <div v-if="hasFeature('invoices')"
                    class="p-4 bg-white border border-t-4 border-t-teal-600 border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                    <div class="space-y-1">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-xs text-stone-500 dark:text-neutral-400">
                                {{ $t('dashboard.kpi.invoices_paid') }}
                            </p>
                            <KpiTrendBadge :trend="kpiData.invoices_paid.trend" />
                        </div>
                        <p class="text-lg font-semibold text-stone-800 dark:text-neutral-100">
                            {{ formatNumber(stat('invoices_paid')) }}
                        </p>
                        <p class="text-xs text-stone-500 dark:text-neutral-400">
                            {{ $t('dashboard.kpi.invoices_total', { count: formatNumber(stat('invoices_total')) }) }}
                        </p>
                        <KpiSparkline
                            :points="kpiData.invoices_paid.points"
                            color-class="bg-teal-500/70 dark:bg-teal-400/50"
                        />
                    </div>
                </div>
                <div v-if="hasFeature('products')"
                    class="p-4 bg-white border border-t-4 border-t-stone-600 border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                    <div class="space-y-1">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-xs text-stone-500 dark:text-neutral-400">
                                {{ $t('dashboard.kpi.inventory_value') }}
                            </p>
                            <KpiTrendBadge :trend="kpiData.inventory_value.trend" />
                        </div>
                        <p class="text-lg font-semibold text-stone-800 dark:text-neutral-100">
                            {{ formatCurrency(stat('inventory_value')) }}
                        </p>
                        <p class="text-xs text-stone-500 dark:text-neutral-400">
                            {{ $t('dashboard.kpi.products_total', { count: formatNumber(stat('products_total')) }) }}
                        </p>
                        <KpiSparkline
                            :points="kpiData.inventory_value.points"
                            color-class="bg-stone-500/70 dark:bg-stone-400/50"
                        />
                    </div>
                </div>
                </section>
                <AnnouncementsPanel
                    v-if="hasTopAnnouncements"
                    :announcements="announcements"
                    variant="side"
                    :title="$t('dashboard.announcements.title')"
                    :subtitle="$t('dashboard.announcements.subtitle')"
                    :limit="3"
                />
            </div>

            <section class="grid grid-cols-1 xl:grid-cols-3 gap-4">
                <div class="xl:col-span-2 space-y-4">
                    <div v-if="hasFeature('tasks') || hasFeature('jobs')" class="bg-white border border-stone-200 rounded-sm p-5 shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                    {{ $t('dashboard.timeline.title') }}
                                </h2>
                                <p class="text-xs text-stone-500 dark:text-neutral-400">
                                    {{ $t('dashboard.timeline.subtitle') }}
                                </p>
                            </div>
                            <div class="flex items-center gap-3">
                                <a
                                    :href="route('tasks.calendar')"
                                    class="text-xs font-medium text-emerald-600 hover:text-emerald-700"
                                    target="_blank"
                                    rel="noreferrer"
                                    download
                                >
                                    {{ $t('dashboard.timeline.sync_calendar') }}
                                </a>
                                <Link :href="route('task.index')" class="text-xs font-medium text-stone-500 hover:text-stone-700 dark:text-neutral-400 dark:hover:text-neutral-200">
                                    {{ $t('dashboard.timeline.view_tasks') }}
                                </Link>
                            </div>
                        </div>
                        <div class="mt-4">
                            <div v-if="hasAgendaAlerts" class="mb-3 rounded-sm border border-sky-200 bg-sky-50 p-3 text-xs text-sky-800 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-200">
                                <div class="font-semibold">{{ $t('dashboard.timeline.auto_alerts') }}</div>
                                <div class="mt-2 flex flex-wrap gap-2">
                                    <span
                                        v-for="item in agendaAlertItems"
                                        :key="item.key"
                                        :class="['rounded-full px-2 py-0.5 text-[11px] font-medium', item.class]"
                                    >
                                        {{ item.label }}
                                    </span>
                                </div>
                            </div>
                            <div v-if="!todayItems.length" class="text-sm text-stone-500 dark:text-neutral-400">
                                {{ $t('dashboard.timeline.empty') }}
                            </div>
                            <div v-else class="space-y-3">
                                <div v-for="(item, index) in todayItems" :key="item.key" class="flex gap-3">
                                    <div class="flex flex-col items-center">
                                        <div class="mt-1 h-2 w-2 rounded-full bg-emerald-500"></div>
                                        <div v-if="index < todayItems.length - 1" class="mt-1 flex-1 w-px bg-stone-200 dark:bg-neutral-700"></div>
                                    </div>
                                    <div class="flex-1 rounded-sm border border-stone-200 p-3 text-sm dark:border-neutral-700">
                                        <div class="flex items-start justify-between gap-3">
                                            <div class="min-w-0">
                                                <div class="text-xs text-stone-500 dark:text-neutral-400">
                                                    {{ formatTimeRange(item) }}
                                                </div>
                                                <div class="truncate font-medium text-stone-900 dark:text-neutral-100">
                                                    {{ item.title || (item.type === 'work' ? $t('dashboard.labels.job') : $t('dashboard.labels.task')) }}
                                                </div>
                                            </div>
                                            <div class="flex flex-col items-end gap-1">
                                                <span :class="['rounded-full px-2 py-0.5 text-xs font-medium', resolvePriority(item).class]">
                                                    {{ resolvePriority(item).label }}
                                                </span>
                                                <span
                                                    v-for="badge in resolveAutoBadges(item)"
                                                    :key="badge.key"
                                                    :class="['rounded-full px-2 py-0.5 text-xs font-medium', badge.class]"
                                                >
                                                    {{ badge.label }}
                                                </span>
                                                <span class="text-[11px] uppercase text-stone-400 dark:text-neutral-500">
                                                    {{ item.type === 'work' ? $t('dashboard.labels.job') : $t('dashboard.labels.task') }}
                                                </span>
                                                <span class="text-[11px] uppercase text-stone-400 dark:text-neutral-500">
                                                    {{ formatStatus(item) }}
                                                </span>
                                            </div>
                                        </div>
                                        <div v-if="item.assignee?.name" class="mt-2 text-xs text-stone-500 dark:text-neutral-400">
                                            {{ $t('dashboard.labels.assignee', { name: item.assignee.name }) }}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div v-if="hasFeature('invoices')" class="bg-white border border-stone-200 rounded-sm p-5 shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                    {{ $t('dashboard.revenue.title') }}
                                </h2>
                                <p class="text-xs text-stone-500 dark:text-neutral-400">
                                    {{ $t('dashboard.revenue.subtitle') }}
                                </p>
                            </div>
                            <Link :href="route('invoice.index')"
                                class="text-xs font-medium text-green-600 hover:text-green-700">
                                {{ $t('dashboard.revenue.view_invoices') }}
                            </Link>
                        </div>
                        <div class="mt-4 flex items-end gap-2 h-36">
                            <div v-for="point in revenuePoints" :key="point.label"
                                class="flex-1 flex flex-col items-center gap-2">
                                <div class="w-full rounded-sm bg-emerald-200 dark:bg-emerald-500/30"
                                    :style="{ height: point.height }"></div>
                                <div class="text-[11px] text-stone-500 dark:text-neutral-400">
                                    {{ point.label }}
                                </div>
                            </div>
                        </div>
                        <div class="mt-4 grid grid-cols-1 md:grid-cols-3 gap-3 text-sm">
                            <div>
                                <div class="text-xs text-stone-500 dark:text-neutral-400">
                                    {{ $t('dashboard.revenue.paid_to_date') }}
                                </div>
                                <div class="font-semibold text-stone-800 dark:text-neutral-100">
                                    {{ formatCurrency(stat('revenue_paid')) }}
                                </div>
                            </div>
                            <div>
                                <div class="text-xs text-stone-500 dark:text-neutral-400">
                                    {{ $t('dashboard.revenue.outstanding') }}
                                </div>
                                <div class="font-semibold text-stone-800 dark:text-neutral-100">
                                    {{ formatCurrency(stat('revenue_outstanding')) }}
                                </div>
                            </div>
                            <div>
                                <div class="text-xs text-stone-500 dark:text-neutral-400">
                                    {{ $t('dashboard.revenue.overdue') }}
                                </div>
                                <div class="font-semibold text-stone-800 dark:text-neutral-100">
                                    {{ formatNumber(stat('invoices_overdue')) }}
                                </div>
                            </div>
                        </div>
                    </div>

                    <div v-if="hasFeature('quotes')" class="bg-white border border-stone-200 rounded-sm p-5 shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                        <div class="flex items-center justify-between">
                            <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                {{ $t('dashboard.sections.recent_quotes') }}
                            </h2>
                            <Link :href="route('quote.index')" class="text-xs font-medium text-green-600 hover:text-green-700">
                                {{ $t('dashboard.actions.view_all') }}
                            </Link>
                        </div>
                        <div class="mt-3 overflow-x-auto">
                            <table class="min-w-full divide-y divide-stone-200 dark:divide-neutral-700">
                                <thead>
                                    <tr>
                                        <th class="py-2 text-left text-xs font-medium text-stone-500 dark:text-neutral-400">
                                            {{ $t('dashboard.table.quote') }}
                                        </th>
                                        <th class="py-2 text-left text-xs font-medium text-stone-500 dark:text-neutral-400">
                                            {{ $t('dashboard.table.customer') }}
                                        </th>
                                        <th class="py-2 text-left text-xs font-medium text-stone-500 dark:text-neutral-400">
                                            {{ $t('dashboard.table.status') }}
                                        </th>
                                        <th class="py-2 text-right text-xs font-medium text-stone-500 dark:text-neutral-400">
                                            {{ $t('dashboard.table.total') }}
                                        </th>
                                        <th class="py-2 text-right text-xs font-medium text-stone-500 dark:text-neutral-400">
                                            {{ $t('dashboard.table.created') }}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-stone-200 dark:divide-neutral-700">
                                    <tr v-for="quote in recentQuotes" :key="quote.id">
                                        <td class="py-2 text-sm text-stone-700 dark:text-neutral-200">
                                            <Link :href="route('customer.quote.show', quote.id)" class="hover:underline">
                                                {{ quote.number || $t('dashboard.labels.quote_fallback') }}
                                            </Link>
                                        </td>
                                        <td class="py-2 text-sm text-stone-600 dark:text-neutral-300">
                                            {{ displayCustomer(quote.customer) }}
                                        </td>
                                        <td class="py-2">
                                            <span class="px-2 py-1 text-xs font-medium rounded-full"
                                                :class="quoteStatusClass(quote.status)">
                                                {{ quoteStatusLabel(quote.status) }}
                                            </span>
                                        </td>
                                        <td class="py-2 text-sm text-right text-stone-600 dark:text-neutral-300">
                                            {{ formatCurrency(quote.total) }}
                                        </td>
                                        <td class="py-2 text-sm text-right text-stone-500 dark:text-neutral-400">
                                            {{ formatDate(quote.created_at) }}
                                        </td>
                                    </tr>
                                    <tr v-if="!recentQuotes.length">
                                        <td colspan="5" class="py-4 text-sm text-center text-stone-500 dark:text-neutral-400">
                                            {{ $t('dashboard.empty.quotes') }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div v-if="hasFeature('jobs')" class="bg-white border border-stone-200 rounded-sm p-5 shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                        <div class="flex items-center justify-between">
                            <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                {{ $t('dashboard.sections.upcoming_jobs') }}
                            </h2>
                            <Link :href="route('jobs.index')" class="text-xs font-medium text-green-600 hover:text-green-700">
                                {{ $t('dashboard.actions.view_all') }}
                            </Link>
                        </div>
                        <div class="mt-3 space-y-3">
                            <div v-for="job in upcomingJobs" :key="job.id"
                                class="flex flex-wrap items-center justify-between gap-3 rounded-sm border border-stone-200 px-3 py-2 text-sm dark:border-neutral-700">
                                <div>
                                    <Link :href="route('work.show', job.id)" class="font-medium text-stone-800 hover:underline dark:text-neutral-200">
                                        {{ job.job_title }}
                                    </Link>
                                    <div class="text-xs text-stone-500 dark:text-neutral-400">
                                        {{ displayCustomer(job.customer) }}
                                    </div>
                                </div>
                                <div class="flex items-center gap-3">
                                    <span class="text-xs text-stone-500 dark:text-neutral-400">
                                        {{ formatDate(job.start_date) }} {{ job.start_time || '' }}
                                    </span>
                                    <span class="px-2 py-1 text-xs font-medium rounded-full"
                                        :class="jobStatusClass(job.status)">
                                        {{ workStatusLabel(job.status) }}
                                    </span>
                                </div>
                            </div>
                            <div v-if="!upcomingJobs.length" class="text-sm text-stone-500 dark:text-neutral-400">
                                {{ $t('dashboard.empty.jobs') }}
                            </div>
                        </div>
                    </div>
                </div>

                <div class="space-y-4">
                    <div
                        v-if="showChecklist"
                        class="bg-white border border-stone-200 rounded-sm p-5 shadow-sm dark:bg-neutral-800 dark:border-neutral-700"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                    {{ $t('dashboard.checklist.title') }}
                                </h2>
                                <p class="text-xs text-stone-500 dark:text-neutral-400">
                                    {{ $t('dashboard.checklist.subtitle') }}
                                </p>
                            </div>
                            <div class="text-xs font-semibold text-emerald-700 dark:text-emerald-300">
                                {{ checklistCompleted }}/{{ checklistTotal }}
                            </div>
                        </div>
                        <div class="mt-3 h-1.5 w-full rounded-full bg-stone-100 dark:bg-neutral-700">
                            <div
                                class="h-1.5 rounded-full bg-emerald-500 dark:bg-emerald-400"
                                :style="{ width: `${checklistProgress}%` }"
                            ></div>
                        </div>
                        <ul class="mt-4 space-y-2 text-sm">
                            <li
                                v-for="item in onboardingChecklist"
                                :key="item.key"
                                class="flex items-start gap-3 rounded-sm border px-3 py-2"
                                :class="item.completed
                                    ? 'border-emerald-200 bg-emerald-50 dark:border-emerald-500/30 dark:bg-emerald-500/10'
                                    : 'border-stone-200 bg-white dark:border-neutral-700 dark:bg-neutral-800'"
                            >
                                <span
                                    class="mt-1 size-2 rounded-full"
                                    :class="item.completed ? 'bg-emerald-600' : 'bg-stone-300 dark:bg-neutral-500'"
                                ></span>
                                <Link
                                    :href="route(item.route)"
                                    class="flex-1 text-sm font-medium"
                                    :class="item.completed
                                        ? 'text-emerald-800 dark:text-emerald-200'
                                        : 'text-stone-700 dark:text-neutral-200'"
                                >
                                    {{ item.label }}
                                </Link>
                                <span v-if="item.completed" class="text-[11px] font-semibold text-emerald-700 dark:text-emerald-300">
                                    {{ $t('dashboard.checklist.done') }}
                                </span>
                            </li>
                        </ul>
                        <div
                            v-if="showPlanScanCta"
                            class="mt-4 rounded-sm border border-stone-200 bg-white px-3 py-3 text-xs text-stone-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300"
                        >
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <div>
                                    <div class="font-semibold text-stone-700 dark:text-neutral-100">
                                        {{ $t('dashboard.checklist.plan_scans_title') }}
                                    </div>
                                    <div class="text-[11px] text-stone-500 dark:text-neutral-400">
                                        {{ planScansEmpty
                                            ? $t('dashboard.checklist.plan_scans_empty')
                                            : $t('dashboard.checklist.plan_scans_count', { count: formatNumber(stat('plan_scans_total')) }) }}
                                    </div>
                                </div>
                                <Link
                                    :href="route('plan-scans.create')"
                                    class="rounded-sm border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-[11px] font-semibold text-emerald-800 hover:bg-emerald-100 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200 dark:hover:bg-emerald-500/20"
                                >
                                    {{ $t('dashboard.checklist.plan_scans_cta') }}
                                </Link>
                            </div>
                        </div>
                    </div>
                    <AnnouncementsPanel
                        v-if="hasQuickAnnouncements"
                        :announcements="quickAnnouncements"
                        variant="side"
                        :fill-height="false"
                        :title="$t('dashboard.announcements.quick_title')"
                        :subtitle="$t('dashboard.announcements.quick_subtitle')"
                        :limit="3"
                    />
                    <div v-else class="bg-white border border-stone-200 rounded-sm p-5 shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                        <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                            {{ $t('dashboard.quick_actions.title') }}
                        </h2>
                        <div class="mt-4 space-y-3 text-sm">
                            <div v-if="primaryAction">
                                <button
                                    v-if="primaryAction.type === 'overlay'"
                                    type="button"
                                    :data-hs-overlay="primaryAction.overlay"
                                    class="w-full rounded-sm border border-emerald-600 bg-emerald-600 px-3 py-2 text-white hover:bg-emerald-700"
                                >
                                    <div class="flex items-center justify-between">
                                        <span class="text-xs font-semibold uppercase tracking-wide">
                                            + {{ $t('dashboard.quick_actions.create') }}
                                        </span>
                                        <span class="text-[11px] font-medium text-emerald-100">
                                            {{ $t('dashboard.quick_actions.suggested', { label: primaryAction.label }) }}
                                        </span>
                                    </div>
                                </button>
                                <Link
                                    v-else
                                    :href="route(primaryAction.route)"
                                    class="block w-full rounded-sm border border-emerald-600 bg-emerald-600 px-3 py-2 text-white hover:bg-emerald-700"
                                >
                                    <div class="flex items-center justify-between">
                                        <span class="text-xs font-semibold uppercase tracking-wide">
                                            + {{ $t('dashboard.quick_actions.create') }}
                                        </span>
                                        <span class="text-[11px] font-medium text-emerald-100">
                                            {{ $t('dashboard.quick_actions.suggested', { label: primaryAction.label }) }}
                                        </span>
                                    </div>
                                </Link>
                            </div>
                            <div v-if="secondaryActions.length" class="space-y-2">
                                <div class="text-xs text-stone-500 dark:text-neutral-400">
                                    {{ $t('dashboard.quick_actions.suggested_next') }}
                                </div>
                                <div class="grid grid-cols-1 gap-2 text-sm">
                                    <template v-for="action in secondaryActions" :key="action.key">
                                        <button
                                            v-if="action.type === 'overlay'"
                                            type="button"
                                            :data-hs-overlay="action.overlay"
                                            class="py-2 px-3 rounded-sm border border-stone-200 bg-stone-100 text-stone-700 hover:bg-stone-200 dark:bg-neutral-700 dark:border-neutral-600 dark:text-neutral-200"
                                        >
                                            {{ action.label }}
                                        </button>
                                        <Link
                                            v-else
                                            :href="route(action.route)"
                                            class="py-2 px-3 rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200"
                                        >
                                            {{ action.label }}
                                        </Link>
                                    </template>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div v-if="hasFeature('invoices')" class="bg-white border border-stone-200 rounded-sm p-5 shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                        <div class="flex items-center justify-between">
                            <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                {{ $t('dashboard.sections.outstanding_invoices') }}
                            </h2>
                            <Link :href="route('invoice.index')" class="text-xs font-medium text-green-600 hover:text-green-700">
                                {{ $t('dashboard.actions.view_all') }}
                            </Link>
                        </div>
                        <div class="mt-3 space-y-3">
                            <div v-for="invoice in outstandingInvoices" :key="invoice.id"
                                class="flex items-center justify-between gap-3 rounded-sm border border-stone-200 px-3 py-2 text-sm dark:border-neutral-700">
                                <div>
                                    <Link :href="route('invoice.show', invoice.id)" class="font-medium text-stone-800 hover:underline dark:text-neutral-200">
                                        {{ invoice.number || $t('dashboard.labels.invoice_fallback') }}
                                    </Link>
                                    <div class="text-xs text-stone-500 dark:text-neutral-400">
                                        {{ displayCustomer(invoice.customer) }}
                                    </div>
                                </div>
                                <div class="text-right">
                                    <div class="text-sm font-medium text-stone-800 dark:text-neutral-200">
                                        {{ formatCurrency(invoice.balance_due) }}
                                    </div>
                                    <span class="px-2 py-0.5 text-xs font-medium rounded-full"
                                        :class="invoiceStatusClass(invoice.status)">
                                        {{ invoiceStatusLabel(invoice.status) }}
                                    </span>
                                </div>
                            </div>
                            <div v-if="!outstandingInvoices.length" class="text-sm text-stone-500 dark:text-neutral-400">
                                {{ $t('dashboard.empty.invoices') }}
                            </div>
                        </div>
                    </div>

                    <div class="bg-white border border-stone-200 rounded-sm p-5 shadow-sm dark:bg-neutral-800 dark:border-neutral-700" data-testid="demo-dashboard-activity">
                        <div class="flex items-center justify-between">
                            <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                {{ $t('dashboard.sections.recent_activity') }}
                            </h2>
                        </div>
                        <div class="mt-3 space-y-3 text-sm">
                            <div v-for="log in activity" :key="log.id"
                                class="rounded-sm border border-stone-200 px-3 py-2 dark:border-neutral-700">
                                <div class="text-xs uppercase text-stone-500 dark:text-neutral-400">
                                    {{ log.subject }} - {{ formatDate(log.created_at) }}
                                </div>
                                <div class="text-sm text-stone-700 dark:text-neutral-200">
                                    {{ log.description || log.action }}
                                </div>
                            </div>
                            <div v-if="!activity.length" class="text-sm text-stone-500 dark:text-neutral-400">
                                {{ $t('dashboard.empty.activity') }}
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
