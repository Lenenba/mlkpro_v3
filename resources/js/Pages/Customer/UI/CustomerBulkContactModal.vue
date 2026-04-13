<script setup>
import { computed, reactive, ref, watch } from 'vue';
import axios from 'axios';
import Modal from '@/Components/UI/Modal.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import FloatingTextarea from '@/Components/FloatingTextarea.vue';
import EmailBodyEditor from '@/Pages/Campaigns/Components/EmailBodyEditor.vue';
import InputError from '@/Components/InputError.vue';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    selectedIds: {
        type: Array,
        default: () => [],
    },
    selectedCount: {
        type: Number,
        default: 0,
    },
});

const emit = defineEmits(['sent']);

const { t } = useI18n();

const modalId = 'hs-customer-bulk-contact';
const isOpen = ref(false);
const previewLoading = ref(false);
const sending = ref(false);
const offerLoading = ref(false);
const previewError = ref('');
const generalError = ref('');
const resultSummary = ref(null);
const preserveResultOnSelectionReset = ref(false);
const formErrors = ref({});
const selectedOffer = ref(null);
const offerSearch = ref('');
const offerResults = ref([]);
const lastSuggested = ref({
    subject: '',
    body: '',
});
let offerSearchTimeout;

const preview = ref({
    selected_count: 0,
    eligible_count: 0,
    excluded_count: 0,
    reasons: [],
    eligible_preview: [],
    excluded_preview: [],
    available_tokens: [],
    currency_code: 'CAD',
    suggested_subject: '',
    suggested_body: '',
    offer: null,
});

const form = reactive({
    objective: 'payment_followup',
    channel: 'EMAIL',
    subject: '',
    body: '',
});

const escapeHtml = (value) => String(value || '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');

const htmlToPlainText = (value) => {
    const source = String(value || '');

    if (!source.trim()) {
        return '';
    }

    if (typeof window !== 'undefined' && window.document) {
        const container = window.document.createElement('div');
        container.innerHTML = source;

        return (container.textContent || container.innerText || '').trim();
    }

    return source
        .replace(/<\s*br\s*\/?\s*>/gi, '\n')
        .replace(/<\/p>/gi, '\n\n')
        .replace(/<\/div>/gi, '\n')
        .replace(/<[^>]+>/g, '')
        .trim();
};

const plainTextToRichText = (value) => {
    const source = String(value || '').trim();

    if (!source) {
        return '';
    }

    if (/<[a-z][\s\S]*>/i.test(source)) {
        return source;
    }

    return source
        .split(/\n{2,}/)
        .map((paragraph) => `<p>${escapeHtml(paragraph).replace(/\n/g, '<br>')}</p>`)
        .join('');
};

const objectiveOptions = computed(() => ([
    { value: 'payment_followup', label: t('customers.bulk_contact.objectives.payment_followup') },
    { value: 'promotion', label: t('customers.bulk_contact.objectives.promotion') },
    { value: 'manual_message', label: t('customers.bulk_contact.objectives.manual_message') },
]));

const channelOptions = computed(() => ([
    { value: 'EMAIL', label: t('customers.bulk_contact.channels.email') },
    { value: 'SMS', label: t('customers.bulk_contact.channels.sms') },
]));

const needsBody = computed(() => form.objective !== 'payment_followup');
const needsOffer = computed(() => form.objective === 'promotion');
const usingBrandedEmailTemplate = computed(() => form.channel === 'EMAIL');
const effectiveBody = computed(() => (
    form.channel === 'EMAIL'
        ? htmlToPlainText(form.body)
        : String(form.body || '').trim()
));
const canSend = computed(() => {
    if (sending.value || !props.selectedIds.length) {
        return false;
    }

    if (preview.value.eligible_count < 1) {
        return false;
    }

    if (needsOffer.value && !selectedOffer.value?.id) {
        return false;
    }

    if (needsBody.value && effectiveBody.value === '') {
        return false;
    }

    return true;
});
const smsLength = computed(() => htmlToPlainText(form.body).length);
const summaryLine = computed(() => t('customers.bulk_contact.selection_summary', {
    count: props.selectedCount,
}));
const sendButtonLabel = computed(() => (sending.value
    ? t('customers.bulk_contact.actions.sending')
    : t('customers.bulk_contact.actions.send')));
