<script setup>
import { computed } from 'vue';
import KpiMiniChart from '@/Components/Dashboard/KpiMiniChart.vue';

const props = defineProps({
    label: {
        type: String,
        default: '',
    },
    chart: {
        type: Object,
        default: null,
    },
    points: {
        type: Array,
        default: () => [],
    },
    tone: {
        type: String,
        default: '',
    },
    colorClass: {
        type: String,
        default: 'bg-stone-400/70 dark:bg-neutral-500/50',
    },
});

const legacyTone = computed(() => {
    const match = String(props.colorClass).match(/(?:bg|text|stroke)-([a-z]+)-\d+/u);

    return match?.[1] ?? 'neutral';
});
const resolvedTone = computed(() => props.tone || props.chart?.tone || legacyTone.value);
</script>

<template>
    <KpiMiniChart
        :label="label"
        :chart="chart"
        :points="points"
        :tone="resolvedTone"
        :height="40"
    />
</template>
