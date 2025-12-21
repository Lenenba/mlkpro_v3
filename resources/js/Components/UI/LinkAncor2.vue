<script setup>
import { ref } from 'vue';
import { Link } from '@inertiajs/vue3';

const isOpen = ref(false);

const menuItems = [
    {
        label: 'Customer',
        route: 'customer.create',
        icon: `<svg xmlns="http://www.w3.org/2000/svg" class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg>`,
    },
    {
        label: 'Product',
        route: 'product.create',
        icon: `<svg xmlns="http://www.w3.org/2000/svg" class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7.5 4.27 9 5.15"/><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/></svg>`,
    },
    {
        label: 'Quotes',
        route: 'quote.index',
        icon: `<svg xmlns="http://www.w3.org/2000/svg" class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2Z"/><path d="M14 2v6h6"/><path d="M8 13h8"/><path d="M8 17h5"/></svg>`,
    },
    {
        label: 'Jobs',
        route: 'jobs.index',
        icon: `<svg xmlns="http://www.w3.org/2000/svg" class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/><path d="M2 13h20"/></svg>`,
    },
    {
        label: 'Invoices',
        route: 'invoice.index',
        icon: `<svg xmlns="http://www.w3.org/2000/svg" class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 2h16v20l-4-2-4 2-4-2-4 2V2z"/><path d="M8 6h8"/><path d="M8 10h8"/><path d="M8 14h6"/></svg>`,
    },
];

const toggleMenu = () => {
    isOpen.value = !isOpen.value;
};

const closeMenu = () => {
    isOpen.value = false;
};
</script>

<template>
    <div class="relative">
        <button
            class="p-2 group-hover:bg-gray-300 dark:bg-neutral-800 rounded-lg focus:outline-none hover:bg-gray-300 dark:hover:bg-neutral-700 transition"
            @click="toggleMenu">
            <slot name="toggle-icon">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-gray-400 dark:text-neutral-400"
                    viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                    stroke-linejoin="round">
                    <path d="M4 6h16M4 12h16M4 18h16" />
                </svg>
            </slot>
        </button>

        <div v-if="isOpen"
            class="absolute left-full top-0 ml-4 bg-white dark:bg-neutral-900 shadow-lg border border-gray-200 dark:border-neutral-700 rounded-lg z-10 flex">
            <ul class="flex">
                <li v-for="item in menuItems" :key="item.label"
                    class="p-4 flex flex-col justify-center items-center hover:bg-gray-100 dark:hover:bg-neutral-800 transition cursor-pointer">
                    <Link :href="route(item.route)" class="flex flex-col items-center w-full" @click="closeMenu">
                        <span class="mb-2 text-gray-500 dark:text-neutral-400" v-html="item.icon"></span>
                        <span class="text-sm text-gray-800 dark:text-neutral-200">{{ item.label }}</span>
                    </Link>
                </li>
            </ul>
        </div>
    </div>
</template>
