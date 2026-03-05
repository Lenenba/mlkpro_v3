<script setup>
import { computed, ref } from 'vue';
import axios from 'axios';

const props = defineProps({
    enums: {
        type: Object,
        default: () => ({}),
    },
});

const rows = ref([]);
const busy = ref(false);
const error = ref('');
const info = ref('');
const preview = ref(null);
const editingId = ref(null);

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
    busy.value = true;
    error.value = '';
    try {
        const response = await axios.get(route('marketing.templates.index'));
        rows.value = Array.isArray(response.data?.templates) ? response.data.templates : [];
    } catch (requestError) {
        error.value = requestError?.response?.data?.message || requestError?.message || 'Unable to load templates.';
    } finally {
        busy.value = false;
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
            info.value = 'Template updated.';
        } else {
            await axios.post(route('marketing.templates.store'), payload);
            info.value = 'Template created.';
        }

        resetForm();
        await load();
    } catch (requestError) {
        error.value = requestError?.response?.data?.message || requestError?.message || 'Unable to save template.';
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
    if (!confirm(`Delete template "${template.name}"?`)) {
        return;
    }

    busy.value = true;
    error.value = '';
    info.value = '';
    try {
        await axios.delete(route('marketing.templates.destroy', template.id));
        info.value = 'Template deleted.';
        await load();
    } catch (requestError) {
        error.value = requestError?.response?.data?.message || requestError?.message || 'Unable to delete template.';
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
        error.value = requestError?.response?.data?.message || requestError?.message || 'Unable to preview template.';
    } finally {
        busy.value = false;
    }
};

load();
</script>

