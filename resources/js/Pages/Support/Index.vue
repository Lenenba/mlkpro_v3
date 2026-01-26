<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import SettingsLayout from '@/Layouts/SettingsLayout.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import FloatingTextarea from '@/Components/FloatingTextarea.vue';
import InputError from '@/Components/InputError.vue';
import Modal from '@/Components/Modal.vue';
import { humanizeDate } from '@/utils/date';
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
    categories: {
        type: Array,
        default: () => [],
    },
    stats: {
        type: Object,
        default: () => ({}),
    },
});

const { t } = useI18n();

const formatNumber = (value) =>
    Number(value || 0).toLocaleString(undefined, { maximumFractionDigits: 0 });
const formatDate = (value) => humanizeDate(value);

const statusOptions = computed(() =>
    (props.statuses || []).map((status) => ({
        value: status,
        label: t(`support_portal.statuses.${status}`),
    }))
);
const editableStatusOptions = computed(() =>
    statusOptions.value.map((option) =>
        option.value === 'assigned' ? { ...option, disabled: true } : option
    )
);
const priorityOptions = computed(() =>
    (props.priorities || []).map((priority) => ({
        value: priority,
        label: t(`support_portal.priorities.${priority}`),
    }))
);
const categoryOptions = computed(() =>
    (props.categories || []).map((category) => ({
        value: category,
        label: t(`support_portal.categories.${category}`),
    }))
);

const filterForm = useForm({
    search: props.filters?.search ?? '',
    status: props.filters?.status ?? '',
    priority: props.filters?.priority ?? '',
});

const showFilters = ref(false);
const showCreate = ref(false);
const showEdit = ref(false);
const attachmentInput = ref(null);
const editAttachmentInput = ref(null);
const selectedTicket = ref(null);

const applyFilters = () => {
    filterForm.get(route('settings.support.index'), {
        only: ['tickets', 'filters', 'stats'],
        preserveScroll: true,
        preserveState: true,
    });
};

const resetFilters = () => {
    filterForm.reset();
    filterForm.get(route('settings.support.index'), {
        only: ['tickets', 'filters', 'stats'],
        preserveScroll: true,
    });
};

const createForm = useForm({
    category: props.categories?.[0] || 'incident',
    title: '',
    description: '',
    priority: props.priorities?.[1] || 'normal',
    attachments: [],
});

const editForm = useForm({
    title: '',
    description: '',
    priority: '',
    status: '',
    attachments: [],
});

const openCreate = () => {
    showCreate.value = true;
};

const clearAttachments = () => {
    createForm.attachments = [];
    if (attachmentInput.value) {
        attachmentInput.value.value = '';
    }
};

const closeCreate = () => {
    showCreate.value = false;
    createForm.reset('title', 'description');
    clearAttachments();
    createForm.clearErrors();
};

const submitTicket = () => {
    createForm.post(route('settings.support.store'), {
        preserveScroll: true,
        onSuccess: () => closeCreate(),
        forceFormData: true,
    });
};

const openEdit = (ticket) => {
    selectedTicket.value = ticket;
    editForm.title = ticket.title || '';
    editForm.description = ticket.description || '';
    editForm.priority = ticket.priority || props.priorities?.[1] || 'normal';
    editForm.status = ticket.status || props.statuses?.[0] || 'open';
    editForm.attachments = [];
    if (editAttachmentInput.value) {
        editAttachmentInput.value.value = '';
    }
    showEdit.value = true;
};

const closeEdit = () => {
    showEdit.value = false;
    selectedTicket.value = null;
    editForm.reset('title', 'description', 'priority', 'status');
    editForm.attachments = [];
    if (editAttachmentInput.value) {
        editAttachmentInput.value.value = '';
    }
    editForm.clearErrors();
};

const submitEdit = () => {
    if (!selectedTicket.value) {
        return;
    }
    editForm.put(route('settings.support.update', selectedTicket.value.id), {
        preserveScroll: true,
        onSuccess: () => closeEdit(),
        forceFormData: true,
    });
};

const onEditAttachmentChange = (event) => {
    const files = event?.target?.files ? Array.from(event.target.files) : [];
    editForm.attachments = files;
};

