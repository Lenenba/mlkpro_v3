<script setup>
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import { Link } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';
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
const loadingRows = ref(false);
const applyDialogOpen = ref(false);
const manageDialogOpen = ref(false);
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
const isWorking = computed(() => busy.value || loadingRows.value);
const anyDialogOpen = computed(() => applyDialogOpen.value || manageDialogOpen.value);
const applyLabel = computed(() => t(key('selection_panel')));
const selectedUpdatedAt = computed(() => formatUpdatedAt(selectedSegment.value?.updated_at));
const managerLabel = computed(() => t(key('manage')));

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

const openApplyDialog = () => {
    manageDialogOpen.value = false;
    applyDialogOpen.value = true;

    if (props.canManage) {
        load();
    }
};

const closeApplyDialog = () => {
    applyDialogOpen.value = false;
};

const openManageDialog = () => {
    applyDialogOpen.value = false;
    manageDialogOpen.value = true;

    if (props.canManage) {
        load();
    }
};

const closeManageDialog = () => {
    manageDialogOpen.value = false;
};

const load = async () => {
    if (!props.canManage) {
        return;
    }

    const previousSelection = selectedId.value;
    loadingRows.value = true;
    error.value = '';

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
    } finally {
        loadingRows.value = false;
    }
};

const applySelected = () => {
    if (!selectedSegment.value) {
        return;
    }

    error.value = '';
    info.value = '';
    emit('apply', selectedSegment.value);
    closeApplyDialog();
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
        class="overflow-hidden rounded-sm border border-stone-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900"
        :data-testid="`saved-segment-bar-${module}`"
    >
        <div class="flex flex-col gap-3 p-4 lg:flex-row lg:items-center lg:justify-between">
            <div class="flex flex-wrap items-center gap-2 text-xs text-stone-500 dark:text-neutral-400">
                <span class="rounded-sm border border-stone-200 bg-stone-50 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.16em] text-stone-700 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200">
                    {{ t(key('title')) }}
                </span>
                <span
                    v-if="selectedSegment"
                    class="rounded-sm border border-emerald-200 bg-emerald-50 px-2.5 py-1 text-stone-700 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-neutral-200"
                >
                    {{ selectedSegment.name }}
                </span>
                <span
                    v-if="selectedSegment"
                    class="rounded-sm border border-stone-200 bg-white px-2.5 py-1 dark:border-neutral-700 dark:bg-neutral-900"
                >
                    {{ t(key('cached_count'), { count: selectedSegment.cached_count || 0 }) }}
                </span>
                <span
                    v-if="selectedUpdatedAt"
                    class="rounded-sm border border-stone-200 bg-white px-2.5 py-1 dark:border-neutral-700 dark:bg-neutral-900"
                >
                    {{ t(key('updated_at'), { value: selectedUpdatedAt }) }}
                </span>
                <span
                    v-if="!selectedSegment && snapshotReady"
                    class="rounded-sm border border-amber-200 bg-amber-50 px-2.5 py-1 text-amber-700 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-200"
                >
                    {{ t(key('snapshot_label')) }}
                </span>
                <span
                    v-if="!selectedSegment && !snapshotReady && !hasSegments"
                    class="rounded-sm border border-dashed border-stone-300 bg-white px-2.5 py-1 dark:border-neutral-700 dark:bg-neutral-900"
                >
                    {{ t(key('empty_state')) }}
                </span>
            </div>

            <div class="flex flex-wrap items-center gap-2 lg:justify-end">
                <Link
                    v-if="historyHref"
                    class="inline-flex items-center gap-2 rounded-sm border border-stone-200 bg-white/90 px-3 py-2 text-xs font-medium text-stone-700 transition hover:bg-white dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                    :href="historyHref"
                    :data-testid="`saved-segment-history-${module}`"
                >
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-3.5 w-3.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 7v5l3 3M3.75 12a8.25 8.25 0 1 0 2.42-5.83M3.75 4.5v4.5h4.5" />
                    </svg>
                    {{ historyLabel || t('marketing.playbook_runs.actions.open_history') }}
                </Link>
                <button
                    v-if="hasSegments"
                    type="button"
                    class="inline-flex items-center gap-2 rounded-sm border border-stone-200 bg-white px-3.5 py-2 text-xs font-semibold text-stone-700 transition hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                    :data-testid="`saved-segment-open-apply-${module}`"
                    @click="openApplyDialog"
                >
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-3.5 w-3.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14M13 6l6 6-6 6" />
                    </svg>
                    {{ applyLabel }}
                </button>
                <button
                    v-if="canManage"
                    type="button"
                    class="inline-flex items-center gap-2 rounded-sm border border-transparent bg-emerald-600 px-3.5 py-2 text-xs font-semibold text-white shadow-lg shadow-emerald-600/20 transition hover:bg-emerald-700"
                    :data-testid="`saved-segment-open-${module}`"
                    @click="openManageDialog"
                >
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-3.5 w-3.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 5.25v13.5M5.25 12h13.5" />
                    </svg>
                    {{ managerLabel }}
                </button>
            </div>
        </div>

        <div
            v-if="error && !anyDialogOpen"
            class="mx-4 mb-4 rounded-sm border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-700 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-300"
            :data-testid="`saved-segment-error-${module}`"
        >
            {{ error }}
        </div>
        <div
            v-if="info && !anyDialogOpen"
            class="mx-4 mb-4 rounded-sm border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs text-emerald-700 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300"
            :data-testid="`saved-segment-info-${module}`"
        >
            {{ info }}
        </div>

        <Modal
            :show="applyDialogOpen"
            max-width="3xl"
            @close="closeApplyDialog"
        >
            <div
                class="relative"
                :data-testid="`saved-segment-apply-modal-${module}`"
            >
                <div class="border-b border-stone-200 bg-white px-5 py-5 dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="flex items-start justify-between gap-4">
                        <div class="flex items-start gap-3">
                            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl border border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-200">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4.5 w-4.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.75 6.75h14.5M4.75 12h14.5M4.75 17.25h8.5" />
                                </svg>
                            </div>
                            <div class="space-y-2">
                                <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-emerald-600 dark:text-emerald-300">
                                    {{ t(key('title')) }}
                                </p>
                                <h3 class="text-lg font-semibold text-stone-900 dark:text-white">
                                    {{ applyLabel }}
                                </h3>
                                <p class="max-w-2xl text-sm leading-6 text-stone-600 dark:text-neutral-300">
                                    {{ hasSegments ? t(key('select_placeholder')) : t(key('empty_state')) }}
                                </p>
                            </div>
                        </div>

                        <button
                            type="button"
                            class="inline-flex h-9 w-9 items-center justify-center rounded-full border border-stone-200 bg-white text-stone-500 transition hover:bg-stone-50 hover:text-stone-700 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-400 dark:hover:bg-neutral-800 dark:hover:text-neutral-200"
                            @click="closeApplyDialog"
                        >
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 6l12 12M18 6L6 18" />
                            </svg>
                        </button>
                    </div>
                </div>

                <div class="space-y-4 bg-stone-50 p-5 dark:bg-neutral-950">
                    <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                        <div class="mb-4 flex items-start justify-between gap-4">
                            <div>
                                <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-stone-500 dark:text-neutral-400">
                                    {{ t(key('selection_panel')) }}
                                </p>
                                <p class="mt-1 text-sm text-stone-600 dark:text-neutral-300">
                                    {{ hasSegments ? t(key('select_placeholder')) : t(key('empty_state')) }}
                                </p>
                            </div>
                            <span
                                class="rounded-full border border-stone-200 bg-stone-50 px-2.5 py-1 text-[11px] font-medium text-stone-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300"
                            >
                                {{ hasSegments ? rows.length : 0 }}
                            </span>
                        </div>

                        <FloatingSelect
                            v-model="selectedId"
                            :label="t(key('select_label'))"
                            :options="segmentOptions"
                            :placeholder="t(key('select_placeholder'))"
                            :disabled="isWorking || !hasSegments"
                            :data-testid="`saved-segment-select-${module}`"
                            filterable
                            dense
                        />

                        <div class="mt-4 flex flex-wrap items-center gap-2">
                            <button
                                type="button"
                                class="inline-flex items-center gap-2 rounded-sm border border-transparent bg-stone-900 px-3.5 py-2 text-xs font-semibold text-white transition hover:bg-stone-800 disabled:pointer-events-none disabled:opacity-50 dark:bg-white dark:text-neutral-900 dark:hover:bg-neutral-200"
                                :disabled="isWorking || !selectedSegment"
                                :data-testid="`saved-segment-apply-${module}`"
                                @click="applySelected"
                            >
                                {{ t(key('apply')) }}
                            </button>
                            <span
                                v-if="loadingRows"
                                class="text-xs text-stone-500 dark:text-neutral-400"
                            >
                                {{ t(key('select_placeholder')) }}...
                            </span>
                        </div>

                        <div class="mt-4 space-y-3 rounded-sm border border-dashed border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-950/60">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="rounded-full bg-stone-900 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.18em] text-white dark:bg-neutral-200 dark:text-neutral-900">
                                    {{ selectedSegment ? t(key('selected_label')) : t(key('summary_empty')) }}
                                </span>
                                <span class="text-sm font-semibold text-stone-900 dark:text-white">
                                    {{ selectedSegment ? selectedSegment.name : t(key('summary_empty')) }}
                                </span>
                            </div>

                            <div class="flex flex-wrap gap-2 text-xs text-stone-500 dark:text-neutral-400">
                                <span
                                    v-if="selectedSegment"
                                    class="rounded-full border border-stone-200 bg-white px-2.5 py-1 dark:border-neutral-700 dark:bg-neutral-900"
                                >
                                    {{ t(key('cached_count'), { count: selectedSegment.cached_count || 0 }) }}
                                </span>
                                <span
                                    v-if="selectedUpdatedAt"
                                    class="rounded-full border border-stone-200 bg-white px-2.5 py-1 dark:border-neutral-700 dark:bg-neutral-900"
                                >
                                    {{ t(key('updated_at'), { value: selectedUpdatedAt }) }}
                                </span>
                            </div>
                        </div>
                    </section>

                    <div
                        v-if="error"
                        class="rounded-sm border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-700 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-300"
                        :data-testid="`saved-segment-error-${module}`"
                    >
                        {{ error }}
                    </div>
                    <div
                        v-if="info"
                        class="rounded-sm border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs text-emerald-700 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300"
                        :data-testid="`saved-segment-info-${module}`"
                    >
                        {{ info }}
                    </div>
                </div>
            </div>
        </Modal>

        <Modal
            :show="manageDialogOpen"
            max-width="3xl"
            @close="closeManageDialog"
        >
            <div
                class="relative"
                :data-testid="`saved-segment-modal-${module}`"
            >
                <div class="border-b border-stone-200 bg-white px-5 py-5 dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="flex items-start justify-between gap-4">
                        <div class="flex items-start gap-3">
                            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl border border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-200">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4.5 w-4.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.75 6.75h14.5M4.75 12h14.5M4.75 17.25h8.5" />
                                </svg>
                            </div>
                            <div class="space-y-2">
                                <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-emerald-600 dark:text-emerald-300">
                                    {{ t(key('title')) }}
                                </p>
                                <h3 class="text-lg font-semibold text-stone-900 dark:text-white">
                                    {{ managerLabel }}
                                </h3>
                                <p class="max-w-2xl text-sm leading-6 text-stone-600 dark:text-neutral-300">
                                    {{ t(key('dialog_hint')) }}
                                </p>
                            </div>
                        </div>

                        <button
                            type="button"
                            class="inline-flex h-9 w-9 items-center justify-center rounded-full border border-stone-200 bg-white text-stone-500 transition hover:bg-stone-50 hover:text-stone-700 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-400 dark:hover:bg-neutral-800 dark:hover:text-neutral-200"
                            @click="closeManageDialog"
                        >
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 6l12 12M18 6L6 18" />
                            </svg>
                        </button>
                    </div>
                </div>

                <div class="space-y-4 bg-stone-50 p-5 dark:bg-neutral-950">
                    <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                        <div class="space-y-4">
                            <div>
                                <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-stone-500 dark:text-neutral-400">
                                    {{ t(key('management_panel')) }}
                                </p>
                                <p class="mt-1 text-sm text-stone-600 dark:text-neutral-300">
                                    {{ t(key('dialog_hint')) }}
                                </p>
                            </div>

                            <FloatingSelect
                                v-model="selectedId"
                                :label="t(key('select_label'))"
                                :options="segmentOptions"
                                :placeholder="t(key('select_placeholder'))"
                                :disabled="isWorking || !hasSegments"
                                :data-testid="`saved-segment-select-${module}`"
                                filterable
                                dense
                            />

                            <FloatingInput
                                v-model="segmentName"
                                :label="t(key('name_label'))"
                                :placeholder="t(key('name_placeholder'))"
                                :disabled="isWorking"
                                :data-testid="`saved-segment-name-${module}`"
                            />

                            <div class="grid gap-2 sm:grid-cols-2">
                                <button
                                    type="button"
                                    class="inline-flex items-center justify-center gap-2 rounded-sm border border-transparent bg-emerald-600 px-3.5 py-2.5 text-xs font-semibold text-white transition hover:bg-emerald-700 disabled:pointer-events-none disabled:opacity-50"
                                    :disabled="isWorking || !snapshotReady"
                                    :data-testid="`saved-segment-save-${module}`"
                                    @click="saveCurrent"
                                >
                                    {{ t(key('save_current')) }}
                                </button>
                                <button
                                    type="button"
                                    class="inline-flex items-center justify-center gap-2 rounded-sm border border-stone-200 bg-white px-3.5 py-2.5 text-xs font-semibold text-stone-700 transition hover:bg-stone-50 disabled:pointer-events-none disabled:opacity-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                                    :disabled="isWorking || !selectedSegment || !snapshotReady"
                                    :data-testid="`saved-segment-update-${module}`"
                                    @click="updateSelected"
                                >
                                    {{ t(key('update_selected')) }}
                                </button>
                                <button
                                    type="button"
                                    class="inline-flex items-center justify-center gap-2 rounded-sm border border-rose-200 bg-rose-50 px-3.5 py-2.5 text-xs font-semibold text-rose-700 transition hover:bg-rose-100 disabled:pointer-events-none disabled:opacity-50 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-300 dark:hover:bg-rose-500/20 sm:col-span-2"
                                    :disabled="isWorking || !selectedSegment"
                                    :data-testid="`saved-segment-delete-${module}`"
                                    @click="destroySelected"
                                >
                                    {{ t(key('delete_selected')) }}
                                </button>
                            </div>

                            <div class="rounded-sm border border-dashed border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-950/60">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span
                                        v-if="selectedSegment"
                                        class="rounded-full border border-stone-200 bg-white px-2.5 py-1 text-xs text-stone-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300"
                                    >
                                        {{ selectedSegment.name }}
                                    </span>
                                    <span
                                        v-if="selectedSegment"
                                        class="rounded-full border border-stone-200 bg-white px-2.5 py-1 text-xs text-stone-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300"
                                    >
                                        {{ t(key('cached_count'), { count: selectedSegment.cached_count || 0 }) }}
                                    </span>
                                    <span
                                        v-if="selectedUpdatedAt"
                                        class="rounded-full border border-stone-200 bg-white px-2.5 py-1 text-xs text-stone-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300"
                                    >
                                        {{ t(key('updated_at'), { value: selectedUpdatedAt }) }}
                                    </span>
                                </div>
                                <p class="mt-3 text-xs font-medium text-stone-700 dark:text-neutral-200">
                                    {{ snapshotReady ? t(key('current_snapshot_ready')) : t(key('empty_state')) }}
                                </p>
                            </div>
                        </div>
                    </section>

                    <div
                        v-if="error"
                        class="rounded-sm border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-700 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-300"
                        :data-testid="`saved-segment-error-${module}`"
                    >
                        {{ error }}
                    </div>
                    <div
                        v-if="info"
                        class="rounded-sm border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs text-emerald-700 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300"
                        :data-testid="`saved-segment-info-${module}`"
                    >
                        {{ info }}
                    </div>
                </div>
            </div>
        </Modal>
    </div>
</template>
