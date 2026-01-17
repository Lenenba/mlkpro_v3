<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    items: {
        type: Array,
        default: () => [],
    },
    title: {
        type: String,
        default: null,
    },
});

const { t } = useI18n();

const resolvedTitle = computed(() => props.title || t('quotes.stats.top_by_value'));
const quoteInitial = computed(() => t('quotes.labels.quote_initial'));

const total = computed(() =>
    props.items.reduce((sum, item) => sum + Number(item.total || 0), 0)
);

const formatCurrency = (value) =>
    `$${Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

const displayCustomer = (item) =>
    item.customer?.company_name ||
    `${item.customer?.first_name || ''} ${item.customer?.last_name || ''}`.trim() ||
    t('quotes.labels.unknown_customer');

const getPercent = (value) => {
    if (!total.value) {
        return 0;
    }
    return Math.round((Number(value || 0) / total.value) * 100);
};
</script>

<template>
    <div
        class="size-full flex flex-col bg-white border border-stone-200 shadow-sm rounded-sm border-t-4 border-t-sky-700 dark:bg-neutral-800 dark:border-neutral-700">
        <div class="p-5 pb-4 flex items-center justify-between gap-x-4">
            <div>
                <h2 class="inline-block font-semibold text-stone-800 dark:text-neutral-200">
                    {{ resolvedTitle }}
                </h2>
                <p class="text-xs text-stone-500 dark:text-neutral-500">
                    {{ $t('quotes.stats.based_on_filters') }}
                </p>
            </div>
            <div class="text-sm text-stone-500 dark:text-neutral-400">
                {{ formatCurrency(total) }}
            </div>
        </div>

        <div class="h-full p-5 pt-0">
            <div v-if="!items.length" class="text-sm text-stone-500 dark:text-neutral-400">
                {{ $t('quotes.stats.empty') }}
            </div>
            <div v-else class="space-y-4">
                <div class="flex gap-x-1 w-full h-2.5 rounded-full overflow-hidden">
                    <div
                        v-for="item in items"
                        :key="item.id"
                        class="flex flex-col justify-center overflow-hidden bg-sky-500 text-xs text-white text-center whitespace-nowrap"
                        :style="{ width: `${getPercent(item.total)}%` }"
                        role="progressbar"
                        :aria-valuenow="getPercent(item.total)"
                        aria-valuemin="0"
                        aria-valuemax="100"
                    ></div>
                </div>

                <ul>
                    <li v-for="item in items" :key="item.id"
                        class="py-2 grid grid-cols-2 justify-between items-center gap-x-4">
                        <div class="flex items-center gap-x-2">
                            <span
                                class="size-6 rounded-full bg-stone-100 text-stone-700 text-xs font-medium flex items-center justify-center dark:bg-neutral-700 dark:text-neutral-200"
                            >
                                {{ item.number?.[0] || quoteInitial }}
                            </span>
                            <div class="flex flex-col">
                                <span class="text-sm text-stone-800 dark:text-neutral-200">
                                    {{ item.number || $t('quotes.labels.quote_fallback') }}
                                </span>
                                <span class="text-xs text-stone-500 dark:text-neutral-500">
                                    {{ displayCustomer(item) }}
                                </span>
                            </div>
                        </div>
                        <div class="text-end">
                            <span class="text-sm text-stone-500 dark:text-neutral-500">
                                {{ formatCurrency(item.total) }}
                            </span>
                        </div>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</template>
