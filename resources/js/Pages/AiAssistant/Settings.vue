<script setup>
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import FloatingTextarea from '@/Components/FloatingTextarea.vue';
import InputError from '@/Components/InputError.vue';

const props = defineProps({
    setting: {
        type: Object,
        required: true,
    },
    options: {
        type: Object,
        default: () => ({
            tones: [],
            languages: [],
        }),
    },
});

const toneLabels = {
    professional: 'Professional',
    warm: 'Warm',
    friendly: 'Friendly',
    premium: 'Premium',
    direct: 'Direct',
};

const languageLabels = {
    fr: 'Francais',
    en: 'English',
};

const actionToggles = [
    { key: 'allow_create_prospect', label: 'Creation prospect' },
    { key: 'allow_create_client', label: 'Creation client' },
    { key: 'allow_create_reservation', label: 'Creation reservation' },
    { key: 'allow_reschedule_reservation', label: 'Replanification' },
    { key: 'allow_create_task', label: 'Creation tache' },
    { key: 'require_human_validation', label: 'Validation humaine obligatoire' },
];

const form = useForm({
    assistant_name: props.setting.assistant_name || 'Malikia AI Assistant',
    enabled: Boolean(props.setting.enabled),
    default_language: props.setting.default_language || 'fr',
    supported_languages: Array.isArray(props.setting.supported_languages)
        ? [...props.setting.supported_languages]
        : ['fr', 'en'],
    tone: props.setting.tone || 'warm',
    greeting_message: props.setting.greeting_message || '',
    fallback_message: props.setting.fallback_message || '',
    allow_create_prospect: Boolean(props.setting.allow_create_prospect),
    allow_create_client: Boolean(props.setting.allow_create_client),
    allow_create_reservation: Boolean(props.setting.allow_create_reservation),
    allow_reschedule_reservation: Boolean(props.setting.allow_reschedule_reservation),
    allow_create_task: Boolean(props.setting.allow_create_task),
    require_human_validation: Boolean(props.setting.require_human_validation),
    business_context: props.setting.business_context || '',
    service_area_rules: props.setting.service_area_rules || null,
    working_hours_rules: props.setting.working_hours_rules || null,
});

const toneOptions = computed(() => (props.options.tones || []).map((tone) => ({
    id: tone,
    name: toneLabels[tone] || tone,
})));

const languageOptions = computed(() => (props.options.languages || []).map((language) => ({
    id: language,
    name: languageLabels[language] || language,
})));

const enabledLabel = computed(() => (form.enabled ? 'Actif' : 'Inactif'));

const isLanguageSelected = (language) => form.supported_languages.includes(language);

const toggleLanguage = (language) => {
    const languages = new Set(form.supported_languages);
    if (languages.has(language)) {
        languages.delete(language);
    } else {
        languages.add(language);
    }

    form.supported_languages = Array.from(languages);
    if (!form.supported_languages.includes(form.default_language) && form.supported_languages.length) {
        form.default_language = form.supported_languages[0];
    }
};

const nullableText = (value) => {
    const normalized = String(value || '').trim();

    return normalized === '' ? null : normalized;
};

const submit = () => {
    form
        .transform((data) => ({
            ...data,
            greeting_message: nullableText(data.greeting_message),
            fallback_message: nullableText(data.fallback_message),
            business_context: nullableText(data.business_context),
            service_area_rules: data.service_area_rules || null,
            working_hours_rules: data.working_hours_rules || null,
        }))
        .put(route('admin.ai-assistant.settings.update'), {
            preserveScroll: true,
        });
};
</script>

