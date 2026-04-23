<script setup>
import { computed } from 'vue';
import { Facebook, Instagram, Linkedin } from 'lucide-vue-next';

const props = defineProps({
    platform: {
        type: String,
        required: true,
    },
});

const platformKey = computed(() => String(props.platform || '').trim().toLowerCase());

const palette = computed(() => ({
    facebook: 'text-[#1877F2]',
    instagram: 'text-[#E4405F]',
    linkedin: 'text-[#0A66C2]',
    x: 'text-stone-900 dark:text-neutral-100',
}[platformKey.value] || 'text-stone-700 dark:text-neutral-200'));

const iconComponent = computed(() => ({
    facebook: Facebook,
    instagram: Instagram,
    linkedin: Linkedin,
}[platformKey.value] || null));
</script>

<template>
    <component
        :is="iconComponent"
        v-if="iconComponent"
        class="size-full"
        :class="palette"
        :stroke-width="1.9"
    />

    <svg
        v-else-if="platformKey === 'x'"
        viewBox="0 0 24 24"
        fill="none"
        xmlns="http://www.w3.org/2000/svg"
        class="size-full"
        :class="palette"
    >
        <path
            d="M18.9 3H21L15.98 8.74L21.89 21H17.26L13.63 13.57L7.13 21H5.02L10.39 14.86L4.73 3H9.48L12.75 9.95L18.9 3ZM18.16 19.62H19.33L8.83 4.3H7.58L18.16 19.62Z"
            fill="currentColor"
        />
    </svg>

    <svg
        v-else
        viewBox="0 0 24 24"
        fill="none"
        xmlns="http://www.w3.org/2000/svg"
        class="size-full"
        :class="palette"
    >
        <circle cx="12" cy="12" r="8.5" stroke="currentColor" stroke-width="1.8" />
        <path d="M8.5 15.5H15.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
    </svg>
</template>
