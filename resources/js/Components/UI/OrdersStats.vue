<script setup>
import { computed } from 'vue';
import KpiMetricGrid from '@/Components/Dashboard/KpiMetricGrid.vue';
import { useCurrencyFormatter } from '@/utils/currency';
import { buildKpiProgress } from '@/utils/kpi';

const props = defineProps({
    stats: {
        type: Object,
        required: true,
    },
});

const formatNumber = (value) =>
    Number(value || 0).toLocaleString(undefined, { maximumFractionDigits: 0 });

const { formatCurrency } = useCurrencyFormatter();

const metrics = computed(() => [
    {
        key: 'total',
        label: 'Commandes',
        value: formatNumber(props.stats.total),
        tone: 'indigo',
    },
    {
        key: 'total_value',
        label: 'Valeur totale',
        value: formatCurrency(props.stats.total_value),
        tone: 'emerald',
    },
    {
        key: 'pending',
        label: 'En attente',
        value: formatNumber(props.stats.pending),
        tone: 'amber',
        progress: buildKpiProgress(props.stats.pending, props.stats.total),
    },
    {
        key: 'draft',
        label: 'Brouillons',
        value: formatNumber(props.stats.draft),
        tone: 'sky',
        progress: buildKpiProgress(props.stats.draft, props.stats.total),
    },
    {
        key: 'canceled',
        label: 'Annulées',
        value: formatNumber(props.stats.canceled),
        tone: 'rose',
        progress: buildKpiProgress(props.stats.canceled, props.stats.total),
    },
]);
</script>

<template>
    <KpiMetricGrid :metrics="metrics" />
</template>
