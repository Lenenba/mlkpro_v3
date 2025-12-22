<script setup>
import { computed } from 'vue';
import ApplicationLogo from "@/Components/ApplicationLogo.vue";
import LinkAncor from "@/Components/UI/LinkAncor.vue";
import { Link, usePage } from '@inertiajs/vue3';
import MenuDropdown from "@/Components/UI/LinkAncor2.vue";
import QuickCreateModals from "@/Components/QuickCreate/QuickCreateModals.vue";

const page = usePage()
const companyType = computed(() => page.props.auth?.account?.company?.type ?? null);
const showServices = computed(() => companyType.value !== 'products');
const showProducts = computed(() => companyType.value !== 'services');
const isOwner = computed(() => Boolean(page.props.auth?.account?.is_owner));
const userName = computed(() => page.props.auth?.user?.name || '');
const userEmail = computed(() => page.props.auth?.user?.email || '');
const avatarUrl = computed(() => page.props.auth?.user?.profile_picture || '');
const avatarInitial = computed(() => {
    const label = (userName.value || userEmail.value || '?').trim();
    return label.length ? label[0].toUpperCase() : '?';
});
</script>

<template>
    <aside class="relative">
        <div id="hs-pro-sidebar" class="hs-overlay [--auto-close:lg]
            hs-overlay-open:translate-x-0
            -translate-x-full transition-all duration-300 transform
            w-16 h-full
            hidden
            fixed inset-y-0 start-0 z-[60]
            bg-gray-100
            lg:block lg:translate-x-0 lg:end-auto lg:bottom-0
            dark:bg-neutral-950" tabindex="-1" aria-label="Compact Sidebar">
            <div class="h-full flex">
                <div class="relative z-10 w-16 flex flex-col h-full max-h-full pb-5">
                    <header class="w-16 py-2.5 flex justify-center">
                        <a class="flex-none rounded-md text-xl inline-block font-semibold focus:outline-none focus:opacity-80"
                            :href="route('dashboard')" aria-label="Preline">
                            <ApplicationLogo class="w-12 h-12 p-1" />
                        </a>
                    </header>

                    <!-- Content -->
                    <div class="w-16 h-full flex flex-col">
                        <!-- Nav -->
                        <nav class="mt-2">
                            <ul class="text-center space-y-4">
                                <MenuDropdown active-item="/profile">
                                    <template #toggle-icon>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                            stroke-linecap="round" stroke-linejoin="round"
                                            class="lucide lucide-circle-plus text-gray-600 dark:text-neutral-800">
                                            <circle cx="12" cy="12" r="10" />
                                            <path d="M8 12h8" />
                                            <path d="M12 8v8" />
                                        </svg>
                                    </template>
                                </MenuDropdown>
                                <!-- Item -->
                                <LinkAncor :label="'Dashboard'" :href="'dashboard'"
                                    :active="route().current('dashboard')">
                                    <template #icon>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                            stroke-linecap="round" stroke-linejoin="round"
                                            class="lucide lucide-layout-dashboard">
                                            <rect width="7" height="9" x="3" y="3" rx="1" />
                                            <rect width="7" height="5" x="14" y="3" rx="1" />
                                            <rect width="7" height="9" x="14" y="12" rx="1" />
                                            <rect width="7" height="5" x="3" y="16" rx="1" />
                                        </svg>
                                    </template>
                                </LinkAncor>
                                <!-- End Item -->

                                <!-- Item -->
                                <LinkAncor v-if="showServices && page.props.auth.account?.is_owner" :label="'Clients'" :href="'customer.index'"
                                    :active="route().current('customer.index')">
                                    <template #icon>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-contact"><path d="M16 2v2"/><path d="M7 22v-2a2 2 0 0 1 2-2h6a2 2 0 0 1 2 2v2"/><path d="M8 2v2"/><circle cx="12" cy="11" r="3"/><rect x="3" y="4" width="18" height="18" rx="2"/></svg>
                                    </template>
                                </LinkAncor>
                                <!-- End Item -->

                                <!-- Item -->
                                <LinkAncor v-if="showProducts && page.props.auth.account?.is_owner" :label="'Products'" :href="'product.index'"
                                    :active="route().current('product.index')">
                                    <template #icon>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                            stroke-linecap="round" stroke-linejoin="round"
                                            class="lucide lucide-package-search">
                                            <path
                                                d="M21 10V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l2-1.14" />
                                            <path d="m7.5 4.27 9 5.15" />
                                            <polyline points="3.29 7 12 12 20.71 7" />
                                            <line x1="12" x2="12" y1="22" y2="12" />
                                            <circle cx="18.5" cy="15.5" r="2.5" />
                                            <path d="M20.27 17.27 22 19" />
                                        </svg>
                                    </template>
                                </LinkAncor>
                                <!-- End Item -->

                                <!-- Item -->
                                <LinkAncor v-if="showServices && page.props.auth.account?.is_owner" :label="'Services'" :href="'service.index'"
                                    :active="route().current('service.*')">
                                    <template #icon>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                            stroke-linecap="round" stroke-linejoin="round"
                                            class="lucide lucide-wrench">
                                            <path
                                                d="M14.7 6.3a4 4 0 0 0-5.66 5.66l-6.34 6.34a2 2 0 0 0 2.83 2.83l6.34-6.34a4 4 0 0 0 5.66-5.66l-2.12 2.12-2.83-2.83 2.12-2.12z" />
                                        </svg>
                                    </template>
                                </LinkAncor>
                                <!-- End Item -->
                                <!-- Item -->
                                <LinkAncor v-if="showServices && page.props.auth.account?.is_owner" :label="'Quotes'" :href="'quote.index'"
                                    :active="route().current('quote.index') || route().current('customer.quote.*')">
                                    <template #icon>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                            stroke-linecap="round" stroke-linejoin="round"
                                            class="lucide lucide-file-text">
                                            <path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2Z" />
                                            <path d="M14 2v6h6" />
                                            <path d="M8 13h8" />
                                            <path d="M8 17h5" />
                                        </svg>
                                    </template>
                                </LinkAncor>
                                <!-- End Item -->
                                <!-- Item -->
                                <LinkAncor v-if="showServices" :label="'Jobs'" :href="'jobs.index'"
                                    :active="route().current('jobs.index') || route().current('work.*')">
                                    <template #icon>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                            stroke-linecap="round" stroke-linejoin="round"
                                            class="lucide lucide-briefcase">
                                            <rect width="20" height="14" x="2" y="7" rx="2" />
                                            <path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2" />
                                            <path d="M2 13h20" />
                                        </svg>
                                    </template>
                                </LinkAncor>
                                <!-- End Item -->

                                <!-- Item -->
                                <LinkAncor :label="'Tasks'" :href="'task.index'"
                                    :active="route().current('task.*')">
                                    <template #icon>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                            stroke-linecap="round" stroke-linejoin="round"
                                            class="lucide lucide-check-square">
                                            <path d="M9 11l3 3L22 4" />
                                            <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11" />
                                        </svg>
                                    </template>
                                </LinkAncor>
                                <!-- End Item -->

                                <!-- Item -->
                                <LinkAncor v-if="page.props.auth.account?.is_owner" :label="'Team'" :href="'team.index'"
                                    :active="route().current('team.*')">
                                    <template #icon>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                            stroke-linecap="round" stroke-linejoin="round"
                                            class="lucide lucide-users">
                                            <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" />
                                            <circle cx="9" cy="7" r="4" />
                                            <path d="M22 21v-2a4 4 0 0 0-3-3.87" />
                                            <path d="M16 3.13a4 4 0 0 1 0 7.75" />
                                        </svg>
                                    </template>
                                </LinkAncor>
                                <!-- End Item -->
                                <!-- Item -->
                                <LinkAncor v-if="showServices && page.props.auth.account?.is_owner" :label="'Invoices'" :href="'invoice.index'"
                                    :active="route().current('invoice.*')">
                                    <template #icon>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                            stroke-linecap="round" stroke-linejoin="round"
                                            class="lucide lucide-receipt">
                                            <path d="M4 2h16v20l-4-2-4 2-4-2-4 2V2z" />
                                            <path d="M8 6h8" />
                                            <path d="M8 10h8" />
                                            <path d="M8 14h6" />
                                        </svg>
                                    </template>
                                </LinkAncor>
                                <!-- End Item -->
                            </ul>
                        </nav>
                        <!-- End Nav -->
                    </div>
                    <!-- End Content -->

                    <!-- Footer -->
                    <footer class="w-16 text-center space-y-3">
                        <!-- Account Dropdown -->
                        <div class="inline-flex justify-center w-full">
                            <div
                                class="hs-dropdown relative [--strategy:absolute] [--auto-close:inside] [--placement:bottom-right] inline-flex">
                                <button id="hs-pro-chmsad" type="button"
                                    class="flex justify-center items-center gap-x-3 size-8 text-start disabled:opacity-50 disabled:pointer-events-none focus:outline-none"
                                    aria-haspopup="menu" aria-expanded="false" aria-label="Dropdown">
                                    <img v-if="avatarUrl" class="shrink-0 size-8 rounded-full object-cover" :src="avatarUrl"
                                        :alt="userName || 'Avatar'">
                                    <div v-else class="size-8 rounded-full bg-gray-200 text-gray-700 flex items-center justify-center text-xs font-semibold dark:bg-neutral-800 dark:text-neutral-200">
                                        {{ avatarInitial }}
                                    </div>
                                    <span
                                        class="absolute -bottom-0 -end-0 block size-2 rounded-full ring-2 ring-gray-100 bg-green-500 dark:ring-neutral-800"></span>
                                </button>

                                <!-- Account Dropdown -->
                                <div class="hs-dropdown-menu hs-dropdown-open:opacity-100 w-48 transition-[opacity,margin] duration opacity-0 hidden z-20 bg-white rounded-xl shadow-[0_10px_40px_10px_rgba(0,0,0,0.08)] dark:shadow-[0_10px_40px_10px_rgba(0,0,0,0.2)] dark:bg-neutral-900"
                                    role="menu" aria-orientation="vertical" aria-labelledby="hs-pro-chmsad">
                                    <div class="px-3 pt-3 pb-2">
                                        <div class="text-sm font-semibold text-gray-800 dark:text-neutral-100">
                                            {{ userName || 'Account' }}
                                        </div>
                                        <div class="text-xs text-gray-500 dark:text-neutral-400 truncate">
                                            {{ userEmail }}
                                        </div>
                                        <div v-if="page.props.auth?.account?.company?.name" class="mt-1 text-xs text-gray-500 dark:text-neutral-400 truncate">
                                            Entreprise: {{ page.props.auth.account.company.name }}
                                        </div>
                                    </div>
                                    <div class="p-1">
                                        <Link v-if="isOwner" :href="route('settings.company.edit')"
                                            class="flex items-center gap-x-3 py-1.5 px-2.5 rounded-lg text-sm text-gray-800 hover:bg-gray-100 focus:outline-none focus:bg-gray-100 dark:text-neutral-300 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800">
                                            <svg class="shrink-0 mt-0.5 size-4" xmlns="http://www.w3.org/2000/svg"
                                                width="24" height="24" viewBox="0 0 24 24" fill="none"
                                                stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                                stroke-linejoin="round">
                                                <path d="M3 21V7a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v14" />
                                                <path d="M9 21V9h6v12" />
                                            </svg>
                                            Parametres entreprise
                                        </Link>

                                        <Link v-if="isOwner" :href="route('settings.billing.edit')"
                                            class="flex items-center gap-x-3 py-1.5 px-2.5 rounded-lg text-sm text-gray-800 hover:bg-gray-100 focus:outline-none focus:bg-gray-100 dark:text-neutral-300 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800">
                                            <svg class="shrink-0 mt-0.5 size-4" xmlns="http://www.w3.org/2000/svg"
                                                width="24" height="24" viewBox="0 0 24 24" fill="none"
                                                stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                                stroke-linejoin="round">
                                                <rect width="20" height="14" x="2" y="5" rx="2" />
                                                <line x1="2" x2="22" y1="10" y2="10" />
                                            </svg>
                                            Paiements
                                        </Link>

                                        <Link :href="route('profile.edit')"
                                            class="flex items-center gap-x-3 py-1.5 px-2.5 rounded-lg text-sm text-gray-800 hover:bg-gray-100 focus:outline-none focus:bg-gray-100 dark:text-neutral-300 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800">
                                            <svg class="shrink-0 mt-0.5 size-4" xmlns="http://www.w3.org/2000/svg"
                                                width="24" height="24" viewBox="0 0 24 24" fill="none"
                                                stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                                stroke-linejoin="round">
                                                <path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2" />
                                                <circle cx="12" cy="7" r="4" />
                                            </svg>
                                            Mon compte
                                        </Link>
                                    </div>
                                    <div class="py-1.5 px-3.5 border-y border-gray-200 dark:border-neutral-800">
                                        <!-- Switch/Toggle -->
                                        <div class="flex justify-between items-center">
                                            <label for="hs-pro-chmsaddm"
                                                class="text-sm text-gray-800 dark:text-neutral-300">Dark
                                                mode</label>
                                            <div class="relative inline-block">
                                                <input data-hs-theme-switch type="checkbox" id="hs-pro-chmsaddm"
                                                    class="relative w-11 h-6 p-px bg-gray-100 border-transparent text-transparent rounded-full cursor-pointer transition-colors ease-in-out duration-200 focus:ring-blue-600 disabled:opacity-50 disabled:pointer-events-none checked:bg-none checked:text-blue-600 checked:border-blue-600 focus:checked:border-blue-600 dark:bg-neutral-800 dark:border-neutral-700 dark:checked:bg-blue-500 dark:checked:border-blue-500 dark:focus:ring-offset-neutral-900

                      before:inline-block before:size-5 before:bg-white checked:before:bg-white before:translate-x-0 checked:before:translate-x-full before:rounded-full before:shadow before:transform before:ring-0 before:transition before:ease-in-out before:duration-200 dark:before:bg-neutral-400 dark:checked:before:bg-white">
                                            </div>
                                        </div>
                                        <!-- End Switch/Toggle -->
                                    </div>
                                    <div class="p-1">
                                        <Link :href="route('logout')" method="post" as="button" type="button"
                                            class="w-full flex items-center gap-x-3 py-1.5 px-2.5 rounded-lg text-sm text-gray-800 hover:bg-gray-100 focus:outline-none focus:bg-gray-100 dark:text-neutral-300 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800">
                                            Se deconnecter
                                        </Link>
                                    </div>
                                </div>
                                <!-- End Account Dropdown -->
                            </div>
                        </div>
                        <!-- End Account Dropdown -->
                    </footer>
                    <!-- End Footer -->
                </div>
            </div>
        </div>
    </aside>
    <QuickCreateModals />
</template>
