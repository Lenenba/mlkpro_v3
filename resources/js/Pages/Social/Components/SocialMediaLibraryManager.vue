<script setup>
import { computed, ref } from 'vue';
import axios from 'axios';
import { useI18n } from 'vue-i18n';
import DropzoneInput from '@/Components/DropzoneInput.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';

const props = defineProps({
    initialAssets: {
        type: Array,
        default: () => ([]),
    },
    initialFilters: {
        type: Object,
        default: () => ({}),
    },
    initialSummary: {
        type: Object,
        default: () => ({}),
    },
    initialSourceOptions: {
        type: Array,
        default: () => ([]),
    },
    initialOriginOptions: {
        type: Array,
        default: () => ([]),
    },
    initialAccess: {
        type: Object,
        default: () => ({}),
    },
});

const { t } = useI18n();

const normalizeAssets = (payload) => Array.isArray(payload) ? payload : [];
const normalizeOptions = (payload) => Array.isArray(payload) ? payload : [];
const normalizeSummary = (payload) => payload && typeof payload === 'object' ? payload : {};
const normalizeAccess = (payload) => ({
    can_view: Boolean(payload?.can_view),
    can_manage_posts: Boolean(payload?.can_manage_posts),
});
const normalizeFilters = (payload) => ({
    source: String(payload?.source || 'all'),
    origin: String(payload?.origin || 'all'),
    search: String(payload?.search || ''),
});

const assets = ref(normalizeAssets(props.initialAssets));
const sourceOptions = ref(normalizeOptions(props.initialSourceOptions));
const originOptions = ref(normalizeOptions(props.initialOriginOptions));
const summary = ref(normalizeSummary(props.initialSummary));
const access = ref(normalizeAccess(props.initialAccess));
const filters = ref(normalizeFilters(props.initialFilters));
const uploadFile = ref(null);
const busy = ref(false);
const isLoading = ref(false);
const error = ref('');
const info = ref('');

const canManage = computed(() => Boolean(access.value.can_manage_posts));
const hasAssets = computed(() => assets.value.length > 0);
const isFile = (value) => typeof File !== 'undefined' && value instanceof File;
const canUploadFile = computed(() => canManage.value && isFile(uploadFile.value));
const optionLabel = (group, value) => t(`social.media_manager.${group}.${value}`);
const sourceFilterOptions = computed(() => sourceOptions.value.map((option) => ({
    ...option,
    label: optionLabel('sources', option.value),
})));
const originFilterOptions = computed(() => originOptions.value.map((option) => ({
    ...option,
    label: optionLabel('origins', option.value),
})));

const requestErrorMessage = (requestError, fallback) => {
    const validationMessage = Object.values(requestError?.response?.data?.errors || {})
        .flat()
        .find((value) => typeof value === 'string' && value.trim() !== '');

    return validationMessage
        || requestError?.response?.data?.message
        || requestError?.message
        || fallback;
};

const refreshFromPayload = (payload) => {
    if (Array.isArray(payload?.assets)) {
        assets.value = normalizeAssets(payload.assets);
    }

    if (payload?.summary) {
        summary.value = normalizeSummary(payload.summary);
    }

    if (payload?.filters) {
        filters.value = normalizeFilters(payload.filters);
    }

    if (Array.isArray(payload?.source_options)) {
        sourceOptions.value = normalizeOptions(payload.source_options);
    }

    if (Array.isArray(payload?.origin_options)) {
        originOptions.value = normalizeOptions(payload.origin_options);
    }

    if (payload?.access) {
        access.value = normalizeAccess(payload.access);
    }
};

const load = async () => {
    isLoading.value = true;
    error.value = '';

    try {
        const response = await axios.get(route('social.media.index'), {
            params: filters.value,
        });
        refreshFromPayload(response.data);
    } catch (requestError) {
        error.value = requestErrorMessage(requestError, t('social.media_manager.messages.load_error'));
    } finally {
        isLoading.value = false;
    }
};

