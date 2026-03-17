<script setup>
defineProps({
    for: {
        type: String,
        default: null,
    },
    value: {
        type: String,
    },
});
</script>

<template>
    <label
        :for="for"
        class="block text-sm font-medium text-stone-700 dark:text-neutral-300"
    >
        <span v-if="value">{{ value }}</span>
        <span v-else><slot /></span>
    </label>
</template>
