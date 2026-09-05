<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
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

const { t } = useI18n();
const { formatCurrency } = useCurrencyFormatter();

const metrics = computed(() => [
    {
        key: 'total',
        label: t('services.stats.total'),
        value: formatNumber(props.stats.total),
        tone: 'indigo',
    },
    {
        key: 'active',
        label: t('services.stats.active'),
        value: formatNumber(props.stats.active),
        tone: 'emerald',
        progress: buildKpiProgress(props.stats.active, props.stats.total),
    },
    {
        key: 'archived',
        label: t('services.stats.archived'),
        value: formatNumber(props.stats.archived),
        tone: 'stone',
        progress: buildKpiProgress(props.stats.archived, props.stats.total),
    },
    {
        key: 'average_price',
        label: t('services.stats.avg_price'),
        value: formatCurrency(props.stats.average_price),
        tone: 'sky',
    },
]);
</script>

<template>
    <KpiMetricGrid :metrics="metrics" />
</template>
