<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import KpiMetricGrid from '@/Components/Dashboard/KpiMetricGrid.vue';

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

const { t } = useI18n();

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
        tone: 'indigo',
    },
    {
        key: 'pending',
        label: 'reservations.stats.pending',
        tone: 'amber',
    },
    {
        key: 'confirmed',
        label: 'reservations.stats.confirmed',
        tone: 'emerald',
    },
    {
        key: 'cancelled',
        label: 'reservations.status.cancelled',
        tone: 'rose',
    },
    {
        key: 'today',
        label: 'reservations.stats.today',
        tone: 'sky',
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
            tone: 'indigo',
        },
        {
            key: 'no_show_rate',
            label: 'reservations.performance.no_show_rate',
            format: 'percent',
            tone: 'rose',
        },
        {
            key: audience === 'member' ? 'completion_rate' : 'reschedule_rate',
            label: audience === 'member'
                ? 'reservations.performance.completion_rate'
                : 'reservations.performance.reschedule_rate',
            format: 'percent',
            tone: 'amber',
        },
        {
            key: 'avg_service_value',
            label: 'reservations.performance.avg_service_value',
            format: 'money',
            tone: 'emerald',
        },
        {
            key: 'tip_rate',
            label: 'reservations.performance.tip_rate',
            format: 'percent',
            tone: 'cyan',
        },
    ];

    if (preset === 'salon') {
        base.push({
            key: 'resource_reservation_rate',
            label: 'reservations.performance.resource_reservation_rate',
            format: 'percent',
            tone: 'fuchsia',
        });
    } else if (preset === 'restaurant') {
        base.push({
            key: 'table_turnover',
            label: 'reservations.performance.table_turnover',
            format: 'decimal',
            tone: 'fuchsia',
        });
        base.push({
            key: 'party_size_avg',
            label: 'reservations.performance.party_size_avg',
            format: 'decimal',
            tone: 'violet',
        });
    }

    return base;
});

const hasPerformance = computed(() => performanceCards.value.length > 0);

const statusKeys = new Set(['pending', 'confirmed', 'cancelled']);

const reservationMetrics = computed(() => {
    const total = Number(props.stats.total || 0);

    return cards.value.map((card) => {
        const value = Number(props.stats[card.key] || 0);

        return {
            key: card.key,
            label: t(card.label),
            value: normalize(value),
            tone: card.tone,
            progress: statusKeys.has(card.key) && total > 0
                ? { value, max: total }
                : undefined,
        };
    });
});

const performanceMetrics = computed(() => performanceCards.value.map((card) => ({
    key: card.key,
    label: t(card.label),
    value: normalizeMetric(props.performance[card.key], card.format),
    tone: card.tone,
    progress: card.format === 'percent'
        ? { value: Number(props.performance[card.key] || 0), max: 100 }
        : undefined,
})));

const responsiveMetricGridClass = 'grid-cols-[repeat(auto-fit,minmax(min(100%,12rem),1fr))] !gap-2 md:!gap-3';
</script>

<template>
    <div
        :class="compact
            ? 'grid grid-cols-1 items-start gap-3'
            : 'space-y-3'"
    >
        <section
            :class="compact
                ? 'min-w-0 rounded-sm border border-stone-200 bg-stone-50/50 p-2.5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900'
                : ''"
        >
            <div
                v-if="compact"
                class="mb-2 text-[10px] font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400"
            >
                {{ $t('reservations.title') }}
            </div>
            <KpiMetricGrid
                :metrics="reservationMetrics"
                :grid-class="responsiveMetricGridClass"
                :compact="compact"
                :aria-label="$t('reservations.title')"
            />
        </section>

        <section
            v-if="compact && hasPerformance"
            class="min-w-0 rounded-sm border border-stone-200 bg-stone-50/50 p-2.5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900"
        >
            <div class="mb-2 text-[10px] font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                {{ $t('reservations.performance.title', { days: performance.window_days || 30 }) }}
            </div>
            <KpiMetricGrid
                :metrics="performanceMetrics"
                :grid-class="responsiveMetricGridClass"
                compact
                :aria-label="$t('reservations.performance.title', { days: performance.window_days || 30 })"
            />
        </section>

        <details
            v-else-if="hasPerformance"
            class="group rounded-sm border border-stone-200 bg-white p-3 shadow-sm dark:border-neutral-700 dark:bg-neutral-900"
            open
        >
            <summary
                class="mb-2 flex cursor-default list-none items-center justify-between gap-2 text-xs font-semibold uppercase tracking-wide text-stone-500 marker:hidden dark:text-neutral-400"
            >
                <span>{{ $t('reservations.performance.title', { days: performance.window_days || 30 }) }}</span>
            </summary>
            <KpiMetricGrid
                :metrics="performanceMetrics"
                :aria-label="$t('reservations.performance.title', { days: performance.window_days || 30 })"
            />
        </details>
    </div>
</template>
