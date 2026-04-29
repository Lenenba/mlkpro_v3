<script setup>
import { computed, ref, watch } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AdminDataTable from '@/Components/DataTable/AdminDataTable.vue';
import AdminDataTableActions from '@/Components/DataTable/AdminDataTableActions.vue';
import AdminDataTableToolbar from '@/Components/DataTable/AdminDataTableToolbar.vue';
import Modal from '@/Components/UI/Modal.vue';
import DatePicker from '@/Components/DatePicker.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import ExpenseAiScanForm from '@/Pages/Expense/UI/ExpenseAiScanForm.vue';
import ExpenseForm from '@/Pages/Expense/UI/ExpenseForm.vue';
import ExpenseWorkflowModal from '@/Pages/Expense/UI/ExpenseWorkflowModal.vue';
import { humanizeDate } from '@/utils/date';
import { resolveDataTablePerPage } from '@/Components/DataTable/pagination';
import { useCurrencyFormatter } from '@/utils/currency';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    expenses: {
        type: Object,
        required: true,
    },
    filters: {
        type: Object,
        default: () => ({}),
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
    pettyCash: {
        type: Object,
        default: () => ({}),
    },
    canUseAiIntake: {
        type: Boolean,
        default: false,
    },
    count: {
        type: Number,
        required: true,
    },
    tenantCurrencyCode: {
        type: String,
        default: 'CAD',
    },
});

const { t } = useI18n();

const filterForm = useForm({
    search: props.filters?.search ?? '',
    status: props.filters?.status ?? '',
    category_key: props.filters?.category_key ?? '',
    quick_filter: props.filters?.quick_filter ?? 'all',
    customer_id: props.filters?.customer_id ?? '',
    work_id: props.filters?.work_id ?? '',
    sale_id: props.filters?.sale_id ?? '',
    invoice_id: props.filters?.invoice_id ?? '',
    campaign_id: props.filters?.campaign_id ?? '',
    expense_date_from: props.filters?.expense_date_from ?? '',
    expense_date_to: props.filters?.expense_date_to ?? '',
    sort: props.filters?.sort ?? 'expense_date',
    direction: props.filters?.direction ?? 'desc',
});

const showAdvanced = ref(false);
const isLoading = ref(false);
const editingExpense = ref(null);
const workflowExpense = ref(null);
const workflowAction = ref('');

const categoryOptions = computed(() =>
    (props.categories || []).map((item) => ({
        value: item.key,
        label: t(item.label_key),
    }))
);
const statusOptions = computed(() =>
    (props.statuses || []).map((status) => ({
        value: status,
        label: t(`expenses.status.${status}`),
    }))
);
const customerOptions = computed(() =>
    (props.linkOptions?.customers || []).map((item) => ({ value: item.id, label: item.name }))
);
const workOptions = computed(() =>
    (props.linkOptions?.works || []).map((item) => ({ value: item.id, label: item.name }))
);
const saleOptions = computed(() =>
    (props.linkOptions?.sales || []).map((item) => ({ value: item.id, label: item.name }))
);
const invoiceOptions = computed(() =>
    (props.linkOptions?.invoices || []).map((item) => ({ value: item.id, label: item.name }))
);
const campaignOptions = computed(() =>
    (props.linkOptions?.campaigns || []).map((item) => ({ value: item.id, label: item.name }))
);
const quickFilters = computed(() => ([
    { value: 'all', label: t('expenses.filters.quick.all') },
    { value: 'submitted', label: t('expenses.filters.quick.submitted') },
    { value: 'due', label: t('expenses.filters.quick.due') },
    { value: 'overdue', label: t('expenses.filters.quick.overdue') },
    { value: 'paid', label: t('expenses.filters.quick.paid') },
    { value: 'reimbursable', label: t('expenses.filters.quick.reimbursable') },
    { value: 'reimbursement_pending', label: t('expenses.filters.quick.reimbursement_pending') },
    { value: 'recurring', label: t('expenses.filters.quick.recurring') },
]));

const currentPerPage = computed(() => resolveDataTablePerPage(props.expenses?.per_page, props.filters?.per_page));
const expenseRows = computed(() => (Array.isArray(props.expenses?.data) ? props.expenses.data : []));
const expenseTableRows = computed(() => (isLoading.value
    ? Array.from({ length: 6 }, (_, index) => ({ id: `expense-skeleton-${index}`, __skeleton: true }))
    : expenseRows.value));