const upload = async () => {
    if (!canUploadFile.value) {
        return;
    }

    busy.value = true;
    error.value = '';
    info.value = '';

    const payload = new FormData();
    payload.append('image_file', uploadFile.value);
    payload.append('source', filters.value.source);
    payload.append('origin', filters.value.origin);
    payload.append('search', filters.value.search);

    try {
        const response = await axios.post(route('social.media.store'), payload, {
            headers: {
                'Content-Type': 'multipart/form-data',
            },
        });
        refreshFromPayload(response.data);
        uploadFile.value = null;
        info.value = String(response.data?.message || t('social.media_manager.messages.upload_success'));
    } catch (requestError) {
        error.value = requestErrorMessage(requestError, t('social.media_manager.messages.upload_error'));
    } finally {
        busy.value = false;
    }
};

const resetFilters = async () => {
    filters.value = {
        source: 'all',
        origin: 'all',
        search: '',
    };
    await load();
};

const useInComposer = (asset) => {
    const url = String(asset?.url || '').trim();
    if (url === '') {
        return;
    }

    window.location.href = route('social.composer', { image_url: url });
};

const copyUrl = async (asset) => {
    const url = String(asset?.url || '').trim();
    if (url === '') {
        return;
    }

    try {
        await navigator.clipboard.writeText(url);
        info.value = t('social.media_manager.messages.url_copied');
    } catch {
        info.value = url;
    }
};

const formatDate = (value) => {
    if (!value) {
        return t('social.media_manager.empty_value');
    }

    try {
        return new Date(value).toLocaleString();
    } catch {
        return t('social.media_manager.empty_value');
    }
};

const bytesLabel = (value) => {
    const size = Number(value || 0);
    if (size <= 0) {
        return '';
    }

    if (size < 1024 * 1024) {
        return `${Math.round(size / 1024)} KB`;
    }

    return `${(size / 1024 / 1024).toFixed(1)} MB`;
};

</script>

