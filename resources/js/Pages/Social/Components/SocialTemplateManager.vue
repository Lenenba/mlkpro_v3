<script setup>
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import { useI18n } from 'vue-i18n';
import DropzoneInput from '@/Components/DropzoneInput.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingTextarea from '@/Components/FloatingTextarea.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import SocialMediaAssetPicker from '@/Pages/Social/Components/SocialMediaAssetPicker.vue';

const props = defineProps({
    initialConnectedAccounts: {
        type: Array,
        default: () => ([]),
    },
    initialTemplates: {
        type: Array,
        default: () => ([]),
    },
    initialMediaAssets: {
        type: Array,
        default: () => ([]),
    },
    initialSummary: {
        type: Object,
        default: () => ({}),
    },
    initialAccess: {
        type: Object,
        default: () => ({}),
    },
    selectedTemplateId: {
        type: Number,
        default: null,
    },
});

const { t } = useI18n();

const normalizeAccounts = (payload) => Array.isArray(payload) ? payload : [];
const normalizeTemplates = (payload) => Array.isArray(payload) ? payload : [];
const normalizeMediaAssets = (payload) => Array.isArray(payload) ? payload : [];
const normalizeSummary = (payload) => payload && typeof payload === 'object' ? payload : {};
const normalizeAccess = (payload) => ({
    can_view: Boolean(payload?.can_view),
    can_manage_posts: Boolean(payload?.can_manage_posts),
    can_publish: Boolean(payload?.can_publish),
});
const sortByUpdatedAt = (left, right) => {
    const leftDate = Date.parse(String(left?.updated_at || '')) || 0;
    const rightDate = Date.parse(String(right?.updated_at || '')) || 0;

    return rightDate - leftDate;
};

const connectedAccounts = ref(normalizeAccounts(props.initialConnectedAccounts));
const templates = ref(normalizeTemplates(props.initialTemplates));
const mediaAssets = ref(normalizeMediaAssets(props.initialMediaAssets));
const summary = ref(normalizeSummary(props.initialSummary));
const access = ref(normalizeAccess(props.initialAccess));
const activeTemplateId = ref(props.selectedTemplateId);
const busy = ref(false);
const isLoading = ref(false);
const error = ref('');
const info = ref('');
const imageFile = ref(null);
const form = ref({
    name: '',
    text: '',
    image_url: '',
    link_url: '',
    link_cta_label: '',
    target_connection_ids: [],
});

const canManage = computed(() => Boolean(access.value.can_manage_posts));
const sortedTemplates = computed(() => [...templates.value].sort(sortByUpdatedAt));
const activeTemplate = computed(() => (
    sortedTemplates.value.find((template) => Number(template.id) === Number(activeTemplateId.value)) || null
));
const imageInputModel = computed({
    get: () => imageFile.value || String(form.value.image_url || '').trim() || null,
    set: (value) => {
        if (value instanceof File) {
            imageFile.value = value;
            form.value.image_url = '';

            return;
        }

        if (typeof value === 'string' && value.trim() !== '') {
            imageFile.value = null;
            form.value.image_url = value.trim();

            return;
        }

        imageFile.value = null;
        form.value.image_url = '';
    },
});
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
const linkSummaryFor = (record) => {
    const label = String(record?.link_cta_label || '').trim();
    const host = linkHostFor(record?.link_url);

    if (label !== '' && host !== '' && label.toLowerCase() !== host.toLowerCase()) {
        return `${label} - ${host}`;
    }

    if (label !== '') {
        return label;
    }

    if (host !== '') {
        return host;
    }

    return '';
};

const templateLabel = (template) => {
    const name = String(template?.name || '').trim();
    if (name !== '') {
        return name;
    }

    const text = String(template?.text || '').trim();
    if (text !== '') {
        return text.length > 80 ? `${text.slice(0, 77)}...` : text;
    }

    const linkSummary = linkSummaryFor(template);
    if (linkSummary !== '') {
        return linkSummary;
    }

    return t('social.template_manager.untitled_template');
};

const formatDate = (value) => {
    if (!value) {
        return t('social.template_manager.empty_value');
    }

    try {
        return new Date(value).toLocaleString();
    } catch {
        return t('social.template_manager.empty_value');
    }
};

