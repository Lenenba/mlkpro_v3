<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { humanizeDate } from '@/utils/date';

/**
 * @typedef {Object} PipelineCustomer
 * @property {number} id
 * @property {string} name
 * @property {string|null} email
 * @property {string|null} phone
 */

/**
 * @typedef {Object} PipelineEntity
 * @property {number} id
 * @property {string|null} status
 * @property {string|null} created_at
 * @property {PipelineCustomer|null} customer
 */

/**
 * @typedef {Object} PipelineTask
 * @property {number} id
 * @property {string} title
 * @property {string|null} status
 * @property {string|null} due_date
 * @property {string|null} assignee
 * @property {boolean} billable
 * @property {string} billing_status
 */

/**
 * @typedef {Object} PipelineResponse
 * @property {{type:string,id:string}} source
 * @property {PipelineEntity|null} request
 * @property {PipelineEntity|null} quote
 * @property {PipelineEntity|null} job
 * @property {PipelineTask[]} tasks
 * @property {PipelineEntity|null} invoice
 * @property {{quote_total:number|null,invoice_total:number|null,remaining_to_bill:number|null,amount_paid:number|null,balance_due:number|null}} billing
 * @property {{completeness:number,globalStatus:string,alerts:string[]}} derived
 */

const props = defineProps({
    entityType: {
        type: String,
        required: true,
    },
    entityId: {
        type: [String, Number],
        required: true,
    },
});

const pipeline = ref(null);
const loading = ref(true);
const errorMessage = ref('');
const tasksExpanded = ref(false);
const copyState = ref('idle');
const creatingInvoice = ref(false);

const fetchPipeline = async () => {
    loading.value = true;
    errorMessage.value = '';
    try {
        const response = await axios.get(route('pipeline.data'), {
            params: {
                entityType: props.entityType,
                entityId: props.entityId,
            },
        });
        pipeline.value = response.data;
    } catch (error) {
        errorMessage.value = error?.response?.data?.message || 'Unable to load pipeline.';
    } finally {
        loading.value = false;
    }
};

onMounted(fetchPipeline);
watch(() => [props.entityType, props.entityId], () => {
    fetchPipeline();
});

const customer = computed(
    () =>
        pipeline.value?.request?.customer ||
        pipeline.value?.quote?.customer ||
        pipeline.value?.job?.customer ||
        pipeline.value?.invoice?.customer ||
        null
);

const customerLabel = computed(() => customer.value?.name || '');

const formatDate = (value) => {
    const relative = humanizeDate(value);
    if (relative) {
        return relative;
    }
    if (!value) {
        return '-';
    }
    const parsed = new Date(value);
    if (Number.isNaN(parsed.getTime())) {
        return '-';
    }
    return parsed.toLocaleDateString();
};

