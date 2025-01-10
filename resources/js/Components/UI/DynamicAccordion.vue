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
    title: {
        type: String,
        required: true,
    },
});

const isExpanded = ref(false);

const toggleAccordion = () => {
    isExpanded.value = !isExpanded.value;
};
</script>

<template>
    <li class="hs-accordion px-2 lg:px-5">
        <!-- Header du menu -->
        <button type="button"
            class="hs-accordion-toggle hs-accordion-active:bg-gray-100 w-full text-start flex gap-x-3 py-2 px-3 text-sm text-gray-800 rounded-lg hover:bg-gray-100 disabled:opacity-50 disabled:pointer-events-none dark:hs-accordion-active:bg-neutral-700 focus:outline-none focus:bg-gray-100 dark:hover:bg-neutral-700 dark:text-neutral-300 dark:focus:bg-neutral-700"
            aria-expanded="false" aria-controls="accordion-content" @click="toggleAccordion">
            <!-- Slot pour l'icône -->
            <slot name="icon">
                <!-- Icône par défaut -->
                <svg class="shrink-0 mt-0.5 size-4" xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                    viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                    stroke-linejoin="round">
                    <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" />
                    <circle cx="9" cy="7" r="4" />
                    <path d="M22 21v-2a4 4 0 0 0-3-3.87" />
                    <path d="M16 3.13a4 4 0 0 1 0 7.75" />
                </svg>
            </slot>

            <!-- Titre -->
            {{ title }}

            <!-- Flèche -->
            <svg :class="['hs-accordion-active:-rotate-180 shrink-0 mt-1 size-3.5 ms-auto transition', { '-rotate-180': isExpanded }]"
                xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="m6 9 6 6 6-6" />
            </svg>
        </button>

        <!-- Contenu -->
        <div id="accordion-content" class="hs-accordion-content w-full overflow-hidden transition-[height] duration-300"
            role="region" aria-labelledby="accordion-title" :style="{ display: isExpanded ? 'block' : 'none' }">
            <ul class="hs-accordion-group ps-7 mt-1.5 space-y-1.5 relative before:absolute before:top-0 before:start-[18px] before:w-0.5 before:h-full before:bg-gray-100 dark:before:bg-neutral-700"
                data-hs-accordion-always-open>
                <!-- Génération dynamique des sous-menus -->
                <li v-for="(item, index) in menuItems" :key="index">
                    <Link
                        class="flex gap-x-4 py-2 px-3 text-sm text-gray-800 rounded-lg hover:bg-gray-100 focus:outline-none focus:bg-gray-100 dark:hover:bg-neutral-700 dark:text-neutral-300 dark:focus:bg-neutral-700"
                        :href="route(item.href)">
                    {{ item.label }}
                    </Link>
                </li>
            </ul>
        </div>
    </li>
</template>
