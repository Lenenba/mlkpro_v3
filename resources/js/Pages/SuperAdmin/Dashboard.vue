<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { Head, Link, useForm } from '@inertiajs/vue3';
import KpiMetricCard from '@/Components/Dashboard/KpiMetricCard.vue';
import KpiMetricGrid from '@/Components/Dashboard/KpiMetricGrid.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { humanizeDate } from '@/utils/date';

const props = defineProps({
    metrics: {
        type: Object,
        required: true,
    },
    recent_audits: {
        type: Array,
        default: () => [],
    },
    audit_filters: {
        type: Object,
        default: () => ({}),
    },
    audit_options: {
        type: Object,
        default: () => ({}),
    },
});

const { t } = useI18n();

const formatNumber = (value) => new Intl.NumberFormat().format(value ?? 0);
const formatPercent = (value) => `${value ?? 0}%`;
const formatBytes = (bytes) => {
    if (bytes === null || bytes === undefined) {
        return t('super_admin.common.not_available');
    }
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let index = 0;
    let size = Number(bytes);
    while (size >= 1024 && index < units.length - 1) {
        size /= 1024;
        index += 1;
    }
    return `${size.toFixed(1)} ${units[index]}`;
};

const resolveAuditUser = (audit) =>
    audit.user?.name || audit.user?.email || t('super_admin.common.unknown');

const auditActionLabel = (action) => {
    if (!action) {
        return t('super_admin.common.not_available');
    }
    const supportKey = `super_admin.support.audit.actions["${action}"]`;
    const supportTranslated = t(supportKey);
    if (supportTranslated !== supportKey) {
        return supportTranslated;
    }
    const dashboardKey = `super_admin.dashboard.audit.actions["${action}"]`;
    const dashboardTranslated = t(dashboardKey);
    return dashboardTranslated === dashboardKey ? action : dashboardTranslated;
};

const newCompanies30 = computed(() => {
    return (props.metrics.acquisition_series || []).reduce((sum, row) => sum + (row.count || 0), 0);
});

const recentAcquisition = computed(() => {
    const series = props.metrics.acquisition_series || [];
    return series.slice(Math.max(series.length - 7, 0));
});

const serviceMix = computed(() => {
    const services = props.metrics.services_total ?? 0;
    const products = props.metrics.products_total ?? 0;
    const total = services + products;
    const servicePercent = total > 0 ? Math.round((services / total) * 100) : 0;
    return {
        services,
        products,
        servicePercent,
    };
});

const formatDate = (value) => humanizeDate(value) || t('super_admin.common.not_available');

const isMeasuredNumber = (value, measurable) => (
    measurable === true
    && value !== null
    && value !== undefined
    && value !== ''
    && Number.isFinite(Number(value))
);

const formatMeasuredNumber = (value, measurable) => (
    isMeasuredNumber(value, measurable)
        ? formatNumber(value)
        : t('super_admin.common.unknown')
);

const failedJobsBackendMeasurable = computed(() => (
    props.metrics.health?.queue_failed_jobs_measurable === true
));

const failedJobsMeasurable = computed(() => isMeasuredNumber(
    props.metrics.health?.failed_jobs_24h,
    failedJobsBackendMeasurable.value,
));

const queueBacklogMeasurable = computed(() => isMeasuredNumber(
    props.metrics.health?.pending_jobs,
    props.metrics.health?.queue_backlog_measurable,
));

const failedMailJobsMeasurable = computed(() => isMeasuredNumber(
    props.metrics.health?.failed_mail_jobs_24h,
    failedJobsBackendMeasurable.value,
));

const stripeFailuresMeasurable = computed(() => isMeasuredNumber(
    props.metrics.alerts?.stripe_failures_24h,
    failedJobsBackendMeasurable.value,
));

const smtpFailuresMeasurable = computed(() => isMeasuredNumber(
    props.metrics.alerts?.smtp_failures_24h,
    failedJobsBackendMeasurable.value,
));

const platformHealthUnavailable = computed(() => (
    !failedJobsMeasurable.value || !queueBacklogMeasurable.value
));

const jobsBacklogPendingMeasurable = computed(() => {
    const backlog = props.metrics.alerts?.jobs_backlog;

    return isMeasuredNumber(backlog?.pending, backlog?.measurable);
});

const jobsBacklogOldestMeasurable = computed(() => {
    const backlog = props.metrics.alerts?.jobs_backlog;

    return isMeasuredNumber(backlog?.oldest_minutes, backlog?.measurable);
});

