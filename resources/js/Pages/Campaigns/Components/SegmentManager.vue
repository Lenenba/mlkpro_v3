<script setup>
import { ref } from 'vue';
import axios from 'axios';

const props = defineProps({
    segments: {
        type: Array,
        default: () => [],
    },
});

const emit = defineEmits(['updated']);

const rows = ref(Array.isArray(props.segments) ? props.segments : []);
const busy = ref(false);
const error = ref('');
const info = ref('');
const editingId = ref(null);

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
    busy.value = true;
    error.value = '';
    try {
        const response = await axios.get(route('marketing.segments.index'));
        rows.value = Array.isArray(response.data?.segments) ? response.data.segments : [];
        emit('updated', rows.value);
    } catch (requestError) {
        error.value = requestError?.response?.data?.message || requestError?.message || 'Unable to load segments.';
    } finally {
        busy.value = false;
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
            info.value = 'Segment updated.';
        } else {
            await axios.post(route('marketing.segments.store'), payload);
            info.value = 'Segment created.';
        }

        resetForm();
        await load();
    } catch (requestError) {
        error.value = requestError?.response?.data?.message || requestError?.message || 'Unable to save segment.';
    } finally {
        busy.value = false;
    }
};

const destroySegment = async (segment) => {
    if (!confirm(`Delete segment "${segment.name}"?`)) {
        return;
    }

    busy.value = true;
    error.value = '';
    info.value = '';
    try {
        await axios.delete(route('marketing.segments.destroy', segment.id));
        info.value = 'Segment deleted.';
        await load();
    } catch (requestError) {
        error.value = requestError?.response?.data?.message || requestError?.message || 'Unable to delete segment.';
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
        info.value = `Eligible contacts: ${total}`;
    } catch (requestError) {
        error.value = requestError?.response?.data?.message || requestError?.message || 'Unable to preview count.';
    } finally {
        busy.value = false;
    }
};
</script>

<template>
    <div class="space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h3 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">Segments</h3>
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
                    placeholder="Segment name"
                    class="w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                >
                <input
                    v-model="form.description"
                    type="text"
                    placeholder="Description"
                    class="w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                >
                <textarea
                    v-model="form.filters"
                    rows="6"
                    class="w-full rounded-sm border-stone-200 font-mono text-xs focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                    placeholder="Filters JSON"
                />
                <textarea
                    v-model="form.exclusions"
                    rows="6"
                    class="w-full rounded-sm border-stone-200 font-mono text-xs focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                    placeholder="Exclusions JSON"
                />
                <input
                    v-model="form.tags"
                    type="text"
                    placeholder="Tags: vip, reactivation"
                    class="md:col-span-2 w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                >
            </div>
            <div class="mt-2 flex flex-wrap items-center gap-2">
                <button
                    type="button"
                    class="rounded-sm border border-transparent bg-green-600 px-2 py-1 text-xs font-semibold text-white hover:bg-green-700 disabled:opacity-60"
                    :disabled="busy"
                    @click="save"
                >
                    {{ editingId ? 'Update segment' : 'Create segment' }}
                </button>
                <button
                    type="button"
                    class="rounded-sm border border-stone-200 bg-white px-2 py-1 text-xs font-semibold text-stone-700 hover:bg-stone-50 disabled:opacity-60 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                    :disabled="busy"
                    @click="previewCount"
                >
                    Preview count
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
        </div>

        <div class="rounded-sm border border-stone-200 bg-white dark:border-neutral-700 dark:bg-neutral-900">
            <table class="min-w-full divide-y divide-stone-200 text-sm dark:divide-neutral-700">
                <thead>
                    <tr class="text-left text-xs uppercase text-stone-500 dark:text-neutral-400">
                        <th class="px-3 py-2 font-medium">Name</th>
                        <th class="px-3 py-2 font-medium">Eligible cache</th>
                        <th class="px-3 py-2 font-medium">Updated</th>
                        <th class="px-3 py-2 font-medium text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-200 dark:divide-neutral-700">
                    <tr v-if="rows.length === 0">
                        <td colspan="4" class="px-3 py-6 text-center text-xs text-stone-500 dark:text-neutral-400">
                            No segment found.
                        </td>
                    </tr>
                    <tr v-for="segment in rows" :key="`segment-${segment.id}`">
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
                                    Edit
                                </button>
                                <button
                                    type="button"
                                    class="rounded-sm border border-rose-200 bg-rose-50 px-2 py-1 text-xs text-rose-700 hover:bg-rose-100 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-300 dark:hover:bg-rose-500/20"
                                    :disabled="busy"
                                    @click="destroySegment(segment)"
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

