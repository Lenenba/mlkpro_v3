<script setup>
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import { Link } from '@inertiajs/vue3';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    module: {
        type: String,
        required: true,
    },
    segments: {
        type: Array,
        default: () => [],
    },
    canManage: {
        type: Boolean,
        default: false,
    },
    currentFilters: {
        type: Object,
        default: () => ({}),
    },
    currentSort: {
        type: Object,
        default: () => ({}),
    },
    currentSearchTerm: {
        type: String,
        default: '',
    },
    i18nPrefix: {
        type: String,
        required: true,
    },
    historyHref: {
        type: String,
        default: '',
    },
    historyLabel: {
        type: String,
        default: '',
    },
});

const emit = defineEmits(['apply']);
const { t } = useI18n();

const rows = ref([]);
const selectedId = ref('');
const segmentName = ref('');
const busy = ref(false);
const error = ref('');
const info = ref('');

const normalizeSegments = (segments) => (Array.isArray(segments) ? segments : [])
    .map((segment) => ({
        ...segment,
        id: segment?.id,
        name: String(segment?.name || ''),
        filters: segment?.filters && typeof segment.filters === 'object' ? segment.filters : {},
        sort: segment?.sort && typeof segment.sort === 'object' ? segment.sort : {},
        search_term: String(segment?.search_term || ''),
        cached_count: Number(segment?.cached_count || 0),
        updated_at: segment?.updated_at || null,
    }));

const syncRows = (segments) => {
    const normalized = normalizeSegments(segments);
    const hasSelected = normalized.some((segment) => String(segment.id) === String(selectedId.value));

    rows.value = normalized;
    if (!hasSelected) {
        selectedId.value = '';
    }
};

watch(() => props.segments, (segments) => {
    syncRows(segments);
}, { immediate: true });

const key = (suffix) => `${props.i18nPrefix}.saved_segments.${suffix}`;
const segmentOptions = computed(() => rows.value.map((segment) => ({
    id: String(segment.id),
    name: segment.name,
})));
const selectedSegment = computed(() =>
    rows.value.find((segment) => String(segment.id) === String(selectedId.value)) || null
);
const hasSegments = computed(() => rows.value.length > 0);
const showBar = computed(() => props.canManage || hasSegments.value);
const snapshotReady = computed(() => {
    const hasFilters = Object.keys(props.currentFilters || {}).length > 0;
    const hasSort = Object.keys(props.currentSort || {}).length > 0;
    const hasSearch = String(props.currentSearchTerm || '').trim() !== '';

    return hasFilters || hasSort || hasSearch;
});

watch(selectedSegment, (segment) => {
    if (!props.canManage) {
        return;
    }

    segmentName.value = segment?.name || '';
}, { immediate: true });

const formatUpdatedAt = (value) => {
    if (!value) {
        return null;
    }

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return null;
    }

    return date.toLocaleString();
};

const resolveError = (requestError, fallbackKey) => {
    const data = requestError?.response?.data;
    const validationErrors = data?.errors && typeof data.errors === 'object'
        ? Object.values(data.errors).flat().filter(Boolean)
        : [];

    return validationErrors[0]
        || data?.message
        || requestError?.message
        || t(key(fallbackKey));
};

const buildPayload = () => ({
    module: props.module,
    name: String(segmentName.value || '').trim(),
    filters: props.currentFilters && typeof props.currentFilters === 'object' ? props.currentFilters : {},
    sort: props.currentSort && typeof props.currentSort === 'object' ? props.currentSort : {},
    search_term: String(props.currentSearchTerm || '').trim() || null,
    is_shared: false,
});

const load = async () => {
    if (!props.canManage) {
        return;
    }

    const previousSelection = selectedId.value;

    try {
        const response = await axios.get(route('crm.saved-segments.index'), {
            params: {
                module: props.module,
            },
            headers: {
                Accept: 'application/json',
            },
        });

        syncRows(response.data?.segments);
        if (previousSelection && rows.value.some((segment) => String(segment.id) === String(previousSelection))) {
            selectedId.value = previousSelection;
        }
    } catch (requestError) {
        error.value = resolveError(requestError, 'error_load');
    }
};

const applySelected = () => {
    if (!selectedSegment.value) {
        return;
    }

    error.value = '';
    info.value = '';
    emit('apply', selectedSegment.value);
};

const saveCurrent = async () => {
    if (!props.canManage) {
        return;
    }

    busy.value = true;
    error.value = '';
    info.value = '';

    try {
        const response = await axios.post(route('crm.saved-segments.store'), buildPayload(), {
            headers: {
                Accept: 'application/json',
            },
        });

        info.value = response.data?.message || t(key('info_created'));
        await load();
        if (response.data?.segment?.id) {
            selectedId.value = String(response.data.segment.id);
        }
    } catch (requestError) {
        error.value = resolveError(requestError, 'error_save');
    } finally {
        busy.value = false;
    }
};

const updateSelected = async () => {
    if (!props.canManage || !selectedSegment.value) {
        return;
    }

    busy.value = true;
    error.value = '';
    info.value = '';

    try {
        const response = await axios.put(
            route('crm.saved-segments.update', selectedSegment.value.id),
            buildPayload(),
            {
                headers: {
                    Accept: 'application/json',
                },
            },
        );

        info.value = response.data?.message || t(key('info_updated'));
        await load();
    } catch (requestError) {
        error.value = resolveError(requestError, 'error_save');
    } finally {
        busy.value = false;
    }
};