const onAttachmentChange = (event) => {
    const files = event?.target?.files ? Array.from(event.target.files) : [];
    createForm.attachments = files;
};

const statusClass = (status) => {
    switch (status) {
        case 'open':
            return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300';
        case 'pending':
            return 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300';
        case 'assigned':
            return 'bg-indigo-100 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300';
        case 'resolved':
            return 'bg-sky-100 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300';
        case 'closed':
            return 'bg-stone-100 text-stone-700 dark:bg-neutral-700 dark:text-neutral-300';
        default:
            return 'bg-stone-100 text-stone-700 dark:bg-neutral-700 dark:text-neutral-300';
    }
};

const priorityClass = (priority) => {
    switch (priority) {
        case 'urgent':
            return 'bg-rose-100 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300';
        case 'high':
            return 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300';
        case 'normal':
            return 'bg-sky-100 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300';
        case 'low':
            return 'bg-stone-100 text-stone-700 dark:bg-neutral-700 dark:text-neutral-300';
        default:
            return 'bg-stone-100 text-stone-700 dark:bg-neutral-700 dark:text-neutral-300';
    }
};

const categoryLabel = (ticket) => {
    const tag = Array.isArray(ticket.tags) ? ticket.tags[0] : null;
    if (!tag) {
        return t('support_portal.categories.other');
    }
    const key = `support_portal.categories.${tag}`;
    const translated = t(key);
    return translated === key ? tag : translated;
};

