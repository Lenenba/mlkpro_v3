<script setup>
import { computed, defineAsyncComponent } from 'vue';
import { useI18n } from 'vue-i18n';
import KpiMetricGrid from '@/Components/Dashboard/KpiMetricGrid.vue';
import { useCurrencyFormatter } from '@/utils/currency';
import { buildKpiProgress } from '@/utils/kpi';
import { buildProductStockPartition } from '@/utils/productStockChart';

const Donutchart = defineAsyncComponent(() => import('@/Components/UI/Donutchart.vue'));

const props = defineProps({
    stats: {
        type: Object,
        required: true,
    },
});

const formatNumber = (value) =>
    Number(value || 0).toLocaleString(undefined, { maximumFractionDigits: 0 });

const { formatCurrency } = useCurrencyFormatter();
const { t } = useI18n();
const stockChartTones = ['emerald', 'amber', 'rose'];

const formatRatio = (value) =>
    Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const stockPartition = computed(() => buildProductStockPartition(props.stats));
const stockCategories = computed(() => stockPartition.value.keys.map((key) => (
    t(`products.stock_status.${key}`)
)));
const hasStockChart = computed(() => stockPartition.value.isValid && stockPartition.value.total > 0);

const metrics = computed(() => [
    {
        key: 'total',
        label: t('products.stats.total'),
        value: formatNumber(props.stats.total),
        tone: 'emerald',
    },
    {
        key: 'inventory-value',
        label: t('products.stats.inventory_value'),
        value: formatCurrency(props.stats.inventory_value),
        tone: 'sky',
    },
    {
        key: 'in-stock',
        label: t('products.stats.in_stock'),
        value: formatNumber(props.stats.in_stock),
        tone: 'blue',
        progress: buildKpiProgress(props.stats.in_stock, props.stats.total),
    },
    {
        key: 'low-stock',
        label: t('products.stats.low_stock'),
        value: formatNumber(props.stats.low_stock),
        tone: 'amber',
        progress: buildKpiProgress(props.stats.low_stock, props.stats.total),
    },
    {
        key: 'out-of-stock',
        label: t('products.stats.out_of_stock'),
        value: formatNumber(props.stats.out_of_stock),
        tone: 'red',
        progress: buildKpiProgress(props.stats.out_of_stock, props.stats.total),
    },
    {
        key: 'rotation',
        label: t('products.stats.rotation'),
        value: `${formatRatio(props.stats.rotation)}x`,
        tone: 'indigo',
    },
]);
</script>

<template>
    <div class="space-y-3 md:space-y-4">
        <KpiMetricGrid :metrics="metrics" />

        <div v-if="hasStockChart" class="min-w-0">
            <Suspense>
                <Donutchart
                    :series="stockPartition.values"
                    :categories="stockCategories"
                    :color-tones="stockChartTones"
                    :title="$t('products.stock_chart.title')"
                    :subtitle="$t('products.stock_chart.subtitle')"
                    :total-label="$t('products.stock_chart.total_label')"
                    :category-label="$t('products.stock_chart.category_label')"
                    :value-label="$t('products.stock_chart.value_label')"
                    :table-caption="$t('products.stock_chart.table_caption')"
                    :value-formatter="formatNumber"
                />

                <template #fallback>
                    <div
                        class="flex min-h-64 items-center rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900"
                        role="status"
                    >
                        <span class="sr-only">{{ $t('charts.loading') }}</span>
                        <div class="h-48 w-full motion-safe:animate-pulse rounded-sm bg-stone-100 dark:bg-neutral-800" aria-hidden="true"></div>
                    </div>
                </template>
            </Suspense>
        </div>
    </div>
</template>
