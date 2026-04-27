<script setup>
import { computed, ref } from 'vue';
import axios from 'axios';
import { useI18n } from 'vue-i18n';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import FloatingTextarea from '@/Components/FloatingTextarea.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';

const props = defineProps({
    initialBrandVoice: {
        type: Object,
        default: () => ({}),
    },
    initialToneOptions: {
        type: Array,
        default: () => ([]),
    },
    initialLanguageOptions: {
        type: Array,
        default: () => ([]),
    },
    initialAccess: {
        type: Object,
        default: () => ({}),
    },
});

const { t } = useI18n();

const normalizeList = (payload) => (Array.isArray(payload)
    ? payload.map((item) => String(item || '').trim()).filter(Boolean)
    : []
);
const normalizeBrandVoice = (payload = {}) => ({
    tone: String(payload?.tone || 'professional'),
    language: String(payload?.language || 'fr'),
    style_notes: String(payload?.style_notes || ''),
    words_to_avoid: normalizeList(payload?.words_to_avoid),
    preferred_hashtags: normalizeList(payload?.preferred_hashtags),
    preferred_ctas: normalizeList(payload?.preferred_ctas),
    sample_phrase: String(payload?.sample_phrase || ''),
    is_configured: Boolean(payload?.is_configured),
});
const normalizeAccess = (payload = {}) => ({
    can_view: Boolean(payload?.can_view),
    can_manage_posts: Boolean(payload?.can_manage_posts),
});
const normalizeOptions = (payload) => Array.isArray(payload) ? payload : [];
const listToText = (items) => normalizeList(items).join('\n');
const textToList = (value) => String(value || '')
    .split(/\r\n|\r|\n|,/)
    .map((item) => item.trim())
    .filter(Boolean);

const brandVoice = ref(normalizeBrandVoice(props.initialBrandVoice));
const toneOptions = ref(normalizeOptions(props.initialToneOptions));
const languageOptions = ref(normalizeOptions(props.initialLanguageOptions));
const access = ref(normalizeAccess(props.initialAccess));
const busy = ref(false);
const isLoading = ref(false);
const error = ref('');
const info = ref('');
const form = ref({
    tone: brandVoice.value.tone,
    language: brandVoice.value.language,
    style_notes: brandVoice.value.style_notes,
    words_to_avoid: listToText(brandVoice.value.words_to_avoid),
    preferred_hashtags: listToText(brandVoice.value.preferred_hashtags),
    preferred_ctas: listToText(brandVoice.value.preferred_ctas),
    sample_phrase: brandVoice.value.sample_phrase,
});

const canManage = computed(() => Boolean(access.value.can_manage_posts));
const selectedToneLabel = computed(() => t(`social.brand_voice_manager.tones.${form.value.tone || 'professional'}`));
const toneSelectOptions = computed(() => toneOptions.value.map((option) => ({
    ...option,
    label: t(`social.brand_voice_manager.tones.${option.value}`),
})));
const selectedLanguageLabel = computed(() => {
    const selected = languageOptions.value.find((option) => String(option.value) === String(form.value.language));

    return String(selected?.label || form.value.language || '');
});
const formHashtags = computed(() => textToList(form.value.preferred_hashtags));
const formCtas = computed(() => textToList(form.value.preferred_ctas));
const formAvoids = computed(() => textToList(form.value.words_to_avoid));
const hasVisiblePreview = computed(() => (
    String(form.value.style_notes || '').trim() !== ''
    || String(form.value.sample_phrase || '').trim() !== ''
    || formHashtags.value.length > 0
    || formCtas.value.length > 0
    || formAvoids.value.length > 0
));

const requestErrorMessage = (requestError, fallback) => {
    const validationMessage = Object.values(requestError?.response?.data?.errors || {})
        .flat()
        .find((value) => typeof value === 'string' && value.trim() !== '');

    return validationMessage
        || requestError?.response?.data?.message
        || requestError?.message
        || fallback;
};

const hydrateForm = (payload) => {
    const next = normalizeBrandVoice(payload);
    brandVoice.value = next;
    form.value = {
        tone: next.tone,
        language: next.language,
        style_notes: next.style_notes,
        words_to_avoid: listToText(next.words_to_avoid),
        preferred_hashtags: listToText(next.preferred_hashtags),
        preferred_ctas: listToText(next.preferred_ctas),
        sample_phrase: next.sample_phrase,
    };
};

