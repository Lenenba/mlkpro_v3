<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { Head } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    metrics: {
        type: Object,
        required: true,
    },
    recent_audits: {
        type: Array,
        default: () => [],
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
</script>

<template>
    <Head :title="$t('super_admin.dashboard.page_title')" />

    <AuthenticatedLayout>
        <div class="space-y-6">
            <section class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="space-y-1">
                    <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">
                        {{ $t('super_admin.dashboard.title') }}
                    </h1>
                    <p class="text-sm text-stone-600 dark:text-neutral-400">
                        {{ $t('super_admin.dashboard.subtitle') }}
                    </p>
                </div>
            </section>

            <div class="grid gap-3 md:grid-cols-3">
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

            <div class="grid gap-3 lg:grid-cols-2">
                <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                        {{ $t('super_admin.dashboard.usage.title') }}
                    </h2>
                    <div class="mt-4 grid gap-3 sm:grid-cols-2">
                        <div class="text-sm text-stone-700 dark:text-neutral-200">
                            {{ $t('super_admin.dashboard.usage.quotes') }}:
                            <span class="font-semibold">{{ formatNumber(metrics.activity_counts?.quotes) }}</span>
                        </div>
                        <div class="text-sm text-stone-700 dark:text-neutral-200">
                            {{ $t('super_admin.dashboard.usage.invoices') }}:
                            <span class="font-semibold">{{ formatNumber(metrics.activity_counts?.invoices) }}</span>
                        </div>
                        <div class="text-sm text-stone-700 dark:text-neutral-200">
                            {{ $t('super_admin.dashboard.usage.jobs') }}:
                            <span class="font-semibold">{{ formatNumber(metrics.activity_counts?.works) }}</span>
                        </div>
                        <div class="text-sm text-stone-700 dark:text-neutral-200">
                            {{ $t('super_admin.dashboard.usage.products') }}:
                            <span class="font-semibold">{{ formatNumber(metrics.activity_counts?.products) }}</span>
                        </div>
                        <div class="text-sm text-stone-700 dark:text-neutral-200">
                            {{ $t('super_admin.dashboard.usage.services') }}:
                            <span class="font-semibold">{{ formatNumber(metrics.activity_counts?.services) }}</span>
                        </div>
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

                <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                        {{ $t('super_admin.dashboard.revenue.title') }}
                    </h2>
                    <div class="mt-4 grid gap-3 sm:grid-cols-2">
                        <div class="text-sm text-stone-700 dark:text-neutral-200">
                            {{ $t('super_admin.dashboard.revenue.mrr') }}:
                            <span class="font-semibold">{{ metrics.subscription?.mrr ?? 0 }}</span>
                        </div>
                        <div class="text-sm text-stone-700 dark:text-neutral-200">
                            {{ $t('super_admin.dashboard.revenue.arpu') }}:
                            <span class="font-semibold">{{ metrics.subscription?.arpu ?? 0 }}</span>
                        </div>
                        <div class="text-sm text-stone-700 dark:text-neutral-200">
                            {{ $t('super_admin.dashboard.revenue.active_subs') }}:
                            <span class="font-semibold">{{ formatNumber(metrics.subscription?.active_subscriptions) }}</span>
                        </div>
                        <div class="text-sm text-stone-700 dark:text-neutral-200">
                            {{ $t('super_admin.dashboard.revenue.trialing') }}:
                            <span class="font-semibold">{{ formatNumber(metrics.subscription?.trialing_subscriptions) }}</span>
                        </div>
                        <div class="text-sm text-stone-700 dark:text-neutral-200">
                            {{ $t('super_admin.dashboard.revenue.payment_failed') }}:
                            <span class="font-semibold">{{ formatNumber(metrics.subscription?.payment_failed) }}</span>
                        </div>
                        <div class="text-sm text-stone-700 dark:text-neutral-200">
                            {{ $t('super_admin.dashboard.revenue.trial_conversion') }}:
                            <span class="font-semibold">{{ formatPercent(metrics.subscription?.trial_conversion) }}</span>
                        </div>
                        <div class="text-sm text-stone-700 dark:text-neutral-200">
                            {{ $t('super_admin.dashboard.revenue.churn_30d') }}:
                            <span class="font-semibold">{{ formatNumber(metrics.subscription?.churned_30d) }}</span>
                        </div>
                        <div class="text-sm text-stone-700 dark:text-neutral-200">
                            {{ $t('super_admin.dashboard.revenue.churn_rate') }}:
                            <span class="font-semibold">{{ formatPercent(metrics.subscription?.churn_rate) }}</span>
                        </div>
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

                <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                        {{ $t('super_admin.dashboard.health.title') }}
                    </h2>
                    <div class="mt-4 space-y-2 text-sm text-stone-700 dark:text-neutral-200">
                        <div>
                            {{ $t('super_admin.dashboard.health.failed_jobs_24h') }}:
                            <span class="font-semibold">{{ formatNumber(metrics.health?.failed_jobs_24h) }}</span>
                        </div>
                        <div>
                            {{ $t('super_admin.dashboard.health.failed_jobs_7d') }}:
                            <span class="font-semibold">{{ formatNumber(metrics.health?.failed_jobs_7d) }}</span>
                        </div>
                        <div>
                            {{ $t('super_admin.dashboard.health.email_failures_24h') }}:
                            <span class="font-semibold">{{ formatNumber(metrics.health?.failed_mail_jobs_24h) }}</span>
                        </div>
                        <div>
                            {{ $t('super_admin.dashboard.health.pending_jobs') }}:
                            <span class="font-semibold">{{ formatNumber(metrics.health?.pending_jobs) }}</span>
                        </div>
                        <div>
                            {{ $t('super_admin.dashboard.health.oldest_job_minutes') }}:
                            <span class="font-semibold">{{ metrics.health?.oldest_job_minutes ?? $t('super_admin.common.not_available') }}</span>
                        </div>
                        <div>
                            {{ $t('super_admin.dashboard.health.public_storage') }}:
                            <span class="font-semibold">{{ formatBytes(metrics.health?.storage_public_bytes) }}</span>
                        </div>
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
                <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                    {{ $t('super_admin.dashboard.recent_activity.title') }}
                </h2>
                <div class="mt-4">
                    <div v-if="recent_audits.length === 0" class="text-sm text-stone-500 dark:text-neutral-400">
                        {{ $t('super_admin.dashboard.recent_activity.empty') }}
                    </div>
                    <ul v-else class="space-y-2 text-sm text-stone-700 dark:text-neutral-200">
                        <li v-for="audit in recent_audits" :key="audit.id" class="flex items-center justify-between">
                            <span>
                                {{ t('super_admin.dashboard.recent_activity.action_by', {
                                    action: audit.action,
                                    user: resolveAuditUser(audit)
                                }) }}
                            </span>
                            <span class="text-xs text-stone-500 dark:text-neutral-400">
                                {{ new Date(audit.created_at).toLocaleString() }}
                            </span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
