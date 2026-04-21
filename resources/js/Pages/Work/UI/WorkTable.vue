<script setup>
import { computed, ref, watch } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AdminDataTable from '@/Components/DataTable/AdminDataTable.vue';
import AdminDataTableActions from '@/Components/DataTable/AdminDataTableActions.vue';
import AdminDataTableToolbar from '@/Components/DataTable/AdminDataTableToolbar.vue';
import StarRating from '@/Components/UI/StarRating.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import DatePicker from '@/Components/DatePicker.vue';
import { humanizeDate } from '@/utils/date';
import { resolveDataTablePerPage } from '@/Components/DataTable/pagination';
import { crmButtonClass } from '@/utils/crmButtonStyles';
import Modal from '@/Components/UI/Modal.vue';
import InputError from '@/Components/InputError.vue';

const props = defineProps({
    works: {
        type: Object,
        required: true,
    },
    filters: {
        type: Object,
        default: () => ({}),
    },
    customers: {
        type: Array,
        default: () => [],
    },
});

const filterForm = useForm({
    search: props.filters?.search ?? '',
    status: props.filters?.status ?? '',
    customer_id: props.filters?.customer_id ?? '',
    start_from: props.filters?.start_from ?? '',
    start_to: props.filters?.start_to ?? '',
    sort: props.filters?.sort ?? 'start_date',
    direction: props.filters?.direction ?? 'desc',
});

const showAdvanced = ref(false);
const isLoading = ref(false);
const createCustomerId = ref('');
const createError = ref('');

const { t } = useI18n();
const page = usePage();
const isOwner = computed(() => Boolean(page.props.auth?.account?.is_owner));

const statusOptions = computed(() => ([
    { value: '', label: t('jobs.filters.status.all') },
    { value: 'to_schedule', label: t('jobs.status.to_schedule') },
    { value: 'scheduled', label: t('jobs.status.scheduled') },
    { value: 'en_route', label: t('jobs.status.en_route') },
    { value: 'in_progress', label: t('jobs.status.in_progress') },
    { value: 'tech_complete', label: t('jobs.status.tech_complete') },
    { value: 'pending_review', label: t('jobs.status.pending_review') },
    { value: 'validated', label: t('jobs.status.validated') },
    { value: 'auto_validated', label: t('jobs.status.auto_validated') },
    { value: 'dispute', label: t('jobs.status.dispute') },
    { value: 'closed', label: t('jobs.status.closed') },
    { value: 'cancelled', label: t('jobs.status.cancelled') },
    { value: 'completed', label: t('jobs.status.completed') },
]));

const customerOptions = computed(() => ([
    { value: '', label: t('jobs.filters.customer.all') },
    ...(props.customers || []).map((customer) => ({
        value: String(customer.id),
        label: customer.company_name || `${customer.first_name} ${customer.last_name}`,
    })),
]));

const createCustomerOptions = computed(() =>
    (props.customers || []).map((customer) => ({
        value: String(customer.id),
        label: customer.company_name || `${customer.first_name} ${customer.last_name}`,
    }))
);

const statusLabels = computed(() => ({
    to_schedule: t('jobs.status.to_schedule'),
    scheduled: t('jobs.status.scheduled'),
    en_route: t('jobs.status.en_route'),
    in_progress: t('jobs.status.in_progress'),
    tech_complete: t('jobs.status.tech_complete'),
    pending_review: t('jobs.status.pending_review'),
    validated: t('jobs.status.validated'),
    auto_validated: t('jobs.status.auto_validated'),
    dispute: t('jobs.status.dispute'),
    closed: t('jobs.status.closed'),
    cancelled: t('jobs.status.cancelled'),
    completed: t('jobs.status.completed'),
}));

const formatStatus = (status) =>
    statusLabels.value[status] || status || statusLabels.value.scheduled;
const resolveStatus = (status) => status || 'scheduled';

const filterPayload = () => {
    const payload = {
        search: filterForm.search,
        status: filterForm.status,
        customer_id: filterForm.customer_id,
        start_from: filterForm.start_from,
        start_to: filterForm.start_to,
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
        router.get(route('work.index'), filterPayload(), {
            only: ['works', 'filters', 'stats', 'customers'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onFinish: () => {
                isLoading.value = false;
            },
        });
    }, 300);
};

watch(() => filterForm.search, () => {
    autoFilter();
});

