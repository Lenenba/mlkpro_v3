<script setup>
import { computed, reactive } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AdminPaginationLinks from '@/Components/DataTable/AdminPaginationLinks.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    status: {
        type: Object,
        default: () => ({}),
    },
    snapshot: {
        type: Object,
        default: () => ({}),
    },
    abilities: {
        type: Object,
        default: () => ({}),
    },
    source_counts: {
        type: Object,
        default: () => ({}),
    },
    system_accounts: {
        type: Array,
        default: () => [],
    },
    mapping_conventions: {
        type: Array,
        default: () => [],
    },
    journal: {
        type: Object,
        default: () => ({
            data: [],
            links: [],
            meta: {},
        }),
    },
    journal_summary: {
        type: Object,
        default: () => ({}),
    },
    tax_summary: {
        type: Object,
        default: () => ({}),
    },
    handoff_summary: {
        type: Object,
        default: () => ({}),
    },
    export_history: {
        type: Array,
        default: () => [],
    },
    periods: {
        type: Array,
        default: () => [],
    },
    period_summary: {
        type: Object,
        default: () => ({}),
    },
    review_workspace: {
        type: Object,
        default: () => ({}),
    },
    mobile_summary: {
        type: Object,
        default: () => ({}),
    },
    mobile_alerts: {
        type: Array,
        default: () => [],
    },
    filters: {
        type: Object,
        default: () => ({}),
    },
    filter_options: {
        type: Object,
        default: () => ({
            accounts: [],
            source_types: [],
            review_statuses: [],
        }),
    },
    sync_summary: {
        type: Object,
        default: () => ({}),
    },
    next_steps: {
        type: Array,
        default: () => [],
    },
});

const { t, locale } = useI18n();

const filterState = reactive({
    period: props.filters?.period ?? '',
    source_type: props.filters?.source_type ?? '',
    account_id: props.filters?.account_id ?? '',
    review_status: props.filters?.review_status ?? '',
    search: props.filters?.search ?? '',
});

const readinessTone = (enabled) => enabled
    ? 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200'
    : 'border-stone-200 bg-stone-50 text-stone-700 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300';

const sourceCards = computed(() => ([
    { key: 'expenses', value: props.source_counts?.expenses ?? 0 },
    { key: 'invoices', value: props.source_counts?.invoices ?? 0 },
    { key: 'payments', value: props.source_counts?.payments ?? 0 },
    { key: 'sales', value: props.source_counts?.sales ?? 0 },
]));

const financeApprovalModeLabel = computed(() => (
    props.snapshot?.finance_approvals_enabled
        ? t('accounting.snapshot.finance_approvals_enabled')
        : t('accounting.snapshot.finance_approvals_not_configured')
));

const statusStateLabel = computed(() => (
    props.status?.state
        ? t(`accounting.state.${props.status.state}`, props.status.state)
        : t('accounting.state.mobile_supervision_ready')
));

const mobileSummaryCards = computed(() => ([
    {
        key: 'cash_in',
        label: t('accounting.mobile.cards.cash_in'),
        value: formatMoney(props.mobile_summary?.cash_in ?? 0),
    },
    {
        key: 'cash_out',
        label: t('accounting.mobile.cards.cash_out'),
        value: formatMoney(props.mobile_summary?.cash_out ?? 0),
    },
    {
        key: 'net_tax_due',
        label: t('accounting.mobile.cards.net_tax_due'),
        value: formatMoney(props.mobile_summary?.net_tax_due ?? 0),
    },
    {
        key: 'open_period_count',
        label: t('accounting.mobile.cards.open_periods'),
        value: props.mobile_summary?.open_period_count ?? 0,
    },
    {
        key: 'unreconciled_entry_count',
        label: t('accounting.mobile.cards.unreconciled_entries'),
        value: props.mobile_summary?.unreconciled_entry_count ?? 0,
    },
    {
        key: 'pending_batch_count',
        label: t('accounting.mobile.cards.pending_batches'),
        value: props.mobile_summary?.pending_batch_count ?? 0,
    },
]));

const summaryCards = computed(() => ([
    { key: 'entry_count', label: t('accounting.journal.summary.entry_count'), value: props.journal_summary?.entry_count ?? 0 },
    { key: 'batch_count', label: t('accounting.journal.summary.batch_count'), value: props.journal_summary?.batch_count ?? 0 },
    { key: 'debit_total', label: t('accounting.journal.summary.debit_total'), value: formatMoney(props.journal_summary?.debit_total ?? 0) },
    { key: 'credit_total', label: t('accounting.journal.summary.credit_total'), value: formatMoney(props.journal_summary?.credit_total ?? 0) },
    { key: 'review_required_count', label: t('accounting.journal.summary.review_required_count'), value: props.journal_summary?.review_required_count ?? 0 },
]));

const taxCards = computed(() => ([
    { key: 'taxes_collected', label: t('accounting.taxes.cards.taxes_collected'), value: formatMoney(props.tax_summary?.taxes_collected ?? 0) },
    { key: 'taxes_paid', label: t('accounting.taxes.cards.taxes_paid'), value: formatMoney(props.tax_summary?.taxes_paid ?? 0) },
    { key: 'net_tax_due', label: t('accounting.taxes.cards.net_tax_due'), value: formatMoney(props.tax_summary?.net_tax_due ?? 0) },
    { key: 'review_required_count', label: t('accounting.taxes.cards.review_required_count'), value: props.tax_summary?.review_required_count ?? 0 },
]));

const exportUrl = computed(() => route('accounting.export', normalizeFilters()));

const directionTone = (direction) => direction === 'credit'
    ? 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200'
    : 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200';

const statusTone = (status) => status === 'review_required'
    ? 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200'
    : 'border-stone-200 bg-stone-50 text-stone-700 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300';

function normalizeFilters() {
    return Object.fromEntries(
        Object.entries({
            period: filterState.period || undefined,
            source_type: filterState.source_type || undefined,
            account_id: filterState.account_id || undefined,
            review_status: filterState.review_status || undefined,
            search: filterState.search || undefined,
        }).filter(([, value]) => value !== undefined && value !== null && value !== ''),
    );
}

