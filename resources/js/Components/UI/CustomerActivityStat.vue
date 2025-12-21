<script setup>
import { computed } from 'vue';

const props = defineProps({
    items: {
        type: Array,
        default: () => [],
    },
    title: {
        type: String,
        default: 'Top customer activity',
    },
});

const total = computed(() =>
    props.items.reduce((sum, item) => sum + Number(item.quotes_count || 0) + Number(item.works_count || 0), 0)
);

const formatNumber = (value) =>
    Number(value || 0).toLocaleString(undefined, { maximumFractionDigits: 0 });

const displayName = (item) => item.company_name || `${item.first_name || ''} ${item.last_name || ''}`.trim();

const getPercent = (item) => {
    const value = Number(item.quotes_count || 0) + Number(item.works_count || 0);
    if (!total.value) {
        return 0;
    }
    return Math.round((value / total.value) * 100);
};
</script>

<template>
    <div
        class="size-full flex flex-col bg-white border border-gray-200 shadow-sm rounded-sm border-t-4 border-t-emerald-700 dark:bg-neutral-800 dark:border-neutral-700">
        <div class="p-5 pb-4 flex items-center justify-between gap-x-4">
            <div>
                <h2 class="inline-block font-semibold text-gray-800 dark:text-neutral-200">
                    {{ title }}
                </h2>
                <p class="text-xs text-gray-500 dark:text-neutral-500">
                    Quotes + jobs activity
                </p>
            </div>
            <div class="text-sm text-gray-500 dark:text-neutral-400">
                {{ formatNumber(total) }} actions
            </div>
        </div>

        <div class="h-full p-5 pt-0">
            <div v-if="!items.length" class="text-sm text-gray-500 dark:text-neutral-400">
                No customer activity yet.
            </div>
            <div v-else class="space-y-4">
                <div class="flex gap-x-1 w-full h-2.5 rounded-full overflow-hidden">
                    <div
                        v-for="item in items"
                        :key="item.id"
                        class="flex flex-col justify-center overflow-hidden bg-emerald-500 text-xs text-white text-center whitespace-nowrap"
                        :style="{ width: `${getPercent(item)}%` }"
                        role="progressbar"
                        :aria-valuenow="getPercent(item)"
                        aria-valuemin="0"
                        aria-valuemax="100"
                    ></div>
                </div>

                <ul>
                    <li v-for="item in items" :key="item.id"
                        class="py-2 grid grid-cols-2 justify-between items-center gap-x-4">
                        <div class="flex items-center gap-x-2">
                            <img
                                v-if="item.logo_url"
                                :src="item.logo_url"
                                :alt="displayName(item)"
                                class="size-6 rounded-full border border-gray-200 dark:border-neutral-700 object-cover"
                            />
                            <span
                                v-else
                                class="size-6 rounded-full bg-gray-100 text-gray-700 text-xs font-medium flex items-center justify-center dark:bg-neutral-700 dark:text-neutral-200"
                            >
                                {{ displayName(item)[0] || '?' }}
                            </span>
                            <span class="text-sm text-gray-800 dark:text-neutral-200">
                                {{ displayName(item) }}
                            </span>
                        </div>
                        <div class="text-end">
                            <span class="text-sm text-gray-500 dark:text-neutral-500">
                                {{ formatNumber(item.quotes_count || 0) }} q / {{ formatNumber(item.works_count || 0) }} j
                            </span>
                        </div>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</template>
