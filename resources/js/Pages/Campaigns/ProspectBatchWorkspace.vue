<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';

const props = defineProps({
    campaign: { type: Object, required: true },
    batch: { type: Object, required: true },
    batches: { type: Array, default: () => [] },
    summary: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
    prospects: { type: Object, default: () => ({}) },
    access: { type: Object, default: () => ({}) },
});

const { t } = useI18n();

const canManage = computed(() => Boolean(props.access?.can_manage));
const campaignId = computed(() => props.campaign?.id || null);
const wizardStepStorageKey = 'campaign-wizard-next-step';

const prospectingBatches = ref(Array.isArray(props.batches) ? props.batches : []);
const prospectingBatchSummary = ref(props.summary || null);
const activeProspectBatchId = ref(Number(props.batch?.id || 0) || null);
const activeProspectBatch = ref(props.batch || null);
const activeProspectPagination = ref(props.prospects || null);
const activeProspectRows = ref(Array.isArray(props.prospects?.data) ? props.prospects.data : []);
const activeProspectId = ref(null);
const activeProspectDetail = ref(null);
const activeProspectBusy = ref(false);
const activeProspectError = ref('');
const activeProspectMessage = ref('');
const prospectActionBusy = ref(false);
const prospectingBatchBusy = ref(false);
const prospectingBatchError = ref('');
const prospectingBatchMessage = ref('');
const prospectingReviewBusy = ref(false);
const prospectingFilters = ref({
    search: String(props.filters?.search || ''),
    status: String(props.filters?.status || ''),
    match_status: String(props.filters?.match_status || ''),
});
const prospectingSort = ref('priority_desc');
const selectedProspectIds = ref([]);
const prospectingBulkBusy = ref(false);
const prospectingBulkError = ref('');
const prospectingBulkMessage = ref('');
const leadLinkSearch = ref('');
const leadLinkOptions = ref([]);
const selectedLeadId = ref(null);
const leadLinkBusy = ref(false);
const leadLinkError = ref('');

const humanizeValue = (value) => String(value || '')
    .replaceAll('_', ' ')
    .toLowerCase()
    .replace(/\b\w/g, (char) => char.toUpperCase());

const translateWithFallback = (key, fallback) => {
    const translated = t(key);
    return translated === key ? fallback : translated;
};

const formatDateTime = (value) => {
    if (!value) {
        return '-';
    }

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return '-';
    }

    return date.toLocaleString();
};

const prospectBatchStatusLabel = (value) => {
    const normalized = String(value || '').toLowerCase();
    if (!normalized) return '-';
    return translateWithFallback(`marketing.campaign_wizard.prospecting.batch_statuses.${normalized}`, humanizeValue(value));
};

const prospectStatusLabel = (value) => {
    const normalized = String(value || '').toLowerCase();
    if (!normalized) return '-';
    return translateWithFallback(`marketing.campaign_wizard.prospecting.prospect_statuses.${normalized}`, humanizeValue(value));
};

const prospectMatchStatusLabel = (value) => {
    const normalized = String(value || '').toLowerCase();
    if (!normalized) return '-';
    return translateWithFallback(`marketing.campaign_wizard.prospecting.match_statuses.${normalized}`, humanizeValue(value));
};

function CampaignProspectStatusOption(value) {
    return {
        value,
        label: prospectStatusLabel(value),
    };
}

function CampaignProspectMatchOption(value) {
    return {
        value,
        label: prospectMatchStatusLabel(value),
    };
}

const prospectingStatusOptions = computed(() => ([
    { value: '', label: t('marketing.campaign_wizard.prospecting.filters.all_statuses') },
    CampaignProspectStatusOption('scored'),
    CampaignProspectStatusOption('approved'),
    CampaignProspectStatusOption('contacted'),
    CampaignProspectStatusOption('follow_up_due'),
    CampaignProspectStatusOption('replied'),
    CampaignProspectStatusOption('qualified'),
    CampaignProspectStatusOption('converted_to_lead'),
    CampaignProspectStatusOption('duplicate'),
    CampaignProspectStatusOption('blocked'),
    CampaignProspectStatusOption('disqualified'),
    CampaignProspectStatusOption('do_not_contact'),
]));

const prospectingMatchStatusOptions = computed(() => ([
    { value: '', label: t('marketing.campaign_wizard.prospecting.filters.all_match_statuses') },
    CampaignProspectMatchOption('none'),
    CampaignProspectMatchOption('matched_customer'),
    CampaignProspectMatchOption('matched_lead'),
    CampaignProspectMatchOption('matched_prospect'),
    CampaignProspectMatchOption('blocked_destination'),
    CampaignProspectMatchOption('manual_review_required'),
]));

const prospectingSortOptions = computed(() => ([
    { value: 'priority_desc', label: t('marketing.campaign_wizard.prospecting.sorts.priority_desc') },
    { value: 'fit_desc', label: t('marketing.campaign_wizard.prospecting.sorts.fit_desc') },
    { value: 'intent_desc', label: t('marketing.campaign_wizard.prospecting.sorts.intent_desc') },
    { value: 'status_asc', label: t('marketing.campaign_wizard.prospecting.sorts.status_asc') },
    { value: 'identity_asc', label: t('marketing.campaign_wizard.prospecting.sorts.identity_asc') },
]));

const activeProspectMeta = computed(() => {
    const pagination = activeProspectPagination.value;

    return {
        currentPage: Number(pagination?.current_page || 1),
        lastPage: Number(pagination?.last_page || 1),
        total: Number(pagination?.total || activeProspectRows.value.length || 0),
    };
});

const prospectIdentity = (prospect) => {
    return String(prospect?.contact_name || '').trim()
        || String(prospect?.company_name || '').trim()
        || String(prospect?.email || '').trim()
        || String(prospect?.phone || '').trim()
        || `#${prospect?.id || '-'}`;
};

const displayedProspectRows = computed(() => {
    const rows = Array.isArray(activeProspectRows.value) ? [...activeProspectRows.value] : [];

    return rows.sort((left, right) => {
        const mode = String(prospectingSort.value || 'priority_desc');
        if (mode === 'fit_desc') {
            return Number(right?.fit_score || 0) - Number(left?.fit_score || 0);
        }
        if (mode === 'intent_desc') {
            return Number(right?.intent_score || 0) - Number(left?.intent_score || 0);
        }
        if (mode === 'status_asc') {
            return prospectStatusLabel(left?.status).localeCompare(prospectStatusLabel(right?.status));
        }
        if (mode === 'identity_asc') {
            return prospectIdentity(left).localeCompare(prospectIdentity(right));
        }

        return Number(right?.priority_score || 0) - Number(left?.priority_score || 0);
    });
});

const selectedProspectCount = computed(() => selectedProspectIds.value.length);
const displayedProspectIds = computed(() => displayedProspectRows.value.map((prospect) => Number(prospect?.id || 0)).filter((id) => id > 0));
const allDisplayedProspectsSelected = computed(() => (
    displayedProspectIds.value.length > 0
    && displayedProspectIds.value.every((id) => selectedProspectIds.value.includes(id))
));

const canReviewActiveBatch = computed(() => Boolean(
    canManage.value
    && activeProspectBatch.value?.id
    && String(activeProspectBatch.value?.status || '') === 'analyzed'
    && !prospectingReviewBusy.value
));

const canManageActiveProspect = computed(() => Boolean(
    canManage.value
    && activeProspectDetail.value?.id
    && !['converted_to_lead', 'converted_to_customer'].includes(String(activeProspectDetail.value?.status || ''))
    && !prospectActionBusy.value
));

