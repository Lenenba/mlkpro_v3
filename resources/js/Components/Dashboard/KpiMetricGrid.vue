<script setup>
import KpiMetricCard from '@/Components/Dashboard/KpiMetricCard.vue';

defineProps({
    metrics: {
        type: Array,
        default: () => [],
    },
    gridClass: {
        type: String,
        default: 'grid-cols-[repeat(auto-fit,minmax(min(100%,12rem),1fr))]',
    },
    compact: {
        type: Boolean,
        default: false,
    },
    labelledBy: {
        type: String,
        default: undefined,
    },
    ariaLabel: {
        type: String,
        default: undefined,
    },
});

defineEmits(['activate']);
</script>

<template>
    <div
        class="grid min-w-0 gap-3"
        :class="gridClass"
        :role="labelledBy || ariaLabel ? 'group' : undefined"
        :aria-labelledby="labelledBy"
        :aria-label="ariaLabel"
    >
        <KpiMetricCard
            v-for="metric in metrics"
            :key="metric.key"
            :metric="metric"
            :compact="compact"
            @activate="$emit('activate', $event)"
        />
    </div>
</template>
