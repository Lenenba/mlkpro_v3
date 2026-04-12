<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import CampaignSectionCard from '@/Pages/Campaigns/Components/CampaignSectionCard.vue';
import CampaignStickyActionBar from '@/Pages/Campaigns/Components/CampaignStickyActionBar.vue';
import CampaignStepHeading from '@/Pages/Campaigns/Components/CampaignStepHeading.vue';
import CampaignStepRail from '@/Pages/Campaigns/Components/CampaignStepRail.vue';
import EmailBodyEditor from '@/Pages/Campaigns/Components/EmailBodyEditor.vue';
import OfferSelector from '@/Pages/Campaigns/Components/OfferSelector.vue';

const props = defineProps({
    campaign: { type: Object, default: null },
    selectedOffers: { type: Array, default: () => [] },
    segments: { type: Array, default: () => [] },
    mailingLists: { type: Array, default: () => [] },
    vipTiers: { type: Array, default: () => [] },
    availableProspectProviders: { type: Array, default: () => [] },
    enums: { type: Object, default: () => ({}) },
    marketingSettings: { type: Object, default: () => ({}) },
    access: { type: Object, default: () => ({}) },
});

const { t } = useI18n();

const canManage = computed(() => Boolean(props.access?.can_manage));
const canSend = computed(() => Boolean(props.access?.can_send));
const isEdit = computed(() => Boolean(props.campaign?.id));
const campaignId = computed(() => props.campaign?.id || null);
const step = ref(1);
const totalWizardSteps = 5;
const stepBlueprints = [
    { id: 1, key: 'setup' },
    { id: 2, key: 'audience' },
    { id: 3, key: 'message' },
    { id: 4, key: 'review' },
    { id: 5, key: 'results' },
];

const humanizeValue = (value) => String(value || '')
    .replaceAll('_', ' ')
    .toLowerCase()
    .replace(/\b\w/g, (char) => char.toUpperCase());

const translateWithFallback = (key, fallback) => {
    const translated = t(key);
    return translated === key ? fallback : translated;
};

