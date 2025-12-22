<script setup>
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    canLogin: {
        type: Boolean,
        default: true,
    },
    canRegister: {
        type: Boolean,
        default: true,
    },
});
</script>

<template>
    <Head title="Bienvenue" />

    <div class="min-h-screen bg-gray-50 text-gray-900 dark:bg-neutral-900 dark:text-neutral-100">
        <header class="mx-auto flex w-full max-w-6xl items-center justify-between px-4 py-6">
            <Link :href="route('welcome')" class="flex items-center gap-3">
                <ApplicationLogo class="h-10 w-10" />
                <div class="leading-tight">
                    <div class="text-sm font-semibold">MLK Pro</div>
                    <div class="text-xs text-gray-500 dark:text-neutral-400">MVP multi-entreprises</div>
                </div>
            </Link>

            <div class="flex items-center gap-2">
                <Link v-if="canLogin" :href="route('login')"
                    class="rounded-sm border border-gray-200 bg-white px-3 py-2 text-sm font-medium text-gray-800 hover:bg-gray-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800">
                    Se connecter
                </Link>
                <Link v-if="canRegister" :href="route('register')"
                    class="rounded-sm border border-transparent bg-green-600 px-3 py-2 text-sm font-medium text-white hover:bg-green-700">
                    Créer un compte
                </Link>
            </div>
        </header>

        <main class="mx-auto w-full max-w-6xl px-4 pb-16 pt-8">
            <section
                class="rounded-sm border border-gray-200 bg-white p-6 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                <div class="grid grid-cols-1 gap-6 md:grid-cols-2 md:items-center">
                    <div class="space-y-4">
                        <h1 class="text-2xl font-semibold tracking-tight text-gray-900 dark:text-neutral-100">
                            Gérez votre activité, votre équipe et vos tâches — sans complexité.
                        </h1>
                        <p class="text-sm text-gray-600 dark:text-neutral-400">
                            MLK Pro vous permet de créer un espace par entreprise, de configurer votre activité (services ou produits),
                            d’inviter votre équipe et de piloter les tâches du quotidien.
                        </p>

                        <div class="flex flex-wrap gap-2 text-xs">
                            <span
                                class="rounded-full border border-gray-200 bg-gray-50 px-3 py-1 text-gray-700 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200">
                                Multi-entreprises
                            </span>
                            <span
                                class="rounded-full border border-gray-200 bg-gray-50 px-3 py-1 text-gray-700 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200">
                                Rôles & permissions
                            </span>
                            <span
                                class="rounded-full border border-gray-200 bg-gray-50 px-3 py-1 text-gray-700 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200">
                                Tâches & assignation
                            </span>
                            <span
                                class="rounded-full border border-gray-200 bg-gray-50 px-3 py-1 text-gray-700 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200">
                                Structure scalable
                            </span>
                        </div>

                        <div class="flex items-center gap-2 pt-2">
                            <Link v-if="canRegister" :href="route('register')"
                                class="rounded-sm border border-transparent bg-green-600 px-3 py-2 text-sm font-medium text-white hover:bg-green-700">
                                Démarrer (onboarding)
                            </Link>
                            <Link v-if="canLogin" :href="route('login')"
                                class="rounded-sm border border-gray-200 bg-white px-3 py-2 text-sm font-medium text-gray-800 hover:bg-gray-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800">
                                J’ai déjà un compte
                            </Link>
                        </div>
                    </div>

                    <div class="rounded-sm border border-gray-200 bg-gray-50 p-4 dark:border-neutral-700 dark:bg-neutral-900">
                        <div class="text-sm font-semibold text-gray-900 dark:text-neutral-100">Ce que vous pouvez faire</div>
                        <ul class="mt-3 space-y-2 text-sm text-gray-700 dark:text-neutral-200">
                            <li class="flex gap-2">
                                <span class="mt-1 size-1.5 rounded-full bg-green-600"></span>
                                Créer votre entreprise (nom, logo, localisation)
                            </li>
                            <li class="flex gap-2">
                                <span class="mt-1 size-1.5 rounded-full bg-green-600"></span>
                                Choisir le type : services ou produits
                            </li>
                            <li class="flex gap-2">
                                <span class="mt-1 size-1.5 rounded-full bg-green-600"></span>
                                Inviter des membres (admin / membre)
                            </li>
                            <li class="flex gap-2">
                                <span class="mt-1 size-1.5 rounded-full bg-green-600"></span>
                                Créer des tâches, assigner, suivre les statuts
                            </li>
                        </ul>
                    </div>
                </div>
            </section>

            <section class="mt-6 grid grid-cols-1 gap-3 md:grid-cols-3">
                <div class="rounded-sm border border-gray-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                    <div class="text-sm font-semibold text-gray-900 dark:text-neutral-100">Services</div>
                    <p class="mt-2 text-sm text-gray-600 dark:text-neutral-400">
                        Clients, devis, jobs, suivi des prestations. Idéal pour les métiers de terrain.
                    </p>
                </div>
                <div class="rounded-sm border border-gray-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                    <div class="text-sm font-semibold text-gray-900 dark:text-neutral-100">Produits</div>
                    <p class="mt-2 text-sm text-gray-600 dark:text-neutral-400">
                        Catalogue produit, préparation stock/commandes. Structure prête pour évoluer.
                    </p>
                </div>
                <div class="rounded-sm border border-gray-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                    <div class="text-sm font-semibold text-gray-900 dark:text-neutral-100">Équipe</div>
                    <p class="mt-2 text-sm text-gray-600 dark:text-neutral-400">
                        Propriétaire, administrateurs, membres. Accès adapté selon le rôle.
                    </p>
                </div>
            </section>
        </main>

        <footer class="border-t border-gray-200 py-8 text-center text-xs text-gray-500 dark:border-neutral-800 dark:text-neutral-400">
            © {{ new Date().getFullYear() }} MLK Pro
        </footer>
    </div>
</template>

