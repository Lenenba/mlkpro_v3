<script setup>
import { computed } from 'vue';
import ApplicationLogo from "@/Components/ApplicationLogo.vue";
import LinkAncor from "@/Components/UI/LinkAncor.vue";
import { usePage } from '@inertiajs/vue3';
import MenuDropdown from "@/Components/UI/LinkAncor2.vue";
import LanguageSwitcherMenu from '@/Components/UI/LanguageSwitcherMenu.vue';
import QuickCreateModals from "@/Components/QuickCreate/QuickCreateModals.vue";
import CategoryIcon from '@/Components/Workspace/CategoryIcon.vue';
import { buildWorkspaceHubCategories } from '@/utils/workspaceHub';
import { useAccountFeatures } from '@/Composables/useAccountFeatures';

const page = usePage()
const companyType = computed(() => page.props.auth?.account?.company?.type ?? null);
const showServices = computed(() => companyType.value !== 'products');
const showProducts = computed(() => true);
const isOwner = computed(() => Boolean(page.props.auth?.account?.is_owner));
const isClient = computed(() => Boolean(page.props.auth?.account?.is_client));
const isSuperadmin = computed(() => Boolean(page.props.auth?.account?.is_superadmin));
const isPlatformAdmin = computed(() => Boolean(page.props.auth?.account?.is_platform_admin));
const platformPermissions = computed(() => page.props.auth?.account?.platform?.permissions || []);
const teamPermissions = computed(() => page.props.auth?.account?.team?.permissions || []);
const teamRole = computed(() => page.props.auth?.account?.team?.role || null);
const isTeamMember = computed(() => Boolean(teamRole.value));
const { hasFeature } = useAccountFeatures();
const showPlatformNav = computed(() => isSuperadmin.value || isPlatformAdmin.value);
const canPlatform = (permission) => isSuperadmin.value || platformPermissions.value.includes(permission);
const homeRoute = computed(() => (showPlatformNav.value ? 'superadmin.dashboard' : 'dashboard'));
const canSales = computed(() =>
    isOwner.value || teamPermissions.value.includes('sales.manage') || teamPermissions.value.includes('sales.pos')
);
const canSalesManage = computed(() =>
    isOwner.value || teamPermissions.value.includes('sales.manage')
);
const canJobs = computed(() =>
    isOwner.value
    || teamPermissions.value.includes('jobs.view')
    || teamPermissions.value.includes('jobs.edit')
);
const canTasks = computed(() =>
    isOwner.value
    || teamPermissions.value.includes('tasks.view')
    || teamPermissions.value.includes('tasks.create')
    || teamPermissions.value.includes('tasks.edit')
    || teamPermissions.value.includes('tasks.delete')
);
const canService = computed(() =>
    isOwner.value
    || teamPermissions.value.includes('jobs.view')
    || teamPermissions.value.includes('tasks.view')
    || teamPermissions.value.includes('jobs.edit')
    || teamPermissions.value.includes('tasks.edit')
);
const canServiceManage = computed(() =>
    isOwner.value
    || teamPermissions.value.includes('jobs.edit')
    || teamPermissions.value.includes('tasks.edit')
);
const canLoyaltyManage = computed(() =>
    isOwner.value
    || canSalesManage.value
    || canServiceManage.value
);
const canCampaigns = computed(() =>
    isOwner.value
    || teamPermissions.value.includes('campaigns.view')
    || teamPermissions.value.includes('campaigns.manage')
    || teamPermissions.value.includes('campaigns.send')
);
const canReservations = computed(() =>
    isOwner.value
    || teamPermissions.value.includes('reservations.view')
    || teamPermissions.value.includes('reservations.queue')
    || teamPermissions.value.includes('reservations.manage')
);
const canAiAssistant = computed(() =>
    isOwner.value
    || teamPermissions.value.includes('reservations.manage')
);
const hasServiceOps = computed(() =>
    showServices.value && (hasFeature('jobs') || hasFeature('tasks'))
);
const canQuotes = computed(() =>
    isOwner.value || teamPermissions.value.includes('quotes.view') || teamPermissions.value.includes('quotes.edit')
);
const canPromotionsManage = computed(() =>
    isOwner.value
    || teamPermissions.value.includes('sales.manage')
    || teamPermissions.value.includes('quotes.edit')
    || teamPermissions.value.includes('jobs.edit')
    || teamPermissions.value.includes('tasks.edit')
    || teamPermissions.value.includes('campaigns.manage')
);
const canOfferPackages = computed(() =>
    !isClient.value
    && !isSeller.value
    && (isOwner.value || canSalesManage.value || canServiceManage.value)
    && (hasFeature('products') || hasFeature('services') || hasFeature('sales'))
);
const canExpensesNav = computed(() =>
    isOwner.value
    || teamPermissions.value.includes('expenses.view')
    || teamPermissions.value.includes('expenses.create')
    || teamPermissions.value.includes('expenses.edit')
    || teamPermissions.value.includes('expenses.approve')
    || teamPermissions.value.includes('expenses.approve_high')
    || teamPermissions.value.includes('expenses.pay')
);
const canAccountingNav = computed(() =>
    isOwner.value
    || teamPermissions.value.includes('accounting.view')
);
const canInvoicesNav = computed(() =>
    isOwner.value
    || teamPermissions.value.includes('invoices.view')
    || teamPermissions.value.includes('invoices.create')
    || teamPermissions.value.includes('invoices.edit')
    || teamPermissions.value.includes('invoices.approve')
    || teamPermissions.value.includes('invoices.approve_high')
);
const isSeller = computed(() => teamRole.value === 'seller');
const planningPendingCount = computed(() => page.props.planning?.pending_count || 0);
const workspaceHubCategories = computed(() => buildWorkspaceHubCategories({
    account: page.props.auth?.account,
    planningPendingCount: planningPendingCount.value,
}).filter((category) => category.visible));
const useWorkspaceHubNav = computed(() => !showPlatformNav.value && !isClient.value);
const showQuickCreateMenu = computed(() => !showPlatformNav.value && !isClient.value && !isSeller.value);
const isWorkspaceHubCategoryActive = (category) => (
    page.url.startsWith(`/workspace-hubs/${category.key}`)
    || (category.match || []).some((pattern) => route().current(pattern))
);
const menuIconBaseClass = 'relative inline-flex size-9 items-center justify-center rounded-sm hover:bg-stone-100 focus:outline-none focus:ring-2 dark:hover:bg-neutral-800';
const languageButtonClass = `${menuIconBaseClass} text-sky-600 focus:ring-sky-500 dark:text-sky-400`;
const isCustomerActive = computed(() => {
    const isCustomerRoute = route().current('customer.*')
        || page.url.startsWith('/customer')
        || page.url.startsWith('/customers');
    const isQuoteRoute = route().current('customer.quote.*')
        || page.url.startsWith('/customer/quote');

    return isCustomerRoute && !isQuoteRoute;
});

