<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import axios from 'axios';
import { router, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import EmailTemplateBuilder from '@/Pages/Campaigns/Components/EmailTemplateBuilder.vue';
import EmailBodyEditor from '@/Pages/Campaigns/Components/EmailBodyEditor.vue';

const props = defineProps({
    enums: {
        type: Object,
        default: () => ({}),
    },
});

const { t } = useI18n();
const page = usePage();

const translateWithFallback = (key, fallback) => {
    const translated = t(key);
    return translated === key ? fallback : translated;
};

const humanizeValue = (value) => String(value || '')
    .replaceAll('_', ' ')
    .toLowerCase()
    .replace(/\b\w/g, (char) => char.toUpperCase());

const channelLabel = (value) => {
    const normalized = String(value || '').toLowerCase();
    if (!normalized) {
        return '-';
    }
    return translateWithFallback(`marketing.channels.${normalized}`, humanizeValue(value));
};

const campaignTypeLabel = (value) => {
    const normalized = String(value || '').toLowerCase();
    if (!normalized) {
        return '-';
    }
    return translateWithFallback(`marketing.campaign_types.${normalized}`, humanizeValue(value));
};

const clone = (value) => JSON.parse(JSON.stringify(value ?? {}));

const createDefaultEmailContent = () => ({
    subject: '',
    previewText: '',
    editorMode: 'builder',
    templateKey: '',
    html: '',
    schema: {
        sections: [],
    },
});

const createDefaultForm = () => ({
    name: '',
    channel: 'EMAIL',
    campaign_type: '',
    language: '',
    is_default: false,
    tags: '',
    emailContent: createDefaultEmailContent(),
    smsContent: {
        text: '',
        shortener: true,
    },
    inAppContent: {
        title: '',
        body: '',
        deepLink: '',
        image: '',
    },
});

const normalizeFormState = (value) => {
    const next = value && typeof value === 'object' ? clone(value) : {};
    const base = createDefaultForm();

    return {
        ...base,
        ...next,
        is_default: Boolean(next?.is_default ?? base.is_default),
        tags: typeof next?.tags === 'string' ? next.tags : base.tags,
        emailContent: {
            ...base.emailContent,
            ...(next?.emailContent && typeof next.emailContent === 'object' ? next.emailContent : {}),
        },
        smsContent: {
            ...base.smsContent,
            ...(next?.smsContent && typeof next.smsContent === 'object' ? next.smsContent : {}),
        },
        inAppContent: {
            ...base.inAppContent,
            ...(next?.inAppContent && typeof next.inAppContent === 'object' ? next.inAppContent : {}),
        },
    };
};

const rows = ref([]);
const presets = ref([]);
const blockLibrary = ref([]);
const supportedTokens = ref([]);
const brandProfile = ref({});
const busy = ref(false);
const isLoadingList = ref(false);
const error = ref('');
const info = ref('');
const preview = ref(null);
const previewMode = ref('desktop');
const editingId = ref(null);
const listSearch = ref('');
const listChannel = ref('');
const listPage = ref(1);
const listPerPage = ref(8);
const perPageOptions = [8, 16, 24];
const tokenInsertRequest = ref(null);
const activeTokenTarget = ref(null);
const defaultTestRecipientEmail = computed(() => String(page.props?.auth?.user?.email || '').trim());
const testRecipientEmail = ref(defaultTestRecipientEmail.value);
const draftStatus = ref('idle');
const draftRestored = ref(false);

const form = ref(normalizeFormState(createDefaultForm()));

let suppressDraftWatch = false;
let draftSaveTimeout = null;
let removeBeforeRouteLeaveGuard = null;

const runWithoutDraftWatch = (callback) => {
    suppressDraftWatch = true;
    callback();
    nextTick(() => {
        suppressDraftWatch = false;
    });
};

const formSnapshot = () => JSON.stringify({
    editingId: editingId.value,
    form: normalizeFormState(form.value),
});

const savedSnapshot = ref(formSnapshot());
const hasUnsavedChanges = computed(() => formSnapshot() !== savedSnapshot.value);
const draftStorageKey = computed(() => {
    const path = typeof window === 'undefined' ? 'marketing-templates' : window.location.pathname;
    const scope = editingId.value ? `template-${editingId.value}` : 'new';

    return `marketing-template-editor:v1:${path}:${scope}`;
});
const leaveWarningMessage = 'You have unsaved template changes. Leave this page without saving?';

const campaignTypes = computed(() => Array.isArray(props.enums?.campaign_types) ? props.enums.campaign_types : []);
const channelOptions = computed(() => [
    { value: 'EMAIL', label: channelLabel('EMAIL') },
    { value: 'SMS', label: channelLabel('SMS') },
    { value: 'IN_APP', label: channelLabel('IN_APP') },
]);
const campaignTypeOptions = computed(() => ([
    { value: '', label: t('marketing.settings.offers.campaign_type_all') },
    ...campaignTypes.value.map((type) => ({
        value: type,
        label: campaignTypeLabel(type),
    })),
]));

const filteredRows = computed(() => {
    const query = String(listSearch.value || '').trim().toLowerCase();
    const selectedChannel = String(listChannel.value || '').trim().toUpperCase();

    return rows.value.filter((template) => {
        if (selectedChannel && String(template?.channel || '').toUpperCase() !== selectedChannel) {
            return false;
        }

        if (!query) {
            return true;
        }

        const haystack = [
            template?.name,
            template?.channel,
            template?.campaign_type,
            template?.language,
            ...(Array.isArray(template?.tags) ? template.tags : []),
        ]
            .map((value) => String(value || '').toLowerCase())
            .join(' ');

        return haystack.includes(query);
    });
});

const totalPages = computed(() => Math.max(1, Math.ceil(filteredRows.value.length / listPerPage.value)));
const pagedRows = computed(() => {
    const start = (listPage.value - 1) * listPerPage.value;
    return filteredRows.value.slice(start, start + listPerPage.value);
});
const emailPreviewSubject = computed(() => String(preview.value?.subject || form.value.emailContent?.subject || '').trim());
const emailPreviewPreheader = computed(() => String(form.value.emailContent?.previewText || '').trim());
const previewViewportClass = computed(() => (
    previewMode.value === 'mobile'
        ? 'mx-auto w-[390px] max-w-full'
        : 'w-full'
));

const getDraftStorage = () => (typeof window === 'undefined' ? null : window.localStorage);

const clearDraft = (key = draftStorageKey.value) => {
    const storage = getDraftStorage();
    if (!storage) {
        return;
    }

    storage.removeItem(key);
};

const syncSavedSnapshot = async ({
    status = 'idle',
    restored = false,
} = {}) => {
    await nextTick();
    await nextTick();
    savedSnapshot.value = formSnapshot();
    draftStatus.value = status;
    draftRestored.value = restored;
};

const restoreDraftForCurrentScope = () => {
    const storage = getDraftStorage();
    if (!storage) {
        return false;
    }

    const raw = storage.getItem(draftStorageKey.value);
    if (!raw) {
        draftStatus.value = 'idle';
        draftRestored.value = false;
        return false;
    }

    try {
        const parsed = JSON.parse(raw);
        if (!parsed || typeof parsed !== 'object' || !parsed.form) {
            storage.removeItem(draftStorageKey.value);
            draftStatus.value = 'idle';
            draftRestored.value = false;
            return false;
        }

        runWithoutDraftWatch(() => {
            preview.value = null;
            previewMode.value = 'desktop';
            tokenInsertRequest.value = null;
            activeTokenTarget.value = null;
            form.value = normalizeFormState(parsed.form);
        });

        savedSnapshot.value = formSnapshot();
        draftStatus.value = 'saved';
        draftRestored.value = true;
        return true;
    } catch (error) {
        storage.removeItem(draftStorageKey.value);
        draftStatus.value = 'idle';
        draftRestored.value = false;
        return false;
    }
};

const persistDraft = () => {
    const storage = getDraftStorage();
    if (!storage) {
        draftStatus.value = 'idle';
        return;
    }

    if (!hasUnsavedChanges.value) {
        clearDraft();
        draftStatus.value = 'idle';
        return;
    }

    storage.setItem(draftStorageKey.value, JSON.stringify({
        form: normalizeFormState(form.value),
        saved_at: new Date().toISOString(),
    }));
    savedSnapshot.value = formSnapshot();
    draftStatus.value = 'saved';
    draftRestored.value = false;
};

const scheduleDraftSave = () => {
    if (suppressDraftWatch) {
        return;
    }

    if (draftSaveTimeout) {
        clearTimeout(draftSaveTimeout);
    }

    if (!hasUnsavedChanges.value) {
        clearDraft();
        draftStatus.value = 'idle';
        return;
    }

    draftStatus.value = 'saving';
    draftSaveTimeout = setTimeout(() => {
        persistDraft();
        draftSaveTimeout = null;
    }, 700);
};

const applyEditorState = (nextForm, templateId = null) => {
    runWithoutDraftWatch(() => {
        editingId.value = templateId === null ? null : Number(templateId);
        preview.value = null;
        previewMode.value = 'desktop';
        tokenInsertRequest.value = null;
        activeTokenTarget.value = null;
        form.value = normalizeFormState(nextForm);
    });
};

const buildContent = () => {
    const channel = String(form.value.channel || 'EMAIL').toUpperCase();
    if (channel === 'EMAIL') {
        return clone(form.value.emailContent);
    }
    if (channel === 'SMS') {
        return clone(form.value.smsContent);
    }

    return clone(form.value.inAppContent);
};

const parseTags = (value) => String(value || '')
    .split(/[,\n;]+/)
    .map((item) => item.trim())
    .filter((item) => item !== '');

const buildFormStateFromTemplate = (template) => {
    const content = template?.content || {};
    const channel = String(template?.channel || 'EMAIL').toUpperCase();

    return normalizeFormState({
        name: template?.name || '',
        channel,
        campaign_type: template?.campaign_type || '',
        language: template?.language || '',
        is_default: Boolean(template?.is_default),
        tags: Array.isArray(template?.tags) ? template.tags.join(', ') : '',
        emailContent: channel === 'EMAIL' ? clone(content) : createDefaultEmailContent(),
        smsContent: channel === 'SMS'
            ? {
                text: content.text || content.body || '',
                shortener: Boolean(content.shortener ?? true),
            }
            : { text: '', shortener: true },
        inAppContent: channel === 'IN_APP'
            ? {
                title: content.title || '',
                body: content.body || '',
                deepLink: content.deepLink || content.deep_link || '',
                image: content.image || content.imageUrl || '',
            }
            : { title: '', body: '', deepLink: '', image: '' },
    });
};

const setActiveEmailField = (key, id, label) => {
    activeTokenTarget.value = {
        scope: 'email-meta',
        key,
        id,
        label,
    };
};

const handleBuilderFocusField = (payload) => {
    activeTokenTarget.value = payload;
};

const insertTokenAtCursor = async (value, token, inputId, applyValue) => {
    const wrappedToken = `{${token}}`;
    const currentValue = String(value || '');
    const element = typeof document !== 'undefined'
        ? document.getElementById(inputId)
        : null;

    let nextValue = `${currentValue}${wrappedToken}`;
    let nextCursor = nextValue.length;

    if (element && typeof element.selectionStart === 'number' && typeof element.selectionEnd === 'number') {
        const start = element.selectionStart;
        const end = element.selectionEnd;
        nextValue = `${currentValue.slice(0, start)}${wrappedToken}${currentValue.slice(end)}`;
        nextCursor = start + wrappedToken.length;
    }

    applyValue(nextValue);

    await nextTick();

    if (element && typeof element.focus === 'function') {
        element.focus();
        if (typeof element.setSelectionRange === 'function') {
            element.setSelectionRange(nextCursor, nextCursor);
        }
    }
};

const insertToken = async (token) => {
    if (String(form.value.channel || '').toUpperCase() !== 'EMAIL' || !activeTokenTarget.value) {
        return;
    }

    if (activeTokenTarget.value.scope === 'builder') {
        tokenInsertRequest.value = {
            token,
            nonce: Date.now(),
        };
        return;
    }

    if (activeTokenTarget.value.scope === 'email-meta') {
        const key = String(activeTokenTarget.value.key || '');
        if (!['subject', 'previewText'].includes(key)) {
            return;
        }

        await insertTokenAtCursor(
            form.value.emailContent[key],
            token,
            String(activeTokenTarget.value.id || ''),
            (nextValue) => {
                form.value.emailContent[key] = nextValue;
            }
        );
    }
};

const resetForm = async ({ clearCurrentDraft = true, restoreDraft = true } = {}) => {
    const previousDraftKey = draftStorageKey.value;
    if (clearCurrentDraft) {
        clearDraft(previousDraftKey);
    }

    applyEditorState(createDefaultForm());
    if (!testRecipientEmail.value) {
        testRecipientEmail.value = defaultTestRecipientEmail.value;
    }
    if (restoreDraft) {
        const restored = restoreDraftForCurrentScope();
        if (restored) {
            await syncSavedSnapshot({ status: 'saved', restored: true });
            return;
        }
    }
    await syncSavedSnapshot();
};

const load = async () => {
    isLoadingList.value = true;
    error.value = '';
    try {
        const response = await axios.get(route('marketing.templates.index'));
        rows.value = Array.isArray(response.data?.templates) ? response.data.templates : [];
        presets.value = Array.isArray(response.data?.presets) ? response.data.presets : [];
        blockLibrary.value = Array.isArray(response.data?.block_library) ? response.data.block_library : [];
        supportedTokens.value = Array.isArray(response.data?.supported_tokens) ? response.data.supported_tokens : [];
        brandProfile.value = response.data?.brand_profile || {};
    } catch (requestError) {
        error.value = requestError?.response?.data?.message || requestError?.message || t('marketing.template_manager.error_load');
    } finally {
        isLoadingList.value = false;
    }
};

const save = async () => {
    busy.value = true;
    error.value = '';
    info.value = '';
    try {
        const currentDraftKey = draftStorageKey.value;
        const payload = {
            name: String(form.value.name || '').trim(),
            channel: String(form.value.channel || '').toUpperCase(),
            campaign_type: form.value.campaign_type || null,
            language: String(form.value.language || '').trim() || null,
            is_default: Boolean(form.value.is_default),
            tags: parseTags(form.value.tags),
            content: buildContent(),
        };

        if (editingId.value) {
            await axios.put(route('marketing.templates.update', editingId.value), payload);
            info.value = t('marketing.template_manager.info_updated');
        } else {
            await axios.post(route('marketing.templates.store'), payload);
            info.value = t('marketing.template_manager.info_created');
        }

        clearDraft(currentDraftKey);
        await resetForm({ clearCurrentDraft: false, restoreDraft: false });
        await load();
    } catch (requestError) {
        error.value = requestError?.response?.data?.message || requestError?.message || t('marketing.template_manager.error_save');
    } finally {
        busy.value = false;
    }
};

const edit = async (template) => {
    applyEditorState(buildFormStateFromTemplate(template), template.id);
    const restored = restoreDraftForCurrentScope();
    if (!restored) {
        await syncSavedSnapshot();
    }
    info.value = '';
    error.value = '';
    window.scrollTo({ top: 0, behavior: 'smooth' });
};

const duplicateTemplate = async (template) => {
    busy.value = true;
    error.value = '';
    info.value = '';
    try {
        const response = await axios.post(route('marketing.templates.duplicate', template.id));
        info.value = response.data?.message || 'Template duplicated.';
        await load();
        if (response.data?.template) {
            edit(response.data.template);
        }
    } catch (requestError) {
        error.value = requestError?.response?.data?.message || requestError?.message || t('marketing.template_manager.error_save');
    } finally {
        busy.value = false;
    }
};

const destroyTemplate = async (template) => {
    if (!confirm(t('marketing.template_manager.confirm_delete', { name: template.name }))) {
        return;
    }

    busy.value = true;
    error.value = '';
    info.value = '';
    try {
        await axios.delete(route('marketing.templates.destroy', template.id));
        info.value = t('marketing.template_manager.info_deleted');
        await load();
    } catch (requestError) {
        error.value = requestError?.response?.data?.message || requestError?.message || t('marketing.template_manager.error_delete');
    } finally {
        busy.value = false;
    }
};

const previewTemplate = async () => {
    busy.value = true;
    error.value = '';
    preview.value = null;
    try {
        const response = await axios.post(route('marketing.templates.preview'), {
            channel: String(form.value.channel || '').toUpperCase(),
            content: buildContent(),
        });
        preview.value = response.data?.preview || null;
    } catch (requestError) {
        error.value = requestError?.response?.data?.message || requestError?.message || t('marketing.template_manager.error_preview');
    } finally {
        busy.value = false;
    }
};

const sendTestEmail = async () => {
    busy.value = true;
    error.value = '';
    info.value = '';
    try {
        const response = await axios.post(route('marketing.templates.test-send'), {
            channel: String(form.value.channel || '').toUpperCase(),
            content: buildContent(),
            recipient_email: String(testRecipientEmail.value || defaultTestRecipientEmail.value || '').trim() || null,
        });
        info.value = response.data?.message || 'Test email sent.';
    } catch (requestError) {
        error.value = requestError?.response?.data?.message || requestError?.message || 'Unable to send the test email.';
    } finally {
        busy.value = false;
    }
};

const listStats = computed(() => ({
    total: rows.value.length,
    email: rows.value.filter((template) => String(template?.channel || '').toUpperCase() === 'EMAIL').length,
    defaults: rows.value.filter((template) => Boolean(template?.is_default)).length,
}));

watch([listSearch, listChannel, listPerPage], () => {
    listPage.value = 1;
});

watch(() => form.value.channel, () => {
    activeTokenTarget.value = null;
    tokenInsertRequest.value = null;
});

watch(totalPages, (value) => {
    if (listPage.value > value) {
        listPage.value = value;
    }
});

watch(() => formSnapshot(), () => {
    scheduleDraftSave();
});

watch(defaultTestRecipientEmail, (value) => {
    if (!String(testRecipientEmail.value || '').trim()) {
        testRecipientEmail.value = String(value || '').trim();
    }
});

const handleBeforeUnload = (event) => {
    if (!hasUnsavedChanges.value) {
        return;
    }

    event.preventDefault();
    event.returnValue = '';
};

const shouldConfirmInertiaLeave = (event) => {
    if (!hasUnsavedChanges.value || typeof window === 'undefined') {
        return false;
    }

    const targetUrl = event?.detail?.visit?.url;
    if (!targetUrl) {
        return false;
    }

    const currentUrl = new URL(window.location.href);
    const nextUrl = new URL(String(targetUrl), window.location.origin);

    return currentUrl.pathname !== nextUrl.pathname
        || currentUrl.search !== nextUrl.search
        || currentUrl.hash !== nextUrl.hash;
};

onMounted(() => {
    if (!String(testRecipientEmail.value || '').trim()) {
        testRecipientEmail.value = defaultTestRecipientEmail.value;
    }

    const restored = restoreDraftForCurrentScope();
    if (!restored) {
        syncSavedSnapshot();
    }
    if (typeof window !== 'undefined') {
        window.addEventListener('beforeunload', handleBeforeUnload);
    }

    removeBeforeRouteLeaveGuard = router.on('before', (event) => {
        if (!shouldConfirmInertiaLeave(event)) {
            return;
        }

        return window.confirm(leaveWarningMessage);
    });
});

onBeforeUnmount(() => {
    if (draftSaveTimeout) {
        clearTimeout(draftSaveTimeout);
    }
    if (typeof window !== 'undefined') {
        window.removeEventListener('beforeunload', handleBeforeUnload);
    }
    if (typeof removeBeforeRouteLeaveGuard === 'function') {
        removeBeforeRouteLeaveGuard();
    }
});

load();
</script>

<template>
    <div class="space-y-4">
        <div class="grid grid-cols-1 gap-4 xl:grid-cols-[1.35fr_0.65fr]">
            <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h3 class="text-base font-semibold text-stone-800 dark:text-neutral-100">{{ t('marketing.template_manager.title') }}</h3>
                        <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                            Build branded emails with a simple 3-section layout, company colors, and live preview.
                        </p>
                        <div class="mt-2 flex flex-wrap items-center gap-2 text-[11px]">
                            <span
                                v-if="hasUnsavedChanges"
                                class="rounded-full border border-amber-200 bg-amber-50 px-2 py-1 font-medium text-amber-700 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-300"
                            >
                                Unsaved changes
                            </span>
                            <span
                                v-if="draftStatus === 'saving'"
                                class="rounded-full border border-stone-200 bg-stone-50 px-2 py-1 text-stone-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300"
                            >
                                Saving draft locally...
                            </span>
                            <span
                                v-else-if="draftStatus === 'saved'"
                                class="rounded-full border border-emerald-200 bg-emerald-50 px-2 py-1 text-emerald-700 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300"
                            >
                                Draft saved locally
                            </span>
                            <span
                                v-if="draftRestored"
                                class="rounded-full border border-sky-200 bg-sky-50 px-2 py-1 text-sky-700 dark:border-sky-500/20 dark:bg-sky-500/10 dark:text-sky-300"
                            >
                                Restored after refresh
                            </span>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <SecondaryButton :disabled="busy || isLoadingList" @click="load">
                            {{ t('marketing.common.reload') }}
                        </SecondaryButton>
                        <PrimaryButton type="button" :disabled="busy" @click="save">
                            {{ editingId ? t('marketing.template_manager.update_template') : t('marketing.template_manager.create_template') }}
                        </PrimaryButton>
                    </div>
                </div>

                <div class="mt-3 rounded-sm border border-stone-200 bg-stone-50 px-3 py-2 text-xs text-stone-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300">
                    Local draft autosave protects the current editor on this device. The template is only saved to your library when you use Create or Update.
                </div>

                <div v-if="error" class="mt-3 rounded-sm border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-700 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-300">
                    {{ error }}
                </div>
                <div v-if="info" class="mt-3 rounded-sm border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs text-emerald-700 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300">
                    {{ info }}
                </div>

                <div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-2">
                    <FloatingInput v-model="form.name" :label="t('marketing.template_manager.template_name')" />
                    <FloatingSelect
                        v-model="form.channel"
                        :label="t('marketing.template_manager.channel')"
                        :options="channelOptions"
                        option-value="value"
                        option-label="label"
                    />
                    <FloatingSelect
                        v-model="form.campaign_type"
                        :label="t('marketing.template_manager.campaign_type')"
                        :options="campaignTypeOptions"
                        option-value="value"
                        option-label="label"
                    />
                    <FloatingInput v-model="form.language" :label="t('marketing.template_manager.language')" />
                    <FloatingInput v-model="form.tags" :label="t('marketing.template_manager.tags')" class="md:col-span-2" />
                    <label class="inline-flex items-center gap-2 text-xs text-stone-600 dark:text-neutral-300 md:col-span-2">
                        <input
                            v-model="form.is_default"
                            type="checkbox"
                            class="rounded border-stone-300 text-green-600 focus:ring-green-600"
                        >
                        <span>{{ t('marketing.template_manager.set_default') }}</span>
                    </label>
                </div>

                <div class="mt-4">
                    <div v-if="form.channel === 'EMAIL'">
                        <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                            <FloatingInput
                                id="template-email-subject"
                                v-model="form.emailContent.subject"
                                :label="t('marketing.template_manager.subject')"
                                @focusin="setActiveEmailField('subject', 'template-email-subject', 'Subject')"
                            />
                            <FloatingInput
                                id="template-email-preview-text"
                                v-model="form.emailContent.previewText"
                                :label="t('marketing.template_manager.preview_text')"
                                @focusin="setActiveEmailField('previewText', 'template-email-preview-text', 'Preview text')"
                            />
                        </div>
                        <details class="mt-4 rounded-sm border border-stone-200 bg-stone-50 px-3 py-2 text-xs text-stone-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300">
                            <summary class="cursor-pointer font-semibold">Quick insert variables</summary>
                            <div class="mt-2 flex flex-wrap items-center justify-between gap-2">
                                <p>
                                    Active field:
                                    <span class="font-semibold text-stone-800 dark:text-neutral-100">
                                        {{ activeTokenTarget?.label || 'Select a text field first' }}
                                    </span>
                                </p>
                                <p class="text-[11px] text-stone-500 dark:text-neutral-400">
                                    Click a field, then click a token.
                                </p>
                            </div>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <button
                                    v-for="token in supportedTokens"
                                    :key="`template-token-${token}`"
                                    type="button"
                                    class="rounded-full border border-stone-300 bg-white px-2 py-1 text-[11px] text-stone-700 transition hover:border-stone-400 hover:bg-stone-100 disabled:cursor-not-allowed disabled:opacity-50 dark:border-neutral-600 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                                    :disabled="!activeTokenTarget"
                                    @mousedown.prevent
                                    @click="insertToken(token)"
                                >
                                    {{ '{' + token + '}' }}
                                </button>
                            </div>
                        </details>
                        <div class="mt-4 rounded-sm border border-stone-200 bg-white p-3 dark:border-neutral-700 dark:bg-neutral-900">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div>
                                    <p class="text-sm font-semibold text-stone-800 dark:text-neutral-100">Preview modes</p>
                                    <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                        Check the same email in desktop and mobile widths before saving.
                                    </p>
                                </div>
                                <div class="inline-flex rounded-sm border border-stone-200 bg-stone-50 p-1 dark:border-neutral-700 dark:bg-neutral-800">
                                    <button
                                        type="button"
                                        class="rounded-sm px-3 py-1.5 text-xs font-semibold transition"
                                        :class="previewMode === 'desktop'
                                            ? 'bg-white text-stone-900 shadow-sm dark:bg-neutral-900 dark:text-neutral-100'
                                            : 'text-stone-500 hover:text-stone-800 dark:text-neutral-400 dark:hover:text-neutral-200'"
                                        @click="previewMode = 'desktop'"
                                    >
                                        Desktop
                                    </button>
                                    <button
                                        type="button"
                                        class="rounded-sm px-3 py-1.5 text-xs font-semibold transition"
                                        :class="previewMode === 'mobile'
                                            ? 'bg-white text-stone-900 shadow-sm dark:bg-neutral-900 dark:text-neutral-100'
                                            : 'text-stone-500 hover:text-stone-800 dark:text-neutral-400 dark:hover:text-neutral-200'"
                                        @click="previewMode = 'mobile'"
                                    >
                                        Mobile
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div class="mt-4">
                            <EmailTemplateBuilder
                                v-model="form.emailContent"
                                :presets="presets"
                                :block-library="blockLibrary"
                                :supported-tokens="supportedTokens"
                                :language="form.language"
                                :brand-profile="brandProfile"
                                :token-insert-request="tokenInsertRequest"
                                @focus-field="handleBuilderFocusField"
                            />
                        </div>
                    </div>

                    <div v-else-if="form.channel === 'SMS'" class="grid grid-cols-1 gap-3">
                        <EmailBodyEditor v-model="form.smsContent.text" :label="t('marketing.template_manager.sms_text')" compact />
                        <label class="inline-flex items-center gap-2 text-xs text-stone-600 dark:text-neutral-300">
                            <input v-model="form.smsContent.shortener" type="checkbox" class="rounded border-stone-300 text-green-600 focus:ring-green-600">
                            <span>{{ t('marketing.template_manager.enable_shortener') }}</span>
                        </label>
                    </div>

                    <div v-else class="grid grid-cols-1 gap-3 md:grid-cols-2">
                        <FloatingInput v-model="form.inAppContent.title" :label="t('marketing.template_manager.in_app_title')" />
                        <FloatingInput v-model="form.inAppContent.deepLink" :label="t('marketing.template_manager.deep_link')" />
                        <EmailBodyEditor v-model="form.inAppContent.body" :label="t('marketing.template_manager.in_app_body')" class="md:col-span-2" compact />
                        <FloatingInput v-model="form.inAppContent.image" :label="t('marketing.template_manager.image_url')" class="md:col-span-2" />
                    </div>
                </div>

                <div v-if="form.channel === 'EMAIL'" class="mt-4 rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800">
                    <div class="grid grid-cols-1 gap-3 md:grid-cols-[1fr_auto] md:items-end">
                        <FloatingInput
                            v-model="testRecipientEmail"
                            label="Test recipient email"
                        />
                        <SecondaryButton type="button" :disabled="busy" @click="sendTestEmail">
                            Send test email
                        </SecondaryButton>
                    </div>
                    <p class="mt-2 text-[11px] text-stone-500 dark:text-neutral-400">
                        The rendered preview is sent to this address only. No campaign is published or scheduled.
                    </p>
                </div>

                <div class="mt-4 flex flex-wrap items-center gap-2">
                    <PrimaryButton type="button" :disabled="busy" @click="save">
                        {{ editingId ? t('marketing.template_manager.update_template') : t('marketing.template_manager.create_template') }}
                    </PrimaryButton>
                    <SecondaryButton type="button" :disabled="busy" @click="previewTemplate">
                        {{ t('marketing.template_manager.preview') }}
                    </SecondaryButton>
                    <SecondaryButton type="button" :disabled="busy" @click="resetForm">
                        {{ t('marketing.common.reset') }}
                    </SecondaryButton>
                </div>
            </div>

            <div class="space-y-4">
                <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <p class="text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">Brand profile in use</p>
                    <div class="mt-3 flex items-center gap-3">
                        <img
                            v-if="brandProfile.logo_url"
                            :src="brandProfile.logo_url"
                            :alt="brandProfile.name || 'Brand logo'"
                            class="h-12 w-auto max-w-[120px] rounded bg-white p-2"
                        >
                        <div>
                            <p class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ brandProfile.name || 'Company brand' }}</p>
                            <p class="text-xs text-stone-500 dark:text-neutral-400">{{ brandProfile.tagline || brandProfile.description || 'Configure this in Marketing settings > brand profile.' }}</p>
                        </div>
                    </div>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <span class="rounded-full border border-stone-200 px-2 py-1 text-[11px] text-stone-600 dark:border-neutral-700 dark:text-neutral-300">{{ brandProfile.contact_email || 'No email' }}</span>
                        <span class="rounded-full border border-stone-200 px-2 py-1 text-[11px] text-stone-600 dark:border-neutral-700 dark:text-neutral-300">{{ brandProfile.phone || 'No phone' }}</span>
                        <span class="rounded-full border border-stone-200 px-2 py-1 text-[11px] text-stone-600 dark:border-neutral-700 dark:text-neutral-300">{{ brandProfile.website_url || 'No website' }}</span>
                    </div>
                    <div class="mt-3 flex gap-2">
                        <span class="h-8 w-8 rounded-full border border-stone-200" :style="{ backgroundColor: brandProfile.primary_color || '#0F766E' }"></span>
                        <span class="h-8 w-8 rounded-full border border-stone-200" :style="{ backgroundColor: brandProfile.secondary_color || '#0F172A' }"></span>
                        <span class="h-8 w-8 rounded-full border border-stone-200" :style="{ backgroundColor: brandProfile.accent_color || '#F59E0B' }"></span>
                    </div>
                </div>

                <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="flex items-center justify-between gap-2">
                        <p class="text-sm font-semibold text-stone-800 dark:text-neutral-100">Preview</p>
                        <span class="text-xs text-stone-500 dark:text-neutral-400">{{ preview?.editor_mode || form.channel }}</span>
                    </div>
                    <div v-if="form.channel === 'EMAIL'" class="mt-3 space-y-3">
                        <div class="rounded-sm border border-stone-200 bg-stone-50 px-3 py-3 text-xs text-stone-700 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200">
                            <p class="text-[11px] font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">Inbox preview</p>
                            <div class="mt-2 rounded-sm border border-stone-200 bg-white px-3 py-3 dark:border-neutral-700 dark:bg-neutral-900">
                                <div class="text-[11px] uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                    {{ brandProfile.name || 'Your company' }}
                                </div>
                                <div class="mt-2 text-sm font-semibold text-stone-900 dark:text-neutral-100">
                                    {{ emailPreviewSubject || 'No subject yet' }}
                                </div>
                                <div class="mt-1 text-xs leading-5 text-stone-500 dark:text-neutral-400">
                                    {{ emailPreviewPreheader || 'No preview text yet' }}
                                </div>
                            </div>
                        </div>
                        <div class="rounded-sm border border-stone-200 bg-stone-100 p-3 dark:border-neutral-700 dark:bg-neutral-800/60">
                            <div :class="previewViewportClass">
                                <iframe
                                    v-if="preview"
                                    class="min-h-[520px] w-full rounded-sm border border-stone-200 bg-white shadow-sm dark:border-neutral-700"
                                    :srcdoc="preview.body || ''"
                                    title="Email preview"
                                />
                                <div
                                    v-else
                                    class="flex min-h-[220px] items-center justify-center rounded-sm border border-dashed border-stone-300 bg-white px-4 py-6 text-center text-sm text-stone-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-400"
                                >
                                    Use preview to render the current email with sample customer and offer data.
                                </div>
                            </div>
                        </div>
                    </div>
                    <div v-else-if="preview" class="mt-3 whitespace-pre-wrap rounded-sm border border-stone-200 bg-stone-50 px-3 py-2 text-sm text-stone-700 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200">
                        {{ preview.body || preview.title || 'No preview body.' }}
                    </div>
                    <p v-else class="mt-3 text-xs text-stone-500 dark:text-neutral-400">
                        Use preview to render the current template with sample customer and offer data.
                    </p>
                    <div v-if="preview?.invalid_tokens?.length" class="mt-3 rounded-sm border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-700 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-300">
                        {{ t('marketing.template_manager.invalid_tokens', { tokens: preview.invalid_tokens.join(', ') }) }}
                    </div>
                </div>
            </div>
        </div>

        <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
            <div class="grid grid-cols-1 gap-3 md:grid-cols-[1.4fr_0.8fr_0.5fr]">
                <FloatingInput v-model="listSearch" label="Search templates" />
                <FloatingSelect
                    v-model="listChannel"
                    label="Channel filter"
                    :options="[{ value: '', label: 'All channels' }, ...channelOptions]"
                    option-value="value"
                    option-label="label"
                />
                <FloatingSelect
                    v-model="listPerPage"
                    :label="t('marketing.common.rows_per_page')"
                    :options="perPageOptions.map((value) => ({ value, label: String(value) }))"
                    option-value="value"
                    option-label="label"
                />
            </div>

            <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-3">
                <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800">
                    <p class="text-xs uppercase tracking-wide text-stone-500 dark:text-neutral-400">Templates</p>
                    <p class="mt-1 text-2xl font-semibold text-stone-800 dark:text-neutral-100">{{ listStats.total }}</p>
                </div>
                <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800">
                    <p class="text-xs uppercase tracking-wide text-stone-500 dark:text-neutral-400">Email layouts</p>
                    <p class="mt-1 text-2xl font-semibold text-stone-800 dark:text-neutral-100">{{ listStats.email }}</p>
                </div>
                <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800">
                    <p class="text-xs uppercase tracking-wide text-stone-500 dark:text-neutral-400">Default templates</p>
                    <p class="mt-1 text-2xl font-semibold text-stone-800 dark:text-neutral-100">{{ listStats.defaults }}</p>
                </div>
            </div>

            <div class="mt-4 grid grid-cols-1 gap-4 xl:grid-cols-2">
                <div
                    v-for="template in pagedRows"
                    :key="`template-${template.id}`"
                    class="rounded-sm border border-stone-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ template.name }}</p>
                            <div class="mt-1 flex flex-wrap gap-2">
                                <span class="rounded-full border border-stone-200 px-2 py-1 text-[11px] text-stone-600 dark:border-neutral-700 dark:text-neutral-300">{{ channelLabel(template.channel) }}</span>
                                <span class="rounded-full border border-stone-200 px-2 py-1 text-[11px] text-stone-600 dark:border-neutral-700 dark:text-neutral-300">{{ template.campaign_type ? campaignTypeLabel(template.campaign_type) : t('marketing.template_manager.all') }}</span>
                                <span class="rounded-full border border-stone-200 px-2 py-1 text-[11px] text-stone-600 dark:border-neutral-700 dark:text-neutral-300">{{ template.language || '-' }}</span>
                                <span v-if="template.is_default" class="rounded-full border border-emerald-200 bg-emerald-50 px-2 py-1 text-[11px] text-emerald-700 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300">{{ t('marketing.template_manager.default') }}</span>
                            </div>
                        </div>
                        <div class="flex flex-wrap items-center justify-end gap-2">
                            <SecondaryButton type="button" :disabled="busy" @click="edit(template)">
                                {{ t('marketing.common.edit') }}
                            </SecondaryButton>
                            <SecondaryButton type="button" :disabled="busy" @click="duplicateTemplate(template)">
                                Duplicate
                            </SecondaryButton>
                            <button
                                type="button"
                                class="rounded-sm border border-rose-200 bg-rose-50 px-2 py-1 text-xs text-rose-700 hover:bg-rose-100 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-300 dark:hover:bg-rose-500/20"
                                :disabled="busy"
                                @click="destroyTemplate(template)"
                            >
                                {{ t('marketing.common.delete') }}
                            </button>
                        </div>
                    </div>

                    <p class="mt-3 text-xs text-stone-500 dark:text-neutral-400">
                        {{ Array.isArray(template.tags) && template.tags.length ? template.tags.join(', ') : 'No tags' }}
                    </p>
                </div>
            </div>

            <div v-if="!isLoadingList && pagedRows.length === 0" class="mt-4 rounded-sm border border-dashed border-stone-300 px-4 py-8 text-center text-sm text-stone-500 dark:border-neutral-600 dark:text-neutral-400">
                {{ t('marketing.template_manager.no_template_found') }}
            </div>

            <div class="mt-4 flex flex-wrap items-center justify-between gap-2 text-xs text-stone-500 dark:text-neutral-400">
                <div>{{ t('marketing.common.results_count', { count: filteredRows.length }) }}</div>
                <div class="flex items-center gap-2">
                    <SecondaryButton type="button" :disabled="listPage <= 1" @click="listPage -= 1">
                        {{ t('marketing.common.previous') }}
                    </SecondaryButton>
                    <span>{{ t('marketing.common.page_of', { page: listPage, total: totalPages }) }}</span>
                    <SecondaryButton type="button" :disabled="listPage >= totalPages" @click="listPage += 1">
                        {{ t('marketing.common.next') }}
                    </SecondaryButton>
                </div>
            </div>
        </div>
    </div>
</template>
