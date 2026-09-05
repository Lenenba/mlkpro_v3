<script setup>
import { computed } from 'vue';

const props = defineProps({
    actionColumns: {
        type: Number,
        default: 1,
        validator: (value) => [1, 2].includes(value),
    },
    reserveFloatingAction: {
        type: Boolean,
        default: true,
    },
});

const actionGridClass = computed(() => (
    props.actionColumns === 2 ? 'sm:grid-cols-2' : 'sm:grid-cols-1'
));
</script>

<template>
    <div
        data-form-action-bar
        class="sticky bottom-3 z-30 rounded-xl border border-stone-200 bg-white/95 p-3 shadow-lg backdrop-blur dark:border-neutral-700 dark:bg-neutral-900/95 md:flex md:items-center md:justify-between md:gap-4"
    >
        <div>
            <div
                v-if="$slots.hint"
                class="hidden text-xs text-stone-500 dark:text-neutral-400 md:block"
            >
                <slot name="hint" />
            </div>
            <slot name="secondary" />
        </div>

        <div
            class="mt-2 grid grid-cols-1 gap-2 md:mt-0"
            :class="[actionGridClass, props.reserveFloatingAction ? 'pe-14' : '']"
        >
            <slot />
        </div>
    </div>
</template>
