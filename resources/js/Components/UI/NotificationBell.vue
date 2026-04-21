<script setup>
import { computed, onBeforeUnmount, onMounted } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { useFloatingMenu } from '@/Composables/useFloatingMenu';

const props = defineProps({
    buttonClass: {
        type: String,
        default:
            'relative inline-flex size-8 items-center justify-center rounded-sm border border-stone-200 bg-white text-stone-600 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800',
    },
    badgeClass: {
        type: String,
        default: 'absolute -top-1 -end-1 rounded-full bg-green-600 px-1.5 text-[10px] font-semibold text-white',
    },
    iconClass: {
        type: String,
        default: 'size-6',
    },
});

const page = usePage();
const { t } = useI18n();
const notifications = computed(() => page.props.notifications?.items || []);
const unreadCount = computed(() => page.props.notifications?.unread_count || 0);
const hasNotifications = computed(() => notifications.value.length > 0);
const shouldPoll = computed(() => Boolean(page.props.notifications));

const { isOpen, toggleRef, menuRef, menuStyle, closeMenu, toggleMenu } = useFloatingMenu();
const pollIntervalMs = 30000;
let pollTimer = null;

const formatDate = (value) => {
    if (!value) {
        return '';
    }
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return '';
    }
    return date.toLocaleString();
};

const pollNotifications = () => {
    if (document.visibilityState !== 'visible' || isOpen.value) {
        return;
    }
    router.reload({ only: ['notifications', 'planning'], preserveScroll: true, preserveState: true });
};

const startPolling = () => {
    if (pollTimer || !shouldPoll.value) {
        return;
    }
    pollTimer = window.setInterval(pollNotifications, pollIntervalMs);
};

const stopPolling = () => {
    if (!pollTimer) {
        return;
    }
    window.clearInterval(pollTimer);
    pollTimer = null;
};

const handleVisibilityChange = () => {
    if (document.visibilityState === 'visible') {
        startPolling();
        pollNotifications();
        return;
    }
    stopPolling();
};

const markAllRead = () => {
    if (!unreadCount.value) {
        return;
    }
    router.post(route('notifications.read-all'), {}, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            router.reload({ only: ['notifications'] });
        },
        onFinish: () => {
            closeMenu();
        },
    });
};

const openNotification = (notification, event) => {
    if (!notification) {
        event?.preventDefault?.();
        return;
    }

    closeMenu();
};

onBeforeUnmount(() => {
    stopPolling();
    document.removeEventListener('visibilitychange', handleVisibilityChange);
});

onMounted(() => {
    if (!page.props.auth?.user || !shouldPoll.value) {
        return;
    }
    if (document.visibilityState === 'visible') {
        startPolling();
    }
    document.addEventListener('visibilitychange', handleVisibilityChange);
});
</script>

<template>
    <div class="relative">
        <button ref="toggleRef" type="button" :class="buttonClass" @click="toggleMenu" :aria-label="t('notifications_panel.title')" data-testid="demo-notifications-bell">
            <svg :class="iconClass" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M10 5a2 2 0 1 1 4 0" />
                <path d="M6 8a6 6 0 0 1 12 0c0 7 3 7 3 7H3s3 0 3-7" />
                <path d="M10 21a2 2 0 0 0 4 0" />
            </svg>
            <span v-if="unreadCount" :class="badgeClass">
                {{ unreadCount > 9 ? '9+' : unreadCount }}
            </span>
        </button>

        <Teleport to="body">
            <div
                v-if="isOpen"
                ref="menuRef"
                class="fixed z-[90] w-80 rounded-sm border border-stone-200 bg-white shadow-lg dark:border-neutral-700 dark:bg-neutral-900"
                :style="menuStyle"
            >
                <div class="flex items-center justify-between border-b border-stone-200 px-4 py-3 dark:border-neutral-700">
                    <div class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ t('notifications_panel.title') }}</div>
                    <button
                        v-if="unreadCount"
                        type="button"
                        class="text-[11px] font-semibold text-green-700 hover:underline dark:text-green-400"
                        @click="markAllRead"
                    >
                        {{ t('notifications_panel.mark_all') }}
                    </button>
                </div>
                <div v-if="!hasNotifications" class="px-4 py-5 text-sm text-stone-500 dark:text-neutral-400">
                    {{ t('notifications_panel.empty') }}
                </div>
                <div v-else class="max-h-80 divide-y divide-stone-100 overflow-y-auto dark:divide-neutral-800">
                    <a
                        v-for="notification in notifications"
                        :key="notification.id"
                        :href="route('notifications.open', { notification: notification.id, source: 'header' })"
                        class="block w-full px-4 py-3 text-left transition hover:bg-stone-50 dark:hover:bg-neutral-800"
                        @click="openNotification(notification, $event)"
                    >
                        <div class="flex items-start gap-2">
                            <span
                                class="mt-1 h-2 w-2 rounded-full"
                                :class="notification.read_at ? 'bg-stone-300 dark:bg-neutral-600' : 'bg-green-500'"
                            ></span>
                            <div class="flex-1">
                                <div class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                    {{ notification.title }}
                                </div>
                                <div class="text-xs text-stone-500 dark:text-neutral-400">
                                    {{ notification.message }}
                                </div>
                                <div class="mt-1 text-[10px] text-stone-400 dark:text-neutral-500">
                                    {{ formatDate(notification.created_at) }}
                                </div>
                            </div>
                        </div>
                    </a>
                </div>
                <div class="border-t border-stone-200 px-4 py-3 dark:border-neutral-700">
                    <Link
                        :href="route('notifications.index')"
                        class="inline-flex items-center gap-1 text-xs font-semibold text-amber-700 transition hover:text-amber-800 hover:underline dark:text-amber-400 dark:hover:text-amber-300"
                        @click="closeMenu"
                    >
                        {{ t('notifications_panel.view_all') }}
                    </Link>
                </div>
            </div>
        </Teleport>
    </div>
</template>
