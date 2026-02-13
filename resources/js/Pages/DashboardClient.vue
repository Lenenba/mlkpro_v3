<script setup>
import { computed, reactive, ref, watchEffect, nextTick } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import KpiSparkline from '@/Components/Dashboard/KpiSparkline.vue';
import KpiTrendBadge from '@/Components/Dashboard/KpiTrendBadge.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { humanizeDate } from '@/utils/date';
import FullCalendar from '@fullcalendar/vue3';
import dayGridPlugin from '@fullcalendar/daygrid';
import timeGridPlugin from '@fullcalendar/timegrid';
import { buildPreviewEvents } from '@/utils/schedule';
import { prepareMediaFile, MEDIA_LIMITS } from '@/utils/media';
import { buildSparklinePoints, buildTrend } from '@/utils/kpi';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    stats: {
        type: Object,
        default: () => ({}),
    },
    kpiSeries: {
        type: Object,
        default: () => ({}),
    },
    pendingQuotes: {
        type: Array,
        default: () => [],
    },
    validatedQuotes: {
        type: Array,
        default: () => [],
    },
    pendingWorks: {
        type: Array,
        default: () => [],
    },
    pendingSchedules: {
        type: Array,
        default: () => [],
    },
    taskProofs: {
        type: Array,
        default: () => [],
    },
    validatedWorks: {
        type: Array,
        default: () => [],
    },
    invoicesDue: {
        type: Array,
        default: () => [],
    },
    quoteRatingsDue: {
        type: Array,
        default: () => [],
    },
    workRatingsDue: {
        type: Array,
        default: () => [],
    },
    profileMissing: {
        type: Boolean,
        default: false,
    },
    autoValidation: {
        type: Object,
        default: () => ({ tasks: false, invoices: false }),
    },
    stripe: {
        type: Object,
        default: () => ({ enabled: false }),
    },
    tips: {
        type: Object,
        default: () => ({}),
    },
    paymentMethodSettings: {
        type: Object,
        default: () => ({}),
    },
});

const page = usePage();
const { t } = useI18n();
const userName = computed(() => page.props.auth?.user?.name || t('client_dashboard.labels.fallback_name'));
const autoValidation = computed(() => ({
    tasks: Boolean(props.autoValidation?.tasks),
    invoices: Boolean(props.autoValidation?.invoices),
}));
const stripeEnabled = computed(() => Boolean(props.stripe?.enabled));
const ALLOWED_INTERNAL_METHODS = ['cash', 'card', 'bank_transfer', 'check'];
const allowedPaymentMethods = computed(() => {
    const raw = Array.isArray(props.paymentMethodSettings?.enabled_methods_internal)
        ? props.paymentMethodSettings.enabled_methods_internal
        : [];

    const normalized = raw
        .map((method) => (typeof method === 'string' ? method.trim().toLowerCase() : ''))
        .filter((method, index, array) => method && array.indexOf(method) === index)
        .filter((method) => ALLOWED_INTERNAL_METHODS.includes(method));

    return normalized.length ? normalized : ['cash', 'card'];
});
const defaultPaymentMethod = computed(() => {
    const configured = typeof props.paymentMethodSettings?.default_method_internal === 'string'
        ? props.paymentMethodSettings.default_method_internal.trim().toLowerCase()
        : '';

    if (configured && allowedPaymentMethods.value.includes(configured)) {
        return configured;
    }

    return allowedPaymentMethods.value[0] || 'cash';
});
const hasMultiplePaymentMethods = computed(() => allowedPaymentMethods.value.length > 1);
const canUseStripeMethod = computed(() =>
    stripeEnabled.value && allowedPaymentMethods.value.includes('card')
);

const paymentMethodLabel = (method) => {
    if (method === 'cash') {
        return t('sales.payments.cash');
    }
    if (method === 'card') {
        return t('sales.payments.card');
    }
    if (method === 'bank_transfer') {
        return 'Bank transfer';
    }
    if (method === 'check') {
        return 'Check';
    }
    return method || '-';
};

const maxTipPercent = computed(() => Number(props.tips?.max_percent ?? 30));
const maxTipFixedAmount = computed(() => Number(props.tips?.max_fixed_amount ?? 200));
const quickTipPercents = computed(() => {
    const values = Array.isArray(props.tips?.quick_percents) ? props.tips.quick_percents : [5, 10, 15, 20];
    const normalized = values
        .map((value) => Number(value))
        .filter((value, index, arr) => Number.isFinite(value) && value >= 0 && arr.indexOf(value) === index)
        .map((value) => Math.min(value, maxTipPercent.value));

    return normalized.length ? normalized : [5, 10, 15, 20];
});
const quickTipFixedAmounts = computed(() => {
    const values = Array.isArray(props.tips?.quick_fixed_amounts) ? props.tips.quick_fixed_amounts : [2, 5, 10];
    const normalized = values
        .map((value) => Number(value))
        .filter((value, index, arr) => Number.isFinite(value) && value >= 0 && arr.indexOf(value) === index)
        .map((value) => Math.min(value, maxTipFixedAmount.value));

    return normalized.length ? normalized : [2, 5, 10];
});
const defaultTipPercent = computed(() => {
    const rawValue = Number(props.tips?.default_percent ?? 10);
    if (!Number.isFinite(rawValue)) {
        return 10;
    }
    return Math.max(0, Math.min(rawValue, maxTipPercent.value));
});
const kpiSeries = computed(() => props.kpiSeries || {});
const kpiConfig = {
    quotes_pending: { direction: 'down' },
    works_pending: { direction: 'down' },
    invoices_due: { direction: 'down' },
    ratings_due: { direction: 'down' },
};
const kpiData = computed(() => {
    const data = {};
    Object.entries(kpiConfig).forEach(([key, config]) => {
        const values = kpiSeries.value?.[key] || [];
        data[key] = {
            points: buildSparklinePoints(values),
            trend: buildTrend(values, config.direction),
        };
    });
    return data;
});

const stat = (key) => props.stats?.[key] ?? 0;

