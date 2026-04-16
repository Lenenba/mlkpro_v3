<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import GlobalSearch from '@/Components/UI/GlobalSearch.vue';
import NotificationBell from '@/Components/UI/NotificationBell.vue';
import SidebarMessagesMenu from '@/Components/UI/SidebarMessagesMenu.vue';
import SidebarAccountMenu from '@/Components/UI/SidebarAccountMenu.vue';

const page = usePage();

const isClient = computed(() => Boolean(page.props.auth?.account?.is_client));
const isOwner = computed(() => Boolean(page.props.auth?.account?.is_owner));
const isSuperadmin = computed(() => Boolean(page.props.auth?.account?.is_superadmin));
const isPlatformAdmin = computed(() => Boolean(page.props.auth?.account?.is_platform_admin));
const platformPermissions = computed(() => page.props.auth?.account?.platform?.permissions || []);
const showPlatformNav = computed(() => isSuperadmin.value || isPlatformAdmin.value);
const showNotifications = computed(() => Boolean(page.props.notifications));
const showMessagesMenu = computed(() => !showPlatformNav.value && !isClient.value);
const showSettingsIcon = computed(() => {
    if (isSuperadmin.value) {
        return true;
    }

    if (isPlatformAdmin.value) {
        return platformPermissions.value.includes('settings.manage');
    }

    return isOwner.value && !isClient.value;
});
const settingsRouteName = computed(() => {
    if (showPlatformNav.value) {
        return 'superadmin.settings.edit';
    }

    return 'settings.company.edit';
});
const topbarIconButtonClass = 'relative inline-flex size-9 items-center justify-center rounded-full bg-transparent text-stone-600 transition hover:bg-stone-100 focus:outline-none focus:ring-2 focus:ring-stone-300 dark:text-neutral-200 dark:hover:bg-neutral-800 dark:focus:ring-neutral-600';
const messagesButtonClass = `${topbarIconButtonClass} text-stone-600 dark:text-neutral-200`;
const notificationButtonClass = `${topbarIconButtonClass} text-amber-600 dark:text-amber-400`;
const settingsButtonClass = `${topbarIconButtonClass} text-slate-600 dark:text-neutral-200`;
</script>

<template>
    <!-- ========== HEADER ========== -->
    <header
        class="fixed top-0 left-0 right-0 lg:left-16 flex flex-wrap md:justify-start md:flex-nowrap z-50 bg-white border-b border-stone-200 dark:bg-neutral-900 dark:border-neutral-700">
        <div class="flex w-full items-center gap-3 py-2.5 px-2 sm:px-5">
            <div class="flex min-w-0 flex-1 items-center gap-3">
                <!-- Sidebar Toggle -->
                <button type="button"
                    class="w-7 h-[38px] inline-flex justify-center items-center gap-x-2 text-sm font-medium rounded-sm border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 focus:outline-none focus:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-700 dark:focus:bg-neutral-700 lg:hidden"
                    aria-haspopup="dialog" aria-expanded="false" aria-controls="hs-pro-sidebar"
                    aria-label="Toggle navigation" data-hs-overlay="#hs-pro-sidebar">
                    <svg class="shrink-0 size-4" xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                        viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                        stroke-linejoin="round">
                        <path d="M17 8L21 12L17 16M3 12H13M3 6H13M3 18H13" />
                    </svg>
                </button>
                <!-- End Sidebar Toggle -->

                <div class="min-w-0 flex-1">
                    <GlobalSearch />
                </div>
            </div>

            <div class="flex shrink-0 items-center justify-end gap-4 pr-1">
                <SidebarMessagesMenu
                    v-if="showMessagesMenu"
                    :button-class="messagesButtonClass"
                    :badge-class="'absolute -top-1 -end-1 rounded-full bg-rose-500 px-1.5 text-[10px] font-semibold text-white'"
                    :icon-class="'size-5'"
                />

                <NotificationBell
                    v-if="showNotifications"
                    :button-class="notificationButtonClass"
                    :badge-class="'absolute -top-1 -end-1 rounded-full bg-amber-500 px-1.5 text-[10px] font-semibold text-white'"
                    :icon-class="'size-5'"
                />

                <Link
                    v-if="showSettingsIcon"
                    :href="route(settingsRouteName)"
                    :class="settingsButtonClass"
                    aria-label="Settings"
                >
                   <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-cog-icon lucide-cog"><path d="M11 10.27 7 3.34"/><path d="m11 13.73-4 6.93"/><path d="M12 22v-2"/><path d="M12 2v2"/><path d="M14 12h8"/><path d="m17 20.66-1-1.73"/><path d="m17 3.34-1 1.73"/><path d="M2 12h2"/><path d="m20.66 17-1.73-1"/><path d="m20.66 7-1.73 1"/><path d="m3.34 17 1.73-1"/><path d="m3.34 7 1.73 1"/><circle cx="12" cy="12" r="2"/><circle cx="12" cy="12" r="8"/></svg>
                </Link>

                <SidebarAccountMenu wrapper-class="inline-flex" />
            </div>
        </div>
    </header>
    <!-- ========== END HEADER ========== -->
</template>
