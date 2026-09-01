<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import KpiMetricGrid from '@/Components/Dashboard/KpiMetricGrid.vue';
import Barchart from '@/Components/UI/Barchart.vue';
import { useCurrencyFormatter } from '@/utils/currency';
import { buildScenarioActivityChartData } from '@/utils/scenarioActivityChart';

const props = defineProps({
    insights: {
        type: Object,
        required: true,
    },
});

const { t, locale } = useI18n();
const { formatCurrency } = useCurrencyFormatter();
const metrics = computed(() => props.insights?.metrics || {});
const isFieldOperations = computed(() => props.insights?.operating_model === 'field_operations');
const monthly = computed(() => props.insights?.monthly || {
    labels: [],
    revenue: [],
    expenses: [],
    reservations: [],
});
const number = (value) => Number(value || 0).toLocaleString(locale.value);
const percent = (value) => `${Number(value || 0).toLocaleString(locale.value, {
    maximumFractionDigits: 1,
})}%`;
const monthLabel = (value) => {
    const [year, month] = String(value || '').split('-').map(Number);
    if (!year || !month) {
        return value;
    }

    return new Intl.DateTimeFormat(locale.value, { month: 'short' }).format(new Date(year, month - 1, 1));
};
const dateTime = (value) => value
    ? new Intl.DateTimeFormat(locale.value, {
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(value))
    : '—';
const activityChartData = computed(() => buildScenarioActivityChartData(monthly.value, {
    labelForPeriod: monthLabel,
    seriesLabel: t(isFieldOperations.value
        ? 'dashboard.scenario.field_operations.activity_series'
        : 'dashboard.scenario.activity_series'),
}));
const activitySeries = computed(() => activityChartData.value.series);
const activityCategories = computed(() => activityChartData.value.categories);
const activityChartOptions = computed(() => ({
    legend: {
        show: false,
    },
    xaxis: {
        tickAmount: Math.min(6, activityCategories.value.length),
        labels: {
            rotate: 0,
            hideOverlappingLabels: true,
            trim: true,
        },
    },
    yaxis: {
        min: 0,
        forceNiceScale: true,
        labels: {
            formatter: (value) => number(Math.round(Number(value || 0))),
        },
    },
}));
const revenueChange = computed(() => metrics.value.revenue_change_percent);
const revenueTone = computed(() => Number(revenueChange.value || 0) >= 0 ? 'emerald' : 'rose');
const revenueColorClass = computed(() => revenueTone.value === 'emerald'
    ? 'bg-emerald-500/70 dark:bg-emerald-400/50'
    : 'bg-rose-500/70 dark:bg-rose-400/50');
const cards = computed(() => [
    {
        key: 'revenue',
        label: t('dashboard.scenario.metrics.revenue_month'),
        value: formatCurrency(metrics.value.revenue_current_month || 0),
        context: revenueChange.value === null || revenueChange.value === undefined
            ? t('dashboard.scenario.metrics.no_comparison')
            : t('dashboard.scenario.metrics.vs_previous', { value: percent(revenueChange.value) }),
        tone: revenueTone.value,
        colorClass: revenueColorClass.value,
        trend: null,
        points: [],
    },
    {
        key: 'today',
        label: t(isFieldOperations.value
            ? 'dashboard.scenario.field_operations.jobs_today'
            : 'dashboard.scenario.metrics.reservations_today'),
        value: number(metrics.value.reservations_today),
        context: t('dashboard.scenario.metrics.upcoming', { count: number(metrics.value.reservations_upcoming) }),
        tone: 'sky',
        colorClass: 'bg-sky-500/70 dark:bg-sky-400/50',
        trend: null,
        points: [],
    },
    {
        key: 'occupancy',
        label: t(isFieldOperations.value
            ? 'dashboard.scenario.field_operations.capacity_usage'
            : 'dashboard.scenario.metrics.occupancy'),
        value: percent(metrics.value.occupancy_rate),
        context: t('dashboard.scenario.metrics.trailing_days', { count: 30 }),
        tone: 'violet',
        colorClass: 'bg-violet-500/70 dark:bg-violet-400/50',
        trend: null,
        points: [],
    },
    {
        key: 'customers',
        label: t('dashboard.scenario.metrics.new_customers'),
        value: number(metrics.value.customers_new),
        context: t('dashboard.scenario.metrics.recurring', { count: number(metrics.value.customers_recurring) }),
        tone: 'indigo',
        colorClass: 'bg-indigo-500/70 dark:bg-indigo-400/50',
        trend: null,
        points: [],
    },
    {
        key: 'ticket',
        label: t(isFieldOperations.value
            ? 'dashboard.scenario.field_operations.average_job_value'
            : 'dashboard.scenario.metrics.average_ticket'),
        value: formatCurrency(metrics.value.average_service_value || 0),
        context: t(isFieldOperations.value
            ? 'dashboard.scenario.field_operations.completed_jobs'
            : 'dashboard.scenario.metrics.completed_services'),
        tone: 'amber',
        colorClass: 'bg-amber-500/70 dark:bg-amber-400/50',
        trend: null,
        points: [],
    },
    {
        key: 'outstanding',
        label: t('dashboard.scenario.metrics.outstanding'),
        value: formatCurrency(metrics.value.outstanding_balance || 0),
        context: t('dashboard.scenario.metrics.invoice_and_future', {
            count: number(metrics.value.outstanding_invoices),
            future: formatCurrency(metrics.value.committed_future_revenue || 0),
        }),
        tone: 'rose',
        colorClass: 'bg-rose-500/70 dark:bg-rose-400/50',
        trend: null,
        points: [],
    },
    {
        key: 'exceptions',
        label: t(isFieldOperations.value
            ? 'dashboard.scenario.field_operations.quality_incidents'
            : 'dashboard.scenario.metrics.service_exceptions'),
        value: percent(metrics.value.no_show_rate),
        context: t('dashboard.scenario.metrics.cancellations', { value: percent(metrics.value.cancellation_rate) }),
        tone: 'orange',
        colorClass: 'bg-orange-500/70 dark:bg-orange-400/50',
        trend: null,
        points: [],
    },
    {
        key: 'alerts',
        label: t('dashboard.scenario.metrics.actions'),
        value: number(
            Number(metrics.value.pending_quotes || 0)
            + Number(metrics.value.open_tasks || 0)
            + Number(metrics.value.inventory_alerts || 0)
            + Number(metrics.value.unread_notifications || 0),
        ),
        context: t('dashboard.scenario.metrics.action_breakdown', {
            quotes: number(metrics.value.pending_quotes),
            tasks: number(metrics.value.open_tasks),
            stock: number(metrics.value.inventory_alerts),
            notifications: number(metrics.value.unread_notifications),
        }),
        tone: 'red',
        colorClass: 'bg-red-500/70 dark:bg-red-400/50',
        trend: null,
        points: [],
    },
]);
</script>

<template>
    <section
        class="rounded-sm border border-violet-200 bg-white p-4 shadow-sm dark:border-violet-500/30 dark:bg-neutral-900"
        data-testid="scenario-business-overview"
    >
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <div class="text-[10px] font-semibold uppercase tracking-[0.18em] text-violet-600 dark:text-violet-300">
                    {{ $t('dashboard.scenario.eyebrow') }}
                </div>
                <h2 class="mt-1 text-base font-semibold text-stone-800 dark:text-neutral-100">
                    {{ $t('dashboard.scenario.title') }}
                </h2>
                <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                    {{ $t('dashboard.scenario.subtitle', {
                        date: insights.reference_date,
                        months: insights.range_months || 12,
                    }) }}
                </p>
            </div>
            <div class="rounded-full border border-violet-200 bg-violet-50 px-3 py-1 text-[11px] font-medium text-violet-700 dark:border-violet-500/30 dark:bg-violet-500/10 dark:text-violet-200">
                {{ insights.scenario_key }}
            </div>
        </div>

        <KpiMetricGrid
            class="mt-4"
            variant="dashboard"
            :metrics="cards"
            grid-class="grid-cols-[repeat(auto-fit,minmax(min(100%,12rem),1fr))]"
            :aria-label="$t('dashboard.scenario.title')"
            compact
        />

        <div class="mt-4 grid grid-cols-1 gap-3 xl:grid-cols-[minmax(0,1.45fr)_minmax(0,1fr)]">
            <div class="rounded-sm border border-stone-200 p-3 dark:border-neutral-700">
                <Barchart
                    :title="$t('dashboard.scenario.history_title')"
                    :subtitle="$t(isFieldOperations
                        ? 'dashboard.scenario.field_operations.history_subtitle'
                        : 'dashboard.scenario.history_subtitle')"
                    :series="activitySeries"
                    :categories="activityCategories"
                    :height="180"
                    :options="activityChartOptions"
                    :color-tones="['blue']"
                    :category-label="$t('dashboard.scenario.month_label')"
                    :value-label="$t('dashboard.scenario.activity_count_label')"
                    :table-caption="$t('dashboard.scenario.activity_table_caption')"
                    :value-formatter="number"
                    :framed="false"
                    data-testid="scenario-monthly-activity-chart"
                />
            </div>

            <div class="grid grid-cols-1 gap-2 sm:grid-cols-3 xl:grid-cols-1">
                <div class="rounded-sm border border-stone-200 p-3 dark:border-neutral-700">
                    <div class="text-[10px] font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">{{ $t('dashboard.scenario.top_services') }}</div>
                    <div class="mt-2 space-y-1.5">
                        <div v-for="item in insights.top_services?.slice(0, 3)" :key="item.name" class="flex items-center justify-between gap-2 text-xs">
                            <span class="truncate text-stone-700 dark:text-neutral-200">{{ item.name }}</span>
                            <span class="shrink-0 font-semibold text-stone-500 dark:text-neutral-400">{{ number(item.count) }}</span>
                        </div>
                    </div>
                </div>
                <div class="rounded-sm border border-stone-200 p-3 dark:border-neutral-700">
                    <div class="text-[10px] font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                        {{ $t(isFieldOperations ? 'dashboard.scenario.field_operations.top_employees' : 'dashboard.scenario.top_employees') }}
                    </div>
                    <div class="mt-2 space-y-1.5">
                        <div v-for="item in insights.top_employees?.slice(0, 3)" :key="item.name" class="flex items-center justify-between gap-2 text-xs">
                            <span class="truncate text-stone-700 dark:text-neutral-200">{{ item.name }}</span>
                            <span class="shrink-0 font-semibold text-stone-500 dark:text-neutral-400">{{ number(item.activity_count ?? item.reservations) }}</span>
                        </div>
                    </div>
                </div>
                <div class="rounded-sm border border-stone-200 p-3 dark:border-neutral-700">
                    <div class="text-[10px] font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                        {{ $t(isFieldOperations ? 'dashboard.scenario.field_operations.top_products' : 'dashboard.scenario.top_products') }}
                    </div>
                    <div class="mt-2 space-y-1.5">
                        <div v-for="item in insights.top_products?.slice(0, 3)" :key="item.name" class="flex items-center justify-between gap-2 text-xs">
                            <span class="truncate text-stone-700 dark:text-neutral-200">{{ item.name }}</span>
                            <span class="shrink-0 font-semibold text-stone-500 dark:text-neutral-400">{{ number(item.quantity) }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-3 rounded-sm border border-stone-200 p-3 dark:border-neutral-700">
            <div class="text-[10px] font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                {{ $t('dashboard.scenario.recent_payments') }}
            </div>
            <div v-if="insights.recent_payments?.length" class="mt-2 grid gap-2 sm:grid-cols-2 xl:grid-cols-5">
                <div
                    v-for="payment in insights.recent_payments"
                    :key="payment.id"
                    class="flex items-center justify-between gap-3 rounded-sm bg-stone-50 px-2.5 py-2 text-xs dark:bg-neutral-800"
                >
                    <div class="min-w-0">
                        <div class="truncate font-medium text-stone-700 dark:text-neutral-200">{{ payment.method }}</div>
                        <div class="truncate text-[10px] text-stone-400 dark:text-neutral-500">{{ dateTime(payment.paid_at) }}</div>
                    </div>
                    <div class="shrink-0 font-semibold text-emerald-700 dark:text-emerald-300">
                        {{ formatCurrency(payment.amount || 0, payment.currency_code) }}
                    </div>
                </div>
            </div>
            <div v-else class="mt-2 text-xs text-stone-500 dark:text-neutral-400">
                {{ $t('dashboard.scenario.no_recent_payments') }}
            </div>
        </div>
    </section>
</template>
