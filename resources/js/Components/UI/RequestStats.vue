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
    { key: 'total', label: t('requests.stats.total'), value: formatNumber(props.stats.total), tone: 'indigo' },
    { key: 'new', label: t('requests.stats.new'), value: formatNumber(props.stats.new), tone: 'amber', progress: buildKpiProgress(props.stats.new, props.stats.total) },
    { key: 'in-progress', label: t('requests.stats.in_progress'), value: formatNumber(props.stats.in_progress), tone: 'sky', progress: buildKpiProgress(props.stats.in_progress, props.stats.total) },
    { key: 'due-soon', label: t('requests.stats.due_soon'), value: formatNumber(props.stats.due_soon), tone: 'cyan', progress: buildKpiProgress(props.stats.due_soon, props.stats.total) },
    { key: 'stale', label: t('requests.stats.stale'), value: formatNumber(props.stats.stale), tone: 'orange', progress: buildKpiProgress(props.stats.stale, props.stats.total) },
    { key: 'breached', label: t('requests.stats.breached'), value: formatNumber(props.stats.breached), tone: 'red', progress: buildKpiProgress(props.stats.breached, props.stats.total) },
    { key: 'won', label: t('requests.stats.won'), value: formatNumber(props.stats.won), tone: 'emerald', progress: buildKpiProgress(props.stats.won, props.stats.total) },
    { key: 'lost', label: t('requests.stats.lost'), value: formatNumber(props.stats.lost), tone: 'rose', progress: buildKpiProgress(props.stats.lost, props.stats.total) },
    { key: 'unassigned', label: t('requests.stats.unassigned'), value: formatNumber(props.stats.unassigned), tone: 'stone', progress: buildKpiProgress(props.stats.unassigned, props.stats.total) },
]);
</script>

<template>
    <KpiMetricGrid :metrics="metrics" />
</template>
