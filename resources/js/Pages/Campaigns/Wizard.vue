<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import FloatingTextarea from '@/Components/FloatingTextarea.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
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

const canManage = computed(() => Boolean(props.access?.can_manage));
const canSend = computed(() => Boolean(props.access?.can_send));
const isEdit = computed(() => Boolean(props.campaign?.id));
const campaignId = computed(() => props.campaign?.id || null);
const step = ref(1);

const channels = (props.enums?.channels || ['EMAIL', 'SMS', 'IN_APP']).map((v) => String(v).toUpperCase());
const types = props.enums?.types || ['PROMOTION'];
const offerModes = props.enums?.offer_modes || ['PRODUCTS', 'SERVICES', 'MIXED'];
const languageModes = props.enums?.language_modes || ['PREFERRED', 'FR', 'EN', 'BOTH'];
const audienceSourceLogicOptions = props.enums?.audience_source_logic || ['UNION', 'INTERSECT'];
const scheduleTypeOptions = [
    { value: 'manual', label: 'manual' },
    { value: 'scheduled', label: 'scheduled' },
    { value: 'automation', label: 'automation' },
];
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

const manualCustomerIds = ref(
    Array.isArray(props.campaign?.audience?.manual_customer_ids)
        ? props.campaign.audience.manual_customer_ids.join(', ')
        : ''
);
const manualContacts = ref(
    Array.isArray(props.campaign?.audience?.manual_contacts)
        ? props.campaign.audience.manual_contacts.join('\n')
        : (props.campaign?.audience?.manual_contacts || '')
);
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

const logicSummary = computed(() => {
    const segmentPart = form.audience_segment_id ? 'Segment' : 'Builder';
    const includeCount = includeMailingListIds.value.length;
    const excludeCount = excludeMailingListIds.value.length;
    const manualCount = manualCustomerIds.value.split(/[\s,;]+/).filter((value) => value !== '').length;
    if (sourceLogic.value === 'INTERSECT') {
        return `(A intersect B) union C | A=${segmentPart}, B=${includeCount} list(s), C=${manualCount} manual id(s), excluded=${excludeCount} list(s)`;
    }

    return `A union B union C | A=${segmentPart}, B=${includeCount} list(s), C=${manualCount} manual id(s), excluded=${excludeCount} list(s)`;
});

