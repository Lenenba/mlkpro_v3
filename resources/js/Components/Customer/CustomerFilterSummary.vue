<script setup>
import AdminFilterSummary from '@/Components/DataTable/AdminFilterSummary.vue';

const props = defineProps({
    matchingCount: {
        type: Number,
        default: 0,
    },
    activeFilters: {
        type: Array,
        default: () => [],
    },
    quickFilterMode: {
        type: String,
        default: 'all',
    },
    quickFilterCount: {
        type: Number,
        default: 0,
    },
    busy: {
        type: Boolean,
        default: false,
    },
});

defineEmits(['update:quick-filter-mode', 'remove', 'clear']);
</script>

<template>
    <AdminFilterSummary
        v-bind="props"
        summary-id="customer-filter-summary-title"
        i18n-prefix="customers"
        @update:quick-filter-mode="$emit('update:quick-filter-mode', $event)"
        @remove="$emit('remove', $event)"
        @clear="$emit('clear')"
    />
</template>
