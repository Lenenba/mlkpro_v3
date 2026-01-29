<script setup>
import { computed } from 'vue';

const props = defineProps({
    current: { type: Number, required: true },
    original: { type: Number, default: null },
    size: { type: String, default: 'md' },
});

const sizeClasses = {
    sm: 'text-sm',
    md: 'text-base',
    lg: 'text-lg',
};

const formatter = new Intl.NumberFormat(undefined, {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});

const format = (value) => `$${formatter.format(Number(value || 0))}`;

const priceClass = computed(() => sizeClasses[props.size] || sizeClasses.md);
</script>

<template>
    <div class="flex items-center gap-2">
        <span :class="['font-semibold text-slate-900', priceClass]">
            {{ format(current) }}
        </span>
        <span v-if="original" class="text-xs text-slate-400 line-through">
            {{ format(original) }}
        </span>
    </div>
</template>
