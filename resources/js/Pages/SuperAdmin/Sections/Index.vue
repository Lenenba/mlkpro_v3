<script setup>
import { computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AdminDataTable from '@/Components/DataTable/AdminDataTable.vue';
import AdminDataTableActions from '@/Components/DataTable/AdminDataTableActions.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    sections: { type: Array, default: () => [] },
    dashboard_url: { type: String, required: true },
    create_url: { type: String, required: true },
});

const { t } = useI18n();

const formatDate = (value) => {
    if (!value) return t('super_admin.sections.meta.never');
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return value;
    return date.toLocaleString();
};

const sortedSections = computed(() =>
    [...props.sections].sort((a, b) => String(a.name || '').localeCompare(String(b.name || '')))
);
const sectionsTotal = computed(() => sortedSections.value.length);
const sectionsResultsLabel = computed(() => t('super_admin.sections.filters.results', { count: sectionsTotal.value }));

const deleteSection = (section) => {
    if (!section?.id) return;
    const ok = window.confirm(t('super_admin.sections.actions.confirm_delete', { name: section.name }));
    if (!ok) return;

    router.delete(route('superadmin.sections.destroy', section.id), {
        preserveScroll: true,
    });
};

const duplicateSection = (section) => {
    if (!section?.id) return;

    router.post(route('superadmin.sections.duplicate', section.id), {}, {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head :title="$t('super_admin.sections.title')" />

    <AuthenticatedLayout>
        <div class="space-y-5">
            <section class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="space-y-1">
                        <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">
                            {{ $t('super_admin.sections.title') }}
                        </h1>
                        <p class="text-sm text-stone-600 dark:text-neutral-400">
                            {{ $t('super_admin.sections.subtitle') }}
                        </p>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <Link :href="dashboard_url"
                            class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800">
                            {{ $t('super_admin.sections.actions.back_dashboard') }}
                        </Link>
                        <Link :href="create_url"
                            class="rounded-sm border border-transparent bg-green-600 px-4 py-2 text-sm font-semibold text-white hover:bg-green-700">
                            {{ $t('super_admin.sections.actions.create') }}
                        </Link>
                    </div>
                </div>
            </section>

            <AdminDataTable
                :rows="sortedSections"
                :total="sectionsTotal"
                :result-label="sectionsResultsLabel"
                :empty-description="$t('super_admin.sections.empty')"
                container-class="border-t-4 border-t-zinc-600"
            >
                <template #head>
                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-stone-600 dark:text-neutral-300">
                        <th class="px-3 py-2">{{ $t('super_admin.sections.table.name') }}</th>
                        <th class="px-3 py-2">{{ $t('super_admin.sections.table.type') }}</th>
                        <th class="px-3 py-2">{{ $t('super_admin.sections.table.status') }}</th>
                        <th class="px-3 py-2">{{ $t('super_admin.sections.table.updated') }}</th>
                        <th class="px-3 py-2 text-right">{{ $t('super_admin.sections.table.actions') }}</th>
                    </tr>
                </template>

                <template #row="{ row: section }">
                    <tr class="align-top">
                        <td class="px-3 py-3">
                            <div class="font-semibold text-stone-800 dark:text-neutral-100">{{ section.name }}</div>
                            <div v-if="section.updated_by" class="text-xs text-stone-500 dark:text-neutral-400">
                                {{ section.updated_by.name || section.updated_by.email }}
                            </div>
                        </td>
                        <td class="px-3 py-3 text-xs text-stone-600 dark:text-neutral-300">
                            {{ $t(`super_admin.sections.types.${section.type}`) }}
                        </td>
                        <td class="px-3 py-3">
                            <span
                                class="inline-flex rounded-full px-2 py-1 text-xs font-semibold"
                                :class="section.is_active
                                    ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200'
                                    : 'bg-stone-200 text-stone-700 dark:bg-neutral-700 dark:text-neutral-200'"
                            >
                                {{ section.is_active ? $t('super_admin.sections.status.active') : $t('super_admin.sections.status.draft') }}
                            </span>
                        </td>
                        <td class="px-3 py-3 text-xs text-stone-600 dark:text-neutral-300">
                            {{ formatDate(section.updated_at) }}
                        </td>
                        <td class="px-3 py-3 text-right">
                            <AdminDataTableActions :label="$t('super_admin.sections.table.actions')">
                                <Link
                                    :href="route('superadmin.sections.edit', section.id)"
                                    class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800"
                                >
                                    {{ $t('super_admin.sections.actions.edit') }}
                                </Link>
                                <button
                                    type="button"
                                    class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800"
                                    @click="duplicateSection(section)"
                                >
                                    {{ $t('super_admin.sections.actions.duplicate') }}
                                </button>
                                <button
                                    type="button"
                                    class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-red-700 hover:bg-red-50 dark:text-red-300 dark:hover:bg-red-500/10"
                                    @click="deleteSection(section)"
                                >
                                    {{ $t('super_admin.sections.actions.delete') }}
                                </button>
                            </AdminDataTableActions>
                        </td>
                    </tr>
                </template>
            </AdminDataTable>
        </div>
    </AuthenticatedLayout>
</template>
