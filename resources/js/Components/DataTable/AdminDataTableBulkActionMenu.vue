<script setup>
import {
    computed,
    onBeforeUnmount,
    onMounted,
    ref,
} from 'vue';

const props = defineProps({
    actions: {
        type: Array,
        default: () => [],
    },
    disabled: {
        type: Boolean,
        default: false,
    },
    menuLabelKey: {
        type: String,
        required: true,
    },
    buttonVariant: {
        type: String,
        default: 'neutral',
    },
});

const emit = defineEmits(['select']);

const isOpen = ref(false);
const menuRef = ref(null);

const visibleActions = computed(() => (
    Array.isArray(props.actions)
        ? props.actions.filter((action) => action && typeof action === 'object' && action.key)
        : []
));

const closeMenu = () => {
    isOpen.value = false;
};

const toggleMenu = () => {
    if (props.disabled || !visibleActions.value.length) {
        closeMenu();

        return;
    }

    isOpen.value = !isOpen.value;
};

const handleClickOutside = (event) => {
    if (!isOpen.value) {
        return;
    }

    if (menuRef.value?.contains(event.target)) {
        return;
    }

    closeMenu();
};

const handleEscape = (event) => {
    if (event.key === 'Escape') {
        closeMenu();
    }
};

const handleSelect = (action) => {
    closeMenu();
    emit('select', action);
};

const buttonClasses = computed(() => {
    if (props.buttonVariant === 'primary') {
        return 'inline-flex items-center gap-x-1.5 rounded-sm border border-emerald-700 bg-emerald-700 px-3 py-2 text-xs font-semibold text-white shadow-sm hover:bg-emerald-800 focus:outline-none focus:ring-2 focus:ring-emerald-500 disabled:pointer-events-none disabled:opacity-50 dark:border-emerald-500 dark:bg-emerald-500 dark:text-white dark:hover:bg-emerald-400 action-feedback';
    }

    return 'inline-flex items-center gap-x-1.5 rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-medium text-stone-700 shadow-sm hover:bg-stone-50 focus:outline-none focus:ring-2 focus:ring-green-500 disabled:pointer-events-none disabled:opacity-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800 action-feedback';
});

const actionClasses = (action) => {
    switch (action?.tone) {
        case 'info':
            return 'w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-sky-700 hover:bg-sky-50 dark:text-sky-300 dark:hover:bg-neutral-800 action-feedback';
        case 'success':
            return 'w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-emerald-700 hover:bg-emerald-50 dark:text-emerald-300 dark:hover:bg-neutral-800 action-feedback';
        case 'warning':
            return 'w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-amber-700 hover:bg-amber-50 dark:text-amber-300 dark:hover:bg-neutral-800 action-feedback';
        case 'danger':
            return 'w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-neutral-800 action-feedback';
        default:
            return 'w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800 action-feedback';
    }
};

onMounted(() => {
    document.addEventListener('mousedown', handleClickOutside);
    document.addEventListener('keydown', handleEscape);
});

onBeforeUnmount(() => {
    document.removeEventListener('mousedown', handleClickOutside);
    document.removeEventListener('keydown', handleEscape);
});
</script>

<template>
    <div ref="menuRef" class="relative inline-flex">
        <button
            type="button"
            :disabled="disabled || !visibleActions.length"
            :class="buttonClasses"
            aria-haspopup="menu"
            :aria-expanded="isOpen ? 'true' : 'false'"
            :aria-label="$t(menuLabelKey)"
            @click="toggleMenu"
        >
            {{ $t(menuLabelKey) }}
            <svg class="size-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                <path d="m5 7.5 5 5 5-5" />
            </svg>
        </button>

        <div
            v-show="isOpen"
            class="absolute end-0 top-full z-20 mt-2 w-48 rounded-sm bg-white shadow-[0_10px_40px_10px_rgba(0,0,0,0.08)] dark:bg-neutral-900 dark:shadow-[0_10px_40px_10px_rgba(0,0,0,0.2)]"
            role="menu"
            aria-orientation="vertical"
        >
            <div class="p-1">
                <template v-for="action in visibleActions" :key="action.key">
                    <div
                        v-if="action.divider_before"
                        class="my-1 border-t border-stone-200 dark:border-neutral-800"
                    />
                    <button
                        type="button"
                        :class="actionClasses(action)"
                        @click="handleSelect(action)"
                    >
                        {{ action.label_key ? $t(action.label_key) : action.label }}
                    </button>
                </template>
            </div>
        </div>
    </div>
</template>
