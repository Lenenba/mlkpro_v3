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

const { t } = useI18n();

const formatNumber = (value) =>
    Number(value || 0).toLocaleString(undefined, { maximumFractionDigits: 0 });

const { formatCurrency } = useCurrencyFormatter();

const metrics = computed(() => [
    {
        key: 'total',
        label: t('sales.stats.total'),
        value: formatNumber(props.stats.total),
        tone: 'indigo',
    },
    {
        key: 'total_value',
        label: t('sales.stats.revenue'),
        value: formatCurrency(props.stats.total_value),
        tone: 'emerald',
    },
    {
        key: 'paid_value',
        label: t('sales.stats.collected'),
        value: formatCurrency(props.stats.paid_value),
        tone: 'emerald',
        progress: buildKpiProgress(props.stats.paid_value, props.stats.total_value),
    },
    {
        key: 'pending',
        label: t('sales.stats.pending'),
        value: formatNumber(props.stats.pending),
        tone: 'amber',
        progress: buildKpiProgress(props.stats.pending, props.stats.total),
    },
    {
        key: 'draft',
        label: t('sales.stats.draft'),
        value: formatNumber(props.stats.draft),
        tone: 'sky',
        progress: buildKpiProgress(props.stats.draft, props.stats.total),
    },
    {
        key: 'canceled',
        label: t('sales.stats.canceled'),
        value: formatNumber(props.stats.canceled),
        tone: 'rose',
        progress: buildKpiProgress(props.stats.canceled, props.stats.total),
    },
]);
</script>

<template>
    <KpiMetricGrid :metrics="metrics" />
</template>
