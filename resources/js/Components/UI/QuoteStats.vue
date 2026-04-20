<script setup>
import { computed } from 'vue';
import { useCurrencyFormatter } from '@/utils/currency';

const props = defineProps({
    stats: {
        type: Object,
        required: true,
    },
});

const formatNumber = (value) =>
    Number(value || 0).toLocaleString(undefined, { maximumFractionDigits: 0 });

const { formatCurrency } = useCurrencyFormatter();

const portfolioCards = computed(() => ([
    {
        key: 'total',
        accent: 'border-t-indigo-600',
        icon: 'catalog',
        label: 'quotes.stats.total',
        value: formatNumber(props.stats.total),
    },
    {
        key: 'total_value',
        accent: 'border-t-emerald-600',
        icon: 'value',
        label: 'quotes.stats.total_value',
        value: formatCurrency(props.stats.total_value),
    },
    {
        key: 'average_value',
        accent: 'border-t-sky-600',
        icon: 'chart',
        label: 'quotes.stats.average_value',
        value: formatCurrency(props.stats.average_value),
    },
    {
        key: 'open',
        accent: 'border-t-amber-600',
        icon: 'open',
        label: 'quotes.stats.open',
        value: formatNumber(props.stats.open),
    },
    {
        key: 'accepted',
        accent: 'border-t-emerald-700',
        icon: 'accepted',
        label: 'quotes.stats.accepted',
        value: formatNumber(props.stats.accepted),
    },
    {
        key: 'declined',
        accent: 'border-t-rose-600',
        icon: 'declined',
        label: 'quotes.stats.declined',
        value: formatNumber(props.stats.declined),
    },
]));

const recoveryCards = computed(() => ([
    {
        key: 'never_followed',
        accent: 'border-t-violet-600',
        icon: 'never_followed',
        label: 'quotes.stats.never_followed',
        value: formatNumber(props.stats.never_followed),
    },
    {
        key: 'due',
        accent: 'border-t-orange-600',
        icon: 'due',
        label: 'quotes.stats.due',
        value: formatNumber(props.stats.due),
    },
    {
        key: 'viewed_not_accepted',
        accent: 'border-t-cyan-600',
        icon: 'viewed',
        label: 'quotes.stats.viewed_not_accepted',
        value: formatNumber(props.stats.viewed_not_accepted),
    },
    {
        key: 'high_value',
        accent: 'border-t-fuchsia-600',
        icon: 'high_value',
        label: 'quotes.stats.high_value',
        value: formatNumber(props.stats.high_value),
    },
    {
        key: 'sent_to_accepted_rate',
        accent: 'border-t-lime-600',
        icon: 'rate',
        label: 'quotes.stats.sent_to_accepted_rate',
        value: `${Number(props.stats.sent_to_accepted_rate || 0).toFixed(1)}%`,
    },
]));
</script>

