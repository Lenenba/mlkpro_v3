<script setup>
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AdminDataTable from '@/Components/DataTable/AdminDataTable.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { resolveDataTablePerPage } from '@/Components/DataTable/pagination';
import { humanizeDate } from '@/utils/date';

const props = defineProps({
    runs: {
        type: Object,
        required: true,
    },
    filters: {
        type: Object,
        default: () => ({}),
    },
    stats: {
        type: Object,
        default: () => ({}),
    },
    enums: {
        type: Object,
        default: () => ({}),
    },
});

const { t } = useI18n();

const filterForm = reactive({
    module: props.filters?.module || '',
    status: props.filters?.status || '',
    origin: props.filters?.origin || '',
});

const isFiltering = ref(false);
const rows = computed(() => (Array.isArray(props.runs?.data) ? props.runs.data : []));
const runTableRows = computed(() => (isFiltering.value
    ? Array.from({ length: 6 }, (_, index) => ({ id: `playbook-run-skeleton-${index}`, __skeleton: true }))
    : rows.value));
const runLinks = computed(() => (Array.isArray(props.runs?.links) ? props.runs.links : []));
const currentPerPage = computed(() => resolveDataTablePerPage(props.runs?.per_page, props.filters?.per_page));
const pageLabel = computed(() => t('marketing.common.page_of', {
    page: props.runs?.current_page || 1,
    total: props.runs?.last_page || 1,
}));

const moduleOptions = computed(() => ([
    { value: '', label: t('marketing.playbook_runs.module_all') },
    ...((props.enums?.modules || []).map((module) => ({
        value: module,
        label: moduleLabel(module),
    }))),
]));

const statusOptions = computed(() => ([
    { value: '', label: t('marketing.playbook_runs.status_all') },
    ...((props.enums?.statuses || []).map((status) => ({
        value: status,
        label: statusLabel(status),
    }))),
]));

const originOptions = computed(() => ([
    { value: '', label: t('marketing.playbook_runs.origin_all') },
    ...((props.enums?.origins || []).map((origin) => ({
        value: origin,
        label: originLabel(origin),
    }))),
]));

let filterTimeout = null;

const applyFilters = () => {
    const payload = {
        module: filterForm.module,
        status: filterForm.status,
        origin: filterForm.origin,
        per_page: currentPerPage.value,
    };

    Object.keys(payload).forEach((key) => {
        if (payload[key] === '' || payload[key] === null || payload[key] === undefined) {
            delete payload[key];
        }
    });

    isFiltering.value = true;
    router.get(route('crm.playbook-runs.index'), payload, {
        preserveScroll: true,
        preserveState: true,
        replace: true,
        onFinish: () => {
            isFiltering.value = false;
        },
    });
};

watch(
    () => [filterForm.module, filterForm.status, filterForm.origin],
    () => {
        if (filterTimeout) {
            clearTimeout(filterTimeout);
        }

        filterTimeout = setTimeout(() => applyFilters(), 250);
    },
);

onBeforeUnmount(() => {
    if (filterTimeout) {
        clearTimeout(filterTimeout);
    }
});

const moduleLabel = (module) => {
    switch (String(module || '')) {
        case 'request':
            return t('marketing.playbook_runs.labels.request');
        case 'customer':
            return t('marketing.playbook_runs.labels.customer');
        case 'quote':
            return t('marketing.playbook_runs.labels.quote');
        default:
            return humanizeValue(module);
    }
};

const statusLabel = (status) => {
    switch (String(status || '')) {
        case 'pending':
            return t('marketing.playbook_runs.labels.pending');
        case 'running':
            return t('marketing.playbook_runs.labels.running');
        case 'completed':
            return t('marketing.playbook_runs.labels.completed');
        case 'failed':
            return t('marketing.playbook_runs.labels.failed');
        case 'canceled':
            return t('marketing.playbook_runs.labels.canceled');
        default:
            return humanizeValue(status);
    }
};

const originLabel = (origin) => {
    switch (String(origin || '')) {
        case 'manual':
            return t('marketing.playbook_runs.labels.manual');
        case 'scheduled':
            return t('marketing.playbook_runs.labels.scheduled');
        default:
            return humanizeValue(origin);
    }
};

const humanizeValue = (value) => String(value || '')
    .replaceAll('_', ' ')
    .toLowerCase()
    .replace(/\b\w/g, (char) => char.toUpperCase());