const selectedOfferTypeLabel = computed(() => {
    if (!selectedOffer.value?.item_type) {
        return '';
    }

    return selectedOffer.value.item_type === 'service'
        ? t('customers.bulk_contact.offer_types.service')
        : t('customers.bulk_contact.offer_types.product');
});

const reasonLabel = (reason) => {
    const key = `customers.bulk_contact.reasons.${reason}`;
    const translated = t(key);

    return translated === key ? reason : translated;
};

const tokenLabel = (token) => {
    const key = `customers.bulk_contact.tokens_map.${token.replace(/[{}]/g, '')}`;
    const translated = t(key);

    return translated === key ? token : translated;
};

const offerTypeLabel = (offer) => {
    if (!offer?.item_type) {
        return '';
    }

    return offer.item_type === 'service'
        ? t('customers.bulk_contact.offer_types.service')
        : t('customers.bulk_contact.offer_types.product');
};

const resetFeedback = () => {
    generalError.value = '';
    previewError.value = '';
    formErrors.value = {};
};

const applySuggestedContent = (data) => {
    const nextSubject = String(data?.suggested_subject || '');
    const nextBody = form.channel === 'EMAIL'
        ? plainTextToRichText(data?.suggested_body || '')
        : String(data?.suggested_body || '');

    if (
        form.channel === 'EMAIL'
        && (form.subject.trim() === '' || form.subject === lastSuggested.value.subject)
    ) {
        form.subject = nextSubject;
    }

    if (form.body.trim() === '' || form.body === lastSuggested.value.body) {
        form.body = nextBody;
    }

    lastSuggested.value = {
        subject: nextSubject,
        body: nextBody,
    };
};

const open = () => {
    if (!props.selectedIds.length || typeof window === 'undefined' || !window.HSOverlay) {
        return;
    }

    resetFeedback();
    resultSummary.value = null;
    window.HSOverlay.open(`#${modalId}`);
};

const close = () => {
    if (typeof window === 'undefined' || !window.HSOverlay) {
        return;
    }

    window.HSOverlay.close(`#${modalId}`);
};

const clearOffer = () => {
    selectedOffer.value = null;
    offerSearch.value = '';
    offerResults.value = [];
    resultSummary.value = null;
    loadPreview();
};

const selectOffer = (offer) => {
    selectedOffer.value = offer;
    offerSearch.value = offer?.name || '';
    offerResults.value = [];
    resultSummary.value = null;
    loadPreview();
};

const searchOffers = (query) => {
    if (offerSearchTimeout) {
        clearTimeout(offerSearchTimeout);
    }

    if (!isOpen.value || !needsOffer.value) {
        offerResults.value = [];
        return;
    }

    const normalized = String(query || '').trim();
    if (selectedOffer.value && normalized === selectedOffer.value.name) {
        offerResults.value = [];
        return;
    }

    if (normalized.length < 2) {
        offerResults.value = [];
        return;
    }

    offerSearchTimeout = setTimeout(async () => {
        offerLoading.value = true;

        try {
            const response = await axios.get(route('catalog.search'), {
                params: {
                    query: normalized,
                    item_type: 'all',
                },
                headers: {
                    Accept: 'application/json',
                },
            });

            offerResults.value = Array.isArray(response.data) ? response.data : [];
        } catch (error) {
            offerResults.value = [];
        } finally {
            offerLoading.value = false;
        }
    }, 220);
};