const expenseLinks = computed(() => props.expenses?.links || []);
const expenseResultsLabel = computed(() => `${props.count} ${t('expenses.pagination.results')}`);
const preferredCurrency = computed(() => props.tenantCurrencyCode);
const { formatCurrency } = useCurrencyFormatter(preferredCurrency);

const filterPayload = () => {
    const payload = {
        search: filterForm.search,
        status: filterForm.status,
        category_key: filterForm.category_key,
        quick_filter: filterForm.quick_filter !== 'all' ? filterForm.quick_filter : null,
        customer_id: filterForm.customer_id,
        work_id: filterForm.work_id,
        sale_id: filterForm.sale_id,
        invoice_id: filterForm.invoice_id,
        campaign_id: filterForm.campaign_id,
        expense_date_from: filterForm.expense_date_from,
        expense_date_to: filterForm.expense_date_to,
        recap_period: props.filters?.recap_period,
        recap_from: props.filters?.recap_from,
        recap_to: props.filters?.recap_to,
        sort: filterForm.sort,
        direction: filterForm.direction,
        per_page: currentPerPage.value,
    };

    Object.keys(payload).forEach((key) => {
        const value = payload[key];
        if (value === '' || value === null || value === undefined) {
            delete payload[key];
        }
    });

    return payload;
};

