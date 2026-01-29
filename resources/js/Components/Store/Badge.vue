<script setup>
import { computed } from 'vue';

const props = defineProps({
    label: { type: String, required: true },
    tone: { type: String, default: 'neutral' },
    size: { type: String, default: 'sm' },
});

const toneClasses = {
    neutral: 'border-slate-200 bg-white text-slate-600',
    primary: 'border-emerald-200 bg-emerald-50 text-emerald-700',
    warning: 'border-amber-200 bg-amber-50 text-amber-700',
    danger: 'border-rose-200 bg-rose-50 text-rose-700',
    promo: 'border-rose-300 bg-rose-600 text-white',
    dark: 'border-slate-900 bg-slate-900 text-white',
};

const sizeClasses = {
    sm: 'px-2 py-0.5 text-xs',
    md: 'px-2.5 py-1 text-xs',
};

const badgeClass = computed(() => {
    const tone = toneClasses[props.tone] || toneClasses.neutral;
    const size = sizeClasses[props.size] || sizeClasses.sm;
    return `inline-flex items-center gap-1 rounded-sm border font-semibold ${size} ${tone}`;
});
</script>

<template>
    <span :class="badgeClass">
        {{ label }}
    </span>
</template>
