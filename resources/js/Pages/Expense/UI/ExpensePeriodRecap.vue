<script setup>
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import DatePicker from '@/Components/DatePicker.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import { humanizeDate } from '@/utils/date';
import { useCurrencyFormatter } from '@/utils/currency';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    periodRecap: {
        type: Object,
        required: true,
    },
    filters: {
        type: Object,
        default: () => ({}),
    },
    tenantCurrencyCode: {
        type: String,
        default: 'CAD',
    },
});

const { t, te } = useI18n();
const preferredCurrency = computed(() => props.tenantCurrencyCode);
const { formatCurrency } = useCurrencyFormatter(preferredCurrency);
const busy = ref(false);
const periodForm = ref({
    period: props.filters?.recap_period || props.periodRecap?.period?.key || 'month',
    from: props.filters?.recap_from || props.periodRecap?.period?.start || '',
    to: props.filters?.recap_to || props.periodRecap?.period?.end || '',
});

const periodOptions = computed(() => ([
    { value: 'week', label: t('expenses.recap.periods.week') },
    { value: 'month', label: t('expenses.recap.periods.month') },
    { value: 'quarter', label: t('expenses.recap.periods.quarter') },
    { value: 'year', label: t('expenses.recap.periods.year') },
    { value: 'custom', label: t('expenses.recap.periods.custom') },
]));

const kpis = computed(() => props.periodRecap?.kpis || {});
const breakdowns = computed(() => props.periodRecap?.breakdowns || {});
const period = computed(() => props.periodRecap?.period || {});
const periodLabel = computed(() => {
    if (!period.value?.start || !period.value?.end) {
        return '';
    }

    return `${humanizeDate(period.value.start)} - ${humanizeDate(period.value.end)}`;
});
const previousPeriodLabel = computed(() => {
    if (!period.value?.previous_start || !period.value?.previous_end) {
        return '';
    }

    return `${humanizeDate(period.value.previous_start)} - ${humanizeDate(period.value.previous_end)}`;
});
const hasCustomPeriod = computed(() => periodForm.value.period === 'custom');
const alerts = computed(() => Array.isArray(props.periodRecap?.alerts) ? props.periodRecap.alerts : []);
const linkedContexts = computed(() => (breakdowns.value.linked_contexts || []).filter((item) =>
    Number(item.count || 0) > 0 || Number(item.total || 0) > 0
));

const currentQuery = () => {
    if (typeof window === 'undefined') {
        return {};
    }

    return Object.fromEntries(new URLSearchParams(window.location.search).entries());
};
const cleanPayload = (payload) => {
    Object.keys(payload).forEach((key) => {
        const value = payload[key];
        if (value === '' || value === null || value === undefined) {
            delete payload[key];
        }
    });

    return payload;
};

const reloadRecap = (overrides, only = ['periodRecap', 'filters']) => {
    busy.value = true;
    router.get(route('expense.index'), cleanPayload({
        ...currentQuery(),
        ...overrides,
    }), {
        only,
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onFinish: () => {
            busy.value = false;
        },
    });
};

watch(() => props.filters, (filters) => {
    periodForm.value = {
        period: filters?.recap_period || props.periodRecap?.period?.key || 'month',
        from: filters?.recap_from || props.periodRecap?.period?.start || '',
        to: filters?.recap_to || props.periodRecap?.period?.end || '',
    };
});

watch(() => periodForm.value.period, (value) => {
    if (value !== 'custom') {
        reloadRecap({
            recap_period: value,
            recap_from: null,
            recap_to: null,
        });
    }
});

const applyCustomPeriod = () => {
    reloadRecap({
        recap_period: 'custom',
        recap_from: periodForm.value.from,
        recap_to: periodForm.value.to,
    });
};

const applyPeriodToList = () => {
    reloadRecap({
        expense_date_from: period.value?.start,
        expense_date_to: period.value?.end,
    }, ['expenses', 'filters', 'stats', 'count']);
};

const formatNumber = (value) =>
    Number(value || 0).toLocaleString(undefined, { maximumFractionDigits: 0 });

const formatPercent = (value) => {
    if (value === null || value === undefined) {
        return t('expenses.recap.delta_new');
    }

    const numeric = Number(value || 0);
    const prefix = numeric > 0 ? '+' : '';

    return `${prefix}${numeric.toLocaleString(undefined, { maximumFractionDigits: 1 })}%`;
};

const deltaClass = computed(() => {
    const delta = kpis.value.total_delta_percent;

    if (delta === null || delta === undefined || Number(delta) === 0) {
        return 'text-stone-500 dark:text-neutral-400';
    }

    return Number(delta) > 0
        ? 'text-rose-600 dark:text-rose-300'
        : 'text-emerald-600 dark:text-emerald-300';
});

