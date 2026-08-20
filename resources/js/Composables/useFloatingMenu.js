import { nextTick, onBeforeUnmount, ref } from 'vue';

const hiddenMenuStyle = () => ({ visibility: 'hidden' });
let activeFloatingMenuClose = null;

export const resolveFloatingMenuPosition = ({
    toggleRect,
    menuRect,
    viewportWidth,
    viewportHeight,
    padding = 12,
    offset = 8,
    align = 'end',
}) => {
    const maximumLeft = Math.max(padding, viewportWidth - menuRect.width - padding);
    const preferredLeft = align === 'start'
        ? toggleRect.left
        : toggleRect.right - menuRect.width;
    const left = Math.max(padding, Math.min(preferredLeft, maximumLeft));
    const belowTop = toggleRect.bottom + offset;
    const aboveTop = toggleRect.top - menuRect.height - offset;
    const fitsBelow = belowTop + menuRect.height <= viewportHeight - padding;
    const fitsAbove = aboveTop >= padding;
    const availableBelow = viewportHeight - toggleRect.bottom;
    const availableAbove = toggleRect.top;
    const shouldOpenAbove = !fitsBelow && (fitsAbove || availableAbove > availableBelow);
    const maximumTop = Math.max(padding, viewportHeight - menuRect.height - padding);
    const preferredTop = shouldOpenAbove ? aboveTop : belowTop;
    const top = Math.max(padding, Math.min(preferredTop, maximumTop));

    return { left, top };
};

export function useFloatingMenu(options = {}) {
    const isOpen = ref(false);
    const toggleRef = ref(null);
    const menuRef = ref(null);
    const menuStyle = ref(hiddenMenuStyle());
    let listenersBound = false;
    let positionFrame = null;

    const padding = options.padding ?? 12;
    const offset = options.offset ?? 8;
    const align = options.align ?? 'end';

    const cancelPositionFrame = () => {
        if (positionFrame === null || typeof window === 'undefined') {
            return;
        }

        window.cancelAnimationFrame?.(positionFrame);
        positionFrame = null;
    };

    const updatePosition = () => {
        const button = toggleRef.value;
        const menu = menuRef.value;

        if (!button || !menu || typeof window === 'undefined') {
            return;
        }

        const toggleRect = button.getBoundingClientRect();
        const toggleIsOutsideViewport = toggleRect.bottom < padding
            || toggleRect.top > window.innerHeight - padding
            || toggleRect.right < padding
            || toggleRect.left > window.innerWidth - padding;

        if (toggleIsOutsideViewport) {
            closeMenu();
            return;
        }

        const position = resolveFloatingMenuPosition({
            toggleRect,
            menuRect: menu.getBoundingClientRect(),
            viewportWidth: window.innerWidth,
            viewportHeight: window.innerHeight,
            padding,
            offset,
            align,
        });

        menuStyle.value = {
            left: `${position.left}px`,
            top: `${position.top}px`,
            visibility: 'visible',
        };
    };

    const schedulePositionUpdate = () => {
        if (positionFrame !== null || typeof window === 'undefined') {
            return;
        }

        if (typeof window.requestAnimationFrame !== 'function') {
            updatePosition();
            return;
        }

        positionFrame = window.requestAnimationFrame(() => {
            positionFrame = null;
            updatePosition();
        });
    };

    const removeListeners = () => {
        if (!listenersBound || typeof window === 'undefined') {
            return;
        }

        window.removeEventListener('resize', schedulePositionUpdate);
        window.removeEventListener('scroll', schedulePositionUpdate, true);
        document.removeEventListener('click', handleOutsideClick, true);
        document.removeEventListener('focusin', handleOutsideFocus, true);
        document.removeEventListener('keydown', handleEscape, true);
        listenersBound = false;
    };

    const closeMenu = () => {
        isOpen.value = false;
        menuStyle.value = hiddenMenuStyle();
        cancelPositionFrame();
        removeListeners();

        if (activeFloatingMenuClose === closeMenu) {
            activeFloatingMenuClose = null;
        }
    };

    const handleOutsideClick = (event) => {
        if (!isOpen.value) {
            return;
        }

        const target = event.target;
        if (toggleRef.value?.contains(target) || menuRef.value?.contains(target)) {
            return;
        }

        closeMenu();
    };

    const handleOutsideFocus = (event) => {
        if (!isOpen.value) {
            return;
        }

        const target = event.target;
        if (toggleRef.value?.contains(target) || menuRef.value?.contains(target)) {
            return;
        }

        closeMenu();
    };

    const handleEscape = (event) => {
        if (event.key !== 'Escape' || !isOpen.value) {
            return;
        }

        event.preventDefault();
        closeMenu();
        nextTick(() => {
            const toggle = toggleRef.value;
            const focusTarget = toggle?.matches?.('button, a, [tabindex]')
                ? toggle
                : toggle?.querySelector?.('button, a, [tabindex]');

            focusTarget?.focus();
        });
    };

    const addListeners = () => {
        if (listenersBound || typeof window === 'undefined') {
            return;
        }

        window.addEventListener('resize', schedulePositionUpdate);
        window.addEventListener('scroll', schedulePositionUpdate, true);
        document.addEventListener('click', handleOutsideClick, true);
        document.addEventListener('focusin', handleOutsideFocus, true);
        document.addEventListener('keydown', handleEscape, true);
        listenersBound = true;
    };

    const openMenu = () => {
        if (isOpen.value) {
            return;
        }

        if (activeFloatingMenuClose && activeFloatingMenuClose !== closeMenu) {
            activeFloatingMenuClose();
        }

        activeFloatingMenuClose = closeMenu;
        menuStyle.value = hiddenMenuStyle();
        isOpen.value = true;

        nextTick(() => {
            if (!isOpen.value) {
                return;
            }

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
        closeMenu();
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
