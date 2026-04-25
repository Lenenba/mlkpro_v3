<script setup>
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AnnouncementsPanel from '@/Components/Dashboard/AnnouncementsPanel.vue';
import KpiCompositePanel from '@/Components/Dashboard/KpiCompositePanel.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { humanizeDate } from '@/utils/date';
import { buildSparklinePoints, buildTrend } from '@/utils/kpi';
import { useCurrencyFormatter } from '@/utils/currency';
import { useAccountFeatures } from '@/Composables/useAccountFeatures';

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
    weekSchedule: {
        type: Object,
        default: () => ({ days: [], rows: [], summary: {} }),
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
        default: () => ({ labels: [], values: [], expenseValues: [] }),
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
    marketingKpis: {
        type: Object,
        default: null,
    },
    financeSummary: {
        type: Object,
        default: () => ({}),
    },
});

const page = usePage();
const { t, locale } = useI18n();
const { hasFeature } = useAccountFeatures();
const userName = computed(() => page.props.auth?.user?.name || '');
const greeting = computed(() =>
    userName.value
        ? t('dashboard.welcome_named', { name: userName.value })
        : t('dashboard.welcome_generic')
);
const companyType = computed(() => page.props.auth?.account?.company?.type ?? null);
const showServices = computed(() => companyType.value !== 'products');
const isOwner = computed(() => Boolean(page.props.auth?.account?.is_owner));
const teamPermissions = computed(() => page.props.auth?.account?.team?.permissions || []);
const hasAnyPermission = (permissions = []) => permissions.some((permission) => teamPermissions.value.includes(permission));
const canQuotes = computed(() => isOwner.value || hasAnyPermission(['quotes.view', 'quotes.edit', 'quotes.send']));
const canSalesManage = computed(() => isOwner.value || hasAnyPermission(['sales.manage']));
const canJobs = computed(() => isOwner.value || hasAnyPermission(['jobs.view', 'jobs.edit']));
const canTasks = computed(() => isOwner.value || hasAnyPermission(['tasks.view', 'tasks.create', 'tasks.edit', 'tasks.delete']));
const canInvoices = computed(() => isOwner.value || hasAnyPermission([
    'invoices.view',
    'invoices.create',
    'invoices.edit',
    'invoices.approve',
    'invoices.approve_high',
]));
const canExpenses = computed(() => isOwner.value || hasAnyPermission([
    'expenses.view',
    'expenses.create',
    'expenses.edit',
    'expenses.approve',
    'expenses.approve_high',
    'expenses.pay',
]));
const canCampaigns = computed(() => isOwner.value || hasAnyPermission([
    'campaigns.view',
    'campaigns.manage',
    'campaigns.send',
]));
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

const isPlanActive = (plan) =>
    Boolean(billingSubscription.value?.price_id && plan?.price_id === billingSubscription.value.price_id);

const stat = (key) => props.stats?.[key] ?? 0;

const formatNumber = (value) =>
    Number(value || 0).toLocaleString(undefined, { maximumFractionDigits: 0 });

const { formatCurrency } = useCurrencyFormatter();
const formatPercent = (value, maxFractionDigits = 2) => {
    if (value === null || value === undefined || Number.isNaN(Number(value))) {
        return '-';
    }

    return `${Number(value).toLocaleString(undefined, {
        minimumFractionDigits: 0,
        maximumFractionDigits: maxFractionDigits,
    })}%`;
};

