<script setup>
const props = defineProps({
    customer: Object,
});
</script>
<template>
    <div
        class="p-5 bg-white border border-stone-200 rounded-sm border-t-4 border-t-teal-800 shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
        <!-- Header -->
        <div class="mb-5 mt-4 flex flex-wrap justify-between items-center gap-3">
            <!-- Heading -->
            <div class="flex items-center gap-x-4">
                <span class="shrink-0 relative size-14">
                    <img :src="customer.logo" alt="" class="absolute inset-0 object-cover w-full h-full rounded-full">
                    <span
                        class="absolute flex shrink-0 justify-center items-center size-6 overflow-hidden -bottom-1 -end-1 bg-white border border-transparent text-gray-800 dark:bg-neutral-700 dark:border-neutral-900 dark:text-neutral-200 rounded-full">
                        <svg class="shrink-0 size-4" xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                            stroke-linecap="round" stroke-linejoin="round">
                            <path d="M17 7 7 17" />
                            <path d="M17 17H7V7" />
                        </svg>
                    </span>
                </span>

                <div class="grow">
                    <p class="text-2xl md:text-3xl font-semibold text-gray-800 dark:text-white">
                        {{ customer.company_name }}
                    </p>
                </div>
            </div>
            <!-- End Heading -->

            <!-- Button Group -->
            <div class="flex flex-wrap gap-2">

                <!-- Button -->
                <a class="py-2 px-3 inline-flex items-center gap-x-1.5 text-sm font-medium rounded-xl border border-gray-200 bg-white text-gray-800 shadow-sm hover:bg-gray-50 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-gray-50 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800"
                    href="../../pro/payment/request.html">
                    Edit
                </a>
                <!-- End Button -->

                <!-- Button -->
                <button type="button"
                    class="py-1.5 px-2.5 inline-flex items-center gap-x-1.5 text-sm font-medium rounded-xl border border-gray-200 bg-white text-red-800 shadow-sm hover:bg-gray-50 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-gray-50 dark:bg-neutral-900 dark:border-neutral-700 dark:text-red-500 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800">
                    Cancel request
                </button>
                <!-- End Button -->
            </div>
            <!-- End Button Group -->
        </div>
        <!-- End Header -->
    </div>
</template>
