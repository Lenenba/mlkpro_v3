<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import FloatingTextarea from '@/Components/FloatingTextarea.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import EmailBodyEditor from '@/Pages/Campaigns/Components/EmailBodyEditor.vue';
import OfferSelector from '@/Pages/Campaigns/Components/OfferSelector.vue';

const props = defineProps({
    campaign: { type: Object, default: null },
    selectedOffers: { type: Array, default: () => [] },
    segments: { type: Array, default: () => [] },
    mailingLists: { type: Array, default: () => [] },
    vipTiers: { type: Array, default: () => [] },
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

const humanizeValue = (value) => String(value || '')
    .replaceAll('_', ' ')
    .toLowerCase()
    .replace(/\b\w/g, (char) => char.toUpperCase());

const translateWithFallback = (key, fallback) => {
    const translated = t(key);
    return translated === key ? fallback : translated;
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

const channelLabel = (value) => {
    const normalized = String(value || '').toLowerCase();
    if (!normalized) return '-';
    return translateWithFallback(`marketing.channels.${normalized}`, humanizeValue(value));
};

const channels = (props.enums?.channels || ['EMAIL', 'SMS', 'IN_APP']).map((v) => String(v).toUpperCase());
const types = props.enums?.types || ['PROMOTION'];
const directions = props.enums?.directions || ['customer_marketing', 'prospecting_outbound', 'lead_generation_inbound'];
const offerModes = props.enums?.offer_modes || ['PRODUCTS', 'SERVICES', 'MIXED'];
const languageModes = props.enums?.language_modes || ['PREFERRED', 'FR', 'EN', 'BOTH'];
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
const estimate = ref(null);
const previews = ref([]);
const testResults = ref([]);
const runMessage = ref('');
const isProspectingMode = computed(() => Boolean(form.prospecting_enabled) && String(form.campaign_direction || '') !== 'customer_marketing');
const wizardStepStorageKey = 'campaign-wizard-next-step';

const prospectingImportMode = ref('manual');
const prospectingSourceType = ref('manual');
const prospectingSourceReference = ref('');
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
]));

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

const manualProspectLineCount = computed(() => String(prospectingManualInput.value || '')
    .split(/\r?\n/)
    .map((value) => value.trim())
    .filter((value) => value !== '')
    .length);

const canAnalyzeProspectBatch = computed(() => {
    if (!canManage.value || !campaignId.value || prospectingImportBusy.value) {
        return false;
    }

    if (prospectingImportMode.value === 'csv') {
        return Boolean(prospectingSelectedFile.value);
    }

    return manualProspectLineCount.value > 0;
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
    const lines = String(prospectingManualInput.value || '')
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
        if (targetBatchId > 0) {
            await loadProspectBatch(targetBatchId);
        } else {
            activeProspectBatchId.value = null;
            activeProspectBatch.value = null;
            activeProspectRows.value = [];
            activeProspectPagination.value = null;
        }
    } catch (error) {
        prospectingBatchError.value = error?.response?.data?.message || error?.message || t('marketing.campaign_wizard.prospecting.errors.load_batches');
    } finally {
        prospectingBatchBusy.value = false;
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

const audiencePayload = () => ({
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
    },
    manual_contacts: initialManualContacts,
});

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
        split_a_percent: clampPercent(ab.split_a_percent, 1, 99),
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
        return;
    }

    await loadProspectBatches();
});
</script>