const formatCurrency = (value) => {
    if (value === null || value === undefined || value === '') {
        return '-';
    }
    const amount = Number(value);
    if (Number.isNaN(amount)) {
        return '-';
    }
    return `$${amount.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
};

const requestLink = computed(() => {
    if (!pipeline.value?.request) {
        return null;
    }
    const search = pipeline.value.request.title || pipeline.value.request.service_type || '';
    return route('request.index', search ? { search } : {});
});

const quoteLink = computed(() =>
    pipeline.value?.quote ? route('customer.quote.show', pipeline.value.quote.id) : null
);

const jobLink = computed(() =>
    pipeline.value?.job ? route('work.show', pipeline.value.job.id) : null
);

const invoiceLink = computed(() =>
    pipeline.value?.invoice ? route('invoice.show', pipeline.value.invoice.id) : null
);

const tasksLink = computed(() => {
    const tasks = pipeline.value?.tasks || [];
    if (!tasks.length) {
        return null;
    }
    if (tasks.length === 1) {
        return route('task.show', tasks[0].id);
    }
    return route('task.index');
});

const customerId = computed(
    () =>
        pipeline.value?.request?.customer?.id ||
        pipeline.value?.quote?.customer?.id ||
        pipeline.value?.job?.customer?.id ||
        pipeline.value?.invoice?.customer?.id ||
        null
);

const createQuoteLink = computed(() => {
    if (!customerId.value) {
        return null;
    }
    return route('customer.quote.create', customerId.value);
});

const createWorkLink = computed(() => {
    if (!customerId.value) {
        return null;
    }
    return route('work.create', customerId.value);
});

const openSourceLink = computed(() => {
    if (!pipeline.value) {
        return null;
    }
    const entityType = props.entityType;
    const entityId = props.entityId;
    switch (entityType) {
        case 'request':
            return requestLink.value || route('request.index');
        case 'quote':
            return route('customer.quote.show', entityId);
        case 'job':
            return route('work.show', entityId);
        case 'task':
            return route('task.show', entityId);
        case 'invoice':
            return route('invoice.show', entityId);
        default:
            return null;
    }
});

const sourceLabel = computed(() => {
    if (!pipeline.value) {
        return `${props.entityType} #${props.entityId}`;
    }
    switch (props.entityType) {
        case 'request':
            return (
                pipeline.value.request?.title ||
                pipeline.value.request?.service_type ||
                `Request #${props.entityId}`
            );
        case 'quote':
            return pipeline.value.quote?.number
                ? `Quote ${pipeline.value.quote.number}`
                : `Quote #${props.entityId}`;
        case 'job':
            return pipeline.value.job?.job_title || `Job #${props.entityId}`;
        case 'task':
            return pipeline.value.tasks?.[0]?.title || `Task #${props.entityId}`;
        case 'invoice':
            return pipeline.value.invoice?.number
                ? `Invoice ${pipeline.value.invoice.number}`
                : `Invoice #${props.entityId}`;
        default:
            return `${props.entityType} #${props.entityId}`;
    }
});

const requestStatus = computed(() => {
    if (!pipeline.value?.request) {
        return 'Missing';
    }
    const status = pipeline.value.request.status;
    if (status === 'REQ_WON') {
        return 'Done';
    }
    if (status === 'REQ_LOST') {
        return 'Rejected';
    }
    if (['REQ_CONTACTED', 'REQ_QUALIFIED', 'REQ_QUOTE_SENT', 'REQ_CONVERTED'].includes(status)) {
        return 'In progress';
    }
    return 'Created';
});

const quoteStatus = computed(() => {
    const quote = pipeline.value?.quote;
    if (!quote) {
        return 'Missing';
    }
    switch (quote.status) {
        case 'accepted':
            return 'Done';
        case 'declined':
            return 'Rejected';
        case 'sent':
            return 'In progress';
        default:
            return 'Created';
    }
});

const jobStatus = computed(() => {
    const job = pipeline.value?.job;
    if (!job) {
        return 'Missing';
    }
    if (['validated', 'auto_validated', 'closed', 'completed', 'tech_complete'].includes(job.status)) {
        return 'Done';
    }
    if (['cancelled', 'dispute'].includes(job.status)) {
        return 'Rejected';
    }
    return 'In progress';
});

const tasksStatus = computed(() => {
    const tasks = pipeline.value?.tasks || [];
    if (!tasks.length) {
        return 'Missing';
    }
    const done = tasks.filter((task) => task.status === 'done').length;
    return done === tasks.length ? 'Done' : 'In progress';
});

const invoiceStatus = computed(() => {
    const invoice = pipeline.value?.invoice;
    if (!invoice) {
        return 'Missing';
    }
    switch (invoice.status) {
        case 'paid':
            return 'Paid';
        case 'partial':
            return 'Partial';
        case 'overdue':
            return 'Overdue';
        case 'void':
            return 'Rejected';
        default:
            return 'Created';
    }
});

const statusBadgeClass = (status) => {
    switch (status) {
        case 'Created':
            return 'bg-sky-100 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300';
        case 'In progress':
            return 'bg-blue-100 text-blue-700 dark:bg-blue-500/10 dark:text-blue-300';
        case 'Done':
        case 'Paid':
            return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300';
        case 'Partial':
            return 'bg-amber-100 text-amber-800 dark:bg-amber-500/10 dark:text-amber-300';
        case 'Overdue':
        case 'Rejected':
            return 'bg-rose-100 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300';
        default:
            return 'bg-stone-100 text-stone-600 dark:bg-neutral-700 dark:text-neutral-300';
    }
};

