<script setup>
import { computed } from 'vue';
import Header from '@/Layouts/UI/Header.vue';
import Sidebar from '@/Layouts/UI/Sidebar.vue';
import { Link, usePage } from '@inertiajs/vue3';

const page = usePage()

const flashSuccess = computed(
    () => page.props.flash?.success
)
const flashError = computed(
    () => page.props.flash?.error
)
const flashWarning = computed(
    () => page.props.flash?.warning
)
const maintenance = computed(() => page.props.platform?.maintenance || { enabled: false, message: '' });
const impersonator = computed(() => page.props.auth?.impersonator || null);
const isSuperadmin = computed(() => Boolean(page.props.auth?.account?.is_superadmin));
</script>

<template>
    <!-- ========== HEADER ========== -->
    <Header />
    <!-- ========== END HEADER ========== -->

    <!-- ========== MAIN SIDEBAR ========== -->
    <Sidebar />
    <!-- ========== END MAIN SIDEBAR ========== -->

    <!-- ========== MAIN CONTENT ========== -->
    <main id="content" class="lg:ps-16 pt-[59px] lg:pt-0 min-h-screen bg-stone-50 dark:bg-neutral-950">
        <div class="p-2 sm:p-5 sm:py-0 md:pt-5 space-y-5">
            <div v-if="maintenance.enabled && !isSuperadmin"
                class="bg-amber-50 border-s-4 border-amber-500 p-4 dark:bg-amber-800/30" role="alert" tabindex="-1"
                aria-labelledby="hs-platform-maintenance-label">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h3 id="hs-platform-maintenance-label" class="text-stone-800 font-semibold dark:text-white">
                            {{ $t('alerts.maintenance.title') }}
                        </h3>
                        <p class="text-sm text-stone-700 dark:text-neutral-400">
                            {{ maintenance.message || $t('alerts.maintenance.message') }}
                        </p>
                    </div>
                </div>
            </div>

            <div v-if="impersonator"
                class="bg-blue-50 border-s-4 border-blue-500 p-4 dark:bg-blue-800/30" role="alert" tabindex="-1"
                aria-labelledby="hs-platform-impersonation-label">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h3 id="hs-platform-impersonation-label" class="text-stone-800 font-semibold dark:text-white">
                            {{ $t('alerts.impersonation.title') }}
                        </h3>
                        <p class="text-sm text-stone-700 dark:text-neutral-400">
                            {{ $t('alerts.impersonation.message', { name: impersonator.name || impersonator.email }) }}
                        </p>
                    </div>
                    <Link :href="route('superadmin.impersonate.stop')" method="post" as="button" type="button"
                        class="py-2 px-3 text-sm font-medium rounded-sm border border-transparent bg-blue-600 text-white hover:bg-blue-700">
                        {{ $t('actions.stop_impersonation') }}
                    </Link>
                </div>
            </div>
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
                        <h3 id="hs-bordered-success-style-label" class="text-stone-800 font-semibold dark:text-white">
                            {{ $t('alerts.success.title') }}
                        </h3>
                        <p class="text-sm text-stone-700 dark:text-neutral-400">
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
                        <h3 id="hs-bordered-red-style-label" class="text-stone-800 font-semibold dark:text-white">
                            {{ $t('alerts.error.title') }}
                        </h3>
                        <p class="text-sm text-stone-700 dark:text-neutral-400">
                            {{ flashError }}
                        </p>
                    </div>
                </div>
            </div>

            <div v-if="flashWarning" class="bg-amber-50 border-s-4 border-amber-500 p-4 dark:bg-amber-800/30"
                role="alert" tabindex="-1" aria-labelledby="hs-bordered-warning-style-label">
                <div class="flex">
                    <div class="shrink-0">
                        <span
                            class="inline-flex justify-center items-center size-8 rounded-full border-4 border-amber-100 bg-amber-200 text-amber-800 dark:border-amber-900 dark:bg-amber-800 dark:text-amber-400">
                            <svg class="shrink-0 size-4" xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                stroke-linecap="round" stroke-linejoin="round">
                                <path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"></path>
                                <path d="M12 9v4"></path>
                                <path d="M12 17h.01"></path>
                            </svg>
                        </span>
                    </div>
                    <div class="ms-3">
                        <h3 id="hs-bordered-warning-style-label" class="text-stone-800 font-semibold dark:text-white">
                            {{ $t('alerts.warning.title') }}
                        </h3>
                        <p class="text-sm text-stone-700 dark:text-neutral-400">
                            {{ flashWarning }}
                        </p>
                    </div>
                </div>
            </div>
            <slot />
        </div>
    </main>
    <!-- ========== END MAIN CONTENT ========== -->
</template>