let filterTimeout;
const autoFilter = () => {
    if (filterTimeout) {
        clearTimeout(filterTimeout);
    }

    filterTimeout = setTimeout(() => {
        isLoading.value = true;
        router.get(route('expense.index'), filterPayload(), {
            only: ['expenses', 'filters', 'stats', 'periodRecap', 'count'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onFinish: () => {
                isLoading.value = false;
            },
        });
    }, 300);
};

watch(() => [
    filterForm.search,
    filterForm.status,
    filterForm.category_key,
    filterForm.quick_filter,
    filterForm.customer_id,
    filterForm.work_id,
    filterForm.sale_id,
    filterForm.invoice_id,
    filterForm.campaign_id,
    filterForm.expense_date_from,
    filterForm.expense_date_to,
    filterForm.sort,
    filterForm.direction,
], () => {
    autoFilter();
});

const clearFilters = () => {
    filterForm.search = '';
    filterForm.status = '';
    filterForm.category_key = '';
    filterForm.quick_filter = 'all';
    filterForm.customer_id = '';
    filterForm.work_id = '';
    filterForm.sale_id = '';
    filterForm.invoice_id = '';
    filterForm.campaign_id = '';
    filterForm.expense_date_from = '';
    filterForm.expense_date_to = '';
    filterForm.sort = 'expense_date';
    filterForm.direction = 'desc';
    autoFilter();
};

const quickFilterClass = (value) => (
    filterForm.quick_filter === value
        ? 'border-transparent bg-red-600 text-white'
        : 'border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800'
);

const setQuickFilter = (value) => {
    filterForm.quick_filter = value;
};

const toggleSort = (column) => {
    if (filterForm.sort === column) {
        filterForm.direction = filterForm.direction === 'asc' ? 'desc' : 'asc';
        return;
    }

    filterForm.sort = column;
    filterForm.direction = 'asc';
};

const openCreate = () => {
    editingExpense.value = null;
};

const openEdit = (expense) => {
    editingExpense.value = expense;
    if (window.HSOverlay) {
        window.HSOverlay.open('#hs-expense-upsert');
    }
};

const destroyExpense = (expense) => {
    if (!confirm(t('expenses.actions.delete_confirm', { name: expense.title }))) {
        return;
    }

    router.delete(route('expense.destroy', expense.id), {
        preserveScroll: true,
    });
};

const workflowActionClass = (action) => {
    if (['cancel', 'reject'].includes(action)) {
        return 'flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-start text-[13px] text-rose-600 hover:bg-rose-50 dark:text-rose-400 dark:hover:bg-neutral-800';
    }

    return 'flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-start text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800';
};

const workflowActionLabel = (action) => t(`expenses.actions.${action}`);

const mobileWorkflowActionClass = (action) => {
    const base = 'inline-flex items-center rounded-full border px-2.5 py-1 text-[11px] font-medium transition';

    switch (action) {
        case 'approve':
            return `${base} border-sky-200 bg-sky-50 text-sky-700 dark:border-sky-900/40 dark:bg-sky-950/40 dark:text-sky-300`;
        case 'reject':
            return `${base} border-rose-200 bg-rose-50 text-rose-700 dark:border-rose-900/40 dark:bg-rose-950/40 dark:text-rose-300`;
        case 'mark_due':
            return `${base} border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-900/40 dark:bg-amber-950/40 dark:text-amber-300`;
        case 'mark_paid':
        case 'mark_reimbursed':
            return `${base} border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900/40 dark:bg-emerald-950/40 dark:text-emerald-300`;
        case 'cancel':
            return `${base} border-rose-200 bg-rose-50 text-rose-700 dark:border-rose-900/40 dark:bg-rose-950/40 dark:text-rose-300`;
        default:
            return `${base} border-stone-200 bg-white text-stone-700 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200`;
    }
};

const openWorkflowAction = (expense, action) => {
    workflowExpense.value = expense;
    workflowAction.value = action;

    if (window.HSOverlay) {
        window.HSOverlay.open('#hs-expense-workflow-action');
    }
};

const clearWorkflowSelection = () => {
    workflowExpense.value = null;
    workflowAction.value = '';
};

const categoryLabel = (expense) => {
    const entry = (props.categories || []).find((item) => item.key === expense.category_key);
    return entry?.label_key ? t(entry.label_key) : t('expenses.labels.uncategorized');
};

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

const linkedContextBadges = (expense) => {
    const badges = [];

    const customer = customerName(expense.customer);
    if (customer) {
        badges.push({
            key: 'customer',
            label: t('expenses.form.customer'),
            value: customer,
        });
    }

    const work = workLabel(expense.work);
    if (work) {
        badges.push({
            key: 'work',
            label: t('expenses.form.work'),
            value: work,
        });
    }

    if (expense.sale?.number) {
        badges.push({
            key: 'sale',
            label: t('expenses.form.sale'),
            value: expense.sale.number,
        });
    }

    if (expense.invoice?.number) {
        badges.push({
            key: 'invoice',
            label: t('expenses.form.invoice'),
            value: expense.invoice.number,
        });
    }

    if (expense.campaign?.name) {
        badges.push({
            key: 'campaign',
            label: t('expenses.form.campaign'),
            value: expense.campaign.name,
        });
    }

    return badges;
};

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

const formatDate = (value) => humanizeDate(value);
const exportExpenses = () => {
    const query = new URLSearchParams(filterPayload()).toString();
    window.location.href = query
        ? `${route('expense.export')}?${query}`
        : route('expense.export');
};
</script>

<template>
    <div
        class="flex flex-col space-y-4 rounded-sm border border-stone-200 border-t-4 border-t-red-600 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
        <AdminDataTableToolbar
            :show-filters="showAdvanced"
            :show-apply="false"
            :busy="isLoading"
            :filters-label="$t('expenses.actions.filters')"
            :clear-label="$t('expenses.actions.clear')"
            @toggle-filters="showAdvanced = !showAdvanced"
            @apply="autoFilter"
            @clear="clearFilters"
        >
            <template #search>
                <div class="relative">
                    <div class="absolute inset-y-0 start-0 z-20 flex items-center pointer-events-none ps-3.5">
                        <svg class="size-4 shrink-0 text-stone-500 dark:text-neutral-400"
                            xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="11" cy="11" r="8" />
                            <path d="m21 21-4.3-4.3" />
                        </svg>
                    </div>
                    <input
                        v-model="filterForm.search"
                        type="text"
                        class="block w-full rounded-sm border border-stone-200 bg-white py-[7px] ps-10 pe-8 text-sm placeholder:text-stone-500 focus:border-red-600 focus:ring-red-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:placeholder:text-neutral-400"
                        :placeholder="$t('expenses.filters.search_placeholder')"
                    >
                </div>
            </template>

            <template #filters>
                <FloatingSelect
                    v-model="filterForm.status"
                    :label="$t('expenses.filters.status')"
                    :options="statusOptions"
                    :placeholder="$t('expenses.filters.status')"
                    dense
                />
                <FloatingSelect
                    v-model="filterForm.category_key"
                    :label="$t('expenses.filters.category')"
                    :options="categoryOptions"
                    :placeholder="$t('expenses.filters.category')"
                    dense
                />
                <DatePicker v-model="filterForm.expense_date_from" :label="$t('expenses.filters.expense_date_from')" />
                <DatePicker v-model="filterForm.expense_date_to" :label="$t('expenses.filters.expense_date_to')" />
                <FloatingSelect
                    v-model="filterForm.customer_id"
                    :label="$t('expenses.filters.customer')"
                    :options="customerOptions"
                    :placeholder="$t('expenses.filters.customer')"
                    dense
                />
                <FloatingSelect
                    v-model="filterForm.work_id"
                    :label="$t('expenses.filters.work')"
                    :options="workOptions"
                    :placeholder="$t('expenses.filters.work')"
                    dense
                />
                <FloatingSelect
                    v-model="filterForm.sale_id"
                    :label="$t('expenses.filters.sale')"
                    :options="saleOptions"
                    :placeholder="$t('expenses.filters.sale')"
                    dense
                />
                <FloatingSelect
                    v-model="filterForm.invoice_id"
                    :label="$t('expenses.filters.invoice')"
                    :options="invoiceOptions"
                    :placeholder="$t('expenses.filters.invoice')"
                    dense
                />
                <FloatingSelect
                    v-model="filterForm.campaign_id"
                    :label="$t('expenses.filters.campaign')"
                    :options="campaignOptions"
                    :placeholder="$t('expenses.filters.campaign')"
                    dense
                />
            </template>

            <template #actions>
                <div class="flex flex-wrap items-center justify-end gap-2">
                    <button
                        type="button"
                        class="inline-flex items-center gap-x-1.5 rounded-sm border border-stone-200 bg-white px-2.5 py-2 text-xs font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                        @click="exportExpenses"
                    >
                        {{ $t('expenses.actions.export_csv') }}
                    </button>
                    <button
                        v-if="canUseAiIntake"
                        type="button"
                        data-hs-overlay="#hs-expense-ai-scan"
                        class="inline-flex items-center gap-x-1.5 rounded-sm border border-stone-200 bg-white px-2.5 py-2 text-xs font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                    >
                        {{ $t('expenses.actions.scan_with_ai') }}
                    </button>
                    <button
                        type="button"
                        data-hs-overlay="#hs-expense-upsert"
                        @click="openCreate"
                        class="inline-flex items-center gap-x-1.5 rounded-sm border border-transparent bg-red-600 px-2.5 py-2 text-xs font-medium text-white hover:bg-red-700"
                    >
                        <svg class="hidden size-3.5 shrink-0 sm:block" xmlns="http://www.w3.org/2000/svg" width="24"
                            height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                            stroke-linecap="round" stroke-linejoin="round">
                            <path d="M5 12h14" />
                            <path d="M12 5v14" />
                        </svg>
                        {{ $t('expenses.actions.add_expense') }}
                    </button>
                </div>
            </template>
        </AdminDataTableToolbar>

        <div class="flex flex-wrap gap-2">
            <button
                v-for="filter in quickFilters"
                :key="filter.value"
                type="button"
                class="inline-flex items-center rounded-full border px-3 py-1.5 text-xs font-medium transition"
                :class="quickFilterClass(filter.value)"
                @click="setQuickFilter(filter.value)"
            >
                {{ filter.label }}
            </button>
        </div>

        <AdminDataTable
            embedded
            :rows="expenseTableRows"
            :links="expenseLinks"
            :show-pagination="expenseRows.length > 0"
            show-per-page
            :per-page="currentPerPage"
        >
            <template #empty>
                <div class="px-5 py-10 text-center text-sm text-stone-500 dark:text-neutral-500">
                    {{ $t('expenses.empty') }}
                </div>
            </template>

            <template #head>
                <tr>
                    <th scope="col" class="min-w-[260px]">
                        <button type="button" @click="toggleSort('title')"
                            class="flex w-full items-center gap-x-1 px-5 py-2.5 text-start text-sm font-normal text-stone-500 hover:text-stone-700 focus:outline-none dark:text-neutral-500 dark:hover:text-neutral-300">
                            {{ $t('expenses.table.expense') }}
                            <svg v-if="filterForm.sort === 'title'" class="size-3" xmlns="http://www.w3.org/2000/svg"
                                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                stroke-linecap="round" stroke-linejoin="round"
                                :class="filterForm.direction === 'asc' ? 'rotate-180' : ''">
                                <path d="m6 9 6 6 6-6" />
                            </svg>
                        </button>
                    </th>
                    <th scope="col" class="min-w-[160px]">
                        <div class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                            {{ $t('expenses.table.category') }}
                        </div>
                    </th>
                    <th scope="col" class="min-w-[150px]">
                        <button type="button" @click="toggleSort('total')"
                            class="flex w-full items-center gap-x-1 px-5 py-2.5 text-start text-sm font-normal text-stone-500 hover:text-stone-700 focus:outline-none dark:text-neutral-500 dark:hover:text-neutral-300">
                            {{ $t('expenses.table.total') }}
                            <svg v-if="filterForm.sort === 'total'" class="size-3" xmlns="http://www.w3.org/2000/svg"
                                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                stroke-linecap="round" stroke-linejoin="round"
                                :class="filterForm.direction === 'asc' ? 'rotate-180' : ''">
                                <path d="m6 9 6 6 6-6" />
                            </svg>
                        </button>
                    </th>
                    <th scope="col" class="min-w-[140px]">
                        <div class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                            {{ $t('expenses.table.status') }}
                        </div>
                    </th>
                    <th scope="col" class="min-w-[130px]">
                        <button type="button" @click="toggleSort('expense_date')"
                            class="flex w-full items-center gap-x-1 px-5 py-2.5 text-start text-sm font-normal text-stone-500 hover:text-stone-700 focus:outline-none dark:text-neutral-500 dark:hover:text-neutral-300">
                            {{ $t('expenses.table.expense_date') }}
                            <svg v-if="filterForm.sort === 'expense_date'" class="size-3" xmlns="http://www.w3.org/2000/svg"
                                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                stroke-linecap="round" stroke-linejoin="round"
                                :class="filterForm.direction === 'asc' ? 'rotate-180' : ''">
                                <path d="m6 9 6 6 6-6" />
                            </svg>
                        </button>
                    </th>
                    <th scope="col" class="min-w-[120px]">
                        <div class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                            {{ $t('expenses.table.attachments') }}
                        </div>
                    </th>
                    <th scope="col" class="min-w-[60px]"></th>
                </tr>
            </template>

            <template #body="{ rows }">
                <tbody class="divide-y divide-stone-200 dark:divide-neutral-700">
                    <tr v-for="expense in rows" :key="expense.id">
                        <template v-if="expense.__skeleton">
                            <td colspan="7" class="px-4 py-3">
                                <div class="grid animate-pulse grid-cols-6 gap-4">
                                    <div class="h-3 w-40 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                                    <div class="h-3 w-24 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                                    <div class="h-3 w-24 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                                    <div class="h-3 w-20 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                                    <div class="h-3 w-24 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                                    <div class="h-3 w-12 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                                </div>
                            </td>
                        </template>
                        <template v-else>
                            <td class="size-px whitespace-nowrap px-5 py-2">
                                <div class="flex flex-col">
                                    <Link
                                        :href="route('expense.show', expense.id)"
                                        class="text-sm font-medium text-stone-800 hover:text-red-600 dark:text-neutral-100 dark:hover:text-red-400"
                                    >
                                        {{ expense.title }}
                                    </Link>
                                    <span class="text-xs text-stone-500 dark:text-neutral-500">
                                        {{ expense.supplier_name || $t('expenses.labels.no_supplier') }}
                                    </span>
                                    <div class="mt-1 flex flex-wrap gap-1">
                                        <span
                                            v-if="expense.is_recurring"
                                            class="inline-flex rounded-full bg-sky-50 px-2 py-0.5 text-[11px] font-medium text-sky-700 dark:bg-sky-950/40 dark:text-sky-300"
                                        >
                                            {{ $t('expenses.labels.recurring') }}
                                        </span>
                                        <span
                                            v-if="expense.reimbursement_status === 'pending'"
                                            class="inline-flex rounded-full bg-amber-50 px-2 py-0.5 text-[11px] font-medium text-amber-700 dark:bg-amber-950/40 dark:text-amber-300"
                                        >
                                            {{ $t('expenses.labels.reimbursement_pending') }}
                                        </span>
                                    </div>
                                    <div v-if="linkedContextBadges(expense).length" class="mt-2 flex flex-wrap gap-1.5">
                                        <span
                                            v-for="badge in linkedContextBadges(expense)"
                                            :key="`${expense.id}-${badge.key}`"
                                            class="inline-flex rounded-full bg-stone-100 px-2 py-0.5 text-[11px] font-medium text-stone-600 dark:bg-neutral-800 dark:text-neutral-300"
                                        >
                                            {{ badge.label }}: {{ badge.value }}
                                        </span>
                                    </div>
                                    <div v-if="expense.available_actions?.length" class="mt-2 flex flex-wrap gap-1.5 md:hidden">
                                        <button
                                            v-for="action in expense.available_actions"
                                            :key="`mobile-${expense.id}-${action}`"
                                            type="button"
                                            :class="mobileWorkflowActionClass(action)"
                                            @click="openWorkflowAction(expense, action)"
                                        >
                                            {{ workflowActionLabel(action) }}
                                        </button>
                                    </div>
                                </div>
                            </td>
                            <td class="size-px whitespace-nowrap px-5 py-2">
                                <span class="text-sm text-stone-600 dark:text-neutral-400">
                                    {{ categoryLabel(expense) }}
                                </span>
                            </td>
                            <td class="size-px whitespace-nowrap px-5 py-2">
                                <span class="text-sm font-medium text-stone-700 dark:text-neutral-200">
                                    {{ formatCurrency(expense.total, expense.currency_code) }}
                                </span>
                            </td>
                            <td class="size-px whitespace-nowrap px-5 py-2">
                                <span
                                    class="inline-flex items-center gap-x-1.5 rounded-full px-2 py-1.5 text-xs font-medium"
                                    :class="statusClass(expense.status)"
                                >
                                    {{ $t(`expenses.status.${expense.status}`) }}
                                </span>
                            </td>
                            <td class="size-px whitespace-nowrap px-5 py-2">
                                <span class="text-xs text-stone-500 dark:text-neutral-500">
                                    {{ formatDate(expense.expense_date) }}
                                </span>
                            </td>
                            <td class="size-px whitespace-nowrap px-5 py-2">
                                <span class="text-xs text-stone-500 dark:text-neutral-400">
                                    {{ expense.attachments_count || 0 }}
                                </span>
                            </td>
                            <td class="size-px whitespace-nowrap px-5 py-2 text-end">
                                <AdminDataTableActions :label="$t('expenses.aria.dropdown')">
                                    <Link
                                        :href="route('expense.show', expense.id)"
                                        class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-start text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800"
                                    >
                                        {{ $t('expenses.actions.open') }}
                                    </Link>
                                    <button
                                        type="button"
                                        @click="openEdit(expense)"
                                        class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-start text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800"
                                    >
                                        {{ $t('expenses.actions.edit') }}
                                    </button>
                                    <template v-if="expense.available_actions?.length">
                                        <div class="my-1 border-t border-stone-200 dark:border-neutral-800"></div>
                                        <button
                                            v-for="action in expense.available_actions"
                                            :key="`${expense.id}-${action}`"
                                            type="button"
                                            @click="openWorkflowAction(expense, action)"
                                            :class="workflowActionClass(action)"
                                        >
                                            {{ workflowActionLabel(action) }}
                                        </button>
                                    </template>
                                    <div class="my-1 border-t border-stone-200 dark:border-neutral-800"></div>
                                    <button
                                        type="button"
                                        @click="destroyExpense(expense)"
                                        class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-start text-[13px] text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-neutral-800"
                                    >
                                        {{ $t('expenses.actions.delete') }}
                                    </button>
                                </AdminDataTableActions>
                            </td>
                        </template>
                    </tr>
                </tbody>
            </template>

            <template #pagination_prefix>
                <p class="text-sm text-stone-800 dark:text-neutral-200">
                    {{ expenseResultsLabel }}
                </p>
            </template>
        </AdminDataTable>

        <Modal
            :title="editingExpense ? $t('expenses.actions.edit_expense') : $t('expenses.actions.new_expense')"
            :id="'hs-expense-upsert'"
        >
            <ExpenseForm
                :key="editingExpense?.id || 'new'"
                :id="'hs-expense-upsert'"
                :expense="editingExpense"
                :categories="categories"
                :payment-methods="paymentMethods"
                :statuses="statuses"
                :recurrence-frequencies="recurrenceFrequencies"
                :team-members="teamMembers"
                :link-options="linkOptions"
                :petty-cash="pettyCash"
                :tenant-currency-code="tenantCurrencyCode"
                @submitted="editingExpense = null"
            />
        </Modal>

        <Modal
            v-if="canUseAiIntake"
            :title="$t('expenses.ai_scan.modal_title')"
            :id="'hs-expense-ai-scan'"
        >
            <ExpenseAiScanForm
                :id="'hs-expense-ai-scan'"
                :petty-cash="pettyCash"
            />
        </Modal>

        <ExpenseWorkflowModal
            id="hs-expense-workflow-action"
            :expense="workflowExpense"
            :action="workflowAction"
            :reload-only="['expenses', 'filters', 'stats', 'periodRecap', 'count']"
            @start="isLoading = true"
            @finished="isLoading = false"
            @closed="clearWorkflowSelection"
        />
    </div>
</template>
