import { nextTick, onBeforeUnmount, ref } from 'vue';

export function useFloatingMenu(options = {}) {
    const isOpen = ref(false);
    const toggleRef = ref(null);
    const menuRef = ref(null);
    const menuStyle = ref({});
    let listenersBound = false;

    const padding = options.padding ?? 12;
    const offset = options.offset ?? 8;

    const updatePosition = () => {
        const button = toggleRef.value;
        const menu = menuRef.value;

        if (!button || !menu) {
            return;
        }

        const rect = button.getBoundingClientRect();
        const menuRect = menu.getBoundingClientRect();

        let left = rect.right - menuRect.width;
        if (left < padding) {
            left = padding;
        }
        if (left + menuRect.width > window.innerWidth - padding) {
            left = Math.max(padding, window.innerWidth - menuRect.width - padding);
        }

        let top = rect.bottom + offset;
        const maxTop = window.innerHeight - menuRect.height - padding;
        if (top > maxTop) {
            top = Math.max(padding, rect.top - menuRect.height - offset);
        }

        menuStyle.value = {
            left: `${left}px`,
            top: `${top}px`,
        };
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

    const handleEscape = (event) => {
        if (event.key === 'Escape' && isOpen.value) {
            event.preventDefault();
            closeMenu();
        }
    };

    const addListeners = () => {
        if (listenersBound) {
            return;
        }

        window.addEventListener('resize', updatePosition);
        window.addEventListener('scroll', updatePosition, true);
        document.addEventListener('click', handleOutsideClick, true);
        document.addEventListener('keydown', handleEscape, true);
        listenersBound = true;
    };

    const removeListeners = () => {
        if (!listenersBound) {
            return;
        }

        window.removeEventListener('resize', updatePosition);
        window.removeEventListener('scroll', updatePosition, true);
        document.removeEventListener('click', handleOutsideClick, true);
        document.removeEventListener('keydown', handleEscape, true);
        listenersBound = false;
    };

    const openMenu = () => {
        isOpen.value = true;
        nextTick(() => {
            updatePosition();
            addListeners();
        });
    };

    const toggleMenu = () => {
        if (isOpen.value) {
            closeMenu();
            return;
        }

        openMenu();
    };

    onBeforeUnmount(() => {
        removeListeners();
    });

    return {
        isOpen,
        toggleRef,
        menuRef,
        menuStyle,
        updatePosition,
        openMenu,
        closeMenu,
        toggleMenu,
    };
}
