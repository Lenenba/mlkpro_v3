<script setup>
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingTextarea from '@/Components/FloatingTextarea.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    initialRules: {
        type: Array,
        default: () => ([]),
    },
    initialSummary: {
        type: Object,
        default: () => ({}),
    },
    initialRecentRuns: {
        type: Array,
        default: () => ([]),
    },
    initialContentSourceCatalog: {
        type: Array,
        default: () => ([]),
    },
    initialTargetConnections: {
        type: Array,
        default: () => ([]),
    },
    initialFrequencyOptions: {
        type: Array,
        default: () => ([]),
    },
    initialApprovalModeOptions: {
        type: Array,
        default: () => ([]),
    },
    initialGenerationToneOptions: {
        type: Array,
        default: () => ([]),
    },
    initialGenerationGoalOptions: {
        type: Array,
        default: () => ([]),
    },
    initialImageModeOptions: {
        type: Array,
        default: () => ([]),
    },
    initialImageFormatOptions: {
        type: Array,
        default: () => ([]),
    },
    initialLocaleOptions: {
        type: Array,
        default: () => ([]),
    },
    initialTimezoneOptions: {
        type: Array,
        default: () => ([]),
    },
    initialAccess: {
        type: Object,
        default: () => ({}),
    },
});

const { t } = useI18n();

const normalizeRules = (payload) => Array.isArray(payload) ? payload : [];
const normalizeSummary = (payload) => payload && typeof payload === 'object' ? payload : {};
const normalizeRuns = (payload) => Array.isArray(payload) ? payload : [];
const normalizeAccess = (payload) => ({
    can_view: Boolean(payload?.can_view),
    can_manage_posts: Boolean(payload?.can_manage_posts),
    can_manage_automations: Boolean(payload?.can_manage_automations),
    can_publish: Boolean(payload?.can_publish),
    can_submit_for_approval: Boolean(payload?.can_submit_for_approval),
    can_approve: Boolean(payload?.can_approve),
});
const normalizeCatalog = (payload) => Array.isArray(payload) ? payload : [];
const normalizeConnections = (payload) => Array.isArray(payload) ? payload : [];
const normalizeOptions = (payload) => Array.isArray(payload) ? payload : [];
const defaultGenerationSettings = () => ({
    text_ai_enabled: false,
    image_ai_enabled: false,
    creative_prompt: '',
    image_prompt: '',
    tone: 'professional',
    goal: 'inform',
    image_mode: 'if_missing',
    image_format: 'square',
    variant_count: 3,
});

const normalizeGenerationSettings = (payload = {}) => {
    const settings = payload && typeof payload === 'object' ? payload : {};
    const defaults = defaultGenerationSettings();

    return {
        text_ai_enabled: Boolean(settings.text_ai_enabled ?? defaults.text_ai_enabled),
        image_ai_enabled: Boolean(settings.image_ai_enabled ?? defaults.image_ai_enabled),
        creative_prompt: String(settings.creative_prompt || '').trim(),
        image_prompt: String(settings.image_prompt || '').trim(),
        tone: String(settings.tone || defaults.tone),
        goal: String(settings.goal || defaults.goal),
        image_mode: String(settings.image_mode || defaults.image_mode),
        image_format: String(settings.image_format || defaults.image_format),
        variant_count: Math.max(1, Math.min(5, Number(settings.variant_count || defaults.variant_count))),
    };
};

const buildSourceConfig = (catalog, contentSources = []) => {
    const config = Object.fromEntries(
        normalizeCatalog(catalog).map((entry) => [
            String(entry.type || ''),
            {
                enabled: false,
                mode: 'all',
                ids: [],
            },
        ])
    );

    (Array.isArray(contentSources) ? contentSources : []).forEach((entry) => {
        const type = String(entry?.type || '');
        if (!config[type]) {
            return;
        }

        config[type] = {
            enabled: true,
            mode: String(entry?.mode || 'all'),
            ids: Array.isArray(entry?.ids) ? entry.ids.map((id) => Number(id)).filter((id) => id > 0) : [],
        };
    });

    return config;
};

const defaultTimezone = computed(() => String(normalizeOptions(props.initialTimezoneOptions)[0]?.value || 'UTC'));
const defaultLocale = computed(() => String(normalizeOptions(props.initialLocaleOptions)[0]?.value || 'fr'));

const buildForm = () => ({
    name: '',
    description: '',
    is_active: true,
    frequency_type: 'daily',
    frequency_interval: 1,
    scheduled_time: '09:00',
    timezone: defaultTimezone.value,
    approval_mode: 'required',
    language: defaultLocale.value,
    target_connection_ids: [],
    source_config: buildSourceConfig(props.initialContentSourceCatalog),
    max_posts_per_day: 1,
    min_hours_between_similar_posts: 24,
    generation_settings: defaultGenerationSettings(),
});

const rules = ref(normalizeRules(props.initialRules));
const summary = ref(normalizeSummary(props.initialSummary));
const recentRuns = ref(normalizeRuns(props.initialRecentRuns));
const contentSourceCatalog = ref(normalizeCatalog(props.initialContentSourceCatalog));
const targetConnections = ref(normalizeConnections(props.initialTargetConnections));
const frequencyOptions = ref(normalizeOptions(props.initialFrequencyOptions));
const approvalModeOptions = ref(normalizeOptions(props.initialApprovalModeOptions));
const generationToneOptions = ref(normalizeOptions(props.initialGenerationToneOptions));
const generationGoalOptions = ref(normalizeOptions(props.initialGenerationGoalOptions));
const imageModeOptions = ref(normalizeOptions(props.initialImageModeOptions));
const imageFormatOptions = ref(normalizeOptions(props.initialImageFormatOptions));
const localeOptions = ref(normalizeOptions(props.initialLocaleOptions));
const timezoneOptions = ref(normalizeOptions(props.initialTimezoneOptions));
const access = ref(normalizeAccess(props.initialAccess));
const busy = ref(false);
const isLoading = ref(false);
const error = ref('');
const info = ref('');
const activeRuleId = ref(null);
const form = ref(buildForm());

