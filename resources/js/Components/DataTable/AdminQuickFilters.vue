<script setup>
defineProps({
    options: {
        type: Array,
        default: () => [],
    },
    selectedValues: {
        type: Array,
        default: () => [],
    },
    busy: {
        type: Boolean,
        default: false,
    },
    allLabel: {
        type: String,
        required: true,
    },
    ariaLabel: {
        type: String,
        required: true,
    },
    testIdPrefix: {
        type: String,
        default: '',
    },
});

defineEmits(['toggle', 'clear']);
</script>

<template>
    <div class="flex flex-wrap gap-2" role="group" :aria-label="ariaLabel">
        <button
            type="button"
            class="inline-flex min-h-11 items-center rounded-full border px-3 py-2 text-xs font-medium transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-green-600"
            :class="!selectedValues.length
                ? 'border-transparent bg-green-600 text-white dark:bg-green-500'
                : 'border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800'"
            :aria-pressed="String(!selectedValues.length)"
            :disabled="busy"
            :data-testid="testIdPrefix ? `${testIdPrefix}-all` : undefined"
            @click="selectedValues.length && $emit('clear')"
        >
            {{ allLabel }}
        </button>
        <button
            v-for="option in options"
            :key="option.value"
            type="button"
            class="inline-flex min-h-11 items-center rounded-full border px-3 py-2 text-xs font-medium transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-green-600"
            :class="selectedValues.includes(option.value)
                ? 'border-transparent bg-green-600 text-white dark:bg-green-500 dark:text-white'
                : 'border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800'"
            :aria-pressed="String(selectedValues.includes(option.value))"
            :disabled="busy"
            :data-testid="testIdPrefix ? `${testIdPrefix}-${option.value}` : undefined"
            @click="$emit('toggle', option.value)"
        >
            {{ option.label }}
        </button>
    </div>
</template>
