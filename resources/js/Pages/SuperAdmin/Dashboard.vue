<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { Head, Link, useForm } from '@inertiajs/vue3';
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

const healthCards = computed(() => ([
    {
        key: 'failed_jobs_24h',
        label: t('super_admin.dashboard.health.failed_jobs_24h'),
        value: formatNumber(props.metrics.health?.failed_jobs_24h),
        tone: (props.metrics.health?.failed_jobs_24h ?? 0) > 0 ? 'text-red-600' : 'text-emerald-700',
    },
    {
        key: 'pending_jobs',
        label: t('super_admin.dashboard.health.pending_jobs'),
        value: formatNumber(props.metrics.health?.pending_jobs),
        tone: (props.metrics.health?.pending_jobs ?? 0) > 0 ? 'text-amber-600' : 'text-emerald-700',
    },
    {
        key: 'email_failures_24h',
        label: t('super_admin.dashboard.health.email_failures_24h'),
        value: formatNumber(props.metrics.health?.failed_mail_jobs_24h),
        tone: (props.metrics.health?.failed_mail_jobs_24h ?? 0) > 0 ? 'text-red-600' : 'text-emerald-700',
    },
    {
        key: 'storage_public_bytes',
        label: t('super_admin.dashboard.health.public_storage'),
        value: formatBytes(props.metrics.health?.storage_public_bytes),
        tone: 'text-stone-700 dark:text-neutral-200',
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
]));

const limitAlerts = computed(() => props.metrics.alerts?.limit_warnings || { count: 0, tenants: [] });
const riskTenants = computed(() => props.metrics.at_risk_tenants?.tenants || []);
const usageTrends = computed(() => props.metrics.usage_trends || []);
const siteTraffic = computed(() => props.metrics.site_traffic || {});

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
                <div class="rounded-sm border border-stone-200 border-t-4 border-t-emerald-600 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                    <p class="text-xs text-stone-500 dark:text-neutral-400">
                        {{ $t('super_admin.dashboard.kpi.total_companies') }}
                    </p>
                    <p class="mt-2 text-2xl font-semibold text-stone-800 dark:text-white">
                        {{ formatNumber(metrics.companies_total) }}
                    </p>
                    <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                        {{ $t('super_admin.dashboard.kpi.onboarded', {
                            count: formatNumber(metrics.companies_onboarded),
                            percent: formatPercent(metrics.onboarding_conversion)
                        }) }}
                    </p>
                </div>
                <div class="rounded-sm border border-stone-200 border-t-4 border-t-amber-600 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                    <p class="text-xs text-stone-500 dark:text-neutral-400">
                        {{ $t('super_admin.dashboard.kpi.new_companies_30d') }}
                    </p>
                    <p class="mt-2 text-2xl font-semibold text-stone-800 dark:text-white">
                        {{ formatNumber(newCompanies30) }}
                    </p>
                    <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                        {{ $t('super_admin.dashboard.kpi.onboarding_conversion_30d', {
                            percent: formatPercent(metrics.onboarding_conversion_30d)
                        }) }}
                    </p>
                </div>
                <div class="rounded-sm border border-stone-200 border-t-4 border-t-blue-600 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                    <p class="text-xs text-stone-500 dark:text-neutral-400">
                        {{ $t('super_admin.dashboard.kpi.active_companies') }}
                    </p>
                    <p class="mt-2 text-2xl font-semibold text-stone-800 dark:text-white">
                        {{ $t('super_admin.dashboard.kpi.active_wau_mau', {
                            wau: formatNumber(metrics.wau),
                            mau: formatNumber(metrics.mau)
                        }) }}
                    </p>
                    <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                        {{ $t('super_admin.dashboard.kpi.activation_rates', {
                            j7: formatPercent(metrics.activation_rates?.j7),
                            j30: formatPercent(metrics.activation_rates?.j30)
                        }) }}
                    </p>
                </div>
                <div class="rounded-sm border border-stone-200 border-t-4 border-t-violet-600 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                    <p class="text-xs text-stone-500 dark:text-neutral-400">
                        {{ $t('super_admin.dashboard.kpi.platform_health') }}
                    </p>
                    <p class="mt-2 text-2xl font-semibold text-stone-800 dark:text-white">
                        {{ formatNumber(metrics.health?.failed_jobs_24h) }}
                    </p>
                    <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                        {{ $t('super_admin.dashboard.kpi.health_hint', {
                            failed: formatNumber(metrics.health?.failed_jobs_24h),
                            pending: formatNumber(metrics.health?.pending_jobs)
                        }) }}
                    </p>
                </div>
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
                        <div class="rounded-sm border border-stone-200 p-3 text-sm dark:border-neutral-700">
                            <div class="text-xs text-stone-500 dark:text-neutral-400">
                                {{ $t('super_admin.dashboard.alerts.limits') }}
                            </div>
                            <div class="mt-1 text-lg font-semibold" :class="limitAlerts.count > 0 ? 'text-rose-600' : 'text-emerald-600'">
                                {{ formatNumber(limitAlerts.count) }}
                            </div>
                        </div>
                        <div class="rounded-sm border border-stone-200 p-3 text-sm dark:border-neutral-700">
                            <div class="text-xs text-stone-500 dark:text-neutral-400">
                                {{ $t('super_admin.dashboard.alerts.stripe_failures') }}
                            </div>
                            <div class="mt-1 text-lg font-semibold" :class="(metrics.alerts?.stripe_failures_24h ?? 0) > 0 ? 'text-rose-600' : 'text-emerald-600'">
                                {{ formatNumber(metrics.alerts?.stripe_failures_24h) }}
                            </div>
                        </div>
                        <div class="rounded-sm border border-stone-200 p-3 text-sm dark:border-neutral-700">
                            <div class="text-xs text-stone-500 dark:text-neutral-400">
                                {{ $t('super_admin.dashboard.alerts.smtp_failures') }}
                            </div>
                            <div class="mt-1 text-lg font-semibold" :class="(metrics.alerts?.smtp_failures_24h ?? 0) > 0 ? 'text-rose-600' : 'text-emerald-600'">
                                {{ formatNumber(metrics.alerts?.smtp_failures_24h) }}
                            </div>
                        </div>
                        <div class="rounded-sm border border-stone-200 p-3 text-sm dark:border-neutral-700">
                            <div class="text-xs text-stone-500 dark:text-neutral-400">
                                {{ $t('super_admin.dashboard.alerts.jobs_backlog') }}
                            </div>
                            <div class="mt-1 text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                {{ $t('super_admin.dashboard.alerts.jobs_backlog_detail', {
                                    pending: formatNumber(metrics.alerts?.jobs_backlog?.pending),
                                    oldest: metrics.alerts?.jobs_backlog?.oldest_minutes ?? $t('super_admin.common.not_available')
                                }) }}
                            </div>
                        </div>
                        <div class="rounded-sm border border-stone-200 p-3 text-sm dark:border-neutral-700 sm:col-span-2">
                            <div class="text-xs text-stone-500 dark:text-neutral-400">
                                {{ $t('super_admin.dashboard.alerts.storage') }}
                            </div>
                            <div class="mt-1 flex items-center gap-2 text-sm">
                                <span class="font-semibold" :class="metrics.alerts?.storage?.critical ? 'text-rose-600' : 'text-emerald-600'">
                                    {{ metrics.alerts?.storage?.used_percent !== null && metrics.alerts?.storage?.used_percent !== undefined
                                        ? `${metrics.alerts?.storage?.used_percent}%`
                                        : $t('super_admin.common.not_available') }}
                                </span>
                                <span class="text-xs text-stone-500 dark:text-neutral-400">
                                    {{ formatBytes(metrics.alerts?.storage?.used_bytes) }}
                                </span>
                            </div>
                        </div>
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
                <div class="mt-4 grid gap-3 sm:grid-cols-4 text-sm text-stone-700 dark:text-neutral-200">
                    <div v-for="card in healthCards" :key="card.key">
                        <div class="text-xs text-stone-500 dark:text-neutral-400">{{ card.label }}</div>
                        <div class="mt-1 font-semibold" :class="card.tone">{{ card.value }}</div>
                    </div>
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
                <div class="mt-4 grid gap-3 sm:grid-cols-3 text-sm text-stone-700 dark:text-neutral-200">
                    <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-900">
                        <div class="text-xs text-stone-500 dark:text-neutral-400">
                            {{ $t('super_admin.dashboard.site_traffic.last_24h') }}
                        </div>
                        <div class="mt-1 text-lg font-semibold text-stone-800 dark:text-neutral-100">
                            {{ formatNumber(siteTraffic.total_24h) }}
                        </div>
                        <div class="text-xs text-stone-500 dark:text-neutral-400">
                            {{ $t('super_admin.dashboard.site_traffic.unique_label', { count: formatNumber(siteTraffic.unique_24h) }) }}
                        </div>
                    </div>
                    <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-900">
                        <div class="text-xs text-stone-500 dark:text-neutral-400">
                            {{ $t('super_admin.dashboard.site_traffic.last_7d') }}
                        </div>
                        <div class="mt-1 text-lg font-semibold text-stone-800 dark:text-neutral-100">
                            {{ formatNumber(siteTraffic.total_7d) }}
                        </div>
                        <div class="text-xs text-stone-500 dark:text-neutral-400">
                            {{ $t('super_admin.dashboard.site_traffic.unique_label', { count: formatNumber(siteTraffic.unique_7d) }) }}
                        </div>
                    </div>
                    <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-900">
                        <div class="text-xs text-stone-500 dark:text-neutral-400">
                            {{ $t('super_admin.dashboard.site_traffic.last_30d') }}
                        </div>
                        <div class="mt-1 text-lg font-semibold text-stone-800 dark:text-neutral-100">
                            {{ formatNumber(siteTraffic.total_30d) }}
                        </div>
                        <div class="text-xs text-stone-500 dark:text-neutral-400">
                            {{ $t('super_admin.dashboard.site_traffic.unique_label', { count: formatNumber(siteTraffic.unique_30d) }) }}
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
                <div class="mt-4 grid gap-3 sm:grid-cols-3 text-sm text-stone-700 dark:text-neutral-200">
                    <div>
                        {{ $t('super_admin.dashboard.data_quality.customer_email_duplicates') }}:
                        <span class="font-semibold">{{ formatNumber(metrics.data_quality?.customer_email_duplicates) }}</span>
                    </div>
                    <div>
                        {{ $t('super_admin.dashboard.data_quality.customer_name_duplicates') }}:
                        <span class="font-semibold">{{ formatNumber(metrics.data_quality?.customer_name_duplicates) }}</span>
                    </div>
                    <div>
                        {{ $t('super_admin.dashboard.data_quality.product_name_duplicates') }}:
                        <span class="font-semibold">{{ formatNumber(metrics.data_quality?.product_name_duplicates) }}</span>
                    </div>
                </div>
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
                                {{ action }}
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
                                    {{ audit.action }}
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
