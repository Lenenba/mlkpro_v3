<script setup>
import { computed, getCurrentInstance, nextTick } from 'vue';
import { useFloatingMenu } from '@/Composables/useFloatingMenu';

const props = defineProps({
    label: {
        type: String,
        default: 'Actions',
    },
    triggerTestId: {
        type: String,
        default: null,
    },
    menuTestId: {
        type: String,
        default: null,
    },
    menuWidthClass: {
        type: String,
        default: 'w-36',
    },
    menuAlign: {
        type: String,
        default: 'end',
        validator: (value) => ['start', 'end'].includes(value),
    },
    disabled: {
        type: Boolean,
        default: false,
    },
});

const menuId = `admin-data-table-actions-${getCurrentInstance()?.uid ?? 'menu'}`;
const resolvedMenuTestId = computed(() => (
    props.menuTestId || (props.triggerTestId ? `${props.triggerTestId}-menu` : null)
));
const {
    isOpen,
    toggleRef,
    menuRef,
    menuStyle,
    openMenu: openFloatingMenu,
    closeMenu,
} = useFloatingMenu({ align: props.menuAlign });

const menuItems = () => Array.from(menuRef.value?.querySelectorAll(
    'a[href], button:not(:disabled), [role="menuitem"]:not([aria-disabled="true"]), [tabindex]:not([tabindex="-1"])',
) || []).filter((element) => !element.hasAttribute('hidden'));

const prepareMenuItems = () => {
    menuItems().forEach((item) => {
        item.setAttribute('role', 'menuitem');
        item.setAttribute('tabindex', '-1');
    });
};

const openMenu = async () => {
    openFloatingMenu();
    await nextTick();

    if (isOpen.value) {
        prepareMenuItems();
    }
};

const toggleMenu = () => {
    if (isOpen.value) {
        closeMenu();
        return;
    }

    openMenu();
};

const focusMenuEdge = async (edge = 'first') => {
    await openMenu();

    const items = menuItems();
    const target = edge === 'last' ? items.at(-1) : items[0];
    target?.focus();
};

const handleTriggerKeydown = (event) => {
    if (event.key === 'ArrowDown') {
        event.preventDefault();
        focusMenuEdge('first');
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        focusMenuEdge('last');
    } else if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        focusMenuEdge('first');
    }
};

const handleMenuKeydown = (event) => {
    if (event.key === 'Tab') {
        closeMenu();
        return;
    }

    if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) {
        return;
    }

    const items = menuItems();
    if (!items.length) {
        return;
    }

    event.preventDefault();
    const currentIndex = items.indexOf(document.activeElement);
    let nextIndex = 0;

    if (event.key === 'End') {
        nextIndex = items.length - 1;
    } else if (event.key === 'ArrowUp') {
        nextIndex = currentIndex <= 0 ? items.length - 1 : currentIndex - 1;
    } else if (event.key === 'ArrowDown') {
        nextIndex = currentIndex < 0 || currentIndex >= items.length - 1 ? 0 : currentIndex + 1;
    }

    items[nextIndex]?.focus();
};

const handleMenuClick = (event) => {
    const action = event.target?.closest?.('a[href], button, [role="menuitem"]');
    if (!action || action.disabled || action.getAttribute('aria-disabled') === 'true') {
        return;
    }

    closeMenu();
};
</script>

<template>
    <span ref="toggleRef" class="relative inline-flex">
        <slot
            name="trigger"
            :toggle="toggleMenu"
            :open="isOpen"
            :menu-id="menuId"
            :keydown="handleTriggerKeydown"
        >
            <button
                type="button"
                class="inline-flex size-7 items-center justify-center gap-x-2 rounded-sm border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 focus:bg-stone-50 focus:outline-none disabled:pointer-events-none disabled:opacity-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300 dark:hover:bg-neutral-700 dark:focus:bg-neutral-700"
                aria-haspopup="menu"
                :aria-controls="menuId"
                :aria-expanded="isOpen"
                :aria-label="label"
                :data-testid="triggerTestId || undefined"
                :disabled="disabled"
                @click="toggleMenu"
                @keydown="handleTriggerKeydown"
            >
                <svg class="size-3.5 shrink-0" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="1" />
                    <circle cx="12" cy="5" r="1" />
                    <circle cx="12" cy="19" r="1" />
                </svg>
            </button>
        </slot>
    </span>

    <Teleport to="body">
        <div
            v-if="isOpen"
            :id="menuId"
            ref="menuRef"
            class="fixed z-[90] max-h-[calc(100vh-1.5rem)] overflow-y-auto rounded-sm border border-stone-200 bg-white shadow-[0_10px_40px_10px_rgba(0,0,0,0.12)] dark:border-neutral-700 dark:bg-neutral-900 dark:shadow-[0_10px_40px_10px_rgba(0,0,0,0.28)]"
            :class="menuWidthClass"
            :style="menuStyle"
            :data-testid="resolvedMenuTestId || undefined"
            data-admin-data-table-actions-menu
            role="menu"
            aria-orientation="vertical"
            @click="handleMenuClick"
            @keydown="handleMenuKeydown"
        >
            <div class="p-1">
                <slot />
            </div>
        </div>
    </Teleport>
</template>