<template>
    <div class="space-y-3">
        <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-2 md:gap-3 lg:gap-5">
            <div
                v-for="card in portfolioCards"
                :key="card.key"
                class="p-4 sm:p-5 bg-white border border-t-4 border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700"
                :class="card.accent"
            >
                <div class="sm:flex sm:gap-x-3">
                    <svg
                        v-if="card.icon === 'catalog'"
                        class="sm:order-2 mb-2 sm:mb-0 shrink-0 size-6 text-stone-400 dark:text-neutral-600"
                        xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"
                    >
                        <path d="M3 3h18v4H3z" />
                        <path d="M3 9h18v12H3z" />
                        <path d="M7 13h10" />
                        <path d="M7 17h6" />
                    </svg>
                    <svg
                        v-else-if="card.icon === 'value'"
                        class="sm:order-2 mb-2 sm:mb-0 shrink-0 size-6 text-stone-400 dark:text-neutral-600"
                        xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"
                    >
                        <path d="M12 1v22" />
                        <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7H14a3.5 3.5 0 0 1 0 7H6" />
                    </svg>
                    <svg
                        v-else-if="card.icon === 'chart'"
                        class="sm:order-2 mb-2 sm:mb-0 shrink-0 size-6 text-stone-400 dark:text-neutral-600"
                        xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"
                    >
                        <path d="M3 3v18h18" />
                        <path d="m7 13 4 4 6-8" />
                    </svg>
                    <svg
                        v-else-if="card.icon === 'open'"
                        class="sm:order-2 mb-2 sm:mb-0 shrink-0 size-6 text-stone-400 dark:text-neutral-600"
                        xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"
                    >
                        <path d="M3 12h18" />
                        <path d="M12 3v18" />
                    </svg>
                    <svg
                        v-else-if="card.icon === 'accepted'"
                        class="sm:order-2 mb-2 sm:mb-0 shrink-0 size-6 text-stone-400 dark:text-neutral-600"
                        xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"
                    >
                        <path d="M12 5l7 7-7 7-7-7z" />
                    </svg>
                    <svg
                        v-else
                        class="sm:order-2 mb-2 sm:mb-0 shrink-0 size-6 text-stone-400 dark:text-neutral-600"
                        xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"
                    >
                        <circle cx="12" cy="12" r="10" />
                        <path d="M15 9l-6 6" />
                        <path d="M9 9l6 6" />
                    </svg>
                    <div class="sm:order-1 grow space-y-1">
                        <h2 class="sm:mb-2 text-sm text-stone-500 dark:text-neutral-400">
                            {{ $t(card.label) }}
                        </h2>
                        <p class="text-lg md:text-xl font-semibold text-stone-800 dark:text-neutral-200">
                            {{ card.value }}
                        </p>
                    </div>
                </div>
            </div>
        </div>

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

        <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-5 gap-2 md:gap-3 lg:gap-5">
            <div
                v-for="card in recoveryCards"
                :key="card.key"
                class="p-4 sm:p-5 bg-white border border-t-4 border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700"
                :class="card.accent"
            >
                <div class="sm:flex sm:gap-x-3">
                    <svg
                        v-if="card.icon === 'never_followed'"
                        class="sm:order-2 mb-2 sm:mb-0 shrink-0 size-6 text-stone-400 dark:text-neutral-600"
                        xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"
                    >
                        <path d="M12 8v5" />
                        <path d="M12 16h.01" />
                        <circle cx="12" cy="12" r="9" />
                    </svg>
                    <svg
                        v-else-if="card.icon === 'due'"
                        class="sm:order-2 mb-2 sm:mb-0 shrink-0 size-6 text-stone-400 dark:text-neutral-600"
                        xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"
                    >
                        <circle cx="12" cy="12" r="9" />
                        <path d="M12 7v5l3 3" />
                    </svg>
                    <svg
                        v-else-if="card.icon === 'viewed'"
                        class="sm:order-2 mb-2 sm:mb-0 shrink-0 size-6 text-stone-400 dark:text-neutral-600"
                        xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"
                    >
                        <path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z" />
                        <circle cx="12" cy="12" r="3" />
                    </svg>
                    <svg
                        v-else-if="card.icon === 'high_value'"
                        class="sm:order-2 mb-2 sm:mb-0 shrink-0 size-6 text-stone-400 dark:text-neutral-600"
                        xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"
                    >
                        <path d="M12 2v20" />
                        <path d="M17 6H9.5a3.5 3.5 0 0 0 0 7H14a3.5 3.5 0 0 1 0 7H6" />
                    </svg>
                    <svg
                        v-else
                        class="sm:order-2 mb-2 sm:mb-0 shrink-0 size-6 text-stone-400 dark:text-neutral-600"
                        xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"
                    >
                        <path d="M3 3v18h18" />
                        <path d="m7 13 4 4 6-8" />
                    </svg>
                    <div class="sm:order-1 grow space-y-1">
                        <h2 class="sm:mb-2 text-sm text-stone-500 dark:text-neutral-400">
                            {{ $t(card.label) }}
                        </h2>
                        <p class="text-lg md:text-xl font-semibold text-stone-800 dark:text-neutral-200">
                            {{ card.value }}
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