const statusDotClass = (status) => {
    switch (status) {
        case 'Created':
            return 'bg-sky-500';
        case 'In progress':
            return 'bg-blue-500';
        case 'Done':
        case 'Paid':
            return 'bg-emerald-500';
        case 'Partial':
            return 'bg-amber-500';
        case 'Overdue':
        case 'Rejected':
            return 'bg-rose-500';
        default:
            return 'bg-stone-300';
    }
};

const statusAccentClass = (status) => {
    switch (status) {
        case 'Created':
            return 'border-l-4 border-l-sky-400';
        case 'In progress':
            return 'border-l-4 border-l-blue-400';
        case 'Done':
        case 'Paid':
            return 'border-l-4 border-l-emerald-400';
        case 'Partial':
            return 'border-l-4 border-l-amber-400';
        case 'Overdue':
        case 'Rejected':
            return 'border-l-4 border-l-rose-400';
        default:
            return 'border-l-4 border-l-stone-200 dark:border-l-neutral-700';
    }
};

const statusIconClass = (status) => {
    switch (status) {
        case 'Created':
            return 'bg-sky-100 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300';
        case 'In progress':
            return 'bg-blue-100 text-blue-700 dark:bg-blue-500/10 dark:text-blue-300';
        case 'Done':
        case 'Paid':
            return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300';
        case 'Partial':
            return 'bg-amber-100 text-amber-800 dark:bg-amber-500/10 dark:text-amber-300';
        case 'Overdue':
        case 'Rejected':
            return 'bg-rose-100 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300';
        default:
            return 'bg-stone-100 text-stone-600 dark:bg-neutral-700 dark:text-neutral-300';
    }
};

const statusBarClass = (status) => {
    switch (status) {
        case 'Created':
            return 'bg-sky-400';
        case 'In progress':
            return 'bg-blue-400';
        case 'Done':
        case 'Paid':
            return 'bg-emerald-400';
        case 'Partial':
            return 'bg-amber-400';
        case 'Overdue':
        case 'Rejected':
            return 'bg-rose-400';
        default:
            return 'bg-stone-200 dark:bg-neutral-700';
    }
};

const taskStatusClass = (status) => {
    switch (status) {
        case 'todo':
            return 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300';
        case 'in_progress':
            return 'bg-blue-100 text-blue-700 dark:bg-blue-500/10 dark:text-blue-300';
        case 'done':
            return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300';
        default:
            return 'bg-stone-100 text-stone-600 dark:bg-neutral-700 dark:text-neutral-300';
    }
};

const taskStatusLabel = (status) => {
    switch (status) {
        case 'todo':
            return 'Todo';
        case 'in_progress':
            return 'In progress';
        case 'done':
            return 'Done';
        default:
            return status || '-';
    }
};

const billingStatusClass = (status) => {
    switch (status) {
        case 'billed':
            return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300';
        case 'partial':
            return 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300';
        default:
            return 'bg-stone-100 text-stone-600 dark:bg-neutral-700 dark:text-neutral-300';
    }
};

const billingStatusLabel = (status) => {
    switch (status) {
        case 'billed':
            return 'Billed';
        case 'partial':
            return 'Partial';
        default:
            return 'Unbilled';
    }
};

const tasksDone = computed(() =>
    (pipeline.value?.tasks || []).filter((task) => task.status === 'done').length
);

const tasksTotal = computed(() => (pipeline.value?.tasks || []).length);

const tasksProgress = computed(() => {
    if (!tasksTotal.value) {
        return 0;
    }
    return Math.round((tasksDone.value / tasksTotal.value) * 100);
});

const nextTaskDue = computed(() => {
    const tasks = (pipeline.value?.tasks || []).filter((task) => task.due_date);
    if (!tasks.length) {
        return null;
    }
    const sorted = [...tasks].sort(
        (a, b) => new Date(a.due_date).getTime() - new Date(b.due_date).getTime()
    );
    return sorted[0]?.due_date || null;
});

