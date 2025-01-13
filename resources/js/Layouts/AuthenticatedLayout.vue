<script setup>
import { computed } from 'vue';
import Header from '@/Layouts/UI/Header.vue'; ``
import Sidebar from '@/Layouts/UI/Sidebar.vue';
import { usePage } from '@inertiajs/vue3';

const page = usePage()

const flashSuccess = computed(
    () => page.props.flash.success
)
const flashError = computed(
    () => page.props.flash.error
)
</script>

<template>
    <!-- ========== HEADER ========== -->
    <Header />
    <!-- ========== END HEADER ========== -->

    <!-- ========== MAIN SIDEBAR ========== -->
    <Sidebar />
    <!-- ========== END MAIN SIDEBAR ========== -->

    <!-- ========== MAIN CONTENT ========== -->
    <main id="content" class="lg:ps-16 pt-[59px] lg:pt-0">
        <div class="p-2 sm:p-5 sm:py-0 md:pt-5 space-y-5">
            <div v-if="flashSuccess" class="bg-teal-50 border-t-4 border-teal-500 rounded-sm p-4 dark:bg-teal-800/30"
                role="alert" tabindex="-1" aria-labelledby="hs-bordered-success-style-label">
                <div class="flex">
                    <div class="shrink-0">
                        <!-- Icon -->
                        <span
                            class="inline-flex justify-center items-center size-8 rounded-full border-4 border-teal-100 bg-teal-200 text-teal-800 dark:border-teal-900 dark:bg-teal-800 dark:text-teal-400">
                            <svg class="shrink-0 size-4" xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                stroke-linecap="round" stroke-linejoin="round">
                                <path d="M12 22c5.523 0 10-4.477 10-10S17.523 2 12 2 2 6.477 2 12s4.477 10 10 10z">
                                </path>
                                <path d="m9 12 2 2 4-4"></path>
                            </svg>
                        </span>
                        <!-- End Icon -->
                    </div>
                    <div class="ms-3">
                        <h3 id="hs-bordered-success-style-label" class="text-gray-800 font-semibold dark:text-white">
                            Success.
                        </h3>
                        <p class="text-sm text-gray-700 dark:text-neutral-400">
                            {{ flashSuccess }}
                        </p>
                    </div>
                </div>
            </div>

            <div v-if="flashError" class="bg-red-50 border-s-4 border-red-500 p-4 dark:bg-red-800/30" role="alert"
                tabindex="-1" aria-labelledby="hs-bordered-red-style-label">
                <div class="flex">
                    <div class="shrink-0">
                        <!-- Icon -->
                        <span
                            class="inline-flex justify-center items-center size-8 rounded-full border-4 border-red-100 bg-red-200 text-red-800 dark:border-red-900 dark:bg-red-800 dark:text-red-400">
                            <svg class="shrink-0 size-4" xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                stroke-linecap="round" stroke-linejoin="round">
                                <path d="M18 6 6 18"></path>
                                <path d="m6 6 12 12"></path>
                            </svg>
                        </span>
                        <!-- End Icon -->
                    </div>
                    <div class="ms-3">
                        <h3 id="hs-bordered-red-style-label" class="text-gray-800 font-semibold dark:text-white">
                            Error!
                        </h3>
                        <p class="text-sm text-gray-700 dark:text-neutral-400">
                            {{ flashError }}
                        </p>
                    </div>
                </div>
            </div>
            <slot />
        </div>
    </main>
    <!-- ========== END MAIN CONTENT ========== -->
</template>
