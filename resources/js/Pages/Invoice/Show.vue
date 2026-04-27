<script setup>
import { computed, watch } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import StarRating from '@/Components/UI/StarRating.vue';
import DatePicker from '@/Components/DatePicker.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import FloatingTextarea from '@/Components/FloatingTextarea.vue';
import { paymentMethodLabel as resolvePaymentMethodLabel, useTenantPaymentMethods } from '@/Composables/useTenantPaymentMethods';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { humanizeDate } from '@/utils/date';
import { useI18n } from 'vue-i18n';
import { useCurrencyFormatter } from '@/utils/currency';

const props = defineProps({
    invoice: Object,
    paymentMethodSettings: {
        type: Object,
        default: () => ({}),
    },
});

const page = usePage();
const { t } = useI18n();
const companyName = computed(() => page.props.auth?.account?.company?.name || t('invoices.company_fallback'));
const companyLogo = computed(() => page.props.auth?.account?.company?.logo_url || null);
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

const form = useForm({
    amount: '',
    method: '',
    reference: '',
    paid_at: '',
    notes: '',
});
const approvalForm = useForm({
    comment: '',
});
const markPaidForm = useForm({});
const sendInvoiceForm = useForm({});
const balanceDue = computed(() => {
    const value = Number(props.invoice?.balance_due || 0);
    return Number.isFinite(value) ? Math.max(0, value) : 0;
});
const paymentAmount = computed(() => {
    const value = Number(form.amount || 0);
    return Number.isFinite(value) ? Math.max(0, value) : 0;
});
const exceedsBalanceDue = computed(() => paymentAmount.value > balanceDue.value + 0.0001);
const remainingAfterPayment = computed(() => roundMoney(Math.max(0, balanceDue.value - paymentAmount.value)));
const isPartialPayment = computed(() => paymentAmount.value > 0 && paymentAmount.value < balanceDue.value);

const dispatchDemoEvent = (eventName) => {
    if (typeof window === 'undefined') {
        return;
    }
    window.dispatchEvent(new CustomEvent(eventName));
};

const submitPayment = () => {
    if (paymentAmount.value < 0.01) {
        form.setError('amount', 'Enter a valid amount.');
        return;
    }

    if (exceedsBalanceDue.value) {
        form.setError('amount', `Amount cannot exceed ${formatCurrency(balanceDue.value)}.`);
        return;
    }

    form.post(route('payment.store', props.invoice.id), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('amount', 'method', 'reference', 'paid_at', 'notes');
            dispatchDemoEvent('demo:invoice_paid');
        },
    });
};

const customer = computed(() => props.invoice.customer || null);
const work = computed(() => props.invoice.work || null);
const invoiceItems = computed(() => props.invoice.items || []);
const isTaskBased = computed(() => invoiceItems.value.length > 0);
const lineItems = computed(() => (isTaskBased.value ? invoiceItems.value : work.value?.products || []));

const customerName = computed(() => {
    const data = customer.value;
    if (!data) {
        return t('invoices.labels.customer_fallback');
    }
    const name = data.company_name || `${data.first_name || ''} ${data.last_name || ''}`.trim();
    return name || t('invoices.labels.customer_fallback');
});

const contactName = computed(() => {
    const data = customer.value;
    if (!data) {
        return '-';
    }
    const name = `${data.first_name || ''} ${data.last_name || ''}`.trim();
    return name || data.company_name || '-';
});

const contactEmail = computed(() => customer.value?.email || '-');
const contactPhone = computed(() => customer.value?.phone || '-');

const fallbackProperty = computed(() => {
    const properties = customer.value?.properties || [];
    return properties.find((item) => item.is_default) || properties[0] || null;
});

const property = computed(() => work.value?.quote?.property || fallbackProperty.value);

const ratingValue = computed(() => {
    const ratings = work.value?.ratings || [];
    if (!ratings.length) {
        return null;
    }
    const sum = ratings.reduce((total, rating) => total + Number(rating.rating || 0), 0);
    return sum / ratings.length;
});

const ratingCount = computed(() => work.value?.ratings?.length || 0);

const formatDate = (value) => humanizeDate(value) || '-';