const visibleTasks = computed(() => {
    const tasks = pipeline.value?.tasks || [];
    return tasksExpanded.value ? tasks : tasks.slice(0, 4);
});

const globalStatus = computed(() => pipeline.value?.derived?.globalStatus || 'missing');
const globalStatusLabel = computed(() => {
    if (!globalStatus.value || globalStatus.value === 'missing') {
        return 'Missing';
    }
    if (globalStatus.value === 'REQ_WON') {
        return 'Done';
    }
    if (globalStatus.value === 'REQ_LOST') {
        return 'Rejected';
    }
    if (globalStatus.value === 'REQ_QUOTE_SENT' || globalStatus.value === 'REQ_CONVERTED') {
        return 'In progress';
    }
    if (globalStatus.value === 'REQ_QUALIFIED' || globalStatus.value === 'REQ_CONTACTED') {
        return 'In progress';
    }
    if (globalStatus.value === 'REQ_NEW') {
        return 'Created';
    }
    if (globalStatus.value === 'declined' || globalStatus.value === 'void') {
        return 'Rejected';
    }
    if (globalStatus.value === 'overdue') {
        return 'Overdue';
    }
    if (globalStatus.value === 'partial') {
        return 'Partial';
    }
    if (globalStatus.value === 'paid') {
        return 'Paid';
    }
    if (globalStatus.value === 'accepted') {
        return 'Done';
    }
    if (['validated', 'auto_validated', 'closed', 'completed', 'tech_complete'].includes(globalStatus.value)) {
        return 'Done';
    }
    if (['scheduled', 'en_route', 'in_progress', 'pending_review'].includes(globalStatus.value)) {
        return 'In progress';
    }
    if (globalStatus.value === 'draft' || globalStatus.value === 'sent') {
        return 'Created';
    }
    return 'In progress';
});

const steps = computed(() => {
    if (!pipeline.value) {
        return [];
    }

    return [
        {
            key: 'request',
            title: 'Request',
            subtitle: pipeline.value.request
                ? (pipeline.value.request.service_type || `Created ${formatDate(pipeline.value.request.created_at)}`)
                : '',
            status: requestStatus.value,
            url: requestLink.value,
            cta: requestLink.value
                ? { label: 'View details', url: requestLink.value }
                : null,
            fields: [
                {
                    label: 'Ref',
                    value:
                        pipeline.value.request?.title ||
                        pipeline.value.request?.service_type ||
                        (pipeline.value.request ? `Request #${pipeline.value.request.id}` : '-'),
                },
                { label: 'Created', value: formatDate(pipeline.value.request?.created_at) },
            ],
        },
        {
            key: 'quote',
            title: 'Quote',
            subtitle: pipeline.value.quote
                ? (pipeline.value.quote.job_title || `Total ${formatCurrency(pipeline.value.quote.total)}`)
                : '',
            status: quoteStatus.value,
            url: quoteLink.value,
            cta: quoteLink.value
                ? { label: 'View details', url: quoteLink.value }
                : createQuoteLink.value
                    ? { label: 'Create', url: createQuoteLink.value }
                    : null,
            fields: [
                {
                    label: 'Ref',
                    value: pipeline.value.quote?.number || (pipeline.value.quote ? `Quote #${pipeline.value.quote.id}` : '-'),
                },
                { label: 'Total', value: formatCurrency(pipeline.value.quote?.total) },
                { label: 'Created', value: formatDate(pipeline.value.quote?.created_at) },
            ],
        },
        {
            key: 'job',
            title: 'Job',
            subtitle: pipeline.value.job?.start_date ? `Starts ${formatDate(pipeline.value.job.start_date)}` : '',
            status: jobStatus.value,
            url: jobLink.value,
            cta: jobLink.value
                ? { label: 'View details', url: jobLink.value }
                : createWorkLink.value
                    ? { label: 'Create', url: createWorkLink.value }
                    : null,
            fields: [
                {
                    label: 'Ref',
                    value: pipeline.value.job?.number || (pipeline.value.job ? `Job #${pipeline.value.job.id}` : '-'),
                },
                { label: 'Start', value: formatDate(pipeline.value.job?.start_date) },
                { label: 'Total', value: formatCurrency(pipeline.value.job?.total) },
            ],
        },
        {
            key: 'tasks',
            title: 'Tasks',
            subtitle: tasksTotal.value
                ? `${tasksDone.value}/${tasksTotal.value} done`
                : '',
            status: tasksStatus.value,
            url: tasksLink.value,
            cta: tasksLink.value
                ? { label: 'View details', url: tasksLink.value }
                : route('task.index')
                    ? { label: 'Create', url: route('task.index') }
                    : null,
            fields: [
                { label: 'Progress', value: tasksTotal.value ? `${tasksDone.value}/${tasksTotal.value}` : '-' },
                { label: 'Next due', value: formatDate(nextTaskDue.value) },
            ],
        },
        {
            key: 'invoice',
            title: 'Invoice',
            subtitle: pipeline.value.invoice
                ? `Balance ${formatCurrency(pipeline.value.invoice.balance_due)}`
                : '',
            status: invoiceStatus.value,
            url: invoiceLink.value,
            cta: invoiceLink.value
                ? { label: 'View details', url: invoiceLink.value }
                : pipeline.value.job?.id
                    ? { label: 'Create', action: 'createInvoice' }
                    : null,
            fields: [
                {
                    label: 'Ref',
                    value: pipeline.value.invoice?.number || (pipeline.value.invoice ? `Invoice #${pipeline.value.invoice.id}` : '-'),
                },
                { label: 'Total', value: formatCurrency(pipeline.value.invoice?.total) },
                { label: 'Balance', value: formatCurrency(pipeline.value.invoice?.balance_due) },
            ],
        },
    ];
});