<template>
    <Head title="Assistant IA" />

    <AuthenticatedLayout>
        <div class="mx-auto flex w-[1200px] max-w-full flex-col gap-4">
            <header class="flex flex-wrap items-center justify-between gap-3 rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-emerald-700 dark:text-emerald-300">
                        Malikia AI Assistant
                    </p>
                    <h1 class="mt-1 text-xl font-semibold text-stone-900 dark:text-neutral-50">
                        Assistant IA
                    </h1>
                    <p class="mt-1 text-sm text-stone-500 dark:text-neutral-400">
                        Statut: {{ enabledLabel }}
                    </p>
                </div>
                <button
                    type="button"
                    class="rounded-sm bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-50"
                    :disabled="form.processing"
                    @click="submit"
                >
                    {{ form.processing ? 'Enregistrement...' : 'Enregistrer' }}
                </button>
            </header>

            <form class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_360px]" @submit.prevent="submit">
                <div class="space-y-4">
                    <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <div class="grid gap-4 md:grid-cols-2">
                            <FloatingInput v-model="form.assistant_name" label="Nom assistant" required />
                            <FloatingSelect v-model="form.tone" :options="toneOptions" label="Ton" required />
                            <FloatingSelect v-model="form.default_language" :options="languageOptions" label="Langue par defaut" required />
                            <label class="flex items-center gap-3 rounded-sm border border-stone-200 px-3 py-3 text-sm text-stone-700 dark:border-neutral-700 dark:text-neutral-200">
                                <input v-model="form.enabled" type="checkbox" class="rounded border-stone-300 text-emerald-600 focus:ring-emerald-600" />
                                <span>Assistant active</span>
                            </label>
                        </div>
                        <div class="mt-2 grid gap-2">
                            <InputError :message="form.errors.assistant_name" />
                            <InputError :message="form.errors.tone" />
                            <InputError :message="form.errors.default_language" />
                            <InputError :message="form.errors.enabled" />
                        </div>
                    </section>

                    <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">Messages</h2>
                        <div class="mt-3 grid gap-4">
                            <FloatingTextarea v-model="form.greeting_message" label="Message d'accueil" />
                            <InputError :message="form.errors.greeting_message" />
                            <FloatingTextarea v-model="form.fallback_message" label="Message fallback" />
                            <InputError :message="form.errors.fallback_message" />
                            <FloatingTextarea v-model="form.business_context" label="Contexte business" />
                            <InputError :message="form.errors.business_context" />
                        </div>
                    </section>
                </div>

                <aside class="space-y-4">
                    <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">Langues</h2>
                        <div class="mt-3 grid gap-2">
                            <label
                                v-for="language in options.languages"
                                :key="language"
                                class="flex items-center gap-3 rounded-sm border border-stone-200 px-3 py-2 text-sm text-stone-700 dark:border-neutral-700 dark:text-neutral-200"
                            >
                                <input
                                    type="checkbox"
                                    class="rounded border-stone-300 text-emerald-600 focus:ring-emerald-600"
                                    :checked="isLanguageSelected(language)"
                                    @change="toggleLanguage(language)"
                                />
                                <span>{{ languageLabels[language] || language }}</span>
                            </label>
                        </div>
                        <InputError class="mt-2" :message="form.errors.supported_languages" />
                        <InputError class="mt-2" :message="form.errors['supported_languages.0']" />
                    </section>

                    <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">Actions autorisees</h2>
                        <div class="mt-3 grid gap-2">
                            <label
                                v-for="toggle in actionToggles"
                                :key="toggle.key"
                                class="flex items-center gap-3 rounded-sm border border-stone-200 px-3 py-2 text-sm text-stone-700 dark:border-neutral-700 dark:text-neutral-200"
                            >
                                <input
                                    v-model="form[toggle.key]"
                                    type="checkbox"
                                    class="rounded border-stone-300 text-emerald-600 focus:ring-emerald-600"
                                />
                                <span>{{ toggle.label }}</span>
                            </label>
                        </div>
                        <div class="mt-2 grid gap-1">
                            <InputError
                                v-for="toggle in actionToggles"
                                :key="`${toggle.key}-error`"
                                :message="form.errors[toggle.key]"
                            />
                        </div>
                    </section>
                </aside>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