const attachmentLabel = (media) => media.original_name || t('support_portal.attachments.file');

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
    <Head :title="$t('support_portal.page_title')" />

    <SettingsLayout active="support">
        <div class="space-y-6">
            <section class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="space-y-1">
                        <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">
                            {{ $t('support_portal.title') }}
                        </h1>
                        <p class="text-sm text-stone-600 dark:text-neutral-400">
                            {{ $t('support_portal.subtitle') }}
                        </p>
                    </div>
                    <button type="button" @click="openCreate"
                        class="py-2 px-3 text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700">
                        {{ $t('support_portal.actions.new_ticket') }}
                    </button>
                </div>
            </section>

            <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-2 md:gap-3 lg:gap-5">
                <div class="p-4 bg-white border border-t-4 border-t-emerald-600 border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                    <p class="text-xs text-stone-500 dark:text-neutral-400">
                        {{ $t('support_portal.stats.total') }}
                    </p>
                    <p class="mt-1 text-2xl font-semibold text-stone-800 dark:text-neutral-100">
                        {{ formatNumber(stats.total) }}
                    </p>
                </div>
                <div class="p-4 bg-white border border-t-4 border-t-emerald-500 border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                    <p class="text-xs text-stone-500 dark:text-neutral-400">
                        {{ $t('support_portal.stats.open') }}
                    </p>
                    <p class="mt-1 text-2xl font-semibold text-stone-800 dark:text-neutral-100">
                        {{ formatNumber(stats.open) }}
                    </p>
                </div>
                <div class="p-4 bg-white border border-t-4 border-t-indigo-500 border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                    <p class="text-xs text-stone-500 dark:text-neutral-400">
                        {{ $t('support_portal.stats.assigned') }}
                    </p>
                    <p class="mt-1 text-2xl font-semibold text-stone-800 dark:text-neutral-100">
                        {{ formatNumber(stats.assigned) }}
                    </p>
                </div>
                <div class="p-4 bg-white border border-t-4 border-t-amber-600 border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                    <p class="text-xs text-stone-500 dark:text-neutral-400">
                        {{ $t('support_portal.stats.pending') }}
                    </p>
                    <p class="mt-1 text-2xl font-semibold text-stone-800 dark:text-neutral-100">
                        {{ formatNumber(stats.pending) }}
                    </p>
                </div>
                <div class="p-4 bg-white border border-t-4 border-t-sky-600 border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                    <p class="text-xs text-stone-500 dark:text-neutral-400">
                        {{ $t('support_portal.stats.resolved') }}
                    </p>
                    <p class="mt-1 text-2xl font-semibold text-stone-800 dark:text-neutral-100">
                        {{ formatNumber(stats.resolved) }}
                    </p>
                </div>
                <div class="p-4 bg-white border border-t-4 border-t-stone-400 border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                    <p class="text-xs text-stone-500 dark:text-neutral-400">
                        {{ $t('support_portal.stats.closed') }}
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
                                    :placeholder="$t('support_portal.filters.search_placeholder')">
                            </div>
                        </div>
                        <div class="flex flex-wrap items-center gap-2 justify-end">
                            <button type="button" @click="showFilters = !showFilters"
                                class="py-2 px-2.5 inline-flex items-center gap-x-1.5 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 focus:outline-none focus:ring-2 focus:ring-green-500 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-700">
                                {{ $t('support_portal.filters.toggle') }}
                            </button>
                            <button type="button" @click="resetFilters"
                                class="py-2 px-2.5 inline-flex items-center gap-x-1.5 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 focus:outline-none focus:ring-2 focus:ring-green-500 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-700">
                                {{ $t('support_portal.filters.clear') }}
                            </button>
                            <button type="submit"
                                class="py-2 px-3 inline-flex items-center gap-x-2 text-xs font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700">
                                {{ $t('support_portal.filters.apply') }}
                            </button>
                        </div>
                    </div>

                    <div v-if="showFilters" class="grid gap-3 md:grid-cols-3">
                        <FloatingSelect
                            v-model="filterForm.status"
                            :label="$t('support_portal.filters.status')"
                            :options="statusOptions"
                            :placeholder="$t('support_portal.filters.status')"
                            dense
                        />
                        <FloatingSelect
                            v-model="filterForm.priority"
                            :label="$t('support_portal.filters.priority')"
                            :options="priorityOptions"
                            :placeholder="$t('support_portal.filters.priority')"
                            dense
                        />
                    </div>
                </form>

                <div
                    class="overflow-x-auto [&::-webkit-scrollbar]:h-2 [&::-webkit-scrollbar-thumb]:rounded-full [&::-webkit-scrollbar-track]:bg-stone-100 [&::-webkit-scrollbar-thumb]:bg-stone-300 dark:[&::-webkit-scrollbar-track]:bg-neutral-700 dark:[&::-webkit-scrollbar-thumb]:bg-neutral-500">
                    <table class="min-w-full divide-y divide-stone-200 text-sm text-left text-stone-600 dark:divide-neutral-700 dark:text-neutral-300">
                        <thead class="text-xs uppercase text-stone-500 dark:text-neutral-400">
                            <tr>
                                <th class="px-4 py-3">{{ $t('support_portal.table.title') }}</th>
                                <th class="px-4 py-3">{{ $t('support_portal.table.category') }}</th>
                                <th class="px-4 py-3">{{ $t('support_portal.table.status') }}</th>
                                <th class="px-4 py-3">{{ $t('support_portal.table.priority') }}</th>
                                <th class="px-4 py-3">{{ $t('support_portal.table.created') }}</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-200 dark:divide-neutral-700">
                            <tr v-for="ticket in tickets.data" :key="ticket.id">
                                <td class="px-4 py-3">
                                    <Link :href="route('settings.support.show', ticket.id)"
                                        class="font-medium text-stone-800 hover:underline dark:text-neutral-100">
                                        {{ ticket.title }}
                                    </Link>
                                    <div v-if="ticket.assigned_to" class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                        {{ $t('support_portal.labels.assigned_to') }}: {{ ticket.assigned_to.name || ticket.assigned_to.email }}
                                    </div>
                                    <div v-if="ticket.description" class="mt-1 text-xs text-stone-500 dark:text-neutral-400 line-clamp-2">
                                        {{ ticket.description }}
                                    </div>
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
                                <td class="px-4 py-3 text-xs">
                                    <span class="rounded-full bg-stone-100 px-2 py-0.5 text-stone-700 dark:bg-neutral-700 dark:text-neutral-200">
                                        {{ categoryLabel(ticket) }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-xs">
                                    <span class="rounded-full px-2 py-0.5" :class="statusClass(ticket.status)">
                                        {{ $t(`support_portal.statuses.${ticket.status}`) }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-xs">
                                    <span class="rounded-full px-2 py-0.5" :class="priorityClass(ticket.priority)">
                                        {{ $t(`support_portal.priorities.${ticket.priority}`) }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-xs text-stone-500 dark:text-neutral-400">
                                    {{ formatDate(ticket.created_at) }}
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        <Link :href="route('settings.support.show', ticket.id)"
                                            class="inline-flex items-center gap-1 rounded-sm border border-stone-200 px-2 py-1 text-xs font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800">
                                            {{ $t('support_portal.actions.view') }}
                                        </Link>
                                        <button type="button" @click="openEdit(ticket)"
                                            class="inline-flex items-center gap-1 rounded-sm border border-stone-200 px-2 py-1 text-xs font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800">
                                            {{ $t('support_portal.actions.update') }}
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!tickets.data.length">
                                <td colspan="6" class="px-4 py-6 text-center text-sm text-stone-500 dark:text-neutral-400">
                                    {{ $t('support_portal.table.empty') }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <Modal :show="showCreate" @close="closeCreate">
            <div class="p-5 space-y-4">
                <div class="flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                        {{ $t('support_portal.actions.new_ticket') }}
                    </h3>
                    <button type="button" @click="closeCreate"
                        class="text-xs font-semibold text-stone-500 hover:text-stone-700 dark:text-neutral-400 dark:hover:text-neutral-200">
                        {{ $t('support_portal.actions.close') }}
                    </button>
                </div>

                <form class="space-y-3" @submit.prevent="submitTicket">
                    <FloatingSelect
                        v-model="createForm.category"
                        :label="$t('support_portal.form.category')"
                        :options="categoryOptions"
                        :placeholder="$t('support_portal.form.category')"
                    />
                    <FloatingInput v-model="createForm.title" :label="$t('support_portal.form.title')" />
                    <FloatingTextarea v-model="createForm.description" :label="$t('support_portal.form.description')" />
                    <FloatingSelect
                        v-model="createForm.priority"
                        :label="$t('support_portal.form.priority')"
                        :options="priorityOptions"
                        :placeholder="$t('support_portal.form.priority')"
                    />
                    <div>
                        <label class="block text-xs text-stone-500 dark:text-neutral-400">
                            {{ $t('support_portal.attachments.label') }}
                        </label>
                        <input
                            ref="attachmentInput"
                            type="file"
                            multiple
                            accept="image/*,application/pdf"
                            class="mt-1 block w-full text-sm text-stone-600 file:me-4 file:rounded-sm file:border-0 file:bg-stone-100 file:px-3 file:py-2 file:text-xs file:font-semibold file:text-stone-700 hover:file:bg-stone-200 dark:text-neutral-300 dark:file:bg-neutral-800 dark:file:text-neutral-200 dark:hover:file:bg-neutral-700"
                            @change="onAttachmentChange"
                        />
                        <p class="mt-1 text-xs text-stone-400 dark:text-neutral-500">
                            {{ $t('support_portal.attachments.help') }}
                        </p>
                        <div v-if="createForm.attachments?.length" class="mt-2 flex flex-wrap gap-2">
                            <span v-for="file in createForm.attachments" :key="file.name"
                                class="inline-flex max-w-[200px] items-center gap-1 rounded-full border border-stone-200 bg-white px-2 py-0.5 text-[11px] text-stone-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300">
                                {{ file.name }}
                            </span>
                        </div>
                        <InputError class="mt-1" :message="createForm.errors.attachments" />
                        <InputError class="mt-1" :message="createForm.errors['attachments.0']" />
                    </div>
                    <InputError class="mt-1" :message="createForm.errors.title" />
                    <InputError class="mt-1" :message="createForm.errors.category" />
                    <InputError class="mt-1" :message="createForm.errors.priority" />
                    <InputError class="mt-1" :message="createForm.errors.description" />
                    <div class="flex justify-end">
                        <button type="submit"
                            class="py-2 px-3 inline-flex items-center text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700"
                            :disabled="createForm.processing">
                            {{ $t('support_portal.actions.submit') }}
                        </button>
                    </div>
                </form>
            </div>
        </Modal>

        <Modal :show="showEdit" @close="closeEdit">
            <div class="p-5 space-y-4">
                <div class="flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                        {{ $t('support_portal.actions.update') }}
                    </h3>
                    <button type="button" @click="closeEdit"
                        class="text-xs font-semibold text-stone-500 hover:text-stone-700 dark:text-neutral-400 dark:hover:text-neutral-200">
                        {{ $t('support_portal.actions.close') }}
                    </button>
                </div>

                <div v-if="selectedTicket?.media?.length" class="rounded-sm border border-stone-200 bg-stone-50 p-3 text-xs text-stone-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300">
                    <div class="font-semibold mb-2">{{ $t('support_portal.attachments.label') }}</div>
                    <div class="flex flex-wrap gap-2">
                        <a v-for="media in selectedTicket.media" :key="media.id" :href="media.url" target="_blank" rel="noopener"
                            class="inline-flex max-w-[180px] items-center gap-1 rounded-full border border-stone-200 bg-white px-2 py-1 text-[11px] text-stone-600 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-950 dark:text-neutral-300 dark:hover:bg-neutral-800">
                            <span class="truncate">{{ attachmentLabel(media) }}</span>
                        </a>
                    </div>
                </div>

                <form class="space-y-3" @submit.prevent="submitEdit">
                    <FloatingInput v-model="editForm.title" :label="$t('support_portal.form.title')" />
                    <FloatingTextarea v-model="editForm.description" :label="$t('support_portal.form.description')" />
                    <div class="grid gap-3 md:grid-cols-2">
                        <FloatingSelect
                            v-model="editForm.status"
                            :label="$t('support_portal.form.status')"
                            :options="editableStatusOptions"
                            :placeholder="$t('support_portal.form.status')"
                        />
                        <FloatingSelect
                            v-model="editForm.priority"
                            :label="$t('support_portal.form.priority')"
                            :options="priorityOptions"
                            :placeholder="$t('support_portal.form.priority')"
                        />
                    </div>
                    <div>
                        <label class="block text-xs text-stone-500 dark:text-neutral-400">
                            {{ $t('support_portal.attachments.label') }}
                        </label>
                        <input
                            ref="editAttachmentInput"
                            type="file"
                            multiple
                            accept="image/*,application/pdf"
                            class="mt-1 block w-full text-sm text-stone-600 file:me-4 file:rounded-sm file:border-0 file:bg-stone-100 file:px-3 file:py-2 file:text-xs file:font-semibold file:text-stone-700 hover:file:bg-stone-200 dark:text-neutral-300 dark:file:bg-neutral-800 dark:file:text-neutral-200 dark:hover:file:bg-neutral-700"
                            @change="onEditAttachmentChange"
                        />
                        <p class="mt-1 text-xs text-stone-400 dark:text-neutral-500">
                            {{ $t('support_portal.attachments.help') }}
                        </p>
                        <div v-if="editForm.attachments?.length" class="mt-2 flex flex-wrap gap-2">
                            <span v-for="file in editForm.attachments" :key="file.name"
                                class="inline-flex max-w-[200px] items-center gap-1 rounded-full border border-stone-200 bg-white px-2 py-0.5 text-[11px] text-stone-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300">
                                {{ file.name }}
                            </span>
                        </div>
                        <InputError class="mt-1" :message="editForm.errors.attachments" />
                        <InputError class="mt-1" :message="editForm.errors['attachments.0']" />
                    </div>
                    <InputError class="mt-1" :message="editForm.errors.title" />
                    <InputError class="mt-1" :message="editForm.errors.description" />
                    <InputError class="mt-1" :message="editForm.errors.status" />
                    <InputError class="mt-1" :message="editForm.errors.priority" />
                    <div class="flex justify-end">
                        <button type="submit"
                            class="py-2 px-3 inline-flex items-center text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700"
                            :disabled="editForm.processing">
                            {{ $t('support_portal.actions.save') }}
                        </button>
                    </div>
                </form>
            </div>
        </Modal>
    </SettingsLayout>
</template>