const audiencePayload = () => ({
    smart_filters: props.campaign?.audience?.smart_filters || null,
    exclusion_filters: props.campaign?.audience?.exclusion_filters || null,
    manual_customer_ids: manualCustomerIds.value.split(/[\s,;]+/).map((v) => Number(v)).filter((v) => Number.isInteger(v) && v > 0),
    include_mailing_list_ids: includeMailingListIds.value,
    exclude_mailing_list_ids: excludeMailingListIds.value,
    source_logic: sourceLogic.value,
    source_summary: {
        logic: sourceLogic.value,
        include_mailing_lists_count: includeMailingListIds.value.length,
        exclude_mailing_lists_count: excludeMailingListIds.value.length,
    },
    manual_contacts: manualContacts.value.split(/\r?\n/).map((v) => v.trim()).filter((v) => v !== ''),
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
        form.setError('offers', 'Select at least one offer.');
        step.value = 1;
        return;
    }

    const payload = {
        ...form.data(),
        type: form.campaign_type,
        offers: offersPayload.value,
        product_ids: productIdsPayload.value,
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
        requestError.value = error?.response?.data?.message || error?.message || 'Request failed.';
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
        runMessage.value = response.data?.message || 'Campaign run queued.';
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
    <Head :title="isEdit ? `Campaign #${campaignId}` : 'New campaign'" />
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
                            <span>{{ isEdit ? `Edit campaign #${campaignId}` : 'Create campaign' }}</span>
                        </h1>
                        <p class="text-sm text-stone-500 dark:text-neutral-400">Products/services campaigns with templates and segments.</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <Link :href="route('campaigns.index')">
                            <SecondaryButton>Back</SecondaryButton>
                        </Link>
                        <Link v-if="isEdit" :href="route('campaigns.show', campaignId)">
                            <SecondaryButton>Details</SecondaryButton>
                        </Link>
                        <PrimaryButton type="button" :disabled="form.processing || !canManage" @click="save">
                            {{ form.processing ? 'Saving...' : (isEdit ? 'Update' : 'Create') }}
                        </PrimaryButton>
                    </div>
                </div>
                <div class="mt-3 flex flex-wrap gap-2">
                    <button type="button" class="rounded-sm px-2 py-1 text-xs font-semibold" :class="step === 1 ? 'bg-green-600 text-white' : 'border border-stone-200 bg-white text-stone-700'" @click="step = 1">1. Setup</button>
                    <button type="button" class="rounded-sm px-2 py-1 text-xs font-semibold" :class="step === 2 ? 'bg-green-600 text-white' : 'border border-stone-200 bg-white text-stone-700'" @click="step = 2">2. Audience</button>
                    <button type="button" class="rounded-sm px-2 py-1 text-xs font-semibold" :class="step === 3 ? 'bg-green-600 text-white' : 'border border-stone-200 bg-white text-stone-700'" @click="step = 3">3. Message</button>
                    <button type="button" class="rounded-sm px-2 py-1 text-xs font-semibold" :class="step === 4 ? 'bg-green-600 text-white' : 'border border-stone-200 bg-white text-stone-700'" @click="step = 4">4. Review</button>
                    <button type="button" class="rounded-sm px-2 py-1 text-xs font-semibold" :class="step === 5 ? 'bg-green-600 text-white' : 'border border-stone-200 bg-white text-stone-700'" @click="step = 5">5. Results</button>
                </div>
            </section>

            <section v-show="step === 1" class="space-y-4 rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                    <FloatingInput v-model="form.name" label="Campaign name" />
                    <FloatingSelect
                        v-model="form.campaign_type"
                        label="Campaign type"
                        :options="types.map((type) => ({ value: type, label: type }))"
                        option-value="value"
                        option-label="label"
                    />
                    <FloatingSelect
                        v-model="form.offer_mode"
                        label="Offer mode"
                        :options="offerModes.map((mode) => ({ value: mode, label: mode }))"
                        option-value="value"
                        option-label="label"
                    />
                    <FloatingSelect
                        v-model="form.language_mode"
                        label="Language mode"
                        :options="languageModes.map((mode) => ({ value: mode, label: mode }))"
                        option-value="value"
                        option-label="label"
                    />
                    <FloatingSelect
                        v-model="form.schedule_type"
                        label="Schedule type"
                        :options="scheduleTypeOptions"
                        option-value="value"
                        option-label="label"
                    />
                    <FloatingInput
                        v-if="form.schedule_type === 'scheduled'"
                        v-model="form.scheduled_at"
                        type="datetime-local"
                        label="Scheduled at"
                    />
                    <FloatingInput v-model="form.locale" label="Locale (fr/en)" />
                    <FloatingInput v-model="form.cta_url" type="url" label="CTA URL" />
                </div>
                <OfferSelector v-model="form.offers" v-model:selectors="form.offer_selectors" :offer-mode="form.offer_mode" :disabled="!canManage" />
                <p v-if="form.errors.offers" class="text-xs text-rose-600">{{ form.errors.offers }}</p>
            </section>

            <section v-show="step === 2" class="space-y-3 rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="grid grid-cols-1 gap-2 md:grid-cols-2">
                    <FloatingSelect
                        v-model="form.audience_segment_id"
                        label="Segment"
                        :options="[
                            { value: '', label: 'No segment' },
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
                        label="Source logic"
                        :options="audienceSourceLogicOptions.map((mode) => ({ value: mode, label: mode }))"
                        option-value="value"
                        option-label="label"
                    />
                </div>

                <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800">
                    <div class="text-xs font-semibold text-stone-700 dark:text-neutral-200">Mailing lists (static targeting)</div>
                    <div v-if="!mailingLists.length" class="mt-2 text-xs text-stone-500 dark:text-neutral-400">
                        No mailing list found in marketing settings.
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
                                        <span>Include</span>
                                    </label>
                                    <label class="inline-flex items-center gap-2 text-stone-600 dark:text-neutral-300">
                                        <input
                                            :checked="isMailingListExcluded(list.id)"
                                            type="checkbox"
                                            class="rounded border-stone-300 text-rose-600 focus:ring-rose-600"
                                            @change="toggleExcludeMailingList(list.id)"
                                        >
                                        <span>Exclude</span>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <FloatingTextarea v-model="manualCustomerIds" label="Manual customer IDs" />
                <FloatingTextarea v-model="manualContacts" label="Manual contacts, one per line" />

                <div class="rounded-sm border border-stone-200 bg-stone-50 px-3 py-2 text-xs text-stone-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300">
                    <strong>Logic summary:</strong> {{ logicSummary }}
                </div>

                <SecondaryButton type="button" :disabled="requestBusy || !canManage || !isEdit" @click="estimateAudience">Estimate audience</SecondaryButton>
                <pre v-if="estimate" class="overflow-x-auto rounded-sm border border-stone-200 bg-stone-50 p-2 text-xs text-stone-700 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200">{{ JSON.stringify(estimate, null, 2) }}</pre>
            </section>

            <section v-show="step === 3" class="space-y-3 rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div v-for="channel in form.channels" :key="channel.channel" class="rounded-sm border border-stone-200 p-3 dark:border-neutral-700">
                    <div class="mb-2 flex items-center justify-between">
                        <div class="text-sm font-semibold text-stone-700 dark:text-neutral-200">{{ channel.channel }}</div>
                        <label class="inline-flex items-center gap-2 text-xs text-stone-600 dark:text-neutral-300"><input v-model="channel.is_enabled" type="checkbox" class="rounded border-stone-300 text-green-600 focus:ring-green-600"> enabled</label>
                    </div>
                    <div class="grid grid-cols-1 gap-2 md:grid-cols-2">
                        <FloatingSelect
                            v-model="channel.message_template_id"
                            label="Template"
                            :options="[
                                { value: '', label: 'No template' },
                                ...templatesForChannel(channel.channel).map((template) => ({
                                    value: template.id,
                                    label: `${template.name} ${template.is_default ? '(default)' : ''}`,
                                })),
                            ]"
                            option-value="value"
                            option-label="label"
                            @update:modelValue="applyTemplate(channel)"
                        />
                        <FloatingInput v-model="channel.subject_template" label="Subject" />
                        <FloatingInput v-model="channel.title_template" label="Title" />
                        <FloatingTextarea
                            v-model="channel.body_template"
                            class="md:col-span-2"
                            label="Body with {offerName}, {offerPrice}, {ctaUrl}"
                        />
                    </div>

                    <div class="mt-3 rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="text-xs font-semibold text-stone-700 dark:text-neutral-200">A/B testing</div>
                            <label class="inline-flex items-center gap-2 text-xs text-stone-600 dark:text-neutral-300">
                                <input v-model="channel.ab_testing.enabled" type="checkbox" class="rounded border-stone-300 text-green-600 focus:ring-green-600">
                                <span>Enable for {{ channel.channel }}</span>
                            </label>
                        </div>

                        <div v-if="channel.ab_testing.enabled" class="mt-3 space-y-3">
                            <FloatingInput
                                v-model="channel.ab_testing.split_a_percent"
                                type="number"
                                label="Variant A split percent (1-99)"
                            />

                            <div class="grid grid-cols-1 gap-3 lg:grid-cols-2">
                                <div class="rounded-sm border border-stone-200 bg-white p-2 dark:border-neutral-700 dark:bg-neutral-900">
                                    <p class="mb-2 text-xs font-semibold text-stone-700 dark:text-neutral-200">Variant A</p>
                                    <div class="space-y-2">
                                        <FloatingInput v-model="channel.ab_testing.variant_a.subject_template" label="Subject A" />
                                        <FloatingInput v-model="channel.ab_testing.variant_a.title_template" label="Title A" />
                                        <FloatingTextarea v-model="channel.ab_testing.variant_a.body_template" label="Body A" />
                                    </div>
                                </div>
                                <div class="rounded-sm border border-stone-200 bg-white p-2 dark:border-neutral-700 dark:bg-neutral-900">
                                    <p class="mb-2 text-xs font-semibold text-stone-700 dark:text-neutral-200">Variant B</p>
                                    <div class="space-y-2">
                                        <FloatingInput v-model="channel.ab_testing.variant_b.subject_template" label="Subject B" />
                                        <FloatingInput v-model="channel.ab_testing.variant_b.title_template" label="Title B" />
                                        <FloatingTextarea v-model="channel.ab_testing.variant_b.body_template" label="Body B" />
                                    </div>
                                </div>
                            </div>
                            <p class="text-xs text-stone-500 dark:text-neutral-400">
                                Empty variant fields fallback to the base channel template.
                            </p>
                        </div>
                    </div>
                </div>
            </section>

            <section v-show="step === 4" class="space-y-3 rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="text-xs text-stone-600 dark:text-neutral-300">
                    <div><strong>Type:</strong> {{ form.campaign_type }}</div>
                    <div><strong>Offer mode:</strong> {{ form.offer_mode }}</div>
                    <div><strong>Offers:</strong> {{ offersPayload.length }}</div>
                    <div><strong>Enabled channels:</strong> {{ form.channels.filter((row) => row.is_enabled).length }}</div>
                    <div><strong>A/B enabled channels:</strong> {{ form.channels.filter((row) => row.ab_testing?.enabled).length }}</div>
                    <div><strong>Holdout:</strong> {{ form.settings.holdout.enabled ? `${form.settings.holdout.percent}%` : 'disabled' }}</div>
                    <div><strong>Channel fallback:</strong> {{ form.settings.channel_fallback.enabled ? 'enabled' : 'disabled' }}</div>
                    <div><strong>Require explicit consent:</strong> {{ marketingSettings?.consent?.require_explicit ? 'yes' : 'no' }}</div>
                </div>

                <div class="grid grid-cols-1 gap-3 xl:grid-cols-2">
                    <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800">
                        <div class="flex items-center justify-between">
                            <div class="text-xs font-semibold text-stone-700 dark:text-neutral-200">Holdout group</div>
                            <label class="inline-flex items-center gap-2 text-xs text-stone-600 dark:text-neutral-300">
                                <input v-model="form.settings.holdout.enabled" type="checkbox" class="rounded border-stone-300 text-green-600 focus:ring-green-600">
                                <span>Enable holdout</span>
                            </label>
                        </div>
                        <div class="mt-2">
                            <FloatingInput
                                v-model="form.settings.holdout.percent"
                                type="number"
                                label="Holdout percent (0-100)"
                            />
                        </div>
                    </div>

                    <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800">
                        <div class="flex items-center justify-between">
                            <div class="text-xs font-semibold text-stone-700 dark:text-neutral-200">Channel fallback</div>
                            <label class="inline-flex items-center gap-2 text-xs text-stone-600 dark:text-neutral-300">
                                <input v-model="form.settings.channel_fallback.enabled" type="checkbox" class="rounded border-stone-300 text-green-600 focus:ring-green-600">
                                <span>Enable fallback</span>
                            </label>
                        </div>
                        <div class="mt-2">
                            <FloatingInput
                                v-model="form.settings.channel_fallback.max_depth"
                                type="number"
                                label="Fallback max depth (1-3)"
                            />
                        </div>
                        <div class="mt-2 space-y-2">
                            <div
                                v-for="source in channels"
                                :key="`fallback-${source}`"
                                class="rounded-sm border border-stone-200 bg-white p-2 dark:border-neutral-700 dark:bg-neutral-900"
                            >
                                <p class="text-xs font-semibold text-stone-700 dark:text-neutral-200">{{ source }} fallback targets</p>
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
                    <SecondaryButton type="button" :disabled="requestBusy || !canManage || !isEdit" @click="previewMessages">Live preview</SecondaryButton>
                    <SecondaryButton type="button" :disabled="requestBusy || (!canManage && !canSend) || !isEdit" @click="testSend">Test send</SecondaryButton>
                    <PrimaryButton type="button" :disabled="requestBusy || !canSend || !isEdit" @click="sendNow">Send now</PrimaryButton>
                </div>
                <p v-if="requestError" class="text-xs text-rose-600">{{ requestError }}</p>
                <p v-if="runMessage" class="text-xs text-emerald-700 dark:text-emerald-300">{{ runMessage }}</p>
                <pre v-if="previews.length" class="overflow-x-auto rounded-sm border border-stone-200 bg-stone-50 p-2 text-xs text-stone-700 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200">{{ JSON.stringify(previews, null, 2) }}</pre>
                <pre v-if="testResults.length" class="overflow-x-auto rounded-sm border border-stone-200 bg-stone-50 p-2 text-xs text-stone-700 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200">{{ JSON.stringify(testResults, null, 2) }}</pre>
            </section>

            <section v-show="step === 5" class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <p class="text-xs text-stone-500 dark:text-neutral-400">Open campaign details for run-level stats and export.</p>
                <div class="mt-3">
                    <Link v-if="isEdit" :href="route('campaigns.show', campaignId)">
                        <PrimaryButton>Open results</PrimaryButton>
                    </Link>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
