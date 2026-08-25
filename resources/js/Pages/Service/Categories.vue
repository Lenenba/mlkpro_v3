<script setup>
import { computed, ref, watch } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import KpiMetricGrid from '@/Components/Dashboard/KpiMetricGrid.vue';
import AdminDataTable from '@/Components/DataTable/AdminDataTable.vue';
import AdminDataTableActions from '@/Components/DataTable/AdminDataTableActions.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import DatePicker from '@/Components/DatePicker.vue';
import InputError from '@/Components/InputError.vue';
import { humanizeDate } from '@/utils/date';
import { resolveDataTablePerPage } from '@/Components/DataTable/pagination';
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

const categoryRows = computed(() => (Array.isArray(props.categories?.data) ? props.categories.data : []));
const categoryLinks = computed(() => props.categories?.links || []);
const currentPerPage = computed(() => resolveDataTablePerPage(props.categories?.per_page, props.filters?.per_page));
const categoryResultsLabel = computed(() => `${props.count} ${t('services.pagination.results')}`);
const categoryKpis = computed(() => ([
    {
        key: 'total',
        label: t('services.categories.stats.total'),
        value: Number(props.stats?.total || 0).toLocaleString(),
        tone: 'emerald',
    },
    {
        key: 'active',
        label: t('services.categories.stats.active'),
        value: Number(props.stats?.active || 0).toLocaleString(),
        tone: 'sky',
    },
    {
        key: 'archived',
        label: t('services.categories.stats.archived'),
        value: Number(props.stats?.archived || 0).toLocaleString(),
        tone: 'stone',
    },
    {
        key: 'in-use',
        label: t('services.categories.stats.in_use'),
        value: Number(props.stats?.used || 0).toLocaleString(),
        tone: 'amber',
    },
]));
</script>

<template>
    <Head :title="$t('services.categories.title')" />

    <AuthenticatedLayout>
        <div class="space-y-5">
            <KpiMetricGrid
                :metrics="categoryKpis"
                grid-class="grid-cols-2 md:grid-cols-4"
            />

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

                <AdminDataTable
                    embedded
                    :rows="categoryRows"
                    :links="categoryLinks"
                    :show-pagination="categoryRows.length > 0"
                    show-per-page
                    :per-page="currentPerPage"
                >
                    <template #empty>
                        <div class="px-5 py-10 text-center text-sm text-stone-500 dark:text-neutral-500">
                            {{ $t('services.categories.empty') }}
                        </div>
                    </template>

                    <template #head>
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
                    </template>

                    <template #body="{ rows }">
                        <tbody class="divide-y divide-stone-200 dark:divide-neutral-700">
                            <tr v-for="category in rows" :key="category.id">
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
                                    <AdminDataTableActions
                                        v-if="canManageCategory(category)"
                                        :label="$t('services.aria.dropdown')"
                                        menu-width-class="w-32"
                                    >
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
                                    </AdminDataTableActions>
                                    <span v-else class="text-xs text-stone-400 dark:text-neutral-500">{{ $t('services.categories.table.locked') }}</span>
                                </td>
                            </tr>
                        </tbody>
                    </template>

                    <template #pagination_prefix>
                        <p class="text-sm text-stone-800 dark:text-neutral-200">
                            {{ categoryResultsLabel }}
                        </p>
                    </template>
                </AdminDataTable>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
