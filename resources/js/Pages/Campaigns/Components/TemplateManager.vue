<script setup>
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import { useI18n } from 'vue-i18n';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import FloatingTextarea from '@/Components/FloatingTextarea.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';

const props = defineProps({
    enums: {
        type: Object,
        default: () => ({}),
    },
});

const { t } = useI18n();

const rows = ref([]);
const busy = ref(false);
const isLoadingList = ref(false);
const error = ref('');
const info = ref('');
const preview = ref(null);
const editingId = ref(null);
const listSearch = ref('');
const listPage = ref(1);
const listPerPage = ref(10);
const perPageOptions = [10, 25, 50];

const form = ref({
    name: '',
    channel: 'EMAIL',
    campaign_type: '',
    language: '',
    is_default: false,
    tags: '',
    subject: '',
    previewText: '',
    html: '',
    smsText: '',
    shortener: false,
    title: '',
    body: '',
    deepLink: '',
    image: '',
});

const campaignTypes = computed(() => Array.isArray(props.enums?.campaign_types) ? props.enums.campaign_types : []);
const channelOptions = [
    { value: 'EMAIL', label: 'EMAIL' },
    { value: 'SMS', label: 'SMS' },
    { value: 'IN_APP', label: 'IN_APP' },
];
const campaignTypeOptions = computed(() => ([
    { value: '', label: t('marketing.settings.offers.campaign_type_all') },
    ...campaignTypes.value.map((type) => ({
        value: type,
        label: type,
    })),
]));

const filteredRows = computed(() => {
    const query = String(listSearch.value || '').trim().toLowerCase();
    if (!query) {
        return rows.value;
    }

    return rows.value.filter((template) => {
        const haystack = [
            template?.name,
            template?.channel,
            template?.campaign_type,
            template?.language,
        ]
            .map((value) => String(value || '').toLowerCase())
            .join(' ');

        return haystack.includes(query);
    });
});

const totalPages = computed(() => {
    const total = filteredRows.value.length;
    return Math.max(1, Math.ceil(total / listPerPage.value));
});

const pagedRows = computed(() => {
    const start = (listPage.value - 1) * listPerPage.value;
    return filteredRows.value.slice(start, start + listPerPage.value);
});

const canGoPrevious = computed(() => listPage.value > 1);
const canGoNext = computed(() => listPage.value < totalPages.value);

watch([filteredRows, listPerPage], () => {
    listPage.value = 1;
});

watch(totalPages, (value) => {
    if (listPage.value > value) {
        listPage.value = value;
    }
});

const parseTags = (value) => {
    return String(value || '')
        .split(/[,\n;]+/)
        .map((item) => item.trim())
        .filter((item) => item !== '');
};

const buildContent = () => {
    const channel = String(form.value.channel || 'EMAIL').toUpperCase();
    if (channel === 'EMAIL') {
        return {
            subject: form.value.subject || '',
            previewText: form.value.previewText || '',
            html: form.value.html || '',
        };
    }
    if (channel === 'SMS') {
        return {
            text: form.value.smsText || '',
            shortener: Boolean(form.value.shortener),
        };
    }

    return {
        title: form.value.title || '',
        body: form.value.body || '',
        deepLink: form.value.deepLink || '',
        image: form.value.image || '',
    };
};

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
        subject: content.subject || '',
        previewText: content.previewText || content.preview_text || '',
        html: content.html || content.body || '',
        smsText: content.text || content.body || '',
        shortener: Boolean(content.shortener),
        title: content.title || '',
        body: content.body || '',
        deepLink: content.deepLink || content.deep_link || '',
        image: content.image || content.imageUrl || '',
    };
};

const resetForm = () => {
    editingId.value = null;
    preview.value = null;
    form.value = {
        name: '',
        channel: 'EMAIL',
        campaign_type: '',
        language: '',
        is_default: false,
        tags: '',
        subject: '',
        previewText: '',
        html: '',
        smsText: '',
        shortener: false,
        title: '',
        body: '',
        deepLink: '',
        image: '',
    };
};

