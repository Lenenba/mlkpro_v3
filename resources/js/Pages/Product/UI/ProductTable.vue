<script setup>
import { watch } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import ProductForm from  './ProductForm.vue';
import Modal from '@/Components/UI/Modal.vue';

const props = defineProps({
    filters: Object,
    products: {
        type: Object,
        required: true,
    },
    product: {
        type: Object,
        default: null,
    },
    categories: {
        type: Array,
        required: true,
    },
    count: {
        type: Number,
        required: true,
    },
});

const filterForm = useForm({
    name: props.filters.name ?? "",
});


// Fonction de filtrage avec un délai pour éviter des appels excessifs
let filterTimeout;
const autoFilter = (routeName) => {
    if (filterTimeout) {
        clearTimeout(filterTimeout);
    }
    filterTimeout = setTimeout(() => {
        filterForm.get(route(routeName), {
            preserveState: true,
            preserveScroll: true,
        });
    }, 300); // Délai de 300ms pour éviter les appels excessifs
};

// Réinitialiser le formulaire lorsque la recherche est vide
watch(() => filterForm.name, (newValue) => {
    if (!newValue) {
        filterForm.name = "";
        autoFilter('product.index');
    }
});

</script>

<template>
    <!-- Orders Table Card -->
    <div
        class="p-5 space-y-4 flex flex-col bg-white border border-stone-200 shadow-sm rounded-xl dark:bg-neutral-800 dark:border-neutral-700">
        <!-- Nav Tab -->
        <nav class="relative  flex space-x-1 after:absolute after:bottom-0 after:inset-x-0 after:border-b-2 after:border-stone-200 dark:after:border-neutral-700"
            aria-label="Tabs" role="tablist" aria-orientation="horizontal">
            <button type="button"
                class="hs-tab-active:after:bg-stone-800 hs-tab-active:text-stone-800 px-2.5 py-1.5 mb-2 relative inline-flex justify-center items-center gap-x-2  hover:bg-stone-100 text-stone-500 hover:text-stone-800 text-sm rounded-lg disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 after:absolute after:-bottom-2 after:inset-x-0 after:z-10 after:h-0.5 after:pointer-events-none dark:hs-tab-active:text-neutral-200 dark:hs-tab-active:after:bg-neutral-400 dark:text-neutral-500 dark:hover:text-neutral-300 dark:hover:bg-neutral-700 dark:focus:bg-neutral-700 active "
                id="hs-pro-tabs-dut-item-all" aria-selected="true" data-hs-tab="#hs-pro-tabs-dut-all"
                aria-controls="hs-pro-tabs-dut-all" role="tab">
                All
            </button>
            <button type="button"
                class="hs-tab-active:after:bg-stone-800 hs-tab-active:text-stone-800 px-2.5 py-1.5 mb-2 relative inline-flex justify-center items-center gap-x-2  hover:bg-stone-100 text-stone-500 hover:text-stone-800 text-sm rounded-lg disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 after:absolute after:-bottom-2 after:inset-x-0 after:z-10 after:h-0.5 after:pointer-events-none dark:hs-tab-active:text-neutral-200 dark:hs-tab-active:after:bg-neutral-400 dark:text-neutral-500 dark:hover:text-neutral-300 dark:hover:bg-neutral-700 dark:focus:bg-neutral-700  "
                id="hs-pro-tabs-dut-item-archived" aria-selected="false" data-hs-tab="#hs-pro-tabs-dut-archived"
                aria-controls="hs-pro-tabs-dut-archived" role="tab">
                Archived
            </button>
            <button type="button"
                class="hs-tab-active:after:bg-stone-800 hs-tab-active:text-stone-800 px-2.5 py-1.5 mb-2 relative inline-flex justify-center items-center gap-x-2  hover:bg-stone-100 text-stone-500 hover:text-stone-800 text-sm rounded-lg disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 after:absolute after:-bottom-2 after:inset-x-0 after:z-10 after:h-0.5 after:pointer-events-none dark:hs-tab-active:text-neutral-200 dark:hs-tab-active:after:bg-neutral-400 dark:text-neutral-500 dark:hover:text-neutral-300 dark:hover:bg-neutral-700 dark:focus:bg-neutral-700  "
                id="hs-pro-tabs-dut-item-publish" aria-selected="false" data-hs-tab="#hs-pro-tabs-dut-publish"
                aria-controls="hs-pro-tabs-dut-publish" role="tab">
                Publish
            </button>
            <button type="button"
                class="hs-tab-active:after:bg-stone-800 hs-tab-active:text-stone-800 px-2.5 py-1.5 mb-2 relative inline-flex justify-center items-center gap-x-2  hover:bg-stone-100 text-stone-500 hover:text-stone-800 text-sm rounded-lg disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 after:absolute after:-bottom-2 after:inset-x-0 after:z-10 after:h-0.5 after:pointer-events-none dark:hs-tab-active:text-neutral-200 dark:hs-tab-active:after:bg-neutral-400 dark:text-neutral-500 dark:hover:text-neutral-300 dark:hover:bg-neutral-700 dark:focus:bg-neutral-700  "
                id="hs-pro-tabs-dut-item-unpublish" aria-selected="false" data-hs-tab="#hs-pro-tabs-dut-unpublish"
                aria-controls="hs-pro-tabs-dut-unpublish" role="tab">
                Unpublish
            </button>
        </nav>
        <!-- End Nav Tab -->

        <!-- Filter Group -->
        <div class="grid md:grid-cols-2 gap-y-2 md:gap-y-0 md:gap-x-5">
            <div>
                <!-- Search Input -->
                <div class="relative">
                    <div class="absolute inset-y-0 start-0 flex items-center pointer-events-none z-20 ps-3.5">
                        <svg class="shrink-0 size-4 text-stone-500 dark:text-neutral-400"
                            xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="11" cy="11" r="8" />
                            <path d="m21 21-4.3-4.3" />
                        </svg>
                    </div>
                    <input type="text"
                        v-model="filterForm.name"
                        @input="filterForm.name.length >= 1  ? autoFilter('product.index') : null"
                        class="py-[7px] ps-10 pe-8 block w-full bg-stone-100 border-transparent rounded-lg text-sm placeholder:text-stone-500 focus:border-green-500 focus:ring-green-600 disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-700 dark:border-transparent dark:text-neutral-400 dark:placeholder:text-neutral-400 dark:focus:ring-neutral-600"
                        placeholder="Search orders">
                    <div class="hidden absolute inset-y-0 end-0 flex items-center pointer-events-none z-20 pe-1">
                        <button type="button"
                            class="inline-flex shrink-0 justify-center items-center size-6 rounded-full text-gray-500 hover:text-green-600 focus:outline-none focus:text-green-600 dark:text-neutral-500 dark:hover:text-green-500 dark:focus:text-green-500"
                            aria-label="Close">
                            <span class="sr-only">Close</span>
                            <svg class="shrink-0 size-4" xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="12" r="10" />
                                <path d="m15 9-6 6" />
                                <path d="m9 9 6 6" />
                            </svg>
                        </button>
                    </div>
                </div>
                <!-- End Search Input -->
            </div>
            <!-- End Col -->

            <div class="flex md:justify-end items-center gap-x-2">

                <!-- Filter Dropdown -->
                <div class="hs-dropdown [--auto-close:inside] [--placement:bottom-right] relative inline-flex">
                    <!-- Filter Button -->
                    <button id="hs-pro-dupfind" type="button"
                        class="py-2 px-2.5 inline-flex items-center gap-x-1.5 text-xs font-medium rounded-lg border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-700 dark:focus:bg-neutral-700"
                        aria-haspopup="menu" aria-expanded="false" aria-label="Dropdown">
                        <svg class="shrink-0 size-3.5" xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                            stroke-linecap="round" stroke-linejoin="round">
                            <line x1="21" x2="14" y1="4" y2="4" />
                            <line x1="10" x2="3" y1="4" y2="4" />
                            <line x1="21" x2="12" y1="12" y2="12" />
                            <line x1="8" x2="3" y1="12" y2="12" />
                            <line x1="21" x2="16" y1="20" y2="20" />
                            <line x1="12" x2="3" y1="20" y2="20" />
                            <line x1="14" x2="14" y1="2" y2="6" />
                            <line x1="8" x2="8" y1="10" y2="14" />
                            <line x1="16" x2="16" y1="18" y2="22" />
                        </svg>
                        Filter
                        <span
                            class="font-medium text-[10px] py-0.5 px-[5px] bg-stone-800 text-white leading-3 rounded-full dark:bg-neutral-500">
                            7
                        </span>
                    </button>
                    <!-- End Filter Button -->

                    <!-- Dropdown -->
                    <div class="hs-dropdown-menu hs-dropdown-open:opacity-100 w-44 transition-[opacity,margin] duration opacity-0 hidden z-10 bg-white rounded-xl shadow-[0_10px_40px_10px_rgba(0,0,0,0.08)] dark:shadow-[0_10px_40px_10px_rgba(0,0,0,0.2)] dark:bg-neutral-900"
                        role="menu" aria-orientation="vertical" aria-labelledby="hs-pro-dupfind">
                        <div class="p-1">
                            <div
                                class="flex items-center gap-x-3 py-1.5 px-2 cursor-pointer rounded-lg hover:bg-stone-100 dark:hover:bg-neutral-800 dark:focus:outline-none dark:focus:ring-1 dark:focus:ring-neutral-600">
                                <input type="checkbox"
                                    class="shrink-0 border-stone-300 rounded text-green-600 focus:ring-green-600 disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-800 dark:border-neutral-600 dark:checked:bg-green-500 dark:checked:border-green-500 dark:focus:ring-offset-neutral-800"
                                    id="hs-pro-dupfindch2" checked>
                                <label for="hs-pro-dupfindch2"
                                    class="flex flex-1 items-center gap-x-3 cursor-pointer text-[13px] text-stone-800 dark:text-neutral-300">
                                    Order
                                </label>
                            </div>

                            <div
                                class="flex items-center gap-x-3 py-1.5 px-2 cursor-pointer rounded-lg hover:bg-stone-100 dark:hover:bg-neutral-800 dark:focus:outline-none dark:focus:ring-1 dark:focus:ring-neutral-600">
                                <input type="checkbox"
                                    class="shrink-0 border-stone-300 rounded text-green-600 focus:ring-green-600 disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-800 dark:border-neutral-600 dark:checked:bg-green-500 dark:checked:border-green-500 dark:focus:ring-offset-neutral-800"
                                    id="hs-pro-dupfindch3" checked>
                                <label for="hs-pro-dupfindch3"
                                    class="flex flex-1 items-center gap-x-3 cursor-pointer text-[13px] text-stone-800 dark:text-neutral-300">
                                    Purchased
                                </label>
                            </div>

                            <div
                                class="flex items-center gap-x-3 py-1.5 px-2 cursor-pointer rounded-lg hover:bg-stone-100 dark:hover:bg-neutral-800 dark:focus:outline-none dark:focus:ring-1 dark:focus:ring-neutral-600">
                                <input type="checkbox"
                                    class="shrink-0 border-stone-300 rounded text-green-600 focus:ring-green-600 disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-800 dark:border-neutral-600 dark:checked:bg-green-500 dark:checked:border-green-500 dark:focus:ring-offset-neutral-800"
                                    id="hs-pro-dupfindch4" checked>
                                <label for="hs-pro-dupfindch4"
                                    class="flex flex-1 items-center gap-x-3 cursor-pointer text-[13px] text-stone-800 dark:text-neutral-300">
                                    Status
                                </label>
                            </div>

                            <div
                                class="flex items-center gap-x-3 py-1.5 px-2 cursor-pointer rounded-lg hover:bg-stone-100 dark:hover:bg-neutral-800 dark:focus:outline-none dark:focus:ring-1 dark:focus:ring-neutral-600">
                                <input type="checkbox"
                                    class="shrink-0 border-stone-300 rounded text-green-600 focus:ring-green-600 disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-800 dark:border-neutral-600 dark:checked:bg-green-500 dark:checked:border-green-500 dark:focus:ring-offset-neutral-800"
                                    id="hs-pro-dupfindch5" checked>
                                <label for="hs-pro-dupfindch5"
                                    class="flex flex-1 items-center gap-x-3 cursor-pointer text-[13px] text-stone-800 dark:text-neutral-300">
                                    Customer
                                </label>
                            </div>

                            <div
                                class="flex items-center gap-x-3 py-1.5 px-2 cursor-pointer rounded-lg hover:bg-stone-100 dark:hover:bg-neutral-800 dark:focus:outline-none dark:focus:ring-1 dark:focus:ring-neutral-600">
                                <input type="checkbox"
                                    class="shrink-0 border-stone-300 rounded text-green-600 focus:ring-green-600 disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-800 dark:border-neutral-600 dark:checked:bg-green-500 dark:checked:border-green-500 dark:focus:ring-offset-neutral-800"
                                    id="hs-pro-dupfindch6" checked>
                                <label for="hs-pro-dupfindch6"
                                    class="flex flex-1 items-center gap-x-3 cursor-pointer text-[13px] text-stone-800 dark:text-neutral-300">
                                    Payment Method
                                </label>
                            </div>

                            <div
                                class="flex items-center gap-x-3 py-1.5 px-2 cursor-pointer rounded-lg hover:bg-stone-100 dark:hover:bg-neutral-800 dark:focus:outline-none dark:focus:ring-1 dark:focus:ring-neutral-600">
                                <input type="checkbox"
                                    class="shrink-0 border-stone-300 rounded text-green-600 focus:ring-green-600 disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-800 dark:border-neutral-600 dark:checked:bg-green-500 dark:checked:border-green-500 dark:focus:ring-offset-neutral-800"
                                    id="hs-pro-dupfindch7" checked>
                                <label for="hs-pro-dupfindch7"
                                    class="flex flex-1 items-center gap-x-3 cursor-pointer text-[13px] text-stone-800 dark:text-neutral-300">
                                    Payment Status
                                </label>
                            </div>

                            <div
                                class="flex items-center gap-x-3 py-1.5 px-2 cursor-pointer rounded-lg hover:bg-stone-100 dark:hover:bg-neutral-800 dark:focus:outline-none dark:focus:ring-1 dark:focus:ring-neutral-600">
                                <input type="checkbox"
                                    class="shrink-0 border-stone-300 rounded text-green-600 focus:ring-green-600 disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-800 dark:border-neutral-600 dark:checked:bg-green-500 dark:checked:border-green-500 dark:focus:ring-offset-neutral-800"
                                    id="hs-pro-dupfindch8" checked>
                                <label for="hs-pro-dupfindch8"
                                    class="flex flex-1 items-center gap-x-3 cursor-pointer text-[13px] text-stone-800 dark:text-neutral-300">
                                    Items
                                </label>
                            </div>
                        </div>
                    </div>
                    <!-- End Dropdown -->

                    <div class="flex justify-end items-center gap-x-2">
                        <!-- Button -->
                        <button type="button"
                            class="py-2 px-2.5 ml-4 inline-flex items-center gap-x-1.5 text-xs font-medium rounded-lg border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:ring-2 focus:ring-green-500"
                            data-hs-overlay="#hs-pro-dasadpm">
                            <svg class="hidden sm:block shrink-0 size-3.5" xmlns="http://www.w3.org/2000/svg" width="24"
                                height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                stroke-linecap="round" stroke-linejoin="round">
                                <path d="M5 12h14" />
                                <path d="M12 5v14" />
                            </svg>
                            Add product
                        </button>
                        <!-- End Button -->
                    </div>
                </div>
                <!-- End Filter Dropdown -->
            </div>
            <!-- End Col -->
        </div>
        <!-- End Filter Group -->

        <div>
            <!-- Tab Content -->
            <div id="hs-pro-tabs-dut-all" role="tabpanel" aria-labelledby="hs-pro-tabs-dut-item-all">
                <!-- Table Section -->
                <div
                    class="overflow-x-auto [&::-webkit-scrollbar]:h-2 [&::-webkit-scrollbar-thumb]:rounded-full [&::-webkit-scrollbar-track]:bg-stone-100 [&::-webkit-scrollbar-thumb]:bg-stone-300 dark:[&::-webkit-scrollbar-track]:bg-neutral-700 dark:[&::-webkit-scrollbar-thumb]:bg-neutral-500">
                    <div class="min-w-full inline-block align-middle">
                        <!-- Table -->
                        <table class="min-w-full divide-y divide-stone-200 dark:divide-neutral-700">
                            <thead>
                                <tr class="border-t border-stone-200 dark:border-neutral-700">
                                    <th scope="col" class="px-3 py-2.5 text-start">
                                        <input type="checkbox"
                                            class="shrink-0 border-stone-300 rounded text-green-600 focus:ring-green-600 disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-800 dark:border-neutral-600 dark:checked:bg-green-500 dark:checked:border-green-500 dark:focus:ring-offset-neutral-800">
                                    </th>

                                    <th scope="col">
                                        <!-- Sort Dropdown -->
                                        <div class="hs-dropdown relative inline-flex w-full cursor-pointer">
                                            <button id="hs-pro-eptors" type="button"
                                                class="px-5 py-2.5 text-start w-full flex items-center gap-x-1 text-sm font-normal text-stone-500 focus:outline-none focus:bg-stone-100 dark:text-neutral-500 dark:focus:bg-neutral-700"
                                                aria-haspopup="menu" aria-expanded="false" aria-label="Dropdown">
                                                #Code
                                                <svg class="shrink-0 size-3.5" xmlns="http://www.w3.org/2000/svg"
                                                    width="24" height="24" viewBox="0 0 24 24" fill="none"
                                                    stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                                    stroke-linejoin="round">
                                                    <path d="m7 15 5 5 5-5" />
                                                    <path d="m7 9 5-5 5 5" />
                                                </svg>
                                            </button>

                                            <!-- Dropdown -->
                                            <div class="hs-dropdown-menu hs-dropdown-open:opacity-100 w-40 transition-[opacity,margin] duration opacity-0 hidden z-10 bg-white rounded-xl shadow-[0_10px_40px_10px_rgba(0,0,0,0.08)] dark:shadow-[0_10px_40px_10px_rgba(0,0,0,0.2)] dark:bg-neutral-900"
                                                role="menu" aria-orientation="vertical" aria-labelledby="hs-pro-eptors">
                                                <div class="p-1">
                                                    <button type="button"
                                                        class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-lg text-[13px] font-normal text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none dark:text-neutral-300 focus:outline-none focus:bg-stone-100 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800">
                                                        <svg class="shrink-0 size-3.5"
                                                            xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                                            viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                            stroke-width="2" stroke-linecap="round"
                                                            stroke-linejoin="round">
                                                            <path d="m5 12 7-7 7 7" />
                                                            <path d="M12 19V5" />
                                                        </svg>
                                                        Sort ascending
                                                    </button>
                                                    <button type="button"
                                                        class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-lg text-[13px] font-normal text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none dark:text-neutral-300 focus:outline-none focus:bg-stone-100 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800">
                                                        <svg class="shrink-0 size-3.5"
                                                            xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                                            viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                            stroke-width="2" stroke-linecap="round"
                                                            stroke-linejoin="round">
                                                            <path d="M12 5v14" />
                                                            <path d="m19 12-7 7-7-7" />
                                                        </svg>
                                                        Sort descending
                                                    </button>
                                                    <button type="button"
                                                        class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-lg text-[13px] font-normal text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none dark:text-neutral-300 focus:outline-none focus:bg-stone-100 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800">
                                                        <svg class="shrink-0 size-3.5"
                                                            xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                                            viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                            stroke-width="2" stroke-linecap="round"
                                                            stroke-linejoin="round">
                                                            <path d="m12 19-7-7 7-7" />
                                                            <path d="M19 12H5" />
                                                        </svg>
                                                        Move left
                                                    </button>
                                                    <button type="button"
                                                        class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-lg text-[13px] font-normal text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none dark:text-neutral-300 focus:outline-none focus:bg-stone-100 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800">
                                                        <svg class="shrink-0 size-3.5"
                                                            xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                                            viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                            stroke-width="2" stroke-linecap="round"
                                                            stroke-linejoin="round">
                                                            <path d="M5 12h14" />
                                                            <path d="m12 5 7 7-7 7" />
                                                        </svg>
                                                        Move right
                                                    </button>

                                                    <div class="my-1 border-t border-stone-200 dark:border-neutral-800">
                                                    </div>

                                                    <button type="button"
                                                        class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-lg text-[13px] font-normal text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none dark:text-neutral-300 focus:outline-none focus:bg-stone-100 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800">
                                                        <svg class="shrink-0 size-3.5"
                                                            xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                                            viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                            stroke-width="2" stroke-linecap="round"
                                                            stroke-linejoin="round">
                                                            <path d="M9.88 9.88a3 3 0 1 0 4.24 4.24" />
                                                            <path
                                                                d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68" />
                                                            <path
                                                                d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61" />
                                                            <line x1="2" x2="22" y1="2" y2="22" />
                                                        </svg>
                                                        Hide in view
                                                    </button>
                                                </div>
                                            </div>
                                            <!-- End Dropdown -->
                                        </div>
                                        <!-- End Sort Dropdown -->
                                    </th>

                                    <th scope="col" class="min-w-[230px] ">
                                        <!-- Sort Dropdown -->
                                        <div class="hs-dropdown relative inline-flex w-full cursor-pointer">
                                            <button id="hs-pro-eptprs" type="button"
                                                class="px-5 py-2.5 text-start w-full flex items-center gap-x-1 text-sm font-normal text-stone-500 focus:outline-none focus:bg-stone-100 dark:text-neutral-500 dark:focus:bg-neutral-700"
                                                aria-haspopup="menu" aria-expanded="false" aria-label="Dropdown">
                                                Name
                                                <svg class="shrink-0 size-3.5" xmlns="http://www.w3.org/2000/svg"
                                                    width="24" height="24" viewBox="0 0 24 24" fill="none"
                                                    stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                                    stroke-linejoin="round">
                                                    <path d="m7 15 5 5 5-5" />
                                                    <path d="m7 9 5-5 5 5" />
                                                </svg>
                                            </button>

                                            <!-- Dropdown -->
                                            <div class="hs-dropdown-menu hs-dropdown-open:opacity-100 w-40 transition-[opacity,margin] duration opacity-0 hidden z-10 bg-white rounded-xl shadow-[0_10px_40px_10px_rgba(0,0,0,0.08)] dark:shadow-[0_10px_40px_10px_rgba(0,0,0,0.2)] dark:bg-neutral-900"
                                                role="menu" aria-orientation="vertical" aria-labelledby="hs-pro-eptprs">
                                                <div class="p-1">
                                                    <button type="button"
                                                        class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-lg text-[13px] font-normal text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none dark:text-neutral-300 focus:outline-none focus:bg-stone-100 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800">
                                                        <svg class="shrink-0 size-3.5"
                                                            xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                                            viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                            stroke-width="2" stroke-linecap="round"
                                                            stroke-linejoin="round">
                                                            <path d="m5 12 7-7 7 7" />
                                                            <path d="M12 19V5" />
                                                        </svg>
                                                        Sort ascending
                                                    </button>
                                                    <button type="button"
                                                        class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-lg text-[13px] font-normal text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none dark:text-neutral-300 focus:outline-none focus:bg-stone-100 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800">
                                                        <svg class="shrink-0 size-3.5"
                                                            xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                                            viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                            stroke-width="2" stroke-linecap="round"
                                                            stroke-linejoin="round">
                                                            <path d="M12 5v14" />
                                                            <path d="m19 12-7 7-7-7" />
                                                        </svg>
                                                        Sort descending
                                                    </button>
                                                    <button type="button"
                                                        class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-lg text-[13px] font-normal text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none dark:text-neutral-300 focus:outline-none focus:bg-stone-100 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800">
                                                        <svg class="shrink-0 size-3.5"
                                                            xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                                            viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                            stroke-width="2" stroke-linecap="round"
                                                            stroke-linejoin="round">
                                                            <path d="m12 19-7-7 7-7" />
                                                            <path d="M19 12H5" />
                                                        </svg>
                                                        Move left
                                                    </button>
                                                    <button type="button"
                                                        class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-lg text-[13px] font-normal text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none dark:text-neutral-300 focus:outline-none focus:bg-stone-100 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800">
                                                        <svg class="shrink-0 size-3.5"
                                                            xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                                            viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                            stroke-width="2" stroke-linecap="round"
                                                            stroke-linejoin="round">
                                                            <path d="M5 12h14" />
                                                            <path d="m12 5 7 7-7 7" />
                                                        </svg>
                                                        Move right
                                                    </button>

                                                    <div class="my-1 border-t border-stone-200 dark:border-neutral-800">
                                                    </div>

                                                    <button type="button"
                                                        class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-lg text-[13px] font-normal text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none dark:text-neutral-300 focus:outline-none focus:bg-stone-100 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800">
                                                        <svg class="shrink-0 size-3.5"
                                                            xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                                            viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                            stroke-width="2" stroke-linecap="round"
                                                            stroke-linejoin="round">
                                                            <path d="M9.88 9.88a3 3 0 1 0 4.24 4.24" />
                                                            <path
                                                                d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68" />
                                                            <path
                                                                d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61" />
                                                            <line x1="2" x2="22" y1="2" y2="22" />
                                                        </svg>
                                                        Hide in view
                                                    </button>
                                                </div>
                                            </div>
                                            <!-- End Dropdown -->
                                        </div>
                                        <!-- End Sort Dropdown -->
                                    </th>

                                    <th scope="col" class="min-w-36">
                                        <!-- Sort Dropdown -->
                                        <div class="hs-dropdown relative inline-flex w-full cursor-pointer">
                                            <button id="hs-pro-eptsts" type="button"
                                                class="px-5 py-2.5 text-start w-full flex items-center gap-x-1 text-sm font-normal text-stone-500 focus:outline-none focus:bg-stone-100 dark:text-neutral-500 dark:focus:bg-neutral-700"
                                                aria-haspopup="menu" aria-expanded="false" aria-label="Dropdown">
                                                Status
                                                <svg class="shrink-0 size-3.5" xmlns="http://www.w3.org/2000/svg"
                                                    width="24" height="24" viewBox="0 0 24 24" fill="none"
                                                    stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                                    stroke-linejoin="round">
                                                    <path d="m7 15 5 5 5-5" />
                                                    <path d="m7 9 5-5 5 5" />
                                                </svg>
                                            </button>

                                            <!-- Dropdown -->
                                            <div class="hs-dropdown-menu hs-dropdown-open:opacity-100 w-40 transition-[opacity,margin] duration opacity-0 hidden z-10 bg-white rounded-xl shadow-[0_10px_40px_10px_rgba(0,0,0,0.08)] dark:shadow-[0_10px_40px_10px_rgba(0,0,0,0.2)] dark:bg-neutral-900"
                                                role="menu" aria-orientation="vertical" aria-labelledby="hs-pro-eptsts">
                                                <div class="p-1">
                                                    <button type="button"
                                                        class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-lg text-[13px] font-normal text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none dark:text-neutral-300 focus:outline-none focus:bg-stone-100 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800">
                                                        <svg class="shrink-0 size-3.5"
                                                            xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                                            viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                            stroke-width="2" stroke-linecap="round"
                                                            stroke-linejoin="round">
                                                            <path d="m5 12 7-7 7 7" />
                                                            <path d="M12 19V5" />
                                                        </svg>
                                                        Sort ascending
                                                    </button>
                                                    <button type="button"
                                                        class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-lg text-[13px] font-normal text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none dark:text-neutral-300 focus:outline-none focus:bg-stone-100 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800">
                                                        <svg class="shrink-0 size-3.5"
                                                            xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                                            viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                            stroke-width="2" stroke-linecap="round"
                                                            stroke-linejoin="round">
                                                            <path d="M12 5v14" />
                                                            <path d="m19 12-7 7-7-7" />
                                                        </svg>
                                                        Sort descending
                                                    </button>
                                                    <button type="button"
                                                        class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-lg text-[13px] font-normal text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none dark:text-neutral-300 focus:outline-none focus:bg-stone-100 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800">
                                                        <svg class="shrink-0 size-3.5"
                                                            xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                                            viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                            stroke-width="2" stroke-linecap="round"
                                                            stroke-linejoin="round">
                                                            <path d="m12 19-7-7 7-7" />
                                                            <path d="M19 12H5" />
                                                        </svg>
                                                        Move left
                                                    </button>
                                                    <button type="button"
                                                        class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-lg text-[13px] font-normal text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none dark:text-neutral-300 focus:outline-none focus:bg-stone-100 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800">
                                                        <svg class="shrink-0 size-3.5"
                                                            xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                                            viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                            stroke-width="2" stroke-linecap="round"
                                                            stroke-linejoin="round">
                                                            <path d="M5 12h14" />
                                                            <path d="m12 5 7 7-7 7" />
                                                        </svg>
                                                        Move right
                                                    </button>

                                                    <div class="my-1 border-t border-stone-200 dark:border-neutral-800">
                                                    </div>

                                                    <button type="button"
                                                        class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-lg text-[13px] font-normal text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none dark:text-neutral-300 focus:outline-none focus:bg-stone-100 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800">
                                                        <svg class="shrink-0 size-3.5"
                                                            xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                                            viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                            stroke-width="2" stroke-linecap="round"
                                                            stroke-linejoin="round">
                                                            <path d="M9.88 9.88a3 3 0 1 0 4.24 4.24" />
                                                            <path
                                                                d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68" />
                                                            <path
                                                                d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61" />
                                                            <line x1="2" x2="22" y1="2" y2="22" />
                                                        </svg>
                                                        Hide in view
                                                    </button>
                                                </div>
                                            </div>
                                            <!-- End Dropdown -->
                                        </div>
                                        <!-- End Sort Dropdown -->
                                    </th>

                                    <th scope="col" class="min-w-36">
                                        <!-- Sort Dropdown -->
                                        <div class="hs-dropdown relative inline-flex w-full cursor-pointer">
                                            <button id="hs-pro-eptcts" type="button"
                                                class="px-5 py-2.5 text-start w-full flex items-center gap-x-1 text-sm font-normal text-stone-500 focus:outline-none focus:bg-stone-100 dark:text-neutral-500 dark:focus:bg-neutral-700"
                                                aria-haspopup="menu" aria-expanded="false" aria-label="Dropdown">
                                                Price
                                                <svg class="shrink-0 size-3.5" xmlns="http://www.w3.org/2000/svg"
                                                    width="24" height="24" viewBox="0 0 24 24" fill="none"
                                                    stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                                    stroke-linejoin="round">
                                                    <path d="m7 15 5 5 5-5" />
                                                    <path d="m7 9 5-5 5 5" />
                                                </svg>
                                            </button>

                                            <!-- Dropdown -->
                                            <div class="hs-dropdown-menu hs-dropdown-open:opacity-100 w-40 transition-[opacity,margin] duration opacity-0 hidden z-10 bg-white rounded-xl shadow-[0_10px_40px_10px_rgba(0,0,0,0.08)] dark:shadow-[0_10px_40px_10px_rgba(0,0,0,0.2)] dark:bg-neutral-900"
                                                role="menu" aria-orientation="vertical" aria-labelledby="hs-pro-eptcts">
                                                <div class="p-1">
                                                    <button type="button"
                                                        class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-lg text-[13px] font-normal text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none dark:text-neutral-300 focus:outline-none focus:bg-stone-100 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800">
                                                        <svg class="shrink-0 size-3.5"
                                                            xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                                            viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                            stroke-width="2" stroke-linecap="round"
                                                            stroke-linejoin="round">
                                                            <path d="m5 12 7-7 7 7" />
                                                            <path d="M12 19V5" />
                                                        </svg>
                                                        Sort ascending
                                                    </button>
                                                    <button type="button"
                                                        class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-lg text-[13px] font-normal text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none dark:text-neutral-300 focus:outline-none focus:bg-stone-100 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800">
                                                        <svg class="shrink-0 size-3.5"
                                                            xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                                            viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                            stroke-width="2" stroke-linecap="round"
                                                            stroke-linejoin="round">
                                                            <path d="M12 5v14" />
                                                            <path d="m19 12-7 7-7-7" />
                                                        </svg>
                                                        Sort descending
                                                    </button>
                                                    <button type="button"
                                                        class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-lg text-[13px] font-normal text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none dark:text-neutral-300 focus:outline-none focus:bg-stone-100 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800">
                                                        <svg class="shrink-0 size-3.5"
                                                            xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                                            viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                            stroke-width="2" stroke-linecap="round"
                                                            stroke-linejoin="round">
                                                            <path d="m12 19-7-7 7-7" />
                                                            <path d="M19 12H5" />
                                                        </svg>
                                                        Move left
                                                    </button>
                                                    <button type="button"
                                                        class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-lg text-[13px] font-normal text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none dark:text-neutral-300 focus:outline-none focus:bg-stone-100 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800">
                                                        <svg class="shrink-0 size-3.5"
                                                            xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                                            viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                            stroke-width="2" stroke-linecap="round"
                                                            stroke-linejoin="round">
                                                            <path d="M5 12h14" />
                                                            <path d="m12 5 7 7-7 7" />
                                                        </svg>
                                                        Move right
                                                    </button>

                                                    <div class="my-1 border-t border-stone-200 dark:border-neutral-800">
                                                    </div>

                                                    <button type="button"
                                                        class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-lg text-[13px] font-normal text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none dark:text-neutral-300 focus:outline-none focus:bg-stone-100 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800">
                                                        <svg class="shrink-0 size-3.5"
                                                            xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                                            viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                            stroke-width="2" stroke-linecap="round"
                                                            stroke-linejoin="round">
                                                            <path d="M9.88 9.88a3 3 0 1 0 4.24 4.24" />
                                                            <path
                                                                d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68" />
                                                            <path
                                                                d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61" />
                                                            <line x1="2" x2="22" y1="2" y2="22" />
                                                        </svg>
                                                        Hide in view
                                                    </button>
                                                </div>
                                            </div>
                                            <!-- End Dropdown -->
                                        </div>
                                        <!-- End Sort Dropdown -->
                                    </th>

                                    <th scope="col" class="min-w-[165px] ">
                                        <!-- Sort Dropdown -->
                                        <div class="hs-dropdown relative inline-flex w-full cursor-pointer">
                                            <button id="hs-pro-eptpms" type="button"
                                                class="px-5 py-2.5 text-start w-full flex items-center gap-x-1 text-sm font-normal text-stone-500 focus:outline-none focus:bg-stone-100 dark:text-neutral-500 dark:focus:bg-neutral-700"
                                                aria-haspopup="menu" aria-expanded="false" aria-label="Dropdown">
                                                Category
                                                <svg class="shrink-0 size-3.5" xmlns="http://www.w3.org/2000/svg"
                                                    width="24" height="24" viewBox="0 0 24 24" fill="none"
                                                    stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                                    stroke-linejoin="round">
                                                    <path d="m7 15 5 5 5-5" />
                                                    <path d="m7 9 5-5 5 5" />
                                                </svg>
                                            </button>

                                            <!-- Dropdown -->
                                            <div class="hs-dropdown-menu hs-dropdown-open:opacity-100 w-40 transition-[opacity,margin] duration opacity-0 hidden z-10 bg-white rounded-xl shadow-[0_10px_40px_10px_rgba(0,0,0,0.08)] dark:shadow-[0_10px_40px_10px_rgba(0,0,0,0.2)] dark:bg-neutral-900"
                                                role="menu" aria-orientation="vertical" aria-labelledby="hs-pro-eptpms">
                                                <div class="p-1">
                                                    <button type="button"
                                                        class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-lg text-[13px] font-normal text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none dark:text-neutral-300 focus:outline-none focus:bg-stone-100 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800">
                                                        <svg class="shrink-0 size-3.5"
                                                            xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                                            viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                            stroke-width="2" stroke-linecap="round"
                                                            stroke-linejoin="round">
                                                            <path d="m5 12 7-7 7 7" />
                                                            <path d="M12 19V5" />
                                                        </svg>
                                                        Sort ascending
                                                    </button>
                                                    <button type="button"
                                                        class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-lg text-[13px] font-normal text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none dark:text-neutral-300 focus:outline-none focus:bg-stone-100 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800">
                                                        <svg class="shrink-0 size-3.5"
                                                            xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                                            viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                            stroke-width="2" stroke-linecap="round"
                                                            stroke-linejoin="round">
                                                            <path d="M12 5v14" />
                                                            <path d="m19 12-7 7-7-7" />
                                                        </svg>
                                                        Sort descending
                                                    </button>
                                                    <button type="button"
                                                        class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-lg text-[13px] font-normal text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none dark:text-neutral-300 focus:outline-none focus:bg-stone-100 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800">
                                                        <svg class="shrink-0 size-3.5"
                                                            xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                                            viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                            stroke-width="2" stroke-linecap="round"
                                                            stroke-linejoin="round">
                                                            <path d="m12 19-7-7 7-7" />
                                                            <path d="M19 12H5" />
                                                        </svg>
                                                        Move left
                                                    </button>
                                                    <button type="button"
                                                        class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-lg text-[13px] font-normal text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none dark:text-neutral-300 focus:outline-none focus:bg-stone-100 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800">
                                                        <svg class="shrink-0 size-3.5"
                                                            xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                                            viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                            stroke-width="2" stroke-linecap="round"
                                                            stroke-linejoin="round">
                                                            <path d="M5 12h14" />
                                                            <path d="m12 5 7 7-7 7" />
                                                        </svg>
                                                        Move right
                                                    </button>

                                                    <div class="my-1 border-t border-stone-200 dark:border-neutral-800">
                                                    </div>

                                                    <button type="button"
                                                        class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-lg text-[13px] font-normal text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none dark:text-neutral-300 focus:outline-none focus:bg-stone-100 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800">
                                                        <svg class="shrink-0 size-3.5"
                                                            xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                                            viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                            stroke-width="2" stroke-linecap="round"
                                                            stroke-linejoin="round">
                                                            <path d="M9.88 9.88a3 3 0 1 0 4.24 4.24" />
                                                            <path
                                                                d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68" />
                                                            <path
                                                                d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61" />
                                                            <line x1="2" x2="22" y1="2" y2="22" />
                                                        </svg>
                                                        Hide in view
                                                    </button>
                                                </div>
                                            </div>
                                            <!-- End Dropdown -->
                                        </div>
                                        <!-- End Sort Dropdown -->
                                    </th>

                                    <th scope="col" class="min-w-[155px] ">
                                        <!-- Sort Dropdown -->
                                        <div class="hs-dropdown relative inline-flex w-full cursor-pointer">
                                            <button id="hs-pro-eptpss" type="button"
                                                class="px-5 py-2.5 text-start w-full flex items-center gap-x-1 text-sm font-normal text-stone-500 focus:outline-none focus:bg-stone-100 dark:text-neutral-500 dark:focus:bg-neutral-700"
                                                aria-haspopup="menu" aria-expanded="false" aria-label="Dropdown">
                                                Stock
                                                <svg class="shrink-0 size-3.5" xmlns="http://www.w3.org/2000/svg"
                                                    width="24" height="24" viewBox="0 0 24 24" fill="none"
                                                    stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                                    stroke-linejoin="round">
                                                    <path d="m7 15 5 5 5-5" />
                                                    <path d="m7 9 5-5 5 5" />
                                                </svg>
                                            </button>

                                            <!-- Dropdown -->
                                            <div class="hs-dropdown-menu hs-dropdown-open:opacity-100 w-40 transition-[opacity,margin] duration opacity-0 hidden z-10 bg-white rounded-xl shadow-[0_10px_40px_10px_rgba(0,0,0,0.08)] dark:shadow-[0_10px_40px_10px_rgba(0,0,0,0.2)] dark:bg-neutral-900"
                                                role="menu" aria-orientation="vertical" aria-labelledby="hs-pro-eptpss">
                                                <div class="p-1">
                                                    <button type="button"
                                                        class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-lg text-[13px] font-normal text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none dark:text-neutral-300 focus:outline-none focus:bg-stone-100 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800">
                                                        <svg class="shrink-0 size-3.5"
                                                            xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                                            viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                            stroke-width="2" stroke-linecap="round"
                                                            stroke-linejoin="round">
                                                            <path d="m5 12 7-7 7 7" />
                                                            <path d="M12 19V5" />
                                                        </svg>
                                                        Sort ascending
                                                    </button>
                                                    <button type="button"
                                                        class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-lg text-[13px] font-normal text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none dark:text-neutral-300 focus:outline-none focus:bg-stone-100 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800">
                                                        <svg class="shrink-0 size-3.5"
                                                            xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                                            viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                            stroke-width="2" stroke-linecap="round"
                                                            stroke-linejoin="round">
                                                            <path d="M12 5v14" />
                                                            <path d="m19 12-7 7-7-7" />
                                                        </svg>
                                                        Sort descending
                                                    </button>
                                                    <button type="button"
                                                        class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-lg text-[13px] font-normal text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none dark:text-neutral-300 focus:outline-none focus:bg-stone-100 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800">
                                                        <svg class="shrink-0 size-3.5"
                                                            xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                                            viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                            stroke-width="2" stroke-linecap="round"
                                                            stroke-linejoin="round">
                                                            <path d="m12 19-7-7 7-7" />
                                                            <path d="M19 12H5" />
                                                        </svg>
                                                        Move left
                                                    </button>
                                                    <button type="button"
                                                        class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-lg text-[13px] font-normal text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none dark:text-neutral-300 focus:outline-none focus:bg-stone-100 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800">
                                                        <svg class="shrink-0 size-3.5"
                                                            xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                                            viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                            stroke-width="2" stroke-linecap="round"
                                                            stroke-linejoin="round">
                                                            <path d="M5 12h14" />
                                                            <path d="m12 5 7 7-7 7" />
                                                        </svg>
                                                        Move right
                                                    </button>

                                                    <div class="my-1 border-t border-stone-200 dark:border-neutral-800">
                                                    </div>

                                                    <button type="button"
                                                        class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-lg text-[13px] font-normal text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none dark:text-neutral-300 focus:outline-none focus:bg-stone-100 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800">
                                                        <svg class="shrink-0 size-3.5"
                                                            xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                                            viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                            stroke-width="2" stroke-linecap="round"
                                                            stroke-linejoin="round">
                                                            <path d="M9.88 9.88a3 3 0 1 0 4.24 4.24" />
                                                            <path
                                                                d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68" />
                                                            <path
                                                                d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61" />
                                                            <line x1="2" x2="22" y1="2" y2="22" />
                                                        </svg>
                                                        Hide in view
                                                    </button>
                                                </div>
                                            </div>
                                            <!-- End Dropdown -->
                                        </div>
                                        <!-- End Sort Dropdown -->
                                    </th>

                                    <th scope="col">
                                        <!-- Sort Dropdown -->
                                        <div class="hs-dropdown relative inline-flex w-full cursor-pointer">
                                            <button id="hs-pro-eptits" type="button"
                                                class="px-5 py-2.5 text-start w-full flex items-center gap-x-1 text-sm font-normal text-stone-500 focus:outline-none focus:bg-stone-100 dark:text-neutral-500 dark:focus:bg-neutral-700"
                                                aria-haspopup="menu" aria-expanded="false" aria-label="Dropdown">
                                                minimum_stock
                                                <svg class="shrink-0 size-3.5" xmlns="http://www.w3.org/2000/svg"
                                                    width="24" height="24" viewBox="0 0 24 24" fill="none"
                                                    stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                                    stroke-linejoin="round">
                                                    <path d="m7 15 5 5 5-5" />
                                                    <path d="m7 9 5-5 5 5" />
                                                </svg>
                                            </button>

                                            <!-- Dropdown -->
                                            <div class="hs-dropdown-menu hs-dropdown-open:opacity-100 w-40 transition-[opacity,margin] duration opacity-0 hidden z-10 bg-white rounded-xl shadow-[0_10px_40px_10px_rgba(0,0,0,0.08)] dark:shadow-[0_10px_40px_10px_rgba(0,0,0,0.2)] dark:bg-neutral-900"
                                                role="menu" aria-orientation="vertical" aria-labelledby="hs-pro-eptits">
                                                <div class="p-1">
                                                    <button type="button"
                                                        class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-lg text-[13px] font-normal text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none dark:text-neutral-300 focus:outline-none focus:bg-stone-100 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800">
                                                        <svg class="shrink-0 size-3.5"
                                                            xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                                            viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                            stroke-width="2" stroke-linecap="round"
                                                            stroke-linejoin="round">
                                                            <path d="m5 12 7-7 7 7" />
                                                            <path d="M12 19V5" />
                                                        </svg>
                                                        Sort ascending
                                                    </button>
                                                    <button type="button"
                                                        class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-lg text-[13px] font-normal text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none dark:text-neutral-300 focus:outline-none focus:bg-stone-100 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800">
                                                        <svg class="shrink-0 size-3.5"
                                                            xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                                            viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                            stroke-width="2" stroke-linecap="round"
                                                            stroke-linejoin="round">
                                                            <path d="M12 5v14" />
                                                            <path d="m19 12-7 7-7-7" />
                                                        </svg>
                                                        Sort descending
                                                    </button>
                                                    <button type="button"
                                                        class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-lg text-[13px] font-normal text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none dark:text-neutral-300 focus:outline-none focus:bg-stone-100 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800">
                                                        <svg class="shrink-0 size-3.5"
                                                            xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                                            viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                            stroke-width="2" stroke-linecap="round"
                                                            stroke-linejoin="round">
                                                            <path d="m12 19-7-7 7-7" />
                                                            <path d="M19 12H5" />
                                                        </svg>
                                                        Move left
                                                    </button>
                                                    <button type="button"
                                                        class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-lg text-[13px] font-normal text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none dark:text-neutral-300 focus:outline-none focus:bg-stone-100 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800">
                                                        <svg class="shrink-0 size-3.5"
                                                            xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                                            viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                            stroke-width="2" stroke-linecap="round"
                                                            stroke-linejoin="round">
                                                            <path d="M5 12h14" />
                                                            <path d="m12 5 7 7-7 7" />
                                                        </svg>
                                                        Move right
                                                    </button>

                                                    <div class="my-1 border-t border-stone-200 dark:border-neutral-800">
                                                    </div>

                                                    <button type="button"
                                                        class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-lg text-[13px] font-normal text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none dark:text-neutral-300 focus:outline-none focus:bg-stone-100 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800">
                                                        <svg class="shrink-0 size-3.5"
                                                            xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                                            viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                            stroke-width="2" stroke-linecap="round"
                                                            stroke-linejoin="round">
                                                            <path d="M9.88 9.88a3 3 0 1 0 4.24 4.24" />
                                                            <path
                                                                d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68" />
                                                            <path
                                                                d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61" />
                                                            <line x1="2" x2="22" y1="2" y2="22" />
                                                        </svg>
                                                        Hide in view
                                                    </button>
                                                </div>
                                            </div>
                                            <!-- End Dropdown -->
                                        </div>
                                        <!-- End Sort Dropdown -->
                                    </th>

                                    <th scope="col"></th>
                                </tr>
                            </thead>

                            <tbody class="divide-y divide-stone-200 dark:divide-neutral-700">
                                <tr v-for="product in products.data" :key="product.id" :value="product.id">
                                    <td class="size-px whitespace-nowrap px-3 py-4">
                                        <input type="checkbox"
                                            class="shrink-0 border-stone-300 rounded text-green-600 focus:ring-green-600 disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-800 dark:border-neutral-600 dark:checked:bg-green-500 dark:checked:border-green-500 dark:focus:ring-offset-neutral-800">
                                    </td>
                                    <td class="size-px whitespace-nowrap px-4 py-1">
                                        <a class="text-sm text-green-600 decoration-2 hover:underline font-medium focus:outline-none focus:underline dark:text-green-400 dark:hover:text-green-500"
                                            href="../../pro/ecommerce/order-details.html">
                                            {{ product.number }}
                                        </a>
                                    </td>
                                    <td class="size-px whitespace-nowrap px-4 py-1">
                                        <div class="w-full flex items-center gap-x-3">
                                            <img class="shrink-0 size-10 rounded-md" :src="product.image"
                                                alt="Product Image">
                                            <span class="text-sm text-stone-600 dark:text-neutral-400">
                                                {{ product.name }}
                                            </span>
                                        </div>
                                    </td>
                                    <td class="size-px whitespace-nowrap px-4 py-1">
                                        <span v-if="product.minimum_stock < product.stock"
                                            class="py-1.5 px-2 inline-flex items-center gap-x-1.5 text-xs font-medium bg-green-100 text-green-800 rounded-full dark:bg-green-500/10 dark:text-green-500">
                                            <svg class="shrink-0 size-4" xmlns="http://www.w3.org/2000/svg" width="24"
                                                height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <circle cx="12" cy="12" r="10"></circle>
                                                <polyline points="12 6 12 12 16 14"></polyline>
                                            </svg>
                                            In stock
                                        </span>
                                        <span v-else
                                            class="py-1.5 px-2 inline-flex items-center gap-x-1.5 text-xs font-medium bg-red-100 text-red-800 rounded-full dark:bg-red-500/10 dark:text-red-500">
                                            <svg class="shrink-0 size-4" xmlns="http://www.w3.org/2000/svg" width="24"
                                                height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <circle cx="12" cy="12" r="10"></circle>
                                                <polyline points="12 6 12 12 16 14"></polyline>
                                            </svg>
                                            Low stock
                                        </span>
                                    </td>
                                    <td class="size-px whitespace-nowrap px-4 py-1">
                                        <span class="text-sm text-stone-600 dark:text-neutral-400">
                                            {{ product.price }} $
                                        </span>
                                    </td>
                                    <td class="size-px whitespace-nowrap px-4 py-1">
                                        <span
                                            class="inline-flex items-center gap-x-1 text-sm text-stone-600 dark:text-neutral-400">
                                            {{ product.category.name }}
                                        </span>
                                    </td>
                                    <td class="size-px whitespace-nowrap px-4 py-1">
                                        <span
                                            class="py-1.5 px-2 inline-flex items-center gap-x-1.5 text-xs font-medium bg-stone-100 text-stone-800 rounded-full dark:bg-neutral-700 dark:text-neutral-200">
                                            {{ product.stock }}
                                        </span>
                                    </td>
                                    <td class="size-px whitespace-nowrap px-4 py-1">
                                        <span
                                            class="py-1.5 px-2 inline-flex items-center gap-x-1.5 text-xs font-medium bg-stone-100 text-stone-800 rounded-full dark:bg-neutral-700 dark:text-neutral-200">
                                            {{ product.minimum_stock }}
                                        </span>
                                    </td>
                                    <td class="size-px whitespace-nowrap px-4 py-1 text-end">
                                        <div
                                            class="hs-dropdown [--auto-close:inside] [--placement:bottom-right] relative inline-flex">
                                            <button id="hs-pro-errtmd1" type="button"
                                                class="size-7 inline-flex justify-center items-center gap-x-2 rounded-lg border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-700 dark:focus:bg-neutral-700"
                                                aria-haspopup="menu" aria-expanded="false" aria-label="Dropdown">
                                                <svg class="shrink-0 size-3.5" xmlns="http://www.w3.org/2000/svg"
                                                    width="24" height="24" viewBox="0 0 24 24" fill="none"
                                                    stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                                    stroke-linejoin="round">
                                                    <circle cx="12" cy="12" r="1" />
                                                    <circle cx="12" cy="5" r="1" />
                                                    <circle cx="12" cy="19" r="1" />
                                                </svg>
                                            </button>

                                            <div class="hs-dropdown-menu hs-dropdown-open:opacity-100 w-24 transition-[opacity,margin] duration opacity-0 hidden z-10 bg-white rounded-xl shadow-[0_10px_40px_10px_rgba(0,0,0,0.08)] dark:shadow-[0_10px_40px_10px_rgba(0,0,0,0.2)] dark:bg-neutral-900"
                                                role="menu" aria-orientation="vertical"
                                                aria-labelledby="hs-pro-errtmd1">
                                                <div class="p-1">
                                                    <button type="button" :data-hs-overlay="'#hs-pro-edit' + product.id"
                                                        class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-lg text-[13px] text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800">
                                                        Edit
                                                    </button>
                                                    <div class="my-1 border-t border-stone-200 dark:border-neutral-800">
                                                    </div>

                                                    <button type="button"
                                                        class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-lg text-[13px] text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800">
                                                        Delete
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </td>

                                        <!-- Modal add product -->
                                        <Modal :title="'Edit product'" :id="'hs-pro-edit' + product.id" >
                                            <ProductForm :product="product" :categories="categories" :id="'hs-pro-edit' + product.id"/>
                                        </Modal>
                                        <!-- End Modal -->
                                </tr>
                            </tbody>
                        </table>
                        <!-- End Table -->
                    </div>
                </div>
                <!-- End Table Section -->

                <!-- Footer -->
                <div class="mt-5 flex flex-wrap justify-between items-center gap-2">
                    <p class="text-sm text-stone-800 dark:text-neutral-200">
                        <span class="font-medium"> {{ count }} </span>
                        <span class="text-stone-500 dark:text-neutral-500"> results</span>
                    </p>

                    <!-- Pagination -->
                    <nav class="flex justify-end items-center gap-x-1" aria-label="Pagination">
                        <Link :href="products.prev_page_url" v-if="products.prev_page_url">
                        <button type="button"
                            class="min-h-[38px] min-w-[38px] py-2 px-2.5 inline-flex justify-center items-center gap-x-2 text-sm rounded-lg text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 dark:text-white dark:hover:bg-white/10 dark:focus:bg-neutral-700"
                            aria-label="Previous">
                            <svg class="shrink-0 size-3.5" xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                stroke-linecap="round" stroke-linejoin="round">
                                <path d="m15 18-6-6 6-6" />
                            </svg>
                            <span class="sr-only">Previous</span>
                        </button>
                        </Link>
                        <div class="flex items-center gap-x-1">
                            <span
                                class="min-h-[38px] min-w-[38px] flex justify-center items-center bg-stone-100 text-stone-800 py-2 px-3 text-sm rounded-lg disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-700 dark:text-white"
                                aria-current="page">{{ products.from }}</span>
                            <span
                                class="min-h-[38px] flex justify-center items-center text-stone-500 py-2 px-1.5 text-sm dark:text-neutral-500">of</span>
                            <span
                                class="min-h-[38px] flex justify-center items-center text-stone-500 py-2 px-1.5 text-sm dark:text-neutral-500">{{
                                    products.to }}</span>
                        </div>

                        <Link :href="products.next_page_url" v-if="products.next_page_url">
                        <button type="button"
                            class="min-h-[38px] min-w-[38px] py-2 px-2.5 inline-flex justify-center items-center gap-x-2 text-sm rounded-lg text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 dark:text-white dark:hover:bg-white/10 dark:focus:bg-neutral-700"
                            aria-label="Next">
                            <span class="sr-only">Next</span>
                            <svg class="shrink-0 size-3.5" xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                stroke-linecap="round" stroke-linejoin="round">
                                <path d="m9 18 6-6-6-6" />
                            </svg>
                        </button>
                        </Link>
                    </nav>
                    <!-- End Pagination -->
                </div>
                <!-- End Footer -->
            </div>

            <!-- Tab Content -->
            <div id="hs-pro-tabs-dut-archived" class="hidden" role="tabpanel"
                aria-labelledby="hs-pro-tabs-dut-item-archived">
                <!-- Empty State -->
                <div class="p-5  flex flex-col justify-center items-center text-center">
                    <svg class="w-48 mx-auto mb-4" width="178" height="90" viewBox="0 0 178 90" fill="none"
                        xmlns="http://www.w3.org/2000/svg">
                        <rect x="27" y="50.5" width="124" height="39" rx="7.5" fill="currentColor"
                            class="fill-white dark:fill-neutral-800" />
                        <rect x="27" y="50.5" width="124" height="39" rx="7.5" stroke="currentColor"
                            class="stroke-stone-50 dark:stroke-neutral-700/10" />
                        <rect x="34.5" y="58" width="24" height="24" rx="4" fill="currentColor"
                            class="fill-stone-50 dark:fill-neutral-700/30" />
                        <rect x="66.5" y="61" width="60" height="6" rx="3" fill="currentColor"
                            class="fill-stone-50 dark:fill-neutral-700/30" />
                        <rect x="66.5" y="73" width="77" height="6" rx="3" fill="currentColor"
                            class="fill-stone-50 dark:fill-neutral-700/30" />
                        <rect x="19.5" y="28.5" width="139" height="39" rx="7.5" fill="currentColor"
                            class="fill-white dark:fill-neutral-800" />
                        <rect x="19.5" y="28.5" width="139" height="39" rx="7.5" stroke="currentColor"
                            class="stroke-stone-100 dark:stroke-neutral-700/30" />
                        <rect x="27" y="36" width="24" height="24" rx="4" fill="currentColor"
                            class="fill-stone-100 dark:fill-neutral-700/70" />
                        <rect x="59" y="39" width="60" height="6" rx="3" fill="currentColor"
                            class="fill-stone-100 dark:fill-neutral-700/70" />
                        <rect x="59" y="51" width="92" height="6" rx="3" fill="currentColor"
                            class="fill-stone-100 dark:fill-neutral-700/70" />
                        <g filter="url(#filter7)">
                            <rect x="12" y="6" width="154" height="40" rx="8" fill="currentColor"
                                class="fill-white dark:fill-neutral-800" shape-rendering="crispEdges" />
                            <rect x="12.5" y="6.5" width="153" height="39" rx="7.5" stroke="currentColor"
                                class="stroke-stone-100 dark:stroke-neutral-700/60" shape-rendering="crispEdges" />
                            <rect x="20" y="14" width="24" height="24" rx="4" fill="currentColor"
                                class="fill-stone-200 dark:fill-neutral-700 " />
                            <rect x="52" y="17" width="60" height="6" rx="3" fill="currentColor"
                                class="fill-stone-200 dark:fill-neutral-700" />
                            <rect x="52" y="29" width="106" height="6" rx="3" fill="currentColor"
                                class="fill-stone-200 dark:fill-neutral-700" />
                        </g>
                        <defs>
                            <filter id="filter7" x="0" y="0" width="178" height="64" filterUnits="userSpaceOnUse"
                                color-interpolation-filters="sRGB">
                                <feFlood flood-opacity="0" result="BackgroundImageFix" />
                                <feColorMatrix in="SourceAlpha" type="matrix"
                                    values="0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 127 0" result="hardAlpha" />
                                <feOffset dy="6" />
                                <feGaussianBlur stdDeviation="6" />
                                <feComposite in2="hardAlpha" operator="out" />
                                <feColorMatrix type="matrix" values="0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0.03 0" />
                                <feBlend mode="normal" in2="BackgroundImageFix"
                                    result="effect1_dropShadow_1187_14810" />
                                <feBlend mode="normal" in="SourceGraphic" in2="effect1_dropShadow_1187_14810"
                                    result="shape" />
                            </filter>
                        </defs>
                    </svg>

                    <div class="max-w-sm mx-auto">
                        <p class="mt-2 font-medium text-stone-800 dark:text-neutral-200">
                            Your data will appear here soon.
                        </p>
                        <p class="mb-5 text-sm text-stone-500 dark:text-neutral-500">
                            In the meantime, you can create new custom insights to monitor your most important metrics.
                        </p>
                    </div>

                    <a class="py-2 px-3 inline-flex items-center gap-x-2 text-sm font-medium rounded-lg border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-700 dark:focus:bg-neutral-700"
                        href="#">
                        Learn more
                    </a>
                </div>
                <!-- End Empty State -->
            </div>
            <!-- End Tab Content -->

            <!-- Tab Content -->
            <div id="hs-pro-tabs-dut-publish" class="hidden" role="tabpanel"
                aria-labelledby="hs-pro-tabs-dut-item-publish">
                <!-- Empty State -->
                <div class="p-5  flex flex-col justify-center items-center text-center">
                    <svg class="w-48 mx-auto mb-4" width="178" height="90" viewBox="0 0 178 90" fill="none"
                        xmlns="http://www.w3.org/2000/svg">
                        <rect x="27" y="50.5" width="124" height="39" rx="7.5" fill="currentColor"
                            class="fill-white dark:fill-neutral-800" />
                        <rect x="27" y="50.5" width="124" height="39" rx="7.5" stroke="currentColor"
                            class="stroke-stone-50 dark:stroke-neutral-700/10" />
                        <rect x="34.5" y="58" width="24" height="24" rx="4" fill="currentColor"
                            class="fill-stone-50 dark:fill-neutral-700/30" />
                        <rect x="66.5" y="61" width="60" height="6" rx="3" fill="currentColor"
                            class="fill-stone-50 dark:fill-neutral-700/30" />
                        <rect x="66.5" y="73" width="77" height="6" rx="3" fill="currentColor"
                            class="fill-stone-50 dark:fill-neutral-700/30" />
                        <rect x="19.5" y="28.5" width="139" height="39" rx="7.5" fill="currentColor"
                            class="fill-white dark:fill-neutral-800" />
                        <rect x="19.5" y="28.5" width="139" height="39" rx="7.5" stroke="currentColor"
                            class="stroke-stone-100 dark:stroke-neutral-700/30" />
                        <rect x="27" y="36" width="24" height="24" rx="4" fill="currentColor"
                            class="fill-stone-100 dark:fill-neutral-700/70" />
                        <rect x="59" y="39" width="60" height="6" rx="3" fill="currentColor"
                            class="fill-stone-100 dark:fill-neutral-700/70" />
                        <rect x="59" y="51" width="92" height="6" rx="3" fill="currentColor"
                            class="fill-stone-100 dark:fill-neutral-700/70" />
                        <g filter="url(#filter8)">
                            <rect x="12" y="6" width="154" height="40" rx="8" fill="currentColor"
                                class="fill-white dark:fill-neutral-800" shape-rendering="crispEdges" />
                            <rect x="12.5" y="6.5" width="153" height="39" rx="7.5" stroke="currentColor"
                                class="stroke-stone-100 dark:stroke-neutral-700/60" shape-rendering="crispEdges" />
                            <rect x="20" y="14" width="24" height="24" rx="4" fill="currentColor"
                                class="fill-stone-200 dark:fill-neutral-700 " />
                            <rect x="52" y="17" width="60" height="6" rx="3" fill="currentColor"
                                class="fill-stone-200 dark:fill-neutral-700" />
                            <rect x="52" y="29" width="106" height="6" rx="3" fill="currentColor"
                                class="fill-stone-200 dark:fill-neutral-700" />
                        </g>
                        <defs>
                            <filter id="filter8" x="0" y="0" width="178" height="64" filterUnits="userSpaceOnUse"
                                color-interpolation-filters="sRGB">
                                <feFlood flood-opacity="0" result="BackgroundImageFix" />
                                <feColorMatrix in="SourceAlpha" type="matrix"
                                    values="0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 127 0" result="hardAlpha" />
                                <feOffset dy="6" />
                                <feGaussianBlur stdDeviation="6" />
                                <feComposite in2="hardAlpha" operator="out" />
                                <feColorMatrix type="matrix" values="0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0.03 0" />
                                <feBlend mode="normal" in2="BackgroundImageFix"
                                    result="effect1_dropShadow_1187_14810" />
                                <feBlend mode="normal" in="SourceGraphic" in2="effect1_dropShadow_1187_14810"
                                    result="shape" />
                            </filter>
                        </defs>
                    </svg>

                    <div class="max-w-sm mx-auto">
                        <p class="mt-2 font-medium text-stone-800 dark:text-neutral-200">
                            Your data will appear here soon.
                        </p>
                        <p class="mb-5 text-sm text-stone-500 dark:text-neutral-500">
                            In the meantime, you can create new custom insights to monitor your most important metrics.
                        </p>
                    </div>

                    <a class="py-2 px-3 inline-flex items-center gap-x-2 text-sm font-medium rounded-lg border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-700 dark:focus:bg-neutral-700"
                        href="#">
                        Learn more
                    </a>
                </div>
                <!-- End Empty State -->
            </div>
            <!-- End Tab Content -->

            <!-- Tab Content -->
            <div id="hs-pro-tabs-dut-unpublish" class="hidden" role="tabpanel"
                aria-labelledby="hs-pro-tabs-dut-item-unpublish">
                <!-- Empty State -->
                <div class="p-5  flex flex-col justify-center items-center text-center">
                    <svg class="w-48 mx-auto mb-4" width="178" height="90" viewBox="0 0 178 90" fill="none"
                        xmlns="http://www.w3.org/2000/svg">
                        <rect x="27" y="50.5" width="124" height="39" rx="7.5" fill="currentColor"
                            class="fill-white dark:fill-neutral-800" />
                        <rect x="27" y="50.5" width="124" height="39" rx="7.5" stroke="currentColor"
                            class="stroke-stone-50 dark:stroke-neutral-700/10" />
                        <rect x="34.5" y="58" width="24" height="24" rx="4" fill="currentColor"
                            class="fill-stone-50 dark:fill-neutral-700/30" />
                        <rect x="66.5" y="61" width="60" height="6" rx="3" fill="currentColor"
                            class="fill-stone-50 dark:fill-neutral-700/30" />
                        <rect x="66.5" y="73" width="77" height="6" rx="3" fill="currentColor"
                            class="fill-stone-50 dark:fill-neutral-700/30" />
                        <rect x="19.5" y="28.5" width="139" height="39" rx="7.5" fill="currentColor"
                            class="fill-white dark:fill-neutral-800" />
                        <rect x="19.5" y="28.5" width="139" height="39" rx="7.5" stroke="currentColor"
                            class="stroke-stone-100 dark:stroke-neutral-700/30" />
                        <rect x="27" y="36" width="24" height="24" rx="4" fill="currentColor"
                            class="fill-stone-100 dark:fill-neutral-700/70" />
                        <rect x="59" y="39" width="60" height="6" rx="3" fill="currentColor"
                            class="fill-stone-100 dark:fill-neutral-700/70" />
                        <rect x="59" y="51" width="92" height="6" rx="3" fill="currentColor"
                            class="fill-stone-100 dark:fill-neutral-700/70" />
                        <g filter="url(#filter9)">
                            <rect x="12" y="6" width="154" height="40" rx="8" fill="currentColor"
                                class="fill-white dark:fill-neutral-800" shape-rendering="crispEdges" />
                            <rect x="12.5" y="6.5" width="153" height="39" rx="7.5" stroke="currentColor"
                                class="stroke-stone-100 dark:stroke-neutral-700/60" shape-rendering="crispEdges" />
                            <rect x="20" y="14" width="24" height="24" rx="4" fill="currentColor"
                                class="fill-stone-200 dark:fill-neutral-700 " />
                            <rect x="52" y="17" width="60" height="6" rx="3" fill="currentColor"
                                class="fill-stone-200 dark:fill-neutral-700" />
                            <rect x="52" y="29" width="106" height="6" rx="3" fill="currentColor"
                                class="fill-stone-200 dark:fill-neutral-700" />
                        </g>
                        <defs>
                            <filter id="filter9" x="0" y="0" width="178" height="64" filterUnits="userSpaceOnUse"
                                color-interpolation-filters="sRGB">
                                <feFlood flood-opacity="0" result="BackgroundImageFix" />
                                <feColorMatrix in="SourceAlpha" type="matrix"
                                    values="0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 127 0" result="hardAlpha" />
                                <feOffset dy="6" />
                                <feGaussianBlur stdDeviation="6" />
                                <feComposite in2="hardAlpha" operator="out" />
                                <feColorMatrix type="matrix" values="0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0.03 0" />
                                <feBlend mode="normal" in2="BackgroundImageFix"
                                    result="effect1_dropShadow_1187_14810" />
                                <feBlend mode="normal" in="SourceGraphic" in2="effect1_dropShadow_1187_14810"
                                    result="shape" />
                            </filter>
                        </defs>
                    </svg>

                    <div class="max-w-sm mx-auto">
                        <p class="mt-2 font-medium text-stone-800 dark:text-neutral-200">
                            Your data will appear here soon.
                        </p>
                        <p class="mb-5 text-sm text-stone-500 dark:text-neutral-500">
                            In the meantime, you can create new custom insights to monitor your most important metrics.
                        </p>
                    </div>

                    <a class="py-2 px-3 inline-flex items-center gap-x-2 text-sm font-medium rounded-lg border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-700 dark:focus:bg-neutral-700"
                        href="#">
                        Learn more
                    </a>
                </div>
                <!-- End Empty State -->
            </div>
            <!-- End Tab Content -->
        </div>
    </div>
    <!-- End Orders Table Card -->

    <!-- Modal add product -->
    <Modal :title="'Add product'" :id="'hs-pro-dasadpm'">
        <ProductForm :product="product" :categories="categories" :id="'hs-pro-dasadpm'"/>
    </Modal>

</template>