const jobsBacklogMeasurable = computed(() => (
    jobsBacklogPendingMeasurable.value && jobsBacklogOldestMeasurable.value
));

const storageAlertMeasurable = computed(() => isMeasuredNumber(
    props.metrics.alerts?.storage?.used_percent,
    true,
));

const jobsBacklogDetail = computed(() => {
    if (!jobsBacklogPendingMeasurable.value) {
        return t('super_admin.dashboard.alerts.measurement_unavailable');
    }

    return t('super_admin.dashboard.alerts.jobs_backlog_detail', {
        pending: formatNumber(props.metrics.alerts.jobs_backlog.pending),
        oldest: jobsBacklogOldestMeasurable.value
            ? props.metrics.alerts.jobs_backlog.oldest_minutes
            : t('super_admin.common.unknown'),
    });
});

const platformMetrics = computed(() => [
    {
        key: 'total_companies',
        label: t('super_admin.dashboard.kpi.total_companies'),
        value: formatNumber(props.metrics.companies_total),
        context: t('super_admin.dashboard.kpi.onboarded', {
            count: formatNumber(props.metrics.companies_onboarded),
            percent: formatPercent(props.metrics.onboarding_conversion),
        }),
        tone: 'emerald',
    },
    {
        key: 'new_companies_30d',
        label: t('super_admin.dashboard.kpi.new_companies_30d'),
        value: formatNumber(newCompanies30.value),
        context: t('super_admin.dashboard.kpi.onboarding_conversion_30d', {
            percent: formatPercent(props.metrics.onboarding_conversion_30d),
        }),
        tone: 'amber',
    },
    {
        key: 'active_companies',
        label: t('super_admin.dashboard.kpi.active_companies'),
        value: t('super_admin.dashboard.kpi.active_wau_mau', {
            wau: formatNumber(props.metrics.wau),
            mau: formatNumber(props.metrics.mau),
        }),
        context: t('super_admin.dashboard.kpi.activation_rates', {
            j7: formatPercent(props.metrics.activation_rates?.j7),
            j30: formatPercent(props.metrics.activation_rates?.j30),
        }),
        tone: 'blue',
    },
    {
        key: 'platform_health',
        label: t('super_admin.dashboard.kpi.platform_health'),
        value: formatMeasuredNumber(
            props.metrics.health?.failed_jobs_24h,
            failedJobsMeasurable.value,
        ),
        context: t('super_admin.dashboard.kpi.health_hint', {
            failed: formatMeasuredNumber(
                props.metrics.health?.failed_jobs_24h,
                failedJobsMeasurable.value,
            ),
            pending: formatMeasuredNumber(
                props.metrics.health?.pending_jobs,
                queueBacklogMeasurable.value,
            ),
        }),
        tone: platformHealthUnavailable.value ? 'amber' : 'violet',
        measurementStatus: platformHealthUnavailable.value ? 'unknown' : 'available',
        testId: 'superadmin-platform-health',
    },
]);

const healthCards = computed(() => ([
    {
        key: 'failed_jobs_24h',
        label: t('super_admin.dashboard.health.failed_jobs_24h'),
        value: formatMeasuredNumber(
            props.metrics.health?.failed_jobs_24h,
            failedJobsMeasurable.value,
        ),
        measurable: failedJobsMeasurable.value,
        tone: !failedJobsMeasurable.value
            ? 'amber'
            : (props.metrics.health.failed_jobs_24h > 0 ? 'red' : 'emerald'),
    },
    {
        key: 'pending_jobs',
        label: t('super_admin.dashboard.health.pending_jobs'),
        value: formatMeasuredNumber(
            props.metrics.health?.pending_jobs,
            queueBacklogMeasurable.value,
        ),
        measurable: queueBacklogMeasurable.value,
        tone: !queueBacklogMeasurable.value
            ? 'amber'
            : (props.metrics.health.pending_jobs > 0 ? 'amber' : 'emerald'),
    },
    {
        key: 'email_failures_24h',
        label: t('super_admin.dashboard.health.email_failures_24h'),
        value: formatMeasuredNumber(
            props.metrics.health?.failed_mail_jobs_24h,
            failedMailJobsMeasurable.value,
        ),
        measurable: failedMailJobsMeasurable.value,
        tone: !failedMailJobsMeasurable.value
            ? 'amber'
            : (props.metrics.health.failed_mail_jobs_24h > 0 ? 'red' : 'emerald'),
    },
    {
        key: 'storage_public_bytes',
        label: t('super_admin.dashboard.health.public_storage'),
        value: formatBytes(props.metrics.health?.storage_public_bytes),
        measurable: props.metrics.health?.storage_public_bytes !== null
            && props.metrics.health?.storage_public_bytes !== undefined,
        tone: 'stone',
    },
]));

