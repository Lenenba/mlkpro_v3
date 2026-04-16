<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { defaultAvatarIcon } from '@/utils/iconPresets';
import { useFloatingMenu } from '@/Composables/useFloatingMenu';

const props = defineProps({
    wrapperClass: {
        type: String,
        default: 'inline-flex justify-center w-full',
    },
});

const page = usePage();
const { t } = useI18n();
const { isOpen, toggleRef, menuRef, menuStyle, closeMenu, toggleMenu } = useFloatingMenu();

const isOwner = computed(() => Boolean(page.props.auth?.account?.is_owner));
const isSuperadmin = computed(() => Boolean(page.props.auth?.account?.is_superadmin));
const userName = computed(() => page.props.auth?.user?.name || '');
const userEmail = computed(() => page.props.auth?.user?.email || '');
const companyName = computed(() => page.props.auth?.account?.company?.name || '');
const hasAvatarImage = computed(() =>
    Boolean(page.props.auth?.user?.profile_picture_url || page.props.auth?.user?.profile_picture)
);
const avatarUrl = computed(() =>
    page.props.auth?.user?.profile_picture_url
    || page.props.auth?.user?.profile_picture
    || defaultAvatarIcon
);
const avatarInitial = computed(() => {
    const label = (userName.value || userEmail.value || '?').trim();
    return label.length ? label[0].toUpperCase() : '?';
});

const closeAfterAction = () => {
    closeMenu();
};
</script>

<template>
    <div :class="props.wrapperClass">
        <button
            ref="toggleRef"
            type="button"
            class="relative inline-flex size-9 items-center justify-center rounded-full transition hover:bg-stone-100 focus:outline-none focus:ring-2 focus:ring-stone-300 dark:hover:bg-neutral-800 dark:focus:ring-neutral-600"
            :aria-expanded="isOpen ? 'true' : 'false'"
            :aria-label="userName || 'Account menu'"
            @click="toggleMenu"
        >
            <img
                v-if="hasAvatarImage"
                class="size-9 rounded-full object-cover"
                :src="avatarUrl"
                :alt="userName || 'Avatar'"
            >
            <div
                v-else
                class="flex size-9 items-center justify-center rounded-full bg-stone-200 text-xs font-semibold text-stone-700 dark:bg-neutral-800 dark:text-neutral-200"
            >
                {{ avatarInitial }}
            </div>
            <span class="absolute -bottom-0.5 -end-0.5 block size-2.5 rounded-full border-2 border-white bg-emerald-500 dark:border-neutral-950"></span>
        </button>

        <Teleport to="body">
            <div
                v-if="isOpen"
                ref="menuRef"
                class="fixed z-[90] w-56 rounded-sm border border-stone-200 bg-white shadow-lg dark:border-neutral-700 dark:bg-neutral-900"
                :style="menuStyle"
            >
                <div class="px-4 pt-4 pb-3">
                    <div class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                        {{ userName || t('account.default_name') }}
                    </div>
                    <div class="truncate text-xs text-stone-500 dark:text-neutral-400">
                        {{ userEmail }}
                    </div>
                    <div v-if="companyName" class="mt-1 truncate text-xs text-stone-500 dark:text-neutral-400">
                        {{ t('account.company_label') }}: {{ companyName }}
                    </div>
                </div>

                <div class="border-t border-stone-200 p-2 dark:border-neutral-800">
                    <Link
                        v-if="isOwner && !isSuperadmin"
                        :href="route('settings.billing.edit')"
                        class="flex items-center gap-x-3 rounded-sm px-2.5 py-2 text-sm text-stone-700 transition hover:bg-stone-100 focus:bg-stone-100 focus:outline-none dark:text-neutral-300 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800"
                        @click="closeAfterAction"
                    >
                        <svg class="size-4 shrink-0" xmlns="http://www.w3.org/2000/svg"
                            width="24" height="24" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2" stroke-linecap="round"
                            stroke-linejoin="round">
                            <rect width="20" height="14" x="2" y="5" rx="2" />
                            <line x1="2" x2="22" y1="10" y2="10" />
                        </svg>
                        {{ t('account.billing') }}
                    </Link>

                    <Link
                        :href="route('profile.edit')"
                        class="flex items-center gap-x-3 rounded-sm px-2.5 py-2 text-sm text-stone-700 transition hover:bg-stone-100 focus:bg-stone-100 focus:outline-none dark:text-neutral-300 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800"
                        @click="closeAfterAction"
                    >
                        <svg class="size-4 shrink-0" xmlns="http://www.w3.org/2000/svg"
                            width="24" height="24" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2" stroke-linecap="round"
                            stroke-linejoin="round">
                            <path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2" />
                            <circle cx="12" cy="7" r="4" />
                        </svg>
                        {{ t('account.profile') }}
                    </Link>
                </div>

                <div class="border-y border-stone-200 px-4 py-3 dark:border-neutral-800">
                    <div class="flex items-center justify-between">
                        <label for="hs-topbar-dark-mode"
                            class="text-sm text-stone-700 dark:text-neutral-300">{{ t('account.dark_mode') }}</label>
                        <div class="relative inline-block">
                            <input data-hs-theme-switch type="checkbox" id="hs-topbar-dark-mode"
                                class="relative h-6 w-11 cursor-pointer rounded-full border-transparent bg-stone-100 p-px text-transparent transition-colors duration-200 ease-in-out before:inline-block before:size-5 before:translate-x-0 before:transform before:rounded-full before:bg-white before:shadow before:ring-0 before:transition before:duration-200 before:ease-in-out checked:border-blue-600 checked:bg-none checked:text-blue-600 checked:before:translate-x-full checked:before:bg-white focus:ring-blue-600 focus:checked:border-blue-600 disabled:pointer-events-none disabled:opacity-50 dark:border-neutral-700 dark:bg-neutral-800 dark:checked:border-blue-500 dark:checked:bg-blue-500 dark:checked:before:bg-white dark:focus:ring-offset-neutral-900 dark:before:bg-neutral-400">
                        </div>
                    </div>
                </div>

                <div class="p-2">
                    <Link
                        :href="route('logout')"
                        method="post"
                        as="button"
                        type="button"
                        class="flex w-full items-center gap-x-3 rounded-sm px-2.5 py-2 text-sm text-stone-700 transition hover:bg-stone-100 focus:bg-stone-100 focus:outline-none dark:text-neutral-300 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800"
                        @click="closeAfterAction"
                    >
                        {{ t('account.logout') }}
                    </Link>
                </div>
            </div>
        </Teleport>
    </div>
</template>