<template>
    <Head :title="isEdit ? t('marketing.campaign_wizard.head_edit', { id: campaignId }) : t('marketing.campaign_wizard.head_new')" />
    <AuthenticatedLayout>
        <div class="space-y-4">
            <section class="rounded-sm border border-stone-200 border-t-4 border-t-green-600 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex flex-wrap items-center justify-between gap-3">
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
                        <p class="text-sm text-stone-500 dark:text-neutral-400">{{ t('marketing.campaign_wizard.description') }}</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <Link :href="route('campaigns.index')">
                            <SecondaryButton>{{ t('marketing.campaign_wizard.actions.back') }}</SecondaryButton>
                        </Link>
                        <Link v-if="isEdit" :href="route('campaigns.show', campaignId)">
                            <SecondaryButton>{{ t('marketing.campaign_wizard.actions.details') }}</SecondaryButton>
                        </Link>
                        <PrimaryButton type="button" :disabled="form.processing || !canManage" @click="save">
                            {{ form.processing ? t('marketing.campaign_wizard.actions.saving') : (isEdit ? t('marketing.campaign_wizard.actions.update') : t('marketing.campaign_wizard.actions.create')) }}
                        </PrimaryButton>
                    </div>
                </div>
                <div class="mt-3 flex flex-wrap gap-2">
                    <button type="button" class="rounded-sm px-2 py-1 text-xs font-semibold" :class="step === 1 ? 'bg-green-600 text-white' : 'border border-stone-200 bg-white text-stone-700'" @click="step = 1">{{ t('marketing.campaign_wizard.steps.setup') }}</button>
                    <button type="button" class="rounded-sm px-2 py-1 text-xs font-semibold" :class="step === 2 ? 'bg-green-600 text-white' : 'border border-stone-200 bg-white text-stone-700'" @click="step = 2">{{ t('marketing.campaign_wizard.steps.audience') }}</button>
                    <button type="button" class="rounded-sm px-2 py-1 text-xs font-semibold" :class="step === 3 ? 'bg-green-600 text-white' : 'border border-stone-200 bg-white text-stone-700'" @click="step = 3">{{ t('marketing.campaign_wizard.steps.message') }}</button>
                    <button type="button" class="rounded-sm px-2 py-1 text-xs font-semibold" :class="step === 4 ? 'bg-green-600 text-white' : 'border border-stone-200 bg-white text-stone-700'" @click="step = 4">{{ t('marketing.campaign_wizard.steps.review') }}</button>
                    <button type="button" class="rounded-sm px-2 py-1 text-xs font-semibold" :class="step === 5 ? 'bg-green-600 text-white' : 'border border-stone-200 bg-white text-stone-700'" @click="step = 5">{{ t('marketing.campaign_wizard.steps.results') }}</button>
                </div>
            </section>

            <section v-show="step === 1" class="space-y-4 rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
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
                    <div class="rounded-sm border border-stone-200 bg-stone-50 px-3 py-4 dark:border-neutral-700 dark:bg-neutral-800">
                        <label class="inline-flex items-center gap-2 text-xs font-semibold text-stone-700 dark:text-neutral-200">
                            <input
                                v-model="form.prospecting_enabled"
                                type="checkbox"
                                class="rounded border-stone-300 text-green-600 focus:ring-green-600"
                            >
                            <span>{{ t('marketing.campaign_wizard.fields.prospecting_enabled') }}</span>
                        </label>
                    </div>
                    <FloatingSelect
                        v-model="form.campaign_direction"
                        :label="t('marketing.campaign_wizard.fields.campaign_direction')"
                        :options="directions.map((direction) => ({ value: direction, label: directionLabel(direction) }))"
                        option-value="value"
                        option-label="label"
                    />
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
                    <FloatingInput v-model="form.locale" :label="t('marketing.campaign_wizard.fields.locale')" />
                    <FloatingInput v-model="form.cta_url" type="url" :label="t('marketing.campaign_wizard.fields.cta_url')" />
                </div>
                <OfferSelector v-model="form.offers" v-model:selectors="form.offer_selectors" :offer-mode="form.offer_mode" :disabled="!canManage" />
                <p v-if="form.errors.offers" class="text-xs text-rose-600">{{ form.errors.offers }}</p>
            </section>

            <section v-show="step === 2" class="space-y-3 rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
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
                                v-model="prospectingSourceType"
                                :label="t('marketing.campaign_wizard.prospecting.fields.source_type')"
                                :options="prospectingSourceTypeOptions"
                                option-value="value"
                                option-label="label"
                            />
                            <FloatingInput
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
                                <label class="block text-xs font-medium text-stone-600 dark:text-neutral-300">
                                    {{ t('marketing.campaign_wizard.prospecting.fields.manual_input') }}
                                </label>
                                <textarea
                                    v-model="prospectingManualInput"
                                    rows="8"
                                    class="block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:focus:ring-neutral-600"
                                    :placeholder="t('marketing.campaign_wizard.prospecting.manual_placeholder')"
                                ></textarea>
                                <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-stone-500 dark:text-neutral-400">
                                    <span>{{ t('marketing.campaign_wizard.prospecting.manual_hint') }}</span>
                                    <span>{{ t('marketing.campaign_wizard.prospecting.manual_line_count', { count: manualProspectLineCount }) }}</span>
                                </div>
                            </div>

                            <div v-else class="mt-3 space-y-2">
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

                            <div class="mt-3 flex flex-wrap gap-2">
                                <PrimaryButton type="button" :disabled="!canAnalyzeProspectBatch" @click="analyzeProspectBatch">
                                    {{ prospectingImportBusy ? t('marketing.campaign_wizard.prospecting.actions.analyzing') : t('marketing.campaign_wizard.prospecting.actions.analyze_batch') }}
                                </PrimaryButton>
                                <SecondaryButton type="button" :disabled="prospectingBatchBusy" @click="loadProspectBatches(activeProspectBatchId)">
                                    {{ t('marketing.common.reload') }}
                                </SecondaryButton>
                            </div>

                            <p v-if="prospectingImportError" class="mt-2 text-xs text-rose-600">{{ prospectingImportError }}</p>
                            <p v-if="prospectingImportMessage" class="mt-2 text-xs text-emerald-700 dark:text-emerald-300">{{ prospectingImportMessage }}</p>
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
                                    :class="Number(activeProspectBatchId || 0) === Number(batch.id) ? 'border-green-500 bg-green-50 dark:border-green-400 dark:bg-green-500/10' : 'border-stone-200 bg-white hover:border-green-300 dark:border-neutral-700 dark:bg-neutral-900'"
                                    @click="loadProspectBatch(batch.id)"
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

                        <div v-if="activeProspectBatch" class="space-y-3 rounded-sm border border-stone-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900">
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
                <div class="grid grid-cols-1 gap-2 md:grid-cols-2">
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

                <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800">
                    <label class="inline-flex items-center gap-2 text-xs text-stone-700 dark:text-neutral-200">
                        <input
                            v-model="useSingleMailingList"
                            type="checkbox"
                            class="rounded border-stone-300 text-green-600 focus:ring-green-600"
                        >
                        <span>{{ t('marketing.campaign_wizard.use_single_mailing_list') }}</span>
                    </label>

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

                <div v-if="!useSingleMailingList" class="rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800">
                    <div class="text-xs font-semibold text-stone-700 dark:text-neutral-200">{{ t('marketing.campaign_wizard.mailing_lists_title') }}</div>
                    <div v-if="!mailingLists.length" class="mt-2 text-xs text-stone-500 dark:text-neutral-400">
                        {{ t('marketing.campaign_wizard.no_mailing_list') }}
                    </div>
                    <div v-else class="mt-2 space-y-2">
                        <div v-for="list in mailingLists" :key="`audience-list-${list.id}`" class="rounded-sm border border-stone-200 bg-white px-3 py-2 dark:border-neutral-700 dark:bg-neutral-900">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div class="text-xs font-semibold text-stone-700 dark:text-neutral-200">
                                    {{ list.name }} ({{ list.customers_count || 0 }})
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
                </div>

                <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div class="text-xs font-semibold text-stone-700 dark:text-neutral-200">
                            {{ t('marketing.campaign_wizard.selected_customers_title') }}
                        </div>
                        <SecondaryButton type="button" :disabled="!canManage" @click="openAudienceCustomerPicker">
                            {{ t('marketing.campaign_wizard.search_customers') }}
                        </SecondaryButton>
                    </div>

                    <div class="mt-2 text-xs text-stone-500 dark:text-neutral-400">
                        {{ t('marketing.campaign_wizard.selected_customers_count', { count: selectedAudienceCustomerIds.length }) }}
                    </div>

                    <div v-if="selectedAudienceCustomers.length === 0" class="mt-2 text-xs text-stone-500 dark:text-neutral-400">
                        {{ t('marketing.campaign_wizard.selected_customers_empty') }}
                    </div>
                    <div v-else class="mt-2 flex flex-wrap gap-2">
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

                <div class="rounded-sm border border-stone-200 bg-stone-50 px-3 py-2 text-xs text-stone-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300">
                    <strong>{{ t('marketing.campaign_wizard.logic_summary') }}</strong> {{ logicSummary }}
                </div>

                <SecondaryButton type="button" :disabled="requestBusy || !canManage || !isEdit" @click="estimateAudience">{{ t('marketing.campaign_wizard.actions.estimate_audience') }}</SecondaryButton>
                <pre v-if="estimate" class="overflow-x-auto rounded-sm border border-stone-200 bg-stone-50 p-2 text-xs text-stone-700 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200">{{ JSON.stringify(estimate, null, 2) }}</pre>
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
                <div v-for="channel in form.channels" :key="channel.channel" class="rounded-sm border border-stone-200 p-3 dark:border-neutral-700">
                    <div class="mb-2 flex items-center justify-between">
                        <div class="text-sm font-semibold text-stone-700 dark:text-neutral-200">{{ channelLabel(channel.channel) }}</div>
                        <label class="inline-flex items-center gap-2 text-xs text-stone-600 dark:text-neutral-300"><input v-model="channel.is_enabled" type="checkbox" class="rounded border-stone-300 text-green-600 focus:ring-green-600"> {{ t('marketing.campaign_wizard.enabled') }}</label>
                    </div>
                    <div class="grid grid-cols-1 gap-2 md:grid-cols-2">
                        <FloatingSelect
                            v-model="channel.message_template_id"
                            :label="t('marketing.campaign_wizard.fields.template')"
                            :options="[
                                { value: '', label: t('marketing.campaign_wizard.no_template') },
                                ...templatesForChannel(channel.channel).map((template) => ({
                                    value: template.id,
                                    label: `${template.name} ${template.is_default ? `(${t('marketing.campaign_wizard.default')})` : ''}`,
                                })),
                            ]"
                            option-value="value"
                            option-label="label"
                            @update:modelValue="applyTemplate(channel)"
                        />
                        <FloatingInput v-model="channel.subject_template" :label="t('marketing.campaign_wizard.fields.subject')" />
                        <FloatingInput v-model="channel.title_template" :label="t('marketing.campaign_wizard.fields.title')" />
                        <EmailBodyEditor
                            v-if="channel.channel === 'EMAIL'"
                            v-model="channel.body_template"
                            class="md:col-span-2"
                            :label="t('marketing.campaign_wizard.fields.body_template')"
                        />
                        <FloatingTextarea
                            v-else
                            v-model="channel.body_template"
                            class="md:col-span-2"
                            :label="t('marketing.campaign_wizard.fields.body_template')"
                        />
                    </div>

                    <div class="mt-3 rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="text-xs font-semibold text-stone-700 dark:text-neutral-200">{{ t('marketing.campaign_wizard.ab_testing.title') }}</div>
                            <label class="inline-flex items-center gap-2 text-xs text-stone-600 dark:text-neutral-300">
                                <input v-model="channel.ab_testing.enabled" type="checkbox" class="rounded border-stone-300 text-green-600 focus:ring-green-600">
                                <span>{{ t('marketing.campaign_wizard.ab_testing.enable_for', { channel: channelLabel(channel.channel) }) }}</span>
                            </label>
                        </div>

                        <div v-if="channel.ab_testing.enabled" class="mt-3 space-y-3">
                            <FloatingInput
                                v-model="channel.ab_testing.split_a_percent"
                                type="number"
                                :label="t('marketing.campaign_wizard.ab_testing.split_percent')"
                            />

                            <div class="grid grid-cols-1 gap-3 lg:grid-cols-2">
                                <div class="rounded-sm border border-stone-200 bg-white p-2 dark:border-neutral-700 dark:bg-neutral-900">
                                    <p class="mb-2 text-xs font-semibold text-stone-700 dark:text-neutral-200">{{ t('marketing.campaign_wizard.ab_testing.variant_a') }}</p>
                                    <div class="space-y-2">
                                        <FloatingInput v-model="channel.ab_testing.variant_a.subject_template" :label="t('marketing.campaign_wizard.ab_testing.subject_a')" />
                                        <FloatingInput v-model="channel.ab_testing.variant_a.title_template" :label="t('marketing.campaign_wizard.ab_testing.title_a')" />
                                        <EmailBodyEditor
                                            v-if="channel.channel === 'EMAIL'"
                                            v-model="channel.ab_testing.variant_a.body_template"
                                            :label="t('marketing.campaign_wizard.ab_testing.body_a')"
                                            compact
                                        />
                                        <FloatingTextarea
                                            v-else
                                            v-model="channel.ab_testing.variant_a.body_template"
                                            :label="t('marketing.campaign_wizard.ab_testing.body_a')"
                                        />
                                    </div>
                                </div>
                                <div class="rounded-sm border border-stone-200 bg-white p-2 dark:border-neutral-700 dark:bg-neutral-900">
                                    <p class="mb-2 text-xs font-semibold text-stone-700 dark:text-neutral-200">{{ t('marketing.campaign_wizard.ab_testing.variant_b') }}</p>
                                    <div class="space-y-2">
                                        <FloatingInput v-model="channel.ab_testing.variant_b.subject_template" :label="t('marketing.campaign_wizard.ab_testing.subject_b')" />
                                        <FloatingInput v-model="channel.ab_testing.variant_b.title_template" :label="t('marketing.campaign_wizard.ab_testing.title_b')" />
                                        <EmailBodyEditor
                                            v-if="channel.channel === 'EMAIL'"
                                            v-model="channel.ab_testing.variant_b.body_template"
                                            :label="t('marketing.campaign_wizard.ab_testing.body_b')"
                                            compact
                                        />
                                        <FloatingTextarea
                                            v-else
                                            v-model="channel.ab_testing.variant_b.body_template"
                                            :label="t('marketing.campaign_wizard.ab_testing.body_b')"
                                        />
                                    </div>
                                </div>
                            </div>
                            <p class="text-xs text-stone-500 dark:text-neutral-400">
                                {{ t('marketing.campaign_wizard.ab_testing.fallback_hint') }}
                            </p>
                        </div>
                    </div>
                </div>
            </section>

            <section v-show="step === 4" class="space-y-3 rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="text-xs text-stone-600 dark:text-neutral-300">
                    <div><strong>{{ t('marketing.campaign_wizard.review.type') }}:</strong> {{ campaignTypeLabel(form.campaign_type) }}</div>
                    <div><strong>{{ t('marketing.campaign_wizard.review.direction') }}:</strong> {{ directionLabel(form.campaign_direction) }}</div>
                    <div><strong>{{ t('marketing.campaign_wizard.review.prospecting_enabled') }}:</strong> {{ form.prospecting_enabled ? t('marketing.campaign_wizard.yes') : t('marketing.campaign_wizard.no') }}</div>
                    <div><strong>{{ t('marketing.campaign_wizard.review.offer_mode') }}:</strong> {{ offerModeLabel(form.offer_mode) }}</div>
                    <div><strong>{{ t('marketing.campaign_wizard.review.offers') }}:</strong> {{ offersPayload.length }}</div>
                    <div><strong>{{ t('marketing.campaign_wizard.review.enabled_channels') }}:</strong> {{ form.channels.filter((row) => row.is_enabled).length }}</div>
                    <div><strong>{{ t('marketing.campaign_wizard.review.ab_enabled_channels') }}:</strong> {{ form.channels.filter((row) => row.ab_testing?.enabled).length }}</div>
                    <div><strong>{{ t('marketing.campaign_wizard.review.holdout') }}:</strong> {{ form.settings.holdout.enabled ? `${form.settings.holdout.percent}%` : t('marketing.campaign_wizard.disabled') }}</div>
                    <div><strong>{{ t('marketing.campaign_wizard.review.channel_fallback') }}:</strong> {{ form.settings.channel_fallback.enabled ? t('marketing.campaign_wizard.enabled') : t('marketing.campaign_wizard.disabled') }}</div>
                    <div><strong>{{ t('marketing.campaign_wizard.review.require_explicit_consent') }}:</strong> {{ marketingSettings?.consent?.require_explicit ? t('marketing.campaign_wizard.yes') : t('marketing.campaign_wizard.no') }}</div>
                </div>

                <div class="grid grid-cols-1 gap-3 xl:grid-cols-2">
                    <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800">
                        <div class="flex items-center justify-between">
                            <div class="text-xs font-semibold text-stone-700 dark:text-neutral-200">{{ t('marketing.campaign_wizard.holdout.title') }}</div>
                            <label class="inline-flex items-center gap-2 text-xs text-stone-600 dark:text-neutral-300">
                                <input v-model="form.settings.holdout.enabled" type="checkbox" class="rounded border-stone-300 text-green-600 focus:ring-green-600">
                                <span>{{ t('marketing.campaign_wizard.holdout.enable') }}</span>
                            </label>
                        </div>
                        <div class="mt-2">
                            <FloatingInput
                                v-model="form.settings.holdout.percent"
                                type="number"
                                :label="t('marketing.campaign_wizard.holdout.percent')"
                            />
                        </div>
                    </div>

                    <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800">
                        <div class="flex items-center justify-between">
                            <div class="text-xs font-semibold text-stone-700 dark:text-neutral-200">{{ t('marketing.campaign_wizard.fallback.title') }}</div>
                            <label class="inline-flex items-center gap-2 text-xs text-stone-600 dark:text-neutral-300">
                                <input v-model="form.settings.channel_fallback.enabled" type="checkbox" class="rounded border-stone-300 text-green-600 focus:ring-green-600">
                                <span>{{ t('marketing.campaign_wizard.fallback.enable') }}</span>
                            </label>
                        </div>
                        <div class="mt-2">
                            <FloatingInput
                                v-model="form.settings.channel_fallback.max_depth"
                                type="number"
                                :label="t('marketing.campaign_wizard.fallback.max_depth')"
                            />
                        </div>
                        <div class="mt-2 space-y-2">
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
                </div>

                <div class="flex flex-wrap gap-2">
                    <SecondaryButton type="button" :disabled="requestBusy || !canManage || !isEdit" @click="previewMessages">{{ t('marketing.campaign_wizard.actions.live_preview') }}</SecondaryButton>
                    <SecondaryButton type="button" :disabled="requestBusy || (!canManage && !canSend) || !isEdit" @click="testSend">{{ t('marketing.campaign_wizard.actions.test_send') }}</SecondaryButton>
                    <PrimaryButton type="button" :disabled="requestBusy || !canSend || !isEdit" @click="sendNow">{{ t('marketing.campaign_wizard.actions.send_now') }}</PrimaryButton>
                </div>
                <p v-if="requestError" class="text-xs text-rose-600">{{ requestError }}</p>
                <p v-if="runMessage" class="text-xs text-emerald-700 dark:text-emerald-300">{{ runMessage }}</p>
                <pre v-if="previews.length" class="overflow-x-auto rounded-sm border border-stone-200 bg-stone-50 p-2 text-xs text-stone-700 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200">{{ JSON.stringify(previews, null, 2) }}</pre>
                <pre v-if="testResults.length" class="overflow-x-auto rounded-sm border border-stone-200 bg-stone-50 p-2 text-xs text-stone-700 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200">{{ JSON.stringify(testResults, null, 2) }}</pre>
            </section>

            <section v-show="step === 5" class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <p class="text-xs text-stone-500 dark:text-neutral-400">{{ t('marketing.campaign_wizard.results_hint') }}</p>
                <div class="mt-3">
                    <Link v-if="isEdit" :href="route('campaigns.show', campaignId)">
                        <PrimaryButton>{{ t('marketing.campaign_wizard.actions.open_results') }}</PrimaryButton>
                    </Link>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