const { formatCurrency } = useCurrencyFormatter();
const roundMoney = (value) => Math.round((Number(value || 0) + Number.EPSILON) * 100) / 100;
const setPaymentAmount = (value) => {
    const normalized = roundMoney(Math.max(0, Math.min(Number(value || 0), balanceDue.value)));
    form.amount = normalized > 0 ? normalized.toFixed(2) : '';
    if (form.errors.amount) {
        form.clearErrors('amount');
    }
};

const paymentTipAmount = (payment) => {
    const value = Number(payment?.tip_amount || 0);
    return Number.isFinite(value) ? Math.max(0, value) : 0;
};

const paymentChargedTotal = (payment) => {
    const fallback = Number(payment?.amount || 0) + paymentTipAmount(payment);
    const value = Number(payment?.charged_total ?? fallback);
    return Number.isFinite(value) ? value : fallback;
};

const formatShortDate = (value) => {
    if (!value) {
        return '-';
    }

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return '-';
    }

    return date.toLocaleDateString();
};

const formatTimeRange = (start, end) => {
    const startLabel = start ? String(start).slice(0, 5) : '';
    const endLabel = end ? String(end).slice(0, 5) : '';
    if (!startLabel && !endLabel) {
        return '-';
    }
    if (!endLabel) {
        return startLabel;
    }
    return `${startLabel} - ${endLabel}`;
};

const invoiceSubtotal = computed(() => {
    if (isTaskBased.value) {
        return invoiceItems.value.reduce((sum, item) => sum + Number(item.total || 0), 0);
    }

    if (work.value?.subtotal !== undefined && work.value?.subtotal !== null) {
        return Number(work.value.subtotal || 0);
    }

    return Number(props.invoice.total || 0);
});

const lineItemColspan = computed(() => (isTaskBased.value ? 5 : 4));

const statusLabel = computed(() => {
    const status = props.invoice?.status || 'draft';
    const key = `invoices.status.${status}`;
    const translated = t(key);
    return translated === key ? status : translated;
});

const approvalStatusLabel = computed(() => {
    const status = props.invoice?.approval_status || 'draft';
    const key = `invoices.approval_status.${status}`;
    const translated = t(key);
    return translated === key ? status : translated;
});

const approvalStatusClass = computed(() => {
    const status = props.invoice?.approval_status || 'draft';

    switch (status) {
    case 'submitted':
        return 'bg-amber-100 text-amber-800 dark:bg-amber-500/10 dark:text-amber-300';
    case 'pending_approval':
        return 'bg-orange-100 text-orange-800 dark:bg-orange-500/10 dark:text-orange-300';
    case 'approved':
        return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300';
    case 'rejected':
        return 'bg-rose-100 text-rose-800 dark:bg-rose-500/10 dark:text-rose-300';
    case 'processed':
        return 'bg-sky-100 text-sky-800 dark:bg-sky-500/10 dark:text-sky-300';
    case 'paid':
        return 'bg-violet-100 text-violet-800 dark:bg-violet-500/10 dark:text-violet-300';
    default:
        return 'bg-stone-100 text-stone-700 dark:bg-neutral-800 dark:text-neutral-200';
    }
});

const availableApprovalActions = computed(() => props.invoice?.available_approval_actions || []);
const approvalHistory = computed(() => props.invoice?.approval_history || []);
const canSendInvoice = computed(() => Boolean(props.invoice?.can_send_email));

