<script setup>
import { computed, useSlots } from 'vue';
import AdminPaginationLinks from '@/Components/DataTable/AdminPaginationLinks.vue';

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
    containerClass: {
        type: [String, Array, Object],
        default: '',
    },
});
const slots = useSlots();

const normalizedRows = computed(() => (Array.isArray(props.rows) ? props.rows : []));
const normalizedLinks = computed(() => (Array.isArray(props.links) ? props.links : []));
const hasRows = computed(() => normalizedRows.value.length > 0);
const tableDensityClass = computed(() => (props.dense ? 'text-xs' : 'text-sm'));
const tbodyClass = computed(() => [
    'divide-y divide-stone-100 dark:divide-neutral-800',
    props.striped ? '[&>tr:nth-child(odd)]:bg-stone-50/50 dark:[&>tr:nth-child(odd)]:bg-neutral-800/20' : '',
]);

const resolveRowKey = (row, index) => {
    if (typeof props.rowKey === 'function') {
        return props.rowKey(row, index);
    }

    if (row && typeof row === 'object' && props.rowKey in row) {
        return row[props.rowKey];
    }

    return index;
};
</script>

<template>
    <section :class="['flex flex-col space-y-4 rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900', containerClass]">
        <slot name="toolbar" />

        <div v-if="resultLabel" class="text-sm text-stone-500 dark:text-neutral-400">
            {{ resultLabel }}
        </div>

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

        <div v-if="normalizedLinks.length" class="flex flex-wrap items-center justify-between gap-3 border-t border-stone-200 pt-4 dark:border-neutral-700">
            <slot name="pagination_prefix" />
            <AdminPaginationLinks :links="normalizedLinks" />
        </div>
    </section>
</template>
