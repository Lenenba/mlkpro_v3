<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import InputError from '@/Components/InputError.vue';
import Modal from '@/Components/Modal.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
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

const formatNumber = (value) =>
    Number(value || 0).toLocaleString(undefined, { maximumFractionDigits: 0 });

const applyFilters = () => {
    filterForm.get(route('superadmin.support.index'), { preserveScroll: true, preserveState: true });
};

const resetFilters = () => {
    filterForm.reset();
    filterForm.get(route('superadmin.support.index'));
};

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
    if (!editingTicket.value) {
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
                    <button type="button" @click="openCreate"
                        class="py-2 px-3 text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700">
                        {{ $t('super_admin.support.actions.new_ticket') }}
                    </button>
                </div>
            </section>

            <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-2 md:gap-3 lg:gap-5">
                <div class="p-4 bg-white border border-t-4 border-t-emerald-600 border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                    <p class="text-xs text-stone-500 dark:text-neutral-400">
                        {{ $t('super_admin.support.stats.total') }}
                    </p>
                    <p class="mt-1 text-2xl font-semibold text-stone-800 dark:text-neutral-100">
                        {{ formatNumber(stats.total) }}
                    </p>
                </div>
                <div class="p-4 bg-white border border-t-4 border-t-blue-600 border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                    <p class="text-xs text-stone-500 dark:text-neutral-400">
                        {{ $t('super_admin.support.stats.open') }}
                    </p>
                    <p class="mt-1 text-2xl font-semibold text-stone-800 dark:text-neutral-100">
                        {{ formatNumber(stats.open) }}
                    </p>
                </div>
                <div class="p-4 bg-white border border-t-4 border-t-indigo-600 border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                    <p class="text-xs text-stone-500 dark:text-neutral-400">
                        {{ $t('super_admin.support.stats.assigned') }}
                    </p>
                    <p class="mt-1 text-2xl font-semibold text-stone-800 dark:text-neutral-100">
                        {{ formatNumber(stats.assigned) }}
                    </p>
                </div>
                <div class="p-4 bg-white border border-t-4 border-t-amber-600 border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                    <p class="text-xs text-stone-500 dark:text-neutral-400">
                        {{ $t('super_admin.support.stats.pending') }}
                    </p>
                    <p class="mt-1 text-2xl font-semibold text-stone-800 dark:text-neutral-100">
                        {{ formatNumber(stats.pending) }}
                    </p>
                </div>
                <div class="p-4 bg-white border border-t-4 border-t-emerald-700 border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                    <p class="text-xs text-stone-500 dark:text-neutral-400">
                        {{ $t('super_admin.support.stats.resolved') }}
                    </p>
                    <p class="mt-1 text-2xl font-semibold text-stone-800 dark:text-neutral-100">
                        {{ formatNumber(stats.resolved) }}
                    </p>
                </div>
                <div class="p-4 bg-white border border-t-4 border-t-rose-600 border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                    <p class="text-xs text-stone-500 dark:text-neutral-400">
                        {{ $t('super_admin.support.stats.closed') }}
                    </p>
                    <p class="mt-1 text-2xl font-semibold text-stone-800 dark:text-neutral-100">
                        {{ formatNumber(stats.closed) }}
                    </p>
                </div>
            </div>

            <div
                class="p-5 space-y-4 flex flex-col border-t-4 border-t-zinc-600 bg-white border border-stone-200 shadow-sm rounded-sm dark:border-neutral-700 dark:bg-neutral-800">
                <form class="space-y-3" @submit.prevent="applyFilters">
                    <div class="flex flex-col lg:flex-row lg:items-center gap-2">
                        <div class="flex-1">
                            <div class="relative">
                                <div class="absolute inset-y-0 start-0 flex items-center pointer-events-none z-20 ps-3.5">
                                    <svg class="shrink-0 size-4 text-stone-500 dark:text-neutral-400"
                                        xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                                        stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <circle cx="11" cy="11" r="8" />
                                        <path d="m21 21-4.3-4.3" />
                                    </svg>
                                </div>
                                <input v-model="filterForm.search" type="text"
                                    class="py-[7px] ps-10 pe-8 block w-full bg-white border border-stone-200 rounded-sm text-sm placeholder:text-stone-500 focus:border-green-500 focus:ring-green-600 disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200 dark:placeholder:text-neutral-400"
                                    :placeholder="$t('super_admin.support.filters.search_placeholder')">
                            </div>
                        </div>
                        <div class="flex flex-wrap items-center gap-2 justify-end">
                            <button type="button" @click="showFilters = !showFilters"
                                class="py-2 px-2.5 inline-flex items-center gap-x-1.5 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 focus:outline-none focus:ring-2 focus:ring-green-500 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-700">
                                {{ $t('super_admin.common.filters') }}
                            </button>
                            <button type="button" @click="resetFilters"
                                class="py-2 px-2.5 inline-flex items-center gap-x-1.5 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 focus:outline-none focus:ring-2 focus:ring-green-500 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-700">
                                {{ $t('super_admin.common.clear') }}
                            </button>
                            <button type="submit"
                                class="py-2 px-3 inline-flex items-center gap-x-2 text-xs font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700">
                                {{ $t('super_admin.common.apply_filters') }}
                            </button>
                        </div>
                    </div>

                    <div v-if="showFilters" class="grid gap-3 md:grid-cols-3">
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
                    </div>
                </form>
                <div
                    class="overflow-x-auto [&::-webkit-scrollbar]:h-2 [&::-webkit-scrollbar-thumb]:rounded-full [&::-webkit-scrollbar-track]:bg-stone-100 [&::-webkit-scrollbar-thumb]:bg-stone-300 dark:[&::-webkit-scrollbar-track]:bg-neutral-700 dark:[&::-webkit-scrollbar-thumb]:bg-neutral-500">
                    <table class="min-w-full divide-y divide-stone-200 text-sm text-left text-stone-600 dark:divide-neutral-700 dark:text-neutral-300">
                        <thead class="text-xs uppercase text-stone-500 dark:text-neutral-400">
                            <tr>
                                <th class="px-4 py-3">{{ $t('super_admin.support.table.title') }}</th>
                                <th class="px-4 py-3">{{ $t('super_admin.support.table.tenant') }}</th>
                                <th class="px-4 py-3">{{ $t('super_admin.support.table.assigned') }}</th>
                                <th class="px-4 py-3">{{ $t('super_admin.support.table.status') }}</th>
                                <th class="px-4 py-3">{{ $t('super_admin.support.table.priority') }}</th>
                                <th class="px-4 py-3">{{ $t('super_admin.support.table.sla') }}</th>
                                <th class="px-4 py-3">{{ $t('super_admin.support.table.tags') }}</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-200 dark:divide-neutral-700">
                            <tr v-for="ticket in tickets.data" :key="ticket.id">
                                <td class="px-4 py-3">
                                    <Link :href="route('superadmin.support.show', ticket.id)"
                                        class="font-medium text-stone-800 hover:underline dark:text-neutral-100">
                                        {{ ticket.title }}
                                    </Link>
                                    <div v-if="ticket.media?.length" class="mt-2 flex flex-wrap gap-2 text-xs">
                                        <a v-for="media in ticket.media" :key="media.id" :href="media.url" target="_blank" rel="noopener"
                                            class="inline-flex max-w-[180px] items-center gap-1 rounded-full border border-stone-200 bg-white px-2 py-1 text-[11px] text-stone-600 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300 dark:hover:bg-neutral-800">
                                            <svg v-if="attachmentIcon(media) === 'image'" class="size-3.5" xmlns="http://www.w3.org/2000/svg"
                                                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                                stroke-linecap="round" stroke-linejoin="round">
                                                <rect width="18" height="14" x="3" y="5" rx="2" />
                                                <circle cx="8" cy="10" r="2" />
                                                <path d="m21 15-5-5L5 21" />
                                            </svg>
                                            <svg v-else class="size-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                                                fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                                stroke-linejoin="round">
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
                                    <div class="hs-dropdown [--auto-close:inside] [--placement:bottom-right] relative inline-flex">
                                        <button type="button"
                                            class="size-7 inline-flex justify-center items-center gap-x-2 rounded-sm border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-700 dark:focus:bg-neutral-700"
                                            aria-haspopup="menu" aria-expanded="false" :aria-label="$t('super_admin.common.actions')">
                                            <svg class="shrink-0 size-3.5" xmlns="http://www.w3.org/2000/svg" width="24"
                                                height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <circle cx="12" cy="12" r="1" />
                                                <circle cx="12" cy="5" r="1" />
                                                <circle cx="12" cy="19" r="1" />
                                            </svg>
                                        </button>
                                        <div class="hs-dropdown-menu hs-dropdown-open:opacity-100 w-32 transition-[opacity,margin] duration opacity-0 hidden z-10 bg-white rounded-sm shadow-[0_10px_40px_10px_rgba(0,0,0,0.08)] dark:shadow-[0_10px_40px_10px_rgba(0,0,0,0.2)] dark:bg-neutral-900"
                                            role="menu" aria-orientation="vertical">
                                            <div class="p-1">
                                                <Link :href="route('superadmin.support.show', ticket.id)"
                                                    class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800">
                                                    {{ $t('super_admin.common.view') }}
                                                </Link>
                                                <button type="button" @click="openEdit(ticket)"
                                                    class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800">
                                                    {{ $t('super_admin.common.edit') }}
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!tickets.data.length">
                                <td colspan="8" class="px-4 py-6 text-center text-sm text-stone-500 dark:text-neutral-400">
                                    {{ $t('super_admin.support.empty') }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="tickets.links?.length" class="mt-2 flex flex-wrap items-center gap-2 text-sm text-stone-600 dark:text-neutral-400">
                    <template v-for="link in tickets.links" :key="link.url || link.label">
                        <span v-if="!link.url"
                            v-html="link.label"
                            class="px-2 py-1 rounded-sm border border-stone-200 text-stone-400 dark:border-neutral-700">
                        </span>
                        <Link v-else
                            :href="link.url"
                            v-html="link.label"
                            class="px-2 py-1 rounded-sm border border-stone-200 dark:border-neutral-700"
                            :class="link.active ? 'bg-green-600 text-white border-transparent' : 'hover:bg-stone-50 dark:hover:bg-neutral-700'"
                            preserve-scroll />
                    </template>
                </div>
            </div>

            <Modal :show="showCreate" @close="closeCreate">
                <div class="p-5">
                    <div class="flex items-center justify-between">
                        <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                            {{ $t('super_admin.support.actions.create_ticket') }}
                        </h2>
                        <button type="button" @click="closeCreate" class="text-sm text-stone-500 dark:text-neutral-400">
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
                            <textarea v-model="createForm.description" rows="3"
                                class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"></textarea>
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
                                <label class="block text-xs text-stone-500 dark:text-neutral-400">
                                    {{ $t('super_admin.support.form.sla_due') }}
                                </label>
                                <input v-model="createForm.sla_due_at" type="datetime-local"
                                    class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200" />
                            </div>
                        </div>
                        <FloatingInput v-model="createForm.tags" :label="$t('super_admin.support.form.tags')" />
                        <InputError class="mt-1" :message="createForm.errors.tags" />
                        <div class="flex justify-end gap-2">
                            <button type="button" @click="closeCreate"
                                class="py-2 px-3 text-sm font-medium rounded-sm border border-stone-200 text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-700">
                                {{ $t('super_admin.common.cancel') }}
                            </button>
                            <button type="submit"
                                class="py-2 px-3 text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700">
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
                        <button type="button" @click="closeEdit" class="text-sm text-stone-500 dark:text-neutral-400">
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
                                <label class="block text-xs text-stone-500 dark:text-neutral-400">
                                    {{ $t('super_admin.support.form.sla_due') }}
                                </label>
                                <input v-model="editForm.sla_due_at" type="datetime-local"
                                    class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200" />
                            </div>
                        </div>
                        <FloatingInput v-model="editForm.tags" :label="$t('super_admin.support.form.tags')" />
                        <InputError class="mt-1" :message="editForm.errors.tags" />
                        <div class="flex justify-end gap-2">
                            <button type="button" @click="closeEdit"
                                class="py-2 px-3 text-sm font-medium rounded-sm border border-stone-200 text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-700">
                                {{ $t('super_admin.common.cancel') }}
                            </button>
                            <button type="submit"
                                class="py-2 px-3 text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700">
                                {{ $t('super_admin.support.actions.save_ticket') }}
                            </button>
                        </div>
                    </form>
                </div>
            </Modal>
        </div>
    </AuthenticatedLayout>
</template>
