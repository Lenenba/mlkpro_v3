<script setup>
import { computed, nextTick, onBeforeUnmount, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { isFeatureEnabled } from '@/utils/features';

const isOpen = ref(false);
const { t, locale } = useI18n();
const toggleRef = ref(null);
const menuRef = ref(null);
const menuStyle = ref({});
let listenersBound = false;

const page = usePage();
const companyType = computed(() => page.props.auth?.account?.company?.type ?? null);
const showServices = computed(() => companyType.value !== 'products');
const showProducts = computed(() => true);
const isOwner = computed(() => Boolean(page.props.auth?.account?.is_owner));
const teamPermissions = computed(() => page.props.auth?.account?.team?.permissions || []);
const teamRole = computed(() => page.props.auth?.account?.team?.role || null);
const featureFlags = computed(() => page.props.auth?.account?.features || {});
const hasFeature = (key) => isFeatureEnabled(featureFlags.value, key);
const canSales = computed(() =>
    isOwner.value || teamPermissions.value.includes('sales.manage') || teamPermissions.value.includes('sales.pos')
);
const isSeller = computed(() => teamRole.value === 'seller');

const quickPalette = {
    customer: { accent: '#8b5cf6', glow: 'rgba(139,92,246,0.35)', label: '#6d28d9', bg: 'rgba(139,92,246,0.12)' },
    service: { accent: '#10b981', glow: 'rgba(16,185,129,0.35)', label: '#0f766e', bg: 'rgba(16,185,129,0.12)' },
    request: { accent: '#06b6d4', glow: 'rgba(6,182,212,0.35)', label: '#0e7490', bg: 'rgba(6,182,212,0.12)' },
    quote: { accent: '#f59e0b', glow: 'rgba(245,158,11,0.35)', label: '#b45309', bg: 'rgba(245,158,11,0.12)' },
    product: { accent: '#3b82f6', glow: 'rgba(59,130,246,0.35)', label: '#1d4ed8', bg: 'rgba(59,130,246,0.12)' },
    default: { accent: '#10b981', glow: 'rgba(16,185,129,0.35)', label: '#0f766e', bg: 'rgba(16,185,129,0.12)' },
};

const itemStyle = (item) => {
    const tone = quickPalette[item.tone] || quickPalette.default;
    return {
        '--quick-accent': tone.accent,
        '--quick-glow': tone.glow,
        '--quick-label': tone.label,
        '--quick-bg': tone.bg,
    };
};

const menuItems = computed(() => {
    locale.value;
    const items = [];

    if (!isSeller.value && ((isOwner.value && showServices.value) || (companyType.value === 'products' && hasFeature('sales') && canSales.value))) {
        items.push({
            label: t('quick_create.customer'),
            overlay: '#hs-quick-create-customer',
            icon: `<svg xmlns="http://www.w3.org/2000/svg" class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg>`,
            tone: 'customer',
        });
        if (hasFeature('services') && showServices.value && isOwner.value) {
            items.push({
                label: t('quick_create.service'),
                overlay: '#hs-quick-create-service',
                icon: `<svg xmlns="http://www.w3.org/2000/svg" class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a4 4 0 0 0-5.66 5.66l-6.34 6.34a2 2 0 0 0 2.83 2.83l6.34-6.34a4 4 0 0 0 5.66-5.66l-2.12 2.12-2.83-2.83 2.12-2.12z"/></svg>`,
                tone: 'service',
            });
        }
        if (hasFeature('requests') && showServices.value && isOwner.value) {
            items.push({
                label: t('quick_create.request'),
                overlay: '#hs-quick-create-request',
                icon: `<svg xmlns="http://www.w3.org/2000/svg" class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 6h13"/><path d="M8 12h13"/><path d="M8 18h13"/><path d="M3 6h.01"/><path d="M3 12h.01"/><path d="M3 18h.01"/></svg>`,
                tone: 'request',
            });
        }
        if (hasFeature('quotes') && showServices.value && isOwner.value) {
            items.push({
                label: t('quick_create.quote'),
                overlay: '#hs-quick-create-quote',
                icon: `<svg xmlns="http://www.w3.org/2000/svg" class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2Z"/><path d="M14 2v6h6"/><path d="M8 13h8"/><path d="M8 17h5"/></svg>`,
                tone: 'quote',
            });
        }
    }

    if (isOwner.value && showProducts.value && hasFeature('products')) {
        items.push({
            label: t('quick_create.product'),
            overlay: '#hs-quick-create-product',
            icon: `<svg xmlns="http://www.w3.org/2000/svg" class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7.5 4.27 9 5.15"/><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/></svg>`,
            tone: 'product',
        });
    }

    return items;
});

const hasItems = computed(() => menuItems.value.length > 0);

const toggleMenu = () => {
    isOpen.value = !isOpen.value;
    if (isOpen.value) {
        nextTick(() => {
            updatePosition();
            addListeners();
        });
    } else {
        removeListeners();
    }
};

const closeMenu = () => {
    isOpen.value = false;
    removeListeners();
};

const openItem = (item) => {
    if (item.overlay && window.HSOverlay) {
        window.HSOverlay.open(item.overlay);
    }
    closeMenu();
};

const updatePosition = () => {
    const button = toggleRef.value;
    if (!button) {
        return;
    }
    const rect = button.getBoundingClientRect();
    let top = rect.top;
    if (menuRef.value) {
        const menuRect = menuRef.value.getBoundingClientRect();
        const maxTop = window.innerHeight - menuRect.height - 12;
        top = Math.max(12, Math.min(top, maxTop));
    }
    menuStyle.value = {
        left: `${rect.right + 12}px`,
        top: `${top}px`,
    };
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

onBeforeUnmount(() => {
    removeListeners();
});
</script>

<template>
    <div v-if="hasItems" class="relative">
        <button
            ref="toggleRef"
            class="p-2 rounded-sm hover:bg-stone-100 dark:bg-neutral-900 dark:hover:bg-neutral-800 transition quick-toggle"
            :class="{ 'quick-toggle-active': isOpen }"
            :aria-expanded="isOpen ? 'true' : 'false'"
            @click="toggleMenu">
            <slot name="toggle-icon">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-stone-400 dark:text-neutral-400"
                    viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                    stroke-linejoin="round">
                    <path d="M4 6h16M4 12h16M4 18h16" />
                </svg>
            </slot>
        </button>
        <Teleport to="body">
            <div v-if="isOpen"
                ref="menuRef"
                class="fixed bg-white dark:bg-neutral-900 shadow-lg border border-stone-200 dark:border-neutral-700 rounded-sm z-[90] flex"
                :style="menuStyle">
                <ul class="flex">
                    <li v-for="item in menuItems" :key="item.label" :style="itemStyle(item)"
                        class="p-4 flex flex-col justify-center items-center quick-menu-item transition cursor-pointer">
                        <button type="button" class="flex flex-col items-center w-full" @click="openItem(item)">
                            <span class="mb-2 text-stone-500 dark:text-neutral-400 quick-menu-icon" v-html="item.icon"></span>
                            <span class="text-sm text-stone-800 dark:text-neutral-200 quick-menu-label">{{ item.label }}</span>
                        </button>
                    </li>
                </ul>
            </div>
        </Teleport>
    </div>
</template>

<style scoped>
.quick-toggle {
    --quick-accent: #10b981;
    --quick-glow: rgba(16, 185, 129, 0.35);
}

.quick-toggle :deep(svg) {
    transition: color 200ms ease, transform 200ms ease, filter 200ms ease;
}

.quick-toggle-active :deep(svg) {
    color: var(--quick-accent) !important;
    filter: drop-shadow(0 0 6px var(--quick-glow));
    animation: quickPulse 1.4s ease-in-out infinite, quickTilt 1.4s ease-in-out infinite;
    transform-origin: 50% 50%;
}

.quick-toggle:hover :deep(svg) {
    color: var(--quick-accent);
    filter: drop-shadow(0 0 6px var(--quick-glow));
    animation: quickPulse 1.4s ease-in-out infinite, quickTilt 1.4s ease-in-out infinite;
    transform-origin: 50% 50%;
}

.quick-menu-item {
    border-radius: 6px;
}

.quick-menu-icon {
    color: var(--quick-accent);
    filter: drop-shadow(0 0 3px var(--quick-glow));
}

.quick-menu-label {
    color: var(--quick-label, #0f766e);
}

.quick-menu-item:hover {
    background: var(--quick-bg, rgba(16, 185, 129, 0.08));
}

.quick-menu-item:hover .quick-menu-icon {
    filter: drop-shadow(0 0 6px var(--quick-glow));
    transform: translateY(-2px) rotate(-4deg);
}

.quick-menu-icon :deep(svg) {
    transition: color 200ms ease, transform 200ms ease, filter 200ms ease;
}

.quick-menu-label {
    transition: color 200ms ease;
}

@keyframes quickPulse {
    0%, 100% {
        transform: scale(1);
    }
    50% {
        transform: scale(1.08);
    }
}

@keyframes quickTilt {
    0%, 100% {
        transform: rotate(0deg);
    }
    40% {
        transform: rotate(10deg);
    }
    70% {
        transform: rotate(-6deg);
    }
}

@media (prefers-reduced-motion: reduce) {
    .quick-toggle-active :deep(svg),
    .quick-toggle:hover :deep(svg),
    .quick-menu-item:hover .quick-menu-icon :deep(svg) {
        animation: none;
    }
}
</style>
