<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    stats: {
        type: Object,
        default: () => ({}),
    },
    filters: {
        type: Object,
        default: () => ({}),
    },
    expenses: {
        type: Object,
        default: () => ({}),
    },
    invoices: {
        type: Object,
        default: () => ({}),
    },
});

const { t } = useI18n();
const busyKey = ref(null);
const search = ref(props.filters?.search || '');
const searchIsLoading = ref(false);
const searchSyncGuard = ref(false);
const listLoading = reactive({
    expense: false,
    invoice: false,
});

const normalizePaginator = (paginator) => {
    const data = Array.isArray(paginator?.data)
        ? paginator.data
        : (Array.isArray(paginator) ? paginator : []);

    return {
        data,
        total: Number(paginator?.total ?? data.length ?? 0),
        current_page: Number(paginator?.current_page ?? 1),
        last_page: Number(paginator?.last_page ?? (data.length ? 1 : 1)),
    };
};

const statsState = ref(props.stats || {});
const expensesState = ref(normalizePaginator(props.expenses));
const invoicesState = ref(normalizePaginator(props.invoices));

watch(() => props.stats, (value) => {
    statsState.value = value || {};
}, { deep: true });

watch(() => props.expenses, (value) => {
    expensesState.value = normalizePaginator(value);
}, { deep: true });

watch(() => props.invoices, (value) => {
    invoicesState.value = normalizePaginator(value);
}, { deep: true });

watch(() => props.filters?.search || '', (value) => {
    searchSyncGuard.value = true;
    search.value = value || '';
    setTimeout(() => {
        searchSyncGuard.value = false;
    }, 0);
}, { immediate: true });

const statCards = computed(() => ([
    { key: 'total_pending', value: statsState.value?.total_pending ?? 0 },
    { key: 'expenses_pending', value: statsState.value?.expenses_pending ?? 0 },
    { key: 'invoices_pending', value: statsState.value?.invoices_pending ?? 0 },
]));

const expenses = computed(() => expensesState.value.data || []);
const invoices = computed(() => invoicesState.value.data || []);
const expenseHasMore = computed(() => expensesState.value.current_page < expensesState.value.last_page);
const invoiceHasMore = computed(() => invoicesState.value.current_page < invoicesState.value.last_page);

const humanizeRoleKey = (value) => String(value || '')
    .split('_')
    .filter(Boolean)
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join(' ') || '-';

const formatDate = (value) => {
    if (!value) {
        return '-';
    }

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return String(value);
    }

    return date.toLocaleDateString();
};

const formatCurrency = (amount, currencyCode) => {
    const numericAmount = Number(amount || 0);
    const currency = String(currencyCode || 'CAD').toUpperCase();

    try {
        return new Intl.NumberFormat(undefined, {
            style: 'currency',
            currency,
            maximumFractionDigits: 2,
        }).format(numericAmount);
    } catch {
        return `${numericAmount.toFixed(2)} ${currency}`;
    }
};

const expenseStatusLabel = (status) => t(`expenses.status.${status || 'draft'}`);
const invoiceApprovalStatusLabel = (status) => t(`invoices.approval_status.${status || 'draft'}`);

const expenseStatusClasses = (status) => ({
    submitted: 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-200',
    pending_approval: 'bg-orange-100 text-orange-800 dark:bg-orange-500/15 dark:text-orange-200',
})[status] || 'bg-stone-100 text-stone-700 dark:bg-neutral-700 dark:text-neutral-200';

const invoiceStatusClasses = (status) => ({
    submitted: 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-200',
    pending_approval: 'bg-orange-100 text-orange-800 dark:bg-orange-500/15 dark:text-orange-200',
    approved: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-200',
})[status] || 'bg-stone-100 text-stone-700 dark:bg-neutral-700 dark:text-neutral-200';

const customerName = (document) => {
    const customer = document?.customer;
    if (!customer) {
        return t('finance_approvals.fallbacks.customer');
    }

    return customer.company_name || `${customer.first_name || ''} ${customer.last_name || ''}`.trim();
};

const workName = (document) => {
    const work = document?.work;
    if (!work) {
        return t('finance_approvals.fallbacks.job');
    }

    return work.number ? `${work.number} - ${work.job_title || t('finance_approvals.fallbacks.job')}` : (work.job_title || t('finance_approvals.fallbacks.job'));
};

const routeMap = {
    expense: {
        approve: 'expense.approve',
        reject: 'expense.reject',
    },
    invoice: {
        approve: 'invoice.approve',
        reject: 'invoice.reject',
        process: 'invoice.process',
    },
};