const handleCardClick = (step) => {
    if (!step.url) {
        return;
    }
    router.visit(step.url);
};

const copyLink = async () => {
    if (copyState.value === 'copied') {
        return;
    }
    try {
        await navigator.clipboard.writeText(window.location.href);
        copyState.value = 'copied';
    } catch (error) {
        copyState.value = 'failed';
    }
    setTimeout(() => {
        copyState.value = 'idle';
    }, 2000);
};

const copyLabel = computed(() => {
    if (copyState.value === 'copied') {
        return 'Copied';
    }
    if (copyState.value === 'failed') {
        return 'Copy failed';
    }
    return 'Copy link';
});

const createInvoice = () => {
    if (!pipeline.value?.job?.id || creatingInvoice.value) {
        return;
    }
    creatingInvoice.value = true;
    router.post(route('invoice.store-from-work', pipeline.value.job.id), {}, {
        preserveScroll: true,
        onSuccess: () => {
            fetchPipeline();
        },
        onFinish: () => {
            creatingInvoice.value = false;
        },
    });
};
</script>

<template>
    <Head title="Pipeline timeline" />
    <AuthenticatedLayout>
        <div class="mx-auto w-full max-w-6xl space-y-6 rise-stagger">
            <div class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <p class="text-xs uppercase tracking-wide text-stone-500 dark:text-neutral-400">Timeline</p>
                        <h1 class="mt-1 text-2xl font-semibold text-stone-800 dark:text-neutral-100">
                            Timeline: {{ sourceLabel }}
                        </h1>
                        <div class="mt-2 flex flex-wrap items-center gap-2 text-xs text-stone-500 dark:text-neutral-400">
                            <span v-if="customerLabel">Client: {{ customerLabel }}</span>
                            <span class="rounded-full px-2 py-1 text-[11px] font-medium" :class="statusBadgeClass(globalStatusLabel)">
                                {{ globalStatusLabel }}
                            </span>
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <Link
                            v-if="openSourceLink"
                            :href="openSourceLink"
                            class="inline-flex items-center gap-2 rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-medium text-stone-700 shadow-sm hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200 dark:hover:bg-neutral-700"
                        >
                            Open source entity
                        </Link>
                        <button
                            type="button"
                            @click="copyLink"
                            class="inline-flex items-center gap-2 rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-medium text-stone-700 shadow-sm hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200 dark:hover:bg-neutral-700"
                        >
                            {{ copyLabel }}
                        </button>
                    </div>
                </div>
            </div>

            <div v-if="loading" class="grid grid-cols-1 gap-4 lg:grid-cols-[minmax(0,1fr),320px]">
                <div class="space-y-4">
                    <div v-for="index in 5" :key="`skeleton-${index}`" class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <div class="flex items-center justify-between">
                            <div class="h-4 w-32 animate-pulse rounded bg-stone-200 dark:bg-neutral-700"></div>
                            <div class="h-5 w-20 animate-pulse rounded-full bg-stone-200 dark:bg-neutral-700"></div>
                        </div>
                        <div class="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2">
                            <div class="h-3 w-24 animate-pulse rounded bg-stone-200 dark:bg-neutral-700"></div>
                            <div class="h-3 w-32 animate-pulse rounded bg-stone-200 dark:bg-neutral-700"></div>
                            <div class="h-3 w-20 animate-pulse rounded bg-stone-200 dark:bg-neutral-700"></div>
                            <div class="h-3 w-28 animate-pulse rounded bg-stone-200 dark:bg-neutral-700"></div>
                        </div>
                    </div>
                </div>
                <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="h-4 w-24 animate-pulse rounded bg-stone-200 dark:bg-neutral-700"></div>
                    <div class="mt-3 h-2 w-full animate-pulse rounded bg-stone-200 dark:bg-neutral-700"></div>
                    <div class="mt-4 space-y-2">
                        <div class="h-3 w-32 animate-pulse rounded bg-stone-200 dark:bg-neutral-700"></div>
                        <div class="h-3 w-40 animate-pulse rounded bg-stone-200 dark:bg-neutral-700"></div>
                    </div>
                </div>
            </div>

            <div v-else-if="errorMessage" class="rounded-sm border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700">
                {{ errorMessage }}
            </div>

            <div v-else-if="!pipeline" class="rounded-sm border border-stone-200 bg-white p-6 text-sm text-stone-500">
                No pipeline data available.
            </div>

            <div v-else class="grid grid-cols-1 gap-4 lg:grid-cols-[minmax(0,1fr),320px]">
                <div class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="relative space-y-5">
                        <div class="absolute left-5 top-3 bottom-3 w-px bg-gradient-to-b from-emerald-300/40 via-stone-200 to-transparent dark:from-emerald-500/30 dark:via-neutral-700 dark:to-transparent"></div>
                        <div
                            v-for="(step, index) in steps"
                            :key="step.key"
                            class="relative pl-12"
                        >
                            <div
                                class="timeline-dot absolute left-0 top-4 flex h-10 w-10 items-center justify-center rounded-full border border-white shadow-sm dark:border-neutral-900"
                                :class="[statusDotClass(step.status), step.status === 'In progress' ? 'animate-pulse' : '']"
                                :style="{ animationDelay: `${index * 90}ms` }"
                            >
                                <svg
                                    v-if="['Done', 'Paid'].includes(step.status)"
                                    xmlns="http://www.w3.org/2000/svg"
                                    class="h-5 w-5 text-white"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="2"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                >
                                    <path d="M20 6 9 17l-5-5" />
                                </svg>
                                <span v-else class="text-[11px] font-semibold text-white">
                                    {{ String(index + 1).padStart(2, '0') }}
                                </span>
                            </div>

                            <div
                                class="timeline-card rounded-sm border border-stone-200 bg-white p-4 shadow-sm transition duration-300 hover:border-stone-300 hover:shadow-md dark:border-neutral-700 dark:bg-neutral-900"
                                :class="[
                                    statusAccentClass(step.status),
                                    step.status === 'Missing' ? 'bg-stone-50 text-stone-400 dark:bg-neutral-800/60' : '',
                                    step.url ? 'cursor-pointer' : 'cursor-default'
                                ]"
                                :role="step.url ? 'button' : undefined"
                                :tabindex="step.url ? 0 : -1"
                                @click="handleCardClick(step)"
                                @keydown.enter.prevent="handleCardClick(step)"
                                :style="{ animationDelay: `${index * 90}ms` }"
                            >
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div class="flex items-start gap-3">
                                        <div class="inline-flex h-9 w-9 items-center justify-center rounded-full" :class="statusIconClass(step.status)">
                                            <svg v-if="step.key === 'request'" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/>
                                                <path d="m3.3 7 8.7 5 8.7-5"/>
                                                <path d="M12 22V12"/>
                                            </svg>
                                            <svg v-else-if="step.key === 'quote'" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path d="M3 4h18v16H3z"/>
                                                <path d="M7 8h10"/>
                                                <path d="M7 12h10"/>
                                                <path d="M7 16h6"/>
                                            </svg>
                                            <svg v-else-if="step.key === 'job'" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path d="M3 7h18"/>
                                                <path d="M7 7V5a2 2 0 0 1 2-2h6a2 2 0 0 1 2 2v2"/>
                                                <rect width="18" height="14" x="3" y="7" rx="2"/>
                                            </svg>
                                            <svg v-else-if="step.key === 'tasks'" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path d="M9 12l2 2 4-4"/>
                                                <path d="M7 6h10"/>
                                                <path d="M7 18h10"/>
                                                <path d="M5 6h.01"/>
                                                <path d="M5 12h.01"/>
                                                <path d="M5 18h.01"/>
                                            </svg>
                                            <svg v-else xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path d="M4 7h16"/>
                                                <path d="M4 12h16"/>
                                                <path d="M4 17h10"/>
                                            </svg>
                                        </div>
                                        <div>
                                            <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                                {{ step.title }}
                                            </h2>
                                            <p v-if="step.subtitle" class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                                {{ step.subtitle }}
                                            </p>
                                        </div>
                                    </div>
                                    <span class="rounded-full px-2 py-1 text-[11px] font-medium" :class="statusBadgeClass(step.status)">
                                        {{ step.status }}
                                    </span>
                                </div>

                                <div class="mt-3 grid grid-cols-1 gap-2 text-xs text-stone-600 dark:text-neutral-300 sm:grid-cols-2">
                                    <div v-for="field in step.fields" :key="field.label" class="flex items-center justify-between gap-2">
                                        <span class="text-stone-400 dark:text-neutral-500">{{ field.label }}</span>
                                        <span class="text-stone-700 dark:text-neutral-200">{{ field.value || '-' }}</span>
                                    </div>
                                </div>

                                <div v-if="step.key === 'tasks'" class="mt-4 rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800">
                                    <div class="flex items-center justify-between text-xs text-stone-500 dark:text-neutral-400">
                                        <span>Progress</span>
                                        <span>{{ tasksDone }}/{{ tasksTotal }} done</span>
                                    </div>
                                    <div class="mt-2 h-2 w-full overflow-hidden rounded-full bg-stone-200 dark:bg-neutral-700">
                                    <div class="h-full rounded-full bg-emerald-500 transition-[width] duration-700 ease-out" :style="{ width: `${tasksProgress}%` }"></div>
                                    </div>
                                    <div v-if="tasksTotal" class="mt-3 space-y-2">
                                        <div v-for="task in visibleTasks" :key="task.id" class="flex items-center justify-between gap-2">
                                            <Link
                                                :href="route('task.show', task.id)"
                                                class="text-xs font-medium text-stone-700 hover:text-emerald-600 dark:text-neutral-200"
                                                @click.stop
                                            >
                                                {{ task.title }}
                                            </Link>
                                            <div class="flex items-center gap-2 text-[11px]">
                                                <span class="rounded-full px-2 py-0.5 font-medium" :class="taskStatusClass(task.status)">
                                                    {{ taskStatusLabel(task.status) }}
                                                </span>
                                                <span class="rounded-full px-2 py-0.5 font-medium" :class="billingStatusClass(task.billing_status)">
                                                    {{ billingStatusLabel(task.billing_status) }}
                                                </span>
                                            </div>
                                        </div>
                                        <button
                                            v-if="tasksTotal > 4"
                                            type="button"
                                            class="mt-2 text-xs font-medium text-emerald-600 hover:text-emerald-700"
                                            @click.stop="tasksExpanded = !tasksExpanded"
                                        >
                                            {{ tasksExpanded ? 'Show less' : 'Show all tasks' }}
                                        </button>
                                    </div>
                                    <div v-else class="mt-2 text-xs text-stone-500 dark:text-neutral-400">
                                        No tasks yet.
                                    </div>
                                </div>

                                <div class="mt-4 flex items-center justify-between">
                                    <div></div>
                                    <div>
                                        <Link
                                            v-if="step.cta?.url"
                                            :href="step.cta.url"
                                            class="inline-flex items-center gap-2 rounded-sm border border-stone-200 bg-white px-3 py-1.5 text-xs font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200 dark:hover:bg-neutral-700"
                                            @click.stop
                                        >
                                            {{ step.cta.label }}
                                        </Link>
                                        <button
                                            v-else-if="step.cta?.action === 'createInvoice'"
                                            type="button"
                                            :disabled="creatingInvoice"
                                            class="inline-flex items-center gap-2 rounded-sm border border-transparent bg-emerald-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-emerald-700 disabled:opacity-60"
                                            @click.stop="createInvoice"
                                        >
                                            {{ step.cta.label }}
                                        </button>
                                    </div>
                                </div>
                                <div class="mt-4 h-0.5 w-full rounded-full" :class="statusBarClass(step.status)"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="timeline-summary rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900" style="animation-delay: 180ms;">
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">Summary</h2>
                    <div class="mt-4">
                        <div class="flex items-center justify-between text-xs text-stone-500 dark:text-neutral-400">
                            <span>Completeness</span>
                            <span>{{ pipeline.derived?.completeness ?? 0 }}%</span>
                        </div>
                        <div class="mt-2 h-2 w-full overflow-hidden rounded-full bg-stone-200 dark:bg-neutral-700">
                            <div
                                class="h-full rounded-full bg-emerald-500 transition-[width] duration-700 ease-out"
                                :style="{ width: `${pipeline.derived?.completeness ?? 0}%` }"
                            ></div>
                        </div>
                    </div>

                    <div v-if="pipeline.derived?.alerts?.length" class="mt-4">
                        <h3 class="text-xs uppercase tracking-wide text-stone-400">Alerts</h3>
                        <ul class="mt-2 space-y-2 text-xs text-rose-600">
                            <li v-for="alert in pipeline.derived.alerts" :key="alert">{{ alert }}</li>
                        </ul>
                    </div>

                    <div class="mt-4">
                        <h3 class="text-xs uppercase tracking-wide text-stone-400">Totals</h3>
                        <div class="mt-2 space-y-2 text-sm text-stone-600 dark:text-neutral-300">
                            <div class="flex items-center justify-between">
                                <span>Quote total</span>
                                <span class="font-medium text-stone-800 dark:text-neutral-100">
                                    {{ formatCurrency(pipeline.billing?.quote_total) }}
                                </span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span>Invoice total</span>
                                <span class="font-medium text-stone-800 dark:text-neutral-100">
                                    {{ formatCurrency(pipeline.billing?.invoice_total) }}
                                </span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span>Remaining to bill</span>
                                <span class="font-medium text-stone-800 dark:text-neutral-100">
                                    {{ formatCurrency(pipeline.billing?.remaining_to_bill) }}
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<style scoped>
@keyframes timelineRise {
    from {
        opacity: 0;
        transform: translateY(12px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes timelinePop {
    from {
        opacity: 0;
        transform: scale(0.75);
    }
    to {
        opacity: 1;
        transform: scale(1);
    }
}

.timeline-card {
    animation: timelineRise 0.45s ease-out both;
    will-change: transform, opacity;
}

.timeline-dot {
    animation: timelinePop 0.45s ease-out both;
    will-change: transform, opacity;
}

.timeline-summary {
    animation: timelineRise 0.55s ease-out both;
    will-change: transform, opacity;
}

@media (prefers-reduced-motion: reduce) {
    .timeline-card,
    .timeline-dot,
    .timeline-summary {
        animation: none;
    }
}
</style>
