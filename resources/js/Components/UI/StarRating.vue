<script setup>
import { computed } from 'vue';

const props = defineProps({
    value: {
        type: [Number, String],
        default: null,
    },
    max: {
        type: Number,
        default: 5,
    },
    iconClass: {
        type: String,
        default: 'h-4 w-4',
    },
    showValue: {
        type: Boolean,
        default: false,
    },
    emptyLabel: {
        type: String,
        default: 'No rating yet',
    },
});

const numericValue = computed(() => {
    if (props.value === null || props.value === undefined || props.value === '') {
        return null;
    }
    const number = Number(props.value);
    if (Number.isNaN(number)) {
        return null;
    }
    return Math.max(0, Math.min(props.max, number));
});

const filled = computed(() => (numericValue.value ? Math.round(numericValue.value) : 0));

const label = computed(() => {
    if (numericValue.value === null) {
        return props.emptyLabel;
    }
    return `${numericValue.value.toFixed(1)} / ${props.max}`;
});
</script>

<template>
    <div class="inline-flex items-center gap-1">
        <svg v-for="i in max" :key="i" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"
            stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"
            :class="[iconClass, i <= filled ? 'text-amber-400' : 'text-stone-300 dark:text-neutral-600']">
            <path
                d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z" />
        </svg>
        <span v-if="showValue" class="text-xs text-stone-500 dark:text-neutral-400">
            {{ label }}
        </span>
    </div>
</template>