const runAction = (documentType, documentId, action) => {
    const routeName = routeMap[documentType]?.[action];
    if (!routeName) {
        return;
    }

    busyKey.value = `${documentType}:${documentId}:${action}`;
    router.patch(route(routeName, documentId), {}, {
        preserveScroll: true,
        preserveState: true,
        onFinish: () => {
            busyKey.value = null;
        },
    });
};

const isBusy = (documentType, documentId, action) => busyKey.value === `${documentType}:${documentId}:${action}`;

const buildInboxQuery = ({ searchValue = search.value, expensePage = 1, invoicePage = 1 } = {}) => {
    const trimmedSearch = String(searchValue || '').trim();
    const query = {
        expense_page: expensePage,
        invoice_page: invoicePage,
    };

    if (trimmedSearch !== '') {
        query.search = trimmedSearch;
    }

    return query;
};

let searchTimeout = null;

watch(search, (value) => {
    if (searchSyncGuard.value) {
        return;
    }

    if (searchTimeout) {
        window.clearTimeout(searchTimeout);
    }

    searchTimeout = window.setTimeout(() => {
        searchIsLoading.value = true;
        router.get(route('finance-approvals.index'), buildInboxQuery({
            searchValue: value,
            expensePage: 1,
            invoicePage: 1,
        }), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['stats', 'filters', 'expenses', 'invoices'],
            onFinish: () => {
                searchIsLoading.value = false;
            },
        });
    }, 300);
});

const appendUniqueItems = (currentItems, nextItems) => {
    const ids = new Set(currentItems.map((item) => item.id));

    return [
        ...currentItems,
        ...nextItems.filter((item) => !ids.has(item.id)),
    ];
};

const loadMore = async (documentType) => {
    const state = documentType === 'expense' ? expensesState.value : invoicesState.value;

    if (searchIsLoading.value || listLoading[documentType] || state.current_page >= state.last_page) {
        return;
    }

    listLoading[documentType] = true;

    const nextExpensePage = documentType === 'expense'
        ? state.current_page + 1
        : expensesState.value.current_page;
    const nextInvoicePage = documentType === 'invoice'
        ? state.current_page + 1
        : invoicesState.value.current_page;

    try {
        const response = await fetch(
            route('finance-approvals.index', buildInboxQuery({
                expensePage: nextExpensePage,
                invoicePage: nextInvoicePage,
            })),
            {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            },
        );

        if (!response.ok) {
            throw new Error(`Unable to load more ${documentType} approvals.`);
        }

        const payload = await response.json();
        const nextPaginator = normalizePaginator(payload[documentType === 'expense' ? 'expenses' : 'invoices']);

        if (documentType === 'expense') {
            expensesState.value = {
                ...nextPaginator,
                data: appendUniqueItems(expensesState.value.data, nextPaginator.data),
            };
        } else {
            invoicesState.value = {
                ...nextPaginator,
                data: appendUniqueItems(invoicesState.value.data, nextPaginator.data),
            };
        }
    } finally {
        listLoading[documentType] = false;
    }
};

const handleSectionScroll = (documentType, event) => {
    const element = event?.target;
    if (!element) {
        return;
    }

    if ((element.scrollHeight - element.scrollTop - element.clientHeight) < 120) {
        loadMore(documentType);
    }
};
</script>

