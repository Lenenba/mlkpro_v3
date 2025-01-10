<script setup>
import ApplicationLogo from "@/Components/ApplicationLogo.vue";
import { Link } from "@inertiajs/vue3";
import DynamicAccordion from "@/Components/UI/DynamicAccordion.vue";
import SingleLink from "@/Components/UI/SingleLink.vue";

const menuItems = [
    { label: "Overview", href: "product.index" },
    { label: "Add Product", href: "product.index" },
];

const item = [
    { label: "Dashboard", href: "/" }
]
</script>

<template>
    <aside id="hs-pro-sidebar" class="hs-overlay [--auto-close:lg]
      hs-overlay-open:translate-x-0
      -translate-x-full transition-all duration-300 transform
      w-[260px] h-full
      hidden
      fixed inset-y-0 start-0 z-[60]
      bg-white border-e border-gray-200
      lg:block lg:translate-x-0 lg:end-auto lg:bottom-0
      dark:bg-neutral-800 dark:border-neutral-700" tabindex="-1" aria-label="Sidebar">
        <div class="relative flex flex-col h-full max-h-full pt-3">
            <header class="h-[46px] ps-5 pe-2 lg:ps-8 flex items-center gap-x-1">
                <!-- Logo -->
                <a class="flex-none rounded-md text-xl inline-block font-semibold focus:outline-none focus:opacity-80"
                    href="@@href" aria-label="Preline">
                    <ApplicationLogo />
                </a>
                <!-- End Logo -->

                <div class="lg:hidden ms-auto">
                    <!-- Sidebar Close -->
                    <button type="button"
                        class="w-6 h-7 inline-flex justify-center items-center gap-x-2 text-sm font-medium rounded-md border border-gray-200 bg-white text-gray-500 hover:bg-gray-50 focus:outline-none focus:bg-gray-100 disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-700 dark:focus:bg-neutral-700"
                        data-hs-overlay="#hs-pro-sidebar">
                        <svg class="shrink-0 size-4" xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                            stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="7 8 3 12 7 16" />
                            <line x1="21" x2="11" y1="12" y2="12" />
                            <line x1="21" x2="11" y1="6" y2="6" />
                            <line x1="21" x2="11" y1="18" y2="18" />
                        </svg>
                    </button>
                    <!-- End Sidebar Close -->
                </div>
                <div class="hidden lg:block ms-2">

                </div>
            </header>

            <!-- Content -->
            <div
                class="mt-1.5 h-full overflow-y-auto [&::-webkit-scrollbar]:w-2 [&::-webkit-scrollbar-thumb]:rounded-full [&::-webkit-scrollbar-track]:bg-gray-100 [&::-webkit-scrollbar-thumb]:bg-gray-300 dark:[&::-webkit-scrollbar-track]:bg-neutral-700 dark:[&::-webkit-scrollbar-thumb]:bg-neutral-500">
                <!-- Nav -->
                <nav class="hs-accordion-group pb-3  w-full flex flex-col flex-wrap" data-hs-accordion-always-open>
                    <ul class="flex flex-col gap-y-1">
                        <!-- Link -->
                        <SingleLink :menuItems="item">
                            <template #icon>
                                <svg class="shrink-0 mt-0.5 size-4" xmlns="http://www.w3.org/2000/svg" width="24"
                                    height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                    stroke-linecap="round" stroke-linejoin="round">
                                    <path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" />
                                    <polyline points="9 22 9 12 15 12 15 22" />
                                </svg>
                            </template>
                        </SingleLink>
                        <!-- End Link -->

                        <!-- Link -->
                        <DynamicAccordion :menuItems="menuItems" title="Products">
                            <template #icon>
                                <!-- SVG personnalisé -->
                                <svg class="shrink-0 mt-0.5 size-4" xmlns="http://www.w3.org/2000/svg" width="24"
                                    height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                    stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" />
                                    <circle cx="9" cy="7" r="4" />
                                    <path d="M22 21v-2a4 4 0 0 0-3-3.87" />
                                    <path d="M16 3.13a4 4 0 0 1 0 7.75" />
                                </svg>
                            </template>
                        </DynamicAccordion>
                        <!-- End Link -->

                        <!-- Divider -->
                        <li
                            class="pt-5 px-5 lg:px-8 mt-5 border-t border-gray-200 first:border-transparent first:pt-0 dark:border-neutral-700 dark:first:border-transparent">
                            <span class="block text-xs uppercase text-gray-500 dark:text-neutral-500">
                                Apps
                            </span>
                        </li>
                        <!-- End Divider -->

                        <!-- Link -->
                        <li class="px-2 lg:px-5">
                            <a class="flex items-center gap-x-2 py-1.5 px-3 text-sm text-gray-800 rounded-lg hover:bg-gray-100 focus:outline-none focus:bg-gray-100  dark:hover:bg-neutral-700 dark:text-neutral-300 dark:focus:bg-neutral-700"
                                href="../../pro/dashboard/chat.html">
                                <span
                                    class="flex justify-center items-center size-6 bg-blue-600 text-white rounded-md dark:bg-blue-500">
                                    <svg class="shrink-0 size-3" xmlns="http://www.w3.org/2000/svg" width="24"
                                        height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" />
                                    </svg>
                                </span>
                                Chat
                            </a>
                        </li>
                        <!-- End Link -->
                    </ul>
                </nav>
                <!-- End Nav -->
            </div>
            <!-- End Content -->

        </div>
    </aside>
</template>