const availableTargetConnectionIds = (targetIds) => {
    const connectedIds = new Set(
        connectedAccounts.value
            .map((account) => Number(account.id))
            .filter((id) => id > 0)
    );

    return (Array.isArray(targetIds) ? targetIds : [])
        .map((id) => Number(id))
        .filter((id) => id > 0 && connectedIds.has(id));
};

const syncFormFromTemplate = (template) => {
    imageFile.value = null;
    form.value = {
        name: String(template?.name || ''),
        text: String(template?.text || ''),
        image_url: String(template?.image_url || ''),
        link_url: String(template?.link_url || ''),
        link_cta_label: String(template?.link_cta_label || ''),
        target_connection_ids: availableTargetConnectionIds(template?.selected_target_connection_ids),
    };
};

const resetForm = () => {
    activeTemplateId.value = null;
    imageFile.value = null;
    form.value = {
        name: '',
        text: '',
        image_url: '',
        link_url: '',
        link_cta_label: '',
        target_connection_ids: [],
    };
    error.value = '';
    info.value = '';
};

const refreshFromPayload = (payload) => {
    if (Array.isArray(payload?.templates)) {
        templates.value = normalizeTemplates(payload.templates);
    }

    if (Array.isArray(payload?.media_assets)) {
        mediaAssets.value = normalizeMediaAssets(payload.media_assets);
    }

    if (Array.isArray(payload?.connected_accounts)) {
        connectedAccounts.value = normalizeAccounts(payload.connected_accounts);
    }

    if (payload?.summary) {
        summary.value = normalizeSummary(payload.summary);
    }

    if (payload?.access) {
        access.value = normalizeAccess(payload.access);
    }

    if (payload?.selected_template_id !== undefined) {
        activeTemplateId.value = Number(payload.selected_template_id || 0) || null;
    }
};

watch(() => props.initialConnectedAccounts, (value) => {
    connectedAccounts.value = normalizeAccounts(value);
}, { deep: true });

watch(() => props.initialTemplates, (value) => {
    templates.value = normalizeTemplates(value);
}, { deep: true });

watch(() => props.initialMediaAssets, (value) => {
    mediaAssets.value = normalizeMediaAssets(value);
}, { deep: true });

watch(() => props.initialSummary, (value) => {
    summary.value = normalizeSummary(value);
}, { deep: true });

watch(() => props.initialAccess, (value) => {
    access.value = normalizeAccess(value);
}, { deep: true });

watch(() => props.selectedTemplateId, (value) => {
    activeTemplateId.value = value;
}, { immediate: true });

watch(() => form.value.image_url, (value, previous) => {
    const next = String(value || '').trim();
    const prev = String(previous || '').trim();

    if (next !== '' && next !== prev && imageFile.value instanceof File) {
        imageFile.value = null;
    }
});

watch([sortedTemplates, activeTemplateId], () => {
    if (activeTemplate.value) {
        syncFormFromTemplate(activeTemplate.value);
    }
}, { immediate: true });

const requestErrorMessage = (requestError, fallback) => {
    const validationMessage = Object.values(requestError?.response?.data?.errors || {})
        .flat()
        .find((value) => typeof value === 'string' && value.trim() !== '');

    return validationMessage
        || requestError?.response?.data?.message
        || requestError?.message
        || fallback;
};

const load = async () => {
    isLoading.value = true;
    error.value = '';

    try {
        const params = activeTemplateId.value ? { template: activeTemplateId.value } : {};
        const response = await axios.get(route('social.templates.index', params));
        refreshFromPayload(response.data);
    } catch (requestError) {
        error.value = requestErrorMessage(requestError, t('social.template_manager.messages.load_error'));
    } finally {
        isLoading.value = false;
    }
};

const toggleTarget = (accountId) => {
    if (!canManage.value) {
        return;
    }

    const id = Number(accountId);
    const exists = form.value.target_connection_ids.includes(id);

    form.value.target_connection_ids = exists
        ? form.value.target_connection_ids.filter((value) => value !== id)
        : [...form.value.target_connection_ids, id];
};

