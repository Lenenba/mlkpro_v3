<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

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
const value = computed(() => props.trend?.percent === null
    ? t('dashboard.trend.new')
    : `${(props.trend?.percent ?? 0).toFixed(1)}%`);

const badgeClass = computed(() => {
    if (!props.trend || props.trend.direction === 'flat') {
        return 'bg-stone-100 text-stone-600 dark:bg-neutral-700 dark:text-neutral-300';
    }

    return props.trend.isPositive
        ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300'
        : 'bg-red-100 text-red-700 dark:bg-red-500/10 dark:text-red-300';
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

    return t('dashboard.trend.summary', { direction: directionLabel, value: value.value });
});
</script>

<template>
    <span
        class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-semibold"
        :class="badgeClass"
        :title="title"
        :aria-label="title"
    >
        <svg
            class="size-3"
            :class="arrowClass"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="2"
            stroke-linecap="round"
            stroke-linejoin="round"
            aria-hidden="true"
        >
            <polyline points="3 17 9 11 13 15 21 7" />
            <polyline points="14 7 21 7 21 14" />
        </svg>
        {{ value }}
    </span>
</template>