const refreshFromPayload = (payload) => {
    if (payload?.brand_voice) {
        hydrateForm(payload.brand_voice);
    }

    if (Array.isArray(payload?.tone_options)) {
        toneOptions.value = normalizeOptions(payload.tone_options);
    }

    if (Array.isArray(payload?.language_options)) {
        languageOptions.value = normalizeOptions(payload.language_options);
    }

    if (payload?.access) {
        access.value = normalizeAccess(payload.access);
    }
};

const load = async () => {
    isLoading.value = true;
    error.value = '';

    try {
        const response = await axios.get(route('social.brand-voice'));
        refreshFromPayload(response.data);
    } catch (requestError) {
        error.value = requestErrorMessage(requestError, t('social.brand_voice_manager.messages.load_error'));
    } finally {
        isLoading.value = false;
    }
};

const save = async () => {
    if (!canManage.value) {
        return;
    }

    busy.value = true;
    error.value = '';
    info.value = '';

    try {
        const response = await axios.put(route('social.brand-voice.update'), {
            tone: String(form.value.tone || 'professional'),
            language: String(form.value.language || 'fr'),
            style_notes: String(form.value.style_notes || '').trim(),
            words_to_avoid: formAvoids.value,
            preferred_hashtags: formHashtags.value,
            preferred_ctas: formCtas.value,
            sample_phrase: String(form.value.sample_phrase || '').trim(),
        });

        refreshFromPayload(response.data);
        info.value = String(response.data?.message || t('social.brand_voice_manager.messages.save_success'));
    } catch (requestError) {
        error.value = requestErrorMessage(requestError, t('social.brand_voice_manager.messages.save_error'));
    } finally {
        busy.value = false;
    }
};
</script>

