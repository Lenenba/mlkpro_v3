<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { formatTrendValue } from '@/utils/kpi';

const props = defineProps({
    trend: {
        type: Object,
        default: () => ({
            direction: 'flat',
            isPositive: true,
            percent: 0,
        }),
    },
});

const { t } = useI18n();

const badgeClass = computed(() => {
    if (!props.trend || props.trend.direction === 'flat') {
        return 'inline-flex items-center gap-1 rounded-full bg-stone-100 px-2 py-0.5 text-[10px] font-semibold text-stone-600 dark:bg-neutral-700 dark:text-neutral-300';
    }

    return props.trend.isPositive
        ? 'inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-semibold text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300'
        : 'inline-flex items-center gap-1 rounded-full bg-red-100 px-2 py-0.5 text-[10px] font-semibold text-red-700 dark:bg-red-500/10 dark:text-red-300';
});

const arrowClass = computed(() => {
    if (!props.trend || props.trend.direction === 'flat') {
        return 'opacity-60';
    }
    return props.trend.direction === 'down' ? 'rotate-180' : '';
});

const title = computed(() => {
    if (!props.trend) {
        return t('dashboard.trend.none');
    }

    const directionLabel =
        props.trend.direction === 'flat'
            ? t('dashboard.trend.no_change')
            : props.trend.direction === 'up'
                ? t('dashboard.trend.up')
                : t('dashboard.trend.down');

    const valueLabel =
        props.trend.percent === null ? t('dashboard.trend.new') : `${props.trend.percent.toFixed(1)}%`;

    return t('dashboard.trend.summary', { direction: directionLabel, value: valueLabel });
});
</script>

<template>
    <span :class="badgeClass" :title="title">
        <svg
            class="size-3 transition-transform"
            :class="arrowClass"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="2"
            stroke-linecap="round"
            stroke-linejoin="round"
        >
            <polyline points="3 17 9 11 13 15 21 7" />
            <polyline points="14 7 21 7 21 14" />
        </svg>
        {{ formatTrendValue(trend, { newLabel: t('dashboard.trend.new') }) }}
    </span>
</template>
