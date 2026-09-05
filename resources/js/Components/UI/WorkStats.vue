<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
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

const { t } = useI18n();

const metrics = computed(() => [
    {
        key: 'total',
        label: t('jobs.stats.total'),
        value: formatNumber(props.stats.total),
        tone: 'indigo',
    },
    {
        key: 'scheduled',
        label: t('jobs.stats.scheduled'),
        value: formatNumber(props.stats.scheduled),
        tone: 'sky',
        progress: buildKpiProgress(props.stats.scheduled, props.stats.total),
    },
    {
        key: 'in_progress',
        label: t('jobs.stats.in_progress'),
        value: formatNumber(props.stats.in_progress),
        tone: 'amber',
        progress: buildKpiProgress(props.stats.in_progress, props.stats.total),
    },
    {
        key: 'completed',
        label: t('jobs.stats.completed'),
        value: formatNumber(props.stats.completed),
        tone: 'emerald',
        progress: buildKpiProgress(props.stats.completed, props.stats.total),
    },
    {
        key: 'cancelled',
        label: t('jobs.stats.cancelled'),
        value: formatNumber(props.stats.cancelled),
        tone: 'rose',
        progress: buildKpiProgress(props.stats.cancelled, props.stats.total),
    },
]);
</script>

<template>
    <KpiMetricGrid :metrics="metrics" />
</template>
