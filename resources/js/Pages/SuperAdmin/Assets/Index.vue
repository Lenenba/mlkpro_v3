<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import { formatBytes } from '@/utils/media';

const props = defineProps({
    assets: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    dashboard_url: { type: String, required: true },
});

const { t } = useI18n();

const assetsData = computed(() => props.assets?.data || []);

const filterForm = useForm({
    search: props.filters?.search ?? '',
    tag: props.filters?.tag ?? '',
});

const uploadForm = useForm({
    files: [],
    tags: '',
    alt: '',
});

const fileInput = ref(null);

const applyFilters = () => {
    filterForm.get(route('superadmin.assets.index'), {
        only: ['assets', 'filters'],
        preserveScroll: true,
        preserveState: true,
    });
};

const resetFilters = () => {
    filterForm.reset();
    filterForm.get(route('superadmin.assets.index'), {
        only: ['assets', 'filters'],
        preserveScroll: true,
    });
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
    if (!asset?.id) return;
    const ok = window.confirm(t('super_admin.assets.actions.confirm_delete', { name: asset.name }));
    if (!ok) return;
    router.delete(route('superadmin.assets.destroy', asset.id), { preserveScroll: true });
};

const copyUrl = async (asset) => {
    if (!asset?.url) return;
    try {
        await navigator.clipboard.writeText(asset.url);
    } catch (error) {
        // ignore
    }
};

