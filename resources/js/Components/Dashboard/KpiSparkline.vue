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
    <div class="mt-3 flex h-8 items-end gap-0.5">
        <span
            v-for="(point, index) in points"
            :key="index"
            class="flex-1 rounded-sm"
            :class="colorClass"
            :style="{ height: point.height }"
            :title="point.value"
        ></span>
    </div>
</template>
