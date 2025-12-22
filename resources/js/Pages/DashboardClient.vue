<script setup>
import { computed, reactive, watchEffect } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { humanizeDate } from '@/utils/date';

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
});

const page = usePage();
const userName = computed(() => page.props.auth?.user?.name || 'there');

const stat = (key) => props.stats?.[key] ?? 0;

const formatCurrency = (value) =>
    `$${Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

const formatDate = (value) => humanizeDate(value) || '-';

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

const acceptQuote = (quoteId) => {
    router.post(route('portal.quotes.accept', quoteId), {}, { preserveScroll: true });
};

const declineQuote = (quoteId) => {
    router.post(route('portal.quotes.decline', quoteId), {}, { preserveScroll: true });
};

const validateWork = (workId) => {
    router.post(route('portal.works.validate', workId), {}, { preserveScroll: true });
};

const disputeWork = (workId) => {
    router.post(route('portal.works.dispute', workId), {}, { preserveScroll: true });
};

const submitPayment = (invoiceId) => {
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
                class="rounded-sm border border-stone-200 bg-gradient-to-br from-white via-white to-sky-50 p-5 shadow-sm dark:border-neutral-700 dark:from-neutral-900 dark:via-neutral-900 dark:to-neutral-800">
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
                    <div class="p-4 bg-white border border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
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

            <section v-else class="grid grid-cols-1 xl:grid-cols-2 gap-4">
                <div class="bg-white border border-stone-200 rounded-sm p-5 shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                    <div class="flex items-center justify-between">
                        <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                            Quotes awaiting validation
                        </h2>
                    </div>
                    <div class="mt-4 space-y-3">
                        <div v-for="quote in pendingQuotes" :key="quote.id"
                            class="flex flex-col gap-3 rounded-lg border border-stone-200 p-3 text-sm dark:border-neutral-700">
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
                            Jobs awaiting validation
                        </h2>
                    </div>
                    <div class="mt-4 space-y-3">
                        <div v-for="work in pendingWorks" :key="work.id"
                            class="flex flex-col gap-3 rounded-lg border border-stone-200 p-3 text-sm dark:border-neutral-700">
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

            <section v-if="!profileMissing" class="grid grid-cols-1 xl:grid-cols-2 gap-4">
                <div class="bg-white border border-stone-200 rounded-sm p-5 shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                    <div class="flex items-center justify-between">
                        <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                            Invoices awaiting payment
                        </h2>
                    </div>
                    <div class="mt-4 space-y-3">
                        <div v-for="invoice in invoicesDue" :key="invoice.id"
                            class="flex flex-col gap-3 rounded-lg border border-stone-200 p-3 text-sm dark:border-neutral-700">
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
                                    class="rounded-lg border border-stone-200 p-3 text-sm dark:border-neutral-700"
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
                                    class="rounded-lg border border-stone-200 p-3 text-sm dark:border-neutral-700"
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
                                class="flex items-center justify-between rounded-lg border border-stone-200 px-3 py-2 dark:border-neutral-700">
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
                                class="flex items-center justify-between rounded-lg border border-stone-200 px-3 py-2 dark:border-neutral-700">
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
    </AuthenticatedLayout>
</template>
