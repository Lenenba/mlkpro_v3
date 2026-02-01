<script setup>
import { computed } from 'vue';
import ApplicationLogo from "@/Components/ApplicationLogo.vue";
import LinkAncor from "@/Components/UI/LinkAncor.vue";
import { Link, usePage } from '@inertiajs/vue3';
import MenuDropdown from "@/Components/UI/LinkAncor2.vue";
import LanguageSwitcherMenu from '@/Components/UI/LanguageSwitcherMenu.vue';
import QuickCreateModals from "@/Components/QuickCreate/QuickCreateModals.vue";
import { isFeatureEnabled } from '@/utils/features';
import { defaultAvatarIcon } from '@/utils/iconPresets';
import NotificationBell from '@/Components/UI/NotificationBell.vue';

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
const featureFlags = computed(() => page.props.auth?.account?.features || {});
const hasFeature = (key) => isFeatureEnabled(featureFlags.value, key);
const showPlatformNav = computed(() => isSuperadmin.value || isPlatformAdmin.value);
const canPlatform = (permission) => isSuperadmin.value || platformPermissions.value.includes(permission);
const homeRoute = computed(() => (showPlatformNav.value ? 'superadmin.dashboard' : 'dashboard'));
const canSales = computed(() =>
    isOwner.value || teamPermissions.value.includes('sales.manage') || teamPermissions.value.includes('sales.pos')
);
const canSalesManage = computed(() =>
    isOwner.value || teamPermissions.value.includes('sales.manage')
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
const hasServiceOps = computed(() =>
    showServices.value && (hasFeature('jobs') || hasFeature('tasks'))
);
const canQuotes = computed(() =>
    isOwner.value || teamPermissions.value.includes('quotes.view') || teamPermissions.value.includes('quotes.edit')
);
const isSeller = computed(() => teamRole.value === 'seller');
const userName = computed(() => page.props.auth?.user?.name || '');
const userEmail = computed(() => page.props.auth?.user?.email || '');
const avatarUrl = computed(() =>
    page.props.auth?.user?.profile_picture_url
    || page.props.auth?.user?.profile_picture
    || defaultAvatarIcon
);
const showNotifications = computed(() => Boolean(page.props.notifications));
const unreadCount = computed(() => page.props.notifications?.unread_count || 0);
const hasUnread = computed(() => unreadCount.value > 0);
const planningPendingCount = computed(() => page.props.planning?.pending_count || 0);
const menuIconBaseClass = 'relative inline-flex size-9 items-center justify-center rounded-sm hover:bg-stone-100 focus:outline-none focus:ring-2 dark:hover:bg-neutral-800';
const languageButtonClass = `${menuIconBaseClass} text-sky-600 focus:ring-sky-500 dark:text-sky-400`;
const notificationButtonClass = `${menuIconBaseClass} text-amber-600 focus:ring-amber-500 dark:text-amber-400`;
const avatarInitial = computed(() => {
    const label = (userName.value || userEmail.value || '?').trim();
    return label.length ? label[0].toUpperCase() : '?';
});
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
                        <!-- Nav -->
                        <nav class="mt-2 flex-1 overflow-y-auto">
                            <ul class="text-center space-y-3 pb-2">
                                <template v-if="showPlatformNav">
                                    <LanguageSwitcherMenu :button-class="languageButtonClass" :icon-class="'size-6'" />
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

                                    <LinkAncor v-if="canPlatform('welcome.manage')" :label="$t('nav.welcome_builder')" :href="'superadmin.welcome.edit'" tone="welcome_builder"
                                        :active="route().current('superadmin.welcome.*')">
                                        <template #icon>
                                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                                stroke-linecap="round" stroke-linejoin="round"
                                                class="lucide lucide-layout-template">
                                                <rect x="3" y="3" width="18" height="18" rx="2" />
                                                <path d="M3 9h18" />
                                                <path d="M9 21V9" />
                                            </svg>
                                        </template>
                                    </LinkAncor>

                                    <LinkAncor v-if="canPlatform('pages.manage')" :label="$t('nav.pages')" :href="'superadmin.pages.index'" tone="pages"
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

                                    <LinkAncor v-if="canPlatform('pages.manage')" :label="$t('nav.sections')" :href="'superadmin.sections.index'" tone="sections"
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

                                    <LinkAncor v-if="canPlatform('pages.manage')" :label="$t('nav.assets')" :href="'superadmin.assets.index'" tone="assets"
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
                                <LanguageSwitcherMenu :button-class="languageButtonClass" :icon-class="'size-6'" />
                                <MenuDropdown v-if="!isClient && !isSeller" active-item="/profile">
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
                                <LinkAncor v-if="showServices && hasFeature('requests') && page.props.auth.account?.is_owner && !isSeller" :label="$t('nav.requests')" :href="'request.index'" tone="requests"
                                    :active="route().current('request.*')">
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
                                <LinkAncor v-if="showServices && hasFeature('jobs') && !isClient && !isSeller" :label="$t('nav.jobs')" :href="'jobs.index'" tone="jobs"
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
                                <LinkAncor v-if="hasFeature('tasks') && !isClient && !isSeller" :label="$t('nav.tasks')" :href="'task.index'" tone="tasks"
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
                                <LinkAncor v-if="showServices && hasFeature('invoices') && page.props.auth.account?.is_owner && !isSeller" :label="$t('nav.invoices')" :href="'invoice.index'" tone="invoices"
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
                                </template>
                                <li v-if="showNotifications" class="flex justify-center">
                                    <NotificationBell
                                        :button-class="notificationButtonClass"
                                        :badge-class="'absolute -top-1 -end-1 rounded-full bg-amber-500 px-1.5 text-[10px] font-semibold text-white'"
                                        :icon-class="'size-6'"
                                    />
                                </li>
                            </ul>
                        </nav>
                        <!-- End Nav -->
                    </div>
                    <!-- End Content -->

                    <!-- Footer -->
                    <footer class="w-16 text-center space-y-3">
                        <!-- Account Dropdown -->
                        <div class="inline-flex justify-center w-full">
                            <div
                                class="hs-dropdown relative [--strategy:absolute] [--auto-close:inside] [--placement:bottom-right] inline-flex">
                                <button id="hs-pro-chmsad" type="button"
                                    class="flex justify-center items-center gap-x-3 size-8 text-start disabled:opacity-50 disabled:pointer-events-none focus:outline-none"
                                    aria-haspopup="menu" aria-expanded="false" aria-label="Dropdown">
                                    <img v-if="avatarUrl" class="shrink-0 size-8 rounded-full object-cover" :src="avatarUrl"
                                        :alt="userName || 'Avatar'">
                                    <div v-else class="size-8 rounded-full bg-stone-200 text-stone-700 flex items-center justify-center text-xs font-semibold dark:bg-neutral-800 dark:text-neutral-200">
                                        {{ avatarInitial }}
                                    </div>
                                    <span
                                        v-if="hasUnread"
                                        class="absolute -top-0 -end-0 flex size-2">
                                        <span
                                            class="absolute inline-flex h-full w-full animate-ping rounded-full bg-amber-400 opacity-75"></span>
                                        <span
                                            class="relative inline-flex h-2 w-2 rounded-full bg-amber-500 ring-2 ring-stone-100 dark:ring-neutral-800"></span>
                                    </span>
                                    <span
                                        v-else
                                        class="absolute -bottom-0 -end-0 block size-2 rounded-full ring-2 ring-stone-100 bg-green-500 dark:ring-neutral-800"></span>
                                </button>

                                <!-- Account Dropdown -->
                                <div class="hs-dropdown-menu hs-dropdown-open:opacity-100 w-48 transition-[opacity,margin] duration opacity-0 hidden z-20 bg-white rounded-sm shadow-[0_10px_40px_10px_rgba(0,0,0,0.08)] dark:shadow-[0_10px_40px_10px_rgba(0,0,0,0.2)] dark:bg-neutral-900"
                                    role="menu" aria-orientation="vertical" aria-labelledby="hs-pro-chmsad">
                                    <div class="px-3 pt-3 pb-2">
                                        <div class="text-sm font-semibold text-stone-700 dark:text-neutral-100">
                                            {{ userName || $t('account.default_name') }}
                                        </div>
                                        <div class="text-xs text-stone-500 dark:text-neutral-400 truncate">
                                            {{ userEmail }}
                                        </div>
                                        <div v-if="page.props.auth?.account?.company?.name" class="mt-1 text-xs text-stone-500 dark:text-neutral-400 truncate">
                                            {{ $t('account.company_label') }}: {{ page.props.auth.account.company.name }}
                                        </div>
                                    </div>
                                    <div class="p-1">
                                        <Link v-if="isOwner" :href="route('settings.company.edit')"
                                            class="flex items-center gap-x-3 py-1.5 px-2.5 rounded-sm text-sm text-stone-700 hover:bg-stone-100 focus:outline-none focus:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800">
                                            <svg class="shrink-0 mt-0.5 size-4" xmlns="http://www.w3.org/2000/svg"
                                                width="24" height="24" viewBox="0 0 24 24" fill="none"
                                                stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                                stroke-linejoin="round">
                                                <path d="M3 21V7a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v14" />
                                                <path d="M9 21V9h6v12" />
                                            </svg>
                                            {{ $t('account.settings') }}
                                        </Link>

                                        <Link v-if="isOwner" :href="route('settings.billing.edit')"
                                            class="flex items-center gap-x-3 py-1.5 px-2.5 rounded-sm text-sm text-stone-700 hover:bg-stone-100 focus:outline-none focus:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800">
                                            <svg class="shrink-0 mt-0.5 size-4" xmlns="http://www.w3.org/2000/svg"
                                                width="24" height="24" viewBox="0 0 24 24" fill="none"
                                                stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                                stroke-linejoin="round">
                                                <rect width="20" height="14" x="2" y="5" rx="2" />
                                                <line x1="2" x2="22" y1="10" y2="10" />
                                            </svg>
                                            {{ $t('account.billing') }}
                                        </Link>

                                        <Link :href="route('profile.edit')"
                                            class="flex items-center gap-x-3 py-1.5 px-2.5 rounded-sm text-sm text-stone-700 hover:bg-stone-100 focus:outline-none focus:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800">
                                            <svg class="shrink-0 mt-0.5 size-4" xmlns="http://www.w3.org/2000/svg"
                                                width="24" height="24" viewBox="0 0 24 24" fill="none"
                                                stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                                stroke-linejoin="round">
                                                <path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2" />
                                                <circle cx="12" cy="7" r="4" />
                                            </svg>
                                            {{ $t('account.profile') }}
                                        </Link>
                                    </div>
                                    <div class="py-1.5 px-3.5 border-y border-stone-200 dark:border-neutral-800">
                                        <!-- Switch/Toggle -->
                                        <div class="flex justify-between items-center">
                                            <label for="hs-pro-chmsaddm"
                                                class="text-sm text-stone-700 dark:text-neutral-300">{{ $t('account.dark_mode') }}</label>
                                            <div class="relative inline-block">
                                                <input data-hs-theme-switch type="checkbox" id="hs-pro-chmsaddm"
                                                    class="relative w-11 h-6 p-px bg-stone-100 border-transparent text-transparent rounded-full cursor-pointer transition-colors ease-in-out duration-200 focus:ring-blue-600 disabled:opacity-50 disabled:pointer-events-none checked:bg-none checked:text-blue-600 checked:border-blue-600 focus:checked:border-blue-600 dark:bg-neutral-800 dark:border-neutral-700 dark:checked:bg-blue-500 dark:checked:border-blue-500 dark:focus:ring-offset-neutral-900

                      before:inline-block before:size-5 before:bg-white checked:before:bg-white before:translate-x-0 checked:before:translate-x-full before:rounded-full before:shadow before:transform before:ring-0 before:transition before:ease-in-out before:duration-200 dark:before:bg-neutral-400 dark:checked:before:bg-white">
                                            </div>
                                        </div>
                                        <!-- End Switch/Toggle -->
                                    </div>
                                    <div class="p-1">
                                        <Link :href="route('logout')" method="post" as="button" type="button"
                                            class="w-full flex items-center gap-x-3 py-1.5 px-2.5 rounded-sm text-sm text-stone-700 hover:bg-stone-100 focus:outline-none focus:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800">
                                            {{ $t('account.logout') }}
                                        </Link>
                                    </div>
                                </div>
                                <!-- End Account Dropdown -->
                            </div>
                        </div>
                        <!-- End Account Dropdown -->
                    </footer>
                    <!-- End Footer -->
                </div>
            </div>
        </div>
    </aside>
    <QuickCreateModals v-if="!isClient && !showPlatformNav && !isSeller" />
</template>
