<script setup>
import { computed, defineAsyncComponent } from 'vue';
import { useI18n } from 'vue-i18n';
import { useCurrencyFormatter } from '@/utils/currency';
import { buildQuoteValueChartData } from '@/utils/quoteValueChart';

const Barchart = defineAsyncComponent(() => import('@/Components/UI/Barchart.vue'));

const props = defineProps({
    items: {
        type: Array,
        default: () => [],
    },
    filteredCount: {
        type: Number,
        default: 0,
    },
    filteredTotal: {
        type: Number,
        default: 0,
    },
    filteredCurrencyCodes: {
        type: Array,
        default: () => [],
    },
    currencyCode: {
        type: String,
        default: 'CAD',
    },
    title: {
        type: String,
        default: null,
    },
});

const { t } = useI18n();
const preferredCurrency = computed(() => props.currencyCode);
const { formatCurrency } = useCurrencyFormatter(preferredCurrency);
const resolvedTitle = computed(() => props.title || t('quotes.stats.top_by_value'));
const displayCustomer = (item) => item?.customer?.company_name
    || `${item?.customer?.first_name || ''} ${item?.customer?.last_name || ''}`.trim()
    || t('quotes.labels.unknown_customer');
const displayQuote = (item) => `${item?.number || t('quotes.labels.quote_fallback')} — ${displayCustomer(item)}`;
const chartData = computed(() => buildQuoteValueChartData(props.items, {
    filteredCount: props.filteredCount,
    filteredTotal: props.filteredTotal,
    filteredCurrencyCodes: props.filteredCurrencyCodes,
    currencyCode: props.currencyCode,
    labelForItem: displayQuote,
    seriesLabel: t('quotes.stats.value_series'),
}));
const chartError = computed(() => chartData.value.isValid
    ? false
    : t('quotes.stats.chart_invalid'));
const chartHeight = computed(() => Math.min(
    430,
    Math.max(260, (chartData.value.categories.length * 54) + 100),
));
const formatExactCurrency = (value) => Number.isFinite(Number(value))
    ? formatCurrency(Number(value), props.currencyCode)
    : '—';
const formatCompactCurrency = (value) => Number.isFinite(Number(value))
    ? formatCurrency(Number(value), props.currencyCode, {
        notation: 'compact',
        minimumFractionDigits: 0,
        maximumFractionDigits: 1,
    })
    : '—';
const chartOptions = computed(() => ({
    legend: {
        show: false,
    },
    xaxis: {
        min: 0,
        forceNiceScale: true,
        tickAmount: 4,
        labels: {
            formatter: formatCompactCurrency,
        },
    },
    plotOptions: {
        bar: {
            horizontal: true,
            barHeight: '56%',
        },
    },
}));
</script>

<template>
    <section class="size-full rounded-sm border border-stone-200 border-t-4 border-t-sky-700 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
        <Suspense>
            <Barchart
                :title="resolvedTitle"
                :subtitle="$t('quotes.stats.top_chart_subtitle', {
                    displayed: chartData.displayedCount,
                    count: filteredCount,
                    currency: currencyCode,
                })"
                :series="chartData.series"
                :categories="chartData.categories"
                :height="chartHeight"
                :options="chartOptions"
                :color-tones="['blue']"
                :value-formatter="formatExactCurrency"
                :category-label="$t('quotes.stats.quote_label')"
                :value-label="$t('quotes.stats.value_label')"
                :unit-label="currencyCode"
                :table-caption="$t('quotes.stats.top_chart_table_caption')"
                :empty-message="$t('quotes.stats.empty')"
                :error="chartError"
                :error-message="$t('quotes.stats.chart_invalid')"
                :framed="false"
                horizontal
                data-testid="quote-value-chart"
            />
            <template #fallback>
                <div
                    class="flex min-h-80 items-center justify-center rounded-sm bg-stone-50 dark:bg-neutral-900"
                    role="status"
                    aria-live="polite"
                >
                    <span class="sr-only">{{ $t('charts.loading') }}</span>
                    <span class="h-64 w-full rounded-sm bg-stone-100 motion-safe:animate-pulse dark:bg-neutral-800" aria-hidden="true"></span>
                </div>
            </template>
        </Suspense>
    </section>
</template>
