<script setup>
import ApplicationLogo from "@/Components/ApplicationLogo.vue";
import LinkAncor from "@/Components/UI/LinkAncor.vue";

const menuItems = [
    { label: "Overview", href: "product.index" },
    { label: "Add Product", href: "product.create" },
];

const item = [
    { label: "Dashboard", href: "/" }
]
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
                            href="../../pro/dashboard/index.html" aria-label="Preline">
                            <ApplicationLogo class="w-12 h-12 p-1" />
                        </a>
                    </header>

                    <!-- Content -->
                    <div class="w-16 h-full flex flex-col">
                        <!-- Nav -->
                        <nav class="mt-2">
                            <ul class="text-center space-y-4">
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
                                <LinkAncor :label="'Products'" :href="'product.index'"
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
                                <li>
                                    <!-- More Dropdown -->
                                    <div
                                        class="hs-dropdown [--strategy:absolute] [--placement:bottom-right] [--auto-close:inside] relative inline-flex">
                                        <button id="hs-pro-chmsnmd" type="button"
                                            class="relative group flex flex-col justify-center items-center gap-y-1 text-[11px] text-gray-600 dark:text-neutral-200 disabled:opacity-50 disabled:pointer-events-none focus:outline-none"
                                            aria-haspopup="menu" aria-expanded="false" aria-label="Dropdown">
                                            <span
                                                class="flex justify-center items-center gap-x-3 size-9 rounded-lg group-hover:bg-gray-300 group-focus:bg-gray-300 dark:group-hover:bg-neutral-800 dark:group-focus:bg-neutral-800">
                                                <svg class="shrink-0 size-4 group-hover:scale-110 mx-auto transition"
                                                    xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                                    viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                    stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                    <path d="M20 7h-9" />
                                                    <path d="M14 17H5" />
                                                    <circle cx="17" cy="17" r="3" />
                                                    <circle cx="7" cy="7" r="3" />
                                                </svg>
                                            </span>
                                            Settings
                                        </button>

                                        <!-- More Dropdown -->
                                        <div class="hs-dropdown-menu hs-dropdown-open:opacity-100 w-40 transition-[opacity,margin] duration opacity-0 hidden z-10 bg-white rounded-xl shadow-lg dark:bg-neutral-900"
                                            role="menu" aria-orientation="vertical" aria-labelledby="hs-pro-chmsnmd">
                                            <div class="p-1 space-y-0.5">
                                                <!-- Item -->
                                                <label for="hs-pro-chsnldin"
                                                    class="py-1.5 px-3 group flex items-center gap-x-3 rounded-lg cursor-pointer text-[13px] text-gray-800 hover:bg-gray-100 dark:text-neutral-200 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800">
                                                    <input type="checkbox" class="hidden" id="hs-pro-chsnldin"
                                                        name="hs-pro-chsnld" checked>
                                                    <svg class="shrink-0 size-4" xmlns="http://www.w3.org/2000/svg"
                                                        width="24" height="24" viewBox="0 0 24 24" fill="none"
                                                        stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                                        stroke-linejoin="round">
                                                        <path
                                                            d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" />
                                                    </svg>
                                                    Inbox
                                                    <svg class="shrink-0 size-3.5 ms-auto opacity-0 group-has-[:checked]:opacity-100"
                                                        xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                                        viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                        <path d="M20 6 9 17l-5-5" />
                                                    </svg>
                                                </label>
                                                <!-- End Item -->
                                            </div>
                                        </div>
                                        <!-- End More Dropdown -->
                                    </div>
                                    <!-- End More Dropdown -->
                                </li>
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
                                    <img class="shrink-0 rounded-full"
                                        src="https://images.unsplash.com/photo-1659482633369-9fe69af50bfb?ixlib=rb-4.0.3&ixid=MnwxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8&auto=format&fit=facearea&facepad=3&w=320&h=320&q=80"
                                        alt="Avatar">
                                    <span
                                        class="absolute -bottom-0 -end-0 block size-2 rounded-full ring-2 ring-gray-100 bg-green-500 dark:ring-neutral-800"></span>
                                </button>

                                <!-- Account Dropdown -->
                                <div class="hs-dropdown-menu hs-dropdown-open:opacity-100 w-48 transition-[opacity,margin] duration opacity-0 hidden z-20 bg-white rounded-xl shadow-[0_10px_40px_10px_rgba(0,0,0,0.08)] dark:shadow-[0_10px_40px_10px_rgba(0,0,0,0.2)] dark:bg-neutral-900"
                                    role="menu" aria-orientation="vertical" aria-labelledby="hs-pro-chmsad">
                                    <div class="p-1">
                                        <a class="flex items-center gap-x-3 py-1.5 px-2.5 rounded-lg text-sm text-gray-800 hover:bg-gray-100 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-gray-100 dark:text-neutral-300 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800"
                                            href="#">
                                            <svg class="shrink-0 mt-0.5 size-4" xmlns="http://www.w3.org/2000/svg"
                                                width="24" height="24" viewBox="0 0 24 24" fill="none"
                                                stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                                stroke-linejoin="round">
                                                <rect width="20" height="14" x="2" y="5" rx="2" />
                                                <line x1="2" x2="22" y1="10" y2="10" />
                                            </svg>
                                            Billing
                                        </a>
                                        <a class="flex items-center gap-x-3 py-1.5 px-2.5 rounded-lg text-sm text-gray-800 hover:bg-gray-100 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-gray-100 dark:text-neutral-300 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800"
                                            href="#">
                                            <svg class="shrink-0 size-4" xmlns="http://www.w3.org/2000/svg" width="24"
                                                height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path
                                                    d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z" />
                                                <circle cx="12" cy="12" r="3" />
                                            </svg>
                                            Settings
                                        </a>
                                        <a class="flex items-center gap-x-3 py-1.5 px-2.5 rounded-lg text-sm text-gray-800 hover:bg-gray-100 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-gray-100 dark:text-neutral-300 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800"
                                            href="#">
                                            <svg class="shrink-0 mt-0.5 size-4" xmlns="http://www.w3.org/2000/svg"
                                                width="24" height="24" viewBox="0 0 24 24" fill="none"
                                                stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                                stroke-linejoin="round">
                                                <path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2" />
                                                <circle cx="12" cy="7" r="4" />
                                            </svg>
                                            My account
                                        </a>
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
                                        <a class="flex items-center gap-x-3 py-1.5 px-2.5 rounded-lg text-sm text-gray-800 hover:bg-gray-100 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-gray-100 dark:text-neutral-300 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800"
                                            href="#">
                                            Sign out
                                        </a>
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
</template>