<template>
    <div class="space-y-5">
        <div class="flex flex-wrap justify-end gap-2">
            <SecondaryButton :disabled="busy || isLoading" @click="load">
                {{ t('social.brand_voice_manager.actions.reload') }}
            </SecondaryButton>
        </div>

        <div
            v-if="!canManage"
            class="rounded-md border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-700 dark:border-sky-500/20 dark:bg-sky-500/10 dark:text-sky-300"
        >
            <div class="font-semibold">{{ t('social.brand_voice_manager.read_only_title') }}</div>
            <div class="mt-1">{{ t('social.brand_voice_manager.read_only_description') }}</div>
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

        <div class="grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,1.1fr)_minmax(280px,0.9fr)]">
            <form class="rounded-md border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900" @submit.prevent="save">
                <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                    <FloatingSelect
                        v-model="form.tone"
                        :label="t('social.brand_voice_manager.fields.tone')"
                        :options="toneSelectOptions"
                        :disabled="busy || !canManage"
                    />

                    <FloatingSelect
                        v-model="form.language"
                        :label="t('social.brand_voice_manager.fields.language')"
                        :options="languageOptions"
                        :disabled="busy || !canManage"
                    />
                </div>

                <div class="mt-3 space-y-3">
                    <FloatingTextarea
                        v-model="form.style_notes"
                        :label="t('social.brand_voice_manager.fields.style_notes')"
                        :disabled="busy || !canManage"
                    />
                    <FloatingTextarea
                        v-model="form.words_to_avoid"
                        :label="t('social.brand_voice_manager.fields.words_to_avoid')"
                        :disabled="busy || !canManage"
                    />
                    <FloatingTextarea
                        v-model="form.preferred_hashtags"
                        :label="t('social.brand_voice_manager.fields.preferred_hashtags')"
                        :disabled="busy || !canManage"
                    />
                    <FloatingTextarea
                        v-model="form.preferred_ctas"
                        :label="t('social.brand_voice_manager.fields.preferred_ctas')"
                        :disabled="busy || !canManage"
                    />
                    <FloatingInput
                        v-model="form.sample_phrase"
                        :label="t('social.brand_voice_manager.fields.sample_phrase')"
                        :disabled="busy || !canManage"
                    />
                </div>

                <div class="mt-4 flex justify-end">
                    <PrimaryButton :disabled="busy || !canManage">
                        {{ busy ? t('social.brand_voice_manager.actions.saving') : t('social.brand_voice_manager.actions.save') }}
                    </PrimaryButton>
                </div>
            </form>

            <section class="rounded-md border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 class="text-base font-semibold text-stone-900 dark:text-neutral-100">
                        {{ t('social.brand_voice_manager.preview_title') }}
                    </h2>
                    <span class="rounded-md border border-stone-200 px-2.5 py-1 text-xs font-medium text-stone-600 dark:border-neutral-700 dark:text-neutral-300">
                        {{ hasVisiblePreview ? t('social.brand_voice_manager.status.configured') : t('social.brand_voice_manager.status.default') }}
                    </span>
                </div>

                <div class="mt-4 flex flex-wrap gap-2">
                    <span class="rounded-md bg-sky-50 px-2.5 py-1 text-xs font-medium text-sky-700 dark:bg-sky-500/10 dark:text-sky-200">
                        {{ selectedToneLabel }}
                    </span>
                    <span class="rounded-md bg-stone-100 px-2.5 py-1 text-xs font-medium text-stone-700 dark:bg-neutral-800 dark:text-neutral-200">
                        {{ selectedLanguageLabel }}
                    </span>
                </div>

                <p
                    v-if="form.style_notes.trim()"
                    class="mt-4 whitespace-pre-line text-sm leading-6 text-stone-700 dark:text-neutral-200"
                >
                    {{ form.style_notes.trim() }}
                </p>

                <div v-if="form.sample_phrase.trim()" class="mt-4 border-l-2 border-sky-400 pl-3 text-sm text-stone-700 dark:text-neutral-200">
                    {{ form.sample_phrase.trim() }}
                </div>

                <div v-if="formHashtags.length" class="mt-4">
                    <div class="text-xs font-semibold uppercase tracking-[0.14em] text-stone-400 dark:text-neutral-500">
                        {{ t('social.brand_voice_manager.preview_hashtags') }}
                    </div>
                    <div class="mt-2 flex flex-wrap gap-2">
                        <span
                            v-for="hashtag in formHashtags"
                            :key="hashtag"
                            class="rounded-md bg-stone-100 px-2.5 py-1 text-xs font-medium text-stone-700 dark:bg-neutral-800 dark:text-neutral-200"
                        >
                            {{ hashtag }}
                        </span>
                    </div>
                </div>

                <div v-if="formCtas.length" class="mt-4">
                    <div class="text-xs font-semibold uppercase tracking-[0.14em] text-stone-400 dark:text-neutral-500">
                        {{ t('social.brand_voice_manager.preview_ctas') }}
                    </div>
                    <div class="mt-2 space-y-2">
                        <div
                            v-for="cta in formCtas"
                            :key="cta"
                            class="rounded-md border border-stone-200 px-3 py-2 text-sm text-stone-700 dark:border-neutral-700 dark:text-neutral-200"
                        >
                            {{ cta }}
                        </div>
                    </div>
                </div>

                <div v-if="formAvoids.length" class="mt-4">
                    <div class="text-xs font-semibold uppercase tracking-[0.14em] text-stone-400 dark:text-neutral-500">
                        {{ t('social.brand_voice_manager.preview_avoid') }}
                    </div>
                    <div class="mt-2 flex flex-wrap gap-2">
                        <span
                            v-for="word in formAvoids"
                            :key="word"
                            class="rounded-md bg-rose-50 px-2.5 py-1 text-xs font-medium text-rose-700 dark:bg-rose-500/10 dark:text-rose-200"
                        >
                            {{ word }}
                        </span>
                    </div>
                </div>

                <div
                    v-if="!hasVisiblePreview"
                    class="mt-4 rounded-md border border-dashed border-stone-300 bg-stone-50 px-4 py-5 text-sm text-stone-500 dark:border-neutral-700 dark:bg-neutral-800/60 dark:text-neutral-400"
                >
                    {{ t('social.brand_voice_manager.empty_preview') }}
                </div>
            </section>
        </div>
    </div>
</template>
