<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import draggable from 'vuedraggable';
import { useI18n } from 'vue-i18n';
import AdminDataTable from '@/Components/DataTable/AdminDataTable.vue';
import AdminDataTableActions from '@/Components/DataTable/AdminDataTableActions.vue';
import AdminDataTableToolbar from '@/Components/DataTable/AdminDataTableToolbar.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    menus: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({ search: '', status: '', location: '' }) },
    choices: { type: Object, default: () => ({ statuses: [], display_locations: [] }) },
    dashboard_url: { type: String, required: true },
    create_url: { type: String, required: true },
    reorder_url: { type: String, required: true },
});

const { t } = useI18n();

const filterForm = reactive({
    search: props.filters?.search || '',
    status: props.filters?.status || '',
    location: props.filters?.location || '',
});

const orderedMenus = ref(
    [...props.menus].sort((left, right) => Number(left.ordering || 0) - Number(right.ordering || 0))
);
const orderDirty = ref(false);
const showFilters = ref(false);

const tx = (key, params = {}) => t(`mega_menu.admin.${key}`, params);
const headTitle = computed(() => tx('index.head_title'));
const menuResultsLabel = computed(() => tx('index.results', { count: orderedMenus.value.length }));

const translateChoiceLabel = (prefix, option) => {
    const value = String(option?.value ?? '').trim();
    if (!value) {
        return option?.label || '';
    }

    const translationKey = `mega_menu.admin.options.${prefix}.${value.replace(/^_+/, '')}`;
    const translated = t(translationKey);

    return translated === translationKey ? (option?.label || value) : translated;
};

watch(
    () => props.menus,
    (menus) => {
        orderedMenus.value = [...menus].sort((left, right) => Number(left.ordering || 0) - Number(right.ordering || 0));
        orderDirty.value = false;
    },
    { deep: true }
);

const formatDate = (value) => {
    if (!value) return tx('common.never');
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? value : date.toLocaleString();
};

const pillClass = (status) => {
    if (status === 'active') {
        return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300';
    }
    if (status === 'draft') {
        return 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300';
    }
    return 'bg-stone-200 text-stone-700 dark:bg-neutral-700 dark:text-neutral-200';
};