<template>
    <div class="space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h3 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">Templates</h3>
            <button
                type="button"
                class="rounded-sm border border-stone-200 bg-white px-2 py-1 text-xs font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                :disabled="busy"
                @click="load"
            >
                Reload
            </button>
        </div>

        <div v-if="error" class="rounded-sm border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-700 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-300">
            {{ error }}
        </div>
        <div v-if="info" class="rounded-sm border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs text-emerald-700 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300">
            {{ info }}
        </div>

        <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800">
            <div class="grid grid-cols-1 gap-2 md:grid-cols-2">
                <input
                    v-model="form.name"
                    type="text"
                    placeholder="Template name"
                    class="w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                >
                <select
                    v-model="form.channel"
                    class="w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                >
                    <option value="EMAIL">EMAIL</option>
                    <option value="SMS">SMS</option>
                    <option value="IN_APP">IN_APP</option>
                </select>
                <select
                    v-model="form.campaign_type"
                    class="w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                >
                    <option value="">All campaign types</option>
                    <option v-for="type in campaignTypes" :key="`tpl-type-${type}`" :value="type">{{ type }}</option>
                </select>
                <input
                    v-model="form.language"
                    type="text"
                    placeholder="Language (FR/EN)"
                    class="w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                >
                <input
                    v-model="form.tags"
                    type="text"
                    placeholder="Tags: promo, vip"
                    class="md:col-span-2 w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                >
                <label class="md:col-span-2 inline-flex items-center gap-2 text-xs text-stone-600 dark:text-neutral-300">
                    <input
                        v-model="form.is_default"
                        type="checkbox"
                        class="rounded border-stone-300 text-green-600 focus:ring-green-600"
                    >
                    <span>Set as default for this channel/type/language</span>
                </label>
            </div>

            <div v-if="form.channel === 'EMAIL'" class="mt-2 grid grid-cols-1 gap-2">
                <input v-model="form.subject" type="text" placeholder="Subject" class="w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200">
                <input v-model="form.previewText" type="text" placeholder="Preview text" class="w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200">
                <textarea v-model="form.html" rows="5" placeholder="HTML body" class="w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
            </div>

            <div v-else-if="form.channel === 'SMS'" class="mt-2 grid grid-cols-1 gap-2">
                <textarea v-model="form.smsText" rows="4" placeholder="SMS text" class="w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                <label class="inline-flex items-center gap-2 text-xs text-stone-600 dark:text-neutral-300">
                    <input
                        v-model="form.shortener"
                        type="checkbox"
                        class="rounded border-stone-300 text-green-600 focus:ring-green-600"
                    >
                    <span>Enable URL shortener option</span>
                </label>
            </div>

            <div v-else class="mt-2 grid grid-cols-1 gap-2">
                <input v-model="form.title" type="text" placeholder="In-app title" class="w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200">
                <textarea v-model="form.body" rows="4" placeholder="In-app body" class="w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                <input v-model="form.deepLink" type="text" placeholder="Deep link" class="w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200">
                <input v-model="form.image" type="text" placeholder="Image URL" class="w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200">
            </div>

            <div class="mt-2 flex flex-wrap items-center gap-2">
                <button
                    type="button"
                    class="rounded-sm border border-transparent bg-green-600 px-2 py-1 text-xs font-semibold text-white hover:bg-green-700 disabled:opacity-60"
                    :disabled="busy"
                    @click="save"
                >
                    {{ editingId ? 'Update template' : 'Create template' }}
                </button>
                <button
                    type="button"
                    class="rounded-sm border border-stone-200 bg-white px-2 py-1 text-xs font-semibold text-stone-700 hover:bg-stone-50 disabled:opacity-60 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                    :disabled="busy"
                    @click="previewTemplate"
                >
                    Preview
                </button>
                <button
                    type="button"
                    class="rounded-sm border border-stone-200 bg-white px-2 py-1 text-xs font-semibold text-stone-700 hover:bg-stone-50 disabled:opacity-60 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                    :disabled="busy"
                    @click="resetForm"
                >
                    Reset
                </button>
            </div>

            <div v-if="preview" class="mt-2 rounded-sm border border-stone-200 bg-white p-2 text-xs dark:border-neutral-700 dark:bg-neutral-900">
                <div v-if="preview.subject"><span class="font-semibold">Subject:</span> {{ preview.subject }}</div>
                <div v-if="preview.title"><span class="font-semibold">Title:</span> {{ preview.title }}</div>
                <div class="mt-1 whitespace-pre-wrap"><span class="font-semibold">Body:</span> {{ preview.body }}</div>
                <div v-if="preview.invalid_tokens?.length" class="mt-1 text-rose-600 dark:text-rose-300">
                    Invalid tokens: {{ preview.invalid_tokens.join(', ') }}
                </div>
            </div>
        </div>

        <div class="rounded-sm border border-stone-200 bg-white dark:border-neutral-700 dark:bg-neutral-900">
            <table class="min-w-full divide-y divide-stone-200 text-sm dark:divide-neutral-700">
                <thead>
                    <tr class="text-left text-xs uppercase text-stone-500 dark:text-neutral-400">
                        <th class="px-3 py-2 font-medium">Name</th>
                        <th class="px-3 py-2 font-medium">Channel</th>
                        <th class="px-3 py-2 font-medium">Type</th>
                        <th class="px-3 py-2 font-medium">Default</th>
                        <th class="px-3 py-2 font-medium text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-200 dark:divide-neutral-700">
                    <tr v-if="rows.length === 0">
                        <td colspan="5" class="px-3 py-6 text-center text-xs text-stone-500 dark:text-neutral-400">
                            No template found.
                        </td>
                    </tr>
                    <tr v-for="template in rows" :key="`template-${template.id}`">
                        <td class="px-3 py-2 text-stone-700 dark:text-neutral-200">
                            <div class="font-medium">{{ template.name }}</div>
                            <div class="text-xs text-stone-500 dark:text-neutral-400">{{ template.language || '-' }}</div>
                        </td>
                        <td class="px-3 py-2 text-stone-700 dark:text-neutral-200">{{ template.channel }}</td>
                        <td class="px-3 py-2 text-stone-700 dark:text-neutral-200">{{ template.campaign_type || 'ALL' }}</td>
                        <td class="px-3 py-2 text-stone-700 dark:text-neutral-200">{{ template.is_default ? 'Yes' : 'No' }}</td>
                        <td class="px-3 py-2">
                            <div class="flex items-center justify-end gap-2">
                                <button
                                    type="button"
                                    class="rounded-sm border border-stone-200 bg-white px-2 py-1 text-xs text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                                    :disabled="busy"
                                    @click="edit(template)"
                                >
                                    Edit
                                </button>
                                <button
                                    type="button"
                                    class="rounded-sm border border-rose-200 bg-rose-50 px-2 py-1 text-xs text-rose-700 hover:bg-rose-100 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-300 dark:hover:bg-rose-500/20"
                                    :disabled="busy"
                                    @click="destroyTemplate(template)"
                                >
                                    Delete
                                </button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>

