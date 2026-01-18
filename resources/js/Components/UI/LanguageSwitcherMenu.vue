<script setup>
import { computed, nextTick, onBeforeUnmount, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';

const page = usePage();
const availableLocales = computed(() => page.props.locales || ['fr', 'en']);
const currentLocale = computed(() => page.props.locale || availableLocales.value[0] || 'fr');
const hasMultipleLocales = computed(() => availableLocales.value.length > 1);

const isOpen = ref(false);
const toggleRef = ref(null);
const menuRef = ref(null);
const menuStyle = ref({});
let listenersBound = false;

const setLocale = (locale) => {
    if (locale === currentLocale.value) {
        return;
    }
    router.post(route('locale.update'), { locale }, { preserveScroll: true });
    closeMenu();
};

const updatePosition = () => {
    const button = toggleRef.value;
    if (!button || !menuRef.value) {
        return;
    }
    const rect = button.getBoundingClientRect();
    const menuRect = menuRef.value.getBoundingClientRect();
    const padding = 12;
    let left = rect.right + 12;
    if (left + menuRect.width > window.innerWidth - padding) {
        left = Math.max(padding, rect.left - menuRect.width - 12);
    }
    let top = rect.top;
    const maxTop = window.innerHeight - menuRect.height - padding;
    top = Math.max(padding, Math.min(top, maxTop));
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

onBeforeUnmount(() => {
    removeListeners();
});
</script>

<template>
    <div v-if="hasMultipleLocales" class="relative flex justify-center">
        <button
            ref="toggleRef"
            type="button"
            class="flex size-9 items-center justify-center rounded-sm text-stone-600 hover:bg-stone-100 focus:outline-none focus:ring-2 focus:ring-green-500 dark:text-neutral-200 dark:hover:bg-neutral-800"
            :aria-label="$t('account.language')"
            :aria-expanded="isOpen ? 'true' : 'false'"
            @click="toggleMenu"
        >
            <svg class="size-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10" />
                <path d="M2 12h20" />
                <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z" />
            </svg>
        </button>

        <Teleport to="body">
            <div
                v-if="isOpen"
                ref="menuRef"
                class="fixed z-[90] w-44 rounded-md border border-stone-200 bg-white shadow-lg dark:border-neutral-700 dark:bg-neutral-900"
                :style="menuStyle"
                role="menu"
                aria-orientation="vertical"
            >
                <div class="flex items-center justify-between border-b border-stone-200 px-3 py-2 text-sm font-semibold text-stone-700 dark:border-neutral-700 dark:text-neutral-200">
                    <span class="flex items-center gap-2">
                        <svg class="size-4 text-stone-400 dark:text-neutral-400" xmlns="http://www.w3.org/2000/svg"
                            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                            stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="10" />
                            <path d="M2 12h20" />
                            <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z" />
                        </svg>
                        {{ $t(`language.${currentLocale}`) }}
                    </span>
                    <svg class="size-3 text-stone-400 dark:text-neutral-500" xmlns="http://www.w3.org/2000/svg"
                        viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round">
                        <path d="m6 9 6 6 6-6" />
                    </svg>
                </div>
                <div class="p-2">
                    <button
                        v-for="locale in availableLocales"
                        :key="locale"
                        type="button"
                        role="menuitemradio"
                        :aria-checked="currentLocale === locale"
                        class="flex w-full items-center gap-2 rounded-sm px-2 py-1.5 text-sm text-stone-700 hover:bg-stone-100 dark:text-neutral-200 dark:hover:bg-neutral-800"
                        @click="setLocale(locale)"
                    >
                        <span
                            class="flex size-4 items-center justify-center rounded-full border border-stone-300 dark:border-neutral-600"
                        >
                            <span
                                v-if="currentLocale === locale"
                                class="size-2 rounded-full bg-green-600"
                            ></span>
                        </span>
                        <span>{{ $t(`language.${locale}`) }}</span>
                    </button>
                </div>
            </div>
        </Teleport>
    </div>
</template>
