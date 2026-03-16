<script setup>
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import { useI18n } from 'vue-i18n';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import FloatingTextarea from '@/Components/FloatingTextarea.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import EmailTemplateBuilder from '@/Pages/Campaigns/Components/EmailTemplateBuilder.vue';

const props = defineProps({
    enums: {
        type: Object,
        default: () => ({}),
    },
});

const { t } = useI18n();

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
const editingId = ref(null);
const listSearch = ref('');
const listChannel = ref('');
const listPage = ref(1);
const listPerPage = ref(8);
const perPageOptions = [8, 16, 24];

const form = ref(createDefaultForm());

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

const fillFromTemplate = (template) => {
    const content = template?.content || {};
    const channel = String(template?.channel || 'EMAIL').toUpperCase();

    form.value = {
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
    };
};

const resetForm = () => {
    editingId.value = null;
    preview.value = null;
    form.value = createDefaultForm();
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

        resetForm();
        await load();
    } catch (requestError) {
        error.value = requestError?.response?.data?.message || requestError?.message || t('marketing.template_manager.error_save');
    } finally {
        busy.value = false;
    }
};

const edit = (template) => {
    editingId.value = Number(template.id);
    fillFromTemplate(template);
    preview.value = null;
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

const listStats = computed(() => ({
    total: rows.value.length,
    email: rows.value.filter((template) => String(template?.channel || '').toUpperCase() === 'EMAIL').length,
    defaults: rows.value.filter((template) => Boolean(template?.is_default)).length,
}));

watch([listSearch, listChannel, listPerPage], () => {
    listPage.value = 1;
});

watch(totalPages, (value) => {
    if (listPage.value > value) {
        listPage.value = value;
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
                            <FloatingInput v-model="form.emailContent.subject" :label="t('marketing.template_manager.subject')" />
                            <FloatingInput v-model="form.emailContent.previewText" :label="t('marketing.template_manager.preview_text')" />
                        </div>
                        <div class="mt-4">
                            <EmailTemplateBuilder
                                v-model="form.emailContent"
                                :presets="presets"
                                :block-library="blockLibrary"
                                :supported-tokens="supportedTokens"
                                :language="form.language"
                                :brand-profile="brandProfile"
                            />
                        </div>
                    </div>

                    <div v-else-if="form.channel === 'SMS'" class="grid grid-cols-1 gap-3">
                        <FloatingTextarea v-model="form.smsContent.text" :label="t('marketing.template_manager.sms_text')" />
                        <label class="inline-flex items-center gap-2 text-xs text-stone-600 dark:text-neutral-300">
                            <input v-model="form.smsContent.shortener" type="checkbox" class="rounded border-stone-300 text-green-600 focus:ring-green-600">
                            <span>{{ t('marketing.template_manager.enable_shortener') }}</span>
                        </label>
                    </div>

                    <div v-else class="grid grid-cols-1 gap-3 md:grid-cols-2">
                        <FloatingInput v-model="form.inAppContent.title" :label="t('marketing.template_manager.in_app_title')" />
                        <FloatingInput v-model="form.inAppContent.deepLink" :label="t('marketing.template_manager.deep_link')" />
                        <FloatingTextarea v-model="form.inAppContent.body" :label="t('marketing.template_manager.in_app_body')" class="md:col-span-2" />
                        <FloatingInput v-model="form.inAppContent.image" :label="t('marketing.template_manager.image_url')" class="md:col-span-2" />
                    </div>
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
                    <div v-if="preview && form.channel === 'EMAIL'" class="mt-3 space-y-3">
                        <div class="rounded-sm border border-stone-200 bg-stone-50 px-3 py-2 text-xs text-stone-700 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200">
                            <div v-if="preview.subject"><strong>{{ t('marketing.template_manager.subject_label') }}</strong> {{ preview.subject }}</div>
                        </div>
                        <iframe
                            class="min-h-[520px] w-full rounded-sm border border-stone-200 bg-white dark:border-neutral-700"
                            :srcdoc="preview.body || ''"
                            title="Email preview"
                        />
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