watch(() => [
    filterForm.status,
    filterForm.customer_id,
    filterForm.start_from,
    filterForm.start_to,
    filterForm.sort,
    filterForm.direction,
], () => {
    autoFilter();
});

const clearFilters = () => {
    filterForm.search = '';
    filterForm.status = '';
    filterForm.customer_id = '';
    filterForm.start_from = '';
    filterForm.start_to = '';
    filterForm.sort = 'start_date';
    filterForm.direction = 'desc';
    autoFilter();
};

const toggleSort = (column) => {
    if (filterForm.sort === column) {
        filterForm.direction = filterForm.direction === 'asc' ? 'desc' : 'asc';
        return;
    }
    filterForm.sort = column;
    filterForm.direction = 'asc';
};

const formatDate = (value) => humanizeDate(value);

const getCustomerName = (work) => {
    const customer = work.customer;
    if (!customer) {
        return t('jobs.labels.unknown_customer');
    }
    return customer.company_name || `${customer.first_name} ${customer.last_name}`;
};

const createInvoice = (work) => {
    router.post(route('invoice.store-from-work', work.id), {}, {
        preserveScroll: true,
    });
};

const openCreateModal = () => {
    createError.value = '';
    if (window.HSOverlay) {
        window.HSOverlay.open('#hs-work-create');
    }
};

const createJob = () => {
    if (!createCustomerId.value) {
        createError.value = t('jobs.create_modal.customer_required');
        return;
    }
    createError.value = '';
    const customerId = Number(createCustomerId.value);
    if (!customerId) {
        createError.value = t('jobs.create_modal.customer_required');
        return;
    }
    if (window.HSOverlay) {
        window.HSOverlay.close('#hs-work-create');
    }
    router.visit(route('work.create', customerId));
};

watch(createCustomerId, () => {
    if (createError.value) {
        createError.value = '';
    }
});

const workRows = computed(() => (Array.isArray(props.works?.data) ? props.works.data : []));
const workTableRows = computed(() => (isLoading.value
    ? Array.from({ length: 6 }, (_, index) => ({ id: `work-skeleton-${index}`, __skeleton: true }))
    : workRows.value));
const workLinks = computed(() => props.works?.links || []);
const currentPerPage = computed(() => resolveDataTablePerPage(props.works?.per_page, props.filters?.per_page));
const workResultsLabel = computed(() => `${props.works?.total ?? props.works?.data?.length ?? 0} ${t('jobs.table.results')}`);
</script>

