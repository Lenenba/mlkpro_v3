<script setup>
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
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

const platformLabel = (platform) => ({
    facebook: 'Facebook',
    instagram: 'Instagram',
    linkedin: 'LinkedIn',
    x: 'X',
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
const linkHref = computed(() => normalizeLinkCandidate(props.linkUrl));
const linkHost = computed(() => linkHostFor(props.linkUrl));
const resolvedLinkLabel = computed(() => (
    String(props.linkLabel || '').trim() || t('social.visual_preview.link_fallback')
));
const resolvedText = computed(() => (
    String(props.text || '').trim() || props.emptyText || t('social.visual_preview.empty_text')
));
const imageClass = computed(() => (
    props.compact
        ? 'h-44 md:h-52'
        : 'h-56 md:h-64'
));
</script>

<template>
    <div class="space-y-3">
        <div v-if="previewTargets.length > 1" class="flex gap-2 overflow-x-auto pb-1">
            <button
                v-for="target in previewTargets"
                :key="`preview-toggle-${target.key}`"
                type="button"
                class="inline-flex shrink-0 items-center gap-2 rounded-md border px-3 py-2 text-xs font-medium transition"
                :class="activeTarget?.key === target.key
                    ? 'border-sky-500 bg-sky-50 text-sky-700 dark:border-sky-500/60 dark:bg-sky-500/10 dark:text-sky-200'
                    : 'border-stone-200 bg-white text-stone-600 hover:border-sky-300 hover:text-sky-700 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300 dark:hover:border-sky-500/40 dark:hover:text-sky-300'"
                :aria-pressed="activeTarget?.key === target.key"
                @click="activeTargetKey = target.key"
            >
                <span class="size-4">
                    <SocialPlatformLogo :platform="target.platform" />
                </span>
                <span>{{ target.platformLabel }}</span>
            </button>
        </div>

        <article
            v-if="activeTarget"
            :key="activeTarget.id"
            class="overflow-hidden rounded-md border border-stone-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900"
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

            <div class="px-4 py-4">
                <p class="whitespace-pre-line text-sm leading-6 text-stone-800 dark:text-neutral-100">
                    {{ resolvedText }}
                </p>
            </div>

            <div v-if="imageUrl" class="border-y border-stone-100 dark:border-neutral-800">
                <img
                    :src="imageUrl"
                    :alt="t('social.visual_preview.image_alt')"
                    class="w-full object-cover"
                    :class="imageClass"
                >
            </div>

            <a
                v-if="linkHref"
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
        </article>
    </div>
</template>
