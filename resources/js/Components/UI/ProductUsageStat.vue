<script setup>
import { computed, defineAsyncComponent } from 'vue';
import { useI18n } from 'vue-i18n';
import { buildProductUsageChartData } from '@/utils/moduleRankingCharts';

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

const formatNumber = (value) =>
    Number(value || 0).toLocaleString(undefined, { maximumFractionDigits: 0 });

const displayTitle = computed(() => props.title || t('products.usage.title'));
const chartData = computed(() => buildProductUsageChartData(props.items, {
    labelForProduct: (item) => item.name,
    usageLabel: t('products.usage.series_label'),
}));
const total = computed(() => chartData.value.details.reduce(
    (sum, item) => sum + item.quantity,
    0,
));
const chartHeight = computed(() => Math.min(
    420,
    Math.max(240, chartData.value.categories.length * 48 + 80),
));
const chartSubtitle = computed(() => [
    t('products.usage.subtitle'),
    t('products.usage.used', { count: formatNumber(total.value) }),
].filter(Boolean).join(' · '));
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
                :title="displayTitle"
                :subtitle="chartSubtitle"
                :series="chartData.series"
                :categories="chartData.categories"
                :height="chartHeight"
                :options="chartOptions"
                :color-tones="['blue']"
                :value-formatter="formatNumber"
                :category-label="t('products.usage.category_label')"
                :value-label="t('products.usage.value_label')"
                :table-caption="t('products.usage.table_caption')"
                :empty-message="t('products.usage.empty')"
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
