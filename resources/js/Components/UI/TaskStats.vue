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

const { t } = useI18n();

const formatNumber = (value) =>
    Number(value || 0).toLocaleString(undefined, { maximumFractionDigits: 0 });

const metrics = computed(() => [
    {
        key: 'total',
        label: t('tasks.stats.total'),
        value: formatNumber(props.stats.total),
        tone: 'indigo',
    },
    {
        key: 'todo',
        label: t('tasks.stats.todo'),
        value: formatNumber(props.stats.todo),
        tone: 'amber',
        progress: buildKpiProgress(props.stats.todo, props.stats.total),
    },
    {
        key: 'in_progress',
        label: t('tasks.stats.in_progress'),
        value: formatNumber(props.stats.in_progress),
        tone: 'sky',
        progress: buildKpiProgress(props.stats.in_progress, props.stats.total),
    },
    {
        key: 'done',
        label: t('tasks.stats.done'),
        value: formatNumber(props.stats.done),
        tone: 'emerald',
        progress: buildKpiProgress(props.stats.done, props.stats.total),
    },
    {
        key: 'cancelled',
        label: t('tasks.stats.cancelled'),
        value: formatNumber(props.stats.cancelled),
        tone: 'rose',
        progress: buildKpiProgress(props.stats.cancelled, props.stats.total),
    },
]);
</script>

<template>
    <KpiMetricGrid :metrics="metrics" />
</template>
