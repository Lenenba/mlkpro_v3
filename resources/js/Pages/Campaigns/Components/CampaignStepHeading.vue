<script setup>
const props = defineProps({
    eyebrow: {
        type: String,
        default: '',
    },
    title: {
        type: String,
        required: true,
    },
    description: {
        type: String,
        default: '',
    },
    recommendation: {
        type: String,
        default: '',
    },
    recommendationLabel: {
        type: String,
        default: '',
    },
});
</script>

<template>
    <div class="mb-5 flex flex-col gap-4 border-b border-stone-100 pb-4 dark:border-neutral-800 lg:flex-row lg:items-start lg:justify-between">
        <div class="max-w-3xl">
            <p v-if="eyebrow" class="text-[11px] font-semibold uppercase tracking-[0.18em] text-green-700 dark:text-green-300">
                {{ eyebrow }}
            </p>
            <h2 class="mt-1 text-lg font-semibold text-stone-900 dark:text-neutral-100">
                {{ title }}
            </h2>
            <p v-if="description" class="mt-2 text-sm leading-6 text-stone-500 dark:text-neutral-400">
                {{ description }}
            </p>
        </div>

        <div
            v-if="recommendation"
            class="rounded-lg border border-stone-200 bg-stone-50 px-4 py-3 text-sm text-stone-700 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200 lg:max-w-sm"
        >
            <div v-if="recommendationLabel" class="text-[11px] font-semibold uppercase tracking-[0.18em] text-stone-500 dark:text-neutral-400">
                {{ recommendationLabel }}
            </div>
            <div class="mt-1 font-medium">
                {{ recommendation }}
            </div>
        </div>
    </div>
</template>
