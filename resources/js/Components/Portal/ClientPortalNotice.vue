<script setup>
import { computed, useSlots } from 'vue';

const props = defineProps({
    tone: {
        type: String,
        default: 'info',
        validator: (value) => ['error', 'info', 'success', 'warning'].includes(value),
    },
    message: {
        type: String,
        default: '',
    },
    compact: {
        type: Boolean,
        default: false,
    },
});

const slots = useSlots();

const hasContent = computed(() => Boolean(props.message || slots.default));
const role = computed(() => (props.tone === 'error' ? 'alert' : 'status'));
const liveMode = computed(() => (props.tone === 'error' ? 'assertive' : 'polite'));
const toneClass = computed(() => ({
    error: 'border-rose-200 bg-rose-50 text-rose-700 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-200',
    info: 'border-sky-200 bg-sky-50 text-sky-700 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-200',
    success: 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200',
    warning: 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100',
}[props.tone]));
</script>

<template>
    <div
        v-if="hasContent"
        class="w-full min-w-0 max-w-full break-words rounded-sm border"
        :class="[toneClass, compact ? 'px-3 py-2 text-xs' : 'px-4 py-3 text-sm']"
        :role="role"
        :aria-live="liveMode"
        aria-atomic="true"
    >
        <slot>{{ message }}</slot>
    </div>
</template>