const richTextToPlainText = (value) => {
    const source = String(value || '');
    if (source.trim() === '') {
        return '';
    }

    const withBreaks = source
        .replace(/<\s*br\s*\/?>/gi, '\n')
        .replace(/<\/(p|div|h2|h3|blockquote|pre)>/gi, '\n')
        .replace(/<li[^>]*>/gi, '- ')
        .replace(/<\/li>/gi, '\n')
        .replace(/<hr[^>]*>/gi, '\n');

    const withoutTags = withBreaks.replace(/<[^>]+>/g, '');

    return withoutTags
        .replace(/&nbsp;/gi, ' ')
        .replace(/&amp;/gi, '&')
        .replace(/&lt;/gi, '<')
        .replace(/&gt;/gi, '>')
        .replace(/&quot;/gi, '"')
        .replace(/&#39;/gi, '\'')
        .replace(/\r/g, '')
        .replace(/\n{3,}/g, '\n\n')
        .trim();
};

const campaignTypeLabel = (value) => {
    const normalized = String(value || '').toLowerCase();
    if (!normalized) return '-';
    return translateWithFallback(`marketing.campaign_types.${normalized}`, humanizeValue(value));
};

const offerModeLabel = (value) => {
    const normalized = String(value || '').toLowerCase();
    if (!normalized) return '-';
    return translateWithFallback(`marketing.offer_modes.${normalized}`, humanizeValue(value));
};

const languageModeLabel = (value) => {
    const normalized = String(value || '').toLowerCase();
    if (!normalized) return '-';
    return translateWithFallback(`marketing.language_modes.${normalized}`, humanizeValue(value));
};

const directionLabel = (value) => {
    const normalized = String(value || '').toLowerCase();
    if (!normalized) return '-';
    return translateWithFallback(`marketing.campaign_directions.${normalized}`, humanizeValue(value));
};

const sourceLogicLabel = (value) => {
    const normalized = String(value || '').toLowerCase();
    if (!normalized) return '-';
    return translateWithFallback(`marketing.source_logic.${normalized}`, humanizeValue(value));
};

const prospectingImportModeLabel = (value) => {
    const normalized = String(value || '').toLowerCase();
    if (!normalized) return '-';
    return translateWithFallback(`marketing.campaign_wizard.prospecting.import_modes.${normalized}`, humanizeValue(value));
};

const prospectingSourceTypeLabel = (value) => {
    const normalized = String(value || '').toLowerCase();
    if (!normalized) return '-';
    return translateWithFallback(`marketing.campaign_wizard.prospecting.source_types.${normalized}`, humanizeValue(value));
};

const channelLabel = (value) => {
    const normalized = String(value || '').toLowerCase();
    if (!normalized) return '-';
    return translateWithFallback(`marketing.channels.${normalized}`, humanizeValue(value));
};

const campaignStatusLabel = (value) => {
    const normalized = String(value || '').toLowerCase();
    if (!normalized) return '-';
    return translateWithFallback(`marketing.campaign_status.${normalized}`, humanizeValue(value));
};

const runStatusLabel = (value) => {
    const normalized = String(value || '').toLowerCase();
    if (!normalized) return '-';
    return translateWithFallback(`marketing.campaign_run_status.${normalized}`, humanizeValue(value));
};

const statusBadgeClass = (status) => {
    const normalized = String(status || '').toLowerCase();

    if (normalized === 'running') {
        return 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/15 dark:text-emerald-200';
    }

    if (['scheduled', 'pending'].includes(normalized)) {
        return 'border-sky-200 bg-sky-50 text-sky-700 dark:border-sky-500/30 dark:bg-sky-500/15 dark:text-sky-200';
    }

    if (normalized === 'completed') {
        return 'border-indigo-200 bg-indigo-50 text-indigo-700 dark:border-indigo-500/30 dark:bg-indigo-500/15 dark:text-indigo-200';
    }

    if (['failed', 'canceled'].includes(normalized)) {
        return 'border-rose-200 bg-rose-50 text-rose-700 dark:border-rose-500/30 dark:bg-rose-500/15 dark:text-rose-200';
    }

    return 'border-stone-200 bg-stone-50 text-stone-700 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300';
};

const formatNumber = (value) => Number(value || 0).toLocaleString(undefined, { maximumFractionDigits: 0 });

const channels = (props.enums?.channels || ['EMAIL', 'SMS', 'IN_APP']).map((v) => String(v).toUpperCase());
const types = props.enums?.types || ['PROMOTION'];
const directions = props.enums?.directions || ['customer_marketing', 'prospecting_outbound', 'lead_generation_inbound'];
const offerModes = props.enums?.offer_modes || ['PRODUCTS', 'SERVICES', 'MIXED'];
const languageModes = props.enums?.language_modes || ['PREFERRED', 'FR', 'EN', 'ES', 'BOTH'];
const audienceSourceLogicOptions = props.enums?.audience_source_logic || ['UNION', 'INTERSECT'];
const scheduleTypeOptions = computed(() => ([
    { value: 'manual', label: 'manual' },
    { value: 'scheduled', label: 'scheduled' },
    { value: 'automation', label: 'automation' },
].map((option) => ({
    ...option,
    label: t(`marketing.campaign_wizard.schedule_type_options.${option.value}`),
}))));
const fallbackChannelDefaults = {
    EMAIL: ['SMS'],
    SMS: ['EMAIL'],
    IN_APP: ['EMAIL'],
};

const normalizeVariantTemplates = (value = {}) => ({
    subject_template: String(value?.subject_template || ''),
    title_template: String(value?.title_template || ''),
    body_template: String(value?.body_template || ''),
});

const normalizeAbTesting = (value = {}) => ({
    enabled: Boolean(value?.enabled ?? false),
    split_a_percent: Number(value?.split_a_percent ?? 50),
    variant_a: normalizeVariantTemplates(value?.variant_a || {}),
    variant_b: normalizeVariantTemplates(value?.variant_b || {}),
});

const normalizeFallbackMap = (input = {}) => {
    return channels.reduce((acc, channel) => {
        const source = String(channel).toUpperCase();
        const configured = Array.isArray(input?.[source]) ? input[source] : fallbackChannelDefaults[source] || [];
        const targets = configured
            .map((value) => String(value).toUpperCase())
            .filter((value) => value !== source && channels.includes(value));
        acc[source] = Array.from(new Set(targets));
        return acc;
    }, {});
};

const existingChannels = Array.isArray(props.campaign?.channels) ? props.campaign.channels : [];
const initialChannels = channels.map((channel) => {
    const existing = existingChannels.find((row) => String(row.channel).toUpperCase() === channel);
    const enabledByConfig = Boolean(props.marketingSettings?.channels?.enabled?.[channel] ?? true);
    const abTesting = normalizeAbTesting(existing?.metadata?.ab_testing || {});
    return {
        channel,
        is_enabled: existing ? Boolean(existing.is_enabled) : enabledByConfig,
        message_template_id: existing?.message_template_id || '',
        subject_template: existing?.subject_template || '',
        title_template: existing?.title_template || '',
        body_template: existing?.body_template || '',
        ab_testing: abTesting,
    };
});

const initialOffers = Array.isArray(props.selectedOffers) ? props.selectedOffers : [];
const existingSettings = props.campaign?.settings || {};

const form = useForm({
    name: props.campaign?.name || '',
    campaign_type: props.campaign?.campaign_type || props.campaign?.type || types[0],
    prospecting_enabled: Boolean(props.campaign?.prospecting_enabled ?? false),
    campaign_direction: props.campaign?.campaign_direction || 'customer_marketing',
    offer_mode: props.campaign?.offer_mode || offerModes[0],
    language_mode: props.campaign?.language_mode || languageModes[0],
    schedule_type: props.campaign?.schedule_type || 'manual',
    scheduled_at: props.campaign?.scheduled_at ? String(props.campaign.scheduled_at).slice(0, 16) : '',
    locale: props.campaign?.locale || '',
    cta_url: props.campaign?.cta_url || '',
    audience_segment_id: props.campaign?.audience_segment_id || '',
    offers: initialOffers,
    offer_selectors: {
        category_ids: Array.isArray(props.campaign?.settings?.offer_selectors?.category_ids)
            ? props.campaign.settings.offer_selectors.category_ids
            : [],
        tags: Array.isArray(props.campaign?.settings?.offer_selectors?.tags)
            ? props.campaign.settings.offer_selectors.tags
            : [],
    },
    channels: initialChannels,
    settings: {
        promo_code: existingSettings?.promo_code || '',
        promo_percent: existingSettings?.promo_percent || '',
        promo_end_date: existingSettings?.promo_end_date || '',
        holdout: {
            enabled: Boolean(existingSettings?.holdout?.enabled ?? false),
            percent: Number(existingSettings?.holdout?.percent ?? 0),
        },
        channel_fallback: {
            enabled: Boolean(existingSettings?.channel_fallback?.enabled ?? false),
            max_depth: Number(existingSettings?.channel_fallback?.max_depth ?? 1),
            map: normalizeFallbackMap(existingSettings?.channel_fallback?.map || {}),
        },
    },
});

const includeMailingListIds = ref(
    Array.isArray(props.campaign?.audience?.include_mailing_list_ids)
        ? props.campaign.audience.include_mailing_list_ids.map((value) => Number(value)).filter((value) => Number.isInteger(value) && value > 0)
        : []
);
const excludeMailingListIds = ref(
    Array.isArray(props.campaign?.audience?.exclude_mailing_list_ids)
        ? props.campaign.audience.exclude_mailing_list_ids.map((value) => Number(value)).filter((value) => Number.isInteger(value) && value > 0)
        : []
);
const sourceLogic = ref(
    String(props.campaign?.audience?.source_logic || props.marketingSettings?.audience?.source_logic_default || 'UNION').toUpperCase()
);

const initialManualCustomerIds = Array.isArray(props.campaign?.audience?.manual_customer_ids)
    ? props.campaign.audience.manual_customer_ids
        .map((value) => Number(value))
        .filter((value) => Number.isInteger(value) && value > 0)
    : [];
const initialManualContacts = Array.isArray(props.campaign?.audience?.manual_contacts)
    ? props.campaign.audience.manual_contacts
        .map((value) => String(value || '').trim())
        .filter((value) => value !== '')
    : (
        typeof props.campaign?.audience?.manual_contacts === 'string'
            ? props.campaign.audience.manual_contacts
                .split(/\r?\n/)
                .map((value) => value.trim())
                .filter((value) => value !== '')
            : []
    );
const selectedAudienceCustomerIds = ref(initialManualCustomerIds);
const audienceCustomerRows = ref([]);
const audienceCustomerSearch = ref('');
const audienceCustomerPage = ref(1);
const audienceCustomerPerPage = ref(15);
const audienceCustomerPickerOpen = ref(false);
const audienceCustomerLoading = ref(false);
const audienceCustomerError = ref('');

const initialSingleMailingListId = includeMailingListIds.value.length === 1 && excludeMailingListIds.value.length === 0
    ? includeMailingListIds.value[0]
    : null;
const useSingleMailingList = ref(initialSingleMailingListId !== null);
const singleMailingListId = ref(initialSingleMailingListId ? String(initialSingleMailingListId) : '');

const templates = ref([]);
const requestBusy = ref(false);
const requestError = ref('');
const estimate = ref(props.campaign?.audience?.estimated_counts && typeof props.campaign.audience.estimated_counts === 'object'
    ? props.campaign.audience.estimated_counts
    : null);
const previews = ref([]);
const testResults = ref([]);
const runMessage = ref('');
const isProspectingMode = computed(() => Boolean(form.prospecting_enabled) && String(form.campaign_direction || '') !== 'customer_marketing');
const wizardStepStorageKey = 'campaign-wizard-next-step';
const savedAudienceSourceSummary = props.campaign?.audience?.source_summary || {};

const prospectingImportMode = ref(String(savedAudienceSourceSummary?.import_mode || 'manual'));
const prospectingSourceType = ref(String(savedAudienceSourceSummary?.source_type || 'manual'));
const prospectingSourceReference = ref(String(savedAudienceSourceSummary?.source_reference || ''));
const prospectingProviderConnectionId = ref(
    savedAudienceSourceSummary?.provider_connection_id ? String(savedAudienceSourceSummary.provider_connection_id) : ''
);
const prospectingProviderQueryLabel = ref(String(savedAudienceSourceSummary?.provider_query_label || ''));
const prospectingProviderQuery = ref(String(savedAudienceSourceSummary?.provider_query || ''));
const providerPreviewBusy = ref(false);
const providerPreviewError = ref('');
const providerPreviewMessage = ref('');
const providerPreviewRows = ref([]);
const providerPreviewMeta = ref(null);
const providerPreviewConnection = ref(null);
const selectedProviderPreviewRefs = ref([]);
const providerImportSummary = ref(null);
const prospectingBatchSize = ref(100);
const prospectingManualInput = ref('');
const prospectingSelectedFile = ref(null);
const prospectingImportBusy = ref(false);
const prospectingImportError = ref('');
const prospectingImportMessage = ref('');
const prospectingBatchBusy = ref(false);
const prospectingBatchError = ref('');
const prospectingBatchMessage = ref('');
const prospectingReviewBusy = ref(false);
const prospectingBatches = ref([]);
const prospectingBatchSummary = ref(null);
const activeProspectBatchId = ref(null);
const activeProspectBatch = ref(null);
const activeProspectRows = ref([]);
const activeProspectPagination = ref(null);
const activeProspectId = ref(null);
const activeProspectDetail = ref(null);
const activeProspectBusy = ref(false);
const activeProspectError = ref('');
const activeProspectMessage = ref('');
const prospectActionBusy = ref(false);
const prospectingFilters = ref({
    search: '',
    status: '',
    match_status: '',
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

const prospectingImportModeOptions = computed(() => ([
    { value: 'manual', label: t('marketing.campaign_wizard.prospecting.import_modes.manual') },
    { value: 'csv', label: t('marketing.campaign_wizard.prospecting.import_modes.csv') },
    { value: 'provider', label: t('marketing.campaign_wizard.prospecting.import_modes.provider') },
]));

const availableProspectProviders = computed(() => (
    Array.isArray(props.availableProspectProviders) ? props.availableProspectProviders : []
));

const prospectingProviderOptions = computed(() => availableProspectProviders.value.map((connection) => ({
    value: String(connection.id),
    label: `${connection.provider_label} - ${connection.label}`,
})));

const selectedProspectProvider = computed(() => {
    const connectionId = Number(prospectingProviderConnectionId.value || 0);
    if (!connectionId) {
        return null;
    }

    return availableProspectProviders.value.find((connection) => Number(connection.id) === connectionId) || null;
});

const prospectingProviderPlaceholder = computed(() => {
    const providerKey = String(selectedProspectProvider.value?.provider_key || '').toLowerCase();
    if (providerKey === 'apollo') {
        return 'VP operations, ecommerce, Toronto, 11-50 employees';
    }
    if (providerKey === 'lusha') {
        return 'Construction companies, Ontario, owner or general manager';
    }
    if (providerKey === 'uplead') {
        return 'Manufacturing, Quebec, procurement, 20-200 employees';
    }

    return 'ICP, filters, regions, titles, or saved search URL';
});

const prospectingSourceTypeOptions = computed(() => ([
    'manual',
    'csv',
    'connector',
    'directory_api',
    'ads',
    'landing_page',
    'import',
].map((value) => ({
    value,
    label: t(`marketing.campaign_wizard.prospecting.source_types.${value}`),
}))));

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

const normalizedProspectingManualInput = computed(() => richTextToPlainText(prospectingManualInput.value));
const normalizedProspectingProviderQuery = computed(() => richTextToPlainText(prospectingProviderQuery.value));

const manualProspectLineCount = computed(() => String(normalizedProspectingManualInput.value || '')
    .split(/\r?\n/)
    .map((value) => value.trim())
    .filter((value) => value !== '')
    .length);

const canAnalyzeProspectBatch = computed(() => {
    if (!canManage.value || !campaignId.value || prospectingImportBusy.value) {
        return false;
    }

    if (prospectingImportMode.value === 'provider') {
        return false;
    }

    if (prospectingImportMode.value === 'csv') {
        return Boolean(prospectingSelectedFile.value);
    }

    return manualProspectLineCount.value > 0;
});

const canSaveProviderSelection = computed(() => {
    return Boolean(
        canManage.value
        && isEdit.value
        && prospectingImportMode.value === 'provider'
        && selectedProspectProvider.value
        && String(normalizedProspectingProviderQuery.value || '').trim() !== ''
        && !form.processing
    );
});

const prospectingProviderSelectionSummary = computed(() => {
    if (prospectingImportMode.value !== 'provider') {
        return null;
    }

    if (selectedProspectProvider.value) {
        return `${selectedProspectProvider.value.provider_label} - ${selectedProspectProvider.value.label}`;
    }

    const fallbackLabel = String(savedAudienceSourceSummary?.provider_connection_label || savedAudienceSourceSummary?.provider_label || '').trim();
    return fallbackLabel || null;
});

const canLoadProviderPreview = computed(() => {
    return Boolean(
        canManage.value
        && campaignId.value
        && prospectingImportMode.value === 'provider'
        && selectedProspectProvider.value
        && String(normalizedProspectingProviderQuery.value || '').trim() !== ''
        && !providerPreviewBusy.value
    );
});

const selectedProviderPreviewCount = computed(() => selectedProviderPreviewRefs.value.length);
const importableProviderPreviewRows = computed(() => providerPreviewRows.value.filter((row) => !row?.already_imported));
const providerPreviewFreshCount = computed(() => importableProviderPreviewRows.value.length);
const providerPreviewAlreadyImportedCount = computed(() => providerPreviewRows.value.filter((row) => Boolean(row?.already_imported)).length);

const allProviderPreviewSelected = computed(() => {
    const importableRefs = importableProviderPreviewRows.value
        .map((row) => String(row?.preview_ref || '').trim())
        .filter((value) => value !== '');

    return importableRefs.length > 0
        && importableRefs.every((value) => selectedProviderPreviewRefs.value.includes(value));
});

const selectedProviderPreviewRows = computed(() => {
    const selectedRefs = new Set(selectedProviderPreviewRefs.value.map((value) => String(value || '').trim()).filter((value) => value !== ''));

    return providerPreviewRows.value.filter((row) => selectedRefs.has(String(row?.preview_ref || '').trim()));
});

const canImportSelectedProviderPreview = computed(() => {
    return Boolean(
        canManage.value
        && campaignId.value
        && prospectingImportMode.value === 'provider'
        && selectedProviderPreviewRows.value.length > 0
        && !prospectingImportBusy.value
    );
});

const canReviewActiveBatch = computed(() => {
    return Boolean(
        canManage.value
        && activeProspectBatch.value?.id
        && String(activeProspectBatch.value?.status || '') === 'analyzed'
        && !prospectingReviewBusy.value
    );
});

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

const activeProspectMeta = computed(() => {
    const pagination = activeProspectPagination.value;
    return {
        currentPage: Number(pagination?.current_page || 1),
        lastPage: Number(pagination?.last_page || 1),
        total: Number(pagination?.total || activeProspectRows.value.length || 0),
    };
});

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

const rememberWizardStep = (nextStep = null) => {
    if (typeof window === 'undefined') {
        return;
    }

    if (Number.isInteger(nextStep) && nextStep >= 1) {
        window.sessionStorage.setItem(wizardStepStorageKey, String(nextStep));
        return;
    }

    window.sessionStorage.removeItem(wizardStepStorageKey);
};

const restoreWizardStep = () => {
    if (typeof window === 'undefined') {
        return null;
    }

    const raw = window.sessionStorage.getItem(wizardStepStorageKey);
    window.sessionStorage.removeItem(wizardStepStorageKey);
    const parsed = Number(raw || 0);
    if (!Number.isInteger(parsed) || parsed < 1 || parsed > 5) {
        return null;
    }

    return parsed;
};

const setProspectingFile = (event) => {
    prospectingSelectedFile.value = event?.target?.files?.[0] || null;
};

const clearProspectingMessages = () => {
    prospectingImportError.value = '';
    prospectingImportMessage.value = '';
    prospectingBatchError.value = '';
    prospectingBatchMessage.value = '';
};

const clearProviderPreviewFeedback = () => {
    providerPreviewError.value = '';
    providerPreviewMessage.value = '';
};

const clearProviderPreview = () => {
    clearProviderPreviewFeedback();
    providerPreviewRows.value = [];
    providerPreviewMeta.value = null;
    providerPreviewConnection.value = null;
    selectedProviderPreviewRefs.value = [];
    providerImportSummary.value = null;
};

const detectManualProspectDelimiter = (lines) => {
    const joined = lines.join('\n');
    if (joined.includes('\t')) {
        return '\t';
    }

    if (joined.includes('|')) {
        return '|';
    }

    return ',';
};

const normalizeManualProspectHeader = (value) => String(value || '')
    .trim()
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '_')
    .replace(/^_+|_+$/g, '');

const resolveManualProspectField = (header) => {
    const normalized = normalizeManualProspectHeader(header);

    if (['company', 'company_name', 'business', 'organization', 'organisation'].includes(normalized)) return 'company_name';
    if (['contact', 'contact_name', 'name', 'full_name'].includes(normalized)) return 'contact_name';
    if (['first_name', 'firstname', 'given_name'].includes(normalized)) return 'first_name';
    if (['last_name', 'lastname', 'surname', 'family_name'].includes(normalized)) return 'last_name';
    if (['email', 'e_mail', 'mail'].includes(normalized)) return 'email';
    if (['phone', 'telephone', 'mobile', 'cell', 'tel'].includes(normalized)) return 'phone';
    if (['website', 'site', 'domain', 'url', 'site_web'].includes(normalized)) return 'website';
    if (['city', 'ville'].includes(normalized)) return 'city';
    if (['state', 'province', 'region'].includes(normalized)) return 'state';
    if (['country', 'pays'].includes(normalized)) return 'country';
    if (['industry', 'sector', 'vertical'].includes(normalized)) return 'industry';
    if (['company_size', 'size', 'employees', 'headcount'].includes(normalized)) return 'company_size';
    if (['tags', 'labels'].includes(normalized)) return 'tags';
    if (['owner_notes', 'notes', 'comment'].includes(normalized)) return 'owner_notes';

    return null;
};

const parseManualProspectsInput = () => {
    const lines = String(normalizedProspectingManualInput.value || '')
        .split(/\r?\n/)
        .map((value) => value.trim())
        .filter((value) => value !== '');

    if (lines.length === 0) {
        return [];
    }

    const delimiter = detectManualProspectDelimiter(lines);
    const rawRows = lines.map((line) => line.split(delimiter).map((value) => String(value || '').trim()));
    const firstRowFields = rawRows[0].map((value) => resolveManualProspectField(value));
    const hasHeader = firstRowFields.some((value) => value !== null);
    const defaultFields = ['company_name', 'contact_name', 'email', 'phone', 'website', 'industry', 'country', 'tags', 'owner_notes'];
    const fields = hasHeader ? firstRowFields : defaultFields;
    const dataRows = hasHeader ? rawRows.slice(1) : rawRows;

    return dataRows.map((columns) => {
        const payload = {};

        fields.forEach((field, index) => {
            if (!field) {
                return;
            }

            const rawValue = String(columns[index] || '').trim();
            if (rawValue === '') {
                return;
            }

            if (field === 'tags') {
                const tags = rawValue
                    .split(/[;,]/)
                    .map((value) => value.trim())
                    .filter((value) => value !== '');

                if (tags.length > 0) {
                    payload.tags = tags;
                }

                return;
            }

            payload[field] = rawValue;
        });

        return payload;
    }).filter((row) => Object.keys(row).length > 0);
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

const prospectIdentity = (prospect) => {
    return String(prospect?.contact_name || '').trim()
        || String(prospect?.company_name || '').trim()
        || String(prospect?.email || '').trim()
        || String(prospect?.phone || '').trim()
        || `#${prospect?.id || '-'}`;
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

const providerPreviewLocation = (row) => {
    const parts = [
        String(row?.city || '').trim(),
        String(row?.state || '').trim(),
        String(row?.country || '').trim(),
    ].filter((value) => value !== '');

    return parts.length > 0 ? parts.join(', ') : '-';
};

const providerPreviewMissingLabel = (field) => {
    const normalized = String(field || '').trim().toLowerCase();
    if (normalized === 'company_name') return t('marketing.campaign_wizard.prospecting.preview_missing.company_name');
    if (normalized === 'contact_name') return t('marketing.campaign_wizard.prospecting.preview_missing.contact_name');
    if (normalized === 'email') return t('marketing.campaign_wizard.prospecting.preview_missing.email');
    if (normalized === 'phone') return t('marketing.campaign_wizard.prospecting.preview_missing.phone');
    if (normalized === 'website') return t('marketing.campaign_wizard.prospecting.preview_missing.website');
    return humanizeValue(field);
};

const providerPreviewImportedSummary = (row) => {
    const status = row?.already_imported_status ? prospectStatusLabel(row.already_imported_status) : null;
    const importedAt = row?.already_imported_at ? formatDateTime(row.already_imported_at) : null;

    if (status && importedAt) {
        return `${status} · ${importedAt}`;
    }

    return status || importedAt || '';
};

const toggleProviderPreviewSelection = (previewRef, checked) => {
    const normalizedRef = String(previewRef || '').trim();
    if (!normalizedRef) {
        return;
    }

    if (checked) {
        selectedProviderPreviewRefs.value = [...new Set([...selectedProviderPreviewRefs.value, normalizedRef])];
        return;
    }

    selectedProviderPreviewRefs.value = selectedProviderPreviewRefs.value.filter((value) => value !== normalizedRef);
};

const toggleAllProviderPreviewRows = () => {
    if (allProviderPreviewSelected.value) {
        selectedProviderPreviewRefs.value = [];
        return;
    }

    selectedProviderPreviewRefs.value = importableProviderPreviewRows.value
        .map((row) => String(row?.preview_ref || '').trim())
        .filter((value) => value !== '');
};

const summarizeImportedBatches = (batches) => {
    const items = Array.isArray(batches) ? batches : [];

    return items.reduce((summary, batch) => ({
        imported: summary.imported + Number(batch?.input_count || 0),
        analyzed: summary.analyzed + Number(batch?.input_count || 0),
        duplicates: summary.duplicates + Number(batch?.duplicate_count || 0),
        blocked: summary.blocked + Number(batch?.blocked_count || 0),
        accepted: summary.accepted + Number(batch?.accepted_count || 0),
        rejected: summary.rejected + Number(batch?.rejected_count || 0),
        batches: summary.batches + 1,
    }), {
        imported: 0,
        analyzed: 0,
        duplicates: 0,
        blocked: 0,
        accepted: 0,
        rejected: 0,
        batches: 0,
    });
};

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
    search: [
        lead?.title,
        lead?.contact_name,
        lead?.contact_email,
        lead?.contact_phone,
        lead?.service_type,
    ].filter(Boolean).join(' '),
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

        const updated = response.data?.prospect || null;
        syncActiveProspectRow(updated);

        if (activeProspectBatchId.value) {
            await loadProspectBatch(activeProspectBatchId.value, activeProspectMeta.value.currentPage || 1);
        }
        await loadProspectBatches(activeProspectBatchId.value);
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

        if (activeProspectBatchId.value) {
            await loadProspectBatch(activeProspectBatchId.value, activeProspectMeta.value.currentPage || 1);
        }
        await loadProspectBatches(activeProspectBatchId.value);
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

        if (activeProspectBatchId.value) {
            await loadProspectBatch(activeProspectBatchId.value, activeProspectMeta.value.currentPage || 1);
        }
        await loadProspectBatches(activeProspectBatchId.value);
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

        if (activeProspectBatchId.value) {
            await loadProspectBatch(activeProspectBatchId.value, activeProspectMeta.value.currentPage || 1);
        }
        await loadProspectBatches(activeProspectBatchId.value);
    } catch (error) {
        leadLinkError.value = error?.response?.data?.message || error?.message || t('marketing.campaign_wizard.prospecting.errors.link_prospect');
    } finally {
        prospectActionBusy.value = false;
    }
};

const loadProspectBatch = async (batchId, page = 1) => {
    if (!campaignId.value || !batchId) {
        activeProspectBatchId.value = null;
        activeProspectBatch.value = null;
        activeProspectRows.value = [];
        activeProspectPagination.value = null;
        activeProspectId.value = null;
        activeProspectDetail.value = null;
        clearSelectedProspects();
        resetLeadLinkState();
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

const loadProspectBatches = async (selectedBatchId = null) => {
    if (!campaignId.value || !isProspectingMode.value) {
        return;
    }

    prospectingBatchBusy.value = true;
    prospectingBatchError.value = '';

    try {
        const response = await axios.get(route('campaigns.prospect-batches.index', campaignId.value), {
            params: { limit: 12 },
        });

        prospectingBatches.value = Array.isArray(response.data?.batches) ? response.data.batches : [];
        prospectingBatchSummary.value = response.data?.summary || null;

        const targetBatchId = Number(selectedBatchId || activeProspectBatchId.value || prospectingBatches.value[0]?.id || 0);
        if (targetBatchId > 0 && activeProspectBatch.value?.id) {
            await loadProspectBatch(targetBatchId);
        } else {
            activeProspectBatchId.value = targetBatchId > 0 ? targetBatchId : null;
        }
    } catch (error) {
        prospectingBatchError.value = error?.response?.data?.message || error?.message || t('marketing.campaign_wizard.prospecting.errors.load_batches');
    } finally {
        prospectingBatchBusy.value = false;
    }
};

const previewProviderProspects = async () => {
    if (!campaignId.value) {
        providerPreviewError.value = t('marketing.campaign_wizard.prospecting.errors.save_draft_first');
        return;
    }

    if (!selectedProspectProvider.value) {
        providerPreviewError.value = t('marketing.campaign_wizard.prospecting.errors.provider_connection_required');
        return;
    }

    if (String(normalizedProspectingProviderQuery.value || '').trim() === '') {
        providerPreviewError.value = t('marketing.campaign_wizard.prospecting.errors.provider_query_required');
        return;
    }

    providerPreviewBusy.value = true;
    clearProviderPreviewFeedback();
    providerImportSummary.value = null;

    try {
        const response = await axios.post(route('campaigns.prospect-provider-preview', campaignId.value), {
            provider_connection_id: Number(prospectingProviderConnectionId.value || 0),
            query_label: String(prospectingProviderQueryLabel.value || '').trim() || null,
            query: String(normalizedProspectingProviderQuery.value || '').trim(),
            limit: 25,
        });

        providerPreviewRows.value = Array.isArray(response.data?.rows) ? response.data.rows : [];
        providerPreviewMeta.value = response.data?.preview || null;
        providerPreviewConnection.value = response.data?.provider_connection || null;
        selectedProviderPreviewRefs.value = providerPreviewRows.value
            .filter((row) => !row?.already_imported)
            .map((row) => String(row?.preview_ref || '').trim())
            .filter((value) => value !== '');
        providerPreviewMessage.value = response.data?.message || t('marketing.campaign_wizard.prospecting.messages.provider_preview_ready');
    } catch (error) {
        const validationErrors = error?.response?.data?.errors || {};
        const firstValidationMessage = Object.values(validationErrors).flat()[0];
        providerPreviewError.value = firstValidationMessage
            || error?.response?.data?.message
            || error?.message
            || t('marketing.campaign_wizard.prospecting.errors.provider_preview_failed');
    } finally {
        providerPreviewBusy.value = false;
    }
};

const importSelectedProviderProspects = async () => {
    if (!campaignId.value) {
        prospectingImportError.value = t('marketing.campaign_wizard.prospecting.errors.save_draft_first');
        return;
    }

    if (selectedProviderPreviewRows.value.length === 0) {
        prospectingImportError.value = t('marketing.campaign_wizard.prospecting.errors.provider_selection_required');
        return;
    }

    prospectingImportBusy.value = true;
    clearProspectingMessages();

    try {
        const payload = {
            source_type: String(prospectingSourceType.value || 'connector'),
            source_reference: String(prospectingSourceReference.value || selectedProspectProvider.value?.label || '').trim() || null,
            batch_size: Number(prospectingBatchSize.value || 100),
            prospects: selectedProviderPreviewRows.value.map((row) => ({
                source_reference: row.source_reference || prospectingSourceReference.value || selectedProspectProvider.value?.label || null,
                external_ref: row.external_ref || row.preview_ref || null,
                company_name: row.company_name || null,
                contact_name: row.contact_name || null,
                first_name: row.first_name || null,
                last_name: row.last_name || null,
                email: row.email || null,
                phone: row.phone || null,
                website: row.website || null,
                city: row.city || null,
                state: row.state || null,
                country: row.country || null,
                industry: row.industry || null,
                company_size: row.company_size || null,
                tags: Array.isArray(row.tags) ? row.tags : [],
                metadata: {
                    ...(row.metadata || {}),
                    provider_preview_ref: row.preview_ref || null,
                    provider_key: row.provider_key || selectedProspectProvider.value?.provider_key || null,
                    provider_label: row.provider_label || selectedProspectProvider.value?.provider_label || null,
                    provider_connection_id: selectedProspectProvider.value?.id || null,
                    provider_connection_label: selectedProspectProvider.value?.label || null,
                    provider_query_label: providerPreviewMeta.value?.query_label || String(prospectingProviderQueryLabel.value || '').trim() || null,
                    provider_query: providerPreviewMeta.value?.query || String(normalizedProspectingProviderQuery.value || '').trim() || null,
                    provider_import_confirmed_at: new Date().toISOString(),
                },
            })),
        };

        const response = await axios.post(route('campaigns.prospect-batches.import', campaignId.value), payload);
        const importedBatches = Array.isArray(response.data?.batches) ? response.data.batches : [];

        prospectingImportMessage.value = response.data?.message || t('marketing.campaign_wizard.prospecting.messages.provider_import_complete');
        providerImportSummary.value = summarizeImportedBatches(importedBatches);
        importedBatches.forEach((batch) => syncProspectBatchIntoList(batch));
        selectedProviderPreviewRefs.value = [];

        const firstBatchId = Number(importedBatches[0]?.id || 0);
        await loadProspectBatches(firstBatchId > 0 ? firstBatchId : null);
    } catch (error) {
        const validationErrors = error?.response?.data?.errors || {};
        const firstValidationMessage = Object.values(validationErrors).flat()[0];
        prospectingImportError.value = firstValidationMessage
            || error?.response?.data?.message
            || error?.message
            || t('marketing.campaign_wizard.prospecting.errors.import_failed');
    } finally {
        prospectingImportBusy.value = false;
    }
};

const analyzeProspectBatch = async () => {
    if (!campaignId.value) {
        prospectingImportError.value = t('marketing.campaign_wizard.prospecting.errors.save_draft_first');
        return;
    }

    prospectingImportBusy.value = true;
    clearProspectingMessages();

    try {
        let payload;
        let config = {};

        if (prospectingImportMode.value === 'csv') {
            if (!prospectingSelectedFile.value) {
                throw new Error(t('marketing.campaign_wizard.prospecting.errors.file_required'));
            }

            payload = new FormData();
            payload.append('source_type', String(prospectingSourceType.value || 'csv'));
            payload.append('source_reference', String(prospectingSourceReference.value || ''));
            payload.append('batch_size', String(prospectingBatchSize.value || 100));
            payload.append('file', prospectingSelectedFile.value);
            config = {
                headers: {
                    'Content-Type': 'multipart/form-data',
                },
            };
        } else {
            const prospects = parseManualProspectsInput();
            if (prospects.length === 0) {
                throw new Error(t('marketing.campaign_wizard.prospecting.errors.manual_required'));
            }

            payload = {
                source_type: String(prospectingSourceType.value || 'manual'),
                source_reference: String(prospectingSourceReference.value || ''),
                batch_size: Number(prospectingBatchSize.value || 100),
                prospects,
            };
        }

        const response = await axios.post(route('campaigns.prospect-batches.import', campaignId.value), payload, config);
        const importedBatches = Array.isArray(response.data?.batches) ? response.data.batches : [];

        prospectingImportMessage.value = response.data?.message || t('marketing.campaign_wizard.prospecting.messages.batch_analyzed');
        importedBatches.forEach((batch) => syncProspectBatchIntoList(batch));

        const firstBatchId = Number(importedBatches[0]?.id || 0);
        await loadProspectBatches(firstBatchId > 0 ? firstBatchId : null);
    } catch (error) {
        prospectingImportError.value = error?.response?.data?.message || error?.message || t('marketing.campaign_wizard.prospecting.errors.import_failed');
    } finally {
        prospectingImportBusy.value = false;
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

        await loadProspectBatch(batchId);
        await loadProspectBatches(batchId);
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

    if (activeProspectBatchId.value) {
        await loadProspectBatch(activeProspectBatchId.value);
    }
};

const saveProspectingDraft = () => {
    save(2);
};

const openProspectBatchWorkspace = (batchId) => {
    const normalizedBatchId = Number(batchId || 0);
    if (!campaignId.value || normalizedBatchId <= 0) {
        return;
    }

    rememberWizardStep(2);
    router.visit(route('campaigns.prospect-batches.workspace', [campaignId.value, normalizedBatchId]));
};

watch(
    () => form.offer_mode,
    (mode) => {
        const normalized = String(mode || '').toUpperCase();
        if (normalized === 'PRODUCTS') {
            form.offers = form.offers.filter((offer) => String(offer.offer_type || offer.type).toLowerCase() === 'product');
        } else if (normalized === 'SERVICES') {
            form.offers = form.offers.filter((offer) => String(offer.offer_type || offer.type).toLowerCase() === 'service');
        }
    }
);

watch(
    () => form.prospecting_enabled,
    (enabled) => {
        if (!enabled) {
            form.campaign_direction = 'customer_marketing';
            return;
        }

        if (String(form.campaign_direction || '') === 'customer_marketing') {
            form.campaign_direction = 'prospecting_outbound';
        }
    }
);

watch(
    () => form.campaign_direction,
    (direction) => {
        const normalized = String(direction || '');
        if (normalized === 'customer_marketing') {
            form.prospecting_enabled = false;
            return;
        }

        if (normalized !== '') {
            form.prospecting_enabled = true;
        }
    }
);

watch(
    () => prospectingImportMode.value,
    (mode, previous) => {
        clearProviderPreview();

        if (mode === 'provider') {
            prospectingSourceType.value = 'connector';
            prospectingSourceReference.value = selectedProspectProvider.value?.label || '';
            return;
        }

        if (previous === 'provider') {
            prospectingSourceReference.value = '';
        }

        if (!prospectingSourceType.value || prospectingSourceType.value === 'connector') {
            prospectingSourceType.value = mode === 'csv' ? 'csv' : 'manual';
        }
    },
    { immediate: true }
);

watch(
    () => selectedProspectProvider.value,
    (connection) => {
        clearProviderPreview();

        if (prospectingImportMode.value !== 'provider') {
            return;
        }

        prospectingSourceType.value = 'connector';
        prospectingSourceReference.value = connection?.label || '';
    },
    { immediate: true }
);

watch(
    () => [prospectingProviderQuery.value, prospectingProviderQueryLabel.value],
    () => {
        if (prospectingImportMode.value !== 'provider') {
            return;
        }

        clearProviderPreview();
    }
);

const offersPayload = computed(() => {
    const byKey = new Map();
    form.offers.forEach((offer) => {
        const type = String(offer.offer_type || offer.type || '').toLowerCase();
        const id = Number(offer.offer_id || offer.id || 0);
        if (!type || id <= 0) return;
        byKey.set(`${type}:${id}`, { offer_type: type, offer_id: id });
    });
    return Array.from(byKey.values());
});

const productIdsPayload = computed(() => {
    return offersPayload.value
        .filter((offer) => offer.offer_type === 'product')
        .map((offer) => offer.offer_id);
});

const audienceCustomerDisplayName = (customer) => {
    return String(customer?.company_name || '').trim()
        || `${customer?.first_name || ''} ${customer?.last_name || ''}`.trim()
        || customer?.email
        || customer?.phone
        || `#${customer?.id || '-'}`;
};

const selectedAudienceCustomerMeta = ref({});
const upsertAudienceCustomerMeta = (customer) => {
    const id = Number(customer?.id || 0);
    if (!Number.isInteger(id) || id <= 0) {
        return;
    }

    selectedAudienceCustomerMeta.value[id] = {
        id,
        first_name: customer?.first_name || '',
        last_name: customer?.last_name || '',
        company_name: customer?.company_name || '',
        email: customer?.email || '',
        phone: customer?.phone || '',
    };
};

const filteredAudienceCustomerRows = computed(() => {
    const query = String(audienceCustomerSearch.value || '').trim().toLowerCase();
    if (query === '') {
        return audienceCustomerRows.value;
    }

    return audienceCustomerRows.value.filter((customer) => {
        const haystack = [
            customer?.company_name,
            customer?.first_name,
            customer?.last_name,
            customer?.email,
            customer?.phone,
        ]
            .map((value) => String(value || '').toLowerCase())
            .join(' ');
        return haystack.includes(query);
    });
});

const audienceCustomerTotalPages = computed(() => {
    return Math.max(1, Math.ceil(filteredAudienceCustomerRows.value.length / audienceCustomerPerPage.value));
});

const pagedAudienceCustomerRows = computed(() => {
    const start = (audienceCustomerPage.value - 1) * audienceCustomerPerPage.value;
    return filteredAudienceCustomerRows.value.slice(start, start + audienceCustomerPerPage.value);
});

const selectedAudienceCustomers = computed(() => {
    return selectedAudienceCustomerIds.value.map((id) => {
        const metadata = selectedAudienceCustomerMeta.value[id];
        return metadata || { id };
    });
});

const allVisibleAudienceCustomersSelected = computed(() => {
    if (pagedAudienceCustomerRows.value.length === 0) {
        return false;
    }

    return pagedAudienceCustomerRows.value.every((customer) => {
        const customerId = Number(customer?.id || 0);
        return customerId > 0 && selectedAudienceCustomerIds.value.includes(customerId);
    });
});

const singleMailingListOptions = computed(() => {
    return [
        { value: '', label: t('marketing.campaign_wizard.no_single_mailing_list') },
        ...props.mailingLists.map((list) => ({
            value: String(list.id),
            label: `${list.name} (${list.customers_count || 0})`,
        })),
    ];
});

watch([filteredAudienceCustomerRows, audienceCustomerPerPage], () => {
    audienceCustomerPage.value = 1;
});

watch(audienceCustomerTotalPages, (value) => {
    if (audienceCustomerPage.value > value) {
        audienceCustomerPage.value = value;
    }
});

watch([useSingleMailingList, singleMailingListId], () => {
    if (!useSingleMailingList.value) {
        return;
    }

    const candidate = Number(singleMailingListId.value || 0);
    includeMailingListIds.value = Number.isInteger(candidate) && candidate > 0 ? [candidate] : [];
    excludeMailingListIds.value = [];
    sourceLogic.value = 'UNION';
    form.audience_segment_id = '';
});

const loadAudienceCustomers = async () => {
    audienceCustomerLoading.value = true;
    audienceCustomerError.value = '';
    try {
        const response = await axios.get(route('customer.options'), {
            params: {
                scope: 'audience',
            },
        });
        const customers = Array.isArray(response.data?.customers) ? response.data.customers : [];
        audienceCustomerRows.value = customers
            .map((customer) => ({
                id: Number(customer?.id || 0),
                first_name: String(customer?.first_name || ''),
                last_name: String(customer?.last_name || ''),
                company_name: String(customer?.company_name || ''),
                email: String(customer?.email || ''),
                phone: String(customer?.phone || ''),
            }))
            .filter((customer) => customer.id > 0);

        audienceCustomerRows.value.forEach((customer) => upsertAudienceCustomerMeta(customer));
    } catch (error) {
        audienceCustomerError.value = error?.response?.data?.message || error?.message || t('marketing.campaign_wizard.errors.load_customers');
    } finally {
        audienceCustomerLoading.value = false;
    }
};

const openAudienceCustomerPicker = async () => {
    audienceCustomerPickerOpen.value = true;
    audienceCustomerSearch.value = '';
    audienceCustomerPage.value = 1;
    await loadAudienceCustomers();
};

const closeAudienceCustomerPicker = () => {
    audienceCustomerPickerOpen.value = false;
    audienceCustomerError.value = '';
    audienceCustomerSearch.value = '';
    audienceCustomerPage.value = 1;
};

const toggleAudienceCustomerSelection = (customer) => {
    const id = Number(customer?.id || 0);
    if (!Number.isInteger(id) || id <= 0) {
        return;
    }

    upsertAudienceCustomerMeta(customer);
    const index = selectedAudienceCustomerIds.value.indexOf(id);
    if (index >= 0) {
        selectedAudienceCustomerIds.value.splice(index, 1);
        return;
    }

    selectedAudienceCustomerIds.value.push(id);
};

const toggleAllVisibleAudienceCustomers = () => {
    const visibleIds = pagedAudienceCustomerRows.value
        .map((customer) => Number(customer?.id || 0))
        .filter((id) => id > 0);

    if (visibleIds.length === 0) {
        return;
    }

    if (allVisibleAudienceCustomersSelected.value) {
        selectedAudienceCustomerIds.value = selectedAudienceCustomerIds.value.filter((id) => !visibleIds.includes(id));
        return;
    }

    const merged = new Set([
        ...selectedAudienceCustomerIds.value,
        ...visibleIds,
    ]);
    selectedAudienceCustomerIds.value = Array.from(merged);
};

const removeAudienceCustomer = (customerId) => {
    const id = Number(customerId || 0);
    if (!Number.isInteger(id) || id <= 0) {
        return;
    }

    selectedAudienceCustomerIds.value = selectedAudienceCustomerIds.value.filter((value) => value !== id);
};

const logicSummary = computed(() => {
    const segmentPart = form.audience_segment_id ? t('marketing.campaign_wizard.logic.segment') : t('marketing.campaign_wizard.logic.builder');
    const includeCount = includeMailingListIds.value.length;
    const excludeCount = excludeMailingListIds.value.length;
    const manualCount = selectedAudienceCustomerIds.value.length;
    if (sourceLogic.value === 'INTERSECT') {
        return t('marketing.campaign_wizard.logic.intersect_summary', {
            segmentPart,
            includeCount,
            manualCount,
            excludeCount,
        });
    }

    return t('marketing.campaign_wizard.logic.union_summary', {
        segmentPart,
        includeCount,
        manualCount,
        excludeCount,
    });
});

const selectedAudienceSegment = computed(() => props.segments.find((segment) => String(segment?.id || '') === String(form.audience_segment_id || '')) || null);
const selectedSingleAudienceMailingList = computed(() => singleMailingListOptions.value.find((option) => String(option?.value || '') === String(singleMailingListId.value || '')) || null);
const audienceEstimateTotal = computed(() => Number(estimate.value?.total_eligible || 0));
const hasAudienceEstimate = computed(() => Boolean(estimate.value));
const audienceEstimateChannels = computed(() => Object.entries(estimate.value?.eligible_by_channel || {})
    .map(([channel, count]) => ({
        key: String(channel || '').toUpperCase(),
        label: channelLabel(channel),
        count: Number(count || 0),
    }))
    .filter((entry) => entry.count > 0));

const audiencePrimarySourceValue = computed(() => {
    if (useSingleMailingList.value && selectedSingleAudienceMailingList.value?.label) {
        return selectedSingleAudienceMailingList.value.label;
    }

    if (selectedAudienceSegment.value?.name) {
        return selectedAudienceSegment.value.name;
    }

    return t('marketing.campaign_wizard.audience.builder_fallback');
});

const audienceOverviewCards = computed(() => ([
    {
        key: 'source',
        label: t('marketing.campaign_wizard.audience.overview_cards.source'),
        value: audiencePrimarySourceValue.value,
        helper: useSingleMailingList.value
            ? t('marketing.campaign_wizard.audience.source_logic_locked')
            : sourceLogicLabel(sourceLogic.value),
    },
    {
        key: 'lists',
        label: t('marketing.campaign_wizard.audience.overview_cards.included_lists'),
        value: String(useSingleMailingList.value ? (singleMailingListId.value ? 1 : 0) : includeMailingListIds.value.length),
        helper: useSingleMailingList.value
            ? (selectedSingleAudienceMailingList.value?.label || t('marketing.campaign_wizard.no_single_mailing_list'))
            : t('marketing.campaign_wizard.audience.list_helper', { exclude: excludeMailingListIds.value.length }),
    },
    {
        key: 'manual',
        label: t('marketing.campaign_wizard.audience.overview_cards.manual_customers'),
        value: String(selectedAudienceCustomerIds.value.length),
        helper: selectedAudienceCustomerIds.value.length > 0
            ? t('marketing.campaign_wizard.selected_customers_count', { count: selectedAudienceCustomerIds.value.length })
            : t('marketing.campaign_wizard.selected_customers_empty'),
    },
    {
        key: 'estimate',
        label: t('marketing.campaign_wizard.audience.overview_cards.eligible_estimate'),
        value: estimate.value ? String(audienceEstimateTotal.value) : '-',
        helper: estimate.value
            ? t('marketing.campaign_wizard.audience.estimate_ready')
            : t('marketing.campaign_wizard.audience.estimate_pending'),
    },
]));

const prospectingOverviewCards = computed(() => ([
    {
        key: 'mode',
        label: t('marketing.campaign_wizard.audience.overview_cards.import_mode'),
        value: prospectingImportModeLabel(prospectingImportMode.value),
        helper: `${t('marketing.campaign_wizard.prospecting.fields.batch_size')}: ${prospectingBatchSize.value || '-'}`,
    },
    {
        key: 'source',
        label: t('marketing.campaign_wizard.audience.overview_cards.source_reference'),
        value: prospectingImportMode.value === 'provider'
            ? (prospectingProviderSelectionSummary.value || '-')
            : (String(prospectingSourceReference.value || '').trim() || prospectingSourceTypeLabel(prospectingSourceType.value)),
        helper: prospectingImportMode.value === 'provider'
            ? (String(prospectingProviderQueryLabel.value || normalizedProspectingProviderQuery.value || '').trim() || t('marketing.campaign_wizard.audience.no_query_saved'))
            : prospectingSourceTypeLabel(prospectingSourceType.value),
    },
    {
        key: 'preview',
        label: t('marketing.campaign_wizard.audience.overview_cards.preview'),
        value: String(providerPreviewRows.value.length || 0),
        helper: providerPreviewRows.value.length > 0
            ? t('marketing.campaign_wizard.prospecting.preview_selected_count', { count: selectedProviderPreviewCount.value, total: providerPreviewRows.value.length || 0 })
            : t('marketing.campaign_wizard.audience.preview_pending'),
    },
    {
        key: 'batches',
        label: t('marketing.campaign_wizard.audience.overview_cards.batches'),
        value: String(prospectingBatchSummary.value?.total_batches || prospectingBatches.value.length || 0),
        helper: t('marketing.campaign_wizard.audience.batch_helper', {
            approved: prospectingBatchSummary.value?.approved_batches || 0,
            analyzed: prospectingBatchSummary.value?.analyzed_batches || 0,
        }),
    },
]));

const hasProspectingDraftInput = computed(() => (
    String(normalizedProspectingManualInput.value || '').trim() !== ''
    || Boolean(prospectingSelectedFile.value)
    || String(prospectingProviderConnectionId.value || '').trim() !== ''
    || String(normalizedProspectingProviderQuery.value || '').trim() !== ''
    || providerPreviewRows.value.length > 0
    || prospectingBatches.value.length > 0
    || Number(prospectingBatchSummary.value?.total_batches || 0) > 0
    || Boolean(activeProspectBatch.value?.id)
));

const hasAudienceSetup = computed(() => {
    if (isProspectingMode.value) {
        return hasProspectingDraftInput.value;
    }

    return Boolean(
        form.audience_segment_id
        || includeMailingListIds.value.length
        || excludeMailingListIds.value.length
        || selectedAudienceCustomerIds.value.length
        || (useSingleMailingList.value && singleMailingListId.value)
    );
});

const enabledChannelsCount = computed(() => form.channels.filter((row) => row.is_enabled).length);
const enabledChannels = computed(() => form.channels.filter((row) => row.is_enabled));

const isChannelConfigured = (channel) => {
    if (!channel?.is_enabled) {
        return true;
    }

    const name = String(channel.channel || '').toUpperCase();
    const subject = String(channel.subject_template || '').trim();
    const title = String(channel.title_template || '').trim();
    const body = String(channel.body_template || '').trim();

    if (name === 'EMAIL') {
        return subject !== '' && body !== '';
    }

    if (name === 'IN_APP') {
        return title !== '' && body !== '';
    }

    return body !== '';
};

const hasMessageSetup = computed(() => (
    enabledChannelsCount.value > 0
    && form.channels.every((channel) => isChannelConfigured(channel))
));

const activeMessageChannelKey = ref(
    String(form.channels.find((row) => row.is_enabled)?.channel || form.channels[0]?.channel || channels[0] || 'EMAIL').toUpperCase()
);

const activeMessageChannel = computed(() => {
    const requestedKey = String(activeMessageChannelKey.value || '').toUpperCase();
    return form.channels.find((row) => String(row.channel || '').toUpperCase() === requestedKey)
        || form.channels[0]
        || null;
});

const configuredMessageChannelsCount = computed(() => (
    form.channels.filter((channel) => channel.is_enabled && isChannelConfigured(channel)).length
));
const enabledChannelLabels = computed(() => enabledChannels.value.map((channel) => channelLabel(channel.channel)));
const abEnabledChannels = computed(() => form.channels.filter((channel) => channel.is_enabled && Boolean(channel.ab_testing?.enabled)));
const unconfiguredEnabledChannels = computed(() => enabledChannels.value.filter((channel) => !isChannelConfigured(channel)));
const fallbackConfiguredSources = computed(() => enabledChannels.value
    .map((channel) => String(channel.channel || '').toUpperCase())
    .filter((source) => Array.isArray(form.settings.channel_fallback?.map?.[source]) && form.settings.channel_fallback.map[source].length > 0));
const campaignRuns = computed(() => (Array.isArray(props.campaign?.runs) ? props.campaign.runs : []));
const latestCampaignRun = computed(() => campaignRuns.value[0] || null);
const latestRunSummary = computed(() => {
    const summary = latestCampaignRun.value?.summary;
    return summary && typeof summary === 'object' ? summary : {};
});
const reviewOverviewCards = computed(() => {
    const audienceValue = hasAudienceSetup.value
        ? (hasAudienceEstimate.value
            ? `${formatNumber(audienceEstimateTotal.value)} eligible`
            : (isProspectingMode.value
                ? (prospectingProviderSelectionSummary.value || prospectingSourceTypeLabel(prospectingSourceType.value))
                : audiencePrimarySourceValue.value))
        : '-';

    return [
        {
            key: 'strategy',
            label: translateWithFallback('marketing.campaign_wizard.steps.setup', 'Parameters'),
            value: `${campaignTypeLabel(form.campaign_type)} | ${directionLabel(form.campaign_direction)}`,
            helper: `${formatNumber(selectedOfferCount.value)} offers | ${scheduleSummary.value}`,
        },
        {
            key: 'audience',
            label: translateWithFallback('marketing.campaign_wizard.steps.audience', 'Audience'),
            value: audienceValue,
            helper: isProspectingMode.value
                ? `${formatNumber(prospectingBatchSummary.value?.approved_batches || 0)} approved batches`
                : logicSummary.value,
        },
        {
            key: 'message',
            label: translateWithFallback('marketing.campaign_wizard.steps.message', 'Message'),
            value: enabledChannelsCount.value > 0 ? enabledChannelLabels.value.join(', ') : 'No active channel',
            helper: `${formatNumber(configuredMessageChannelsCount.value)}/${formatNumber(enabledChannelsCount.value)} channels ready`,
        },
        {
            key: 'lifecycle',
            label: translateWithFallback('marketing.campaign_wizard.steps.results', 'Results'),
            value: campaignStatusLabel(props.campaign?.status || 'draft'),
            helper: latestCampaignRun.value
                ? `Latest run #${latestCampaignRun.value.id}`
                : 'No run yet',
        },
    ];
});
const reviewSections = computed(() => ([
    {
        key: 'setup',
        title: translateWithFallback('marketing.campaign_wizard.steps.setup', 'Parameters'),
        ready: setupStepComplete.value,
        step: 1,
        summary: String(form.name || '').trim() || 'Campaign name missing',
        helper: `${campaignTypeLabel(form.campaign_type)} | ${offerModeLabel(form.offer_mode)} | ${scheduleSummary.value}`,
    },
    {
        key: 'audience',
        title: translateWithFallback('marketing.campaign_wizard.steps.audience', 'Audience'),
        ready: hasAudienceSetup.value,
        step: 2,
        summary: isProspectingMode.value
            ? (prospectingProviderSelectionSummary.value || prospectingSourceTypeLabel(prospectingSourceType.value))
            : audiencePrimarySourceValue.value,
        helper: hasAudienceEstimate.value
            ? `${formatNumber(audienceEstimateTotal.value)} eligible | ${formatNumber(includeMailingListIds.value.length)} lists`
            : 'Estimate not refreshed yet',
    },
    {
        key: 'message',
        title: translateWithFallback('marketing.campaign_wizard.steps.message', 'Message'),
        ready: hasMessageSetup.value,
        step: 3,
        summary: enabledChannelsCount.value > 0 ? enabledChannelLabels.value.join(', ') : 'No active channel',
        helper: enabledChannelsCount.value > 0
            ? `${formatNumber(configuredMessageChannelsCount.value)}/${formatNumber(enabledChannelsCount.value)} channels configured`
            : 'Enable at least one channel',
    },
]));
const reviewBlockers = computed(() => {
    const items = [];

    if (!isEdit.value) {
        items.push({
            key: 'save-first',
            title: 'Save the draft first',
            description: 'Create the campaign record before opening lifecycle data or launching it.',
            step: 1,
        });
    }

    if (String(form.name || '').trim() === '') {
        items.push({
            key: 'name',
            title: 'Campaign name is missing',
            description: 'Parameters still need a clear campaign name.',
            step: 1,
        });
    }

    if (offersPayload.value.length === 0) {
        items.push({
            key: 'offers',
            title: 'No offer selected',
            description: 'Choose at least one product or service before launch.',
            step: 1,
        });
    }

    if (String(form.schedule_type || '') === 'scheduled' && !form.scheduled_at) {
        items.push({
            key: 'schedule',
            title: 'Scheduled send has no date',
            description: 'Pick a date and time for the scheduled run.',
            step: 1,
        });
    }

    if (!hasAudienceSetup.value) {
        items.push({
            key: 'audience',
            title: 'Audience is not configured',
            description: 'Select a segment, lists, manual customers, or a prospecting source.',
            step: 2,
        });
    }

    if (hasAudienceEstimate.value && audienceEstimateTotal.value <= 0) {
        items.push({
            key: 'empty-audience',
            title: 'Audience estimate is empty',
            description: 'The current selection returns zero eligible recipients.',
            step: 2,
        });
    }

    if (enabledChannelsCount.value === 0) {
        items.push({
            key: 'channels',
            title: 'No channel is enabled',
            description: 'Enable at least one delivery channel.',
            step: 3,
        });
    }

    if (unconfiguredEnabledChannels.value.length > 0) {
        items.push({
            key: 'message',
            title: 'Message content is incomplete',
            description: `Finish ${unconfiguredEnabledChannels.value.map((channel) => channelLabel(channel.channel)).join(', ')} before sending.`,
            step: 3,
        });
    }

    return items;
});
const reviewWarnings = computed(() => {
    const items = [];

    if (!hasAudienceEstimate.value) {
        items.push({
            key: 'estimate',
            title: 'Audience estimate was not refreshed',
            description: 'Run the estimate once to validate volume and channel coverage.',
            step: 2,
        });
    }

    if (isProspectingMode.value && Number(prospectingBatchSummary.value?.total_batches || 0) > 0 && Number(prospectingBatchSummary.value?.approved_batches || 0) === 0) {
        items.push({
            key: 'prospecting-review',
            title: 'No approved prospect batch yet',
            description: 'Review at least one batch before treating the prospecting audience as ready.',
            step: 2,
        });
    }

    if (form.settings.holdout?.enabled && Number(form.settings.holdout?.percent || 0) === 0) {
        items.push({
            key: 'holdout',
            title: 'Holdout is enabled with 0%',
            description: 'Increase the holdout percentage or disable the option.',
            step: 4,
        });
    }

    if (form.settings.channel_fallback?.enabled && fallbackConfiguredSources.value.length === 0) {
        items.push({
            key: 'fallback',
            title: 'Fallback is enabled without targets',
            description: 'Map at least one fallback route for the enabled channels.',
            step: 4,
        });
    }

    if (isEdit.value && previews.value.length === 0) {
        items.push({
            key: 'preview',
            title: 'Preview has not been generated',
            description: 'Generate a live preview once before the launch decision.',
            step: 3,
        });
    }

    if (isEdit.value && canSend.value && testResults.value.length === 0) {
        items.push({
            key: 'test-send',
            title: 'No test send has been executed',
            description: 'A quick test send is still recommended for final reassurance.',
            step: 3,
        });
    }

    if (Boolean(props.marketingSettings?.consent?.require_explicit)) {
        items.push({
            key: 'consent',
            title: 'Explicit consent is required',
            description: 'Double-check coverage and legal readiness for the selected audience.',
            step: 2,
        });
    }

    return items;
});

const messageOverviewCards = computed(() => ([
    {
        key: 'enabled',
        label: t('marketing.campaign_wizard.review.enabled_channels'),
        value: String(enabledChannelsCount.value),
        helper: t('marketing.campaign_wizard.parameters.overview_cards.delivery'),
    },
    {
        key: 'configured',
        label: t('marketing.campaign_wizard.foundation.statuses.complete'),
        value: String(configuredMessageChannelsCount.value),
        helper: hasMessageSetup.value
            ? t('marketing.campaign_wizard.foundation.statuses.complete')
            : t('marketing.campaign_wizard.foundation.statuses.attention'),
    },
    {
        key: 'templates',
        label: t('marketing.campaign_wizard.fields.template'),
        value: String(form.channels.filter((channel) => Number(channel.message_template_id || 0) > 0).length),
        helper: t('marketing.campaign_wizard.actions.live_preview'),
    },
    {
        key: 'ab',
        label: t('marketing.campaign_wizard.ab_testing.title'),
        value: String(form.channels.filter((channel) => Boolean(channel.ab_testing?.enabled)).length),
        helper: t('marketing.campaign_wizard.review.ab_enabled_channels'),
    },
]));

const setActiveMessageChannel = (channelKey) => {
    activeMessageChannelKey.value = String(channelKey || '').toUpperCase();
};

const channelRequiresSubject = (channel) => String(channel?.channel || '').toUpperCase() === 'EMAIL';
const channelRequiresTitle = (channel) => String(channel?.channel || '').toUpperCase() === 'IN_APP';

const messageChecklist = (channel) => {
    if (!channel?.is_enabled) {
        return [];
    }

    return [
        {
            key: 'template',
            label: t('marketing.campaign_wizard.fields.template'),
            ready: Number(channel.message_template_id || 0) > 0,
        },
        {
            key: 'subject',
            label: t('marketing.campaign_wizard.fields.subject'),
            ready: !channelRequiresSubject(channel) || String(channel.subject_template || '').trim() !== '',
        },
        {
            key: 'title',
            label: t('marketing.campaign_wizard.fields.title'),
            ready: !channelRequiresTitle(channel) || String(channel.title_template || '').trim() !== '',
        },
        {
            key: 'body',
            label: t('marketing.campaign_wizard.fields.body_template'),
            ready: String(channel.body_template || '').trim() !== '',
        },
    ].filter((item) => {
        if (item.key === 'subject') return channelRequiresSubject(channel);
        if (item.key === 'title') return channelRequiresTitle(channel);
        return true;
    });
};

const messageChannelStateClass = (channel) => {
    const isActive = String(activeMessageChannel.value?.channel || '').toUpperCase() === String(channel?.channel || '').toUpperCase();
    if (isActive) {
        return 'border-green-500 bg-green-50 dark:border-green-400 dark:bg-green-500/10';
    }

    return 'border-stone-200 bg-white hover:border-green-300 dark:border-neutral-700 dark:bg-neutral-900';
};

watch(
    () => form.channels.map((channel) => String(channel.channel || '').toUpperCase()),
    (keys) => {
        if (keys.includes(String(activeMessageChannelKey.value || '').toUpperCase())) {
            return;
        }

        activeMessageChannelKey.value = keys[0] || channels[0] || 'EMAIL';
    },
    { immediate: true }
);

const setupStepComplete = computed(() => Boolean(
    String(form.name || '').trim() !== ''
    && offersPayload.value.length > 0
    && form.campaign_type
));

const reviewStepReady = computed(() => Boolean(
    isEdit.value
    && setupStepComplete.value
    && hasAudienceSetup.value
    && hasMessageSetup.value
    && reviewBlockers.value.length === 0
));

const reviewReadinessScore = computed(() => {
    const checks = [
        isEdit.value,
        setupStepComplete.value,
        hasAudienceSetup.value,
        hasMessageSetup.value,
        hasAudienceEstimate.value,
        previews.value.length > 0 || testResults.value.length > 0,
    ];

    return Math.round((checks.filter(Boolean).length / checks.length) * 100);
});

const reviewReadinessState = computed(() => {
    if (reviewBlockers.value.length > 0) {
        return 'blocked';
    }

    if (reviewWarnings.value.length > 0) {
        return 'attention';
    }

    return 'ready';
});

const reviewReadinessLabel = computed(() => {
    if (reviewReadinessState.value === 'blocked') {
        return 'Blocked';
    }

    if (reviewReadinessState.value === 'attention') {
        return 'Needs attention';
    }

    return 'Ready to send';
});

const reviewReadinessDescription = computed(() => {
    if (reviewReadinessState.value === 'blocked') {
        return `${reviewBlockers.value.length} blocking item(s) must be fixed before send.`;
    }

    if (reviewReadinessState.value === 'attention') {
        return `${reviewWarnings.value.length} recommended check(s) are still open before launch.`;
    }

    return 'Parameters, audience, and message are aligned for launch.';
});

const reviewReadinessPanelClass = computed(() => {
    if (reviewReadinessState.value === 'blocked') {
        return 'border-rose-200 bg-rose-50 dark:border-rose-500/30 dark:bg-rose-500/10';
    }

    if (reviewReadinessState.value === 'attention') {
        return 'border-amber-200 bg-amber-50 dark:border-amber-500/30 dark:bg-amber-500/10';
    }

    return 'border-emerald-200 bg-emerald-50 dark:border-emerald-500/30 dark:bg-emerald-500/10';
});

const resultsOverviewCards = computed(() => ([
    {
        key: 'campaign-status',
        label: 'Campaign status',
        value: campaignStatusLabel(props.campaign?.status || 'draft'),
        helper: props.campaign?.updated_at ? `Updated ${formatDateTime(props.campaign.updated_at)}` : 'Save the draft to start the lifecycle.',
    },
    {
        key: 'latest-run',
        label: 'Latest run',
        value: latestCampaignRun.value ? `#${latestCampaignRun.value.id}` : 'No run yet',
        helper: latestCampaignRun.value
            ? `${runStatusLabel(latestCampaignRun.value.status)} | ${formatDateTime(latestCampaignRun.value.started_at || latestCampaignRun.value.created_at)}`
            : 'Launch from Review to create the first run.',
    },
    {
        key: 'targeted',
        label: 'Targeted',
        value: latestCampaignRun.value
            ? formatNumber(latestRunSummary.value?.targeted || latestCampaignRun.value?.audience_snapshot?.eligible || 0)
            : (hasAudienceEstimate.value ? formatNumber(audienceEstimateTotal.value) : '-'),
        helper: latestCampaignRun.value ? 'Recipients in the latest run' : 'Estimated eligible recipients',
    },
    {
        key: 'delivery',
        label: 'Delivered',
        value: latestCampaignRun.value ? formatNumber(latestRunSummary.value?.delivered || 0) : '-',
        helper: latestCampaignRun.value
            ? `${formatNumber(latestRunSummary.value?.sent || 0)} sent | ${formatNumber(latestRunSummary.value?.failed || 0)} failed`
            : 'Delivery metrics appear after launch.',
    },
]));

const latestRunHighlights = computed(() => {
    if (!latestCampaignRun.value) {
        return [];
    }

    return [
        {
            key: 'trigger',
            label: 'Trigger',
            value: humanizeValue(latestCampaignRun.value.trigger_type || 'manual'),
        },
        {
            key: 'started',
            label: 'Started',
            value: formatDateTime(latestCampaignRun.value.started_at || latestCampaignRun.value.created_at),
        },
        {
            key: 'completed',
            label: 'Completed',
            value: formatDateTime(latestCampaignRun.value.completed_at),
        },
        {
            key: 'engagement',
            label: 'Engagement',
            value: `${formatNumber(latestRunSummary.value?.opened || 0)} opened | ${formatNumber(latestRunSummary.value?.clicked || 0)} clicked`,
        },
    ];
});

const resultsEmptyStateMessage = computed(() => {
    if (String(form.schedule_type || '') === 'scheduled' && form.scheduled_at) {
        return `This campaign is saved and scheduled for ${form.scheduled_at}. The first run will appear here once dispatch starts.`;
    }

    return 'No execution has started yet. Launch the campaign from Review to unlock run history and analytics.';
});

const stepCompletion = computed(() => ({
    1: setupStepComplete.value,
    2: hasAudienceSetup.value,
    3: hasMessageSetup.value,
    4: reviewStepReady.value,
    5: Boolean(isEdit.value),
}));

const stepState = (stepId) => {
    if (step.value === stepId) {
        return 'current';
    }

    if (stepCompletion.value[stepId]) {
        return 'complete';
    }

    if (stepId < step.value) {
        return 'attention';
    }

    return 'upcoming';
};

const wizardSteps = computed(() => {
    return stepBlueprints.map((stepItem) => {
        const state = stepState(stepItem.id);

        return {
            ...stepItem,
            title: t(`marketing.campaign_wizard.steps.${stepItem.key}`),
            summary: t(`marketing.campaign_wizard.foundation.summaries.${stepItem.key}`),
            description: t(`marketing.campaign_wizard.foundation.descriptions.${stepItem.key}`),
            recommendation: t(`marketing.campaign_wizard.foundation.recommendations.${stepItem.key}`),
            state,
            statusLabel: t(`marketing.campaign_wizard.foundation.statuses.${state}`),
        };
    });
});

const stepMeta = (stepId) => wizardSteps.value.find((item) => item.id === stepId) || wizardSteps.value[0];

const currentStepMeta = computed(() => stepMeta(step.value));

const completedStepCount = computed(() => (
    [1, 2, 3, 4].filter((stepId) => stepCompletion.value[stepId]).length
));

const journeyProgressPercent = computed(() => Math.max(
    Math.round((step.value / totalWizardSteps) * 100),
    10
));

const campaignHeaderStatus = computed(() => {
    if (!canManage.value) {
        return t('marketing.campaign_wizard.foundation.header_status.read_only');
    }

    return isEdit.value
        ? t('marketing.campaign_wizard.foundation.header_status.draft')
        : t('marketing.campaign_wizard.foundation.header_status.new');
});

const saveDraftLabel = computed(() => (
    isEdit.value
        ? t('marketing.campaign_wizard.actions.update_draft')
        : t('marketing.campaign_wizard.actions.save_draft')
));

const primaryActionLabel = computed(() => {
    if (step.value === 5) {
        return isEdit.value
            ? t('marketing.campaign_wizard.actions.open_results')
            : t('marketing.campaign_wizard.actions.save_draft');
    }

    if (step.value === 4) {
        return t('marketing.campaign_wizard.actions.save_and_open_results');
    }

    return isEdit.value
        ? t('marketing.campaign_wizard.actions.update_and_continue')
        : t('marketing.campaign_wizard.actions.save_and_continue');
});

const stickyGuidance = computed(() => {
    if (requestError.value) {
        return requestError.value;
    }

    if (Object.keys(form.errors || {}).length > 0) {
        return t('marketing.campaign_wizard.foundation.validation_hint');
    }

    if (step.value === 4) {
        return reviewReadinessDescription.value;
    }

    if (step.value === 5) {
        return latestCampaignRun.value
            ? `Latest run ${runStatusLabel(latestCampaignRun.value.status)}. Open results for the full analytics view.`
            : resultsEmptyStateMessage.value;
    }

    return currentStepMeta.value?.recommendation || '';
});

const selectedOfferCount = computed(() => (
    Array.isArray(form.offers) ? form.offers.length : 0
));

const automaticOfferRuleCount = computed(() => {
    const selectors = form.offer_selectors || {};
    const categoryCount = Array.isArray(selectors.category_ids) ? selectors.category_ids.length : 0;
    const tagCount = Array.isArray(selectors.tags) ? selectors.tags.length : 0;

    return categoryCount + tagCount;
});

const configuredAdvancedParameterCount = computed(() => (
    [
        String(form.locale || '').trim() !== '',
        String(form.cta_url || '').trim() !== '',
    ].filter(Boolean).length
));

const parametersAdvancedOpen = ref(Boolean(
    String(form.locale || '').trim() !== ''
    || String(form.cta_url || '').trim() !== ''
));

const scheduleSummary = computed(() => {
    const scheduleType = String(form.schedule_type || 'manual');
    const base = t(`marketing.campaign_wizard.schedule_type_options.${scheduleType}`);

    if (scheduleType === 'scheduled' && form.scheduled_at) {
        return `${base} | ${form.scheduled_at}`;
    }

    return base;
});

const parametersDirectionHint = computed(() => {
    const direction = String(form.campaign_direction || 'customer_marketing');

    return t(`marketing.campaign_wizard.parameters.direction_hints.${direction}`);
});

const parametersScheduleHint = computed(() => {
    const scheduleType = String(form.schedule_type || 'manual');

    return t(`marketing.campaign_wizard.parameters.schedule_hints.${scheduleType}`);
});

const setupOverviewCards = computed(() => ([
    {
        key: 'strategy',
        label: t('marketing.campaign_wizard.parameters.overview_cards.strategy'),
        value: `${campaignTypeLabel(form.campaign_type)} | ${offerModeLabel(form.offer_mode)}`,
    },
    {
        key: 'direction',
        label: t('marketing.campaign_wizard.parameters.overview_cards.direction'),
        value: `${directionLabel(form.campaign_direction)} | ${form.prospecting_enabled ? t('marketing.campaign_wizard.parameters.prospecting_on') : t('marketing.campaign_wizard.parameters.prospecting_off')}`,
    },
    {
        key: 'delivery',
        label: t('marketing.campaign_wizard.parameters.overview_cards.delivery'),
        value: scheduleSummary.value,
    },
    {
        key: 'offers',
        label: t('marketing.campaign_wizard.parameters.overview_cards.offers'),
        value: t('marketing.campaign_wizard.parameters.offers_summary', {
            selected: selectedOfferCount.value,
            rules: automaticOfferRuleCount.value,
        }),
    },
]));

const setStep = (nextStep) => {
    const candidate = Number(nextStep || 0);
    if (!Number.isInteger(candidate) || candidate < 1 || candidate > totalWizardSteps) {
        return;
    }

    step.value = candidate;
};

const saveStepProgress = () => {
    if (step.value === 5) {
        return;
    }

    save(step.value);
};

const goToPreviousStep = () => {
    setStep(step.value - 1);
};

const runPrimaryWizardAction = () => {
    if (step.value === 5) {
        if (!isEdit.value) {
            save(step.value);
            return;
        }

        router.visit(route('campaigns.show', campaignId.value));
        return;
    }

    save(Math.min(totalWizardSteps, step.value + 1));
};

const audiencePayload = () => {
    const providerContext = prospectingImportMode.value === 'provider'
        ? {
            provider_connection_id: selectedProspectProvider.value
                ? Number(selectedProspectProvider.value.id)
                : (savedAudienceSourceSummary?.provider_connection_id ?? null),
            provider_key: selectedProspectProvider.value?.provider_key || savedAudienceSourceSummary?.provider_key || null,
            provider_label: selectedProspectProvider.value?.provider_label || savedAudienceSourceSummary?.provider_label || null,
            provider_connection_label: selectedProspectProvider.value?.label || savedAudienceSourceSummary?.provider_connection_label || null,
            provider_query_label: String(prospectingProviderQueryLabel.value || '').trim() || null,
            provider_query: String(normalizedProspectingProviderQuery.value || '').trim() || null,
        }
        : {
            provider_connection_id: null,
            provider_key: null,
            provider_label: null,
            provider_connection_label: null,
            provider_query_label: null,
            provider_query: null,
        };

    return {
        smart_filters: props.campaign?.audience?.smart_filters || null,
        exclusion_filters: props.campaign?.audience?.exclusion_filters || null,
        manual_customer_ids: selectedAudienceCustomerIds.value,
        include_mailing_list_ids: includeMailingListIds.value,
        exclude_mailing_list_ids: excludeMailingListIds.value,
        source_logic: sourceLogic.value,
        source_summary: {
            logic: sourceLogic.value,
            include_mailing_lists_count: includeMailingListIds.value.length,
            exclude_mailing_lists_count: excludeMailingListIds.value.length,
            import_mode: String(prospectingImportMode.value || 'manual'),
            source_type: String(prospectingSourceType.value || 'manual'),
            source_reference: String(prospectingSourceReference.value || '').trim()
                || (prospectingImportMode.value === 'provider'
                    ? (savedAudienceSourceSummary?.source_reference || null)
                    : null),
            ...providerContext,
        },
        manual_contacts: initialManualContacts,
    };
};

const templatesForChannel = (channel) => {
    return templates.value.filter((row) => String(row.channel).toUpperCase() === String(channel).toUpperCase());
};

const isMailingListIncluded = (id) => includeMailingListIds.value.includes(Number(id));
const isMailingListExcluded = (id) => excludeMailingListIds.value.includes(Number(id));

const toggleIncludeMailingList = (id) => {
    const candidate = Number(id);
    if (!Number.isInteger(candidate) || candidate <= 0) {
        return;
    }

    if (isMailingListIncluded(candidate)) {
        includeMailingListIds.value = includeMailingListIds.value.filter((value) => value !== candidate);
        return;
    }

    includeMailingListIds.value = [...includeMailingListIds.value, candidate];
    excludeMailingListIds.value = excludeMailingListIds.value.filter((value) => value !== candidate);
};

const toggleExcludeMailingList = (id) => {
    const candidate = Number(id);
    if (!Number.isInteger(candidate) || candidate <= 0) {
        return;
    }

    if (isMailingListExcluded(candidate)) {
        excludeMailingListIds.value = excludeMailingListIds.value.filter((value) => value !== candidate);
        return;
    }

    excludeMailingListIds.value = [...excludeMailingListIds.value, candidate];
    includeMailingListIds.value = includeMailingListIds.value.filter((value) => value !== candidate);
};

const applyTemplate = (channelRow) => {
    const id = Number(channelRow.message_template_id || 0);
    if (!id) return;
    const template = templates.value.find((row) => Number(row.id) === id);
    if (!template) return;
    const content = template.content || {};
    const channelTemplates = template.channel_templates || {};
    const channel = String(channelRow.channel).toUpperCase();
    if (channel === 'EMAIL') {
        channelRow.subject_template = channelTemplates.subject_template || content.subject || '';
        channelRow.body_template = channelTemplates.body_template || content.html || content.body || '';
    } else if (channel === 'SMS') {
        channelRow.body_template = channelTemplates.body_template || content.text || content.body || '';
    } else if (channel === 'IN_APP') {
        channelRow.title_template = channelTemplates.title_template || content.title || '';
        channelRow.body_template = channelTemplates.body_template || content.body || '';
    }
};

const clampPercent = (value, min = 0, max = 100) => {
    const parsed = Number(value);
    if (!Number.isFinite(parsed)) {
        return min;
    }

    return Math.min(max, Math.max(min, Math.round(parsed)));
};

const fallbackTargets = (sourceChannel) => {
    const source = String(sourceChannel || '').toUpperCase();
    if (!source) return [];
    return Array.isArray(form.settings.channel_fallback.map?.[source])
        ? form.settings.channel_fallback.map[source]
        : [];
};

const toggleFallbackTarget = (sourceChannel, targetChannel) => {
    const source = String(sourceChannel || '').toUpperCase();
    const target = String(targetChannel || '').toUpperCase();
    if (!source || !target || source === target) {
        return;
    }

    if (!form.settings.channel_fallback.map?.[source]) {
        form.settings.channel_fallback.map[source] = [];
    }

    const current = Array.isArray(form.settings.channel_fallback.map[source])
        ? [...form.settings.channel_fallback.map[source]]
        : [];
    if (current.includes(target)) {
        form.settings.channel_fallback.map[source] = current.filter((candidate) => candidate !== target);
        return;
    }

    form.settings.channel_fallback.map[source] = [...current, target];
};

const normalizeChannelSettings = (channel) => {
    const ab = channel?.ab_testing || {};
    return {
        enabled: Boolean(ab.enabled),
        split_a_percent: clampPercent(ab.split_a_percent, 0, 100),
        variant_a: {
            subject_template: String(ab?.variant_a?.subject_template || ''),
            title_template: String(ab?.variant_a?.title_template || ''),
            body_template: String(ab?.variant_a?.body_template || ''),
        },
        variant_b: {
            subject_template: String(ab?.variant_b?.subject_template || ''),
            title_template: String(ab?.variant_b?.title_template || ''),
            body_template: String(ab?.variant_b?.body_template || ''),
        },
    };
};

const save = (nextStep = null) => {
    if (!canManage.value) return;
    if (offersPayload.value.length === 0) {
        form.setError('offers', t('marketing.campaign_wizard.errors.select_offer'));
        step.value = 1;
        return;
    }

    rememberWizardStep(nextStep);

    const payload = {
        ...form.data(),
        type: form.campaign_type,
        prospecting_enabled: Boolean(form.prospecting_enabled),
        campaign_direction: form.prospecting_enabled
            ? String(form.campaign_direction || 'prospecting_outbound')
            : 'customer_marketing',
        offers: offersPayload.value,
        product_ids: productIdsPayload.value.length > 0 ? productIdsPayload.value : null,
        scheduled_at: form.schedule_type === 'scheduled' ? (form.scheduled_at || null) : null,
        audience_segment_id: form.audience_segment_id || null,
        channels: form.channels.map((channel) => ({
            channel: String(channel.channel).toUpperCase(),
            is_enabled: Boolean(channel.is_enabled),
            message_template_id: channel.message_template_id ? Number(channel.message_template_id) : null,
            subject_template: channel.subject_template || null,
            title_template: channel.title_template || null,
            body_template: channel.body_template || null,
            metadata: {
                ab_testing: normalizeChannelSettings(channel),
            },
        })),
        audience: audiencePayload(),
        settings: {
            ...form.settings,
            offer_selectors: form.offer_selectors,
            holdout: {
                enabled: Boolean(form.settings.holdout?.enabled),
                percent: clampPercent(form.settings.holdout?.percent, 0, 100),
            },
            channel_fallback: {
                enabled: Boolean(form.settings.channel_fallback?.enabled),
                max_depth: clampPercent(form.settings.channel_fallback?.max_depth, 1, 3),
                map: normalizeFallbackMap(form.settings.channel_fallback?.map || {}),
            },
        },
    };

    form.transform(() => payload);
    if (isEdit.value) {
        form.put(route('campaigns.update', campaignId.value), { preserveScroll: true });
    } else {
        form.post(route('campaigns.store'), { preserveScroll: true });
    }
};

const runAction = async (callback) => {
    requestBusy.value = true;
    requestError.value = '';
    try {
        await callback();
    } catch (error) {
        requestError.value = error?.response?.data?.message || error?.message || t('marketing.campaign_wizard.errors.request_failed');
    } finally {
        requestBusy.value = false;
    }
};

const estimateAudience = async () => {
    if (!campaignId.value || !canManage.value) return;
    await runAction(async () => {
        const response = await axios.post(route('campaigns.estimate', campaignId.value));
        estimate.value = response.data?.estimated || null;
    });
};

const previewMessages = async () => {
    if (!campaignId.value || !canManage.value) return;
    await runAction(async () => {
        const response = await axios.post(route('campaigns.preview', campaignId.value), { sample_size: 3 });
        previews.value = Array.isArray(response.data?.previews) ? response.data.previews : [];
    });
};

const testSend = async () => {
    if (!campaignId.value || (!canManage.value && !canSend.value)) return;
    const enabled = form.channels.filter((row) => row.is_enabled).map((row) => String(row.channel).toUpperCase());
    await runAction(async () => {
        const response = await axios.post(route('campaigns.test-send', campaignId.value), { channels: enabled });
        testResults.value = Array.isArray(response.data?.results) ? response.data.results : [];
    });
};

const sendNow = async () => {
    if (!campaignId.value || !canSend.value) return;
    await runAction(async () => {
        const response = await axios.post(route('campaigns.send', campaignId.value));
        runMessage.value = response.data?.message || t('marketing.campaign_wizard.run_queued');
    });
};

onMounted(async () => {
    try {
        const response = await axios.get(route('marketing.templates.index'));
        templates.value = Array.isArray(response.data?.templates) ? response.data.templates : [];
    } catch {
        templates.value = [];
    }

    const restoredStep = restoreWizardStep();
    if (restoredStep) {
        step.value = restoredStep;
    }

    if (isProspectingMode.value && campaignId.value) {
        await loadProspectBatches();
    }
});

watch(isProspectingMode, async (enabled) => {
    if (!enabled || !campaignId.value) {
        clearProviderPreview();
        return;
    }

    await loadProspectBatches();
});
</script>

<template>
    <Head :title="isEdit ? t('marketing.campaign_wizard.head_edit', { id: campaignId }) : t('marketing.campaign_wizard.head_new')" />
    <AuthenticatedLayout>
        <div class="space-y-6 pb-28">
            <section class="rounded-lg border border-stone-200 border-t-4 border-t-green-600 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
                    <div class="space-y-3">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="inline-flex rounded-full border border-green-200 bg-green-50 px-3 py-1 text-xs font-semibold text-green-700 dark:border-green-500/30 dark:bg-green-500/10 dark:text-green-200">
                                {{ campaignHeaderStatus }}
                            </span>
                            <span class="inline-flex rounded-full border border-stone-200 bg-stone-50 px-3 py-1 text-xs font-medium text-stone-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300">
                                {{ t('marketing.campaign_wizard.foundation.current_step', { current: step, total: totalWizardSteps }) }}
                            </span>
                        </div>

                        <div>
                            <h1 class="inline-flex items-center gap-2 text-xl font-semibold text-stone-800 dark:text-neutral-100">
                                <svg class="size-5 text-green-600 dark:text-green-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M4 4h16v16H4z" />
                                    <path d="M8 8h8" />
                                    <path d="M8 12h8" />
                                    <path d="M8 16h5" />
                                </svg>
                                <span>{{ isEdit ? t('marketing.campaign_wizard.header_edit', { id: campaignId }) : t('marketing.campaign_wizard.header_new') }}</span>
                            </h1>
                            <p class="mt-2 max-w-3xl text-sm leading-6 text-stone-500 dark:text-neutral-400">
                                {{ t('marketing.campaign_wizard.description') }}
                            </p>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-2 xl:justify-end">
                        <Link :href="route('campaigns.index')">
                            <SecondaryButton>{{ t('marketing.campaign_wizard.actions.back_to_list') }}</SecondaryButton>
                        </Link>
                        <Link v-if="isEdit" :href="route('campaigns.show', campaignId)">
                            <SecondaryButton>{{ t('marketing.campaign_wizard.actions.details') }}</SecondaryButton>
                        </Link>
                        <SecondaryButton type="button" :disabled="form.processing || !canManage" @click="saveStepProgress">
                            {{ form.processing ? t('marketing.campaign_wizard.actions.saving') : saveDraftLabel }}
                        </SecondaryButton>
                    </div>
                </div>

                <div class="mt-5 grid gap-4 xl:grid-cols-[minmax(0,1fr),340px]">
                    <div>
                        <div class="flex items-center justify-between gap-2 text-xs font-medium text-stone-500 dark:text-neutral-400">
                            <span>{{ currentStepMeta.title }}</span>
                            <span>{{ t('marketing.campaign_wizard.foundation.progress_ready', { count: completedStepCount }) }}</span>
                        </div>
                        <div class="mt-2 h-2 overflow-hidden rounded-full bg-stone-100 dark:bg-neutral-800">
                            <div class="h-full rounded-full bg-green-600 transition-all dark:bg-green-500" :style="{ width: `${journeyProgressPercent}%` }"></div>
                        </div>
                        <p class="mt-3 text-sm leading-6 text-stone-500 dark:text-neutral-400">
                            {{ currentStepMeta.description }}
                        </p>
                    </div>

                    <div class="rounded-lg border border-stone-200 bg-stone-50 px-4 py-4 dark:border-neutral-700 dark:bg-neutral-800">
                        <div class="text-[11px] font-semibold uppercase tracking-[0.18em] text-stone-500 dark:text-neutral-400">
                            {{ t('marketing.campaign_wizard.foundation.recommended_next') }}
                        </div>
                        <p class="mt-2 text-sm font-medium leading-6 text-stone-800 dark:text-neutral-100">
                            {{ currentStepMeta.recommendation }}
                        </p>
                    </div>
                </div>

                <div class="mt-5">
                    <CampaignStepRail :steps="wizardSteps" :current-step="step" @select="setStep" />
                </div>
            </section>

            <section v-show="step === 1" class="space-y-4 rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <CampaignStepHeading
                    :eyebrow="t('marketing.campaign_wizard.foundation.current_step', { current: 1, total: totalWizardSteps })"
                    :title="stepMeta(1).title"
                    :description="stepMeta(1).description"
                    :recommendation="stepMeta(1).recommendation"
                    :recommendation-label="t('marketing.campaign_wizard.foundation.recommended_next')"
                />
                <div class="grid grid-cols-1 gap-3 xl:grid-cols-4">
                    <div
                        v-for="item in setupOverviewCards"
                        :key="item.key"
                        class="rounded-lg border border-stone-200 bg-stone-50 px-4 py-4 dark:border-neutral-700 dark:bg-neutral-800"
                    >
                        <div class="text-[11px] font-semibold uppercase tracking-[0.18em] text-stone-500 dark:text-neutral-400">
                            {{ item.label }}
                        </div>
                        <div class="mt-2 text-sm font-medium leading-6 text-stone-800 dark:text-neutral-100">
                            {{ item.value }}
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,1.3fr),minmax(320px,0.9fr)]">
                    <CampaignSectionCard
                        :title="t('marketing.campaign_wizard.parameters.essentials_title')"
                        :description="t('marketing.campaign_wizard.parameters.essentials_description')"
                    >
                        <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                            <FloatingInput v-model="form.name" :label="t('marketing.campaign_wizard.fields.campaign_name')" />
                            <FloatingSelect
                                v-model="form.campaign_type"
                                :label="t('marketing.campaign_wizard.fields.campaign_type')"
                                :options="types.map((type) => ({ value: type, label: campaignTypeLabel(type) }))"
                                option-value="value"
                                option-label="label"
                            />
                            <FloatingSelect
                                v-model="form.offer_mode"
                                :label="t('marketing.campaign_wizard.fields.offer_mode')"
                                :options="offerModes.map((mode) => ({ value: mode, label: offerModeLabel(mode) }))"
                                option-value="value"
                                option-label="label"
                            />
                            <FloatingSelect
                                v-model="form.language_mode"
                                :label="t('marketing.campaign_wizard.fields.language_mode')"
                                :options="languageModes.map((mode) => ({ value: mode, label: languageModeLabel(mode) }))"
                                option-value="value"
                                option-label="label"
                            />
                        </div>
                    </CampaignSectionCard>

                    <CampaignSectionCard
                        :title="t('marketing.campaign_wizard.parameters.advanced_title')"
                        :description="t('marketing.campaign_wizard.parameters.advanced_description')"
                        compact
                    >
                        <template #actions>
                            <button
                                type="button"
                                class="text-xs font-medium text-green-700 underline underline-offset-2 dark:text-green-300"
                                @click="parametersAdvancedOpen = !parametersAdvancedOpen"
                            >
                                {{ parametersAdvancedOpen ? t('marketing.campaign_wizard.parameters.hide_advanced') : t('marketing.campaign_wizard.parameters.show_advanced') }}
                            </button>
                        </template>

                        <div class="rounded-lg border border-dashed border-stone-300 bg-stone-50 px-4 py-3 text-xs leading-5 text-stone-500 dark:border-neutral-600 dark:bg-neutral-800 dark:text-neutral-400">
                            {{
                                parametersAdvancedOpen
                                    ? t('marketing.campaign_wizard.parameters.advanced_open_summary')
                                    : (
                                        configuredAdvancedParameterCount > 0
                                            ? t('marketing.campaign_wizard.parameters.advanced_configured_summary', { count: configuredAdvancedParameterCount })
                                            : t('marketing.campaign_wizard.parameters.advanced_closed_summary')
                                    )
                            }}
                        </div>

                        <div v-if="parametersAdvancedOpen" class="mt-4 grid grid-cols-1 gap-3">
                            <FloatingInput v-model="form.locale" :label="t('marketing.campaign_wizard.fields.locale')" />
                            <FloatingInput v-model="form.cta_url" type="url" :label="t('marketing.campaign_wizard.fields.cta_url')" />
                        </div>
                    </CampaignSectionCard>
                </div>

                <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
                    <CampaignSectionCard
                        :title="t('marketing.campaign_wizard.parameters.mode_title')"
                        :description="t('marketing.campaign_wizard.parameters.mode_description')"
                    >
                        <div class="grid grid-cols-1 gap-3 lg:grid-cols-[280px,minmax(0,1fr)]">
                            <div class="rounded-lg border border-stone-200 bg-stone-50 px-4 py-4 dark:border-neutral-700 dark:bg-neutral-800">
                                <label class="inline-flex items-start gap-3 text-sm font-medium text-stone-800 dark:text-neutral-100">
                                    <input
                                        v-model="form.prospecting_enabled"
                                        type="checkbox"
                                        class="mt-0.5 rounded border-stone-300 text-green-600 focus:ring-green-600"
                                    >
                                    <span>
                                        <span class="block">{{ t('marketing.campaign_wizard.fields.prospecting_enabled') }}</span>
                                        <span class="mt-1 block text-xs font-normal leading-5 text-stone-500 dark:text-neutral-400">
                                            {{ t('marketing.campaign_wizard.parameters.prospecting_help') }}
                                        </span>
                                    </span>
                                </label>
                            </div>
                            <div class="space-y-3">
                                <FloatingSelect
                                    v-model="form.campaign_direction"
                                    :label="t('marketing.campaign_wizard.fields.campaign_direction')"
                                    :options="directions.map((direction) => ({ value: direction, label: directionLabel(direction) }))"
                                    option-value="value"
                                    option-label="label"
                                />
                                <div class="rounded-lg border border-dashed border-stone-300 bg-white px-4 py-3 text-xs leading-5 text-stone-500 dark:border-neutral-600 dark:bg-neutral-900 dark:text-neutral-400">
                                    {{ parametersDirectionHint }}
                                </div>
                            </div>
                        </div>
                    </CampaignSectionCard>

                    <CampaignSectionCard
                        :title="t('marketing.campaign_wizard.parameters.delivery_title')"
                        :description="t('marketing.campaign_wizard.parameters.delivery_description')"
                    >
                        <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                            <FloatingSelect
                                v-model="form.schedule_type"
                                :label="t('marketing.campaign_wizard.fields.schedule_type')"
                                :options="scheduleTypeOptions"
                                option-value="value"
                                option-label="label"
                            />
                            <FloatingInput
                                v-if="form.schedule_type === 'scheduled'"
                                v-model="form.scheduled_at"
                                type="datetime-local"
                                :label="t('marketing.campaign_wizard.fields.scheduled_at')"
                            />
                        </div>
                        <div class="mt-4 rounded-lg border border-dashed border-stone-300 bg-stone-50 px-4 py-3 text-xs leading-5 text-stone-500 dark:border-neutral-600 dark:bg-neutral-800 dark:text-neutral-400">
                            {{ parametersScheduleHint }}
                        </div>
                    </CampaignSectionCard>
                </div>

                <div class="space-y-2">
                    <div>
                        <h3 class="text-base font-semibold text-stone-800 dark:text-neutral-100">
                            {{ t('marketing.campaign_wizard.parameters.offers_title') }}
                        </h3>
                        <p class="mt-1 text-sm leading-6 text-stone-500 dark:text-neutral-400">
                            {{ t('marketing.campaign_wizard.parameters.offers_description') }}
                        </p>
                    </div>
                    <OfferSelector v-model="form.offers" v-model:selectors="form.offer_selectors" :offer-mode="form.offer_mode" :disabled="!canManage" />
                </div>
                <p v-if="form.errors.offers" class="text-xs text-rose-600">{{ form.errors.offers }}</p>
            </section>

            <section v-show="step === 2" class="space-y-3 rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <CampaignStepHeading
                    :eyebrow="t('marketing.campaign_wizard.foundation.current_step', { current: 2, total: totalWizardSteps })"
                    :title="stepMeta(2).title"
                    :description="stepMeta(2).description"
                    :recommendation="stepMeta(2).recommendation"
                    :recommendation-label="t('marketing.campaign_wizard.foundation.recommended_next')"
                />
                <div
                    v-if="isProspectingMode"
                    class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-4"
                >
                    <div
                        v-for="card in prospectingOverviewCards"
                        :key="`prospecting-audience-overview-${card.key}`"
                        class="rounded-lg border border-stone-200 bg-stone-50 px-4 py-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800/80"
                    >
                        <div class="text-[11px] font-semibold uppercase tracking-[0.16em] text-stone-500 dark:text-neutral-400">
                            {{ card.label }}
                        </div>
                        <div class="mt-2 text-base font-semibold text-stone-800 dark:text-neutral-100">
                            {{ card.value }}
                        </div>
                        <div class="mt-1 text-xs leading-5 text-stone-500 dark:text-neutral-400">
                            {{ card.helper }}
                        </div>
                    </div>
                </div>
                <div
                    v-else
                    class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-4"
                >
                    <div
                        v-for="card in audienceOverviewCards"
                        :key="`audience-overview-${card.key}`"
                        class="rounded-lg border border-stone-200 bg-stone-50 px-4 py-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800/80"
                    >
                        <div class="text-[11px] font-semibold uppercase tracking-[0.16em] text-stone-500 dark:text-neutral-400">
                            {{ card.label }}
                        </div>
                        <div class="mt-2 text-base font-semibold text-stone-800 dark:text-neutral-100">
                            {{ card.value }}
                        </div>
                        <div class="mt-1 text-xs leading-5 text-stone-500 dark:text-neutral-400">
                            {{ card.helper }}
                        </div>
                    </div>
                </div>
                <template v-if="isProspectingMode">
                    <div class="rounded-sm border border-green-200 bg-green-50 px-3 py-3 text-xs text-green-800 dark:border-green-500/20 dark:bg-green-500/10 dark:text-green-200">
                        <div class="font-semibold">{{ t('marketing.campaign_wizard.prospecting.title') }}</div>
                        <p class="mt-1">{{ t('marketing.campaign_wizard.prospecting.description') }}</p>
                    </div>

                    <div v-if="!isEdit" class="rounded-sm border border-amber-200 bg-amber-50 px-3 py-3 text-xs text-amber-800 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-200">
                        <div class="font-semibold">{{ t('marketing.campaign_wizard.prospecting.save_draft_title') }}</div>
                        <p class="mt-1">{{ t('marketing.campaign_wizard.prospecting.save_draft_description') }}</p>
                        <div class="mt-3">
                            <PrimaryButton type="button" :disabled="form.processing || !canManage" @click="saveProspectingDraft">
                                {{ t('marketing.campaign_wizard.prospecting.actions.create_draft') }}
                            </PrimaryButton>
                        </div>
                    </div>

                    <template v-else>
                        <div class="grid grid-cols-1 gap-2 md:grid-cols-2 xl:grid-cols-4">
                            <FloatingSelect
                                v-model="prospectingImportMode"
                                :label="t('marketing.campaign_wizard.prospecting.fields.import_mode')"
                                :options="prospectingImportModeOptions"
                                option-value="value"
                                option-label="label"
                            />
                            <FloatingSelect
                                v-if="prospectingImportMode !== 'provider'"
                                v-model="prospectingSourceType"
                                :label="t('marketing.campaign_wizard.prospecting.fields.source_type')"
                                :options="prospectingSourceTypeOptions"
                                option-value="value"
                                option-label="label"
                            />
                            <FloatingInput
                                v-if="prospectingImportMode !== 'provider'"
                                v-model="prospectingSourceReference"
                                :label="t('marketing.campaign_wizard.prospecting.fields.source_reference')"
                            />
                            <FloatingInput
                                v-model="prospectingBatchSize"
                                type="number"
                                :label="t('marketing.campaign_wizard.prospecting.fields.batch_size')"
                                readonly
                            />
                        </div>

                        <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div class="text-xs font-semibold text-stone-700 dark:text-neutral-200">
                                    {{ t('marketing.campaign_wizard.prospecting.source_title') }}
                                </div>
                                <div class="text-xs text-stone-500 dark:text-neutral-400">
                                    {{ t('marketing.campaign_wizard.prospecting.batch_cap_hint') }}
                                </div>
                            </div>

                            <div v-if="prospectingImportMode === 'manual'" class="mt-3 space-y-2">
                                <EmailBodyEditor
                                    v-model="prospectingManualInput"
                                    :label="t('marketing.campaign_wizard.prospecting.fields.manual_input')"
                                    :placeholder="t('marketing.campaign_wizard.prospecting.manual_placeholder')"
                                />
                                <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-stone-500 dark:text-neutral-400">
                                    <span>{{ t('marketing.campaign_wizard.prospecting.manual_hint') }}</span>
                                    <span>{{ t('marketing.campaign_wizard.prospecting.manual_line_count', { count: manualProspectLineCount }) }}</span>
                                </div>
                            </div>

                            <div v-else-if="prospectingImportMode === 'csv'" class="mt-3 space-y-2">
                                <label class="block text-xs font-medium text-stone-600 dark:text-neutral-300">
                                    {{ t('marketing.campaign_wizard.prospecting.fields.csv_file') }}
                                </label>
                                <input
                                    type="file"
                                    accept=".csv,.txt,text/csv"
                                    class="block w-full rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm text-stone-700 file:mr-3 file:rounded-sm file:border-0 file:bg-green-600 file:px-3 file:py-2 file:text-sm file:font-semibold file:text-white hover:file:bg-green-700 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                                    @change="setProspectingFile"
                                >
                                <div class="text-xs text-stone-500 dark:text-neutral-400">
                                    {{ prospectingSelectedFile?.name || t('marketing.campaign_wizard.prospecting.no_file_selected') }}
                                </div>
                            </div>

                            <div v-else class="mt-3 space-y-3">
                                <div
                                    v-if="prospectingProviderOptions.length === 0"
                                    class="rounded-sm border border-amber-200 bg-amber-50 px-3 py-3 text-xs text-amber-800 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-200"
                                >
                                    <div class="font-semibold">{{ t('marketing.campaign_wizard.prospecting.provider_empty_title') }}</div>
                                    <p class="mt-1">{{ t('marketing.campaign_wizard.prospecting.provider_empty_description') }}</p>
                                    <div class="mt-3">
                                        <Link :href="route('campaigns.prospect-providers.manage')">
                                            <SecondaryButton type="button">
                                                {{ t('marketing.campaign_wizard.prospecting.actions.manage_providers') }}
                                            </SecondaryButton>
                                        </Link>
                                    </div>
                                </div>
                                <template v-else>
                                    <div class="grid grid-cols-1 gap-2 md:grid-cols-2">
                                        <FloatingSelect
                                            v-model="prospectingProviderConnectionId"
                                            :label="t('marketing.campaign_wizard.prospecting.fields.provider_connection')"
                                            :options="prospectingProviderOptions"
                                            option-value="value"
                                            option-label="label"
                                        />
                                        <FloatingInput
                                            v-model="prospectingProviderQueryLabel"
                                            :label="t('marketing.campaign_wizard.prospecting.fields.provider_query_label')"
                                        />
                                    </div>
                                    <EmailBodyEditor
                                        v-model="prospectingProviderQuery"
                                        :label="t('marketing.campaign_wizard.prospecting.fields.provider_query')"
                                        :placeholder="prospectingProviderPlaceholder"
                                        compact
                                    />
                                    <div class="rounded-sm border border-dashed border-stone-300 bg-white px-3 py-2 text-xs text-stone-500 dark:border-neutral-600 dark:bg-neutral-900 dark:text-neutral-400">
                                        {{ t('marketing.campaign_wizard.prospecting.provider_query_hint') }}
                                    </div>
                                </template>
                            </div>

                            <div class="mt-3 flex flex-wrap gap-2">
                                <template v-if="prospectingImportMode === 'provider'">
                                    <PrimaryButton type="button" :disabled="!canSaveProviderSelection" @click="save(2)">
                                        {{ form.processing ? t('marketing.campaign_wizard.actions.saving') : t('marketing.campaign_wizard.prospecting.actions.save_provider_selection') }}
                                    </PrimaryButton>
                                    <SecondaryButton type="button" :disabled="!canLoadProviderPreview" @click="previewProviderProspects">
                                        {{ providerPreviewBusy ? t('marketing.campaign_wizard.prospecting.actions.previewing_provider') : t('marketing.campaign_wizard.prospecting.actions.preview_provider') }}
                                    </SecondaryButton>
                                </template>
                                <template v-else>
                                    <PrimaryButton type="button" :disabled="!canAnalyzeProspectBatch" @click="analyzeProspectBatch">
                                        {{ prospectingImportBusy ? t('marketing.campaign_wizard.prospecting.actions.analyzing') : t('marketing.campaign_wizard.prospecting.actions.analyze_batch') }}
                                    </PrimaryButton>
                                </template>
                                <SecondaryButton type="button" :disabled="prospectingBatchBusy" @click="loadProspectBatches(activeProspectBatchId)">
                                    {{ t('marketing.common.reload') }}
                                </SecondaryButton>
                            </div>

                            <p v-if="prospectingImportError" class="mt-2 text-xs text-rose-600">{{ prospectingImportError }}</p>
                            <p v-if="prospectingImportMessage" class="mt-2 text-xs text-emerald-700 dark:text-emerald-300">{{ prospectingImportMessage }}</p>
                            <p v-if="providerPreviewError" class="mt-2 text-xs text-rose-600">{{ providerPreviewError }}</p>
                            <p v-if="providerPreviewMessage" class="mt-2 text-xs text-emerald-700 dark:text-emerald-300">{{ providerPreviewMessage }}</p>
                            <p v-if="prospectingImportMode === 'provider'" class="mt-2 text-xs text-stone-500 dark:text-neutral-400">
                                {{ t('marketing.campaign_wizard.prospecting.provider_next_step_hint') }}
                            </p>
                        </div>

                        <div
                            v-if="prospectingImportMode === 'provider' && (providerPreviewRows.length > 0 || providerPreviewBusy || providerPreviewError || providerPreviewMessage)"
                            class="rounded-sm border border-stone-200 bg-white p-3 shadow-sm dark:border-neutral-700 dark:bg-neutral-900"
                        >
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <div class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                        {{ t('marketing.campaign_wizard.prospecting.preview_title') }}
                                    </div>
                                    <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                        {{ t('marketing.campaign_wizard.prospecting.preview_hint', {
                                            count: providerPreviewMeta?.count || providerPreviewRows.length || 0,
                                            provider: providerPreviewConnection?.label || prospectingProviderSelectionSummary || '-',
                                        }) }}
                                    </p>
                                </div>
                                <div class="flex flex-wrap items-center gap-2 text-xs">
                                    <span class="rounded-sm border border-stone-200 bg-stone-50 px-2 py-1 text-stone-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300">
                                        {{ t('marketing.campaign_wizard.prospecting.preview_selected_count', { count: selectedProviderPreviewCount, total: providerPreviewRows.length || 0 }) }}
                                    </span>
                                    <PrimaryButton
                                        v-if="providerPreviewRows.length"
                                        type="button"
                                        :disabled="!canImportSelectedProviderPreview"
                                        @click="importSelectedProviderProspects"
                                    >
                                        {{ prospectingImportBusy ? t('marketing.campaign_wizard.prospecting.actions.importing_selected_preview') : t('marketing.campaign_wizard.prospecting.actions.import_selected_preview') }}
                                    </PrimaryButton>
                                    <SecondaryButton
                                        v-if="providerPreviewRows.length"
                                        type="button"
                                        @click="toggleAllProviderPreviewRows"
                                    >
                                        {{ allProviderPreviewSelected ? t('marketing.campaign_wizard.prospecting.preview_unselect_all') : t('marketing.campaign_wizard.prospecting.preview_select_all') }}
                                    </SecondaryButton>
                                </div>
                            </div>

                            <div v-if="providerPreviewMeta?.query || providerPreviewMeta?.query_label" class="mt-3 flex flex-wrap gap-2 text-xs">
                                <span
                                    v-if="providerPreviewMeta?.query_label"
                                    class="rounded-sm border border-stone-200 bg-stone-50 px-2 py-1 text-stone-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300"
                                >
                                    {{ providerPreviewMeta.query_label }}
                                </span>
                                <span
                                    v-if="providerPreviewMeta?.query"
                                    class="rounded-sm border border-stone-200 bg-stone-50 px-2 py-1 text-stone-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300"
                                >
                                    {{ providerPreviewMeta.query }}
                                </span>
                            </div>

                            <div
                                v-if="providerPreviewRows.length"
                                class="mt-3 grid grid-cols-1 gap-2 xl:grid-cols-3"
                            >
                                <div class="rounded-sm border border-stone-200 bg-stone-50 px-3 py-3 text-xs dark:border-neutral-700 dark:bg-neutral-800">
                                    <div class="text-stone-500 dark:text-neutral-400">{{ t('marketing.campaign_wizard.prospecting.preview_fresh_rows') }}</div>
                                    <div class="mt-1 text-lg font-semibold text-stone-800 dark:text-neutral-100">{{ providerPreviewMeta?.fresh_count ?? providerPreviewFreshCount }}</div>
                                </div>
                                <div class="rounded-sm border border-amber-200 bg-amber-50 px-3 py-3 text-xs dark:border-amber-500/30 dark:bg-amber-500/10">
                                    <div class="text-amber-700 dark:text-amber-300">{{ t('marketing.campaign_wizard.prospecting.preview_already_imported_rows') }}</div>
                                    <div class="mt-1 text-lg font-semibold text-amber-900 dark:text-amber-100">{{ providerPreviewMeta?.already_imported_count ?? providerPreviewAlreadyImportedCount }}</div>
                                </div>
                                <div class="rounded-sm border border-stone-200 bg-stone-50 px-3 py-3 text-xs dark:border-neutral-700 dark:bg-neutral-800">
                                    <div class="text-stone-500 dark:text-neutral-400">{{ t('marketing.campaign_wizard.prospecting.preview_last_imported_at') }}</div>
                                    <div class="mt-1 font-medium text-stone-800 dark:text-neutral-100">{{ providerPreviewMeta?.latest_imported_at ? formatDateTime(providerPreviewMeta.latest_imported_at) : '-' }}</div>
                                </div>
                            </div>

                            <div class="mt-3 rounded-sm border border-amber-200 bg-amber-50 px-3 py-3 text-xs text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100">
                                <div class="font-semibold">{{ t('marketing.campaign_wizard.prospecting.compliance_title') }}</div>
                                <div class="mt-1">{{ t('marketing.campaign_wizard.prospecting.compliance_body') }}</div>
                                <div class="mt-2">
                                    {{ props.marketingSettings?.consent?.require_explicit
                                        ? t('marketing.campaign_wizard.prospecting.compliance_consent_required')
                                        : t('marketing.campaign_wizard.prospecting.compliance_consent_not_required') }}
                                </div>
                                <div class="mt-1">{{ t('marketing.campaign_wizard.prospecting.compliance_contactability') }}</div>
                            </div>

                            <div v-if="providerPreviewBusy" class="mt-3 rounded-sm border border-stone-200 bg-stone-50 px-3 py-3 text-xs text-stone-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300">
                                {{ t('marketing.campaign_wizard.prospecting.preview_loading') }}
                            </div>

                            <div
                                v-if="providerImportSummary"
                                class="mt-3 rounded-sm border border-green-200 bg-green-50 px-3 py-3 dark:border-green-500/20 dark:bg-green-500/10"
                            >
                                <div class="text-xs font-semibold text-green-800 dark:text-green-200">
                                    {{ t('marketing.campaign_wizard.prospecting.import_summary_title') }}
                                </div>
                                <p class="mt-1 text-xs text-green-700 dark:text-green-300">
                                    {{ t('marketing.campaign_wizard.prospecting.import_summary_hint', { batches: providerImportSummary.batches }) }}
                                </p>
                                <div class="mt-3 grid grid-cols-2 gap-2 xl:grid-cols-5">
                                    <div class="rounded-sm border border-white/60 bg-white/70 px-3 py-3 text-xs text-green-900 dark:border-green-500/20 dark:bg-neutral-900/40 dark:text-green-100">
                                        <div class="text-green-700 dark:text-green-300">{{ t('marketing.campaign_wizard.prospecting.import_summary.imported') }}</div>
                                        <div class="mt-1 text-lg font-semibold">{{ providerImportSummary.imported }}</div>
                                    </div>
                                    <div class="rounded-sm border border-white/60 bg-white/70 px-3 py-3 text-xs text-green-900 dark:border-green-500/20 dark:bg-neutral-900/40 dark:text-green-100">
                                        <div class="text-green-700 dark:text-green-300">{{ t('marketing.campaign_wizard.prospecting.import_summary.analyzed') }}</div>
                                        <div class="mt-1 text-lg font-semibold">{{ providerImportSummary.analyzed }}</div>
                                    </div>
                                    <div class="rounded-sm border border-white/60 bg-white/70 px-3 py-3 text-xs text-green-900 dark:border-green-500/20 dark:bg-neutral-900/40 dark:text-green-100">
                                        <div class="text-green-700 dark:text-green-300">{{ t('marketing.campaign_wizard.prospecting.import_summary.duplicates') }}</div>
                                        <div class="mt-1 text-lg font-semibold">{{ providerImportSummary.duplicates }}</div>
                                    </div>
                                    <div class="rounded-sm border border-white/60 bg-white/70 px-3 py-3 text-xs text-green-900 dark:border-green-500/20 dark:bg-neutral-900/40 dark:text-green-100">
                                        <div class="text-green-700 dark:text-green-300">{{ t('marketing.campaign_wizard.prospecting.import_summary.blocked') }}</div>
                                        <div class="mt-1 text-lg font-semibold">{{ providerImportSummary.blocked }}</div>
                                    </div>
                                    <div class="rounded-sm border border-white/60 bg-white/70 px-3 py-3 text-xs text-green-900 dark:border-green-500/20 dark:bg-neutral-900/40 dark:text-green-100">
                                        <div class="text-green-700 dark:text-green-300">{{ t('marketing.campaign_wizard.prospecting.import_summary.accepted') }}</div>
                                        <div class="mt-1 text-lg font-semibold">{{ providerImportSummary.accepted }}</div>
                                    </div>
                                </div>
                            </div>

                            <div v-if="!providerPreviewBusy && !providerImportSummary && providerPreviewRows.length === 0" class="mt-3 rounded-sm border border-dashed border-stone-300 bg-stone-50 px-3 py-4 text-xs text-stone-500 dark:border-neutral-600 dark:bg-neutral-800 dark:text-neutral-400">
                                {{ t('marketing.campaign_wizard.prospecting.preview_empty') }}
                            </div>

                            <div v-else class="mt-3 overflow-x-auto">
                                <table class="min-w-full divide-y divide-stone-200 text-left text-xs dark:divide-neutral-700">
                                    <thead class="bg-stone-50 text-stone-500 dark:bg-neutral-800 dark:text-neutral-400">
                                        <tr>
                                            <th class="px-3 py-2 font-semibold"></th>
                                            <th class="px-3 py-2 font-semibold">{{ t('marketing.campaign_wizard.prospecting.preview_columns.company') }}</th>
                                            <th class="px-3 py-2 font-semibold">{{ t('marketing.campaign_wizard.prospecting.preview_columns.contact') }}</th>
                                            <th class="px-3 py-2 font-semibold">{{ t('marketing.campaign_wizard.prospecting.preview_columns.reach') }}</th>
                                            <th class="px-3 py-2 font-semibold">{{ t('marketing.campaign_wizard.prospecting.preview_columns.location') }}</th>
                                            <th class="px-3 py-2 font-semibold">{{ t('marketing.campaign_wizard.prospecting.preview_columns.origin') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-stone-100 bg-white text-stone-700 dark:divide-neutral-800 dark:bg-neutral-900 dark:text-neutral-200">
                                        <tr
                                            v-for="row in providerPreviewRows"
                                            :key="row.preview_ref"
                                            class="align-top"
                                        >
                                            <td class="px-3 py-3">
                                                <input
                                                    :checked="selectedProviderPreviewRefs.includes(row.preview_ref)"
                                                    type="checkbox"
                                                    class="rounded border-stone-300 text-green-600 focus:ring-green-600"
                                                    :disabled="row.already_imported"
                                                    @change="toggleProviderPreviewSelection(row.preview_ref, $event.target.checked)"
                                                >
                                            </td>
                                            <td class="px-3 py-3">
                                                <div class="font-semibold text-stone-800 dark:text-neutral-100">{{ row.company_name || '-' }}</div>
                                                <div class="mt-1 text-stone-500 dark:text-neutral-400">{{ row.industry || '-' }}</div>
                                                <div v-if="row.company_size" class="mt-1 text-stone-500 dark:text-neutral-400">{{ row.company_size }}</div>
                                            </td>
                                            <td class="px-3 py-3">
                                                <div class="font-medium text-stone-700 dark:text-neutral-200">{{ row.contact_name || '-' }}</div>
                                                <div class="mt-1 text-stone-500 dark:text-neutral-400">{{ row.first_name || row.last_name ? `${row.first_name || ''} ${row.last_name || ''}`.trim() : '-' }}</div>
                                            </td>
                                            <td class="px-3 py-3">
                                                <div>{{ row.email || '-' }}</div>
                                                <div class="mt-1 text-stone-500 dark:text-neutral-400">{{ row.phone || '-' }}</div>
                                                <a
                                                    v-if="row.website"
                                                    :href="row.website"
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    class="mt-1 inline-flex text-green-700 hover:text-green-800 dark:text-green-300 dark:hover:text-green-200"
                                                >
                                                    {{ row.website_domain || row.website }}
                                                </a>
                                            </td>
                                            <td class="px-3 py-3">
                                                <div>{{ providerPreviewLocation(row) }}</div>
                                            </td>
                                            <td class="px-3 py-3">
                                                <div class="text-stone-500 dark:text-neutral-400">{{ row.provider_label || '-' }}</div>
                                                <div class="mt-1 text-stone-500 dark:text-neutral-400">{{ row.source_reference || '-' }}</div>
                                                <div
                                                    v-if="row.already_imported"
                                                    class="mt-2 rounded-sm border border-amber-200 bg-amber-50 px-2 py-2 text-[11px] text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200"
                                                >
                                                    <div class="font-semibold">{{ t('marketing.campaign_wizard.prospecting.preview_already_imported_badge') }}</div>
                                                    <div class="mt-1">{{ providerPreviewImportedSummary(row) || t('marketing.campaign_wizard.prospecting.preview_already_imported_short') }}</div>
                                                </div>
                                                <div v-if="row.missing_fields?.length" class="mt-2 flex flex-wrap gap-1">
                                                    <span
                                                        v-for="field in row.missing_fields"
                                                        :key="`${row.preview_ref}-${field}`"
                                                        class="rounded-sm border border-amber-200 bg-amber-50 px-2 py-1 text-[11px] text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200"
                                                    >
                                                        {{ providerPreviewMissingLabel(field) }}
                                                    </span>
                                                </div>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div v-if="prospectingBatchSummary" class="grid grid-cols-2 gap-2 xl:grid-cols-4">
                            <div class="rounded-sm border border-stone-200 bg-stone-50 px-3 py-3 text-xs dark:border-neutral-700 dark:bg-neutral-800">
                                <div class="text-stone-500 dark:text-neutral-400">{{ t('marketing.campaign_wizard.prospecting.summary.total_batches') }}</div>
                                <div class="mt-1 text-lg font-semibold text-stone-800 dark:text-neutral-100">{{ prospectingBatchSummary.total_batches || 0 }}</div>
                            </div>
                            <div class="rounded-sm border border-stone-200 bg-stone-50 px-3 py-3 text-xs dark:border-neutral-700 dark:bg-neutral-800">
                                <div class="text-stone-500 dark:text-neutral-400">{{ t('marketing.campaign_wizard.prospecting.summary.analyzed_batches') }}</div>
                                <div class="mt-1 text-lg font-semibold text-stone-800 dark:text-neutral-100">{{ prospectingBatchSummary.analyzed_batches || 0 }}</div>
                            </div>
                            <div class="rounded-sm border border-stone-200 bg-stone-50 px-3 py-3 text-xs dark:border-neutral-700 dark:bg-neutral-800">
                                <div class="text-stone-500 dark:text-neutral-400">{{ t('marketing.campaign_wizard.prospecting.summary.approved_batches') }}</div>
                                <div class="mt-1 text-lg font-semibold text-stone-800 dark:text-neutral-100">{{ prospectingBatchSummary.approved_batches || 0 }}</div>
                            </div>
                            <div class="rounded-sm border border-stone-200 bg-stone-50 px-3 py-3 text-xs dark:border-neutral-700 dark:bg-neutral-800">
                                <div class="text-stone-500 dark:text-neutral-400">{{ t('marketing.campaign_wizard.prospecting.summary.canceled_batches') }}</div>
                                <div class="mt-1 text-lg font-semibold text-stone-800 dark:text-neutral-100">{{ prospectingBatchSummary.canceled_batches || 0 }}</div>
                            </div>
                        </div>

                        <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div class="text-xs font-semibold text-stone-700 dark:text-neutral-200">
                                    {{ t('marketing.campaign_wizard.prospecting.review_title') }}
                                </div>
                                <div class="text-xs text-stone-500 dark:text-neutral-400">
                                    {{ t('marketing.campaign_wizard.prospecting.review_hint') }}
                                </div>
                            </div>

                            <div v-if="prospectingBatches.length === 0" class="mt-3 text-xs text-stone-500 dark:text-neutral-400">
                                {{ t('marketing.campaign_wizard.prospecting.empty_batches') }}
                            </div>

                            <div v-else class="mt-3 grid grid-cols-1 gap-2 xl:grid-cols-2">
                                <button
                                    v-for="batch in prospectingBatches"
                                    :key="`prospecting-batch-${batch.id}`"
                                    type="button"
                                    class="rounded-sm border px-3 py-3 text-left transition"
                                    :class="'border-stone-200 bg-white hover:border-green-300 dark:border-neutral-700 dark:bg-neutral-900'"
                                    @click="openProspectBatchWorkspace(batch.id)"
                                >
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <div class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                            {{ t('marketing.campaign_wizard.prospecting.batch_label', { number: batch.batch_number }) }}
                                        </div>
                                        <div class="text-xs text-stone-500 dark:text-neutral-400">
                                            {{ prospectBatchStatusLabel(batch.status) }}
                                        </div>
                                    </div>
                                    <div class="mt-2 grid grid-cols-2 gap-2 text-xs text-stone-600 dark:text-neutral-300">
                                        <div>{{ t('marketing.campaign_wizard.prospecting.kpis.accepted') }}: {{ batch.accepted_count }}</div>
                                        <div>{{ t('marketing.campaign_wizard.prospecting.kpis.duplicates') }}: {{ batch.duplicate_count }}</div>
                                        <div>{{ t('marketing.campaign_wizard.prospecting.kpis.blocked') }}: {{ batch.blocked_count }}</div>
                                        <div>{{ t('marketing.campaign_wizard.prospecting.kpis.review_required') }}: {{ batch.analysis_summary?.review_required_count ?? batch.accepted_count }}</div>
                                    </div>
                                    <div class="mt-2 text-xs text-stone-500 dark:text-neutral-400">
                                        {{ formatDateTime(batch.created_at) }}
                                    </div>
                                </button>
                            </div>
                        </div>

                        <div v-if="false && activeProspectBatch" class="space-y-3 rounded-sm border border-stone-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <h3 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                        {{ t('marketing.campaign_wizard.prospecting.batch_label', { number: activeProspectBatch.batch_number }) }}
                                    </h3>
                                    <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                        {{ t('marketing.campaign_wizard.prospecting.batch_meta', {
                                            source: activeProspectBatch.source_reference || activeProspectBatch.source_type,
                                            status: prospectBatchStatusLabel(activeProspectBatch.status),
                                        }) }}
                                    </p>
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    <SecondaryButton type="button" :disabled="prospectingBatchBusy" @click="loadProspectBatch(activeProspectBatch.id)">
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
                                    <div class="mt-1 text-lg font-semibold text-stone-800 dark:text-neutral-100">{{ activeProspectBatch.accepted_count }}</div>
                                </div>
                                <div class="rounded-sm border border-stone-200 bg-stone-50 px-3 py-3 text-xs dark:border-neutral-700 dark:bg-neutral-800">
                                    <div class="text-stone-500 dark:text-neutral-400">{{ t('marketing.campaign_wizard.prospecting.kpis.duplicates') }}</div>
                                    <div class="mt-1 text-lg font-semibold text-stone-800 dark:text-neutral-100">{{ activeProspectBatch.duplicate_count }}</div>
                                </div>
                                <div class="rounded-sm border border-stone-200 bg-stone-50 px-3 py-3 text-xs dark:border-neutral-700 dark:bg-neutral-800">
                                    <div class="text-stone-500 dark:text-neutral-400">{{ t('marketing.campaign_wizard.prospecting.kpis.blocked') }}</div>
                                    <div class="mt-1 text-lg font-semibold text-stone-800 dark:text-neutral-100">{{ activeProspectBatch.blocked_count }}</div>
                                </div>
                                <div class="rounded-sm border border-stone-200 bg-stone-50 px-3 py-3 text-xs dark:border-neutral-700 dark:bg-neutral-800">
                                    <div class="text-stone-500 dark:text-neutral-400">{{ t('marketing.campaign_wizard.prospecting.kpis.rejected') }}</div>
                                    <div class="mt-1 text-lg font-semibold text-stone-800 dark:text-neutral-100">{{ activeProspectBatch.rejected_count }}</div>
                                </div>
                                <div class="rounded-sm border border-stone-200 bg-stone-50 px-3 py-3 text-xs dark:border-neutral-700 dark:bg-neutral-800">
                                    <div class="text-stone-500 dark:text-neutral-400">{{ t('marketing.campaign_wizard.prospecting.kpis.review_required') }}</div>
                                    <div class="mt-1 text-lg font-semibold text-stone-800 dark:text-neutral-100">{{ activeProspectBatch.analysis_summary?.review_required_count ?? activeProspectBatch.accepted_count }}</div>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 gap-2 xl:grid-cols-3">
                                <div class="rounded-sm border border-stone-200 bg-stone-50 px-3 py-3 text-xs dark:border-neutral-700 dark:bg-neutral-800">
                                    <div class="font-semibold text-stone-700 dark:text-neutral-200">{{ t('marketing.campaign_wizard.prospecting.scoring_title') }}</div>
                                    <div class="mt-2 space-y-1 text-stone-600 dark:text-neutral-300">
                                        <div>{{ t('marketing.campaign_wizard.prospecting.kpis.high_priority') }}: {{ activeProspectBatch.analysis_summary?.high_priority_count ?? 0 }}</div>
                                        <div>{{ t('marketing.campaign_wizard.prospecting.score_ranges.high') }}: {{ activeProspectBatch.analysis_summary?.score_ranges?.high ?? 0 }}</div>
                                        <div>{{ t('marketing.campaign_wizard.prospecting.score_ranges.medium') }}: {{ activeProspectBatch.analysis_summary?.score_ranges?.medium ?? 0 }}</div>
                                        <div>{{ t('marketing.campaign_wizard.prospecting.score_ranges.low') }}: {{ activeProspectBatch.analysis_summary?.score_ranges?.low ?? 0 }}</div>
                                    </div>
                                </div>
                                <div class="rounded-sm border border-stone-200 bg-stone-50 px-3 py-3 text-xs dark:border-neutral-700 dark:bg-neutral-800">
                                    <div class="font-semibold text-stone-700 dark:text-neutral-200">{{ t('marketing.campaign_wizard.prospecting.match_summary_title') }}</div>
                                    <div class="mt-2 space-y-1 text-stone-600 dark:text-neutral-300">
                                        <div>{{ t('marketing.campaign_wizard.prospecting.match_statuses.none') }}: {{ activeProspectBatch.analysis_summary?.match_status_counts?.none ?? 0 }}</div>
                                        <div>{{ t('marketing.campaign_wizard.prospecting.match_statuses.matched_customer') }}: {{ activeProspectBatch.analysis_summary?.match_status_counts?.matched_customer ?? 0 }}</div>
                                        <div>{{ t('marketing.campaign_wizard.prospecting.match_statuses.matched_lead') }}: {{ activeProspectBatch.analysis_summary?.match_status_counts?.matched_lead ?? 0 }}</div>
                                        <div>{{ t('marketing.campaign_wizard.prospecting.match_statuses.matched_prospect') }}: {{ activeProspectBatch.analysis_summary?.match_status_counts?.matched_prospect ?? 0 }}</div>
                                    </div>
                                </div>
                                <div class="rounded-sm border border-stone-200 bg-stone-50 px-3 py-3 text-xs dark:border-neutral-700 dark:bg-neutral-800">
                                    <div class="font-semibold text-stone-700 dark:text-neutral-200">{{ t('marketing.campaign_wizard.prospecting.review_state_title') }}</div>
                                    <div class="mt-2 space-y-1 text-stone-600 dark:text-neutral-300">
                                        <div>{{ t('marketing.campaign_wizard.prospecting.fields.source_type') }}: {{ t(`marketing.campaign_wizard.prospecting.source_types.${activeProspectBatch.source_type}`) }}</div>
                                        <div>{{ t('marketing.campaign_wizard.prospecting.review_decision') }}: {{ activeProspectBatch.analysis_summary?.review_decision ? prospectBatchStatusLabel(activeProspectBatch.status) : t('marketing.campaign_wizard.prospecting.pending_review') }}</div>
                                        <div>{{ t('marketing.campaign_wizard.prospecting.reviewed_at') }}: {{ formatDateTime(activeProspectBatch.analysis_summary?.reviewed_at || activeProspectBatch.approved_at) }}</div>
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
                                    <SecondaryButton type="button" :disabled="prospectingBatchBusy" @click="loadProspectBatch(activeProspectBatch.id)">
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

                            <div class="rounded-sm border border-stone-200 dark:border-neutral-700">
                                <div v-if="prospectingBatchBusy" class="px-3 py-8 text-center text-xs text-stone-500 dark:text-neutral-400">
                                    {{ t('marketing.campaign_wizard.prospecting.loading_batch') }}
                                </div>
                                <div v-else-if="displayedProspectRows.length === 0" class="px-3 py-8 text-center text-xs text-stone-500 dark:text-neutral-400">
                                    {{ t('marketing.campaign_wizard.prospecting.empty_prospects') }}
                                </div>
                                <div v-else class="overflow-x-auto">
                                    <table class="min-w-full divide-y divide-stone-200 text-sm dark:divide-neutral-700">
                                        <thead class="bg-stone-50 text-left text-xs uppercase text-stone-500 dark:bg-neutral-800 dark:text-neutral-400">
                                            <tr>
                                                <th class="px-3 py-2 font-medium">
                                                    <span class="sr-only">{{ t('marketing.campaign_wizard.prospecting.table.select') }}</span>
                                                </th>
                                                <th class="px-3 py-2 font-medium">{{ t('marketing.campaign_wizard.prospecting.table.identity') }}</th>
                                                <th class="px-3 py-2 font-medium">{{ t('marketing.campaign_wizard.prospecting.table.contact') }}</th>
                                                <th class="px-3 py-2 font-medium">{{ t('marketing.campaign_wizard.prospecting.table.priority') }}</th>
                                                <th class="px-3 py-2 font-medium">{{ t('marketing.campaign_wizard.prospecting.table.match') }}</th>
                                                <th class="px-3 py-2 font-medium">{{ t('marketing.campaign_wizard.prospecting.table.status') }}</th>
                                                <th class="px-3 py-2 font-medium">{{ t('marketing.campaign_wizard.prospecting.table.recommended_action') }}</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-stone-200 dark:divide-neutral-700">
                                            <tr
                                                v-for="prospect in displayedProspectRows"
                                                :key="`prospecting-row-${prospect.id}`"
                                                class="align-top"
                                                :class="Number(activeProspectId || 0) === Number(prospect.id) ? 'bg-emerald-50/70 dark:bg-emerald-500/10' : ''"
                                            >
                                                <td class="px-3 py-3">
                                                    <input
                                                        type="checkbox"
                                                        class="rounded border-stone-300 text-green-600 focus:ring-green-600"
                                                        :checked="selectedProspectIds.includes(Number(prospect.id))"
                                                        @change="toggleProspectSelection(prospect.id, $event.target.checked)"
                                                    >
                                                </td>
                                                <td class="px-3 py-3 text-stone-700 dark:text-neutral-200">
                                                    <div class="font-medium">{{ prospectIdentity(prospect) }}</div>
                                                    <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                                        {{ prospect.company_name || prospect.website_domain || '-' }}
                                                    </div>
                                                    <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                                        {{ prospect.qualification_summary || '-' }}
                                                    </div>
                                                </td>
                                                <td class="px-3 py-3 text-xs text-stone-600 dark:text-neutral-300">
                                                    <div>{{ prospect.email || '-' }}</div>
                                                    <div class="mt-1">{{ prospect.phone || '-' }}</div>
                                                </td>
                                                <td class="px-3 py-3 text-xs text-stone-600 dark:text-neutral-300">
                                                    <div>{{ prospect.priority_score ?? '-' }}</div>
                                                    <div class="mt-1 text-stone-500 dark:text-neutral-400">
                                                        {{ t('marketing.campaign_wizard.prospecting.table.fit_intent', { fit: prospect.fit_score ?? '-', intent: prospect.intent_score ?? '-' }) }}
                                                    </div>
                                                </td>
                                                <td class="px-3 py-3 text-xs text-stone-600 dark:text-neutral-300">
                                                    <div>{{ prospectMatchStatusLabel(prospect.match_status) }}</div>
                                                    <div v-if="prospect.matched_customer" class="mt-1 text-stone-500 dark:text-neutral-400">
                                                        {{ t('marketing.campaign_wizard.prospecting.table.matched_customer', {
                                                            value: prospect.matched_customer.company_name || prospect.matched_customer.email || prospect.matched_customer.phone || `#${prospect.matched_customer.id}`,
                                                        }) }}
                                                    </div>
                                                    <div v-else-if="prospect.matched_lead" class="mt-1 text-stone-500 dark:text-neutral-400">
                                                        {{ t('marketing.campaign_wizard.prospecting.table.matched_lead', {
                                                            value: prospect.matched_lead.title || prospect.matched_lead.contact_name || prospect.matched_lead.contact_email || `#${prospect.matched_lead.id}`,
                                                        }) }}
                                                    </div>
                                                </td>
                                                <td class="px-3 py-3 text-xs text-stone-600 dark:text-neutral-300">
                                                    <div>{{ prospectStatusLabel(prospect.status) }}</div>
                                                    <div class="mt-1 text-stone-500 dark:text-neutral-400">{{ prospect.blocked_reason || '-' }}</div>
                                                </td>
                                                <td class="px-3 py-3 text-xs text-stone-600 dark:text-neutral-300">
                                                    <div>{{ prospectRecommendedAction(prospect) }}</div>
                                                    <button
                                                        type="button"
                                                        class="mt-2 text-xs font-medium text-emerald-700 underline underline-offset-2 dark:text-emerald-300"
                                                        @click="loadProspectDetail(prospect.id)"
                                                    >
                                                        {{ t('marketing.campaign_wizard.prospecting.actions.view_prospect') }}
                                                    </button>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <div v-if="activeProspectId || activeProspectBusy || activeProspectError || activeProspectDetail" class="rounded-sm border border-stone-200 bg-stone-50 p-4 text-sm dark:border-neutral-700 dark:bg-neutral-800">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <div class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                            {{ t('marketing.campaign_wizard.prospecting.detail_title') }}
                                        </div>
                                        <div v-if="activeProspectDetail" class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                            {{ prospectIdentity(activeProspectDetail) }} | {{ prospectStatusLabel(activeProspectDetail.status) }}
                                        </div>
                                    </div>
                                    <div v-if="activeProspectDetail" class="flex flex-wrap gap-2">
                                        <PrimaryButton type="button" :disabled="!canConvertActiveProspect" @click="convertActiveProspectToLead">
                                            {{ t('marketing.campaign_wizard.prospecting.actions.convert_to_lead') }}
                                        </PrimaryButton>
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
                                <template v-else-if="activeProspectDetail">
                                    <p v-if="activeProspectMessage" class="mt-3 text-xs text-emerald-700 dark:text-emerald-300">{{ activeProspectMessage }}</p>

                                    <div class="mt-3 grid grid-cols-1 gap-2 md:grid-cols-2 xl:grid-cols-4">
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
                                        <div class="rounded-sm border border-stone-200 bg-white px-3 py-3 text-xs dark:border-neutral-700 dark:bg-neutral-900">
                                            <div class="text-stone-500 dark:text-neutral-400">{{ t('marketing.campaign_wizard.prospecting.detail.sequence') }}</div>
                                            <div class="mt-1 text-stone-800 dark:text-neutral-100">{{ prospectSequenceSummary(activeProspectDetail) }}</div>
                                        </div>
                                        <div class="rounded-sm border border-stone-200 bg-white px-3 py-3 text-xs dark:border-neutral-700 dark:bg-neutral-900">
                                            <div class="text-stone-500 dark:text-neutral-400">{{ t('marketing.campaign_wizard.prospecting.detail.last_activity') }}</div>
                                            <div class="mt-1 text-stone-800 dark:text-neutral-100">{{ formatDateTime(activeProspectDetail.last_activity_at) }}</div>
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
                                        <div v-if="activeProspectDetail.converted_lead" class="rounded-sm border border-stone-200 bg-white px-3 py-3 text-xs md:col-span-2 xl:col-span-4 dark:border-neutral-700 dark:bg-neutral-900">
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

                            <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-stone-500 dark:text-neutral-400">
                                <span>{{ t('marketing.common.results_count', { count: activeProspectMeta.total }) }}</span>
                                <span>{{ t('marketing.common.page_of', { page: activeProspectMeta.currentPage, total: activeProspectMeta.lastPage }) }}</span>
                            </div>

                            <div class="flex flex-wrap justify-end gap-2">
                                <SecondaryButton
                                    type="button"
                                    :disabled="prospectingBatchBusy || activeProspectMeta.currentPage <= 1"
                                    @click="loadProspectBatch(activeProspectBatch.id, activeProspectMeta.currentPage - 1)"
                                >
                                    {{ t('marketing.common.previous') }}
                                </SecondaryButton>
                                <SecondaryButton
                                    type="button"
                                    :disabled="prospectingBatchBusy || activeProspectMeta.currentPage >= activeProspectMeta.lastPage"
                                    @click="loadProspectBatch(activeProspectBatch.id, activeProspectMeta.currentPage + 1)"
                                >
                                    {{ t('marketing.common.next') }}
                                </SecondaryButton>
                            </div>
                        </div>
                    </template>
                </template>

                <template v-else>
                    <div class="grid grid-cols-1 gap-3 xl:grid-cols-[minmax(0,1.18fr),minmax(320px,0.82fr)]">
                        <CampaignSectionCard
                            compact
                            :title="t('marketing.campaign_wizard.audience.source_title')"
                            :description="t('marketing.campaign_wizard.audience.source_description')"
                        >
                            <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                                <FloatingSelect
                                    v-model="form.audience_segment_id"
                                    :label="t('marketing.campaign_wizard.fields.segment')"
                                    :disabled="useSingleMailingList"
                                    :options="[
                                        { value: '', label: t('marketing.campaign_wizard.no_segment') },
                                        ...segments.map((segment) => ({
                                            value: segment.id,
                                            label: `${segment.name} (${segment.cached_count || 0})`,
                                        })),
                                    ]"
                                    option-value="value"
                                    option-label="label"
                                />
                                <FloatingSelect
                                    v-model="sourceLogic"
                                    :label="t('marketing.campaign_wizard.fields.source_logic')"
                                    :disabled="useSingleMailingList"
                                    :options="audienceSourceLogicOptions.map((mode) => ({ value: mode, label: sourceLogicLabel(mode) }))"
                                    option-value="value"
                                    option-label="label"
                                />
                            </div>

                            <div class="mt-3 rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800">
                                <label class="inline-flex items-center gap-2 text-xs text-stone-700 dark:text-neutral-200">
                                    <input
                                        v-model="useSingleMailingList"
                                        type="checkbox"
                                        class="rounded border-stone-300 text-green-600 focus:ring-green-600"
                                    >
                                    <span>{{ t('marketing.campaign_wizard.use_single_mailing_list') }}</span>
                                </label>
                                <p class="mt-2 text-xs leading-5 text-stone-500 dark:text-neutral-400">
                                    {{ t('marketing.campaign_wizard.audience.single_list_help') }}
                                </p>

                                <div v-if="useSingleMailingList" class="mt-3">
                                    <FloatingSelect
                                        v-model="singleMailingListId"
                                        :label="t('marketing.campaign_wizard.single_mailing_list')"
                                        :options="singleMailingListOptions"
                                        option-value="value"
                                        option-label="label"
                                    />
                                </div>
                            </div>

                            <div class="mt-3 rounded-sm border border-dashed border-stone-300 bg-stone-50 px-3 py-3 text-xs text-stone-600 dark:border-neutral-600 dark:bg-neutral-800 dark:text-neutral-300">
                                <div class="font-semibold text-stone-700 dark:text-neutral-200">
                                    {{ t('marketing.campaign_wizard.logic_summary') }}
                                </div>
                                <p class="mt-1">{{ logicSummary }}</p>
                                <p class="mt-2 text-stone-500 dark:text-neutral-400">
                                    {{ useSingleMailingList ? t('marketing.campaign_wizard.audience.source_logic_locked') : t('marketing.campaign_wizard.audience.source_logic_help') }}
                                </p>
                            </div>
                        </CampaignSectionCard>

                        <CampaignSectionCard
                            compact
                            :title="t('marketing.campaign_wizard.selected_customers_title')"
                            :description="t('marketing.campaign_wizard.audience.manual_customers_description')"
                        >
                            <template #actions>
                                <SecondaryButton type="button" :disabled="!canManage" @click="openAudienceCustomerPicker">
                                    {{ t('marketing.campaign_wizard.search_customers') }}
                                </SecondaryButton>
                            </template>

                            <div class="rounded-sm border border-stone-200 bg-stone-50 px-3 py-3 text-xs text-stone-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-400">
                                {{ t('marketing.campaign_wizard.selected_customers_count', { count: selectedAudienceCustomerIds.length }) }}
                            </div>

                            <div v-if="selectedAudienceCustomers.length === 0" class="mt-3 rounded-sm border border-dashed border-stone-300 bg-stone-50 px-3 py-4 text-xs text-stone-500 dark:border-neutral-600 dark:bg-neutral-800 dark:text-neutral-400">
                                {{ t('marketing.campaign_wizard.selected_customers_empty') }}
                            </div>
                            <div v-else class="mt-3 max-h-[220px] overflow-y-auto pr-1">
                                <div class="flex flex-wrap gap-2">
                                    <button
                                        v-for="customer in selectedAudienceCustomers"
                                        :key="`audience-selected-customer-${customer.id}`"
                                        type="button"
                                        class="inline-flex items-center gap-1 rounded-sm border border-green-200 bg-green-50 px-2 py-1 text-xs text-green-700 dark:border-green-500/20 dark:bg-green-500/10 dark:text-green-300"
                                        @click="removeAudienceCustomer(customer.id)"
                                    >
                                        <span>{{ audienceCustomerDisplayName(customer) }}</span>
                                        <span class="font-semibold">x</span>
                                    </button>
                                </div>
                            </div>
                        </CampaignSectionCard>
                    </div>

                    <div class="grid grid-cols-1 gap-3 xl:grid-cols-[minmax(0,1.18fr),minmax(320px,0.82fr)]">
                    <CampaignSectionCard
                        v-if="!useSingleMailingList"
                        compact
                        :title="t('marketing.campaign_wizard.mailing_lists_title')"
                        :description="t('marketing.campaign_wizard.audience.mailing_lists_description')"
                    >
                        <div v-if="!mailingLists.length" class="rounded-sm border border-dashed border-stone-300 bg-stone-50 px-3 py-4 text-xs text-stone-500 dark:border-neutral-600 dark:bg-neutral-800 dark:text-neutral-400">
                            {{ t('marketing.campaign_wizard.no_mailing_list') }}
                        </div>
                        <div v-else class="grid max-h-[340px] grid-cols-1 gap-2 overflow-y-auto pr-1 xl:grid-cols-2">
                            <div v-for="list in mailingLists" :key="`audience-list-${list.id}`" class="rounded-sm border border-stone-200 bg-stone-50 px-3 py-3 dark:border-neutral-700 dark:bg-neutral-800">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <div>
                                        <div class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                            {{ list.name }}
                                        </div>
                                        <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                            {{ t('marketing.campaign_wizard.audience.list_size', { count: list.customers_count || 0 }) }}
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-4 text-xs">
                                        <label class="inline-flex items-center gap-2 text-stone-600 dark:text-neutral-300">
                                            <input
                                                :checked="isMailingListIncluded(list.id)"
                                                type="checkbox"
                                                class="rounded border-stone-300 text-green-600 focus:ring-green-600"
                                                @change="toggleIncludeMailingList(list.id)"
                                            >
                                            <span>{{ t('marketing.campaign_wizard.include') }}</span>
                                        </label>
                                        <label class="inline-flex items-center gap-2 text-stone-600 dark:text-neutral-300">
                                            <input
                                                :checked="isMailingListExcluded(list.id)"
                                                type="checkbox"
                                                class="rounded border-stone-300 text-rose-600 focus:ring-rose-600"
                                                @change="toggleExcludeMailingList(list.id)"
                                            >
                                            <span>{{ t('marketing.campaign_wizard.exclude') }}</span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </CampaignSectionCard>

                    <CampaignSectionCard
                        compact
                        :title="t('marketing.campaign_wizard.audience.estimate_title')"
                        :description="t('marketing.campaign_wizard.audience.estimate_description')"
                    >
                        <template #actions>
                            <SecondaryButton type="button" :disabled="requestBusy || !canManage || !isEdit" @click="estimateAudience">
                                {{ t('marketing.campaign_wizard.actions.estimate_audience') }}
                            </SecondaryButton>
                        </template>

                        <div class="rounded-sm border border-dashed border-stone-300 bg-stone-50 px-3 py-3 text-xs text-stone-600 dark:border-neutral-600 dark:bg-neutral-800 dark:text-neutral-300">
                            <div class="font-semibold text-stone-700 dark:text-neutral-200">
                                {{ t('marketing.campaign_wizard.logic_summary') }}
                            </div>
                            <p class="mt-1">{{ logicSummary }}</p>
                        </div>

                        <div v-if="estimate" class="mt-4 space-y-3">
                            <div class="grid grid-cols-1 gap-3 md:grid-cols-[minmax(0,0.7fr),minmax(0,1.3fr)]">
                                <div class="rounded-sm border border-stone-200 bg-stone-50 px-3 py-4 text-xs dark:border-neutral-700 dark:bg-neutral-800">
                                    <div class="text-stone-500 dark:text-neutral-400">{{ t('marketing.campaign_wizard.audience.estimate_total') }}</div>
                                    <div class="mt-2 text-2xl font-semibold text-stone-800 dark:text-neutral-100">{{ audienceEstimateTotal }}</div>
                                </div>
                                <div class="rounded-sm border border-stone-200 bg-stone-50 px-3 py-4 text-xs dark:border-neutral-700 dark:bg-neutral-800">
                                    <div class="text-stone-500 dark:text-neutral-400">{{ t('marketing.campaign_wizard.audience.estimate_channels') }}</div>
                                    <div v-if="audienceEstimateChannels.length" class="mt-3 grid grid-cols-2 gap-2 xl:grid-cols-3">
                                        <div
                                            v-for="entry in audienceEstimateChannels"
                                            :key="`audience-estimate-channel-${entry.key}`"
                                            class="rounded-sm border border-white/80 bg-white px-3 py-3 dark:border-neutral-700 dark:bg-neutral-900"
                                        >
                                            <div class="text-stone-500 dark:text-neutral-400">{{ entry.label }}</div>
                                            <div class="mt-1 text-lg font-semibold text-stone-800 dark:text-neutral-100">{{ entry.count }}</div>
                                        </div>
                                    </div>
                                    <div v-else class="mt-3 text-stone-500 dark:text-neutral-400">
                                        {{ t('marketing.campaign_wizard.audience.estimate_channel_empty') }}
                                    </div>
                                </div>
                            </div>

                            <details class="rounded-sm border border-stone-200 bg-white dark:border-neutral-700 dark:bg-neutral-900">
                                <summary class="cursor-pointer px-3 py-3 text-xs font-medium text-stone-600 dark:text-neutral-300">
                                    {{ t('marketing.campaign_wizard.audience.estimate_details') }}
                                </summary>
                                <pre class="overflow-x-auto border-t border-stone-200 bg-stone-50 p-3 text-xs text-stone-700 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200">{{ JSON.stringify(estimate, null, 2) }}</pre>
                            </details>
                        </div>

                        <div v-else class="mt-4 rounded-sm border border-dashed border-stone-300 bg-stone-50 px-3 py-4 text-xs text-stone-500 dark:border-neutral-600 dark:bg-neutral-800 dark:text-neutral-400">
                            <div class="font-semibold text-stone-700 dark:text-neutral-200">
                                {{ t('marketing.campaign_wizard.audience.estimate_empty') }}
                            </div>
                            <p class="mt-1">
                                {{ isEdit ? t('marketing.campaign_wizard.audience.estimate_empty_help') : t('marketing.campaign_wizard.audience.estimate_requires_save') }}
                            </p>
                        </div>
                    </CampaignSectionCard>
                    </div>
                </template>
            </section>

            <Modal :show="audienceCustomerPickerOpen" max-width="4xl" @close="closeAudienceCustomerPicker">
                <div class="space-y-4 p-5">
                    <div>
                        <h5 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                            {{ t('marketing.campaign_wizard.customer_picker.title') }}
                        </h5>
                        <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                            {{ t('marketing.campaign_wizard.customer_picker.description') }}
                        </p>
                    </div>

                    <div class="grid grid-cols-1 gap-2 md:grid-cols-[1fr_auto]">
                        <FloatingInput
                            v-model="audienceCustomerSearch"
                            :label="t('marketing.campaign_wizard.customer_picker.search')"
                        />
                        <FloatingSelect
                            v-model="audienceCustomerPerPage"
                            :label="t('marketing.common.rows_per_page')"
                            :options="[10, 15, 25, 50].map((value) => ({ value, label: value }))"
                            option-value="value"
                            option-label="label"
                        />
                    </div>

                    <div v-if="audienceCustomerError" class="rounded-sm border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-700 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-300">
                        {{ audienceCustomerError }}
                    </div>

                    <div class="rounded-sm border border-stone-200 dark:border-neutral-700">
                        <div v-if="audienceCustomerLoading" class="px-3 py-6 text-center text-xs text-stone-500 dark:text-neutral-400">
                            {{ t('marketing.campaign_wizard.customer_picker.loading') }}
                        </div>
                        <div v-else-if="filteredAudienceCustomerRows.length === 0" class="px-3 py-6 text-center text-xs text-stone-500 dark:text-neutral-400">
                            {{ t('marketing.campaign_wizard.customer_picker.no_results') }}
                        </div>
                        <div v-else class="max-h-80 overflow-y-auto">
                            <table class="min-w-full divide-y divide-stone-200 text-sm dark:divide-neutral-700">
                                <thead class="bg-stone-50 text-left text-xs uppercase text-stone-500 dark:bg-neutral-800 dark:text-neutral-400">
                                    <tr>
                                        <th class="px-3 py-2 font-medium"></th>
                                        <th class="px-3 py-2 font-medium">{{ t('marketing.mailing_list_manager.customer') }}</th>
                                        <th class="px-3 py-2 font-medium">{{ t('marketing.mailing_list_manager.email') }}</th>
                                        <th class="px-3 py-2 font-medium">{{ t('marketing.mailing_list_manager.phone') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-stone-200 dark:divide-neutral-700">
                                    <tr
                                        v-for="customer in pagedAudienceCustomerRows"
                                        :key="`audience-customer-${customer.id}`"
                                        class="cursor-pointer hover:bg-stone-50 dark:hover:bg-neutral-800"
                                        @click="toggleAudienceCustomerSelection(customer)"
                                    >
                                        <td class="px-3 py-2">
                                            <input
                                                :checked="selectedAudienceCustomerIds.includes(Number(customer.id))"
                                                type="checkbox"
                                                class="rounded border-stone-300 text-green-600 focus:ring-green-600"
                                                @click.stop
                                                @change="toggleAudienceCustomerSelection(customer)"
                                            >
                                        </td>
                                        <td class="px-3 py-2 text-stone-700 dark:text-neutral-200">{{ audienceCustomerDisplayName(customer) }}</td>
                                        <td class="px-3 py-2 text-stone-600 dark:text-neutral-300">{{ customer.email || '-' }}</td>
                                        <td class="px-3 py-2 text-stone-600 dark:text-neutral-300">{{ customer.phone || '-' }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-stone-500 dark:text-neutral-400">
                        <span>{{ t('marketing.campaign_wizard.selected_customers_count', { count: selectedAudienceCustomerIds.length }) }}</span>
                        <span>{{ t('marketing.common.page_of', { page: audienceCustomerPage, total: audienceCustomerTotalPages }) }}</span>
                    </div>

                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div class="flex items-center gap-2">
                            <SecondaryButton type="button" @click="toggleAllVisibleAudienceCustomers">
                                {{ allVisibleAudienceCustomersSelected ? t('marketing.campaign_wizard.customer_picker.unselect_page') : t('marketing.campaign_wizard.customer_picker.select_page') }}
                            </SecondaryButton>
                        </div>
                        <div class="flex items-center gap-2">
                            <SecondaryButton type="button" :disabled="audienceCustomerPage <= 1" @click="audienceCustomerPage -= 1">
                                {{ t('marketing.common.previous') }}
                            </SecondaryButton>
                            <SecondaryButton type="button" :disabled="audienceCustomerPage >= audienceCustomerTotalPages" @click="audienceCustomerPage += 1">
                                {{ t('marketing.common.next') }}
                            </SecondaryButton>
                            <SecondaryButton type="button" @click="closeAudienceCustomerPicker">
                                {{ t('marketing.common.close') }}
                            </SecondaryButton>
                        </div>
                    </div>
                </div>
            </Modal>

            <section v-show="step === 3" class="space-y-3 rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <CampaignStepHeading
                    :eyebrow="t('marketing.campaign_wizard.foundation.current_step', { current: 3, total: totalWizardSteps })"
                    :title="stepMeta(3).title"
                    :description="stepMeta(3).description"
                    :recommendation="stepMeta(3).recommendation"
                    :recommendation-label="t('marketing.campaign_wizard.foundation.recommended_next')"
                />
                <div class="grid grid-cols-1 gap-3 xl:grid-cols-[minmax(0,1.18fr),minmax(320px,0.82fr)]">
                    <div class="space-y-3">
                        <div class="grid grid-cols-2 gap-2 xl:grid-cols-4">
                            <div
                                v-for="card in messageOverviewCards"
                                :key="`message-overview-${card.key}`"
                                class="rounded-sm border border-stone-200 bg-stone-50 px-3 py-3 text-xs dark:border-neutral-700 dark:bg-neutral-800"
                            >
                                <div class="text-stone-500 dark:text-neutral-400">{{ card.label }}</div>
                                <div class="mt-1 text-lg font-semibold text-stone-800 dark:text-neutral-100">{{ card.value }}</div>
                                <div class="mt-2 text-stone-500 dark:text-neutral-400">{{ card.helper }}</div>
                            </div>
                        </div>

                        <div class="rounded-sm border border-stone-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900">
                            <div class="flex flex-wrap gap-2 border-b border-stone-200 pb-3 dark:border-neutral-700">
                                <button
                                    v-for="channel in form.channels"
                                    :key="`message-channel-tab-${channel.channel}`"
                                    type="button"
                                    class="rounded-sm border px-3 py-2 text-left text-xs transition"
                                    :class="messageChannelStateClass(channel)"
                                    @click="setActiveMessageChannel(channel.channel)"
                                >
                                    <div class="font-semibold text-stone-800 dark:text-neutral-100">{{ channelLabel(channel.channel) }}</div>
                                    <div class="mt-1 text-stone-500 dark:text-neutral-400">
                                        {{ channel.is_enabled ? (isChannelConfigured(channel) ? t('marketing.campaign_wizard.foundation.statuses.complete') : t('marketing.campaign_wizard.foundation.statuses.attention')) : t('marketing.campaign_wizard.disabled') }}
                                    </div>
                                </button>
                            </div>

                            <div v-if="activeMessageChannel" class="mt-4 space-y-4">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <h3 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ channelLabel(activeMessageChannel.channel) }}</h3>
                                        <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                            {{ activeMessageChannel.is_enabled ? stepMeta(3).recommendation : t('marketing.campaign_wizard.disabled') }}
                                        </p>
                                    </div>
                                    <label class="inline-flex items-center gap-2 text-xs text-stone-600 dark:text-neutral-300">
                                        <input v-model="activeMessageChannel.is_enabled" type="checkbox" class="rounded border-stone-300 text-green-600 focus:ring-green-600">
                                        <span>{{ t('marketing.campaign_wizard.enabled') }}</span>
                                    </label>
                                </div>

                                <div v-if="!activeMessageChannel.is_enabled" class="rounded-sm border border-dashed border-stone-300 bg-stone-50 px-3 py-4 text-xs text-stone-500 dark:border-neutral-600 dark:bg-neutral-800 dark:text-neutral-400">
                                    {{ channelLabel(activeMessageChannel.channel) }} {{ t('marketing.campaign_wizard.disabled') }}
                                </div>

                                <template v-else>
                                    <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                                        <FloatingSelect
                                            v-model="activeMessageChannel.message_template_id"
                                            class="md:col-span-2"
                                            :label="t('marketing.campaign_wizard.fields.template')"
                                            :options="[
                                                { value: '', label: t('marketing.campaign_wizard.no_template') },
                                                ...templatesForChannel(activeMessageChannel.channel).map((template) => ({
                                                    value: template.id,
                                                    label: `${template.name} ${template.is_default ? `(${t('marketing.campaign_wizard.default')})` : ''}`,
                                                })),
                                            ]"
                                            option-value="value"
                                            option-label="label"
                                            @update:modelValue="applyTemplate(activeMessageChannel)"
                                        />
                                        <FloatingInput
                                            v-if="channelRequiresSubject(activeMessageChannel)"
                                            v-model="activeMessageChannel.subject_template"
                                            :label="t('marketing.campaign_wizard.fields.subject')"
                                        />
                                        <FloatingInput
                                            v-if="channelRequiresTitle(activeMessageChannel)"
                                            v-model="activeMessageChannel.title_template"
                                            :label="t('marketing.campaign_wizard.fields.title')"
                                        />
                                        <EmailBodyEditor
                                            v-model="activeMessageChannel.body_template"
                                            class="md:col-span-2"
                                            :label="t('marketing.campaign_wizard.fields.body_template')"
                                            :compact="activeMessageChannel.channel !== 'EMAIL'"
                                        />
                                    </div>

                                    <details class="rounded-sm border border-stone-200 bg-stone-50 dark:border-neutral-700 dark:bg-neutral-800">
                                        <summary class="cursor-pointer px-3 py-3 text-xs font-medium text-stone-600 dark:text-neutral-300">
                                            {{ t('marketing.campaign_wizard.ab_testing.title') }}
                                        </summary>
                                        <div class="border-t border-stone-200 p-3 dark:border-neutral-700">
                                            <div class="flex flex-wrap items-center justify-between gap-2">
                                                <div class="text-xs font-semibold text-stone-700 dark:text-neutral-200">{{ t('marketing.campaign_wizard.ab_testing.title') }}</div>
                                                <label class="inline-flex items-center gap-2 text-xs text-stone-600 dark:text-neutral-300">
                                                    <input v-model="activeMessageChannel.ab_testing.enabled" type="checkbox" class="rounded border-stone-300 text-green-600 focus:ring-green-600">
                                                    <span>{{ t('marketing.campaign_wizard.ab_testing.enable_for', { channel: channelLabel(activeMessageChannel.channel) }) }}</span>
                                                </label>
                                            </div>

                                            <div v-if="activeMessageChannel.ab_testing.enabled" class="mt-3 space-y-3">
                                                <FloatingInput
                                                    v-model="activeMessageChannel.ab_testing.split_a_percent"
                                                    type="number"
                                                    :label="t('marketing.campaign_wizard.ab_testing.split_percent')"
                                                />

                                                <div class="grid grid-cols-1 gap-3 lg:grid-cols-2">
                                                    <div class="rounded-sm border border-stone-200 bg-white p-3 dark:border-neutral-700 dark:bg-neutral-900">
                                                        <p class="mb-2 text-xs font-semibold text-stone-700 dark:text-neutral-200">{{ t('marketing.campaign_wizard.ab_testing.variant_a') }}</p>
                                                        <div class="space-y-2">
                                                            <FloatingInput v-if="channelRequiresSubject(activeMessageChannel)" v-model="activeMessageChannel.ab_testing.variant_a.subject_template" :label="t('marketing.campaign_wizard.ab_testing.subject_a')" />
                                                            <FloatingInput v-if="channelRequiresTitle(activeMessageChannel)" v-model="activeMessageChannel.ab_testing.variant_a.title_template" :label="t('marketing.campaign_wizard.ab_testing.title_a')" />
                                                            <EmailBodyEditor
                                                                v-model="activeMessageChannel.ab_testing.variant_a.body_template"
                                                                :label="t('marketing.campaign_wizard.ab_testing.body_a')"
                                                                compact
                                                            />
                                                        </div>
                                                    </div>
                                                    <div class="rounded-sm border border-stone-200 bg-white p-3 dark:border-neutral-700 dark:bg-neutral-900">
                                                        <p class="mb-2 text-xs font-semibold text-stone-700 dark:text-neutral-200">{{ t('marketing.campaign_wizard.ab_testing.variant_b') }}</p>
                                                        <div class="space-y-2">
                                                            <FloatingInput v-if="channelRequiresSubject(activeMessageChannel)" v-model="activeMessageChannel.ab_testing.variant_b.subject_template" :label="t('marketing.campaign_wizard.ab_testing.subject_b')" />
                                                            <FloatingInput v-if="channelRequiresTitle(activeMessageChannel)" v-model="activeMessageChannel.ab_testing.variant_b.title_template" :label="t('marketing.campaign_wizard.ab_testing.title_b')" />
                                                            <EmailBodyEditor
                                                                v-model="activeMessageChannel.ab_testing.variant_b.body_template"
                                                                :label="t('marketing.campaign_wizard.ab_testing.body_b')"
                                                                compact
                                                            />
                                                        </div>
                                                    </div>
                                                </div>
                                                <p class="text-xs text-stone-500 dark:text-neutral-400">
                                                    {{ t('marketing.campaign_wizard.ab_testing.fallback_hint') }}
                                                </p>
                                            </div>
                                        </div>
                                    </details>
                                </template>
                            </div>
                        </div>
                    </div>

                    <div class="space-y-3">
                        <div class="rounded-sm border border-stone-200 bg-stone-50 p-4 dark:border-neutral-700 dark:bg-neutral-800">
                            <div class="text-xs font-semibold text-stone-700 dark:text-neutral-200">
                                {{ activeMessageChannel ? channelLabel(activeMessageChannel.channel) : stepMeta(3).title }}
                            </div>
                            <div class="mt-3 space-y-2">
                                <div v-if="activeMessageChannel && !activeMessageChannel.is_enabled" class="rounded-sm border border-dashed border-stone-300 bg-white px-3 py-3 text-xs text-stone-500 dark:border-neutral-600 dark:bg-neutral-900 dark:text-neutral-400">
                                    {{ t('marketing.campaign_wizard.disabled') }}
                                </div>
                                <div
                                    v-for="item in activeMessageChannel ? messageChecklist(activeMessageChannel) : []"
                                    :key="`message-check-${item.key}`"
                                    class="flex items-center justify-between gap-2 rounded-sm border border-white/80 bg-white px-3 py-3 text-xs dark:border-neutral-700 dark:bg-neutral-900"
                                >
                                    <span class="text-stone-600 dark:text-neutral-300">{{ item.label }}</span>
                                    <span class="font-medium" :class="item.ready ? 'text-emerald-700 dark:text-emerald-300' : 'text-amber-700 dark:text-amber-300'">
                                        {{ item.ready ? t('marketing.campaign_wizard.foundation.statuses.complete') : t('marketing.campaign_wizard.foundation.statuses.attention') }}
                                    </span>
                                </div>
                            </div>
                        </div>

                        <div class="rounded-sm border border-stone-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900">
                            <div class="text-xs font-semibold text-stone-700 dark:text-neutral-200">{{ t('marketing.campaign_wizard.actions.live_preview') }}</div>
                            <p class="mt-2 text-xs text-stone-500 dark:text-neutral-400">
                                {{ isEdit ? stepMeta(3).recommendation : saveDraftLabel }}
                            </p>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <SecondaryButton type="button" :disabled="requestBusy || !canManage || !isEdit" @click="previewMessages">{{ t('marketing.campaign_wizard.actions.live_preview') }}</SecondaryButton>
                                <SecondaryButton type="button" :disabled="requestBusy || (!canManage && !canSend) || !isEdit" @click="testSend">{{ t('marketing.campaign_wizard.actions.test_send') }}</SecondaryButton>
                            </div>
                            <p v-if="requestError" class="mt-3 text-xs text-rose-600">{{ requestError }}</p>
                        </div>

                        <div v-if="previews.length" class="rounded-sm border border-stone-200 bg-stone-50 p-4 dark:border-neutral-700 dark:bg-neutral-800">
                            <div class="text-xs font-semibold text-stone-700 dark:text-neutral-200">{{ t('marketing.campaign_wizard.actions.live_preview') }}</div>
                            <pre class="mt-3 overflow-x-auto rounded-sm border border-stone-200 bg-white p-3 text-xs text-stone-700 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200">{{ JSON.stringify(previews, null, 2) }}</pre>
                        </div>

                        <div v-if="testResults.length" class="rounded-sm border border-stone-200 bg-stone-50 p-4 dark:border-neutral-700 dark:bg-neutral-800">
                            <div class="text-xs font-semibold text-stone-700 dark:text-neutral-200">{{ t('marketing.campaign_wizard.actions.test_send') }}</div>
                            <pre class="mt-3 overflow-x-auto rounded-sm border border-stone-200 bg-white p-3 text-xs text-stone-700 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200">{{ JSON.stringify(testResults, null, 2) }}</pre>
                        </div>
                    </div>
                </div>
            </section>

            <section v-show="step === 4" class="space-y-3 rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <CampaignStepHeading
                    :eyebrow="t('marketing.campaign_wizard.foundation.current_step', { current: 4, total: totalWizardSteps })"
                    :title="stepMeta(4).title"
                    :description="stepMeta(4).description"
                    :recommendation="stepMeta(4).recommendation"
                    :recommendation-label="t('marketing.campaign_wizard.foundation.recommended_next')"
                />
                <div class="grid gap-4 xl:grid-cols-[minmax(0,1.35fr)_minmax(320px,0.85fr)]">
                    <div class="space-y-4">
                        <div class="rounded-lg border p-5" :class="reviewReadinessPanelClass">
                            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                                <div>
                                    <p class="text-[11px] font-semibold uppercase tracking-[0.18em]" :class="reviewReadinessState === 'blocked' ? 'text-rose-700 dark:text-rose-200' : (reviewReadinessState === 'attention' ? 'text-amber-700 dark:text-amber-200' : 'text-emerald-700 dark:text-emerald-200')">
                                        Pre-flight
                                    </p>
                                    <div class="mt-2 flex flex-wrap items-end gap-3">
                                        <div class="text-4xl font-semibold text-stone-900 dark:text-neutral-100">{{ reviewReadinessScore }}%</div>
                                        <span
                                            class="inline-flex rounded-full border px-3 py-1 text-xs font-semibold"
                                            :class="reviewReadinessState === 'blocked' ? 'border-rose-200 bg-white/80 text-rose-700 dark:border-rose-500/30 dark:bg-neutral-900/70 dark:text-rose-200' : (reviewReadinessState === 'attention' ? 'border-amber-200 bg-white/80 text-amber-700 dark:border-amber-500/30 dark:bg-neutral-900/70 dark:text-amber-200' : 'border-emerald-200 bg-white/80 text-emerald-700 dark:border-emerald-500/30 dark:bg-neutral-900/70 dark:text-emerald-200')"
                                        >
                                            {{ reviewReadinessLabel }}
                                        </span>
                                    </div>
                                    <p class="mt-2 max-w-2xl text-sm text-stone-600 dark:text-neutral-300">{{ reviewReadinessDescription }}</p>
                                </div>

                                <div class="grid grid-cols-2 gap-2 sm:min-w-[220px]">
                                    <div class="rounded-sm border border-white/80 bg-white/80 px-3 py-3 text-xs dark:border-neutral-700 dark:bg-neutral-900/70">
                                        <div class="text-stone-500 dark:text-neutral-400">Blockers</div>
                                        <div class="mt-1 text-lg font-semibold text-stone-900 dark:text-neutral-100">{{ reviewBlockers.length }}</div>
                                    </div>
                                    <div class="rounded-sm border border-white/80 bg-white/80 px-3 py-3 text-xs dark:border-neutral-700 dark:bg-neutral-900/70">
                                        <div class="text-stone-500 dark:text-neutral-400">Warnings</div>
                                        <div class="mt-1 text-lg font-semibold text-stone-900 dark:text-neutral-100">{{ reviewWarnings.length }}</div>
                                    </div>
                                    <div class="rounded-sm border border-white/80 bg-white/80 px-3 py-3 text-xs dark:border-neutral-700 dark:bg-neutral-900/70">
                                        <div class="text-stone-500 dark:text-neutral-400">Channels</div>
                                        <div class="mt-1 text-lg font-semibold text-stone-900 dark:text-neutral-100">{{ enabledChannelsCount }}</div>
                                    </div>
                                    <div class="rounded-sm border border-white/80 bg-white/80 px-3 py-3 text-xs dark:border-neutral-700 dark:bg-neutral-900/70">
                                        <div class="text-stone-500 dark:text-neutral-400">Audience</div>
                                        <div class="mt-1 text-lg font-semibold text-stone-900 dark:text-neutral-100">{{ hasAudienceEstimate ? formatNumber(audienceEstimateTotal) : '-' }}</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                            <div
                                v-for="card in reviewOverviewCards"
                                :key="`review-overview-${card.key}`"
                                class="rounded-sm border border-stone-200 bg-stone-50 p-4 dark:border-neutral-700 dark:bg-neutral-800"
                            >
                                <div class="text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">{{ card.label }}</div>
                                <div class="mt-2 text-sm font-semibold text-stone-900 dark:text-neutral-100">{{ card.value }}</div>
                                <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">{{ card.helper }}</p>
                            </div>
                        </div>

                        <div class="grid gap-3 lg:grid-cols-3">
                            <div
                                v-for="section in reviewSections"
                                :key="`review-section-${section.key}`"
                                class="rounded-sm border border-stone-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900"
                            >
                                <div class="flex items-center justify-between gap-3">
                                    <div class="text-sm font-semibold text-stone-900 dark:text-neutral-100">{{ section.title }}</div>
                                    <span
                                        class="inline-flex rounded-full border px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide"
                                        :class="section.ready ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/15 dark:text-emerald-200' : 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/15 dark:text-amber-200'"
                                    >
                                        {{ section.ready ? t('marketing.campaign_wizard.foundation.statuses.complete') : t('marketing.campaign_wizard.foundation.statuses.attention') }}
                                    </span>
                                </div>
                                <p class="mt-3 text-sm font-medium text-stone-700 dark:text-neutral-200">{{ section.summary }}</p>
                                <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">{{ section.helper }}</p>
                                <div class="mt-3">
                                    <SecondaryButton type="button" @click="setStep(section.step)">Review step</SecondaryButton>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="space-y-4">
                        <div class="rounded-sm border border-stone-200 bg-stone-50 p-4 dark:border-neutral-700 dark:bg-neutral-800">
                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <div class="text-sm font-semibold text-stone-900 dark:text-neutral-100">Blockers</div>
                                    <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">What must be fixed before launch.</p>
                                </div>
                                <span class="inline-flex rounded-full border border-rose-200 bg-white px-2 py-0.5 text-[11px] font-semibold text-rose-700 dark:border-rose-500/30 dark:bg-neutral-900 dark:text-rose-200">{{ reviewBlockers.length }}</span>
                            </div>

                            <div v-if="reviewBlockers.length" class="mt-3 space-y-2">
                                <div
                                    v-for="item in reviewBlockers"
                                    :key="`review-blocker-${item.key}`"
                                    class="rounded-sm border border-rose-200 bg-white p-3 dark:border-rose-500/30 dark:bg-neutral-900"
                                >
                                    <div class="flex flex-wrap items-start justify-between gap-2">
                                        <div>
                                            <div class="text-sm font-semibold text-stone-900 dark:text-neutral-100">{{ item.title }}</div>
                                            <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">{{ item.description }}</p>
                                        </div>
                                        <SecondaryButton type="button" @click="setStep(item.step)">{{ stepMeta(item.step).title }}</SecondaryButton>
                                    </div>
                                </div>
                            </div>
                            <div v-else class="mt-3 rounded-sm border border-emerald-200 bg-white px-3 py-3 text-xs text-emerald-700 dark:border-emerald-500/30 dark:bg-neutral-900 dark:text-emerald-200">
                                All blocking checks are clear.
                            </div>
                        </div>

                        <div class="rounded-sm border border-stone-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900">
                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <div class="text-sm font-semibold text-stone-900 dark:text-neutral-100">Recommended checks</div>
                                    <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">Helpful checks before the send decision.</p>
                                </div>
                                <span class="inline-flex rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 text-[11px] font-semibold text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/15 dark:text-amber-200">{{ reviewWarnings.length }}</span>
                            </div>

                            <div v-if="reviewWarnings.length" class="mt-3 space-y-2">
                                <div
                                    v-for="item in reviewWarnings"
                                    :key="`review-warning-${item.key}`"
                                    class="rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800"
                                >
                                    <div class="flex flex-wrap items-start justify-between gap-2">
                                        <div>
                                            <div class="text-sm font-semibold text-stone-900 dark:text-neutral-100">{{ item.title }}</div>
                                            <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">{{ item.description }}</p>
                                        </div>
                                        <SecondaryButton type="button" @click="setStep(item.step)">{{ stepMeta(item.step).title }}</SecondaryButton>
                                    </div>
                                </div>
                            </div>
                            <div v-else class="mt-3 rounded-sm border border-emerald-200 bg-emerald-50 px-3 py-3 text-xs text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200">
                                No extra recommendations are pending.
                            </div>
                        </div>

                        <div class="rounded-sm border border-stone-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900">
                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <div class="text-sm font-semibold text-stone-900 dark:text-neutral-100">Launch controls</div>
                                    <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">Final distribution settings before send.</p>
                                </div>
                                <span class="inline-flex rounded-full border px-2 py-0.5 text-[11px] font-semibold" :class="statusBadgeClass(props.campaign?.status || 'draft')">
                                    {{ campaignStatusLabel(props.campaign?.status || 'draft') }}
                                </span>
                            </div>

                            <details class="mt-3 rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800" :open="form.settings.holdout.enabled">
                                <summary class="cursor-pointer text-xs font-semibold text-stone-700 dark:text-neutral-200">{{ t('marketing.campaign_wizard.holdout.title') }}</summary>
                                <div class="mt-3 space-y-3">
                                    <label class="inline-flex items-center gap-2 text-xs text-stone-600 dark:text-neutral-300">
                                        <input v-model="form.settings.holdout.enabled" type="checkbox" class="rounded border-stone-300 text-green-600 focus:ring-green-600">
                                        <span>{{ t('marketing.campaign_wizard.holdout.enable') }}</span>
                                    </label>
                                    <FloatingInput
                                        v-model="form.settings.holdout.percent"
                                        type="number"
                                        :label="t('marketing.campaign_wizard.holdout.percent')"
                                    />
                                </div>
                            </details>

                            <details class="mt-3 rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800" :open="form.settings.channel_fallback.enabled">
                                <summary class="cursor-pointer text-xs font-semibold text-stone-700 dark:text-neutral-200">{{ t('marketing.campaign_wizard.fallback.title') }}</summary>
                                <div class="mt-3 space-y-3">
                                    <label class="inline-flex items-center gap-2 text-xs text-stone-600 dark:text-neutral-300">
                                        <input v-model="form.settings.channel_fallback.enabled" type="checkbox" class="rounded border-stone-300 text-green-600 focus:ring-green-600">
                                        <span>{{ t('marketing.campaign_wizard.fallback.enable') }}</span>
                                    </label>
                                    <FloatingInput
                                        v-model="form.settings.channel_fallback.max_depth"
                                        type="number"
                                        :label="t('marketing.campaign_wizard.fallback.max_depth')"
                                    />
                                    <div class="space-y-2">
                                        <div
                                            v-for="source in channels"
                                            :key="`fallback-${source}`"
                                            class="rounded-sm border border-stone-200 bg-white p-2 dark:border-neutral-700 dark:bg-neutral-900"
                                        >
                                            <p class="text-xs font-semibold text-stone-700 dark:text-neutral-200">{{ t('marketing.campaign_wizard.fallback.targets', { source }) }}</p>
                                            <div class="mt-1 flex flex-wrap gap-2">
                                                <label
                                                    v-for="target in channels.filter((candidate) => candidate !== source)"
                                                    :key="`fallback-${source}-${target}`"
                                                    class="inline-flex items-center gap-2 rounded-sm border border-stone-200 bg-stone-50 px-2 py-1 text-xs text-stone-700 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200"
                                                >
                                                    <input
                                                        :checked="fallbackTargets(source).includes(target)"
                                                        type="checkbox"
                                                        class="rounded border-stone-300 text-green-600 focus:ring-green-600"
                                                        @change="toggleFallbackTarget(source, target)"
                                                    >
                                                    <span>{{ target }}</span>
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </details>

                            <div class="mt-4 flex flex-wrap gap-2">
                                <PrimaryButton type="button" :disabled="requestBusy || !canSend || !isEdit || reviewBlockers.length > 0" @click="sendNow">{{ t('marketing.campaign_wizard.actions.send_now') }}</PrimaryButton>
                                <Link v-if="isEdit" :href="route('campaigns.show', campaignId)">
                                    <SecondaryButton type="button">{{ t('marketing.campaign_wizard.actions.open_results') }}</SecondaryButton>
                                </Link>
                            </div>
                            <p v-if="requestError" class="mt-3 text-xs text-rose-600">{{ requestError }}</p>
                            <p v-if="runMessage" class="mt-3 text-xs text-emerald-700 dark:text-emerald-300">{{ runMessage }}</p>
                        </div>
                    </div>
                </div>
            </section>

            <section v-show="step === 5" class="space-y-4 rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <CampaignStepHeading
                    :eyebrow="t('marketing.campaign_wizard.foundation.current_step', { current: 5, total: totalWizardSteps })"
                    :title="stepMeta(5).title"
                    :description="stepMeta(5).description"
                    :recommendation="stepMeta(5).recommendation"
                    :recommendation-label="t('marketing.campaign_wizard.foundation.recommended_next')"
                />
                <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-4">
                    <div
                        v-for="card in resultsOverviewCards"
                        :key="`results-card-${card.key}`"
                        class="rounded-sm border border-stone-200 bg-stone-50 p-4 dark:border-neutral-700 dark:bg-neutral-800"
                    >
                        <div class="text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">{{ card.label }}</div>
                        <div class="mt-2 text-sm font-semibold text-stone-900 dark:text-neutral-100">{{ card.value }}</div>
                        <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">{{ card.helper }}</p>
                    </div>
                </div>

                <div v-if="!latestCampaignRun" class="rounded-lg border border-dashed border-stone-300 bg-stone-50 p-6 dark:border-neutral-600 dark:bg-neutral-800">
                    <div class="max-w-2xl">
                        <div class="text-base font-semibold text-stone-900 dark:text-neutral-100">No run yet</div>
                        <p class="mt-2 text-sm text-stone-600 dark:text-neutral-300">{{ resultsEmptyStateMessage }}</p>
                        <p class="mt-2 text-xs text-stone-500 dark:text-neutral-400">{{ t('marketing.campaign_wizard.results_hint') }}</p>
                    </div>
                    <div class="mt-4 flex flex-wrap gap-2">
                        <SecondaryButton type="button" @click="setStep(4)">Back to review</SecondaryButton>
                        <Link v-if="isEdit" :href="route('campaigns.show', campaignId)">
                            <PrimaryButton>{{ t('marketing.campaign_wizard.actions.open_results') }}</PrimaryButton>
                        </Link>
                    </div>
                </div>

                <div v-else class="grid gap-4 xl:grid-cols-[minmax(0,1.15fr)_minmax(280px,0.85fr)]">
                    <div class="rounded-sm border border-stone-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <div class="text-sm font-semibold text-stone-900 dark:text-neutral-100">Latest run</div>
                                <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">High-level delivery progress for the newest execution.</p>
                            </div>
                            <span class="inline-flex rounded-full border px-2 py-0.5 text-[11px] font-semibold" :class="statusBadgeClass(latestCampaignRun.status)">
                                {{ runStatusLabel(latestCampaignRun.status) }}
                            </span>
                        </div>

                        <div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-2">
                            <div
                                v-for="item in latestRunHighlights"
                                :key="`latest-run-highlight-${item.key}`"
                                class="rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800"
                            >
                                <div class="text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">{{ item.label }}</div>
                                <div class="mt-2 text-sm font-semibold text-stone-900 dark:text-neutral-100">{{ item.value }}</div>
                            </div>
                        </div>

                        <div class="mt-4 flex flex-wrap gap-2">
                            <Link v-if="isEdit" :href="route('campaigns.show', campaignId)">
                                <PrimaryButton>{{ t('marketing.campaign_wizard.actions.open_results') }}</PrimaryButton>
                            </Link>
                            <SecondaryButton type="button" @click="setStep(4)">Back to review</SecondaryButton>
                        </div>
                    </div>

                    <div class="rounded-sm border border-stone-200 bg-stone-50 p-4 dark:border-neutral-700 dark:bg-neutral-800">
                        <div class="text-sm font-semibold text-stone-900 dark:text-neutral-100">Recent runs</div>
                        <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">Lifecycle continuity from the wizard before opening the full analytics page.</p>

                        <div class="mt-3 space-y-2">
                            <div
                                v-for="run in campaignRuns"
                                :key="`recent-run-${run.id}`"
                                class="rounded-sm border border-stone-200 bg-white p-3 dark:border-neutral-700 dark:bg-neutral-900"
                            >
                                <div class="flex items-center justify-between gap-3">
                                    <div class="text-sm font-semibold text-stone-900 dark:text-neutral-100">Run #{{ run.id }}</div>
                                    <span class="inline-flex rounded-full border px-2 py-0.5 text-[11px] font-semibold" :class="statusBadgeClass(run.status)">
                                        {{ runStatusLabel(run.status) }}
                                    </span>
                                </div>
                                <div class="mt-2 text-xs text-stone-500 dark:text-neutral-400">
                                    {{ humanizeValue(run.trigger_type || 'manual') }} | {{ formatDateTime(run.started_at || run.created_at) }}
                                </div>
                                <div class="mt-2 text-xs text-stone-600 dark:text-neutral-300">
                                    {{ formatNumber(run.summary?.targeted || run.audience_snapshot?.eligible || 0) }} targeted
                                    | {{ formatNumber(run.summary?.delivered || 0) }} delivered
                                    | {{ formatNumber(run.summary?.failed || 0) }} failed
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <CampaignStickyActionBar
                :current-step-label="t('marketing.campaign_wizard.foundation.current_step', { current: step, total: totalWizardSteps })"
                :current-step-title="currentStepMeta.title"
                :guidance="stickyGuidance"
                :show-previous="step > 1"
                :previous-disabled="step <= 1"
                :save-disabled="form.processing || !canManage || step === 5"
                :primary-disabled="form.processing || (!canManage && step !== 5) || (step === 4 && reviewBlockers.length > 0) || (step === 5 && !isEdit && !canManage)"
                :previous-label="t('marketing.common.previous')"
                :save-label="step === 5 ? '' : saveDraftLabel"
                :primary-label="primaryActionLabel"
                @previous="goToPreviousStep"
                @save="saveStepProgress"
                @primary="runPrimaryWizardAction"
            />
        </div>
    </AuthenticatedLayout>
</template>
