<script setup>
const props = defineProps({
    items: Array,
});
</script>

<template>
    <!-- List Group -->
    <ul class="flex flex-col divide-y divide-gray-200 dark:divide-neutral-700">
        <!-- List Item -->
        <li v-for="item in items" :key="item.id" class="p-4 border-t border-gray-200 first:border-t-0 dark:border-neutral-700">
            <!-- Media -->
            <div class="flex gap-x-3">
                <!-- Logo -->
                <div>
                    <div class="py-2.5 px-3 border rounded-sm dark:border-neutral-700">
                        <slot name="logo" />
                    </div>
                </div>
                <!-- End Logo -->

                <!-- Body -->
                <div class="grow flex flex-col sm:flex-row sm:justify-between gap-y-2 sm:gap-x-3">
                    <div>
                        <p class="text-sm font-medium text-gray-800 dark:text-neutral-200">
                            {{ item.name }}
                        </p>
                        <p class="text-xs text-gray-500 dark:text-neutral-500">
                            {{ item.description }}
                        </p>
                        <p class="text-xs text-gray-500 dark:text-neutral-500">
                            {{ item.description2 }}
                        </p>
                    </div>

                    <!-- Button Group -->
                    <div class="flex gap-x-2">
                        <div>
                            <button type="button"
                                class="py-2 px-2.5 inline-flex items-center gap-x-2 text-xs font-medium rounded-sm border border-gray-200 bg-white text-gray-800 shadow-sm hover:bg-gray-50 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-gray-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-700 dark:focus:bg-neutral-700"
                                disabled>
                                Set as default
                            </button>
                        </div>
                        <div>
                            <button type="button"
                                class="py-2 px-2.5 inline-flex items-center gap-x-2 text-xs font-semibold rounded-sm border border-transparent bg-gray-200 text-gray-800 hover:bg-gray-300 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-gray-300 dark:bg-neutral-600 dark:text-neutral-200 dark:hover:bg-neutral-500 dark:focus:bg-neutral-700"
                                data-hs-overlay="#hs-pro-deacm">
                                Edit
                            </button>
                        </div>
                    </div>
                    <!-- End Button Group -->
                </div>
                <!-- End Body -->
            </div>
            <!-- End Media -->
        </li>
        <!-- End List Item -->
    </ul>
    <!-- End List Group -->
</template>
