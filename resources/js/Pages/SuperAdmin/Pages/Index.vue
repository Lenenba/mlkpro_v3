<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AdminDataTable from '@/Components/DataTable/AdminDataTable.vue';
import AdminDataTableActions from '@/Components/DataTable/AdminDataTableActions.vue';
import AdminDataTableToolbar from '@/Components/DataTable/AdminDataTableToolbar.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import useDataTableFilters from '@/Composables/useDataTableFilters';

const props = defineProps({
    pages: {
        type: Object,
        default: () => ({ data: [], links: [], total: 0 }),
    },
    filters: { type: Object, default: () => ({ search: '', status: '' }) },
    choices: { type: Object, default: () => ({ statuses: [] }) },
    dashboard_url: { type: String, required: true },
    create_url: { type: String, required: true },
});

const { t } = useI18n();
const showFilters = ref(false);

const filterForm = useForm({
    search: props.filters?.search ?? '',
    status: props.filters?.status ?? '',
});

const formatDate = (value) => {
    if (! value) return t('super_admin.pages.meta.never');
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return value;
    return date.toLocaleString();
};

const pageRows = computed(() => props.pages?.data || []);
const pageLinks = computed(() => props.pages?.links || []);
const pageTotal = computed(() => Number(props.pages?.total || pageRows.value.length || 0));
const pageResultsLabel = computed(() => t('super_admin.pages.filters.results', { count: pageTotal.value }));

const statusOptions = computed(() => [
    ...(props.choices?.statuses || []).map((status) => ({
        value: status.value,
        label: status.value === 'active'
            ? t('super_admin.pages.status.active')
            : t('super_admin.pages.status.draft'),
    })),
]);

const { apply: applyFilters, clear: clearFilters } = useDataTableFilters(
    filterForm,
    route('superadmin.pages.index'),
    {
        only: ['pages', 'filters'],
    }
);

const deletePage = (page) => {
    if (! page?.id) return;
    const ok = window.confirm(t('super_admin.pages.actions.confirm_delete', { slug: page.slug }));
    if (! ok) return;

    router.delete(route('superadmin.pages.destroy', page.id), {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head :title="$t('super_admin.pages.title')" />

    <AuthenticatedLayout>
        <div class="space-y-5">
            <section class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="space-y-1">
                        <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">
                            {{ $t('super_admin.pages.title') }}
                        </h1>
                        <p class="text-sm text-stone-600 dark:text-neutral-400">
                            {{ $t('super_admin.pages.subtitle') }}
                        </p>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <Link
                            :href="dashboard_url"
                            class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                        >
                            {{ $t('super_admin.pages.actions.back_dashboard') }}
                        </Link>
                        <Link
                            :href="create_url"
                            class="rounded-sm border border-transparent bg-green-600 px-4 py-2 text-sm font-semibold text-white hover:bg-green-700"
                        >
                            {{ $t('super_admin.pages.actions.create') }}
                        </Link>
                    </div>
                </div>
            </section>

            <AdminDataTable
                :rows="pageRows"
                :links="pageLinks"
                :total="pageTotal"
                :result-label="pageResultsLabel"
                :empty-description="$t('super_admin.pages.empty')"
                container-class="border-t-4 border-t-zinc-600"
            >
                <template #toolbar>
                    <AdminDataTableToolbar
                        :show-filters="showFilters"
                        :search-placeholder="$t('super_admin.pages.filters.search_placeholder')"
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
                                    :label="$t('super_admin.pages.filters.status')"
                                    :options="statusOptions"
                                    :placeholder="$t('super_admin.pages.filters.all_statuses')"
                                />
                            </div>
                        </template>
                    </AdminDataTableToolbar>
                </template>

                <template #head>
                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-stone-600 dark:text-neutral-300">
                        <th class="px-4 py-3">{{ $t('super_admin.pages.table.slug') }}</th>
                        <th class="px-4 py-3">{{ $t('super_admin.pages.table.title') }}</th>
                        <th class="px-4 py-3">{{ $t('super_admin.pages.table.status') }}</th>
                        <th class="px-4 py-3">{{ $t('super_admin.pages.table.updated') }}</th>
                        <th class="px-4 py-3 text-right"></th>
                    </tr>
                </template>

                <template #row="{ row: page }">
                    <tr class="align-top">
                        <td class="px-4 py-3 font-mono text-xs text-stone-700 dark:text-neutral-200">
                            {{ page.path_label || `/pages/${page.slug}` }}
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex flex-wrap items-center gap-2">
                                <div class="font-medium text-stone-800 dark:text-neutral-100">{{ page.title }}</div>
                                <span
                                    v-if="page.is_welcome"
                                    class="inline-flex items-center rounded-full bg-stone-100 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-stone-700 dark:bg-neutral-800 dark:text-neutral-200"
                                >
                                    Home
                                </span>
                            </div>
                            <div v-if="page.updated_by" class="text-xs text-stone-500 dark:text-neutral-400">
                                {{ page.updated_by.name || page.updated_by.email }}
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <span
                                class="inline-flex items-center rounded-full px-2 py-0.5 text-xs"
                                :class="page.is_active
                                    ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200'
                                    : 'bg-stone-200 text-stone-700 dark:bg-neutral-700 dark:text-neutral-200'"
                            >
                                {{ page.is_active ? $t('super_admin.pages.status.active') : $t('super_admin.pages.status.draft') }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-xs text-stone-600 dark:text-neutral-300">
                            {{ formatDate(page.updated_at) }}
                        </td>
                        <td class="px-4 py-3 text-right">
                            <AdminDataTableActions :label="$t('super_admin.common.actions')">
                                <a
                                    v-if="page.public_url"
                                    :href="page.public_url"
                                    target="_blank"
                                    rel="noopener"
                                    class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800"
                                >
                                    {{ $t('super_admin.pages.actions.view') }}
                                </a>
                                <Link
                                    :href="route('superadmin.pages.edit', page.id)"
                                    class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800"
                                >
                                    {{ $t('super_admin.pages.actions.edit') }}
                                </Link>
                                <button
                                    v-if="!page.is_welcome"
                                    type="button"
                                    class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-red-700 hover:bg-red-50 dark:text-red-300 dark:hover:bg-red-500/10"
                                    @click="deletePage(page)"
                                >
                                    {{ $t('super_admin.pages.actions.delete') }}
                                </button>
                            </AdminDataTableActions>
                        </td>
                    </tr>
                </template>
            </AdminDataTable>
        </div>
    </AuthenticatedLayout>
</template>