const canConvertActiveProspect = computed(() => Boolean(
    canManage.value
    && activeProspectDetail.value?.id
    && !activeProspectDetail.value?.converted_lead?.id
    && String(activeProspectDetail.value?.status || '') !== 'converted_to_customer'
    && !prospectActionBusy.value
));

const canSearchLeadOptions = computed(() => Boolean(
    canManage.value
    && campaignId.value
    && activeProspectDetail.value?.id
    && !activeProspectDetail.value?.converted_lead?.id
    && !leadLinkBusy.value
));

const canLinkActiveProspect = computed(() => Boolean(
    canManage.value
    && activeProspectDetail.value?.id
    && !activeProspectDetail.value?.converted_lead?.id
    && Number(selectedLeadId.value || 0) > 0
    && !prospectActionBusy.value
));

const batchPageTitle = computed(() => t('marketing.campaign_wizard.prospecting.batch_label', {
    number: activeProspectBatch.value?.batch_number || props.batch?.batch_number || '-',
}));

const batchMetaText = computed(() => {
    if (!activeProspectBatch.value) {
        return '';
    }

    return t('marketing.campaign_wizard.prospecting.batch_meta', {
        source: activeProspectBatch.value.source_reference || activeProspectBatch.value.source_type,
        status: prospectBatchStatusLabel(activeProspectBatch.value.status),
    });
});

