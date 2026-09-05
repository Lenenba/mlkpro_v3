<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { crmButtonClass } from '@/utils/crmButtonStyles';

const props = defineProps({
    summaryId: {
        type: String,
        default: 'filter-summary-title',
    },
    i18nPrefix: {
        type: String,
        default: 'customers',
    },
    matchingCount: {
        type: Number,
        default: 0,
    },
    activeFilters: {
        type: Array,
        default: () => [],
    },
    quickFilterMode: {
        type: String,
        default: 'all',
    },
    quickFilterCount: {
        type: Number,
        default: 0,
    },
    busy: {
        type: Boolean,
        default: false,
    },
});

defineEmits(['update:quick-filter-mode', 'remove', 'clear']);
const { t } = useI18n();
const hasFilters = computed(() => props.activeFilters.length > 0);
</script>

<template>
    <section class="space-y-2" :aria-labelledby="summaryId">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 :id="summaryId" class="sr-only">
                    {{ t(`${i18nPrefix}.filter_summary.title`) }}
                </h2>
                <p class="text-sm font-semibold text-stone-700 dark:text-neutral-200" aria-live="polite" aria-atomic="true">
                    {{ t(`${i18nPrefix}.filter_summary.results`, { count: matchingCount }) }}
                    <span v-if="busy" class="font-normal text-stone-500 dark:text-neutral-400">
                        · {{ t(`${i18nPrefix}.filter_summary.updating`) }}
                    </span>
                </p>
                <p v-if="hasFilters" class="mt-0.5 text-xs text-stone-500 dark:text-neutral-400">
                    {{ t(`${i18nPrefix}.filter_summary.active_count`, { count: activeFilters.length }) }}
                </p>
            </div>

            <div v-if="quickFilterCount > 1" class="inline-flex w-fit rounded-sm border border-stone-200 bg-stone-50 p-1 dark:border-neutral-700 dark:bg-neutral-900" role="group" :aria-label="t(`${i18nPrefix}.filter_summary.mode_label`)">
                <button
                    v-for="mode in ['all', 'any']"
                    :key="mode"
                    type="button"
                    class="min-h-9 rounded-sm px-3 text-xs font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-green-600"
                    :class="quickFilterMode === mode
                        ? 'bg-white text-green-700 shadow-sm dark:bg-neutral-700 dark:text-green-300'
                        : 'text-stone-500 hover:text-stone-800 dark:text-neutral-400 dark:hover:text-neutral-100'"
                    :aria-pressed="String(quickFilterMode === mode)"
                    :disabled="busy"
                    @click="$emit('update:quick-filter-mode', mode)"
                >
                    {{ t(`${i18nPrefix}.filter_summary.modes.${mode}`) }}
                </button>
            </div>
        </div>

        <div v-if="hasFilters" class="flex flex-wrap items-center gap-2">
            <span
                v-for="filter in activeFilters"
                :key="filter.id"
                class="inline-flex min-h-9 items-center gap-1 rounded-full border border-green-200 bg-green-50 ps-3 pe-1 text-xs font-medium text-green-800 dark:border-green-700/60 dark:bg-green-500/10 dark:text-green-200"
            >
                <span class="max-w-56 truncate">{{ filter.label }}</span>
                <button
                    type="button"
                    class="inline-flex size-8 shrink-0 items-center justify-center rounded-full hover:bg-green-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-green-600 dark:hover:bg-green-500/20"
                    :aria-label="t(`${i18nPrefix}.filter_summary.remove`, { label: filter.label })"
                    :disabled="busy"
                    @click="$emit('remove', filter)"
                >
                    <svg class="size-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path d="M18 6 6 18M6 6l12 12" />
                    </svg>
                </button>
            </span>

            <button
                type="button"
                :class="crmButtonClass('secondary', 'compact')"
                :disabled="busy"
                @click="$emit('clear')"
            >
                {{ t(`${i18nPrefix}.filter_summary.clear_all`) }}
            </button>
        </div>
    </section>
</template>