const categoryLabel = (item) => {
    const key = item?.key;

    return key && te(`expenses.categories.${key}`)
        ? t(`expenses.categories.${key}`)
        : (item?.label || key || t('expenses.labels.uncategorized'));
};
const paymentMethodLabel = (item) => {
    const key = item?.key;

    return key && te(`expenses.payment_methods.${key}`)
        ? t(`expenses.payment_methods.${key}`)
        : (item?.label || key || t('expenses.labels.not_set'));
};
const linkedContextLabel = (item) => t(`expenses.recap.linked_contexts.${item.key}`);
const alertLabel = (alert) => t(`expenses.recap.alerts.${alert.key}`);

const moneyKpis = computed(() => ([
    {
        key: 'total_spent',
        label: t('expenses.recap.kpis.total_spent'),
        value: formatCurrency(kpis.value.total_spent),
        tone: 'border-t-red-600',
    },
    {
        key: 'approved_total',
        label: t('expenses.recap.kpis.approved_total'),
        value: formatCurrency(kpis.value.approved_total),
        tone: 'border-t-sky-600',
    },
    {
        key: 'paid_total',
        label: t('expenses.recap.kpis.paid_total'),
        value: formatCurrency(kpis.value.paid_total),
        tone: 'border-t-emerald-600',
    },
    {
        key: 'to_pay_total',
        label: t('expenses.recap.kpis.to_pay_total'),
        value: formatCurrency(kpis.value.to_pay_total),
        tone: 'border-t-amber-500',
    },
    {
        key: 'reimbursement_total',
        label: t('expenses.recap.kpis.reimbursement_total'),
        value: formatCurrency(kpis.value.reimbursement_total),
        tone: 'border-t-orange-500',
    },
    {
        key: 'pending_approval_count',
        label: t('expenses.recap.kpis.pending_approval_count'),
        value: formatNumber(kpis.value.pending_approval_count),
        tone: 'border-t-stone-500',
    },
]));

const breakdownCards = computed(() => ([
    {
        key: 'categories',
        title: t('expenses.recap.breakdowns.categories'),
        rows: breakdowns.value.categories || [],
        label: categoryLabel,
    },
    {
        key: 'suppliers',
        title: t('expenses.recap.breakdowns.suppliers'),
        rows: breakdowns.value.suppliers || [],
        label: (item) => item.name || t('expenses.labels.no_supplier'),
    },
    {
        key: 'payment_methods',
        title: t('expenses.recap.breakdowns.payment_methods'),
        rows: breakdowns.value.payment_methods || [],
        label: paymentMethodLabel,
    },
    {
        key: 'team_members',
        title: t('expenses.recap.breakdowns.team_members'),
        rows: breakdowns.value.team_members || [],
        label: (item) => item.label || t('expenses.labels.no_team_member'),
    },
]));
</script>

