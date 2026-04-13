<script setup>
import { computed, useSlots } from 'vue';
import { router } from '@inertiajs/vue3';
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
        default: false,
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
        default: 'Rows / page',
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
const tableDensityClass = computed(() => (props.dense ? 'text-xs' : 'text-sm'));
const tbodyClass = computed(() => [
    'divide-y divide-stone-100 dark:divide-neutral-800',
    props.striped ? '[&>tr:nth-child(odd)]:bg-stone-50/50 dark:[&>tr:nth-child(odd)]:bg-neutral-800/20' : '',
]);
const shouldShowFooter = computed(() => (
    !!props.resultLabel
    || !!slots.pagination_prefix
    || (props.showPagination && normalizedLinks.value.length > 0)
    || props.showPerPage
));
const rootClass = computed(() => (props.embedded
    ? ['space-y-4', props.containerClass]
    : ['flex flex-col space-y-4 rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900', props.containerClass]));

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
            Loading...
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
            <table class="min-w-full divide-y divide-stone-200 text-left text-stone-600 dark:divide-neutral-700 dark:text-neutral-300" :class="tableDensityClass">
                <thead class="bg-stone-50 dark:bg-neutral-800/60">
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

                <div v-if="showPagination && normalizedLinks.length" class="flex justify-start md:col-start-2 md:justify-center">
                    <AdminPaginationLinks :links="normalizedLinks" />
                </div>

                <div v-if="showPerPage" class="flex justify-start md:col-start-3 md:justify-end">
                    <label
                        class="inline-flex items-center gap-2 whitespace-nowrap text-xs text-stone-500 dark:text-neutral-400"
                    >
                        <span>{{ perPageLabel }}</span>
                        <select
                            :value="normalizedPerPage"
                            class="rounded-sm border border-stone-200 bg-white px-2 py-1 text-xs text-stone-700 focus:border-green-500 focus:ring-green-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
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
                    </label>
                </div>
            </div>
        </div>
    </section>
</template>