const formatCurrency = (value) =>
    `$${Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

const formatDate = (value) => humanizeDate(value) || '-';

const formatCalendarDate = (value) => {
    if (!value) {
        return '-';
    }

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return '-';
    }

    return date.toLocaleDateString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
};

const formatTime = (value) => {
    if (!value) {
        return '-';
    }

    const text = String(value);
    return text.length >= 5 ? text.slice(0, 5) : text;
};

const formatTimeRange = (start, end) => {
    const startLabel = formatTime(start);
    const endLabel = formatTime(end);

    if (startLabel === '-' && endLabel === '-') {
        return '-';
    }

    if (endLabel === '-') {
        return startLabel;
    }

    return `${startLabel} - ${endLabel}`;
};

const formatStatus = (status, keyPrefix = '') => {
    if (!status) {
        return t('client_dashboard.labels.pending');
    }
    if (keyPrefix) {
        const key = `${keyPrefix}.${status}`;
        const translated = t(key);
        if (translated && translated !== key) {
            return translated;
        }
    }
    return String(status).replace(/_/g, ' ');
};

const statusClass = (status) => {
    switch (status) {
        case 'to_schedule':
            return 'bg-amber-100 text-amber-800 dark:bg-amber-500/10 dark:text-amber-400';
        case 'scheduled':
            return 'bg-yellow-100 text-yellow-800 dark:bg-yellow-500/10 dark:text-yellow-400';
        case 'en_route':
            return 'bg-sky-100 text-sky-800 dark:bg-sky-500/10 dark:text-sky-400';
        case 'in_progress':
            return 'bg-blue-100 text-blue-800 dark:bg-blue-500/10 dark:text-blue-400';
        case 'tech_complete':
            return 'bg-indigo-100 text-indigo-800 dark:bg-indigo-500/10 dark:text-indigo-400';
        case 'pending_review':
            return 'bg-violet-100 text-violet-800 dark:bg-violet-500/10 dark:text-violet-400';
        case 'validated':
            return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-400';
        case 'auto_validated':
            return 'bg-teal-100 text-teal-800 dark:bg-teal-500/10 dark:text-teal-400';
        case 'dispute':
            return 'bg-rose-100 text-rose-800 dark:bg-rose-500/10 dark:text-rose-400';
        case 'closed':
            return 'bg-slate-200 text-slate-800 dark:bg-slate-500/10 dark:text-slate-300';
        case 'cancelled':
            return 'bg-stone-200 text-stone-700 dark:bg-neutral-700 dark:text-neutral-300';
        case 'completed':
            return 'bg-lime-100 text-lime-800 dark:bg-lime-500/10 dark:text-lime-400';
        case 'accepted':
        case 'paid':
        case 'done':
            return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-400';
        case 'sent':
            return 'bg-blue-100 text-blue-800 dark:bg-blue-500/10 dark:text-blue-400';
        case 'partial':
        case 'pending':
        case 'todo':
            return 'bg-amber-100 text-amber-800 dark:bg-amber-500/10 dark:text-amber-400';
        case 'declined':
        case 'overdue':
        case 'void':
            return 'bg-red-100 text-red-800 dark:bg-red-500/10 dark:text-red-400';
        case 'draft':
            return 'bg-stone-100 text-stone-700 dark:bg-neutral-700 dark:text-neutral-200';
        default:
            return 'bg-stone-100 text-stone-700 dark:bg-neutral-700 dark:text-neutral-200';
    }
};

const ratingOptions = computed(() =>
    [1, 2, 3, 4, 5].map((value) => ({
        value,
        label: `${value} ${value > 1 ? t('client_dashboard.ratings.stars') : t('client_dashboard.ratings.star')}`,
    }))
);

const proofTypeOptions = computed(() => ([
    { value: 'execution', label: t('client_dashboard.proof.types.execution') },
    { value: 'completion', label: t('client_dashboard.proof.types.completion') },
    { value: 'other', label: t('client_dashboard.proof.types.other') },
]));

const paymentAmounts = reactive({});
const paymentMethods = reactive({});
const paymentTipEnabled = reactive({});
const paymentTipModes = reactive({});
const paymentTipPercents = reactive({});
const paymentTipFixedAmounts = reactive({});
const stripeProcessing = reactive({});
const ratingForms = reactive({
    quotes: {},
    works: {},
});

const schedulePreviewOpen = ref(false);
const schedulePreviewWork = ref(null);
const schedulePreviewCalendar = ref(null);

const taskProofOpen = ref(false);
const taskProofTask = ref(null);
const taskProofForm = useForm({
    type: 'execution',
    file: null,
    note: '',
});

const schedulePreviewId = computed(() => schedulePreviewWork.value?.id ?? null);
const schedulePreviewAssignees = computed(() => schedulePreviewWork.value?.team_members || []);

const schedulePreviewEvents = computed(() => {
    const work = schedulePreviewWork.value;
    if (!work) {
        return [];
    }

    return buildPreviewEvents({
        startDate: work.start_date,
        endDate: work.end_date || null,
        frequency: work.frequency,
        repeatsOn: work.repeatsOn || [],
        totalVisits: work.totalVisits,
        startTime: work.start_time,
        endTime: work.end_time,
        title: work.job_title || t('client_dashboard.labels.job_fallback'),
        workId: work.id,
        assignees: schedulePreviewAssignees.value,
        preview: true,
    });
});

const schedulePreviewHasEvents = computed(() => schedulePreviewEvents.value.length > 0);

const schedulePreviewIsRecurring = computed(() => {
    const work = schedulePreviewWork.value;
    if (!work) {
        return false;
    }

    const totalVisits = Number(work.totalVisits || 0);
    if (totalVisits > 1 || work.end_date) {
        return true;
    }

    return schedulePreviewEvents.value.length > 1;
});

const schedulePreviewRange = computed(() => {
    const events = schedulePreviewEvents.value;
    if (!events.length) {
        return { start: '-', end: '-' };
    }

    const start = events[0]?.start;
    const end = events[events.length - 1]?.start;

    return {
        start: formatCalendarDate(start),
        end: formatCalendarDate(end),
    };
});

const schedulePreviewCalendarOptions = computed(() => ({
    plugins: [dayGridPlugin, timeGridPlugin],
    initialView: 'dayGridMonth',
    headerToolbar: {
        left: 'prev,next today',
        center: 'title',
        right: 'timeGridWeek,dayGridMonth',
    },
    buttonText: {
        today: t('client_dashboard.calendar.today'),
        month: t('client_dashboard.calendar.month'),
        week: t('client_dashboard.calendar.week'),
        day: t('client_dashboard.calendar.day'),
    },
    events: schedulePreviewEvents.value,
    editable: false,
    selectable: false,
    height: 'auto',
    eventClassNames(info) {
        return info.event.extendedProps?.preview ? ['preview-event'] : [];
    },
}));

watchEffect(() => {
    props.invoicesDue?.forEach((invoice) => {
        if (paymentAmounts[invoice.id] === undefined) {
            paymentAmounts[invoice.id] = invoice.balance_due || 0;
        }
        if (
            paymentMethods[invoice.id] === undefined
            || !allowedPaymentMethods.value.includes(paymentMethods[invoice.id])
        ) {
            paymentMethods[invoice.id] = defaultPaymentMethod.value;
        }
        if (paymentTipEnabled[invoice.id] === undefined) {
            paymentTipEnabled[invoice.id] = false;
        }
        if (paymentTipModes[invoice.id] === undefined) {
            paymentTipModes[invoice.id] = 'percent';
        }
        if (paymentTipPercents[invoice.id] === undefined) {
            paymentTipPercents[invoice.id] = defaultTipPercent.value;
        }
        if (paymentTipFixedAmounts[invoice.id] === undefined) {
            paymentTipFixedAmounts[invoice.id] = 0;
        }
    });
    props.quoteRatingsDue?.forEach((quote) => {
        if (!ratingForms.quotes[quote.id]) {
            ratingForms.quotes[quote.id] = {
                rating: 5,
                feedback: '',
            };
        }
    });
    props.workRatingsDue?.forEach((work) => {
        if (!ratingForms.works[work.id]) {
            ratingForms.works[work.id] = {
                rating: 5,
                feedback: '',
            };
        }
    });
});

const openSchedulePreview = (work) => {
    schedulePreviewWork.value = work;
    schedulePreviewOpen.value = true;

    nextTick(() => {
        if (schedulePreviewCalendar.value) {
            schedulePreviewCalendar.value.getApi().updateSize();
        }
    });
};

const closeSchedulePreview = () => {
    schedulePreviewOpen.value = false;
    schedulePreviewWork.value = null;
};

const openTaskProof = (task) => {
    if (autoValidation.value.tasks) {
        return;
    }

    taskProofTask.value = task;
    taskProofForm.reset();
    taskProofForm.clearErrors();
    taskProofOpen.value = true;
};

const closeTaskProof = () => {
    taskProofOpen.value = false;
    taskProofTask.value = null;
};

const handleTaskProofFile = async (event) => {
    const file = event.target.files?.[0] || null;
    taskProofForm.clearErrors('file');
    if (!file) {
        taskProofForm.file = null;
        return;
    }
    const result = await prepareMediaFile(file, {
        maxImageBytes: MEDIA_LIMITS.maxImageBytes,
        maxVideoBytes: MEDIA_LIMITS.maxVideoBytes,
    });
    if (result.error) {
        taskProofForm.setError('file', result.error);
        taskProofForm.file = null;
        return;
    }
    taskProofForm.file = result.file;
};

const submitTaskProof = () => {
    if (autoValidation.value.tasks) {
        return;
    }

    const taskId = taskProofTask.value?.id;
    if (!taskId || taskProofForm.processing) {
        return;
    }

    taskProofForm.post(route('portal.tasks.media.store', taskId), {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            closeTaskProof();
        },
    });
};

const acceptQuote = (quoteId) => {
    router.post(route('portal.quotes.accept', quoteId), {}, { preserveScroll: true });
};

const declineQuote = (quoteId) => {
    router.post(route('portal.quotes.decline', quoteId), {}, { preserveScroll: true });
};

const validateWork = (workId) => {
    router.post(route('portal.works.validate', workId), {}, { preserveScroll: true });
};

const confirmSchedule = (workId, closeOnSuccess = false) => {
    if (!workId) {
        return;
    }

    router.post(route('portal.works.schedule.confirm', workId), {}, {
        preserveScroll: true,
        onSuccess: () => {
            if (closeOnSuccess) {
                closeSchedulePreview();
            }
        },
    });
};

const rejectSchedule = (workId) => {
    if (!workId) {
        return;
    }

    router.post(route('portal.works.schedule.reject', workId), {}, {
        preserveScroll: true,
        onSuccess: () => {
            closeSchedulePreview();
        },
    });
};

const disputeWork = (workId) => {
    router.post(route('portal.works.dispute', workId), {}, { preserveScroll: true });
};

const roundMoney = (value) => Math.round((Number(value || 0) + Number.EPSILON) * 100) / 100;

const invoiceAmountValue = (invoiceId) => {
    const rawValue = Number(paymentAmounts[invoiceId] || 0);
    return Number.isFinite(rawValue) ? Math.max(0, rawValue) : 0;
};

const tipPercentValue = (invoiceId) => {
    const rawValue = Number(paymentTipPercents[invoiceId] || 0);
    if (!Number.isFinite(rawValue)) {
        return 0;
    }
    return Math.max(0, Math.min(rawValue, maxTipPercent.value));
};

const tipFixedAmountValue = (invoiceId) => {
    const rawValue = Number(paymentTipFixedAmounts[invoiceId] || 0);
    if (!Number.isFinite(rawValue)) {
        return 0;
    }
    return Math.max(0, Math.min(rawValue, maxTipFixedAmount.value));
};

const tipAmountValue = (invoiceId) => {
    if (!paymentTipEnabled[invoiceId]) {
        return 0;
    }

    if (paymentTipModes[invoiceId] === 'percent') {
        return roundMoney(invoiceAmountValue(invoiceId) * (tipPercentValue(invoiceId) / 100));
    }

    return roundMoney(tipFixedAmountValue(invoiceId));
};

const totalChargeValue = (invoiceId) => roundMoney(invoiceAmountValue(invoiceId) + tipAmountValue(invoiceId));
const invoiceBalanceDue = (invoice) => {
    const value = Number(invoice?.balance_due || 0);
    return Number.isFinite(value) ? Math.max(0, value) : 0;
};
const amountExceedsBalance = (invoice) => invoiceAmountValue(invoice.id) > invoiceBalanceDue(invoice) + 0.0001;
const remainingBalanceAfterPayment = (invoice) =>
    roundMoney(Math.max(0, invoiceBalanceDue(invoice) - invoiceAmountValue(invoice.id)));
const isPartialPayment = (invoice) => {
    const amount = invoiceAmountValue(invoice.id);
    const due = invoiceBalanceDue(invoice);
    return amount > 0 && amount < due;
};
const canSubmitInvoicePayment = (invoice) => {
    if (autoValidation.value.invoices) {
        return false;
    }

    const amount = invoiceAmountValue(invoice.id);
    return amount >= 0.01 && !amountExceedsBalance(invoice);
};
const setInvoicePaymentAmount = (invoice, value) => {
    if (!invoice) {
        return;
    }

    const normalized = roundMoney(Math.max(0, Math.min(Number(value || 0), invoiceBalanceDue(invoice))));
    paymentAmounts[invoice.id] = normalized;
};

const tipPayload = (invoiceId) => {
    if (!paymentTipEnabled[invoiceId]) {
        return {
            tip_enabled: false,
            tip_mode: 'none',
            tip_percent: null,
            tip_amount: 0,
        };
    }

    const mode = paymentTipModes[invoiceId] === 'fixed' ? 'fixed' : 'percent';
    if (mode === 'percent') {
        return {
            tip_enabled: true,
            tip_mode: 'percent',
            tip_percent: tipPercentValue(invoiceId),
            tip_amount: 0,
        };
    }

    return {
        tip_enabled: true,
        tip_mode: 'fixed',
        tip_percent: null,
        tip_amount: tipFixedAmountValue(invoiceId),
    };
};

const submitPayment = (invoice) => {
    if (!invoice || !canSubmitInvoicePayment(invoice)) {
        return;
    }

    const amount = invoiceAmountValue(invoice.id);
    router.post(
        route('portal.invoices.payments.store', invoice.id),
        {
            amount,
            method: paymentMethods[invoice.id] || defaultPaymentMethod.value,
            ...tipPayload(invoice.id),
        },
        { preserveScroll: true }
    );
};

const canUseStripeForInvoice = (invoice) => {
    if (!invoice) {
        return false;
    }
    return canUseStripeMethod.value && canSubmitInvoicePayment(invoice);
};

const startStripePayment = (invoice) => {
    if (!canUseStripeForInvoice(invoice)) {
        return;
    }
    if (stripeProcessing[invoice.id]) {
        return;
    }

    stripeProcessing[invoice.id] = true;
    const amount = invoiceAmountValue(invoice.id);
    const payload = {
        amount,
        ...tipPayload(invoice.id),
    };

    router.post(route('portal.invoices.stripe', invoice.id), payload, {
        preserveScroll: true,
        onFinish: () => {
            stripeProcessing[invoice.id] = false;
        },
    });
};

const submitQuoteRating = (quoteId) => {
    const form = ratingForms.quotes[quoteId];
    if (!form) {
        return;
    }
    router.post(
        route('portal.quotes.ratings.store', quoteId),
        { rating: form.rating, feedback: form.feedback },
        {
            preserveScroll: true,
            onSuccess: () => {
                form.rating = 5;
                form.feedback = '';
            },
        }
    );
};

const submitWorkRating = (workId) => {
    const form = ratingForms.works[workId];
    if (!form) {
        return;
    }
    router.post(
        route('portal.works.ratings.store', workId),
        { rating: form.rating, feedback: form.feedback },
        {
            preserveScroll: true,
            onSuccess: () => {
                form.rating = 5;
                form.feedback = '';
            },
        }
    );
};
</script>

<template>
    <Head :title="$t('client_dashboard.title')" />

    <AuthenticatedLayout>
        <div class="space-y-6">
            <section
                class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                    <div class="space-y-1">
                        <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">
                            {{ $t('client_dashboard.title') }}
                        </h1>
                        <p class="text-sm text-stone-600 dark:text-neutral-400">
                            {{ $t('client_dashboard.welcome', { name: userName }) }}
                        </p>
                    </div>
                </div>
                <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3">
                    <div class="p-4 bg-white border border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-xs text-stone-500 dark:text-neutral-400">{{ $t('client_dashboard.kpi.quotes_pending') }}</p>
                            <KpiTrendBadge :trend="kpiData.quotes_pending.trend" />
                        </div>
                        <p class="mt-1 text-2xl font-semibold text-stone-800 dark:text-neutral-100">
                            {{ stat('quotes_pending') }}
                        </p>
                        <KpiSparkline :points="kpiData.quotes_pending.points" />
                    </div>
                    <div class="p-4 bg-white border border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-xs text-stone-500 dark:text-neutral-400">{{ $t('client_dashboard.kpi.jobs_pending') }}</p>
                            <KpiTrendBadge :trend="kpiData.works_pending.trend" />
                        </div>
                        <p class="mt-1 text-2xl font-semibold text-stone-800 dark:text-neutral-100">
                            {{ stat('works_pending') }}
                        </p>
                        <KpiSparkline :points="kpiData.works_pending.points" />
                    </div>
                    <div v-if="!autoValidation.invoices"
                        class="p-4 bg-white border border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-xs text-stone-500 dark:text-neutral-400">{{ $t('client_dashboard.kpi.invoices_due') }}</p>
                            <KpiTrendBadge :trend="kpiData.invoices_due.trend" />
                        </div>
                        <p class="mt-1 text-2xl font-semibold text-stone-800 dark:text-neutral-100">
                            {{ stat('invoices_due') }}
                        </p>
                        <KpiSparkline :points="kpiData.invoices_due.points" />
                    </div>
                    <div class="p-4 bg-white border border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-xs text-stone-500 dark:text-neutral-400">{{ $t('client_dashboard.kpi.ratings_due') }}</p>
                            <KpiTrendBadge :trend="kpiData.ratings_due.trend" />
                        </div>
                        <p class="mt-1 text-2xl font-semibold text-stone-800 dark:text-neutral-100">
                            {{ stat('ratings_due') }}
                        </p>
                        <KpiSparkline :points="kpiData.ratings_due.points" />
                    </div>
                </div>
            </section>

            <div v-if="profileMissing"
                class="rounded-sm border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-200">
                {{ $t('client_dashboard.profile_missing') }}
            </div>

            <section v-else class="grid grid-cols-1 xl:grid-cols-3 gap-4">
                <div class="bg-white border border-stone-200 rounded-sm p-5 shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                    <div class="flex items-center justify-between">
                        <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                            {{ $t('client_dashboard.sections.quotes_pending') }}
                        </h2>
                    </div>
                    <div class="mt-4 space-y-3">
                        <div v-for="quote in pendingQuotes" :key="quote.id"
                            class="flex flex-col gap-3 rounded-sm border border-stone-200 p-3 text-sm dark:border-neutral-700">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div>
                                    <div class="font-medium text-stone-800 dark:text-neutral-100">
                                        {{ quote.number || $t('client_dashboard.labels.quote_fallback') }} - {{ quote.job_title || $t('client_dashboard.labels.job_fallback') }}
                                    </div>
                                    <div class="text-xs text-stone-500 dark:text-neutral-400">
                                        {{ $t('client_dashboard.labels.sent_on', { date: formatDate(quote.created_at) }) }}
                                    </div>
                                </div>
                                <div class="text-right">
                                    <div class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                        {{ formatCurrency(quote.total) }}
                                    </div>
                                    <span class="px-2 py-0.5 text-xs font-medium rounded-full"
                                        :class="statusClass(quote.status)">
                                        {{ formatStatus(quote.status, 'dashboard.status.quote') }}
                                    </span>
                                </div>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <button type="button" @click="acceptQuote(quote.id)"
                                    class="py-2 px-3 inline-flex items-center gap-x-2 text-xs font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700">
                                    {{ $t('client_dashboard.actions.accept') }}
                                </button>
                                <button type="button" @click="declineQuote(quote.id)"
                                    class="py-2 px-3 inline-flex items-center gap-x-2 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200">
                                    {{ $t('client_dashboard.actions.decline') }}
                                </button>
                                <span v-if="quote.initial_deposit > 0" class="text-xs text-stone-500 dark:text-neutral-400">
                                    {{ $t('client_dashboard.labels.required_deposit', { amount: formatCurrency(quote.initial_deposit) }) }}
                                </span>
                            </div>
                        </div>
                        <div v-if="!pendingQuotes.length" class="text-sm text-stone-500 dark:text-neutral-400">
                            {{ $t('client_dashboard.empty.quotes_pending') }}
                        </div>
                    </div>
                </div>

                <div class="bg-white border border-stone-200 rounded-sm p-5 shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                    <div class="flex items-center justify-between">
                        <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                            {{ $t('client_dashboard.sections.schedules_pending') }}
                        </h2>
                    </div>
                    <div class="mt-4 space-y-3">
                        <div v-for="work in pendingSchedules" :key="`schedule-${work.id}`"
                            class="flex flex-col gap-3 rounded-sm border border-stone-200 p-3 text-sm dark:border-neutral-700">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div>
                                    <div class="font-medium text-stone-800 dark:text-neutral-100">
                                        {{ work.job_title || $t('client_dashboard.labels.job_fallback') }}
                                    </div>
                                    <div class="text-xs text-stone-500 dark:text-neutral-400">
                                        {{ formatDate(work.start_date) }} {{ work.start_time || '' }}
                                    </div>
                                    <div v-if="work.frequency" class="text-xs text-stone-500 dark:text-neutral-400">
                                        {{ formatStatus(String(work.frequency || '').toLowerCase(), 'client_dashboard.frequency') }}
                                    </div>
                                </div>
                                <span class="px-2 py-0.5 text-xs font-medium rounded-full"
                                    :class="statusClass(work.status)">
                                    {{ formatStatus(work.status, 'dashboard.status.work') }}
                                </span>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <button type="button" @click="openSchedulePreview(work)"
                                    class="py-2 px-3 inline-flex items-center gap-x-2 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200">
                                    {{ $t('client_dashboard.actions.review_schedule') }}
                                </button>
                            </div>
                        </div>
                        <div v-if="!pendingSchedules.length" class="text-sm text-stone-500 dark:text-neutral-400">
                            {{ $t('client_dashboard.empty.schedules_pending') }}
                        </div>
                    </div>
                </div>

                <div class="bg-white border border-stone-200 rounded-sm p-5 shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                    <div class="flex items-center justify-between">
                        <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                            {{ $t('client_dashboard.sections.jobs_pending') }}
                        </h2>
                    </div>
                    <div class="mt-4 space-y-3">
                        <div v-for="work in pendingWorks" :key="work.id"
                            class="flex flex-col gap-3 rounded-sm border border-stone-200 p-3 text-sm dark:border-neutral-700">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div>
                                    <div class="font-medium text-stone-800 dark:text-neutral-100">
                                        {{ work.job_title || $t('client_dashboard.labels.job_fallback') }}
                                    </div>
                                    <div class="text-xs text-stone-500 dark:text-neutral-400">
                                        {{ $t('client_dashboard.labels.completed_on', { date: formatDate(work.completed_at) }) }}
                                    </div>
                                </div>
                                <span class="px-2 py-0.5 text-xs font-medium rounded-full"
                                    :class="statusClass(work.status)">
                                    {{ formatStatus(work.status, 'dashboard.status.work') }}
                                </span>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <button type="button" @click="validateWork(work.id)"
                                    class="py-2 px-3 inline-flex items-center gap-x-2 text-xs font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700">
                                    {{ $t('client_dashboard.actions.validate_job') }}
                                </button>
                                <button type="button" @click="disputeWork(work.id)"
                                    class="py-2 px-3 inline-flex items-center gap-x-2 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200">
                                    {{ $t('client_dashboard.actions.dispute') }}
                                </button>
                            </div>
                        </div>
                        <div v-if="!pendingWorks.length" class="text-sm text-stone-500 dark:text-neutral-400">
                            {{ $t('client_dashboard.empty.jobs_pending') }}
                        </div>
                    </div>
                </div>
            </section>

            <section v-if="!profileMissing && !autoValidation.tasks"
                class="bg-white border border-stone-200 rounded-sm p-5 shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                        {{ $t('client_dashboard.sections.task_proofs') }}
                    </h2>
                </div>
                <div class="mt-4 space-y-3">
                    <div v-for="task in taskProofs" :key="`proof-${task.id}`"
                        class="flex flex-col gap-3 rounded-sm border border-stone-200 p-3 text-sm dark:border-neutral-700">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <div class="font-medium text-stone-800 dark:text-neutral-100">
                                    {{ task.title || $t('client_dashboard.labels.task_fallback') }}
                                </div>
                                <div class="text-xs text-stone-500 dark:text-neutral-400">
                                    {{ formatDate(task.due_date) }} {{ task.start_time || '' }}
                                </div>
                                <div v-if="task.work_title" class="text-xs text-stone-500 dark:text-neutral-400">
                                    {{ task.work_title }}
                                </div>
                            </div>
                            <span class="px-2 py-0.5 text-xs font-medium rounded-full"
                                :class="statusClass(task.status)">
                                {{ formatStatus(task.status, 'dashboard.status.task') }}
                            </span>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <button type="button" @click="openTaskProof(task)"
                                class="py-2 px-3 inline-flex items-center gap-x-2 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200">
                                {{ $t('client_dashboard.actions.add_proof') }}
                            </button>
                            <Link v-if="task.work_id" :href="route('portal.works.proofs', task.work_id)"
                                class="py-2 px-3 inline-flex items-center gap-x-2 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200">
                                {{ $t('client_dashboard.actions.view_proofs') }}
                            </Link>
                        </div>
                    </div>
                    <div v-if="!taskProofs.length" class="text-sm text-stone-500 dark:text-neutral-400">
                        {{ $t('client_dashboard.empty.tasks') }}
                    </div>
                </div>
            </section>

            <section v-if="!profileMissing"
                :class="['grid grid-cols-1 gap-4', autoValidation.invoices ? 'xl:grid-cols-1' : 'xl:grid-cols-2']">
                <div v-if="!autoValidation.invoices"
                    class="bg-white border border-stone-200 rounded-sm p-5 shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                    <div class="flex items-center justify-between">
                        <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                            {{ $t('client_dashboard.sections.invoices_due') }}
                        </h2>
                    </div>
                    <div class="mt-4 space-y-3">
                        <div v-for="invoice in invoicesDue" :key="invoice.id"
                            class="flex flex-col gap-3 rounded-sm border border-stone-200 p-3 text-sm dark:border-neutral-700">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div>
                                    <Link
                                        :href="route('portal.invoices.show', invoice.id)"
                                        class="font-medium text-stone-800 hover:text-green-700 hover:underline dark:text-neutral-100"
                                    >
                                        {{ invoice.number || $t('client_dashboard.labels.invoice_fallback') }}
                                    </Link>
                                    <div class="text-xs text-stone-500 dark:text-neutral-400">
                                        {{ $t('client_dashboard.labels.issued_on', { date: formatDate(invoice.created_at) }) }}
                                    </div>
                                </div>
                                <div class="text-right">
                                    <div class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                        {{ formatCurrency(invoice.balance_due) }}
                                    </div>
                                    <span class="px-2 py-0.5 text-xs font-medium rounded-full"
                                        :class="statusClass(invoice.status)">
                                        {{ formatStatus(invoice.status, 'dashboard.status.invoice') }}
                                    </span>
                                </div>
                            </div>
                            <form class="space-y-3" @submit.prevent="submitPayment(invoice)">
                                <div class="space-y-2">
                                    <input
                                        v-model.number="paymentAmounts[invoice.id]"
                                        type="number"
                                        min="0.01"
                                        :max="invoice.balance_due"
                                        step="0.01"
                                        class="w-40 py-2 px-3 rounded-sm border border-stone-200 text-sm text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                                        :placeholder="$t('client_dashboard.labels.amount')"
                                    />
                                    <div v-if="amountExceedsBalance(invoice)" class="text-xs text-red-600">
                                        {{ $t('client_dashboard.labels.amount_exceeds_due', { amount: formatCurrency(invoice.balance_due) }) }}
                                    </div>
                                    <p class="text-[11px] text-stone-500 dark:text-neutral-400">
                                        {{ $t('client_dashboard.labels.partial_payment_help') }}
                                    </p>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <button
                                            type="button"
                                            class="rounded-sm border border-stone-200 bg-white px-2.5 py-1 text-xs font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200 dark:hover:bg-neutral-700"
                                            @click="setInvoicePaymentAmount(invoice, invoice.balance_due)"
                                        >
                                            {{ $t('client_dashboard.labels.pay_full_balance') }}
                                        </button>
                                        <button
                                            type="button"
                                            class="rounded-sm border border-stone-200 bg-white px-2.5 py-1 text-xs font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200 dark:hover:bg-neutral-700"
                                            @click="setInvoicePaymentAmount(invoice, roundMoney(invoice.balance_due * 0.5))"
                                        >
                                            {{ $t('client_dashboard.labels.pay_half') }}
                                        </button>
                                        <button
                                            type="button"
                                            class="rounded-sm border border-stone-200 bg-white px-2.5 py-1 text-xs font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200 dark:hover:bg-neutral-700"
                                            @click="setInvoicePaymentAmount(invoice, roundMoney(invoice.balance_due * 0.25))"
                                        >
                                            {{ $t('client_dashboard.labels.pay_quarter') }}
                                        </button>
                                    </div>

                                    <div v-if="hasMultiplePaymentMethods" class="pt-1">
                                        <label class="block text-[11px] text-stone-500 dark:text-neutral-400">
                                            {{ $t('sales.payments.method_label') }}
                                        </label>
                                        <select
                                            v-model="paymentMethods[invoice.id]"
                                            class="mt-1 w-40 rounded-sm border border-stone-200 bg-white py-2 px-3 text-sm text-stone-700 focus:border-green-500 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                                        >
                                            <option
                                                v-for="method in allowedPaymentMethods"
                                                :key="`dashboard-invoice-method-${invoice.id}-${method}`"
                                                :value="method"
                                            >
                                                {{ paymentMethodLabel(method) }}
                                            </option>
                                        </select>
                                    </div>
                                    <div v-else class="rounded-sm border border-stone-200 bg-stone-50 px-3 py-2 text-xs text-stone-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300">
                                        Payment method:
                                        <span class="font-semibold text-stone-700 dark:text-neutral-200">
                                            {{ paymentMethodLabel(paymentMethods[invoice.id]) }}
                                        </span>
                                    </div>
                                </div>

                                <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-900">
                                    <div class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                        {{ $t('client_dashboard.labels.tip_prompt_title') }}
                                    </div>
                                    <p class="text-xs text-stone-500 dark:text-neutral-400">
                                        {{ $t('client_dashboard.labels.tip_prompt_help') }}
                                    </p>

                                    <div class="mt-3 flex flex-wrap gap-2">
                                        <button
                                            type="button"
                                            class="rounded-sm border px-3 py-1.5 text-xs font-medium"
                                            :class="paymentTipEnabled[invoice.id] ? 'border-emerald-500 bg-emerald-50 text-emerald-700' : 'border-stone-200 bg-white text-stone-700 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200'"
                                            @click="paymentTipEnabled[invoice.id] = true"
                                        >
                                            {{ $t('client_dashboard.labels.tip_yes') }}
                                        </button>
                                        <button
                                            type="button"
                                            class="rounded-sm border px-3 py-1.5 text-xs font-medium"
                                            :class="!paymentTipEnabled[invoice.id] ? 'border-stone-900 bg-stone-900 text-white dark:border-neutral-200 dark:bg-neutral-200 dark:text-neutral-900' : 'border-stone-200 bg-white text-stone-700 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200'"
                                            @click="paymentTipEnabled[invoice.id] = false"
                                        >
                                            {{ $t('client_dashboard.labels.tip_no') }}
                                        </button>
                                    </div>

                                    <div v-if="paymentTipEnabled[invoice.id]" class="mt-3 space-y-3">
                                        <div class="flex flex-wrap gap-2">
                                            <button
                                                type="button"
                                                class="rounded-sm border px-3 py-1.5 text-xs font-medium"
                                                :class="paymentTipModes[invoice.id] === 'percent' ? 'border-slate-900 bg-slate-900 text-white dark:border-neutral-200 dark:bg-neutral-200 dark:text-neutral-900' : 'border-stone-200 bg-white text-stone-700 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200'"
                                                @click="paymentTipModes[invoice.id] = 'percent'"
                                            >
                                                {{ $t('client_dashboard.labels.tip_mode_percent') }}
                                            </button>
                                            <button
                                                type="button"
                                                class="rounded-sm border px-3 py-1.5 text-xs font-medium"
                                                :class="paymentTipModes[invoice.id] === 'fixed' ? 'border-slate-900 bg-slate-900 text-white dark:border-neutral-200 dark:bg-neutral-200 dark:text-neutral-900' : 'border-stone-200 bg-white text-stone-700 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200'"
                                                @click="paymentTipModes[invoice.id] = 'fixed'"
                                            >
                                                {{ $t('client_dashboard.labels.tip_mode_fixed') }}
                                            </button>
                                        </div>

                                        <div v-if="paymentTipModes[invoice.id] === 'percent'" class="space-y-2">
                                            <div class="flex flex-wrap gap-2">
                                                <button
                                                    v-for="value in quickTipPercents"
                                                    :key="`dashboard-tip-percent-${invoice.id}-${value}`"
                                                    type="button"
                                                    class="rounded-sm border px-2.5 py-1 text-xs font-medium"
                                                    :class="tipPercentValue(invoice.id) === value ? 'border-emerald-500 bg-emerald-50 text-emerald-700' : 'border-stone-200 bg-white text-stone-700 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200'"
                                                    @click="paymentTipPercents[invoice.id] = value"
                                                >
                                                    {{ value }}%
                                                </button>
                                            </div>
                                            <input
                                                v-model.number="paymentTipPercents[invoice.id]"
                                                type="number"
                                                min="0"
                                                :max="maxTipPercent"
                                                step="0.01"
                                                class="w-full rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm text-stone-700 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200"
                                                :placeholder="$t('client_dashboard.labels.tip_other_percent')"
                                            />
                                            <div class="text-[11px] text-stone-500 dark:text-neutral-400">
                                                {{ $t('client_dashboard.labels.tip_max_percent', { value: maxTipPercent }) }}
                                            </div>
                                        </div>

                                        <div v-else class="space-y-2">
                                            <div class="flex flex-wrap gap-2">
                                                <button
                                                    v-for="value in quickTipFixedAmounts"
                                                    :key="`dashboard-tip-fixed-${invoice.id}-${value}`"
                                                    type="button"
                                                    class="rounded-sm border px-2.5 py-1 text-xs font-medium"
                                                    :class="tipFixedAmountValue(invoice.id) === value ? 'border-emerald-500 bg-emerald-50 text-emerald-700' : 'border-stone-200 bg-white text-stone-700 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200'"
                                                    @click="paymentTipFixedAmounts[invoice.id] = value"
                                                >
                                                    {{ formatCurrency(value) }}
                                                </button>
                                            </div>
                                            <input
                                                v-model.number="paymentTipFixedAmounts[invoice.id]"
                                                type="number"
                                                min="0"
                                                :max="maxTipFixedAmount"
                                                step="0.01"
                                                class="w-full rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm text-stone-700 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200"
                                                :placeholder="$t('client_dashboard.labels.tip_other_amount')"
                                            />
                                            <div class="text-[11px] text-stone-500 dark:text-neutral-400">
                                                {{ $t('client_dashboard.labels.tip_max_amount', { amount: formatCurrency(maxTipFixedAmount) }) }}
                                            </div>
                                        </div>
                                    </div>

                                    <p class="mt-2 text-[11px] text-stone-500 dark:text-neutral-400">
                                        {{ $t('client_dashboard.labels.tip_change_before_pay') }}
                                    </p>
                                </div>

                                <div class="rounded-sm border border-stone-200 bg-stone-50 px-3 py-2 text-xs text-stone-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300">
                                    <div class="flex items-center justify-between">
                                        <span>{{ $t('client_dashboard.labels.subtotal') }}</span>
                                        <span class="font-medium text-stone-800 dark:text-neutral-100">
                                            {{ formatCurrency(invoiceAmountValue(invoice.id)) }}
                                        </span>
                                    </div>
                                    <div class="mt-1 flex items-center justify-between">
                                        <span>{{ $t('client_dashboard.labels.tip_optional') }}</span>
                                        <span class="font-medium text-stone-800 dark:text-neutral-100">
                                            {{ formatCurrency(tipAmountValue(invoice.id)) }}
                                        </span>
                                    </div>
                                    <div class="mt-2 flex items-center justify-between border-t border-stone-200 pt-2 dark:border-neutral-700">
                                        <span class="font-semibold text-stone-800 dark:text-neutral-100">{{ $t('client_dashboard.labels.total_charge') }}</span>
                                        <span class="font-semibold text-stone-900 dark:text-neutral-100">
                                            {{ formatCurrency(totalChargeValue(invoice.id)) }}
                                        </span>
                                    </div>
                                </div>
                                <p class="text-[11px] text-stone-500 dark:text-neutral-400">
                                    <template v-if="isPartialPayment(invoice)">
                                        {{ $t('client_dashboard.labels.partial_selected_remaining', { amount: formatCurrency(remainingBalanceAfterPayment(invoice)) }) }}
                                    </template>
                                    <template v-else>
                                        {{ $t('client_dashboard.labels.remaining_after_payment', { amount: formatCurrency(remainingBalanceAfterPayment(invoice)) }) }}
                                    </template>
                                </p>

                                <div class="space-y-2 pt-1">
                                    <button
                                        type="submit"
                                        :disabled="!canSubmitInvoicePayment(invoice)"
                                        class="w-full py-2 px-3 inline-flex items-center justify-center gap-x-2 text-xs font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50"
                                    >
                                        {{ $t('client_dashboard.actions.pay_now') }}
                                    </button>
                                    <button
                                        v-if="canUseStripeMethod"
                                        type="button"
                                        :disabled="stripeProcessing[invoice.id] || !canUseStripeForInvoice(invoice)"
                                        class="w-full py-2 px-3 inline-flex items-center justify-center gap-x-2 text-xs font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-800 disabled:opacity-50"
                                        @click="startStripePayment(invoice)"
                                    >
                                        {{ $t('client_dashboard.actions.pay_with_stripe') }}
                                    </button>
                                </div>

                                <div class="flex flex-wrap items-center gap-3 text-xs">
                                    <span class="text-stone-500 dark:text-neutral-400">
                                        {{ $t('client_dashboard.labels.paid_of', { paid: formatCurrency(invoice.amount_paid), total: formatCurrency(invoice.total) }) }}
                                    </span>
                                    <span class="text-stone-500 dark:text-neutral-400">
                                        {{ $t('client_dashboard.labels.total_to_charge', { total: formatCurrency(totalChargeValue(invoice.id)) }) }}
                                    </span>
                                </div>
                            </form>
                        </div>
                        <div v-if="!invoicesDue.length" class="text-sm text-stone-500 dark:text-neutral-400">
                            {{ $t('client_dashboard.empty.invoices_due') }}
                        </div>
                    </div>
                </div>

                <div class="bg-white border border-stone-200 rounded-sm p-5 shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                    <div class="flex items-center justify-between">
                        <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                            {{ $t('client_dashboard.sections.ratings_due') }}
                        </h2>
                    </div>
                    <div class="mt-4 space-y-4">
                        <div>
                            <h3 class="text-xs font-semibold uppercase text-stone-500 dark:text-neutral-400">
                                {{ $t('client_dashboard.sections.quotes') }}
                            </h3>
                            <div class="mt-2 space-y-3">
                                <form v-for="quote in quoteRatingsDue" :key="`quote-${quote.id}`"
                                    class="rounded-sm border border-stone-200 p-3 text-sm dark:border-neutral-700"
                                    @submit.prevent="submitQuoteRating(quote.id)">
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <div class="font-medium text-stone-800 dark:text-neutral-100">
                                            {{ quote.number || $t('client_dashboard.labels.quote_fallback') }} - {{ quote.job_title || $t('client_dashboard.labels.job_fallback') }}
                                        </div>
                                        <span class="px-2 py-0.5 text-xs font-medium rounded-full"
                                            :class="statusClass(quote.status)">
                                            {{ formatStatus(quote.status, 'dashboard.status.quote') }}
                                        </span>
                                    </div>
                                    <div class="mt-2 flex flex-wrap items-center gap-2">
                                        <FloatingSelect
                                            v-model="ratingForms.quotes[quote.id].rating"
                                            :label="$t('client_dashboard.ratings.label')"
                                            :options="ratingOptions"
                                            dense
                                            class="min-w-[140px] text-sm"
                                        />
                                        <input v-model="ratingForms.quotes[quote.id].feedback" type="text"
                                            class="flex-1 min-w-[160px] py-2 px-3 rounded-sm border border-stone-200 text-sm text-stone-700 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                                            :placeholder="$t('client_dashboard.labels.feedback_placeholder')" />
                                        <button type="submit"
                                            class="py-2 px-3 inline-flex items-center gap-x-2 text-xs font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700">
                                            {{ $t('client_dashboard.actions.submit') }}
                                        </button>
                                    </div>
                                </form>
                                <div v-if="!quoteRatingsDue.length" class="text-sm text-stone-500 dark:text-neutral-400">
                                    {{ $t('client_dashboard.empty.quote_ratings') }}
                                </div>
                            </div>
                        </div>
                        <div>
                            <h3 class="text-xs font-semibold uppercase text-stone-500 dark:text-neutral-400">
                                {{ $t('client_dashboard.sections.jobs') }}
                            </h3>
                            <div class="mt-2 space-y-3">
                                <form v-for="work in workRatingsDue" :key="`work-${work.id}`"
                                    class="rounded-sm border border-stone-200 p-3 text-sm dark:border-neutral-700"
                                    @submit.prevent="submitWorkRating(work.id)">
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <div class="font-medium text-stone-800 dark:text-neutral-100">
                                            {{ work.job_title || $t('client_dashboard.labels.job_fallback') }}
                                        </div>
                                        <span class="px-2 py-0.5 text-xs font-medium rounded-full"
                                            :class="statusClass(work.status)">
                                            {{ formatStatus(work.status, 'dashboard.status.work') }}
                                        </span>
                                    </div>
                                    <div class="mt-2 flex flex-wrap items-center gap-2">
                                        <FloatingSelect
                                            v-model="ratingForms.works[work.id].rating"
                                            :label="$t('client_dashboard.ratings.label')"
                                            :options="ratingOptions"
                                            dense
                                            class="min-w-[140px] text-sm"
                                        />
                                        <input v-model="ratingForms.works[work.id].feedback" type="text"
                                            class="flex-1 min-w-[160px] py-2 px-3 rounded-sm border border-stone-200 text-sm text-stone-700 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                                            :placeholder="$t('client_dashboard.labels.feedback_placeholder')" />
                                        <button type="submit"
                                            class="py-2 px-3 inline-flex items-center gap-x-2 text-xs font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700">
                                            {{ $t('client_dashboard.actions.submit') }}
                                        </button>
                                    </div>
                                </form>
                                <div v-if="!workRatingsDue.length" class="text-sm text-stone-500 dark:text-neutral-400">
                                    {{ $t('client_dashboard.empty.job_ratings') }}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section v-if="!profileMissing" class="bg-white border border-stone-200 rounded-sm p-5 shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                        {{ $t('client_dashboard.sections.recently_validated') }}
                    </h2>
                </div>
                <div class="mt-4 grid grid-cols-1 lg:grid-cols-2 gap-4 text-sm">
                    <div>
                        <h3 class="text-xs font-semibold uppercase text-stone-500 dark:text-neutral-400">
                            {{ $t('client_dashboard.sections.quotes') }}
                        </h3>
                        <div class="mt-2 space-y-2">
                            <div v-for="quote in validatedQuotes" :key="`validated-quote-${quote.id}`"
                                class="flex items-center justify-between rounded-sm border border-stone-200 px-3 py-2 dark:border-neutral-700">
                                <div>
                                    <div class="font-medium text-stone-800 dark:text-neutral-100">
                                        {{ quote.number || $t('client_dashboard.labels.quote_fallback') }} - {{ quote.job_title || $t('client_dashboard.labels.job_fallback') }}
                                    </div>
                                    <div class="text-xs text-stone-500 dark:text-neutral-400">
                                        {{ formatDate(quote.decided_at) }}
                                    </div>
                                </div>
                                <span class="px-2 py-0.5 text-xs font-medium rounded-full"
                                    :class="statusClass(quote.status)">
                                    {{ formatStatus(quote.status, 'dashboard.status.quote') }}
                                </span>
                            </div>
                            <div v-if="!validatedQuotes.length" class="text-sm text-stone-500 dark:text-neutral-400">
                                {{ $t('client_dashboard.empty.validated_quotes') }}
                            </div>
                        </div>
                    </div>
                    <div>
                        <h3 class="text-xs font-semibold uppercase text-stone-500 dark:text-neutral-400">
                            {{ $t('client_dashboard.sections.jobs') }}
                        </h3>
                        <div class="mt-2 space-y-2">
                            <div v-for="work in validatedWorks" :key="`validated-work-${work.id}`"
                                class="flex items-center justify-between rounded-sm border border-stone-200 px-3 py-2 dark:border-neutral-700">
                                <div>
                                    <div class="font-medium text-stone-800 dark:text-neutral-100">
                                        {{ work.job_title || $t('client_dashboard.labels.job_fallback') }}
                                    </div>
                                    <div class="text-xs text-stone-500 dark:text-neutral-400">
                                        {{ formatDate(work.completed_at) }}
                                    </div>
                                </div>
                                <span class="px-2 py-0.5 text-xs font-medium rounded-full"
                                    :class="statusClass(work.status)">
                                    {{ formatStatus(work.status, 'dashboard.status.work') }}
                                </span>
                            </div>
                            <div v-if="!validatedWorks.length" class="text-sm text-stone-500 dark:text-neutral-400">
                                {{ $t('client_dashboard.empty.validated_jobs') }}
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <div v-if="schedulePreviewOpen" class="fixed inset-0 z-50 flex items-center justify-center px-4 py-6">
            <div class="absolute inset-0 bg-stone-900/60" @click="closeSchedulePreview"></div>
            <div
                class="relative w-full max-w-5xl rounded-sm border border-stone-200 bg-white p-5 shadow-lg dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h3 class="text-base font-semibold text-stone-800 dark:text-neutral-100">
                            {{ $t('client_dashboard.sections.schedule_preview') }}
                        </h3>
                        <p class="text-sm text-stone-500 dark:text-neutral-400">
                            {{ schedulePreviewWork?.job_title || $t('client_dashboard.labels.job_fallback') }}
                        </p>
                    </div>
                    <button type="button" @click="closeSchedulePreview"
                        class="py-1.5 px-3 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200">
                        {{ $t('client_dashboard.actions.close') }}
                    </button>
                </div>

                <div v-if="schedulePreviewWork" class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-3">
                    <div class="space-y-3 lg:col-span-1">
                        <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800">
                            <div class="text-xs font-semibold uppercase text-stone-500 dark:text-neutral-400">
                                {{ $t('client_dashboard.labels.summary') }}
                            </div>
                            <div class="mt-2 space-y-2 text-sm text-stone-700 dark:text-neutral-200">
                                <div class="flex items-center justify-between">
                                    <span>{{ $t('client_dashboard.labels.start_date') }}</span>
                                    <span>{{ formatCalendarDate(schedulePreviewWork.start_date) }}</span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span>{{ $t('client_dashboard.labels.time') }}</span>
                                    <span>{{ formatTimeRange(schedulePreviewWork.start_time, schedulePreviewWork.end_time) }}</span>
                                </div>
                                <div v-if="schedulePreviewIsRecurring" class="flex items-center justify-between">
                                    <span>{{ $t('client_dashboard.labels.frequency') }}</span>
                                    <span>{{ formatStatus(String(schedulePreviewWork.frequency || '').toLowerCase(), 'client_dashboard.frequency') }}</span>
                                </div>
                                <div v-if="schedulePreviewIsRecurring" class="flex items-center justify-between">
                                    <span>{{ $t('client_dashboard.labels.visits') }}</span>
                                    <span>{{ schedulePreviewEvents.length || schedulePreviewWork.totalVisits || 0 }}</span>
                                </div>
                                <div v-if="schedulePreviewIsRecurring" class="flex items-center justify-between">
                                    <span>{{ $t('client_dashboard.labels.range') }}</span>
                                    <span>{{ schedulePreviewRange.start }} - {{ schedulePreviewRange.end }}</span>
                                </div>
                            </div>
                        </div>

                        <div class="rounded-sm border border-stone-200 bg-white p-3 dark:border-neutral-700 dark:bg-neutral-900">
                            <div class="text-xs font-semibold uppercase text-stone-500 dark:text-neutral-400">
                                {{ $t('client_dashboard.labels.team') }}
                            </div>
                            <div v-if="schedulePreviewAssignees.length" class="mt-2 flex flex-wrap gap-2">
                                <span v-for="member in schedulePreviewAssignees" :key="member.id"
                                    class="rounded-sm bg-stone-100 px-2 py-1 text-xs text-stone-700 dark:bg-neutral-800 dark:text-neutral-200">
                                    {{ member.name }}
                                </span>
                            </div>
                            <div v-else class="mt-2 text-sm text-stone-500 dark:text-neutral-400">
                                {{ $t('client_dashboard.labels.team_unassigned') }}
                            </div>
                        </div>

                        <div v-if="!schedulePreviewHasEvents"
                            class="rounded-sm border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-200">
                            {{ $t('client_dashboard.empty.schedule_details') }}
                        </div>
                    </div>

                    <div class="lg:col-span-2">
                        <div v-if="schedulePreviewIsRecurring"
                            class="rounded-sm border border-stone-200 bg-white p-2 dark:border-neutral-700 dark:bg-neutral-900">
                            <FullCalendar :options="schedulePreviewCalendarOptions" ref="schedulePreviewCalendar" />
                        </div>
                        <div v-else
                            class="rounded-sm border border-stone-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900">
                            <div class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                {{ $t('client_dashboard.labels.single_visit') }}
                            </div>
                            <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                {{ $t('client_dashboard.labels.single_visit_note') }}
                            </p>
                            <div class="mt-3 grid grid-cols-2 gap-3 text-sm text-stone-700 dark:text-neutral-200">
                                <div>
                                    <div class="text-xs text-stone-500 dark:text-neutral-400">{{ $t('client_dashboard.labels.date') }}</div>
                                    <div>{{ formatCalendarDate(schedulePreviewWork.start_date) }}</div>
                                </div>
                                <div>
                                    <div class="text-xs text-stone-500 dark:text-neutral-400">{{ $t('client_dashboard.labels.time') }}</div>
                                    <div>{{ formatTimeRange(schedulePreviewWork.start_time, schedulePreviewWork.end_time) }}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-5 flex flex-wrap items-center justify-between gap-3">
                    <p class="text-xs text-stone-500 dark:text-neutral-400">
                        {{ $t('client_dashboard.labels.accept_creates_tasks') }}
                    </p>
                    <div class="flex flex-wrap items-center gap-2">
                        <button type="button" @click="rejectSchedule(schedulePreviewId)" :disabled="!schedulePreviewId"
                            class="py-2 px-3 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 disabled:pointer-events-none disabled:opacity-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200">
                            {{ $t('client_dashboard.actions.request_changes') }}
                        </button>
                        <button type="button" @click="confirmSchedule(schedulePreviewId, true)"
                            :disabled="!schedulePreviewId || !schedulePreviewHasEvents"
                            class="py-2 px-3 text-xs font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:pointer-events-none disabled:opacity-50">
                            {{ $t('client_dashboard.actions.accept_schedule') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div v-if="taskProofOpen" class="fixed inset-0 z-50 flex items-center justify-center px-4 py-6">
            <div class="absolute inset-0 bg-stone-900/60" @click="closeTaskProof"></div>
            <div class="relative w-full max-w-lg rounded-sm border border-stone-200 bg-white p-5 shadow-lg dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h3 class="text-base font-semibold text-stone-800 dark:text-neutral-100">
                            {{ $t('client_dashboard.proof.title') }}
                        </h3>
                        <p class="text-sm text-stone-500 dark:text-neutral-400">
                            {{ taskProofTask?.title || $t('client_dashboard.labels.task_fallback') }}
                        </p>
                    </div>
                    <button type="button" @click="closeTaskProof"
                        class="py-1.5 px-3 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200">
                        {{ $t('client_dashboard.actions.close') }}
                    </button>
                </div>

                <form class="mt-4 space-y-4" @submit.prevent="submitTaskProof">
                    <div>
                        <div class="mt-1">
                            <FloatingSelect
                                v-model="taskProofForm.type"
                                :label="$t('client_dashboard.proof.type')"
                                :options="proofTypeOptions"
                            />
                        </div>
                        <div v-if="taskProofForm.errors.type" class="mt-1 text-xs text-red-600">
                            {{ taskProofForm.errors.type }}
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs text-stone-500 dark:text-neutral-400">{{ $t('client_dashboard.proof.file') }}</label>
                        <input type="file" @change="handleTaskProofFile" accept="image/*,video/*"
                            class="mt-1 block w-full text-sm text-stone-600 file:mr-4 file:py-2 file:px-3 file:rounded-sm file:border-0 file:text-sm file:font-medium file:bg-stone-100 file:text-stone-700 hover:file:bg-stone-200 dark:text-neutral-300 dark:file:bg-neutral-800 dark:file:text-neutral-200" />
                        <div v-if="taskProofForm.errors.file" class="mt-1 text-xs text-red-600">
                            {{ taskProofForm.errors.file }}
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs text-stone-500 dark:text-neutral-400">{{ $t('client_dashboard.proof.note_optional') }}</label>
                        <input v-model="taskProofForm.note" type="text"
                            class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200" />
                        <div v-if="taskProofForm.errors.note" class="mt-1 text-xs text-red-600">
                            {{ taskProofForm.errors.note }}
                        </div>
                    </div>

                    <div class="flex justify-end gap-2">
                        <button type="button" @click="closeTaskProof"
                            class="py-2 px-3 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200">
                            {{ $t('client_dashboard.actions.cancel') }}
                        </button>
                        <button type="submit" :disabled="taskProofForm.processing"
                            class="py-2 px-3 text-xs font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:pointer-events-none disabled:opacity-50">
                            {{ $t('client_dashboard.actions.upload') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<style scoped>
:deep(.fc-event.preview-event) {
    background-color: #e2e8f0;
    border-color: #cbd5e1;
    color: #334155;
}

:deep(.dark .fc-event.preview-event) {
    background-color: #1f2937;
    border-color: #374151;
    color: #e5e7eb;
}
</style>