<template>
    <div class="space-y-5">
        <div
            v-if="!canManage"
            class="rounded-md border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-700 dark:border-sky-500/20 dark:bg-sky-500/10 dark:text-sky-300"
        >
            <div class="font-semibold">{{ t('social.media_manager.read_only_title') }}</div>
            <div class="mt-1">{{ t('social.media_manager.read_only_description') }}</div>
        </div>

        <div
            v-if="error"
            class="rounded-md border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-300"
        >
            {{ error }}
        </div>

        <div
            v-if="info"
            class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300"
        >
            {{ info }}
        </div>

        <section class="grid grid-cols-1 items-start gap-4 xl:grid-cols-3">
            <section class="rounded-md border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="grid grid-cols-1 gap-3">
                    <div
                        v-for="item in [
                            ['total', summary.total || 0],
                            ['uploads', summary.uploads || 0],
                            ['ai', summary.ai || 0],
                            ['posts', summary.posts || 0],
                        ]"
                        :key="item[0]"
                        class="flex items-center justify-between gap-3 rounded-md border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800/60"
                    >
                        <div class="text-xs font-medium text-stone-500 dark:text-neutral-400">
                            {{ t(`social.media_manager.summary.${item[0]}`) }}
                        </div>
                        <div class="text-xl font-semibold text-stone-900 dark:text-neutral-100">
                            {{ item[1] }}
                        </div>
                    </div>
                </div>
            </section>

            <form class="rounded-md border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900" @submit.prevent="load">
                <div class="grid grid-cols-1 gap-3">
                    <FloatingInput
                        v-model="filters.search"
                        :label="t('social.media_manager.fields.search')"
                        :disabled="isLoading || busy"
                    />

                    <FloatingSelect
                        v-model="filters.source"
                        :label="t('social.media_manager.fields.source')"
                        :options="sourceFilterOptions"
                        :disabled="isLoading || busy"
                    />

                    <FloatingSelect
                        v-model="filters.origin"
                        :label="t('social.media_manager.fields.origin')"
                        :options="originFilterOptions"
                        :disabled="isLoading || busy"
                    />

                    <div class="grid grid-cols-2 gap-2">
                        <PrimaryButton class="w-full justify-center" :disabled="isLoading || busy">
                            {{ t('social.media_manager.actions.apply_filters') }}
                        </PrimaryButton>
                        <SecondaryButton type="button" class="w-full justify-center" :disabled="isLoading || busy" @click="resetFilters">
                            {{ t('social.media_manager.actions.reset_filters') }}
                        </SecondaryButton>
                    </div>
                </div>
            </form>

            <section class="rounded-md border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="mb-3">
                    <h2 class="text-base font-semibold text-stone-900 dark:text-neutral-100">
                        {{ t('social.media_manager.upload_title') }}
                    </h2>
                </div>

                <DropzoneInput
                    v-model="uploadFile"
                    :label="t('social.media_manager.fields.image_file')"
                />

                <div class="mt-3 flex justify-end">
                    <PrimaryButton :disabled="busy || !canUploadFile" @click="upload">
                        {{ busy ? t('social.media_manager.actions.uploading') : t('social.media_manager.actions.upload') }}
                    </PrimaryButton>
                </div>
            </section>
        </section>

        <section v-if="hasAssets" class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-4">
            <article
                v-for="asset in assets"
                :key="asset.id"
                class="rounded-md border border-stone-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900"
            >
                <div class="aspect-square overflow-hidden rounded-t-md bg-stone-100 dark:bg-neutral-800">
                    <img
                        :src="asset.url"
                        :alt="asset.name || asset.origin_label || t('social.media_manager.preview_alt')"
                        class="h-full w-full object-cover"
                        loading="lazy"
                    />
                </div>
                <div class="space-y-3 p-3">
                    <div>
                        <div class="line-clamp-1 text-sm font-semibold text-stone-900 dark:text-neutral-100">
                            {{ asset.name || asset.origin_label || t('social.media_manager.untitled_asset') }}
                        </div>
                        <div class="mt-1 line-clamp-1 text-xs text-stone-500 dark:text-neutral-400">
                            {{ asset.origin_label || t('social.media_manager.empty_value') }}
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <span class="rounded-md bg-sky-50 px-2 py-1 text-xs font-medium text-sky-700 dark:bg-sky-500/10 dark:text-sky-200">
                            {{ optionLabel('sources', asset.source || 'url') }}
                        </span>
                        <span class="rounded-md bg-stone-100 px-2 py-1 text-xs font-medium text-stone-700 dark:bg-neutral-800 dark:text-neutral-200">
                            {{ optionLabel('origins', asset.origin || 'post') }}
                        </span>
                    </div>

                    <div class="text-xs text-stone-500 dark:text-neutral-400">
                        <span>{{ formatDate(asset.used_at || asset.created_at) }}</span>
                        <span v-if="bytesLabel(asset.size)"> · {{ bytesLabel(asset.size) }}</span>
                    </div>

                    <div class="flex gap-2">
                        <PrimaryButton type="button" class="flex-1 justify-center" @click="useInComposer(asset)">
                            {{ t('social.media_manager.actions.use_in_composer') }}
                        </PrimaryButton>
                        <SecondaryButton type="button" @click="copyUrl(asset)">
                            {{ t('social.media_manager.actions.copy_url') }}
                        </SecondaryButton>
                    </div>
                </div>
            </article>
        </section>

        <section
            v-else
            class="rounded-md border border-dashed border-stone-300 bg-stone-50 px-5 py-8 text-center text-sm text-stone-500 dark:border-neutral-700 dark:bg-neutral-800/60 dark:text-neutral-400"
        >
            {{ t('social.media_manager.empty_assets') }}
        </section>
    </div>
</template>