<template>
    <section class="rounded-sm border border-stone-200 border-t-4 border-t-red-600 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
        <div class="grid gap-4 xl:grid-cols-[1fr,420px]">
            <div>
                <div class="text-xs font-semibold uppercase tracking-[0.18em] text-red-600 dark:text-red-400">
                    {{ $t('expenses.recap.eyebrow') }}
                </div>
                <h2 class="mt-1 text-lg font-semibold text-stone-900 dark:text-neutral-100">
                    {{ $t('expenses.recap.title') }}
                </h2>
                <p class="mt-1 max-w-3xl text-sm text-stone-500 dark:text-neutral-400">
                    {{ $t('expenses.recap.description') }}
                </p>
                <div class="mt-3 flex flex-wrap items-center gap-2 text-xs text-stone-500 dark:text-neutral-400">
                    <span class="rounded-full border border-stone-200 px-2.5 py-1 dark:border-neutral-700">
                        {{ periodLabel }}
                    </span>
                    <span v-if="previousPeriodLabel">
                        {{ $t('expenses.recap.compared_to', { period: previousPeriodLabel }) }}
                    </span>
                    <span :class="deltaClass" class="font-semibold">
                        {{ formatPercent(kpis.total_delta_percent) }}
                    </span>
                </div>
            </div>

            <div class="grid gap-2 sm:grid-cols-[1fr,auto] xl:grid-cols-1">
                <FloatingSelect
                    v-model="periodForm.period"
                    :label="$t('expenses.recap.period')"
                    :options="periodOptions"
                    :disabled="busy"
                />
                <button
                    type="button"
                    class="inline-flex items-center justify-center rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-semibold uppercase tracking-wide text-stone-700 hover:bg-stone-50 disabled:opacity-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-700"
                    :disabled="busy"
                    @click="applyPeriodToList"
                >
                    {{ $t('expenses.recap.actions.filter_list') }}
                </button>
            </div>
        </div>

        <div v-if="hasCustomPeriod" class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-[1fr,1fr,auto]">
            <DatePicker v-model="periodForm.from" :label="$t('expenses.recap.from')" />
            <DatePicker v-model="periodForm.to" :label="$t('expenses.recap.to')" />
            <button
                type="button"
                class="inline-flex items-center justify-center rounded-sm border border-transparent bg-red-600 px-4 py-2 text-xs font-semibold uppercase tracking-wide text-white hover:bg-red-700 disabled:opacity-50"
                :disabled="busy"
                @click="applyCustomPeriod"
            >
                {{ $t('expenses.recap.actions.apply') }}
            </button>
        </div>

        <div class="mt-5 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-6">
            <div
                v-for="item in moneyKpis"
                :key="item.key"
                class="rounded-sm border border-t-4 border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900"
                :class="item.tone"
            >
                <div class="text-xs font-medium uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                    {{ item.label }}
                </div>
                <div class="mt-2 text-lg font-semibold text-stone-900 dark:text-neutral-100">
                    {{ item.value }}
                </div>
            </div>
        </div>

        <div class="mt-4 grid gap-4 xl:grid-cols-[1fr,320px]">
            <div class="grid gap-4 lg:grid-cols-2">
                <div
                    v-for="card in breakdownCards"
                    :key="card.key"
                    class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900"
                >
                    <h3 class="text-sm font-semibold text-stone-900 dark:text-neutral-100">
                        {{ card.title }}
                    </h3>
                    <div v-if="card.rows.length" class="mt-3 space-y-3">
                        <div v-for="row in card.rows" :key="`${card.key}-${row.key || row.id || row.name || row.label}`">
                            <div class="flex items-start justify-between gap-3 text-sm">
                                <div>
                                    <div class="font-medium text-stone-800 dark:text-neutral-100">
                                        {{ card.label(row) }}
                                    </div>
                                    <div class="text-xs text-stone-500 dark:text-neutral-500">
                                        {{ $t('expenses.recap.row_count', { count: formatNumber(row.count) }) }}
                                    </div>
                                </div>
                                <div class="text-right font-medium text-stone-700 dark:text-neutral-200">
                                    {{ formatCurrency(row.total) }}
                                    <div class="text-xs font-normal text-stone-500 dark:text-neutral-500">
                                        {{ row.share }}%
                                    </div>
                                </div>
                            </div>
                            <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-stone-100 dark:bg-neutral-800">
                                <div
                                    class="h-full rounded-full bg-red-600"
                                    :style="{ width: `${Math.min(100, Number(row.share || 0))}%` }"
                                ></div>
                            </div>
                        </div>
                    </div>
                    <p v-else class="mt-3 text-sm text-stone-500 dark:text-neutral-500">
                        {{ $t('expenses.stats.no_breakdown') }}
                    </p>
                </div>
            </div>

            <div class="space-y-4">
                <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <h3 class="text-sm font-semibold text-stone-900 dark:text-neutral-100">
                        {{ $t('expenses.recap.breakdowns.linked_contexts') }}
                    </h3>
                    <div v-if="linkedContexts.length" class="mt-3 space-y-3">
                        <div
                            v-for="item in linkedContexts"
                            :key="item.key"
                            class="flex items-center justify-between gap-3 text-sm"
                        >
                            <div>
                                <div class="font-medium text-stone-800 dark:text-neutral-100">
                                    {{ linkedContextLabel(item) }}
                                </div>
                                <div class="text-xs text-stone-500 dark:text-neutral-500">
                                    {{ $t('expenses.recap.row_count', { count: formatNumber(item.count) }) }}
                                </div>
                            </div>
                            <div class="font-medium text-stone-700 dark:text-neutral-200">
                                {{ formatCurrency(item.total) }}
                            </div>
                        </div>
                    </div>
                    <p v-else class="mt-3 text-sm text-stone-500 dark:text-neutral-500">
                        {{ $t('expenses.stats.no_breakdown') }}
                    </p>
                </div>

                <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <h3 class="text-sm font-semibold text-stone-900 dark:text-neutral-100">
                        {{ $t('expenses.recap.alerts_title') }}
                    </h3>
                    <div v-if="alerts.length" class="mt-3 space-y-2">
                        <div
                            v-for="alert in alerts"
                            :key="alert.key"
                            class="rounded-sm border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200"
                        >
                            <div class="font-medium">
                                {{ alertLabel(alert) }}
                            </div>
                            <div class="mt-0.5 text-xs">
                                <template v-if="alert.total !== null && alert.total !== undefined">
                                    {{ formatCurrency(alert.total) }}
                                </template>
                                <template v-else>
                                    {{ $t('expenses.recap.row_count', { count: formatNumber(alert.count) }) }}
                                </template>
                            </div>
                        </div>
                    </div>
                    <p v-else class="mt-3 text-sm text-stone-500 dark:text-neutral-500">
                        {{ $t('expenses.recap.no_alerts') }}
                    </p>
                </div>
            </div>
        </div>
    </section>
</template>