</script>

<template>
    <aside class="relative">
        <div id="hs-pro-sidebar" class="hs-overlay [--auto-close:lg]
            hs-overlay-open:translate-x-0
            -translate-x-full transition-all duration-300 transform
            w-16 h-full
            hidden
            fixed inset-y-0 start-0 z-[60]
            bg-white border-r border-stone-200
            lg:block lg:translate-x-0 lg:end-auto lg:bottom-0
            dark:bg-neutral-950 dark:border-neutral-800" tabindex="-1" aria-label="Compact Sidebar">
            <div class="h-full flex">
                <div class="relative z-10 w-16 flex flex-col h-full max-h-full pb-5">
                    <header class="w-16 py-2.5 flex justify-center shrink-0">
                        <a class="flex-none rounded-sm text-xl inline-block font-semibold focus:outline-none focus:opacity-80"
                            :href="route(homeRoute)" aria-label="Preline">
                            <ApplicationLogo class="w-[4rem] h-[4rem] p-1" />
                        </a>
                    </header>

                    <!-- Content -->
                    <div class="w-16 flex-1 min-h-0 flex flex-col">
                        <div class="mb-3 flex shrink-0 flex-col items-center gap-3 border-b border-stone-100 pb-3 dark:border-neutral-800">
                            <LanguageSwitcherMenu :button-class="languageButtonClass" :icon-class="'size-6'" />

                            <MenuDropdown v-if="showQuickCreateMenu" active-item="/profile">
                                <template #toggle-icon>
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                        viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                        stroke-linecap="round" stroke-linejoin="round"
                                        class="lucide lucide-circle-plus text-emerald-600 dark:text-emerald-400">
                                        <circle cx="12" cy="12" r="10" />
                                        <path d="M8 12h8" />
                                        <path d="M12 8v8" />
                                    </svg>
                                </template>
                            </MenuDropdown>
                        </div>

                        <!-- Nav -->
                        <nav class="flex-1 overflow-y-auto">
                            <ul class="text-center space-y-3 pb-2">
                                <template v-if="showPlatformNav">
                                    <LinkAncor v-if="isSuperadmin" :label="$t('nav.dashboard')" :href="'superadmin.dashboard'" tone="dashboard"
                                        :active="route().current('superadmin.dashboard')">
                                        <template #icon>
                                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                                stroke-linecap="round" stroke-linejoin="round"
                                                class="lucide lucide-layout-dashboard">
                                                <rect width="7" height="9" x="3" y="3" rx="1" />
                                                <rect width="7" height="5" x="14" y="3" rx="1" />
                                                <rect width="7" height="9" x="14" y="12" rx="1" />
                                                <rect width="7" height="5" x="3" y="16" rx="1" />
                                            </svg>
                                        </template>
                                    </LinkAncor>
                                    <LinkAncor v-else-if="canPlatform('analytics.view')" :label="$t('nav.admin')" :href="'superadmin.dashboard'" tone="admin"
                                        :active="route().current('superadmin.dashboard')">
                                        <template #icon>
                                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                                stroke-linecap="round" stroke-linejoin="round"
                                                class="lucide lucide-shield">
                                                <path d="M12 2 3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5z" />
                                            </svg>
                                        </template>
                                    </LinkAncor>

                                    <LinkAncor v-if="canPlatform('tenants.view')" :label="$t('nav.tenants')" :href="'superadmin.tenants.index'" tone="tenants"
                                        :active="route().current('superadmin.tenants.*')">
                                        <template #icon>
                                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                                stroke-linecap="round" stroke-linejoin="round"
                                                class="lucide lucide-building-2">
                                                <path d="M6 22V2l6 4 6-4v20" />
                                                <path d="M6 12h12" />
                                                <path d="M6 18h12" />
                                                <path d="M6 6h12" />
                                            </svg>
                                        </template>
                                    </LinkAncor>

                                    <LinkAncor v-if="canPlatform('demos.manage')" :label="$t('nav.demo_workspaces')" :href="'superadmin.demo-workspaces.index'" tone="support"
                                        :active="route().current('superadmin.demo-workspaces.*')">
                                        <template #icon>
                                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                                stroke-linecap="round" stroke-linejoin="round"
                                                class="lucide lucide-projector">
                                                <path d="M5 7 3 5" />
                                                <path d="M9 6V3" />
                                                <path d="m13 7 2-2" />
                                                <circle cx="9" cy="13" r="3" />
                                                <path d="M11.83 12H20a2 2 0 0 1 2 2v4a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2v-4a2 2 0 0 1 2-2h2.17" />
                                                <path d="M16 16h2" />
                                            </svg>
                                        </template>
                                    </LinkAncor>

                                    <LinkAncor v-if="isSuperadmin" :label="$t('nav.plans')" :href="'superadmin.settings.edit'" tone="settings"
                                        :active="route().current('superadmin.settings.*')">
                                        <template #icon>
                                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                                stroke-linecap="round" stroke-linejoin="round"
                                                class="lucide lucide-tag">
                                                <path d="M20.59 13.41 11 3H4v7l9.59 9.59a2 2 0 0 0 2.82 0l4.18-4.18a2 2 0 0 0 0-2.82Z"/>
                                                <circle cx="7.5" cy="7.5" r="0.5"/>
                                            </svg>
                                        </template>
                                    </LinkAncor>

                                    <LinkAncor v-if="canPlatform('support.manage')" :label="$t('nav.support')" :href="'superadmin.support.index'" tone="support"
                                        :active="route().current('superadmin.support.*')">
                                        <template #icon>
                                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                                stroke-linecap="round" stroke-linejoin="round"
                                                class="lucide lucide-life-buoy">
                                                <circle cx="12" cy="12" r="10" />
                                                <circle cx="12" cy="12" r="4" />
                                                <line x1="4.93" x2="9.17" y1="4.93" y2="9.17" />
                                                <line x1="14.83" x2="19.07" y1="14.83" y2="19.07" />
                                                <line x1="14.83" x2="19.07" y1="9.17" y2="4.93" />
                                                <line x1="14.83" x2="18.36" y1="9.17" y2="5.64" />
                                            </svg>
                                        </template>
                                    </LinkAncor>

                                    <LinkAncor v-if="canPlatform('admins.manage')" :label="$t('nav.admins')" :href="'superadmin.admins.index'" tone="admins"
                                        :active="route().current('superadmin.admins.*')">
                                        <template #icon>
                                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                                stroke-linecap="round" stroke-linejoin="round"
                                                class="lucide lucide-users-2">
                                                <path d="M14 19a6 6 0 0 0-12 0" />
                                                <circle cx="8" cy="9" r="4" />
                                                <path d="M22 20a6 6 0 0 0-4-5.65" />
                                                <path d="M16 3.13a4 4 0 0 1 0 7.75" />
                                            </svg>
                                        </template>
                                    </LinkAncor>

                                    <LinkAncor v-if="canPlatform('notifications.manage')" :label="$t('nav.notifications')" :href="'superadmin.notifications.edit'" tone="notifications"
                                        :active="route().current('superadmin.notifications.*')">
                                        <template #icon>
                                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                                stroke-linecap="round" stroke-linejoin="round"
                                                class="lucide lucide-bell">
                                                <path d="M10 5a2 2 0 1 1 4 0" />
                                                <path d="M6 8a6 6 0 0 1 12 0c0 7 3 7 3 7H3s3 0 3-7" />
                                                <path d="M10 21a2 2 0 0 0 4 0" />
                                            </svg>
                                        </template>
                                    </LinkAncor>

                                    <LinkAncor v-if="canPlatform('announcements.manage')" :label="$t('nav.announcements')" :href="'superadmin.announcements.index'" tone="announcements"
                                        :active="route().current('superadmin.announcements.*')">
                                        <template #icon>
                                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                                stroke-linecap="round" stroke-linejoin="round"
                                                class="lucide lucide-megaphone">
                                                <path d="M3 11h2l8-4v10l-8-4H3z" />
                                                <path d="M11 19a3 3 0 0 1-3-3v-2" />
                                                <path d="M19 9a4 4 0 0 1 0 6" />
                                            </svg>
                                        </template>
                                    </LinkAncor>

                                    <LinkAncor v-if="canPlatform('pages.manage') || canPlatform('welcome.manage')" :label="$t('nav.pages')" :href="'superadmin.pages.index'" tone="pages"
                                        :active="route().current('superadmin.pages.*')">
                                        <template #icon>
                                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                                stroke-linecap="round" stroke-linejoin="round"
                                                class="lucide lucide-file-text">
                                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
                                                <path d="M14 2v6h6" />
                                                <path d="M16 13H8" />
                                                <path d="M16 17H8" />
                                                <path d="M10 9H8" />
                                            </svg>
                                        </template>
                                    </LinkAncor>

                                    <LinkAncor v-if="canPlatform('pages.manage') || canPlatform('welcome.manage')" :label="$t('nav.sections')" :href="'superadmin.sections.index'" tone="sections"
                                        :active="route().current('superadmin.sections.*')">
                                        <template #icon>
                                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                                stroke-linecap="round" stroke-linejoin="round"
                                                class="lucide lucide-layout-grid">
                                                <rect width="7" height="7" x="3" y="3" rx="1" />
                                                <rect width="7" height="7" x="14" y="3" rx="1" />
                                                <rect width="7" height="7" x="3" y="14" rx="1" />
                                                <rect width="7" height="7" x="14" y="14" rx="1" />
                                            </svg>
                                        </template>
                                    </LinkAncor>

                                    <LinkAncor v-if="canPlatform('mega_menus.manage')" label="Mega Menus" :href="'superadmin.mega-menus.index'" tone="pages"
                                        :active="route().current('superadmin.mega-menus.*')">
                                        <template #icon>
                                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                                stroke-linecap="round" stroke-linejoin="round"
                                                class="lucide lucide-panel-top">
                                                <path d="M3 8h18" />
                                                <path d="M20 3H4a1 1 0 0 0-1 1v16a1 1 0 0 0 1 1h16a1 1 0 0 0 1-1V4a1 1 0 0 0-1-1Z" />
                                            </svg>
                                        </template>
                                    </LinkAncor>

                                    <LinkAncor v-if="canPlatform('pages.manage') || canPlatform('welcome.manage')" :label="$t('nav.assets')" :href="'superadmin.assets.index'" tone="assets"
                                        :active="route().current('superadmin.assets.*')">
                                        <template #icon>
                                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                                stroke-linecap="round" stroke-linejoin="round"
                                                class="lucide lucide-image">
                                                <rect width="18" height="14" x="3" y="5" rx="2" />
                                                <circle cx="8" cy="10" r="2" />
                                                <path d="m21 15-5-5L5 21" />
                                            </svg>
                                        </template>
                                    </LinkAncor>

                                    <LinkAncor v-if="canPlatform('settings.manage')" :label="$t('nav.settings')" :href="'superadmin.settings.edit'" tone="settings"
                                        :active="route().current('superadmin.settings.*')">
                                        <template #icon>
                                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                                stroke-linecap="round" stroke-linejoin="round"
                                                class="lucide lucide-settings">
                                                <path d="M12 1v2" />
                                                <path d="M12 21v2" />
                                                <path d="M4.22 4.22l1.42 1.42" />
                                                <path d="M18.36 18.36l1.42 1.42" />
                                                <path d="M1 12h2" />
                                                <path d="M21 12h2" />
                                                <path d="M4.22 19.78l1.42-1.42" />
                                                <path d="M18.36 5.64l1.42-1.42" />
                                                <circle cx="12" cy="12" r="3" />
                                            </svg>
                                        </template>
                                    </LinkAncor>
                                </template>
                                <template v-else>
                                <!-- Item -->
                                <LinkAncor :label="$t('nav.dashboard')" :href="'dashboard'" tone="dashboard"
                                    :active="route().current('dashboard')">
                                    <template #icon>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                            stroke-linecap="round" stroke-linejoin="round"
                                            class="lucide lucide-layout-dashboard">
                                            <rect width="7" height="9" x="3" y="3" rx="1" />
                                            <rect width="7" height="5" x="14" y="3" rx="1" />
                                            <rect width="7" height="9" x="14" y="12" rx="1" />
                                            <rect width="7" height="5" x="3" y="16" rx="1" />
                                        </svg>
                                    </template>
                                </LinkAncor>
                                <!-- End Item -->

                                <!-- Item -->
                                <LinkAncor v-if="isClient && companyType === 'products'" :label="$t('nav.orders')" :href="'portal.orders.index'" tone="orders"
                                    :active="route().current('portal.orders.*')">
                                    <template #icon>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                            stroke-linecap="round" stroke-linejoin="round"
                                            class="lucide lucide-shopping-bag">
                                            <path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z" />
                                            <path d="M3 6h18" />
                                            <path d="M16 10a4 4 0 0 1-8 0" />
                                        </svg>
                                    </template>
                                </LinkAncor>
                                <!-- End Item -->

                                <!-- Item -->
                                <LinkAncor
                                    v-if="isClient && hasFeature('loyalty')"
                                    :label="$t('nav.loyalty')"
                                    :href="'portal.loyalty.index'"
                                    tone="loyalty"
                                    :active="route().current('portal.loyalty.*')"
                                >
                                    <template #icon>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                            stroke-linecap="round" stroke-linejoin="round"
                                            class="lucide lucide-award">
                                            <circle cx="12" cy="8" r="6" />
                                            <path d="M15.477 12.89 17 22l-5-3-5 3 1.523-9.11" />
                                        </svg>
                                    </template>
                                </LinkAncor>
                                <!-- End Item -->

                                <!-- Item -->
                                <LinkAncor
                                    v-if="isClient"
                                    :label="$t('nav.my_packages')"
                                    :href="'portal.packages.index'"
                                    tone="products"
                                    :active="route().current('portal.packages.*')"
                                >
                                    <template #icon>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                            stroke-linecap="round" stroke-linejoin="round"
                                            class="lucide lucide-package-check">
                                            <path d="m16 16 2 2 4-4" />
                                            <path d="M21 10V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l2-1.14" />
                                            <path d="m7.5 4.27 9 5.15" />
                                            <polyline points="3.29 7 12 12 20.71 7" />
                                            <line x1="12" x2="12" y1="22" y2="12" />
                                        </svg>
                                    </template>
                                </LinkAncor>
                                <!-- End Item -->

                                <!-- Item -->
                                <LinkAncor
                                    v-if="isClient && showServices && hasFeature('reservations')"
                                    :label="$t('nav.book_reservation')"
                                    :href="'client.reservations.book'"
                                    tone="planning"
                                    :active="route().current('client.reservations.book')"
                                >
                                    <template #icon>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                            stroke-linecap="round" stroke-linejoin="round"
                                            class="lucide lucide-calendar-plus-2">
                                            <path d="M8 2v4" />
                                            <path d="M16 2v4" />
                                            <path d="M3 10h18" />
                                            <path d="M21 9v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V9a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2Z" />
                                            <path d="M12 15v6" />
                                            <path d="M9 18h6" />
                                        </svg>
                                    </template>
                                </LinkAncor>
                                <!-- End Item -->

                                <!-- Item -->
                                <LinkAncor
                                    v-if="isClient && showServices && hasFeature('reservations')"
                                    :label="$t('nav.my_reservations')"
                                    :href="'client.reservations.index'"
                                    tone="planning"
                                    :active="route().current('client.reservations.*') && !route().current('client.reservations.book')"
                                >
                                    <template #icon>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                            stroke-linecap="round" stroke-linejoin="round"
                                            class="lucide lucide-calendar-check-2">
                                            <path d="M8 2v4" />
                                            <path d="M16 2v4" />
                                            <path d="M3 10h18" />
                                            <path d="M21 9v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V9a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2Z" />
                                            <path d="m9 16 2 2 4-4" />
                                        </svg>
                                    </template>
                                </LinkAncor>
                                <!-- End Item -->

                                <template v-if="useWorkspaceHubNav">
                                    <LinkAncor
                                        v-for="category in workspaceHubCategories"
                                        :key="category.key"
                                        :label="$t(category.labelKey)"
                                        :href="category.routeName"
                                        :params="category.routeParams"
                                        :tone="category.tone"
                                        :active="isWorkspaceHubCategoryActive(category)"
                                    >
                                        <template #icon>
                                            <CategoryIcon :name="category.icon" icon-class="size-6" />
                                        </template>
                                    </LinkAncor>
                                </template>
                                <template v-else>
                                <!-- Item -->
                                <LinkAncor v-if="((showServices && isOwner) || (companyType === 'products' && hasFeature('sales') && canSales)) && !isSeller" :label="$t('nav.customers')" :href="'customer.index'" tone="customers"
                                    :active="isCustomerActive">
                                    <template #icon>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-contact"><path d="M16 2v2"/><path d="M7 22v-2a2 2 0 0 1 2-2h6a2 2 0 0 1 2 2v2"/><path d="M8 2v2"/><circle cx="12" cy="11" r="3"/><rect x="3" y="4" width="18" height="18" rx="2"/></svg>
                                    </template>
                                </LinkAncor>
                                <!-- End Item -->

                                <!-- Item -->
                                <LinkAncor v-if="showProducts && hasFeature('products') && (isOwner || canSales) && !isSeller" :label="$t('nav.products')" :href="'product.index'" tone="products"
                                    :active="route().current('product.*')">
                                    <template #icon>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                            stroke-linecap="round" stroke-linejoin="round"
                                            class="lucide lucide-package-search">
                                            <path
                                                d="M21 10V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l2-1.14" />
                                            <path d="m7.5 4.27 9 5.15" />
                                            <polyline points="3.29 7 12 12 20.71 7" />
                                            <line x1="12" x2="12" y1="22" y2="12" />
                                            <circle cx="18.5" cy="15.5" r="2.5" />
                                            <path d="M20.27 17.27 22 19" />
                                        </svg>
                                    </template>
                                </LinkAncor>
                                <!-- End Item -->

                                <!-- Item -->
                                <LinkAncor v-if="canOfferPackages" :label="$t('nav.offer_packages')" :href="'offer-packages.index'" tone="products"
                                    :active="route().current('offer-packages.*')">
                                    <template #icon>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                            stroke-linecap="round" stroke-linejoin="round"
                                            class="lucide lucide-package-plus">
                                            <path d="M16 16h6" />
                                            <path d="M19 13v6" />
                                            <path d="M21 10V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l2-1.14" />
                                            <path d="m7.5 4.27 9 5.15" />
                                            <polyline points="3.29 7 12 12 20.71 7" />
                                            <line x1="12" x2="12" y1="22" y2="12" />
                                        </svg>
                                    </template>
                                </LinkAncor>
                                <!-- End Item -->

                                <!-- Item -->
                                <LinkAncor v-if="companyType === 'products' && hasFeature('sales') && canSales" :label="$t('nav.orders')" :href="'orders.index'" tone="orders"
                                    :active="route().current('orders.*')">
                                    <template #icon>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                            stroke-linecap="round" stroke-linejoin="round"
                                            class="lucide lucide-clipboard-list">
                                            <path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2" />
                                            <rect x="8" y="2" width="8" height="4" rx="1" />
                                            <path d="M9 12h6" />
                                            <path d="M9 16h6" />
                                            <path d="M9 8h6" />
                                        </svg>
                                    </template>
                                </LinkAncor>
                                <!-- End Item -->

                                <!-- Item -->
                                <LinkAncor v-if="companyType === 'products' && hasFeature('sales') && canSales" :label="$t('nav.sales')" :href="isSeller ? 'sales.create' : 'sales.index'" tone="sales"
                                    :active="route().current('sales.*')">
                                    <template #icon>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                            stroke-linecap="round" stroke-linejoin="round"
                                            class="lucide lucide-shopping-cart">
                                            <circle cx="8" cy="21" r="1" />
                                            <circle cx="19" cy="21" r="1" />
                                            <path d="M2.05 2.05h2l2.6 12.4a2 2 0 0 0 2 1.6h9.6a2 2 0 0 0 2-1.6l1.2-6.4H6.2" />
                                        </svg>
                                    </template>
                                </LinkAncor>
                                <!-- End Item -->
                                <!-- Item -->
                                <LinkAncor
                                    v-if="hasFeature('promotions') && canPromotionsManage && !isClient && !isSeller"
                                    :label="$t('nav.promotions')"
                                    :href="'promotions.index'"
                                    tone="sales"
                                    :active="route().current('promotions.*')"
                                >
                                    <template #icon>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                            stroke-linecap="round" stroke-linejoin="round"
                                            class="lucide lucide-badge-percent">
                                            <path d="M16 16 8 8" />
                                            <path d="M7 16h.01" />
                                            <path d="M17 8h.01" />
                                            <path d="M21 12c0 1.6-1.4 2.7-2 4-.7 1.4-.4 3.4-1.7 4.3-1.3.9-3.2.2-4.8.7-1.5.5-2.7 2-4.4 1.6-1.6-.3-2.4-2.2-3.6-3.2-1.2-1-3.3-1.3-3.9-2.8-.6-1.5.6-3.2.7-4.8.1-1.6-.8-3.6 0-5.1.8-1.4 2.9-1.8 4.1-2.8C7.6 2.9 8.4.8 10 0c1.5-.8 3.1.3 4.7.6 1.6.3 3.8-.3 5 .8 1.2 1 1 3.1 1.5 4.6.5 1.5 1.8 2.7 1.8 4.1Z" />
                                        </svg>
                                    </template>
                                </LinkAncor>
                                <!-- End Item -->
                                <!-- Item -->
                                <LinkAncor
                                    v-if="hasFeature('campaigns') && canCampaigns && !isClient && !isSeller"
                                    :label="$t('nav.campaigns')"
                                    :href="'campaigns.index'"
                                    tone="campaigns"
                                    :active="route().current('campaigns.*') || route().current('campaign-automations.*') || route().current('campaign-runs.*')"
                                >
                                    <template #icon>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                            stroke-linecap="round" stroke-linejoin="round"
                                            class="lucide lucide-send-horizontal">
                                            <path d="m3 3 3 9-3 9 19-9Z" />
                                            <path d="M6 12h16" />
                                        </svg>
                                    </template>
                                </LinkAncor>
                                <!-- End Item -->
                                <!-- Item -->
                                <LinkAncor
                                    v-if="canLoyaltyManage && hasFeature('loyalty') && !isClient && !isSeller"
                                    :label="$t('nav.loyalty')"
                                    :href="'loyalty.index'"
                                    tone="loyalty"
                                    :active="route().current('loyalty.*') || route().current('settings.loyalty.*')"
                                >
                                    <template #icon>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                            stroke-linecap="round" stroke-linejoin="round"
                                            class="lucide lucide-award">
                                            <circle cx="12" cy="8" r="6" />
                                            <path d="M15.477 12.89 17 22l-5-3-5 3 1.523-9.11" />
                                        </svg>
                                    </template>
                                </LinkAncor>
                                <!-- End Item -->

                                <!-- Item -->
                                <LinkAncor v-if="(companyType === 'products' && hasFeature('sales') && hasFeature('performance') && canSalesManage) || (hasServiceOps && hasFeature('performance') && canServiceManage)" :label="$t('nav.performance')" :href="'performance.index'" tone="performance"
                                    :active="route().current('performance.*')">
                                    <template #icon>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                            fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                            stroke-linejoin="round" class="lucide lucide-bar-chart-3">
                                            <path d="M3 3v18h18" />
                                            <path d="M18 17V9" />
                                            <path d="M13 17V5" />
                                            <path d="M8 17v-3" />
                                        </svg>
                                    </template>
                                </LinkAncor>
                                <!-- End Item -->

                                <!-- Item -->
                                <LinkAncor v-if="(companyType === 'products' && hasFeature('sales') && hasFeature('presence') && canSales) || (hasServiceOps && hasFeature('presence') && canService)" :label="$t('nav.presence')" :href="'presence.index'" tone="presence"
                                    :active="route().current('presence.*')">
                                    <template #icon>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                            stroke-linecap="round" stroke-linejoin="round"
                                            class="lucide lucide-clock">
                                            <circle cx="12" cy="12" r="10" />
                                            <polyline points="12 6 12 12 16 14" />
                                        </svg>
                                    </template>
                                </LinkAncor>
                                <!-- End Item -->

                                <!-- Item -->
                                <LinkAncor v-if="(companyType === 'products' && hasFeature('sales') && hasFeature('planning') && (canSales || isTeamMember)) || (hasServiceOps && hasFeature('planning') && (canService || isTeamMember))" :label="$t('nav.planning')" :href="'planning.index'" tone="planning"
                                    :badge="planningPendingCount"
                                    :active="route().current('planning.*')">
                                    <template #icon>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                            stroke-linecap="round" stroke-linejoin="round"
                                            class="lucide lucide-calendar-clock">
                                            <path d="M21 14V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h8" />
                                            <path d="M16 2v4" />
                                            <path d="M8 2v4" />
                                            <path d="M3 10h18" />
                                            <path d="M16 22a6 6 0 1 0 0-12 6 6 0 0 0 0 12Z" />
                                            <path d="M16 16v2l1.5 1.5" />
                                        </svg>
                                    </template>
                                </LinkAncor>
                                <!-- End Item -->

                                <!-- Item -->
                                <LinkAncor v-if="showServices && hasFeature('services') && page.props.auth.account?.is_owner && !isSeller" :label="$t('nav.services')" :href="'service.index'" tone="services"
                                    :active="route().current('service.index')">
                                    <template #icon>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                            stroke-linecap="round" stroke-linejoin="round"
                                            class="lucide lucide-wrench">
                                            <path
                                                d="M14.7 6.3a4 4 0 0 0-5.66 5.66l-6.34 6.34a2 2 0 0 0 2.83 2.83l6.34-6.34a4 4 0 0 0 5.66-5.66l-2.12 2.12-2.83-2.83 2.12-2.12z" />
                                        </svg>
                                    </template>
                                </LinkAncor>
                                <!-- End Item -->
                                <!-- Item -->
                                <LinkAncor v-if="showServices && hasFeature('services') && page.props.auth.account?.is_owner" :label="$t('nav.categories')" :href="'service.categories'" tone="categories"
                                    :active="route().current('service.categories')">
                                    <template #icon>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                            stroke-linecap="round" stroke-linejoin="round"
                                            class="lucide lucide-tags">
                                            <path d="M12 2H2v10l9 9 9-9-9-9z" />
                                            <path d="M7 7h.01" />
                                        </svg>
                                    </template>
                                </LinkAncor>
                                <!-- End Item -->
                                <!-- Item -->
                                <LinkAncor v-if="showServices && hasFeature('quotes') && canQuotes && !isSeller" :label="$t('nav.quotes')" :href="'quote.index'" tone="quotes"
                                    :active="route().current('quote.index') || route().current('customer.quote.*')">
                                    <template #icon>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                            stroke-linecap="round" stroke-linejoin="round"
                                            class="lucide lucide-file-text">
                                            <path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2Z" />
                                            <path d="M14 2v6h6" />
                                            <path d="M8 13h8" />
                                            <path d="M8 17h5" />
                                        </svg>
                                    </template>
                                </LinkAncor>
                                <!-- End Item -->
                                <!-- Item -->
                                <LinkAncor v-if="showServices && hasFeature('plan_scans') && page.props.auth.account?.is_owner && !isSeller" :label="$t('nav.plan_scans')" :href="'plan-scans.index'" tone="plan_scans"
                                    :active="route().current('plan-scans.*')">
                                    <template #icon>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                            stroke-linecap="round" stroke-linejoin="round"
                                            class="lucide lucide-search">
                                            <circle cx="11" cy="11" r="7" />
                                            <path d="m21 21-4.3-4.3" />
                                        </svg>
                                    </template>
                                </LinkAncor>
                                <!-- End Item -->
                                <!-- Item -->
                                <LinkAncor v-if="showServices && hasFeature('requests') && canSalesManage && !isSeller" :label="$t('nav.requests')" :href="'service-requests.index'" tone="requests"
                                    :active="route().current('service-requests.*')">
                                    <template #icon>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                                            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                            class="lucide lucide-clipboard-list">
                                            <rect width="8" height="4" x="8" y="2" rx="1" ry="1" />
                                            <path d="M9 14h6" />
                                            <path d="M9 18h6" />
                                            <path d="M9 10h6" />
                                            <path d="M4 6h16a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2Z" />
                                        </svg>
                                    </template>
                                </LinkAncor>
                                <!-- End Item -->
                                <!-- Item -->
                                <LinkAncor v-if="showServices && hasFeature('requests') && canSalesManage && !isSeller" :label="$t('nav.prospects')" :href="'prospects.index'" tone="requests"
                                    :active="route().current('prospects.*') || route().current('request.*')">
                                    <template #icon>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                                            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                            class="lucide lucide-git-pull-request">
                                            <circle cx="18" cy="18" r="3" />
                                            <circle cx="6" cy="6" r="3" />
                                            <path d="M13 6h3a2 2 0 0 1 2 2v7" />
                                            <line x1="6" x2="6" y1="9" y2="21" />
                                        </svg>
                                    </template>
                                </LinkAncor>
                                <!-- End Item -->
                                <!-- Item -->
                                <LinkAncor
                                    v-if="showServices && hasFeature('reservations') && canReservations && !isClient && !isSeller"
                                    :label="$t('nav.reservations')"
                                    :href="'reservation.index'"
                                    tone="planning"
                                    :active="route().current('reservation.*') || route().current('settings.reservations.*') || page.url.startsWith('/app/reservations') || page.url.startsWith('/settings/reservations')"
                                >
                                    <template #icon>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                            stroke-linecap="round" stroke-linejoin="round"
                                            class="lucide lucide-calendar-clock">
                                            <path d="M21 7.5V6a2 2 0 0 0-2-2h-1V2" />
                                            <path d="M17 2v2" />
                                            <path d="M7 2v2" />
                                            <path d="M3 10h5" />
                                            <path d="M3 14h4" />
                                            <path d="M3 18h3" />
                                            <path d="M3 7.5V18a2 2 0 0 0 2 2h4" />
                                            <path d="M9 2v2H8a2 2 0 0 0-2 2v1.5" />
                                            <circle cx="16" cy="16" r="5" />
                                            <path d="M16 14v2l1 1" />
                                        </svg>
                                    </template>
                                </LinkAncor>
                                <!-- End Item -->
                                <!-- Item -->
                                <LinkAncor
                                    v-if="showServices && hasFeature('assistant') && canAiAssistant && !isClient && !isSeller"
                                    :label="$t('nav.ai_assistant')"
                                    :href="'admin.ai-assistant.conversations.index'"
                                    tone="planning"
                                    :active="route().current('admin.ai-assistant.*') || page.url.startsWith('/admin/ai-assistant')"
                                >
                                    <template #icon>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                            stroke-linecap="round" stroke-linejoin="round"
                                            class="lucide lucide-bot-message-square">
                                            <path d="M12 6V2H8" />
                                            <path d="m8 18-4 4V8a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2Z" />
                                            <path d="M2 12h2" />
                                            <path d="M9 11v2" />
                                            <path d="M15 11v2" />
                                            <path d="M20 12h2" />
                                        </svg>
                                    </template>
                                </LinkAncor>
                                <!-- End Item -->
                                <!-- Item -->
                                <LinkAncor v-if="showServices && hasFeature('jobs') && canJobs && !isClient && !isSeller" :label="$t('nav.jobs')" :href="'jobs.index'" tone="jobs"
                                    :active="route().current('jobs.index') || route().current('work.*')">
                                    <template #icon>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                            stroke-linecap="round" stroke-linejoin="round"
                                            class="lucide lucide-briefcase">
                                            <rect width="20" height="14" x="2" y="7" rx="2" />
                                            <path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2" />
                                            <path d="M2 13h20" />
                                        </svg>
                                    </template>
                                </LinkAncor>
                                <!-- End Item -->

                                <!-- Item -->
                                <LinkAncor v-if="showServices && hasFeature('tasks') && canTasks && !isClient && !isSeller" :label="$t('nav.tasks')" :href="'task.index'" tone="tasks"
                                    :active="route().current('task.*')">
                                    <template #icon>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                            stroke-linecap="round" stroke-linejoin="round"
                                            class="lucide lucide-check-square">
                                            <path d="M9 11l3 3L22 4" />
                                            <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11" />
                                        </svg>
                                    </template>
                                </LinkAncor>
                                <!-- End Item -->

                                <!-- Item -->
                                <LinkAncor v-if="hasFeature('team_members') && page.props.auth.account?.is_owner && !isSeller" :label="$t('nav.team')" :href="'team.index'" tone="team"
                                    :active="route().current('team.*')">
                                    <template #icon>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                            stroke-linecap="round" stroke-linejoin="round"
                                            class="lucide lucide-users">
                                            <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" />
                                            <circle cx="9" cy="7" r="4" />
                                            <path d="M22 21v-2a4 4 0 0 0-3-3.87" />
                                            <path d="M16 3.13a4 4 0 0 1 0 7.75" />
                                        </svg>
                                    </template>
                                </LinkAncor>
                                <!-- End Item -->
                                <!-- Item -->
                                <LinkAncor
                                    v-if="hasFeature('expenses') && canExpensesNav && !isSeller"
                                    :label="$t('nav.expenses')"
                                    :href="'expense.index'"
                                    tone="expenses"
                                    :active="route().current('expense.*')"
                                >
                                    <template #icon>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                            stroke-linecap="round" stroke-linejoin="round"
                                            class="lucide lucide-wallet-cards">
                                            <rect width="18" height="18" x="3" y="3" rx="2" />
                                            <path d="M3 9a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2" />
                                            <path d="M3 11h3c.8 0 1.6.3 2.1.9l1.1.9c1.6 1.6 4.1 1.6 5.7 0l1.1-.9c.5-.5 1.3-.9 2.1-.9H21" />
                                        </svg>
                                    </template>
                                </LinkAncor>
                                <!-- End Item -->
                                <!-- Item -->
                                <LinkAncor
                                    v-if="hasFeature('accounting') && canAccountingNav && !isSeller"
                                    :label="$t('nav.accounting')"
                                    :href="'accounting.index'"
                                    tone="accounting"
                                    :active="route().current('accounting.*')"
                                >
                                    <template #icon>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                            stroke-linecap="round" stroke-linejoin="round"
                                            class="lucide lucide-calculator">
                                            <rect x="4" y="2" width="16" height="20" rx="2" />
                                            <line x1="8" y1="6" x2="16" y2="6" />
                                            <path d="M8 10h.01" />
                                            <path d="M12 10h.01" />
                                            <path d="M16 10h.01" />
                                            <path d="M8 14h.01" />
                                            <path d="M12 14h.01" />
                                            <line x1="16" y1="14" x2="16" y2="18" />
                                            <path d="M8 18h.01" />
                                            <line x1="12" y1="18" x2="16" y2="18" />
                                        </svg>
                                    </template>
                                </LinkAncor>
                                <!-- End Item -->
                                <!-- Item -->
                                <LinkAncor v-if="showServices && hasFeature('invoices') && canInvoicesNav && !isSeller" :label="$t('nav.invoices')" :href="'invoice.index'" tone="invoices"
                                    :active="route().current('invoice.*')">
                                    <template #icon>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                            stroke-linecap="round" stroke-linejoin="round"
                                            class="lucide lucide-receipt">
                                            <path d="M4 2h16v20l-4-2-4 2-4-2-4 2V2z" />
                                            <path d="M8 6h8" />
                                            <path d="M8 10h8" />
                                            <path d="M8 14h6" />
                                        </svg>
                                    </template>
                                </LinkAncor>
                                <!-- End Item -->
                                <!-- Item -->
                                <LinkAncor
                                    v-if="showServices && hasFeature('invoices') && page.props.auth.account?.is_owner && !isSeller"
                                    :label="$t('nav.tips')"
                                    :href="'payments.tips.index'"
                                    tone="invoices"
                                    :active="route().current('payments.tips.*')"
                                >
                                    <template #icon>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                            stroke-linecap="round" stroke-linejoin="round"
                                            class="lucide lucide-hand-coins">
                                            <path d="M11 15h2a2 2 0 0 0 0-4h-3a2 2 0 0 1 0-4h3" />
                                            <path d="M12 18v2" />
                                            <path d="M12 4v2" />
                                            <path d="M20 12v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-8" />
                                            <path d="M2 12h20" />
                                            <path d="M7 8a2 2 0 1 1 0-4h1a2 2 0 0 1 2 2v2" />
                                            <path d="M17 8a2 2 0 1 0 0-4h-1a2 2 0 0 0-2 2v2" />
                                        </svg>
                                    </template>
                                </LinkAncor>
                                <!-- End Item -->
                                <!-- Item -->
                                <LinkAncor
                                    v-if="showServices && hasFeature('invoices') && isTeamMember && !isClient && !isSeller"
                                    :label="$t('nav.tips')"
                                    :href="'my-earnings.tips.index'"
                                    tone="invoices"
                                    :active="route().current('my-earnings.tips.*')"
                                >
                                    <template #icon>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                            stroke-linecap="round" stroke-linejoin="round"
                                            class="lucide lucide-wallet">
                                            <path d="M19 7V4a1 1 0 0 0-1-1H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a1 1 0 0 0 1-1v-3" />
                                            <path d="M2 7h20v10H2z" />
                                            <circle cx="16" cy="12" r="1.5" />
                                        </svg>
                                    </template>
                                </LinkAncor>
                                <!-- End Item -->
                                </template>
                                </template>
                            </ul>
                        </nav>
                        <!-- End Nav -->
                    </div>
                    <!-- End Content -->
                </div>
            </div>
        </div>
    </aside>
    <QuickCreateModals v-if="!isClient && !showPlatformNav && !isSeller" />
</template>