const canManage = computed(() => Boolean(access.value.can_manage_automations));
const sortedRules = computed(() => [...rules.value].sort((left, right) => {
    if (Boolean(left?.is_active) !== Boolean(right?.is_active)) {
        return left?.is_active ? -1 : 1;
    }

    const leftDate = Date.parse(String(left?.next_generation_at || left?.updated_at || '')) || 0;
    const rightDate = Date.parse(String(right?.next_generation_at || right?.updated_at || '')) || 0;

    return rightDate - leftDate;
}));
const activeRule = computed(() => (
    sortedRules.value.find((rule) => Number(rule.id) === Number(activeRuleId.value)) || null
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

const refreshFromPayload = (payload) => {
    if (Array.isArray(payload?.rules)) {
        rules.value = normalizeRules(payload.rules);
    }

    if (payload?.summary) {
        summary.value = normalizeSummary(payload.summary);
    }

    if (Array.isArray(payload?.recent_runs)) {
        recentRuns.value = normalizeRuns(payload.recent_runs);
    }

    if (Array.isArray(payload?.content_source_catalog)) {
        contentSourceCatalog.value = normalizeCatalog(payload.content_source_catalog);
    }

    if (Array.isArray(payload?.target_connections)) {
        targetConnections.value = normalizeConnections(payload.target_connections);
    }

    if (Array.isArray(payload?.frequency_options)) {
        frequencyOptions.value = normalizeOptions(payload.frequency_options);
    }

    if (Array.isArray(payload?.approval_mode_options)) {
        approvalModeOptions.value = normalizeOptions(payload.approval_mode_options);
    }

    if (Array.isArray(payload?.generation_tone_options)) {
        generationToneOptions.value = normalizeOptions(payload.generation_tone_options);
    }

    if (Array.isArray(payload?.generation_goal_options)) {
        generationGoalOptions.value = normalizeOptions(payload.generation_goal_options);
    }

    if (Array.isArray(payload?.image_mode_options)) {
        imageModeOptions.value = normalizeOptions(payload.image_mode_options);
    }

    if (Array.isArray(payload?.image_format_options)) {
        imageFormatOptions.value = normalizeOptions(payload.image_format_options);
    }

    if (Array.isArray(payload?.locale_options)) {
        localeOptions.value = normalizeOptions(payload.locale_options);
    }

    if (Array.isArray(payload?.timezone_options)) {
        timezoneOptions.value = normalizeOptions(payload.timezone_options);
    }

    if (payload?.access) {
        access.value = normalizeAccess(payload.access);
    }
};

watch(() => props.initialRules, (value) => {
    rules.value = normalizeRules(value);
}, { deep: true });

watch(() => props.initialSummary, (value) => {
    summary.value = normalizeSummary(value);
}, { deep: true });

watch(() => props.initialRecentRuns, (value) => {
    recentRuns.value = normalizeRuns(value);
}, { deep: true });

watch(() => props.initialAccess, (value) => {
    access.value = normalizeAccess(value);
}, { deep: true });

const frequencyLabel = (value) => t(`social.automation_manager.frequency_options.${value || 'daily'}`);
const approvalModeLabel = (value) => t(`social.automation_manager.approval_mode_options.${value || 'required'}`);
const generationToneLabel = (value) => t(`social.automation_manager.generation_tones.${value || 'professional'}`);
const generationGoalLabel = (value) => t(`social.automation_manager.generation_goals.${value || 'inform'}`);
const imageModeLabel = (value) => t(`social.automation_manager.image_modes.${value || 'if_missing'}`);
const imageFormatLabel = (value) => t(`social.automation_manager.image_formats.${value || 'square'}`);
const contentSourceTypeLabel = (type) => t(`social.automation_manager.source_types.${type || 'template'}`);
const healthStateLabel = (value) => t(`social.automation_manager.health_states.${value || 'healthy'}`);
const runStatusLabel = (value) => t(`social.automation_manager.run_statuses.${value || 'skipped'}`);
const healthReasonLabel = (value) => t(`social.automation_manager.health_reasons.${value || 'last_error'}`);
const outcomeCodeLabel = (value) => t(`social.automation_manager.outcome_codes.${value || 'skipped'}`);

const formatDate = (value) => {
    if (!value) {
        return t('social.automation_manager.empty_value');
    }

    try {
        return new Date(value).toLocaleString();
    } catch {
        return t('social.automation_manager.empty_value');
    }
};

const formatTargetLabel = (connection) => {
    const label = String(connection?.label || '').trim();
    const platform = String(connection?.provider_label || connection?.platform || '').trim();
    const handle = String(connection?.account_handle || '').trim();

    return [label || platform, handle].filter(Boolean).join(' - ');
};

const healthBadgeClass = (rule) => {
    const state = String(rule?.health?.state || 'healthy');

    if (state === 'attention') {
        return 'border-rose-200 bg-rose-50 text-rose-700 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-300';
    }

    if (state === 'warning') {
        return 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-200';
    }

    return 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300';
};

const runBadgeClass = (run) => {
    const status = String(run?.status || 'skipped');

    if (status === 'error') {
        return 'border-rose-200 bg-rose-50 text-rose-700 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-300';
    }

    if (status === 'generated') {
        return 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300';
    }

    return 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-200';
};

const buildContentSourcesPayload = () => contentSourceCatalog.value
    .map((entry) => {
        const current = form.value.source_config[String(entry.type || '')] || {
            enabled: false,
            mode: 'all',
            ids: [],
        };

        if (!current.enabled) {
            return null;
        }

        return {
            type: entry.type,
            mode: current.mode === 'selected_ids' ? 'selected_ids' : 'all',
            ids: current.mode === 'selected_ids'
                ? (Array.isArray(current.ids) ? current.ids.map((id) => Number(id)).filter((id) => id > 0) : [])
                : [],
        };
    })
    .filter(Boolean);

const hydrateForm = (rule = null) => {
    if (!rule) {
        form.value = buildForm();
        activeRuleId.value = null;
        return;
    }

    form.value = {
        name: String(rule.name || ''),
        description: String(rule.description || ''),
        is_active: Boolean(rule.is_active),
        frequency_type: String(rule.frequency_type || 'daily'),
        frequency_interval: Number(rule.frequency_interval || 1),
        scheduled_time: String(rule.scheduled_time || '09:00'),
        timezone: String(rule.timezone || defaultTimezone.value),
        approval_mode: String(rule.approval_mode || 'required'),
        language: String(rule.language || defaultLocale.value),
        target_connection_ids: Array.isArray(rule.target_connection_ids)
            ? rule.target_connection_ids.map((id) => Number(id)).filter((id) => id > 0)
            : [],
        source_config: buildSourceConfig(contentSourceCatalog.value, rule.content_sources),
        max_posts_per_day: Number(rule.max_posts_per_day || 1),
        min_hours_between_similar_posts: Number(rule.min_hours_between_similar_posts || 24),
        generation_settings: normalizeGenerationSettings(rule.generation_settings || rule.metadata?.generation_settings),
    };
    activeRuleId.value = Number(rule.id);
};

const resetForm = () => {
    error.value = '';
    info.value = '';
    hydrateForm(null);
};

const load = async () => {
    isLoading.value = true;
    error.value = '';

    try {
        const response = await axios.get(route('social.automations.index'));
        refreshFromPayload(response.data);
    } catch (requestError) {
        error.value = requestErrorMessage(requestError, t('social.automation_manager.messages.load_error'));
    } finally {
        isLoading.value = false;
    }
};

const upsertRule = (rule) => {
    const next = [...rules.value];
    const index = next.findIndex((entry) => Number(entry.id) === Number(rule.id));

    if (index >= 0) {
        next.splice(index, 1, rule);
    } else {
        next.unshift(rule);
    }

    rules.value = next;
};

const saveRule = async () => {
    if (!canManage.value) {
        return;
    }

    busy.value = true;
    error.value = '';
    info.value = '';

    const payload = {
        name: String(form.value.name || '').trim(),
        description: String(form.value.description || '').trim(),
        is_active: Boolean(form.value.is_active),
        frequency_type: form.value.frequency_type,
        frequency_interval: Number(form.value.frequency_interval || 1),
        scheduled_time: String(form.value.scheduled_time || '').trim(),
        timezone: form.value.timezone,
        approval_mode: form.value.approval_mode,
        language: form.value.language,
        target_connection_ids: Array.isArray(form.value.target_connection_ids)
            ? form.value.target_connection_ids.map((id) => Number(id)).filter((id) => id > 0)
            : [],
        content_sources: buildContentSourcesPayload(),
        max_posts_per_day: Number(form.value.max_posts_per_day || 1),
        min_hours_between_similar_posts: Number(form.value.min_hours_between_similar_posts || 24),
        generation_settings: normalizeGenerationSettings(form.value.generation_settings),
    };

    try {
        const response = activeRuleId.value
            ? await axios.put(route('social.automations.update', activeRuleId.value), payload)
            : await axios.post(route('social.automations.store'), payload);

        if (response.data?.rule) {
            upsertRule(response.data.rule);
        }

        await load();
        info.value = String(response.data?.message || (
            activeRuleId.value
                ? t('social.automation_manager.messages.update_success')
                : t('social.automation_manager.messages.save_success')
        ));
        hydrateForm(response.data?.rule || null);
    } catch (requestError) {
        error.value = requestErrorMessage(requestError, t('social.automation_manager.messages.save_error'));
    } finally {
        busy.value = false;
    }
};

const toggleRule = async (rule) => {
    if (!canManage.value) {
        return;
    }

    busy.value = true;
    error.value = '';
    info.value = '';

    try {
        const response = await axios.post(
            Boolean(rule?.is_active)
                ? route('social.automations.pause', rule.id)
                : route('social.automations.resume', rule.id)
        );

        if (response.data?.rule) {
            upsertRule(response.data.rule);
        }

        await load();
        info.value = String(response.data?.message || (
            Boolean(rule?.is_active)
                ? t('social.automation_manager.messages.pause_success')
                : t('social.automation_manager.messages.resume_success')
        ));
    } catch (requestError) {
        error.value = requestErrorMessage(requestError, t('social.automation_manager.messages.toggle_error'));
    } finally {
        busy.value = false;
    }
};

const removeRule = async (rule) => {
    if (!canManage.value) {
        return;
    }

    const name = String(rule?.name || '').trim() || t('social.automation_manager.untitled_rule');
    if (!window.confirm(t('social.automation_manager.messages.confirm_delete', { name }))) {
        return;
    }

    busy.value = true;
    error.value = '';
    info.value = '';

    try {
        await axios.delete(route('social.automations.destroy', rule.id));
        rules.value = rules.value.filter((entry) => Number(entry.id) !== Number(rule.id));
        await load();
        if (Number(activeRuleId.value) === Number(rule.id)) {
            hydrateForm(null);
        }
        info.value = t('social.automation_manager.messages.delete_success');
    } catch (requestError) {
        error.value = requestErrorMessage(requestError, t('social.automation_manager.messages.delete_error'));
    } finally {
        busy.value = false;
    }
};

const editRule = (rule) => {
    hydrateForm(rule);
};

const sourceSummaryFor = (rule) => (Array.isArray(rule?.content_sources) ? rule.content_sources : [])
    .map((entry) => contentSourceTypeLabel(entry?.type))
    .filter(Boolean)
    .join(' • ');
</script>

<template>
    <div class="space-y-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h3 class="text-lg font-semibold text-stone-900 dark:text-neutral-100">
                    {{ t('social.automation_manager.title') }}
                </h3>
                <p class="mt-1 max-w-3xl text-sm text-stone-500 dark:text-neutral-400">
                    {{ t('social.automation_manager.description') }}
                </p>
            </div>

            <div class="flex flex-wrap gap-2">
                <SecondaryButton :disabled="busy || isLoading" @click="load">
                    {{ t('social.automation_manager.actions.reload') }}
                </SecondaryButton>
                <SecondaryButton :disabled="busy || isLoading" @click="resetForm">
                    {{ t('social.automation_manager.actions.new_rule') }}
                </SecondaryButton>
            </div>
        </div>

        <div
            v-if="!canManage"
            class="rounded-3xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-700 dark:border-sky-500/20 dark:bg-sky-500/10 dark:text-sky-300"
        >
            <div class="font-semibold">{{ t('social.automation_manager.read_only_title') }}</div>
            <div class="mt-1">{{ t('social.automation_manager.read_only_description') }}</div>
        </div>

        <div
            v-if="error"
            class="rounded-3xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-300"
        >
            {{ error }}
        </div>

        <div
            v-if="info"
            class="rounded-3xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300"
        >
            {{ info }}
        </div>

        <section class="grid grid-cols-1 gap-4 md:grid-cols-3 xl:grid-cols-6">
            <div class="rounded-3xl border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="text-xs uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                    {{ t('social.automation_manager.summary.total') }}
                </div>
                <div class="mt-2 text-2xl font-semibold text-stone-900 dark:text-neutral-100">
                    {{ Number(summary.total || 0) }}
                </div>
            </div>
            <div class="rounded-3xl border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="text-xs uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                    {{ t('social.automation_manager.summary.active') }}
                </div>
                <div class="mt-2 text-2xl font-semibold text-stone-900 dark:text-neutral-100">
                    {{ Number(summary.active || 0) }}
                </div>
            </div>
            <div class="rounded-3xl border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="text-xs uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                    {{ t('social.automation_manager.summary.paused') }}
                </div>
                <div class="mt-2 text-2xl font-semibold text-stone-900 dark:text-neutral-100">
                    {{ Number(summary.paused || 0) }}
                </div>
            </div>
            <div class="rounded-3xl border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="text-xs uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                    {{ t('social.automation_manager.summary.pending_approvals') }}
                </div>
                <div class="mt-2 text-2xl font-semibold text-stone-900 dark:text-neutral-100">
                    {{ Number(summary.pending_approvals || 0) }}
                </div>
            </div>
            <div class="rounded-3xl border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="text-xs uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                    {{ t('social.automation_manager.summary.attention') }}
                </div>
                <div class="mt-2 text-2xl font-semibold text-stone-900 dark:text-neutral-100">
                    {{ Number(summary.attention || 0) }}
                </div>
            </div>
            <div class="rounded-3xl border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="text-xs uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                    {{ t('social.automation_manager.summary.auto_paused') }}
                </div>
                <div class="mt-2 text-2xl font-semibold text-stone-900 dark:text-neutral-100">
                    {{ Number(summary.auto_paused || 0) }}
                </div>
            </div>
        </section>

        <div class="grid grid-cols-1 gap-5 xl:grid-cols-[1.1fr,0.9fr]">
            <section class="rounded-3xl border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h4 class="text-base font-semibold text-stone-900 dark:text-neutral-100">
                            {{ activeRule ? t('social.automation_manager.form.edit_title') : t('social.automation_manager.form.create_title') }}
                        </h4>
                        <p class="mt-1 text-sm text-stone-500 dark:text-neutral-400">
                            {{ t('social.automation_manager.form.description') }}
                        </p>
                    </div>

                    <SecondaryButton :disabled="busy" @click="resetForm">
                        {{ t('social.automation_manager.actions.clear_form') }}
                    </SecondaryButton>
                </div>

                <div class="mt-5 grid grid-cols-1 gap-4 md:grid-cols-2">
                    <FloatingInput v-model="form.name" :label="t('social.automation_manager.fields.name')" :disabled="busy || !canManage" />
                    <label class="flex items-center gap-3 rounded-2xl border border-stone-200 bg-stone-50 px-4 py-3 text-sm text-stone-700 dark:border-neutral-700 dark:bg-neutral-800/70 dark:text-neutral-200">
                        <input v-model="form.is_active" type="checkbox" class="rounded border-stone-300 text-sky-600 focus:ring-sky-500" :disabled="busy || !canManage">
                        <span>{{ t('social.automation_manager.fields.is_active') }}</span>
                    </label>
                    <div class="md:col-span-2">
                        <FloatingTextarea v-model="form.description" :label="t('social.automation_manager.fields.description')" :disabled="busy || !canManage" />
                    </div>
                    <label class="block">
                        <span class="mb-1 block text-xs font-semibold uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                            {{ t('social.automation_manager.fields.frequency_type') }}
                        </span>
                        <select v-model="form.frequency_type" class="block w-full rounded-2xl border border-stone-300 bg-white px-4 py-3 text-sm text-stone-900 shadow-sm focus:border-sky-500 focus:outline-none focus:ring-2 focus:ring-sky-200 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100" :disabled="busy || !canManage">
                            <option v-for="option in frequencyOptions" :key="option.value" :value="option.value">
                                {{ frequencyLabel(option.value) }}
                            </option>
                        </select>
                    </label>
                    <FloatingInput v-model="form.scheduled_time" type="time" :label="t('social.automation_manager.fields.scheduled_time')" :disabled="busy || !canManage" />
                    <label class="block">
                        <span class="mb-1 block text-xs font-semibold uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                            {{ t('social.automation_manager.fields.timezone') }}
                        </span>
                        <select v-model="form.timezone" class="block w-full rounded-2xl border border-stone-300 bg-white px-4 py-3 text-sm text-stone-900 shadow-sm focus:border-sky-500 focus:outline-none focus:ring-2 focus:ring-sky-200 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100" :disabled="busy || !canManage">
                            <option v-for="option in timezoneOptions" :key="option.value" :value="option.value">
                                {{ option.label }}
                            </option>
                        </select>
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-xs font-semibold uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                            {{ t('social.automation_manager.fields.language') }}
                        </span>
                        <select v-model="form.language" class="block w-full rounded-2xl border border-stone-300 bg-white px-4 py-3 text-sm text-stone-900 shadow-sm focus:border-sky-500 focus:outline-none focus:ring-2 focus:ring-sky-200 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100" :disabled="busy || !canManage">
                            <option v-for="option in localeOptions" :key="option.value" :value="option.value">
                                {{ option.label }}
                            </option>
                        </select>
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-xs font-semibold uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                            {{ t('social.automation_manager.fields.approval_mode') }}
                        </span>
                        <select v-model="form.approval_mode" class="block w-full rounded-2xl border border-stone-300 bg-white px-4 py-3 text-sm text-stone-900 shadow-sm focus:border-sky-500 focus:outline-none focus:ring-2 focus:ring-sky-200 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100" :disabled="busy || !canManage">
                            <option v-for="option in approvalModeOptions" :key="option.value" :value="option.value">
                                {{ approvalModeLabel(option.value) }}
                            </option>
                        </select>
                    </label>
                    <FloatingInput v-model="form.max_posts_per_day" type="number" min="1" max="20" :label="t('social.automation_manager.fields.max_posts_per_day')" :disabled="busy || !canManage" />
                    <FloatingInput v-model="form.min_hours_between_similar_posts" type="number" min="1" max="720" :label="t('social.automation_manager.fields.min_hours_between_similar_posts')" :disabled="busy || !canManage" />
                </div>

                <div class="mt-5">
                    <div class="text-sm font-semibold text-stone-900 dark:text-neutral-100">
                        {{ t('social.automation_manager.generation_title') }}
                    </div>

                    <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                        <label class="flex items-center gap-3 rounded-2xl border border-stone-200 bg-stone-50 px-4 py-3 text-sm text-stone-700 dark:border-neutral-700 dark:bg-neutral-800/70 dark:text-neutral-200">
                            <input v-model="form.generation_settings.text_ai_enabled" type="checkbox" class="rounded border-stone-300 text-sky-600 focus:ring-sky-500" :disabled="busy || !canManage">
                            <span>{{ t('social.automation_manager.fields.text_ai_enabled') }}</span>
                        </label>
                        <label class="flex items-center gap-3 rounded-2xl border border-stone-200 bg-stone-50 px-4 py-3 text-sm text-stone-700 dark:border-neutral-700 dark:bg-neutral-800/70 dark:text-neutral-200">
                            <input v-model="form.generation_settings.image_ai_enabled" type="checkbox" class="rounded border-stone-300 text-sky-600 focus:ring-sky-500" :disabled="busy || !canManage">
                            <span>{{ t('social.automation_manager.fields.image_ai_enabled') }}</span>
                        </label>
                        <label class="block">
                            <span class="mb-1 block text-xs font-semibold uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                                {{ t('social.automation_manager.fields.generation_tone') }}
                            </span>
                            <select v-model="form.generation_settings.tone" class="block w-full rounded-2xl border border-stone-300 bg-white px-4 py-3 text-sm text-stone-900 shadow-sm focus:border-sky-500 focus:outline-none focus:ring-2 focus:ring-sky-200 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100" :disabled="busy || !canManage">
                                <option v-for="option in generationToneOptions" :key="option.value" :value="option.value">
                                    {{ generationToneLabel(option.value) }}
                                </option>
                            </select>
                        </label>
                        <label class="block">
                            <span class="mb-1 block text-xs font-semibold uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                                {{ t('social.automation_manager.fields.generation_goal') }}
                            </span>
                            <select v-model="form.generation_settings.goal" class="block w-full rounded-2xl border border-stone-300 bg-white px-4 py-3 text-sm text-stone-900 shadow-sm focus:border-sky-500 focus:outline-none focus:ring-2 focus:ring-sky-200 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100" :disabled="busy || !canManage">
                                <option v-for="option in generationGoalOptions" :key="option.value" :value="option.value">
                                    {{ generationGoalLabel(option.value) }}
                                </option>
                            </select>
                        </label>
                        <label class="block">
                            <span class="mb-1 block text-xs font-semibold uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                                {{ t('social.automation_manager.fields.image_mode') }}
                            </span>
                            <select v-model="form.generation_settings.image_mode" class="block w-full rounded-2xl border border-stone-300 bg-white px-4 py-3 text-sm text-stone-900 shadow-sm focus:border-sky-500 focus:outline-none focus:ring-2 focus:ring-sky-200 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100" :disabled="busy || !canManage">
                                <option v-for="option in imageModeOptions" :key="option.value" :value="option.value">
                                    {{ imageModeLabel(option.value) }}
                                </option>
                            </select>
                        </label>
                        <label class="block">
                            <span class="mb-1 block text-xs font-semibold uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                                {{ t('social.automation_manager.fields.image_format') }}
                            </span>
                            <select v-model="form.generation_settings.image_format" class="block w-full rounded-2xl border border-stone-300 bg-white px-4 py-3 text-sm text-stone-900 shadow-sm focus:border-sky-500 focus:outline-none focus:ring-2 focus:ring-sky-200 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100" :disabled="busy || !canManage">
                                <option v-for="option in imageFormatOptions" :key="option.value" :value="option.value">
                                    {{ imageFormatLabel(option.value) }}
                                </option>
                            </select>
                        </label>
                        <FloatingInput v-model="form.generation_settings.variant_count" type="number" min="1" max="5" :label="t('social.automation_manager.fields.variant_count')" :disabled="busy || !canManage" />
                        <div class="hidden md:block" />
                        <div class="md:col-span-2">
                            <FloatingTextarea v-model="form.generation_settings.creative_prompt" :label="t('social.automation_manager.fields.creative_prompt')" :disabled="busy || !canManage" />
                        </div>
                        <div class="md:col-span-2">
                            <FloatingTextarea v-model="form.generation_settings.image_prompt" :label="t('social.automation_manager.fields.image_prompt')" :disabled="busy || !canManage" />
                        </div>
                    </div>
                </div>

                <div class="mt-5">
                    <div class="text-sm font-semibold text-stone-900 dark:text-neutral-100">
                        {{ t('social.automation_manager.targets_title') }}
                    </div>
                    <p class="mt-1 text-sm text-stone-500 dark:text-neutral-400">
                        {{ t('social.automation_manager.targets_description') }}
                    </p>

                    <div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-2">
                        <label
                            v-for="connection in targetConnections"
                            :key="connection.id"
                            class="flex items-start gap-3 rounded-2xl border border-stone-200 bg-stone-50 px-4 py-3 text-sm text-stone-700 dark:border-neutral-700 dark:bg-neutral-800/60 dark:text-neutral-200"
                        >
                            <input v-model="form.target_connection_ids" :value="connection.id" type="checkbox" class="mt-1 rounded border-stone-300 text-sky-600 focus:ring-sky-500" :disabled="busy || !canManage">
                            <div>
                                <div class="font-medium text-stone-900 dark:text-neutral-100">
                                    {{ formatTargetLabel(connection) }}
                                </div>
                                <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                    {{ connection.provider_label || connection.platform || t('social.automation_manager.empty_value') }}
                                </div>
                                <div class="mt-1 text-xs" :class="connection.is_connected ? 'text-emerald-600 dark:text-emerald-300' : 'text-rose-600 dark:text-rose-300'">
                                    {{ connection.is_connected ? t('social.automation_manager.connection_states.connected') : t('social.automation_manager.connection_states.needs_attention') }}
                                </div>
                            </div>
                        </label>
                    </div>
                </div>

                <div class="mt-5">
                    <div class="text-sm font-semibold text-stone-900 dark:text-neutral-100">
                        {{ t('social.automation_manager.sources_title') }}
                    </div>
                    <p class="mt-1 text-sm text-stone-500 dark:text-neutral-400">
                        {{ t('social.automation_manager.sources_description') }}
                    </p>

                    <div class="mt-4 space-y-4">
                        <div
                            v-for="entry in contentSourceCatalog"
                            :key="entry.type"
                            class="rounded-3xl border border-stone-200 bg-stone-50 p-4 dark:border-neutral-700 dark:bg-neutral-800/60"
                        >
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <label class="flex items-center gap-3 text-sm font-medium text-stone-900 dark:text-neutral-100">
                                    <input v-model="form.source_config[entry.type].enabled" type="checkbox" class="rounded border-stone-300 text-sky-600 focus:ring-sky-500" :disabled="busy || !canManage">
                                    <span>{{ contentSourceTypeLabel(entry.type) }}</span>
                                </label>

                                <select v-model="form.source_config[entry.type].mode" class="rounded-2xl border border-stone-300 bg-white px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-sky-500 focus:outline-none focus:ring-2 focus:ring-sky-200 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100" :disabled="busy || !canManage || !form.source_config[entry.type].enabled">
                                    <option value="all">{{ t('social.automation_manager.source_modes.all') }}</option>
                                    <option value="selected_ids">{{ t('social.automation_manager.source_modes.selected_ids') }}</option>
                                </select>
                            </div>

                            <div class="mt-3 text-xs text-stone-500 dark:text-neutral-400">
                                {{ t('social.automation_manager.source_items_count', { count: Number(entry.items?.length || 0) }) }}
                            </div>

                            <div v-if="form.source_config[entry.type].enabled && form.source_config[entry.type].mode === 'selected_ids'" class="mt-3">
                                <select
                                    v-model="form.source_config[entry.type].ids"
                                    multiple
                                    class="block min-h-36 w-full rounded-2xl border border-stone-300 bg-white px-4 py-3 text-sm text-stone-900 shadow-sm focus:border-sky-500 focus:outline-none focus:ring-2 focus:ring-sky-200 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100"
                                    :disabled="busy || !canManage"
                                >
                                    <option v-for="item in entry.items" :key="item.value" :value="item.value">
                                        {{ item.label }}
                                    </option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-5 flex flex-wrap gap-2">
                    <PrimaryButton :disabled="busy || !canManage" @click="saveRule">
                        {{ activeRule ? t('social.automation_manager.actions.update_rule') : t('social.automation_manager.actions.save_rule') }}
                    </PrimaryButton>
                    <SecondaryButton :disabled="busy" @click="resetForm">
                        {{ t('social.automation_manager.actions.clear_form') }}
                    </SecondaryButton>
                </div>
            </section>

            <section class="rounded-3xl border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h4 class="text-base font-semibold text-stone-900 dark:text-neutral-100">
                            {{ t('social.automation_manager.library_title') }}
                        </h4>
                        <p class="mt-1 text-sm text-stone-500 dark:text-neutral-400">
                            {{ t('social.automation_manager.library_description') }}
                        </p>
                    </div>
                </div>

                <div v-if="sortedRules.length" class="mt-4 space-y-4">
                    <article
                        v-for="rule in sortedRules"
                        :key="rule.id"
                        class="rounded-3xl border border-stone-200 bg-stone-50 p-4 dark:border-neutral-700 dark:bg-neutral-800/60"
                    >
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="space-y-2">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="inline-flex items-center rounded-full border px-3 py-1 text-xs font-semibold" :class="rule.is_active ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300' : 'border-stone-200 bg-white text-stone-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300'">
                                        {{ rule.is_active ? t('social.automation_manager.statuses.active') : t('social.automation_manager.statuses.paused') }}
                                    </span>
                                    <span class="inline-flex items-center rounded-full border px-3 py-1 text-xs font-semibold" :class="healthBadgeClass(rule)">
                                        {{ healthStateLabel(rule.health?.state) }}
                                    </span>
                                    <span class="text-xs text-stone-500 dark:text-neutral-400">
                                        {{ frequencyLabel(rule.frequency_type) }}
                                    </span>
                                </div>

                                <h5 class="text-base font-semibold text-stone-900 dark:text-neutral-100">
                                    {{ rule.name || t('social.automation_manager.untitled_rule') }}
                                </h5>

                                <p v-if="rule.description" class="text-sm text-stone-600 dark:text-neutral-300">
                                    {{ rule.description }}
                                </p>

                                <div class="text-xs text-stone-500 dark:text-neutral-400">
                                    {{ sourceSummaryFor(rule) || t('social.automation_manager.empty_value') }}
                                </div>
                                <div v-if="Array.isArray(rule.health?.reasons) && rule.health.reasons.length" class="flex flex-wrap gap-2">
                                    <span
                                        v-for="reason in rule.health.reasons"
                                        :key="reason"
                                        class="inline-flex items-center rounded-full border border-stone-200 bg-white px-3 py-1 text-[11px] font-medium text-stone-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300"
                                    >
                                        {{ healthReasonLabel(reason) }}
                                    </span>
                                </div>
                            </div>

                            <div v-if="canManage" class="flex flex-wrap gap-2">
                                <SecondaryButton :disabled="busy" @click="editRule(rule)">
                                    {{ t('social.automation_manager.actions.edit_rule') }}
                                </SecondaryButton>
                                <SecondaryButton :disabled="busy" @click="toggleRule(rule)">
                                    {{ rule.is_active ? t('social.automation_manager.actions.pause_rule') : t('social.automation_manager.actions.resume_rule') }}
                                </SecondaryButton>
                                <SecondaryButton :disabled="busy" @click="removeRule(rule)">
                                    {{ t('social.automation_manager.actions.delete_rule') }}
                                </SecondaryButton>
                            </div>
                        </div>

                        <div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-2">
                            <div class="rounded-2xl border border-stone-200 bg-white p-3 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                                <div class="text-xs uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                                    {{ t('social.automation_manager.meta.next_generation_at') }}
                                </div>
                                <div class="mt-1 font-medium text-stone-900 dark:text-neutral-100">
                                    {{ formatDate(rule.next_generation_at) }}
                                </div>
                            </div>
                            <div class="rounded-2xl border border-stone-200 bg-white p-3 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                                <div class="text-xs uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                                    {{ t('social.automation_manager.meta.last_generated_at') }}
                                </div>
                                <div class="mt-1 font-medium text-stone-900 dark:text-neutral-100">
                                    {{ formatDate(rule.last_generated_at) }}
                                </div>
                            </div>
                            <div class="rounded-2xl border border-stone-200 bg-white p-3 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                                <div class="text-xs uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                                    {{ t('social.automation_manager.meta.accounts') }}
                                </div>
                                <div class="mt-1 font-medium text-stone-900 dark:text-neutral-100">
                                    {{ Number(rule.target_connections?.length || 0) }}
                                </div>
                            </div>
                            <div class="rounded-2xl border border-stone-200 bg-white p-3 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                                <div class="text-xs uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                                    {{ t('social.automation_manager.meta.generated_posts_count') }}
                                </div>
                                <div class="mt-1 font-medium text-stone-900 dark:text-neutral-100">
                                    {{ Number(rule.generated_posts_count || 0) }}
                                </div>
                            </div>
                            <div class="rounded-2xl border border-stone-200 bg-white p-3 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                                <div class="text-xs uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                                    {{ t('social.automation_manager.meta.pending_posts') }}
                                </div>
                                <div class="mt-1 font-medium text-stone-900 dark:text-neutral-100">
                                    {{ Number(rule.pending_approval_posts_count || 0) }}
                                </div>
                            </div>
                            <div class="rounded-2xl border border-stone-200 bg-white p-3 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                                <div class="text-xs uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                                    {{ t('social.automation_manager.meta.published_posts') }}
                                </div>
                                <div class="mt-1 font-medium text-stone-900 dark:text-neutral-100">
                                    {{ Number(rule.published_posts_count || 0) }}
                                </div>
                            </div>
                        </div>

                        <div v-if="rule.latest_run" class="mt-4 rounded-2xl border border-stone-200 bg-white p-3 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="inline-flex items-center rounded-full border px-3 py-1 text-xs font-semibold" :class="runBadgeClass(rule.latest_run)">
                                    {{ runStatusLabel(rule.latest_run.status) }}
                                </span>
                                <span v-if="rule.latest_run.outcome_code" class="text-xs text-stone-500 dark:text-neutral-400">
                                    {{ outcomeCodeLabel(rule.latest_run.outcome_code) }}
                                </span>
                            </div>
                            <div class="mt-2 font-medium text-stone-900 dark:text-neutral-100">
                                {{ rule.latest_run.message || t('social.automation_manager.empty_value') }}
                            </div>
                            <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                {{ formatDate(rule.latest_run.completed_at || rule.latest_run.started_at) }}
                            </div>
                        </div>

                        <div v-if="rule.last_error" class="mt-4 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-200">
                            {{ rule.last_error }}
                        </div>
                    </article>
                </div>

                <div
                    v-else
                    class="mt-4 rounded-3xl border border-dashed border-stone-300 bg-stone-50 px-5 py-6 text-sm text-stone-500 dark:border-neutral-700 dark:bg-neutral-800/60 dark:text-neutral-400"
                >
                    {{ t('social.automation_manager.empty_rules') }}
                </div>
            </section>
        </div>

        <section class="rounded-3xl border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h4 class="text-base font-semibold text-stone-900 dark:text-neutral-100">
                        {{ t('social.automation_manager.recent_runs_title') }}
                    </h4>
                    <p class="mt-1 text-sm text-stone-500 dark:text-neutral-400">
                        {{ t('social.automation_manager.recent_runs_description') }}
                    </p>
                </div>
            </div>

            <div v-if="recentRuns.length" class="mt-4 space-y-3">
                <article
                    v-for="run in recentRuns"
                    :key="run.id"
                    class="rounded-3xl border border-stone-200 bg-stone-50 p-4 dark:border-neutral-700 dark:bg-neutral-800/60"
                >
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="space-y-2">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="inline-flex items-center rounded-full border px-3 py-1 text-xs font-semibold" :class="runBadgeClass(run)">
                                    {{ runStatusLabel(run.status) }}
                                </span>
                                <span v-if="run.rule?.name" class="inline-flex items-center rounded-full border border-stone-200 bg-white px-3 py-1 text-xs font-semibold text-stone-700 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200">
                                    {{ run.rule.name }}
                                </span>
                                <span v-if="run.outcome_code" class="text-xs text-stone-500 dark:text-neutral-400">
                                    {{ outcomeCodeLabel(run.outcome_code) }}
                                </span>
                            </div>
                            <div class="font-medium text-stone-900 dark:text-neutral-100">
                                {{ run.message || t('social.automation_manager.empty_value') }}
                            </div>
                            <div class="flex flex-wrap items-center gap-3 text-xs text-stone-500 dark:text-neutral-400">
                                <span>{{ formatDate(run.completed_at || run.started_at) }}</span>
                                <span v-if="run.source_type">
                                    {{ contentSourceTypeLabel(run.source_type) }}
                                </span>
                                <span v-if="run.post?.id">
                                    #{{ run.post.id }}
                                </span>
                            </div>
                        </div>

                        <a
                            v-if="run.post?.id"
                            :href="route('social.composer', { draft: run.post.id })"
                            class="inline-flex items-center rounded-full border border-stone-300 bg-white px-4 py-2 text-sm font-medium text-stone-700 transition hover:border-stone-400 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:border-neutral-500"
                        >
                            {{ t('social.automation_manager.actions.open_generated_post') }}
                        </a>
                    </div>
                </article>
            </div>

            <div
                v-else
                class="mt-4 rounded-3xl border border-dashed border-stone-300 bg-stone-50 px-5 py-6 text-sm text-stone-500 dark:border-neutral-700 dark:bg-neutral-800/60 dark:text-neutral-400"
            >
                {{ t('social.automation_manager.empty_recent_runs') }}
            </div>
        </section>
    </div>
</template>
