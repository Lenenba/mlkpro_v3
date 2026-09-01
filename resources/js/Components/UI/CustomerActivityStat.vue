<script setup>
import { computed, defineAsyncComponent } from 'vue';
import { useI18n } from 'vue-i18n';
import { useAccountFeatures } from '@/Composables/useAccountFeatures';
import { buildCustomerActivityChartData } from '@/utils/moduleRankingCharts';

const Barchart = defineAsyncComponent(() => import('@/Components/UI/Barchart.vue'));

const props = defineProps({
    items: {
        type: Array,
        default: () => [],
    },
    title: {
        type: String,
        default: '',
    },
});

const { t } = useI18n();
const { hasFeature } = useAccountFeatures();
const quotesFeatureEnabled = computed(() => hasFeature('quotes'));
const jobsFeatureEnabled = computed(() => hasFeature('jobs'));

const resolvedTitle = computed(() => props.title || t('customers.activity.title'));
const resolvedSubtitle = computed(() => {
    if (quotesFeatureEnabled.value && jobsFeatureEnabled.value) {
        return t('customers.activity.subtitle');
    }

    return t(quotesFeatureEnabled.value
        ? 'customers.activity.subtitle_quotes'
        : 'customers.activity.subtitle_jobs');
});

const formatNumber = (value) =>
    Number(value || 0).toLocaleString(undefined, { maximumFractionDigits: 0 });

const displayName = (item) => item.company_name
    || `${item.first_name || ''} ${item.last_name || ''}`.trim()
    || `${t('customers.labels.customer_fallback')} #${item.id}`;
const chartData = computed(() => buildCustomerActivityChartData(props.items, {
    labelForCustomer: displayName,
    quotesEnabled: quotesFeatureEnabled.value,
    jobsEnabled: jobsFeatureEnabled.value,
    quotesLabel: t('customers.activity.quotes_series'),
    jobsLabel: t('customers.activity.jobs_series'),
}));
const total = computed(() => chartData.value.details.reduce(
    (sum, item) => sum + item.total,
    0,
));
const chartHeight = computed(() => Math.min(
    420,
    Math.max(240, chartData.value.categories.length * 48 + 80),
));
const chartSubtitle = computed(() => [
    resolvedSubtitle.value,
    t('customers.activity.actions', { count: formatNumber(total.value) }),
].filter(Boolean).join(' · '));
const colorTones = computed(() => [
    quotesFeatureEnabled.value ? 'blue' : null,
    jobsFeatureEnabled.value ? 'emerald' : null,
].filter(Boolean));
const chartOptions = computed(() => ({
    dataLabels: {
        enabled: true,
        formatter: (value) => formatNumber(value),
    },
    plotOptions: {
        bar: {
            barHeight: '62%',
        },
    },
    xaxis: {
        min: 0,
        forceNiceScale: true,
        labels: {
            formatter: (value) => formatNumber(value),
        },
    },
    yaxis: {
        labels: {
            maxWidth: 128,
        },
    },
}));
</script>

<template>
    <div class="size-full min-w-0">
        <Suspense>
            <Barchart
                class="h-full"
                :title="resolvedTitle"
                :subtitle="chartSubtitle"
                :series="chartData.series"
                :categories="chartData.categories"
                :height="chartHeight"
                :options="chartOptions"
                :color-tones="colorTones"
                :value-formatter="formatNumber"
                :category-label="t('customers.activity.category_label')"
                :value-label="t('customers.activity.value_label')"
                :table-caption="t('customers.activity.table_caption')"
                :empty-message="t('customers.activity.empty')"
                horizontal
            />
            <template #fallback>
                <div
                    class="flex min-h-64 items-center justify-center rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900"
                    role="status"
                    aria-live="polite"
                >
                    <span class="sr-only">{{ t('charts.loading') }}</span>
                    <span class="h-52 w-full rounded-sm bg-stone-100 motion-safe:animate-pulse dark:bg-neutral-800" aria-hidden="true"></span>
                </div>
            </template>
        </Suspense>
    </div>
</template>
