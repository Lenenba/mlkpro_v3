<script setup>
import { computed } from 'vue';
import Header from '@/Layouts/UI/Header.vue';
import Sidebar from '@/Layouts/UI/Sidebar.vue';
import ValidationSummary from '@/Components/ValidationSummary.vue';
import DemoBanner from '@/Components/Demo/DemoBanner.vue';
import DemoTourProvider from '@/Components/Demo/DemoTourProvider.vue';
import GlobalAssistant from '@/Components/Assistant/GlobalAssistant.vue';
import FlashToaster from '@/Components/UI/FlashToaster.vue';
import CookieBanner from '@/Components/UI/CookieBanner.vue';
import { Link, usePage } from '@inertiajs/vue3';

const page = usePage()

const validationErrors = computed(() => page.props.errors || {});
const maintenance = computed(() => page.props.platform?.maintenance || { enabled: false, message: '' });
const impersonator = computed(() => page.props.auth?.impersonator || null);
const isSuperadmin = computed(() => Boolean(page.props.auth?.account?.is_superadmin));
const isClient = computed(() => Boolean(page.props.auth?.account?.is_client));
</script>

<template>
    <DemoTourProvider>
        <!-- ========== HEADER ========== -->
        <Header />
        <!-- ========== END HEADER ========== -->

        <!-- ========== MAIN SIDEBAR ========== -->
        <Sidebar />
        <!-- ========== END MAIN SIDEBAR ========== -->

        <FlashToaster />

        <!-- ========== MAIN CONTENT ========== -->
        <main id="content" class="lg:ps-16 pt-[59px] lg:pt-[59px] min-h-screen w-full min-w-0 overflow-x-hidden bg-stone-50 dark:bg-neutral-950">
            <div class="p-2 sm:p-5 sm:py-0 md:pt-5 space-y-5 min-w-0">
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

                <DemoBanner />

                <ValidationSummary :errors="validationErrors" />
                <slot />
            </div>
        </main>
        <!-- ========== END MAIN CONTENT ========== -->
        <GlobalAssistant v-if="!isClient" />
        <CookieBanner />
    </DemoTourProvider>
</template>
