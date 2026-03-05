<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import OfferSelector from '@/Pages/Campaigns/Components/OfferSelector.vue';

const props = defineProps({
    campaign: { type: Object, default: null },
    selectedOffers: { type: Array, default: () => [] },
    segments: { type: Array, default: () => [] },
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

const existingChannels = Array.isArray(props.campaign?.channels) ? props.campaign.channels : [];
const initialChannels = channels.map((channel) => {
    const existing = existingChannels.find((row) => String(row.channel).toUpperCase() === channel);
    const enabledByConfig = Boolean(props.marketingSettings?.channels?.enabled?.[channel] ?? true);
    return {
        channel,
        is_enabled: existing ? Boolean(existing.is_enabled) : enabledByConfig,
        message_template_id: existing?.message_template_id || '',
        subject_template: existing?.subject_template || '',
        title_template: existing?.title_template || '',
        body_template: existing?.body_template || '',
    };
});

const initialOffers = Array.isArray(props.selectedOffers) ? props.selectedOffers : [];

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
        promo_code: props.campaign?.settings?.promo_code || '',
        promo_percent: props.campaign?.settings?.promo_percent || '',
        promo_end_date: props.campaign?.settings?.promo_end_date || '',
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

const audiencePayload = () => ({
    smart_filters: props.campaign?.audience?.smart_filters || null,
    exclusion_filters: props.campaign?.audience?.exclusion_filters || null,
    manual_customer_ids: manualCustomerIds.value.split(/[\s,;]+/).map((v) => Number(v)).filter((v) => Number.isInteger(v) && v > 0),
    manual_contacts: manualContacts.value.split(/\r?\n/).map((v) => v.trim()).filter((v) => v !== ''),
});

const templatesForChannel = (channel) => {
    return templates.value.filter((row) => String(row.channel).toUpperCase() === String(channel).toUpperCase());
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
        })),
        audience: audiencePayload(),
        settings: {
            ...form.settings,
            offer_selectors: form.offer_selectors,
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
                        <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">{{ isEdit ? `Edit campaign #${campaignId}` : 'Create campaign' }}</h1>
                        <p class="text-sm text-stone-500 dark:text-neutral-400">Products/services campaigns with templates and segments.</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <Link :href="route('campaigns.index')" class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200 dark:hover:bg-neutral-700">Back</Link>
                        <Link v-if="isEdit" :href="route('campaigns.show', campaignId)" class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200 dark:hover:bg-neutral-700">Details</Link>
                        <button type="button" :disabled="form.processing || !canManage" class="rounded-sm border border-transparent bg-green-600 px-3 py-2 text-xs font-semibold text-white hover:bg-green-700 disabled:opacity-60" @click="save">
                            {{ form.processing ? 'Saving...' : (isEdit ? 'Update' : 'Create') }}
                        </button>
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
                    <input v-model="form.name" type="text" placeholder="Campaign name" class="rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200">
                    <select v-model="form.campaign_type" class="rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200"><option v-for="type in types" :key="type" :value="type">{{ type }}</option></select>
                    <select v-model="form.offer_mode" class="rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200"><option v-for="mode in offerModes" :key="mode" :value="mode">{{ mode }}</option></select>
                    <select v-model="form.language_mode" class="rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200"><option v-for="mode in languageModes" :key="mode" :value="mode">{{ mode }}</option></select>
                    <select v-model="form.schedule_type" class="rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200"><option value="manual">manual</option><option value="scheduled">scheduled</option><option value="automation">automation</option></select>
                    <input v-if="form.schedule_type === 'scheduled'" v-model="form.scheduled_at" type="datetime-local" class="rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200">
                    <input v-model="form.locale" type="text" placeholder="Locale (fr/en)" class="rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200">
                    <input v-model="form.cta_url" type="url" placeholder="CTA URL" class="rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200">
                </div>
                <OfferSelector v-model="form.offers" v-model:selectors="form.offer_selectors" :offer-mode="form.offer_mode" :disabled="!canManage" />
                <p v-if="form.errors.offers" class="text-xs text-rose-600">{{ form.errors.offers }}</p>
            </section>

            <section v-show="step === 2" class="space-y-3 rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <select v-model="form.audience_segment_id" class="w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200">
                    <option value="">No segment</option>
                    <option v-for="segment in segments" :key="segment.id" :value="segment.id">{{ segment.name }} ({{ segment.cached_count || 0 }})</option>
                </select>
                <textarea v-model="manualCustomerIds" rows="3" placeholder="Manual customer IDs" class="w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200" />
                <textarea v-model="manualContacts" rows="3" placeholder="Manual contacts, one per line" class="w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200" />
                <button type="button" :disabled="requestBusy || !canManage || !isEdit" class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-semibold text-stone-700 hover:bg-stone-50 disabled:opacity-60 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200 dark:hover:bg-neutral-700" @click="estimateAudience">Estimate audience</button>
                <pre v-if="estimate" class="overflow-x-auto rounded-sm border border-stone-200 bg-stone-50 p-2 text-xs text-stone-700 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200">{{ JSON.stringify(estimate, null, 2) }}</pre>
            </section>

            <section v-show="step === 3" class="space-y-3 rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div v-for="channel in form.channels" :key="channel.channel" class="rounded-sm border border-stone-200 p-3 dark:border-neutral-700">
                    <div class="mb-2 flex items-center justify-between">
                        <div class="text-sm font-semibold text-stone-700 dark:text-neutral-200">{{ channel.channel }}</div>
                        <label class="inline-flex items-center gap-2 text-xs text-stone-600 dark:text-neutral-300"><input v-model="channel.is_enabled" type="checkbox" class="rounded border-stone-300 text-green-600 focus:ring-green-600"> enabled</label>
                    </div>
                    <div class="grid grid-cols-1 gap-2 md:grid-cols-2">
                        <select v-model="channel.message_template_id" class="rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200" @change="applyTemplate(channel)">
                            <option value="">No template</option>
                            <option v-for="template in templatesForChannel(channel.channel)" :key="template.id" :value="template.id">{{ template.name }} {{ template.is_default ? '(default)' : '' }}</option>
                        </select>
                        <input v-model="channel.subject_template" type="text" placeholder="Subject" class="rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200">
                        <input v-model="channel.title_template" type="text" placeholder="Title" class="rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200">
                        <textarea v-model="channel.body_template" rows="3" placeholder="Body with {offerName}, {offerPrice}, {ctaUrl}" class="md:col-span-2 rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200" />
                    </div>
                </div>
            </section>

            <section v-show="step === 4" class="space-y-3 rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="text-xs text-stone-600 dark:text-neutral-300">
                    <div><strong>Type:</strong> {{ form.campaign_type }}</div>
                    <div><strong>Offer mode:</strong> {{ form.offer_mode }}</div>
                    <div><strong>Offers:</strong> {{ offersPayload.length }}</div>
                    <div><strong>Enabled channels:</strong> {{ form.channels.filter((row) => row.is_enabled).length }}</div>
                    <div><strong>Require explicit consent:</strong> {{ marketingSettings?.consent?.require_explicit ? 'yes' : 'no' }}</div>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button type="button" :disabled="requestBusy || !canManage || !isEdit" class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-semibold text-stone-700 hover:bg-stone-50 disabled:opacity-60 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200 dark:hover:bg-neutral-700" @click="previewMessages">Live preview</button>
                    <button type="button" :disabled="requestBusy || (!canManage && !canSend) || !isEdit" class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-semibold text-stone-700 hover:bg-stone-50 disabled:opacity-60 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200 dark:hover:bg-neutral-700" @click="testSend">Test send</button>
                    <button type="button" :disabled="requestBusy || !canSend || !isEdit" class="rounded-sm border border-transparent bg-green-600 px-3 py-2 text-xs font-semibold text-white hover:bg-green-700 disabled:opacity-60" @click="sendNow">Send now</button>
                </div>
                <p v-if="requestError" class="text-xs text-rose-600">{{ requestError }}</p>
                <p v-if="runMessage" class="text-xs text-emerald-700 dark:text-emerald-300">{{ runMessage }}</p>
                <pre v-if="previews.length" class="overflow-x-auto rounded-sm border border-stone-200 bg-stone-50 p-2 text-xs text-stone-700 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200">{{ JSON.stringify(previews, null, 2) }}</pre>
                <pre v-if="testResults.length" class="overflow-x-auto rounded-sm border border-stone-200 bg-stone-50 p-2 text-xs text-stone-700 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200">{{ JSON.stringify(testResults, null, 2) }}</pre>
            </section>

            <section v-show="step === 5" class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <p class="text-xs text-stone-500 dark:text-neutral-400">Open campaign details for run-level stats and export.</p>
                <div class="mt-3">
                    <Link v-if="isEdit" :href="route('campaigns.show', campaignId)" class="rounded-sm border border-transparent bg-green-600 px-3 py-2 text-xs font-semibold text-white hover:bg-green-700">Open results</Link>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>

