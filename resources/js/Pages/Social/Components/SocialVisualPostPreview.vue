<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { socialPreviewAssets } from '@/utils/socialMediaAssets';
import SocialPlatformLogo from '@/Pages/Social/Components/SocialPlatformLogo.vue';

const props = defineProps({
    text: {
        type: String,
        default: '',
    },
    imageUrl: {
        type: String,
        default: '',
    },
    mediaAssets: {
        type: Array,
        default: () => ([]),
    },
    linkUrl: {
        type: String,
        default: '',
    },
    linkLabel: {
        type: String,
        default: '',
    },
    targets: {
        type: Array,
        default: () => ([]),
    },
    emptyText: {
        type: String,
        default: '',
    },
    compact: {
        type: Boolean,
        default: false,
    },
});

const { t } = useI18n();
const activeTargetKey = ref('');
const activeMediaIndex = ref(0);
const mediaErrors = ref({});
const videoPlayer = ref(null);

const platformLabel = (platform) => ({
    facebook: 'Facebook',
    instagram: 'Instagram',
    linkedin: 'LinkedIn',
    x: 'X',
    twitter: 'X',
    tiktok: 'TikTok',
    youtube: 'YouTube',
    threads: 'Threads',
    pinterest: 'Pinterest',
}[String(platform || '').trim().toLowerCase()] || t('social.visual_preview.generic_platform'));

const normalizeLinkCandidate = (value) => {
    const candidate = String(value || '').trim();
    if (candidate === '') {
        return '';
    }

    if (/^[a-z][a-z0-9+.-]*:/i.test(candidate)) {
        return candidate;
    }

    if (candidate.startsWith('//')) {
        return `https:${candidate}`;
    }

    if (/\s/u.test(candidate) || !candidate.includes('.')) {
        return candidate;
    }

    return `https://${candidate}`;
};

const linkHostFor = (value) => {
    const candidate = normalizeLinkCandidate(value);
    if (candidate === '') {
        return '';
    }

    try {
        return new URL(candidate).host.replace(/^www\./i, '');
    } catch {
        return candidate;
    }
};

const initialFor = (value) => {
    const initial = String(value || '').trim().charAt(0).toUpperCase();

    return initial !== '' ? initial : 'P';
};

const accountNameFor = (target) => {
    const name = String(
        target?.display_name
        || target?.label
        || target?.provider_label
        || platformLabel(target?.platform)
    ).trim();

    return name !== '' ? name : t('social.visual_preview.generic_account');
};

const accountMetaFor = (target) => {
    const handle = String(target?.account_handle || '').trim();
    if (handle !== '') {
        return handle.startsWith('@') ? handle : `@${handle}`;
    }

    return String(target?.provider_label || platformLabel(target?.platform)).trim();
};

const previewTargets = computed(() => {
    const targets = Array.isArray(props.targets) ? props.targets : [];

    if (targets.length === 0) {
        return [{
            id: 'generic',
            key: 'generic',
            platform: 'generic',
            platformLabel: t('social.visual_preview.generic_platform'),
            accountName: t('social.visual_preview.generic_account'),
            accountMeta: t('social.visual_preview.generic_meta'),
            avatarInitial: 'P',
        }];
    }

    return targets.map((target, index) => {
        const platform = String(target?.platform || '').trim().toLowerCase();
        const accountName = accountNameFor(target);

        return {
            id: target?.id || target?.social_account_connection_id || `${platform || 'target'}-${index}`,
            key: String(target?.id || target?.social_account_connection_id || `${platform || 'target'}-${index}`),
            platform,
            platformLabel: platformLabel(platform),
            accountName,
            accountMeta: accountMetaFor(target),
            avatarInitial: initialFor(accountName),
        };
    });
});

watch(previewTargets, (targets) => {
    if (targets.length === 0) {
        activeTargetKey.value = '';

        return;
    }

    if (!targets.some((target) => target.key === activeTargetKey.value)) {
        activeTargetKey.value = targets[0].key;
    }
}, { immediate: true });

