<script setup>
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    customer: Object,
});
</script>
<template>
    <div
        class="p-5 bg-white border border-stone-200 rounded-sm border-t-4 border-t-neutral-800 shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
        <!-- Header -->
        <div class="mb-5 mt-4 flex flex-wrap justify-between items-center gap-3">
            <!-- Heading -->
            <div class="flex items-center gap-x-4">
                <span class="shrink-0 relative size-14">
                    <img :src="customer.logo_url || customer.logo" :alt="customer.company_name"
                        class="absolute inset-0 object-cover w-full h-full rounded-sm">
                    <span
                        class="absolute flex shrink-0 justify-center items-center size-6 overflow-hidden -bottom-1 -end-1 bg-white border border-transparent text-gray-800 dark:bg-neutral-700 dark:border-neutral-900 dark:text-neutral-200 rounded-sm">
                        <svg class="shrink-0 size-4" xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                            stroke-linecap="round" stroke-linejoin="round">
                            <path d="M17 7 7 17" />
                            <path d="M17 17H7V7" />
                        </svg>
                    </span>
                </span>

                <div class="grow">
                    <p class="text-2xl md:text-3xl font-semibold text-gray-600 dark:text-white">
                        {{ customer.company_name || `${customer.first_name} ${customer.last_name}` }}
                    </p>
                    <p class="text-sm text-gray-500 dark:text-neutral-400">
                        {{ customer.email }} | {{ customer.phone || 'No phone' }}
                    </p>
                </div>
            </div>
            <!-- End Heading -->

            <!-- Button Group -->
            <div class="flex flex-wrap gap-2">

                <!-- Button -->
                <Link :href="route('customer.index')"
                    class="py-1 px-3 inline-flex items-center gap-x-1.5 text-sm font-medium rounded-sm border border-gray-100 bg-white text-gray-800 hover:bg-gray-50 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-gray-50 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800">
                    Back to list
                </Link>
                <!-- End Button -->

                <!-- Button Group -->
                <div class="inline-flex items-center">
                    <!-- Button -->
                    <!-- End Button -->

                    <!-- More Send Options Dropdown -->
                    <div class="hs-dropdown [--auto-close:inside] [--placement:top-right] relative inline-flex">
                        <div class="hs-tooltip [--placement:bottom-right] inline-block">
                            <button id="hs-pro-in1trsbgwmdid1" type="button"
                                class="hs-tooltip-toggle py-2 px-2.5 inline-flex items-center gap-x-1.5 text-xs font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:ring-2 focus:ring-green-500">
                                ... More action
                                <span class="sr-only">More send options</span>
                            </button>
                            <span
                                class="hs-tooltip-content hs-tooltip-shown:opacity-100 hs-tooltip-shown:visible opacity-0 inline-block absolute invisible z-[60] py-1.5 px-2.5 bg-gray-900 text-xs text-white rounded-sm whitespace-nowrap dark:bg-neutral-700"
                                role="tooltip">
                                More send options
                            </span>
                        </div>

                        <!-- More Send Options Dropdown -->
                        <div class="hs-dropdown-menu hs-dropdown-open:opacity-100 w-40 transition-[opacity,margin] duration opacity-0 hidden z-[11] bg-white rounded-sm shadow-lg dark:bg-neutral-950"
                            role="menu" aria-orientation="vertical" aria-labelledby="hs-pro-in1trsbgwmdid1">
                            <!-- List -->
                            <div class="p-1">
                                <Link :href="route('customer.quote.create', customer)">
                                    <button type="button"
                                        class="w-full flex items-center gap-x-3 py-2 px-3 rounded-sm text-sm text-gray-800 hover:bg-gray-100 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-gray-100 dark:text-neutral-300 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                            fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                            stroke-linejoin="round" class="lucide lucide-text-quote">
                                            <path d="M17 6H3" />
                                            <path d="M21 12H8" />
                                            <path d="M21 18H8" />
                                            <path d="M3 12v6" />
                                        </svg>
                                        Create quote
                                    </button>
                                </Link>

                                <button type="button"
                                    class="w-full flex items-center gap-x-3 py-2 px-3 rounded-sm text-sm text-gray-800 hover:bg-gray-100 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-gray-100 dark:text-neutral-300 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                        fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                        stroke-linejoin="round" class="lucide lucide-git-pull-request">
                                        <circle cx="18" cy="18" r="3" />
                                        <circle cx="6" cy="6" r="3" />
                                        <path d="M13 6h3a2 2 0 0 1 2 2v7" />
                                        <line x1="6" x2="6" y1="9" y2="21" />
                                    </svg>
                                    create request
                                </button>
                                <Link :href="route('work.create', customer)">
                                    <button type="button"
                                        class="w-full flex items-center gap-x-3 py-2 px-3 rounded-sm text-sm text-gray-800 hover:bg-gray-100 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-gray-100 dark:text-neutral-300 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                            fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                            stroke-linejoin="round" class="lucide lucide-monitor-cog">
                                            <path d="M12 17v4" />
                                            <path d="m15.2 4.9-.9-.4" />
                                            <path d="m15.2 7.1-.9.4" />
                                            <path d="m16.9 3.2-.4-.9" />
                                            <path d="m16.9 8.8-.4.9" />
                                            <path d="m19.5 2.3-.4.9" />
                                            <path d="m19.5 9.7-.4-.9" />
                                            <path d="m21.7 4.5-.9.4" />
                                            <path d="m21.7 7.5-.9-.4" />
                                            <path d="M22 13v2a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h7" />
                                            <path d="M8 21h8" />
                                            <circle cx="18" cy="6" r="3" />
                                        </svg>
                                        Create job
                                    </button>
                                </Link>
                                <button type="button"
                                    class="w-full flex items-center gap-x-3 py-2 px-3 rounded-sm text-sm text-gray-800 hover:bg-gray-100 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-gray-100 dark:text-neutral-300 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                        fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                        stroke-linejoin="round" class="lucide lucide-file-text">
                                        <path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z" />
                                        <path d="M14 2v4a2 2 0 0 0 2 2h4" />
                                        <path d="M10 9H8" />
                                        <path d="M16 13H8" />
                                        <path d="M16 17H8" />
                                    </svg>
                                    Create invoice
                                </button>
                            </div>
                            <!-- List -->
                        </div>
                        <!-- End More Send Options Dropdown -->
                    </div>
                    <!-- End More Send Options Dropdown -->
                </div>
                <!-- End Button Group -->


            </div>
            <!-- End Button Group -->
        </div>
        <!-- End Header -->
    </div>
</template>
