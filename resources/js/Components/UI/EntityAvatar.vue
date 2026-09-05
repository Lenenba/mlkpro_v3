<script setup>
import { computed, ref, watch } from 'vue';

const props = defineProps({
    src: {
        type: String,
        default: '',
    },
    name: {
        type: String,
        default: '',
    },
    alt: {
        type: String,
        default: '',
    },
    size: {
        type: String,
        default: 'md',
        validator: (value) => ['sm', 'md', 'lg', 'xl'].includes(value),
    },
    shape: {
        type: String,
        default: 'circle',
        validator: (value) => ['circle', 'rounded', 'square'].includes(value),
    },
});

const imageFailed = ref(false);

watch(
    () => props.src,
    () => {
        imageFailed.value = false;
    },
);

const normalizedSource = computed(() => (typeof props.src === 'string' ? props.src.trim() : ''));
const hasImage = computed(() => Boolean(normalizedSource.value) && !imageFailed.value);
const accessibleLabel = computed(() => props.alt.trim() || props.name.trim());

const initials = computed(() => {
    const words = props.name
        .trim()
        .split(/\s+/u)
        .filter(Boolean);

    if (!words.length) {
        return '–';
    }

    const selectedWords = words.length === 1 ? words : [words[0], words.at(-1)];

    return selectedWords
        .map((word) => Array.from(word)[0] || '')
        .join('')
        .toLocaleUpperCase()
        .slice(0, 2);
});

const sizeClass = computed(
    () =>
        ({
            sm: 'h-8 w-8 text-[0.6875rem]',
            md: 'h-10 w-10 text-xs',
            lg: 'h-12 w-12 text-sm',
            xl: 'h-16 w-16 text-base',
        })[props.size],
);

const shapeClass = computed(
    () =>
        ({
            circle: 'rounded-full',
            rounded: 'rounded-xl',
            square: 'rounded-md',
        })[props.shape],
);
</script>

<template>
    <span
        class="relative inline-flex shrink-0 items-center justify-center overflow-hidden border border-emerald-200 bg-emerald-100 font-semibold tracking-wide text-emerald-800 shadow-sm ring-1 ring-stone-900/5 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-200 dark:ring-white/10"
        :class="[sizeClass, shapeClass]"
        :role="!hasImage && accessibleLabel ? 'img' : undefined"
        :aria-label="!hasImage && accessibleLabel ? accessibleLabel : undefined"
        :aria-hidden="!hasImage && !accessibleLabel ? 'true' : undefined"
    >
        <img
            v-if="hasImage"
            :src="normalizedSource"
            :alt="accessibleLabel"
            class="h-full w-full object-cover"
            loading="lazy"
            decoding="async"
            @error="imageFailed = true"
        />
        <span v-else aria-hidden="true">{{ initials }}</span>
    </span>
</template>
