<script setup>
import { computed, useSlots } from 'vue';
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AdminPaginationLinks from '@/Components/DataTable/AdminPaginationLinks.vue';
import { DATA_TABLE_PER_PAGE_OPTIONS, normalizeDataTablePerPage } from '@/Components/DataTable/pagination';

const emit = defineEmits(['update:perPage']);

const props = defineProps({
    rows: {
        type: Array,
        default: () => [],
    },
    links: {
        type: Array,
        default: () => [],
    },
    total: {
        type: Number,
        default: null,
    },
    columns: {
        type: Number,
        default: 0,
    },
    emptyTitle: {
        type: String,
        default: '',
    },
    emptyDescription: {
        type: String,
        default: '',
    },
    loading: {
        type: Boolean,
        default: false,
    },
    striped: {
        type: Boolean,
        default: true,
    },
    dense: {
        type: Boolean,
        default: false,
    },
    rowKey: {
        type: [String, Function],
        default: 'id',
    },
    resultLabel: {
        type: String,
        default: '',
    },
    embedded: {
        type: Boolean,
        default: false,
    },
    showPagination: {
        type: Boolean,
        default: true,
    },
    showPerPage: {
        type: Boolean,
        default: false,
    },
    perPage: {
        type: Number,
        default: null,
    },
    perPageLabel: {
        type: String,
        default: '',
    },
    perPageOptions: {
        type: Array,
        default: () => DATA_TABLE_PER_PAGE_OPTIONS,
    },
    containerClass: {
        type: [String, Array, Object],
        default: '',
    },
});
const slots = useSlots();
const { t } = useI18n();

const normalizedRows = computed(() => (Array.isArray(props.rows) ? props.rows : []));
const normalizedLinks = computed(() => (Array.isArray(props.links) ? props.links : []));
const normalizedPerPageOptions = computed(() => {
    const options = Array.isArray(props.perPageOptions) && props.perPageOptions.length
        ? props.perPageOptions
        : DATA_TABLE_PER_PAGE_OPTIONS;

    return options
        .map((value) => Number.parseInt(value, 10))
        .filter((value, index, collection) => Number.isInteger(value) && value > 0 && collection.indexOf(value) === index);
});
const normalizedPerPage = computed(() => normalizeDataTablePerPage(props.perPage));
const hasRows = computed(() => normalizedRows.value.length > 0);
const paginationPageCount = computed(() => normalizedLinks.value
    .map((link) => String(link?.label ?? '').replace(/<[^>]*>/g, '').trim())
    .filter((label) => /^\d+$/.test(label))
    .length);
const hasMultiplePaginationPages = computed(() => paginationPageCount.value > 1);
const tableDensityClass = computed(() => (props.dense ? 'text-xs' : 'text-sm'));
const tbodyClass = computed(() => [
    'divide-y divide-stone-100 dark:divide-neutral-800',
    props.striped ? '[&>tr:nth-child(odd)]:bg-white [&>tr:nth-child(even)]:bg-stone-50/60 dark:[&>tr:nth-child(odd)]:bg-neutral-900/55 dark:[&>tr:nth-child(even)]:bg-neutral-800/22' : '',
]);
const shouldShowFooter = computed(() => (
    !!props.resultLabel
    || !!slots.pagination_prefix
    || (props.showPagination && hasMultiplePaginationPages.value)
    || props.showPerPage
));
const resolvedPerPageLabel = computed(() => props.perPageLabel || t('datatable.shared.rows_per_page'));
const resolvedLoadingLabel = computed(() => t('datatable.shared.loading'));
const rootClass = computed(() => (props.embedded
    ? ['admin-data-table space-y-4', { 'admin-data-table--striped': props.striped }, props.containerClass]
    : ['admin-data-table flex flex-col space-y-4 rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900', { 'admin-data-table--striped': props.striped }, props.containerClass]));

const resolveRowKey = (row, index) => {
    if (typeof props.rowKey === 'function') {
        return props.rowKey(row, index);
    }

    if (row && typeof row === 'object' && props.rowKey in row) {
        return row[props.rowKey];
    }

    return index;
};

