<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import DatePicker from '@/Components/DatePicker.vue';
import InputError from '@/Components/InputError.vue';
import { humanizeDate } from '@/utils/date';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    categories: {
        type: Object,
        required: true,
    },
    filters: {
        type: Object,
        default: () => ({}),
    },
    stats: {
        type: Object,
        default: () => ({}),
    },
    count: {
        type: Number,
        default: 0,
    },
    creators: {
        type: Array,
        default: () => [],
    },
});

const page = usePage();
const ownerId = computed(() => page.props?.auth?.account?.owner_id ?? null);
const { t } = useI18n();
const statusFilterOptions = computed(() => ([
    { value: 'active', label: t('services.status.active') },
    { value: 'archived', label: t('services.status.archived') },
]));
const creatorOptions = computed(() =>
    (props.creators || []).map((creator) => ({
        value: String(creator.id),
        label: creator.name,
    }))
);

const categoryForm = useForm({
    name: '',
});

const editingCategory = ref(null);
const canSubmitCategory = computed(() => categoryForm.name.trim().length > 0);

const resetCategoryForm = () => {
    categoryForm.reset('name');
    categoryForm.clearErrors();
    editingCategory.value = null;
};

const saveCategory = () => {
    if (!canSubmitCategory.value) {
        return;
    }

    if (editingCategory.value) {
        categoryForm.patch(route('settings.categories.update', editingCategory.value.id), {
            preserveScroll: true,
            onSuccess: () => resetCategoryForm(),
        });
        return;
    }

    categoryForm.post(route('settings.categories.store'), {
        preserveScroll: true,
        onSuccess: () => resetCategoryForm(),
    });
};

const startEditCategory = (category) => {
    if (!canManageCategory(category)) {
        return;
    }
    editingCategory.value = category;
    categoryForm.name = category.name;
    categoryForm.clearErrors();
};

const filterForm = useForm({
    search: props.filters?.search ?? '',
    status: props.filters?.status ?? '',
    created_from: props.filters?.created_from ?? '',
    created_to: props.filters?.created_to ?? '',
    created_by: props.filters?.created_by ?? '',
    sort: props.filters?.sort ?? 'created_at',
    direction: props.filters?.direction ?? 'desc',
});

const showAdvanced = ref(false);

