<script setup>
import { computed, reactive, ref, watchEffect, nextTick } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { humanizeDate } from '@/utils/date';
import FullCalendar from '@fullcalendar/vue3';
import dayGridPlugin from '@fullcalendar/daygrid';
import timeGridPlugin from '@fullcalendar/timegrid';
import { buildPreviewEvents } from '@/utils/schedule';

const props = defineProps({
    stats: {
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
});

const page = usePage();
const userName = computed(() => page.props.auth?.user?.name || 'there');
const autoValidation = computed(() => ({
    tasks: Boolean(props.autoValidation?.tasks),
    invoices: Boolean(props.autoValidation?.invoices),
}));

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

const formatStatus = (status) => (status || 'pending').replace(/_/g, ' ');

const statusClass = (status) => {
    switch (status) {
        case 'accepted':
        case 'validated':
        case 'auto_validated':
        case 'closed':
        case 'completed':
        case 'paid':
            return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-400';
        case 'sent':
        case 'tech_complete':
            return 'bg-blue-100 text-blue-800 dark:bg-blue-500/10 dark:text-blue-400';
        case 'pending_review':
        case 'partial':
            return 'bg-amber-100 text-amber-800 dark:bg-amber-500/10 dark:text-amber-400';
        case 'declined':
        case 'dispute':
        case 'overdue':
            return 'bg-red-100 text-red-800 dark:bg-red-500/10 dark:text-red-400';
        default:
            return 'bg-stone-100 text-stone-700 dark:bg-neutral-700 dark:text-neutral-200';
    }
};

const paymentAmounts = reactive({});
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
        title: work.job_title || 'Job',
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

const handleTaskProofFile = (event) => {
    const file = event.target.files?.[0] || null;
    taskProofForm.file = file;
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

const submitPayment = (invoiceId) => {
    if (autoValidation.value.invoices) {
        return;
    }

    const amount = Number(paymentAmounts[invoiceId] || 0);
    router.post(
        route('portal.invoices.payments.store', invoiceId),
        { amount },
        { preserveScroll: true }
    );
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
    <Head title="Dashboard" />

    <AuthenticatedLayout>
        <div class="space-y-6">
            <section
                class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                    <div class="space-y-1">
                        <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">
                            Client dashboard
                        </h1>
                        <p class="text-sm text-stone-600 dark:text-neutral-400">
                            Welcome back, {{ userName }}. Here is what is waiting for your validation.
                        </p>
                    </div>
                </div>
                <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3">
                    <div class="p-4 bg-white border border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                        <p class="text-xs text-stone-500 dark:text-neutral-400">Quotes awaiting validation</p>
                        <p class="mt-1 text-2xl font-semibold text-stone-800 dark:text-neutral-100">
                            {{ stat('quotes_pending') }}
                        </p>
                    </div>
                    <div class="p-4 bg-white border border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                        <p class="text-xs text-stone-500 dark:text-neutral-400">Jobs awaiting validation</p>
                        <p class="mt-1 text-2xl font-semibold text-stone-800 dark:text-neutral-100">
                            {{ stat('works_pending') }}
                        </p>
                    </div>
                    <div v-if="!autoValidation.invoices"
                        class="p-4 bg-white border border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                        <p class="text-xs text-stone-500 dark:text-neutral-400">Invoices to pay</p>
                        <p class="mt-1 text-2xl font-semibold text-stone-800 dark:text-neutral-100">
                            {{ stat('invoices_due') }}
                        </p>
                    </div>
                    <div class="p-4 bg-white border border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                        <p class="text-xs text-stone-500 dark:text-neutral-400">Ratings to leave</p>
                        <p class="mt-1 text-2xl font-semibold text-stone-800 dark:text-neutral-100">
                            {{ stat('ratings_due') }}
                        </p>
                    </div>
                </div>
            </section>

            <div v-if="profileMissing"
                class="rounded-sm border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-200">
                Your client profile is not linked yet. Please contact the business to connect your account.
            </div>

            <section v-else class="grid grid-cols-1 xl:grid-cols-3 gap-4">
                <div class="bg-white border border-stone-200 rounded-sm p-5 shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                    <div class="flex items-center justify-between">
                        <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                            Quotes awaiting validation
                        </h2>
                    </div>
                    <div class="mt-4 space-y-3">
                        <div v-for="quote in pendingQuotes" :key="quote.id"
                            class="flex flex-col gap-3 rounded-sm border border-stone-200 p-3 text-sm dark:border-neutral-700">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div>
                                    <div class="font-medium text-stone-800 dark:text-neutral-100">
                                        {{ quote.number || 'Quote' }} - {{ quote.job_title || 'Job' }}
                                    </div>
                                    <div class="text-xs text-stone-500 dark:text-neutral-400">
                                        Sent {{ formatDate(quote.created_at) }}
                                    </div>
                                </div>
                                <div class="text-right">
                                    <div class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                        {{ formatCurrency(quote.total) }}
                                    </div>
                                    <span class="px-2 py-0.5 text-xs font-medium rounded-full"
                                        :class="statusClass(quote.status)">
                                        {{ formatStatus(quote.status) }}
                                    </span>
                                </div>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <button type="button" @click="acceptQuote(quote.id)"
                                    class="py-2 px-3 inline-flex items-center gap-x-2 text-xs font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700">
                                    Accept
                                </button>
                                <button type="button" @click="declineQuote(quote.id)"
                                    class="py-2 px-3 inline-flex items-center gap-x-2 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200">
                                    Decline
                                </button>
                                <span v-if="quote.initial_deposit > 0" class="text-xs text-stone-500 dark:text-neutral-400">
                                    Required deposit: {{ formatCurrency(quote.initial_deposit) }}
                                </span>
                            </div>
                        </div>
                        <div v-if="!pendingQuotes.length" class="text-sm text-stone-500 dark:text-neutral-400">
                            No quotes waiting for validation.
                        </div>
                    </div>
                </div>

                <div class="bg-white border border-stone-200 rounded-sm p-5 shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                    <div class="flex items-center justify-between">
                        <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                            Schedules awaiting validation
                        </h2>
                    </div>
                    <div class="mt-4 space-y-3">
                        <div v-for="work in pendingSchedules" :key="`schedule-${work.id}`"
                            class="flex flex-col gap-3 rounded-sm border border-stone-200 p-3 text-sm dark:border-neutral-700">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div>
                                    <div class="font-medium text-stone-800 dark:text-neutral-100">
                                        {{ work.job_title || 'Job' }}
                                    </div>
                                    <div class="text-xs text-stone-500 dark:text-neutral-400">
                                        {{ formatDate(work.start_date) }} {{ work.start_time || '' }}
                                    </div>
                                    <div v-if="work.frequency" class="text-xs text-stone-500 dark:text-neutral-400">
                                        {{ formatStatus(work.frequency) }}
                                    </div>
                                </div>
                                <span class="px-2 py-0.5 text-xs font-medium rounded-full"
                                    :class="statusClass(work.status)">
                                    {{ formatStatus(work.status) }}
                                </span>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <button type="button" @click="openSchedulePreview(work)"
                                    class="py-2 px-3 inline-flex items-center gap-x-2 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200">
                                    Review schedule
                                </button>
                            </div>
                        </div>
                        <div v-if="!pendingSchedules.length" class="text-sm text-stone-500 dark:text-neutral-400">
                            No schedules waiting for validation.
                        </div>
                    </div>
                </div>

                <div class="bg-white border border-stone-200 rounded-sm p-5 shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                    <div class="flex items-center justify-between">
                        <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                            Jobs awaiting validation
                        </h2>
                    </div>
                    <div class="mt-4 space-y-3">
                        <div v-for="work in pendingWorks" :key="work.id"
                            class="flex flex-col gap-3 rounded-sm border border-stone-200 p-3 text-sm dark:border-neutral-700">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div>
                                    <div class="font-medium text-stone-800 dark:text-neutral-100">
                                        {{ work.job_title || 'Job' }}
                                    </div>
                                    <div class="text-xs text-stone-500 dark:text-neutral-400">
                                        Completed {{ formatDate(work.completed_at) }}
                                    </div>
                                </div>
                                <span class="px-2 py-0.5 text-xs font-medium rounded-full"
                                    :class="statusClass(work.status)">
                                    {{ formatStatus(work.status) }}
                                </span>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <button type="button" @click="validateWork(work.id)"
                                    class="py-2 px-3 inline-flex items-center gap-x-2 text-xs font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700">
                                    Validate job
                                </button>
                                <button type="button" @click="disputeWork(work.id)"
                                    class="py-2 px-3 inline-flex items-center gap-x-2 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200">
                                    Dispute
                                </button>
                            </div>
                        </div>
                        <div v-if="!pendingWorks.length" class="text-sm text-stone-500 dark:text-neutral-400">
                            No jobs waiting for validation.
                        </div>
                    </div>
                </div>
            </section>

            <section v-if="!profileMissing && !autoValidation.tasks"
                class="bg-white border border-stone-200 rounded-sm p-5 shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                        Task proofs
                    </h2>
                </div>
                <div class="mt-4 space-y-3">
                    <div v-for="task in taskProofs" :key="`proof-${task.id}`"
                        class="flex flex-col gap-3 rounded-sm border border-stone-200 p-3 text-sm dark:border-neutral-700">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <div class="font-medium text-stone-800 dark:text-neutral-100">
                                    {{ task.title || 'Task' }}
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
                                {{ formatStatus(task.status) }}
                            </span>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <button type="button" @click="openTaskProof(task)"
                                class="py-2 px-3 inline-flex items-center gap-x-2 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200">
                                Add proof
                            </button>
                            <Link v-if="task.work_id" :href="route('portal.works.proofs', task.work_id)"
                                class="py-2 px-3 inline-flex items-center gap-x-2 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200">
                                Voir les preuves
                            </Link>
                        </div>
                    </div>
                    <div v-if="!taskProofs.length" class="text-sm text-stone-500 dark:text-neutral-400">
                        No tasks available yet.
                    </div>
                </div>
            </section>

            <section v-if="!profileMissing"
                :class="['grid grid-cols-1 gap-4', autoValidation.invoices ? 'xl:grid-cols-1' : 'xl:grid-cols-2']">
                <div v-if="!autoValidation.invoices"
                    class="bg-white border border-stone-200 rounded-sm p-5 shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                    <div class="flex items-center justify-between">
                        <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                            Invoices awaiting payment
                        </h2>
                    </div>
                    <div class="mt-4 space-y-3">
                        <div v-for="invoice in invoicesDue" :key="invoice.id"
                            class="flex flex-col gap-3 rounded-sm border border-stone-200 p-3 text-sm dark:border-neutral-700">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div>
                                    <div class="font-medium text-stone-800 dark:text-neutral-100">
                                        {{ invoice.number || 'Invoice' }}
                                    </div>
                                    <div class="text-xs text-stone-500 dark:text-neutral-400">
                                        Issued {{ formatDate(invoice.created_at) }}
                                    </div>
                                </div>
                                <div class="text-right">
                                    <div class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                        {{ formatCurrency(invoice.balance_due) }}
                                    </div>
                                    <span class="px-2 py-0.5 text-xs font-medium rounded-full"
                                        :class="statusClass(invoice.status)">
                                        {{ formatStatus(invoice.status) }}
                                    </span>
                                </div>
                            </div>
                            <form class="flex flex-wrap items-center gap-2" @submit.prevent="submitPayment(invoice.id)">
                                <input v-model.number="paymentAmounts[invoice.id]" type="number" min="0.01" :max="invoice.balance_due" step="0.01"
                                    class="w-32 py-2 px-3 rounded-sm border border-stone-200 text-sm text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                                    placeholder="Amount">
                                <button type="submit"
                                    class="py-2 px-3 inline-flex items-center gap-x-2 text-xs font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700">
                                    Pay now
                                </button>
                                <span class="text-xs text-stone-500 dark:text-neutral-400">
                                    Paid {{ formatCurrency(invoice.amount_paid) }} of {{ formatCurrency(invoice.total) }}
                                </span>
                            </form>
                        </div>
                        <div v-if="!invoicesDue.length" class="text-sm text-stone-500 dark:text-neutral-400">
                            All invoices are settled.
                        </div>
                    </div>
                </div>

                <div class="bg-white border border-stone-200 rounded-sm p-5 shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                    <div class="flex items-center justify-between">
                        <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                            Ratings to leave
                        </h2>
                    </div>
                    <div class="mt-4 space-y-4">
                        <div>
                            <h3 class="text-xs font-semibold uppercase text-stone-500 dark:text-neutral-400">
                                Quotes
                            </h3>
                            <div class="mt-2 space-y-3">
                                <form v-for="quote in quoteRatingsDue" :key="`quote-${quote.id}`"
                                    class="rounded-sm border border-stone-200 p-3 text-sm dark:border-neutral-700"
                                    @submit.prevent="submitQuoteRating(quote.id)">
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <div class="font-medium text-stone-800 dark:text-neutral-100">
                                            {{ quote.number || 'Quote' }} - {{ quote.job_title || 'Job' }}
                                        </div>
                                        <span class="px-2 py-0.5 text-xs font-medium rounded-full"
                                            :class="statusClass(quote.status)">
                                            {{ formatStatus(quote.status) }}
                                        </span>
                                    </div>
                                    <div class="mt-2 flex flex-wrap items-center gap-2">
                                        <select v-model.number="ratingForms.quotes[quote.id].rating"
                                            class="py-2 px-3 rounded-sm border border-stone-200 text-sm text-stone-700 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                                            <option v-for="value in [1, 2, 3, 4, 5]" :key="value" :value="value">
                                                {{ value }} star{{ value > 1 ? 's' : '' }}
                                            </option>
                                        </select>
                                        <input v-model="ratingForms.quotes[quote.id].feedback" type="text"
                                            class="flex-1 min-w-[160px] py-2 px-3 rounded-sm border border-stone-200 text-sm text-stone-700 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                                            placeholder="Feedback (optional)">
                                        <button type="submit"
                                            class="py-2 px-3 inline-flex items-center gap-x-2 text-xs font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700">
                                            Submit
                                        </button>
                                    </div>
                                </form>
                                <div v-if="!quoteRatingsDue.length" class="text-sm text-stone-500 dark:text-neutral-400">
                                    No quote ratings needed.
                                </div>
                            </div>
                        </div>
                        <div>
                            <h3 class="text-xs font-semibold uppercase text-stone-500 dark:text-neutral-400">
                                Jobs
                            </h3>
                            <div class="mt-2 space-y-3">
                                <form v-for="work in workRatingsDue" :key="`work-${work.id}`"
                                    class="rounded-sm border border-stone-200 p-3 text-sm dark:border-neutral-700"
                                    @submit.prevent="submitWorkRating(work.id)">
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <div class="font-medium text-stone-800 dark:text-neutral-100">
                                            {{ work.job_title || 'Job' }}
                                        </div>
                                        <span class="px-2 py-0.5 text-xs font-medium rounded-full"
                                            :class="statusClass(work.status)">
                                            {{ formatStatus(work.status) }}
                                        </span>
                                    </div>
                                    <div class="mt-2 flex flex-wrap items-center gap-2">
                                        <select v-model.number="ratingForms.works[work.id].rating"
                                            class="py-2 px-3 rounded-sm border border-stone-200 text-sm text-stone-700 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                                            <option v-for="value in [1, 2, 3, 4, 5]" :key="value" :value="value">
                                                {{ value }} star{{ value > 1 ? 's' : '' }}
                                            </option>
                                        </select>
                                        <input v-model="ratingForms.works[work.id].feedback" type="text"
                                            class="flex-1 min-w-[160px] py-2 px-3 rounded-sm border border-stone-200 text-sm text-stone-700 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                                            placeholder="Feedback (optional)">
                                        <button type="submit"
                                            class="py-2 px-3 inline-flex items-center gap-x-2 text-xs font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700">
                                            Submit
                                        </button>
                                    </div>
                                </form>
                                <div v-if="!workRatingsDue.length" class="text-sm text-stone-500 dark:text-neutral-400">
                                    No job ratings needed.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section v-if="!profileMissing" class="bg-white border border-stone-200 rounded-sm p-5 shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                        Recently validated
                    </h2>
                </div>
                <div class="mt-4 grid grid-cols-1 lg:grid-cols-2 gap-4 text-sm">
                    <div>
                        <h3 class="text-xs font-semibold uppercase text-stone-500 dark:text-neutral-400">Quotes</h3>
                        <div class="mt-2 space-y-2">
                            <div v-for="quote in validatedQuotes" :key="`validated-quote-${quote.id}`"
                                class="flex items-center justify-between rounded-sm border border-stone-200 px-3 py-2 dark:border-neutral-700">
                                <div>
                                    <div class="font-medium text-stone-800 dark:text-neutral-100">
                                        {{ quote.number || 'Quote' }} - {{ quote.job_title || 'Job' }}
                                    </div>
                                    <div class="text-xs text-stone-500 dark:text-neutral-400">
                                        {{ formatDate(quote.decided_at) }}
                                    </div>
                                </div>
                                <span class="px-2 py-0.5 text-xs font-medium rounded-full"
                                    :class="statusClass(quote.status)">
                                    {{ formatStatus(quote.status) }}
                                </span>
                            </div>
                            <div v-if="!validatedQuotes.length" class="text-sm text-stone-500 dark:text-neutral-400">
                                No validated quotes yet.
                            </div>
                        </div>
                    </div>
                    <div>
                        <h3 class="text-xs font-semibold uppercase text-stone-500 dark:text-neutral-400">Jobs</h3>
                        <div class="mt-2 space-y-2">
                            <div v-for="work in validatedWorks" :key="`validated-work-${work.id}`"
                                class="flex items-center justify-between rounded-sm border border-stone-200 px-3 py-2 dark:border-neutral-700">
                                <div>
                                    <div class="font-medium text-stone-800 dark:text-neutral-100">
                                        {{ work.job_title || 'Job' }}
                                    </div>
                                    <div class="text-xs text-stone-500 dark:text-neutral-400">
                                        {{ formatDate(work.completed_at) }}
                                    </div>
                                </div>
                                <span class="px-2 py-0.5 text-xs font-medium rounded-full"
                                    :class="statusClass(work.status)">
                                    {{ formatStatus(work.status) }}
                                </span>
                            </div>
                            <div v-if="!validatedWorks.length" class="text-sm text-stone-500 dark:text-neutral-400">
                                No validated jobs yet.
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
                            Schedule preview
                        </h3>
                        <p class="text-sm text-stone-500 dark:text-neutral-400">
                            {{ schedulePreviewWork?.job_title || 'Job' }}
                        </p>
                    </div>
                    <button type="button" @click="closeSchedulePreview"
                        class="py-1.5 px-3 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200">
                        Close
                    </button>
                </div>

                <div v-if="schedulePreviewWork" class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-3">
                    <div class="space-y-3 lg:col-span-1">
                        <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800">
                            <div class="text-xs font-semibold uppercase text-stone-500 dark:text-neutral-400">
                                Summary
                            </div>
                            <div class="mt-2 space-y-2 text-sm text-stone-700 dark:text-neutral-200">
                                <div class="flex items-center justify-between">
                                    <span>Start date</span>
                                    <span>{{ formatCalendarDate(schedulePreviewWork.start_date) }}</span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span>Time</span>
                                    <span>{{ formatTimeRange(schedulePreviewWork.start_time, schedulePreviewWork.end_time) }}</span>
                                </div>
                                <div v-if="schedulePreviewIsRecurring" class="flex items-center justify-between">
                                    <span>Frequency</span>
                                    <span>{{ formatStatus(schedulePreviewWork.frequency) }}</span>
                                </div>
                                <div v-if="schedulePreviewIsRecurring" class="flex items-center justify-between">
                                    <span>Visits</span>
                                    <span>{{ schedulePreviewEvents.length || schedulePreviewWork.totalVisits || 0 }}</span>
                                </div>
                                <div v-if="schedulePreviewIsRecurring" class="flex items-center justify-between">
                                    <span>Range</span>
                                    <span>{{ schedulePreviewRange.start }} - {{ schedulePreviewRange.end }}</span>
                                </div>
                            </div>
                        </div>

                        <div class="rounded-sm border border-stone-200 bg-white p-3 dark:border-neutral-700 dark:bg-neutral-900">
                            <div class="text-xs font-semibold uppercase text-stone-500 dark:text-neutral-400">
                                Team
                            </div>
                            <div v-if="schedulePreviewAssignees.length" class="mt-2 flex flex-wrap gap-2">
                                <span v-for="member in schedulePreviewAssignees" :key="member.id"
                                    class="rounded-sm bg-stone-100 px-2 py-1 text-xs text-stone-700 dark:bg-neutral-800 dark:text-neutral-200">
                                    {{ member.name }}
                                </span>
                            </div>
                            <div v-else class="mt-2 text-sm text-stone-500 dark:text-neutral-400">
                                Team will be assigned by the company.
                            </div>
                        </div>

                        <div v-if="!schedulePreviewHasEvents"
                            class="rounded-sm border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-200">
                            Schedule details are not available yet.
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
                                Single visit
                            </div>
                            <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                This schedule includes one visit.
                            </p>
                            <div class="mt-3 grid grid-cols-2 gap-3 text-sm text-stone-700 dark:text-neutral-200">
                                <div>
                                    <div class="text-xs text-stone-500 dark:text-neutral-400">Date</div>
                                    <div>{{ formatCalendarDate(schedulePreviewWork.start_date) }}</div>
                                </div>
                                <div>
                                    <div class="text-xs text-stone-500 dark:text-neutral-400">Time</div>
                                    <div>{{ formatTimeRange(schedulePreviewWork.start_time, schedulePreviewWork.end_time) }}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-5 flex flex-wrap items-center justify-between gap-3">
                    <p class="text-xs text-stone-500 dark:text-neutral-400">
                        Accepting will create tasks for each visit.
                    </p>
                    <div class="flex flex-wrap items-center gap-2">
                        <button type="button" @click="rejectSchedule(schedulePreviewId)" :disabled="!schedulePreviewId"
                            class="py-2 px-3 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 disabled:pointer-events-none disabled:opacity-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200">
                            Request changes
                        </button>
                        <button type="button" @click="confirmSchedule(schedulePreviewId, true)"
                            :disabled="!schedulePreviewId || !schedulePreviewHasEvents"
                            class="py-2 px-3 text-xs font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:pointer-events-none disabled:opacity-50">
                            Accept schedule
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
                            Upload proof
                        </h3>
                        <p class="text-sm text-stone-500 dark:text-neutral-400">
                            {{ taskProofTask?.title || 'Task' }}
                        </p>
                    </div>
                    <button type="button" @click="closeTaskProof"
                        class="py-1.5 px-3 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200">
                        Close
                    </button>
                </div>

                <form class="mt-4 space-y-4" @submit.prevent="submitTaskProof">
                    <div>
                        <label class="block text-xs text-stone-500 dark:text-neutral-400">Type</label>
                        <select v-model="taskProofForm.type"
                            class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                            <option value="execution">Execution</option>
                            <option value="completion">Completion</option>
                            <option value="other">Other</option>
                        </select>
                        <div v-if="taskProofForm.errors.type" class="mt-1 text-xs text-red-600">
                            {{ taskProofForm.errors.type }}
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs text-stone-500 dark:text-neutral-400">File (photo or video)</label>
                        <input type="file" @change="handleTaskProofFile" accept="image/*,video/*"
                            class="mt-1 block w-full text-sm text-stone-600 file:mr-4 file:py-2 file:px-3 file:rounded-sm file:border-0 file:text-sm file:font-medium file:bg-stone-100 file:text-stone-700 hover:file:bg-stone-200 dark:text-neutral-300 dark:file:bg-neutral-800 dark:file:text-neutral-200" />
                        <div v-if="taskProofForm.errors.file" class="mt-1 text-xs text-red-600">
                            {{ taskProofForm.errors.file }}
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs text-stone-500 dark:text-neutral-400">Note (optional)</label>
                        <input v-model="taskProofForm.note" type="text"
                            class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200" />
                        <div v-if="taskProofForm.errors.note" class="mt-1 text-xs text-red-600">
                            {{ taskProofForm.errors.note }}
                        </div>
                    </div>

                    <div class="flex justify-end gap-2">
                        <button type="button" @click="closeTaskProof"
                            class="py-2 px-3 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200">
                            Cancel
                        </button>
                        <button type="submit" :disabled="taskProofForm.processing"
                            class="py-2 px-3 text-xs font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:pointer-events-none disabled:opacity-50">
                            Upload
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
