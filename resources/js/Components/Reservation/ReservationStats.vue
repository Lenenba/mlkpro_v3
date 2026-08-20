<script setup>
import { computed } from 'vue';

const props = defineProps({
    stats: {
        type: Object,
        default: () => ({}),
    },
    performance: {
        type: Object,
        default: () => ({}),
    },
    compact: {
        type: Boolean,
        default: false,
    },
});

const normalize = (value) => Number(value || 0).toLocaleString();
const normalizeMetric = (value, format = 'number') => {
    const numeric = Number(value || 0);
    if (!Number.isFinite(numeric)) {
        return '-';
    }

    if (format === 'percent') {
        return `${numeric.toFixed(1)}%`;
    }
    if (format === 'money') {
        return numeric.toLocaleString(undefined, {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        });
    }
    if (format === 'decimal') {
        return numeric.toFixed(1);
    }

    return numeric.toLocaleString();
};

const cards = computed(() => ([
    {
        key: 'total',
        label: 'reservations.stats.total',
        border: 'border-t-indigo-600',
        icon: 'layout-grid',
    },
    {
        key: 'pending',
        label: 'reservations.stats.pending',
        border: 'border-t-amber-500',
        icon: 'clock-3',
    },
    {
        key: 'confirmed',
        label: 'reservations.stats.confirmed',
        border: 'border-t-emerald-600',
        icon: 'badge-check',
    },
    {
        key: 'cancelled',
        label: 'reservations.status.cancelled',
        border: 'border-t-rose-600',
        icon: 'x-circle',
    },
    {
        key: 'today',
        label: 'reservations.stats.today',
        border: 'border-t-sky-600',
        icon: 'calendar-days',
    },
]));

const performanceCards = computed(() => {
    const performance = props.performance || {};
    if (!Object.keys(performance).length) {
        return [];
    }

    const audience = String(performance.audience || 'owner');
    const preset = String(performance.preset || 'service_general');

    const base = [
        {
            key: 'occupancy_rate',
            label: 'reservations.performance.occupancy_rate',
            format: 'percent',
            border: 'border-t-indigo-600',
        },
        {
            key: 'no_show_rate',
            label: 'reservations.performance.no_show_rate',
            format: 'percent',
            border: 'border-t-rose-600',
        },
        {
            key: audience === 'member' ? 'completion_rate' : 'reschedule_rate',
            label: audience === 'member'
                ? 'reservations.performance.completion_rate'
                : 'reservations.performance.reschedule_rate',
            format: 'percent',
            border: 'border-t-amber-500',
        },
        {
            key: 'avg_service_value',
            label: 'reservations.performance.avg_service_value',
            format: 'money',
            border: 'border-t-emerald-600',
        },
        {
            key: 'tip_rate',
            label: 'reservations.performance.tip_rate',
            format: 'percent',
            border: 'border-t-cyan-600',
        },
    ];

    if (preset === 'salon') {
        base.push({
            key: 'resource_reservation_rate',
            label: 'reservations.performance.resource_reservation_rate',
            format: 'percent',
            border: 'border-t-fuchsia-600',
        });
    } else if (preset === 'restaurant') {
        base.push({
            key: 'table_turnover',
            label: 'reservations.performance.table_turnover',
            format: 'decimal',
            border: 'border-t-fuchsia-600',
        });
        base.push({
            key: 'party_size_avg',
            label: 'reservations.performance.party_size_avg',
            format: 'decimal',
            border: 'border-t-violet-600',
        });
    }

    return base;
});

const hasPerformance = computed(() => performanceCards.value.length > 0);
</script>

