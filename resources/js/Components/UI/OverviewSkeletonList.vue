<script setup>
const props = defineProps({
    rows: {
        type: Number,
        default: 4,
    },
});
</script>

<template>
    <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
        <div
            v-for="row in rows"
            :key="`overview-skeleton-${row}`"
            class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:bg-neutral-900 dark:border-neutral-700"
        >
            <div class="animate-pulse space-y-3">
                <div class="flex items-center justify-between gap-3">
                    <div class="space-y-2">
                        <div class="h-3 w-36 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                        <div class="h-2 w-24 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                    </div>
                    <div class="h-5 w-16 rounded-full bg-stone-200 dark:bg-neutral-700"></div>
                </div>
                <div class="h-2 w-3/4 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                <div class="flex items-center justify-between">
                    <div class="h-2 w-28 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                    <div class="h-6 w-20 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                </div>
            </div>
        </div>
    </div>
</template>
