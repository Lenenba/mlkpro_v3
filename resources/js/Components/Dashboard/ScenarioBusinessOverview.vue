<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { useCurrencyFormatter } from '@/utils/currency';

const props = defineProps({
    insights: {
        type: Object,
        required: true,
    },
});

const { t, locale } = useI18n();
const { formatCurrency } = useCurrencyFormatter();
const metrics = computed(() => props.insights?.metrics || {});
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
const chartMaximum = computed(() => Math.max(
    1,
    ...(monthly.value.revenue || []).map(Number),
    ...(monthly.value.expenses || []).map(Number),
));
const barHeight = (value) => `${Math.max(3, (Number(value || 0) / chartMaximum.value) * 100)}%`;
const revenueChange = computed(() => metrics.value.revenue_change_percent);
const revenueChangeClass = computed(() => (
    Number(revenueChange.value || 0) >= 0
        ? 'text-emerald-700 dark:text-emerald-300'
        : 'text-rose-700 dark:text-rose-300'
));
const cards = computed(() => [
    {
        key: 'revenue',
        label: t('dashboard.scenario.metrics.revenue_month'),
        value: formatCurrency(metrics.value.revenue_current_month || 0),
        detail: revenueChange.value === null || revenueChange.value === undefined
            ? t('dashboard.scenario.metrics.no_comparison')
            : t('dashboard.scenario.metrics.vs_previous', { value: percent(revenueChange.value) }),
        detailClass: revenueChangeClass.value,
    },
    {
        key: 'today',
        label: t('dashboard.scenario.metrics.reservations_today'),
        value: number(metrics.value.reservations_today),
        detail: t('dashboard.scenario.metrics.upcoming', { count: number(metrics.value.reservations_upcoming) }),
    },
    {
        key: 'occupancy',
        label: t('dashboard.scenario.metrics.occupancy'),
        value: percent(metrics.value.occupancy_rate),
        detail: t('dashboard.scenario.metrics.trailing_days', { count: 30 }),
    },
    {
        key: 'customers',
        label: t('dashboard.scenario.metrics.new_customers'),
        value: number(metrics.value.customers_new),
        detail: t('dashboard.scenario.metrics.recurring', { count: number(metrics.value.customers_recurring) }),
    },
    {
        key: 'ticket',
        label: t('dashboard.scenario.metrics.average_ticket'),
        value: formatCurrency(metrics.value.average_service_value || 0),
        detail: t('dashboard.scenario.metrics.completed_services'),
    },
    {
        key: 'outstanding',
        label: t('dashboard.scenario.metrics.outstanding'),
        value: formatCurrency(metrics.value.outstanding_balance || 0),
        detail: t('dashboard.scenario.metrics.invoice_and_future', {
            count: number(metrics.value.outstanding_invoices),
            future: formatCurrency(metrics.value.committed_future_revenue || 0),
        }),
    },
    {
        key: 'exceptions',
        label: t('dashboard.scenario.metrics.service_exceptions'),
        value: percent(metrics.value.no_show_rate),
        detail: t('dashboard.scenario.metrics.cancellations', { value: percent(metrics.value.cancellation_rate) }),
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
        detail: t('dashboard.scenario.metrics.action_breakdown', {
            quotes: number(metrics.value.pending_quotes),
            tasks: number(metrics.value.open_tasks),
            stock: number(metrics.value.inventory_alerts),
            notifications: number(metrics.value.unread_notifications),
        }),
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

        <div class="mt-4 grid grid-cols-2 gap-2 md:grid-cols-4 xl:grid-cols-8">
            <div
                v-for="card in cards"
                :key="card.key"
                class="min-w-0 rounded-sm border border-stone-200 bg-stone-50 p-2.5 dark:border-neutral-700 dark:bg-neutral-800"
            >
                <div class="text-[10px] font-semibold uppercase leading-tight tracking-wide text-stone-500 dark:text-neutral-400">
                    {{ card.label }}
                </div>
                <div class="mt-2 text-lg font-semibold leading-none text-stone-800 dark:text-neutral-100">
                    {{ card.value }}
                </div>
                <div class="mt-2 line-clamp-2 text-[10px] leading-tight text-stone-500 dark:text-neutral-400" :class="card.detailClass">
                    {{ card.detail }}
                </div>
            </div>
        </div>

        <div class="mt-4 grid grid-cols-1 gap-3 xl:grid-cols-[minmax(0,1.45fr)_minmax(0,1fr)]">
            <div class="rounded-sm border border-stone-200 p-3 dark:border-neutral-700">
                <div class="flex items-center justify-between gap-2">
                    <div class="text-xs font-semibold text-stone-700 dark:text-neutral-200">
                        {{ $t('dashboard.scenario.history_title') }}
                    </div>
                    <div class="flex items-center gap-3 text-[10px] text-stone-500 dark:text-neutral-400">
                        <span class="inline-flex items-center gap-1"><span class="size-2 rounded-sm bg-emerald-500" />{{ $t('dashboard.scenario.revenue') }}</span>
                        <span class="inline-flex items-center gap-1"><span class="size-2 rounded-sm bg-rose-400" />{{ $t('dashboard.scenario.expenses') }}</span>
                    </div>
                </div>
                <div class="mt-3 grid h-36 grid-cols-12 items-end gap-1.5" data-testid="scenario-twelve-month-chart">
                    <div
                        v-for="(label, index) in monthly.labels"
                        :key="label"
                        class="flex h-full min-w-0 flex-col justify-end"
                        :title="`${label} · ${formatCurrency(monthly.revenue[index] || 0)} / ${formatCurrency(monthly.expenses[index] || 0)}`"
                    >
                        <div class="flex min-h-0 flex-1 items-end justify-center gap-px">
                            <span class="w-1/2 rounded-t-sm bg-emerald-500/80" :style="{ height: barHeight(monthly.revenue[index]) }" />
                            <span class="w-1/2 rounded-t-sm bg-rose-400/75" :style="{ height: barHeight(monthly.expenses[index]) }" />
                        </div>
                        <div class="mt-1 truncate text-center text-[9px] text-stone-400 dark:text-neutral-500">
                            {{ monthLabel(label) }}
                        </div>
                    </div>
                </div>
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
                    <div class="text-[10px] font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">{{ $t('dashboard.scenario.top_employees') }}</div>
                    <div class="mt-2 space-y-1.5">
                        <div v-for="item in insights.top_employees?.slice(0, 3)" :key="item.name" class="flex items-center justify-between gap-2 text-xs">
                            <span class="truncate text-stone-700 dark:text-neutral-200">{{ item.name }}</span>
                            <span class="shrink-0 font-semibold text-stone-500 dark:text-neutral-400">{{ number(item.reservations) }}</span>
                        </div>
                    </div>
                </div>
                <div class="rounded-sm border border-stone-200 p-3 dark:border-neutral-700">
                    <div class="text-[10px] font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">{{ $t('dashboard.scenario.top_products') }}</div>
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