const filterPayload = () => {
    const payload = {
        search: filterForm.search,
        status: filterForm.status,
        created_from: filterForm.created_from,
        created_to: filterForm.created_to,
        created_by: filterForm.created_by,
        sort: filterForm.sort,
        direction: filterForm.direction,
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
        router.get(route('service.categories'), filterPayload(), {
            only: ['categories', 'filters', 'stats', 'count'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }, 300);
};

watch(
    () => [
        filterForm.search,
        filterForm.status,
        filterForm.created_from,
        filterForm.created_to,
        filterForm.created_by,
        filterForm.sort,
        filterForm.direction,
    ],
    () => {
        autoFilter();
    }
);

const clearFilters = () => {
    filterForm.search = '';
    filterForm.status = '';
    filterForm.created_from = '';
    filterForm.created_to = '';
    filterForm.created_by = '';
    filterForm.sort = 'created_at';
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

const canManageCategory = (category) => {
    if (!category?.user_id || !ownerId.value) {
        return false;
    }
    return Number(category.user_id) === Number(ownerId.value);
};

const creatorLabel = (category) => {
    if (!category?.created_by_user_id) {
        if (category?.user_id && ownerId.value && Number(category.user_id) === Number(ownerId.value)) {
            return t('services.categories.creator.owner');
        }
        return t('services.categories.creator.system');
    }
    return Number(category.created_by_user_id) === Number(category.user_id)
        ? t('services.categories.creator.owner')
        : t('services.categories.creator.team_member');
};

const creatorName = (category) => {
    if (category?.created_by?.name) {
        return category.created_by.name;
    }
    if (category?.user_id && ownerId.value && Number(category.user_id) === Number(ownerId.value)) {
        return page.props?.auth?.user?.name || t('services.categories.creator.owner');
    }
    return t('services.categories.creator.system');
};

const archiveCategory = (category) => {
    if (!canManageCategory(category)) {
        return;
    }
    if (!confirm(t('services.categories.actions.archive_confirm', { name: category.name }))) {
        return;
    }
    router.patch(route('settings.categories.archive', category.id), {}, { preserveScroll: true });
};

const restoreCategory = (category) => {
    if (!canManageCategory(category)) {
        return;
    }
    router.patch(route('settings.categories.restore', category.id), {}, { preserveScroll: true });
};
</script>

<template>
    <Head :title="$t('services.categories.title')" />

    <AuthenticatedLayout>
        <div class="space-y-5">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-2 md:gap-3 lg:gap-5">
                <div
                    class="p-4 sm:p-5 bg-white border border-t-4 border-t-emerald-600 border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                    <div class="sm:flex sm:gap-x-3">
                        <svg class="sm:order-2 mb-2 sm:mb-0 shrink-0 size-6 text-stone-400 dark:text-neutral-600"
                            xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M3 3h18v18H3z" />
                            <path d="M7 7h10v10H7z" />
                        </svg>
                        <div class="sm:order-1 grow space-y-1">
                            <h2 class="sm:mb-2 text-sm text-stone-500 dark:text-neutral-400">
                                {{ $t('services.categories.stats.total') }}
                            </h2>
                            <p class="text-lg md:text-xl font-semibold text-stone-800 dark:text-neutral-200">
                                {{ Number(stats.total || 0).toLocaleString() }}
                            </p>
                        </div>
                    </div>
                </div>

                <div
                    class="p-4 sm:p-5 bg-white border border-t-4 border-t-sky-600 border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                    <div class="sm:flex sm:gap-x-3">
                        <svg class="sm:order-2 mb-2 sm:mb-0 shrink-0 size-6 text-stone-400 dark:text-neutral-600"
                            xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                            <path d="m9 12 2 2 4-4" />
                            <circle cx="12" cy="12" r="10" />
                        </svg>
                        <div class="sm:order-1 grow space-y-1">
                            <h2 class="sm:mb-2 text-sm text-stone-500 dark:text-neutral-400">
                                {{ $t('services.categories.stats.active') }}
                            </h2>
                            <p class="text-lg md:text-xl font-semibold text-stone-800 dark:text-neutral-200">
                                {{ Number(stats.active || 0).toLocaleString() }}
                            </p>
                        </div>
                    </div>
                </div>

                <div
                    class="p-4 sm:p-5 bg-white border border-t-4 border-t-stone-500 border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                    <div class="sm:flex sm:gap-x-3">
                        <svg class="sm:order-2 mb-2 sm:mb-0 shrink-0 size-6 text-stone-400 dark:text-neutral-600"
                            xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M3 3h18v18H3z" />
                            <path d="M8 8h8v8H8z" />
                        </svg>
                        <div class="sm:order-1 grow space-y-1">
                            <h2 class="sm:mb-2 text-sm text-stone-500 dark:text-neutral-400">
                                {{ $t('services.categories.stats.archived') }}
                            </h2>
                            <p class="text-lg md:text-xl font-semibold text-stone-800 dark:text-neutral-200">
                                {{ Number(stats.archived || 0).toLocaleString() }}
                            </p>
                        </div>
                    </div>
                </div>

                <div
                    class="p-4 sm:p-5 bg-white border border-t-4 border-t-amber-500 border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                    <div class="sm:flex sm:gap-x-3">
                        <svg class="sm:order-2 mb-2 sm:mb-0 shrink-0 size-6 text-stone-400 dark:text-neutral-600"
                            xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M8 6h13" />
                            <path d="M5 6h.01" />
                            <path d="M8 12h13" />
                            <path d="M5 12h.01" />
                            <path d="M8 18h13" />
                            <path d="M5 18h.01" />
                        </svg>
                        <div class="sm:order-1 grow space-y-1">
                            <h2 class="sm:mb-2 text-sm text-stone-500 dark:text-neutral-400">
                                {{ $t('services.categories.stats.in_use') }}
                            </h2>
                            <p class="text-lg md:text-xl font-semibold text-stone-800 dark:text-neutral-200">
                                {{ Number(stats.used || 0).toLocaleString() }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <div
                class="p-5 space-y-4 flex flex-col border-t-4 border-t-emerald-600 bg-white border border-stone-200 shadow-sm rounded-sm dark:bg-neutral-800 dark:border-neutral-700">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-center">
                    <div class="flex-1">
                        <h1 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ $t('services.categories.title') }}</h1>
                        <p class="mt-1 text-xs text-stone-600 dark:text-neutral-400">
                            {{ $t('services.categories.subtitle') }}
                        </p>
                    </div>
                    <div class="flex w-full flex-col gap-2 sm:flex-row lg:w-auto">
                        <div class="flex-1 min-w-[220px]">
                            <FloatingInput v-model="categoryForm.name"
                                :label="editingCategory ? $t('services.categories.form.edit_label') : $t('services.categories.form.new_label')"
                                :required="true" />
                            <InputError class="mt-1" :message="categoryForm.errors.name" />
                            <p v-if="editingCategory" class="mt-1 text-[11px] text-stone-500 dark:text-neutral-400">
                                {{ $t('services.categories.form.editing', { name: editingCategory.name }) }}
                            </p>
                        </div>
                        <button type="button" @click="saveCategory"
                            :disabled="!canSubmitCategory || categoryForm.processing"
                            class="w-full sm:w-auto py-2 px-3 text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50 disabled:pointer-events-none">
                            {{ editingCategory ? $t('services.categories.form.update') : $t('services.categories.form.add') }}
                        </button>
                        <button v-if="editingCategory" type="button" @click="resetCategoryForm"
                            class="w-full sm:w-auto py-2 px-3 text-sm font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-700">
                            {{ $t('services.actions.cancel') }}
                        </button>
                    </div>
                </div>

                <div class="space-y-3">
                    <div class="flex flex-col lg:flex-row lg:items-center gap-2">
                        <div class="flex-1">
                            <div class="relative">
                                <div class="absolute inset-y-0 start-0 flex items-center pointer-events-none z-20 ps-3.5">
                                    <svg class="shrink-0 size-4 text-stone-500 dark:text-neutral-400"
                                        xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                        fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                        stroke-linejoin="round">
                                        <circle cx="11" cy="11" r="8" />
                                        <path d="m21 21-4.3-4.3" />
                                    </svg>
                                </div>
                                <input type="text" v-model="filterForm.search"
                                    class="py-[7px] ps-10 pe-8 block w-full bg-white border border-stone-200 rounded-sm text-sm placeholder:text-stone-500 focus:border-green-600 focus:ring-green-600 disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200 dark:placeholder:text-neutral-400"
                                    :placeholder="$t('services.categories.filters.search_placeholder')">
                            </div>
                        </div>

                        <div class="flex flex-wrap items-center gap-2 justify-end">
                            <button type="button" @click="showAdvanced = !showAdvanced"
                                class="py-2 px-2.5 inline-flex items-center gap-x-1.5 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 focus:outline-none focus:bg-stone-100 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-700">
                                {{ $t('services.actions.filters') }}
                            </button>
                            <button type="button" @click="clearFilters"
                                class="py-2 px-2.5 inline-flex items-center gap-x-1.5 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 focus:outline-none focus:bg-stone-100 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-700">
                                {{ $t('services.actions.clear') }}
                            </button>
                        </div>
                    </div>

                    <div v-if="showAdvanced" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-2">
                        <FloatingSelect
                            v-model="filterForm.status"
                            :label="$t('services.categories.filters.status')"
                            :options="statusFilterOptions"
                            :placeholder="$t('services.categories.filters.status')"
                            dense
                        />
                        <FloatingSelect
                            v-model="filterForm.created_by"
                            :label="$t('services.categories.filters.created_by')"
                            :options="creatorOptions"
                            :placeholder="$t('services.categories.filters.created_by')"
                            dense
                        />
                        <DatePicker v-model="filterForm.created_from" :label="$t('services.categories.filters.created_from')" />
                        <DatePicker v-model="filterForm.created_to" :label="$t('services.categories.filters.created_to')" />
                    </div>
                </div>

                <div
                    class="overflow-x-auto [&::-webkit-scrollbar]:h-2 [&::-webkit-scrollbar-thumb]:rounded-full [&::-webkit-scrollbar-track]:bg-stone-100 [&::-webkit-scrollbar-thumb]:bg-stone-300 dark:[&::-webkit-scrollbar-track]:bg-neutral-700 dark:[&::-webkit-scrollbar-thumb]:bg-neutral-500">
                    <div class="min-w-full inline-block align-middle">
                        <table class="min-w-full divide-y divide-stone-200 dark:divide-neutral-700">
                            <thead>
                                <tr>
                                    <th scope="col" class="min-w-[240px]">
                                        <button type="button" @click="toggleSort('name')"
                                            class="px-5 py-2.5 text-start w-full flex items-center gap-x-1 text-sm font-normal text-stone-500 hover:text-stone-700 focus:outline-none dark:text-neutral-500 dark:hover:text-neutral-300">
                                            {{ $t('services.categories.table.category') }}
                                            <svg v-if="filterForm.sort === 'name'" class="size-3"
                                                xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                                stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                                stroke-linejoin="round"
                                                :class="filterForm.direction === 'asc' ? 'rotate-180' : ''">
                                                <path d="m6 9 6 6 6-6" />
                                            </svg>
                                        </button>
                                    </th>
                                    <th scope="col" class="min-w-[160px]">
                                        <button type="button" @click="toggleSort('items_count')"
                                            class="px-5 py-2.5 text-start w-full flex items-center gap-x-1 text-sm font-normal text-stone-500 hover:text-stone-700 focus:outline-none dark:text-neutral-500 dark:hover:text-neutral-300">
                                            {{ $t('services.categories.table.items') }}
                                            <svg v-if="filterForm.sort === 'items_count'" class="size-3"
                                                xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                                stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                                stroke-linejoin="round"
                                                :class="filterForm.direction === 'asc' ? 'rotate-180' : ''">
                                                <path d="m6 9 6 6 6-6" />
                                            </svg>
                                        </button>
                                    </th>
                                    <th scope="col" class="min-w-[200px]">
                                        <div class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                                            {{ $t('services.categories.table.created_by') }}
                                        </div>
                                    </th>
                                    <th scope="col" class="min-w-[120px]">
                                        <div class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                                            {{ $t('services.categories.table.status') }}
                                        </div>
                                    </th>
                                    <th scope="col" class="min-w-[130px]">
                                        <button type="button" @click="toggleSort('created_at')"
                                            class="px-5 py-2.5 text-start w-full flex items-center gap-x-1 text-sm font-normal text-stone-500 hover:text-stone-700 focus:outline-none dark:text-neutral-500 dark:hover:text-neutral-300">
                                            {{ $t('services.categories.table.created') }}
                                            <svg v-if="filterForm.sort === 'created_at'" class="size-3"
                                                xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                                stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                                stroke-linejoin="round"
                                                :class="filterForm.direction === 'asc' ? 'rotate-180' : ''">
                                                <path d="m6 9 6 6 6-6" />
                                            </svg>
                                        </button>
                                    </th>
                                    <th scope="col" class="min-w-[80px]"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-stone-200 dark:divide-neutral-700">
                                <tr v-for="category in props.categories.data" :key="category.id">
                                    <td class="size-px whitespace-nowrap px-5 py-2">
                                        <div class="flex flex-col">
                                            <span class="text-sm text-stone-700 dark:text-neutral-200">
                                                {{ category.name }}
                                            </span>
                                            <span v-if="!category.user_id" class="text-xs text-stone-400 dark:text-neutral-500">
                                                {{ $t('services.categories.table.system_category') }}
                                            </span>
                                        </div>
                                    </td>
                                    <td class="size-px whitespace-nowrap px-5 py-2">
                                        <div class="flex flex-col">
                                            <span class="text-sm text-stone-700 dark:text-neutral-200">
                                                {{ Number(category.items_count || 0).toLocaleString() }}
                                            </span>
                                            <span class="text-xs text-stone-500 dark:text-neutral-500">
                                                {{ $t('services.categories.table.products') }}: {{ category.products_count || 0 }} /
                                                {{ $t('services.categories.table.services') }}: {{ category.services_count || 0 }}
                                            </span>
                                        </div>
                                    </td>
                                    <td class="size-px whitespace-nowrap px-5 py-2">
                                        <div class="flex flex-col">
                                            <span class="text-sm text-stone-700 dark:text-neutral-200">
                                                {{ creatorName(category) }}
                                            </span>
                                            <span class="text-xs text-stone-500 dark:text-neutral-500">
                                                {{ creatorLabel(category) }}
                                            </span>
                                        </div>
                                    </td>
                                    <td class="size-px whitespace-nowrap px-5 py-2">
                                        <span v-if="!category.archived_at"
                                            class="py-1.5 px-2 inline-flex items-center gap-x-1.5 text-xs font-medium bg-emerald-100 text-emerald-800 rounded-full dark:bg-emerald-500/10 dark:text-emerald-400">
                                            {{ $t('services.status.active') }}
                                        </span>
                                        <span v-else
                                            class="py-1.5 px-2 inline-flex items-center gap-x-1.5 text-xs font-medium bg-stone-200 text-stone-700 rounded-full dark:bg-neutral-700 dark:text-neutral-300">
                                            {{ $t('services.status.archived') }}
                                        </span>
                                    </td>
                                    <td class="size-px whitespace-nowrap px-5 py-2">
                                        <span class="text-xs text-stone-500 dark:text-neutral-500">
                                            {{ formatDate(category.created_at) }}
                                        </span>
                                    </td>
                                    <td class="size-px whitespace-nowrap px-5 py-2 text-end">
                                        <div v-if="canManageCategory(category)" class="hs-dropdown [--auto-close:inside] [--placement:bottom-right] relative inline-flex">
                                            <button type="button"
                                                class="size-7 inline-flex justify-center items-center gap-x-2 rounded-sm border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-700 dark:focus:bg-neutral-700"
                                                aria-haspopup="menu" aria-expanded="false" :aria-label="$t('services.aria.dropdown')">
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
                                                    <button type="button" @click="startEditCategory(category)"
                                                        class="w-full text-start flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800">
                                                        {{ $t('services.actions.edit') }}
                                                    </button>
                                                    <button v-if="!category.archived_at" type="button" @click="archiveCategory(category)"
                                                        class="w-full text-start flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800">
                                                        {{ $t('services.actions.archive') }}
                                                    </button>
                                                    <button v-else type="button" @click="restoreCategory(category)"
                                                        class="w-full text-start flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800">
                                                        {{ $t('services.actions.restore') }}
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                        <span v-else class="text-xs text-stone-400 dark:text-neutral-500">{{ $t('services.categories.table.locked') }}</span>
                                    </td>
                                </tr>

                                <tr v-if="props.categories.data.length === 0">
                                    <td colspan="6" class="px-5 py-10 text-center text-sm text-stone-500 dark:text-neutral-500">
                                        {{ $t('services.categories.empty') }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div v-if="props.categories.data.length > 0" class="mt-5 flex flex-wrap justify-between items-center gap-2">
                    <p class="text-sm text-stone-800 dark:text-neutral-200">
                        <span class="font-medium">{{ count }}</span>
                        <span class="text-stone-500 dark:text-neutral-500"> {{ $t('services.pagination.results') }}</span>
                    </p>

                    <nav class="flex justify-end items-center gap-x-1" :aria-label="$t('services.pagination.label')">
                        <Link :href="props.categories.prev_page_url" v-if="props.categories.prev_page_url">
                        <button type="button"
                            class="min-h-[38px] min-w-[38px] py-2 px-2.5 inline-flex justify-center items-center gap-x-2 text-sm rounded-sm text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 dark:text-white dark:hover:bg-white/10 dark:focus:bg-neutral-700"
                            :aria-label="$t('services.pagination.previous')">
                            <svg class="shrink-0 size-3.5" xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                stroke-linecap="round" stroke-linejoin="round">
                                <path d="m15 18-6-6 6-6" />
                            </svg>
                            <span class="sr-only">{{ $t('services.pagination.previous') }}</span>
                        </button>
                        </Link>

                        <div class="flex items-center gap-x-1">
                            <span
                                class="min-h-[38px] min-w-[38px] flex justify-center items-center bg-stone-100 text-stone-800 py-2 px-3 text-sm rounded-sm disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-700 dark:text-white"
                                aria-current="page">{{ props.categories.from }}</span>
                            <span
                                class="min-h-[38px] flex justify-center items-center text-stone-500 py-2 px-1.5 text-sm dark:text-neutral-500">{{ $t('services.pagination.of') }}</span>
                            <span
                                class="min-h-[38px] flex justify-center items-center text-stone-500 py-2 px-1.5 text-sm dark:text-neutral-500">{{ props.categories.to }}</span>
                        </div>

                        <Link :href="props.categories.next_page_url" v-if="props.categories.next_page_url">
                        <button type="button"
                            class="min-h-[38px] min-w-[38px] py-2 px-2.5 inline-flex justify-center items-center gap-x-2 text-sm rounded-sm text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 dark:text-white dark:hover:bg-white/10 dark:focus:bg-neutral-700"
                            :aria-label="$t('services.pagination.next')">
                            <span class="sr-only">{{ $t('services.pagination.next') }}</span>
                            <svg class="shrink-0 size-3.5" xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                stroke-linecap="round" stroke-linejoin="round">
                                <path d="m9 18 6-6-6-6" />
                            </svg>
                        </button>
                        </Link>
                    </nav>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
