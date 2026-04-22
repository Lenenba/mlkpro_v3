<script setup>
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import { useI18n } from 'vue-i18n';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingTextarea from '@/Components/FloatingTextarea.vue';
import DateTimePicker from '@/Components/DateTimePicker.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';

const props = defineProps({
    initialConnectedAccounts: {
        type: Array,
        default: () => ([]),
    },
    initialDrafts: {
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
    selectedDraftId: {
        type: Number,
        default: null,
    },
});

const { t } = useI18n();

const normalizeAccounts = (payload) => Array.isArray(payload) ? payload : [];
const normalizeDrafts = (payload) => Array.isArray(payload) ? payload : [];
const normalizeSummary = (payload) => payload && typeof payload === 'object' ? payload : {};
const normalizeAccess = (payload) => ({
    can_view: Boolean(payload?.can_view),
    can_manage_posts: Boolean(payload?.can_manage_posts),
    can_publish: Boolean(payload?.can_publish),
});

const connectedAccounts = ref(normalizeAccounts(props.initialConnectedAccounts));
const drafts = ref(normalizeDrafts(props.initialDrafts));
const summary = ref(normalizeSummary(props.initialSummary));
const access = ref(normalizeAccess(props.initialAccess));
const activeDraftId = ref(props.selectedDraftId);
const draftSnapshot = ref(null);
const busy = ref(false);
const isLoading = ref(false);
const error = ref('');
const info = ref('');
const form = ref({
    text: '',
    image_url: '',
    link_url: '',
    scheduled_for: '',
    target_connection_ids: [],
});

const canManage = computed(() => Boolean(access.value.can_manage_posts));
const canPublish = computed(() => Boolean(access.value.can_publish));

const sortedDrafts = computed(() => [...drafts.value].sort((left, right) => {
    const leftDate = Date.parse(String(left?.updated_at || '')) || 0;
    const rightDate = Date.parse(String(right?.updated_at || '')) || 0;

    return rightDate - leftDate;
}));

const activeDraft = computed(() => (
    sortedDrafts.value.find((draft) => Number(draft.id) === Number(activeDraftId.value)) || null
));

const selectedAccounts = computed(() => connectedAccounts.value.filter((account) => (
    form.value.target_connection_ids.includes(Number(account.id))
)));

const currentStatus = computed(() => {
    const snapshotStatus = String(draftSnapshot.value?.status || '').trim();
    if (snapshotStatus !== '') {
        return snapshotStatus;
    }

    return form.value.scheduled_for ? 'scheduled' : 'draft';
});

const statusClass = (status) => {
    if (status === 'scheduled') {
        return 'border-sky-200 bg-sky-50 text-sky-700 dark:border-sky-500/20 dark:bg-sky-500/10 dark:text-sky-300';
    }

    if (status === 'published') {
        return 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300';
    }

    if (status === 'partial_failed' || status === 'failed') {
        return 'border-rose-200 bg-rose-50 text-rose-700 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-300';
    }

    return 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-300';
};

const previewStatus = computed(() => t(`social.composer_manager.statuses.${currentStatus.value}`));

const isQueuedPublication = computed(() => Boolean(draftSnapshot.value?.metadata?.publish_requested_at));

const formatDate = (value) => {
    if (!value) {
        return t('social.composer_manager.empty_value');
    }

    try {
        return new Date(value).toLocaleString();
    } catch {
        return t('social.composer_manager.empty_value');
    }
};

const draftLabel = (draft) => {
    const text = String(draft?.text || '').trim();
    if (text !== '') {
        return text.length > 70 ? `${text.slice(0, 67)}...` : text;
    }

    const link = String(draft?.link_url || '').trim();
    if (link !== '') {
        return link;
    }

    return t('social.composer_manager.untitled_draft');
};

const syncFormFromDraft = (draft) => {
    draftSnapshot.value = draft ? { ...draft } : null;
    form.value = {
        text: String(draft?.text || ''),
        image_url: String(draft?.image_url || ''),
        link_url: String(draft?.link_url || ''),
        scheduled_for: String(draft?.scheduled_for || ''),
        target_connection_ids: Array.isArray(draft?.selected_target_connection_ids)
            ? draft.selected_target_connection_ids.map((id) => Number(id)).filter((id) => id > 0)
            : [],
    };
};

const resetForm = () => {
    activeDraftId.value = null;
    draftSnapshot.value = null;
    form.value = {
        text: '',
        image_url: '',
        link_url: '',
        scheduled_for: '',
        target_connection_ids: [],
    };
    error.value = '';
    info.value = '';
};

const refreshFromPayload = (payload) => {
    if (Array.isArray(payload?.drafts)) {
        drafts.value = normalizeDrafts(payload.drafts);
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
};

watch(() => props.initialConnectedAccounts, (value) => {
    connectedAccounts.value = normalizeAccounts(value);
}, { deep: true });

watch(() => props.initialDrafts, (value) => {
    drafts.value = normalizeDrafts(value);
}, { deep: true });

watch(() => props.initialSummary, (value) => {
    summary.value = normalizeSummary(value);
}, { deep: true });

watch(() => props.initialAccess, (value) => {
    access.value = normalizeAccess(value);
}, { deep: true });

watch(() => props.selectedDraftId, (value) => {
    activeDraftId.value = value;
}, { immediate: true });

watch([sortedDrafts, activeDraftId], () => {
    if (activeDraft.value) {
        syncFormFromDraft(activeDraft.value);
        return;
    }

    if (activeDraftId.value && Number(draftSnapshot.value?.id || 0) === Number(activeDraftId.value)) {
        return;
    }

    if (activeDraftId.value) {
        activeDraftId.value = null;
    }

    if (!form.value.text && !form.value.image_url && !form.value.link_url && !form.value.target_connection_ids.length) {
        return;
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
        const params = activeDraftId.value ? { draft: activeDraftId.value } : {};
        const response = await axios.get(route('social.composer', params));
        refreshFromPayload(response.data);

        const selectedId = Number(response.data?.selected_draft_id || 0);
        if (selectedId > 0) {
            activeDraftId.value = selectedId;
        }

        const refreshedDraft = drafts.value.find((draft) => Number(draft.id) === Number(activeDraftId.value));
        if (refreshedDraft) {
            syncFormFromDraft(refreshedDraft);
        }
    } catch (requestError) {
        error.value = requestErrorMessage(requestError, t('social.composer_manager.messages.load_error'));
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

const openDraft = (draft) => {
    activeDraftId.value = Number(draft.id);
    syncFormFromDraft(draft);
    error.value = '';
    info.value = '';
};

const saveDraft = async ({ quiet = false } = {}) => {
    if (!canManage.value) {
        return null;
    }

    busy.value = true;
    if (!quiet) {
        error.value = '';
        info.value = '';
    }

    const payload = {
        text: String(form.value.text || '').trim(),
        image_url: String(form.value.image_url || '').trim(),
        link_url: String(form.value.link_url || '').trim(),
        scheduled_for: String(form.value.scheduled_for || '').trim(),
        target_connection_ids: form.value.target_connection_ids.map((id) => Number(id)).filter((id) => id > 0),
    };

    try {
        const response = activeDraftId.value
            ? await axios.put(route('social.posts.update', activeDraftId.value), payload)
            : await axios.post(route('social.posts.store'), payload);

        refreshFromPayload(response.data);

        if (response.data?.draft) {
            activeDraftId.value = Number(response.data.draft.id);
            syncFormFromDraft(response.data.draft);
        }

        if (!quiet) {
            info.value = String(response.data?.message || t('social.composer_manager.messages.save_success'));
        }

        return response.data ?? null;
    } catch (requestError) {
        error.value = requestErrorMessage(requestError, t('social.composer_manager.messages.save_error'));
        return null;
    } finally {
        busy.value = false;
    }
};

const submit = async () => {
    await saveDraft();
};

const publishDraft = async (mode) => {
    if (!canPublish.value) {
        return;
    }

    error.value = '';
    info.value = '';

    let draftId = Number(activeDraftId.value || 0);

    if (draftId <= 0 || !isQueuedPublication.value) {
        const saved = await saveDraft({ quiet: true });
        draftId = Number(saved?.draft?.id || 0);
    }

    if (draftId <= 0) {
        return;
    }

    busy.value = true;

    try {
        const routeName = mode === 'schedule'
            ? 'social.posts.schedule'
            : 'social.posts.publish';
        const response = await axios.post(route(routeName, draftId));

        refreshFromPayload(response.data);

        if (response.data?.draft) {
            activeDraftId.value = Number(response.data.draft.id);
            syncFormFromDraft(response.data.draft);
        }

        info.value = String(response.data?.message || (
            mode === 'schedule'
                ? t('social.composer_manager.messages.schedule_success')
                : t('social.composer_manager.messages.publish_success')
        ));
    } catch (requestError) {
        error.value = requestErrorMessage(
            requestError,
            mode === 'schedule'
                ? t('social.composer_manager.messages.schedule_error')
                : t('social.composer_manager.messages.publish_error')
        );
    } finally {
        busy.value = false;
    }
};
</script>

<template>
    <div class="space-y-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h3 class="text-lg font-semibold text-stone-900 dark:text-neutral-100">
                    {{ t('social.composer_manager.title') }}
                </h3>
                <p class="mt-1 max-w-3xl text-sm text-stone-500 dark:text-neutral-400">
                    {{ t('social.composer_manager.description') }}
                </p>
            </div>

            <div class="flex flex-wrap gap-2">
                <SecondaryButton :disabled="busy || isLoading" @click="load">
                    {{ t('social.composer_manager.actions.reload') }}
                </SecondaryButton>
                <SecondaryButton :disabled="busy" @click="resetForm">
                    {{ t('social.composer_manager.actions.new_draft') }}
                </SecondaryButton>
            </div>
        </div>

        <div
            v-if="!access.can_manage_posts"
            class="rounded-3xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-700 dark:border-sky-500/20 dark:bg-sky-500/10 dark:text-sky-300"
        >
            <div class="font-semibold">{{ t('social.composer_manager.read_only_title') }}</div>
            <div class="mt-1">{{ t('social.composer_manager.read_only_description') }}</div>
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
                        <FloatingTextarea
                            v-model="form.text"
                            :label="t('social.composer_manager.fields.text')"
                            :disabled="!canManage || busy"
                        />

                        <FloatingInput
                            v-model="form.image_url"
                            :label="t('social.composer_manager.fields.image_url')"
                            :disabled="!canManage || busy"
                        />

                        <FloatingInput
                            v-model="form.link_url"
                            :label="t('social.composer_manager.fields.link_url')"
                            :disabled="!canManage || busy"
                        />

                        <DateTimePicker
                            v-model="form.scheduled_for"
                            :label="t('social.composer_manager.fields.scheduled_for')"
                            :disabled="!canManage || busy"
                        />
                    </div>

                    <div class="mt-4 flex flex-wrap items-center gap-2">
                        <PrimaryButton type="button" :disabled="busy || !canManage" @click="submit">
                            {{ activeDraftId ? t('social.composer_manager.actions.update_draft') : t('social.composer_manager.actions.save_draft') }}
                        </PrimaryButton>
                        <PrimaryButton
                            v-if="canPublish"
                            type="button"
                            :disabled="busy || isLoading || currentStatus === 'publishing' || currentStatus === 'published' || (isQueuedPublication && currentStatus === 'scheduled')"
                            @click="publishDraft('publish')"
                        >
                            {{ t('social.composer_manager.actions.publish_now') }}
                        </PrimaryButton>
                        <SecondaryButton
                            v-if="canPublish"
                            type="button"
                            :disabled="busy || isLoading || currentStatus === 'publishing' || currentStatus === 'published' || (!form.scheduled_for && currentStatus !== 'scheduled') || (isQueuedPublication && currentStatus === 'scheduled')"
                            @click="publishDraft('schedule')"
                        >
                            {{ t('social.composer_manager.actions.schedule_post') }}
                        </SecondaryButton>
                        <SecondaryButton type="button" :disabled="busy" @click="resetForm">
                            {{ t('social.composer_manager.actions.clear_form') }}
                        </SecondaryButton>
                    </div>
                </div>

                <div class="rounded-3xl border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <div>
                        <h4 class="text-base font-semibold text-stone-900 dark:text-neutral-100">
                            {{ t('social.composer_manager.targets_title') }}
                        </h4>
                        <p class="mt-1 text-sm text-stone-500 dark:text-neutral-400">
                            {{ t('social.composer_manager.targets_description') }}
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
                            {{ t('social.composer_manager.empty_connected_title') }}
                        </div>
                        <div class="mt-1">
                            {{ t('social.composer_manager.empty_connected_description') }}
                        </div>
                    </div>
                </div>

                <div class="rounded-3xl border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <h4 class="text-base font-semibold text-stone-900 dark:text-neutral-100">
                                {{ t('social.composer_manager.drafts_title') }}
                            </h4>
                            <p class="mt-1 text-sm text-stone-500 dark:text-neutral-400">
                                {{ t('social.composer_manager.drafts_description') }}
                            </p>
                        </div>
                        <div class="rounded-2xl bg-stone-100 px-3 py-1 text-xs font-semibold text-stone-700 dark:bg-neutral-800 dark:text-neutral-300">
                            {{ Number(summary.drafts || 0) }}
                        </div>
                    </div>

                    <div v-if="sortedDrafts.length" class="mt-4 space-y-3">
                        <button
                            v-for="draft in sortedDrafts"
                            :key="draft.id"
                            type="button"
                            class="w-full rounded-3xl border border-stone-200 bg-stone-50 p-4 text-left transition hover:border-sky-300 dark:border-neutral-700 dark:bg-neutral-800/60 dark:hover:border-sky-500/40"
                            @click="openDraft(draft)"
                        >
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="space-y-1">
                                    <div class="text-sm font-semibold text-stone-900 dark:text-neutral-100">
                                        {{ draftLabel(draft) }}
                                    </div>
                                    <div class="text-xs text-stone-500 dark:text-neutral-400">
                                        {{ t('social.composer_manager.draft_targets', { count: Number(draft.selected_accounts_count || 0) }) }}
                                    </div>
                                </div>

                                <span class="inline-flex items-center rounded-full border px-3 py-1 text-xs font-semibold" :class="statusClass(draft.status)">
                                    {{ t(`social.composer_manager.statuses.${draft.status || 'draft'}`) }}
                                </span>
                            </div>

                            <div class="mt-3 text-xs text-stone-500 dark:text-neutral-400">
                                {{ t('social.composer_manager.last_updated') }}: {{ formatDate(draft.updated_at) }}
                            </div>
                        </button>
                    </div>

                    <div
                        v-else
                        class="mt-4 rounded-3xl border border-dashed border-stone-300 bg-stone-50 px-5 py-6 text-sm text-stone-500 dark:border-neutral-700 dark:bg-neutral-800/60 dark:text-neutral-400"
                    >
                        {{ t('social.composer_manager.empty_drafts') }}
                    </div>
                </div>
            </section>

            <section class="space-y-5">
                <div class="rounded-3xl border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h4 class="text-base font-semibold text-stone-900 dark:text-neutral-100">
                                {{ t('social.composer_manager.preview_title') }}
                            </h4>
                            <p class="mt-1 text-sm text-stone-500 dark:text-neutral-400">
                                {{ t('social.composer_manager.preview_description') }}
                            </p>
                        </div>

                        <span class="inline-flex items-center rounded-full border px-3 py-1 text-xs font-semibold" :class="statusClass(currentStatus)">
                            {{ previewStatus }}
                        </span>
                    </div>

                    <div class="mt-4 space-y-4">
                        <div class="rounded-3xl border border-stone-200 bg-stone-50 p-4 dark:border-neutral-700 dark:bg-neutral-800/60">
                            <div class="text-xs uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                                {{ t('social.composer_manager.preview_targets') }}
                            </div>
                            <div v-if="selectedAccounts.length" class="mt-3 flex flex-wrap gap-2">
                                <span
                                    v-for="account in selectedAccounts"
                                    :key="`preview-account-${account.id}`"
                                    class="rounded-full border border-sky-200 bg-sky-50 px-3 py-1 text-xs font-medium text-sky-700 dark:border-sky-500/20 dark:bg-sky-500/10 dark:text-sky-300"
                                >
                                    {{ account.label }}
                                </span>
                            </div>
                            <div v-else class="mt-2 text-sm text-stone-500 dark:text-neutral-400">
                                {{ t('social.composer_manager.preview_no_targets') }}
                            </div>
                        </div>

                        <div class="rounded-3xl border border-stone-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900">
                            <div class="text-sm whitespace-pre-line text-stone-800 dark:text-neutral-100">
                                {{ form.text || t('social.composer_manager.preview_empty_text') }}
                            </div>

                            <div v-if="form.image_url" class="mt-4 overflow-hidden rounded-3xl border border-stone-200 dark:border-neutral-700">
                                <img :src="form.image_url" :alt="t('social.composer_manager.preview_image_alt')" class="h-52 w-full object-cover">
                            </div>

                            <a
                                v-if="form.link_url"
                                :href="form.link_url"
                                target="_blank"
                                rel="noreferrer"
                                class="mt-4 block rounded-3xl border border-stone-200 bg-stone-50 px-4 py-3 text-sm text-sky-700 hover:text-sky-800 dark:border-neutral-700 dark:bg-neutral-800/70 dark:text-sky-300 dark:hover:text-sky-200"
                            >
                                {{ form.link_url }}
                            </a>

                            <div class="mt-4 text-xs text-stone-500 dark:text-neutral-400">
                                {{ t('social.composer_manager.preview_schedule') }}: {{ form.scheduled_for ? formatDate(form.scheduled_for) : t('social.composer_manager.preview_now') }}
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </div>
</template>