const openTemplate = (template) => {
    activeTemplateId.value = Number(template.id);
    syncFormFromTemplate(template);
    error.value = '';
    info.value = '';
};

const appendFormDataValue = (formData, key, value) => {
    if (Array.isArray(value)) {
        value.forEach((item) => {
            formData.append(`${key}[]`, String(item));
        });

        return;
    }

    if (value instanceof File) {
        formData.append(key, value);

        return;
    }

    formData.append(key, value ?? '');
};

const usesFormData = (payload) => payload instanceof FormData;

const putWithPayload = (url, payload) => {
    if (usesFormData(payload)) {
        payload.append('_method', 'PUT');

        return axios.post(url, payload);
    }

    return axios.put(url, payload);
};

const templatePayload = () => {
    const payload = {
        name: String(form.value.name || '').trim(),
        text: String(form.value.text || '').trim(),
        image_url: String(form.value.image_url || '').trim(),
        link_url: String(form.value.link_url || '').trim(),
        link_cta_label: String(form.value.link_cta_label || '').trim(),
        target_connection_ids: availableTargetConnectionIds(form.value.target_connection_ids),
    };

    if (!(imageFile.value instanceof File)) {
        return payload;
    }

    const formData = new FormData();

    appendFormDataValue(formData, 'name', payload.name);
    appendFormDataValue(formData, 'text', payload.text);
    appendFormDataValue(formData, 'image_url', payload.image_url);
    appendFormDataValue(formData, 'image_file', imageFile.value);
    appendFormDataValue(formData, 'link_url', payload.link_url);
    appendFormDataValue(formData, 'link_cta_label', payload.link_cta_label);
    appendFormDataValue(formData, 'target_connection_ids', payload.target_connection_ids);

    return formData;
};

const saveTemplate = async () => {
    if (!canManage.value) {
        return;
    }

    busy.value = true;
    error.value = '';
    info.value = '';

    const payload = templatePayload();

    try {
        const response = activeTemplateId.value
            ? await putWithPayload(route('social.templates.update', activeTemplateId.value), payload)
            : await axios.post(route('social.templates.store'), payload);

        refreshFromPayload(response.data);

        if (response.data?.template) {
            activeTemplateId.value = Number(response.data.template.id);
            syncFormFromTemplate(response.data.template);
        }

        info.value = String(response.data?.message || (
            activeTemplateId.value
                ? t('social.template_manager.messages.update_success')
                : t('social.template_manager.messages.save_success')
        ));
    } catch (requestError) {
        error.value = requestErrorMessage(requestError, t('social.template_manager.messages.save_error'));
    } finally {
        busy.value = false;
    }
};

const destroyTemplate = async (template) => {
    if (!canManage.value) {
        return;
    }

    const name = templateLabel(template);
    if (!window.confirm(t('social.template_manager.messages.confirm_delete', { name }))) {
        return;
    }

    busy.value = true;
    error.value = '';
    info.value = '';

    try {
        const response = await axios.delete(route('social.templates.destroy', template.id));
        refreshFromPayload(response.data);

        if (Number(activeTemplateId.value) === Number(template.id)) {
            resetForm();
        }

        info.value = String(response.data?.message || t('social.template_manager.messages.delete_success'));
    } catch (requestError) {
        error.value = requestErrorMessage(requestError, t('social.template_manager.messages.delete_error'));
    } finally {
        busy.value = false;
    }
};

const useTemplateInComposer = (template) => {
    router.visit(route('social.composer', { template: template.id }));
};
</script>

