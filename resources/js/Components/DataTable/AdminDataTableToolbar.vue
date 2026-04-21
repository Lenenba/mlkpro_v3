<script setup>
import { useSlots } from 'vue';
import { crmButtonClass } from '@/utils/crmButtonStyles';

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
                    :class="crmButtonClass('secondary', 'toolbar')"
                    :disabled="busy"
                    @click="$emit('toggle-filters')"
                >
                    {{ filtersLabel }}
                </button>

                <button
                    v-if="showClear"
                    type="button"
                    :class="crmButtonClass('secondary', 'toolbar')"
                    :disabled="busy"
                    @click="$emit('clear')"
                >
                    {{ clearLabel }}
                </button>

                <button
                    v-if="showApply"
                    type="submit"
                    :class="crmButtonClass('primary', 'toolbar')"
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
