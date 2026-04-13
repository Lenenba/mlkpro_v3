<script setup>
import { computed, useSlots } from 'vue';

const props = defineProps({
    count: {
        type: Number,
        default: 0,
    },
    label: {
        type: String,
        default: '',
    },
    containerClass: {
        type: [String, Array, Object],
        default: '',
    },
});

const slots = useSlots();

const isVisible = computed(() => props.count > 0 && (!!props.label || !!slots.summary || !!slots.default));
</script>

<template>
    <div
        v-if="isVisible"
        :class="[
            'flex flex-col gap-3 rounded-sm border border-emerald-200/80 bg-emerald-50/60 px-4 py-3 shadow-sm dark:border-emerald-500/20 dark:bg-emerald-500/10 md:flex-row md:items-center md:justify-between',
            containerClass,
        ]"
    >
        <div class="min-w-0 text-sm font-semibold text-stone-800 dark:text-neutral-100">
            <slot name="summary">
                {{ label }}
            </slot>
        </div>

        <div v-if="$slots.default" class="flex flex-wrap items-center gap-2 md:justify-end">
            <slot />
        </div>
    </div>
</template>