const loadPreview = async () => {
    if (!isOpen.value || !props.selectedIds.length) {
        return;
    }

    previewLoading.value = true;
    previewError.value = '';

    try {
        const { data } = await axios.post(route('customer.bulk-contact.preview'), {
            ids: props.selectedIds,
            channel: form.channel,
            objective: form.objective,
            offer_id: selectedOffer.value?.id || null,
        }, {
            headers: {
                Accept: 'application/json',
            },
        });

        preview.value = {
            selected_count: Number(data?.selected_count ?? 0),
            eligible_count: Number(data?.eligible_count ?? 0),
            excluded_count: Number(data?.excluded_count ?? 0),
            reasons: Array.isArray(data?.reasons) ? data.reasons : [],
            eligible_preview: Array.isArray(data?.eligible_preview) ? data.eligible_preview : [],
            excluded_preview: Array.isArray(data?.excluded_preview) ? data.excluded_preview : [],
            available_tokens: Array.isArray(data?.available_tokens) ? data.available_tokens : [],
            currency_code: data?.currency_code || 'CAD',
            suggested_subject: String(data?.suggested_subject || ''),
            suggested_body: String(data?.suggested_body || ''),
            offer: data?.offer || null,
        };

        applySuggestedContent(data);
    } catch (error) {
        previewError.value = error?.response?.data?.message || error?.message || t('customers.bulk_contact.preview_error');
    } finally {
        previewLoading.value = false;
    }
};

const submit = async () => {
    resetFeedback();
    sending.value = true;

    try {
        const { data } = await axios.post(route('customer.bulk-contact.send'), {
            ids: props.selectedIds,
            channel: form.channel,
            objective: form.objective,
            offer_id: selectedOffer.value?.id || null,
            subject: form.channel === 'EMAIL' ? form.subject : '',
            body: form.channel === 'EMAIL' ? form.body : htmlToPlainText(form.body),
        }, {
            headers: {
                Accept: 'application/json',
            },
        });

        resultSummary.value = data;
        preserveResultOnSelectionReset.value = true;
        emit('sent', data);
    } catch (error) {
        generalError.value = error?.response?.data?.message || error?.message || t('customers.bulk_contact.send_error');
        formErrors.value = error?.response?.data?.errors || {};
    } finally {
        sending.value = false;
    }
};

const handleOpen = () => {
    isOpen.value = true;
    resultSummary.value = null;
    resetFeedback();
    loadPreview();
};

const handleClose = () => {
    isOpen.value = false;
    offerResults.value = [];
};

watch(() => [form.channel, form.objective], () => {
    resultSummary.value = null;
    resetFeedback();
    if (!needsOffer.value) {
        offerResults.value = [];
    }
    if (form.channel === 'EMAIL' && form.body.trim() !== '') {
        form.body = plainTextToRichText(form.body);
    }
    loadPreview();
});

watch(() => offerSearch.value, (value) => {
    if (!isOpen.value || !needsOffer.value) {
        return;
    }

    if (selectedOffer.value && value !== selectedOffer.value.name) {
        selectedOffer.value = null;
    }

    searchOffers(value);
});

watch(() => props.selectedIds, () => {
    if (preserveResultOnSelectionReset.value && !props.selectedIds.length) {
        preserveResultOnSelectionReset.value = false;
        return;
    }

    resultSummary.value = null;
    resetFeedback();
    loadPreview();
}, { deep: true });

defineExpose({
    open,
    close,
});
</script>

