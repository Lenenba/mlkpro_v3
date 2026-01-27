<script setup>
import { computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
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

const deleteSection = (section) => {
    if (!section?.id) return;
    const ok = window.confirm(t('super_admin.sections.actions.confirm_delete', { name: section.name }));
    if (!ok) return;

    router.delete(route('superadmin.sections.destroy', section.id), {
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

            <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div v-if="!sortedSections.length"
                    class="rounded-sm border border-dashed border-stone-300 p-6 text-sm text-stone-600 dark:border-neutral-600 dark:text-neutral-300">
                    {{ $t('super_admin.sections.empty') }}
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-stone-200 text-sm dark:divide-neutral-700">
                        <thead class="bg-stone-50 dark:bg-neutral-800/60">
                            <tr class="text-left text-xs font-semibold uppercase tracking-wide text-stone-600 dark:text-neutral-300">
                                <th class="px-3 py-2">{{ $t('super_admin.sections.table.name') }}</th>
                                <th class="px-3 py-2">{{ $t('super_admin.sections.table.type') }}</th>
                                <th class="px-3 py-2">{{ $t('super_admin.sections.table.status') }}</th>
                                <th class="px-3 py-2">{{ $t('super_admin.sections.table.updated') }}</th>
                                <th class="px-3 py-2 text-right">{{ $t('super_admin.sections.table.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-100 dark:divide-neutral-800">
                            <tr v-for="section in sortedSections" :key="section.id" class="align-top">
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
                                    <span class="inline-flex rounded-full px-2 py-1 text-xs font-semibold"
                                        :class="section.is_active
                                            ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200'
                                            : 'bg-stone-200 text-stone-700 dark:bg-neutral-700 dark:text-neutral-200'">
                                        {{ section.is_active ? $t('super_admin.sections.status.active') : $t('super_admin.sections.status.draft') }}
                                    </span>
                                </td>
                                <td class="px-3 py-3 text-xs text-stone-600 dark:text-neutral-300">
                                    {{ formatDate(section.updated_at) }}
                                </td>
                                <td class="px-3 py-3">
                                    <div class="flex flex-wrap justify-end gap-2 text-xs">
                                        <Link :href="route('superadmin.sections.edit', section.id)"
                                            class="rounded-sm border border-stone-200 px-2 py-1 font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800">
                                            {{ $t('super_admin.sections.actions.edit') }}
                                        </Link>
                                        <button type="button" @click="deleteSection(section)"
                                            class="rounded-sm border border-red-200 px-2 py-1 font-semibold text-red-700 hover:bg-red-50">
                                            {{ $t('super_admin.sections.actions.delete') }}
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>

