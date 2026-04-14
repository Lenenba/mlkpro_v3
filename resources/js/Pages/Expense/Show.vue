<script setup>
import { computed, ref } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Modal from '@/Components/UI/Modal.vue';
import ExpenseForm from '@/Pages/Expense/UI/ExpenseForm.vue';
import ExpenseWorkflowModal from '@/Pages/Expense/UI/ExpenseWorkflowModal.vue';
import { useCurrencyFormatter } from '@/utils/currency';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    expense: {
        type: Object,
        required: true,
    },
    categories: {
        type: Array,
        required: true,
    },
    paymentMethods: {
        type: Array,
        required: true,
    },
    statuses: {
        type: Array,
        required: true,
    },
    recurrenceFrequencies: {
        type: Array,
        default: () => [],
    },
    teamMembers: {
        type: Array,
        default: () => [],
    },
    linkOptions: {
        type: Object,
        default: () => ({
            customers: [],
            works: [],
            sales: [],
            invoices: [],
            campaigns: [],
        }),
    },
    tenantCurrencyCode: {
        type: String,
        default: 'CAD',
    },
    canEdit: {
        type: Boolean,
        default: false,
    },
});

const { t } = useI18n();
const page = usePage();
const editingExpense = ref(null);
const workflowExpense = ref(null);
const pendingWorkflowAction = ref('');
const preferredCurrency = computed(() => props.expense?.currency_code || props.tenantCurrencyCode);
const { formatCurrency } = useCurrencyFormatter(preferredCurrency);

const formatDate = (value) => {
    if (!value) {
        return '-';
    }

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return '-';
    }

    return new Intl.DateTimeFormat(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    }).format(date);
};

const categoryMap = computed(() => new Map(
    (props.categories || []).map((item) => [item.key, item.label_key])
));
const paymentMethodMap = computed(() => new Map(
    (props.paymentMethods || []).map((item) => [item.key, item.label_key])
));

const categoryLabel = computed(() => {
    const labelKey = categoryMap.value.get(props.expense?.category_key);
    return labelKey ? t(labelKey) : t('expenses.labels.uncategorized');
});

const paymentMethodLabel = computed(() => {
    const labelKey = paymentMethodMap.value.get(props.expense?.payment_method);
    return labelKey ? t(labelKey) : t('expenses.labels.not_set');
});
const recurrenceFrequencyLabel = computed(() => {
    const frequency = props.expense?.recurrence_frequency;
    return frequency ? t(`expenses.recurrence.frequency.${frequency}`) : t('expenses.labels.not_set');
});
const reimbursementStatusLabel = computed(() => {
    const status = props.expense?.reimbursement_status;
    return status ? t(`expenses.reimbursement_status.${status}`) : t('expenses.labels.not_set');
});
const workflowActions = computed(() => Array.isArray(props.expense?.available_actions) ? props.expense.available_actions : []);
const workflowHistory = computed(() => Array.isArray(props.expense?.workflow_history) ? props.expense.workflow_history : []);
const aiIntake = computed(() => (props.expense?.ai_intake && typeof props.expense.ai_intake === 'object') ? props.expense.ai_intake : null);
const duplicateDetection = computed(() => {
    const value = aiIntake.value?.duplicate_detection;
    return value && typeof value === 'object' ? value : null;
});
const customerName = (customer) => {
    if (!customer) {
        return '';
    }

    return customer.company_name || [customer.first_name, customer.last_name].filter(Boolean).join(' ').trim();
};
const workLabel = (work) => {
    if (!work) {
        return '';
    }

    return [work.number, work.job_title].filter(Boolean).join(' - ').trim();
};
const linkedContextItems = computed(() => {
    const items = [];

    const customer = customerName(props.expense?.customer);
    if (customer && props.expense?.customer?.id) {
        items.push({
            key: 'customer',
            label: t('expenses.form.customer'),
            value: customer,
            href: route('customer.show', props.expense.customer.id),
        });
    }

    const work = workLabel(props.expense?.work);
    if (work && props.expense?.work?.id) {
        items.push({
            key: 'work',
            label: t('expenses.form.work'),
            value: work,
            href: route('work.show', props.expense.work.id),
        });
    }

    if (props.expense?.sale?.number && props.expense?.sale?.id) {
        items.push({
            key: 'sale',
            label: t('expenses.form.sale'),
            value: props.expense.sale.number,
            href: route('sales.show', props.expense.sale.id),
        });
    }

    if (props.expense?.invoice?.number && props.expense?.invoice?.id) {
        items.push({
            key: 'invoice',
            label: t('expenses.form.invoice'),
            value: props.expense.invoice.number,
            href: route('invoice.show', props.expense.invoice.id),
        });
    }

    if (props.expense?.campaign?.name && props.expense?.campaign?.id) {
        items.push({
            key: 'campaign',
            label: t('expenses.form.campaign'),
            value: props.expense.campaign.name,
            href: route('campaigns.show', props.expense.campaign.id),
        });
    }

    return items;
});