<template>
    <Modal
        :id="modalId"
        :title="$t('customers.bulk_contact.title')"
        @open="handleOpen"
        @close="handleClose"
    >
        <div class="space-y-5">
            <div class="rounded-sm border border-stone-200 bg-stone-50/80 px-4 py-3 text-sm text-stone-600 dark:border-neutral-700 dark:bg-neutral-800/70 dark:text-neutral-300">
                <div class="font-medium text-stone-800 dark:text-neutral-100">
                    {{ $t('customers.bulk_contact.subtitle') }}
                </div>
                <div class="mt-1">
                    {{ summaryLine }}
                </div>
            </div>

            <div v-if="resultSummary" class="rounded-sm border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200">
                <div class="font-medium">
                    {{ resultSummary.message }}
                </div>
                <div class="mt-1 text-xs">
                    {{ $t('customers.bulk_contact.result_summary', {
                        sent: resultSummary.success_count ?? 0,
                        failed: resultSummary.failed_count ?? 0,
                        skipped: resultSummary.skipped_count ?? 0,
                    }) }}
                </div>
            </div>

            <div v-if="generalError" class="rounded-sm border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-200">
                {{ generalError }}
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <FloatingSelect
                    v-model="form.objective"
                    :label="$t('customers.bulk_contact.fields.objective')"
                    :options="objectiveOptions"
                />
                <FloatingSelect
                    v-model="form.channel"
                    :label="$t('customers.bulk_contact.fields.channel')"
                    :options="channelOptions"
                />
            </div>

            <div v-if="needsOffer" class="space-y-2">
                <label class="block text-xs font-semibold uppercase tracking-wide text-stone-600 dark:text-neutral-300">
                    {{ $t('customers.bulk_contact.fields.offer') }}
                </label>
                <div class="relative">
                    <input
                        v-model="offerSearch"
                        type="text"
                        class="block w-full rounded-sm border border-stone-200 bg-white px-3 py-2.5 text-sm text-stone-700 placeholder:text-stone-400 focus:border-green-500 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:placeholder:text-neutral-500"
                        :placeholder="$t('customers.bulk_contact.offer_search_placeholder')"
                    >
                    <button
                        v-if="selectedOffer"
                        type="button"
                        class="absolute inset-y-0 right-0 inline-flex items-center px-3 text-xs font-medium text-stone-500 hover:text-stone-800 dark:text-neutral-400 dark:hover:text-neutral-200"
                        @click="clearOffer"
                    >
                        {{ $t('customers.bulk_contact.clear_offer') }}
                    </button>
                </div>

                <div v-if="selectedOffer" class="rounded-sm border border-sky-200 bg-sky-50 px-3 py-3 text-sm text-sky-900 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-100">
                    <div class="font-semibold">
                        {{ selectedOffer.name }}
                    </div>
                    <div class="mt-1 flex flex-wrap items-center gap-2 text-xs">
                        <span>{{ selectedOfferTypeLabel }}</span>
                        <span v-if="selectedOffer.price">
                            {{ selectedOffer.price }}
                        </span>
                    </div>
                </div>

                <ul
                    v-if="offerResults.length"
                    class="max-h-56 overflow-y-auto rounded-sm border border-stone-200 bg-white shadow-lg dark:border-neutral-700 dark:bg-neutral-900"
                >
                    <li
                        v-for="offer in offerResults"
                        :key="offer.id"
                        class="cursor-pointer px-3 py-2 hover:bg-stone-50 dark:hover:bg-neutral-800"
                        @click="selectOffer(offer)"
                    >
                        <div class="flex items-center justify-between gap-3">
                            <div class="min-w-0">
                                <div class="truncate text-sm font-medium text-stone-800 dark:text-neutral-100">
                                    {{ offer.name }}
                                </div>
                                <div class="text-xs text-stone-500 dark:text-neutral-400">
                                    {{ offerTypeLabel(offer) }}
                                </div>
                            </div>
                            <div class="text-xs text-stone-500 dark:text-neutral-400">
                                {{ offer.price }}
                            </div>
                        </div>
                    </li>
                </ul>

                <div v-else-if="needsOffer && offerSearch.trim().length >= 2 && !offerLoading" class="text-xs text-stone-500 dark:text-neutral-400">
                    {{ $t('customers.bulk_contact.no_offer_results') }}
                </div>

                <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-stone-500 dark:text-neutral-400">
                    <span>{{ $t('customers.bulk_contact.offer_required_hint') }}</span>
                    <span v-if="offerLoading">{{ $t('customers.bulk_contact.loading_offers') }}</span>
                </div>
                <InputError :message="formErrors.offer_id?.[0]" />
            </div>

            <FloatingInput
                v-if="form.channel === 'EMAIL'"
                v-model="form.subject"
                :label="$t('customers.bulk_contact.fields.subject')"
            />

            <div class="space-y-2">
                <EmailBodyEditor
                    v-if="form.channel === 'EMAIL'"
                    v-model="form.body"
                    :label="$t('customers.bulk_contact.fields.body')"
                    compact
                />
                <FloatingTextarea
                    v-else
                    v-model="form.body"
                    :label="$t('customers.bulk_contact.fields.body')"
                />
                <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-stone-500 dark:text-neutral-400">
                    <span v-if="usingBrandedEmailTemplate">
                        {{ $t('customers.bulk_contact.template_hint_email') }}
                    </span>
                    <span v-if="form.objective === 'payment_followup'">
                        {{ $t('customers.bulk_contact.payment_followup_hint') }}
                    </span>
                    <span v-else>
                        {{ $t('customers.bulk_contact.body_required_hint') }}
                    </span>
                    <span v-if="form.channel === 'SMS'">
                        {{ $t('customers.bulk_contact.sms_length', { count: smsLength }) }}
                    </span>
                </div>
                <InputError :message="formErrors.body?.[0]" />
            </div>

            <InputError v-if="form.channel === 'EMAIL'" :message="formErrors.subject?.[0]" />

            <div class="space-y-3 rounded-sm border border-stone-200 bg-white px-4 py-4 dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex items-center justify-between gap-2">
                    <div>
                        <div class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                            {{ $t('customers.bulk_contact.preview_title') }}
                        </div>
                        <div class="text-xs text-stone-500 dark:text-neutral-400">
                            {{ $t('customers.bulk_contact.preview_subtitle') }}
                        </div>
                    </div>
                    <button
                        type="button"
                        class="inline-flex items-center gap-x-1.5 rounded-sm border border-stone-200 bg-white px-2.5 py-2 text-xs font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200 dark:hover:bg-neutral-700"
                        :disabled="previewLoading"
                        @click="loadPreview"
                    >
                        {{ previewLoading ? $t('customers.bulk_contact.actions.refreshing') : $t('customers.bulk_contact.actions.refresh_preview') }}
                    </button>
                </div>

                <div v-if="previewError" class="rounded-sm border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
                    {{ previewError }}
                </div>

                <div v-if="preview.offer" class="rounded-sm border border-sky-200 bg-sky-50/70 px-3 py-3 dark:border-sky-500/30 dark:bg-sky-500/10">
                    <div class="text-[11px] uppercase tracking-wide text-sky-700 dark:text-sky-200">
                        {{ $t('customers.bulk_contact.preview.selected_offer') }}
                    </div>
                    <div class="mt-1 text-sm font-semibold text-sky-900 dark:text-sky-100">
                        {{ preview.offer.name }}
                    </div>
                    <div class="mt-1 text-xs text-sky-800 dark:text-sky-200">
                        {{ offerTypeLabel(preview.offer) }}
                        <span v-if="preview.offer.price">
                            · {{ preview.offer.price }} {{ preview.offer.currency_code }}
                        </span>
                    </div>
                </div>

                <div class="grid gap-3 md:grid-cols-3">
                    <div class="rounded-sm border border-stone-200 bg-stone-50/70 px-3 py-3 dark:border-neutral-700 dark:bg-neutral-800/70">
                        <div class="text-[11px] uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                            {{ $t('customers.bulk_contact.preview.selected') }}
                        </div>
                        <div class="mt-1 text-lg font-semibold text-stone-900 dark:text-neutral-100">
                            {{ preview.selected_count }}
                        </div>
                    </div>
                    <div class="rounded-sm border border-emerald-200 bg-emerald-50/70 px-3 py-3 dark:border-emerald-500/30 dark:bg-emerald-500/10">
                        <div class="text-[11px] uppercase tracking-wide text-emerald-700 dark:text-emerald-200">
                            {{ $t('customers.bulk_contact.preview.eligible') }}
                        </div>
                        <div class="mt-1 text-lg font-semibold text-emerald-800 dark:text-emerald-100">
                            {{ preview.eligible_count }}
                        </div>
                    </div>
                    <div class="rounded-sm border border-amber-200 bg-amber-50/70 px-3 py-3 dark:border-amber-500/30 dark:bg-amber-500/10">
                        <div class="text-[11px] uppercase tracking-wide text-amber-700 dark:text-amber-200">
                            {{ $t('customers.bulk_contact.preview.excluded') }}
                        </div>
                        <div class="mt-1 text-lg font-semibold text-amber-800 dark:text-amber-100">
                            {{ preview.excluded_count }}
                        </div>
                    </div>
                </div>

                <div v-if="preview.reasons.length" class="space-y-2">
                    <div class="text-xs font-semibold text-stone-700 dark:text-neutral-200">
                        {{ $t('customers.bulk_contact.preview.reasons_title') }}
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <span
                            v-for="reason in preview.reasons"
                            :key="`${reason.reason}-${reason.count}`"
                            class="inline-flex items-center rounded-full bg-stone-100 px-2.5 py-1 text-[11px] font-medium text-stone-700 dark:bg-neutral-800 dark:text-neutral-200"
                        >
                            {{ reasonLabel(reason.reason) }}: {{ reason.count }}
                        </span>
                    </div>
                </div>

                <div v-if="preview.eligible_preview.length" class="space-y-2">
                    <div class="text-xs font-semibold text-stone-700 dark:text-neutral-200">
                        {{ $t('customers.bulk_contact.preview.eligible_examples') }}
                    </div>
                    <div class="space-y-2">
                        <div
                            v-for="recipient in preview.eligible_preview"
                            :key="`eligible-${recipient.id}`"
                            class="flex flex-col gap-1 rounded-sm border border-stone-200 px-3 py-2 text-xs text-stone-600 dark:border-neutral-700 dark:text-neutral-300 md:flex-row md:items-center md:justify-between"
                        >
                            <div class="font-medium text-stone-800 dark:text-neutral-100">
                                {{ recipient.name }}
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <span>{{ recipient.destination }}</span>
                                <span v-if="form.objective === 'payment_followup'" class="text-stone-500 dark:text-neutral-400">
                                    {{ $t('customers.bulk_contact.preview.invoice_summary', {
                                        count: recipient.open_invoice_count,
                                        amount: recipient.balance_due,
                                        currency: preview.currency_code,
                                    }) }}
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <div v-if="preview.excluded_preview.length" class="space-y-2">
                    <div class="text-xs font-semibold text-stone-700 dark:text-neutral-200">
                        {{ $t('customers.bulk_contact.preview.excluded_examples') }}
                    </div>
                    <div class="space-y-2">
                        <div
                            v-for="recipient in preview.excluded_preview"
                            :key="`excluded-${recipient.id}`"
                            class="flex flex-col gap-1 rounded-sm border border-stone-200 px-3 py-2 text-xs text-stone-600 dark:border-neutral-700 dark:text-neutral-300 md:flex-row md:items-center md:justify-between"
                        >
                            <div class="font-medium text-stone-800 dark:text-neutral-100">
                                {{ recipient.name }}
                            </div>
                            <div>
                                {{ reasonLabel(recipient.reason) }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="space-y-2 rounded-sm border border-stone-200 bg-stone-50/80 px-4 py-4 dark:border-neutral-700 dark:bg-neutral-800/70">
                <div class="text-xs font-semibold text-stone-700 dark:text-neutral-200">
                    {{ $t('customers.bulk_contact.tokens_title') }}
                </div>
                <div class="flex flex-wrap gap-2">
                    <span
                        v-for="token in preview.available_tokens"
                        :key="token"
                        class="inline-flex items-center rounded-full border border-stone-200 bg-white px-2.5 py-1 text-[11px] text-stone-700 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                        :title="tokenLabel(token)"
                    >
                        {{ token }}
                    </span>
                </div>
                <div class="text-[11px] text-stone-500 dark:text-neutral-400">
                    {{ $t('customers.bulk_contact.tokens_hint') }}
                </div>
            </div>

            <div class="flex flex-wrap items-center justify-end gap-2">
                <button
                    type="button"
                    class="inline-flex items-center gap-x-1.5 rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200 dark:hover:bg-neutral-700"
                    @click="close"
                >
                    {{ $t('customers.bulk_contact.actions.close') }}
                </button>
                <button
                    type="button"
                    class="inline-flex items-center gap-x-1.5 rounded-sm border border-transparent bg-green-600 px-3 py-2 text-xs font-medium text-white hover:bg-green-700 disabled:pointer-events-none disabled:opacity-50"
                    :disabled="!canSend"
                    @click="submit"
                >
                    {{ sendButtonLabel }}
                </button>
            </div>
        </div>
    </Modal>
</template>
