<script setup>
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import SettingsLayout from '@/Layouts/SettingsLayout.vue';
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
    { key: 'allow_create_prospect', label: 'Creer un prospect' },
    { key: 'allow_create_client', label: 'Creer un client' },
    { key: 'allow_create_reservation', label: 'Preparer une reservation' },
    { key: 'allow_reschedule_reservation', label: 'Preparer une replanification' },
    { key: 'allow_create_task', label: 'Creer une tache' },
];

const proactiveToggles = [
    {
        key: 'enable_proactive_suggestions',
        label: 'Suggestions proactives',
        help: 'L assistant propose le prochain pas utile selon le contexte.',
    },
    {
        key: 'allow_ai_to_choose_earliest_slot',
        label: 'Choisir le premier creneau',
        help: 'Utilise le premier creneau disponible quand le client est flexible.',
    },
    {
        key: 'allow_ai_to_recommend_services',
        label: 'Recommander des services',
        help: 'Guide le client quand il decrit un besoin sans connaitre le service.',
    },
    {
        key: 'allow_ai_to_recommend_staff',
        label: 'Recommander un membre',
        help: 'Suggere un membre seulement quand plusieurs options existent.',
    },
    {
        key: 'enable_client_history_recommendations',
        label: 'Utiliser l historique client',
        help: 'Peut proposer le meme service que le dernier rendez-vous.',
    },
    {
        key: 'require_confirmation_before_ai_action',
        label: 'Confirmer avant action IA',
        help: 'Demande une confirmation avant les actions sensibles.',
    },
    {
        key: 'enable_upsell_suggestions',
        label: 'Suggestions complementaires',
        help: 'Optionnel et desactive par defaut pour eviter un ton trop commercial.',
    },
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
    enable_proactive_suggestions: Boolean(props.setting.enable_proactive_suggestions ?? true),
    enable_upsell_suggestions: Boolean(props.setting.enable_upsell_suggestions ?? false),
    enable_client_history_recommendations: Boolean(props.setting.enable_client_history_recommendations ?? false),
    max_suggestions_per_response: Number(props.setting.max_suggestions_per_response ?? 3),
    require_confirmation_before_ai_action: Boolean(props.setting.require_confirmation_before_ai_action ?? true),
    allow_ai_to_choose_earliest_slot: Boolean(props.setting.allow_ai_to_choose_earliest_slot ?? true),
    allow_ai_to_recommend_staff: Boolean(props.setting.allow_ai_to_recommend_staff ?? true),
    allow_ai_to_recommend_services: Boolean(props.setting.allow_ai_to_recommend_services ?? true),
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
const validationModeLabel = computed(() => (
    form.require_human_validation ? 'Validation humaine' : 'Execution automatique'
));
const enabledActionsCount = computed(() => actionToggles
    .filter((toggle) => Boolean(form[toggle.key]))
    .length);
const languageSummary = computed(() => form.supported_languages
    .map((language) => languageLabels[language] || language)
    .join(', '));

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
            max_suggestions_per_response: Number(data.max_suggestions_per_response || 3),
        }))
        .put(route('admin.ai-assistant.settings.update'), {
            preserveScroll: true,
        });
};
</script>

