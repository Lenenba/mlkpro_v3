<script setup>
import { computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    pages: { type: Array, default: () => [] },
    dashboard_url: { type: String, required: true },
    create_url: { type: String, required: true },
});

const { t } = useI18n();

const formatDate = (value) => {
    if (!value) return t('super_admin.pages.meta.never');
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return value;
    return date.toLocaleString();
};

const sortedPages = computed(() =>
    [...props.pages].sort((a, b) => String(a.slug || '').localeCompare(String(b.slug || '')))
);

const deletePage = (page) => {
    if (!page?.id) return;
    const ok = window.confirm(t('super_admin.pages.actions.confirm_delete', { slug: page.slug }));
    if (!ok) return;

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
                        <Link :href="dashboard_url"
                            class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800">
                            {{ $t('super_admin.pages.actions.back_dashboard') }}
                        </Link>
                        <Link :href="create_url"
                            class="rounded-sm border border-transparent bg-green-600 px-4 py-2 text-sm font-semibold text-white hover:bg-green-700">
                            {{ $t('super_admin.pages.actions.create') }}
                        </Link>
                    </div>
                </div>
            </section>

            <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div v-if="!sortedPages.length"
                    class="rounded-sm border border-dashed border-stone-300 p-6 text-sm text-stone-600 dark:border-neutral-600 dark:text-neutral-300">
                    {{ $t('super_admin.pages.empty') }}
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-stone-200 text-sm dark:divide-neutral-700">
                        <thead class="bg-stone-50 dark:bg-neutral-800/60">
                            <tr class="text-left text-xs font-semibold uppercase tracking-wide text-stone-600 dark:text-neutral-300">
                                <th class="px-3 py-2">{{ $t('super_admin.pages.table.slug') }}</th>
                                <th class="px-3 py-2">{{ $t('super_admin.pages.table.title') }}</th>
                                <th class="px-3 py-2">{{ $t('super_admin.pages.table.status') }}</th>
                                <th class="px-3 py-2">{{ $t('super_admin.pages.table.updated') }}</th>
                                <th class="px-3 py-2 text-right">{{ $t('super_admin.pages.table.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-100 dark:divide-neutral-800">
                            <tr v-for="page in sortedPages" :key="page.id" class="align-top">
                                <td class="px-3 py-3 font-mono text-xs text-stone-700 dark:text-neutral-200">
                                    /pages/{{ page.slug }}
                                </td>
                                <td class="px-3 py-3">
                                    <div class="font-semibold text-stone-800 dark:text-neutral-100">{{ page.title }}</div>
                                    <div v-if="page.updated_by" class="text-xs text-stone-500 dark:text-neutral-400">
                                        {{ page.updated_by.name || page.updated_by.email }}
                                    </div>
                                </td>
                                <td class="px-3 py-3">
                                    <span class="inline-flex rounded-full px-2 py-1 text-xs font-semibold"
                                        :class="page.is_active
                                            ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200'
                                            : 'bg-stone-200 text-stone-700 dark:bg-neutral-700 dark:text-neutral-200'">
                                        {{ page.is_active ? $t('super_admin.pages.status.active') : $t('super_admin.pages.status.draft') }}
                                    </span>
                                </td>
                                <td class="px-3 py-3 text-xs text-stone-600 dark:text-neutral-300">
                                    {{ formatDate(page.updated_at) }}
                                </td>
                                <td class="px-3 py-3">
                                    <div class="flex flex-wrap justify-end gap-2 text-xs">
                                        <a v-if="page.public_url" :href="page.public_url" target="_blank" rel="noopener"
                                            class="rounded-sm border border-stone-200 px-2 py-1 font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800">
                                            {{ $t('super_admin.pages.actions.view') }}
                                        </a>
                                        <Link :href="route('superadmin.pages.edit', page.id)"
                                            class="rounded-sm border border-stone-200 px-2 py-1 font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800">
                                            {{ $t('super_admin.pages.actions.edit') }}
                                        </Link>
                                        <button type="button" @click="deletePage(page)"
                                            class="rounded-sm border border-red-200 px-2 py-1 font-semibold text-red-700 hover:bg-red-50">
                                            {{ $t('super_admin.pages.actions.delete') }}
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