const statusBadgeClass = (status) => {
    if (status === 'running' || status === 'pending') {
        return 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300';
    }
    if (status === 'completed') {
        return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300';
    }
    if (status === 'failed' || status === 'canceled') {
        return 'bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300';
    }

    return 'bg-stone-100 text-stone-700 dark:bg-neutral-700 dark:text-neutral-300';
};

const moduleBadgeClass = (module) => {
    if (module === 'request') {
        return 'bg-cyan-100 text-cyan-700 dark:bg-cyan-500/15 dark:text-cyan-300';
    }
    if (module === 'customer') {
        return 'bg-violet-100 text-violet-700 dark:bg-violet-500/15 dark:text-violet-300';
    }
    if (module === 'quote') {
        return 'bg-sky-100 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300';
    }

    return 'bg-stone-100 text-stone-700 dark:bg-neutral-700 dark:text-neutral-300';
};

const formatAbsoluteDate = (value) => {
    if (!value) {
        return '-';
    }

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return '-';
    }

    return date.toLocaleString();
};

const formatRelativeDate = (value) => humanizeDate(value) || '-';
const actionLabel = (value) => humanizeValue(value);
const summaryErrors = (run) => (Array.isArray(run?.summary?.errors) ? run.summary.errors.slice(0, 2) : []);
const hasErrors = (run) => summaryErrors(run).length > 0;
</script>

