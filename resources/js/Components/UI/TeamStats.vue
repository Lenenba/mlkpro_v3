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
        label: t('team.stats.total_members'),
        value: formatNumber(props.stats.total),
        tone: 'indigo',
    },
    {
        key: 'active',
        label: t('team.stats.active'),
        value: formatNumber(props.stats.active),
        tone: 'emerald',
        progress: buildKpiProgress(props.stats.active, props.stats.total),
    },
    {
        key: 'admins',
        label: t('team.stats.administrators'),
        value: formatNumber(props.stats.admins),
        tone: 'sky',
        progress: buildKpiProgress(props.stats.admins, props.stats.total),
    },
    {
        key: 'members',
        label: t('team.stats.members'),
        value: formatNumber(props.stats.members),
        tone: 'amber',
        progress: buildKpiProgress(props.stats.members, props.stats.total),
    },
]);
</script>

<template>
    <KpiMetricGrid :metrics="metrics" />
</template>
