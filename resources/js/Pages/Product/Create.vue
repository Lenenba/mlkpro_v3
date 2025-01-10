<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';

Dropzone.autoDiscover = false;

const props = defineProps({
    user: {
        type: Object,
        required: true,
    }
});



</script>

<template>

    <Head title="Dashboard" />
    <AuthenticatedLayout :user="user">

        <!-- Products Grid -->
        <div class="grid grid-cols-1 lg:grid-cols-6 gap-5">
            <div class="lg:col-span-4 space-y-4">
                <!-- Products Card -->
                <div
                    class="flex flex-col bg-white border border-stone-200 overflow-hidden rounded-xl shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                    <!-- Header -->
                    <div
                        class="py-3 px-5 flex justify-between items-center gap-x-5 border-b border-stone-200 dark:border-neutral-700">
                        <h2 class="inline-block font-semibold text-stone-800 dark:text-neutral-200">
                            Product info
                        </h2>
                    </div>
                    <!-- End Header -->

                    <!-- Body -->
                    <div class="p-5 space-y-4">
                        <!-- Input -->
                        <div>
                            <label for="hs-pro-eapnm"
                                class="block mb-2 text-sm font-medium text-stone-800 dark:text-neutral-200">
                                Name
                                <span class="hs-tooltip inline-block align-middle">
                                    <svg class="shrink-0 size-4 text-stone-500 dark:text-neutral-400"
                                        xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                        fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                        stroke-linejoin="round">
                                        <circle cx="12" cy="12" r="10" />
                                        <path d="M12 16v-4" />
                                        <path d="M12 8h.01" />
                                    </svg>
                                    <span
                                        class="hs-tooltip-content hs-tooltip-shown:opacity-100 hs-tooltip-shown:visible opacity-0 transition-opacity hidden invisible z-[60] max-w-60 py-1 px-2 bg-stone-900 text-xs font-normal text-white rounded-lg shadow-sm dark:bg-neutral-700"
                                        role="tooltip">
                                        Give your product a short and clear name.
                                    </span>
                                </span>
                            </label>
                            <input id="hs-pro-eapnm" type="text"
                                class="py-2 px-3 block w-full border-stone-200 rounded-lg text-sm text-stone-800 placeholder:text-stone-500 focus:z-10 focus:border-green-600 focus:ring-green-600 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200 dark:placeholder:text-neutral-500 dark:focus:outline-none dark:focus:ring-1 dark:focus:ring-neutral-600"
                                placeholder="Shirt, t-shirts, etc." value="">
                        </div>
                        <!-- End Input -->

                        <!-- Input Grid -->
                        <div class="grid sm:grid-cols-2 gap-3 sm:gap-5">
                            <!-- Input -->
                            <div>
                                <label for="hs-pro-eapsku"
                                    class="block mb-2 text-sm font-medium text-stone-800 dark:text-neutral-200">
                                    SKU
                                </label>
                                <input id="hs-pro-eapsku" type="text"
                                    class="py-2 px-3 block w-full border-stone-200 rounded-lg text-sm text-stone-800 placeholder:text-stone-500 focus:z-10 focus:border-green-600 focus:ring-green-600 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200 dark:placeholder:text-neutral-500 dark:focus:outline-none dark:focus:ring-1 dark:focus:ring-neutral-600"
                                    placeholder="eg. 348121032" value="">
                            </div>
                            <!-- End Input -->

                            <!-- Input -->
                            <div>
                                <label for="hs-pro-eapwe"
                                    class="block mb-2 text-sm font-medium text-stone-800 dark:text-neutral-200">
                                    Weight
                                </label>
                                <div class="relative w-full">
                                    <input id="hs-pro-eapwe" type="text"
                                        class="py-2 px-3 block w-full border-stone-200 rounded-lg text-sm text-stone-800 placeholder:text-stone-500 focus:z-10 focus:border-green-600 focus:ring-green-600 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200 dark:placeholder:text-neutral-500 dark:focus:outline-none dark:focus:ring-1 dark:focus:ring-neutral-600"
                                        placeholder="0.0" value="">
                                    <div
                                        class="absolute inset-y-0 end-0 flex items-center z-20 pe-[5px] text-stone-400">
                                        <!-- Select -->
                                        <div class="relative">
                                            <select data-hs-select='{
                              "placeholder": "Select option...",
                              "toggleTag": "<button type=\"button\" aria-expanded=\"false\"></button>",
                              "toggleClasses": "hs-select-disabled:pointer-events-none hs-select-disabled:opacity-50 relative py-1.5 ps-2.5 pe-6 inline-flex shrink-0 justify-center items-center gap-x-1.5 text-xs text-stone-800 rounded-lg hover:bg-stone-100 focus:outline-none focus:bg-stone-100 before:absolute before:inset-0 before:z-[1] dark:text-neutral-400 dark:hover:bg-neutral-700 dark:focus:bg-neutral-700",
                              "dropdownClasses": "end-0 mt-2 p-1 z-50 w-20 space-y-0.5 bg-white rounded-xl shadow-[0_10px_40px_10px_rgba(0,0,0,0.08)] dark:bg-neutral-950",
                              "optionClasses": "hs-selected:bg-stone-100 dark:hs-selected:bg-neutral-800 py-1.5 px-2 w-full text-xs text-stone-800 rounded-lg cursor-pointer hover:bg-stone-100 rounded-lg focus:outline-none focus:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800",
                              "optionTemplate": "<div class=\"flex justify-between items-center w-full\"><span data-title></span><span class=\"hidden hs-selected:block\"><svg class=\"shrink-0 size-3.5 text-stone-800 dark:text-neutral-200\" xmlns=\"http:.w3.org/2000/svg\" width=\"24\" height=\"24\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><polyline points=\"20 6 9 17 4 12\"/></svg></span></div>"
                            }' class="hidden">
                                                <option value="">Choose</option>
                                                <option>lb</option>
                                                <option>oz</option>
                                                <option selected>kg</option>
                                                <option>g</option>
                                            </select>

                                            <div class="absolute top-1/2 end-1.5 -translate-y-1/2">
                                                <svg class="shrink-0 size-3.5 text-stone-600 dark:text-neutral-400"
                                                    xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                                    viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                    stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                    <path d="m6 9 6 6 6-6" />
                                                </svg>
                                            </div>
                                        </div>
                                        <!-- End Select -->
                                    </div>
                                </div>

                                <p class="mt-1.5 text-xs text-stone-500 dark:text-neutral-500">
                                    Used to calculate shipping rates at checkout and label prices during fulfillment.
                                </p>
                            </div>
                            <!-- End Input -->
                        </div>
                        <!-- End Input Grid -->

                        <!-- Textarea Input -->
                        <div>
                            <label class="block mb-2 text-sm font-medium text-stone-800 dark:text-neutral-200">
                                Description
                            </label>

                            <!-- Tiptap -->
                            <div
                                class="bg-white border border-stone-200 rounded-xl overflow-hidden dark:bg-neutral-800 dark:border-neutral-700">
                                <div id="hs-editor-tiptap">
                                    <div
                                        class="sticky top-0 bg-white flex align-middle gap-x-0.5 border-b border-stone-200 p-2 dark:border-neutral-700">
                                        <button
                                            class="size-8 inline-flex justify-center items-center gap-x-2 text-sm font-semibold rounded-full border border-transparent text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 dark:text-white dark:hover:bg-neutral-700 dark:focus:bg-neutral-700"
                                            type="button" data-hs-editor-bold>
                                            <svg class="shrink-0 size-4" xmlns="http://www.w3.org/2000/svg" width="24"
                                                height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M14 12a4 4 0 0 0 0-8H6v8" />
                                                <path d="M15 20a4 4 0 0 0 0-8H6v8Z" />
                                            </svg>
                                        </button>
                                        <button
                                            class="size-8 inline-flex justify-center items-center gap-x-2 text-sm font-semibold rounded-full border border-transparent text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 dark:text-white dark:hover:bg-neutral-700 dark:focus:bg-neutral-700"
                                            type="button" data-hs-editor-italic>
                                            <svg class="shrink-0 size-4" xmlns="http://www.w3.org/2000/svg" width="24"
                                                height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <line x1="19" x2="10" y1="4" y2="4" />
                                                <line x1="14" x2="5" y1="20" y2="20" />
                                                <line x1="15" x2="9" y1="4" y2="20" />
                                            </svg>
                                        </button>
                                        <button
                                            class="size-8 inline-flex justify-center items-center gap-x-2 text-sm font-semibold rounded-full border border-transparent text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 dark:text-white dark:hover:bg-neutral-700 dark:focus:bg-neutral-700"
                                            type="button" data-hs-editor-underline>
                                            <svg class="shrink-0 size-4" xmlns="http://www.w3.org/2000/svg" width="24"
                                                height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M6 4v6a6 6 0 0 0 12 0V4" />
                                                <line x1="4" x2="20" y1="20" y2="20" />
                                            </svg>
                                        </button>
                                        <button
                                            class="size-8 inline-flex justify-center items-center gap-x-2 text-sm font-semibold rounded-full border border-transparent text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 dark:text-white dark:hover:bg-neutral-700 dark:focus:bg-neutral-700"
                                            type="button" data-hs-editor-strike>
                                            <svg class="shrink-0 size-4" xmlns="http://www.w3.org/2000/svg" width="24"
                                                height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M16 4H9a3 3 0 0 0-2.83 4" />
                                                <path d="M14 12a4 4 0 0 1 0 8H6" />
                                                <line x1="4" x2="20" y1="12" y2="12" />
                                            </svg>
                                        </button>
                                        <button
                                            class="size-8 inline-flex justify-center items-center gap-x-2 text-sm font-semibold rounded-full border border-transparent text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 dark:text-white dark:hover:bg-neutral-700 dark:focus:bg-neutral-700"
                                            type="button" data-hs-editor-link>
                                            <svg class="shrink-0 size-4" xmlns="http://www.w3.org/2000/svg" width="24"
                                                height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" />
                                                <path
                                                    d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" />
                                            </svg>
                                        </button>
                                        <button
                                            class="size-8 inline-flex justify-center items-center gap-x-2 text-sm font-semibold rounded-full border border-transparent text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 dark:text-white dark:hover:bg-neutral-700 dark:focus:bg-neutral-700"
                                            type="button" data-hs-editor-ol>
                                            <svg class="shrink-0 size-4" xmlns="http://www.w3.org/2000/svg" width="24"
                                                height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <line x1="10" x2="21" y1="6" y2="6" />
                                                <line x1="10" x2="21" y1="12" y2="12" />
                                                <line x1="10" x2="21" y1="18" y2="18" />
                                                <path d="M4 6h1v4" />
                                                <path d="M4 10h2" />
                                                <path d="M6 18H4c0-1 2-2 2-3s-1-1.5-2-1" />
                                            </svg>
                                        </button>
                                        <button
                                            class="size-8 inline-flex justify-center items-center gap-x-2 text-sm font-semibold rounded-full border border-transparent text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 dark:text-white dark:hover:bg-neutral-700 dark:focus:bg-neutral-700"
                                            type="button" data-hs-editor-ul>
                                            <svg class="shrink-0 size-4" xmlns="http://www.w3.org/2000/svg" width="24"
                                                height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <line x1="8" x2="21" y1="6" y2="6" />
                                                <line x1="8" x2="21" y1="12" y2="12" />
                                                <line x1="8" x2="21" y1="18" y2="18" />
                                                <line x1="3" x2="3.01" y1="6" y2="6" />
                                                <line x1="3" x2="3.01" y1="12" y2="12" />
                                                <line x1="3" x2="3.01" y1="18" y2="18" />
                                            </svg>
                                        </button>
                                        <button
                                            class="size-8 inline-flex justify-center items-center gap-x-2 text-sm font-semibold rounded-full border border-transparent text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 dark:text-white dark:hover:bg-neutral-700 dark:focus:bg-neutral-700"
                                            type="button" data-hs-editor-blockquote>
                                            <svg class="shrink-0 size-4" xmlns="http://www.w3.org/2000/svg" width="24"
                                                height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M17 6H3" />
                                                <path d="M21 12H8" />
                                                <path d="M21 18H8" />
                                                <path d="M3 12v6" />
                                            </svg>
                                        </button>
                                        <button
                                            class="size-8 inline-flex justify-center items-center gap-x-2 text-sm font-semibold rounded-full border border-transparent text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 dark:text-white dark:hover:bg-neutral-700 dark:focus:bg-neutral-700"
                                            type="button" data-hs-editor-code>
                                            <svg class="shrink-0 size-4" xmlns="http://www.w3.org/2000/svg" width="24"
                                                height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="m18 16 4-4-4-4" />
                                                <path d="m6 8-4 4 4 4" />
                                                <path d="m14.5 4-5 16" />
                                            </svg>
                                        </button>
                                    </div>
                                    <div class="h-[10rem] overflow-auto " data-hs-editor-field></div>
                                </div>
                            </div>
                            <!-- End Tiptap -->
                        </div>
                        <!-- End Textarea Input -->

                        <div data-hs-file-upload='{
  "url": "/upload",
  "extensions": {
    "default": {
      "class": "shrink-0 size-5"
    },
    "xls": {
      "class": "shrink-0 size-5"
    },
    "zip": {
      "class": "shrink-0 size-5"
    },
    "csv": {
      "icon": "<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"24\" height=\"24\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><path d=\"M4 22h14a2 2 0 0 0 2-2V7l-5-5H6a2 2 0 0 0-2 2v4\"/><path d=\"M14 2v4a2 2 0 0 0 2 2h4\"/><path d=\"m5 12-3 3 3 3\"/><path d=\"m9 18 3-3-3-3\"/></svg>",
      "class": "shrink-0 size-5"
    }
  }
}'>
                            <template data-hs-file-upload-preview="">
                                <div
                                    class="p-3 bg-white border border-solid border-gray-300 rounded-xl dark:bg-neutral-800 dark:border-neutral-600">
                                    <div class="mb-1 flex justify-between items-center">
                                        <div class="flex items-center gap-x-3">
                                            <span
                                                class="size-10 flex justify-center items-center border border-gray-200 text-gray-500 rounded-lg dark:border-neutral-700 dark:text-neutral-500"
                                                data-hs-file-upload-file-icon="">
                                                <img class="rounded-lg hidden" data-dz-thumbnail="">
                                            </span>
                                            <div>
                                                <p class="text-sm font-medium text-gray-800 dark:text-white">
                                                    <span class="truncate inline-block max-w-[300px] align-bottom"
                                                        data-hs-file-upload-file-name=""></span>.<span
                                                        data-hs-file-upload-file-ext=""></span>
                                                </p>
                                                <p class="text-xs text-gray-500 dark:text-neutral-500"
                                                    data-hs-file-upload-file-size=""></p>
                                            </div>
                                        </div>
                                        <div class="flex items-center gap-x-2">
                                            <button type="button"
                                                class="text-gray-500 hover:text-gray-800 focus:outline-none focus:text-gray-800 dark:text-neutral-500 dark:hover:text-neutral-200 dark:focus:text-neutral-200"
                                                data-hs-file-upload-remove="">
                                                <svg class="shrink-0 size-4" xmlns="http://www.w3.org/2000/svg"
                                                    width="24" height="24" viewBox="0 0 24 24" fill="none"
                                                    stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                                    stroke-linejoin="round">
                                                    <path d="M3 6h18"></path>
                                                    <path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"></path>
                                                    <path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"></path>
                                                    <line x1="10" x2="10" y1="11" y2="17"></line>
                                                    <line x1="14" x2="14" y1="11" y2="17"></line>
                                                </svg>
                                            </button>
                                        </div>
                                    </div>

                                    <div class="flex items-center gap-x-3 whitespace-nowrap">
                                        <div class="flex w-full h-2 bg-gray-200 rounded-full overflow-hidden dark:bg-neutral-700"
                                            role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"
                                            data-hs-file-upload-progress-bar="">
                                            <div class="flex flex-col justify-center rounded-full overflow-hidden bg-blue-600 text-xs text-white text-center whitespace-nowrap transition-all duration-500 hs-file-upload-complete:bg-green-500"
                                                style="width: 0" data-hs-file-upload-progress-bar-pane=""></div>
                                        </div>
                                        <div class="w-10 text-end">
                                            <span class="text-sm text-gray-800 dark:text-white">
                                                <span data-hs-file-upload-progress-bar-value="">0</span>%
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </template>

                            <div class="cursor-pointer p-12 flex justify-center bg-white border border-dashed border-gray-300 rounded-xl dark:bg-neutral-800 dark:border-neutral-600"
                                data-hs-file-upload-trigger="">
                                <div class="text-center">
                                    <span
                                        class="inline-flex justify-center items-center size-16 bg-gray-100 text-gray-800 rounded-full dark:bg-neutral-700 dark:text-neutral-200">
                                        <svg class="shrink-0 size-6" xmlns="http://www.w3.org/2000/svg" width="24"
                                            height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                            stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                                            <polyline points="17 8 12 3 7 8"></polyline>
                                            <line x1="12" x2="12" y1="3" y2="15"></line>
                                        </svg>
                                    </span>

                                    <div class="mt-4 flex flex-wrap justify-center text-sm leading-6 text-gray-600">
                                        <span class="pe-1 font-medium text-gray-800 dark:text-neutral-200">
                                            Drop your file here or
                                        </span>
                                        <span
                                            class="bg-white font-semibold text-blue-600 hover:text-blue-700 rounded-lg decoration-2 hover:underline focus-within:outline-none focus-within:ring-2 focus-within:ring-blue-600 focus-within:ring-offset-2 dark:bg-neutral-800 dark:text-blue-500 dark:hover:text-blue-600">browse</span>
                                    </div>

                                    <p class="mt-1 text-xs text-gray-400 dark:text-neutral-400">
                                        Pick a file up to 2MB.
                                    </p>
                                </div>
                            </div>

                            <div class="mt-4 space-y-2 empty:mt-0" data-hs-file-upload-previews=""></div>
                        </div>
                    </div>
                    <!-- End Body -->
                </div>
                <!-- End Products Card -->
            </div>
            <!-- End Col -->

            <div class="lg:col-span-2">
                <div class="lg:sticky lg:top-5 space-y-4">
                    <!-- Product Pricing Card -->
                    <div
                        class="flex flex-col bg-white border border-stone-200 overflow-hidden rounded-xl shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                        <!-- Header -->
                        <div
                            class="py-3 px-5 flex justify-between items-center gap-x-5 border-b border-stone-200 dark:border-neutral-700">
                            <h2 class="inline-block font-semibold text-stone-800 dark:text-neutral-200">
                                Pricing
                            </h2>
                        </div>
                        <!-- End Header -->

                        <!-- Body -->
                        <div id="hs-product-details-pricing-card-body" class="p-5 space-y-4">
                            <!-- Input -->
                            <div>
                                <label for="hs-pro-daufpr"
                                    class="block mb-2 text-sm font-medium text-stone-800 dark:text-neutral-200">
                                    Price
                                </label>
                                <div class="relative w-full">
                                    <input id="hs-pro-daufpr" type="text"
                                        class="py-2 px-3 block w-full border-stone-200 rounded-lg text-sm text-stone-800 placeholder:text-stone-500 focus:z-10 focus:border-green-600 focus:ring-green-600 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200 dark:placeholder:text-neutral-500 dark:focus:outline-none dark:focus:ring-1 dark:focus:ring-neutral-600"
                                        placeholder="0.00" value="45.00">
                                    <div class="absolute inset-y-0 end-0 flex items-center pe-[5px] text-stone-400">
                                        <!-- Select -->
                                        <div class="relative">
                                            <select data-hs-select='{
                                                "placeholder": "Select option...",
                                                "toggleTag": "<button type=\"button\" aria-expanded=\"false\"></button>",
                                                "toggleClasses": "hs-select-disabled:pointer-events-none hs-select-disabled:opacity-50 relative py-1.5 ps-2.5 pe-6 inline-flex shrink-0 justify-center items-center gap-x-1.5 text-xs text-stone-800 rounded-lg hover:bg-stone-100 focus:outline-none focus:bg-stone-100 before:absolute before:inset-0 before:z-[1] dark:text-neutral-400 dark:hover:bg-neutral-700 dark:focus:bg-neutral-700",
                                                "dropdownClasses": "end-0 mt-2 p-1 z-50 w-24 space-y-0.5 bg-white rounded-xl shadow-[0_10px_40px_10px_rgba(0,0,0,0.08)] dark:bg-neutral-950",
                                                "optionClasses": "hs-selected:bg-stone-100 dark:hs-selected:bg-neutral-800 py-1.5 px-2 w-full text-xs text-stone-800 rounded-lg cursor-pointer hover:bg-stone-100 rounded-lg focus:outline-none focus:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800",
                                                "optionTemplate": "<div class=\"flex justify-between items-center w-full\"><span data-title></span><span class=\"hidden hs-selected:block\"><svg class=\"shrink-0 size-3.5 text-stone-800 dark:text-neutral-200\" xmlns=\"http:.w3.org/2000/svg\" width=\"24\" height=\"24\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><polyline points=\"20 6 9 17 4 12\"/></svg></span></div>",
                                                "viewport": "#hs-product-details-pricing-card-body"
                                                }' class="hidden">
                                                <option value="">Choose</option>
                                                <option selected value="USD">USD</option>
                                                <option value="EURO">EURO</option>
                                                <option value="YUAN">YUAN</option>
                                            </select>

                                            <div class="absolute top-1/2 end-1.5 -translate-y-1/2">
                                                <svg class="shrink-0 size-3.5 text-stone-600 dark:text-neutral-400"
                                                    xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                                    viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                    stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                    <path d="m6 9 6 6 6-6" />
                                                </svg>
                                            </div>
                                        </div>
                                        <!-- End Select -->
                                    </div>
                                </div>
                            </div>
                            <!-- End Input -->

                            <!-- Switch/Toggle -->
                            <div
                                class="py-2 px-3 flex justify-between items-center border border-stone-200 rounded-lg dark:border-neutral-700">
                                <label for="hs-pro-epdas"
                                    class="inline-block flex-1 text-sm text-stone-800 dark:text-neutral-200">Availability</label>
                                <div class="relative inline-block">
                                    <input type="checkbox" id="hs-pro-epdas"
                                        class="relative w-11 h-6 p-px bg-stone-100 border-transparent text-transparent rounded-full cursor-pointer transition-colors ease-in-out duration-200 focus:ring-green-600 disabled:opacity-50 disabled:pointer-events-none checked:bg-none checked:text-green-600 checked:border-green-600 focus:checked:border-green-600 dark:bg-neutral-800 dark:border-neutral-700 dark:checked:bg-green-500 dark:checked:border-green-500 dark:focus:ring-offset-neutral-900

                      before:inline-block before:size-5 before:bg-white checked:before:bg-white before:translate-x-0 checked:before:translate-x-full before:rounded-full before:shadow before:transform before:ring-0 before:transition before:ease-in-out before:duration-200 dark:before:bg-neutral-400 dark:checked:before:bg-white"
                                        checked>
                                </div>
                            </div>
                            <!-- End Switch/Toggle -->

                            <!-- Links List -->
                            <div>
                                <p>
                                    <span class="inline-flex items-center gap-x-2 text-sm">
                                        <svg class="shrink-0 size-4 text-yellow-500" xmlns="http://www.w3.org/2000/svg"
                                            width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                                            <path
                                                d="M3.612 15.443c-.386.198-.824-.149-.746-.592l.83-4.73L.173 6.765c-.329-.314-.158-.888.283-.95l4.898-.696L7.538.792c.197-.39.73-.39.927 0l2.184 4.327 4.898.696c.441.062.612.636.282.95l-3.522 3.356.83 4.73c.078.443-.36.79-.746.592L8 13.187l-4.389 2.256z" />
                                        </svg>
                                        <a class="text-sm text-green-600 decoration-2 hover:underline font-medium focus:outline-none focus:underline dark:text-green-400 dark:hover:text-green-500"
                                            href="#">Set "Compare to" price</a>
                                    </span>
                                </p>

                                <p>
                                    <span class="inline-flex items-center gap-x-2 text-sm">
                                        <svg class="shrink-0 size-4 text-yellow-500" xmlns="http://www.w3.org/2000/svg"
                                            width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                                            <path
                                                d="M3.612 15.443c-.386.198-.824-.149-.746-.592l.83-4.73L.173 6.765c-.329-.314-.158-.888.283-.95l4.898-.696L7.538.792c.197-.39.73-.39.927 0l2.184 4.327 4.898.696c.441.062.612.636.282.95l-3.522 3.356.83 4.73c.078.443-.36.79-.746.592L8 13.187l-4.389 2.256z" />
                                        </svg>
                                        <a class="text-sm text-green-600 decoration-2 hover:underline font-medium focus:outline-none focus:underline dark:text-green-400 dark:hover:text-green-500"
                                            href="#">Bulk discount pricing</a>
                                    </span>
                                </p>
                            </div>
                            <!-- End Links List -->
                        </div>
                        <!-- End Body -->
                    </div>
                    <!-- End Product Pricing Card -->

                    <!-- Organization Card -->
                    <div
                        class="flex flex-col bg-white border border-stone-200 overflow-hidden rounded-xl shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                        <!-- Header -->
                        <div
                            class="py-3 px-5 flex justify-between items-center gap-x-5 border-b border-stone-200 dark:border-neutral-700">
                            <h2 class="inline-block font-semibold text-stone-800 dark:text-neutral-200">
                                Organization
                            </h2>
                        </div>
                        <!-- End Header -->

                        <!-- Body -->
                        <div id="hs-add-product-organization-card-body" class="p-5 space-y-4">
                            <!-- Input -->
                            <div>
                                <label for="hs-pro-daufvd"
                                    class="block mb-2 text-sm font-medium text-stone-800 dark:text-neutral-200">
                                    Vendor
                                </label>
                                <input id="hs-pro-daufvd" type="text"
                                    class="py-2 px-3 block w-full border-stone-200 rounded-lg text-sm text-stone-800 placeholder:text-stone-500 focus:z-10 focus:border-green-600 focus:ring-green-600 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200 dark:placeholder:text-neutral-500 dark:focus:outline-none dark:focus:ring-1 dark:focus:ring-neutral-600"
                                    placeholder="eg. Nike">
                            </div>
                            <!-- End Input -->

                            <!-- Input -->
                            <div>
                                <label class="block mb-2 text-sm font-medium text-stone-800 dark:text-neutral-200">
                                    Category
                                </label>

                                <!-- Select -->
                                <div class="relative">
                                    <select data-hs-select='{
                          "placeholder": "Select option...",
                          "toggleTag": "<button type=\"button\" aria-expanded=\"false\"></button>",
                          "toggleClasses": "hs-select-disabled:pointer-events-none hs-select-disabled:opacity-50 relative py-2 px-4 pe-9 flex text-nowrap w-full cursor-pointer bg-white border border-stone-200 rounded-lg text-start text-sm text-stone-800 focus:outline-none focus:ring-2 focus:ring-green-600 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200 dark:focus:outline-none dark:focus:ring-1 dark:focus:ring-neutral-600",
                          "dropdownClasses": "mt-2 z-50 w-full min-w-36 max-h-72 z-50 p-1 space-y-0.5 bg-white rounded-xl shadow-[0_10px_40px_10px_rgba(0,0,0,0.08)] dark:bg-neutral-900",
                          "optionClasses": "hs-selected:bg-stone-100 dark:hs-selected:bg-neutral-800 py-2 px-4 w-full text-sm text-stone-800 cursor-pointer hover:bg-stone-100 rounded-lg focus:outline-none focus:bg-stone-100 dark:bg-neutral-900 dark:hover:bg-neutral-800 dark:text-neutral-200 dark:focus:bg-neutral-800",
                          "optionTemplate": "<div class=\"flex justify-between items-center w-full\"><span data-title></span><span class=\"hidden hs-selected:block\"><svg class=\"shrink-0 size-3.5 text-stone-800 dark:text-neutral-200\" xmlns=\"http:.w3.org/2000/svg\" width=\"24\" height=\"24\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><polyline points=\"20 6 9 17 4 12\"/></svg></span></div>"
                        }' class="hidden">
                                        <option value="">Choose</option>
                                        <option>Clothing</option>
                                        <option>Shoes</option>
                                        <option>Electronics</option>
                                        <option>Others</option>
                                    </select>

                                    <div class="absolute top-1/2 end-2.5 -translate-y-1/2">
                                        <svg class="shrink-0 size-3.5 text-stone-500" xmlns="http://www.w3.org/2000/svg"
                                            width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                            stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="m7 15 5 5 5-5" />
                                            <path d="m7 9 5-5 5 5" />
                                        </svg>
                                    </div>
                                </div>
                                <!-- End Select -->
                            </div>
                            <!-- End Input -->

                            <!-- Input -->
                            <div>
                                <label class="block mb-2 text-sm font-medium text-stone-800 dark:text-neutral-200">
                                    Collections
                                </label>

                                <!-- Select -->
                                <div class="relative">
                                    <select data-hs-select='{
                          "placeholder": "Select option...",
                          "toggleTag": "<button type=\"button\" aria-expanded=\"false\"></button>",
                          "toggleClasses": "hs-select-disabled:pointer-events-none hs-select-disabled:opacity-50 relative py-2 px-4 pe-9 flex text-nowrap w-full cursor-pointer bg-white border border-stone-200 rounded-lg text-start text-sm text-stone-800 focus:outline-none focus:ring-2 focus:ring-green-600 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200 dark:focus:outline-none dark:focus:ring-1 dark:focus:ring-neutral-600",
                          "dropdownClasses": "mt-2 z-50 w-full min-w-36 max-h-72 z-50 p-1 space-y-0.5 bg-white rounded-xl shadow-[0_10px_40px_10px_rgba(0,0,0,0.08)] dark:bg-neutral-900",
                          "optionClasses": "hs-selected:bg-stone-100 dark:hs-selected:bg-neutral-800 py-2 px-4 w-full text-sm text-stone-800 cursor-pointer hover:bg-stone-100 rounded-lg focus:outline-none focus:bg-stone-100 dark:bg-neutral-900 dark:hover:bg-neutral-800 dark:text-neutral-200 dark:focus:bg-neutral-800",
                          "optionTemplate": "<div class=\"flex justify-between items-center w-full\"><span data-title></span><span class=\"hidden hs-selected:block\"><svg class=\"shrink-0 size-3.5 text-stone-800 dark:text-neutral-200\" xmlns=\"http:.w3.org/2000/svg\" width=\"24\" height=\"24\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><polyline points=\"20 6 9 17 4 12\"/></svg></span></div>",
                          "viewport": "#hs-add-product-organization-card-body"
                        }' class="hidden">
                                        <option value="">Choose</option>
                                        <option>Winter</option>
                                        <option>Spring</option>
                                        <option>Summer</option>
                                        <option>Autumn</option>
                                    </select>

                                    <div class="absolute top-1/2 end-2.5 -translate-y-1/2">
                                        <svg class="shrink-0 size-3.5 text-stone-500" xmlns="http://www.w3.org/2000/svg"
                                            width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                            stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="m7 15 5 5 5-5" />
                                            <path d="m7 9 5-5 5 5" />
                                        </svg>
                                    </div>
                                </div>
                                <!-- End Select -->

                                <p class="mt-1.5 text-xs text-stone-500 dark:text-neutral-500">
                                    Add this product to a collection so it’s easy to find in your store.
                                </p>
                            </div>
                            <!-- End Input -->

                            <!-- Input -->
                            <div>
                                <label for="hs-pro-dauftg"
                                    class="block mb-2 text-sm font-medium text-stone-800 dark:text-neutral-200">
                                    Tags
                                </label>
                                <input id="hs-pro-dauftg" type="text"
                                    class="py-2 px-3 block w-full border-stone-200 rounded-lg text-sm text-stone-800 placeholder:text-stone-500 focus:z-10 focus:border-green-600 focus:ring-green-600 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200 dark:placeholder:text-neutral-500 dark:focus:outline-none dark:focus:ring-1 dark:focus:ring-neutral-600"
                                    placeholder="Enter tags">
                            </div>
                            <!-- End Input -->
                        </div>
                        <!-- End Body -->
                    </div>
                    <!-- End Organization Card -->
                </div>
            </div>
            <!-- End Col -->
        </div>
        <!-- End Products Grid -->
    </AuthenticatedLayout>
</template>
