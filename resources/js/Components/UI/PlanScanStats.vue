<script setup>
import { computed } from 'vue';
import KpiMetricGrid from '@/Components/Dashboard/KpiMetricGrid.vue';
import { buildKpiProgress } from '@/utils/kpi';

const props = defineProps({
    stats: {
        type: Object,
        required: true,
    },
});

const formatNumber = (value) =>
    Number(value || 0).toLocaleString(undefined, { maximumFractionDigits: 0 });

const metrics = computed(() => [
    {
        key: 'total',
        label: 'Total scans',
        value: formatNumber(props.stats.total),
        tone: 'indigo',
    },
    {
        key: 'ready',
        label: 'Ready',
        value: formatNumber(props.stats.ready),
        tone: 'emerald',
        progress: buildKpiProgress(props.stats.ready, props.stats.total),
    },
    {
        key: 'processing',
        label: 'Processing',
        value: formatNumber(props.stats.processing),
        tone: 'amber',
        progress: buildKpiProgress(props.stats.processing, props.stats.total),
    },
    {
        key: 'failed',
        label: 'Failed',
        value: formatNumber(props.stats.failed),
        tone: 'rose',
        progress: buildKpiProgress(props.stats.failed, props.stats.total),
    },
]);
</script>

<template>
    <KpiMetricGrid :metrics="metrics" />
</template>
