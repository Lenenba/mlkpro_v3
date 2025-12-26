<script setup>
import { computed } from 'vue';

const props = defineProps({
    items: {
        type: Array,
        default: () => [],
    },
    title: {
        type: String,
        default: 'Most used products',
    },
});

const colors = ['bg-blue-500', 'bg-violet-500', 'bg-teal-400', 'bg-amber-400', 'bg-stone-300'];

const total = computed(() =>
    props.items.reduce((sum, item) => sum + Number(item.quantity || 0), 0)
);

const formatNumber = (value) =>
    Number(value || 0).toLocaleString(undefined, { maximumFractionDigits: 0 });

const getPercent = (value) => {
    if (!total.value) {
        return 0;
    }

    return Math.round((Number(value || 0) / total.value) * 100);
};
</script>

<template>
    <div
        class="size-full flex flex-col bg-white border border-stone-200 shadow-sm rounded-sm border-t-4 border-t-indigo-700 dark:bg-neutral-800 dark:border-neutral-700">
        <div class="p-5 pb-4 flex items-center justify-between gap-x-4">
            <div>
                <h2 class="inline-block font-semibold text-stone-800 dark:text-neutral-200">
                    {{ title }}
                </h2>
                <p class="text-xs text-stone-500 dark:text-neutral-500">
                    Based on quotes and jobs
                </p>
            </div>
            <div class="text-sm text-stone-500 dark:text-neutral-400">
                {{ formatNumber(total) }} used
            </div>
        </div>

        <div class="h-full p-5 pt-0">
            <div v-if="!items.length" class="text-sm text-stone-500 dark:text-neutral-400">
                No usage data yet.
            </div>
            <div v-else class="h-full flex flex-col justify-between space-y-4">
                <div class="space-y-4">
                    <div class="flex gap-x-1 w-full h-2.5 rounded-full overflow-hidden">
                        <div
                            v-for="(item, index) in items"
                            :key="item.id"
                            class="flex flex-col justify-center overflow-hidden text-xs text-white text-center whitespace-nowrap"
                            :class="colors[index % colors.length]"
                            :style="{ width: `${getPercent(item.quantity)}%` }"
                            role="progressbar"
                            :aria-valuenow="getPercent(item.quantity)"
                            aria-valuemin="0"
                            aria-valuemax="100"
                        ></div>
                    </div>

                    <ul>
                        <li v-for="(item, index) in items" :key="item.id"
                            class="py-2 grid grid-cols-2 justify-between items-center gap-x-4">
                            <div class="flex items-center gap-x-2">
                                <span class="shrink-0 size-2.5 inline-block rounded-sm"
                                    :class="colors[index % colors.length]"></span>
                                <div class="flex items-center gap-x-2">
                                    <img
                                        v-if="item.image_url"
                                        :src="item.image_url"
                                        :alt="item.name"
                                        class="size-6 rounded-full border border-stone-200 dark:border-neutral-700 object-cover"
                                    />
                                    <span
                                        v-else
                                        class="size-6 rounded-full bg-stone-100 text-stone-700 text-xs font-medium flex items-center justify-center dark:bg-neutral-700 dark:text-neutral-200"
                                    >
                                        {{ item.name?.[0] || '?' }}
                                    </span>
                                    <span class="text-sm text-stone-800 dark:text-neutral-200">
                                        {{ item.name }}
                                    </span>
                                </div>
                            </div>
                            <div class="text-end">
                                <span class="text-sm text-stone-500 dark:text-neutral-500">
                                    {{ formatNumber(item.quantity) }}
                                </span>
                            </div>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</template>
