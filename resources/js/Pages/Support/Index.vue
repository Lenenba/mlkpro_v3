<script setup>
import { computed, ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
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

const applyFilters = () => {
    filterForm.get(route('support.index'), {
        preserveScroll: true,
        preserveState: true,
    });
};

const resetFilters = () => {
    filterForm.reset();
    filterForm.get(route('support.index'));
};

const createForm = useForm({
    category: props.categories?.[0] || 'incident',
    title: '',
    description: '',
    priority: props.priorities?.[1] || 'normal',
});

const openCreate = () => {
    showCreate.value = true;
};

const closeCreate = () => {
    showCreate.value = false;
    createForm.reset('title', 'description');
    createForm.clearErrors();
};

const submitTicket = () => {
    createForm.post(route('support.store'), {
        preserveScroll: true,
        onSuccess: () => closeCreate(),
    });
};

const statusClass = (status) => {
    switch (status) {
        case 'open':
            return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300';
        case 'pending':
            return 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300';
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
</script>

<template>
    <Head :title="$t('support_portal.page_title')" />

    <AuthenticatedLayout>
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

            <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-5 gap-2 md:gap-3 lg:gap-5">
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
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-200 dark:divide-neutral-700">
                            <tr v-for="ticket in tickets.data" :key="ticket.id">
                                <td class="px-4 py-3">
                                    <div class="font-medium text-stone-800 dark:text-neutral-100">
                                        {{ ticket.title }}
                                    </div>
                                    <div v-if="ticket.description" class="mt-1 text-xs text-stone-500 dark:text-neutral-400 line-clamp-2">
                                        {{ ticket.description }}
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
                            </tr>
                            <tr v-if="!tickets.data.length">
                                <td colspan="5" class="px-4 py-6 text-center text-sm text-stone-500 dark:text-neutral-400">
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
    </AuthenticatedLayout>
</template>