const actionCenterItems = computed(() => ([
    {
        key: 'support',
        label: t('super_admin.dashboard.action_center.support'),
        route: 'superadmin.support.index',
        count: props.metrics.action_center?.support_open ?? 0,
    },
    {
        key: 'announcements',
        label: t('super_admin.dashboard.action_center.announcements'),
        route: 'superadmin.announcements.index',
        count: props.metrics.action_center?.announcements_active ?? 0,
    },
    {
        key: 'notifications',
        label: t('super_admin.dashboard.action_center.notifications'),
        route: 'superadmin.notifications.edit',
        count: props.metrics.action_center?.notifications_pending ?? 0,
    },
    {
        key: 'tenants',
        label: t('super_admin.dashboard.action_center.tenants'),
        route: 'superadmin.tenants.index',
        count: props.metrics.action_center?.tenants_at_risk ?? 0,
    },
    {
        key: 'admins',
        label: t('super_admin.dashboard.action_center.admins'),
        route: 'superadmin.admins.index',
        count: null,
    },
    {
        key: 'settings',
        label: t('super_admin.dashboard.action_center.settings'),
        route: 'superadmin.settings.edit',
        count: null,
    },
    {
        key: 'mega_menus',
        label: 'Mega Menus',
        route: 'superadmin.mega-menus.index',
        count: null,
    },
]));

const limitAlerts = computed(() => props.metrics.alerts?.limit_warnings || { count: 0, tenants: [] });
const riskTenants = computed(() => props.metrics.at_risk_tenants?.tenants || []);
const usageTrends = computed(() => props.metrics.usage_trends || []);
const siteTraffic = computed(() => props.metrics.site_traffic || {});
const siteTrafficSeries = computed(() => props.metrics.site_traffic_series || []);

const alertMetrics = computed(() => [
    {
        key: 'limits',
        label: t('super_admin.dashboard.alerts.limits'),
        value: formatNumber(limitAlerts.value.count),
        tone: limitAlerts.value.count > 0 ? 'rose' : 'emerald',
    },
    {
        key: 'stripe_failures',
        label: t('super_admin.dashboard.alerts.stripe_failures'),
        value: formatMeasuredNumber(
            props.metrics.alerts?.stripe_failures_24h,
            stripeFailuresMeasurable.value,
        ),
        tone: !stripeFailuresMeasurable.value
            ? 'amber'
            : (props.metrics.alerts.stripe_failures_24h > 0 ? 'rose' : 'emerald'),
        measurementStatus: stripeFailuresMeasurable.value ? 'available' : 'unknown',
        testId: 'superadmin-stripe-failures',
    },
    {
        key: 'smtp_failures',
        label: t('super_admin.dashboard.alerts.smtp_failures'),
        value: formatMeasuredNumber(
            props.metrics.alerts?.smtp_failures_24h,
            smtpFailuresMeasurable.value,
        ),
        tone: !smtpFailuresMeasurable.value
            ? 'amber'
            : (props.metrics.alerts.smtp_failures_24h > 0 ? 'rose' : 'emerald'),
        measurementStatus: smtpFailuresMeasurable.value ? 'available' : 'unknown',
        testId: 'superadmin-smtp-failures',
    },
    {
        key: 'jobs_backlog',
        label: t('super_admin.dashboard.alerts.jobs_backlog'),
        value: formatMeasuredNumber(
            props.metrics.alerts?.jobs_backlog?.pending,
            jobsBacklogPendingMeasurable.value,
        ),
        context: jobsBacklogDetail.value,
        tone: !jobsBacklogMeasurable.value
            ? 'amber'
            : (props.metrics.alerts.jobs_backlog.pending > 0 ? 'amber' : 'emerald'),
        measurementStatus: jobsBacklogMeasurable.value ? 'available' : 'unknown',
        testId: 'superadmin-jobs-backlog',
    },
    {
        key: 'storage',
        label: t('super_admin.dashboard.alerts.storage'),
        value: storageAlertMeasurable.value
            ? `${props.metrics.alerts?.storage?.used_percent}%`
            : t('super_admin.common.not_available'),
        context: formatBytes(props.metrics.alerts?.storage?.used_bytes),
        tone: !storageAlertMeasurable.value
            ? 'amber'
            : (props.metrics.alerts?.storage?.critical ? 'rose' : 'emerald'),
        measurementStatus: storageAlertMeasurable.value ? 'available' : 'unknown',
        testId: 'superadmin-storage-alert',
        gridClass: 'sm:col-span-2',
    },
]);

