<script setup>
const props = defineProps({
    name: {
        type: String,
        default: 'workspace',
    },
    iconClass: {
        type: String,
        default: 'size-6',
    },
});
</script>

<template>
    <svg
        v-if="name === 'revenue'"
        xmlns="http://www.w3.org/2000/svg"
        width="24"
        height="24"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        stroke-linecap="round"
        stroke-linejoin="round"
        :class="props.iconClass"
    >
        <path d="M3 3v18h18" />
        <path d="m7 14 4-4 3 3 5-6" />
    </svg>

    <svg
        v-else-if="name === 'growth'"
        xmlns="http://www.w3.org/2000/svg"
        width="24"
        height="24"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        stroke-linecap="round"
        stroke-linejoin="round"
        :class="props.iconClass"
    >
        <path d="M3 21h18" />
        <path d="M5 17 12 10l4 4 5-7" />
        <path d="M16 7h5v5" />
    </svg>

    <svg
        v-else-if="name === 'operations'"
        xmlns="http://www.w3.org/2000/svg"
        width="24"
        height="24"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        stroke-linecap="round"
        stroke-linejoin="round"
        :class="props.iconClass"
    >
        <rect width="20" height="14" x="2" y="7" rx="2" />
        <path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2" />
        <path d="M2 13h20" />
    </svg>

    <svg
        v-else-if="name === 'finance'"
        xmlns="http://www.w3.org/2000/svg"
        width="24"
        height="24"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        stroke-linecap="round"
        stroke-linejoin="round"
        :class="props.iconClass"
    >
        <path d="M3 6h18" />
        <path d="M7 3v6" />
        <path d="M17 3v6" />
        <rect width="18" height="15" x="3" y="6" rx="2" />
        <path d="M8 14h8" />
        <path d="M10 18h4" />
    </svg>

    <svg
        v-else-if="name === 'catalog'"
        xmlns="http://www.w3.org/2000/svg"
        width="24"
        height="24"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        stroke-linecap="round"
        stroke-linejoin="round"
        :class="props.iconClass"
    >
        <path d="M12 3v18" />
        <path d="M3 8h18" />
        <path d="M3 16h18" />
        <rect width="18" height="18" x="3" y="3" rx="2" />
    </svg>

    <svg
        v-else
        xmlns="http://www.w3.org/2000/svg"
        width="24"
        height="24"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        stroke-linecap="round"
        stroke-linejoin="round"
        :class="props.iconClass"
    >
        <path d="M12 1v2" />
        <path d="M12 21v2" />
        <path d="m4.93 4.93 1.41 1.41" />
        <path d="m17.66 17.66 1.41 1.41" />
        <path d="M1 12h2" />
        <path d="M21 12h2" />
        <path d="m4.93 19.07 1.41-1.41" />
        <path d="m17.66 6.34 1.41-1.41" />
        <circle cx="12" cy="12" r="4" />
    </svg>
</template>
