<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, useId } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { useFloatingMenu } from '@/Composables/useFloatingMenu';

const props = defineProps({
    items: {
        type: Array,
        default: () => [],
    },
    label: {
        type: String,
        required: true,
    },
    currentKey: {
        type: [String, Number],
        default: undefined,
    },
    triggerVariant: {
        type: String,
        default: 'trailing',
        validator: (value) => ['separator', 'trailing'].includes(value),
    },
});

const emit = defineEmits(['select']);
const { t } = useI18n();
const menuId = `breadcrumb-siblings-${useId().replaceAll(':', '')}`;
const activeIndex = ref(-1);

const normalizedItems = computed(() => (props.items || [])
    .filter((item) => Boolean(item?.label))
    .map((item, index) => ({
        ...item,
        key: item.key ?? `${index}-${item.label}`,
    })));

const currentIndex = computed(() => normalizedItems.value.findIndex((item) => (
    Boolean(item.current)
    || String(item.key) === String(props.currentKey)
)));

const {
    isOpen,
    toggleRef,
    menuRef,
    menuStyle,
    openMenu,
    closeMenu,
} = useFloatingMenu({ align: 'start', padding: 12, offset: 6 });

const menuElements = () => [
    ...(menuRef.value?.querySelectorAll('[data-breadcrumb-menu-item]') || []),
];

const focusItem = (index) => {
    const elements = menuElements();
    if (!elements.length) {
        return;
    }

    const normalizedIndex = ((index % elements.length) + elements.length) % elements.length;
    activeIndex.value = normalizedIndex;
    nextTick(() => elements[normalizedIndex]?.focus());
};

const openAndFocus = (position = 'current') => {
    if (!normalizedItems.value.length) {
        return;
    }

    openMenu();
    nextTick(() => {
        if (position === 'last') {
            focusItem(normalizedItems.value.length - 1);
            return;
        }
        if (position === 'first') {
            focusItem(0);
            return;
        }

        focusItem(currentIndex.value >= 0 ? currentIndex.value : 0);
    });
};

const closeAndRestoreFocus = () => {
    closeMenu();
    nextTick(() => toggleRef.value?.focus());
};

const toggle = () => {
    if (isOpen.value) {
        closeMenu();
        return;
    }

    activeIndex.value = currentIndex.value >= 0 ? currentIndex.value : 0;
    openMenu();
};

const handleTriggerKeydown = (event) => {
    if (event.key === 'ArrowDown') {
        event.preventDefault();
        openAndFocus('first');
        return;
    }
    if (event.key === 'ArrowUp') {
        event.preventDefault();
        openAndFocus('last');
        return;
    }
    if (event.key === 'Home') {
        event.preventDefault();
        openAndFocus('first');
        return;
    }
    if (event.key === 'End') {
        event.preventDefault();
        openAndFocus('last');
        return;
    }
    if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        openAndFocus();
        return;
    }
    if (event.key === 'Escape' && isOpen.value) {
        event.preventDefault();
        closeAndRestoreFocus();
    }
};

const handleMenuKeydown = (event) => {
    const elements = menuElements();
    const focusedIndex = Math.max(0, elements.indexOf(document.activeElement));

    if (event.key === 'ArrowDown') {
        event.preventDefault();
        focusItem(focusedIndex + 1);
        return;
    }
    if (event.key === 'ArrowUp') {
        event.preventDefault();
        focusItem(focusedIndex - 1);
        return;
    }
    if (event.key === 'Home') {
        event.preventDefault();
        focusItem(0);
        return;
    }
    if (event.key === 'End') {
        event.preventDefault();
        focusItem(elements.length - 1);
        return;
    }
    if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        elements[focusedIndex]?.click();
        return;
    }
    if (event.key === 'Escape') {
        event.preventDefault();
        closeAndRestoreFocus();
    }
};

const selectItem = (item) => {
    closeMenu();
    if (!item.href) {
        emit('select', item);
    }
};

let stopNavigationListener = null;

onMounted(() => {
    stopNavigationListener = router.on('start', closeMenu);
});

