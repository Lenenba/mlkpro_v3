<script setup>
import CardNavLink from './CardNavLink.vue';
import TabEmptyState from './TabEmptyState.vue';
</script>

<template>
    <div
        class="flex flex-col bg-white border shadow-sm rounded-sm dark:bg-neutral-900 dark:border-neutral-700 dark:shadow-neutral-700/70">
        <div
            class="bg-gray-100 border-b rounded-t-sm py-3 px-4 md:py-4 md:px-5 dark:bg-neutral-900 dark:border-neutral-700">
            <p class="mt-1 text-sm text-gray-800 dark:text-neutral-800">
                Overview
            </p>
        </div>
        <!-- Audience -->
        <div
            class="flex flex-col bg-white  rounded-sm overflow-hidden dark:bg-neutral-800 dark:border-neutral-700">
            <!-- Tab Nav -->
            <CardNavLink />
            <!-- End Tab Nav -->

            <!-- Tab Content -->
            <div class="p-5">
                <!-- Tab Content Item -->
                <div id="bar-with-underline-1" role="tabpanel" aria-labelledby="bar-with-underline-item-1">
                    <!-- Empty State -->
                    <TabEmptyState :type="'works'"/>
                    <!-- End Empty State -->
                </div>
                <!-- End Tab Content Item -->

                <!-- Tab Content Item -->
                <div id="bar-with-underline-2" class="hidden" role="tabpanel"
                    aria-labelledby="bar-with-underline-item-2">
                    <!-- Empty State -->
                    <TabEmptyState :type="'requests'" />

                    <!-- End Empty State -->
                </div>
                <!-- End Tab Content Item -->

                <!-- Tab Content Item -->
                <div id="bar-with-underline-3" class="hidden" role="tabpanel"
                    aria-labelledby="bar-with-underline-item-3">
                    <!-- Empty State -->
                    <TabEmptyState :type="'quotes'" />
                    <!-- End Empty State -->
                </div>
                <!-- End Tab Content Item -->

                <!-- Tab Content Item -->
                <div id="bar-with-underline-4" class="hidden" role="tabpanel"
                    aria-labelledby="bar-with-underline-item-4">
                    <TabEmptyState :type="'jobs'" />
                </div>
                <!-- End Tab Content Item -->

                <!-- Tab Content Item -->
                <div id="bar-with-underline-5" class="hidden" role="tabpanel"
                    aria-labelledby="bar-with-underline-item-5">
                    <TabEmptyState :type="'invoices'" />
                </div>
                <!-- End Tab Content Item -->
            </div>
            <!-- End Tab Content -->
        </div>
        <!-- End Audience -->
    </div>

</template>
