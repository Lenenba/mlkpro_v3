<script setup>
import { ref } from 'vue';
import { Link } from '@inertiajs/vue3';

// État d'ouverture/fermeture du menu
const isOpen = ref(false);

// Fonction pour gérer l'ouverture/fermeture
const toggleMenu = () => {
    isOpen.value = !isOpen.value;
};
</script>

<template>
    <div class="relative">
        <!-- Bouton pour ouvrir le menu -->
        <button
            class="p-2 group-hover:bg-gray-300 dark:bg-neutral-800 rounded-lg focus:outline-none hover:bg-gray-300 dark:hover:bg-neutral-700 transition"
            @click="toggleMenu">
            <slot name="toggle-icon">
                <!-- Icône par défaut si aucune n'est fournie dans le slot -->
                <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-gray-400 dark:text-neutral-400"
                    viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                    stroke-linejoin="round">
                    <path d="M4 6h16M4 12h16M4 18h16" />
                </svg>
            </slot>
        </button>

        <!-- Menu horizontal -->
        <div v-if="isOpen"
            class="absolute left-full top-0 ml-4 bg-white dark:bg-neutral-900 shadow-lg border border-gray-200 dark:border-neutral-700 rounded-lg z-10 flex">
            <!-- Items du menu -->
            <ul class="flex">
                <li v-for="(item, index) in [
                    { href: '/customer/create', icon: '👤', label: 'Client' },
                    { href: '/requests', icon: '📋', label: 'Request' },
                    { href: '/quotes', icon: '🔍', label: 'Quote' },
                    { href: '/jobs', icon: '🔧', label: 'Job' },
                    { href: '/invoices', icon: '💵', label: 'Invoice' },
                ]" :key="index"
                    class="p-4 flex flex-col justify-center items-center hover:bg-gray-100 dark:hover:bg-neutral-800 transition cursor-pointer">
                    <Link :href="item.href" class="flex flex-col items-center w-full">
                    <!-- Icône -->
                    <span class="text-2xl mb-2">{{ item.icon }}</span>
                    <!-- Label -->
                    <span class="text-sm text-gray-800 dark:text-neutral-200">{{ item.label }}</span>
                    </Link>
                </li>
            </ul>
        </div>
    </div>
</template>


<!-- { href: '/clients', icon: '👤', label: 'Client' },
{ href: '/requests', icon: '📋', label: 'Request' },
{ href: '/quotes', icon: '🔍', label: 'Quote' },
{ href: '/jobs', icon: '🔧', label: 'Job' },
{ href: '/invoices', icon: '💵', label: 'Invoice' }, -->
