<script setup>
const props = defineProps({
    points: {
        type: Array,
        default: () => [],
    },
    colorClass: {
        type: String,
        default: 'bg-stone-400/70 dark:bg-neutral-500/50',
    },
});
</script>

<template>
    <div class="flex h-10 items-end gap-1 border-b border-stone-200 dark:border-neutral-700" aria-hidden="true">
        <span
            v-for="(point, index) in points"
            :key="index"
            class="flex-1 rounded-t-sm opacity-80"
            :class="colorClass"
            :style="{ height: point.height }"
        ></span>
    </div>
</template>