const statusClass = (status) => {
    switch (status) {
        case 'paid':
        case 'reimbursed':
            return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300';
        case 'due':
        case 'submitted':
        case 'pending_approval':
            return 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300';
        case 'approved':
            return 'bg-sky-100 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300';
        case 'review_required':
        case 'rejected':
        case 'cancelled':
            return 'bg-rose-100 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300';
        default:
            return 'bg-stone-100 text-stone-700 dark:bg-neutral-700 dark:text-neutral-300';
    }
};

const openEdit = () => {
    editingExpense.value = props.expense;
};

const workflowActionLabel = (action) => t(`expenses.actions.${action}`);
const workflowActionClass = (action) => {
    const base = 'inline-flex w-full items-center justify-center rounded-sm border px-3 py-2 text-sm font-medium transition sm:w-auto';

    switch (action) {
        case 'approve':
            return `${base} border-sky-200 bg-sky-50 text-sky-700 hover:bg-sky-100 dark:border-sky-900/40 dark:bg-sky-950/40 dark:text-sky-300 dark:hover:bg-sky-950/60`;
        case 'reject':
            return `${base} border-rose-200 bg-rose-50 text-rose-700 hover:bg-rose-100 dark:border-rose-900/40 dark:bg-rose-950/40 dark:text-rose-300 dark:hover:bg-rose-950/60`;
        case 'mark_due':
            return `${base} border-amber-200 bg-amber-50 text-amber-700 hover:bg-amber-100 dark:border-amber-900/40 dark:bg-amber-950/40 dark:text-amber-300 dark:hover:bg-amber-950/60`;
        case 'mark_paid':
        case 'mark_reimbursed':
            return `${base} border-emerald-200 bg-emerald-50 text-emerald-700 hover:bg-emerald-100 dark:border-emerald-900/40 dark:bg-emerald-950/40 dark:text-emerald-300 dark:hover:bg-emerald-950/60`;
        case 'cancel':
            return `${base} border-rose-200 bg-rose-50 text-rose-700 hover:bg-rose-100 dark:border-rose-900/40 dark:bg-rose-950/40 dark:text-rose-300 dark:hover:bg-rose-950/60`;
        default:
            return `${base} border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800`;
    }
};

const formatDateTime = (value) => {
    if (!value) {
        return '-';
    }

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return '-';
    }

    return new Intl.DateTimeFormat(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    }).format(date);
};

const aiFieldStateClass = (state) => {
    switch (state) {
        case 'ok':
            return 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900/40 dark:bg-emerald-950/40 dark:text-emerald-300';
        case 'missing':
            return 'border-rose-200 bg-rose-50 text-rose-700 dark:border-rose-900/40 dark:bg-rose-950/40 dark:text-rose-300';
        default:
            return 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-900/40 dark:bg-amber-950/40 dark:text-amber-300';
    }
};

const duplicateReasonLabel = (reason) => t(`expenses.ai_scan.duplicate_reasons.${reason}`);
const canOpenFinanceApprovals = computed(() => {
    const account = page.props.auth?.account;
    const permissions = account?.team?.permissions || [];

    if (account?.is_client) {
        return false;
    }

    if (account?.is_owner) {
        return Boolean(account?.features?.expenses || account?.features?.invoices);
    }

    return permissions.includes('expenses.approve')
        || permissions.includes('expenses.approve_high')
        || permissions.includes('invoices.approve')
        || permissions.includes('invoices.approve_high');
});

const clearWorkflowSelection = () => {
    workflowExpense.value = null;
    pendingWorkflowAction.value = '';
};

