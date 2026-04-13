<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AdminDataTable from '@/Components/DataTable/AdminDataTable.vue';
import AdminDataTableActions from '@/Components/DataTable/AdminDataTableActions.vue';
import AdminDataTableToolbar from '@/Components/DataTable/AdminDataTableToolbar.vue';
import InputError from '@/Components/InputError.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import useDataTableFilters from '@/Composables/useDataTableFilters';
import { formatBytes } from '@/utils/media';

const props = defineProps({
    assets: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    dashboard_url: { type: String, required: true },
});

const { t } = useI18n();

const showFilters = ref(false);
const fileInput = ref(null);

const assetRows = computed(() => props.assets?.data || []);
const assetLinks = computed(() => props.assets?.links || []);
const assetsTotal = computed(() => Number(props.assets?.total || assetRows.value.length || 0));
const assetsResultsLabel = computed(() => t('super_admin.assets.filters.results', { count: assetsTotal.value }));

const filterForm = useForm({
    search: props.filters?.search ?? '',
    tag: props.filters?.tag ?? '',
});

const uploadForm = useForm({
    files: [],
    tags: '',
    alt: '',
});

const { apply: applyFilters, clear: clearDataTableFilters } = useDataTableFilters(
    filterForm,
    route('superadmin.assets.index'),
    {
        only: ['assets', 'filters'],
    }
);

const clearFilters = () => {
    showFilters.value = false;
    clearDataTableFilters();
};

const onFilesChange = (event) => {
    const files = event?.target?.files ? Array.from(event.target.files) : [];
    uploadForm.files = files;
};

const submitUpload = () => {
    uploadForm.post(route('superadmin.assets.store'), {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            uploadForm.reset('files', 'tags', 'alt');

            if (fileInput.value) {
                fileInput.value.value = '';
            }
        },
    });
};

const deleteAsset = (asset) => {
    if (! asset?.id || asset?.is_system) {
        return;
    }

    if (! window.confirm(t('super_admin.assets.actions.confirm_delete', { name: asset.name }))) {
        return;
    }

    router.delete(route('superadmin.assets.destroy', asset.id), {
        preserveScroll: true,
    });
};

const copyUrl = async (asset) => {
    if (! asset?.url) {
        return;
    }

    try {
        await navigator.clipboard.writeText(asset.url);
    } catch (error) {
        // Ignore clipboard failures and keep the current page state intact.
    }
};

const isImage = (asset) => Boolean(asset?.is_image);
const isVideo = (asset) => String(asset?.mime || '').startsWith('video/');
const isPdf = (asset) => asset?.mime === 'application/pdf';

