<script setup>
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import { useI18n } from 'vue-i18n';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingTextarea from '@/Components/FloatingTextarea.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';

const props = defineProps({
    segments: {
        type: Array,
        default: () => [],
    },
});

const emit = defineEmits(['updated']);
const { t } = useI18n();

const rows = ref(Array.isArray(props.segments) ? props.segments : []);
const busy = ref(false);
const isLoadingList = ref(false);
const error = ref('');
const info = ref('');
const editingId = ref(null);
const listSearch = ref('');
const listPage = ref(1);
const listPerPage = ref(10);
const perPageOptions = [10, 25, 50];

const form = ref({
    name: '',
    description: '',
    filters: '{\n  "operator": "AND",\n  "rules": []\n}',
    exclusions: '{\n  "operator": "AND",\n  "rules": []\n}',
    tags: '',
});

const parseJson = (raw, fallback) => {
    const text = String(raw || '').trim();
    if (!text) {
        return fallback;
    }
    try {
        return JSON.parse(text);
    } catch {
        return fallback;
    }
};

const parseTags = (input) => {
    return String(input || '')
        .split(/[,\n;]+/)
        .map((value) => value.trim())
        .filter((value) => value !== '');
};

const filteredRows = computed(() => {
    const query = String(listSearch.value || '').trim().toLowerCase();
    if (!query) {
        return rows.value;
    }

    return rows.value.filter((segment) => {
        const haystack = [
            segment?.name,
            segment?.description,
            segment?.cached_count,
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

const resetForm = () => {
    editingId.value = null;
    form.value = {
        name: '',
        description: '',
        filters: '{\n  "operator": "AND",\n  "rules": []\n}',
        exclusions: '{\n  "operator": "AND",\n  "rules": []\n}',
        tags: '',
    };
};

const load = async () => {
    isLoadingList.value = true;
    error.value = '';
    try {
        const response = await axios.get(route('marketing.segments.index'));
        rows.value = Array.isArray(response.data?.segments) ? response.data.segments : [];
        emit('updated', rows.value);
    } catch (requestError) {
        error.value = requestError?.response?.data?.message || requestError?.message || t('marketing.segment_manager.error_load');
    } finally {
        isLoadingList.value = false;
    }
};

const edit = (segment) => {
    editingId.value = Number(segment.id);
    form.value = {
        name: segment.name || '',
        description: segment.description || '',
        filters: JSON.stringify(segment.filters || { operator: 'AND', rules: [] }, null, 2),
        exclusions: JSON.stringify(segment.exclusions || { operator: 'AND', rules: [] }, null, 2),
        tags: Array.isArray(segment.tags) ? segment.tags.join(', ') : '',
    };
    info.value = '';
    error.value = '';
};

const save = async () => {
    busy.value = true;
    error.value = '';
    info.value = '';
    try {
        const payload = {
            name: String(form.value.name || '').trim(),
            description: String(form.value.description || '').trim() || null,
            filters: parseJson(form.value.filters, { operator: 'AND', rules: [] }),
            exclusions: parseJson(form.value.exclusions, { operator: 'AND', rules: [] }),
            tags: parseTags(form.value.tags),
        };

        if (editingId.value) {
            await axios.put(route('marketing.segments.update', editingId.value), payload);
            info.value = t('marketing.segment_manager.info_updated');
        } else {
            await axios.post(route('marketing.segments.store'), payload);
            info.value = t('marketing.segment_manager.info_created');
        }

        resetForm();
        await load();
    } catch (requestError) {
        error.value = requestError?.response?.data?.message || requestError?.message || t('marketing.segment_manager.error_save');
    } finally {
        busy.value = false;
    }
};

const destroySegment = async (segment) => {
    if (!confirm(t('marketing.segment_manager.confirm_delete', { name: segment.name }))) {
        return;
    }

    busy.value = true;
    error.value = '';
    info.value = '';
    try {
        await axios.delete(route('marketing.segments.destroy', segment.id));
        info.value = t('marketing.segment_manager.info_deleted');
        await load();
    } catch (requestError) {
        error.value = requestError?.response?.data?.message || requestError?.message || t('marketing.segment_manager.error_delete');
    } finally {
        busy.value = false;
    }
};

const previewCount = async () => {
    busy.value = true;
    error.value = '';
    info.value = '';
    try {
        const response = await axios.post(route('marketing.segments.preview-count'), {
            filters: parseJson(form.value.filters, { operator: 'AND', rules: [] }),
            exclusions: parseJson(form.value.exclusions, { operator: 'AND', rules: [] }),
        });
        const total = Number(response.data?.counts?.total_eligible || 0);
        info.value = t('marketing.segment_manager.info_eligible_contacts', { count: total });
    } catch (requestError) {
        error.value = requestError?.response?.data?.message || requestError?.message || t('marketing.segment_manager.error_preview');
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
                    <path d="M3 3h7v7H3z" />
                    <path d="M14 3h7v7h-7z" />
                    <path d="M3 14h7v7H3z" />
                    <path d="M14 14h7v7h-7z" />
                </svg>
                <span>{{ t('marketing.segment_manager.title') }}</span>
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
                    :label="t('marketing.segment_manager.segment_name')"
                />
                <FloatingInput
                    v-model="form.description"
                    :label="t('marketing.segment_manager.description')"
                />
                <FloatingTextarea
                    v-model="form.filters"
                    :label="t('marketing.segment_manager.filters_json')"
                    class="font-mono text-xs"
                />
                <FloatingTextarea
                    v-model="form.exclusions"
                    :label="t('marketing.segment_manager.exclusions_json')"
                    class="font-mono text-xs"
                />
                <FloatingInput
                    v-model="form.tags"
                    :label="t('marketing.segment_manager.tags')"
                    class="md:col-span-2"
                />
            </div>
            <div class="mt-2 flex flex-wrap items-center gap-2">
                <PrimaryButton type="button" :disabled="busy" @click="save">
                    {{ editingId ? t('marketing.segment_manager.update_segment') : t('marketing.segment_manager.create_segment') }}
                </PrimaryButton>
                <SecondaryButton type="button" :disabled="busy" @click="previewCount">
                    {{ t('marketing.segment_manager.preview_count') }}
                </SecondaryButton>
                <SecondaryButton type="button" :disabled="busy" @click="resetForm">
                    {{ t('marketing.common.reset') }}
                </SecondaryButton>
            </div>
        </div>

        <div class="space-y-3 rounded-sm border border-stone-200 bg-white p-3 dark:border-neutral-700 dark:bg-neutral-900">
            <div class="grid grid-cols-1 gap-2 md:grid-cols-3">
                <FloatingInput v-model="listSearch" :label="t('marketing.segment_manager.search_segment')" />
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
                        <th class="px-3 py-2 font-medium">{{ t('marketing.segment_manager.eligible_cache') }}</th>
                        <th class="px-3 py-2 font-medium">{{ t('marketing.segment_manager.updated') }}</th>
                        <th class="px-3 py-2 font-medium text-right">{{ t('marketing.template_manager.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-200 dark:divide-neutral-700">
                    <template v-if="isLoadingList">
                        <tr v-for="row in 6" :key="`segment-skeleton-${row}`">
                            <td v-for="col in 4" :key="`segment-skeleton-${row}-${col}`" class="px-3 py-2">
                                <div class="h-3 w-full animate-pulse rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                            </td>
                        </tr>
                    </template>
                    <tr v-else-if="pagedRows.length === 0">
                        <td colspan="4" class="px-3 py-6 text-center text-xs text-stone-500 dark:text-neutral-400">
                            {{ t('marketing.segment_manager.no_segment_found') }}
                        </td>
                    </tr>
                    <tr v-for="segment in pagedRows" :key="`segment-${segment.id}`">
                        <td class="px-3 py-2 text-stone-700 dark:text-neutral-200">
                            <div class="font-medium">{{ segment.name }}</div>
                            <div class="text-xs text-stone-500 dark:text-neutral-400">{{ segment.description || '-' }}</div>
                        </td>
                        <td class="px-3 py-2 text-stone-700 dark:text-neutral-200">{{ segment.cached_count ?? 0 }}</td>
                        <td class="px-3 py-2 text-stone-600 dark:text-neutral-300">{{ segment.updated_at || '-' }}</td>
                        <td class="px-3 py-2">
                            <div class="flex items-center justify-end gap-2">
                                <button
                                    type="button"
                                    class="rounded-sm border border-stone-200 bg-white px-2 py-1 text-xs text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                                    :disabled="busy"
                                    @click="edit(segment)"
                                >
                                    {{ t('marketing.common.edit') }}
                                </button>
                                <button
                                    type="button"
                                    class="rounded-sm border border-rose-200 bg-rose-50 px-2 py-1 text-xs text-rose-700 hover:bg-rose-100 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-300 dark:hover:bg-rose-500/20"
                                    :disabled="busy"
                                    @click="destroySegment(segment)"
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
