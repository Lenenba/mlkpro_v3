<script setup>
import { computed } from 'vue';

const props = defineProps({
    stats: {
        type: Object,
        default: () => ({}),
    },
});

const normalize = (value) => Number(value || 0).toLocaleString();

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
</script>

<template>
    <div class="grid grid-cols-2 gap-2 md:grid-cols-5 md:gap-3 lg:gap-5">
        <div
            v-for="card in cards"
            :key="`reservation-stat-${card.key}`"
            class="rounded-sm border border-stone-200 border-t-4 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800"
            :class="card.border"
        >
            <div class="flex items-start justify-between gap-2">
                <div class="text-xs text-stone-500 dark:text-neutral-400">{{ $t(card.label) }}</div>
                <svg
                    v-if="card.icon === 'layout-grid'"
                    class="size-4 text-stone-400 dark:text-neutral-500"
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
                    class="size-4 text-stone-400 dark:text-neutral-500"
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
                    class="size-4 text-stone-400 dark:text-neutral-500"
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
                    class="size-4 text-stone-400 dark:text-neutral-500"
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
                    class="size-4 text-stone-400 dark:text-neutral-500"
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
            <div class="mt-1 text-lg font-semibold text-stone-800 dark:text-neutral-100">
                {{ normalize(stats[card.key]) }}
            </div>
        </div>
    </div>
</template>