const formatDate = (value) => {
    if (! value) {
        return t('super_admin.common.not_available');
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? value : date.toLocaleDateString();
};

const previewTypeLabel = (asset) => {
    if (isPdf(asset)) {
        return 'PDF';
    }

    if (isVideo(asset)) {
        return 'Video';
    }

    return 'File';
};
</script>

<template>
    <Head :title="$t('super_admin.assets.title')" />

    <AuthenticatedLayout>
        <div class="space-y-5">
            <section class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="space-y-1">
                        <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">
                            {{ $t('super_admin.assets.title') }}
                        </h1>
                        <p class="text-sm text-stone-600 dark:text-neutral-400">
                            {{ $t('super_admin.assets.subtitle') }}
                        </p>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <Link
                            :href="dashboard_url"
                            class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                        >
                            {{ $t('super_admin.assets.actions.back_dashboard') }}
                        </Link>
                    </div>
                </div>
            </section>

            <section class="space-y-4 rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex items-center justify-between gap-2">
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                        {{ $t('super_admin.assets.actions.upload') }}
                    </h2>
                </div>

                <form class="grid gap-3 md:grid-cols-[2fr_1fr_1fr_auto]" @submit.prevent="submitUpload">
                    <div>
                        <label class="block text-xs text-stone-500 dark:text-neutral-400">
                            {{ $t('super_admin.assets.fields.files') }}
                        </label>
                        <input
                            ref="fileInput"
                            type="file"
                            multiple
                            accept="image/*,application/pdf,video/*"
                            class="mt-1 block w-full text-sm text-stone-600 file:me-4 file:rounded-sm file:border-0 file:bg-stone-100 file:px-3 file:py-2 file:text-xs file:font-semibold file:text-stone-700 hover:file:bg-stone-200 dark:text-neutral-300 dark:file:bg-neutral-800 dark:file:text-neutral-200 dark:hover:file:bg-neutral-700"
                            @change="onFilesChange"
                        >
                        <InputError class="mt-1" :message="uploadForm.errors.files" />
                        <InputError class="mt-1" :message="uploadForm.errors['files.0']" />
                    </div>
                    <div>
                        <label class="block text-xs text-stone-500 dark:text-neutral-400">
                            {{ $t('super_admin.assets.fields.tags') }}
                        </label>
                        <input
                            v-model="uploadForm.tags"
                            type="text"
                            class="mt-1 w-full rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm text-stone-700 focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                            :placeholder="$t('super_admin.assets.fields.tags_placeholder')"
                        >
                    </div>
                    <div>
                        <label class="block text-xs text-stone-500 dark:text-neutral-400">
                            {{ $t('super_admin.assets.fields.alt') }}
                        </label>
                        <input
                            v-model="uploadForm.alt"
                            type="text"
                            class="mt-1 w-full rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm text-stone-700 focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                            :placeholder="$t('super_admin.assets.fields.alt_placeholder')"
                        >
                    </div>
                    <div class="flex items-end">
                        <button
                            type="submit"
                            class="rounded-sm border border-transparent bg-green-600 px-4 py-2 text-sm font-semibold text-white hover:bg-green-700"
                            :disabled="uploadForm.processing"
                        >
                            {{ $t('super_admin.assets.actions.upload') }}
                        </button>
                    </div>
                </form>

                <div v-if="uploadForm.files?.length" class="flex flex-wrap gap-2 text-xs text-stone-500">
                    <span
                        v-for="file in uploadForm.files"
                        :key="file.name"
                        class="inline-flex items-center gap-1 rounded-full border border-stone-200 bg-white px-2 py-0.5 text-[11px] text-stone-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300"
                    >
                        {{ file.name }} · {{ formatBytes(file.size) }}
                    </span>
                </div>
            </section>

            <AdminDataTable
                :rows="assetRows"
                :links="assetLinks"
                :total="assetsTotal"
                :result-label="assetsResultsLabel"
                :empty-description="$t('super_admin.assets.empty')"
                container-class="border-t-4 border-t-zinc-600"
            >
                <template #toolbar>
                    <AdminDataTableToolbar
                        :show-filters="showFilters"
                        :search-placeholder="$t('super_admin.assets.filters.search_placeholder')"
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
                                <label class="block text-xs text-stone-500 dark:text-neutral-400">
                                    {{ $t('super_admin.assets.filters.tag') }}
                                </label>
                                <input
                                    v-model="filterForm.tag"
                                    type="text"
                                    class="mt-1 w-full rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm text-stone-700 focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                                    :placeholder="$t('super_admin.assets.filters.tag_placeholder')"
                                >
                            </div>
                        </template>
                    </AdminDataTableToolbar>
                </template>

                <template #head>
                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-stone-600 dark:text-neutral-300">
                        <th class="px-4 py-3">{{ $t('super_admin.assets.table.preview') }}</th>
                        <th class="px-4 py-3">{{ $t('super_admin.assets.table.asset') }}</th>
                        <th class="px-4 py-3">{{ $t('super_admin.assets.table.type') }}</th>
                        <th class="px-4 py-3">{{ $t('super_admin.assets.table.tags') }}</th>
                        <th class="px-4 py-3">{{ $t('super_admin.assets.table.created') }}</th>
                        <th class="px-4 py-3 text-right"></th>
                    </tr>
                </template>

                <template #row="{ row: asset }">
                    <tr class="align-top">
                        <td class="px-4 py-3">
                            <div class="flex h-16 w-16 items-center justify-center overflow-hidden rounded-sm border border-stone-200 bg-stone-100 dark:border-neutral-700 dark:bg-neutral-800">
                                <img
                                    v-if="isImage(asset)"
                                    :src="asset.url"
                                    :alt="asset.alt || asset.name"
                                    class="h-full w-full object-cover"
                                    loading="lazy"
                                    decoding="async"
                                >
                                <div v-else class="flex flex-col items-center gap-1 text-[11px] text-stone-500 dark:text-neutral-300">
                                    <svg v-if="isPdf(asset)" class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2Z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M14 2v6h6" />
                                    </svg>
                                    <svg v-else-if="isVideo(asset)" class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M14.752 11.168 9.718 8.168A1 1 0 0 0 8.25 9.05v5.9a1 1 0 0 0 1.468.882l5.034-3a1 1 0 0 0 0-1.664Z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                                    </svg>
                                    <svg v-else class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2Z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M14 2v6h6" />
                                    </svg>
                                    <span>{{ previewTypeLabel(asset) }}</span>
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <div class="font-medium text-stone-800 dark:text-neutral-100">{{ asset.name }}</div>
                            <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                {{ asset.mime }} · {{ formatBytes(asset.size) }}
                            </div>
                            <div v-if="asset.alt" class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                {{ asset.alt }}
                            </div>
                            <div
                                v-if="asset.is_system"
                                class="mt-2 inline-flex items-center rounded-full bg-sky-100 px-2 py-0.5 text-[11px] font-medium text-sky-700 dark:bg-sky-500/10 dark:text-sky-300"
                            >
                                {{ $t('super_admin.assets.table.stock') }}
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <div class="text-sm text-stone-800 dark:text-neutral-100">{{ asset.mime }}</div>
                            <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                {{ isImage(asset) ? 'Image' : previewTypeLabel(asset) }}
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <div v-if="asset.tags?.length" class="flex flex-wrap gap-1">
                                <span
                                    v-for="tag in asset.tags"
                                    :key="tag"
                                    class="rounded-full bg-stone-100 px-2 py-0.5 text-[11px] text-stone-600 dark:bg-neutral-800 dark:text-neutral-300"
                                >
                                    {{ tag }}
                                </span>
                            </div>
                            <span v-else class="text-xs text-stone-400 dark:text-neutral-500">-</span>
                        </td>
                        <td class="px-4 py-3">
                            {{ formatDate(asset.created_at) }}
                        </td>
                        <td class="px-4 py-3 text-right">
                            <AdminDataTableActions :label="$t('super_admin.common.actions')">
                                <a
                                    :href="asset.url"
                                    target="_blank"
                                    rel="noopener"
                                    class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800"
                                >
                                    {{ $t('super_admin.common.view') }}
                                </a>
                                <button
                                    type="button"
                                    class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800"
                                    @click="copyUrl(asset)"
                                >
                                    {{ $t('super_admin.assets.actions.copy') }}
                                </button>
                                <button
                                    v-if="!asset.is_system"
                                    type="button"
                                    class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-neutral-800"
                                    @click="deleteAsset(asset)"
                                >
                                    {{ $t('super_admin.assets.actions.delete') }}
                                </button>
                            </AdminDataTableActions>
                        </td>
                    </tr>
                </template>
            </AdminDataTable>
        </div>
    </AuthenticatedLayout>
</template>
