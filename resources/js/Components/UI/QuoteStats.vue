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

const portfolioCards = computed(() => ([
    {
        key: 'total',
        tone: 'indigo',
        label: t('quotes.stats.total'),
        value: formatNumber(props.stats.total),
    },
    {
        key: 'total_value',
        tone: 'emerald',
        label: t('quotes.stats.total_value'),
        value: formatCurrency(props.stats.total_value),
    },
    {
        key: 'average_value',
        tone: 'sky',
        label: t('quotes.stats.average_value'),
        value: formatCurrency(props.stats.average_value),
    },
    {
        key: 'open',
        tone: 'amber',
        label: t('quotes.stats.open'),
        value: formatNumber(props.stats.open),
        progress: buildKpiProgress(props.stats.open, props.stats.total),
    },
    {
        key: 'accepted',
        tone: 'emerald',
        label: t('quotes.stats.accepted'),
        value: formatNumber(props.stats.accepted),
        progress: buildKpiProgress(props.stats.accepted, props.stats.total),
    },
    {
        key: 'declined',
        tone: 'rose',
        label: t('quotes.stats.declined'),
        value: formatNumber(props.stats.declined),
        progress: buildKpiProgress(props.stats.declined, props.stats.total),
    },
]));

const recoveryCards = computed(() => ([
    {
        key: 'never_followed',
        tone: 'violet',
        label: t('quotes.stats.never_followed'),
        value: formatNumber(props.stats.never_followed),
        progress: buildKpiProgress(props.stats.never_followed, props.stats.total),
    },
    {
        key: 'due',
        tone: 'orange',
        label: t('quotes.stats.due'),
        value: formatNumber(props.stats.due),
        progress: buildKpiProgress(props.stats.due, props.stats.total),
    },
    {
        key: 'viewed_not_accepted',
        tone: 'cyan',
        label: t('quotes.stats.viewed_not_accepted'),
        value: formatNumber(props.stats.viewed_not_accepted),
        progress: buildKpiProgress(props.stats.viewed_not_accepted, props.stats.total),
    },
    {
        key: 'high_value',
        tone: 'fuchsia',
        label: t('quotes.stats.high_value'),
        value: formatNumber(props.stats.high_value),
        progress: buildKpiProgress(props.stats.high_value, props.stats.total),
    },
    {
        key: 'sent_to_accepted_rate',
        tone: 'lime',
        label: t('quotes.stats.sent_to_accepted_rate'),
        value: `${Number(props.stats.sent_to_accepted_rate || 0).toFixed(1)}%`,
        progress: buildKpiProgress(props.stats.sent_to_accepted_rate, 100),
    },
]));
</script>

<template>
    <div class="space-y-3">
        <KpiMetricGrid :metrics="portfolioCards" />

        <div class="rounded-sm border border-stone-200 bg-white px-4 py-3 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
            <div class="flex flex-col gap-1 md:flex-row md:items-end md:justify-between">
                <div>
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-200">
                        {{ $t('quotes.stats.recovery_title') }}
                    </h2>
                    <p class="text-xs text-stone-500 dark:text-neutral-400">
                        {{ $t('quotes.stats.recovery_subtitle') }}
                    </p>
                </div>
                <p class="text-xs text-stone-500 dark:text-neutral-400">
                    {{ $t('quotes.stats.based_on_filters') }}
                </p>
            </div>
        </div>

        <KpiMetricGrid :metrics="recoveryCards" />
    </div>
</template>
