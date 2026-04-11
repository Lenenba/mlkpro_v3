<script setup>
import { computed } from 'vue';

const props = defineProps({
    locale: {
        type: String,
        default: 'en',
    },
});

const normalizedLocale = computed(() => String(props.locale || 'en').trim().split(/[-_]/)[0].toLowerCase());
</script>

<template>
    <svg
        viewBox="0 0 18 12"
        xmlns="http://www.w3.org/2000/svg"
        fill="none"
        aria-hidden="true"
    >
        <rect width="18" height="12" rx="2" fill="#F8FAFC" />

        <template v-if="normalizedLocale === 'fr'">
            <rect width="6" height="12" rx="2" fill="#2563EB" />
            <rect x="6" width="6" height="12" fill="#FFFFFF" />
            <rect x="12" width="6" height="12" rx="2" fill="#DC2626" />
        </template>

        <template v-else-if="normalizedLocale === 'es'">
            <rect width="18" height="12" rx="2" fill="#C81E1E" />
            <rect y="3" width="18" height="6" fill="#FACC15" />
        </template>

        <template v-else>
            <rect width="18" height="12" rx="2" fill="#1D4ED8" />
            <path d="M0 1.2 18 10.8" stroke="#FFFFFF" stroke-width="2.2" />
            <path d="M18 1.2 0 10.8" stroke="#FFFFFF" stroke-width="2.2" />
            <path d="M0 1.2 18 10.8" stroke="#DC2626" stroke-width="1" />
            <path d="M18 1.2 0 10.8" stroke="#DC2626" stroke-width="1" />
            <path d="M9 0v12" stroke="#FFFFFF" stroke-width="3.8" />
            <path d="M0 6h18" stroke="#FFFFFF" stroke-width="3.8" />
            <path d="M9 0v12" stroke="#DC2626" stroke-width="2.2" />
            <path d="M0 6h18" stroke="#DC2626" stroke-width="2.2" />
        </template>
    </svg>
</template>
