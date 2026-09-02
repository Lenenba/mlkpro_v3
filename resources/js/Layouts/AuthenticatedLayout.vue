<script setup>
import { computed, defineAsyncComponent, useSlots } from 'vue';
import Header from '@/Layouts/UI/Header.vue';
import Sidebar from '@/Layouts/UI/Sidebar.vue';
import ValidationSummary from '@/Components/ValidationSummary.vue';
import DemoBanner from '@/Components/Demo/DemoBanner.vue';
import DemoTourProvider from '@/Components/Demo/DemoTourProvider.vue';
import GlobalAssistant from '@/Components/Assistant/GlobalAssistant.vue';
import FlashToaster from '@/Components/UI/FlashToaster.vue';
import AppFooter from '@/Components/UI/AppFooter.vue';
import CookieBanner from '@/Components/UI/CookieBanner.vue';
import { Link, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { resolveWorkspaceBreadcrumbItems } from '@/utils/workspaceBreadcrumbs';

const AppBreadcrumbs = defineAsyncComponent(() => import('@/Components/UI/AppBreadcrumbs.vue'));

const props = defineProps({
    showFooter: {
        type: Boolean,
        default: true,
    },
});

const page = usePage();
const slots = useSlots();
const { t, locale } = useI18n();

const validationErrors = computed(() => page.props.errors || {});
const maintenance = computed(() => page.props.platform?.maintenance || { enabled: false, message: '' });
const impersonator = computed(() => page.props.auth?.impersonator || null);
const isSuperadmin = computed(() => Boolean(page.props.auth?.account?.is_superadmin));
const isClient = computed(() => Boolean(page.props.auth?.account?.is_client));
const hasFloatingAssistant = computed(() => !isClient.value && Boolean(page.props.assistant?.enabled));
const hasCustomBreadcrumb = computed(() => Boolean(slots.breadcrumb));

const autoBreadcrumbItems = computed(() => {
    locale.value;

    return resolveWorkspaceBreadcrumbItems({
        account: page.props.auth?.account,
        planningPendingCount: page.props.planning?.pending_count || 0,
        pageComponent: page.component,
        pageProps: page.props,
        t,
    });
});

const shouldShowAutoBreadcrumbs = computed(() => !hasCustomBreadcrumb.value && autoBreadcrumbItems.value.length > 1);
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

        <div
            class="flex min-h-screen min-w-0 flex-col overflow-x-hidden bg-stone-50 pt-[59px] dark:bg-neutral-950 lg:pt-[59px]"
            :class="isClient
                ? 'w-full lg:ml-16 lg:w-auto'
                : 'w-full lg:ps-16'"
        >
            <!-- ========== MAIN CONTENT ========== -->
            <main id="content" class="flex w-full min-w-0 flex-1 flex-col">
                <div
                    class="flex min-w-0 flex-1 flex-col gap-5"
                    :class="isClient
                        ? 'mx-auto w-full max-w-7xl p-2 sm:p-5'
                        : 'p-2 sm:p-5 sm:py-0 md:pt-5'"
                >
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
                    <slot v-if="hasCustomBreadcrumb" name="breadcrumb" />
                    <AppBreadcrumbs
                        v-else-if="shouldShowAutoBreadcrumbs"
                        :items="autoBreadcrumbItems"
                    />
                    <div v-if="isClient" class="w-full min-w-0">
                        <slot />
                    </div>
                    <slot v-else />
                </div>
            </main>
            <!-- ========== END MAIN CONTENT ========== -->

            <div
                v-if="props.showFooter"
                class="w-full px-2 pb-3 pt-2 sm:px-5 sm:pb-5"
                :class="isClient ? 'mx-auto max-w-7xl' : null"
            >
                <AppFooter
                    :class="isClient ? '!rounded-sm' : null"
                    :floating-action-reserve="hasFloatingAssistant ? 'compact' : 'none'"
                    :variant="isClient ? 'powered-by' : 'platform'"
                />
            </div>
        </div>
        <GlobalAssistant v-if="!isClient" />
        <CookieBanner />
    </DemoTourProvider>
</template>
