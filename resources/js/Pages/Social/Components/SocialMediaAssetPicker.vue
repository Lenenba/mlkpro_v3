<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    assets: {
        type: Array,
        default: () => ([]),
    },
    modelValue: {
        default: null,
    },
    disabled: {
        type: Boolean,
        default: false,
    },
    limit: {
        type: Number,
        default: 8,
    },
});

const emit = defineEmits(['update:modelValue']);

const { t } = useI18n();

const normalizedAssets = computed(() => (Array.isArray(props.assets) ? props.assets : [])
    .map((asset) => ({
        ...asset,
        url: String(asset?.url || '').trim(),
    }))
    .filter((asset) => asset.url !== '')
    .slice(0, Math.max(1, Number(props.limit || 8))));

const selectedUrl = computed(() => (
    typeof props.modelValue === 'string'
        ? props.modelValue.trim()
        : ''
));

const assetLabel = (asset) => (
    String(asset?.name || asset?.origin_label || t('social.media_picker.untitled_asset')).trim()
        || t('social.media_picker.untitled_asset')
);

const selectAsset = (asset) => {
    if (props.disabled || !asset?.url) {
        return;
    }

    emit('update:modelValue', asset.url);
};
</script>

<template>
    <section
        v-if="normalizedAssets.length"
        class="rounded-md border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800/60"
    >
        <div class="mb-2 flex items-center justify-between gap-2">
            <div class="text-xs font-semibold uppercase tracking-[0.18em] text-stone-500 dark:text-neutral-400">
                {{ t('social.media_picker.title') }}
            </div>
            <div class="text-xs text-stone-400 dark:text-neutral-500">
                {{ normalizedAssets.length }}
            </div>
        </div>

        <div class="grid grid-cols-4 gap-2 sm:grid-cols-6 xl:grid-cols-8">
            <button
                v-for="asset in normalizedAssets"
                :key="asset.id || asset.url"
                type="button"
                class="group relative aspect-square overflow-hidden rounded-md border bg-white p-1 transition focus:outline-none focus:ring-2 focus:ring-sky-300 dark:bg-neutral-900"
                :class="selectedUrl === asset.url
                    ? 'border-sky-600 ring-2 ring-sky-200 dark:border-sky-400 dark:ring-sky-500/30'
                    : 'border-stone-200 hover:border-sky-300 dark:border-neutral-700 dark:hover:border-sky-500/50'"
                :disabled="disabled"
                :title="t('social.media_picker.select_asset', { name: assetLabel(asset) })"
                :aria-label="t('social.media_picker.select_asset', { name: assetLabel(asset) })"
                @click="selectAsset(asset)"
            >
                <img
                    :src="asset.url"
                    :alt="assetLabel(asset)"
                    class="h-full w-full rounded object-cover"
                    loading="lazy"
                />
                <span
                    v-if="selectedUrl === asset.url"
                    class="absolute right-1 top-1 inline-flex size-5 items-center justify-center rounded-full bg-sky-600 text-xs font-semibold text-white dark:bg-sky-400 dark:text-stone-950"
                >
                    ✓
                </span>
            </button>
        </div>
    </section>
</template>