const humanizeRoleKey = (value) => {
    if (!value) {
        return '-';
    }

    return String(value)
        .replaceAll('_', ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
};

const approvalActionLabel = (action) => {
    const key = `invoices.approval_actions.${action}`;
    const translated = t(key);
    return translated === key ? action : translated;
};

const approvalTimelineLabel = (entry) => {
    const action = String(entry?.action || '');
    const key = `invoices.approval_history.${action}`;
    const translated = t(key);

    return translated === key ? action : translated;
};

const runApprovalAction = (action) => {
    const routeMap = {
        approve: 'invoice.approve',
        reject: 'invoice.reject',
        process: 'invoice.process',
    };
    const routeName = routeMap[action];
    if (!routeName || approvalForm.processing) {
        return;
    }

    approvalForm.patch(route(routeName, props.invoice.id), {
        preserveScroll: true,
        onSuccess: () => {
            approvalForm.reset('comment');
        },
    });
};

const sendInvoice = () => {
    if (!canSendInvoice.value || sendInvoiceForm.processing) {
        return;
    }

    sendInvoiceForm.post(route('invoice.send.email', props.invoice.id), {
        preserveScroll: true,
    });
};

const {
    allowedPaymentMethods,
    defaultPaymentMethod,
    hasMultiplePaymentMethods,
    singlePaymentMethod,
} = useTenantPaymentMethods(computed(() => props.paymentMethodSettings));

const paymentMethodLabel = (method) => {
    return resolvePaymentMethodLabel(method, {
        cash: t('sales.payments.cash'),
        card: t('sales.payments.card'),
        bankTransfer: t('public_invoice.methods.bank_transfer'),
        check: t('public_invoice.methods.check'),
    });
};
const paymentMethodOptions = computed(() => allowedPaymentMethods.value.map((method) => ({
    value: method,
    label: paymentMethodLabel(method),
})));

const isPendingCashPayment = (payment) =>
    String(payment?.method || '').toLowerCase() === 'cash'
    && String(payment?.status || '').toLowerCase() === 'pending';

const paymentStatusLabel = (payment) => {
    if (isPendingCashPayment(payment)) {
        return t('invoices.show.payments.pending_cash');
    }

    const status = String(payment?.status || '').toLowerCase();
    if (!status) {
        return '-';
    }

    const key = `invoices.show.payments.status.${status}`;
    const translated = t(key);
    return translated === key ? status : translated;
};

const paymentStatusClass = (payment) => {
    const status = String(payment?.status || '').toLowerCase();

    if (isPendingCashPayment(payment)) {
        return 'bg-amber-100 text-amber-800 dark:bg-amber-500/10 dark:text-amber-300';
    }

    if (status === 'completed' || status === 'paid') {
        return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300';
    }

    if (status === 'failed' || status === 'refunded' || status === 'reversed') {
        return 'bg-rose-100 text-rose-800 dark:bg-rose-500/10 dark:text-rose-300';
    }

    return 'bg-stone-100 text-stone-700 dark:bg-neutral-800 dark:text-neutral-300';
};

const markCashPaymentAsPaid = (paymentId) => {
    if (!paymentId || markPaidForm.processing) {
        return;
    }

    markPaidForm.patch(route('payment.mark-paid', paymentId), {
        preserveScroll: true,
    });
};

watch(
    () => [allowedPaymentMethods.value, defaultPaymentMethod.value],
    () => {
        if (!allowedPaymentMethods.value.includes(form.method)) {
            form.method = defaultPaymentMethod.value;
        }
    },
    { immediate: true }
);
</script>

<template>
    <Head :title="$t('invoices.show.title', { number: invoice.number || invoice.id })" />

    <AuthenticatedLayout>
        <div class="mx-auto w-full max-w-6xl space-y-5 rise-stagger">
            <div class="p-5 space-y-3 flex flex-col bg-stone-100 border border-stone-100 rounded-sm shadow-sm dark:bg-neutral-900 dark:border-neutral-800">
                <div class="flex flex-wrap justify-between items-center gap-3">
                    <div class="flex items-center gap-3">
                        <img v-if="companyLogo"
                            :src="companyLogo"
                            :alt="companyName"
                            class="h-12 w-12 rounded-sm border border-stone-200 object-cover dark:border-neutral-700"
                            loading="lazy"
                            decoding="async" />
                        <div>
                            <p class="text-xs uppercase text-stone-500 dark:text-neutral-400">
                                {{ companyName }}
                            </p>
                            <h1 class="text-xl inline-block font-semibold text-stone-800 dark:text-neutral-100">
                                {{ $t('invoices.show.invoice_for', { customer: customerName }) }}
                            </h1>
                            <p class="text-sm text-stone-600 dark:text-neutral-300">
                                {{ work?.job_title || $t('invoices.labels.job_fallback') }}
                            </p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <Link
                            v-if="canOpenFinanceApprovals"
                            :href="route('finance-approvals.index')"
                            class="inline-flex items-center gap-x-2 text-xs font-medium rounded-sm border border-stone-200 bg-white px-3 py-1.5 text-stone-700 hover:bg-stone-50 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800"
                        >
                            {{ $t('finance_approvals.title') }}
                        </Link>
                        <Link
                            :href="route('pipeline.timeline', { entityType: 'invoice', entityId: invoice.id })"
                            class="inline-flex items-center gap-x-2 text-xs font-medium rounded-sm border border-stone-200 bg-white px-3 py-1.5 text-stone-700 hover:bg-stone-50 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800"
                        >
                            {{ $t('invoices.show.timeline') }}
                        </Link>
                        <a
                            :href="route('invoice.pdf', invoice.id)"
                            class="inline-flex items-center gap-x-2 text-xs font-medium rounded-sm border border-stone-200 bg-white px-3 py-1.5 text-stone-700 hover:bg-stone-50 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800"
                            target="_blank"
                            rel="noopener"
                        >
                            {{ $t('invoices.show.download_pdf') }}
                        </a>
                        <button
                            v-if="canSendInvoice"
                            type="button"
                            class="inline-flex items-center gap-x-2 text-xs font-medium rounded-sm border border-transparent bg-green-600 px-3 py-1.5 text-white hover:bg-green-700 disabled:opacity-60"
                            :disabled="sendInvoiceForm.processing"
                            @click="sendInvoice"
                        >
                            {{ sendInvoiceForm.processing ? $t('invoices.actions.sending_invoice') : $t('invoices.actions.send_invoice') }}
                        </button>
                        <span class="py-1.5 px-3 inline-flex items-center gap-x-1.5 text-xs font-medium rounded-full"
                            :class="{
                                'bg-stone-100 text-stone-700 dark:bg-neutral-800 dark:text-neutral-200': invoice.status === 'draft',
                                'bg-sky-100 text-sky-700 dark:bg-sky-500/10 dark:text-sky-400': invoice.status === 'sent',
                                'bg-amber-100 text-amber-800 dark:bg-amber-500/10 dark:text-amber-400': invoice.status === 'partial',
                                'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-400': invoice.status === 'paid',
                                'bg-rose-100 text-rose-800 dark:bg-rose-500/10 dark:text-rose-400': invoice.status === 'overdue' || invoice.status === 'void',
                            }">
                            {{ statusLabel }}
                        </span>
                        <span
                            class="py-1.5 px-3 inline-flex items-center gap-x-1.5 text-xs font-medium rounded-full"
                            :class="approvalStatusClass"
                        >
                            {{ approvalStatusLabel }}
                        </span>
                    </div>
                </div>
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <div class="col-span-2 space-x-2">
                        <div class="bg-white rounded-sm border border-stone-100 p-4 mb-4 text-stone-700 dark:bg-neutral-900 dark:border-neutral-800 dark:text-neutral-100">
                            {{ work?.job_title || $t('invoices.labels.job_fallback') }}
                        </div>
                        <div class="flex flex-row space-x-6">
                            <div class="lg:col-span-3">
                                <p class="text-sm text-stone-700 dark:text-neutral-200">{{ $t('invoices.show.property_address') }}</p>
                                <div v-if="property" class="space-y-1">
                                    <div class="text-xs text-stone-600 dark:text-neutral-300">{{ property.country }}</div>
                                    <div class="text-xs text-stone-600 dark:text-neutral-300">{{ property.street1 }}</div>
                                    <div class="text-xs text-stone-600 dark:text-neutral-300">{{ property.state }} - {{ property.zip }}</div>
                                </div>
                                <div v-else class="text-xs text-stone-600 dark:text-neutral-400">
                                    {{ $t('invoices.show.no_property') }}
                                </div>
                            </div>
                            <div class="lg:col-span-3">
                                <p class="text-sm text-stone-700 dark:text-neutral-200">{{ $t('invoices.show.contact_details') }}</p>
                                <div class="text-xs text-stone-600 dark:text-neutral-300">
                                    {{ contactName }}
                                </div>
                                <div class="text-xs text-stone-600 dark:text-neutral-300">
                                    {{ contactEmail }}
                                </div>
                                <div class="text-xs text-stone-600 dark:text-neutral-300">
                                    {{ contactPhone }}
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="bg-white p-4 rounded-sm border border-stone-100 dark:bg-neutral-900 dark:border-neutral-800">
                        <p class="text-sm text-stone-700 dark:text-neutral-200">{{ $t('invoices.show.invoice_details') }}</p>
                        <div class="text-xs text-stone-600 dark:text-neutral-300 flex justify-between">
                            <span>{{ $t('invoices.show.invoice_label') }}:</span>
                            <span>{{ invoice.number || invoice.id }}</span>
                        </div>
                        <div class="text-xs text-stone-600 dark:text-neutral-300 flex justify-between">
                            <span>{{ $t('invoices.show.issued') }}:</span>
                            <span>{{ formatDate(invoice.created_at) }}</span>
                        </div>
                        <div class="text-xs text-stone-600 dark:text-neutral-300 flex justify-between">
                            <span>{{ $t('invoices.show.balance_due') }}:</span>
                            <span>{{ formatCurrency(invoice.balance_due) }}</span>
                        </div>
                        <div class="text-xs text-stone-600 dark:text-neutral-300 flex justify-between">
                            <span>{{ $t('invoices.show.job_rating') }}:</span>
                            <span class="flex items-center gap-2">
                                <StarRating :value="ratingValue" show-value :empty-label="$t('invoices.show.no_rating')" />
                                <span v-if="ratingCount" class="text-xs text-stone-500 dark:text-neutral-400">({{ ratingCount }})</span>
                            </span>
                        </div>
                    </div>
                </div>
                <div class="rounded-sm border border-stone-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-950">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                        <div class="space-y-2">
                            <div class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                {{ $t('invoices.show.finance_approval.title') }}
                            </div>
                            <div class="grid gap-2 text-xs text-stone-600 dark:text-neutral-300 md:grid-cols-2 xl:grid-cols-4">
                                <div>
                                    <div class="text-[11px] uppercase tracking-wide text-stone-400 dark:text-neutral-500">
                                        {{ $t('invoices.show.finance_approval.current_status') }}
                                    </div>
                                    <div class="mt-1 font-medium text-stone-800 dark:text-neutral-100">
                                        {{ approvalStatusLabel }}
                                    </div>
                                </div>
                                <div>
                                    <div class="text-[11px] uppercase tracking-wide text-stone-400 dark:text-neutral-500">
                                        {{ $t('invoices.show.finance_approval.current_role') }}
                                    </div>
                                    <div class="mt-1 font-medium text-stone-800 dark:text-neutral-100">
                                        {{ humanizeRoleKey(invoice.current_approver_role_key) }}
                                    </div>
                                </div>
                                <div>
                                    <div class="text-[11px] uppercase tracking-wide text-stone-400 dark:text-neutral-500">
                                        {{ $t('invoices.show.finance_approval.approved_by') }}
                                    </div>
                                    <div class="mt-1 font-medium text-stone-800 dark:text-neutral-100">
                                        {{ invoice.approver?.name || '-' }}
                                    </div>
                                </div>
                                <div>
                                    <div class="text-[11px] uppercase tracking-wide text-stone-400 dark:text-neutral-500">
                                        {{ $t('invoices.show.finance_approval.processed_by') }}
                                    </div>
                                    <div class="mt-1 font-medium text-stone-800 dark:text-neutral-100">
                                        {{ invoice.processor?.name || '-' }}
                                    </div>
                                </div>
                            </div>
                            <p
                                v-if="!canSendInvoice && ['submitted', 'pending_approval'].includes(invoice.approval_status)"
                                class="text-xs text-amber-700 dark:text-amber-300"
                            >
                                {{ $t('invoices.show.finance_approval.send_blocked') }}
                            </p>
                        </div>
                        <div v-if="availableApprovalActions.length" class="w-full max-w-md space-y-2">
                            <FloatingTextarea
                                v-model="approvalForm.comment"
                                :label="$t('invoices.show.finance_approval.comment_placeholder')"
                            />
                            <div class="flex flex-wrap gap-2">
                                <button
                                    v-for="action in availableApprovalActions"
                                    :key="action"
                                    type="button"
                                    class="inline-flex items-center rounded-sm border px-3 py-1.5 text-xs font-medium disabled:opacity-60"
                                    :class="action === 'reject'
                                        ? 'border-rose-200 bg-rose-50 text-rose-700 hover:bg-rose-100 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-300'
                                        : action === 'process'
                                            ? 'border-sky-200 bg-sky-50 text-sky-700 hover:bg-sky-100 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-300'
                                            : 'border-emerald-200 bg-emerald-50 text-emerald-700 hover:bg-emerald-100 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300'"
                                    :disabled="approvalForm.processing"
                                    @click="runApprovalAction(action)"
                                >
                                    {{ approvalActionLabel(action) }}
                                </button>
                            </div>
                        </div>
                    </div>
                    <div v-if="approvalHistory.length" class="mt-4 border-t border-stone-200 pt-4 dark:border-neutral-800">
                        <div class="text-xs font-semibold uppercase tracking-wide text-stone-400 dark:text-neutral-500">
                            {{ $t('invoices.show.finance_approval.history') }}
                        </div>
                        <div class="mt-3 space-y-2">
                            <div
                                v-for="(entry, index) in approvalHistory.slice().reverse()"
                                :key="`${entry.created_at || index}-${entry.action || index}`"
                                class="rounded-sm border border-stone-200 bg-stone-50 px-3 py-2 text-xs text-stone-600 dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-300"
                            >
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <span class="font-medium text-stone-800 dark:text-neutral-100">
                                        {{ approvalTimelineLabel(entry) }}
                                    </span>
                                    <span>{{ formatDate(entry.created_at) }}</span>
                                </div>
                                <div class="mt-1 flex flex-wrap gap-x-3 gap-y-1">
                                    <span>{{ $t('invoices.show.finance_approval.from') }}: {{ entry.from || '-' }}</span>
                                    <span>{{ $t('invoices.show.finance_approval.to') }}: {{ entry.to || '-' }}</span>
                                    <span>{{ $t('invoices.show.finance_approval.by') }}: {{ entry.actor_name || '-' }}</span>
                                </div>
                                <p v-if="entry.comment" class="mt-1 text-stone-500 dark:text-neutral-400">
                                    {{ entry.comment }}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="p-5 space-y-3 flex flex-col bg-white border border-stone-100 rounded-sm shadow-sm dark:bg-neutral-900 dark:border-neutral-800">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-stone-200 dark:divide-neutral-700">
                        <thead>
                            <tr>
                                <th class="min-w-[300px] text-left text-sm font-medium text-stone-800 dark:text-neutral-100">
                                    {{ isTaskBased ? $t('invoices.show.table.tasks') : $t('invoices.show.table.products_services') }}
                                </th>
                                <th v-if="isTaskBased" class="text-left text-sm font-medium text-stone-800 dark:text-neutral-100">{{ $t('invoices.show.table.date') }}</th>
                                <th v-if="isTaskBased" class="text-left text-sm font-medium text-stone-800 dark:text-neutral-100">{{ $t('invoices.show.table.time') }}</th>
                                <th v-if="isTaskBased" class="text-left text-sm font-medium text-stone-800 dark:text-neutral-100">{{ $t('invoices.show.table.assignee') }}</th>
                                <th v-if="isTaskBased" class="text-left text-sm font-medium text-stone-800 dark:text-neutral-100">{{ $t('invoices.show.table.total') }}</th>
                                <th v-else class="text-left text-sm font-medium text-stone-800 dark:text-neutral-100">{{ $t('invoices.show.table.qty') }}</th>
                                <th v-else class="text-left text-sm font-medium text-stone-800 dark:text-neutral-100">{{ $t('invoices.show.table.unit_cost') }}</th>
                                <th v-else class="text-left text-sm font-medium text-stone-800 dark:text-neutral-100">{{ $t('invoices.show.table.total') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-200 dark:divide-neutral-700">
                            <tr v-for="item in lineItems" :key="item.id">
                                <template v-if="isTaskBased">
                                    <td class="px-4 py-3 text-sm text-stone-700 dark:text-neutral-200">{{ item.title }}</td>
                                    <td class="px-4 py-3 text-sm text-stone-700 dark:text-neutral-200">{{ formatShortDate(item.scheduled_date) }}</td>
                                    <td class="px-4 py-3 text-sm text-stone-700 dark:text-neutral-200">{{ formatTimeRange(item.start_time, item.end_time) }}</td>
                                    <td class="px-4 py-3 text-sm text-stone-700 dark:text-neutral-200">{{ item.assignee_name || '-' }}</td>
                                    <td class="px-4 py-3 text-sm text-stone-700 dark:text-neutral-200">{{ formatCurrency(item.total) }}</td>
                                </template>
                                <template v-else>
                                    <td class="px-4 py-3 text-sm text-stone-700 dark:text-neutral-200">{{ item.name }}</td>
                                    <td class="px-4 py-3 text-sm text-stone-700 dark:text-neutral-200">{{ item.pivot?.quantity ?? '-' }}</td>
                                    <td class="px-4 py-3 text-sm text-stone-700 dark:text-neutral-200">{{ formatCurrency(item.pivot?.price) }}</td>
                                    <td class="px-4 py-3 text-sm text-stone-700 dark:text-neutral-200">{{ formatCurrency(item.pivot?.total) }}</td>
                                </template>
                            </tr>
                            <tr v-if="!lineItems.length">
                                <td :colspan="lineItemColspan" class="px-4 py-4 text-sm text-stone-500 dark:text-neutral-400">
                                    {{ $t('invoices.show.line_items_empty') }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="p-5 grid grid-cols-2 gap-4 justify-between bg-white border border-stone-100 rounded-sm shadow-sm dark:bg-neutral-900 dark:border-neutral-800">
                <div></div>
                <div class="border-l border-stone-200 rounded-sm p-4 dark:border-neutral-700">
                    <div class="py-4 grid grid-cols-2 gap-x-4">
                        <div class="col-span-1">
                            <p class="text-sm text-stone-500 dark:text-neutral-400">{{ $t('invoices.show.summary.subtotal') }}:</p>
                        </div>
                        <div class="col-span-1 flex justify-end">
                            <p class="text-sm text-green-600 dark:text-emerald-400">
                                {{ formatCurrency(invoiceSubtotal) }}
                            </p>
                        </div>
                    </div>

                    <div class="py-4 grid grid-cols-2 gap-x-4 border-t border-stone-200 dark:border-neutral-700">
                        <div class="col-span-1">
                            <p class="text-sm text-stone-500 dark:text-neutral-400">{{ $t('invoices.show.summary.paid') }}:</p>
                        </div>
                        <div class="flex justify-end">
                            <p class="text-sm text-stone-800 dark:text-neutral-100">
                                {{ formatCurrency(invoice.amount_paid) }}
                            </p>
                        </div>
                    </div>

                    <div class="py-4 grid grid-cols-2 gap-x-4 border-t border-stone-200 dark:border-neutral-700">
                        <div class="col-span-1">
                            <p class="text-sm text-stone-800 font-bold dark:text-neutral-100">{{ $t('invoices.show.summary.total_amount') }}:</p>
                        </div>
                        <div class="flex justify-end">
                            <p class="text-sm text-stone-800 font-bold dark:text-neutral-100">
                                {{ formatCurrency(invoice.total) }}
                            </p>
                        </div>
                    </div>

                    <div class="py-4 grid grid-cols-2 gap-x-4 border-t border-stone-200 dark:border-neutral-700">
                        <div class="col-span-1">
                            <p class="text-sm text-stone-500 dark:text-neutral-400">{{ $t('invoices.show.summary.balance_due') }}:</p>
                        </div>
                        <div class="flex justify-end">
                            <p class="text-sm text-stone-800 dark:text-neutral-100">
                                {{ formatCurrency(invoice.balance_due) }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">
                <div class="p-4 bg-white border border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100 mb-3">{{ $t('invoices.show.payments.title') }}</h2>
                    <div v-if="invoice.payments?.length" class="space-y-2">
                        <div v-for="payment in invoice.payments" :key="payment.id"
                            class="flex items-center justify-between p-2 rounded-sm bg-stone-50 dark:bg-neutral-900">
                            <div>
                                <p class="text-sm text-stone-700 dark:text-neutral-200">
                                    {{ formatCurrency(payment.amount) }} - {{ paymentMethodLabel(payment.method) }}
                                </p>
                                <div class="mt-1 space-y-0.5 text-xs text-stone-500 dark:text-neutral-400">
                                    <div>{{ $t('invoices.show.payments.subtotal') }}: {{ formatCurrency(payment.amount) }}</div>
                                    <div>{{ $t('invoices.show.payments.tip') }}: {{ formatCurrency(paymentTipAmount(payment)) }}</div>
                                    <div class="font-medium text-stone-700 dark:text-neutral-300">
                                        {{ $t('invoices.show.payments.total_paid') }}: {{ formatCurrency(paymentChargedTotal(payment)) }}
                                    </div>
                                    <div v-if="payment.tip_assignee?.name">
                                        {{ $t('invoices.show.payments.tip_assigned_to') }}: {{ payment.tip_assignee.name }}
                                    </div>
                                </div>
                                <p class="text-xs text-stone-500 dark:text-neutral-400">
                                    {{ formatDate(payment.paid_at || payment.created_at) }}
                                </p>
                            </div>
                            <div class="flex flex-col items-end gap-2">
                                <span
                                    class="rounded-full px-2 py-0.5 text-[11px] font-medium"
                                    :class="paymentStatusClass(payment)"
                                >
                                    {{ paymentStatusLabel(payment) }}
                                </span>
                                <button
                                    v-if="isPendingCashPayment(payment)"
                                    type="button"
                                    class="rounded-sm border border-stone-200 bg-white px-2 py-1 text-[11px] font-medium text-stone-700 hover:bg-stone-50 disabled:opacity-60 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                                    :disabled="markPaidForm.processing"
                                    @click="markCashPaymentAsPaid(payment.id)"
                                >
                                    {{ markPaidForm.processing ? $t('invoices.show.payments.marking_paid') : $t('invoices.show.payments.mark_paid') }}
                                </button>
                            </div>
                        </div>
                    </div>
                    <p v-else class="text-sm text-stone-500 dark:text-neutral-400">{{ $t('invoices.show.payments.empty') }}</p>
                </div>

                <div class="p-4 bg-white border border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100 mb-3">{{ $t('invoices.show.add_payment.title') }}</h2>
                    <form @submit.prevent="submitPayment" class="space-y-3">
                        <FloatingInput
                            v-model="form.amount"
                            type="number"
                            min="0"
                            step="0.01"
                            :label="$t('invoices.show.add_payment.amount')"
                        />
                        <div v-if="form.errors.amount" class="text-xs text-red-600">{{ form.errors.amount }}</div>
                        <p class="text-[11px] text-stone-500 dark:text-neutral-400">
                            For partial payments, enter an amount lower than the current balance.
                        </p>
                        <div class="flex flex-wrap gap-2">
                            <button
                                type="button"
                                class="rounded-sm border border-stone-200 bg-white px-2.5 py-1 text-xs font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-700"
                                @click="setPaymentAmount(balanceDue)"
                            >
                                Pay full balance
                            </button>
                            <button
                                type="button"
                                class="rounded-sm border border-stone-200 bg-white px-2.5 py-1 text-xs font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-700"
                                @click="setPaymentAmount(roundMoney(balanceDue * 0.5))"
                            >
                                50%
                            </button>
                            <button
                                type="button"
                                class="rounded-sm border border-stone-200 bg-white px-2.5 py-1 text-xs font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-700"
                                @click="setPaymentAmount(roundMoney(balanceDue * 0.25))"
                            >
                                25%
                            </button>
                        </div>
                        <p class="text-[11px] text-stone-500 dark:text-neutral-400">
                            <template v-if="isPartialPayment">
                                Partial payment selected. Remaining after this payment:
                                <span class="font-semibold text-stone-700 dark:text-neutral-200">{{ formatCurrency(remainingAfterPayment) }}</span>
                            </template>
                            <template v-else>
                                Remaining after this payment:
                                <span class="font-semibold text-stone-700 dark:text-neutral-200">{{ formatCurrency(remainingAfterPayment) }}</span>
                            </template>
                        </p>
                        <div v-if="hasMultiplePaymentMethods">
                            <FloatingSelect
                                v-model="form.method"
                                :label="$t('invoices.show.add_payment.method')"
                                :options="paymentMethodOptions"
                            />
                        </div>
                        <div v-else class="rounded-sm border border-stone-200 bg-stone-50 px-3 py-2 text-xs text-stone-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300">
                            Payment method:
                            <span class="font-semibold text-stone-700 dark:text-neutral-200">
                                {{ paymentMethodLabel(singlePaymentMethod) }}
                            </span>
                        </div>
                        <div v-if="form.errors.method" class="text-xs text-red-600">{{ form.errors.method }}</div>
                        <FloatingInput
                            v-model="form.reference"
                            :label="$t('invoices.show.add_payment.reference')"
                        />
                        <DatePicker v-model="form.paid_at" :label="$t('invoices.show.add_payment.paid_at')" />
                        <FloatingTextarea
                            v-model="form.notes"
                            :label="$t('invoices.show.add_payment.notes')"
                        />
                        <button type="submit" data-testid="demo-invoice-payment-submit"
                            class="py-2 px-3 inline-flex items-center gap-x-2 text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50 disabled:pointer-events-none">
                            {{ $t('invoices.show.add_payment.submit') }}
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