const activeTarget = computed(() => (
    previewTargets.value.find((target) => target.key === activeTargetKey.value)
    || previewTargets.value[0]
    || null
));
const linkHref = computed(() => {
    const url = normalizeLinkCandidate(props.linkUrl);

    return /^https?:\/\//iu.test(url) ? url : '';
});
const linkHost = computed(() => linkHostFor(props.linkUrl));
const resolvedLinkLabel = computed(() => (
    String(props.linkLabel || '').trim() || t('social.visual_preview.link_fallback')
));
const resolvedText = computed(() => (
    String(props.text || '').trim() || props.emptyText || t('social.visual_preview.empty_text')
));
const media = computed(() => socialPreviewAssets(props.mediaAssets, props.imageUrl));
const activeMedia = computed(() => media.value[activeMediaIndex.value] || null);
const mediaFirst = computed(() => ['instagram', 'tiktok', 'youtube', 'pinterest'].includes(activeTarget.value?.platform));
const mediaClass = computed(() => props.compact ? 'max-h-96' : 'max-h-[520px]');
const mediaLabel = computed(() => t(`social.visual_preview.media_types.${activeMedia.value?.type || 'image'}`));
const pauseVideo = () => videoPlayer.value?.pause();

watch([activeTargetKey, activeMediaIndex, () => activeMedia.value?.url], pauseVideo);
onBeforeUnmount(pauseVideo);

watch(() => media.value.map((asset) => asset.url).join('|'), () => {
    activeMediaIndex.value = 0;
    mediaErrors.value = {};
});
const markMediaError = (event) => {
    const url = event.currentTarget?.getAttribute('src');
    if (url) {
        mediaErrors.value[url] = true;
    }
};
</script>

