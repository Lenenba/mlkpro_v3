<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AdminDataTable from '@/Components/DataTable/AdminDataTable.vue';
import { resolveDataTablePerPage } from '@/Components/DataTable/pagination';
import AdminDataTableActions from '@/Components/DataTable/AdminDataTableActions.vue';
import AdminDataTableToolbar from '@/Components/DataTable/AdminDataTableToolbar.vue';
import DateTimePicker from '@/Components/DateTimePicker.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import InputError from '@/Components/InputError.vue';
import Modal from '@/Components/Modal.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import useDataTableFilters from '@/Composables/useDataTableFilters';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    tickets: {
        type: Object,
        required: true,
    },
    filters: {
        type: Object,
        default: () => ({}),
    },
    statuses: {
        type: Array,
        default: () => [],
    },
    priorities: {
        type: Array,
        default: () => [],
    },
    assignees: {
        type: Array,
        default: () => [],
    },
    tenants: {
        type: Array,
        default: () => [],
    },
    stats: {
        type: Object,
        default: () => ({}),
    },
});

const { t } = useI18n();
const showFilters = ref(false);
const showCreate = ref(false);

const statusOptions = computed(() =>
    (props.statuses || []).map((status) => ({
        value: status,
        label: status,
    }))
);

const priorityOptions = computed(() =>
    (props.priorities || []).map((priority) => ({
        value: priority,
        label: priority,
    }))
);

const tenantOptions = computed(() =>
    (props.tenants || []).map((tenant) => ({
        value: String(tenant.id),
        label: tenant.company_name || tenant.email,
    }))
);

const assigneeOptions = computed(() =>
    (props.assignees || []).map((assignee) => ({
        value: String(assignee.id),
        label: assignee.name || assignee.email,
    }))
);

const filterForm = useForm({
    search: props.filters?.search ?? '',
    status: props.filters?.status ?? '',
    priority: props.filters?.priority ?? '',
    account_id: props.filters?.account_id ?? '',
});

const createForm = useForm({
    account_id: '',
    title: '',
    description: '',
    status: props.statuses[0] || 'open',
    priority: props.priorities[1] || 'normal',
    sla_due_at: '',
    tags: '',
    assigned_to_user_id: '',
});

const editingTicket = ref(null);
const showEdit = computed(() => Boolean(editingTicket.value));

const editForm = useForm({
    status: '',
    priority: '',
    sla_due_at: '',
    tags: '',
    assigned_to_user_id: '',
});

const ticketRows = computed(() => props.tickets?.data || []);
const ticketLinks = computed(() => props.tickets?.links || []);
const ticketsTotal = computed(() => Number(props.tickets?.total || ticketRows.value.length || 0));
const ticketResultsLabel = computed(() => t('super_admin.support.filters.results', { count: ticketsTotal.value }));
const currentPerPage = computed(() => resolveDataTablePerPage(props.tickets?.per_page, props.filters?.per_page));

const formatNumber = (value) =>
    Number(value || 0).toLocaleString(undefined, { maximumFractionDigits: 0 });

const { apply: applyFilters, clear: clearFilters } = useDataTableFilters(
    filterForm,
    route('superadmin.support.index'),
    {
        only: ['tickets', 'filters', 'stats'],
    }
);

const openCreate = () => {
    showCreate.value = true;
};

const closeCreate = () => {
    showCreate.value = false;
    createForm.reset('account_id', 'title', 'description', 'sla_due_at', 'tags', 'assigned_to_user_id');
    createForm.clearErrors();
};

const submitTicket = () => {
    createForm.post(route('superadmin.support.store'), {
        preserveScroll: true,
        onSuccess: () => closeCreate(),
    });
};

const openEdit = (ticket) => {
    editingTicket.value = ticket;
    editForm.status = ticket.status;
    editForm.priority = ticket.priority;
    editForm.sla_due_at = ticket.sla_due_at ? ticket.sla_due_at.substring(0, 16) : '';
    editForm.tags = Array.isArray(ticket.tags) ? ticket.tags.join(', ') : '';
    editForm.assigned_to_user_id = ticket.assigned_to?.id ? String(ticket.assigned_to.id) : '';
};

