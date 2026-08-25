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
    variant: {
        type: String,
        default: 'module',
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
        class="grid min-w-0"
        :class="[
            gridClass,
            variant === 'record'
                ? 'gap-px overflow-hidden rounded-lg border border-stone-200 bg-stone-200 dark:border-neutral-700 dark:bg-neutral-700'
                : 'gap-3',
        ]"
        :role="labelledBy || ariaLabel ? 'group' : undefined"
        :aria-labelledby="labelledBy"
        :aria-label="ariaLabel"
    >
        <KpiMetricCard
            v-for="metric in metrics"
            :key="metric.key"
            :metric="metric"
            :compact="compact"
            :variant="variant"
            @activate="$emit('activate', $event)"
        />
    </div>
</template>
