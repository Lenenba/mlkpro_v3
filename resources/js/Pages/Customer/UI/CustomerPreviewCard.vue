<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import CardNoHeader from '@/Components/UI/CardNoHeader.vue';
import KpiMetricGrid from '@/Components/Dashboard/KpiMetricGrid.vue';
import { humanizeDate } from '@/utils/date';
import { useCurrencyFormatter } from '@/utils/currency';
import { useAccountFeatures } from '@/Composables/useAccountFeatures';

const props = defineProps({
    stats: {
        type: Object,
        default: () => ({}),
    },
    billing: {
        type: Object,
        default: () => ({ summary: {} }),
    },
    latestQuote: {
        type: Object,
        default: null,
    },
    latestWork: {
        type: Object,
        default: null,
    },
    latestInvoice: {
        type: Object,
        default: null,
    },
});

const { t } = useI18n();
const { hasFeature } = useAccountFeatures();
const quotesFeatureEnabled = computed(() => hasFeature('quotes'));
const requestsFeatureEnabled = computed(() => hasFeature('requests'));
const jobsFeatureEnabled = computed(() => hasFeature('jobs'));
const invoicesFeatureEnabled = computed(() => hasFeature('invoices'));

const formatDate = (value) => humanizeDate(value);
const { formatCurrency } = useCurrencyFormatter();
const formatNumber = (value) =>
    Number(value || 0).toLocaleString(undefined, {
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    });
const formatStatus = (status, keyPrefix = '') => {
    if (!status) {
        return t('customers.labels.unknown_status');
    }
    if (keyPrefix) {
        const key = `${keyPrefix}.${status}`;
        const translated = t(key);
        if (translated && translated !== key) {
            return translated;
        }
    }

    return String(status).replace(/_/g, ' ');
};
const hasValue = (value) => value !== null && value !== undefined;

const kpiMax = computed(() => {
    const values = [];

    if (quotesFeatureEnabled.value) {
        values.push(Number(props.stats?.quotes || 0));
    }
    if (jobsFeatureEnabled.value) {
        values.push(Number(props.stats?.active_works || 0));
        values.push(Number(props.stats?.jobs || 0));
    }
    if (invoicesFeatureEnabled.value) {
        values.push(Number(props.stats?.invoices || 0));
    }
    if (requestsFeatureEnabled.value) {
        values.push(Number(props.stats?.requests || 0));
    }

    return Math.max(1, ...values);
});

const kpiBarWidth = (value) => {
    const safe = Number(value || 0);
    if (safe <= 0) {
        return '0%';
    }

    const max = kpiMax.value || 1;
    const percent = max ? Math.round((safe / max) * 100) : 0;

    return `${Math.min(100, Math.max(12, percent))}%`;
};

const balanceBarWidth = computed(() => {
    const balance = Math.max(0, Number(props.billing?.summary?.balance_due || 0));
    const total = Math.max(0, Number(props.billing?.summary?.total_invoiced || 0));
    if (!balance) {
        return '0%';
    }
    if (!total) {
        return '60%';
    }

    const percent = Math.round((balance / total) * 100);

    return `${Math.min(100, Math.max(12, percent))}%`;
});
const countMetricPoints = (value) => {
    const safe = Number(value || 0);

    return safe > 0
        ? [{ value: formatNumber(safe), height: kpiBarWidth(safe) }]
        : [];
};
const previewMetrics = computed(() => [
    quotesFeatureEnabled.value
        ? {
            key: 'quotes',
            value: formatNumber(props.stats?.quotes ?? 0),
            tone: 'emerald',
            points: countMetricPoints(props.stats?.quotes),
        }
        : null,
    jobsFeatureEnabled.value
        ? {
            key: 'active-jobs',
            value: formatNumber(props.stats?.active_works ?? 0),
            tone: 'sky',
            points: countMetricPoints(props.stats?.active_works),
        }
        : null,
    jobsFeatureEnabled.value
        ? {
            key: 'jobs',
            value: formatNumber(props.stats?.jobs ?? 0),
            tone: 'amber',
            points: countMetricPoints(props.stats?.jobs),
        }
        : null,
    invoicesFeatureEnabled.value
        ? {
            key: 'invoices',
            value: formatNumber(props.stats?.invoices ?? 0),
            tone: 'indigo',
            points: countMetricPoints(props.stats?.invoices),
        }
        : null,
    requestsFeatureEnabled.value
        ? {
            key: 'requests',
            value: formatNumber(props.stats?.requests ?? 0),
            tone: 'rose',
            points: countMetricPoints(props.stats?.requests),
        }
        : null,
    invoicesFeatureEnabled.value
        ? {
            key: 'balance-due',
            value: formatCurrency(props.billing?.summary?.balance_due),
            tone: 'teal',
            points: Number(props.billing?.summary?.balance_due || 0) > 0
                ? [{
                    value: formatCurrency(props.billing?.summary?.balance_due),
                    height: balanceBarWidth.value,
                }]
                : [],
        }
        : null,
].filter(Boolean));
const withPreviewLabels = (labels) => previewMetrics.value.map((metric) => ({
    ...metric,
    label: labels[metric.key],
}));
const hasPreviewContent = computed(() => (
    quotesFeatureEnabled.value
    || requestsFeatureEnabled.value
    || jobsFeatureEnabled.value
    || invoicesFeatureEnabled.value
));
</script>