<template>
    <div class="space-y-5">
        <div class="flex flex-wrap justify-end gap-2">
            <SecondaryButton :disabled="busy || isLoading" @click="load">
                {{ t('social.template_manager.actions.reload') }}
            </SecondaryButton>
            <SecondaryButton :disabled="busy" @click="resetForm">
                {{ t('social.template_manager.actions.new_template') }}
            </SecondaryButton>
        </div>

        <div
            v-if="!access.can_manage_posts"
            class="rounded-3xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-700 dark:border-sky-500/20 dark:bg-sky-500/10 dark:text-sky-300"
        >
            <div class="font-semibold">{{ t('social.template_manager.read_only_title') }}</div>
            <div class="mt-1">{{ t('social.template_manager.read_only_description') }}</div>
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

        <div class="grid grid-cols-1 gap-5 xl:grid-cols-[1.1fr,0.9fr]">
            <section class="space-y-5">
                <div class="rounded-3xl border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="grid grid-cols-1 gap-4">
                        <FloatingInput
                            v-model="form.name"
                            :label="t('social.template_manager.fields.name')"
                            :disabled="!canManage || busy"
                        />

                        <FloatingTextarea
                            v-model="form.text"
                            :label="t('social.template_manager.fields.text')"
                            :disabled="!canManage || busy"
                        />

                        <DropzoneInput
                            v-model="imageInputModel"
                            :label="t('social.template_manager.fields.image_file')"
                        />

                        <SocialMediaAssetPicker
                            v-model="imageInputModel"
                            :assets="mediaAssets"
                            :disabled="!canManage || busy"
                        />

                        <FloatingInput
                            v-model="form.image_url"
                            type="url"
                            :label="t('social.template_manager.fields.image_url')"
                            placeholder="https://example.com/image.jpg"
                            autocomplete="url"
                            :disabled="!canManage || busy"
                        />

                        <FloatingInput
                            v-model="form.link_url"
                            type="url"
                            :label="t('social.template_manager.fields.link_url')"
                            placeholder="https://example.com"
                            autocomplete="url"
                            :disabled="!canManage || busy"
                        />

                        <FloatingInput
                            v-model="form.link_cta_label"
                            :label="t('social.template_manager.fields.link_cta_label')"
                            placeholder="Voir les details"
                            :disabled="!canManage || busy"
                        />
                    </div>

                    <div class="mt-4 flex flex-wrap items-center gap-2">
                        <PrimaryButton type="button" :disabled="busy || !canManage" @click="saveTemplate">
                            {{ activeTemplateId ? t('social.template_manager.actions.update_template') : t('social.template_manager.actions.save_template') }}
                        </PrimaryButton>
                        <SecondaryButton type="button" :disabled="busy || !activeTemplateId" @click="resetForm">
                            {{ t('social.template_manager.actions.clear_form') }}
                        </SecondaryButton>
                    </div>
                </div>

                <div class="rounded-3xl border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <div>
                        <h4 class="text-base font-semibold text-stone-900 dark:text-neutral-100">
                            {{ t('social.template_manager.targets_title') }}
                        </h4>
                        <p class="mt-1 text-sm text-stone-500 dark:text-neutral-400">
                            {{ t('social.template_manager.targets_description') }}
                        </p>
                    </div>

                    <div v-if="connectedAccounts.length" class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-2">
                        <button
                            v-for="account in connectedAccounts"
                            :key="account.id"
                            type="button"
                            class="rounded-3xl border p-4 text-left transition"
                            :class="form.target_connection_ids.includes(Number(account.id))
                                ? 'border-sky-600 bg-sky-50 dark:border-sky-500 dark:bg-sky-500/10'
                                : 'border-stone-200 bg-stone-50 hover:border-sky-300 dark:border-neutral-700 dark:bg-neutral-800/70 dark:hover:border-sky-500/40'"
                            :disabled="!canManage || busy"
                            @click="toggleTarget(account.id)"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <div class="text-xs uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                                        {{ account.provider_label }}
                                    </div>
                                    <div class="mt-1 text-sm font-semibold text-stone-900 dark:text-neutral-100">
                                        {{ account.label }}
                                    </div>
                                    <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                        {{ account.display_name || account.account_handle || account.platform }}
                                    </div>
                                </div>
                                <span
                                    class="inline-flex size-6 items-center justify-center rounded-full border text-xs font-semibold"
                                    :class="form.target_connection_ids.includes(Number(account.id))
                                        ? 'border-sky-600 bg-sky-600 text-white dark:border-sky-500 dark:bg-sky-500 dark:text-stone-950'
                                        : 'border-stone-300 bg-white text-stone-500 dark:border-neutral-600 dark:bg-neutral-900 dark:text-neutral-400'"
                                >
                                    {{ form.target_connection_ids.includes(Number(account.id)) ? '✓' : '+' }}
                                </span>
                            </div>
                        </button>
                    </div>

                    <div
                        v-else
                        class="mt-4 rounded-3xl border border-dashed border-stone-300 bg-stone-50 px-5 py-6 text-sm text-stone-500 dark:border-neutral-700 dark:bg-neutral-800/60 dark:text-neutral-400"
                    >
                        <div class="font-semibold text-stone-900 dark:text-neutral-100">
                            {{ t('social.template_manager.empty_connected_title') }}
                        </div>
                        <div class="mt-1">
                            {{ t('social.template_manager.empty_connected_description') }}
                        </div>
                    </div>
                </div>
            </section>

            <section class="space-y-5">
                <div class="rounded-3xl border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <h4 class="text-base font-semibold text-stone-900 dark:text-neutral-100">
                                {{ t('social.template_manager.library_title') }}
                            </h4>
                            <p class="mt-1 text-sm text-stone-500 dark:text-neutral-400">
                                {{ t('social.template_manager.library_description') }}
                            </p>
                        </div>
                        <div class="rounded-2xl bg-stone-100 px-3 py-1 text-xs font-semibold text-stone-700 dark:bg-neutral-800 dark:text-neutral-300">
                            {{ sortedTemplates.length }}
                        </div>
                    </div>

                    <div v-if="sortedTemplates.length" class="mt-4 space-y-3">
                        <div
                            v-for="template in sortedTemplates"
                            :key="template.id"
                            class="rounded-3xl border border-stone-200 bg-stone-50 p-4 dark:border-neutral-700 dark:bg-neutral-800/60"
                        >
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <button
                                    type="button"
                                    class="min-w-0 flex-1 text-left"
                                    @click="openTemplate(template)"
                                >
                                    <div class="text-sm font-semibold text-stone-900 dark:text-neutral-100">
                                        {{ templateLabel(template) }}
                                    </div>
                                    <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                        {{ t('social.template_manager.template_targets', { count: Number(template.selected_accounts_count || 0) }) }}
                                    </div>
                                </button>

                                <div class="text-xs text-stone-500 dark:text-neutral-400">
                                    {{ formatDate(template.updated_at) }}
                                </div>
                            </div>

                            <div class="mt-3 line-clamp-3 text-sm text-stone-600 dark:text-neutral-300">
                                {{ template.text || linkSummaryFor(template) || t('social.template_manager.untitled_template') }}
                            </div>

                            <div v-if="template.targets?.length" class="mt-3 flex flex-wrap gap-2">
                                <span
                                    v-for="target in template.targets"
                                    :key="`${template.id}-${target.social_account_connection_id}`"
                                    class="rounded-full border border-stone-200 bg-white px-3 py-1 text-xs font-medium text-stone-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300"
                                >
                                    {{ target.label || target.account_handle || target.platform || t('social.template_manager.empty_value') }}
                                </span>
                            </div>

                            <div v-else class="mt-3 text-xs text-stone-500 dark:text-neutral-400">
                                {{ t('social.template_manager.no_targets') }}
                            </div>

                            <div class="mt-4 flex flex-wrap gap-2">
                                <SecondaryButton type="button" @click="useTemplateInComposer(template)">
                                    {{ t('social.template_manager.actions.use_in_composer') }}
                                </SecondaryButton>
                                <SecondaryButton type="button" :disabled="busy" @click="openTemplate(template)">
                                    {{ t('social.template_manager.actions.edit_template') }}
                                </SecondaryButton>
                                <SecondaryButton
                                    v-if="canManage"
                                    type="button"
                                    :disabled="busy"
                                    @click="destroyTemplate(template)"
                                >
                                    {{ t('social.template_manager.actions.delete_template') }}
                                </SecondaryButton>
                            </div>
                        </div>
                    </div>

                    <div
                        v-else
                        class="mt-4 rounded-3xl border border-dashed border-stone-300 bg-stone-50 px-5 py-6 text-sm text-stone-500 dark:border-neutral-700 dark:bg-neutral-800/60 dark:text-neutral-400"
                    >
                        {{ t('social.template_manager.empty_templates') }}
                    </div>
                </div>
            </section>
        </div>
    </div>
</template>
