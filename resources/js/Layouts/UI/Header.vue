<script setup>
import { computed } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import NotificationBell from '@/Components/UI/NotificationBell.vue';

const page = usePage();
const showNotifications = computed(() => Boolean(page.props.notifications));
const currentLocale = computed(() => page.props.locale || 'fr');
const availableLocales = computed(() => page.props.locales || ['fr', 'en']);

const setLocale = (locale) => {
    if (locale === currentLocale.value) {
        return;
    }

    router.post(route('locale.update'), { locale }, { preserveScroll: true });
};
</script>

<template>
    <!-- ========== HEADER ========== -->
    <header
        class="fixed top-0 left-0 right-0 lg:left-16 flex flex-wrap md:justify-start md:flex-nowrap z-50 bg-white border-b border-stone-200 dark:bg-neutral-900 dark:border-neutral-700">
        <div class="flex justify-between basis-full items-center w-full py-2.5 px-2 sm:px-5">
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
            <div class="flex items-center gap-2">
                <div
                    v-if="availableLocales.length > 1"
                    class="inline-flex items-center rounded-sm border border-stone-200 bg-white p-0.5 text-[11px] font-semibold text-stone-600 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300"
                >
                    <button
                        v-for="locale in availableLocales"
                        :key="locale"
                        type="button"
                        :aria-pressed="currentLocale === locale"
                        :disabled="currentLocale === locale"
                        class="inline-flex items-center rounded-sm px-2.5 py-1 transition disabled:opacity-60 disabled:cursor-default"
                        :class="currentLocale === locale
                            ? 'bg-green-600 text-white shadow-sm'
                            : 'text-stone-600 hover:text-stone-800 dark:text-neutral-300 dark:hover:text-neutral-100'"
                        @click="setLocale(locale)"
                    >
                        {{ $t(`language.${locale}`) }}
                    </button>
                </div>
                <NotificationBell v-if="showNotifications" />
            </div>
        </div>
    </header>
    <!-- ========== END HEADER ========== -->
</template>