<template>
    <CardNoHeader v-if="hasPreviewContent">
        <template #title>{{ $t('customers.details.preview.title') }}</template>

        <KpiMetricGrid
            class="rise-stagger"
            :metrics="withPreviewLabels({
                quotes: $t('customers.details.preview.quotes'),
                'active-jobs': $t('customers.details.preview.active_jobs'),
                jobs: $t('customers.details.preview.jobs'),
                invoices: $t('customers.details.preview.invoices'),
                requests: $t('customers.details.preview.requests'),
                'balance-due': $t('customers.details.preview.balance_due'),
            })"
            grid-class="grid-cols-2"
            :aria-label="$t('customers.details.preview.title')"
        />

        <div class="mt-4 space-y-3 text-sm">
            <div v-if="quotesFeatureEnabled" class="rounded-sm border border-stone-200 px-3 py-2 dark:border-neutral-700">
                <div class="text-xs uppercase text-stone-500 dark:text-neutral-400">{{ $t('customers.details.preview.latest_quote') }}</div>
                <div v-if="latestQuote">
                    <Link :href="route('customer.quote.show', latestQuote.id)" class="font-medium text-stone-800 hover:underline dark:text-neutral-200">
                        {{ latestQuote.number ? $t('customers.details.preview.quote_number', { number: latestQuote.number }) : $t('customers.details.preview.quote_fallback') }}
                    </Link>
                    <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                        {{ formatStatus(latestQuote.status, 'quotes.status') }} | {{ formatDate(latestQuote.created_at) }}
                    </div>
                    <div v-if="hasValue(latestQuote.total)" class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                        {{ $t('customers.details.preview.total') }} {{ formatCurrency(latestQuote.total) }}
                    </div>
                </div>
                <div v-else class="text-xs text-stone-500 dark:text-neutral-400">{{ $t('customers.details.preview.no_quotes') }}</div>
            </div>

            <div v-if="jobsFeatureEnabled" class="rounded-sm border border-stone-200 px-3 py-2 dark:border-neutral-700">
                <div class="text-xs uppercase text-stone-500 dark:text-neutral-400">{{ $t('customers.details.preview.latest_job') }}</div>
                <div v-if="latestWork">
                    <Link :href="route('work.show', latestWork.id)" class="font-medium text-stone-800 hover:underline dark:text-neutral-200">
                        {{ latestWork.job_title || $t('customers.details.preview.job_fallback') }}
                    </Link>
                    <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                        {{ formatStatus(latestWork.status, 'jobs.status') }} | {{ formatDate(latestWork.start_date || latestWork.created_at) }}
                    </div>
                </div>
                <div v-else class="text-xs text-stone-500 dark:text-neutral-400">{{ $t('customers.details.preview.no_jobs') }}</div>
            </div>

            <div v-if="invoicesFeatureEnabled" class="rounded-sm border border-stone-200 px-3 py-2 dark:border-neutral-700">
                <div class="text-xs uppercase text-stone-500 dark:text-neutral-400">{{ $t('customers.details.preview.latest_invoice') }}</div>
                <div v-if="latestInvoice">
                    <Link :href="route('invoice.show', latestInvoice.id)" class="font-medium text-stone-800 hover:underline dark:text-neutral-200">
                        {{ latestInvoice.number ? $t('customers.details.preview.invoice_number', { number: latestInvoice.number }) : $t('customers.details.preview.invoice_fallback') }}
                    </Link>
                    <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                        {{ formatStatus(latestInvoice.status, 'dashboard.status.invoice') }} | {{ formatDate(latestInvoice.created_at) }}
                    </div>
                    <div v-if="hasValue(latestInvoice.total)" class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                        {{ $t('customers.details.preview.total') }} {{ formatCurrency(latestInvoice.total) }}
                    </div>
                </div>
                <div v-else class="text-xs text-stone-500 dark:text-neutral-400">{{ $t('customers.details.preview.no_invoices') }}</div>
            </div>
        </div>
    </CardNoHeader>
</template>