onBeforeUnmount(() => {
    stopNavigationListener?.();
});
</script>

<template>
    <span class="inline-flex shrink-0">
        <button
            ref="toggleRef"
            type="button"
            class="inline-flex size-8 touch-manipulation items-center justify-center rounded-full text-current transition hover:bg-black/5 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-green-600 dark:hover:bg-white/10"
            :aria-label="label"
            :aria-controls="menuId"
            :aria-expanded="isOpen ? 'true' : 'false'"
            aria-haspopup="menu"
            @click="toggle"
            @keydown="handleTriggerKeydown"
        >
            <svg
                v-if="triggerVariant === 'separator'"
                aria-hidden="true"
                class="size-3.5 opacity-60 transition motion-reduce:transition-none"
                :class="isOpen ? 'rotate-90' : ''"
                viewBox="0 0 20 20"
                fill="currentColor"
            >
                <path
                    fill-rule="evenodd"
                    d="M7.22 4.22a.75.75 0 0 1 1.06 0l5.25 5.25a.75.75 0 0 1 0 1.06l-5.25 5.25a.75.75 0 1 1-1.06-1.06L11.94 10 7.22 5.28a.75.75 0 0 1 0-1.06Z"
                    clip-rule="evenodd"
                />
            </svg>
            <svg
                v-else
                aria-hidden="true"
                class="size-3.5 opacity-60 transition motion-reduce:transition-none"
                :class="isOpen ? 'rotate-180' : ''"
                viewBox="0 0 20 20"
                fill="currentColor"
            >
                <path fill-rule="evenodd" d="M5.22 7.22a.75.75 0 0 1 1.06 0L10 10.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 8.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" />
            </svg>
        </button>

        <Teleport to="body">
            <div
                v-if="isOpen"
                :id="menuId"
                ref="menuRef"
                class="fixed z-[100] max-h-[min(24rem,calc(100vh-1.5rem))] w-[min(18rem,calc(100vw-1.5rem))] overflow-x-hidden overflow-y-auto overscroll-contain rounded-md border border-stone-200 bg-white p-1 shadow-xl dark:border-neutral-700 dark:bg-neutral-900"
                :style="menuStyle"
                role="menu"
                aria-orientation="vertical"
                :aria-label="label"
                @keydown="handleMenuKeydown"
            >
                <component
                    :is="item.href ? Link : 'button'"
                    v-for="(item, index) in normalizedItems"
                    :key="item.key"
                    :href="item.href || undefined"
                    :type="item.href ? undefined : 'button'"
                    role="menuitemradio"
                    :aria-checked="index === currentIndex ? 'true' : 'false'"
                    :tabindex="index === activeIndex ? 0 : -1"
                    data-breadcrumb-menu-item
                    class="flex w-full min-w-0 items-center gap-2 rounded-sm px-3 py-2 text-start text-sm text-stone-700 hover:bg-stone-100 focus-visible:bg-stone-100 focus-visible:outline-none dark:text-neutral-200 dark:hover:bg-neutral-800 dark:focus-visible:bg-neutral-800"
                    @click="selectItem(item)"
                >
                    <span class="min-w-0 flex-1 truncate">{{ item.label }}</span>
                    <span
                        v-if="index === currentIndex"
                        class="inline-flex size-5 shrink-0 items-center justify-center rounded-full bg-green-100 text-green-700 dark:bg-green-500/20 dark:text-green-200"
                    >
                        <svg aria-hidden="true" class="size-3.5" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M16.704 5.29a1 1 0 0 1 .006 1.414l-7.25 7.31a1 1 0 0 1-1.42.004L3.29 9.268a1 1 0 1 1 1.414-1.414l4.04 4.04 6.542-6.598a1 1 0 0 1 1.418-.006Z" clip-rule="evenodd" />
                        </svg>
                        <span class="sr-only">{{ t('workspace_hub.breadcrumbs.current') }}</span>
                    </span>
                </component>
            </div>
        </Teleport>
    </span>
</template>