<template>
    <Head :title="t('marketing.playbook_runs.head_title')" />

    <AuthenticatedLayout>
        <div class="space-y-4">
            <section class="rounded-sm border border-stone-200 border-t-4 border-t-indigo-600 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="space-y-1">
                        <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">
                            {{ t('marketing.playbook_runs.page_title') }}
                        </h1>
                        <p class="text-sm text-stone-500 dark:text-neutral-400">
                            {{ t('marketing.playbook_runs.page_description') }}
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2 text-xs">
                        <span class="rounded-sm border border-stone-200 bg-stone-50 px-2 py-1 text-stone-700 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200">
                            {{ t('marketing.playbook_runs.labels.processed') }}: {{ Number(stats.processed || 0) }}
                        </span>
                        <span class="rounded-sm border border-rose-200 bg-rose-50 px-2 py-1 text-rose-700 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-300">
                            {{ t('marketing.playbook_runs.labels.skipped') }}: {{ Number(stats.skipped || 0) }}
                        </span>
                    </div>
                </div>
            </section>

            <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
                    <FloatingSelect
                        v-model="filterForm.module"
                        :label="t('marketing.playbook_runs.filters.module')"
                        :options="moduleOptions"
                        option-value="value"
                        option-label="label"
                        data-testid="playbook-runs-filter-module"
                    />
                    <FloatingSelect
                        v-model="filterForm.status"
                        :label="t('marketing.playbook_runs.filters.status')"
                        :options="statusOptions"
                        option-value="value"
                        option-label="label"
                        data-testid="playbook-runs-filter-status"
                    />
                    <FloatingSelect
                        v-model="filterForm.origin"
                        :label="t('marketing.playbook_runs.filters.origin')"
                        :options="originOptions"
                        option-value="value"
                        option-label="label"
                        data-testid="playbook-runs-filter-origin"
                    />
                </div>
            </section>

            <section class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
                <div class="rounded-sm border border-stone-200 bg-white p-3 shadow-sm dark:border-neutral-700 dark:bg-neutral-900" data-testid="playbook-runs-card-total">
                    <div class="text-xs text-stone-500 dark:text-neutral-400">{{ t('marketing.playbook_runs.cards.total') }}</div>
                    <div class="mt-1 text-xl font-semibold text-stone-800 dark:text-neutral-100">{{ Number(stats.total || 0) }}</div>
                </div>
                <div class="rounded-sm border border-stone-200 bg-white p-3 shadow-sm dark:border-neutral-700 dark:bg-neutral-900" data-testid="playbook-runs-card-active">
                    <div class="text-xs text-stone-500 dark:text-neutral-400">{{ t('marketing.playbook_runs.cards.active') }}</div>
                    <div class="mt-1 text-xl font-semibold text-amber-700 dark:text-amber-300">{{ Number(stats.active || 0) }}</div>
                </div>
                <div class="rounded-sm border border-stone-200 bg-white p-3 shadow-sm dark:border-neutral-700 dark:bg-neutral-900" data-testid="playbook-runs-card-completed">
                    <div class="text-xs text-stone-500 dark:text-neutral-400">{{ t('marketing.playbook_runs.cards.completed') }}</div>
                    <div class="mt-1 text-xl font-semibold text-emerald-700 dark:text-emerald-300">{{ Number(stats.completed || 0) }}</div>
                </div>
                <div class="rounded-sm border border-stone-200 bg-white p-3 shadow-sm dark:border-neutral-700 dark:bg-neutral-900" data-testid="playbook-runs-card-failed">
                    <div class="text-xs text-stone-500 dark:text-neutral-400">{{ t('marketing.playbook_runs.cards.failed') }}</div>
                    <div class="mt-1 text-xl font-semibold text-rose-700 dark:text-rose-300">{{ Number(stats.failed || 0) }}</div>
                </div>
                <div class="rounded-sm border border-stone-200 bg-white p-3 shadow-sm dark:border-neutral-700 dark:bg-neutral-900" data-testid="playbook-runs-card-processed">
                    <div class="text-xs text-stone-500 dark:text-neutral-400">{{ t('marketing.playbook_runs.cards.processed') }}</div>
                    <div class="mt-1 text-xl font-semibold text-stone-800 dark:text-neutral-100">{{ Number(stats.processed || 0) }}</div>
                </div>
                <div class="rounded-sm border border-stone-200 bg-white p-3 shadow-sm dark:border-neutral-700 dark:bg-neutral-900" data-testid="playbook-runs-card-skipped">
                    <div class="text-xs text-stone-500 dark:text-neutral-400">{{ t('marketing.playbook_runs.cards.skipped') }}</div>
                    <div class="mt-1 text-xl font-semibold text-stone-800 dark:text-neutral-100">{{ Number(stats.skipped || 0) }}</div>
                </div>
            </section>

            <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <AdminDataTable
                    embedded
                    :rows="runTableRows"
                    :links="runLinks"
                    :show-pagination="rows.length > 0"
                    show-per-page
                    :per-page="currentPerPage"
                >
                    <template #head>
                        <tr class="text-left text-xs uppercase text-stone-500 dark:text-neutral-400">
                            <th class="px-4 py-3 font-medium">{{ t('marketing.playbook_runs.table.run') }}</th>
                            <th class="px-4 py-3 font-medium">{{ t('marketing.playbook_runs.table.playbook') }}</th>
                            <th class="px-4 py-3 font-medium">{{ t('marketing.playbook_runs.table.status') }}</th>
                            <th class="px-4 py-3 font-medium">{{ t('marketing.playbook_runs.table.counters') }}</th>
                            <th class="px-4 py-3 font-medium">{{ t('marketing.playbook_runs.table.timing') }}</th>
                            <th class="px-4 py-3 font-medium">{{ t('marketing.playbook_runs.table.summary') }}</th>
                        </tr>
                    </template>

                    <template #row="{ row: run }">
                        <tr class="align-top text-stone-700 dark:text-neutral-200" :data-testid="run.__skeleton ? undefined : `playbook-run-row-${run.id}`">
                            <template v-if="run.__skeleton">
                                <td v-for="col in 6" :key="`run-skeleton-cell-${run.id}-${col}`" class="px-4 py-3">
                                    <div class="h-3 w-full animate-pulse rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                                </td>
                            </template>
                            <template v-else>
                                <td class="px-4 py-3">
                                    <div class="font-semibold text-stone-800 dark:text-neutral-100">#{{ run.id }}</div>
                                    <div class="mt-2 flex flex-wrap gap-1">
                                        <span class="inline-flex rounded-full px-2 py-0.5 text-[11px] font-semibold" :class="moduleBadgeClass(run.module)">
                                            {{ moduleLabel(run.module) }}
                                        </span>
                                        <span class="inline-flex rounded-full bg-stone-100 px-2 py-0.5 text-[11px] font-semibold text-stone-700 dark:bg-neutral-800 dark:text-neutral-300">
                                            {{ originLabel(run.origin) }}
                                        </span>
                                    </div>
                                    <div class="mt-2 text-xs text-stone-500 dark:text-neutral-400">
                                        {{ formatRelativeDate(run.created_at) }}
                                    </div>
                                </td>

                                <td class="px-4 py-3">
                                    <div class="font-semibold text-stone-800 dark:text-neutral-100">
                                        {{ run.playbook?.name || t('marketing.playbook_runs.labels.playbook_deleted') }}
                                    </div>
                                    <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                        {{ t('marketing.playbook_runs.labels.segment') }}:
                                        {{ run.saved_segment?.name || t('marketing.playbook_runs.labels.none') }}
                                    </div>
                                    <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                        {{ t('marketing.playbook_runs.labels.action') }}: {{ actionLabel(run.action_key) }}
                                    </div>
                                    <div v-if="run.requested_by?.name" class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                        {{ t('marketing.playbook_runs.labels.requested_by') }}: {{ run.requested_by.name }}
                                    </div>
                                </td>

                                <td class="px-4 py-3">
                                    <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold" :class="statusBadgeClass(run.status)">
                                        {{ statusLabel(run.status) }}
                                    </span>
                                </td>

                                <td class="px-4 py-3">
                                    <div class="grid grid-cols-2 gap-x-3 gap-y-1 text-xs">
                                        <div class="text-stone-500 dark:text-neutral-400">{{ t('marketing.playbook_runs.labels.selected') }}</div>
                                        <div class="font-semibold text-stone-700 dark:text-neutral-200">{{ Number(run.selected_count || 0) }}</div>
                                        <div class="text-stone-500 dark:text-neutral-400">{{ t('marketing.playbook_runs.labels.processed') }}</div>
                                        <div class="font-semibold text-stone-700 dark:text-neutral-200">{{ Number(run.processed_count || 0) }}</div>
                                        <div class="text-stone-500 dark:text-neutral-400">{{ t('marketing.playbook_runs.labels.success') }}</div>
                                        <div class="font-semibold text-emerald-700 dark:text-emerald-300">{{ Number(run.success_count || 0) }}</div>
                                        <div class="text-stone-500 dark:text-neutral-400">{{ t('marketing.playbook_runs.labels.failed_count') }}</div>
                                        <div class="font-semibold text-rose-700 dark:text-rose-300">{{ Number(run.failed_count || 0) }}</div>
                                        <div class="text-stone-500 dark:text-neutral-400">{{ t('marketing.playbook_runs.labels.skipped') }}</div>
                                        <div class="font-semibold text-stone-700 dark:text-neutral-200">{{ Number(run.skipped_count || 0) }}</div>
                                    </div>
                                </td>

                                <td class="px-4 py-3">
                                    <div class="space-y-1 text-xs">
                                        <div class="text-stone-500 dark:text-neutral-400">
                                            {{ t('marketing.playbook_runs.labels.started_at') }}:
                                            <span class="text-stone-700 dark:text-neutral-200">{{ formatAbsoluteDate(run.started_at) }}</span>
                                        </div>
                                        <div class="text-stone-500 dark:text-neutral-400">
                                            {{ t('marketing.playbook_runs.labels.finished_at') }}:
                                            <span class="text-stone-700 dark:text-neutral-200">{{ formatAbsoluteDate(run.finished_at) }}</span>
                                        </div>
                                        <div v-if="run.scheduled_for" class="text-stone-500 dark:text-neutral-400">
                                            {{ t('marketing.playbook_runs.labels.scheduled_for') }}:
                                            <span class="text-stone-700 dark:text-neutral-200">{{ formatAbsoluteDate(run.scheduled_for) }}</span>
                                        </div>
                                    </div>
                                </td>

                                <td class="px-4 py-3">
                                    <div class="text-sm text-stone-700 dark:text-neutral-200">
                                        {{ run.summary?.message || '-' }}
                                    </div>
                                    <div v-if="hasErrors(run)" class="mt-2 space-y-1 text-xs text-rose-700 dark:text-rose-300">
                                        <div
                                            v-for="errorMessage in summaryErrors(run)"
                                            :key="`${run.id}-${errorMessage}`"
                                            class="rounded-sm border border-rose-200 bg-rose-50 px-2 py-1 dark:border-rose-500/20 dark:bg-rose-500/10"
                                        >
                                            {{ errorMessage }}
                                        </div>
                                    </div>
                                    <div
                                        v-else-if="Number(run.skipped_count || 0) > 0"
                                        class="mt-2 text-xs text-stone-500 dark:text-neutral-400"
                                    >
                                        {{ t('marketing.playbook_runs.labels.skipped') }}: {{ Number(run.skipped_count || 0) }}
                                    </div>
                                </td>
                            </template>
                        </tr>
                    </template>

                    <template #empty>
                        <div class="px-4 py-10 text-center">
                            <div class="text-sm font-semibold text-stone-700 dark:text-neutral-200">
                                {{ t('marketing.playbook_runs.empty.title') }}
                            </div>
                            <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                {{ t('marketing.playbook_runs.empty.description') }}
                            </div>
                        </div>
                    </template>

                    <template #pagination_prefix>
                        <div class="text-xs text-stone-500 dark:text-neutral-400">
                            {{ pageLabel }}
                        </div>
                    </template>
                </AdminDataTable>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