const load = async () => {
    isLoadingList.value = true;
    error.value = '';
    try {
        const response = await axios.get(route('marketing.templates.index'));
        rows.value = Array.isArray(response.data?.templates) ? response.data.templates : [];
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

load();
</script>

<template>
    <div class="space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h3 class="inline-flex items-center gap-1.5 text-sm font-semibold text-stone-800 dark:text-neutral-100">
                <svg class="size-4 text-green-600 dark:text-green-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M4 4h16v16H4z" />
                    <path d="M8 8h8" />
                    <path d="M8 12h8" />
                    <path d="M8 16h5" />
                </svg>
                <span>{{ t('marketing.template_manager.title') }}</span>
            </h3>
            <SecondaryButton :disabled="busy || isLoadingList" @click="load">
                {{ t('marketing.common.reload') }}
            </SecondaryButton>
        </div>

        <div v-if="error" class="rounded-sm border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-700 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-300">
            {{ error }}
        </div>
        <div v-if="info" class="rounded-sm border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs text-emerald-700 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300">
            {{ info }}
        </div>

        <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800">
            <div class="grid grid-cols-1 gap-2 md:grid-cols-2">
                <FloatingInput
                    v-model="form.name"
                    :label="t('marketing.template_manager.template_name')"
                />
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
                <FloatingInput
                    v-model="form.language"
                    :label="t('marketing.template_manager.language')"
                />
                <FloatingInput
                    v-model="form.tags"
                    :label="t('marketing.template_manager.tags')"
                    class="md:col-span-2"
                />
                <label class="md:col-span-2 inline-flex items-center gap-2 text-xs text-stone-600 dark:text-neutral-300">
                    <input
                        v-model="form.is_default"
                        type="checkbox"
                        class="rounded border-stone-300 text-green-600 focus:ring-green-600"
                    >
                    <span>{{ t('marketing.template_manager.set_default') }}</span>
                </label>
            </div>

            <div v-if="form.channel === 'EMAIL'" class="mt-2 grid grid-cols-1 gap-2">
                <FloatingInput v-model="form.subject" :label="t('marketing.template_manager.subject')" />
                <FloatingInput v-model="form.previewText" :label="t('marketing.template_manager.preview_text')" />
                <FloatingTextarea v-model="form.html" :label="t('marketing.template_manager.html_body')" />
            </div>

            <div v-else-if="form.channel === 'SMS'" class="mt-2 grid grid-cols-1 gap-2">
                <FloatingTextarea v-model="form.smsText" :label="t('marketing.template_manager.sms_text')" />
                <label class="inline-flex items-center gap-2 text-xs text-stone-600 dark:text-neutral-300">
                    <input
                        v-model="form.shortener"
                        type="checkbox"
                        class="rounded border-stone-300 text-green-600 focus:ring-green-600"
                    >
                    <span>{{ t('marketing.template_manager.enable_shortener') }}</span>
                </label>
            </div>

            <div v-else class="mt-2 grid grid-cols-1 gap-2">
                <FloatingInput v-model="form.title" :label="t('marketing.template_manager.in_app_title')" />
                <FloatingTextarea v-model="form.body" :label="t('marketing.template_manager.in_app_body')" />
                <FloatingInput v-model="form.deepLink" :label="t('marketing.template_manager.deep_link')" />
                <FloatingInput v-model="form.image" :label="t('marketing.template_manager.image_url')" />
            </div>

            <div class="mt-2 flex flex-wrap items-center gap-2">
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

            <div v-if="preview" class="mt-2 rounded-sm border border-stone-200 bg-white p-2 text-xs dark:border-neutral-700 dark:bg-neutral-900">
                <div v-if="preview.subject"><span class="font-semibold">{{ t('marketing.template_manager.subject_label') }}</span> {{ preview.subject }}</div>
                <div v-if="preview.title"><span class="font-semibold">{{ t('marketing.template_manager.title_label') }}</span> {{ preview.title }}</div>
                <div class="mt-1 whitespace-pre-wrap"><span class="font-semibold">{{ t('marketing.template_manager.body_label') }}</span> {{ preview.body }}</div>
                <div v-if="preview.invalid_tokens?.length" class="mt-1 text-rose-600 dark:text-rose-300">
                    {{ t('marketing.template_manager.invalid_tokens', { tokens: preview.invalid_tokens.join(', ') }) }}
                </div>
            </div>
        </div>

        <div class="space-y-3 rounded-sm border border-stone-200 bg-white p-3 dark:border-neutral-700 dark:bg-neutral-900">
            <div class="grid grid-cols-1 gap-2 md:grid-cols-3">
                <FloatingInput v-model="listSearch" :label="t('marketing.template_manager.search_template')" />
                <FloatingSelect
                    v-model="listPerPage"
                    :label="t('marketing.common.rows_per_page')"
                    :options="perPageOptions.map((value) => ({ value, label: String(value) }))"
                    option-value="value"
                    option-label="label"
                />
            </div>
            <table class="min-w-full divide-y divide-stone-200 text-sm dark:divide-neutral-700">
                <thead>
                    <tr class="text-left text-xs uppercase text-stone-500 dark:text-neutral-400">
                        <th class="px-3 py-2 font-medium">{{ t('marketing.template_manager.name') }}</th>
                        <th class="px-3 py-2 font-medium">{{ t('marketing.template_manager.channel') }}</th>
                        <th class="px-3 py-2 font-medium">{{ t('marketing.template_manager.type') }}</th>
                        <th class="px-3 py-2 font-medium">{{ t('marketing.template_manager.default') }}</th>
                        <th class="px-3 py-2 font-medium text-right">{{ t('marketing.template_manager.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-200 dark:divide-neutral-700">
                    <template v-if="isLoadingList">
                        <tr v-for="row in 6" :key="`template-skeleton-${row}`">
                            <td v-for="col in 5" :key="`template-skeleton-${row}-${col}`" class="px-3 py-2">
                                <div class="h-3 w-full animate-pulse rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                            </td>
                        </tr>
                    </template>
                    <tr v-else-if="pagedRows.length === 0">
                        <td colspan="5" class="px-3 py-6 text-center text-xs text-stone-500 dark:text-neutral-400">
                            {{ t('marketing.template_manager.no_template_found') }}
                        </td>
                    </tr>
                    <tr v-for="template in pagedRows" :key="`template-${template.id}`">
                        <td class="px-3 py-2 text-stone-700 dark:text-neutral-200">
                            <div class="font-medium">{{ template.name }}</div>
                            <div class="text-xs text-stone-500 dark:text-neutral-400">{{ template.language || '-' }}</div>
                        </td>
                        <td class="px-3 py-2 text-stone-700 dark:text-neutral-200">{{ template.channel }}</td>
                        <td class="px-3 py-2 text-stone-700 dark:text-neutral-200">{{ template.campaign_type || t('marketing.template_manager.all') }}</td>
                        <td class="px-3 py-2 text-stone-700 dark:text-neutral-200">{{ template.is_default ? t('marketing.template_manager.yes') : t('marketing.template_manager.no') }}</td>
                        <td class="px-3 py-2">
                            <div class="flex items-center justify-end gap-2">
                                <button
                                    type="button"
                                    class="rounded-sm border border-stone-200 bg-white px-2 py-1 text-xs text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                                    :disabled="busy"
                                    @click="edit(template)"
                                >
                                    {{ t('marketing.common.edit') }}
                                </button>
                                <button
                                    type="button"
                                    class="rounded-sm border border-rose-200 bg-rose-50 px-2 py-1 text-xs text-rose-700 hover:bg-rose-100 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-300 dark:hover:bg-rose-500/20"
                                    :disabled="busy"
                                    @click="destroyTemplate(template)"
                                >
                                    {{ t('marketing.common.delete') }}
                                </button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>

            <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-stone-500 dark:text-neutral-400">
                <div>{{ t('marketing.common.results_count', { count: filteredRows.length }) }}</div>
                <div class="flex items-center gap-2">
                    <SecondaryButton type="button" :disabled="!canGoPrevious" @click="listPage -= 1">
                        {{ t('marketing.common.previous') }}
                    </SecondaryButton>
                    <span>{{ t('marketing.common.page_of', { page: listPage, total: totalPages }) }}</span>
                    <SecondaryButton type="button" :disabled="!canGoNext" @click="listPage += 1">
                        {{ t('marketing.common.next') }}
                    </SecondaryButton>
                </div>
            </div>
        </div>
    </div>
</template>
