<script setup>
import { computed } from 'vue';
import KpiMetricGrid from '@/Components/Dashboard/KpiMetricGrid.vue';

const props = defineProps({
    cards: {
        type: Array,
        default: () => [],
    },
    labelledBy: {
        type: String,
        default: undefined,
    },
});

defineEmits(['activate']);

const metrics = computed(() => props.cards.map((card) => ({
    ...card,
    label: card.label,
    value: card.value,
    context: card.detail,
    action: card.action,
    interactive: card.interactive,
    active: card.active,
    ariaLabel: card.ariaLabel,
    progress: card.progress,
})));
</script>

<template>
    <KpiMetricGrid
        :metrics="metrics"
        :labelled-by="labelledBy"
        @activate="$emit('activate', $event)"
    />
</template>