const siteTrafficMetrics = computed(() => [
    {
        key: 'last_24h',
        label: t('super_admin.dashboard.site_traffic.last_24h'),
        value: formatNumber(siteTraffic.value.total_24h),
        context: t('super_admin.dashboard.site_traffic.unique_label', {
            count: formatNumber(siteTraffic.value.unique_24h),
        }),
        tone: 'emerald',
    },
    {
        key: 'last_7d',
        label: t('super_admin.dashboard.site_traffic.last_7d'),
        value: formatNumber(siteTraffic.value.total_7d),
        context: t('super_admin.dashboard.site_traffic.unique_label', {
            count: formatNumber(siteTraffic.value.unique_7d),
        }),
        tone: 'sky',
    },
    {
        key: 'last_30d',
        label: t('super_admin.dashboard.site_traffic.last_30d'),
        value: formatNumber(siteTraffic.value.total_30d),
        context: t('super_admin.dashboard.site_traffic.unique_label', {
            count: formatNumber(siteTraffic.value.unique_30d),
        }),
        tone: 'indigo',
    },
]);

const dataQualityMetrics = computed(() => [
    {
        key: 'customer_email_duplicates',
        label: t('super_admin.dashboard.data_quality.customer_email_duplicates'),
        value: formatNumber(props.metrics.data_quality?.customer_email_duplicates),
        tone: 'rose',
    },
    {
        key: 'customer_name_duplicates',
        label: t('super_admin.dashboard.data_quality.customer_name_duplicates'),
        value: formatNumber(props.metrics.data_quality?.customer_name_duplicates),
        tone: 'amber',
    },
    {
        key: 'product_name_duplicates',
        label: t('super_admin.dashboard.data_quality.product_name_duplicates'),
        value: formatNumber(props.metrics.data_quality?.product_name_duplicates),
        tone: 'orange',
    },
]);

const buildSparklinePoints = (values, width = 260, height = 80, padding = 6) => {
    if (!values.length) {
        return '';
    }

    const max = Math.max(...values, 0);
    const min = Math.min(...values, 0);
    const range = max - min || 1;
    const usableWidth = width - padding * 2;
    const usableHeight = height - padding * 2;
    const lastIndex = values.length - 1;

    return values.map((value, index) => {
        const x = padding + (lastIndex === 0 ? 0 : (usableWidth * (index / lastIndex)));
        const normalized = (value - min) / range;
        const y = height - padding - (usableHeight * normalized);
        return `${x},${y}`;
    }).join(' ');
};

const trafficTotals = computed(() => siteTrafficSeries.value.map((row) => row.total || 0));
const trafficUniques = computed(() => siteTrafficSeries.value.map((row) => row.unique || 0));
const trafficTotalPoints = computed(() => buildSparklinePoints(trafficTotals.value));
const trafficUniquePoints = computed(() => buildSparklinePoints(trafficUniques.value));
const trafficHasData = computed(() => trafficTotals.value.some((value) => value > 0) || trafficUniques.value.some((value) => value > 0));
const trafficStart = computed(() => siteTrafficSeries.value[0]?.date || '');
const trafficEnd = computed(() => siteTrafficSeries.value[siteTrafficSeries.value.length - 1]?.date || '');

const limitLabel = (key) => t(`super_admin.dashboard.limits.${key}`);

const riskFlagLabels = computed(() => ({
    onboarding_blocked: t('super_admin.dashboard.risk.flags.onboarding'),
    churn_risk: t('super_admin.dashboard.risk.flags.churn'),
    inactive_14: t('super_admin.dashboard.risk.flags.inactive_14'),
    inactive_30: t('super_admin.dashboard.risk.flags.inactive_30'),
}));

const trendTone = (row) => {
    if (row.trend_direction === 'up') {
        return 'text-emerald-600';
    }
    if (row.trend_direction === 'down') {
        return 'text-rose-600';
    }
    return 'text-stone-500 dark:text-neutral-400';
};

const trendLabel = (row) => {
    const directionKey = row.trend_direction || 'none';
    if (directionKey === 'none') {
        return t('super_admin.dashboard.trend.none');
    }
    if (directionKey === 'flat') {
        return t('super_admin.dashboard.trend.flat');
    }
    if (directionKey === 'new') {
        return t('super_admin.dashboard.trend.new');
    }

    const sign = row.trend_delta > 0 ? '+' : '';
    const delta = row.trend_delta ?? 0;
    const percent = row.trend_percent !== null && row.trend_percent !== undefined
        ? ` (${row.trend_percent}%)`
        : '';
    return `${sign}${delta}${percent}`;
};

