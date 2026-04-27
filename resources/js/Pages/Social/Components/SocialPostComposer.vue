<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import axios from 'axios';
import { useI18n } from 'vue-i18n';
import DropzoneInput from '@/Components/DropzoneInput.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingTextarea from '@/Components/FloatingTextarea.vue';
import DateTimePicker from '@/Components/DateTimePicker.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import SocialPostQualityPanel from '@/Pages/Social/Components/SocialPostQualityPanel.vue';
import SocialVisualPostPreview from '@/Pages/Social/Components/SocialVisualPostPreview.vue';

const props = defineProps({
    initialConnectedAccounts: {
        type: Array,
        default: () => ([]),
    },
    initialDrafts: {
        type: Array,
        default: () => ([]),
    },
    initialTemplates: {
        type: Array,
        default: () => ([]),
    },
    initialPrefill: {
        type: Object,
        default: () => null,
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
    selectedTemplateId: {
        type: Number,
        default: null,
    },
    initialMediaUrl: {
        type: String,
        default: '',
    },
});

const { t } = useI18n();

const normalizeString = (value) => {
    const resolved = String(value || '').trim();

    return resolved !== '' ? resolved : null;
};
const normalizeAccounts = (payload) => Array.isArray(payload) ? payload : [];
const normalizeDrafts = (payload) => Array.isArray(payload) ? payload : [];
const normalizeTemplates = (payload) => Array.isArray(payload) ? payload : [];
const normalizeSummary = (payload) => payload && typeof payload === 'object' ? payload : {};
const normalizeAccess = (payload) => ({
    can_view: Boolean(payload?.can_view),
    can_manage_posts: Boolean(payload?.can_manage_posts),
    can_publish: Boolean(payload?.can_publish),
    can_submit_for_approval: Boolean(payload?.can_submit_for_approval),
    can_approve: Boolean(payload?.can_approve),
});
const normalizeHashtag = (value) => {
    const resolved = String(value || '').trim().replace(/\s+/g, '');
    if (resolved === '') {
        return null;
    }

    return resolved.startsWith('#')
        ? resolved
        : `#${resolved}`;
};
const normalizeHashtagList = (payload) => {
    const seen = new Set();

    return (Array.isArray(payload) ? payload : [])
        .map((value) => normalizeHashtag(value))
        .filter((value) => {
            if (!value) {
                return false;
            }

            const key = value.toLowerCase();
            if (seen.has(key)) {
                return false;
            }

            seen.add(key);

            return true;
        });
};
const normalizeSuggestions = (payload) => ({
    context: {
        locale: normalizeString(payload?.context?.locale),
        source_type: normalizeString(payload?.context?.source_type),
        source_id: Number(payload?.context?.source_id || 0) || null,
        source_label: normalizeString(payload?.context?.source_label),
    },
    captions: (Array.isArray(payload?.captions) ? payload.captions : [])
        .map((caption) => ({
            key: normalizeString(caption?.key),
            label: String(caption?.label || '').trim(),
            text: String(caption?.text || '').trim(),
        }))
        .filter((caption) => caption.label !== '' && caption.text !== ''),
    hashtags: normalizeHashtagList(payload?.hashtags),
    ctas: (Array.isArray(payload?.ctas) ? payload.ctas : [])
        .map((cta) => ({
            key: normalizeString(cta?.key),
            label: String(cta?.label || '').trim(),
            text: String(cta?.text || '').trim(),
        }))
        .filter((cta) => cta.label !== '' && cta.text !== ''),
});
const normalizePrefill = (payload) => {
    if (!payload || typeof payload !== 'object') {
        return null;
    }

    const sourceType = normalizeString(payload?.source_type);
    const sourceId = Number(payload?.source_id || 0) || null;
    if (!sourceType || !sourceId) {
        return null;
    }

    return {
        source_type: sourceType,
        source_id: sourceId,
        source_label: normalizeString(payload?.source_label),
        text: String(payload?.text || ''),
        image_url: String(payload?.image_url || ''),
        link_url: String(payload?.link_url || ''),
        link_cta_label: String(payload?.link_cta_label || ''),
    };
};
const normalizeSourceReference = (payload) => {
    const sourceType = normalizeString(payload?.source_type);
    const sourceId = Number(payload?.source_id || 0) || null;
    if (!sourceType || !sourceId) {
        return null;
    }

    return {
        source_type: sourceType,
        source_id: sourceId,
        source_label: normalizeString(payload?.source_label),
    };
};
const sortByUpdatedAt = (left, right) => {
    const leftDate = Date.parse(String(left?.updated_at || '')) || 0;
    const rightDate = Date.parse(String(right?.updated_at || '')) || 0;

    return rightDate - leftDate;
};
const translateWithFallback = (key, fallback) => {
    const translated = t(key);

    return translated === key ? fallback : translated;
};
const sourceReferenceKey = (source) => (
    source?.source_type && source?.source_id
        ? `${source.source_type}:${source.source_id}`
        : ''
);
const sourceTypeLabelFor = (sourceType) => translateWithFallback(
    `social.composer_manager.sources.${sourceType}`,
    sourceType
);
const sourceDisplayLabelFor = (source) => {
    if (!source?.source_type || !source?.source_id) {
        return '';
    }

    const typeLabel = sourceTypeLabelFor(source.source_type);
    const sourceLabel = String(source.source_label || '').trim();

    return sourceLabel !== ''
        ? `${typeLabel} ${sourceLabel}`
        : `${typeLabel} #${source.source_id}`;
};
const sourceHrefFor = (source) => {
    const sourceId = Number(source?.source_id || 0);
    if (!source?.source_type || sourceId <= 0) {
        return null;
    }

    if (source.source_type === 'product') {
        return route('product.show', sourceId);
    }

    if (source.source_type === 'campaign') {
        return route('campaigns.show', sourceId);
    }

    if (source.source_type === 'promotion') {
        return route('promotions.index');
    }

    if (source.source_type === 'service') {
        return route('service.index');
    }

    return null;
};

const connectedAccounts = ref(normalizeAccounts(props.initialConnectedAccounts));
const drafts = ref(normalizeDrafts(props.initialDrafts));
const templates = ref(normalizeTemplates(props.initialTemplates));
const prefillPayload = ref(normalizePrefill(props.initialPrefill));
const summary = ref(normalizeSummary(props.initialSummary));
const access = ref(normalizeAccess(props.initialAccess));
const activeDraftId = ref(props.selectedDraftId);
const requestedTemplateId = ref(props.selectedTemplateId);
const lastAppliedTemplateId = ref(null);
const lastAppliedPrefillKey = ref('');
const draftSnapshot = ref(null);
const busy = ref(false);
const isLoading = ref(false);
const suggestionsLoading = ref(false);
const error = ref('');
const info = ref('');
const templateName = ref('');
const hashtagDraft = ref('');
const suggestions = ref(normalizeSuggestions(null));
const sourceReference = ref(normalizeSourceReference(props.initialPrefill));
const imageFile = ref(null);
const localImagePreviewUrl = ref('');
const form = ref({
    text: '',
    image_url: String(props.initialMediaUrl || '').trim(),
    link_url: '',
    link_cta_label: '',
    scheduled_for: '',
    target_connection_ids: [],
});

const canManage = computed(() => Boolean(access.value.can_manage_posts));
const canPublish = computed(() => Boolean(access.value.can_publish));
const canSubmitForApproval = computed(() => (
    Boolean(access.value.can_submit_for_approval) && !canPublish.value
));
const canApprove = computed(() => Boolean(access.value.can_approve));
const canView = computed(() => Boolean(access.value.can_view));

const sortedDrafts = computed(() => [...drafts.value].sort(sortByUpdatedAt));
const sortedTemplates = computed(() => [...templates.value].sort(sortByUpdatedAt));

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
const approvalRequest = computed(() => draftSnapshot.value?.approval_request || null);
const isApprovalLocked = computed(() => currentStatus.value === 'pending_approval');
const isEditDisabled = computed(() => !canManage.value || busy.value || isApprovalLocked.value);
const sourceDisplayName = computed(() => sourceDisplayLabelFor(sourceReference.value));
const sourceHref = computed(() => sourceHrefFor(sourceReference.value));
const hasSuggestions = computed(() => (
    suggestions.value.captions.length > 0
    || suggestions.value.hashtags.length > 0
    || suggestions.value.ctas.length > 0
));
const suggestionsActionLabel = computed(() => (
    hasSuggestions.value
        ? t('social.composer_manager.actions.refresh_suggestions')
        : t('social.composer_manager.actions.generate_suggestions')
));
const hashtagsLine = computed(() => suggestions.value.hashtags.join(' '));
const approvalRequestedMode = computed(() => (
    String(approvalRequest.value?.requested_mode || '').trim() || (form.value.scheduled_for ? 'scheduled' : 'immediate')
));
const approvalRequestActor = computed(() => String(
    approvalRequest.value?.requested_by?.name
    || approvalRequest.value?.resolved_by?.name
    || ''
).trim());
const approvalRequestDate = computed(() => (
    approvalRequest.value?.requested_at
    || approvalRequest.value?.rejected_at
    || approvalRequest.value?.approved_at
    || null
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
const previewImageSrc = computed(() => localImagePreviewUrl.value || String(form.value.image_url || '').trim());
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
const previewLinkLabel = computed(() => (
    String(form.value.link_cta_label || '').trim() || t('social.composer_manager.preview_cta_fallback')
));
const recentQualityTexts = computed(() => sortedDrafts.value
    .filter((draft) => Number(draft.id) !== Number(activeDraftId.value))
    .map((draft) => String(draft?.text || '').trim())
    .filter((text) => text !== ''));

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

    const linkSummary = linkSummaryFor(draft);
    if (linkSummary !== '') {
        return linkSummary;
    }

    return t('social.composer_manager.untitled_draft');
};

const templateLabel = (template) => {
    const name = String(template?.name || '').trim();
    if (name !== '') {
        return name;
    }

    const text = String(template?.text || '').trim();
    if (text !== '') {
        return text.length > 70 ? `${text.slice(0, 67)}...` : text;
    }

    const linkSummary = linkSummaryFor(template);
    if (linkSummary !== '') {
        return linkSummary;
    }

    return t('social.composer_manager.untitled_template');
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

const resetSuggestions = () => {
    suggestions.value = normalizeSuggestions(null);
    hashtagDraft.value = '';
};

const revokeLocalImagePreview = () => {
    if (localImagePreviewUrl.value.startsWith('blob:')) {
        URL.revokeObjectURL(localImagePreviewUrl.value);
    }

    localImagePreviewUrl.value = '';
};

const clearImageSelection = () => {
    imageFile.value = null;
};

const syncFormFromDraft = (draft) => {
    draftSnapshot.value = draft ? { ...draft } : null;
    templateName.value = '';
    resetSuggestions();
    sourceReference.value = normalizeSourceReference(draft);
    clearImageSelection();
    form.value = {
        text: String(draft?.text || ''),
        image_url: String(draft?.image_url || ''),
        link_url: String(draft?.link_url || ''),
        link_cta_label: String(draft?.link_cta_label || ''),
        scheduled_for: String(draft?.scheduled_for || ''),
        target_connection_ids: Array.isArray(draft?.selected_target_connection_ids)
            ? draft.selected_target_connection_ids.map((id) => Number(id)).filter((id) => id > 0)
            : [],
    };
};

const resetForm = () => {
    activeDraftId.value = null;
    requestedTemplateId.value = null;
    lastAppliedTemplateId.value = null;
    draftSnapshot.value = null;
    templateName.value = '';
    resetSuggestions();
    sourceReference.value = null;
    clearImageSelection();
    form.value = {
        text: '',
        image_url: '',
        link_url: '',
        link_cta_label: '',
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

    if (Array.isArray(payload?.templates)) {
        templates.value = normalizeTemplates(payload.templates);
    }

    if (Array.isArray(payload?.connected_accounts)) {
        connectedAccounts.value = normalizeAccounts(payload.connected_accounts);
    }

    if (payload?.summary) {
        summary.value = normalizeSummary(payload.summary);
    }

    if (payload?.prefill !== undefined) {
        prefillPayload.value = normalizePrefill(payload.prefill);
    }

    if (payload?.access) {
        access.value = normalizeAccess(payload.access);
    }

    if (payload?.selected_template_id !== undefined) {
        requestedTemplateId.value = Number(payload.selected_template_id || 0) || null;
    }
};

watch(() => props.initialConnectedAccounts, (value) => {
    connectedAccounts.value = normalizeAccounts(value);
}, { deep: true });

watch(() => props.initialDrafts, (value) => {
    drafts.value = normalizeDrafts(value);
}, { deep: true });

watch(() => props.initialTemplates, (value) => {
    templates.value = normalizeTemplates(value);
}, { deep: true });

watch(() => props.initialPrefill, (value) => {
    prefillPayload.value = normalizePrefill(value);
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

watch(() => props.selectedTemplateId, (value) => {
    requestedTemplateId.value = value;
}, { immediate: true });

watch(imageFile, (value) => {
    revokeLocalImagePreview();

    if (value instanceof File) {
        localImagePreviewUrl.value = URL.createObjectURL(value);
    }
});

watch(() => form.value.image_url, (value, previous) => {
    const next = String(value || '').trim();
    const prev = String(previous || '').trim();

    if (next !== '' && next !== prev && imageFile.value instanceof File) {
        clearImageSelection();
    }
});

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

onBeforeUnmount(() => {
    revokeLocalImagePreview();
});

const applyTemplate = (template, { announce = true } = {}) => {
    const availableTargetIds = availableTargetConnectionIds(template?.selected_target_connection_ids);
    const missingTargetCount = Math.max(0, Number(template?.selected_accounts_count || 0) - availableTargetIds.length);

    activeDraftId.value = null;
    requestedTemplateId.value = Number(template?.id || 0) || null;
    lastAppliedTemplateId.value = Number(template?.id || 0) || null;
    draftSnapshot.value = null;
    templateName.value = String(template?.name || '');
    resetSuggestions();
    sourceReference.value = null;
    clearImageSelection();
    form.value = {
        text: String(template?.text || ''),
        image_url: String(template?.image_url || ''),
        link_url: String(template?.link_url || ''),
        link_cta_label: String(template?.link_cta_label || ''),
        scheduled_for: '',
        target_connection_ids: availableTargetIds,
    };
    error.value = '';

    if (announce) {
        info.value = missingTargetCount > 0
            ? t('social.composer_manager.messages.template_applied_with_missing_targets', { count: missingTargetCount })
            : t('social.composer_manager.messages.template_applied');
    }
};

const applyPrefill = (prefill, { announce = true } = {}) => {
    const normalizedPrefill = normalizePrefill(prefill);
    const key = sourceReferenceKey(normalizedPrefill);
    if (!normalizedPrefill || key === '') {
        return;
    }

    activeDraftId.value = null;
    requestedTemplateId.value = null;
    lastAppliedTemplateId.value = null;
    draftSnapshot.value = null;
    templateName.value = '';
    resetSuggestions();
    sourceReference.value = normalizeSourceReference(normalizedPrefill);
    clearImageSelection();
    form.value = {
        text: String(normalizedPrefill.text || ''),
        image_url: String(normalizedPrefill.image_url || ''),
        link_url: String(normalizedPrefill.link_url || ''),
        link_cta_label: String(normalizedPrefill.link_cta_label || ''),
        scheduled_for: '',
        target_connection_ids: [],
    };
    lastAppliedPrefillKey.value = key;
    error.value = '';

    if (announce) {
        info.value = t('social.composer_manager.messages.prefill_applied', {
            source: sourceDisplayLabelFor(normalizedPrefill),
        });
    }
};

const clearSourceReference = () => {
    sourceReference.value = null;
    error.value = '';
    info.value = '';
};

watch([sortedTemplates, requestedTemplateId], () => {
    const templateId = Number(requestedTemplateId.value || 0);
    if (templateId <= 0 || templateId === Number(lastAppliedTemplateId.value || 0)) {
        return;
    }

    const template = sortedTemplates.value.find((item) => Number(item.id) === templateId);
    if (!template) {
        return;
    }

    applyTemplate(template, { announce: true });
}, { immediate: true });

watch([prefillPayload, activeDraftId, requestedTemplateId], () => {
    const prefill = prefillPayload.value;
    const key = sourceReferenceKey(prefill);

    if (!prefill || key === '' || activeDraftId.value || requestedTemplateId.value) {
        return;
    }

    if (key === lastAppliedPrefillKey.value) {
        return;
    }

    applyPrefill(prefill, { announce: true });
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

const appendTextBlock = (base, addition) => {
    const current = String(base || '').trim();
    const next = String(addition || '').trim();

    if (next === '') {
        return current;
    }

    if (current.toLowerCase().includes(next.toLowerCase())) {
        return current;
    }

    return current !== ''
        ? `${current}\n\n${next}`
        : next;
};

const load = async () => {
    isLoading.value = true;
    error.value = '';

    try {
        const params = {};
        if (activeDraftId.value) {
            params.draft = activeDraftId.value;
        }
        if (requestedTemplateId.value) {
            params.template = requestedTemplateId.value;
        }
        if (!activeDraftId.value && !requestedTemplateId.value && sourceReference.value?.source_type && sourceReference.value?.source_id) {
            params.source_type = sourceReference.value.source_type;
            params.source_id = sourceReference.value.source_id;
        }

        const response = await axios.get(route('social.composer', params));
        refreshFromPayload(response.data);

        const selectedId = Number(response.data?.selected_draft_id || 0);
        if (selectedId > 0) {
            activeDraftId.value = selectedId;
        }

        const selectedTemplateId = Number(response.data?.selected_template_id || 0);
        if (selectedTemplateId > 0) {
            requestedTemplateId.value = selectedTemplateId;
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
    requestedTemplateId.value = null;
    syncFormFromDraft(draft);
    error.value = '';
    info.value = '';
};

const loadSuggestions = async () => {
    if (!canView.value) {
        return;
    }

    suggestionsLoading.value = true;
    error.value = '';
    info.value = '';

    try {
        const response = await axios.post(route('social.suggestions'), {
            text: String(form.value.text || '').trim(),
            image_url: String(form.value.image_url || '').trim(),
            link_url: String(form.value.link_url || '').trim(),
            source_type: sourceReference.value?.source_type || null,
            source_id: sourceReference.value?.source_id || null,
        });

        suggestions.value = normalizeSuggestions(response.data?.suggestions);
        info.value = t('social.composer_manager.messages.suggestions_loaded');
    } catch (requestError) {
        error.value = requestErrorMessage(requestError, t('social.composer_manager.messages.suggestions_load_error'));
    } finally {
        suggestionsLoading.value = false;
    }
};

const applyCaptionSuggestion = (caption) => {
    if (!canManage.value) {
        return;
    }

    form.value.text = String(caption?.text || '');
    error.value = '';
    info.value = t('social.composer_manager.messages.caption_applied');
};

const appendHashtagsToText = () => {
    if (!canManage.value) {
        return;
    }

    form.value.text = appendTextBlock(form.value.text, hashtagsLine.value);
    error.value = '';
    info.value = t('social.composer_manager.messages.hashtags_applied');
};

const applyCtaSuggestion = (cta) => {
    if (!canManage.value) {
        return;
    }

    form.value.text = appendTextBlock(form.value.text, String(cta?.text || ''));
    error.value = '';
    info.value = t('social.composer_manager.messages.cta_applied');
};

const addCustomHashtag = () => {
    if (!canManage.value) {
        return;
    }

    const nextHashtag = normalizeHashtag(hashtagDraft.value);
    if (!nextHashtag) {
        return;
    }

    suggestions.value = {
        ...suggestions.value,
        hashtags: normalizeHashtagList([...suggestions.value.hashtags, nextHashtag]),
    };
    hashtagDraft.value = '';
    error.value = '';
    info.value = t('social.composer_manager.messages.hashtag_added');
};

const removeHashtag = (hashtag) => {
    if (!canManage.value) {
        return;
    }

    suggestions.value = {
        ...suggestions.value,
        hashtags: suggestions.value.hashtags.filter((item) => item.toLowerCase() !== String(hashtag || '').toLowerCase()),
    };
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

const composerPayload = () => {
    const payload = {
        text: String(form.value.text || '').trim(),
        image_url: String(form.value.image_url || '').trim(),
        link_url: String(form.value.link_url || '').trim(),
        link_cta_label: String(form.value.link_cta_label || '').trim(),
        scheduled_for: String(form.value.scheduled_for || '').trim(),
        source_type: sourceReference.value?.source_type || null,
        source_id: sourceReference.value?.source_id || null,
        target_connection_ids: form.value.target_connection_ids.map((id) => Number(id)).filter((id) => id > 0),
    };

    if (!(imageFile.value instanceof File)) {
        return payload;
    }

    const formData = new FormData();

    appendFormDataValue(formData, 'text', payload.text);
    appendFormDataValue(formData, 'image_url', payload.image_url);
    appendFormDataValue(formData, 'image_file', imageFile.value);
    appendFormDataValue(formData, 'link_url', payload.link_url);
    appendFormDataValue(formData, 'link_cta_label', payload.link_cta_label);
    appendFormDataValue(formData, 'scheduled_for', payload.scheduled_for);

    if (payload.source_type !== null) {
        appendFormDataValue(formData, 'source_type', payload.source_type);
    }

    if (payload.source_id !== null) {
        appendFormDataValue(formData, 'source_id', payload.source_id);
    }

    appendFormDataValue(formData, 'target_connection_ids', payload.target_connection_ids);

    return formData;
};

const templatePayload = (name) => {
    const payload = {
        name,
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

const saveDraft = async ({ quiet = false } = {}) => {
    if (!canManage.value) {
        return null;
    }

    busy.value = true;
    if (!quiet) {
        error.value = '';
        info.value = '';
    }

    const payload = composerPayload();

    try {
        const response = activeDraftId.value
            ? await putWithPayload(route('social.posts.update', activeDraftId.value), payload)
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

const submitApprovalRequest = async () => {
    if (!canSubmitForApproval.value) {
        return;
    }

    error.value = '';
    info.value = '';

    let draftId = Number(activeDraftId.value || 0);

    if (draftId <= 0) {
        const saved = await saveDraft({ quiet: true });
        draftId = Number(saved?.draft?.id || 0);
    }

    if (draftId <= 0) {
        return;
    }

    busy.value = true;

    try {
        const response = await axios.post(route('social.posts.submit-approval', draftId));

        refreshFromPayload(response.data);

        if (response.data?.draft) {
            activeDraftId.value = Number(response.data.draft.id);
            syncFormFromDraft(response.data.draft);
        }

        info.value = String(response.data?.message || t('social.composer_manager.messages.submit_approval_success'));
    } catch (requestError) {
        error.value = requestErrorMessage(
            requestError,
            t('social.composer_manager.messages.submit_approval_error')
        );
    } finally {
        busy.value = false;
    }
};

const saveAsTemplate = async () => {
    if (!canManage.value) {
        return;
    }

    busy.value = true;
    error.value = '';
    info.value = '';

    const fallbackName = draftLabel({
        text: form.value.text,
        link_url: form.value.link_url,
        link_cta_label: form.value.link_cta_label,
    });

    const requestedTemplateName = String(templateName.value || '').trim() || fallbackName;
    const payload = templatePayload(requestedTemplateName);

    try {
        const response = await axios.post(route('social.templates.store'), payload);
        refreshFromPayload(response.data);
        templateName.value = String(response.data?.template?.name || requestedTemplateName);
        info.value = String(response.data?.message || t('social.composer_manager.messages.template_save_success'));
    } catch (requestError) {
        error.value = requestErrorMessage(requestError, t('social.composer_manager.messages.template_save_error'));
    } finally {
        busy.value = false;
    }
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

const resolveApproval = async (decision) => {
    if (!canApprove.value || currentStatus.value !== 'pending_approval') {
        return;
    }

    const draftId = Number(activeDraftId.value || draftSnapshot.value?.id || 0);
    if (draftId <= 0) {
        return;
    }

    busy.value = true;
    error.value = '';
    info.value = '';

    try {
        const routeName = decision === 'reject'
            ? 'social.posts.reject'
            : 'social.posts.approve';
        const response = await axios.post(route(routeName, draftId));

        refreshFromPayload(response.data);

        if (response.data?.draft) {
            activeDraftId.value = Number(response.data.draft.id);
            syncFormFromDraft(response.data.draft);
        }

        info.value = String(response.data?.message || (
            decision === 'reject'
                ? t('social.composer_manager.messages.reject_success')
                : t('social.composer_manager.messages.approve_success')
        ));
    } catch (requestError) {
        error.value = requestErrorMessage(
            requestError,
            decision === 'reject'
                ? t('social.composer_manager.messages.reject_error')
                : t('social.composer_manager.messages.approve_error')
        );
    } finally {
        busy.value = false;
    }
};
</script>

<template>
    <div class="space-y-5">
        <div class="flex flex-wrap justify-end gap-2">
            <SecondaryButton :disabled="busy || isLoading" @click="load">
                {{ t('social.composer_manager.actions.reload') }}
            </SecondaryButton>
            <SecondaryButton :disabled="busy" @click="resetForm">
                {{ t('social.composer_manager.actions.new_draft') }}
            </SecondaryButton>
        </div>

        <div
            v-if="!access.can_manage_posts"
            class="rounded-3xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-700 dark:border-sky-500/20 dark:bg-sky-500/10 dark:text-sky-300"
        >
            <div class="font-semibold">{{ t('social.composer_manager.read_only_title') }}</div>
            <div class="mt-1">{{ t('social.composer_manager.read_only_description') }}</div>
        </div>

        <div
            v-if="sourceReference"
            class="rounded-3xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-200"
        >
            <div class="font-semibold">{{ t('social.composer_manager.prefill_title') }}</div>
            <div class="mt-1">
                {{ t('social.composer_manager.prefill_description', { source: sourceDisplayName }) }}
            </div>

            <div class="mt-3 flex flex-wrap gap-2">
                <Link v-if="sourceHref" :href="sourceHref">
                    <SecondaryButton type="button">
                        {{ t('social.composer_manager.actions.open_source') }}
                    </SecondaryButton>
                </Link>

                <SecondaryButton type="button" :disabled="busy" @click="clearSourceReference">
                    {{ t('social.composer_manager.actions.clear_source') }}
                </SecondaryButton>
            </div>
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
                    <div
                        v-if="currentStatus === 'pending_approval'"
                        class="mb-4 rounded-3xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-200"
                    >
                        <div class="font-semibold">
                            {{ canApprove
                                ? t('social.composer_manager.approval.pending_actionable_title')
                                : t('social.composer_manager.approval.pending_title') }}
                        </div>
                        <div class="mt-1">
                            {{ canApprove
                                ? t('social.composer_manager.approval.pending_actionable_description')
                                : t('social.composer_manager.approval.pending_description') }}
                        </div>
                        <div v-if="approvalRequestActor || approvalRequestDate" class="mt-2 text-xs text-amber-700 dark:text-amber-300">
                            {{ t('social.composer_manager.approval.request_meta', {
                                actor: approvalRequestActor || t('social.composer_manager.empty_value'),
                                date: formatDate(approvalRequestDate),
                            }) }}
                        </div>
                        <div v-if="approvalRequest?.note" class="mt-2 rounded-2xl bg-white/80 px-3 py-2 text-xs text-amber-800 dark:bg-neutral-900/60 dark:text-amber-100">
                            {{ approvalRequest.note }}
                        </div>
                    </div>

                    <div
                        v-else-if="approvalRequest?.status === 'rejected'"
                        class="mb-4 rounded-3xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-300"
                    >
                        <div class="font-semibold">
                            {{ t('social.composer_manager.approval.rejected_title') }}
                        </div>
                        <div class="mt-1">
                            {{ t('social.composer_manager.approval.rejected_description') }}
                        </div>
                        <div v-if="approvalRequestActor || approvalRequestDate" class="mt-2 text-xs text-rose-600 dark:text-rose-300">
                            {{ t('social.composer_manager.approval.resolve_meta', {
                                actor: approvalRequestActor || t('social.composer_manager.empty_value'),
                                date: formatDate(approvalRequestDate),
                            }) }}
                        </div>
                        <div v-if="approvalRequest?.note" class="mt-2 rounded-2xl bg-white/80 px-3 py-2 text-xs text-rose-700 dark:bg-neutral-900/60 dark:text-rose-200">
                            {{ approvalRequest.note }}
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-4">
                        <FloatingTextarea
                            v-model="form.text"
                            :label="t('social.composer_manager.fields.text')"
                            :disabled="isEditDisabled"
                        />

                        <DropzoneInput
                            v-model="imageInputModel"
                            :label="t('social.composer_manager.fields.image_file')"
                        />

                        <FloatingInput
                            v-model="form.image_url"
                            type="url"
                            :label="t('social.composer_manager.fields.image_url')"
                            placeholder="https://example.com/image.jpg"
                            autocomplete="url"
                            :disabled="isEditDisabled"
                        />

                        <FloatingInput
                            v-model="form.link_url"
                            type="url"
                            :label="t('social.composer_manager.fields.link_url')"
                            placeholder="https://example.com"
                            autocomplete="url"
                            :disabled="isEditDisabled"
                        />

                        <FloatingInput
                            v-model="form.link_cta_label"
                            :label="t('social.composer_manager.fields.link_cta_label')"
                            placeholder="Voir les details"
                            :disabled="isEditDisabled"
                        />

                        <DateTimePicker
                            v-model="form.scheduled_for"
                            :label="t('social.composer_manager.fields.scheduled_for')"
                            :disabled="isEditDisabled"
                        />
                    </div>

                    <div class="mt-4 flex flex-wrap items-center gap-2">
                        <PrimaryButton type="button" :disabled="busy || !canManage || isApprovalLocked" @click="submit">
                            {{ activeDraftId ? t('social.composer_manager.actions.update_draft') : t('social.composer_manager.actions.save_draft') }}
                        </PrimaryButton>
                        <PrimaryButton
                            v-if="canSubmitForApproval"
                            type="button"
                            :disabled="busy || isLoading || currentStatus === 'publishing' || currentStatus === 'published' || currentStatus === 'pending_approval'"
                            @click="submitApprovalRequest"
                        >
                            {{ t('social.composer_manager.actions.submit_for_approval') }}
                        </PrimaryButton>
                        <PrimaryButton
                            v-if="canPublish && currentStatus !== 'pending_approval'"
                            type="button"
                            :disabled="busy || isLoading || currentStatus === 'publishing' || currentStatus === 'published' || (isQueuedPublication && currentStatus === 'scheduled')"
                            @click="publishDraft('publish')"
                        >
                            {{ t('social.composer_manager.actions.publish_now') }}
                        </PrimaryButton>
                        <SecondaryButton
                            v-if="canPublish && currentStatus !== 'pending_approval'"
                            type="button"
                            :disabled="busy || isLoading || currentStatus === 'publishing' || currentStatus === 'published' || (!form.scheduled_for && currentStatus !== 'scheduled') || (isQueuedPublication && currentStatus === 'scheduled')"
                            @click="publishDraft('schedule')"
                        >
                            {{ t('social.composer_manager.actions.schedule_post') }}
                        </SecondaryButton>
                        <PrimaryButton
                            v-if="canApprove && currentStatus === 'pending_approval'"
                            type="button"
                            :disabled="busy || isLoading"
                            @click="resolveApproval('approve')"
                        >
                            {{ approvalRequestedMode === 'scheduled'
                                ? t('social.composer_manager.actions.approve_schedule')
                                : t('social.composer_manager.actions.approve_post') }}
                        </PrimaryButton>
                        <SecondaryButton
                            v-if="canApprove && currentStatus === 'pending_approval'"
                            type="button"
                            :disabled="busy || isLoading"
                            @click="resolveApproval('reject')"
                        >
                            {{ t('social.composer_manager.actions.reject_post') }}
                        </SecondaryButton>
                        <SecondaryButton type="button" :disabled="busy" @click="resetForm">
                            {{ t('social.composer_manager.actions.clear_form') }}
                        </SecondaryButton>
                    </div>
                </div>

                <div class="rounded-3xl border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h4 class="text-base font-semibold text-stone-900 dark:text-neutral-100">
                                {{ t('social.composer_manager.suggestions_title') }}
                            </h4>
                            <p class="mt-1 text-sm text-stone-500 dark:text-neutral-400">
                                {{ t('social.composer_manager.suggestions_description') }}
                            </p>
                        </div>

                        <SecondaryButton
                            type="button"
                            :disabled="busy || suggestionsLoading || !canView"
                            @click="loadSuggestions"
                        >
                            {{ suggestionsActionLabel }}
                        </SecondaryButton>
                    </div>

                    <div v-if="hasSuggestions" class="mt-4 space-y-4">
                        <div>
                            <div class="text-xs uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                                {{ t('social.composer_manager.suggestions_captions_title') }}
                            </div>

                            <div class="mt-3 grid grid-cols-1 gap-3 md:grid-cols-3">
                                <div
                                    v-for="caption in suggestions.captions"
                                    :key="caption.key || caption.label"
                                    class="rounded-3xl border border-stone-200 bg-stone-50 p-4 dark:border-neutral-700 dark:bg-neutral-800/60"
                                >
                                    <div class="text-xs font-semibold uppercase tracking-[0.18em] text-stone-500 dark:text-neutral-400">
                                        {{ caption.label }}
                                    </div>
                                    <div class="mt-3 text-sm whitespace-pre-line text-stone-700 dark:text-neutral-200">
                                        {{ caption.text }}
                                    </div>
                                    <div class="mt-4">
                                        <SecondaryButton
                                            type="button"
                                            :disabled="busy || !canManage || isApprovalLocked"
                                            @click="applyCaptionSuggestion(caption)"
                                        >
                                            {{ t('social.composer_manager.actions.apply_caption') }}
                                        </SecondaryButton>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 gap-4 lg:grid-cols-[1.1fr,0.9fr]">
                            <div class="rounded-3xl border border-stone-200 bg-stone-50 p-4 dark:border-neutral-700 dark:bg-neutral-800/60">
                                <div class="flex flex-wrap items-center justify-between gap-3">
                                    <div>
                                        <div class="text-xs uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                                            {{ t('social.composer_manager.suggestions_hashtags_title') }}
                                        </div>
                                        <p class="mt-1 text-sm text-stone-500 dark:text-neutral-400">
                                            {{ t('social.composer_manager.suggestions_hashtags_description') }}
                                        </p>
                                    </div>

                                    <SecondaryButton
                                        type="button"
                                        :disabled="busy || !canManage || isApprovalLocked || !suggestions.hashtags.length"
                                        @click="appendHashtagsToText"
                                    >
                                        {{ t('social.composer_manager.actions.append_hashtags') }}
                                    </SecondaryButton>
                                </div>

                                <div v-if="suggestions.hashtags.length" class="mt-4 flex flex-wrap gap-2">
                                    <span
                                        v-for="hashtag in suggestions.hashtags"
                                        :key="hashtag"
                                        class="inline-flex items-center gap-2 rounded-full border border-stone-200 bg-white px-3 py-1 text-xs font-medium text-stone-700 dark:border-neutral-600 dark:bg-neutral-900 dark:text-neutral-200"
                                    >
                                        {{ hashtag }}
                                        <button
                                            type="button"
                                            class="text-stone-400 transition hover:text-rose-500 dark:text-neutral-500 dark:hover:text-rose-300"
                                            :disabled="!canManage || isApprovalLocked"
                                            @click="removeHashtag(hashtag)"
                                        >
                                            ×
                                        </button>
                                    </span>
                                </div>

                                <div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-[1fr,auto]">
                                    <FloatingInput
                                        v-model="hashtagDraft"
                                        :label="t('social.composer_manager.fields.custom_hashtag')"
                                        :disabled="isEditDisabled"
                                    />

                                    <SecondaryButton type="button" :disabled="busy || !canManage || isApprovalLocked" @click="addCustomHashtag">
                                        {{ t('social.composer_manager.actions.add_hashtag') }}
                                    </SecondaryButton>
                                </div>
                            </div>

                            <div class="rounded-3xl border border-stone-200 bg-stone-50 p-4 dark:border-neutral-700 dark:bg-neutral-800/60">
                                <div>
                                    <div class="text-xs uppercase tracking-[0.18em] text-stone-400 dark:text-neutral-500">
                                        {{ t('social.composer_manager.suggestions_ctas_title') }}
                                    </div>
                                    <p class="mt-1 text-sm text-stone-500 dark:text-neutral-400">
                                        {{ t('social.composer_manager.suggestions_ctas_description') }}
                                    </p>
                                </div>

                                <div class="mt-4 space-y-3">
                                    <div
                                        v-for="cta in suggestions.ctas"
                                        :key="cta.key || cta.label"
                                        class="rounded-3xl border border-stone-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900"
                                    >
                                        <div class="text-sm font-semibold text-stone-900 dark:text-neutral-100">
                                            {{ cta.label }}
                                        </div>
                                        <div class="mt-2 text-sm text-stone-600 dark:text-neutral-300">
                                            {{ cta.text }}
                                        </div>
                                        <div class="mt-4">
                                            <SecondaryButton
                                                type="button"
                                                :disabled="busy || !canManage || isApprovalLocked"
                                                @click="applyCtaSuggestion(cta)"
                                            >
                                                {{ t('social.composer_manager.actions.append_cta') }}
                                            </SecondaryButton>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div
                        v-else
                        class="mt-4 rounded-3xl border border-dashed border-stone-300 bg-stone-50 px-5 py-6 text-sm text-stone-500 dark:border-neutral-700 dark:bg-neutral-800/60 dark:text-neutral-400"
                    >
                        {{ t('social.composer_manager.empty_suggestions') }}
                    </div>
                </div>

                <div class="rounded-3xl border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h4 class="text-base font-semibold text-stone-900 dark:text-neutral-100">
                                {{ t('social.composer_manager.templates_title') }}
                            </h4>
                            <p class="mt-1 text-sm text-stone-500 dark:text-neutral-400">
                                {{ t('social.composer_manager.templates_description') }}
                            </p>
                        </div>

                        <Link :href="route('social.templates.index')">
                            <SecondaryButton type="button">
                                {{ t('social.composer_manager.actions.open_templates') }}
                            </SecondaryButton>
                        </Link>
                    </div>

                    <div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-[1fr,auto]">
                        <FloatingInput
                            v-model="templateName"
                            :label="t('social.composer_manager.fields.template_name')"
                            :disabled="isEditDisabled"
                        />

                        <PrimaryButton type="button" :disabled="busy || !canManage || isApprovalLocked" @click="saveAsTemplate">
                            {{ t('social.composer_manager.actions.save_as_template') }}
                        </PrimaryButton>
                    </div>

                    <div v-if="sortedTemplates.length" class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-2">
                        <div
                            v-for="template in sortedTemplates.slice(0, 4)"
                            :key="template.id"
                            class="rounded-3xl border border-stone-200 bg-stone-50 p-4 dark:border-neutral-700 dark:bg-neutral-800/60"
                        >
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="space-y-1">
                                    <div class="text-sm font-semibold text-stone-900 dark:text-neutral-100">
                                        {{ templateLabel(template) }}
                                    </div>
                                    <div class="text-xs text-stone-500 dark:text-neutral-400">
                                        {{ t('social.composer_manager.template_targets', { count: Number(template.selected_accounts_count || 0) }) }}
                                    </div>
                                </div>

                                <div class="text-xs text-stone-500 dark:text-neutral-400">
                                    {{ formatDate(template.updated_at) }}
                                </div>
                            </div>

                            <div class="mt-3 line-clamp-3 text-sm text-stone-600 dark:text-neutral-300">
                                {{ template.text || linkSummaryFor(template) || t('social.composer_manager.untitled_template') }}
                            </div>

                            <div class="mt-4 flex flex-wrap gap-2">
                                <SecondaryButton type="button" :disabled="busy" @click="applyTemplate(template)">
                                    {{ t('social.composer_manager.actions.apply_template') }}
                                </SecondaryButton>

                                <Link :href="route('social.templates.index', { template: template.id })">
                                    <SecondaryButton type="button">
                                        {{ t('social.composer_manager.actions.manage_templates') }}
                                    </SecondaryButton>
                                </Link>
                            </div>
                        </div>
                    </div>

                    <div
                        v-else
                        class="mt-4 rounded-3xl border border-dashed border-stone-300 bg-stone-50 px-5 py-6 text-sm text-stone-500 dark:border-neutral-700 dark:bg-neutral-800/60 dark:text-neutral-400"
                    >
                        {{ t('social.composer_manager.empty_templates') }}
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
                            :disabled="!canManage || busy || isApprovalLocked"
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

            <section class="space-y-4">
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

                <SocialPostQualityPanel
                    :text="form.text"
                    :image-url="previewImageSrc"
                    :link-url="form.link_url"
                    :link-label="form.link_cta_label"
                    :targets="selectedAccounts"
                    :recent-texts="recentQualityTexts"
                />

                <SocialVisualPostPreview
                    :text="form.text"
                    :image-url="previewImageSrc"
                    :link-url="form.link_url"
                    :link-label="previewLinkLabel"
                    :targets="selectedAccounts"
                    :empty-text="t('social.composer_manager.preview_empty_text')"
                    compact
                />
            </section>
        </div>
    </div>
</template>
