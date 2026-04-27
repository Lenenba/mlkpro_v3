<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

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
    recentTexts: {
        type: Array,
        default: () => ([]),
    },
    brandVoice: {
        type: Object,
        default: () => ({}),
    },
    serverQuality: {
        type: Object,
        default: () => null,
    },
});

const { t } = useI18n();

const normalizedText = computed(() => String(props.text || '').trim());
const hasImage = computed(() => String(props.imageUrl || '').trim() !== '');
const hasLink = computed(() => String(props.linkUrl || '').trim() !== '');
const hasLinkLabel = computed(() => String(props.linkLabel || '').trim() !== '');
const targetCount = computed(() => (Array.isArray(props.targets) ? props.targets.length : 0));
const targetPlatforms = computed(() => (Array.isArray(props.targets) ? props.targets : [])
    .map((target) => String(target?.platform || '').trim().toLowerCase())
    .filter((platform) => platform !== ''));
const textLimit = computed(() => {
    const limits = targetPlatforms.value.map((platform) => ({
        x: 280,
        instagram: 2200,
        linkedin: 3000,
        facebook: 5000,
    }[platform] || 900));

    return limits.length > 0 ? Math.min(...limits) : 900;
});
const requiresImage = computed(() => targetPlatforms.value.includes('instagram'));
const configuredBrandVoice = computed(() => (
    props.brandVoice && typeof props.brandVoice === 'object'
        ? props.brandVoice
        : {}
));

const comparableText = (value) => String(value || '')
    .toLowerCase()
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/https?:\/\/\S+/g, '')
    .replace(/[^\p{L}\p{N}\s#]/gu, ' ')
    .replace(/\s+/g, ' ')
    .trim();

const isSimilarToRecent = computed(() => {
    const current = comparableText(normalizedText.value);
    if (current.length < 40) {
        return false;
    }

    const currentWords = new Set(current.split(' ').filter((word) => word.length > 3));
    if (currentWords.size < 5) {
        return false;
    }

    return (Array.isArray(props.recentTexts) ? props.recentTexts : []).some((recent) => {
        const candidate = comparableText(recent);
        if (candidate === '' || candidate === current) {
            return candidate === current;
        }

        const candidateWords = new Set(candidate.split(' ').filter((word) => word.length > 3));
        if (candidateWords.size < 5) {
            return false;
        }

        const overlap = [...currentWords].filter((word) => candidateWords.has(word)).length;

        return overlap / Math.min(currentWords.size, candidateWords.size) >= 0.72;
    });
});

const quality = computed(() => {
    if (props.serverQuality && typeof props.serverQuality === 'object' && Number.isFinite(Number(props.serverQuality.score))) {
        return {
            score: Number(props.serverQuality.score || 0),
            issues: Array.isArray(props.serverQuality.issues) ? props.serverQuality.issues : [],
            status: String(props.serverQuality.status || 'warning'),
        };
    }

    const issues = [];
    let score = 100;

    if (targetCount.value === 0) {
        issues.push({ key: 'no_targets', level: 'attention', points: 25 });
        score -= 25;
    }

    if (normalizedText.value === '' && !hasImage.value && !hasLink.value) {
        issues.push({ key: 'empty_content', level: 'attention', points: 30 });
        score -= 30;
    }

    if (normalizedText.value.length > textLimit.value) {
        issues.push({ key: 'text_too_long', level: 'warning', points: 18 });
        score -= 18;
    }

    if (requiresImage.value && !hasImage.value) {
        issues.push({ key: 'missing_image', level: 'notice', points: 8 });
        score -= 8;
    }

    if (hasLinkLabel.value && !hasLink.value) {
        issues.push({ key: 'cta_without_link', level: 'warning', points: 14 });
        score -= 14;
    }

    if (isSimilarToRecent.value) {
        issues.push({ key: 'similar_recent', level: 'warning', points: 16 });
        score -= 16;
    }

    const blockedWord = (Array.isArray(configuredBrandVoice.value.words_to_avoid) ? configuredBrandVoice.value.words_to_avoid : [])
        .map((word) => String(word || '').trim())
        .find((word) => word !== '' && normalizedText.value.toLowerCase().includes(word.toLowerCase()));

    if (blockedWord) {
        issues.push({ key: 'brand_voice_word', level: 'warning', points: 12 });
        score -= 12;
    }

    if ((hasLink.value || hasLinkLabel.value) && !hasLinkLabel.value && !/(reserve|reservez|book|contact|message|decouvrir|discover|voir|shop|acheter|buy|learn)/i.test(normalizedText.value)) {
        issues.push({ key: 'weak_cta', level: 'notice', points: 8 });
        score -= 8;
    }

    if ((hasImage.value && normalizedText.value.length > 0 && normalizedText.value.length < 20)
        || (!hasImage.value && /(photo|image|visuel|look|voir en image)/i.test(normalizedText.value))) {
        issues.push({ key: 'image_text_gap', level: 'notice', points: 7 });
        score -= 7;
    }

    score = Math.max(0, Math.min(100, score));

    return {
        score,
        issues,
        status: score >= 85 ? 'good' : (score >= 65 ? 'warning' : 'attention'),
    };
});

const statusClass = computed(() => ({
    good: 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300',
    warning: 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-300',
    attention: 'border-rose-200 bg-rose-50 text-rose-700 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-300',
}[quality.value.status]));

const issueClass = (level) => ({
    attention: 'border-rose-200 bg-rose-50 text-rose-700 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-300',
    warning: 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-300',
    notice: 'border-stone-200 bg-stone-50 text-stone-600 dark:border-neutral-700 dark:bg-neutral-800/70 dark:text-neutral-300',
}[level] || 'border-stone-200 bg-stone-50 text-stone-600 dark:border-neutral-700 dark:bg-neutral-800/70 dark:text-neutral-300');
</script>

<template>
    <section class="rounded-md border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <div class="flex items-start justify-between gap-3">
            <div>
                <h4 class="text-sm font-semibold text-stone-900 dark:text-neutral-100">
                    {{ t('social.quality.title') }}
                </h4>
                <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                    {{ t('social.quality.subtitle') }}
                </p>
            </div>

            <span class="inline-flex shrink-0 items-center rounded-md border px-2.5 py-1 text-xs font-semibold" :class="statusClass">
                {{ quality.score }}/100
            </span>
        </div>

        <div v-if="quality.issues.length" class="mt-3 flex flex-wrap gap-2">
            <span
                v-for="issue in quality.issues"
                :key="issue.key"
                class="inline-flex items-center rounded-md border px-2.5 py-1 text-xs font-medium"
                :class="issueClass(issue.level)"
            >
                {{ t(`social.quality.issues.${issue.key}`) }}
            </span>
        </div>

        <div v-else class="mt-3 text-xs text-stone-500 dark:text-neutral-400">
            {{ t('social.quality.no_issues') }}
        </div>
    </section>
</template>