<template>
    <div class="min-w-0 space-y-3">
        <div v-if="previewTargets.length > 1" class="flex gap-2 overflow-x-auto pb-1" role="group" :aria-label="t('social.visual_preview.platform_selector')">
            <button
                v-for="target in previewTargets"
                :key="`preview-toggle-${target.key}`"
                type="button"
                class="inline-flex shrink-0 items-center gap-2 rounded-md border px-3 py-2 text-xs font-medium transition"
                :class="activeTarget?.key === target.key
                    ? 'border-sky-500 bg-sky-50 text-sky-700 dark:border-sky-500/60 dark:bg-sky-500/10 dark:text-sky-200'
                    : 'border-stone-200 bg-white text-stone-600 hover:border-sky-300 hover:text-sky-700 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300 dark:hover:border-sky-500/40 dark:hover:text-sky-300'"
                :aria-pressed="activeTarget?.key === target.key"
                :aria-label="`${target.platformLabel} · ${target.accountName}`"
                :title="target.accountName"
                @click="activeTargetKey = target.key"
            >
                <span class="size-4">
                    <SocialPlatformLogo :platform="target.platform" />
                </span>
                <span>{{ target.platformLabel }}</span>
                <span v-if="previewTargets.filter((item) => item.platform === target.platform).length > 1" class="max-w-32 truncate">{{ target.accountName }}</span>
            </button>
        </div>

        <article
            v-if="activeTarget"
            :key="activeTarget.id"
            class="mx-auto w-full overflow-hidden rounded-md border border-stone-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900"
            :class="mediaFirst ? 'max-w-sm' : 'max-w-xl'"
            :aria-label="t('social.visual_preview.preview_for', { platform: activeTarget.platformLabel })"
        >
            <div class="flex items-center gap-3 border-b border-stone-100 px-4 py-3 dark:border-neutral-800">
                <div class="flex size-9 items-center justify-center rounded-full bg-stone-100 text-sm font-semibold text-stone-700 dark:bg-neutral-800 dark:text-neutral-200">
                    {{ activeTarget.avatarInitial }}
                </div>
                <div class="min-w-0 flex-1">
                    <div class="truncate text-sm font-semibold text-stone-900 dark:text-neutral-100">
                        {{ activeTarget.accountName }}
                    </div>
                    <div class="truncate text-xs text-stone-500 dark:text-neutral-400">
                        {{ activeTarget.accountMeta }}
                    </div>
                </div>
                <div class="flex items-center gap-2 text-xs font-medium text-stone-500 dark:text-neutral-400">
                    <span class="size-5">
                        <SocialPlatformLogo :platform="activeTarget.platform" />
                    </span>
                    <span>{{ activeTarget.platformLabel }}</span>
                </div>
            </div>

            <div class="flex flex-col">
                <div class="px-4 py-4" :class="mediaFirst ? 'order-2' : 'order-1'">
                    <p class="whitespace-pre-line break-words text-sm leading-6 text-stone-800 dark:text-neutral-100">
                        {{ resolvedText }}
                    </p>
                </div>

                <div v-if="activeMedia" class="min-w-0 border-y border-stone-100 dark:border-neutral-800" :class="mediaFirst ? 'order-1' : 'order-2'">
                    <div v-if="!mediaErrors[activeMedia.url]" class="flex min-h-40 items-center justify-center bg-black">
                        <video
                            v-if="activeMedia.type === 'video'"
                            ref="videoPlayer"
                            :key="activeMedia.url"
                            :src="activeMedia.url"
                            :poster="activeMedia.thumbnail_url || undefined"
                            :aria-label="t('social.visual_preview.video_label')"
                            controls
                            playsinline
                            preload="metadata"
                            class="block h-auto w-full object-contain"
                            :class="mediaClass"
                            @error="markMediaError"
                        />
                        <img
                            v-else-if="activeMedia.type === 'image'"
                            :key="activeMedia.url"
                            :src="activeMedia.url"
                            :alt="activeMedia.alt_text || t('social.visual_preview.image_alt')"
                            loading="lazy"
                            class="block h-auto w-full object-contain"
                            :class="mediaClass"
                            @error="markMediaError"
                        >
                        <a v-else :href="activeMedia.url" target="_blank" rel="noopener noreferrer" class="flex flex-col gap-2 p-6 text-center text-sm text-white underline">
                            <span>{{ activeMedia.title || activeMedia.name || t('social.visual_preview.media_types.document') }}</span>
                            <span>{{ t('social.visual_preview.open_media') }}</span>
                        </a>
                    </div>
                    <div v-else role="status" class="space-y-2 bg-stone-50 p-4 text-sm text-stone-600 dark:bg-neutral-800 dark:text-neutral-300">
                        <p>{{ t('social.visual_preview.media_error') }}</p>
                        <a :href="activeMedia.url" target="_blank" rel="noopener noreferrer" class="font-medium text-sky-700 underline dark:text-sky-300">{{ t('social.visual_preview.open_media') }}</a>
                    </div>
                    <div class="flex items-center justify-between gap-3 px-4 py-2 text-xs text-stone-500 dark:text-neutral-400">
                        <span>{{ mediaLabel }}</span>
                        <div v-if="media.length > 1" class="flex items-center gap-3">
                            <button type="button" :disabled="activeMediaIndex === 0" :aria-label="t('social.visual_preview.previous_media')" class="rounded border border-stone-200 px-3 py-2 disabled:opacity-40 dark:border-neutral-700" @click="activeMediaIndex--">‹</button>
                            <span aria-live="polite">{{ activeMediaIndex + 1 }} / {{ media.length }}</span>
                            <button type="button" :disabled="activeMediaIndex === media.length - 1" :aria-label="t('social.visual_preview.next_media')" class="rounded border border-stone-200 px-3 py-2 disabled:opacity-40 dark:border-neutral-700" @click="activeMediaIndex++">›</button>
                        </div>
                    </div>
                </div>
            </div>

            <a
                v-if="linkHref && activeTarget.platform !== 'instagram'"
                :href="linkHref"
                target="_blank"
                rel="noreferrer"
                class="block border-t border-stone-100 bg-stone-50 px-4 py-3 transition hover:bg-stone-100 dark:border-neutral-800 dark:bg-neutral-800/70 dark:hover:bg-neutral-800"
            >
                <span class="block text-sm font-semibold text-stone-900 dark:text-neutral-100">
                    {{ resolvedLinkLabel }}
                </span>
                <span v-if="linkHost" class="mt-1 block text-xs text-stone-500 dark:text-neutral-400">
                    {{ t('social.visual_preview.link_destination') }}: {{ linkHost }}
                </span>
            </a>
            <p v-if="linkHref && activeTarget.platform === 'instagram'" class="break-words border-t border-stone-100 px-4 py-3 text-xs text-stone-500 dark:border-neutral-800 dark:text-neutral-400">
                {{ t('social.visual_preview.instagram_link') }} {{ linkHref }}
            </p>
        </article>
        <p class="text-xs leading-5 text-stone-500 dark:text-neutral-400">{{ t('social.visual_preview.disclaimer') }}</p>
    </div>
</template>