const closeEdit = () => {
    editingTicket.value = null;
    editForm.reset('status', 'priority', 'sla_due_at', 'tags', 'assigned_to_user_id');
    editForm.clearErrors();
};

const submitEdit = () => {
    if (! editingTicket.value) {
        return;
    }

    editForm.put(route('superadmin.support.update', editingTicket.value.id), {
        preserveScroll: true,
        onSuccess: () => closeEdit(),
    });
};

const attachmentLabel = (media) => media.original_name || t('super_admin.support.attachments.file');

const attachmentIcon = (media) => {
    const mime = media?.mime || '';

    if (mime.startsWith('image/')) {
        return 'image';
    }

    if (mime === 'application/pdf') {
        return 'file';
    }

    return 'file';
};
</script>

<template>
    <Head :title="$t('super_admin.support.page_title')" />

    <AuthenticatedLayout>
        <div class="space-y-6">
            <section class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="space-y-1">
                        <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">
                            {{ $t('super_admin.support.title') }}
                        </h1>
                        <p class="text-sm text-stone-600 dark:text-neutral-400">
                            {{ $t('super_admin.support.subtitle') }}
                        </p>
                    </div>
                    <button
                        type="button"
                        class="rounded-sm border border-transparent bg-green-600 px-3 py-2 text-sm font-medium text-white hover:bg-green-700"
                        @click="openCreate"
                    >
                        {{ $t('super_admin.support.actions.new_ticket') }}
                    </button>
                </div>
            </section>

            <div class="grid grid-cols-2 gap-2 md:grid-cols-3 md:gap-3 lg:gap-5 xl:grid-cols-6">
                <div class="rounded-sm border border-stone-200 border-t-4 border-t-emerald-600 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                    <p class="text-xs text-stone-500 dark:text-neutral-400">
                        {{ $t('super_admin.support.stats.total') }}
                    </p>
                    <p class="mt-1 text-2xl font-semibold text-stone-800 dark:text-neutral-100">
                        {{ formatNumber(stats.total) }}
                    </p>
                </div>
                <div class="rounded-sm border border-stone-200 border-t-4 border-t-blue-600 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                    <p class="text-xs text-stone-500 dark:text-neutral-400">
                        {{ $t('super_admin.support.stats.open') }}
                    </p>
                    <p class="mt-1 text-2xl font-semibold text-stone-800 dark:text-neutral-100">
                        {{ formatNumber(stats.open) }}
                    </p>
                </div>
                <div class="rounded-sm border border-stone-200 border-t-4 border-t-indigo-600 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                    <p class="text-xs text-stone-500 dark:text-neutral-400">
                        {{ $t('super_admin.support.stats.assigned') }}
                    </p>
                    <p class="mt-1 text-2xl font-semibold text-stone-800 dark:text-neutral-100">
                        {{ formatNumber(stats.assigned) }}
                    </p>
                </div>
                <div class="rounded-sm border border-stone-200 border-t-4 border-t-amber-600 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                    <p class="text-xs text-stone-500 dark:text-neutral-400">
                        {{ $t('super_admin.support.stats.pending') }}
                    </p>
                    <p class="mt-1 text-2xl font-semibold text-stone-800 dark:text-neutral-100">
                        {{ formatNumber(stats.pending) }}
                    </p>
                </div>
                <div class="rounded-sm border border-stone-200 border-t-4 border-t-emerald-700 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                    <p class="text-xs text-stone-500 dark:text-neutral-400">
                        {{ $t('super_admin.support.stats.resolved') }}
                    </p>
                    <p class="mt-1 text-2xl font-semibold text-stone-800 dark:text-neutral-100">
                        {{ formatNumber(stats.resolved) }}
                    </p>
                </div>
                <div class="rounded-sm border border-stone-200 border-t-4 border-t-rose-600 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                    <p class="text-xs text-stone-500 dark:text-neutral-400">
                        {{ $t('super_admin.support.stats.closed') }}
                    </p>
                    <p class="mt-1 text-2xl font-semibold text-stone-800 dark:text-neutral-100">
                        {{ formatNumber(stats.closed) }}
                    </p>
                </div>
            </div>

            <AdminDataTable
                :rows="ticketRows"
                :links="ticketLinks"
                :total="ticketsTotal"
                :result-label="ticketResultsLabel"
                :empty-description="$t('super_admin.support.empty')"
                container-class="border-t-4 border-t-zinc-600"
                show-per-page
                :per-page="currentPerPage"
            >
                <template #toolbar>
                    <AdminDataTableToolbar
                        :show-filters="showFilters"
                        :search-placeholder="$t('super_admin.support.filters.search_placeholder')"
                        :filters-label="$t('super_admin.common.filters')"
                        :clear-label="$t('super_admin.common.clear')"
                        :apply-label="$t('super_admin.common.apply_filters')"
                        @toggle-filters="showFilters = !showFilters"
                        @apply="applyFilters"
                        @clear="clearFilters"
                    >
                        <template #search="{ searchPlaceholder }">
                            <div class="relative">
                                <div class="pointer-events-none absolute inset-y-0 start-0 z-20 flex items-center ps-3.5">
                                    <svg class="size-4 shrink-0 text-stone-500 dark:text-neutral-400" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <circle cx="11" cy="11" r="8" />
                                        <path d="m21 21-4.3-4.3" />
                                    </svg>
                                </div>
                                <input
                                    v-model="filterForm.search"
                                    type="text"
                                    :placeholder="searchPlaceholder"
                                    class="block w-full rounded-sm border border-stone-200 bg-white py-[7px] ps-10 pe-8 text-sm text-stone-700 placeholder:text-stone-500 focus:border-green-500 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:placeholder:text-neutral-400"
                                >
                            </div>
                        </template>

                        <template #filters>
                            <div>
                                <FloatingSelect
                                    v-model="filterForm.status"
                                    :label="$t('super_admin.support.filters.status')"
                                    :options="statusOptions"
                                    :placeholder="$t('super_admin.common.all')"
                                    dense
                                />
                            </div>
                            <div>
                                <FloatingSelect
                                    v-model="filterForm.priority"
                                    :label="$t('super_admin.support.filters.priority')"
                                    :options="priorityOptions"
                                    :placeholder="$t('super_admin.common.all')"
                                    dense
                                />
                            </div>
                            <div>
                                <FloatingSelect
                                    v-model="filterForm.account_id"
                                    :label="$t('super_admin.support.filters.tenant')"
                                    :options="tenantOptions"
                                    :placeholder="$t('super_admin.common.all')"
                                    dense
                                />
                            </div>
                        </template>
                    </AdminDataTableToolbar>
                </template>

                <template #head>
                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-stone-600 dark:text-neutral-300">
                        <th class="px-4 py-3">{{ $t('super_admin.support.table.title') }}</th>
                        <th class="px-4 py-3">{{ $t('super_admin.support.table.tenant') }}</th>
                        <th class="px-4 py-3">{{ $t('super_admin.support.table.assigned') }}</th>
                        <th class="px-4 py-3">{{ $t('super_admin.support.table.status') }}</th>
                        <th class="px-4 py-3">{{ $t('super_admin.support.table.priority') }}</th>
                        <th class="px-4 py-3">{{ $t('super_admin.support.table.sla') }}</th>
                        <th class="px-4 py-3">{{ $t('super_admin.support.table.tags') }}</th>
                        <th class="px-4 py-3 text-right"></th>
                    </tr>
                </template>

                <template #row="{ row: ticket }">
                    <tr class="align-top">
                        <td class="px-4 py-3">
                            <Link
                                :href="route('superadmin.support.show', ticket.id)"
                                class="font-medium text-stone-800 hover:underline dark:text-neutral-100"
                            >
                                {{ ticket.title }}
                            </Link>
                            <div v-if="ticket.media?.length" class="mt-2 flex flex-wrap gap-2 text-xs">
                                <a
                                    v-for="media in ticket.media"
                                    :key="media.id"
                                    :href="media.url"
                                    target="_blank"
                                    rel="noopener"
                                    class="inline-flex max-w-[180px] items-center gap-1 rounded-full border border-stone-200 bg-white px-2 py-1 text-[11px] text-stone-600 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300 dark:hover:bg-neutral-800"
                                >
                                    <svg v-if="attachmentIcon(media) === 'image'" class="size-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <rect width="18" height="14" x="3" y="5" rx="2" />
                                        <circle cx="8" cy="10" r="2" />
                                        <path d="m21 15-5-5L5 21" />
                                    </svg>
                                    <svg v-else class="size-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2Z" />
                                        <path d="M14 2v6h6" />
                                    </svg>
                                    <span class="truncate">{{ attachmentLabel(media) }}</span>
                                </a>
                            </div>
                        </td>
                        <td class="px-4 py-3">{{ ticket.account?.company_name || ticket.account?.email }}</td>
                        <td class="px-4 py-3">
                            {{ ticket.assigned_to?.name || ticket.assigned_to?.email || $t('super_admin.common.not_available') }}
                        </td>
                        <td class="px-4 py-3">{{ ticket.status }}</td>
                        <td class="px-4 py-3">{{ ticket.priority }}</td>
                        <td class="px-4 py-3">
                            {{ ticket.sla_due_at ? new Date(ticket.sla_due_at).toLocaleString() : $t('super_admin.common.not_available') }}
                        </td>
                        <td class="px-4 py-3">{{ Array.isArray(ticket.tags) ? ticket.tags.join(', ') : '' }}</td>
                        <td class="px-4 py-3 text-right">
                            <AdminDataTableActions :label="$t('super_admin.common.actions')">
                                <Link
                                    :href="route('superadmin.support.show', ticket.id)"
                                    class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800"
                                >
                                    {{ $t('super_admin.common.view') }}
                                </Link>
                                <button
                                    type="button"
                                    class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800"
                                    @click="openEdit(ticket)"
                                >
                                    {{ $t('super_admin.common.edit') }}
                                </button>
                            </AdminDataTableActions>
                        </td>
                    </tr>
                </template>
            </AdminDataTable>

            <Modal :show="showCreate" @close="closeCreate">
                <div class="p-5">
                    <div class="flex items-center justify-between">
                        <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                            {{ $t('super_admin.support.actions.create_ticket') }}
                        </h2>
                        <button type="button" class="text-sm text-stone-500 dark:text-neutral-400" @click="closeCreate">
                            {{ $t('super_admin.common.close') }}
                        </button>
                    </div>
                    <form class="mt-4 space-y-3" @submit.prevent="submitTicket">
                        <div>
                            <FloatingSelect
                                v-model="createForm.account_id"
                                :label="$t('super_admin.support.form.tenant')"
                                :options="tenantOptions"
                                :placeholder="$t('super_admin.support.form.select_tenant')"
                            />
                            <InputError class="mt-1" :message="createForm.errors.account_id" />
                        </div>
                        <div>
                            <FloatingSelect
                                v-model="createForm.assigned_to_user_id"
                                :label="$t('super_admin.support.form.assigned')"
                                :options="assigneeOptions"
                                :placeholder="$t('super_admin.support.form.select_assignee')"
                            />
                            <InputError class="mt-1" :message="createForm.errors.assigned_to_user_id" />
                        </div>
                        <FloatingInput v-model="createForm.title" :label="$t('super_admin.support.form.title')" />
                        <InputError class="mt-1" :message="createForm.errors.title" />
                        <div>
                            <label class="block text-xs text-stone-500 dark:text-neutral-400">
                                {{ $t('super_admin.support.form.description') }}
                            </label>
                            <textarea
                                v-model="createForm.description"
                                rows="3"
                                class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                            />
                            <InputError class="mt-1" :message="createForm.errors.description" />
                        </div>
                        <div class="grid gap-3 md:grid-cols-3">
                            <div>
                                <FloatingSelect
                                    v-model="createForm.status"
                                    :label="$t('super_admin.support.form.status')"
                                    :options="statusOptions"
                                />
                            </div>
                            <div>
                                <FloatingSelect
                                    v-model="createForm.priority"
                                    :label="$t('super_admin.support.form.priority')"
                                    :options="priorityOptions"
                                />
                            </div>
                            <div>
                                <DateTimePicker
                                    v-model="createForm.sla_due_at"
                                    :label="$t('super_admin.support.form.sla_due')"
                                />
                            </div>
                        </div>
                        <FloatingInput v-model="createForm.tags" :label="$t('super_admin.support.form.tags')" />
                        <InputError class="mt-1" :message="createForm.errors.tags" />
                        <div class="flex justify-end gap-2">
                            <button
                                type="button"
                                class="rounded-sm border border-stone-200 px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-700"
                                @click="closeCreate"
                            >
                                {{ $t('super_admin.common.cancel') }}
                            </button>
                            <button
                                type="submit"
                                class="rounded-sm border border-transparent bg-green-600 px-3 py-2 text-sm font-medium text-white hover:bg-green-700"
                            >
                                {{ $t('super_admin.support.actions.create_ticket') }}
                            </button>
                        </div>
                    </form>
                </div>
            </Modal>

            <Modal :show="showEdit" @close="closeEdit">
                <div class="p-5">
                    <div class="flex items-center justify-between">
                        <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                            {{ $t('super_admin.support.actions.update_ticket') }}
                        </h2>
                        <button type="button" class="text-sm text-stone-500 dark:text-neutral-400" @click="closeEdit">
                            {{ $t('super_admin.common.close') }}
                        </button>
                    </div>
                    <form class="mt-4 space-y-3" @submit.prevent="submitEdit">
                        <div>
                            <FloatingSelect
                                v-model="editForm.assigned_to_user_id"
                                :label="$t('super_admin.support.form.assigned')"
                                :options="assigneeOptions"
                                :placeholder="$t('super_admin.support.form.select_assignee')"
                            />
                            <InputError class="mt-1" :message="editForm.errors.assigned_to_user_id" />
                        </div>
                        <div class="grid gap-3 md:grid-cols-3">
                            <div>
                                <FloatingSelect
                                    v-model="editForm.status"
                                    :label="$t('super_admin.support.form.status')"
                                    :options="statusOptions"
                                />
                            </div>
                            <div>
                                <FloatingSelect
                                    v-model="editForm.priority"
                                    :label="$t('super_admin.support.form.priority')"
                                    :options="priorityOptions"
                                />
                            </div>
                            <div>
                                <DateTimePicker
                                    v-model="editForm.sla_due_at"
                                    :label="$t('super_admin.support.form.sla_due')"
                                />
                            </div>
                        </div>
                        <FloatingInput v-model="editForm.tags" :label="$t('super_admin.support.form.tags')" />
                        <InputError class="mt-1" :message="editForm.errors.tags" />
                        <div class="flex justify-end gap-2">
                            <button
                                type="button"
                                class="rounded-sm border border-stone-200 px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-700"
                                @click="closeEdit"
                            >
                                {{ $t('super_admin.common.cancel') }}
                            </button>
                            <button
                                type="submit"
                                class="rounded-sm border border-transparent bg-green-600 px-3 py-2 text-sm font-medium text-white hover:bg-green-700"
                            >
                                {{ $t('super_admin.support.actions.save_ticket') }}
                            </button>
                        </div>
                    </form>
                </div>
            </Modal>
        </div>
    </AuthenticatedLayout>
</template>
