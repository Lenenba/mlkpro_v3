<script setup>
import { computed, ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AdminDataTable from '@/Components/DataTable/AdminDataTable.vue';
import AdminDataTableActions from '@/Components/DataTable/AdminDataTableActions.vue';
import AdminDataTableToolbar from '@/Components/DataTable/AdminDataTableToolbar.vue';
import DatePicker from '@/Components/DatePicker.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import FloatingTextarea from '@/Components/FloatingTextarea.vue';
import Modal from '@/Components/UI/Modal.vue';
import { humanizeDate } from '@/utils/date';
import { useCurrencyFormatter } from '@/utils/currency';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    pettyCash: {
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

const { t } = useI18n();
const preferredCurrency = computed(() => props.pettyCash?.account?.currency_code || props.tenantCurrencyCode);
const { formatCurrency } = useCurrencyFormatter(preferredCurrency);
const busy = ref(false);
const receiptInput = ref(null);
const showMovementFilters = ref(false);
const filterForm = ref({
    petty_type: props.pettyCash?.filters?.petty_type || '',
    petty_status: props.pettyCash?.filters?.petty_status || '',
    petty_responsible_user_id: props.pettyCash?.filters?.petty_responsible_user_id || '',
    petty_from: props.pettyCash?.filters?.petty_from || '',
    petty_to: props.pettyCash?.filters?.petty_to || '',
});

const today = () => new Date().toISOString().slice(0, 10);
const defaultResponsibleId = computed(() => props.pettyCash?.account?.responsible_user_id
    || props.pettyCash?.responsibleOptions?.[0]?.id
    || '');

const movementForm = useForm({
    type: 'funding',
    status: props.pettyCash?.canPost ? 'posted' : 'draft',
    amount: '',
    movement_date: today(),
    responsible_user_id: defaultResponsibleId.value,
    team_member_id: '',
    expense_id: '',
    note: '',
    requires_receipt: false,
    receipt_attached: false,
    receipt: null,
});
const settingsForm = useForm({
    responsible_user_id: defaultResponsibleId.value,
    low_balance_threshold: props.pettyCash?.account?.low_balance_threshold ?? 0,
    receipt_required_above: props.pettyCash?.account?.receipt_required_above ?? 0,
});
const closureForm = useForm({
    period_start: props.pettyCash?.stats?.period_start || '',
    period_end: props.pettyCash?.stats?.period_end || '',
    counted_balance: props.pettyCash?.account?.current_balance ?? 0,
    status: 'closed',
    comment: '',
});

const account = computed(() => props.pettyCash?.account || {});
const stats = computed(() => props.pettyCash?.stats || {});
const reconciliation = computed(() => props.pettyCash?.reconciliation || {});
const movements = computed(() => Array.isArray(props.pettyCash?.movements) ? props.pettyCash.movements : []);
const movementLinks = computed(() => Array.isArray(props.pettyCash?.movementLinks) ? props.pettyCash.movementLinks : []);
const movementCount = computed(() => Number(props.pettyCash?.movementCount ?? movements.value.length));
const closures = computed(() => Array.isArray(props.pettyCash?.closures) ? props.pettyCash.closures : []);
const canCreate = computed(() => Boolean(props.pettyCash?.canCreate));
const canPost = computed(() => Boolean(props.pettyCash?.canPost));
const canManage = computed(() => Boolean(props.pettyCash?.canManage));
const canClose = computed(() => Boolean(props.pettyCash?.canClose));
const hasLowBalance = computed(() => Boolean(stats.value.low_balance));
const periodLabel = computed(() => {
    if (!stats.value.period_start || !stats.value.period_end) {
        return '';
    }

    return `${humanizeDate(stats.value.period_start)} - ${humanizeDate(stats.value.period_end)}`;
});
const movementResultsLabel = computed(() => `${formatNumber(movementCount.value)} ${t('expenses.petty_cash.table.results')}`);

const typeOptions = computed(() => (props.pettyCash?.types || []).map((type) => ({
    value: type,
    label: t(`expenses.petty_cash.types.${type}`),
})));
const statusOptions = computed(() => (props.pettyCash?.statuses || []).map((status) => ({
    value: status,
    label: t(`expenses.petty_cash.status.${status}`),
})));
const formStatusOptions = computed(() => statusOptions.value.filter((status) => status.value !== 'voided'));
const closureStatusOptions = computed(() => (props.pettyCash?.closureStatuses || [])
    .filter((status) => ['in_review', 'closed'].includes(status))
    .map((status) => ({
        value: status,
        label: t(`expenses.petty_cash.closure_status.${status}`),
    })));
const responsibleOptions = computed(() => (props.pettyCash?.responsibleOptions || []).map((item) => ({
    value: item.id,
    label: item.name,
})));
const teamMemberOptions = computed(() => (props.pettyCash?.teamMemberOptions || []).map((item) => ({
    value: item.id,
    label: item.name,
})));
const expenseOptions = computed(() => (props.pettyCash?.expenseOptions || []).map((item) => ({
    value: item.id,
    label: item.name,
})));

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

const reloadPettyCash = (overrides = {}) => {
    busy.value = true;
    router.get(route('expense.index'), cleanPayload({
        ...currentQuery(),
        ...overrides,
    }), {
        only: ['pettyCash', 'filters'],
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onFinish: () => {
            busy.value = false;
        },
    });
};

watch(() => props.pettyCash?.filters, (filters) => {
    filterForm.value = {
        petty_type: filters?.petty_type || '',
        petty_status: filters?.petty_status || '',
        petty_responsible_user_id: filters?.petty_responsible_user_id || '',
        petty_from: filters?.petty_from || '',
        petty_to: filters?.petty_to || '',
    };
});

watch(() => defaultResponsibleId.value, (value) => {
    if (!movementForm.responsible_user_id && value) {
        movementForm.responsible_user_id = value;
    }
});

watch(() => movementForm.type, (type) => {
    movementForm.requires_receipt = ['expense', 'advance'].includes(type);
});

const applyFilters = () => {
    reloadPettyCash({
        ...filterForm.value,
        petty_page: null,
    });
};

const clearFilters = () => {
    filterForm.value = {
        petty_type: '',
        petty_status: '',
        petty_responsible_user_id: '',
        petty_from: '',
        petty_to: '',
    };
    reloadPettyCash({
        petty_type: null,
        petty_status: null,
        petty_responsible_user_id: null,
        petty_from: null,
        petty_to: null,
        petty_page: null,
    });
};

const resetMovementForm = () => {
    movementForm.reset();
    movementForm.type = 'funding';
    movementForm.status = canPost.value ? 'posted' : 'draft';
    movementForm.amount = '';
    movementForm.movement_date = today();
    movementForm.responsible_user_id = defaultResponsibleId.value;
    movementForm.team_member_id = '';
    movementForm.expense_id = '';
    movementForm.note = '';
    movementForm.requires_receipt = false;
    movementForm.receipt_attached = false;
    movementForm.receipt = null;
    movementForm.clearErrors();

    if (receiptInput.value) {
        receiptInput.value.value = '';
    }
};

const resetSettingsForm = () => {
    settingsForm.responsible_user_id = account.value.responsible_user_id || defaultResponsibleId.value;
    settingsForm.low_balance_threshold = account.value.low_balance_threshold ?? 0;
    settingsForm.receipt_required_above = account.value.receipt_required_above ?? 0;
    settingsForm.clearErrors();
};

const resetClosureForm = () => {
    closureForm.period_start = stats.value.period_start || filterForm.value.petty_from || today();
    closureForm.period_end = stats.value.period_end || filterForm.value.petty_to || today();
    closureForm.counted_balance = account.value.current_balance ?? 0;
    closureForm.status = 'closed';
    closureForm.comment = '';
    closureForm.clearErrors();
};

const closeMovementModal = () => {
    if (window.HSOverlay) {
        window.HSOverlay.close('#hs-expense-petty-cash-movement');
    }
};

const closeSettingsModal = () => {
    if (window.HSOverlay) {
        window.HSOverlay.close('#hs-expense-petty-cash-settings');
    }
};

const closeClosureModal = () => {
    if (window.HSOverlay) {
        window.HSOverlay.close('#hs-expense-petty-cash-closure');
    }
};

const handleReceipt = (event) => {
    movementForm.receipt = event.target.files?.[0] || null;
};

const submitMovement = () => {
    movementForm.post(route('expense.petty-cash.movements.store'), {
        forceFormData: true,
        only: ['pettyCash', 'filters'],
        preserveScroll: true,
        onSuccess: () => {
            closeMovementModal();
            resetMovementForm();
        },
    });
};

const submitSettings = () => {
    settingsForm.patch(route('expense.petty-cash.account.update'), {
        only: ['pettyCash', 'filters'],
        preserveScroll: true,
        onSuccess: () => {
            closeSettingsModal();
        },
    });
};

const submitClosure = () => {
    closureForm.post(route('expense.petty-cash.closures.store'), {
        only: ['pettyCash', 'filters'],
        preserveScroll: true,
        onSuccess: () => {
            closeClosureModal();
            resetClosureForm();
        },
    });
};

const postMovement = (movement) => {
    busy.value = true;
    router.patch(route('expense.petty-cash.movements.post', movement.id), {}, {
        only: ['pettyCash', 'filters'],
        preserveScroll: true,
        onFinish: () => {
            busy.value = false;
        },
    });
};

const voidMovement = (movement) => {
    const reason = window.prompt(t('expenses.petty_cash.void_prompt'));

    if (!reason) {
        return;
    }

    busy.value = true;
    router.patch(route('expense.petty-cash.movements.void', movement.id), {
        void_reason: reason,
    }, {
        only: ['pettyCash', 'filters'],
        preserveScroll: true,
        onFinish: () => {
            busy.value = false;
        },
    });
};

const closeClosure = (closure) => {
    busy.value = true;
    router.patch(route('expense.petty-cash.closures.close', closure.id), {}, {
        only: ['pettyCash', 'filters'],
        preserveScroll: true,
        onFinish: () => {
            busy.value = false;
        },
    });
};

const reopenClosure = (closure) => {
    const comment = window.prompt(t('expenses.petty_cash.reopen_prompt'));

    if (!comment) {
        return;
    }

    busy.value = true;
    router.patch(route('expense.petty-cash.closures.reopen', closure.id), {
        comment,
    }, {
        only: ['pettyCash', 'filters'],
        preserveScroll: true,
        onFinish: () => {
            busy.value = false;
        },
    });
};

const exportPettyCash = () => {
    const url = new URL(route('expense.petty-cash.export'), window.location.origin);
    Object.entries(cleanPayload({ ...filterForm.value })).forEach(([key, value]) => {
        url.searchParams.set(key, value);
    });
    window.location.href = url.toString();
};

const formatNumber = (value) =>
    Number(value || 0).toLocaleString(undefined, { maximumFractionDigits: 0 });

const formatSignedCurrency = (value, currencyCode = account.value.currency_code) => {
    const amount = Number(value || 0);
    const prefix = amount > 0 ? '+' : '';

    return `${prefix}${formatCurrency(amount, currencyCode)}`;
};

const formatDelta = (movement) => {
    const delta = Number(movement.balance_delta || 0);
    const prefix = delta > 0 ? '+' : '';

    return `${prefix}${formatCurrency(delta, movement.currency_code)}`;
};

const deltaClass = (movement) => {
    const delta = Number(movement.balance_delta || 0);

    if (movement.status === 'voided') {
        return 'text-stone-400 line-through dark:text-neutral-500';
    }

    return delta >= 0
        ? 'text-emerald-700 dark:text-emerald-300'
        : 'text-rose-700 dark:text-rose-300';
};

const statusClass = (status) => {
    switch (status) {
        case 'posted':
            return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300';
        case 'voided':
            return 'bg-stone-100 text-stone-500 dark:bg-neutral-700 dark:text-neutral-400';
        default:
            return 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300';
    }
};

const closureStatusClass = (status) => {
    switch (status) {
        case 'closed':
            return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300';
        case 'reopened':
            return 'bg-sky-100 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300';
        case 'in_review':
            return 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300';
        default:
            return 'bg-stone-100 text-stone-600 dark:bg-neutral-700 dark:text-neutral-300';
    }
};

const typeClass = (type) => {
    switch (type) {
        case 'funding':
        case 'reimbursement':
            return 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300';
        case 'expense':
        case 'advance':
            return 'border-rose-200 bg-rose-50 text-rose-700 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-300';
        default:
            return 'border-sky-200 bg-sky-50 text-sky-700 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-300';
    }
};

const canRunAction = (movement, action) => Array.isArray(movement.available_actions)
    && movement.available_actions.includes(action);
</script>

<template>
    <section class="rounded-sm border border-stone-200 border-t-4 border-t-emerald-600 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <div class="text-xs font-semibold uppercase tracking-[0.18em] text-emerald-700 dark:text-emerald-400">
                    {{ $t('expenses.petty_cash.eyebrow') }}
                </div>
                <h2 class="mt-1 text-lg font-semibold text-stone-900 dark:text-neutral-100">
                    {{ $t('expenses.petty_cash.title') }}
                </h2>
                <p class="mt-1 max-w-3xl text-sm text-stone-500 dark:text-neutral-400">
                    {{ $t('expenses.petty_cash.description') }}
                </p>
            </div>
        </div>

        <div class="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-6">
            <div class="rounded-sm border border-t-4 border-stone-200 border-t-emerald-600 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="text-xs font-medium uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                    {{ $t('expenses.petty_cash.kpis.balance') }}
                </div>
                <div class="mt-2 text-xl font-semibold text-stone-900 dark:text-neutral-100">
                    {{ formatCurrency(account.current_balance, account.currency_code) }}
                </div>
                <div v-if="hasLowBalance" class="mt-2 text-xs font-medium text-amber-700 dark:text-amber-300">
                    {{ $t('expenses.petty_cash.low_balance') }}
                </div>
            </div>
            <div class="rounded-sm border border-t-4 border-stone-200 border-t-sky-600 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="text-xs font-medium uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                    {{ $t('expenses.petty_cash.kpis.inflows') }}
                </div>
                <div class="mt-2 text-lg font-semibold text-stone-900 dark:text-neutral-100">
                    {{ formatCurrency(stats.period_inflows, account.currency_code) }}
                </div>
            </div>
            <div class="rounded-sm border border-t-4 border-stone-200 border-t-rose-500 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="text-xs font-medium uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                    {{ $t('expenses.petty_cash.kpis.outflows') }}
                </div>
                <div class="mt-2 text-lg font-semibold text-stone-900 dark:text-neutral-100">
                    {{ formatCurrency(stats.period_outflows, account.currency_code) }}
                </div>
            </div>
            <div class="rounded-sm border border-t-4 border-stone-200 border-t-amber-500 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="text-xs font-medium uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                    {{ $t('expenses.petty_cash.kpis.drafts') }}
                </div>
                <div class="mt-2 text-lg font-semibold text-stone-900 dark:text-neutral-100">
                    {{ formatNumber(stats.draft_count) }}
                </div>
            </div>
            <div class="rounded-sm border border-t-4 border-stone-200 border-t-orange-500 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="text-xs font-medium uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                    {{ $t('expenses.petty_cash.kpis.missing_receipts') }}
                </div>
                <div class="mt-2 text-lg font-semibold text-stone-900 dark:text-neutral-100">
                    {{ formatNumber(stats.missing_receipt_count) }}
                </div>
            </div>
            <div class="rounded-sm border border-t-4 border-stone-200 border-t-violet-500 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="text-xs font-medium uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                    {{ $t('expenses.petty_cash.kpis.unlinked') }}
                </div>
                <div class="mt-2 text-lg font-semibold text-stone-900 dark:text-neutral-100">
                    {{ formatNumber(stats.unlinked_expense_count) }}
                </div>
            </div>
        </div>

        <div class="mt-4 grid gap-3 lg:grid-cols-[1.1fr,0.9fr]">
            <div class="rounded-sm border border-stone-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h3 class="text-sm font-semibold text-stone-900 dark:text-neutral-100">
                            {{ $t('expenses.petty_cash.reconciliation.title') }}
                        </h3>
                        <p class="mt-0.5 text-xs text-stone-500 dark:text-neutral-500">
                            {{ $t('expenses.petty_cash.reconciliation.period', { period: periodLabel }) }}
                        </p>
                    </div>
                    <span
                        v-if="reconciliation.closure"
                        class="inline-flex rounded-full px-2 py-0.5 text-[11px] font-medium"
                        :class="closureStatusClass(reconciliation.closure.status)"
                    >
                        {{ $t(`expenses.petty_cash.closure_status.${reconciliation.closure.status}`) }}
                    </span>
                </div>
                <div class="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <div>
                        <div class="text-xs text-stone-500 dark:text-neutral-500">
                            {{ $t('expenses.petty_cash.reconciliation.expected') }}
                        </div>
                        <div class="mt-1 text-sm font-semibold text-stone-900 dark:text-neutral-100">
                            {{ formatCurrency(reconciliation.expected_balance, account.currency_code) }}
                        </div>
                    </div>
                    <div>
                        <div class="text-xs text-stone-500 dark:text-neutral-500">
                            {{ $t('expenses.petty_cash.reconciliation.counted') }}
                        </div>
                        <div class="mt-1 text-sm font-semibold text-stone-900 dark:text-neutral-100">
                            {{ reconciliation.counted_balance === null || reconciliation.counted_balance === undefined ? '-' : formatCurrency(reconciliation.counted_balance, account.currency_code) }}
                        </div>
                    </div>
                    <div>
                        <div class="text-xs text-stone-500 dark:text-neutral-500">
                            {{ $t('expenses.petty_cash.reconciliation.difference') }}
                        </div>
                        <div
                            class="mt-1 text-sm font-semibold"
                            :class="Number(reconciliation.difference || 0) === 0 ? 'text-stone-900 dark:text-neutral-100' : 'text-amber-700 dark:text-amber-300'"
                        >
                            {{ reconciliation.difference === null || reconciliation.difference === undefined ? '-' : formatSignedCurrency(reconciliation.difference, account.currency_code) }}
                        </div>
                    </div>
                    <div>
                        <div class="text-xs text-stone-500 dark:text-neutral-500">
                            {{ $t('expenses.petty_cash.reconciliation.justified') }}
                        </div>
                        <div class="mt-1 text-sm font-semibold text-stone-900 dark:text-neutral-100">
                            {{ formatNumber(reconciliation.justified_count) }} / {{ formatNumber(reconciliation.movement_count) }}
                        </div>
                    </div>
                </div>
                <div class="mt-3 flex flex-wrap gap-2 text-xs">
                    <span class="rounded-sm bg-amber-50 px-2 py-1 font-medium text-amber-700 dark:bg-amber-500/10 dark:text-amber-300">
                        {{ $t('expenses.petty_cash.reconciliation.missing_receipts', { count: formatNumber(reconciliation.missing_receipt_count) }) }}
                    </span>
                    <span class="rounded-sm bg-violet-50 px-2 py-1 font-medium text-violet-700 dark:bg-violet-500/10 dark:text-violet-300">
                        {{ $t('expenses.petty_cash.reconciliation.unlinked', { count: formatNumber(reconciliation.unlinked_expense_count) }) }}
                    </span>
                </div>
            </div>

            <div class="rounded-sm border border-stone-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900">
                <h3 class="text-sm font-semibold text-stone-900 dark:text-neutral-100">
                    {{ $t('expenses.petty_cash.closures.title') }}
                </h3>
                <div v-if="closures.length" class="mt-3 space-y-2">
                    <div
                        v-for="closure in closures"
                        :key="closure.id"
                        class="rounded-sm border border-stone-200 px-3 py-2 dark:border-neutral-700"
                    >
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <div>
                                <div class="text-sm font-medium text-stone-900 dark:text-neutral-100">
                                    {{ humanizeDate(closure.period_start) }} - {{ humanizeDate(closure.period_end) }}
                                </div>
                                <div class="mt-0.5 text-xs text-stone-500 dark:text-neutral-500">
                                    {{ $t('expenses.petty_cash.reconciliation.difference') }}:
                                    <span :class="Number(closure.difference || 0) === 0 ? '' : 'font-semibold text-amber-700 dark:text-amber-300'">
                                        {{ formatSignedCurrency(closure.difference, account.currency_code) }}
                                    </span>
                                </div>
                            </div>
                            <div class="flex flex-wrap justify-end gap-2">
                                <span
                                    class="inline-flex rounded-full px-2 py-0.5 text-[11px] font-medium"
                                    :class="closureStatusClass(closure.status)"
                                >
                                    {{ $t(`expenses.petty_cash.closure_status.${closure.status}`) }}
                                </span>
                                <button
                                    v-if="canClose && closure.status === 'in_review'"
                                    type="button"
                                    class="inline-flex items-center rounded-sm border border-emerald-200 bg-emerald-50 px-2 py-1 text-xs font-medium text-emerald-700 hover:bg-emerald-100 disabled:opacity-50 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300"
                                    :disabled="busy"
                                    @click="closeClosure(closure)"
                                >
                                    {{ $t('expenses.petty_cash.actions.close') }}
                                </button>
                                <button
                                    v-if="canClose && closure.status === 'closed'"
                                    type="button"
                                    class="inline-flex items-center rounded-sm border border-stone-200 bg-white px-2 py-1 text-xs font-medium text-stone-700 hover:bg-stone-50 disabled:opacity-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-700"
                                    :disabled="busy"
                                    @click="reopenClosure(closure)"
                                >
                                    {{ $t('expenses.petty_cash.actions.reopen') }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <div v-else class="mt-3 rounded-sm border border-dashed border-stone-200 px-3 py-6 text-center text-sm text-stone-500 dark:border-neutral-700 dark:text-neutral-500">
                    {{ $t('expenses.petty_cash.closures.empty') }}
                </div>
            </div>
        </div>

        <AdminDataTable
            class="mt-4"
            embedded
            dense
            :rows="movements"
            :links="movementLinks"
            :loading="busy"
            :show-pagination="true"
            :result-label="movementResultsLabel"
        >
            <template #toolbar>
                <AdminDataTableToolbar
                    :show-filters="showMovementFilters"
                    :busy="busy"
                    :filters-label="$t('expenses.petty_cash.actions.filter')"
                    :clear-label="$t('expenses.petty_cash.actions.clear')"
                    :apply-label="$t('expenses.recap.actions.apply')"
                    @toggle-filters="showMovementFilters = !showMovementFilters"
                    @apply="applyFilters"
                    @clear="clearFilters"
                >
                    <template #search>
                        <div class="text-xs text-stone-500 dark:text-neutral-500">
                            {{ periodLabel }}
                        </div>
                    </template>

                    <template #filters>
                        <FloatingSelect
                            v-model="filterForm.petty_type"
                            :label="$t('expenses.petty_cash.filters.type')"
                            :placeholder="$t('expenses.petty_cash.filters.type')"
                            :options="typeOptions"
                            dense
                        />
                        <FloatingSelect
                            v-model="filterForm.petty_status"
                            :label="$t('expenses.petty_cash.filters.status')"
                            :placeholder="$t('expenses.petty_cash.filters.status')"
                            :options="statusOptions"
                            dense
                        />
                        <FloatingSelect
                            v-model="filterForm.petty_responsible_user_id"
                            :label="$t('expenses.petty_cash.filters.responsible')"
                            :placeholder="$t('expenses.petty_cash.filters.responsible')"
                            :options="responsibleOptions"
                            dense
                        />
                        <DatePicker v-model="filterForm.petty_from" :label="$t('expenses.petty_cash.filters.from')" />
                        <DatePicker v-model="filterForm.petty_to" :label="$t('expenses.petty_cash.filters.to')" />
                    </template>

                    <template #actions>
                        <button
                            type="button"
                            class="inline-flex items-center justify-center rounded-sm border border-stone-200 bg-white px-2.5 py-2 text-xs font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                            @click="exportPettyCash"
                        >
                            {{ $t('expenses.petty_cash.actions.export') }}
                        </button>
                        <button
                            v-if="canManage"
                            type="button"
                            data-hs-overlay="#hs-expense-petty-cash-settings"
                            class="inline-flex items-center justify-center rounded-sm border border-stone-200 bg-white px-2.5 py-2 text-xs font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                            @click="resetSettingsForm"
                        >
                            {{ $t('expenses.petty_cash.actions.settings') }}
                        </button>
                        <button
                            v-if="canClose"
                            type="button"
                            data-hs-overlay="#hs-expense-petty-cash-closure"
                            class="inline-flex items-center justify-center rounded-sm border border-emerald-200 bg-emerald-50 px-2.5 py-2 text-xs font-medium text-emerald-700 hover:bg-emerald-100 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300"
                            @click="resetClosureForm"
                        >
                            {{ $t('expenses.petty_cash.actions.close_period') }}
                        </button>
                        <button
                            v-if="canCreate"
                            type="button"
                            data-hs-overlay="#hs-expense-petty-cash-movement"
                            class="inline-flex items-center justify-center rounded-sm border border-transparent bg-emerald-600 px-2.5 py-2 text-xs font-medium text-white hover:bg-emerald-700"
                            @click="resetMovementForm"
                        >
                            {{ $t('expenses.petty_cash.actions.add') }}
                        </button>
                    </template>
                </AdminDataTableToolbar>
            </template>

            <template #empty>
                <div class="rounded-sm border border-dashed border-stone-200 px-5 py-10 text-center text-sm text-stone-500 dark:border-neutral-700 dark:text-neutral-500">
                    {{ $t('expenses.petty_cash.empty') }}
                </div>
            </template>

            <template #head>
                <tr>
                    <th scope="col" class="min-w-[280px]">
                        <div class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                            {{ $t('expenses.petty_cash.table.movement') }}
                        </div>
                    </th>
                    <th scope="col" class="min-w-[180px]">
                        <div class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                            {{ $t('expenses.petty_cash.table.responsible') }}
                        </div>
                    </th>
                    <th scope="col" class="min-w-[140px]">
                        <div class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                            {{ $t('expenses.petty_cash.table.receipt') }}
                        </div>
                    </th>
                    <th scope="col" class="min-w-[140px]">
                        <div class="px-5 py-2.5 text-end text-sm font-normal text-stone-500 dark:text-neutral-500">
                            {{ $t('expenses.petty_cash.table.amount') }}
                        </div>
                    </th>
                    <th scope="col" class="min-w-[60px]"></th>
                </tr>
            </template>

            <template #body="{ rows }">
                <tbody class="divide-y divide-stone-200 dark:divide-neutral-700">
                    <tr v-for="movement in rows" :key="movement.id">
                        <td class="px-5 py-3 align-top">
                            <div class="flex flex-wrap items-center gap-2">
                                <span
                                    class="inline-flex rounded-full border px-2 py-0.5 text-[11px] font-medium"
                                    :class="typeClass(movement.type)"
                                >
                                    {{ $t(`expenses.petty_cash.types.${movement.type}`) }}
                                </span>
                                <span
                                    class="inline-flex rounded-full px-2 py-0.5 text-[11px] font-medium"
                                    :class="statusClass(movement.status)"
                                >
                                    {{ $t(`expenses.petty_cash.status.${movement.status}`) }}
                                </span>
                                <span
                                    v-if="movement.locked_by_closure"
                                    class="inline-flex rounded-full bg-stone-100 px-2 py-0.5 text-[11px] font-medium text-stone-600 dark:bg-neutral-700 dark:text-neutral-300"
                                >
                                    {{ $t('expenses.petty_cash.locked') }}
                                </span>
                            </div>
                            <div class="mt-1 text-sm font-medium text-stone-900 dark:text-neutral-100">
                                {{ humanizeDate(movement.movement_date) }}
                            </div>
                            <div v-if="movement.note" class="mt-0.5 max-w-xl whitespace-normal text-xs text-stone-500 dark:text-neutral-500">
                                {{ movement.note }}
                            </div>
                            <div v-if="movement.expense" class="mt-1 text-xs text-stone-500 dark:text-neutral-500">
                                {{ $t('expenses.petty_cash.linked_expense', { name: movement.expense.title }) }}
                            </div>
                        </td>
                        <td class="px-5 py-3 align-top text-sm text-stone-700 dark:text-neutral-300">
                            {{ movement.responsible?.name || $t('expenses.labels.not_set') }}
                            <div v-if="movement.team_member" class="text-xs text-stone-500 dark:text-neutral-500">
                                {{ movement.team_member.name }}
                            </div>
                        </td>
                        <td class="px-5 py-3 align-top">
                            <span
                                class="inline-flex rounded-full px-2 py-0.5 text-[11px] font-medium"
                                :class="movement.requires_receipt && !movement.receipt_attached
                                    ? 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300'
                                    : 'bg-stone-100 text-stone-600 dark:bg-neutral-700 dark:text-neutral-300'"
                            >
                                {{ movement.receipt_attached ? $t('expenses.petty_cash.receipt_ok') : $t('expenses.petty_cash.receipt_missing') }}
                            </span>
                        </td>
                        <td class="px-5 py-3 text-right align-top text-sm font-semibold" :class="deltaClass(movement)">
                            {{ formatDelta(movement) }}
                        </td>
                        <td class="px-5 py-3 text-right align-top">
                            <AdminDataTableActions
                                v-if="movement.available_actions?.length"
                                :label="$t('expenses.aria.dropdown')"
                            >
                                <button
                                    v-if="canRunAction(movement, 'post')"
                                    type="button"
                                    class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-start text-[13px] text-emerald-700 hover:bg-emerald-50 dark:text-emerald-300 dark:hover:bg-neutral-800"
                                    :disabled="busy"
                                    @click="postMovement(movement)"
                                >
                                    {{ $t('expenses.petty_cash.actions.post') }}
                                </button>
                                <button
                                    v-if="canRunAction(movement, 'void')"
                                    type="button"
                                    class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-start text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800"
                                    :disabled="busy"
                                    @click="voidMovement(movement)"
                                >
                                    {{ $t('expenses.petty_cash.actions.void') }}
                                </button>
                            </AdminDataTableActions>
                        </td>
                    </tr>
                </tbody>
            </template>
        </AdminDataTable>

        <Modal :title="$t('expenses.petty_cash.modal_title')" id="hs-expense-petty-cash-movement">
            <form class="space-y-4" @submit.prevent="submitMovement">
                <div class="grid gap-3 md:grid-cols-2">
                    <FloatingSelect
                        v-model="movementForm.type"
                        :label="$t('expenses.petty_cash.form.type')"
                        :options="typeOptions"
                        required
                    />
                    <FloatingSelect
                        v-model="movementForm.status"
                        :label="$t('expenses.petty_cash.form.status')"
                        :options="formStatusOptions"
                        :disabled="!canPost"
                        required
                    />
                    <FloatingInput
                        v-model="movementForm.amount"
                        type="number"
                        step="0.01"
                        :label="$t('expenses.petty_cash.form.amount')"
                        required
                    />
                    <DatePicker v-model="movementForm.movement_date" :label="$t('expenses.petty_cash.form.date')" required />
                    <FloatingSelect
                        v-model="movementForm.responsible_user_id"
                        :label="$t('expenses.petty_cash.form.responsible')"
                        :options="responsibleOptions"
                        required
                    />
                    <FloatingSelect
                        v-model="movementForm.team_member_id"
                        :label="$t('expenses.petty_cash.form.team_member')"
                        :placeholder="$t('expenses.petty_cash.form.team_member')"
                        :options="teamMemberOptions"
                    />
                    <FloatingSelect
                        v-model="movementForm.expense_id"
                        :label="$t('expenses.petty_cash.form.expense')"
                        :placeholder="$t('expenses.petty_cash.form.expense')"
                        :options="expenseOptions"
                        filterable
                    />
                </div>

                <FloatingTextarea
                    v-model="movementForm.note"
                    :label="$t('expenses.petty_cash.form.note')"
                />

                <div class="grid gap-3 md:grid-cols-[1fr,1fr,2fr]">
                    <label class="flex items-center gap-2 rounded-sm border border-stone-200 bg-white px-3 py-3 text-sm text-stone-700 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200">
                        <input
                            v-model="movementForm.requires_receipt"
                            type="checkbox"
                            class="rounded border-stone-300 text-emerald-600 focus:ring-emerald-600 dark:border-neutral-700 dark:bg-neutral-900"
                        >
                        <span>{{ $t('expenses.petty_cash.form.requires_receipt') }}</span>
                    </label>
                    <label class="flex items-center gap-2 rounded-sm border border-stone-200 bg-white px-3 py-3 text-sm text-stone-700 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200">
                        <input
                            v-model="movementForm.receipt_attached"
                            type="checkbox"
                            class="rounded border-stone-300 text-emerald-600 focus:ring-emerald-600 dark:border-neutral-700 dark:bg-neutral-900"
                        >
                        <span>{{ $t('expenses.petty_cash.form.receipt_attached') }}</span>
                    </label>
                    <input
                        ref="receiptInput"
                        type="file"
                        accept=".pdf,.png,.jpg,.jpeg,.webp"
                        class="block w-full rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm text-stone-700 file:me-3 file:rounded-sm file:border-0 file:bg-stone-100 file:px-3 file:py-1.5 file:text-xs file:font-medium file:text-stone-700 hover:file:bg-stone-200 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300 dark:file:bg-neutral-800 dark:file:text-neutral-200"
                        @change="handleReceipt"
                    >
                </div>

                <div v-if="Object.keys(movementForm.errors).length" class="rounded-sm border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-300">
                    <div v-for="(error, key) in movementForm.errors" :key="key">
                        {{ error }}
                    </div>
                </div>

                <div class="flex justify-end gap-2">
                    <button
                        type="button"
                        class="inline-flex items-center justify-center rounded-sm border border-stone-200 bg-white px-4 py-2 text-xs font-semibold uppercase tracking-wide text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-700"
                        data-hs-overlay="#hs-expense-petty-cash-movement"
                    >
                        {{ $t('expenses.petty_cash.actions.cancel') }}
                    </button>
                    <button
                        type="submit"
                        class="inline-flex items-center justify-center rounded-sm border border-transparent bg-emerald-600 px-4 py-2 text-xs font-semibold uppercase tracking-wide text-white hover:bg-emerald-700 disabled:opacity-50"
                        :disabled="movementForm.processing"
                    >
                        {{ $t('expenses.petty_cash.actions.save') }}
                    </button>
                </div>
            </form>
        </Modal>

        <Modal :title="$t('expenses.petty_cash.settings.title')" id="hs-expense-petty-cash-settings">
            <form class="space-y-4" @submit.prevent="submitSettings">
                <FloatingSelect
                    v-model="settingsForm.responsible_user_id"
                    :label="$t('expenses.petty_cash.form.responsible')"
                    :options="responsibleOptions"
                    required
                />
                <div class="grid gap-3 md:grid-cols-2">
                    <FloatingInput
                        v-model="settingsForm.low_balance_threshold"
                        type="number"
                        step="0.01"
                        min="0"
                        :label="$t('expenses.petty_cash.settings.low_balance_threshold')"
                    />
                    <FloatingInput
                        v-model="settingsForm.receipt_required_above"
                        type="number"
                        step="0.01"
                        min="0"
                        :label="$t('expenses.petty_cash.settings.receipt_required_above')"
                    />
                </div>

                <div v-if="Object.keys(settingsForm.errors).length" class="rounded-sm border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-300">
                    <div v-for="(error, key) in settingsForm.errors" :key="key">
                        {{ error }}
                    </div>
                </div>

                <div class="flex justify-end gap-2">
                    <button
                        type="button"
                        class="inline-flex items-center justify-center rounded-sm border border-stone-200 bg-white px-4 py-2 text-xs font-semibold uppercase tracking-wide text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-700"
                        data-hs-overlay="#hs-expense-petty-cash-settings"
                    >
                        {{ $t('expenses.petty_cash.actions.cancel') }}
                    </button>
                    <button
                        type="submit"
                        class="inline-flex items-center justify-center rounded-sm border border-transparent bg-emerald-600 px-4 py-2 text-xs font-semibold uppercase tracking-wide text-white hover:bg-emerald-700 disabled:opacity-50"
                        :disabled="settingsForm.processing"
                    >
                        {{ $t('expenses.petty_cash.actions.save') }}
                    </button>
                </div>
            </form>
        </Modal>

        <Modal :title="$t('expenses.petty_cash.closures.modal_title')" id="hs-expense-petty-cash-closure">
            <form class="space-y-4" @submit.prevent="submitClosure">
                <div class="grid gap-3 md:grid-cols-2">
                    <DatePicker v-model="closureForm.period_start" :label="$t('expenses.petty_cash.closures.period_start')" required />
                    <DatePicker v-model="closureForm.period_end" :label="$t('expenses.petty_cash.closures.period_end')" required />
                    <FloatingInput
                        v-model="closureForm.counted_balance"
                        type="number"
                        step="0.01"
                        :label="$t('expenses.petty_cash.closures.counted_balance')"
                        required
                    />
                    <FloatingSelect
                        v-model="closureForm.status"
                        :label="$t('expenses.petty_cash.form.status')"
                        :options="closureStatusOptions"
                        required
                    />
                </div>

                <div class="rounded-sm border border-stone-200 bg-stone-50 px-3 py-2 text-sm text-stone-700 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300">
                    {{ $t('expenses.petty_cash.reconciliation.expected') }}:
                    <strong>{{ formatCurrency(reconciliation.expected_balance, account.currency_code) }}</strong>
                </div>

                <FloatingTextarea
                    v-model="closureForm.comment"
                    :label="$t('expenses.petty_cash.closures.comment')"
                />

                <div v-if="Object.keys(closureForm.errors).length" class="rounded-sm border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-300">
                    <div v-for="(error, key) in closureForm.errors" :key="key">
                        {{ error }}
                    </div>
                </div>

                <div class="flex justify-end gap-2">
                    <button
                        type="button"
                        class="inline-flex items-center justify-center rounded-sm border border-stone-200 bg-white px-4 py-2 text-xs font-semibold uppercase tracking-wide text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-700"
                        data-hs-overlay="#hs-expense-petty-cash-closure"
                    >
                        {{ $t('expenses.petty_cash.actions.cancel') }}
                    </button>
                    <button
                        type="submit"
                        class="inline-flex items-center justify-center rounded-sm border border-transparent bg-emerald-600 px-4 py-2 text-xs font-semibold uppercase tracking-wide text-white hover:bg-emerald-700 disabled:opacity-50"
                        :disabled="closureForm.processing"
                    >
                        {{ $t('expenses.petty_cash.actions.save') }}
                    </button>
                </div>
            </form>
        </Modal>
    </section>
</template>
