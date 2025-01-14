<script setup>
import { watch } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import Modal from '@/Components/UI/Modal.vue';

const props = defineProps({
    filters: Object,
    customers: {
        type: Object,
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
        autoFilter('customer.index');
    }
});

</script>

<template>
    <!-- Referral Users Table Card -->
    <div
        class="p-5 space-y-4 flex flex-col border-t-4 border-t-zinc-600 bg-white border border-stone-200 shadow-sm rounded-sm dark:bg-neutral-800 dark:border-neutral-700">
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
                    <input type="text" v-model="filterForm.name"
                        @input="filterForm.name.length >= 1 ? autoFilter('customer.index') : null"
                        class="py-[7px] ps-10 pe-8 block w-full bg-stone-100 border-transparent rounded-lg text-sm placeholder:text-stone-500 focus:border-green-500 focus:ring-green-600 disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-700 dark:border-transparent dark:text-neutral-400 dark:placeholder:text-neutral-400 dark:focus:ring-neutral-600"
                        placeholder="Search customers">
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
                        class="py-2 px-2.5 inline-flex items-center gap-x-1.5 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-700 dark:focus:bg-neutral-700"
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
                            1
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


                        </div>
                    </div>
                    <!-- End Dropdown -->
                    <div class="flex justify-end items-center gap-x-2">
                        <!-- Button -->
                        <Link :href="route('customer.create')"
                            class="py-2 px-2.5 ml-4 inline-flex items-center gap-x-1.5 text-xs font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:ring-2 focus:ring-green-500" >
                            <svg class="hidden sm:block shrink-0 size-3.5" xmlns="http://www.w3.org/2000/svg" width="24"
                                height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                stroke-linecap="round" stroke-linejoin="round">
                                <path d="M5 12h14" />
                                <path d="M12 5v14" />
                            </svg>
                            Add customer
                        </Link>
                        <!-- End Button -->
                    </div>
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
                        <tr>
                            <th scope="col" class="min-w-[270px] ">
                                <!-- Sort Dropdown -->
                                <div class="hs-dropdown relative inline-flex w-full cursor-pointer">
                                    <button id="hs-pro-eptnms" type="button"
                                        class="px-5 py-2.5 text-start w-full flex items-center gap-x-1 text-sm font-normal text-stone-500 focus:outline-none focus:bg-stone-100 dark:text-neutral-500 dark:focus:bg-neutral-700"
                                        aria-haspopup="menu" aria-expanded="false" aria-label="Dropdown">
                                        Company name
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
                                        Name
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
                                        Phone
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
                                        Description
                                    </button>
                                </div>
                                <!-- End Sort Dropdown -->
                            </th>

                            <th scope="col"></th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-stone-200 dark:divide-neutral-700">
                        <tr v-for="customer in customers.data" :key="customer.id">
                            <td class="size-px whitespace-nowrap px-4 py-1 text-start">
                                <Link :href="route('customer.show', customer)" >
                                    <div class="w-full flex items-center gap-x-3">
                                        <img class="shrink-0 size-10 rounded-md" :src="customer.logo" alt="customer Image">
                                        <div class="flex flex-col">
                                            <span class="text-sm text-stone-600 dark:text-neutral-400">
                                                {{ customer.company_name }}
                                            </span>
                                            <span class="text-xs text-stone-500 dark:text-neutral-500">
                                                {{ customer.number }}
                                            </span>
                                        </div>
                                    </div>
                                </Link>
                            </td>
                            <td class="size-px whitespace-nowrap px-4 py-1">
                                <div class="flex flex-col">
                                    <span class="text-sm text-stone-600 dark:text-neutral-400">
                                        {{ customer.first_name }}
                                    </span>
                                    <span class="text-xs text-stone-500 dark:text-neutral-500">
                                        {{ customer.last_name }}
                                    </span>
                                </div>
                            </td>
                            <td class="size-px whitespace-nowrap px-4 py-1">
                                <span
                                    class="inline-flex items-center gap-x-1 text-sm text-stone-600 dark:text-neutral-400">
                                    {{ customer.phone }}
                                </span>
                            </td>
                            <td class="size-px whitespace-nowrap px-4 py-1">
                                <span
                                    class="py-1.5 px-2 inline-flex items-center gap-x-1.5 text-xs font-medium bg-stone-100 text-stone-800 rounded-full dark:bg-neutral-700 dark:text-neutral-200">
                                    {{ customer.description }}
                                </span>
                            </td>
                            <td class="size-px whitespace-nowrap px-4 py-1 text-end">
                                <div
                                    class="hs-dropdown [--auto-close:inside] [--placement:bottom-right] relative inline-flex">
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

                                    <div class="hs-dropdown-menu hs-dropdown-open:opacity-100 w-24 transition-[opacity,margin] duration opacity-0 hidden z-10 bg-white rounded-xl shadow-[0_10px_40px_10px_rgba(0,0,0,0.08)] dark:shadow-[0_10px_40px_10px_rgba(0,0,0,0.2)] dark:bg-neutral-900"
                                        role="menu" aria-orientation="vertical" aria-labelledby="hs-pro-errtmd1">
                                        <div class="p-1">
                                            <button type="button" :data-hs-overlay="'#hs-pro-edit' + customer.id"
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

                            <!-- Modal add customer -->
                            <!-- <Modal :title="'Edit customer'" :id="'hs-pro-edit' + customer.id">
                                                <customerForm :customer="customer" :categories="categories" :id="'hs-pro-edit' + customer.id" />
                                            </Modal> -->
                            <!-- End Modal -->
                        </tr>
                    </tbody>
                </table>
                <!-- End Table -->
            </div>
        </div>
        <!-- End Table Section -->

        <!-- Footer -->
        <div v-if="customers.data.length > 0" class="mt-5 flex flex-wrap justify-between items-center gap-2">
            <p class="text-sm text-stone-800 dark:text-neutral-200">
                <span class="font-medium"> {{ count }} </span>
                <span class="text-stone-500 dark:text-neutral-500"> results</span>
            </p>

            <!-- Pagination -->
            <nav class="flex justify-end items-center gap-x-1" aria-label="Pagination">
                <Link :href="customers.prev_page_url" v-if="customers.prev_page_url">
                <button type="button"
                    class="min-h-[38px] min-w-[38px] py-2 px-2.5 inline-flex justify-center items-center gap-x-2 text-sm rounded-lg text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 dark:text-white dark:hover:bg-white/10 dark:focus:bg-neutral-700"
                    aria-label="Previous">
                    <svg class="shrink-0 size-3.5" xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                        viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                        stroke-linejoin="round">
                        <path d="m15 18-6-6 6-6" />
                    </svg>
                    <span class="sr-only">Previous</span>
                </button>
                </Link>
                <div class="flex items-center gap-x-1">
                    <span
                        class="min-h-[38px] min-w-[38px] flex justify-center items-center bg-stone-100 text-stone-800 py-2 px-3 text-sm rounded-sm disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-700 dark:text-white"
                        aria-current="page">{{ customers.from }}</span>
                    <span
                        class="min-h-[38px] flex justify-center items-center text-stone-500 py-2 px-1.5 text-sm dark:text-neutral-500">of</span>
                    <span
                        class="min-h-[38px] flex justify-center items-center text-stone-500 py-2 px-1.5 text-sm dark:text-neutral-500">{{
                            customers.to }}</span>
                </div>

                <Link :href="customers.next_page_url" v-if="customers.next_page_url">
                <button type="button"
                    class="min-h-[38px] min-w-[38px] py-2 px-2.5 inline-flex justify-center items-center gap-x-2 text-sm rounded-sm text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 dark:text-white dark:hover:bg-white/10 dark:focus:bg-neutral-700"
                    aria-label="Next">
                    <span class="sr-only">Next</span>
                    <svg class="shrink-0 size-3.5" xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                        viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                        stroke-linejoin="round">
                        <path d="m9 18 6-6-6-6" />
                    </svg>
                </button>
                </Link>
            </nav>
            <!-- End Pagination -->
        </div>
        <!-- End Footer -->
    </div>
    <!-- End Referral Users Table Card -->

    <!-- Modal add customer -->
    <!-- <Modal :title="'Add customer'" :id="'hs-pro-dasadpm'">
        <customerForm :customer="customer" :categories="categories" :id="'hs-pro-dasadpm'" />
    </Modal> -->

</template>