const auditFilterForm = useForm({
    admin_id: props.audit_filters?.admin_id ?? '',
    tenant_id: props.audit_filters?.tenant_id ?? '',
    action: props.audit_filters?.action ?? '',
});

const applyAuditFilters = () => {
    auditFilterForm.get(route('superadmin.dashboard'), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
};

const resetAuditFilters = () => {
    auditFilterForm.reset();
    auditFilterForm.get(route('superadmin.dashboard'));
};
</script>

<template>
    <Head :title="$t('super_admin.dashboard.page_title')" />

    <AuthenticatedLayout>
        <div class="space-y-6">
            <section class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="space-y-1">
                        <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">
                            {{ $t('super_admin.dashboard.title') }}
                        </h1>
                        <p class="text-sm text-stone-600 dark:text-neutral-400">
                            {{ $t('super_admin.dashboard.subtitle') }}
                        </p>
                    </div>
                </div>
            </section>

            <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                <div class="flex items-center justify-between gap-3">
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                        {{ $t('super_admin.dashboard.action_center.title') }}
                    </h2>
                </div>
                <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
                    <Link
                        v-for="item in actionCenterItems"
                        :key="item.key"
                        :href="route(item.route)"
                        class="rounded-sm border border-stone-200 bg-stone-50 px-3 py-3 text-sm text-stone-700 transition hover:bg-stone-100 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                    >
                        <div class="flex items-center justify-between">
                            <span class="font-semibold">{{ item.label }}</span>
                            <span v-if="item.count !== null" class="rounded-full bg-stone-200 px-2 py-0.5 text-xs font-semibold text-stone-700 dark:bg-neutral-700 dark:text-neutral-200">
                                {{ formatNumber(item.count) }}
                            </span>
                        </div>
                    </Link>
                </div>
            </div>

            <div class="grid gap-3 md:grid-cols-4">
                <KpiMetricCard
                    v-for="metric in platformMetrics"
                    :key="metric.key"
                    :metric="metric"
                    :data-measurement-status="metric.measurementStatus"
                    :data-testid="metric.testId"
                />
            </div>

            <div class="grid gap-3 lg:grid-cols-2">
                <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                    <div class="flex items-center justify-between">
                        <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                            {{ $t('super_admin.dashboard.alerts.title') }}
                        </h2>
                        <span class="text-xs text-stone-500 dark:text-neutral-400">
                            {{ $t('super_admin.dashboard.alerts.last_24h') }}
                        </span>
                    </div>
                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                        <KpiMetricCard
                            v-for="metric in alertMetrics"
                            :key="metric.key"
                            :metric="metric"
                            :class="metric.gridClass"
                            :data-measurement-status="metric.measurementStatus"
                            :data-testid="metric.testId"
                        />
                    </div>
                    <div class="mt-4">
                        <h3 class="text-xs font-semibold uppercase text-stone-500 dark:text-neutral-400">
                            {{ $t('super_admin.dashboard.alerts.limit_list') }}
                        </h3>
                        <div v-if="limitAlerts.tenants.length === 0" class="mt-2 text-sm text-stone-500 dark:text-neutral-400">
                            {{ $t('super_admin.dashboard.alerts.limit_empty') }}
                        </div>
                        <ul v-else class="mt-2 space-y-3 text-sm text-stone-700 dark:text-neutral-200">
                            <li v-for="tenant in limitAlerts.tenants" :key="tenant.id" class="flex flex-col gap-1">
                                <div class="flex items-center justify-between">
                                    <Link :href="route('superadmin.tenants.show', tenant.id)" class="font-semibold text-stone-800 dark:text-neutral-100">
                                        {{ tenant.company_name || tenant.email }}
                                    </Link>
                                    <span v-if="tenant.plan_name" class="text-xs text-stone-500 dark:text-neutral-400">
                                        {{ tenant.plan_name }}
                                    </span>
                                </div>
                                <div class="flex flex-wrap gap-1">
                                    <span
                                        v-for="flag in tenant.flags"
                                        :key="flag.key"
                                        class="rounded-full border border-stone-200 bg-stone-50 px-2 py-0.5 text-xs text-stone-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                                        :class="flag.status === 'over' ? 'border-rose-300 text-rose-700 dark:border-rose-700 dark:text-rose-400' : ''"
                                    >
                                        {{ limitLabel(flag.key) }} ·
                                        {{ flag.percent !== null && flag.percent !== undefined
                                            ? `${flag.percent}%`
                                            : `${flag.used}/${flag.limit}` }}
                                    </span>
                                </div>
                            </li>
                        </ul>
                    </div>
                </div>

                <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                    <div class="flex items-center justify-between">
                        <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                            {{ $t('super_admin.dashboard.risk.title') }}
                        </h2>
                        <Link :href="route('superadmin.tenants.index')" class="text-xs text-green-700 hover:text-green-800 dark:text-green-400">
                            {{ $t('super_admin.dashboard.risk.view_all') }}
                        </Link>
                    </div>
                    <div v-if="riskTenants.length === 0" class="mt-3 text-sm text-stone-500 dark:text-neutral-400">
                        {{ $t('super_admin.dashboard.risk.empty') }}
                    </div>
                    <ul v-else class="mt-3 space-y-3 text-sm text-stone-700 dark:text-neutral-200">
                        <li v-for="tenant in riskTenants" :key="tenant.id" class="rounded-sm border border-stone-200 p-3 dark:border-neutral-700">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <Link :href="route('superadmin.tenants.show', tenant.id)" class="font-semibold text-stone-800 dark:text-neutral-100">
                                    {{ tenant.company_name || tenant.email }}
                                </Link>
                                <span class="text-xs text-stone-500 dark:text-neutral-400">
                                    {{ $t('super_admin.dashboard.risk.inactive_days', { count: tenant.inactive_days }) }}
                                </span>
                            </div>
                            <div class="mt-2 flex flex-wrap gap-1">
                                <span
                                    v-for="flag in tenant.flags"
                                    :key="flag"
                                    class="rounded-full border border-stone-200 bg-stone-50 px-2 py-0.5 text-xs text-stone-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                                >
                                    {{ riskFlagLabels[flag] || flag }}
                                </span>
                            </div>
                            <div class="mt-2 text-xs text-stone-500 dark:text-neutral-400">
                                {{ $t('super_admin.dashboard.risk.last_activity', {
                                    date: tenant.last_activity_at ? formatDate(tenant.last_activity_at) : $t('super_admin.common.not_available')
                                }) }}
                            </div>
                        </li>
                    </ul>
                </div>
            </div>

            <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                    {{ $t('super_admin.dashboard.health.title') }}
                </h2>
                <div class="mt-4 grid gap-3 sm:grid-cols-4">
                    <KpiMetricCard
                        v-for="card in healthCards"
                        :key="card.key"
                        :metric="card"
                        compact
                        :data-measurement-status="card.measurable ? 'available' : 'unknown'"
                        :data-testid="`superadmin-health-${card.key}`"
                    />
                </div>
            </div>

            <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                    {{ $t('super_admin.dashboard.acquisition.title') }}
                </h2>
                <div class="mt-3 grid gap-2 sm:grid-cols-7 text-xs text-stone-600 dark:text-neutral-300">
                    <div v-for="row in recentAcquisition" :key="row.date" class="rounded-sm border border-stone-200 p-2 text-center dark:border-neutral-700">
                        <div class="text-stone-500">{{ row.date }}</div>
                        <div class="mt-1 text-sm font-semibold text-stone-800 dark:text-neutral-100">
                            {{ row.count }}
                        </div>
                    </div>
                    <div v-if="recentAcquisition.length === 0" class="text-sm text-stone-500 dark:text-neutral-400">
                        {{ $t('super_admin.dashboard.acquisition.empty') }}
                    </div>
                </div>
            </div>

            <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                    {{ $t('super_admin.dashboard.site_traffic.title') }}
                </h2>
                <KpiMetricGrid
                    class="mt-4"
                    :metrics="siteTrafficMetrics"
                    grid-class="sm:grid-cols-3"
                />
                <div class="mt-4">
                    <div v-if="!trafficHasData" class="text-xs text-stone-500 dark:text-neutral-400">
                        {{ $t('super_admin.dashboard.site_traffic.empty') }}
                    </div>
                    <div v-else>
                        <svg viewBox="0 0 260 80" class="h-20 w-full">
                            <polyline
                                :points="trafficTotalPoints"
                                fill="none"
                                stroke="#16a34a"
                                stroke-width="2"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                            />
                            <polyline
                                :points="trafficUniquePoints"
                                fill="none"
                                stroke="#64748b"
                                stroke-width="2"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                            />
                        </svg>
                        <div class="mt-2 flex items-center justify-between text-[11px] text-stone-500 dark:text-neutral-400">
                            <span>{{ trafficStart }}</span>
                            <span>{{ trafficEnd }}</span>
                        </div>
                        <div class="mt-2 flex flex-wrap gap-3 text-[11px] text-stone-500 dark:text-neutral-400">
                            <span class="inline-flex items-center gap-1">
                                <span class="inline-block h-2 w-2 rounded-full bg-emerald-600"></span>
                                {{ $t('super_admin.dashboard.site_traffic.legend_total') }}
                            </span>
                            <span class="inline-flex items-center gap-1">
                                <span class="inline-block h-2 w-2 rounded-full bg-slate-500"></span>
                                {{ $t('super_admin.dashboard.site_traffic.legend_unique') }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="grid gap-3 lg:grid-cols-2">
                <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                        {{ $t('super_admin.dashboard.usage_trends.title') }}
                    </h2>
                    <div
                        class="mt-4 overflow-x-auto [&::-webkit-scrollbar]:h-2 [&::-webkit-scrollbar-thumb]:rounded-full [&::-webkit-scrollbar-track]:bg-stone-100 [&::-webkit-scrollbar-thumb]:bg-stone-300 dark:[&::-webkit-scrollbar-track]:bg-neutral-700 dark:[&::-webkit-scrollbar-thumb]:bg-neutral-500">
                        <table class="min-w-full divide-y divide-stone-200 text-sm text-left text-stone-600 dark:divide-neutral-700 dark:text-neutral-300">
                            <thead class="text-xs uppercase text-stone-500 dark:text-neutral-400">
                                <tr>
                                    <th class="py-2">{{ $t('super_admin.dashboard.usage_trends.module') }}</th>
                                    <th class="py-2">{{ $t('super_admin.dashboard.usage_trends.last_7d') }}</th>
                                    <th class="py-2">{{ $t('super_admin.dashboard.usage_trends.last_30d') }}</th>
                                    <th class="py-2">{{ $t('super_admin.dashboard.usage_trends.trend') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-stone-200 dark:divide-neutral-700">
                                <tr v-for="row in usageTrends" :key="row.key">
                                    <td class="py-2 font-medium text-stone-800 dark:text-neutral-100">
                                        {{ limitLabel(row.key) }}
                                    </td>
                                    <td class="py-2">{{ formatNumber(row.count_7d) }}</td>
                                    <td class="py-2">{{ formatNumber(row.count_30d) }}</td>
                                    <td class="py-2">
                                        <span :class="trendTone(row)">
                                            {{ trendLabel(row) }}
                                        </span>
                                    </td>
                                </tr>
                                <tr v-if="usageTrends.length === 0">
                                    <td colspan="4" class="py-3 text-center text-sm text-stone-500 dark:text-neutral-400">
                                        {{ $t('super_admin.dashboard.usage_trends.empty') }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="mt-4 text-xs text-stone-500 dark:text-neutral-400">
                        {{ $t('super_admin.dashboard.usage.avg_days_to_first', {
                            quote: metrics.avg_days_to_first?.quote ?? $t('super_admin.common.not_available'),
                            invoice: metrics.avg_days_to_first?.invoice ?? $t('super_admin.common.not_available'),
                            product: metrics.avg_days_to_first?.product ?? $t('super_admin.common.not_available'),
                            service: metrics.avg_days_to_first?.service ?? $t('super_admin.common.not_available'),
                            job: metrics.avg_days_to_first?.work ?? $t('super_admin.common.not_available')
                        }) }}
                    </div>
                    <div class="mt-2 text-xs text-stone-500 dark:text-neutral-400">
                        {{ $t('super_admin.dashboard.usage.service_mix', {
                            services: formatNumber(serviceMix.services),
                            products: formatNumber(serviceMix.products),
                            percent: serviceMix.servicePercent
                        }) }}
                    </div>
                </div>
            </div>

            <div class="grid gap-3 lg:grid-cols-2">
                <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                        {{ $t('super_admin.dashboard.cohorts.title') }}
                    </h2>
                    <div
                        class="mt-4 overflow-x-auto [&::-webkit-scrollbar]:h-2 [&::-webkit-scrollbar-thumb]:rounded-full [&::-webkit-scrollbar-track]:bg-stone-100 [&::-webkit-scrollbar-thumb]:bg-stone-300 dark:[&::-webkit-scrollbar-track]:bg-neutral-700 dark:[&::-webkit-scrollbar-thumb]:bg-neutral-500">
                        <table class="min-w-full divide-y divide-stone-200 text-sm text-left text-stone-600 dark:divide-neutral-700 dark:text-neutral-300">
                            <thead class="text-xs uppercase text-stone-500 dark:text-neutral-400">
                                <tr>
                                    <th class="py-2">{{ $t('super_admin.dashboard.cohorts.month') }}</th>
                                    <th class="py-2">{{ $t('super_admin.dashboard.cohorts.new') }}</th>
                                    <th class="py-2">{{ $t('super_admin.dashboard.cohorts.retained') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-stone-200 dark:divide-neutral-700">
                                <tr v-for="row in metrics.cohorts" :key="row.month">
                                    <td class="py-2">{{ row.month }}</td>
                                    <td class="py-2">{{ formatNumber(row.new) }}</td>
                                    <td class="py-2">{{ formatPercent(row.retained_30d) }}</td>
                                </tr>
                                <tr v-if="!metrics.cohorts?.length">
                                    <td colspan="3" class="py-3 text-center text-sm text-stone-500 dark:text-neutral-400">
                                        {{ $t('super_admin.dashboard.cohorts.empty') }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                    {{ $t('super_admin.dashboard.data_quality.title') }}
                </h2>
                <KpiMetricGrid
                    class="mt-4"
                    :metrics="dataQualityMetrics"
                    grid-class="sm:grid-cols-3"
                    compact
                />
            </div>

            <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                        {{ $t('super_admin.dashboard.audit.title') }}
                    </h2>
                    <Link :href="route('superadmin.tenants.index')"
                        class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800">
                        {{ $t('super_admin.dashboard.audit.impersonate_cta') }}
                    </Link>
                </div>
                <form class="mt-4 grid gap-3 md:grid-cols-4" @submit.prevent="applyAuditFilters">
                    <div>
                        <label class="text-xs text-stone-500 dark:text-neutral-400">
                            {{ $t('super_admin.dashboard.audit.filters.admin') }}
                        </label>
                        <select v-model="auditFilterForm.admin_id"
                            class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                            <option value="">{{ $t('super_admin.common.all') }}</option>
                            <option v-for="admin in audit_options?.admins || []" :key="admin.id" :value="String(admin.id)">
                                {{ admin.name || admin.email }}
                            </option>
                        </select>
                    </div>
                    <div>
                        <label class="text-xs text-stone-500 dark:text-neutral-400">
                            {{ $t('super_admin.dashboard.audit.filters.tenant') }}
                        </label>
                        <select v-model="auditFilterForm.tenant_id"
                            class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                            <option value="">{{ $t('super_admin.common.all') }}</option>
                            <option v-for="tenant in audit_options?.tenants || []" :key="tenant.id" :value="String(tenant.id)">
                                {{ tenant.company_name || tenant.email }}
                            </option>
                        </select>
                    </div>
                    <div>
                        <label class="text-xs text-stone-500 dark:text-neutral-400">
                            {{ $t('super_admin.dashboard.audit.filters.action') }}
                        </label>
                        <select v-model="auditFilterForm.action"
                            class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                            <option value="">{{ $t('super_admin.common.all') }}</option>
                            <option v-for="action in audit_options?.actions || []" :key="action" :value="action">
                                {{ auditActionLabel(action) }}
                            </option>
                        </select>
                    </div>
                    <div class="flex items-end gap-2">
                        <button type="button" @click="resetAuditFilters"
                            class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800">
                            {{ $t('super_admin.common.clear') }}
                        </button>
                        <button type="submit"
                            class="rounded-sm border border-transparent bg-green-600 px-3 py-2 text-xs font-semibold text-white hover:bg-green-700">
                            {{ $t('super_admin.common.apply_filters') }}
                        </button>
                    </div>
                </form>
                <div class="mt-4">
                    <div v-if="recent_audits.length === 0" class="text-sm text-stone-500 dark:text-neutral-400">
                        {{ $t('super_admin.dashboard.audit.empty') }}
                    </div>
                    <ul v-else class="space-y-2 text-sm text-stone-700 dark:text-neutral-200">
                        <li v-for="audit in recent_audits" :key="audit.id" class="flex items-start justify-between gap-3">
                            <div>
                                <div class="font-semibold text-stone-800 dark:text-neutral-100">
                                    {{ auditActionLabel(audit.action) }}
                                </div>
                                <div class="text-xs text-stone-500 dark:text-neutral-400">
                                    {{ $t('super_admin.dashboard.audit.action_by', { user: resolveAuditUser(audit) }) }}
                                    <span v-if="audit.subject_id">· #{{ audit.subject_id }}</span>
                                </div>
                            </div>
                            <span class="text-xs text-stone-500 dark:text-neutral-400">
                                {{ formatDate(audit.created_at) }}
                            </span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