function applyFilters() {
    router.get(route('accounting.index'), normalizeFilters(), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function resetFilters() {
    filterState.period = '';
    filterState.source_type = '';
    filterState.account_id = '';
    filterState.review_status = '';
    filterState.search = '';
    applyFilters();
}

function formatMoney(amount, currency = props.snapshot?.currency_code || 'CAD') {
    const numeric = Number(amount || 0);

    try {
        return new Intl.NumberFormat(locale.value || undefined, {
            style: 'currency',
            currency,
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        }).format(numeric);
    } catch {
        return `${numeric.toFixed(2)} ${currency}`;
    }
}

function formatDate(value) {
    if (!value) {
        return '-';
    }

    try {
        return new Intl.DateTimeFormat(locale.value || undefined, {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
        }).format(new Date(value));
    } catch {
        return value;
    }
}

function sourceTypeLabel(value) {
    return t(`accounting.source_counts.${value}`, value);
}

function transitionPeriod(periodKey, action) {
    const routeMap = {
        open: 'accounting.periods.open',
        in_review: 'accounting.periods.in-review',
        close: 'accounting.periods.close',
        reopen: 'accounting.periods.reopen',
    };

    const routeName = routeMap[action];
    if (!routeName || !periodKey) {
        return;
    }

    router.patch(route(routeName, { periodKey }), {}, {
        preserveState: true,
        preserveScroll: true,
    });
}

function transitionEntry(entryId, action) {
    const routeMap = {
        unreviewed: 'accounting.entries.unreview',
        reviewed: 'accounting.entries.review',
        reconciled: 'accounting.entries.reconcile',
    };
    const routeName = routeMap[action];
    if (!routeName || !entryId) {
        return;
    }

    router.patch(route(routeName, { accountingEntry: entryId }), {}, {
        preserveState: true,
        preserveScroll: true,
    });
}

function transitionBatch(batchId, action) {
    const routeMap = {
        unreviewed: 'accounting.batches.unreview',
        reviewed: 'accounting.batches.review',
        reconciled: 'accounting.batches.reconcile',
    };
    const routeName = routeMap[action];
    if (!routeName || !batchId) {
        return;
    }

    router.patch(route(routeName, { accountingEntryBatch: batchId }), {}, {
        preserveState: true,
        preserveScroll: true,
    });
}

function mobileAlertTone(tone) {
    const tones = {
        warning: 'border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100',
        success: 'border-emerald-200 bg-emerald-50 text-emerald-900 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-100',
        info: 'border-sky-200 bg-sky-50 text-sky-900 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-100',
        neutral: 'border-stone-200 bg-stone-50 text-stone-900 dark:border-neutral-700 dark:bg-neutral-950 dark:text-neutral-100',
    };

    return tones[tone] || tones.neutral;
}

function mobileAlertTitle(key) {
    return t(`accounting.mobile.alerts.${key}.title`);
}

function mobileAlertDescription(key) {
    return t(`accounting.mobile.alerts.${key}.description`);
}

function mobileAlertValue(alert) {
    if (alert?.value_type === 'money') {
        return formatMoney(alert?.value ?? 0);
    }

    return alert?.value ?? 0;
}

function mobileAlertHref(target) {
    const targets = {
        periods: '#accounting-periods',
        taxes: '#accounting-tax-summary',
        review: '#accounting-review-workspace',
        exports: '#accounting-exports',
    };

    return targets[target] || '#accounting-mobile-board';
}
</script>

<template>
    <Head :title="$t('accounting.title')" />

    <AuthenticatedLayout>
        <div class="space-y-5">
            <section class="overflow-hidden rounded-sm border border-stone-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="border-b border-stone-200 bg-gradient-to-r from-stone-900 via-stone-800 to-emerald-800 px-5 py-6 text-white dark:border-neutral-700">
                    <div class="flex flex-wrap items-center gap-3">
                        <span class="rounded-full border border-white/20 bg-white/10 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.18em]">
                            {{ $t('accounting.badge') }}
                        </span>
                        <span class="rounded-full border border-white/15 bg-black/10 px-3 py-1 text-[11px] font-medium uppercase tracking-[0.16em]">
                            {{ statusStateLabel }}
                        </span>
                        <span class="rounded-full border border-white/15 bg-black/10 px-3 py-1 text-[11px] font-medium">
                            {{ $t('accounting.sync.batches_synced') }}: {{ sync_summary.batches_synced ?? 0 }}
                        </span>
                        <span class="rounded-full border border-white/15 bg-black/10 px-3 py-1 text-[11px] font-medium">
                            {{ $t('accounting.sync.entries_written') }}: {{ sync_summary.entries_written ?? 0 }}
                        </span>
                    </div>
                    <h1 class="mt-4 text-2xl font-semibold">
                        {{ $t('accounting.heading') }}
                    </h1>
                    <p class="mt-2 max-w-3xl text-sm text-stone-100/90">
                        {{ $t('accounting.subtitle') }}
                    </p>
                </div>

                <div class="grid gap-4 px-5 py-5 lg:grid-cols-[1.6fr,1fr]">
                    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        <div
                            v-for="card in sourceCards"
                            :key="card.key"
                            class="rounded-sm border border-stone-200 bg-stone-50 p-4 dark:border-neutral-700 dark:bg-neutral-950"
                        >
                            <div class="text-[11px] font-medium uppercase tracking-[0.16em] text-stone-500 dark:text-neutral-400">
                                {{ $t(`accounting.source_counts.${card.key}`) }}
                            </div>
                            <div class="mt-2 text-2xl font-semibold text-stone-900 dark:text-neutral-100">
                                {{ card.value }}
                            </div>
                        </div>
                    </div>

                    <div class="rounded-sm border border-stone-200 bg-stone-50 p-4 dark:border-neutral-700 dark:bg-neutral-950">
                        <div class="text-[11px] font-medium uppercase tracking-[0.16em] text-stone-500 dark:text-neutral-400">
                            {{ $t('accounting.snapshot.title') }}
                        </div>
                        <div class="mt-4 space-y-3 text-sm text-stone-700 dark:text-neutral-200">
                            <div class="flex items-start justify-between gap-4">
                                <span class="text-stone-500 dark:text-neutral-400">{{ $t('accounting.snapshot.company') }}</span>
                                <span class="font-semibold text-right">{{ snapshot.company_name || '-' }}</span>
                            </div>
                            <div class="flex items-start justify-between gap-4">
                                <span class="text-stone-500 dark:text-neutral-400">{{ $t('accounting.snapshot.currency') }}</span>
                                <span class="font-semibold">{{ snapshot.currency_code || 'CAD' }}</span>
                            </div>
                            <div class="flex items-start justify-between gap-4">
                                <span class="text-stone-500 dark:text-neutral-400">{{ $t('accounting.snapshot.finance_approvals') }}</span>
                                <span class="font-semibold text-right">{{ financeApprovalModeLabel }}</span>
                            </div>
                            <div class="flex items-start justify-between gap-4">
                                <span class="text-stone-500 dark:text-neutral-400">{{ $t('accounting.snapshot.last_sync') }}</span>
                                <span class="font-semibold">{{ formatDate(status.last_synced_at) }}</span>
                            </div>
                            <div
                                v-if="snapshot.invoice_auto_approve_under_amount !== null && snapshot.invoice_auto_approve_under_amount !== undefined && snapshot.invoice_auto_approve_under_amount !== ''"
                                class="flex items-start justify-between gap-4"
                            >
                                <span class="text-stone-500 dark:text-neutral-400">{{ $t('accounting.snapshot.invoice_auto_approve_under_amount') }}</span>
                                <span class="font-semibold">
                                    {{ snapshot.invoice_auto_approve_under_amount }} {{ snapshot.currency_code || 'CAD' }}
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section
                id="accounting-mobile-board"
                class="space-y-4 rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 lg:hidden"
            >
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 class="text-sm font-semibold text-stone-900 dark:text-neutral-100">
                            {{ $t('accounting.mobile.title') }}
                        </h2>
                        <p class="mt-1 text-sm text-stone-600 dark:text-neutral-400">
                            {{ $t('accounting.mobile.subtitle') }}
                        </p>
                    </div>
                    <span class="rounded-full border border-stone-200 bg-stone-50 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.14em] text-stone-600 dark:border-neutral-700 dark:bg-neutral-950 dark:text-neutral-300">
                        {{ mobile_summary.period_label || $t('accounting.taxes.all_periods') }}
                    </span>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div
                        v-for="card in mobileSummaryCards"
                        :key="card.key"
                        class="rounded-sm border border-stone-200 bg-stone-50 px-4 py-3 dark:border-neutral-700 dark:bg-neutral-950"
                    >
                        <div class="text-[11px] font-medium uppercase tracking-[0.14em] text-stone-500 dark:text-neutral-400">
                            {{ card.label }}
                        </div>
                        <div class="mt-2 text-lg font-semibold text-stone-900 dark:text-neutral-100">
                            {{ card.value }}
                        </div>
                    </div>
                </div>

                <div>
                    <h3 class="text-sm font-semibold text-stone-900 dark:text-neutral-100">
                        {{ $t('accounting.mobile.alerts.title') }}
                    </h3>
                    <p class="mt-1 text-sm text-stone-600 dark:text-neutral-400">
                        {{ $t('accounting.mobile.alerts.subtitle') }}
                    </p>

                    <div
                        v-if="(mobile_alerts || []).length"
                        class="mt-3 space-y-3"
                    >
                        <a
                            v-for="alert in mobile_alerts || []"
                            :key="`${alert.key}-${alert.target}`"
                            :href="mobileAlertHref(alert.target)"
                            class="block rounded-sm border px-4 py-3 transition hover:opacity-90"
                            :class="mobileAlertTone(alert.tone)"
                        >
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <div class="text-sm font-semibold">
                                        {{ mobileAlertTitle(alert.key) }}
                                    </div>
                                    <div class="mt-1 text-xs opacity-80">
                                        {{ mobileAlertDescription(alert.key) }}
                                    </div>
                                </div>
                                <div class="text-right">
                                    <div class="text-sm font-semibold">
                                        {{ mobileAlertValue(alert) }}
                                    </div>
                                    <div class="mt-1 text-[11px] uppercase tracking-[0.14em] opacity-80">
                                        {{ $t('accounting.mobile.alerts.open') }}
                                    </div>
                                </div>
                            </div>
                        </a>
                    </div>
                    <div
                        v-else
                        class="mt-3 rounded-sm border border-dashed border-stone-300 px-4 py-5 text-sm text-stone-500 dark:border-neutral-700 dark:text-neutral-400"
                    >
                        {{ $t('accounting.mobile.alerts.empty') }}
                    </div>
                </div>
            </section>

            <section
                id="accounting-periods"
                class="grid gap-5 xl:grid-cols-[1.1fr,1fr]"
            >
                <div
                    id="accounting-tax-summary"
                    class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900"
                >
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h2 class="text-sm font-semibold text-stone-900 dark:text-neutral-100">
                                {{ $t('accounting.periods.title') }}
                            </h2>
                            <p class="mt-1 text-sm text-stone-600 dark:text-neutral-400">
                                {{ $t('accounting.periods.subtitle') }}
                            </p>
                        </div>
                        <div class="grid grid-cols-2 gap-2 text-right sm:grid-cols-4">
                            <div class="rounded-sm border border-stone-200 bg-stone-50 px-3 py-2 dark:border-neutral-700 dark:bg-neutral-950">
                                <div class="text-[11px] uppercase tracking-[0.14em] text-stone-500 dark:text-neutral-400">
                                    {{ $t('accounting.periods.summary.open') }}
                                </div>
                                <div class="mt-1 text-lg font-semibold text-stone-900 dark:text-neutral-100">
                                    {{ period_summary.open_count ?? 0 }}
                                </div>
                            </div>
                            <div class="rounded-sm border border-stone-200 bg-stone-50 px-3 py-2 dark:border-neutral-700 dark:bg-neutral-950">
                                <div class="text-[11px] uppercase tracking-[0.14em] text-stone-500 dark:text-neutral-400">
                                    {{ $t('accounting.periods.summary.in_review') }}
                                </div>
                                <div class="mt-1 text-lg font-semibold text-stone-900 dark:text-neutral-100">
                                    {{ period_summary.in_review_count ?? 0 }}
                                </div>
                            </div>
                            <div class="rounded-sm border border-stone-200 bg-stone-50 px-3 py-2 dark:border-neutral-700 dark:bg-neutral-950">
                                <div class="text-[11px] uppercase tracking-[0.14em] text-stone-500 dark:text-neutral-400">
                                    {{ $t('accounting.periods.summary.closed') }}
                                </div>
                                <div class="mt-1 text-lg font-semibold text-stone-900 dark:text-neutral-100">
                                    {{ period_summary.closed_count ?? 0 }}
                                </div>
                            </div>
                            <div class="rounded-sm border border-stone-200 bg-stone-50 px-3 py-2 dark:border-neutral-700 dark:bg-neutral-950">
                                <div class="text-[11px] uppercase tracking-[0.14em] text-stone-500 dark:text-neutral-400">
                                    {{ $t('accounting.periods.summary.reopened') }}
                                </div>
                                <div class="mt-1 text-lg font-semibold text-stone-900 dark:text-neutral-100">
                                    {{ period_summary.reopened_count ?? 0 }}
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="mt-4 space-y-3">
                        <article
                            v-for="period in periods || []"
                            :key="period.period_key"
                            class="rounded-sm border border-stone-200 bg-stone-50 p-4 dark:border-neutral-700 dark:bg-neutral-950"
                        >
                            <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
                                <div class="space-y-2">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="rounded-full bg-stone-900 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.14em] text-white dark:bg-neutral-100 dark:text-neutral-900">
                                            {{ period.label }}
                                        </span>
                                        <span class="rounded-full border border-stone-200 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.14em] text-stone-600 dark:border-neutral-700 dark:text-neutral-300">
                                            {{ $t(`accounting.periods.statuses.${period.status}`) }}
                                        </span>
                                        <span
                                            v-if="period.is_locked"
                                            class="rounded-full border border-amber-200 bg-amber-50 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.14em] text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200"
                                        >
                                            {{ $t('accounting.periods.locked') }}
                                        </span>
                                    </div>
                                    <div class="grid gap-2 text-sm text-stone-700 dark:text-neutral-200 sm:grid-cols-2">
                                        <div>{{ $t('accounting.journal.summary.entry_count') }}: <span class="font-semibold">{{ period.entry_count }}</span></div>
                                        <div>{{ $t('accounting.journal.summary.batch_count') }}: <span class="font-semibold">{{ period.batch_count }}</span></div>
                                        <div>{{ $t('accounting.journal.summary.debit_total') }}: <span class="font-semibold">{{ formatMoney(period.debit_total) }}</span></div>
                                        <div>{{ $t('accounting.journal.summary.credit_total') }}: <span class="font-semibold">{{ formatMoney(period.credit_total) }}</span></div>
                                    </div>
                                    <div
                                        v-if="period.closed_at || period.reopened_at"
                                        class="flex flex-wrap gap-3 text-xs text-stone-500 dark:text-neutral-400"
                                    >
                                        <span v-if="period.closed_at">
                                            {{ $t('accounting.periods.closed_at') }}: {{ formatDate(period.closed_at) }}<span v-if="period.closed_by_name"> • {{ period.closed_by_name }}</span>
                                        </span>
                                        <span v-if="period.reopened_at">
                                            {{ $t('accounting.periods.reopened_at') }}: {{ formatDate(period.reopened_at) }}<span v-if="period.reopened_by_name"> • {{ period.reopened_by_name }}</span>
                                        </span>
                                    </div>
                                </div>

                                <div
                                    v-if="abilities.can_manage"
                                    class="flex flex-wrap gap-2 xl:max-w-[16rem] xl:justify-end"
                                >
                                    <button
                                        v-if="period.actions?.open"
                                        type="button"
                                        class="inline-flex items-center justify-center rounded-sm border border-stone-300 px-3 py-2 text-sm font-medium text-stone-700 transition hover:bg-stone-100 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800"
                                        @click="transitionPeriod(period.period_key, 'open')"
                                    >
                                        {{ $t('accounting.periods.actions.open') }}
                                    </button>
                                    <button
                                        v-if="period.actions?.in_review"
                                        type="button"
                                        class="inline-flex items-center justify-center rounded-sm border border-amber-300 bg-amber-50 px-3 py-2 text-sm font-medium text-amber-800 transition hover:bg-amber-100 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200"
                                        @click="transitionPeriod(period.period_key, 'in_review')"
                                    >
                                        {{ $t('accounting.periods.actions.in_review') }}
                                    </button>
                                    <button
                                        v-if="period.actions?.close"
                                        type="button"
                                        class="inline-flex items-center justify-center rounded-sm bg-stone-900 px-3 py-2 text-sm font-medium text-white transition hover:bg-stone-800 dark:bg-neutral-100 dark:text-neutral-900 dark:hover:bg-white"
                                        @click="transitionPeriod(period.period_key, 'close')"
                                    >
                                        {{ $t('accounting.periods.actions.close') }}
                                    </button>
                                    <button
                                        v-if="period.actions?.reopen"
                                        type="button"
                                        class="inline-flex items-center justify-center rounded-sm border border-emerald-300 bg-emerald-50 px-3 py-2 text-sm font-medium text-emerald-800 transition hover:bg-emerald-100 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200"
                                        @click="transitionPeriod(period.period_key, 'reopen')"
                                    >
                                        {{ $t('accounting.periods.actions.reopen') }}
                                    </button>
                                </div>
                            </div>
                        </article>
                    </div>
                </div>

                <div
                    id="accounting-exports"
                    class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900"
                >
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h2 class="text-sm font-semibold text-stone-900 dark:text-neutral-100">
                                {{ $t('accounting.taxes.title') }}
                            </h2>
                            <p class="mt-1 text-sm text-stone-600 dark:text-neutral-400">
                                {{ $t('accounting.taxes.subtitle') }}
                            </p>
                        </div>
                        <span class="rounded-full border border-stone-200 bg-stone-50 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.14em] text-stone-600 dark:border-neutral-700 dark:bg-neutral-950 dark:text-neutral-300">
                            {{ tax_summary.period_label || $t('accounting.taxes.all_periods') }}
                        </span>
                    </div>

                    <div class="mt-4 grid gap-3 sm:grid-cols-2">
                        <div
                            v-for="card in taxCards"
                            :key="card.key"
                            class="rounded-sm border border-stone-200 bg-stone-50 p-4 dark:border-neutral-700 dark:bg-neutral-950"
                        >
                            <div class="text-[11px] font-medium uppercase tracking-[0.16em] text-stone-500 dark:text-neutral-400">
                                {{ card.label }}
                            </div>
                            <div class="mt-2 text-2xl font-semibold text-stone-900 dark:text-neutral-100">
                                {{ card.value }}
                            </div>
                        </div>
                    </div>

                    <div class="mt-4 grid gap-3 sm:grid-cols-3">
                        <div
                            v-for="row in tax_summary.source_breakdown || []"
                            :key="`${row.source_type}-${row.direction}`"
                            class="rounded-sm border border-stone-200 px-4 py-3 text-sm dark:border-neutral-700"
                        >
                            <div class="text-xs uppercase tracking-[0.14em] text-stone-500 dark:text-neutral-400">
                                {{ sourceTypeLabel(row.source_type) }}
                            </div>
                            <div class="mt-2 font-semibold text-stone-900 dark:text-neutral-100">
                                {{ formatMoney(row.amount) }}
                            </div>
                            <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                {{ $t(`accounting.taxes.directions.${row.direction}`) }}
                            </div>
                        </div>
                    </div>

                    <div
                        v-if="(tax_summary.review_required_sources || []).length"
                        class="mt-4 rounded-sm border border-amber-200 bg-amber-50 p-4 dark:border-amber-500/30 dark:bg-amber-500/10"
                    >
                        <h3 class="text-sm font-semibold text-amber-900 dark:text-amber-100">
                            {{ $t('accounting.taxes.review_required_title') }}
                        </h3>
                        <p class="mt-1 text-sm text-amber-800/90 dark:text-amber-200/90">
                            {{ $t('accounting.taxes.review_required_subtitle') }}
                        </p>

                        <div class="mt-3 space-y-2">
                            <article
                                v-for="source in tax_summary.review_required_sources || []"
                                :key="`${source.source_type}-${source.source_event_key}-${source.source_reference}`"
                                class="rounded-sm border border-amber-200 bg-white/70 px-3 py-3 dark:border-amber-500/20 dark:bg-neutral-950/60"
                            >
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="rounded-full bg-amber-200 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.14em] text-amber-900 dark:bg-amber-300/20 dark:text-amber-100">
                                        {{ sourceTypeLabel(source.source_type) }}
                                    </span>
                                    <span class="text-sm font-medium text-stone-900 dark:text-neutral-100">
                                        {{ source.source_reference }}
                                    </span>
                                </div>
                                <div class="mt-2 flex flex-wrap items-center gap-3 text-xs text-stone-600 dark:text-neutral-300">
                                    <span>{{ $t('accounting.taxes.cards.taxes_collected') }} / {{ $t('accounting.taxes.cards.taxes_paid') }}: {{ formatMoney(source.tax_amount) }}</span>
                                    <span>{{ $t(`accounting.journal.batch_statuses.${source.batch_status}`) }}</span>
                                    <Link
                                        v-if="source.source_url"
                                        :href="source.source_url"
                                        class="font-semibold text-emerald-700 transition hover:text-emerald-800 dark:text-emerald-300 dark:hover:text-emerald-200"
                                    >
                                        {{ $t('accounting.journal.view_source') }}
                                    </Link>
                                </div>
                            </article>
                        </div>
                    </div>
                </div>

                <div class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <h2 class="text-sm font-semibold text-stone-900 dark:text-neutral-100">
                                {{ $t('accounting.exports.title') }}
                            </h2>
                            <p class="mt-1 text-sm text-stone-600 dark:text-neutral-400">
                                {{ $t('accounting.exports.subtitle') }}
                            </p>
                        </div>
                        <a
                            :href="exportUrl"
                            class="inline-flex items-center justify-center rounded-sm bg-stone-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-stone-800 dark:bg-neutral-100 dark:text-neutral-900 dark:hover:bg-white"
                        >
                            {{ $t('accounting.exports.generate_csv') }}
                        </a>
                    </div>

                    <div class="mt-4 rounded-sm border border-stone-200 bg-stone-50 p-4 dark:border-neutral-700 dark:bg-neutral-950">
                        <h3 class="text-sm font-semibold text-stone-900 dark:text-neutral-100">
                            {{ $t('accounting.exports.handoff_title') }}
                        </h3>
                        <div class="mt-3 grid gap-3 sm:grid-cols-2">
                            <div class="text-sm text-stone-700 dark:text-neutral-200">
                                <div class="text-xs uppercase tracking-[0.14em] text-stone-500 dark:text-neutral-400">
                                    {{ $t('accounting.exports.scope') }}
                                </div>
                                <div class="mt-1 font-semibold">
                                    {{ handoff_summary.selected_period_label || $t('accounting.taxes.all_periods') }}
                                </div>
                            </div>
                            <div class="text-sm text-stone-700 dark:text-neutral-200">
                                <div class="text-xs uppercase tracking-[0.14em] text-stone-500 dark:text-neutral-400">
                                    {{ $t('accounting.journal.summary.entry_count') }}
                                </div>
                                <div class="mt-1 font-semibold">
                                    {{ handoff_summary.entry_count ?? 0 }}
                                </div>
                            </div>
                            <div class="text-sm text-stone-700 dark:text-neutral-200">
                                <div class="text-xs uppercase tracking-[0.14em] text-stone-500 dark:text-neutral-400">
                                    {{ $t('accounting.journal.summary.review_required_count') }}
                                </div>
                                <div class="mt-1 font-semibold">
                                    {{ handoff_summary.review_required_count ?? 0 }}
                                </div>
                            </div>
                            <div class="text-sm text-stone-700 dark:text-neutral-200">
                                <div class="text-xs uppercase tracking-[0.14em] text-stone-500 dark:text-neutral-400">
                                    {{ $t('accounting.exports.last_export_at') }}
                                </div>
                                <div class="mt-1 font-semibold">
                                    {{ formatDate(handoff_summary.last_export_at) }}
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="mt-4">
                        <h3 class="text-sm font-semibold text-stone-900 dark:text-neutral-100">
                            {{ $t('accounting.exports.history_title') }}
                        </h3>
                        <div
                            v-if="(export_history || []).length"
                            class="mt-3 space-y-3"
                        >
                            <article
                                v-for="item in export_history"
                                :key="item.id"
                                class="rounded-sm border border-stone-200 bg-stone-50 p-4 dark:border-neutral-700 dark:bg-neutral-950"
                            >
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                    <div class="space-y-2">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="rounded-full bg-stone-900 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.14em] text-white dark:bg-neutral-100 dark:text-neutral-900">
                                                {{ item.format }}
                                            </span>
                                            <span class="rounded-full border border-stone-200 px-2.5 py-1 text-[11px] font-medium uppercase tracking-[0.14em] text-stone-600 dark:border-neutral-700 dark:text-neutral-300">
                                                {{ item.period_key || $t('accounting.taxes.all_periods') }}
                                            </span>
                                        </div>
                                        <div class="text-sm text-stone-700 dark:text-neutral-200">
                                            {{ $t('accounting.exports.generated_by') }}: {{ item.generated_by_name || '-' }}
                                        </div>
                                        <div class="text-sm text-stone-700 dark:text-neutral-200">
                                            {{ $t('accounting.exports.rows') }}: {{ item.row_count || 0 }} • {{ $t('accounting.journal.summary.batch_count') }}: {{ item.batch_count || 0 }}
                                        </div>
                                        <div class="text-xs text-stone-500 dark:text-neutral-400">
                                            {{ formatDate(item.generated_at) }}
                                        </div>
                                    </div>
                                    <a
                                        :href="item.download_url"
                                        class="inline-flex items-center justify-center rounded-sm border border-stone-300 px-3 py-2 text-sm font-medium text-stone-700 transition hover:bg-stone-100 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800"
                                    >
                                        {{ $t('accounting.exports.download') }}
                                    </a>
                                </div>
                            </article>
                        </div>
                        <div
                            v-else
                            class="mt-3 rounded-sm border border-dashed border-stone-300 px-4 py-6 text-sm text-stone-500 dark:border-neutral-700 dark:text-neutral-400"
                        >
                            {{ $t('accounting.exports.empty') }}
                        </div>
                    </div>
                </div>
            </section>

            <section class="grid gap-5 xl:grid-cols-[1.1fr,1.2fr]">
                <div class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <h2 class="text-sm font-semibold text-stone-900 dark:text-neutral-100">
                                {{ $t('accounting.dependencies.title') }}
                            </h2>
                            <p class="mt-1 text-sm text-stone-600 dark:text-neutral-400">
                                {{ $t('accounting.dependencies.subtitle') }}
                            </p>
                        </div>
                    </div>

                    <div class="mt-4 grid gap-3">
                        <div
                            v-for="dependency in status.dependencies || []"
                            :key="dependency.key"
                            class="rounded-sm border p-4"
                            :class="readinessTone(dependency.enabled)"
                        >
                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <div class="text-sm font-semibold">
                                        {{ dependency.label }}
                                    </div>
                                    <div class="mt-1 text-xs">
                                        {{ dependency.required ? $t('accounting.dependencies.required') : $t('accounting.dependencies.optional') }}
                                    </div>
                                </div>
                                <span class="rounded-full border border-current/10 bg-white/40 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.12em] dark:bg-black/10">
                                    {{ dependency.enabled ? $t('accounting.dependencies.ready') : $t('accounting.dependencies.missing') }}
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <h2 class="text-sm font-semibold text-stone-900 dark:text-neutral-100">
                        {{ $t('accounting.next_steps.title') }}
                    </h2>
                    <p class="mt-1 text-sm text-stone-600 dark:text-neutral-400">
                        {{ $t('accounting.next_steps.subtitle') }}
                    </p>

                    <div class="mt-4 space-y-3">
                        <div
                            v-for="(step, index) in next_steps || []"
                            :key="`${index}-${step}`"
                            class="flex items-start gap-3 rounded-sm border border-stone-200 bg-stone-50 px-4 py-3 dark:border-neutral-700 dark:bg-neutral-950"
                        >
                            <div class="mt-0.5 inline-flex size-6 shrink-0 items-center justify-center rounded-sm bg-stone-900 text-[11px] font-semibold text-white dark:bg-neutral-100 dark:text-neutral-900">
                                {{ index + 1 }}
                            </div>
                            <div class="text-sm text-stone-700 dark:text-neutral-200">
                                {{ step }}
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section
                id="accounting-review-workspace"
                class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900"
            >
                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <h2 class="text-sm font-semibold text-stone-900 dark:text-neutral-100">
                            {{ $t('accounting.review.title') }}
                        </h2>
                        <p class="mt-1 text-sm text-stone-600 dark:text-neutral-400">
                            {{ $t('accounting.review.subtitle') }}
                        </p>
                    </div>
                    <div class="grid grid-cols-3 gap-2 text-right">
                        <div class="rounded-sm border border-stone-200 bg-stone-50 px-3 py-2 dark:border-neutral-700 dark:bg-neutral-950">
                            <div class="text-[11px] uppercase tracking-[0.14em] text-stone-500 dark:text-neutral-400">
                                {{ $t('accounting.journal.review_statuses.unreviewed') }}
                            </div>
                            <div class="mt-1 text-lg font-semibold text-stone-900 dark:text-neutral-100">
                                {{ review_workspace.entry_status_counts?.unreviewed ?? 0 }}
                            </div>
                        </div>
                        <div class="rounded-sm border border-stone-200 bg-stone-50 px-3 py-2 dark:border-neutral-700 dark:bg-neutral-950">
                            <div class="text-[11px] uppercase tracking-[0.14em] text-stone-500 dark:text-neutral-400">
                                {{ $t('accounting.journal.review_statuses.reviewed') }}
                            </div>
                            <div class="mt-1 text-lg font-semibold text-stone-900 dark:text-neutral-100">
                                {{ review_workspace.entry_status_counts?.reviewed ?? 0 }}
                            </div>
                        </div>
                        <div class="rounded-sm border border-stone-200 bg-stone-50 px-3 py-2 dark:border-neutral-700 dark:bg-neutral-950">
                            <div class="text-[11px] uppercase tracking-[0.14em] text-stone-500 dark:text-neutral-400">
                                {{ $t('accounting.journal.review_statuses.reconciled') }}
                            </div>
                            <div class="mt-1 text-lg font-semibold text-stone-900 dark:text-neutral-100">
                                {{ review_workspace.entry_status_counts?.reconciled ?? 0 }}
                            </div>
                        </div>
                    </div>
                </div>

                <div
                    v-if="(review_workspace.batches || []).length"
                    class="mt-4 space-y-3"
                >
                    <article
                        v-for="batch in review_workspace.batches || []"
                        :key="batch.id"
                        class="rounded-sm border border-stone-200 bg-stone-50 p-4 dark:border-neutral-700 dark:bg-neutral-950"
                    >
                        <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
                            <div class="space-y-2">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="rounded-full bg-stone-900 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.14em] text-white dark:bg-neutral-100 dark:text-neutral-900">
                                        {{ sourceTypeLabel(batch.source_type) }}
                                    </span>
                                    <span class="text-sm font-semibold text-stone-900 dark:text-neutral-100">
                                        {{ batch.source_reference }}
                                    </span>
                                    <span
                                        class="rounded-full border px-2.5 py-1 text-[11px] font-semibold"
                                        :class="statusTone(batch.review_status)"
                                    >
                                        {{ $t(`accounting.journal.review_statuses.${batch.review_status}`) }}
                                    </span>
                                    <span
                                        class="rounded-full border px-2.5 py-1 text-[11px] font-semibold"
                                        :class="statusTone(batch.status)"
                                    >
                                        {{ $t(`accounting.journal.batch_statuses.${batch.status}`) }}
                                    </span>
                                </div>
                                <div class="grid gap-2 text-sm text-stone-700 dark:text-neutral-200 sm:grid-cols-2 xl:grid-cols-4">
                                    <div>{{ $t('accounting.journal.summary.entry_count') }}: <span class="font-semibold">{{ batch.entry_count }}</span></div>
                                    <div>{{ $t('accounting.review.pending_batches') }}: <span class="font-semibold">{{ batch.unreviewed_entry_count }}</span></div>
                                    <div>{{ $t('accounting.journal.summary.debit_total') }}: <span class="font-semibold">{{ formatMoney(batch.debit_total) }}</span></div>
                                    <div>{{ $t('accounting.journal.summary.credit_total') }}: <span class="font-semibold">{{ formatMoney(batch.credit_total) }}</span></div>
                                </div>
                                <div class="flex flex-wrap items-center gap-3 text-xs text-stone-500 dark:text-neutral-400">
                                    <span>{{ batch.source_event_key }}</span>
                                    <span v-if="batch.tax_total">{{ $t('accounting.journal.tax_label') }}: {{ formatMoney(batch.tax_total) }}</span>
                                    <Link
                                        v-if="batch.source_url"
                                        :href="batch.source_url"
                                        class="font-semibold text-emerald-700 transition hover:text-emerald-800 dark:text-emerald-300 dark:hover:text-emerald-200"
                                    >
                                        {{ $t('accounting.journal.view_source') }}
                                    </Link>
                                </div>
                            </div>

                            <div
                                v-if="abilities.can_manage"
                                class="flex flex-wrap gap-2 xl:max-w-[18rem] xl:justify-end"
                            >
                                <button
                                    v-if="batch.actions?.mark_unreviewed"
                                    type="button"
                                    class="inline-flex items-center justify-center rounded-sm border border-stone-300 px-3 py-2 text-sm font-medium text-stone-700 transition hover:bg-stone-100 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800"
                                    @click="transitionBatch(batch.id, 'unreviewed')"
                                >
                                    {{ $t('accounting.review.actions.unreviewed') }}
                                </button>
                                <button
                                    v-if="batch.actions?.mark_reviewed"
                                    type="button"
                                    class="inline-flex items-center justify-center rounded-sm border border-amber-300 bg-amber-50 px-3 py-2 text-sm font-medium text-amber-800 transition hover:bg-amber-100 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200"
                                    @click="transitionBatch(batch.id, 'reviewed')"
                                >
                                    {{ $t('accounting.review.actions.reviewed') }}
                                </button>
                                <button
                                    v-if="batch.actions?.mark_reconciled"
                                    type="button"
                                    class="inline-flex items-center justify-center rounded-sm border border-emerald-300 bg-emerald-50 px-3 py-2 text-sm font-medium text-emerald-800 transition hover:bg-emerald-100 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200"
                                    @click="transitionBatch(batch.id, 'reconciled')"
                                >
                                    {{ $t('accounting.review.actions.reconciled') }}
                                </button>
                            </div>
                        </div>
                    </article>
                </div>
                <div
                    v-else
                    class="mt-4 rounded-sm border border-dashed border-stone-300 px-4 py-6 text-sm text-stone-500 dark:border-neutral-700 dark:text-neutral-400"
                >
                    {{ $t('accounting.review.empty') }}
                </div>
            </section>

            <section class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
                    <div>
                        <h2 class="text-sm font-semibold text-stone-900 dark:text-neutral-100">
                            {{ $t('accounting.journal.title') }}
                        </h2>
                        <p class="mt-1 text-sm text-stone-600 dark:text-neutral-400">
                            {{ $t('accounting.journal.subtitle') }}
                        </p>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                        <div
                            v-for="card in summaryCards"
                            :key="card.key"
                            class="rounded-sm border border-stone-200 bg-stone-50 px-4 py-3 dark:border-neutral-700 dark:bg-neutral-950"
                        >
                            <div class="text-[11px] font-medium uppercase tracking-[0.16em] text-stone-500 dark:text-neutral-400">
                                {{ card.label }}
                            </div>
                            <div class="mt-2 text-lg font-semibold text-stone-900 dark:text-neutral-100">
                                {{ card.value }}
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-5 grid gap-3 rounded-sm border border-stone-200 bg-stone-50 p-4 dark:border-neutral-700 dark:bg-neutral-950 lg:grid-cols-[1.5fr,0.8fr,0.8fr,0.8fr,1fr,auto,auto]">
                    <input
                        v-model="filterState.search"
                        type="text"
                        :placeholder="$t('accounting.journal.filters.search_placeholder')"
                        class="w-full rounded-sm border border-stone-300 bg-white px-3 py-2 text-sm text-stone-900 outline-none transition focus:border-emerald-500 focus:ring-2 focus:ring-emerald-200 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100 dark:focus:ring-emerald-500/20"
                        @keyup.enter="applyFilters"
                    >
                    <input
                        v-model="filterState.period"
                        type="month"
                        class="w-full rounded-sm border border-stone-300 bg-white px-3 py-2 text-sm text-stone-900 outline-none transition focus:border-emerald-500 focus:ring-2 focus:ring-emerald-200 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100 dark:focus:ring-emerald-500/20"
                    >
                    <select
                        v-model="filterState.source_type"
                        class="w-full rounded-sm border border-stone-300 bg-white px-3 py-2 text-sm text-stone-900 outline-none transition focus:border-emerald-500 focus:ring-2 focus:ring-emerald-200 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100 dark:focus:ring-emerald-500/20"
                    >
                        <option value="">{{ $t('accounting.journal.filters.all_sources') }}</option>
                        <option
                            v-for="option in filter_options.source_types || []"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </option>
                    </select>
                    <select
                        v-model="filterState.account_id"
                        class="w-full rounded-sm border border-stone-300 bg-white px-3 py-2 text-sm text-stone-900 outline-none transition focus:border-emerald-500 focus:ring-2 focus:ring-emerald-200 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100 dark:focus:ring-emerald-500/20"
                    >
                        <option value="">{{ $t('accounting.journal.filters.all_accounts') }}</option>
                        <option
                            v-for="option in filter_options.accounts || []"
                            :key="option.id"
                            :value="String(option.id)"
                        >
                            {{ option.label }}
                        </option>
                    </select>
                    <select
                        v-model="filterState.review_status"
                        class="w-full rounded-sm border border-stone-300 bg-white px-3 py-2 text-sm text-stone-900 outline-none transition focus:border-emerald-500 focus:ring-2 focus:ring-emerald-200 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100 dark:focus:ring-emerald-500/20"
                    >
                        <option value="">{{ $t('accounting.journal.filters.all_review_statuses') }}</option>
                        <option
                            v-for="option in filter_options.review_statuses || []"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </option>
                    </select>
                    <button
                        type="button"
                        class="inline-flex items-center justify-center rounded-sm bg-stone-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-stone-800 dark:bg-neutral-100 dark:text-neutral-900 dark:hover:bg-white"
                        @click="applyFilters"
                    >
                        {{ $t('accounting.journal.filters.apply') }}
                    </button>
                    <button
                        type="button"
                        class="inline-flex items-center justify-center rounded-sm border border-stone-300 px-4 py-2 text-sm font-medium text-stone-700 transition hover:bg-stone-100 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800"
                        @click="resetFilters"
                    >
                        {{ $t('accounting.journal.filters.reset') }}
                    </button>
                </div>

                <div
                    v-if="(journal.data || []).length"
                    class="mt-5 space-y-3 lg:hidden"
                >
                    <article
                        v-for="entry in journal.data"
                        :key="`mobile-${entry.id}`"
                        class="rounded-sm border border-stone-200 bg-stone-50 p-4 dark:border-neutral-700 dark:bg-neutral-950"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <div class="text-sm font-semibold text-stone-900 dark:text-neutral-100">
                                    {{ entry.batch?.source_reference || '-' }}
                                </div>
                                <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                    {{ formatDate(entry.entry_date) }}
                                </div>
                            </div>
                            <div class="text-right">
                                <div class="text-sm font-semibold text-stone-900 dark:text-neutral-100">
                                    {{ formatMoney(entry.amount, entry.currency_code) }}
                                </div>
                                <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                    {{ entry.account?.code || '----' }}
                                </div>
                            </div>
                        </div>

                        <div class="mt-3 flex flex-wrap items-center gap-2 text-xs text-stone-500 dark:text-neutral-400">
                            <span>{{ sourceTypeLabel(entry.batch?.source_type) }}</span>
                            <span>•</span>
                            <span>{{ entry.batch?.source_event_key }}</span>
                            <Link
                                v-if="entry.batch?.source_url"
                                :href="entry.batch.source_url"
                                class="font-medium text-emerald-700 transition hover:text-emerald-800 dark:text-emerald-300 dark:hover:text-emerald-200"
                            >
                                {{ $t('accounting.journal.view_source') }}
                            </Link>
                        </div>

                        <div class="mt-3 grid gap-2 text-sm text-stone-700 dark:text-neutral-200">
                            <div>
                                <span class="font-semibold">{{ $t('accounting.journal.columns.account') }}:</span>
                                {{ entry.account?.name || $t('accounting.journal.unknown_account') }}
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <span
                                    class="inline-flex rounded-full border px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.14em]"
                                    :class="directionTone(entry.direction)"
                                >
                                    {{ $t(`accounting.journal.directions.${entry.direction}`) }}
                                </span>
                                <span
                                    class="inline-flex rounded-full border px-2.5 py-1 text-[11px] font-semibold"
                                    :class="statusTone(entry.review_status)"
                                >
                                    {{ $t(`accounting.journal.review_statuses.${entry.review_status}`) }}
                                </span>
                                <span
                                    class="inline-flex rounded-full border px-2.5 py-1 text-[11px] font-semibold"
                                    :class="statusTone(entry.batch?.review_status)"
                                >
                                    {{ $t(`accounting.journal.review_statuses.${entry.batch?.review_status}`) }}
                                </span>
                            </div>
                        </div>

                        <div
                            v-if="abilities.can_manage"
                            class="mt-3 flex flex-wrap gap-2"
                        >
                            <button
                                v-if="entry.review_status !== 'unreviewed'"
                                type="button"
                                class="inline-flex items-center justify-center rounded-sm border border-stone-300 px-2.5 py-1 text-xs font-medium text-stone-700 transition hover:bg-stone-100 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800"
                                @click="transitionEntry(entry.id, 'unreviewed')"
                            >
                                {{ $t('accounting.review.actions.unreviewed') }}
                            </button>
                            <button
                                v-if="entry.review_status !== 'reviewed'"
                                type="button"
                                class="inline-flex items-center justify-center rounded-sm border border-amber-300 bg-amber-50 px-2.5 py-1 text-xs font-medium text-amber-800 transition hover:bg-amber-100 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200"
                                @click="transitionEntry(entry.id, 'reviewed')"
                            >
                                {{ $t('accounting.review.actions.reviewed') }}
                            </button>
                            <button
                                v-if="entry.review_status !== 'reconciled'"
                                type="button"
                                class="inline-flex items-center justify-center rounded-sm border border-emerald-300 bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-800 transition hover:bg-emerald-100 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200"
                                @click="transitionEntry(entry.id, 'reconciled')"
                            >
                                {{ $t('accounting.review.actions.reconciled') }}
                            </button>
                        </div>
                    </article>
                </div>

                <div class="mt-5 hidden overflow-hidden rounded-sm border border-stone-200 dark:border-neutral-700 lg:block">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-stone-200 dark:divide-neutral-700">
                            <thead class="bg-stone-100 text-left text-[11px] font-semibold uppercase tracking-[0.16em] text-stone-500 dark:bg-neutral-800 dark:text-neutral-400">
                                <tr>
                                    <th class="px-4 py-3">{{ $t('accounting.journal.columns.date') }}</th>
                                    <th class="px-4 py-3">{{ $t('accounting.journal.columns.source') }}</th>
                                    <th class="px-4 py-3">{{ $t('accounting.journal.columns.account') }}</th>
                                    <th class="px-4 py-3">{{ $t('accounting.journal.columns.direction') }}</th>
                                    <th class="px-4 py-3">{{ $t('accounting.journal.columns.amount') }}</th>
                                    <th class="px-4 py-3">{{ $t('accounting.journal.columns.review') }}</th>
                                    <th
                                        v-if="abilities.can_manage"
                                        class="px-4 py-3"
                                    >
                                        {{ $t('accounting.journal.columns.actions') }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody
                                v-if="(journal.data || []).length"
                                class="divide-y divide-stone-200 text-sm dark:divide-neutral-800"
                            >
                                <tr
                                    v-for="entry in journal.data"
                                    :key="entry.id"
                                    class="odd:bg-white even:bg-stone-50 dark:odd:bg-neutral-900 dark:even:bg-neutral-950"
                                >
                                    <td class="px-4 py-3 text-stone-700 dark:text-neutral-200">
                                        {{ formatDate(entry.entry_date) }}
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="font-medium text-stone-900 dark:text-neutral-100">
                                            {{ entry.batch?.source_reference || '-' }}
                                        </div>
                                        <div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-stone-500 dark:text-neutral-400">
                                            <span>{{ sourceTypeLabel(entry.batch?.source_type) }}</span>
                                            <span>•</span>
                                            <span>{{ entry.batch?.source_event_key }}</span>
                                            <Link
                                                v-if="entry.batch?.source_url"
                                                :href="entry.batch.source_url"
                                                class="font-medium text-emerald-700 transition hover:text-emerald-800 dark:text-emerald-300 dark:hover:text-emerald-200"
                                            >
                                                {{ $t('accounting.journal.view_source') }}
                                            </Link>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="font-medium text-stone-900 dark:text-neutral-100">
                                            {{ entry.account?.code || '----' }}
                                        </div>
                                        <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                            {{ entry.account?.name || $t('accounting.journal.unknown_account') }}
                                        </div>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span
                                            class="inline-flex rounded-full border px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.14em]"
                                            :class="directionTone(entry.direction)"
                                        >
                                            {{ $t(`accounting.journal.directions.${entry.direction}`) }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 font-semibold text-stone-900 dark:text-neutral-100">
                                        <div>{{ formatMoney(entry.amount, entry.currency_code) }}</div>
                                        <div
                                            v-if="entry.tax_amount"
                                            class="mt-1 text-xs font-normal text-stone-500 dark:text-neutral-400"
                                        >
                                            {{ $t('accounting.journal.tax_label') }}: {{ formatMoney(entry.tax_amount, entry.currency_code) }}
                                        </div>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="flex flex-wrap gap-2">
                                            <span
                                                class="inline-flex rounded-full border px-2.5 py-1 text-[11px] font-semibold"
                                                :class="statusTone(entry.review_status)"
                                            >
                                                {{ $t(`accounting.journal.review_statuses.${entry.review_status}`) }}
                                            </span>
                                            <span
                                                class="inline-flex rounded-full border px-2.5 py-1 text-[11px] font-semibold"
                                                :class="statusTone(entry.batch?.status)"
                                            >
                                                {{ $t(`accounting.journal.batch_statuses.${entry.batch?.status}`) }}
                                            </span>
                                            <span
                                                class="inline-flex rounded-full border px-2.5 py-1 text-[11px] font-semibold"
                                                :class="statusTone(entry.batch?.review_status)"
                                            >
                                                {{ $t(`accounting.journal.review_statuses.${entry.batch?.review_status}`) }}
                                            </span>
                                        </div>
                                    </td>
                                    <td
                                        v-if="abilities.can_manage"
                                        class="px-4 py-3"
                                    >
                                        <div class="flex flex-wrap gap-2">
                                            <button
                                                v-if="entry.review_status !== 'unreviewed'"
                                                type="button"
                                                class="inline-flex items-center justify-center rounded-sm border border-stone-300 px-2.5 py-1 text-xs font-medium text-stone-700 transition hover:bg-stone-100 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800"
                                                @click="transitionEntry(entry.id, 'unreviewed')"
                                            >
                                                {{ $t('accounting.review.actions.unreviewed') }}
                                            </button>
                                            <button
                                                v-if="entry.review_status !== 'reviewed'"
                                                type="button"
                                                class="inline-flex items-center justify-center rounded-sm border border-amber-300 bg-amber-50 px-2.5 py-1 text-xs font-medium text-amber-800 transition hover:bg-amber-100 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200"
                                                @click="transitionEntry(entry.id, 'reviewed')"
                                            >
                                                {{ $t('accounting.review.actions.reviewed') }}
                                            </button>
                                            <button
                                                v-if="entry.review_status !== 'reconciled'"
                                                type="button"
                                                class="inline-flex items-center justify-center rounded-sm border border-emerald-300 bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-800 transition hover:bg-emerald-100 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200"
                                                @click="transitionEntry(entry.id, 'reconciled')"
                                            >
                                                {{ $t('accounting.review.actions.reconciled') }}
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                            <tbody v-else>
                                <tr>
                                    <td :colspan="abilities.can_manage ? 7 : 6" class="px-4 py-10 text-center text-sm text-stone-500 dark:text-neutral-400">
                                        {{ $t('accounting.journal.empty') }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="mt-4">
                    <AdminPaginationLinks :links="journal.links || []" />
                </div>
            </section>

            <section class="grid gap-5 xl:grid-cols-2">
                <div class="hidden rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 lg:block">
                    <h2 class="text-sm font-semibold text-stone-900 dark:text-neutral-100">
                        {{ $t('accounting.journal.grouped.by_account_title') }}
                    </h2>
                    <p class="mt-1 text-sm text-stone-600 dark:text-neutral-400">
                        {{ $t('accounting.journal.grouped.by_account_subtitle') }}
                    </p>

                    <div class="mt-4 overflow-hidden rounded-sm border border-stone-200 dark:border-neutral-700">
                        <table class="min-w-full divide-y divide-stone-200 dark:divide-neutral-700">
                            <thead class="bg-stone-100 text-left text-[11px] font-semibold uppercase tracking-[0.16em] text-stone-500 dark:bg-neutral-800 dark:text-neutral-400">
                                <tr>
                                    <th class="px-4 py-3">{{ $t('accounting.accounts.columns.code') }}</th>
                                    <th class="px-4 py-3">{{ $t('accounting.accounts.columns.name') }}</th>
                                    <th class="px-4 py-3">{{ $t('accounting.journal.summary.debit_total') }}</th>
                                    <th class="px-4 py-3">{{ $t('accounting.journal.summary.credit_total') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-stone-200 text-sm dark:divide-neutral-800">
                                <tr
                                    v-for="group in journal_summary.by_account || []"
                                    :key="group.account_id || `${group.account_code}-${group.account_name}`"
                                    class="odd:bg-white even:bg-stone-50 dark:odd:bg-neutral-900 dark:even:bg-neutral-950"
                                >
                                    <td class="px-4 py-3 font-semibold text-stone-900 dark:text-neutral-100">
                                        {{ group.account_code }}
                                    </td>
                                    <td class="px-4 py-3 text-stone-700 dark:text-neutral-200">
                                        {{ group.account_name }}
                                    </td>
                                    <td class="px-4 py-3 text-stone-700 dark:text-neutral-200">
                                        {{ formatMoney(group.debit_total) }}
                                    </td>
                                    <td class="px-4 py-3 text-stone-700 dark:text-neutral-200">
                                        {{ formatMoney(group.credit_total) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <h2 class="text-sm font-semibold text-stone-900 dark:text-neutral-100">
                        {{ $t('accounting.journal.grouped.by_source_title') }}
                    </h2>
                    <p class="mt-1 text-sm text-stone-600 dark:text-neutral-400">
                        {{ $t('accounting.journal.grouped.by_source_subtitle') }}
                    </p>

                    <div class="mt-4 space-y-3">
                        <article
                            v-for="group in journal_summary.by_source || []"
                            :key="`${group.source_type}-${group.source_event_key}`"
                            class="rounded-sm border border-stone-200 bg-stone-50 p-4 dark:border-neutral-700 dark:bg-neutral-950"
                        >
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="rounded-full bg-stone-900 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.14em] text-white dark:bg-neutral-100 dark:text-neutral-900">
                                    {{ sourceTypeLabel(group.source_type) }}
                                </span>
                                <span class="rounded-full border border-stone-200 px-2.5 py-1 text-[11px] font-medium uppercase tracking-[0.14em] text-stone-600 dark:border-neutral-700 dark:text-neutral-300">
                                    {{ group.source_event_key }}
                                </span>
                            </div>
                            <div class="mt-3 grid gap-2 text-sm text-stone-700 dark:text-neutral-200">
                                <div>
                                    <span class="font-semibold">{{ $t('accounting.journal.summary.entry_count') }}:</span>
                                    {{ group.entry_count }}
                                </div>
                                <div>
                                    <span class="font-semibold">{{ $t('accounting.journal.summary.debit_total') }}:</span>
                                    {{ formatMoney(group.debit_total) }}
                                </div>
                                <div>
                                    <span class="font-semibold">{{ $t('accounting.journal.summary.credit_total') }}:</span>
                                    {{ formatMoney(group.credit_total) }}
                                </div>
                            </div>
                        </article>
                    </div>
                </div>
            </section>

            <section class="hidden gap-5 xl:grid-cols-2 lg:grid">
                <div class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <h2 class="text-sm font-semibold text-stone-900 dark:text-neutral-100">
                        {{ $t('accounting.accounts.title') }}
                    </h2>
                    <p class="mt-1 text-sm text-stone-600 dark:text-neutral-400">
                        {{ $t('accounting.accounts.subtitle') }}
                    </p>

                    <div class="mt-4 overflow-hidden rounded-sm border border-stone-200 dark:border-neutral-700">
                        <table class="min-w-full divide-y divide-stone-200 dark:divide-neutral-700">
                            <thead class="bg-stone-100 text-left text-[11px] font-semibold uppercase tracking-[0.16em] text-stone-500 dark:bg-neutral-800 dark:text-neutral-400">
                                <tr>
                                    <th class="px-4 py-3">{{ $t('accounting.accounts.columns.code') }}</th>
                                    <th class="px-4 py-3">{{ $t('accounting.accounts.columns.name') }}</th>
                                    <th class="px-4 py-3">{{ $t('accounting.accounts.columns.type') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-stone-200 text-sm dark:divide-neutral-800">
                                <tr
                                    v-for="account in system_accounts"
                                    :key="account.key"
                                    class="odd:bg-white even:bg-stone-50 dark:odd:bg-neutral-900 dark:even:bg-neutral-950"
                                >
                                    <td class="px-4 py-3 font-semibold text-stone-900 dark:text-neutral-100">
                                        {{ account.code }}
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="font-medium text-stone-900 dark:text-neutral-100">
                                            {{ account.name }}
                                        </div>
                                        <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                            {{ account.description }}
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 text-stone-600 dark:text-neutral-300">
                                        {{ account.type }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <h2 class="text-sm font-semibold text-stone-900 dark:text-neutral-100">
                        {{ $t('accounting.mappings.title') }}
                    </h2>
                    <p class="mt-1 text-sm text-stone-600 dark:text-neutral-400">
                        {{ $t('accounting.mappings.subtitle') }}
                    </p>

                    <div class="mt-4 space-y-3">
                        <article
                            v-for="mapping in mapping_conventions"
                            :key="`${mapping.source_domain}-${mapping.source_key}`"
                            class="rounded-sm border border-stone-200 bg-stone-50 p-4 dark:border-neutral-700 dark:bg-neutral-950"
                        >
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="rounded-full bg-stone-900 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.14em] text-white dark:bg-neutral-100 dark:text-neutral-900">
                                    {{ mapping.source_domain }}
                                </span>
                                <span class="rounded-full border border-stone-200 px-2.5 py-1 text-[11px] font-medium uppercase tracking-[0.14em] text-stone-600 dark:border-neutral-700 dark:text-neutral-300">
                                    {{ mapping.source_key }}
                                </span>
                            </div>
                            <p class="mt-3 text-sm text-stone-700 dark:text-neutral-200">
                                {{ mapping.description }}
                            </p>
                            <div class="mt-3 grid gap-2 text-xs text-stone-500 dark:text-neutral-400">
                                <div>
                                    <span class="font-semibold text-stone-700 dark:text-neutral-200">{{ $t('accounting.mappings.debit') }}:</span>
                                    {{ mapping.debit_account_label || mapping.debit_account_key || '-' }}
                                </div>
                                <div>
                                    <span class="font-semibold text-stone-700 dark:text-neutral-200">{{ $t('accounting.mappings.credit') }}:</span>
                                    {{ mapping.credit_account_label || mapping.credit_account_key || '-' }}
                                </div>
                                <div>
                                    <span class="font-semibold text-stone-700 dark:text-neutral-200">{{ $t('accounting.mappings.tax') }}:</span>
                                    {{ mapping.tax_account_label || mapping.tax_account_key || t('accounting.mappings.no_tax') }}
                                </div>
                            </div>
                        </article>
                    </div>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