const pageTitle = computed(() => `${props.campaign?.name || `#${props.campaign?.id || ''}`} | ${batchPageTitle.value}`);

const clearSelectedProspects = () => {
    selectedProspectIds.value = [];
};

const toggleProspectSelection = (prospectId, checked) => {
    const normalizedId = Number(prospectId || 0);
    if (normalizedId <= 0) {
        return;
    }

    if (checked) {
        selectedProspectIds.value = [...new Set([...selectedProspectIds.value, normalizedId])];
        return;
    }

    selectedProspectIds.value = selectedProspectIds.value.filter((value) => Number(value || 0) !== normalizedId);
};

const toggleAllDisplayedProspects = (checked) => {
    if (checked) {
        selectedProspectIds.value = [...new Set([...selectedProspectIds.value, ...displayedProspectIds.value])];
        return;
    }

    selectedProspectIds.value = selectedProspectIds.value.filter((value) => !displayedProspectIds.value.includes(Number(value || 0)));
};

const canBulkApplyStatus = (status) => {
    if (!canManage.value || prospectingBulkBusy.value || selectedProspectIds.value.length === 0) {
        return false;
    }

    const selectedRows = displayedProspectRows.value.filter((row) => selectedProspectIds.value.includes(Number(row?.id || 0)));
    if (selectedRows.length === 0) {
        return false;
    }

    return selectedRows.every((prospect) => {
        const normalizedStatus = String(prospect?.status || '');
        if (status === 'approved') {
            return ['scored', 'approved', 'blocked', 'duplicate', 'disqualified'].includes(normalizedStatus)
                && !prospect?.do_not_contact
                && !['converted_to_lead', 'converted_to_customer'].includes(normalizedStatus);
        }

        if (status === 'do_not_contact') {
            return !prospect?.do_not_contact
                && !['converted_to_lead', 'converted_to_customer'].includes(normalizedStatus);
        }

        if (status === 'disqualified') {
            return !['converted_to_lead', 'converted_to_customer', 'do_not_contact'].includes(normalizedStatus);
        }

        return false;
    });
};

const resetLeadLinkState = () => {
    leadLinkSearch.value = '';
    leadLinkOptions.value = [];
    selectedLeadId.value = null;
    leadLinkError.value = '';
};

const leadOptionLabel = (lead) => {
    const identity = lead?.title || lead?.contact_name || lead?.contact_email || lead?.contact_phone || `#${lead?.id || '-'}`;
    const status = lead?.status ? humanizeValue(lead.status) : '-';
    return `${identity} | ${status}`;
};

const normalizeLeadOption = (lead) => ({
    ...lead,
    label: leadOptionLabel(lead),
});

const syncLeadOptions = (leads = []) => {
    const options = Array.isArray(leads) ? leads.map((lead) => normalizeLeadOption(lead)) : [];
    const matchedLead = activeProspectDetail.value?.matched_lead;
    if (matchedLead?.id && !options.some((lead) => Number(lead?.id || 0) === Number(matchedLead.id))) {
        options.unshift(normalizeLeadOption(matchedLead));
    }

    leadLinkOptions.value = options;
    if (matchedLead?.id && !selectedLeadId.value) {
        selectedLeadId.value = matchedLead.id;
    }
};

const syncActiveProspectRow = (prospect) => {
    if (!prospect?.id) {
        return;
    }

    activeProspectRows.value = activeProspectRows.value.map((row) => (
        Number(row?.id || 0) === Number(prospect.id)
            ? { ...row, ...prospect }
            : row
    ));
};

const syncProspectBatchIntoList = (batch) => {
    if (!batch?.id) {
        return;
    }

    const current = Array.isArray(prospectingBatches.value) ? [...prospectingBatches.value] : [];
    const index = current.findIndex((item) => Number(item?.id || 0) === Number(batch.id));

    if (index >= 0) {
        current[index] = batch;
    } else {
        current.unshift(batch);
    }

    prospectingBatches.value = current.sort((left, right) => Number(right?.batch_number || 0) - Number(left?.batch_number || 0));
};

const prospectRecommendedAction = (prospect) => {
    const status = String(prospect?.status || '');
    if (status === 'approved') return t('marketing.campaign_wizard.prospecting.recommended_actions.approved');
    if (status === 'scored') return t('marketing.campaign_wizard.prospecting.recommended_actions.approve');
    if (status === 'duplicate') return t('marketing.campaign_wizard.prospecting.recommended_actions.duplicate');
    if (status === 'blocked') return t('marketing.campaign_wizard.prospecting.recommended_actions.blocked');
    if (status === 'disqualified') return t('marketing.campaign_wizard.prospecting.recommended_actions.reject');
    return t('marketing.campaign_wizard.prospecting.recommended_actions.review');
};

const prospectSequenceSummary = (prospect) => {
    const sequence = prospect?.metadata?.sequence || {};
    const currentStep = Number(sequence?.current_step || 0);
    const phase = String(sequence?.current_phase || '').trim();
    const nextFollowUpAt = sequence?.next_follow_up_at ? formatDateTime(sequence.next_follow_up_at) : '-';
    const stopReason = String(sequence?.stop_reason || '').trim();

    if (!currentStep && !phase && !stopReason) {
        return t('marketing.campaign_wizard.prospecting.sequence.not_started');
    }

    if (stopReason) {
        return t('marketing.campaign_wizard.prospecting.sequence.stopped', {
            reason: humanizeValue(stopReason),
        });
    }

    return t('marketing.campaign_wizard.prospecting.sequence.progress', {
        step: currentStep || 0,
        phase: phase ? humanizeValue(phase) : '-',
        next: nextFollowUpAt,
    });
};

const rememberAudienceStep = () => {
    if (typeof window === 'undefined') {
        return;
    }

    window.sessionStorage.setItem(wizardStepStorageKey, '2');
};

const returnToWizard = () => {
    if (!campaignId.value) {
        return;
    }

    rememberAudienceStep();
    router.visit(route('campaigns.edit', campaignId.value));
};

const openBatchWorkspace = (batchId) => {
    const normalizedBatchId = Number(batchId || 0);
    if (!campaignId.value || normalizedBatchId <= 0) {
        return;
    }

    router.visit(route('campaigns.prospect-batches.workspace', [campaignId.value, normalizedBatchId]));
};

const loadProspectBatches = async () => {
    if (!campaignId.value) {
        return;
    }

    try {
        const response = await axios.get(route('campaigns.prospect-batches.index', campaignId.value), {
            params: { limit: 12 },
        });

        prospectingBatches.value = Array.isArray(response.data?.batches) ? response.data.batches : [];
        prospectingBatchSummary.value = response.data?.summary || null;
    } catch (error) {
        prospectingBatchError.value = error?.response?.data?.message || error?.message || t('marketing.campaign_wizard.prospecting.errors.load_batches');
    }
};

const loadProspectBatch = async (batchId, page = 1) => {
    if (!campaignId.value || !batchId) {
        return;
    }

    prospectingBatchBusy.value = true;
    prospectingBatchError.value = '';

    try {
        const response = await axios.get(route('campaigns.prospect-batches.show', [campaignId.value, batchId]), {
            params: {
                page,
                search: prospectingFilters.value.search || undefined,
                status: prospectingFilters.value.status || undefined,
                match_status: prospectingFilters.value.match_status || undefined,
            },
        });

        activeProspectBatchId.value = Number(batchId);
        activeProspectBatch.value = response.data?.batch || null;
        activeProspectPagination.value = response.data?.prospects || null;
        activeProspectRows.value = Array.isArray(response.data?.prospects?.data) ? response.data.prospects.data : [];
        clearSelectedProspects();

        if (activeProspectId.value) {
            const stillVisible = activeProspectRows.value.some((row) => Number(row?.id || 0) === Number(activeProspectId.value));
            if (stillVisible) {
                await loadProspectDetail(activeProspectId.value);
            } else {
                activeProspectId.value = null;
                activeProspectDetail.value = null;
                resetLeadLinkState();
            }
        }

        if (activeProspectBatch.value) {
            syncProspectBatchIntoList(activeProspectBatch.value);
        }
    } catch (error) {
        prospectingBatchError.value = error?.response?.data?.message || error?.message || t('marketing.campaign_wizard.prospecting.errors.load_batch');
    } finally {
        prospectingBatchBusy.value = false;
    }
};

const loadProspectDetail = async (prospectId) => {
    if (!campaignId.value || !prospectId) {
        activeProspectId.value = null;
        activeProspectDetail.value = null;
        activeProspectError.value = '';
        activeProspectMessage.value = '';
        resetLeadLinkState();
        return;
    }

    activeProspectBusy.value = true;
    activeProspectError.value = '';
    activeProspectMessage.value = '';
    leadLinkError.value = '';

    try {
        const response = await axios.get(route('campaigns.prospects.show', [campaignId.value, prospectId]));
        activeProspectId.value = Number(prospectId);
        activeProspectDetail.value = response.data?.prospect || null;
        resetLeadLinkState();
        syncLeadOptions(activeProspectDetail.value?.matched_lead ? [activeProspectDetail.value.matched_lead] : []);
    } catch (error) {
        activeProspectError.value = error?.response?.data?.message || error?.message || t('marketing.campaign_wizard.prospecting.errors.load_prospect');
    } finally {
        activeProspectBusy.value = false;
    }
};

const applyProspectStatus = async (status) => {
    const prospectId = Number(activeProspectDetail.value?.id || 0);
    if (!campaignId.value || prospectId <= 0) {
        return;
    }

    prospectActionBusy.value = true;
    activeProspectError.value = '';
    activeProspectMessage.value = '';

    try {
        const response = await axios.patch(route('campaigns.prospects.status', [campaignId.value, prospectId]), {
            status,
        });

        activeProspectDetail.value = response.data?.prospect || activeProspectDetail.value;
        activeProspectMessage.value = response.data?.message || t('marketing.campaign_wizard.prospecting.messages.prospect_updated');
        syncActiveProspectRow(response.data?.prospect || null);

        await loadProspectBatch(activeProspectBatchId.value, activeProspectMeta.value.currentPage || 1);
        await loadProspectBatches();
    } catch (error) {
        activeProspectError.value = error?.response?.data?.message || error?.message || t('marketing.campaign_wizard.prospecting.errors.update_prospect');
    } finally {
        prospectActionBusy.value = false;
    }
};

const bulkUpdateProspects = async (status) => {
    const prospectIds = selectedProspectIds.value
        .map((value) => Number(value || 0))
        .filter((value) => value > 0);

    if (!campaignId.value || prospectIds.length === 0 || !canBulkApplyStatus(status)) {
        return;
    }

    prospectingBulkBusy.value = true;
    prospectingBulkError.value = '';
    prospectingBulkMessage.value = '';

    try {
        const response = await axios.patch(route('campaigns.prospects.bulk-status', campaignId.value), {
            prospect_ids: prospectIds,
            status,
        });

        const updatedProspects = Array.isArray(response.data?.prospects) ? response.data.prospects : [];
        const updatedMap = new Map(updatedProspects.map((prospect) => [Number(prospect?.id || 0), prospect]));

        activeProspectRows.value = activeProspectRows.value.map((row) => {
            const updated = updatedMap.get(Number(row?.id || 0));
            return updated ? { ...row, ...updated } : row;
        });

        if (activeProspectDetail.value?.id) {
            const updatedActive = updatedMap.get(Number(activeProspectDetail.value.id));
            if (updatedActive) {
                activeProspectDetail.value = updatedActive;
            }
        }

        clearSelectedProspects();
        prospectingBulkMessage.value = response.data?.message || t('marketing.campaign_wizard.prospecting.messages.bulk_updated');

        await loadProspectBatch(activeProspectBatchId.value, activeProspectMeta.value.currentPage || 1);
        await loadProspectBatches();
    } catch (error) {
        prospectingBulkError.value = error?.response?.data?.message || error?.message || t('marketing.campaign_wizard.prospecting.errors.bulk_update');
    } finally {
        prospectingBulkBusy.value = false;
    }
};

const convertActiveProspectToLead = async () => {
    const prospectId = Number(activeProspectDetail.value?.id || 0);
    if (!campaignId.value || prospectId <= 0 || !canConvertActiveProspect.value) {
        return;
    }

    prospectActionBusy.value = true;
    activeProspectError.value = '';
    activeProspectMessage.value = '';

    try {
        const response = await axios.post(route('campaigns.prospects.convert', [campaignId.value, prospectId]));
        const updated = response.data?.prospect || null;

        activeProspectDetail.value = updated || activeProspectDetail.value;
        activeProspectMessage.value = response.data?.created === false
            ? t('marketing.campaign_wizard.prospecting.messages.prospect_linked')
            : t('marketing.campaign_wizard.prospecting.messages.prospect_converted');
        leadLinkError.value = '';

        syncActiveProspectRow(updated);
        await loadProspectBatch(activeProspectBatchId.value, activeProspectMeta.value.currentPage || 1);
        await loadProspectBatches();
    } catch (error) {
        activeProspectError.value = error?.response?.data?.message || error?.message || t('marketing.campaign_wizard.prospecting.errors.convert_prospect');
    } finally {
        prospectActionBusy.value = false;
    }
};

const searchLeadOptions = async () => {
    if (!canSearchLeadOptions.value) {
        return;
    }

    leadLinkBusy.value = true;
    leadLinkError.value = '';

    try {
        const response = await axios.get(route('campaigns.prospects.lead-options', campaignId.value), {
            params: {
                search: String(leadLinkSearch.value || '').trim() || undefined,
                limit: 8,
            },
        });

        syncLeadOptions(response.data?.leads || []);
    } catch (error) {
        leadLinkError.value = error?.response?.data?.message || error?.message || t('marketing.campaign_wizard.prospecting.errors.load_leads');
    } finally {
        leadLinkBusy.value = false;
    }
};

const linkActiveProspectToLead = async (leadId = null) => {
    const prospectId = Number(activeProspectDetail.value?.id || 0);
    const resolvedLeadId = Number(leadId || selectedLeadId.value || 0);
    if (!campaignId.value || prospectId <= 0 || resolvedLeadId <= 0 || !canManage.value) {
        return;
    }

    prospectActionBusy.value = true;
    leadLinkError.value = '';
    activeProspectError.value = '';
    activeProspectMessage.value = '';

    try {
        const response = await axios.post(route('campaigns.prospects.link', [campaignId.value, prospectId]), {
            lead_id: resolvedLeadId,
        });

        const updated = response.data?.prospect || null;
        activeProspectDetail.value = updated || activeProspectDetail.value;
        activeProspectMessage.value = response.data?.message || t('marketing.campaign_wizard.prospecting.messages.prospect_linked');
        syncActiveProspectRow(updated);

        if (updated?.converted_lead?.id) {
            selectedLeadId.value = updated.converted_lead.id;
        }

        await loadProspectBatch(activeProspectBatchId.value, activeProspectMeta.value.currentPage || 1);
        await loadProspectBatches();
    } catch (error) {
        leadLinkError.value = error?.response?.data?.message || error?.message || t('marketing.campaign_wizard.prospecting.errors.link_prospect');
    } finally {
        prospectActionBusy.value = false;
    }
};

const reviewProspectBatch = async (decision) => {
    const batchId = Number(activeProspectBatch.value?.id || 0);
    if (!campaignId.value || batchId <= 0) {
        return;
    }

    prospectingReviewBusy.value = true;
    prospectingBatchError.value = '';
    prospectingBatchMessage.value = '';

    try {
        const routeName = decision === 'approve'
            ? 'campaigns.prospect-batches.approve'
            : 'campaigns.prospect-batches.reject';
        const response = await axios.post(route(routeName, [campaignId.value, batchId]));
        const batch = response.data?.batch || null;

        if (batch) {
            syncProspectBatchIntoList(batch);
        }

        prospectingBatchMessage.value = response.data?.message
            || (decision === 'approve'
                ? t('marketing.campaign_wizard.prospecting.messages.batch_approved')
                : t('marketing.campaign_wizard.prospecting.messages.batch_rejected'));

        await loadProspectBatch(batchId, activeProspectMeta.value.currentPage || 1);
        await loadProspectBatches();
    } catch (error) {
        prospectingBatchError.value = error?.response?.data?.message || error?.message || t('marketing.campaign_wizard.prospecting.errors.review_failed');
    } finally {
        prospectingReviewBusy.value = false;
    }
};

const resetProspectFilters = async () => {
    prospectingFilters.value = {
        search: '',
        status: '',
        match_status: '',
    };
    prospectingSort.value = 'priority_desc';
    clearSelectedProspects();
    await loadProspectBatch(activeProspectBatchId.value);
};
</script>

<template>
    <Head :title="pageTitle" />
    <AuthenticatedLayout>
        <div class="space-y-4">
            <section class="rounded-sm border border-stone-200 border-t-4 border-t-green-600 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="space-y-2">
                        <div class="flex flex-wrap items-center gap-2 text-xs">
                            <span class="inline-flex rounded-full border border-green-200 bg-green-50 px-3 py-1 font-semibold text-green-700 dark:border-green-500/30 dark:bg-green-500/10 dark:text-green-200">
                                {{ prospectBatchStatusLabel(activeProspectBatch?.status) }}
                            </span>
                            <span class="inline-flex rounded-full border border-stone-200 bg-stone-50 px-3 py-1 font-medium text-stone-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300">
                                {{ campaign.name }}
                            </span>
                        </div>

                        <div>
                            <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">{{ batchPageTitle }}</h1>
                            <p class="mt-2 max-w-3xl text-sm leading-6 text-stone-500 dark:text-neutral-400">
                                {{ batchMetaText }}
                            </p>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <Link :href="route('campaigns.show', campaign.id)">
                            <SecondaryButton>{{ t('marketing.campaign_show.actions.back') }}</SecondaryButton>
                        </Link>
                        <SecondaryButton type="button" @click="returnToWizard">
                            {{ t('marketing.campaign_show.actions.edit') }}
                        </SecondaryButton>
                    </div>
                </div>
            </section>

            <div class="grid grid-cols-1 gap-4 xl:grid-cols-[320px,minmax(0,1fr)]">
                <aside class="space-y-4">
                    <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <div class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                            {{ t('marketing.campaign_wizard.prospecting.review_title') }}
                        </div>
                        <div class="mt-3 grid grid-cols-2 gap-2">
                            <div class="rounded-sm border border-stone-200 bg-stone-50 px-3 py-3 text-xs dark:border-neutral-700 dark:bg-neutral-800">
                                <div class="text-stone-500 dark:text-neutral-400">{{ t('marketing.campaign_wizard.prospecting.summary.total_batches') }}</div>
                                <div class="mt-1 text-lg font-semibold text-stone-800 dark:text-neutral-100">{{ prospectingBatchSummary?.total_batches || 0 }}</div>
                            </div>
                            <div class="rounded-sm border border-stone-200 bg-stone-50 px-3 py-3 text-xs dark:border-neutral-700 dark:bg-neutral-800">
                                <div class="text-stone-500 dark:text-neutral-400">{{ t('marketing.campaign_wizard.prospecting.summary.analyzed_batches') }}</div>
                                <div class="mt-1 text-lg font-semibold text-stone-800 dark:text-neutral-100">{{ prospectingBatchSummary?.analyzed_batches || 0 }}</div>
                            </div>
                            <div class="rounded-sm border border-stone-200 bg-stone-50 px-3 py-3 text-xs dark:border-neutral-700 dark:bg-neutral-800">
                                <div class="text-stone-500 dark:text-neutral-400">{{ t('marketing.campaign_wizard.prospecting.summary.approved_batches') }}</div>
                                <div class="mt-1 text-lg font-semibold text-stone-800 dark:text-neutral-100">{{ prospectingBatchSummary?.approved_batches || 0 }}</div>
                            </div>
                            <div class="rounded-sm border border-stone-200 bg-stone-50 px-3 py-3 text-xs dark:border-neutral-700 dark:bg-neutral-800">
                                <div class="text-stone-500 dark:text-neutral-400">{{ t('marketing.campaign_wizard.prospecting.summary.canceled_batches') }}</div>
                                <div class="mt-1 text-lg font-semibold text-stone-800 dark:text-neutral-100">{{ prospectingBatchSummary?.canceled_batches || 0 }}</div>
                            </div>
                        </div>
                    </section>

                    <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <div class="flex items-center justify-between gap-2">
                            <div class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                {{ t('marketing.campaign_wizard.prospecting.review_title') }}
                            </div>
                            <SecondaryButton type="button" :disabled="prospectingBatchBusy" @click="loadProspectBatches">
                                {{ t('marketing.common.reload') }}
                            </SecondaryButton>
                        </div>

                        <div v-if="prospectingBatches.length === 0" class="mt-3 text-xs text-stone-500 dark:text-neutral-400">
                            {{ t('marketing.campaign_wizard.prospecting.empty_batches') }}
                        </div>

                        <div v-else class="mt-3 space-y-2">
                            <button
                                v-for="batchItem in prospectingBatches"
                                :key="`workspace-batch-${batchItem.id}`"
                                type="button"
                                class="w-full rounded-sm border px-3 py-3 text-left transition"
                                :class="Number(activeProspectBatchId || 0) === Number(batchItem.id) ? 'border-green-500 bg-green-50 dark:border-green-400 dark:bg-green-500/10' : 'border-stone-200 bg-white hover:border-green-300 dark:border-neutral-700 dark:bg-neutral-900'"
                                @click="openBatchWorkspace(batchItem.id)"
                            >
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <div class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                        {{ t('marketing.campaign_wizard.prospecting.batch_label', { number: batchItem.batch_number }) }}
                                    </div>
                                    <div class="text-xs text-stone-500 dark:text-neutral-400">
                                        {{ prospectBatchStatusLabel(batchItem.status) }}
                                    </div>
                                </div>
                                <div class="mt-2 grid grid-cols-2 gap-2 text-xs text-stone-600 dark:text-neutral-300">
                                    <div>{{ t('marketing.campaign_wizard.prospecting.kpis.accepted') }}: {{ batchItem.accepted_count }}</div>
                                    <div>{{ t('marketing.campaign_wizard.prospecting.kpis.duplicates') }}: {{ batchItem.duplicate_count }}</div>
                                    <div>{{ t('marketing.campaign_wizard.prospecting.kpis.blocked') }}: {{ batchItem.blocked_count }}</div>
                                    <div>{{ t('marketing.campaign_wizard.prospecting.kpis.review_required') }}: {{ batchItem.analysis_summary?.review_required_count ?? batchItem.accepted_count }}</div>
                                </div>
                                <div class="mt-2 text-xs text-stone-500 dark:text-neutral-400">
                                    {{ formatDateTime(batchItem.created_at) }}
                                </div>
                            </button>
                        </div>
                    </section>
                </aside>

                <section class="space-y-3 rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                {{ batchPageTitle }}
                            </h2>
                            <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                {{ batchMetaText }}
                            </p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <SecondaryButton type="button" :disabled="prospectingBatchBusy" @click="loadProspectBatch(activeProspectBatchId, activeProspectMeta.currentPage || 1)">
                                {{ t('marketing.common.reload') }}
                            </SecondaryButton>
                            <SecondaryButton type="button" :disabled="!canReviewActiveBatch" @click="reviewProspectBatch('reject')">
                                {{ prospectingReviewBusy ? t('marketing.campaign_wizard.prospecting.actions.processing') : t('marketing.campaign_wizard.prospecting.actions.reject_batch') }}
                            </SecondaryButton>
                            <PrimaryButton type="button" :disabled="!canReviewActiveBatch" @click="reviewProspectBatch('approve')">
                                {{ prospectingReviewBusy ? t('marketing.campaign_wizard.prospecting.actions.processing') : t('marketing.campaign_wizard.prospecting.actions.approve_batch') }}
                            </PrimaryButton>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-2 xl:grid-cols-5">
                        <div class="rounded-sm border border-stone-200 bg-stone-50 px-3 py-3 text-xs dark:border-neutral-700 dark:bg-neutral-800">
                            <div class="text-stone-500 dark:text-neutral-400">{{ t('marketing.campaign_wizard.prospecting.kpis.accepted') }}</div>
                            <div class="mt-1 text-lg font-semibold text-stone-800 dark:text-neutral-100">{{ activeProspectBatch?.accepted_count || 0 }}</div>
                        </div>
                        <div class="rounded-sm border border-stone-200 bg-stone-50 px-3 py-3 text-xs dark:border-neutral-700 dark:bg-neutral-800">
                            <div class="text-stone-500 dark:text-neutral-400">{{ t('marketing.campaign_wizard.prospecting.kpis.duplicates') }}</div>
                            <div class="mt-1 text-lg font-semibold text-stone-800 dark:text-neutral-100">{{ activeProspectBatch?.duplicate_count || 0 }}</div>
                        </div>
                        <div class="rounded-sm border border-stone-200 bg-stone-50 px-3 py-3 text-xs dark:border-neutral-700 dark:bg-neutral-800">
                            <div class="text-stone-500 dark:text-neutral-400">{{ t('marketing.campaign_wizard.prospecting.kpis.blocked') }}</div>
                            <div class="mt-1 text-lg font-semibold text-stone-800 dark:text-neutral-100">{{ activeProspectBatch?.blocked_count || 0 }}</div>
                        </div>
                        <div class="rounded-sm border border-stone-200 bg-stone-50 px-3 py-3 text-xs dark:border-neutral-700 dark:bg-neutral-800">
                            <div class="text-stone-500 dark:text-neutral-400">{{ t('marketing.campaign_wizard.prospecting.kpis.rejected') }}</div>
                            <div class="mt-1 text-lg font-semibold text-stone-800 dark:text-neutral-100">{{ activeProspectBatch?.rejected_count || 0 }}</div>
                        </div>
                        <div class="rounded-sm border border-stone-200 bg-stone-50 px-3 py-3 text-xs dark:border-neutral-700 dark:bg-neutral-800">
                            <div class="text-stone-500 dark:text-neutral-400">{{ t('marketing.campaign_wizard.prospecting.kpis.review_required') }}</div>
                            <div class="mt-1 text-lg font-semibold text-stone-800 dark:text-neutral-100">{{ activeProspectBatch?.analysis_summary?.review_required_count ?? activeProspectBatch?.accepted_count ?? 0 }}</div>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-2 xl:grid-cols-3">
                        <div class="rounded-sm border border-stone-200 bg-stone-50 px-3 py-3 text-xs dark:border-neutral-700 dark:bg-neutral-800">
                            <div class="font-semibold text-stone-700 dark:text-neutral-200">{{ t('marketing.campaign_wizard.prospecting.scoring_title') }}</div>
                            <div class="mt-2 space-y-1 text-stone-600 dark:text-neutral-300">
                                <div>{{ t('marketing.campaign_wizard.prospecting.kpis.high_priority') }}: {{ activeProspectBatch?.analysis_summary?.high_priority_count ?? 0 }}</div>
                                <div>{{ t('marketing.campaign_wizard.prospecting.score_ranges.high') }}: {{ activeProspectBatch?.analysis_summary?.score_ranges?.high ?? 0 }}</div>
                                <div>{{ t('marketing.campaign_wizard.prospecting.score_ranges.medium') }}: {{ activeProspectBatch?.analysis_summary?.score_ranges?.medium ?? 0 }}</div>
                                <div>{{ t('marketing.campaign_wizard.prospecting.score_ranges.low') }}: {{ activeProspectBatch?.analysis_summary?.score_ranges?.low ?? 0 }}</div>
                            </div>
                        </div>
                        <div class="rounded-sm border border-stone-200 bg-stone-50 px-3 py-3 text-xs dark:border-neutral-700 dark:bg-neutral-800">
                            <div class="font-semibold text-stone-700 dark:text-neutral-200">{{ t('marketing.campaign_wizard.prospecting.match_summary_title') }}</div>
                            <div class="mt-2 space-y-1 text-stone-600 dark:text-neutral-300">
                                <div>{{ t('marketing.campaign_wizard.prospecting.match_statuses.none') }}: {{ activeProspectBatch?.analysis_summary?.match_status_counts?.none ?? 0 }}</div>
                                <div>{{ t('marketing.campaign_wizard.prospecting.match_statuses.matched_customer') }}: {{ activeProspectBatch?.analysis_summary?.match_status_counts?.matched_customer ?? 0 }}</div>
                                <div>{{ t('marketing.campaign_wizard.prospecting.match_statuses.matched_lead') }}: {{ activeProspectBatch?.analysis_summary?.match_status_counts?.matched_lead ?? 0 }}</div>
                                <div>{{ t('marketing.campaign_wizard.prospecting.match_statuses.matched_prospect') }}: {{ activeProspectBatch?.analysis_summary?.match_status_counts?.matched_prospect ?? 0 }}</div>
                            </div>
                        </div>
                        <div class="rounded-sm border border-stone-200 bg-stone-50 px-3 py-3 text-xs dark:border-neutral-700 dark:bg-neutral-800">
                            <div class="font-semibold text-stone-700 dark:text-neutral-200">{{ t('marketing.campaign_wizard.prospecting.review_state_title') }}</div>
                            <div class="mt-2 space-y-1 text-stone-600 dark:text-neutral-300">
                                <div>{{ t('marketing.campaign_wizard.prospecting.fields.source_type') }}: {{ t(`marketing.campaign_wizard.prospecting.source_types.${activeProspectBatch?.source_type || 'import'}`) }}</div>
                                <div>{{ t('marketing.campaign_wizard.prospecting.review_decision') }}: {{ activeProspectBatch?.analysis_summary?.review_decision ? prospectBatchStatusLabel(activeProspectBatch?.status) : t('marketing.campaign_wizard.prospecting.pending_review') }}</div>
                                <div>{{ t('marketing.campaign_wizard.prospecting.reviewed_at') }}: {{ formatDateTime(activeProspectBatch?.analysis_summary?.reviewed_at || activeProspectBatch?.approved_at) }}</div>
                            </div>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-2 md:grid-cols-5">
                        <FloatingInput
                            v-model="prospectingFilters.search"
                            :label="t('marketing.campaign_wizard.prospecting.filters.search')"
                        />
                        <FloatingSelect
                            v-model="prospectingFilters.status"
                            :label="t('marketing.campaign_wizard.prospecting.filters.status')"
                            :options="prospectingStatusOptions"
                            option-value="value"
                            option-label="label"
                        />
                        <FloatingSelect
                            v-model="prospectingFilters.match_status"
                            :label="t('marketing.campaign_wizard.prospecting.filters.match_status')"
                            :options="prospectingMatchStatusOptions"
                            option-value="value"
                            option-label="label"
                        />
                        <FloatingSelect
                            v-model="prospectingSort"
                            :label="t('marketing.campaign_wizard.prospecting.filters.sort')"
                            :options="prospectingSortOptions"
                            option-value="value"
                            option-label="label"
                        />
                        <div class="flex items-end gap-2">
                            <SecondaryButton type="button" :disabled="prospectingBatchBusy" @click="loadProspectBatch(activeProspectBatchId, 1)">
                                {{ t('marketing.common.reload') }}
                            </SecondaryButton>
                            <SecondaryButton type="button" :disabled="prospectingBatchBusy" @click="resetProspectFilters">
                                {{ t('marketing.common.reset') }}
                            </SecondaryButton>
                        </div>
                    </div>

                    <p v-if="prospectingBatchError" class="text-xs text-rose-600">{{ prospectingBatchError }}</p>
                    <p v-if="prospectingBatchMessage" class="text-xs text-emerald-700 dark:text-emerald-300">{{ prospectingBatchMessage }}</p>
                    <p v-if="prospectingBulkError" class="text-xs text-rose-600">{{ prospectingBulkError }}</p>
                    <p v-if="prospectingBulkMessage" class="text-xs text-emerald-700 dark:text-emerald-300">{{ prospectingBulkMessage }}</p>

                    <div v-if="displayedProspectRows.length > 0" class="flex flex-wrap items-center justify-between gap-2 rounded-sm border border-stone-200 bg-stone-50 px-3 py-3 text-xs dark:border-neutral-700 dark:bg-neutral-800">
                        <label class="inline-flex items-center gap-2 text-stone-600 dark:text-neutral-300">
                            <input
                                type="checkbox"
                                class="rounded border-stone-300 text-green-600 focus:ring-green-600"
                                :checked="allDisplayedProspectsSelected"
                                @change="toggleAllDisplayedProspects($event.target.checked)"
                            >
                            <span>{{ t('marketing.campaign_wizard.prospecting.bulk.select_all_visible') }}</span>
                        </label>
                        <div class="text-stone-500 dark:text-neutral-400">
                            {{ t('marketing.campaign_wizard.prospecting.bulk.selected_count', { count: selectedProspectCount }) }}
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <SecondaryButton type="button" :disabled="!canBulkApplyStatus('approved')" @click="bulkUpdateProspects('approved')">
                                {{ t('marketing.campaign_wizard.prospecting.actions.bulk_approve') }}
                            </SecondaryButton>
                            <SecondaryButton type="button" :disabled="!canBulkApplyStatus('disqualified')" @click="bulkUpdateProspects('disqualified')">
                                {{ t('marketing.campaign_wizard.prospecting.actions.bulk_reject') }}
                            </SecondaryButton>
                            <PrimaryButton type="button" :disabled="!canBulkApplyStatus('do_not_contact')" @click="bulkUpdateProspects('do_not_contact')">
                                {{ t('marketing.campaign_wizard.prospecting.actions.bulk_do_not_contact') }}
                            </PrimaryButton>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,1.15fr),minmax(320px,0.85fr)]">
                        <div class="overflow-hidden rounded-sm border border-stone-200 dark:border-neutral-700">
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-stone-200 text-sm dark:divide-neutral-700">
                                    <thead class="bg-stone-50 dark:bg-neutral-800">
                                        <tr class="text-left text-xs uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                            <th class="px-3 py-3"></th>
                                            <th class="px-3 py-3">{{ t('marketing.campaign_wizard.prospecting.table.prospect') }}</th>
                                            <th class="px-3 py-3">{{ t('marketing.campaign_wizard.prospecting.table.status') }}</th>
                                            <th class="px-3 py-3">{{ t('marketing.campaign_wizard.prospecting.table.match') }}</th>
                                            <th class="px-3 py-3">{{ t('marketing.campaign_wizard.prospecting.table.scores') }}</th>
                                            <th class="px-3 py-3">{{ t('marketing.campaign_wizard.prospecting.table.recommendation') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-stone-200 bg-white dark:divide-neutral-800 dark:bg-neutral-900">
                                        <tr v-if="prospectingBatchBusy">
                                            <td colspan="6" class="px-3 py-6 text-center text-xs text-stone-500 dark:text-neutral-400">
                                                {{ t('marketing.campaign_wizard.prospecting.loading_batch') }}
                                            </td>
                                        </tr>
                                        <tr v-else-if="displayedProspectRows.length === 0">
                                            <td colspan="6" class="px-3 py-6 text-center text-xs text-stone-500 dark:text-neutral-400">
                                                {{ t('marketing.campaign_wizard.prospecting.empty_prospects') }}
                                            </td>
                                        </tr>
                                        <tr
                                            v-for="prospect in displayedProspectRows"
                                            :key="`batch-prospect-${prospect.id}`"
                                            class="align-top text-stone-700 dark:text-neutral-200"
                                            :class="Number(activeProspectId || 0) === Number(prospect.id) ? 'bg-green-50/60 dark:bg-green-500/5' : ''"
                                        >
                                            <td class="px-3 py-3">
                                                <input
                                                    type="checkbox"
                                                    class="rounded border-stone-300 text-green-600 focus:ring-green-600"
                                                    :checked="selectedProspectIds.includes(Number(prospect.id))"
                                                    @change="toggleProspectSelection(prospect.id, $event.target.checked)"
                                                >
                                            </td>
                                            <td class="px-3 py-3">
                                                <button
                                                    type="button"
                                                    class="text-left"
                                                    @click="loadProspectDetail(prospect.id)"
                                                >
                                                    <div class="font-medium text-stone-800 dark:text-neutral-100">{{ prospectIdentity(prospect) }}</div>
                                                    <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">{{ prospect.email || prospect.phone || '-' }}</div>
                                                    <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">{{ prospect.company_name || prospect.website_domain || '-' }}</div>
                                                </button>
                                            </td>
                                            <td class="px-3 py-3 text-xs">
                                                <div class="font-medium">{{ prospectStatusLabel(prospect.status) }}</div>
                                                <div class="mt-1 text-stone-500 dark:text-neutral-400">{{ formatDateTime(prospect.updated_at) }}</div>
                                            </td>
                                            <td class="px-3 py-3 text-xs">
                                                <div>{{ prospectMatchStatusLabel(prospect.match_status) }}</div>
                                                <div class="mt-1 text-stone-500 dark:text-neutral-400">{{ prospect.source_reference || '-' }}</div>
                                            </td>
                                            <td class="px-3 py-3 text-xs">
                                                <div>{{ t('marketing.campaign_wizard.prospecting.detail.scores_value', {
                                                    priority: prospect.priority_score ?? '-',
                                                    fit: prospect.fit_score ?? '-',
                                                    intent: prospect.intent_score ?? '-',
                                                }) }}</div>
                                            </td>
                                            <td class="px-3 py-3 text-xs">
                                                <div class="font-medium text-stone-700 dark:text-neutral-200">{{ prospectRecommendedAction(prospect) }}</div>
                                                <div class="mt-1 text-stone-500 dark:text-neutral-400">{{ prospect.qualification_summary || '-' }}</div>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="rounded-sm border border-stone-200 bg-stone-50 p-4 dark:border-neutral-700 dark:bg-neutral-800">
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                <div>
                                    <div class="text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                        {{ t('marketing.campaign_wizard.prospecting.detail_title') }}
                                    </div>
                                    <div class="mt-1 text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                        {{ activeProspectDetail ? prospectIdentity(activeProspectDetail) : t('marketing.campaign_wizard.prospecting.select_prospect') }}
                                    </div>
                                </div>
                                <div v-if="activeProspectDetail" class="flex flex-wrap gap-2">
                                    <SecondaryButton type="button" :disabled="!canConvertActiveProspect" @click="convertActiveProspectToLead">
                                        {{ t('marketing.campaign_wizard.prospecting.actions.convert_to_lead') }}
                                    </SecondaryButton>
                                    <SecondaryButton
                                        v-if="activeProspectDetail.matched_lead && !activeProspectDetail.converted_lead"
                                        type="button"
                                        :disabled="!canManage || prospectActionBusy"
                                        @click="linkActiveProspectToLead(activeProspectDetail.matched_lead.id)"
                                    >
                                        {{ t('marketing.campaign_wizard.prospecting.actions.link_matched_lead') }}
                                    </SecondaryButton>
                                    <SecondaryButton type="button" :disabled="!canManageActiveProspect" @click="applyProspectStatus('blocked')">
                                        {{ t('marketing.campaign_wizard.prospecting.actions.mark_blocked') }}
                                    </SecondaryButton>
                                    <SecondaryButton type="button" :disabled="!canManageActiveProspect" @click="applyProspectStatus('duplicate')">
                                        {{ t('marketing.campaign_wizard.prospecting.actions.mark_duplicate') }}
                                    </SecondaryButton>
                                    <SecondaryButton type="button" :disabled="!canManageActiveProspect" @click="applyProspectStatus('disqualified')">
                                        {{ t('marketing.campaign_wizard.prospecting.actions.mark_disqualified') }}
                                    </SecondaryButton>
                                    <PrimaryButton type="button" :disabled="!canManageActiveProspect" @click="applyProspectStatus('do_not_contact')">
                                        {{ t('marketing.campaign_wizard.prospecting.actions.mark_do_not_contact') }}
                                    </PrimaryButton>
                                </div>
                            </div>

                            <div v-if="activeProspectBusy" class="mt-3 text-xs text-stone-500 dark:text-neutral-400">
                                {{ t('marketing.campaign_wizard.prospecting.loading_prospect') }}
                            </div>
                            <p v-else-if="activeProspectError" class="mt-3 text-xs text-rose-600">{{ activeProspectError }}</p>
                            <div v-else-if="!activeProspectDetail" class="mt-3 text-xs text-stone-500 dark:text-neutral-400">
                                {{ t('marketing.campaign_wizard.prospecting.select_prospect') }}
                            </div>
                            <template v-else>
                                <p v-if="activeProspectMessage" class="mt-3 text-xs text-emerald-700 dark:text-emerald-300">{{ activeProspectMessage }}</p>
                                <div class="mt-3 grid grid-cols-1 gap-2 md:grid-cols-2">
                                    <div class="rounded-sm border border-stone-200 bg-white px-3 py-3 text-xs dark:border-neutral-700 dark:bg-neutral-900">
                                        <div class="text-stone-500 dark:text-neutral-400">{{ t('marketing.campaign_wizard.prospecting.detail.email') }}</div>
                                        <div class="mt-1 text-stone-800 dark:text-neutral-100">{{ activeProspectDetail.email || '-' }}</div>
                                    </div>
                                    <div class="rounded-sm border border-stone-200 bg-white px-3 py-3 text-xs dark:border-neutral-700 dark:bg-neutral-900">
                                        <div class="text-stone-500 dark:text-neutral-400">{{ t('marketing.campaign_wizard.prospecting.detail.phone') }}</div>
                                        <div class="mt-1 text-stone-800 dark:text-neutral-100">{{ activeProspectDetail.phone || '-' }}</div>
                                    </div>
                                    <div class="rounded-sm border border-stone-200 bg-white px-3 py-3 text-xs dark:border-neutral-700 dark:bg-neutral-900">
                                        <div class="text-stone-500 dark:text-neutral-400">{{ t('marketing.campaign_wizard.prospecting.detail.source') }}</div>
                                        <div class="mt-1 text-stone-800 dark:text-neutral-100">{{ t(`marketing.campaign_wizard.prospecting.source_types.${activeProspectDetail.source_type || 'import'}`) }}</div>
                                        <div class="mt-1 text-stone-500 dark:text-neutral-400">{{ activeProspectDetail.source_reference || activeProspectDetail.batch?.batch_number || '-' }}</div>
                                    </div>
                                    <div class="rounded-sm border border-stone-200 bg-white px-3 py-3 text-xs dark:border-neutral-700 dark:bg-neutral-900">
                                        <div class="text-stone-500 dark:text-neutral-400">{{ t('marketing.campaign_wizard.prospecting.detail.scores') }}</div>
                                        <div class="mt-1 text-stone-800 dark:text-neutral-100">
                                            {{ t('marketing.campaign_wizard.prospecting.detail.scores_value', {
                                                priority: activeProspectDetail.priority_score ?? '-',
                                                fit: activeProspectDetail.fit_score ?? '-',
                                                intent: activeProspectDetail.intent_score ?? '-',
                                            }) }}
                                        </div>
                                    </div>
                                    <div class="rounded-sm border border-stone-200 bg-white px-3 py-3 text-xs md:col-span-2 dark:border-neutral-700 dark:bg-neutral-900">
                                        <div class="text-stone-500 dark:text-neutral-400">{{ t('marketing.campaign_wizard.prospecting.detail.sequence') }}</div>
                                        <div class="mt-1 text-stone-800 dark:text-neutral-100">{{ prospectSequenceSummary(activeProspectDetail) }}</div>
                                    </div>
                                    <div class="rounded-sm border border-stone-200 bg-white px-3 py-3 text-xs md:col-span-2 dark:border-neutral-700 dark:bg-neutral-900">
                                        <div class="text-stone-500 dark:text-neutral-400">{{ t('marketing.campaign_wizard.prospecting.detail.match') }}</div>
                                        <div class="mt-1 text-stone-800 dark:text-neutral-100">{{ prospectMatchStatusLabel(activeProspectDetail.match_status) }}</div>
                                        <div v-if="activeProspectDetail.matched_customer" class="mt-1 text-stone-500 dark:text-neutral-400">
                                            {{ t('marketing.campaign_wizard.prospecting.detail.matched_customer', {
                                                value: activeProspectDetail.matched_customer.company_name || activeProspectDetail.matched_customer.email || activeProspectDetail.matched_customer.phone || `#${activeProspectDetail.matched_customer.id}`,
                                            }) }}
                                        </div>
                                        <div v-else-if="activeProspectDetail.matched_lead" class="mt-1 text-stone-500 dark:text-neutral-400">
                                            {{ t('marketing.campaign_wizard.prospecting.detail.matched_lead', {
                                                value: activeProspectDetail.matched_lead.title || activeProspectDetail.matched_lead.contact_name || activeProspectDetail.matched_lead.contact_email || `#${activeProspectDetail.matched_lead.id}`,
                                            }) }}
                                        </div>
                                    </div>
                                    <div class="rounded-sm border border-stone-200 bg-white px-3 py-3 text-xs md:col-span-2 dark:border-neutral-700 dark:bg-neutral-900">
                                        <div class="text-stone-500 dark:text-neutral-400">{{ t('marketing.campaign_wizard.prospecting.detail.qualification_summary') }}</div>
                                        <div class="mt-1 text-stone-800 dark:text-neutral-100">{{ activeProspectDetail.qualification_summary || '-' }}</div>
                                        <div class="mt-2 text-stone-500 dark:text-neutral-400">{{ t('marketing.campaign_wizard.prospecting.detail.owner_notes') }}</div>
                                        <div class="mt-1 whitespace-pre-wrap text-stone-800 dark:text-neutral-100">{{ activeProspectDetail.owner_notes || t('marketing.campaign_wizard.prospecting.detail.no_owner_notes') }}</div>
                                    </div>
                                    <div v-if="activeProspectDetail.converted_lead" class="rounded-sm border border-stone-200 bg-white px-3 py-3 text-xs md:col-span-2 dark:border-neutral-700 dark:bg-neutral-900">
                                        <div class="text-stone-500 dark:text-neutral-400">{{ t('marketing.campaign_wizard.prospecting.detail.converted_lead') }}</div>
                                        <div class="mt-1 text-stone-800 dark:text-neutral-100">
                                            {{ activeProspectDetail.converted_lead.title || activeProspectDetail.converted_lead.contact_name || `#${activeProspectDetail.converted_lead.id}` }}
                                        </div>
                                        <div class="mt-1 text-stone-500 dark:text-neutral-400">
                                            {{ humanizeValue(activeProspectDetail.converted_lead.status) }}
                                        </div>
                                        <Link
                                            :href="route('request.show', activeProspectDetail.converted_lead.id)"
                                            class="mt-2 inline-flex text-xs font-medium text-emerald-700 underline underline-offset-2 dark:text-emerald-300"
                                        >
                                            {{ t('marketing.campaign_wizard.prospecting.detail.open_lead') }}
                                        </Link>
                                    </div>
                                </div>

                                <div v-if="!activeProspectDetail.converted_lead" class="mt-4 rounded-sm border border-stone-200 bg-white px-3 py-3 dark:border-neutral-700 dark:bg-neutral-900">
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <div class="text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                            {{ t('marketing.campaign_wizard.prospecting.detail.link_existing_lead') }}
                                        </div>
                                        <div v-if="activeProspectDetail.matched_lead" class="text-xs text-stone-500 dark:text-neutral-400">
                                            {{ t('marketing.campaign_wizard.prospecting.detail.matched_lead_ready') }}
                                        </div>
                                    </div>

                                    <div class="mt-3 grid grid-cols-1 gap-2 md:grid-cols-[minmax(0,1fr),auto,minmax(0,1fr),auto]">
                                        <FloatingInput
                                            v-model="leadLinkSearch"
                                            :label="t('marketing.campaign_wizard.prospecting.fields.lead_search')"
                                        />
                                        <div class="flex items-end">
                                            <SecondaryButton type="button" :disabled="!canSearchLeadOptions" @click="searchLeadOptions">
                                                {{ leadLinkBusy ? t('marketing.campaign_wizard.prospecting.actions.searching_leads') : t('marketing.campaign_wizard.prospecting.actions.search_leads') }}
                                            </SecondaryButton>
                                        </div>
                                        <FloatingSelect
                                            v-model="selectedLeadId"
                                            :label="t('marketing.campaign_wizard.prospecting.fields.linked_lead')"
                                            :options="leadLinkOptions"
                                            option-value="id"
                                            option-label="label"
                                        />
                                        <div class="flex items-end">
                                            <PrimaryButton type="button" :disabled="!canLinkActiveProspect" @click="linkActiveProspectToLead()">
                                                {{ t('marketing.campaign_wizard.prospecting.actions.link_existing_lead') }}
                                            </PrimaryButton>
                                        </div>
                                    </div>

                                    <p v-if="leadLinkError" class="mt-2 text-xs text-rose-600">{{ leadLinkError }}</p>
                                </div>

                                <div class="mt-4 rounded-sm border border-stone-200 bg-white px-3 py-3 dark:border-neutral-700 dark:bg-neutral-900">
                                    <div class="text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                        {{ t('marketing.campaign_wizard.prospecting.timeline_title') }}
                                    </div>
                                    <div v-if="!Array.isArray(activeProspectDetail.activities) || activeProspectDetail.activities.length === 0" class="mt-3 text-xs text-stone-500 dark:text-neutral-400">
                                        {{ t('marketing.campaign_wizard.prospecting.empty_timeline') }}
                                    </div>
                                    <div v-else class="mt-3 space-y-3">
                                        <div v-for="activity in activeProspectDetail.activities" :key="`prospect-activity-${activity.id}`" class="flex items-start justify-between gap-3 border-b border-stone-100 pb-3 text-xs last:border-b-0 last:pb-0 dark:border-neutral-800">
                                            <div>
                                                <div class="font-medium text-stone-800 dark:text-neutral-100">{{ humanizeValue(activity.activity_type) }}</div>
                                                <div class="mt-1 text-stone-600 dark:text-neutral-300">{{ activity.summary || '-' }}</div>
                                                <div class="mt-1 text-stone-500 dark:text-neutral-400">{{ activity.channel || '-' }}</div>
                                            </div>
                                            <div class="shrink-0 text-right text-stone-500 dark:text-neutral-400">
                                                {{ formatDateTime(activity.occurred_at) }}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-stone-500 dark:text-neutral-400">
                        <span>{{ t('marketing.common.results_count', { count: activeProspectMeta.total }) }}</span>
                        <span>{{ t('marketing.common.page_of', { page: activeProspectMeta.currentPage, total: activeProspectMeta.lastPage }) }}</span>
                    </div>

                    <div class="flex flex-wrap justify-end gap-2">
                        <SecondaryButton
                            type="button"
                            :disabled="prospectingBatchBusy || activeProspectMeta.currentPage <= 1"
                            @click="loadProspectBatch(activeProspectBatchId, activeProspectMeta.currentPage - 1)"
                        >
                            {{ t('marketing.common.previous') }}
                        </SecondaryButton>
                        <SecondaryButton
                            type="button"
                            :disabled="prospectingBatchBusy || activeProspectMeta.currentPage >= activeProspectMeta.lastPage"
                            @click="loadProspectBatch(activeProspectBatchId, activeProspectMeta.currentPage + 1)"
                        >
                            {{ t('marketing.common.next') }}
                        </SecondaryButton>
                    </div>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