const destroySelected = async () => {
    if (!props.canManage || !selectedSegment.value) {
        return;
    }

    if (!window.confirm(t(key('confirm_delete'), { name: selectedSegment.value.name }))) {
        return;
    }

    busy.value = true;
    error.value = '';
    info.value = '';

    try {
        const response = await axios.delete(route('crm.saved-segments.destroy', selectedSegment.value.id), {
            headers: {
                Accept: 'application/json',
            },
        });

        selectedId.value = '';
        segmentName.value = '';
        info.value = response.data?.message || t(key('info_deleted'));
        await load();
    } catch (requestError) {
        error.value = resolveError(requestError, 'error_delete');
    } finally {
        busy.value = false;
    }
};
</script>

<template>
    <div
        v-if="showBar"
        class="rounded-sm border border-stone-200 bg-stone-50/80 p-3 dark:border-neutral-700 dark:bg-neutral-900/60"
        :data-testid="`saved-segment-bar-${module}`"
    >
        <div class="flex flex-col gap-3 xl:flex-row xl:items-end">
            <div class="grid flex-1 gap-2 md:grid-cols-[minmax(0,1fr)_minmax(0,240px)]">
                <FloatingSelect
                    v-model="selectedId"
                    :label="t(key('select_label'))"
                    :options="segmentOptions"
                    :placeholder="t(key('select_placeholder'))"
                    :disabled="busy || !hasSegments"
                    :data-testid="`saved-segment-select-${module}`"
                    filterable
                    dense
                />
                <FloatingInput
                    v-if="canManage"
                    v-model="segmentName"
                    :label="t(key('name_label'))"
                    :placeholder="t(key('name_placeholder'))"
                    :disabled="busy"
                    :data-testid="`saved-segment-name-${module}`"
                />
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <button
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-medium text-stone-700 hover:bg-stone-50 disabled:pointer-events-none disabled:opacity-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                    :disabled="busy || !selectedSegment"
                    :data-testid="`saved-segment-apply-${module}`"
                    @click="applySelected"
                >
                    {{ t(key('apply')) }}
                </button>
                <Link
                    v-if="historyHref"
                    class="inline-flex items-center gap-1.5 rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-medium text-stone-700 hover:bg-stone-50 disabled:pointer-events-none disabled:opacity-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                    :href="historyHref"
                    :data-testid="`saved-segment-history-${module}`"
                >
                    {{ historyLabel || t('marketing.playbook_runs.actions.open_history') }}
                </Link>
                <button
                    v-if="canManage"
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-sm border border-transparent bg-green-600 px-3 py-2 text-xs font-medium text-white hover:bg-green-700 disabled:pointer-events-none disabled:opacity-50"
                    :disabled="busy || !snapshotReady"
                    :data-testid="`saved-segment-save-${module}`"
                    @click="saveCurrent"
                >
                    {{ t(key('save_current')) }}
                </button>
                <button
                    v-if="canManage"
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-medium text-stone-700 hover:bg-stone-50 disabled:pointer-events-none disabled:opacity-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                    :disabled="busy || !selectedSegment || !snapshotReady"
                    :data-testid="`saved-segment-update-${module}`"
                    @click="updateSelected"
                >
                    {{ t(key('update_selected')) }}
                </button>
                <button
                    v-if="canManage"
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-sm border border-rose-200 bg-rose-50 px-3 py-2 text-xs font-medium text-rose-700 hover:bg-rose-100 disabled:pointer-events-none disabled:opacity-50 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-300 dark:hover:bg-rose-500/20"
                    :disabled="busy || !selectedSegment"
                    :data-testid="`saved-segment-delete-${module}`"
                    @click="destroySelected"
                >
                    {{ t(key('delete_selected')) }}
                </button>
            </div>
        </div>

        <div class="mt-2 flex flex-wrap items-center gap-2 text-xs text-stone-500 dark:text-neutral-400">
            <span class="font-semibold uppercase tracking-wide">
                {{ t(key('title')) }}
            </span>
            <span
                v-if="selectedSegment"
                class="rounded-full border border-stone-200 bg-white px-2 py-1 dark:border-neutral-700 dark:bg-neutral-900"
            >
                {{ t(key('cached_count'), { count: selectedSegment.cached_count || 0 }) }}
            </span>
            <span
                v-if="selectedSegment && formatUpdatedAt(selectedSegment.updated_at)"
                class="rounded-full border border-stone-200 bg-white px-2 py-1 dark:border-neutral-700 dark:bg-neutral-900"
            >
                {{ t(key('updated_at'), { value: formatUpdatedAt(selectedSegment.updated_at) }) }}
            </span>
            <span v-if="!selectedSegment && snapshotReady">
                {{ t(key('current_snapshot_ready')) }}
            </span>
        </div>

        <div
            v-if="error"
            class="mt-2 rounded-sm border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-700 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-300"
            :data-testid="`saved-segment-error-${module}`"
        >
            {{ error }}
        </div>
        <div
            v-if="info"
            class="mt-2 rounded-sm border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs text-emerald-700 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300"
            :data-testid="`saved-segment-info-${module}`"
        >
            {{ info }}
        </div>
    </div>
</template>
