<script setup>
import { computed } from 'vue';
import { useCurrencyFormatter } from '@/utils/currency';

const props = defineProps({
    current: { type: Number, required: true },
    original: { type: Number, default: null },
    size: { type: String, default: 'md' },
    currencyCode: { type: String, default: null },
});

const sizeClasses = {
    xs: 'text-xs',
    sm: 'text-sm',
    md: 'text-base',
    lg: 'text-lg',
};

const { formatCurrency: format } = useCurrencyFormatter(computed(() => props.currencyCode));

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