<template>
    <div
        class="p-5 space-y-4 flex flex-col border-t-4 border-t-zinc-600 bg-white border border-stone-200 shadow-sm rounded-sm dark:bg-neutral-800 dark:border-neutral-700">
        <AdminDataTableToolbar
            :show-filters="showAdvanced"
            :show-apply="false"
            :busy="isLoading"
            :filters-label="$t('jobs.actions.filters')"
            :clear-label="$t('jobs.actions.reset')"
            @toggle-filters="showAdvanced = !showAdvanced"
            @apply="autoFilter"
            @clear="clearFilters"
        >
            <template #search>
                <div class="relative">
                    <div class="absolute inset-y-0 start-0 flex items-center pointer-events-none z-20 ps-3.5">
                        <svg class="shrink-0 size-4 text-stone-500 dark:text-neutral-400"
                            xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="11" cy="11" r="8" />
                            <path d="m21 21-4.3-4.3" />
                        </svg>
                    </div>
                    <input type="text" v-model="filterForm.search"
                        class="py-[7px] ps-10 pe-8 block w-full bg-white border border-stone-200 rounded-sm text-sm placeholder:text-stone-500 focus:border-green-500 focus:ring-green-600 disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200 dark:placeholder:text-neutral-400 dark:focus:ring-neutral-600"
                        :placeholder="$t('jobs.filters.search_placeholder')">
                </div>
            </template>

            <template #filters>
                <FloatingSelect
                    v-model="filterForm.status"
                    :label="$t('jobs.table.status')"
                    :options="statusOptions"
                    dense
                />
                <FloatingSelect
                    v-model="filterForm.customer_id"
                    :label="$t('jobs.table.customer')"
                    :options="customerOptions"
                    dense
                />
                <DatePicker v-model="filterForm.start_from" :label="$t('jobs.filters.start_from')" />
                <DatePicker v-model="filterForm.start_to" :label="$t('jobs.filters.start_to')" />
            </template>

            <template #actions>
                <button
                    v-if="isOwner"
                    type="button"
                    @click="openCreateModal"
                    :class="crmButtonClass('primary', 'toolbar')"
                >
                    {{ $t('jobs.actions.create') }}
                </button>
            </template>
        </AdminDataTableToolbar>

        <AdminDataTable
            embedded
            :rows="workTableRows"
            :links="workLinks"
            :show-pagination="workRows.length > 0"
            show-per-page
            :per-page="currentPerPage"
        >
            <template #empty>
                <div class="px-5 py-10 text-center text-sm text-stone-500 dark:text-neutral-500">
                    {{ $t('jobs.empty') }}
                </div>
            </template>

            <template #head>
                <tr>
                    <th scope="col" class="min-w-[240px]">
                        <button type="button" @click="toggleSort('job_title')"
                            class="px-5 py-2.5 text-start w-full flex items-center gap-x-1 text-sm font-normal text-stone-500 hover:text-stone-700 focus:outline-none dark:text-neutral-500 dark:hover:text-neutral-300">
                            {{ $t('jobs.table.job') }}
                            <svg v-if="filterForm.sort === 'job_title'" class="size-3" xmlns="http://www.w3.org/2000/svg"
                                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                stroke-linecap="round" stroke-linejoin="round"
                                :class="filterForm.direction === 'asc' ? 'rotate-180' : ''">
                                <path d="m6 9 6 6 6-6" />
                            </svg>
                        </button>
                    </th>
                    <th scope="col" class="min-w-40">
                        <div class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                            {{ $t('jobs.table.customer') }}
                        </div>
                    </th>
                    <th scope="col" class="min-w-32">
                        <button type="button" @click="toggleSort('status')"
                            class="px-5 py-2.5 text-start w-full flex items-center gap-x-1 text-sm font-normal text-stone-500 hover:text-stone-700 focus:outline-none dark:text-neutral-500 dark:hover:text-neutral-300">
                            {{ $t('jobs.table.status') }}
                            <svg v-if="filterForm.sort === 'status'" class="size-3" xmlns="http://www.w3.org/2000/svg"
                                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                stroke-linecap="round" stroke-linejoin="round"
                                :class="filterForm.direction === 'asc' ? 'rotate-180' : ''">
                                <path d="m6 9 6 6 6-6" />
                            </svg>
                        </button>
                    </th>
                    <th scope="col" class="min-w-32">
                        <div class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                            {{ $t('jobs.table.rating') }}
                        </div>
                    </th>
                    <th scope="col" class="min-w-32">
                        <button type="button" @click="toggleSort('total')"
                            class="px-5 py-2.5 text-start w-full flex items-center gap-x-1 text-sm font-normal text-stone-500 hover:text-stone-700 focus:outline-none dark:text-neutral-500 dark:hover:text-neutral-300">
                            {{ $t('jobs.table.total') }}
                            <svg v-if="filterForm.sort === 'total'" class="size-3" xmlns="http://www.w3.org/2000/svg"
                                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                stroke-linecap="round" stroke-linejoin="round"
                                :class="filterForm.direction === 'asc' ? 'rotate-180' : ''">
                                <path d="m6 9 6 6 6-6" />
                            </svg>
                        </button>
                    </th>
                    <th scope="col" class="min-w-32">
                        <button type="button" @click="toggleSort('start_date')"
                            class="px-5 py-2.5 text-start w-full flex items-center gap-x-1 text-sm font-normal text-stone-500 hover:text-stone-700 focus:outline-none dark:text-neutral-500 dark:hover:text-neutral-300">
                            {{ $t('jobs.table.start_date') }}
                            <svg v-if="filterForm.sort === 'start_date'" class="size-3" xmlns="http://www.w3.org/2000/svg"
                                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                stroke-linecap="round" stroke-linejoin="round"
                                :class="filterForm.direction === 'asc' ? 'rotate-180' : ''">
                                <path d="m6 9 6 6 6-6" />
                            </svg>
                        </button>
                    </th>
                    <th scope="col" class="min-w-32">
                        <button type="button" @click="toggleSort('created_at')"
                            class="px-5 py-2.5 text-start w-full flex items-center gap-x-1 text-sm font-normal text-stone-500 hover:text-stone-700 focus:outline-none dark:text-neutral-500 dark:hover:text-neutral-300">
                            {{ $t('jobs.table.created_at') }}
                            <svg v-if="filterForm.sort === 'created_at'" class="size-3" xmlns="http://www.w3.org/2000/svg"
                                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                stroke-linecap="round" stroke-linejoin="round"
                                :class="filterForm.direction === 'asc' ? 'rotate-180' : ''">
                                <path d="m6 9 6 6 6-6" />
                            </svg>
                        </button>
                    </th>
                    <th scope="col"></th>
                </tr>
            </template>

            <template #body="{ rows }">
                <tbody class="divide-y divide-stone-200 dark:divide-neutral-700">
                    <tr v-for="work in rows" :key="work.id">
                        <template v-if="work.__skeleton">
                            <td colspan="8" class="px-4 py-3">
                                <div class="grid grid-cols-6 gap-4 animate-pulse">
                                    <div class="h-3 w-32 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                                    <div class="h-3 w-28 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                                    <div class="h-3 w-24 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                                    <div class="h-3 w-20 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                                    <div class="h-3 w-16 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                                    <div class="h-3 w-20 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                                </div>
                            </td>
                        </template>
                        <template v-else>
                            <td class="size-px whitespace-nowrap px-4 py-2 text-start">
                                <Link :href="route('work.show', work.id)" class="flex flex-col hover:underline">
                                    <span class="text-sm text-stone-600 dark:text-neutral-300">
                                        {{ work.job_title }}
                                    </span>
                                    <span class="text-xs text-stone-500 dark:text-neutral-500">
                                        {{ work.number }}
                                    </span>
                                </Link>
                            </td>
                            <td class="size-px whitespace-nowrap px-4 py-2">
                                <span class="text-sm text-stone-600 dark:text-neutral-300">
                                    {{ getCustomerName(work) }}
                                </span>
                            </td>
                            <td class="size-px whitespace-nowrap px-4 py-2">
                                <span
                                    class="py-1.5 px-2 inline-flex items-center gap-x-1.5 text-xs font-medium rounded-full"
                                    :class="{
                                        'bg-amber-100 text-amber-800 dark:bg-amber-500/20 dark:text-amber-200': resolveStatus(work.status) === 'to_schedule',
                                        'bg-yellow-100 text-yellow-800 dark:bg-yellow-500/20 dark:text-yellow-200': resolveStatus(work.status) === 'scheduled',
                                        'bg-sky-100 text-sky-800 dark:bg-sky-500/20 dark:text-sky-200': resolveStatus(work.status) === 'en_route',
                                        'bg-blue-100 text-blue-800 dark:bg-blue-500/20 dark:text-blue-200': resolveStatus(work.status) === 'in_progress',
                                        'bg-indigo-100 text-indigo-800 dark:bg-indigo-500/20 dark:text-indigo-200': resolveStatus(work.status) === 'tech_complete',
                                        'bg-violet-100 text-violet-800 dark:bg-violet-500/20 dark:text-violet-200': resolveStatus(work.status) === 'pending_review',
                                        'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/20 dark:text-emerald-200': resolveStatus(work.status) === 'validated',
                                        'bg-teal-100 text-teal-800 dark:bg-teal-500/20 dark:text-teal-200': resolveStatus(work.status) === 'auto_validated',
                                        'bg-rose-100 text-rose-800 dark:bg-rose-500/20 dark:text-rose-200': resolveStatus(work.status) === 'dispute',
                                        'bg-slate-200 text-slate-800 dark:bg-slate-500/20 dark:text-slate-200': resolveStatus(work.status) === 'closed',
                                        'bg-stone-200 text-stone-700 dark:bg-neutral-700 dark:text-neutral-300': resolveStatus(work.status) === 'cancelled',
                                        'bg-lime-100 text-lime-800 dark:bg-lime-500/20 dark:text-lime-200': resolveStatus(work.status) === 'completed',
                                    }">
                                    {{ formatStatus(work.status) }}
                                </span>
                                <span
                                    v-if="work.overdue_tasks_count > 0"
                                    class="ms-2 py-1 px-2 inline-flex items-center text-[11px] font-semibold rounded-full bg-rose-100 text-rose-700 dark:bg-rose-500/20 dark:text-rose-200"
                                >
                                    {{ $t('jobs.badges.overdue', { count: work.overdue_tasks_count }) }}
                                </span>
                            </td>
                            <td class="size-px whitespace-nowrap px-4 py-2">
                                <StarRating :value="work.ratings_avg_rating" icon-class="h-3.5 w-3.5" empty-label="-" />
                            </td>
                            <td class="size-px whitespace-nowrap px-4 py-2">
                                <span class="text-sm text-stone-600 dark:text-neutral-300">
                                    ${{ Number(work.total || 0).toFixed(2) }}
                                </span>
                            </td>
                            <td class="size-px whitespace-nowrap px-4 py-2">
                                <span class="text-xs text-stone-500 dark:text-neutral-500">
                                    {{ formatDate(work.start_date) }}
                                </span>
                            </td>
                            <td class="size-px whitespace-nowrap px-4 py-2">
                                <span class="text-xs text-stone-500 dark:text-neutral-500">
                                    {{ formatDate(work.created_at) }}
                                </span>
                            </td>
                            <td class="size-px whitespace-nowrap px-4 py-2 text-end">
                                <AdminDataTableActions :label="$t('jobs.actions.view')">
                                    <Link :href="route('work.show', work.id)"
                                        class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800">
                                        {{ $t('jobs.actions.view') }}
                                    </Link>
                                    <Link :href="route('work.edit', work.id)"
                                        class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800">
                                        {{ $t('jobs.actions.edit') }}
                                    </Link>
                                    <Link v-if="['to_schedule', 'scheduled'].includes(work.status || 'scheduled')"
                                        :href="route('work.edit', { work: work.id, tab: 'planning' })"
                                        class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800">
                                        {{ $t('jobs.actions.schedule') }}
                                    </Link>
                                    <div class="my-1 border-t border-stone-200 dark:border-neutral-800"></div>
                                    <Link v-if="work.invoice" :href="route('invoice.show', work.invoice.id)"
                                        class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800">
                                        {{ $t('jobs.actions.view_invoice') }}
                                    </Link>
                                    <button v-else type="button" @click="createInvoice(work)"
                                        class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-emerald-600 hover:bg-emerald-50 dark:text-emerald-400 dark:hover:bg-neutral-800 action-feedback">
                                        {{ $t('jobs.actions.create_invoice') }}
                                    </button>
                                </AdminDataTableActions>
                            </td>
                        </template>
                    </tr>
                </tbody>
            </template>

            <template #pagination_prefix>
                <p class="text-sm text-stone-800 dark:text-neutral-200">
                    {{ workResultsLabel }}
                </p>
            </template>
        </AdminDataTable>

        <Modal :title="$t('jobs.create_modal.title')" :id="'hs-work-create'">
            <div class="space-y-4">
                <p class="text-sm text-stone-600 dark:text-neutral-300">
                    {{ $t('jobs.create_modal.subtitle') }}
                </p>

                <div v-if="!createCustomerOptions.length" class="rounded-sm border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
                    {{ $t('jobs.create_modal.empty') }}
                    <div class="mt-2">
                        <Link
                            :href="route('customer.create')"
                            class="inline-flex items-center text-xs font-semibold text-amber-800 hover:underline dark:text-amber-200"
                        >
                            {{ $t('jobs.create_modal.add_customer') }}
                        </Link>
                    </div>
                </div>

                <div v-else>
                    <FloatingSelect
                        v-model="createCustomerId"
                        :label="$t('jobs.create_modal.customer_label')"
                        :options="createCustomerOptions"
                    />
                    <InputError class="mt-1" :message="createError" />
                </div>

                <div class="flex justify-end gap-2">
                    <button
                        type="button"
                        class="py-2 px-3 inline-flex items-center text-sm font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200"
                        data-hs-overlay="#hs-work-create"
                    >
                        {{ $t('jobs.create_modal.cancel') }}
                    </button>
                    <button
                        type="button"
                        class="py-2 px-3 inline-flex items-center text-sm font-medium rounded-sm border border-transparent bg-emerald-600 text-white hover:bg-emerald-700 disabled:opacity-50"
                        :disabled="!createCustomerOptions.length"
                        @click="createJob"
                    >
                        {{ $t('jobs.create_modal.create') }}
                    </button>
                </div>
            </div>
        </Modal>
    </div>
</template>
