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
    const channel = String(channelRow.channel).toUpperCase();
    if (channel === 'EMAIL') {
        channelRow.subject_template = content.subject || '';
        channelRow.body_template = content.html || content.body || '';
    } else if (channel === 'SMS') {
        channelRow.body_template = content.text || content.body || '';
    } else if (channel === 'IN_APP') {
        channelRow.title_template = content.title || '';
        channelRow.body_template = content.body || '';
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

const save = () => {
    if (!canManage.value) return;
    if (offersPayload.value.length === 0) {
        form.setError('offers', t('marketing.campaign_wizard.errors.select_offer'));
        step.value = 1;
        return;
    }

    const payload = {
        ...form.data(),
        type: form.campaign_type,
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