<template>
    <Head :title="$t('finance_approvals.title')" />

    <AuthenticatedLayout>
        <div class="space-y-5">
            <section class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex flex-col gap-2 md:flex-row md:items-end md:justify-between">
                    <div>
                        <h1 class="text-xl font-semibold text-stone-900 dark:text-neutral-100">
                            {{ $t('finance_approvals.title') }}
                        </h1>
                        <p class="mt-1 text-sm text-stone-600 dark:text-neutral-300">
                            {{ $t('finance_approvals.subtitle') }}
                        </p>
                    </div>
                </div>

                <div class="mt-4 grid gap-3 md:grid-cols-3">
                    <div
                        v-for="card in statCards"
                        :key="card.key"
                        class="rounded-sm border border-stone-200 bg-stone-50 p-4 dark:border-neutral-700 dark:bg-neutral-950"
                    >
                        <div class="text-xs font-medium uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                            {{ $t(`finance_approvals.stats.${card.key}`) }}
                        </div>
                        <div class="mt-2 text-2xl font-semibold text-stone-900 dark:text-neutral-100">
                            {{ card.value }}
                        </div>
                    </div>
                </div>

                <div class="mt-4">
                    <label class="mb-2 block text-xs font-medium uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                        {{ $t('finance_approvals.search.label') }}
                    </label>
                    <input
                        v-model="search"
                        type="search"
                        :placeholder="$t('finance_approvals.search.placeholder')"
                        class="w-full rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm text-stone-700 placeholder:text-stone-400 focus:border-green-600 focus:outline-none focus:ring-2 focus:ring-green-200 dark:border-neutral-700 dark:bg-neutral-950 dark:text-neutral-100 dark:placeholder:text-neutral-500 dark:focus:ring-green-900/50"
                    />
                    <div class="mt-2 text-xs text-stone-500 dark:text-neutral-400">
                        {{ searchIsLoading ? $t('finance_approvals.search.loading') : $t('finance_approvals.search.helper') }}
                    </div>
                </div>
            </section>

            <section class="grid gap-5 xl:grid-cols-2">
                <div class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="flex items-center justify-between gap-3">
                        <h2 class="text-sm font-semibold text-stone-900 dark:text-neutral-100">
                            {{ $t('finance_approvals.sections.expenses') }}
                        </h2>
                        <span class="rounded-full bg-stone-100 px-2.5 py-1 text-xs font-semibold text-stone-700 dark:bg-neutral-800 dark:text-neutral-200">
                            {{ expensesState.total }}
                        </span>
                    </div>

                    <div v-if="!expenses.length" class="mt-4 rounded-sm border border-dashed border-stone-200 px-4 py-8 text-sm text-stone-500 dark:border-neutral-700 dark:text-neutral-400">
                        {{ $t('finance_approvals.empty.expenses') }}
                    </div>

                    <div
                        v-else
                        class="mt-4 max-h-[68vh] space-y-3 overflow-y-auto pr-1"
                        @scroll.passive="handleSectionScroll('expense', $event)"
                    >
                        <article
                            v-for="expense in expenses"
                            :key="expense.id"
                            class="rounded-sm border border-stone-200 bg-stone-50 p-4 dark:border-neutral-700 dark:bg-neutral-950"
                        >
                            <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                                <div class="space-y-2">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <Link :href="expense.document_url" class="text-sm font-semibold text-stone-900 hover:text-green-700 dark:text-neutral-100 dark:hover:text-green-400">
                                            {{ expense.title }}
                                        </Link>
                                        <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold" :class="expenseStatusClasses(expense.status)">
                                            {{ expenseStatusLabel(expense.status) }}
                                        </span>
                                    </div>
                                    <div class="text-sm text-stone-600 dark:text-neutral-300">
                                        {{ formatCurrency(expense.total, expense.currency_code) }}
                                    </div>
                                    <div class="grid gap-1 text-xs text-stone-500 dark:text-neutral-400">
                                        <div>{{ $t('finance_approvals.labels.created_by') }}: {{ expense.creator?.name || '-' }}</div>
                                        <div>{{ $t('finance_approvals.labels.customer') }}: {{ customerName(expense) }}</div>
                                        <div>{{ $t('finance_approvals.labels.job') }}: {{ workName(expense) }}</div>
                                        <div>{{ $t('finance_approvals.labels.current_role') }}: {{ humanizeRoleKey(expense.current_approver_role_key) }}</div>
                                        <div>{{ $t('finance_approvals.labels.expense_date') }}: {{ formatDate(expense.expense_date) }}</div>
                                    </div>
                                </div>

                                <div class="flex flex-wrap gap-2 md:justify-end">
                                    <Link
                                        :href="expense.document_url"
                                        class="inline-flex items-center rounded-sm border border-stone-200 px-3 py-2 text-xs font-semibold text-stone-700 hover:bg-white dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800"
                                    >
                                        {{ $t('finance_approvals.actions.open') }}
                                    </Link>
                                    <button
                                        v-for="action in expense.inbox_actions || []"
                                        :key="action"
                                        type="button"
                                        class="inline-flex items-center rounded-sm px-3 py-2 text-xs font-semibold text-white"
                                        :class="action === 'reject' ? 'bg-rose-600 hover:bg-rose-500' : 'bg-emerald-600 hover:bg-emerald-500'"
                                        :disabled="isBusy('expense', expense.id, action)"
                                        @click="runAction('expense', expense.id, action)"
                                    >
                                        {{ $t(`finance_approvals.actions.${action}`) }}
                                    </button>
                                </div>
                            </div>
                        </article>

                        <div v-if="expenseHasMore" class="flex justify-center py-2">
                            <button
                                type="button"
                                class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-semibold text-stone-700 hover:bg-stone-50 disabled:cursor-not-allowed disabled:opacity-60 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                                :disabled="listLoading.expense"
                                @click="loadMore('expense')"
                            >
                                {{ listLoading.expense ? $t('finance_approvals.load_more.loading') : $t('finance_approvals.load_more.button') }}
                            </button>
                        </div>
                    </div>
                </div>

                <div class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="flex items-center justify-between gap-3">
                        <h2 class="text-sm font-semibold text-stone-900 dark:text-neutral-100">
                            {{ $t('finance_approvals.sections.invoices') }}
                        </h2>
                        <span class="rounded-full bg-stone-100 px-2.5 py-1 text-xs font-semibold text-stone-700 dark:bg-neutral-800 dark:text-neutral-200">
                            {{ invoicesState.total }}
                        </span>
                    </div>

                    <div v-if="!invoices.length" class="mt-4 rounded-sm border border-dashed border-stone-200 px-4 py-8 text-sm text-stone-500 dark:border-neutral-700 dark:text-neutral-400">
                        {{ $t('finance_approvals.empty.invoices') }}
                    </div>

                    <div
                        v-else
                        class="mt-4 max-h-[68vh] space-y-3 overflow-y-auto pr-1"
                        @scroll.passive="handleSectionScroll('invoice', $event)"
                    >
                        <article
                            v-for="invoice in invoices"
                            :key="invoice.id"
                            class="rounded-sm border border-stone-200 bg-stone-50 p-4 dark:border-neutral-700 dark:bg-neutral-950"
                        >
                            <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                                <div class="space-y-2">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <Link :href="invoice.document_url" class="text-sm font-semibold text-stone-900 hover:text-green-700 dark:text-neutral-100 dark:hover:text-green-400">
                                            {{ invoice.number || `#${invoice.id}` }}
                                        </Link>
                                        <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold" :class="invoiceStatusClasses(invoice.approval_status)">
                                            {{ invoiceApprovalStatusLabel(invoice.approval_status) }}
                                        </span>
                                    </div>
                                    <div class="text-sm text-stone-600 dark:text-neutral-300">
                                        {{ formatCurrency(invoice.total, invoice.currency_code) }}
                                    </div>
                                    <div class="grid gap-1 text-xs text-stone-500 dark:text-neutral-400">
                                        <div>{{ $t('finance_approvals.labels.created_by') }}: {{ invoice.creator?.name || '-' }}</div>
                                        <div>{{ $t('finance_approvals.labels.customer') }}: {{ customerName(invoice) }}</div>
                                        <div>{{ $t('finance_approvals.labels.job') }}: {{ workName(invoice) }}</div>
                                        <div>{{ $t('finance_approvals.labels.current_role') }}: {{ humanizeRoleKey(invoice.current_approver_role_key) }}</div>
                                        <div>{{ $t('finance_approvals.labels.created_at') }}: {{ formatDate(invoice.created_at) }}</div>
                                    </div>
                                </div>

                                <div class="flex flex-wrap gap-2 md:justify-end">
                                    <Link
                                        :href="invoice.document_url"
                                        class="inline-flex items-center rounded-sm border border-stone-200 px-3 py-2 text-xs font-semibold text-stone-700 hover:bg-white dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800"
                                    >
                                        {{ $t('finance_approvals.actions.open') }}
                                    </Link>
                                    <button
                                        v-for="action in invoice.inbox_actions || []"
                                        :key="action"
                                        type="button"
                                        class="inline-flex items-center rounded-sm px-3 py-2 text-xs font-semibold text-white"
                                        :class="action === 'reject' ? 'bg-rose-600 hover:bg-rose-500' : 'bg-emerald-600 hover:bg-emerald-500'"
                                        :disabled="isBusy('invoice', invoice.id, action)"
                                        @click="runAction('invoice', invoice.id, action)"
                                    >
                                        {{ $t(`finance_approvals.actions.${action}`) }}
                                    </button>
                                </div>
                            </div>
                        </article>

                        <div v-if="invoiceHasMore" class="flex justify-center py-2">
                            <button
                                type="button"
                                class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-semibold text-stone-700 hover:bg-stone-50 disabled:cursor-not-allowed disabled:opacity-60 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                                :disabled="listLoading.invoice"
                                @click="loadMore('invoice')"
                            >
                                {{ listLoading.invoice ? $t('finance_approvals.load_more.loading') : $t('finance_approvals.load_more.button') }}
                            </button>
                        </div>
                    </div>
                </div>
            </section>

            <section
                v-if="!expenses.length && !invoices.length"
                class="rounded-sm border border-dashed border-stone-200 bg-white px-5 py-10 text-center text-sm text-stone-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-400"
            >
                {{ $t('finance_approvals.empty.all') }}
            </section>
        </div>
    </AuthenticatedLayout>
</template>
