<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';

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
    if (!value) return t('super_admin.pages.meta.never');
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return value;
    return date.toLocaleString();
};

const pageRows = computed(() => props.pages?.data || []);
const pageLinks = computed(() => props.pages?.links || []);
const pageTotal = computed(() => Number(props.pages?.total || pageRows.value.length || 0));

const statusOptions = computed(() => [
    ...(props.choices?.statuses || []).map((status) => ({
        value: status.value,
        label: status.value === 'active'
            ? t('super_admin.pages.status.active')
            : t('super_admin.pages.status.draft'),
    })),
]);

const applyFilters = () => {
    filterForm.get(route('superadmin.pages.index'), {
        only: ['pages', 'filters'],
        preserveState: true,
        preserveScroll: true,
    });
};

const clearFilters = () => {
    filterForm.reset();
    filterForm.get(route('superadmin.pages.index'), {
        only: ['pages', 'filters'],
        preserveScroll: true,
    });
};

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

            <section class="flex flex-col space-y-4 rounded-sm border border-stone-200 border-t-4 border-t-zinc-600 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <form class="space-y-3" @submit.prevent="applyFilters">
                    <div class="flex flex-col gap-2 lg:flex-row lg:items-center">
                        <div class="flex-1">
                            <div class="relative">
                                <div class="pointer-events-none absolute inset-y-0 start-0 z-20 flex items-center ps-3.5">
                                    <svg class="size-4 shrink-0 text-stone-500 dark:text-neutral-400"
                                        xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                                        stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <circle cx="11" cy="11" r="8" />
                                        <path d="m21 21-4.3-4.3" />
                                    </svg>
                                </div>
                                <input
                                    v-model="filterForm.search"
                                    type="text"
                                    :placeholder="$t('super_admin.pages.filters.search_placeholder')"
                                    class="block w-full rounded-sm border border-stone-200 bg-white py-[7px] ps-10 pe-8 text-sm text-stone-700 placeholder:text-stone-500 focus:border-green-500 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:placeholder:text-neutral-400"
                                >
                            </div>
                        </div>
                        <div class="flex flex-wrap items-center justify-end gap-2">
                            <button
                                type="button"
                                class="inline-flex items-center gap-x-1.5 rounded-sm border border-stone-200 bg-white px-2.5 py-2 text-xs font-medium text-stone-700 hover:bg-stone-50 focus:outline-none focus:ring-2 focus:ring-green-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200 dark:hover:bg-neutral-700"
                                @click="showFilters = !showFilters"
                            >
                                {{ $t('super_admin.common.filters') }}
                            </button>
                            <button
                                type="button"
                                class="inline-flex items-center gap-x-1.5 rounded-sm border border-stone-200 bg-white px-2.5 py-2 text-xs font-medium text-stone-700 hover:bg-stone-50 focus:outline-none focus:ring-2 focus:ring-green-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200 dark:hover:bg-neutral-700"
                                @click="clearFilters"
                            >
                                {{ $t('super_admin.common.clear') }}
                            </button>
                            <button
                                type="submit"
                                class="inline-flex items-center gap-x-2 rounded-sm border border-transparent bg-green-600 px-3 py-2 text-xs font-medium text-white hover:bg-green-700"
                            >
                                {{ $t('super_admin.common.apply_filters') }}
                            </button>
                        </div>
                    </div>

                    <div v-if="showFilters" class="grid gap-3 md:grid-cols-3">
                        <div>
                            <FloatingSelect
                                v-model="filterForm.status"
                                :label="$t('super_admin.pages.filters.status')"
                                :options="statusOptions"
                                :placeholder="$t('super_admin.pages.filters.all_statuses')"
                            />
                        </div>
                    </div>
                </form>

                <div class="text-sm text-stone-500 dark:text-neutral-400">
                    {{ $t('super_admin.pages.filters.results', { count: pageTotal }) }}
                </div>

                <div v-if="!pageRows.length"
                    class="rounded-sm border border-dashed border-stone-300 p-6 text-sm text-stone-600 dark:border-neutral-600 dark:text-neutral-300">
                    {{ $t('super_admin.pages.empty') }}
                </div>

                <div v-else
                    class="overflow-x-auto [&::-webkit-scrollbar]:h-2 [&::-webkit-scrollbar-thumb]:rounded-full [&::-webkit-scrollbar-track]:bg-stone-100 [&::-webkit-scrollbar-thumb]:bg-stone-300 dark:[&::-webkit-scrollbar-track]:bg-neutral-700 dark:[&::-webkit-scrollbar-thumb]:bg-neutral-500">
                    <table class="min-w-full divide-y divide-stone-200 text-left text-sm text-stone-600 dark:divide-neutral-700 dark:text-neutral-300">
                        <thead class="text-xs uppercase text-stone-500 dark:text-neutral-400">
                            <tr>
                                <th class="px-4 py-3">{{ $t('super_admin.pages.table.slug') }}</th>
                                <th class="px-4 py-3">{{ $t('super_admin.pages.table.title') }}</th>
                                <th class="px-4 py-3">{{ $t('super_admin.pages.table.status') }}</th>
                                <th class="px-4 py-3">{{ $t('super_admin.pages.table.updated') }}</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-200 dark:divide-neutral-700">
                            <tr v-for="page in pageRows" :key="page.id" class="align-top">
                                <td class="px-4 py-3 font-mono text-xs text-stone-700 dark:text-neutral-200">
                                    /pages/{{ page.slug }}
                                </td>
                                <td class="px-4 py-3">
                                    <div class="font-medium text-stone-800 dark:text-neutral-100">{{ page.title }}</div>
                                    <div v-if="page.updated_by" class="text-xs text-stone-500 dark:text-neutral-400">
                                        {{ page.updated_by.name || page.updated_by.email }}
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs"
                                        :class="page.is_active
                                            ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200'
                                            : 'bg-stone-200 text-stone-700 dark:bg-neutral-700 dark:text-neutral-200'">
                                        {{ page.is_active ? $t('super_admin.pages.status.active') : $t('super_admin.pages.status.draft') }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-xs text-stone-600 dark:text-neutral-300">
                                    {{ formatDate(page.updated_at) }}
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <div class="hs-dropdown [--auto-close:inside] [--placement:bottom-right] relative inline-flex">
                                        <button type="button"
                                            class="inline-flex size-7 items-center justify-center gap-x-2 rounded-sm border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 focus:bg-stone-50 focus:outline-none disabled:pointer-events-none disabled:opacity-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300 dark:hover:bg-neutral-700 dark:focus:bg-neutral-700"
                                            aria-haspopup="menu" aria-expanded="false" :aria-label="$t('super_admin.common.actions')">
                                            <svg class="size-3.5 shrink-0" xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                                stroke-linecap="round" stroke-linejoin="round">
                                                <circle cx="12" cy="12" r="1" />
                                                <circle cx="12" cy="5" r="1" />
                                                <circle cx="12" cy="19" r="1" />
                                            </svg>
                                        </button>
                                        <div
                                            class="hs-dropdown-menu hs-dropdown-open:opacity-100 hidden z-10 w-36 rounded-sm bg-white opacity-0 shadow-[0_10px_40px_10px_rgba(0,0,0,0.08)] transition-[opacity,margin] duration dark:bg-neutral-900 dark:shadow-[0_10px_40px_10px_rgba(0,0,0,0.2)]"
                                            role="menu" aria-orientation="vertical">
                                            <div class="p-1">
                                                <a v-if="page.public_url" :href="page.public_url" target="_blank" rel="noopener"
                                                    class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800">
                                                    {{ $t('super_admin.pages.actions.view') }}
                                                </a>
                                                <Link :href="route('superadmin.pages.edit', page.id)"
                                                    class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800">
                                                    {{ $t('super_admin.pages.actions.edit') }}
                                                </Link>
                                                <button type="button" @click="deletePage(page)"
                                                    class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-red-700 hover:bg-red-50 dark:text-red-300 dark:hover:bg-red-500/10">
                                                    {{ $t('super_admin.pages.actions.delete') }}
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!pageRows.length">
                                <td colspan="5" class="px-4 py-6 text-center text-sm text-stone-500 dark:text-neutral-400">
                                    {{ $t('super_admin.pages.empty') }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="pageLinks.length" class="mt-2 flex flex-wrap items-center gap-2 text-sm text-stone-600 dark:text-neutral-400">
                    <template v-for="link in pageLinks" :key="link.url || link.label">
                        <span v-if="!link.url"
                            v-html="link.label"
                            class="rounded-sm border border-stone-200 px-2 py-1 text-stone-400 dark:border-neutral-700">
                        </span>
                        <Link v-else
                            :href="link.url"
                            v-html="link.label"
                            class="rounded-sm border border-stone-200 px-2 py-1 dark:border-neutral-700"
                            :class="link.active ? 'border-transparent bg-green-600 text-white' : 'hover:bg-stone-50 dark:hover:bg-neutral-700'"
                            preserve-scroll />
                    </template>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
