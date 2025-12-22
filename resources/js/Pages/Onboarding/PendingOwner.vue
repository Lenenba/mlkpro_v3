<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
</script>

<template>
    <GuestLayout>
        <Head title="Onboarding" />

        <div class="space-y-3">
            <h1 class="text-lg font-semibold text-gray-900 dark:text-neutral-100">Espace en cours de configuration</h1>
            <p class="text-sm text-gray-600 dark:text-neutral-400">
                Votre compte est rattaché à une entreprise dont la configuration n’est pas terminée.
                Demandez au propriétaire de finaliser l’onboarding.
            </p>

            <div class="flex items-center justify-between pt-2">
                <Link :href="route('logout')" method="post" as="button"
                    class="rounded-sm border border-gray-200 bg-white px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800">
                    Se déconnecter
                </Link>
                <Link :href="route('dashboard')"
                    class="rounded-sm border border-transparent bg-green-600 px-3 py-2 text-sm font-medium text-white hover:bg-green-700">
                    Tableau de bord
                </Link>
            </div>
        </div>
    </GuestLayout>
</template>