const updatePerPage = (event) => {
    const nextPerPage = normalizeDataTablePerPage(event?.target?.value, normalizedPerPage.value);

    emit('update:perPage', nextPerPage);

    if (typeof window === 'undefined') {
        return;
    }

    const url = new URL(window.location.href);
    url.searchParams.set('per_page', String(nextPerPage));
    url.searchParams.delete('page');

    router.get(`${url.pathname}${url.search}`, {}, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
};
</script>

<template>
    <section :class="rootClass">
        <slot name="toolbar" />

        <div
            v-if="loading"
            class="rounded-sm border border-dashed border-stone-300 p-6 text-sm text-stone-600 dark:border-neutral-600 dark:text-neutral-300"
        >
            {{ resolvedLoadingLabel }}
        </div>

        <slot v-else-if="!hasRows" name="empty">
            <div class="rounded-sm border border-dashed border-stone-300 p-6 text-sm text-stone-600 dark:border-neutral-600 dark:text-neutral-300">
                <div v-if="emptyTitle" class="font-medium text-stone-800 dark:text-neutral-100">
                    {{ emptyTitle }}
                </div>
                <p v-if="emptyDescription" :class="emptyTitle ? 'mt-1' : ''">
                    {{ emptyDescription }}
                </p>
            </div>
        </slot>

        <div
            v-else
            class="overflow-x-auto [&::-webkit-scrollbar]:h-2 [&::-webkit-scrollbar-thumb]:rounded-full [&::-webkit-scrollbar-track]:bg-stone-100 [&::-webkit-scrollbar-thumb]:bg-stone-300 dark:[&::-webkit-scrollbar-track]:bg-neutral-700 dark:[&::-webkit-scrollbar-thumb]:bg-neutral-500"
        >
            <table class="admin-data-table__table min-w-full divide-y divide-stone-200 text-left text-stone-600 dark:divide-neutral-700 dark:text-neutral-300" :class="tableDensityClass">
                <thead class="border-b border-stone-300 bg-stone-100/95 shadow-[inset_0_-1px_0_0_rgb(214_211_209)] backdrop-blur-sm dark:border-neutral-600 dark:bg-neutral-800/95 dark:shadow-[inset_0_-1px_0_0_rgb(82_82_82)]">
                    <slot name="head" />
                </thead>
                <slot v-if="slots.body" name="body" :rows="normalizedRows" />
                <tbody v-else :class="tbodyClass">
                    <template v-for="(row, index) in normalizedRows" :key="resolveRowKey(row, index)">
                        <slot name="row" :row="row" :index="index" />
                    </template>
                </tbody>
            </table>
        </div>

        <div v-if="shouldShowFooter" class="border-t border-stone-200 pt-4 dark:border-neutral-700">
            <div class="grid gap-3 md:grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] md:items-center">
                <div
                    v-if="slots.pagination_prefix || resultLabel"
                    class="min-w-0 text-sm text-stone-500 dark:text-neutral-400 [&_*]:!m-0 [&_*]:!text-sm [&_*]:!font-normal [&_*]:!text-stone-500 dark:[&_*]:!text-neutral-400 md:col-start-1"
                >
                    <slot v-if="slots.pagination_prefix" name="pagination_prefix" />
                    <p v-else>{{ resultLabel }}</p>
                </div>

                <div v-if="showPagination && hasMultiplePaginationPages" class="flex justify-start md:col-start-2 md:justify-center">
                    <AdminPaginationLinks :links="normalizedLinks" />
                </div>

                <div v-if="showPerPage" class="flex justify-start md:col-start-3 md:justify-end">
                    <label
                        class="inline-flex items-center gap-2 whitespace-nowrap text-xs text-stone-500 dark:text-neutral-400"
                    >
                        <span>{{ resolvedPerPageLabel }}</span>
                        <span class="relative inline-flex">
                            <select
                                :value="normalizedPerPage"
                                class="data-table-per-page-select rounded-sm border border-stone-200 bg-none bg-white pl-2 pr-7 py-1 text-xs text-stone-700 focus:border-green-500 focus:ring-green-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                                @change="updatePerPage"
                            >
                                <option
                                    v-for="option in normalizedPerPageOptions"
                                    :key="`per-page-${option}`"
                                    :value="option"
                                >
                                    {{ option }}
                                </option>
                            </select>
                            <span class="pointer-events-none absolute inset-y-0 right-2 flex items-center text-stone-400 dark:text-neutral-500">
                                <svg class="size-3" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="m5 7.5 5 5 5-5" />
                                </svg>
                            </span>
                        </span>
                    </label>
                </div>
            </div>
        </div>
    </section>
</template>

<style scoped>
.data-table-per-page-select {
    -webkit-appearance: none;
    -moz-appearance: none;
    appearance: none;
    background-image: none;
}

.data-table-per-page-select::-ms-expand {
    display: none;
}

:deep(.admin-data-table__table thead th),
:deep(.admin-data-table__table thead th > *),
:deep(.admin-data-table__table thead th button),
:deep(.admin-data-table__table thead th button > *) {
    font-weight: 700;
}

:deep(.admin-data-table__table thead th) {
    border-bottom: 1px solid rgb(214 211 209 / 0.9);
}

:deep(.admin-data-table__table thead th),
:deep(.admin-data-table__table thead th > *),
:deep(.admin-data-table__table thead th button) {
    color: rgb(68 64 60);
    letter-spacing: 0.015em;
}

:deep(.dark .admin-data-table__table thead th) {
    border-bottom: 1px solid rgb(82 82 82 / 0.95);
}

:deep(.dark .admin-data-table__table thead th),
:deep(.dark .admin-data-table__table thead th > *),
:deep(.dark .admin-data-table__table thead th button) {
    color: rgb(245 245 245);
}

.admin-data-table--striped :deep(.admin-data-table__table tbody > tr:nth-child(odd)),
.admin-data-table--striped :deep(.admin-data-table__table tbody > tr:nth-child(odd) > td),
.admin-data-table--striped :deep(.admin-data-table__table tbody > tr:nth-child(odd) > th) {
    background-color: rgb(255 255 255);
}

.admin-data-table--striped :deep(.admin-data-table__table tbody > tr:nth-child(even)),
.admin-data-table--striped :deep(.admin-data-table__table tbody > tr:nth-child(even) > td),
.admin-data-table--striped :deep(.admin-data-table__table tbody > tr:nth-child(even) > th) {
    background-color: rgb(245 245 244);
}

.dark .admin-data-table--striped :deep(.admin-data-table__table tbody > tr:nth-child(odd)),
.dark .admin-data-table--striped :deep(.admin-data-table__table tbody > tr:nth-child(odd) > td),
.dark .admin-data-table--striped :deep(.admin-data-table__table tbody > tr:nth-child(odd) > th) {
    background-color: rgb(23 23 23 / 0.55);
}

.dark .admin-data-table--striped :deep(.admin-data-table__table tbody > tr:nth-child(even)),
.dark .admin-data-table--striped :deep(.admin-data-table__table tbody > tr:nth-child(even) > td),
.dark .admin-data-table--striped :deep(.admin-data-table__table tbody > tr:nth-child(even) > th) {
    background-color: rgb(38 38 38 / 0.82);
}
</style>