<template>
    <div :class="compact ? 'space-y-2' : 'space-y-3'">
        <div
            class="grid"
            :class="compact ? 'gap-2' : 'grid-cols-2 gap-2 md:grid-cols-5 md:gap-3 lg:gap-5'"
            :style="compact ? { gridTemplateColumns: 'repeat(auto-fit, minmax(min(100%, 8.5rem), 1fr))' } : undefined"
        >
            <div
                v-for="card in cards"
                :key="`reservation-stat-${card.key}`"
                class="rounded-sm border border-stone-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-800"
                :class="[
                    card.border,
                    compact ? 'border-t-2 px-2.5 py-2' : 'border-t-4 p-4',
                ]"
            >
                <div class="flex items-start justify-between gap-2">
                    <div :class="compact ? 'text-[11px]' : 'text-xs'" class="text-stone-500 dark:text-neutral-400">{{ $t(card.label) }}</div>
                    <svg
                        v-if="card.icon === 'layout-grid'"
                        class="text-stone-400 dark:text-neutral-500"
                        :class="compact ? 'size-3.5' : 'size-4'"
                        xmlns="http://www.w3.org/2000/svg"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                    >
                        <rect x="3" y="3" width="7" height="7" />
                        <rect x="14" y="3" width="7" height="7" />
                        <rect x="14" y="14" width="7" height="7" />
                        <rect x="3" y="14" width="7" height="7" />
                    </svg>
                    <svg
                        v-else-if="card.icon === 'clock-3'"
                        class="text-stone-400 dark:text-neutral-500"
                        :class="compact ? 'size-3.5' : 'size-4'"
                        xmlns="http://www.w3.org/2000/svg"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                    >
                        <circle cx="12" cy="12" r="10" />
                        <path d="M12 6v6l4 2" />
                    </svg>
                    <svg
                        v-else-if="card.icon === 'badge-check'"
                        class="text-stone-400 dark:text-neutral-500"
                        :class="compact ? 'size-3.5' : 'size-4'"
                        xmlns="http://www.w3.org/2000/svg"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                    >
                        <path d="m12 3 2.5 2.5L18 6l.5 3.5L21 12l-2.5 2.5L18 18l-3.5.5L12 21l-2.5-2.5L6 18l-.5-3.5L3 12l2.5-2.5L6 6l3.5-.5z" />
                        <path d="m9 12 2 2 4-4" />
                    </svg>
                    <svg
                        v-else-if="card.icon === 'x-circle'"
                        class="text-stone-400 dark:text-neutral-500"
                        :class="compact ? 'size-3.5' : 'size-4'"
                        xmlns="http://www.w3.org/2000/svg"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                    >
                        <circle cx="12" cy="12" r="10" />
                        <path d="m15 9-6 6" />
                        <path d="m9 9 6 6" />
                    </svg>
                    <svg
                        v-else
                        class="text-stone-400 dark:text-neutral-500"
                        :class="compact ? 'size-3.5' : 'size-4'"
                        xmlns="http://www.w3.org/2000/svg"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                    >
                        <path d="M8 2v4" />
                        <path d="M16 2v4" />
                        <rect x="3" y="4" width="18" height="18" rx="2" />
                        <path d="M3 10h18" />
                    </svg>
                </div>
                <div
                    class="font-semibold text-stone-800 dark:text-neutral-100"
                    :class="compact ? 'mt-0.5 text-base leading-tight' : 'mt-1 text-lg'"
                >
                    {{ normalize(stats[card.key]) }}
                </div>
            </div>
        </div>

        <details
            v-if="hasPerformance"
            class="group rounded-sm border border-stone-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900"
            :class="compact ? 'px-3 py-2' : 'p-3'"
            :open="!compact"
        >
            <summary
                class="flex cursor-pointer list-none items-center justify-between gap-2 text-xs font-semibold uppercase tracking-wide text-stone-500 marker:hidden dark:text-neutral-400"
                :class="compact ? 'group-open:mb-2' : 'mb-2 cursor-default'"
            >
                <span>{{ $t('reservations.performance.title', { days: performance.window_days || 30 }) }}</span>
                <svg
                    v-if="compact"
                    class="size-3.5 shrink-0 transition-transform group-open:rotate-180"
                    xmlns="http://www.w3.org/2000/svg"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    aria-hidden="true"
                >
                    <path d="m6 9 6 6 6-6" />
                </svg>
            </summary>
            <div
                class="grid"
                :class="compact ? 'gap-1.5' : 'grid-cols-2 gap-2 md:grid-cols-3 lg:grid-cols-6'"
                :style="compact ? { gridTemplateColumns: 'repeat(auto-fit, minmax(min(100%, 7.5rem), 1fr))' } : undefined"
            >
                <div
                    v-for="card in performanceCards"
                    :key="`reservation-performance-${card.key}`"
                    class="rounded-sm border border-stone-200 bg-stone-50 dark:border-neutral-700 dark:bg-neutral-800"
                    :class="[
                        card.border,
                        compact ? 'border-t-2 px-2 py-1.5' : 'border-t-4 p-3',
                    ]"
                >
                    <div class="uppercase tracking-wide text-stone-500 dark:text-neutral-400" :class="compact ? 'text-[10px]' : 'text-[11px]'">
                        {{ $t(card.label) }}
                    </div>
                    <div class="font-semibold text-stone-800 dark:text-neutral-100" :class="compact ? 'mt-0.5 text-sm' : 'mt-1 text-base'">
                        {{ normalizeMetric(performance[card.key], card.format) }}
                    </div>
                </div>
            </div>
        </details>
    </div>
</template>
