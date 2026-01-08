<script setup>
import { computed, nextTick, onBeforeUnmount, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';

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
});

const page = usePage();
const notifications = computed(() => page.props.notifications?.items || []);
const unreadCount = computed(() => page.props.notifications?.unread_count || 0);
const hasNotifications = computed(() => notifications.value.length > 0);

const isOpen = ref(false);
const toggleRef = ref(null);
const menuRef = ref(null);
const menuStyle = ref({});
let listenersBound = false;

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

const updatePosition = () => {
    const button = toggleRef.value;
    if (!button || !menuRef.value) {
        return;
    }
    const rect = button.getBoundingClientRect();
    const menuRect = menuRef.value.getBoundingClientRect();
    const padding = 12;
    let left = rect.right - menuRect.width;
    if (left < padding) {
        left = padding;
    }
    if (left + menuRect.width > window.innerWidth - padding) {
        left = Math.max(padding, window.innerWidth - menuRect.width - padding);
    }
    let top = rect.bottom + 8;
    const maxTop = window.innerHeight - menuRect.height - padding;
    if (top > maxTop) {
        top = Math.max(padding, rect.top - menuRect.height - 8);
    }
    menuStyle.value = { left: `${left}px`, top: `${top}px` };
};

const addListeners = () => {
    if (listenersBound) {
        return;
    }
    window.addEventListener('resize', updatePosition);
    window.addEventListener('scroll', updatePosition, true);
    document.addEventListener('click', handleOutsideClick, true);
    listenersBound = true;
};

const removeListeners = () => {
    if (!listenersBound) {
        return;
    }
    window.removeEventListener('resize', updatePosition);
    window.removeEventListener('scroll', updatePosition, true);
    document.removeEventListener('click', handleOutsideClick, true);
    listenersBound = false;
};

const toggleMenu = () => {
    isOpen.value = !isOpen.value;
    if (isOpen.value) {
        nextTick(() => {
            updatePosition();
            addListeners();
        });
        return;
    }
    removeListeners();
};

const closeMenu = () => {
    isOpen.value = false;
    removeListeners();
};

const handleOutsideClick = (event) => {
    if (!isOpen.value) {
        return;
    }
    const target = event.target;
    if (toggleRef.value && toggleRef.value.contains(target)) {
        return;
    }
    if (menuRef.value && menuRef.value.contains(target)) {
        return;
    }
    closeMenu();
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

const openNotification = (notification) => {
    const url = notification?.action_url;
    if (url) {
        router.visit(url);
    }
    closeMenu();
};

onBeforeUnmount(() => {
    removeListeners();
});
</script>

<template>
    <div class="relative">
        <button ref="toggleRef" type="button" :class="buttonClass" @click="toggleMenu" aria-label="Notifications">
            <svg class="size-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
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
                    <div class="text-sm font-semibold text-stone-800 dark:text-neutral-100">Notifications</div>
                    <button
                        v-if="unreadCount"
                        type="button"
                        class="text-[11px] font-semibold text-green-700 hover:underline dark:text-green-400"
                        @click="markAllRead"
                    >
                        Tout marquer
                    </button>
                </div>
                <div v-if="!hasNotifications" class="px-4 py-5 text-sm text-stone-500 dark:text-neutral-400">
                    Aucune notification.
                </div>
                <div v-else class="max-h-80 divide-y divide-stone-100 overflow-y-auto dark:divide-neutral-800">
                    <button
                        v-for="notification in notifications"
                        :key="notification.id"
                        type="button"
                        class="w-full px-4 py-3 text-left transition hover:bg-stone-50 dark:hover:bg-neutral-800"
                        @click="openNotification(notification)"
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
                    </button>
                </div>
            </div>
        </Teleport>
    </div>
</template>