<template>
    <Head title="Assistant IA" />

    <SettingsLayout active="assistant" content-class="w-[1400px] max-w-full">
        <div class="space-y-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">
                        Reglages Assistant IA
                    </h1>
                    <p class="mt-1 text-sm text-stone-600 dark:text-neutral-400">
                        Configurez le comportement de l assistant et le niveau de validation.
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Link
                        :href="route('admin.ai-assistant.conversations.index', { queue: 'review' })"
                        class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                    >
                        Inbox IA
                    </Link>
                    <Link
                        :href="route('admin.ai-assistant.knowledge.index')"
                        class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                    >
                        Base de connaissance
                    </Link>
                    <button
                        type="button"
                        class="rounded-sm bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-50"
                        :disabled="form.processing"
                        @click="submit"
                    >
                        {{ form.processing ? 'Enregistrement...' : 'Enregistrer' }}
                    </button>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-2 md:grid-cols-4 md:gap-3 lg:gap-5">
                <div class="rounded-sm border border-stone-200 border-t-4 border-t-emerald-500 bg-white p-4 shadow-sm dark:border-neutral-700 dark:border-t-emerald-500 dark:bg-neutral-900">
                    <div class="text-xs text-stone-500 dark:text-neutral-400">Statut</div>
                    <div class="mt-1 text-lg font-semibold text-stone-800 dark:text-neutral-100">{{ enabledLabel }}</div>
                </div>
                <div class="rounded-sm border border-stone-200 border-t-4 border-t-sky-500 bg-white p-4 shadow-sm dark:border-neutral-700 dark:border-t-sky-500 dark:bg-neutral-900">
                    <div class="text-xs text-stone-500 dark:text-neutral-400">Mode</div>
                    <div class="mt-1 text-lg font-semibold text-stone-800 dark:text-neutral-100">{{ validationModeLabel }}</div>
                </div>
                <div class="rounded-sm border border-stone-200 border-t-4 border-t-violet-500 bg-white p-4 shadow-sm dark:border-neutral-700 dark:border-t-violet-500 dark:bg-neutral-900">
                    <div class="text-xs text-stone-500 dark:text-neutral-400">Langues</div>
                    <div class="mt-1 truncate text-lg font-semibold text-stone-800 dark:text-neutral-100">{{ languageSummary }}</div>
                </div>
                <div class="rounded-sm border border-stone-200 border-t-4 border-t-amber-500 bg-white p-4 shadow-sm dark:border-neutral-700 dark:border-t-amber-500 dark:bg-neutral-900">
                    <div class="text-xs text-stone-500 dark:text-neutral-400">Actions</div>
                    <div class="mt-1 text-lg font-semibold text-stone-800 dark:text-neutral-100">{{ enabledActionsCount }} actives</div>
                </div>
            </div>

            <form class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_360px]" @submit.prevent="submit">
                <div class="space-y-4">
                    <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <h2 class="mb-3 text-sm font-semibold text-stone-800 dark:text-neutral-100">Identite</h2>
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
                        <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">Validation</h2>
                        <label class="mt-3 flex items-start gap-3 rounded-sm border border-stone-200 px-3 py-3 text-sm text-stone-700 dark:border-neutral-700 dark:text-neutral-200">
                            <input
                                v-model="form.require_human_validation"
                                type="checkbox"
                                class="mt-0.5 rounded border-stone-300 text-emerald-600 focus:ring-emerald-600"
                            />
                            <span>
                                <span class="block font-semibold">Valider avant execution</span>
                                <span class="mt-1 block text-xs text-stone-500 dark:text-neutral-400">
                                    Les reservations preparees restent dans l inbox jusqu a approbation.
                                </span>
                            </span>
                        </label>
                        <InputError class="mt-2" :message="form.errors.require_human_validation" />
                    </section>

                    <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">Intelligence proactive</h2>
                        <div class="mt-3 grid gap-2">
                            <label
                                v-for="toggle in proactiveToggles"
                                :key="toggle.key"
                                class="flex items-start gap-3 rounded-sm border border-stone-200 px-3 py-2 text-sm text-stone-700 dark:border-neutral-700 dark:text-neutral-200"
                            >
                                <input
                                    v-model="form[toggle.key]"
                                    type="checkbox"
                                    class="mt-0.5 rounded border-stone-300 text-emerald-600 focus:ring-emerald-600"
                                />
                                <span>
                                    <span class="block font-semibold">{{ toggle.label }}</span>
                                    <span class="mt-1 block text-xs text-stone-500 dark:text-neutral-400">{{ toggle.help }}</span>
                                </span>
                            </label>
                        </div>
                        <div class="mt-3">
                            <label class="block text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                Suggestions max / reponse
                            </label>
                            <input
                                v-model.number="form.max_suggestions_per_response"
                                type="number"
                                min="1"
                                max="5"
                                class="mt-1 w-full rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm text-stone-800 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500 dark:border-neutral-700 dark:bg-neutral-950 dark:text-neutral-100"
                            />
                        </div>
                        <div class="mt-2 grid gap-1">
                            <InputError
                                v-for="toggle in proactiveToggles"
                                :key="`${toggle.key}-error`"
                                :message="form.errors[toggle.key]"
                            />
                            <InputError :message="form.errors.max_suggestions_per_response" />
                        </div>
                    </section>

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
    </SettingsLayout>
</template>