const openWorkflowAction = (action) => {
    workflowExpense.value = props.expense;
    pendingWorkflowAction.value = action;

    if (window.HSOverlay) {
        window.HSOverlay.open('#hs-expense-workflow');
    }
};
</script>

<template>
    <Head :title="expense.title" />

    <AuthenticatedLayout>
        <div class="space-y-5">
            <section class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
                    <div class="space-y-2">
                        <div class="flex flex-wrap items-center gap-2">
                            <h1 class="text-2xl font-semibold text-stone-800 dark:text-neutral-100">
                                {{ expense.title }}
                            </h1>
                            <span
                                class="inline-flex rounded-full px-2 py-1 text-xs font-medium"
                                :class="statusClass(expense.status)"
                            >
                                {{ $t(`expenses.status.${expense.status}`) }}
                            </span>
                        </div>
                        <p class="text-sm text-stone-600 dark:text-neutral-400">
                            {{ expense.supplier_name || $t('expenses.labels.no_supplier') }}
                        </p>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <Link
                            v-if="canOpenFinanceApprovals"
                            :href="route('finance-approvals.index')"
                            class="inline-flex items-center rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                        >
                            {{ $t('finance_approvals.title') }}
                        </Link>
                        <Link
                            :href="route('expense.index')"
                            class="inline-flex items-center rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                        >
                            {{ $t('expenses.actions.back_to_list') }}
                        </Link>
                        <button
                            v-if="canEdit"
                            type="button"
                            data-hs-overlay="#hs-expense-edit"
                            @click="openEdit"
                            class="inline-flex items-center rounded-sm border border-transparent bg-red-600 px-3 py-2 text-sm font-medium text-white hover:bg-red-700"
                        >
                            {{ $t('expenses.actions.edit') }}
                        </button>
                    </div>
                </div>

                <div v-if="workflowActions.length" class="mt-4 border-t border-stone-200 pt-4 dark:border-neutral-800">
                    <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-500">
                        {{ $t('expenses.detail.quick_actions') }}
                    </p>

                    <div class="grid gap-2 sm:flex sm:flex-wrap">
                        <button
                            v-for="action in workflowActions"
                            :key="action"
                            type="button"
                            :class="workflowActionClass(action)"
                            @click="openWorkflowAction(action)"
                        >
                            {{ workflowActionLabel(action) }}
                        </button>
                    </div>
                </div>
            </section>

            <div class="grid gap-5 lg:grid-cols-[minmax(0,2fr)_minmax(320px,1fr)]">
                <section class="space-y-5">
                    <div class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                            {{ $t('expenses.detail.summary') }}
                        </h2>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <p class="text-xs uppercase tracking-wide text-stone-500 dark:text-neutral-500">
                                    {{ $t('expenses.table.total') }}
                                </p>
                                <p class="mt-1 text-xl font-semibold text-stone-900 dark:text-neutral-100">
                                    {{ formatCurrency(expense.total, expense.currency_code) }}
                                </p>
                            </div>
                            <div>
                                <p class="text-xs uppercase tracking-wide text-stone-500 dark:text-neutral-500">
                                    {{ $t('expenses.form.expense_date') }}
                                </p>
                                <p class="mt-1 text-sm text-stone-700 dark:text-neutral-300">
                                    {{ formatDate(expense.expense_date) }}
                                </p>
                            </div>
                            <div>
                                <p class="text-xs uppercase tracking-wide text-stone-500 dark:text-neutral-500">
                                    {{ $t('expenses.form.due_date') }}
                                </p>
                                <p class="mt-1 text-sm text-stone-700 dark:text-neutral-300">
                                    {{ formatDate(expense.due_date) }}
                                </p>
                            </div>
                            <div>
                                <p class="text-xs uppercase tracking-wide text-stone-500 dark:text-neutral-500">
                                    {{ $t('expenses.form.paid_date') }}
                                </p>
                                <p class="mt-1 text-sm text-stone-700 dark:text-neutral-300">
                                    {{ formatDate(expense.paid_date) }}
                                </p>
                            </div>
                            <div>
                                <p class="text-xs uppercase tracking-wide text-stone-500 dark:text-neutral-500">
                                    {{ $t('expenses.form.category') }}
                                </p>
                                <p class="mt-1 text-sm text-stone-700 dark:text-neutral-300">
                                    {{ categoryLabel }}
                                </p>
                            </div>
                            <div>
                                <p class="text-xs uppercase tracking-wide text-stone-500 dark:text-neutral-500">
                                    {{ $t('expenses.form.payment_method') }}
                                </p>
                                <p class="mt-1 text-sm text-stone-700 dark:text-neutral-300">
                                    {{ paymentMethodLabel }}
                                </p>
                            </div>
                            <div>
                                <p class="text-xs uppercase tracking-wide text-stone-500 dark:text-neutral-500">
                                    {{ $t('expenses.detail.reimbursement_status') }}
                                </p>
                                <p class="mt-1 text-sm text-stone-700 dark:text-neutral-300">
                                    {{ reimbursementStatusLabel }}
                                </p>
                            </div>
                            <div>
                                <p class="text-xs uppercase tracking-wide text-stone-500 dark:text-neutral-500">
                                    {{ $t('expenses.detail.next_recurrence') }}
                                </p>
                                <p class="mt-1 text-sm text-stone-700 dark:text-neutral-300">
                                    {{ expense.recurrence_next_date ? formatDate(expense.recurrence_next_date) : '-' }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                            {{ $t('expenses.detail.notes') }}
                        </h2>

                        <div class="space-y-4">
                            <div>
                                <p class="mb-1 text-xs uppercase tracking-wide text-stone-500 dark:text-neutral-500">
                                    {{ $t('expenses.form.description') }}
                                </p>
                                <p class="text-sm leading-6 text-stone-700 dark:text-neutral-300">
                                    {{ expense.description || $t('expenses.labels.none') }}
                                </p>
                            </div>
                            <div>
                                <p class="mb-1 text-xs uppercase tracking-wide text-stone-500 dark:text-neutral-500">
                                    {{ $t('expenses.form.notes') }}
                                </p>
                                <p class="text-sm leading-6 text-stone-700 dark:text-neutral-300">
                                    {{ expense.notes || $t('expenses.labels.none') }}
                                </p>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="space-y-5">
                    <div class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                            {{ $t('expenses.detail.record') }}
                        </h2>

                        <dl class="space-y-3 text-sm">
                            <div class="flex items-start justify-between gap-3">
                                <dt class="text-stone-500 dark:text-neutral-500">{{ $t('expenses.detail.creator') }}</dt>
                                <dd class="text-right text-stone-800 dark:text-neutral-200">{{ expense.creator?.name || '-' }}</dd>
                            </div>
                            <div class="flex items-start justify-between gap-3">
                                <dt class="text-stone-500 dark:text-neutral-500">{{ $t('expenses.detail.approver') }}</dt>
                                <dd class="text-right text-stone-800 dark:text-neutral-200">{{ expense.approver?.name || '-' }}</dd>
                            </div>
                            <div class="flex items-start justify-between gap-3">
                                <dt class="text-stone-500 dark:text-neutral-500">{{ $t('expenses.detail.payer') }}</dt>
                                <dd class="text-right text-stone-800 dark:text-neutral-200">{{ expense.payer?.name || '-' }}</dd>
                            </div>
                            <div class="flex items-start justify-between gap-3">
                                <dt class="text-stone-500 dark:text-neutral-500">{{ $t('expenses.form.reference_number') }}</dt>
                                <dd class="text-right text-stone-800 dark:text-neutral-200">{{ expense.reference_number || '-' }}</dd>
                            </div>
                            <div class="flex items-start justify-between gap-3">
                                <dt class="text-stone-500 dark:text-neutral-500">{{ $t('expenses.form.reimbursable') }}</dt>
                                <dd class="text-right text-stone-800 dark:text-neutral-200">{{ expense.reimbursable ? $t('expenses.labels.yes') : $t('expenses.labels.no') }}</dd>
                            </div>
                            <div class="flex items-start justify-between gap-3">
                                <dt class="text-stone-500 dark:text-neutral-500">{{ $t('expenses.form.is_recurring') }}</dt>
                                <dd class="text-right text-stone-800 dark:text-neutral-200">{{ expense.is_recurring ? $t('expenses.labels.yes') : $t('expenses.labels.no') }}</dd>
                            </div>
                            <div class="flex items-start justify-between gap-3">
                                <dt class="text-stone-500 dark:text-neutral-500">{{ $t('expenses.form.team_member') }}</dt>
                                <dd class="text-right text-stone-800 dark:text-neutral-200">{{ expense.team_member?.name || '-' }}</dd>
                            </div>
                            <div class="flex items-start justify-between gap-3">
                                <dt class="text-stone-500 dark:text-neutral-500">{{ $t('expenses.detail.reimbursement_status') }}</dt>
                                <dd class="text-right text-stone-800 dark:text-neutral-200">{{ reimbursementStatusLabel }}</dd>
                            </div>
                            <div class="flex items-start justify-between gap-3">
                                <dt class="text-stone-500 dark:text-neutral-500">{{ $t('expenses.detail.reimburser') }}</dt>
                                <dd class="text-right text-stone-800 dark:text-neutral-200">{{ expense.reimburser?.name || '-' }}</dd>
                            </div>
                            <div class="flex items-start justify-between gap-3">
                                <dt class="text-stone-500 dark:text-neutral-500">{{ $t('expenses.form.recurrence_frequency') }}</dt>
                                <dd class="text-right text-stone-800 dark:text-neutral-200">{{ expense.is_recurring ? recurrenceFrequencyLabel : '-' }}</dd>
                            </div>
                            <div class="flex items-start justify-between gap-3">
                                <dt class="text-stone-500 dark:text-neutral-500">{{ $t('expenses.detail.generated_count') }}</dt>
                                <dd class="text-right text-stone-800 dark:text-neutral-200">{{ expense.generated_recurrences_count ?? 0 }}</dd>
                            </div>
                            <div class="flex items-start justify-between gap-3">
                                <dt class="text-stone-500 dark:text-neutral-500">{{ $t('expenses.detail.recurrence_source') }}</dt>
                                <dd class="text-right text-stone-800 dark:text-neutral-200">
                                    <Link
                                        v-if="expense.recurrence_source?.id"
                                        :href="route('expense.show', expense.recurrence_source.id)"
                                        class="text-red-600 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300"
                                    >
                                        {{ expense.recurrence_source.title }}
                                    </Link>
                                    <span v-else>-</span>
                                </dd>
                            </div>
                        </dl>
                    </div>

                    <div class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                            {{ $t('expenses.detail.linked_context') }}
                        </h2>

                        <dl v-if="linkedContextItems.length" class="space-y-3 text-sm">
                            <div
                                v-for="item in linkedContextItems"
                                :key="item.key"
                                class="flex items-start justify-between gap-3"
                            >
                                <dt class="text-stone-500 dark:text-neutral-500">{{ item.label }}</dt>
                                <dd class="text-right text-stone-800 dark:text-neutral-200">
                                    <Link
                                        :href="item.href"
                                        class="text-red-600 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300"
                                    >
                                        {{ item.value }}
                                    </Link>
                                </dd>
                            </div>
                        </dl>
                        <p v-else class="text-sm text-stone-500 dark:text-neutral-500">
                            {{ $t('expenses.detail.no_linked_context') }}
                        </p>
                    </div>

                    <div class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                            {{ $t('expenses.detail.attachments') }}
                        </h2>

                        <div v-if="expense.attachments?.length" class="space-y-3">
                            <a
                                v-for="attachment in expense.attachments"
                                :key="attachment.id"
                                :href="attachment.url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="flex items-center justify-between gap-3 rounded-sm border border-stone-200 px-3 py-2 text-sm text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800"
                            >
                                <div class="min-w-0">
                                    <p class="truncate font-medium">
                                        {{ attachment.original_name || $t('expenses.attachments.file') }}
                                    </p>
                                    <p class="text-xs text-stone-500 dark:text-neutral-500">
                                        {{ attachment.mime || $t('expenses.labels.not_set') }}
                                    </p>
                                </div>
                                <span class="text-xs text-red-600 dark:text-red-400">
                                    {{ $t('expenses.actions.open') }}
                                </span>
                            </a>
                        </div>
                        <p v-else class="text-sm text-stone-500 dark:text-neutral-500">
                            {{ $t('expenses.attachments.empty') }}
                        </p>
                    </div>

                    <div class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                            {{ $t('expenses.detail.workflow_history') }}
                        </h2>

                        <div v-if="workflowHistory.length" class="space-y-3">
                            <div
                                v-for="(entry, index) in workflowHistory"
                                :key="`${entry.at || index}-${entry.action || index}`"
                                class="rounded-sm border border-stone-200 px-3 py-3 dark:border-neutral-700"
                            >
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <p class="text-sm font-medium text-stone-800 dark:text-neutral-100">
                                        {{ $t(`expenses.history.${entry.action}`, { fallback: entry.action }) }}
                                    </p>
                                    <span class="text-xs text-stone-500 dark:text-neutral-500">
                                        {{ formatDateTime(entry.at) }}
                                    </span>
                                </div>
                                <p class="mt-1 text-xs text-stone-500 dark:text-neutral-500">
                                    {{ entry.actor_name || '-' }}
                                </p>
                                <p v-if="entry.to_status" class="mt-2 text-xs text-stone-600 dark:text-neutral-400">
                                    {{ $t('expenses.detail.status_change', {
                                        from: entry.from_status ? $t(`expenses.status.${entry.from_status}`) : '-',
                                        to: $t(`expenses.status.${entry.to_status}`)
                                    }) }}
                                </p>
                                <p v-if="entry.comment" class="mt-2 text-sm text-stone-700 dark:text-neutral-300">
                                    {{ entry.comment }}
                                </p>
                            </div>
                        </div>
                        <p v-else class="text-sm text-stone-500 dark:text-neutral-500">
                            {{ $t('expenses.detail.workflow_empty') }}
                        </p>
                    </div>

                    <div v-if="aiIntake" class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <h2 class="text-sm font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                {{ $t('expenses.ai_scan.review_title') }}
                            </h2>
                            <span
                                class="inline-flex rounded-full px-2 py-1 text-xs font-medium"
                                :class="aiIntake.review_required ? 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300' : 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300'"
                            >
                                {{ aiIntake.review_required ? $t('expenses.ai_scan.review_required') : $t('expenses.ai_scan.ready_for_review') }}
                            </span>
                        </div>

                        <div class="mt-4 grid gap-4 sm:grid-cols-2">
                            <div>
                                <p class="text-xs uppercase tracking-wide text-stone-500 dark:text-neutral-500">
                                    {{ $t('expenses.ai_scan.source_document') }}
                                </p>
                                <p class="mt-1 text-sm text-stone-700 dark:text-neutral-300">
                                    {{ aiIntake.source_file_name || '-' }}
                                </p>
                            </div>
                            <div>
                                <p class="text-xs uppercase tracking-wide text-stone-500 dark:text-neutral-500">
                                    {{ $t('expenses.ai_scan.confidence') }}
                                </p>
                                <p class="mt-1 text-sm text-stone-700 dark:text-neutral-300">
                                    {{ aiIntake.normalized?.confidence?.overall ?? 0 }}%
                                </p>
                            </div>
                        </div>

                        <div v-if="aiIntake.normalized?.field_flags?.length" class="mt-4 space-y-2">
                            <p class="text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-500">
                                {{ $t('expenses.ai_scan.field_review') }}
                            </p>
                            <div class="grid gap-2">
                                <div
                                    v-for="flag in aiIntake.normalized.field_flags"
                                    :key="flag.field"
                                    class="rounded-sm border px-3 py-3"
                                    :class="aiFieldStateClass(flag.status)"
                                >
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <p class="text-sm font-medium">
                                            {{ flag.label }}
                                        </p>
                                        <span class="text-xs">
                                            {{ flag.confidence ?? 0 }}%
                                        </span>
                                    </div>
                                    <p class="mt-1 text-xs opacity-90">
                                        {{ flag.value || '-' }}
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div v-if="aiIntake.normalized?.review_flags?.length" class="mt-4 space-y-2">
                            <p class="text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-500">
                                {{ $t('expenses.ai_scan.review_flags') }}
                            </p>
                            <ul class="space-y-2 text-sm text-stone-700 dark:text-neutral-300">
                                <li
                                    v-for="(flag, index) in aiIntake.normalized.review_flags"
                                    :key="`review-flag-${index}`"
                                    class="rounded-sm border border-amber-200 bg-amber-50 px-3 py-2 dark:border-amber-900/40 dark:bg-amber-950/40"
                                >
                                    {{ flag }}
                                </li>
                            </ul>
                        </div>

                        <div v-if="duplicateDetection?.has_matches" class="mt-4 space-y-3">
                            <div class="rounded-sm border border-rose-200 bg-rose-50 px-4 py-3 dark:border-rose-900/40 dark:bg-rose-950/30">
                                <p class="text-sm font-medium text-rose-700 dark:text-rose-300">
                                    {{ duplicateDetection.exact_match_found ? $t('expenses.ai_scan.duplicate_exact') : $t('expenses.ai_scan.duplicate_possible') }}
                                </p>
                                <p class="mt-1 text-xs text-rose-600 dark:text-rose-300/80">
                                    {{ $t('expenses.ai_scan.duplicate_match_count', { count: duplicateDetection.match_count || duplicateDetection.matches?.length || 0 }) }}
                                </p>
                            </div>

                            <div class="space-y-2">
                                <div
                                    v-for="match in duplicateDetection.matches || []"
                                    :key="`duplicate-${match.expense_id}`"
                                    class="rounded-sm border border-stone-200 px-3 py-3 dark:border-neutral-700"
                                >
                                    <div class="flex flex-wrap items-start justify-between gap-3">
                                        <div>
                                            <p class="text-sm font-medium text-stone-800 dark:text-neutral-100">
                                                {{ match.title || $t('expenses.labels.none') }}
                                            </p>
                                            <p class="mt-1 text-xs text-stone-500 dark:text-neutral-500">
                                                {{ match.supplier_name || $t('expenses.labels.no_supplier') }}
                                                <span v-if="match.reference_number">• {{ match.reference_number }}</span>
                                            </p>
                                        </div>
                                        <Link
                                            :href="route('expense.show', match.expense_id)"
                                            class="text-xs font-medium text-red-600 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300"
                                        >
                                            {{ $t('expenses.actions.open') }}
                                        </Link>
                                    </div>

                                    <div class="mt-2 flex flex-wrap items-center gap-2 text-xs text-stone-500 dark:text-neutral-500">
                                        <span>{{ formatCurrency(match.total, match.currency_code || expense.currency_code) }}</span>
                                        <span>•</span>
                                        <span>{{ formatDate(match.expense_date) }}</span>
                                        <span>•</span>
                                        <span>{{ $t(`expenses.status.${match.status}`) }}</span>
                                    </div>

                                    <div v-if="match.reasons?.length" class="mt-2 flex flex-wrap gap-2">
                                        <span
                                            v-for="reason in match.reasons"
                                            :key="`${match.expense_id}-${reason}`"
                                            class="inline-flex rounded-full bg-stone-100 px-2 py-1 text-[11px] font-medium text-stone-600 dark:bg-neutral-800 dark:text-neutral-300"
                                        >
                                            {{ duplicateReasonLabel(reason) }}
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div v-if="aiIntake.normalized?.assumptions?.length" class="mt-4 space-y-2">
                            <p class="text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-500">
                                {{ $t('expenses.ai_scan.assumptions') }}
                            </p>
                            <ul class="space-y-2 text-sm text-stone-700 dark:text-neutral-300">
                                <li
                                    v-for="(assumption, index) in aiIntake.normalized.assumptions"
                                    :key="`assumption-${index}`"
                                    class="rounded-sm border border-stone-200 px-3 py-2 dark:border-neutral-700"
                                >
                                    {{ assumption }}
                                </li>
                            </ul>
                        </div>
                    </div>
                </section>
            </div>
        </div>

        <Modal
            v-if="canEdit"
            :title="$t('expenses.actions.edit_expense')"
            :id="'hs-expense-edit'"
        >
            <ExpenseForm
                :key="editingExpense?.id || expense.id"
                :id="'hs-expense-edit'"
                :expense="editingExpense || expense"
                :categories="categories"
                :payment-methods="paymentMethods"
                :statuses="statuses"
                :recurrence-frequencies="recurrenceFrequencies"
                :team-members="teamMembers"
                :link-options="linkOptions"
                :tenant-currency-code="expense.currency_code || tenantCurrencyCode"
            />
        </Modal>

        <ExpenseWorkflowModal
            id="hs-expense-workflow"
            :expense="workflowExpense"
            :action="pendingWorkflowAction"
            :reload-only="['expense']"
            @closed="clearWorkflowSelection"
        />
    </AuthenticatedLayout>
</template>
