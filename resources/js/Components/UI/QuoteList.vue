<script setup>
import { Link, router } from '@inertiajs/vue3';

const props = defineProps({
    quotes: Object, // Liste des devis (quotes)
});

const deleteQuote = async (quote) => {
    try {
        // Appelle la méthode DELETE via Inertia
        router.delete(route('customer.quote.destroy', quote), {
            onSuccess: () => console.log('Quote deleted successfully!'),
            onError: (error) => console.error('Error deleting quote:', error),
        });
    } catch (error) {
        console.error('Error deleting quote:', error);
    }
};
</script>

<template>
    <div v-for="quote in quotes" :key="quote.id">
        <!-- quote List Card -->
        <div
            class="p-4 relative flex flex-col bg-white border border-gray-200 rounded-sm dark:bg-neutral-800 dark:border-neutral-700">
            <div class="grid lg:grid-cols-12 gap-y-2 lg:gap-y-0 gap-x-4">
                <div class="lg:col-span-3">
                    <p>
                        <a class="inline-flex items-center gap-x-1 text-gray-800 decoration-2 hover:underline font-semibold hover:text-blue-600 focus:outline-none focus:underline focus:text-blue-600 dark:text-neutral-200 dark:hover:text-blue-500 dark:focus:outline-none dark:focus:text-blue-500"
                            href="#">
                            {{ quote.number }}
                        </a>
                    </p>

                    <!-- Badge Group -->
                    <div class="mt-1 lg:mt-2 -mx-0.5 sm:-mx-1">
                        <span
                            class="m-0.5 sm:m-1 p-1.5 sm:p-2 inline-block bg-gray-100 text-gray-800 text-xs rounded-sm dark:bg-neutral-700 dark:text-neutral-200">{{
                                new Date(quote.created_at).toLocaleDateString() }}</span>
                        <span
                            class="m-0.5 sm:m-1 p-1.5 sm:p-2 inline-block bg-gray-100 text-gray-800 text-xs rounded-sm dark:bg-neutral-700 dark:text-neutral-200">{{
                                quote.status }}</span>
                    </div>
                    <!-- End Badge Group -->
                </div>
                <!-- End Col -->

                <div class="lg:col-span-3">
                    <p class="mt-1 text-sm text-gray-500 dark:text-neutral-500">
                        {{ quote.notes }}
                    </p>

                    <!-- Avatar Group -->
                    <div class="mt-2 flex items-center gap-x-3">
                        <h4 class="text-xs uppercase text-gray-500 dark:text-neutral-200">
                            Products:
                        </h4>
                        <div class="flex items-center -space-x-2">
                            <img v-for="product in quote.products" :key="product.id"
                                class="shrink-0 size-7 rounded-smll" :src="product.image" alt="Avatar">
                        </div>
                    </div>
                    <!-- End Avatar Group -->
                </div>
                <div class="lg:col-span-3">
                    <!-- Media -->
                    <div class="flex gap-x-3">
                        <!-- Logo -->
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                            class="lucide lucide-house">
                            <path d="M15 21v-8a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v8" />
                            <path
                                d="M3 10a2 2 0 0 1 .709-1.528l7-5.999a2 2 0 0 1 2.582 0l7 5.999A2 2 0 0 1 21 10v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" />
                        </svg>
                        <!-- End Logo -->

                        <!-- Body -->
                        <div class="grow flex flex-col sm:flex-row sm:justify-between gap-y-2 sm:gap-x-3">
                            <div>
                                <p class="text-sm font-medium text-gray-800 dark:text-neutral-200">
                                    {{ quote.property.country }}
                                </p>
                                <p class="text-xs text-gray-500 dark:text-neutral-500">
                                    {{ quote.property.street1 }}
                                </p>
                            </div>
                        </div>
                        <!-- End Body -->
                    </div>
                    <!-- End Media -->

                </div>
                <!-- End Col -->
                <div class="lg:col-span-3">
                    <!-- Button Group -->
                    <div
                        class="flex lg:flex-col justify-end items-center gap-2 border-t border-gray-200 lg:border-t-0 pt-3 lg:pt-0 dark:border-neutral-700">
                        <div class="lg:order-2 lg:ms-auto hidden">
                            <button type="button"
                                class="py-2 px-3 inline-flex items-center gap-x-2 text-sm font-medium rounded-sm border border-gray-200 bg-white text-gray-800 shadow-sm hover:bg-gray-50 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-gray-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-700 dark:focus:bg-neutral-700"
                                data-hs-overlay="#hs-pro-chhdl">
                                More details
                            </button>
                        </div>

                        <!-- More Dropdown -->
                        <div class="lg:order-1 lg:ms-auto">
                            <!-- More Dropdown -->
                            <div class="hs-dropdown [--placement:bottom-right] relative inline-flex">
                                <button id="hs-pro-dupc1" type="button"
                                    class="size-7 inline-flex justify-center items-center gap-x-2 rounded-sm border border-transparent text-gray-500 hover:bg-gray-100 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-gray-200 dark:text-neutral-400 dark:hover:bg-neutral-700 dark:focus:bg-neutral-700"
                                    aria-haspopup="menu" aria-expanded="false" aria-label="Dropdown">
                                    <svg class="shrink-0 size-4" xmlns="http://www.w3.org/2000/svg" width="24"
                                        height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <circle cx="12" cy="12" r="1" />
                                        <circle cx="12" cy="5" r="1" />
                                        <circle cx="12" cy="19" r="1" />
                                    </svg>
                                </button>

                                <div class="hs-dropdown-menu hs-dropdown-open:opacity-100 w-40 transition-[opacity,margin] duration opacity-0 hidden z-10 bg-white rounded-sm shadow-[0_10px_40px_10px_rgba(0,0,0,0.08)] dark:shadow-[0_10px_40px_10px_rgba(0,0,0,0.2)] dark:bg-neutral-900"
                                    role="menu" aria-orientation="vertical" aria-labelledby="hs-pro-dupc1">
                                    <div class="p-1">
                                        <Link :href="route('customer.quote.edit', quote)">
                                        <button type="button"
                                            class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] font-normal text-gray-800 hover:bg-gray-100 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-gray-100 dark:text-neutral-300 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800">
                                            <svg class="shrink-0 size-3.5" xmlns="http://www.w3.org/2000/svg" width="24"
                                                height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z" />
                                                <path d="m15 5 4 4" />
                                            </svg>
                                            Edit
                                        </button>
                                        </Link>
                                        <Link :href="route('customer.quote.show', quote)">
                                        <button type="button"
                                            class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] font-normal text-gray-800 hover:bg-gray-100 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-gray-100 dark:text-neutral-300 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                                stroke-linecap="round" stroke-linejoin="round"
                                                class="lucide lucide-eye shrink-0 size-3.5">
                                                <path
                                                    d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0" />
                                                <circle cx="12" cy="12" r="3" />
                                            </svg>
                                            View
                                        </button>
                                        </Link>
                                        <button type="button"
                                            class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] font-normal text-gray-800 hover:bg-gray-100 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-gray-100 dark:text-neutral-300 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                                stroke-linecap="round" stroke-linejoin="round"
                                                class="lucide lucide-send shrink-0 size-3.5">
                                                <path
                                                    d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z" />
                                                <path d="m21.854 2.147-10.94 10.939" />
                                            </svg>
                                            Send to client
                                        </button>

                                        <div class="my-1 border-t border-gray-200 dark:border-neutral-700">
                                        </div>

                                        <button type="button"
                                            class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] font-normal text-red-600 hover:bg-gray-100 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-gray-100 dark:text-red-500 dark:hover:bg-neutral-800 dark:focus:bg-neutral-700"
                                            aria-haspopup="dialog" aria-expanded="false"
                                            :aria-controls="'hs-pro-pycdpdcm-' + quote.id"
                                            :data-hs-overlay="'#hs-pro-pycdpdcm-' + quote.id">
                                            <svg class="shrink-0 size-3.5" xmlns="http://www.w3.org/2000/svg" width="24"
                                                height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M3 6h18" />
                                                <path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6" />
                                                <path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2" />
                                                <line x1="10" x2="10" y1="11" y2="17" />
                                                <line x1="14" x2="14" y1="11" y2="17" />
                                            </svg>
                                            Delete quote
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <!-- End More Dropdown -->
                        </div>
                        <!-- End More Dropdown -->
                    </div>
                    <!-- End Button Group -->
                </div>
                <!-- End Col -->
            </div>
        </div>
        <!-- End quote List Card -->




        <!-- Card Details Modal -->
        <div :id="'hs-pro-pycdpdcm-' + quote.id"
            class="hs-overlay hidden size-full fixed top-0 start-0 z-[80] overflow-x-hidden overflow-y-auto [--close-when-click-inside:true] pointer-events-none"
            role="dialog" tabindex="-1" aria-labelledby="hs-pro-pycdpdcm-label">
            <div
                class="hs-overlay-open:mt-7 hs-overlay-open:opacity-100 hs-overlay-open:duration-500 mt-0 opacity-0 ease-out transition-all sm:max-w-md sm:w-full m-3 sm:mx-auto h-[calc(100%-3.5rem)] min-h-[calc(100%-3.5rem)] flex items-center">
                <div
                    class="relative w-full max-h-full overflow-hidden flex flex-col bg-white rounded-sm pointer-events-auto dark:bg-neutral-800">
                    <!-- Close Button -->
                    <div class="absolute top-3 end-3">
                        <button type="button"
                            class="size-8 inline-flex justify-center items-center gap-x-2 rounded-sm border border-transparent bg-gray-100 text-gray-800 hover:bg-gray-200 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-gray-200 dark:bg-neutral-700 dark:hover:bg-neutral-600 dark:text-neutral-400 dark:focus:bg-neutral-600"
                            aria-label="Close" :data-hs-overlay="'#hs-pro-pycdpdcm-' + quote.id">
                            <span class="sr-only">Close</span>
                            <svg class="shrink-0 size-4" xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                stroke-linecap="round" stroke-linejoin="round">
                                <path d="M18 6 6 18" />
                                <path d="m6 6 12 12" />
                            </svg>
                        </button>
                    </div>
                    <!-- End Close Button -->

                    <!-- Body -->
                    <div class="p-5 sm:p-10">
                        <h3 id="hs-pro-pycdpdcm-label" class="text-lg font-medium text-gray-800 dark:text-neutral-200">
                            Are you sure you want to delete this quote?
                        </h3>
                        <p class="mt-2 text-sm text-gray-500 dark:text-neutral-500">
                            This action is irreversible. If you want to just temporarily
                            disable this card, you
                            can freeze it in settings.
                        </p>
                    </div>
                    <!-- End Body -->

                    <!-- Footer -->
                    <div class="pb-5 px-5 sm:px-10 flex justify-center items-center gap-x-3">
                        <button type="button"
                            class="py-2.5 px-3 w-full inline-flex justify-center items-center gap-x-1.5 text-sm font-medium rounded-sm border border-gray-200 bg-white text-gray-800 shadow-sm hover:bg-gray-50 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-gray-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-700 dark:focus:bg-neutral-700"
                            :data-hs-overlay="'#hs-pro-pycdpdcm-' + quote.id">
                            Cancel
                        </button>
                        <button @click="deleteQuote(quote)" type="button"
                            class="py-2.5 px-3 w-full inline-flex justify-center items-center gap-x-1.5 text-sm font-medium rounded-sm border border-transparent bg-green-500 text-white hover:bg-green-600 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-green-600"
                            :data-hs-overlay="'#hs-pro-pycdpdcm-' + quote.id">
                            Confirm
                        </button>
                    </div>
                    <!-- End Footer -->
                </div>
            </div>
        </div>
        <!-- End Card Details Modal -->
    </div>
</template>