const isImage = (asset) => Boolean(asset?.is_image);
const isVideo = (asset) => String(asset?.mime || '').startsWith('video/');
const isPdf = (asset) => asset?.mime === 'application/pdf';
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
                        <Link :href="dashboard_url"
                            class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800">
                            {{ $t('super_admin.assets.actions.back_dashboard') }}
                        </Link>
                    </div>
                </div>
            </section>

            <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-4">
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
                        <input ref="fileInput" type="file" multiple
                            accept="image/*,application/pdf,video/*"
                            class="mt-1 block w-full text-sm text-stone-600 file:me-4 file:rounded-sm file:border-0 file:bg-stone-100 file:px-3 file:py-2 file:text-xs file:font-semibold file:text-stone-700 hover:file:bg-stone-200 dark:text-neutral-300 dark:file:bg-neutral-800 dark:file:text-neutral-200 dark:hover:file:bg-neutral-700"
                            @change="onFilesChange" />
                        <InputError class="mt-1" :message="uploadForm.errors.files" />
                        <InputError class="mt-1" :message="uploadForm.errors['files.0']" />
                    </div>
                    <div>
                        <label class="block text-xs text-stone-500 dark:text-neutral-400">
                            {{ $t('super_admin.assets.fields.tags') }}
                        </label>
                        <input v-model="uploadForm.tags" type="text"
                            class="mt-1 w-full rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm text-stone-700 focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                            :placeholder="$t('super_admin.assets.fields.tags_placeholder')" />
                    </div>
                    <div>
                        <label class="block text-xs text-stone-500 dark:text-neutral-400">
                            {{ $t('super_admin.assets.fields.alt') }}
                        </label>
                        <input v-model="uploadForm.alt" type="text"
                            class="mt-1 w-full rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm text-stone-700 focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                            :placeholder="$t('super_admin.assets.fields.alt_placeholder')" />
                    </div>
                    <div class="flex items-end">
                        <button type="submit"
                            class="rounded-sm border border-transparent bg-green-600 px-4 py-2 text-sm font-semibold text-white hover:bg-green-700"
                            :disabled="uploadForm.processing">
                            {{ $t('super_admin.assets.actions.upload') }}
                        </button>
                    </div>
                </form>

                <div v-if="uploadForm.files?.length" class="flex flex-wrap gap-2 text-xs text-stone-500">
                    <span v-for="file in uploadForm.files" :key="file.name"
                        class="inline-flex items-center gap-1 rounded-full border border-stone-200 bg-white px-2 py-0.5 text-[11px] text-stone-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300">
                        {{ file.name }} · {{ formatBytes(file.size) }}
                    </span>
                </div>
            </section>

            <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-4">
                <form class="grid gap-3 md:grid-cols-[1fr_220px_auto_auto]" @submit.prevent="applyFilters">
                    <div>
                        <label class="block text-xs text-stone-500 dark:text-neutral-400">
                            {{ $t('super_admin.assets.filters.search') }}
                        </label>
                        <input v-model="filterForm.search" type="text"
                            class="mt-1 w-full rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm text-stone-700 focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                            :placeholder="$t('super_admin.assets.filters.search_placeholder')" />
                    </div>
                    <div>
                        <label class="block text-xs text-stone-500 dark:text-neutral-400">
                            {{ $t('super_admin.assets.filters.tag') }}
                        </label>
                        <input v-model="filterForm.tag" type="text"
                            class="mt-1 w-full rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm text-stone-700 focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                            :placeholder="$t('super_admin.assets.filters.tag_placeholder')" />
                    </div>
                    <div class="flex items-end">
                        <button type="button" @click="resetFilters"
                            class="rounded-sm border border-stone-200 px-3 py-2 text-xs font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800">
                            {{ $t('super_admin.common.clear') }}
                        </button>
                    </div>
                    <div class="flex items-end">
                        <button type="submit"
                            class="rounded-sm border border-transparent bg-green-600 px-3 py-2 text-xs font-semibold text-white hover:bg-green-700">
                            {{ $t('super_admin.common.apply_filters') }}
                        </button>
                    </div>
                </form>

                <div v-if="!assetsData.length"
                    class="rounded-sm border border-dashed border-stone-300 p-6 text-sm text-stone-600 dark:border-neutral-600 dark:text-neutral-300">
                    {{ $t('super_admin.assets.empty') }}
                </div>

                <div v-else class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div v-for="asset in assetsData" :key="asset.id"
                        class="flex flex-col overflow-hidden rounded-sm border border-stone-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <div class="flex h-32 items-center justify-center bg-stone-100 dark:bg-neutral-800">
                            <img v-if="isImage(asset)" :src="asset.url" :alt="asset.name"
                                class="h-full w-full object-cover" loading="lazy" decoding="async" />
                            <div v-else class="flex flex-col items-center gap-1 text-xs text-stone-500 dark:text-neutral-300">
                                <svg v-if="isPdf(asset)" class="h-6 w-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                    stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                        d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2Z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M14 2v6h6" />
                                </svg>
                                <svg v-else-if="isVideo(asset)" class="h-6 w-6" xmlns="http://www.w3.org/2000/svg" fill="none"
                                    viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                        d="M14.752 11.168 9.718 8.168A1 1 0 0 0 8.25 9.05v5.9a1 1 0 0 0 1.468.882l5.034-3a1 1 0 0 0 0-1.664Z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                        d="M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                                </svg>
                                <svg v-else class="h-6 w-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                    stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                        d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2Z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M14 2v6h6" />
                                </svg>
                                <span>{{ isPdf(asset) ? 'PDF' : isVideo(asset) ? 'Video' : 'File' }}</span>
                            </div>
                        </div>
                        <div class="flex flex-1 flex-col gap-1 p-3">
                            <div class="truncate text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                {{ asset.name }}
                            </div>
                            <div class="text-[11px] text-stone-500 dark:text-neutral-400">
                                {{ asset.mime }} · {{ formatBytes(asset.size) }}
                            </div>
                            <div v-if="asset.tags?.length" class="mt-1 flex flex-wrap gap-1 text-[10px] text-stone-500">
                                <span v-for="tag in asset.tags" :key="tag"
                                    class="rounded-full bg-stone-100 px-2 py-0.5 dark:bg-neutral-800 dark:text-neutral-300">
                                    {{ tag }}
                                </span>
                            </div>
                            <div class="mt-auto flex flex-wrap gap-2 pt-3 text-xs">
                                <button type="button" class="rounded-sm border border-stone-200 px-2 py-1 text-stone-600 hover:bg-stone-50"
                                    @click="copyUrl(asset)">
                                    {{ $t('super_admin.assets.actions.copy') }}
                                </button>
                                <button type="button" class="rounded-sm border border-red-200 px-2 py-1 text-red-700 hover:bg-red-50"
                                    @click="deleteAsset(asset)">
                                    {{ $t('super_admin.assets.actions.delete') }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div v-if="props.assets.links?.length" class="mt-2 flex flex-wrap items-center gap-2 text-sm text-stone-600 dark:text-neutral-400">
                    <template v-for="link in props.assets.links" :key="link.url || link.label">
                        <span v-if="!link.url"
                            v-html="link.label"
                            class="px-2 py-1 rounded-sm border border-stone-200 text-stone-400 dark:border-neutral-700">
                        </span>
                        <Link v-else
                            :href="link.url"
                            v-html="link.label"
                            class="px-2 py-1 rounded-sm border border-stone-200 dark:border-neutral-700"
                            :class="link.active ? 'bg-green-600 text-white border-transparent' : 'hover:bg-stone-50 dark:hover:bg-neutral-700'"
                            preserve-scroll />
                    </template>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
