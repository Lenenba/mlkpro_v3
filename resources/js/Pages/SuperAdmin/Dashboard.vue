<script setup>
import { computed } from 'vue';
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

const formatNumber = (value) => new Intl.NumberFormat().format(value ?? 0);
const formatPercent = (value) => `${value ?? 0}%`;
const formatBytes = (bytes) => {
    if (bytes === null || bytes === undefined) {
        return 'N/A';
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
    <Head title="Super Admin Dashboard" />

    <AuthenticatedLayout>
        <div class="space-y-6">
            <section class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="space-y-1">
                    <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">Platform dashboard</h1>
                    <p class="text-sm text-stone-600 dark:text-neutral-400">
                        Global KPIs and platform health across all companies.
                    </p>
                </div>
            </section>

            <div class="grid gap-3 md:grid-cols-3">
                <div class="rounded-sm border border-stone-200 border-t-4 border-t-emerald-600 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                    <p class="text-xs text-stone-500 dark:text-neutral-400">Total companies</p>
                    <p class="mt-2 text-2xl font-semibold text-stone-800 dark:text-white">
                        {{ formatNumber(metrics.companies_total) }}
                    </p>
                    <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                        Onboarded: {{ formatNumber(metrics.companies_onboarded) }} ({{ formatPercent(metrics.onboarding_conversion) }})
                    </p>
                </div>
                <div class="rounded-sm border border-stone-200 border-t-4 border-t-amber-600 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                    <p class="text-xs text-stone-500 dark:text-neutral-400">New companies (30d)</p>
                    <p class="mt-2 text-2xl font-semibold text-stone-800 dark:text-white">
                        {{ formatNumber(newCompanies30) }}
                    </p>
                    <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                        Onboarding conversion: {{ formatPercent(metrics.onboarding_conversion_30d) }}
                    </p>
                </div>
                <div class="rounded-sm border border-stone-200 border-t-4 border-t-blue-600 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                    <p class="text-xs text-stone-500 dark:text-neutral-400">Active companies</p>
                    <p class="mt-2 text-2xl font-semibold text-stone-800 dark:text-white">
                        WAU {{ formatNumber(metrics.wau) }} / MAU {{ formatNumber(metrics.mau) }}
                    </p>
                    <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                        Activation J7 {{ formatPercent(metrics.activation_rates?.j7) }} | J30 {{ formatPercent(metrics.activation_rates?.j30) }}
                    </p>
                </div>
            </div>

            <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">Acquisition (last 7 days)</h2>
                <div class="mt-3 grid gap-2 sm:grid-cols-7 text-xs text-stone-600 dark:text-neutral-300">
                    <div v-for="row in recentAcquisition" :key="row.date" class="rounded-sm border border-stone-200 p-2 text-center dark:border-neutral-700">
                        <div class="text-stone-500">{{ row.date }}</div>
                        <div class="mt-1 text-sm font-semibold text-stone-800 dark:text-neutral-100">
                            {{ row.count }}
                        </div>
                    </div>
                    <div v-if="recentAcquisition.length === 0" class="text-sm text-stone-500 dark:text-neutral-400">
                        No acquisition data.
                    </div>
                </div>
            </div>

            <div class="grid gap-3 lg:grid-cols-2">
                <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">Usage (last 30 days)</h2>
                    <div class="mt-4 grid gap-3 sm:grid-cols-2">
                        <div class="text-sm text-stone-700 dark:text-neutral-200">
                            Quotes: <span class="font-semibold">{{ formatNumber(metrics.activity_counts?.quotes) }}</span>
                        </div>
                        <div class="text-sm text-stone-700 dark:text-neutral-200">
                            Invoices: <span class="font-semibold">{{ formatNumber(metrics.activity_counts?.invoices) }}</span>
                        </div>
                        <div class="text-sm text-stone-700 dark:text-neutral-200">
                            Jobs: <span class="font-semibold">{{ formatNumber(metrics.activity_counts?.works) }}</span>
                        </div>
                        <div class="text-sm text-stone-700 dark:text-neutral-200">
                            Products: <span class="font-semibold">{{ formatNumber(metrics.activity_counts?.products) }}</span>
                        </div>
                        <div class="text-sm text-stone-700 dark:text-neutral-200">
                            Services: <span class="font-semibold">{{ formatNumber(metrics.activity_counts?.services) }}</span>
                        </div>
                    </div>
                    <div class="mt-4 text-xs text-stone-500 dark:text-neutral-400">
                        Avg days to first: Quote {{ metrics.avg_days_to_first?.quote ?? 'N/A' }},
                        Invoice {{ metrics.avg_days_to_first?.invoice ?? 'N/A' }},
                        Product {{ metrics.avg_days_to_first?.product ?? 'N/A' }},
                        Service {{ metrics.avg_days_to_first?.service ?? 'N/A' }},
                        Job {{ metrics.avg_days_to_first?.work ?? 'N/A' }}
                    </div>
                    <div class="mt-2 text-xs text-stone-500 dark:text-neutral-400">
                        Service mix: {{ formatNumber(serviceMix.services) }} services / {{ formatNumber(serviceMix.products) }} products ({{ serviceMix.servicePercent }}% services)
                    </div>
                </div>

                <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">Revenue & retention</h2>
                    <div class="mt-4 grid gap-3 sm:grid-cols-2">
                        <div class="text-sm text-stone-700 dark:text-neutral-200">
                            MRR: <span class="font-semibold">{{ metrics.subscription?.mrr ?? 0 }}</span>
                        </div>
                        <div class="text-sm text-stone-700 dark:text-neutral-200">
                            ARPU: <span class="font-semibold">{{ metrics.subscription?.arpu ?? 0 }}</span>
                        </div>
                        <div class="text-sm text-stone-700 dark:text-neutral-200">
                            Active subs: <span class="font-semibold">{{ formatNumber(metrics.subscription?.active_subscriptions) }}</span>
                        </div>
                        <div class="text-sm text-stone-700 dark:text-neutral-200">
                            Trialing: <span class="font-semibold">{{ formatNumber(metrics.subscription?.trialing_subscriptions) }}</span>
                        </div>
                        <div class="text-sm text-stone-700 dark:text-neutral-200">
                            Payment failed: <span class="font-semibold">{{ formatNumber(metrics.subscription?.payment_failed) }}</span>
                        </div>
                        <div class="text-sm text-stone-700 dark:text-neutral-200">
                            Trial conversion: <span class="font-semibold">{{ formatPercent(metrics.subscription?.trial_conversion) }}</span>
                        </div>
                        <div class="text-sm text-stone-700 dark:text-neutral-200">
                            Churn (30d): <span class="font-semibold">{{ formatNumber(metrics.subscription?.churned_30d) }}</span>
                        </div>
                        <div class="text-sm text-stone-700 dark:text-neutral-200">
                            Churn rate: <span class="font-semibold">{{ formatPercent(metrics.subscription?.churn_rate) }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="grid gap-3 lg:grid-cols-2">
                <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">Cohorts (30d retention)</h2>
                    <div
                        class="mt-4 overflow-x-auto [&::-webkit-scrollbar]:h-2 [&::-webkit-scrollbar-thumb]:rounded-full [&::-webkit-scrollbar-track]:bg-stone-100 [&::-webkit-scrollbar-thumb]:bg-stone-300 dark:[&::-webkit-scrollbar-track]:bg-neutral-700 dark:[&::-webkit-scrollbar-thumb]:bg-neutral-500">
                        <table class="min-w-full divide-y divide-stone-200 text-sm text-left text-stone-600 dark:divide-neutral-700 dark:text-neutral-300">
                            <thead class="text-xs uppercase text-stone-500 dark:text-neutral-400">
                                <tr>
                                    <th class="py-2">Month</th>
                                    <th class="py-2">New</th>
                                    <th class="py-2">Retained</th>
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
                                        No cohort data yet.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">Health</h2>
                    <div class="mt-4 space-y-2 text-sm text-stone-700 dark:text-neutral-200">
                        <div>Failed jobs 24h: <span class="font-semibold">{{ formatNumber(metrics.health?.failed_jobs_24h) }}</span></div>
                        <div>Failed jobs 7d: <span class="font-semibold">{{ formatNumber(metrics.health?.failed_jobs_7d) }}</span></div>
                        <div>Email failures 24h: <span class="font-semibold">{{ formatNumber(metrics.health?.failed_mail_jobs_24h) }}</span></div>
                        <div>Pending jobs: <span class="font-semibold">{{ formatNumber(metrics.health?.pending_jobs) }}</span></div>
                        <div>Oldest job age (min): <span class="font-semibold">{{ metrics.health?.oldest_job_minutes ?? 'N/A' }}</span></div>
                        <div>Public storage: <span class="font-semibold">{{ formatBytes(metrics.health?.storage_public_bytes) }}</span></div>
                    </div>
                </div>
            </div>

            <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">Data quality</h2>
                <div class="mt-4 grid gap-3 sm:grid-cols-3 text-sm text-stone-700 dark:text-neutral-200">
                    <div>Customer email duplicates: <span class="font-semibold">{{ formatNumber(metrics.data_quality?.customer_email_duplicates) }}</span></div>
                    <div>Customer name duplicates: <span class="font-semibold">{{ formatNumber(metrics.data_quality?.customer_name_duplicates) }}</span></div>
                    <div>Product name duplicates: <span class="font-semibold">{{ formatNumber(metrics.data_quality?.product_name_duplicates) }}</span></div>
                </div>
            </div>

            <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">Recent admin activity</h2>
                <div class="mt-4">
                    <div v-if="recent_audits.length === 0" class="text-sm text-stone-500 dark:text-neutral-400">
                        No recent admin actions.
                    </div>
                    <ul v-else class="space-y-2 text-sm text-stone-700 dark:text-neutral-200">
                        <li v-for="audit in recent_audits" :key="audit.id" class="flex items-center justify-between">
                            <span>
                                {{ audit.action }} by {{ audit.user?.name || audit.user?.email || 'Unknown' }}
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
