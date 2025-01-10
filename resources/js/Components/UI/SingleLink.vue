<script setup>
import { ref } from "vue";
import { Link } from '@inertiajs/vue3';

// Props pour recevoir les données dynamiques
const props = defineProps({
    menuItems: {
        type: Array,
        required: true,
        default: () => [],
    },
});
</script>

<template>
    <li class="px-2 lg:px-5">
        <Link
            class="flex gap-x-3 py-2 px-3 text-sm text-gray-800 rounded-lg hover:bg-gray-100 focus:outline-none focus:bg-gray-100 dark:hover:bg-neutral-700 dark:text-neutral-300 dark:focus:bg-neutral-700 "
            :href="menuItems[0].href">
        <slot name="icon">
            <!-- Icône par défaut -->
            <svg class="shrink-0 mt-0.5 size-4" xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                stroke-linejoin="round">
                <path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" />
                <polyline points="9 22 9 12 15 12 15 22" />
            </svg>
        </slot>

        {{ menuItems[0].label }}
        </Link>

    </li>
</template>