const applyFilters = () => {
    showFilters.value = false;
    router.get(route('superadmin.mega-menus.index'), filterForm, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
};

const clearFilters = () => {
    filterForm.search = '';
    filterForm.status = '';
    filterForm.location = '';
    applyFilters();
};

const persistOrder = () => {
    router.post(props.reorder_url, {
        ids: orderedMenus.value.map((menu) => menu.id),
    }, {
        preserveScroll: true,
        onSuccess: () => {
            orderDirty.value = false;
        },
    });
};

const confirmDelete = (menu) => {
    if (!menu?.id) return;
    if (!window.confirm(tx('edit.delete_menu_confirm', { title: menu.title }))) return;

    router.delete(route('superadmin.mega-menus.destroy', menu.id), {
        preserveScroll: true,
    });
};

const duplicateMenu = (menu) => {
    router.post(route('superadmin.mega-menus.duplicate', menu.id), {}, { preserveScroll: true });
};

const activateMenu = (menu) => {
    router.post(route('superadmin.mega-menus.activate', menu.id), {}, { preserveScroll: true });
};

const deactivateMenu = (menu) => {
    router.post(route('superadmin.mega-menus.deactivate', menu.id), {}, { preserveScroll: true });
};

const statusOptions = computed(() => [
    { value: '', label: tx('options.statuses.all') },
    ...(props.choices?.statuses || []).map((option) => ({
        ...option,
        label: translateChoiceLabel('statuses', option),
    })),
]);

const locationOptions = computed(() => [
    { value: '', label: tx('options.locations.all') },
    ...(props.choices?.display_locations || []).map((option) => ({
        ...option,
        label: translateChoiceLabel('locations', option),
    })),
]);
</script>

<template>
    <Head :title="headTitle" />

    <AuthenticatedLayout>
        <div class="space-y-5">
            <section class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="space-y-1">
                        <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">
                            {{ tx('index.title') }}
                        </h1>
                        <p class="text-sm text-stone-600 dark:text-neutral-400">
                            {{ tx('index.description') }}
                        </p>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <Link :href="dashboard_url"
                            class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800">
                            {{ tx('index.back_to_dashboard') }}
                        </Link>
                        <Link :href="create_url"
                            class="rounded-sm border border-transparent bg-green-600 px-4 py-2 text-sm font-semibold text-white hover:bg-green-700">
                            {{ tx('index.create') }}
                        </Link>
                    </div>
                </div>
            </section>

            <AdminDataTable
                :rows="orderedMenus"
                :result-label="menuResultsLabel"
                :empty-description="tx('index.empty')"
                container-class="border-t-4 border-t-zinc-600"
            >
                <template #toolbar>
                    <div class="space-y-3">
                        <AdminDataTableToolbar
                            :show-filters="showFilters"
                            :search-placeholder="tx('index.search_placeholder')"
                            :filters-label="tx('index.filters')"
                            :clear-label="tx('index.clear')"
                            :apply-label="tx('index.apply')"
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
                                    <label class="block text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                        {{ tx('index.status') }}
                                    </label>
                                    <select
                                        v-model="filterForm.status"
                                        class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                                    >
                                        <option v-for="option in statusOptions" :key="option.value || 'all-statuses'" :value="option.value">
                                            {{ option.label }}
                                        </option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                        {{ tx('index.location') }}
                                    </label>
                                    <select
                                        v-model="filterForm.location"
                                        class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                                    >
                                        <option v-for="option in locationOptions" :key="option.value || 'all-locations'" :value="option.value">
                                            {{ option.label }}
                                        </option>
                                    </select>
                                </div>
                            </template>

                            <template #actions>
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-x-2 rounded-sm border px-3 py-2 text-xs font-semibold"
                                    :class="orderDirty
                                        ? 'border-green-600 bg-green-600 text-white hover:bg-green-700'
                                        : 'border-stone-200 bg-white text-stone-400 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-500'"
                                    :disabled="!orderDirty"
                                    @click="persistOrder"
                                >
                                    {{ tx('index.save_order') }}
                                </button>
                            </template>
                        </AdminDataTableToolbar>

                        <div class="text-sm text-stone-500 dark:text-neutral-400">
                            {{ tx('index.reorder_hint') }}
                        </div>
                    </div>
                </template>

                <template #head>
                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-stone-600 dark:text-neutral-300">
                        <th class="px-3 py-2">{{ tx('index.order') }}</th>
                        <th class="px-3 py-2">{{ tx('index.menu') }}</th>
                        <th class="px-3 py-2">{{ tx('index.status') }}</th>
                        <th class="px-3 py-2">{{ tx('index.location') }}</th>
                        <th class="px-3 py-2">{{ tx('index.structure') }}</th>
                        <th class="px-3 py-2">{{ tx('index.updated') }}</th>
                        <th class="px-3 py-2 text-right">{{ tx('index.actions') }}</th>
                    </tr>
                </template>

                <template #body>
                    <draggable
                        v-model="orderedMenus"
                        tag="tbody"
                        item-key="id"
                        handle=".mega-menu-sort-handle"
                        class="divide-y divide-stone-100 dark:divide-neutral-800"
                        @end="orderDirty = true"
                    >
                        <template #item="{ element: menu, index }">
                            <tr class="align-top">
                                <td class="px-3 py-3">
                                    <button type="button" class="mega-menu-sort-handle cursor-grab rounded-sm border border-stone-200 px-2 py-1 text-xs font-semibold text-stone-600 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-800">
                                        #{{ index + 1 }}
                                    </button>
                                </td>
                                <td class="px-3 py-3">
                                    <div class="font-semibold text-stone-800 dark:text-neutral-100">{{ menu.title }}</div>
                                    <div class="mt-1 font-mono text-[11px] text-stone-500 dark:text-neutral-400">{{ menu.slug }}</div>
                                    <div v-if="menu.description" class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                        {{ menu.description }}
                                    </div>
                                </td>
                                <td class="px-3 py-3">
                                    <span class="inline-flex rounded-full px-2 py-1 text-xs font-semibold" :class="pillClass(menu.status)">
                                        {{ menu.status }}
                                    </span>
                                </td>
                                <td class="px-3 py-3 text-xs text-stone-600 dark:text-neutral-300">
                                    <div class="font-semibold">{{ menu.display_location }}</div>
                                    <div v-if="menu.custom_zone">{{ menu.custom_zone }}</div>
                                </td>
                                <td class="px-3 py-3 text-xs text-stone-600 dark:text-neutral-300">
                                    <div>{{ tx('index.top_level_items', { count: menu.top_level_items }) }}</div>
                                    <div>{{ tx('index.content_blocks', { count: menu.block_count }) }}</div>
                                </td>
                                <td class="px-3 py-3 text-xs text-stone-600 dark:text-neutral-300">
                                    <div>{{ formatDate(menu.updated_at) }}</div>
                                    <div v-if="menu.updated_by">{{ menu.updated_by.name || menu.updated_by.email }}</div>
                                </td>
                                <td class="px-3 py-3 text-right">
                                    <AdminDataTableActions :label="tx('index.actions')">
                                        <Link
                                            :href="route('superadmin.mega-menus.preview', menu.id)"
                                            class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800"
                                        >
                                            {{ tx('common.preview') }}
                                        </Link>
                                        <Link
                                            :href="route('superadmin.mega-menus.edit', menu.id)"
                                            class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800"
                                        >
                                            {{ tx('common.edit') }}
                                        </Link>
                                        <button
                                            type="button"
                                            class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800"
                                            @click="duplicateMenu(menu)"
                                        >
                                            {{ tx('common.duplicate') }}
                                        </button>
                                        <button
                                            v-if="menu.status !== 'active'"
                                            type="button"
                                            class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-emerald-700 hover:bg-emerald-50 dark:text-emerald-300 dark:hover:bg-emerald-500/10"
                                            @click="activateMenu(menu)"
                                        >
                                            {{ tx('common.activate') }}
                                        </button>
                                        <button
                                            v-else
                                            type="button"
                                            class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-amber-700 hover:bg-amber-50 dark:text-amber-300 dark:hover:bg-amber-500/10"
                                            @click="deactivateMenu(menu)"
                                        >
                                            {{ tx('common.deactivate') }}
                                        </button>
                                        <button
                                            type="button"
                                            class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-red-700 hover:bg-red-50 dark:text-red-300 dark:hover:bg-red-500/10"
                                            @click="confirmDelete(menu)"
                                        >
                                            {{ tx('common.delete') }}
                                        </button>
                                    </AdminDataTableActions>
                                </td>
                            </tr>
                        </template>
                    </draggable>
                </template>
            </AdminDataTable>
        </div>
    </AuthenticatedLayout>
</template>