const formatDate = (value) => humanizeDate(value);
const marketingKpiPayload = computed(() => (
    hasFeature('campaigns') && canCampaigns.value ? (props.marketingKpis || null) : null
));
const marketingRange = computed(() => marketingKpiPayload.value?.range || null);
const marketingMetrics = computed(() => marketingKpiPayload.value?.marketing || null);
const marketingCrossModule = computed(() => marketingKpiPayload.value?.cross_module || null);
const hasMarketingKpis = computed(() => Boolean(marketingMetrics.value));
const marketingPanelStorageKey = computed(() => `dashboard:marketing-panel:${companyType.value || 'services'}`);
const showMarketingPanel = ref(false);
const marketingCards = computed(() => {
    if (!marketingMetrics.value) {
        return [];
    }

    return [
        {
            key: 'campaigns_sent',
            label: t('dashboard.marketing_panel.cards.campaigns_sent'),
            value: formatNumber(marketingMetrics.value.campaigns_sent || 0),
        },
        {
            key: 'delivery_success_rate',
            label: t('dashboard.marketing_panel.cards.delivery_success_rate'),
            value: formatPercent(marketingMetrics.value.delivery_success_rate),
        },
        {
            key: 'click_rate',
            label: t('dashboard.marketing_panel.cards.click_rate'),
            value: marketingMetrics.value.click_rate === null
                ? t('dashboard.marketing_panel.cards.tracking_off')
                : formatPercent(marketingMetrics.value.click_rate),
        },
        {
            key: 'conversions_attributed',
            label: t('dashboard.marketing_panel.cards.conversions_attributed'),
            value: formatNumber(marketingMetrics.value.conversions_attributed || 0),
        },
    ];
});
const audienceGrowthDelta = computed(() => {
    const delta = Number(marketingMetrics.value?.audience_growth?.delta || 0);
    if (delta > 0) {
        return `+${formatNumber(delta)}`;
    }

    return formatNumber(delta);
});
const audienceGrowthDeltaClass = computed(() => {
    const delta = Number(marketingMetrics.value?.audience_growth?.delta || 0);
    if (delta > 0) {
        return 'text-emerald-700 dark:text-emerald-300';
    }
    if (delta < 0) {
        return 'text-rose-700 dark:text-rose-300';
    }

    return 'text-stone-600 dark:text-neutral-300';
});
const setMarketingPanelVisibility = (visible) => {
    showMarketingPanel.value = visible;

    if (typeof window === 'undefined') {
        return;
    }

    window.localStorage.setItem(marketingPanelStorageKey.value, visible ? '1' : '0');
};
const toggleMarketingPanel = () => setMarketingPanelVisibility(!showMarketingPanel.value);
const recentQuotes = computed(() => (hasFeature('quotes') && canQuotes.value ? (props.recentQuotes || []) : []));
const upcomingJobs = computed(() => (hasFeature('jobs') && canJobs.value ? (props.upcomingJobs || []) : []));
const tasksToday = computed(() => (hasFeature('tasks') && canTasks.value ? (props.tasksToday || []) : []));
const worksToday = computed(() => (hasFeature('jobs') && canJobs.value ? (props.worksToday || []) : []));
const canViewWeekPlanning = computed(() => (
    (hasFeature('tasks') && canTasks.value) || (hasFeature('jobs') && canJobs.value)
));
const agendaAlerts = computed(() => (canViewWeekPlanning.value ? (props.agendaAlerts || {}) : {}));
const outstandingInvoices = computed(() => (hasFeature('invoices') && canInvoices.value ? (props.outstandingInvoices || []) : []));
const activityFeed = computed(() => (isOwner.value ? (props.activity || []) : []));
const weekSchedule = computed(() => (canViewWeekPlanning.value ? (props.weekSchedule || {}) : { days: [], rows: [], summary: {} }));
const weekDays = computed(() => weekSchedule.value.days || []);
const weekRows = computed(() => weekSchedule.value.rows || []);
const weekSummary = computed(() => weekSchedule.value.summary || {});
const hasWeekPlanning = computed(() => weekDays.value.length > 0 && weekRows.value.length > 0);
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
    const tasksOverdue = Number(alerts.tasks_overdue || 0);
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
    if (tasksOverdue > 0) {
        items.push({
            key: 'tasks-overdue',
            label: t('dashboard.agenda.overdue_at', {
                count: formatAgendaCount(tasksOverdue, 'dashboard.agenda.task', 'dashboard.agenda.tasks'),
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
const dateFromKey = (value) => new Date(`${value}T12:00:00`);
const formatWeekdayShort = (value) =>
    new Intl.DateTimeFormat(locale.value || undefined, { weekday: 'short' }).format(dateFromKey(value));
const formatWeekdayDate = (value) =>
    new Intl.DateTimeFormat(locale.value || undefined, { day: 'numeric', month: 'short' }).format(dateFromKey(value));
const weekEventsFor = (row, dayKey) => row?.days?.[dayKey] || [];
const weekRowName = (row) => row?.name || t('dashboard.weekly.unassigned');
const weekRowLoad = (row) => t('dashboard.weekly.slots', { count: formatNumber(row?.event_count || 0) });
const MAX_VISIBLE_WEEK_EVENTS = 1;
const weekDayStats = computed(() => weekDays.value.reduce((stats, day) => {
    let total = 0;
    let resources = 0;

    weekRows.value.forEach((row) => {
        const events = weekEventsFor(row, day.key);
        if (events.length > 0) {
            total += events.length;
            resources += 1;
        }
    });

    stats[day.key] = { total, resources };
    return stats;
}, {}));
const weekDayStat = (dayKey) => weekDayStats.value?.[dayKey] || { total: 0, resources: 0 };
const visibleWeekEvents = (row, dayKey) => weekEventsFor(row, dayKey).slice(0, MAX_VISIBLE_WEEK_EVENTS);
const hiddenWeekEventsCount = (row, dayKey) => Math.max(weekEventsFor(row, dayKey).length - MAX_VISIBLE_WEEK_EVENTS, 0);
const scheduleSummaryCards = computed(() => ([
    {
        key: 'total',
        label: t('dashboard.weekly.summary.total'),
        value: formatNumber(weekSummary.value.total || 0),
        class: 'border-stone-200 bg-stone-50 text-stone-700 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200',
    },
    {
        key: 'to-go',
        label: t('dashboard.weekly.summary.to_go'),
        value: formatNumber(weekSummary.value.to_go || 0),
        class: 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200',
    },
    {
        key: 'active',
        label: t('dashboard.weekly.summary.active'),
        value: formatNumber(weekSummary.value.active || 0),
        class: 'border-sky-200 bg-sky-50 text-sky-700 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-200',
    },
    {
        key: 'complete',
        label: t('dashboard.weekly.summary.complete'),
        value: formatNumber(weekSummary.value.complete || 0),
        class: 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200',
    },
]));
const scheduleEventClass = (event) => {
    if (event?.type === 'work') {
        if (['en_route', 'in_progress'].includes(event?.status)) {
            return 'border-indigo-200 bg-indigo-50 text-indigo-700 dark:border-indigo-500/30 dark:bg-indigo-500/10 dark:text-indigo-200';
        }

        return 'border-sky-200 bg-sky-50 text-sky-700 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-200';
    }

    if (event?.status === 'in_progress') {
        return 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200';
    }

    return 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200';
};
const planningActionHref = computed(() => {
    if (hasFeature('tasks')) {
        return route('task.index');
    }

    if (hasFeature('jobs')) {
        return route('jobs.index');
    }

    return '';
});
const planningActionLabel = computed(() => (
    hasFeature('tasks')
        ? t('dashboard.timeline.view_tasks')
        : t('dashboard.actions.view_all')
));
const insightItems = computed(() => {
    const items = [];
    const biggestInvoice = [...outstandingInvoices.value]
        .sort((left, right) => Number(right.balance_due || 0) - Number(left.balance_due || 0))[0];
    const nextJob = upcomingJobs.value[0];
    const latestQuote = recentQuotes.value[0];
    const latestActivity = activityFeed.value[0];

    if (biggestInvoice) {
        items.push({
            key: 'invoice',
            label: t('dashboard.insights.invoice_label'),
            metric: formatCurrency(biggestInvoice.balance_due),
            title: biggestInvoice.number || t('dashboard.labels.invoice_fallback'),
            context: displayCustomer(biggestInvoice.customer),
            badge: biggestInvoice.status ? String(biggestInvoice.status).replace(/_/g, ' ') : '',
            href: route('invoice.show', biggestInvoice.id),
            class: 'border-rose-200 bg-rose-50/70 dark:border-rose-500/30 dark:bg-rose-500/10',
        });
    }

    if (nextJob) {
        items.push({
            key: 'job',
            label: t('dashboard.insights.job_label'),
            metric: `${formatDate(nextJob.start_date)}${nextJob.start_time ? ` · ${formatTime(nextJob.start_time)}` : ''}`,
            title: nextJob.job_title,
            context: displayCustomer(nextJob.customer),
            badge: workStatusLabel(nextJob.status),
            href: route('work.show', nextJob.id),
            class: 'border-sky-200 bg-sky-50/70 dark:border-sky-500/30 dark:bg-sky-500/10',
        });
    }

    if (latestQuote) {
        items.push({
            key: 'quote',
            label: t('dashboard.insights.quote_label'),
            metric: formatCurrency(latestQuote.total),
            title: latestQuote.number || t('dashboard.labels.quote_fallback'),
            context: displayCustomer(latestQuote.customer),
            badge: quoteStatusLabel(latestQuote.status),
            href: route('customer.quote.show', latestQuote.id),
            class: 'border-amber-200 bg-amber-50/70 dark:border-amber-500/30 dark:bg-amber-500/10',
        });
    }

    if (latestActivity) {
        items.push({
            key: 'activity',
            label: t('dashboard.insights.activity_label'),
            metric: formatDate(latestActivity.created_at),
            title: latestActivity.description || latestActivity.action,
            context: latestActivity.subject,
            badge: '',
            href: '',
            class: 'border-stone-200 bg-stone-50/90 dark:border-neutral-700 dark:bg-neutral-800',
        });
    }

    return items;
});
const customersEmpty = computed(() => stat('customers_total') <= 0);
const catalogEmpty = computed(() => stat('products_total') <= 0);
const quotesEmpty = computed(() => stat('quotes_total') <= 0);
const planScansEmpty = computed(() => stat('plan_scans_total') <= 0);

const expenseSeriesValues = computed(() => (
    hasFeature('expenses') && canExpenses.value ? (props.revenueSeries?.expenseValues || []) : []
));
const financeInsights = computed(() => (
    hasFeature('invoices') && canInvoices.value ? (props.financeSummary || {}) : {}
));
const hasExpenseTrend = computed(() => hasFeature('expenses') && canExpenses.value && expenseSeriesValues.value.length > 0);
const expenseKpiData = computed(() => ({
    points: hasExpenseTrend.value ? buildSparklinePoints(expenseSeriesValues.value) : [],
    trend: hasExpenseTrend.value ? buildTrend(expenseSeriesValues.value, 'down') : null,
}));

const kpiSeries = computed(() => props.kpiSeries || {});
const financeCount = (key) => Number(financeInsights.value?.[key] || 0);
const kpiConfig = {
    revenue_paid: { direction: 'up' },
    revenue_outstanding: { direction: 'down' },
    quotes_open: { direction: 'up' },
    works_scheduled: { direction: 'up' },
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
const financePanelMetrics = computed(() => {
    if (!hasFeature('invoices') || !canInvoices.value) {
        return [];
    }

    const items = [
        {
            key: 'revenue-paid',
            label: t('dashboard.kpi.revenue_paid'),
            value: formatCurrency(stat('revenue_paid')),
            context: t('dashboard.kpi.revenue_billed', { amount: formatCurrency(stat('revenue_billed')) }),
            trend: kpiData.value.revenue_paid.trend,
            points: kpiData.value.revenue_paid.points,
            colorClass: 'bg-emerald-500/70 dark:bg-emerald-400/50',
        },
        {
            key: 'revenue-outstanding',
            label: t('dashboard.kpi.outstanding_balance'),
            value: formatCurrency(stat('revenue_outstanding')),
            context: t('dashboard.kpi.partial_invoices', { count: formatNumber(stat('invoices_partial')) }),
            trend: kpiData.value.revenue_outstanding.trend,
            points: kpiData.value.revenue_outstanding.points,
            colorClass: 'bg-amber-500/70 dark:bg-amber-400/50',
        },
        {
            key: 'client-follow-up',
            label: t('dashboard.kpi.client_follow_up'),
            value: formatNumber(financeCount('outstanding_invoices_count')),
            context: t('dashboard.kpi.client_follow_up_amount', {
                amount: formatCurrency(stat('revenue_outstanding')),
            }),
            colorClass: 'bg-sky-500/70 dark:bg-sky-400/50',
        },
    ];

    if (hasFeature('expenses') && canExpenses.value) {
        items.push({
            key: 'expenses-due',
            label: t('dashboard.kpi.expenses_due'),
            value: formatNumber(financeCount('due_expenses_count')),
            context: t('dashboard.kpi.expenses_pending_approval', {
                count: formatNumber(financeCount('pending_expense_approvals_count')),
            }),
            trend: expenseKpiData.value.trend,
            points: expenseKpiData.value.points,
            colorClass: 'bg-rose-500/70 dark:bg-rose-400/50',
        });
    }

    return items;
});
const pipelinePanelMetrics = computed(() => {
    const items = [];

    if (hasFeature('quotes') && canQuotes.value) {
        items.push({
            key: 'quotes-open',
            label: t('dashboard.kpi.open_quotes'),
            value: formatNumber(stat('quotes_open')),
            context: t('dashboard.kpi.accepted_quotes', { count: formatNumber(stat('quotes_accepted')) }),
            trend: kpiData.value.quotes_open.trend,
            points: kpiData.value.quotes_open.points,
            colorClass: 'bg-blue-500/70 dark:bg-blue-400/50',
        });
    }

    if (hasFeature('jobs') && canJobs.value) {
        items.push({
            key: 'jobs-scheduled',
            label: t('dashboard.kpi.jobs_scheduled_label'),
            value: formatNumber(stat('works_scheduled')),
            context: t('dashboard.kpi.jobs_completed', { count: formatNumber(stat('works_completed')) }),
            trend: kpiData.value.works_scheduled.trend,
            points: kpiData.value.works_scheduled.points,
            colorClass: 'bg-cyan-500/70 dark:bg-cyan-400/50',
        });

        items.push({
            key: 'jobs-progress',
            label: t('dashboard.kpi.jobs_in_progress'),
            value: formatNumber(stat('works_in_progress')),
            context: t('dashboard.kpi.jobs_total', { count: formatNumber(stat('works_total')) }),
            trend: kpiData.value.works_in_progress.trend,
            points: kpiData.value.works_in_progress.points,
            colorClass: 'bg-indigo-500/70 dark:bg-indigo-400/50',
        });
    }

    return items;
});
const pipelinePanelActionHref = computed(() => {
    if (hasFeature('jobs') && canJobs.value) {
        return route('jobs.index');
    }

    if (hasFeature('quotes') && canQuotes.value) {
        return route('quote.index');
    }

    return '';
});
const financePanelSummary = computed(() => {
    if (!hasFeature('invoices') || !canInvoices.value) {
        return [];
    }

    return [
        {
            key: 'finance-billed',
            label: t('dashboard.kpi_panels.billed_total'),
            value: formatCurrency(stat('revenue_billed')),
        },
        {
            key: 'finance-partial',
            label: t('dashboard.kpi_panels.partial_label'),
            value: formatNumber(stat('invoices_partial')),
        },
        {
            key: 'finance-overdue',
            label: t('dashboard.kpi_panels.overdue_label'),
            value: formatNumber(stat('invoices_overdue')),
        },
        {
            key: 'finance-pending-actions',
            label: t('dashboard.kpi_panels.pending_actions_label'),
            value: formatNumber(financeCount('pending_finance_actions_count')),
        },
    ];
});
const pipelinePanelSummary = computed(() => {
    const items = [];

    if (hasFeature('quotes') && canQuotes.value) {
        items.push({
            key: 'pipeline-accepted',
            label: t('dashboard.kpi_panels.accepted_label'),
            value: formatNumber(stat('quotes_accepted')),
        });
        items.push({
            key: 'pipeline-month',
            label: t('dashboard.kpi_panels.quotes_month_label'),
            value: formatNumber(stat('quotes_month')),
        });
    }

    if (hasFeature('jobs') && canJobs.value) {
        items.push({
            key: 'pipeline-completed',
            label: t('dashboard.kpi_panels.completed_label'),
            value: formatNumber(stat('works_completed')),
        });
    }

    items.push({
        key: 'pipeline-today',
        label: t('dashboard.kpi_panels.today_load_label'),
        value: formatNumber(todayItems.value.length),
    });

    return items;
});
const financePanelGridClass = computed(() => {
    if (financePanelMetrics.value.length >= 4) {
        return 'sm:grid-cols-2 xl:grid-cols-4';
    }

    if (financePanelMetrics.value.length === 3) {
        return 'sm:grid-cols-2 xl:grid-cols-3';
    }

    return 'sm:grid-cols-2';
});
const pipelinePanelGridClass = computed(() => {
    if (pipelinePanelMetrics.value.length >= 3) {
        return 'sm:grid-cols-2 xl:grid-cols-3';
    }

    return 'sm:grid-cols-2';
});
const financePanelSummaryGridClass = computed(() => {
    if (financePanelSummary.value.length >= 4) {
        return 'sm:grid-cols-2 xl:grid-cols-4';
    }

    if (financePanelSummary.value.length === 3) {
        return 'sm:grid-cols-3';
    }

    return 'sm:grid-cols-2';
});
const pipelinePanelSummaryGridClass = computed(() => {
    if (pipelinePanelSummary.value.length >= 4) {
        return 'sm:grid-cols-2 xl:grid-cols-4';
    }

    if (pipelinePanelSummary.value.length === 3) {
        return 'sm:grid-cols-3';
    }

    return 'sm:grid-cols-2';
});
const hasFinancePanel = computed(() => financePanelMetrics.value.length > 0);
const hasPipelinePanel = computed(() => pipelinePanelMetrics.value.length > 0);
const financePanelClass = computed(() => (hasPipelinePanel.value ? 'xl:col-span-6' : 'xl:col-span-12'));
const pipelinePanelClass = computed(() => (hasFinancePanel.value ? 'xl:col-span-6' : 'xl:col-span-12'));

const displayCustomer = (customer) =>
    customer?.company_name ||
    `${customer?.first_name || ''} ${customer?.last_name || ''}`.trim() ||
    t('dashboard.labels.unknown_customer');

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

    if (hasFeature('quotes') && canQuotes.value) {
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

    if (hasFeature('quotes') && canQuotes.value) {
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

    if (showServices.value && canSalesManage.value && hasFeature('requests')) {
        actions.push({
            key: 'request',
            label: t('dashboard.suggestions.create_request'),
            type: 'overlay',
            overlay: '#hs-quick-create-request',
            priority: 9,
        });
    }

    if (hasFeature('jobs') && canJobs.value) {
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

onMounted(() => {
    if (typeof window === 'undefined') {
        return;
    }

    const storedValue = window.localStorage.getItem(marketingPanelStorageKey.value);
    if (storedValue === '1' || storedValue === '0') {
        showMarketingPanel.value = storedValue === '1';
    }
});
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

            <div :class="['grid gap-4 items-start', hasTopAnnouncements ? 'xl:grid-cols-[minmax(0,1fr)_320px]' : 'grid-cols-1']">
                <section class="grid grid-cols-1 gap-4 xl:grid-cols-12" data-testid="demo-dashboard-overview">
                    <KpiCompositePanel
                        v-if="financePanelMetrics.length"
                        :class="financePanelClass"
                        :title="$t('dashboard.kpi_panels.finance_title')"
                        :subtitle="$t('dashboard.kpi_panels.finance_subtitle')"
                        :metrics="financePanelMetrics"
                        :metrics-grid-class="financePanelGridClass"
                        :summary-items="financePanelSummary"
                        :summary-grid-class="financePanelSummaryGridClass"
                        :action-href="route('invoice.index')"
                        :action-label="$t('dashboard.revenue.view_invoices')"
                        accent-class="border-t-emerald-600"
                        compact-metrics
                    />
                    <KpiCompositePanel
                        v-if="pipelinePanelMetrics.length"
                        :class="pipelinePanelClass"
                        :title="$t('dashboard.kpi_panels.pipeline_title')"
                        :subtitle="$t('dashboard.kpi_panels.pipeline_subtitle')"
                        :metrics="pipelinePanelMetrics"
                        :metrics-grid-class="pipelinePanelGridClass"
                        :summary-items="pipelinePanelSummary"
                        :summary-grid-class="pipelinePanelSummaryGridClass"
                        :action-href="pipelinePanelActionHref"
                        :action-label="$t('dashboard.actions.view_all')"
                        accent-class="border-t-blue-600"
                        compact-metrics
                    />
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

            <section
                v-if="hasMarketingKpis"
                class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900"
            >
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ $t('dashboard.marketing_panel.title') }}</h2>
                        <p class="text-xs text-stone-500 dark:text-neutral-400">
                            {{ $t('dashboard.marketing_panel.range', {
                                label: marketingRange?.label || '30d',
                                start: marketingRange?.start || '-',
                                end: marketingRange?.end || '-',
                            }) }}
                        </p>
                        <p v-if="!showMarketingPanel" class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                            {{ $t('dashboard.marketing_panel.collapsed_hint') }}
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <button
                            type="button"
                            class="rounded-sm border border-stone-200 bg-stone-50 px-3 py-1.5 text-xs font-semibold text-stone-700 hover:bg-stone-100 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200 dark:hover:bg-neutral-700"
                            @click="toggleMarketingPanel"
                        >
                            {{ showMarketingPanel ? $t('dashboard.marketing_panel.hide') : $t('dashboard.marketing_panel.show') }}
                        </button>
                        <Link
                            :href="route('campaigns.index')"
                            class="rounded-sm border border-stone-200 bg-white px-3 py-1.5 text-xs font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                        >
                            {{ $t('dashboard.marketing_panel.open_campaigns') }}
                        </Link>
                    </div>
                </div>

                <div v-if="showMarketingPanel" class="mt-3 grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-4">
                    <div
                        v-for="card in marketingCards"
                        :key="card.key"
                        class="rounded-sm border border-stone-200 bg-stone-50 px-3 py-3 dark:border-neutral-700 dark:bg-neutral-800"
                    >
                        <div class="text-xs uppercase text-stone-500 dark:text-neutral-400">{{ card.label }}</div>
                        <div class="mt-1 text-lg font-semibold text-stone-800 dark:text-neutral-100">{{ card.value }}</div>
                    </div>
                </div>

                <div v-if="showMarketingPanel" class="mt-3 grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-4">
                    <div class="rounded-sm border border-stone-200 bg-white px-3 py-3 dark:border-neutral-700 dark:bg-neutral-900">
                        <div class="text-xs uppercase text-stone-500 dark:text-neutral-400">{{ $t('dashboard.marketing_panel.top_campaign') }}</div>
                        <div class="mt-1 text-sm font-semibold text-stone-800 dark:text-neutral-100">
                            {{ marketingMetrics?.top_performing_campaign?.name || $t('dashboard.marketing_panel.no_data') }}
                        </div>
                        <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                            {{ $t('dashboard.marketing_panel.conversions_clicks', {
                                conversions: formatNumber(marketingMetrics?.top_performing_campaign?.conversions || 0),
                                clicks: formatNumber(marketingMetrics?.top_performing_campaign?.clicks || 0),
                            }) }}
                        </div>
                    </div>
                    <div class="rounded-sm border border-stone-200 bg-white px-3 py-3 dark:border-neutral-700 dark:bg-neutral-900">
                        <div class="text-xs uppercase text-stone-500 dark:text-neutral-400">{{ $t('dashboard.marketing_panel.audience_growth') }}</div>
                        <div class="mt-1 text-sm font-semibold text-stone-800 dark:text-neutral-100">
                            {{ formatNumber(marketingMetrics?.audience_growth?.current || 0) }}
                        </div>
                        <div class="mt-1 text-xs" :class="audienceGrowthDeltaClass">
                            {{ $t('dashboard.marketing_panel.delta') }}: {{ audienceGrowthDelta }}
                        </div>
                    </div>
                    <div class="rounded-sm border border-stone-200 bg-white px-3 py-3 dark:border-neutral-700 dark:bg-neutral-900">
                        <div class="text-xs uppercase text-stone-500 dark:text-neutral-400">{{ $t('dashboard.marketing_panel.vip_customers') }}</div>
                        <div class="mt-1 text-sm font-semibold text-stone-800 dark:text-neutral-100">
                            {{ formatNumber(marketingMetrics?.vip_count || 0) }}
                        </div>
                    </div>
                    <div class="rounded-sm border border-stone-200 bg-white px-3 py-3 dark:border-neutral-700 dark:bg-neutral-900">
                        <div class="text-xs uppercase text-stone-500 dark:text-neutral-400">{{ $t('dashboard.marketing_panel.mailing_lists') }}</div>
                        <div class="mt-1 text-sm font-semibold text-stone-800 dark:text-neutral-100">
                            {{ $t('dashboard.marketing_panel.list_count', {
                                count: formatNumber(marketingMetrics?.mailing_lists?.count || 0),
                            }) }}
                        </div>
                        <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                            {{ $t('dashboard.marketing_panel.customers_count', {
                                count: formatNumber(marketingMetrics?.mailing_lists?.customers_total || 0),
                            }) }}
                        </div>
                    </div>
                </div>

                <div v-if="showMarketingPanel && marketingCrossModule" class="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-3">
                    <div class="rounded-sm border border-stone-200 bg-stone-50 px-3 py-2 text-xs text-stone-700 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200">
                        {{ $t('dashboard.marketing_panel.reservations_created', {
                            count: formatNumber(marketingCrossModule.reservations_created || 0),
                        }) }}
                    </div>
                    <div class="rounded-sm border border-stone-200 bg-stone-50 px-3 py-2 text-xs text-stone-700 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200">
                        {{ $t('dashboard.marketing_panel.invoices_paid', {
                            count: formatNumber(marketingCrossModule.invoices_paid || 0),
                        }) }}
                    </div>
                    <div class="rounded-sm border border-stone-200 bg-stone-50 px-3 py-2 text-xs text-stone-700 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200">
                        {{ $t('dashboard.marketing_panel.quotes_accepted', {
                            count: formatNumber(marketingCrossModule.quotes_accepted || 0),
                        }) }}
                    </div>
                </div>
            </section>

            <section class="grid grid-cols-1 xl:grid-cols-3 gap-4">
                <div class="xl:col-span-2 space-y-4">
                    <div v-if="hasFeature('tasks') || hasFeature('jobs')" class="bg-white border border-stone-200 rounded-sm p-5 shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                    {{ $t('dashboard.weekly.title') }}
                                </h2>
                                <p class="text-xs text-stone-500 dark:text-neutral-400">
                                    {{ $t('dashboard.weekly.subtitle') }}
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
                                    {{ $t('dashboard.weekly.sync_calendar') }}
                                </a>
                                <Link
                                    v-if="planningActionHref"
                                    :href="planningActionHref"
                                    class="text-xs font-medium text-stone-500 hover:text-stone-700 dark:text-neutral-400 dark:hover:text-neutral-200"
                                >
                                    {{ planningActionLabel }}
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
                            <div class="grid grid-cols-2 gap-2 xl:grid-cols-4">
                                <div
                                    v-for="card in scheduleSummaryCards"
                                    :key="card.key"
                                    class="rounded-sm border px-3 py-2.5"
                                    :class="card.class"
                                >
                                    <div class="text-[11px] font-semibold uppercase tracking-[0.1em]">
                                        {{ card.label }}
                                    </div>
                                    <div class="mt-1.5 text-xl font-semibold">
                                        {{ card.value }}
                                    </div>
                                </div>
                            </div>
                            <div v-if="!hasWeekPlanning" class="mt-4 text-sm text-stone-500 dark:text-neutral-400">
                                {{ $t('dashboard.weekly.empty') }}
                            </div>
                            <div v-else class="mt-4 overflow-x-auto">
                                <div class="min-w-[980px] overflow-hidden rounded-sm border border-stone-200 dark:border-neutral-700">
                                    <div class="grid grid-cols-[180px_repeat(7,minmax(112px,1fr))]">
                                        <div class="border-b border-emerald-200 bg-emerald-600 px-4 py-3 text-[11px] font-semibold uppercase tracking-[0.1em] text-stone-100 dark:border-neutral-700 dark:bg-neutral-950">
                                            {{ $t('dashboard.weekly.team_label') }}
                                        </div>
                                        <div
                                            v-for="day in weekDays"
                                            :key="day.key"
                                            class="border-b border-l border-stone-200 px-3 py-3 dark:border-neutral-700"
                                            :class="day.is_today ? 'bg-emerald-50/80 dark:bg-emerald-500/10' : 'bg-stone-50 dark:bg-neutral-900/80'"
                                        >
                                            <div class="flex items-start justify-between gap-2">
                                                <div>
                                                    <div class="text-[11px] font-semibold uppercase tracking-[0.08em] text-stone-500 dark:text-neutral-400">
                                                        {{ formatWeekdayShort(day.key) }}
                                                    </div>
                                                    <div class="mt-1 text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                                        {{ formatWeekdayDate(day.key) }}
                                                    </div>
                                                </div>
                                                <div
                                                    class="rounded-full px-2 py-0.5 text-[10px] font-semibold"
                                                    :class="day.is_today
                                                        ? 'bg-emerald-600 text-white dark:bg-emerald-500'
                                                        : 'bg-stone-200 text-stone-700 dark:bg-neutral-700 dark:text-neutral-200'"
                                                >
                                                    {{ formatNumber(weekDayStat(day.key).total) }}
                                                </div>
                                            </div>
                                            <div class="mt-2 text-[10px] text-stone-500 dark:text-neutral-400">
                                                {{ $t('dashboard.weekly.assignees', { count: formatNumber(weekDayStat(day.key).resources) }) }}
                                            </div>
                                         </div>

                                        <template v-for="row in weekRows" :key="row.key">
                                            <div class="border-b border-stone-200 bg-white px-4 py-3 dark:border-neutral-700 dark:bg-neutral-800">
                                                <div class="flex items-center gap-3">
                                                    <div class="flex size-9 items-center justify-center rounded-full bg-stone-100 text-xs font-semibold text-stone-700 dark:bg-neutral-700 dark:text-neutral-200">
                                                        {{ row.initials }}
                                                    </div>
                                                    <div class="min-w-0">
                                                        <div class="truncate text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                                            {{ weekRowName(row) }}
                                                        </div>
                                                        <div class="mt-1 inline-flex rounded-full bg-stone-100 px-2 py-0.5 text-[10px] font-medium text-stone-600 dark:bg-neutral-700 dark:text-neutral-300">{{ weekRowLoad(row) }}</div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div
                                                v-for="day in weekDays"
                                                :key="`${row.key}-${day.key}`"
                                                class="border-b border-l border-stone-200 px-2 py-2 dark:border-neutral-700"
                                                :class="day.is_today ? 'bg-emerald-50/40 dark:bg-emerald-500/5' : 'bg-white dark:bg-neutral-800'"
                                            >
                                                <div v-if="visibleWeekEvents(row, day.key).length" class="space-y-1.5">
                                                    <div
                                                        v-for="event in visibleWeekEvents(row, day.key)"
                                                        :key="event.key"
                                                        class="rounded-sm border px-2 py-2 text-[11px] shadow-sm"
                                                        :class="scheduleEventClass(event)"
                                                    >
                                                        <div class="flex items-center gap-1.5">
                                                            <span class="rounded bg-white/80 px-1.5 py-0.5 text-[10px] font-semibold text-current dark:bg-neutral-950/50">
                                                                {{ event.time_label || $t('dashboard.time.any') }}
                                                            </span>
                                                        </div>
                                                        <div class="mt-1 line-clamp-2 font-medium leading-4">
                                                            {{ event.title }}
                                                        </div>
                                                    </div>
                                                    <div
                                                        v-if="hiddenWeekEventsCount(row, day.key)"
                                                        class="rounded-sm border border-dashed border-stone-300 bg-stone-50 px-2 py-1 text-[10px] font-semibold text-stone-500 dark:border-neutral-600 dark:bg-neutral-900 dark:text-neutral-400"
                                                    >
                                                        +{{ formatNumber(hiddenWeekEventsCount(row, day.key)) }}
                                                    </div>
                                                </div>
                                                <div
                                                    v-else
                                                    class="min-h-[74px] rounded-sm border border-dashed border-stone-200/80 bg-stone-50/60 dark:border-neutral-700/80 dark:bg-neutral-900/40"
                                                ></div>
                                            </div>
                                        </template>
                                    </div>
                                </div>
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
                    <div v-if="insightItems.length" class="bg-white border border-stone-200 rounded-sm p-5 shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                        <div class="flex items-center justify-between gap-3">
                            <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                {{ $t('dashboard.insights.title') }}
                            </h2>
                            <span class="rounded-full bg-stone-100 px-2.5 py-1 text-[10px] font-semibold uppercase tracking-[0.08em] text-stone-500 dark:bg-neutral-700 dark:text-neutral-300">
                                {{ formatNumber(insightItems.length) }}
                            </span>
                        </div>
                        <div class="mt-4 grid grid-cols-1 gap-2 sm:grid-cols-2">
                            <component
                                :is="item.href ? Link : 'div'"
                                v-for="item in insightItems"
                                :key="item.key"
                                v-bind="item.href ? { href: item.href } : {}"
                                class="rounded-sm border px-3 py-3 transition"
                                :class="[item.class, item.href ? 'hover:-translate-y-0.5 hover:shadow-sm' : '']"
                            >
                                <div class="flex items-start justify-between gap-2">
                                    <div class="text-[10px] font-semibold uppercase tracking-[0.1em] text-stone-500 dark:text-neutral-400">
                                        {{ item.label }}
                                    </div>
                                    <span
                                        v-if="item.badge"
                                        class="rounded-full bg-white/80 px-2 py-0.5 text-[10px] font-medium capitalize text-stone-600 dark:bg-neutral-900/70 dark:text-neutral-300"
                                    >
                                        {{ item.badge }}
                                    </span>
                                </div>
                                <div class="mt-3 text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                    {{ item.metric }}
                                </div>
                                <div class="mt-1 truncate text-xs font-medium text-stone-700 dark:text-neutral-200">
                                    {{ item.title }}
                                </div>
                                <div class="mt-1 flex items-center justify-between gap-2 text-[11px] text-stone-500 dark:text-neutral-400">
                                    <span class="truncate">{{ item.context }}</span>
                                    <span v-if="item.href" class="shrink-0 font-semibold text-emerald-700 dark:text-emerald-300">
                                        {{ $t('dashboard.insights.open') }}
                                    </span>
                                </div>
                            </component>
                        </div>
                    </div>

                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
