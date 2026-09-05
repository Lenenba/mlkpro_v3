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

const { formatCurrency } = useCurrencyFormatter();
const { t } = useI18n();

const formatRatio = (value) =>
    Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const metrics = computed(() => [
    {
        key: 'total',
        label: t('products.stats.total'),
        value: formatNumber(props.stats.total),
        tone: 'emerald',
    },
    {
        key: 'inventory-value',
        label: t('products.stats.inventory_value'),
        value: formatCurrency(props.stats.inventory_value),
        tone: 'sky',
    },
    {
        key: 'in-stock',
        label: t('products.stats.in_stock'),
        value: formatNumber(props.stats.in_stock),
        tone: 'blue',
        progress: buildKpiProgress(props.stats.in_stock, props.stats.total),
    },
    {
        key: 'low-stock',
        label: t('products.stats.low_stock'),
        value: formatNumber(props.stats.low_stock),
        tone: 'amber',
        progress: buildKpiProgress(props.stats.low_stock, props.stats.total),
    },
    {
        key: 'out-of-stock',
        label: t('products.stats.out_of_stock'),
        value: formatNumber(props.stats.out_of_stock),
        tone: 'red',
        progress: buildKpiProgress(props.stats.out_of_stock, props.stats.total),
    },
    {
        key: 'rotation',
        label: t('products.stats.rotation'),
        value: `${formatRatio(props.stats.rotation)}x`,
        tone: 'indigo',
    },
]);
</script>

<template>
    <KpiMetricGrid :metrics="metrics" />
</template>
