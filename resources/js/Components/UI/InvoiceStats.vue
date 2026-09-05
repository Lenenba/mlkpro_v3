<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import KpiMetricGrid from '@/Components/Dashboard/KpiMetricGrid.vue';
import { useCurrencyFormatter } from '@/utils/currency';
import { buildKpiProgress } from '@/utils/kpi';

const { t } = useI18n();
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
        label: t('invoices.stats.total'),
        value: formatNumber(props.stats.total),
        tone: 'indigo',
    },
    {
        key: 'total_value',
        label: t('invoices.stats.total_value'),
        value: formatCurrency(props.stats.total_value),
        tone: 'emerald',
    },
    {
        key: 'outstanding',
        label: t('invoices.stats.outstanding'),
        value: formatCurrency(props.stats.outstanding),
        tone: 'amber',
        progress: buildKpiProgress(props.stats.outstanding, props.stats.total_value),
    },
    {
        key: 'open',
        label: t('invoices.stats.open'),
        value: formatNumber(props.stats.open),
        tone: 'sky',
        progress: buildKpiProgress(props.stats.open, props.stats.total),
    },
    {
        key: 'paid',
        label: t('invoices.stats.paid'),
        value: formatNumber(props.stats.paid),
        tone: 'emerald',
        progress: buildKpiProgress(props.stats.paid, props.stats.total),
    },
    {
        key: 'partial',
        label: t('invoices.stats.partial'),
        value: formatNumber(props.stats.partial),
        tone: 'rose',
        progress: buildKpiProgress(props.stats.partial, props.stats.total),
    },
]);
</script>

<template>
    <KpiMetricGrid :metrics="metrics" />
</template>
