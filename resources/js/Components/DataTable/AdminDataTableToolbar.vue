<script setup>
import { useSlots } from 'vue';

defineEmits(['toggle-filters', 'apply', 'clear']);

defineProps({
    showFilters: {
        type: Boolean,
        default: false,
    },
    showClear: {
        type: Boolean,
        default: true,
    },
    showApply: {
        type: Boolean,
        default: true,
    },
    searchPlaceholder: {
        type: String,
        default: '',
    },
    busy: {
        type: Boolean,
        default: false,
    },
    filtersLabel: {
        type: String,
        default: 'Filters',
    },
    clearLabel: {
        type: String,
        default: 'Clear',
    },
    applyLabel: {
        type: String,
        default: 'Apply',
    },
});

const slots = useSlots();
</script>

<template>
    <form class="space-y-3" @submit.prevent="$emit('apply')">
        <div class="flex flex-col gap-2 lg:flex-row lg:items-center">
            <div v-if="slots.search" class="flex-1">
                <slot name="search" :search-placeholder="searchPlaceholder" />
            </div>

            <div class="flex flex-wrap items-center justify-end gap-2">
                <button
                    v-if="slots.filters"
                    type="button"
                    class="inline-flex items-center gap-x-1.5 rounded-sm border border-stone-200 bg-white px-2.5 py-2 text-xs font-medium text-stone-700 hover:bg-stone-50 focus:outline-none focus:ring-2 focus:ring-green-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200 dark:hover:bg-neutral-700"
                    :disabled="busy"
                    @click="$emit('toggle-filters')"
                >
                    {{ filtersLabel }}
                </button>

                <button
                    v-if="showClear"
                    type="button"
                    class="inline-flex items-center gap-x-1.5 rounded-sm border border-stone-200 bg-white px-2.5 py-2 text-xs font-medium text-stone-700 hover:bg-stone-50 focus:outline-none focus:ring-2 focus:ring-green-500 disabled:pointer-events-none disabled:opacity-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200 dark:hover:bg-neutral-700"
                    :disabled="busy"
                    @click="$emit('clear')"
                >
                    {{ clearLabel }}
                </button>

                <button
                    v-if="showApply"
                    type="submit"
                    class="inline-flex items-center gap-x-2 rounded-sm border border-transparent bg-green-600 px-3 py-2 text-xs font-medium text-white hover:bg-green-700 disabled:pointer-events-none disabled:opacity-50"
                    :disabled="busy"
                >
                    {{ applyLabel }}
                </button>

                <slot name="actions" />
            </div>
        </div>

        <div v-if="slots.filters && showFilters" class="grid gap-3 md:grid-cols-3">
            <slot name="filters" />
        </div>
    </form>
</template>
