<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import Stat from '@/Components/UI/Stat.vue';
import TrafficStat from '@/Components/UI/TrafficStat.vue';

const props = defineProps({
    customers: Object,
});
</script>
<template>

    <Head title="Customers" />
    <AuthenticatedLayout>
        <Stat />
        <div class="grid grid-cols-1 lg:grid-cols-4 gap-2 md:gap-3 lg:gap-5 ">
            <div class="col-span-1 lg:col-span-3">
                        <!-- Referral Users Table Card -->
        <div
            class="p-5 space-y-4 flex flex-col bg-white border border-stone-200 shadow-sm rounded-xl dark:bg-neutral-800 dark:border-neutral-700">
            <!-- Filter Group -->
            <div class="grid md:grid-cols-2 gap-y-2 md:gap-y-0 md:gap-x-5">
                <div>
                    <!-- Search Input -->
                    <div class="relative">
                        <div class="absolute inset-y-0 start-0 flex items-center pointer-events-none z-20 ps-3.5">
                            <svg class="shrink-0 size-4 text-stone-500 dark:text-neutral-400"
                                xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                stroke-linejoin="round">
                                <circle cx="11" cy="11" r="8" />
                                <path d="m21 21-4.3-4.3" />
                            </svg>
                        </div>
                        <input type="text"
                            class="py-[7px] ps-10 pe-8 block w-full bg-stone-100 border-transparent rounded-lg text-sm placeholder:text-stone-500 focus:border-green-500 focus:ring-green-600 disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-700 dark:border-transparent dark:text-neutral-400 dark:placeholder:text-neutral-400 dark:focus:ring-neutral-600"
                            placeholder="Search referral users">
                        <div class="hidden absolute inset-y-0 end-0 flex items-center pointer-events-none z-20 pe-1">
                            <button type="button"
                                class="inline-flex shrink-0 justify-center items-center size-6 rounded-full text-gray-500 hover:text-blue-600 focus:outline-none focus:text-blue-600 dark:text-neutral-500 dark:hover:text-blue-500 dark:focus:text-blue-500"
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

                <div class="flex flex-wrap md:justify-end items-center gap-2">
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
                                5
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
                                        Name
                                    </label>
                                </div>

                                <div
                                    class="flex items-center gap-x-3 py-1.5 px-2 cursor-pointer rounded-lg hover:bg-stone-100 dark:hover:bg-neutral-800 dark:focus:outline-none dark:focus:ring-1 dark:focus:ring-neutral-600">
                                    <input type="checkbox"
                                        class="shrink-0 border-stone-300 rounded text-green-600 focus:ring-green-600 disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-800 dark:border-neutral-600 dark:checked:bg-green-500 dark:checked:border-green-500 dark:focus:ring-offset-neutral-800"
                                        id="hs-pro-dupfindch3" checked>
                                    <label for="hs-pro-dupfindch3"
                                        class="flex flex-1 items-center gap-x-3 cursor-pointer text-[13px] text-stone-800 dark:text-neutral-300">
                                        Date
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
                                        Product
                                    </label>
                                </div>

                                <div
                                    class="flex items-center gap-x-3 py-1.5 px-2 cursor-pointer rounded-lg hover:bg-stone-100 dark:hover:bg-neutral-800 dark:focus:outline-none dark:focus:ring-1 dark:focus:ring-neutral-600">
                                    <input type="checkbox"
                                        class="shrink-0 border-stone-300 rounded text-green-600 focus:ring-green-600 disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-800 dark:border-neutral-600 dark:checked:bg-green-500 dark:checked:border-green-500 dark:focus:ring-offset-neutral-800"
                                        id="hs-pro-dupfindch6" checked>
                                    <label for="hs-pro-dupfindch6"
                                        class="flex flex-1 items-center gap-x-3 cursor-pointer text-[13px] text-stone-800 dark:text-neutral-300">
                                        Amount
                                    </label>
                                </div>
                            </div>
                        </div>
                        <!-- End Dropdown -->
                    </div>
                    <!-- End Filter Dropdown -->
                </div>
                <!-- End Col -->
            </div>
            <!-- End Filter Group -->

            <!-- Table Section -->
            <div
                class="overflow-x-auto [&::-webkit-scrollbar]:h-2 [&::-webkit-scrollbar-thumb]:rounded-full [&::-webkit-scrollbar-track]:bg-stone-100 [&::-webkit-scrollbar-thumb]:bg-stone-300 dark:[&::-webkit-scrollbar-track]:bg-neutral-700 dark:[&::-webkit-scrollbar-thumb]:bg-neutral-500">
                <div class="min-w-full inline-block align-middle">
                    <!-- Table -->
                    <table class="min-w-full divide-y divide-stone-200 dark:divide-neutral-700">
                        <thead>
                            <tr class="border-t border-stone-200 dark:border-neutral-700">
                                <th scope="col" class="min-w-[270px] ">
                                    <!-- Sort Dropdown -->
                                    <div class="hs-dropdown relative inline-flex w-full cursor-pointer">
                                        <button id="hs-pro-eptnms" type="button"
                                            class="px-5 py-2.5 text-start w-full flex items-center gap-x-1 text-sm font-normal text-stone-500 focus:outline-none focus:bg-stone-100 dark:text-neutral-500 dark:focus:bg-neutral-700"
                                            aria-haspopup="menu" aria-expanded="false" aria-label="Dropdown">
                                            Name
                                        </button>
                                    </div>
                                    <!-- End Sort Dropdown -->
                                </th>

                                <th scope="col" class="min-w-36">
                                    <!-- Sort Dropdown -->
                                    <div class="hs-dropdown relative inline-flex w-full cursor-pointer">
                                        <button id="hs-pro-eptdts" type="button"
                                            class="px-5 py-2.5 text-start w-full flex items-center gap-x-1 text-sm font-normal text-stone-500 focus:outline-none focus:bg-stone-100 dark:text-neutral-500 dark:focus:bg-neutral-700"
                                            aria-haspopup="menu" aria-expanded="false" aria-label="Dropdown">
                                            Date
                                        </button>
                                    </div>
                                    <!-- End Sort Dropdown -->
                                </th>

                                <th scope="col" class="min-w-[170px] ">
                                    <!-- Sort Dropdown -->
                                    <div class="hs-dropdown relative inline-flex w-full cursor-pointer">
                                        <button id="hs-pro-eptsts" type="button"
                                            class="px-5 py-2.5 text-start w-full flex items-center gap-x-1 text-sm font-normal text-stone-500 focus:outline-none focus:bg-stone-100 dark:text-neutral-500 dark:focus:bg-neutral-700"
                                            aria-haspopup="menu" aria-expanded="false" aria-label="Dropdown">
                                            Status
                                        </button>
                                    </div>
                                    <!-- End Sort Dropdown -->
                                </th>

                                <th scope="col" class="min-w-[300px] ">
                                    <!-- Sort Dropdown -->
                                    <div class="hs-dropdown relative inline-flex w-full cursor-pointer">
                                        <button id="hs-pro-eptprs" type="button"
                                            class="px-5 py-2.5 text-start w-full flex items-center gap-x-1 text-sm font-normal text-stone-500 focus:outline-none focus:bg-stone-100 dark:text-neutral-500 dark:focus:bg-neutral-700"
                                            aria-haspopup="menu" aria-expanded="false" aria-label="Dropdown">
                                            Product
                                        </button>
                                    </div>
                                    <!-- End Sort Dropdown -->
                                </th>

                                <th scope="col">
                                    <!-- Sort Dropdown -->
                                    <div class="hs-dropdown relative inline-flex w-full cursor-pointer">
                                        <button id="hs-pro-eptams" type="button"
                                            class="px-5 py-2.5 text-start w-full flex items-center gap-x-1 text-sm font-normal text-stone-500 focus:outline-none focus:bg-stone-100 dark:text-neutral-500 dark:focus:bg-neutral-700"
                                            aria-haspopup="menu" aria-expanded="false" aria-label="Dropdown">
                                            Amount
                                        </button>
                                    </div>
                                    <!-- End Sort Dropdown -->
                                </th>

                                <th scope="col"></th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-stone-200 dark:divide-neutral-700">
                            <tr>
                                <td class="size-px whitespace-nowrap px-4 py-1">
                                    <div class="w-full flex items-center gap-x-3">
                                        <img class="shrink-0 size-[38px] rounded-md"
                                            src="https://images.unsplash.com/photo-1541101767792-f9b2b1c4f127?ixlib=rb-4.0.3&ixid=MnwxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8&auto=format&fit=facearea&facepad=3&w=320&h=320&q=80"
                                            alt="Avatar">
                                        <div class="grow">
                                            <span class="text-sm font-medium text-stone-800 dark:text-neutral-200">
                                                Amanda Harvey
                                            </span>
                                        </div>
                                    </div>
                                </td>
                                <td class="size-px whitespace-nowrap px-4 py-1">
                                    <span class="text-sm text-stone-600 dark:text-neutral-400">
                                        29 Aug, 2023
                                    </span>
                                </td>
                                <td class="size-px whitespace-nowrap px-4 py-1">
                                    <span
                                        class="py-1.5 ps-1.5 pe-2.5 inline-flex items-center gap-x-1.5 text-xs font-medium bg-stone-100 text-stone-800 rounded-full dark:bg-neutral-500/20 dark:text-neutral-500">
                                        Pending
                                    </span>
                                </td>
                                <td class="size-px whitespace-nowrap px-4 py-1">
                                    <div class="flex items-center gap-x-3">
                                        <img class="shrink-0 size-8 rounded-md"
                                            src="https://images.unsplash.com/photo-1610398752800-146f269dfcc8?ixlib=rb-4.0.3&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D&auto=format&fit=crop&w=320&h=320&q=80"
                                            alt="Avatar">
                                        <div class="grow">
                                            <span class="text-sm text-stone-600 dark:text-neutral-400">
                                                Mango Women's shoe
                                            </span>
                                        </div>
                                    </div>
                                </td>
                                <td class="size-px whitespace-nowrap px-4 py-1 text-end">
                                    <span class="text-sm text-stone-600 dark:text-neutral-400">
                                        $29.99
                                    </span>
                                </td>
                                <td class="size-px whitespace-nowrap px-4 py-1 text-end">
                                    <div
                                        class="hs-dropdown [--auto-close:inside] [--placement:top-right] relative inline-flex">
                                        <button id="hs-pro-errtmd1" type="button"
                                            class="size-7 inline-flex justify-center items-center gap-x-2 rounded-lg border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-700 dark:focus:bg-neutral-700"
                                            aria-haspopup="menu" aria-expanded="false" aria-label="Dropdown">
                                            <svg class="shrink-0 size-3.5" xmlns="http://www.w3.org/2000/svg" width="24"
                                                height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <circle cx="12" cy="12" r="1" />
                                                <circle cx="12" cy="5" r="1" />
                                                <circle cx="12" cy="19" r="1" />
                                            </svg>
                                        </button>

                                        <div class="hs-dropdown-menu hs-dropdown-open:opacity-100 w-28 transition-[opacity,margin] duration opacity-0 hidden z-10 bg-white rounded-xl shadow-[0_10px_40px_10px_rgba(0,0,0,0.08)] dark:shadow-[0_10px_40px_10px_rgba(0,0,0,0.2)] dark:bg-neutral-900"
                                            role="menu" aria-orientation="vertical" aria-labelledby="hs-pro-errtmd1">
                                            <div class="p-1">
                                                <button type="button"
                                                    class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-lg text-[13px] text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800">
                                                    Accept
                                                </button>
                                                <button type="button"
                                                    class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-lg text-[13px] text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800">
                                                    Review
                                                </button>
                                                <button type="button"
                                                    class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-lg text-[13px] text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800">
                                                    Reject
                                                </button>
                                                <button type="button"
                                                    class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-lg text-[13px] text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800">
                                                    Delete
                                                </button>

                                                <div class="my-1 border-t border-stone-200 dark:border-neutral-800">
                                                </div>

                                                <button type="button"
                                                    class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-lg text-[13px] text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800">
                                                    View order
                                                </button>
                                                <button type="button"
                                                    class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-lg text-[13px] text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800">
                                                    View product
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </td>
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
                    <span class="font-medium">27</span>
                    <span class="text-stone-500 dark:text-neutral-500">results</span>
                </p>

                <!-- Pagination -->
                <nav class="flex justify-end items-center gap-x-1" aria-label="Pagination">
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
                    <div class="flex items-center gap-x-1">
                        <span
                            class="min-h-[38px] min-w-[38px] flex justify-center items-center bg-stone-100 text-stone-800 py-2 px-3 text-sm rounded-lg disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-700 dark:text-white"
                            aria-current="page">1</span>
                        <span
                            class="min-h-[38px] flex justify-center items-center text-stone-500 py-2 px-1.5 text-sm dark:text-neutral-500">of</span>
                        <span
                            class="min-h-[38px] flex justify-center items-center text-stone-500 py-2 px-1.5 text-sm dark:text-neutral-500">3</span>
                    </div>
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
                </nav>
                <!-- End Pagination -->
            </div>
            <!-- End Footer -->
        </div>
        <!-- End Referral Users Table Card -->
            </div>
            <TrafficStat />
        </div>

    </AuthenticatedLayout>
</template>
