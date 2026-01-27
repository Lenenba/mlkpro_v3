<script setup>
import { computed, ref, watch } from 'vue';
import Modal from '@/Components/Modal.vue';
import { formatBytes } from '@/utils/media';

const props = defineProps({
    show: { type: Boolean, default: false },
    listUrl: { type: String, default: '' },
    title: { type: String, default: '' },
});

const emit = defineEmits(['close', 'select']);

const assets = ref([]);
const loading = ref(false);
const filters = ref({
    search: '',
    tag: '',
});

const isImage = (asset) => Boolean(asset?.is_image);
const isVideo = (asset) => String(asset?.mime || '').startsWith('video/');
const isPdf = (asset) => asset?.mime === 'application/pdf';

const assetLabel = (asset) => asset?.name || 'Asset';
const assetMeta = (asset) => {
    const parts = [];
    if (asset?.mime) parts.push(asset.mime);
    if (asset?.size) parts.push(formatBytes(asset.size));
    return parts.join(' · ');
};

const fetchAssets = async () => {
    if (!props.listUrl) {
        assets.value = [];
        return;
    }
    loading.value = true;
    try {
        const response = await window.axios.get(props.listUrl, {
            params: {
                search: filters.value.search || undefined,
                tag: filters.value.tag || undefined,
            },
        });
        assets.value = response?.data?.assets || [];
    } catch (error) {
        assets.value = [];
    } finally {
        loading.value = false;
    }
};

const applyFilters = () => {
    fetchAssets();
};

const resetFilters = () => {
    filters.value = { search: '', tag: '' };
    fetchAssets();
};

const close = () => emit('close');
const selectAsset = (asset) => emit('select', asset);

watch(
    () => props.show,
    (show) => {
        if (show) {
            fetchAssets();
        }
    }
);
</script>

<template>
    <Modal :show="show" max-width="2xl" @close="close">
        <div class="p-5 space-y-4">
            <div class="flex items-center justify-between gap-2">
                <div>
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                        {{ title || 'Assets' }}
                    </h2>
                    <p class="text-xs text-stone-500 dark:text-neutral-400">
                        {{ $t('super_admin.pages.assets.subtitle') }}
                    </p>
                </div>
                <button type="button" @click="close" class="text-xs text-stone-500 hover:text-stone-700">
                    {{ $t('super_admin.common.close') }}
                </button>
            </div>

            <div class="grid gap-3 md:grid-cols-[1fr_220px_auto_auto] md:items-end">
                <div>
                    <label class="block text-xs text-stone-500 dark:text-neutral-400">
                        {{ $t('super_admin.pages.assets.search') }}
                    </label>
                    <input v-model="filters.search" type="text"
                        class="mt-1 w-full rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm text-stone-700 focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                        :placeholder="$t('super_admin.pages.assets.search_placeholder')" />
                </div>
                <div>
                    <label class="block text-xs text-stone-500 dark:text-neutral-400">
                        {{ $t('super_admin.pages.assets.tag') }}
                    </label>
                    <input v-model="filters.tag" type="text"
                        class="mt-1 w-full rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm text-stone-700 focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                        :placeholder="$t('super_admin.pages.assets.tag_placeholder')" />
                </div>
                <button type="button" @click="resetFilters"
                    class="rounded-sm border border-stone-200 px-3 py-2 text-xs font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800">
                    {{ $t('super_admin.common.clear') }}
                </button>
                <button type="button" @click="applyFilters"
                    class="rounded-sm border border-transparent bg-green-600 px-3 py-2 text-xs font-semibold text-white hover:bg-green-700">
                    {{ $t('super_admin.common.apply') }}
                </button>
            </div>

            <div v-if="loading" class="rounded-sm border border-dashed border-stone-200 p-6 text-center text-sm text-stone-500">
                {{ $t('super_admin.pages.assets.loading') }}
            </div>
            <div v-else-if="!assets.length" class="rounded-sm border border-dashed border-stone-200 p-6 text-center text-sm text-stone-500">
                {{ $t('super_admin.pages.assets.empty') }}
            </div>
            <div v-else class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <button v-for="asset in assets" :key="asset.id" type="button"
                    class="group flex flex-col overflow-hidden rounded-sm border border-stone-200 bg-white text-left shadow-sm transition hover:-translate-y-0.5 hover:shadow-md dark:border-neutral-700 dark:bg-neutral-900"
                    @click="selectAsset(asset)">
                    <div class="flex h-32 items-center justify-center bg-stone-100 dark:bg-neutral-800">
                        <img v-if="isImage(asset)" :src="asset.url" :alt="assetLabel(asset)"
                            class="h-full w-full object-cover" loading="lazy" decoding="async" />
                        <div v-else class="flex flex-col items-center gap-1 text-xs text-stone-500 dark:text-neutral-300">
                            <svg v-if="isPdf(asset)" class="h-6 w-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                    d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2Z" />
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M14 2v6h6" />
                            </svg>
                            <svg v-else-if="isVideo(asset)" class="h-6 w-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                stroke="currentColor">
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
                            {{ assetLabel(asset) }}
                        </div>
                        <div class="text-[11px] text-stone-500 dark:text-neutral-400">
                            {{ assetMeta(asset) }}
                        </div>
                        <div v-if="asset.tags?.length" class="mt-1 flex flex-wrap gap-1 text-[10px] text-stone-500">
                            <span v-for="tag in asset.tags" :key="tag" class="rounded-full bg-stone-100 px-2 py-0.5">
                                {{ tag }}
                            </span>
                        </div>
                        <div class="mt-2 text-xs font-semibold text-green-700">
                            {{ $t('super_admin.pages.assets.use') }}
                        </div>
                    </div>
                </button>
            </div>
        </div>
    </Modal>
</template>
