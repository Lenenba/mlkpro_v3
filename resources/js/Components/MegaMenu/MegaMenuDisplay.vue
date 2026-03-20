<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import MegaMenuBlockRenderer from '@/Components/MegaMenu/MegaMenuBlockRenderer.vue';

const props = defineProps({
    menu: {
        type: Object,
        default: () => ({}),
    },
    fallbackItems: {
        type: Array,
        default: () => [],
    },
    preview: {
        type: Boolean,
        default: false,
    },
    defaultOpenFirstPanel: {
        type: Boolean,
        default: false,
    },
});

const desktopOpenKey = ref(null);
const mobileOpen = ref(false);
const mobilePanelKeys = ref([]);
const rootRef = ref(null);
const closeDesktopPanelTimer = ref(null);
const desktopPanelMetrics = ref({
    left: 0,
    top: 0,
    viewportWidth: 0,
});

const normalizeItem = (item, index) => ({
    id: item?.id ?? null,
    builder_key: item?.builder_key || `fallback-${index}`,
    label: item?.label || 'Menu item',
    description: item?.description || '',
    panel_type: item?.panel_type || 'link',
    resolved_href: item?.resolved_href || item?.href || item?.link_value || null,
    link_target: item?.link_target || item?.target || '_self',
    badge_text: item?.badge_text || item?.badge || '',
    badge_variant: item?.badge_variant || null,
    is_visible: item?.is_visible ?? item?.visible ?? true,
    children: (item?.children || []).map(normalizeItem),
    columns: (item?.columns || []).map((column, columnIndex) => ({
        id: column?.id ?? null,
        builder_key: column?.builder_key || `column-${index}-${columnIndex}`,
        title: column?.title || '',
        width: column?.width || '1fr',
        settings: column?.settings || {},
        blocks: (column?.blocks || []).map((block, blockIndex) => ({
            id: block?.id ?? null,
            builder_key: block?.builder_key || `block-${index}-${columnIndex}-${blockIndex}`,
            ...block,
        })),
    })),
});

const normalizedMenu = computed(() => {
    const sourceItems = Array.isArray(props.menu?.items) && props.menu.items.length
        ? props.menu.items
        : props.fallbackItems;

    return {
        ...props.menu,
        settings: props.menu?.settings || {},
        items: sourceItems.map((item, index) => normalizeItem(item, index))
            .filter((item) => item.is_visible !== false),
    };
});

const panelMaxWidth = computed(() => {
    const width = normalizedMenu.value.settings?.container_width || 'xl';

    if (width === 'full') return 1440;
    if (width === '2xl') return 1320;
    if (width === 'lg') return 1040;
    return 1180;
});

const menuStyle = computed(() => ({
    '--mega-menu-accent': normalizedMenu.value.settings?.accent_color || '#16a34a',
    '--mega-menu-panel': normalizedMenu.value.settings?.panel_background || '#ffffff',
}));

const megaPanelStyle = computed(() => ({
    width: `min(calc(100vw - 48px), ${panelMaxWidth.value}px)`,
}));

const desktopPanelShellStyle = computed(() => ({
    left: `${desktopPanelMetrics.value.left}px`,
    top: `${desktopPanelMetrics.value.top}px`,
    width: `${desktopPanelMetrics.value.viewportWidth || (typeof window !== 'undefined' ? window.innerWidth : panelMaxWidth.value)}px`,
}));

const itemKey = (item, index = 0) => String(item.id ?? item.builder_key ?? `${item.label}-${index}`);
const hasPanel = (item) => item.panel_type === 'classic' || item.panel_type === 'mega';
const itemHref = (item) => String(item.resolved_href || '').trim() || '#';
const itemTarget = (item) => (item.link_target === '_blank' ? '_blank' : undefined);
const itemRel = (item) => (item.link_target === '_blank' ? 'noopener noreferrer' : undefined);
const shouldOpenOnHover = computed(() => normalizedMenu.value.settings?.open_on_hover !== false);
const activeDesktopItem = computed(() =>
    normalizedMenu.value.items.find((item, index) => itemKey(item, index) === desktopOpenKey.value) || null
);

const mainColumns = (item) => (item.columns || []).filter((column) => (column.settings?.row || 'main') !== 'footer');
const footerColumns = (item) => (item.columns || []).filter((column) => (column.settings?.row || 'main') === 'footer');

const columnGridStyle = (columns) => ({
    gridTemplateColumns: columns.map((column) => column.width || '1fr').join(' '),
});

const openDesktopPanel = (item) => {
    if (!hasPanel(item)) {
        return;
    }

    if (closeDesktopPanelTimer.value) {
        window.clearTimeout(closeDesktopPanelTimer.value);
        closeDesktopPanelTimer.value = null;
    }

    desktopOpenKey.value = itemKey(item);
};

const closeDesktopPanel = () => {
    if (closeDesktopPanelTimer.value) {
        window.clearTimeout(closeDesktopPanelTimer.value);
        closeDesktopPanelTimer.value = null;
    }

    desktopOpenKey.value = null;
};

const clearDesktopCloseTimer = () => {
    if (!closeDesktopPanelTimer.value) {
        return;
    }

    window.clearTimeout(closeDesktopPanelTimer.value);
    closeDesktopPanelTimer.value = null;
};

const scheduleDesktopPanelClose = () => {
    if (typeof window === 'undefined') {
        closeDesktopPanel();
        return;
    }

    clearDesktopCloseTimer();
    closeDesktopPanelTimer.value = window.setTimeout(() => {
        desktopOpenKey.value = null;
        closeDesktopPanelTimer.value = null;
    }, 180);
};

const toggleDesktopPanel = (item, event) => {
    if (!hasPanel(item)) {
        return;
    }

    if (props.preview) {
        event?.preventDefault();
    }

    const key = itemKey(item);
    desktopOpenKey.value = desktopOpenKey.value === key ? null : key;
};

const toggleMobilePanel = (item) => {
    const key = itemKey(item);
    mobilePanelKeys.value = mobilePanelKeys.value.includes(key)
        ? mobilePanelKeys.value.filter((entry) => entry !== key)
        : [...mobilePanelKeys.value, key];
};

const isDesktopOpen = (item) => desktopOpenKey.value === itemKey(item);
const isMobileOpen = (item) => mobilePanelKeys.value.includes(itemKey(item));

const syncDesktopPanelMetrics = () => {
    if (typeof window === 'undefined' || !rootRef.value) {
        return;
    }

    const rootRect = rootRef.value.getBoundingClientRect();
    const headerRect = rootRef.value.closest('header')?.getBoundingClientRect() ?? rootRect;

    desktopPanelMetrics.value = {
        left: -rootRect.left,
        top: Math.max(headerRect.bottom - rootRect.top, rootRect.height),
        viewportWidth: window.innerWidth,
    };
};

watch(
    () => normalizedMenu.value.items.map((item, index) => itemKey(item, index)).join('|'),
    async () => {
        mobilePanelKeys.value = [];

        if (props.preview && props.defaultOpenFirstPanel) {
            const firstPanelItem = normalizedMenu.value.items.find((item) => hasPanel(item));
            desktopOpenKey.value = firstPanelItem ? itemKey(firstPanelItem) : null;
        } else {
            desktopOpenKey.value = null;
        }

        await nextTick();
        syncDesktopPanelMetrics();
    },
    { immediate: true }
);

onMounted(() => {
    syncDesktopPanelMetrics();
    window.addEventListener('resize', syncDesktopPanelMetrics);
});

onBeforeUnmount(() => {
    clearDesktopCloseTimer();
    window.removeEventListener('resize', syncDesktopPanelMetrics);
});
</script>

<template>
    <div
        ref="rootRef"
        class="relative w-full"
        :style="menuStyle"
        @mouseenter="clearDesktopCloseTimer"
        @mouseleave="shouldOpenOnHover ? scheduleDesktopPanelClose() : null"
    >
        <div class="hidden w-full min-w-0 items-center justify-center gap-6 lg:flex xl:gap-8 2xl:gap-10">
            <div
                v-for="(item, index) in normalizedMenu.items"
                :key="itemKey(item, index)"
                class="shrink-0"
                @mouseenter="shouldOpenOnHover ? openDesktopPanel(item) : null"
                @mouseleave="shouldOpenOnHover ? scheduleDesktopPanelClose() : null"
            >
                <a
                    :href="preview ? '#' : itemHref(item)"
                    :target="itemTarget(item)"
                    :rel="itemRel(item)"
                    class="inline-flex items-center gap-2 whitespace-nowrap border-b-2 border-transparent px-1 py-5 text-[14px] font-medium leading-none text-stone-700 transition hover:text-stone-950 dark:text-neutral-200 dark:hover:text-white"
                    :class="isDesktopOpen(item) ? 'text-stone-950 dark:text-white' : ''"
                    :style="isDesktopOpen(item) ? { borderColor: 'var(--mega-menu-accent)' } : null"
                    @click="hasPanel(item) ? toggleDesktopPanel(item, $event) : (preview ? $event.preventDefault() : null)"
                >
                    <span class="whitespace-nowrap">{{ item.label }}</span>
                    <span
                        v-if="item.badge_text"
                        class="hidden rounded-sm bg-stone-100 px-2 py-1 text-[9px] font-semibold uppercase tracking-[0.14em] text-stone-700 xl:inline-flex dark:bg-neutral-800 dark:text-neutral-200"
                    >
                        {{ item.badge_text }}
                    </span>
                </a>
            </div>
        </div>

        <div
            v-if="activeDesktopItem && activeDesktopItem.panel_type === 'classic'"
            class="absolute z-40 border-y border-stone-200 bg-[var(--mega-menu-panel)] shadow-[0_24px_60px_-35px_rgba(15,23,42,0.28)] dark:border-neutral-700 dark:bg-neutral-900"
            :style="desktopPanelShellStyle"
            @mouseenter="clearDesktopCloseTimer"
            @mouseleave="shouldOpenOnHover ? scheduleDesktopPanelClose() : null"
        >
            <div class="mx-auto" :style="megaPanelStyle">
                <div class="grid gap-10 px-8 py-8 md:grid-cols-2 xl:grid-cols-3">
                    <a
                        v-for="(child, childIndex) in activeDesktopItem.children"
                        :key="itemKey(child, childIndex)"
                        :href="preview ? '#' : itemHref(child)"
                        :target="itemTarget(child)"
                        :rel="itemRel(child)"
                        class="block py-1 text-[15px] font-medium text-stone-800 transition hover:text-stone-600 dark:text-neutral-100 dark:hover:text-neutral-300"
                        @click="preview ? $event.preventDefault() : null"
                    >
                        <div>{{ child.label }}</div>
                        <div v-if="child.description" class="mt-1 text-sm font-normal leading-6 text-stone-500 dark:text-neutral-400">{{ child.description }}</div>
                    </a>
                </div>
            </div>
        </div>

        <div
            v-if="activeDesktopItem && activeDesktopItem.panel_type === 'mega'"
            class="absolute z-40 border-y border-stone-200 bg-[var(--mega-menu-panel)] shadow-[0_24px_60px_-35px_rgba(15,23,42,0.3)] dark:border-neutral-700 dark:bg-neutral-900"
            :style="desktopPanelShellStyle"
            @mouseenter="clearDesktopCloseTimer"
            @mouseleave="shouldOpenOnHover ? scheduleDesktopPanelClose() : null"
        >
            <div class="mx-auto" :style="megaPanelStyle">
                <div class="px-8 py-8">
                    <div
                        v-if="mainColumns(activeDesktopItem).length"
                        class="grid gap-10"
                        :style="columnGridStyle(mainColumns(activeDesktopItem))"
                    >
                        <section
                            v-for="(column, columnIndex) in mainColumns(activeDesktopItem)"
                            :key="column.builder_key || column.id || columnIndex"
                            class="space-y-4"
                        >
                            <div v-if="column.title" class="text-[11px] font-semibold uppercase tracking-[0.18em] text-stone-500 dark:text-neutral-400">
                                {{ column.title }}
                            </div>
                            <MegaMenuBlockRenderer
                                v-for="(block, blockIndex) in column.blocks"
                                :key="block.builder_key || block.id || blockIndex"
                                :block="block"
                                :preview="preview"
                            />
                        </section>
                    </div>

                    <div
                        v-if="footerColumns(activeDesktopItem).length"
                        class="mt-8 border-t border-stone-200 pt-6 dark:border-neutral-700"
                    >
                        <div class="grid gap-6" :style="columnGridStyle(footerColumns(activeDesktopItem))">
                            <section
                                v-for="(column, columnIndex) in footerColumns(activeDesktopItem)"
                                :key="column.builder_key || column.id || `footer-${columnIndex}`"
                                class="space-y-4"
                            >
                                <div v-if="column.title" class="text-[11px] font-semibold uppercase tracking-[0.18em] text-stone-500 dark:text-neutral-400">
                                    {{ column.title }}
                                </div>
                                <MegaMenuBlockRenderer
                                    v-for="(block, blockIndex) in column.blocks"
                                    :key="block.builder_key || block.id || `footer-block-${blockIndex}`"
                                    :block="block"
                                    :preview="preview"
                                />
                            </section>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="lg:hidden">
            <button
                type="button"
                class="inline-flex items-center gap-2 rounded-full border border-stone-300 bg-white px-4 py-2 text-sm font-semibold text-stone-800 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100"
                @click="mobileOpen = !mobileOpen"
            >
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M4 12h16" />
                    <path d="M4 6h16" />
                    <path d="M4 18h16" />
                </svg>
                <span>Menu</span>
            </button>

            <div
                v-if="mobileOpen"
                class="mt-3 overflow-hidden rounded-[20px] border border-stone-200 bg-white shadow-xl dark:border-neutral-700 dark:bg-neutral-900"
            >
                <div class="space-y-2 p-3">
                    <div
                        v-for="(item, index) in normalizedMenu.items"
                        :key="itemKey(item, index)"
                        class="rounded-[18px] border border-stone-200/80 bg-white dark:border-neutral-700 dark:bg-neutral-900"
                    >
                        <div class="flex items-center gap-2 p-3">
                            <a
                                :href="preview ? '#' : itemHref(item)"
                                :target="itemTarget(item)"
                                :rel="itemRel(item)"
                                class="min-w-0 flex-1 text-sm font-semibold text-stone-900 dark:text-neutral-100"
                                @click="preview ? $event.preventDefault() : null"
                            >
                                {{ item.label }}
                            </a>
                            <button
                                v-if="hasPanel(item)"
                                type="button"
                                class="rounded-full p-1 text-stone-500 hover:bg-stone-100 dark:text-neutral-400 dark:hover:bg-neutral-800"
                                @click="toggleMobilePanel(item)"
                            >
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 transition" :class="isMobileOpen(item) ? 'rotate-180' : ''" viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.104l3.71-3.873a.75.75 0 1 1 1.08 1.04l-4.24 4.43a.75.75 0 0 1-1.08 0L5.21 8.27a.75.75 0 0 1 .02-1.06z" clip-rule="evenodd" />
                                </svg>
                            </button>
                        </div>

                        <div v-if="isMobileOpen(item) && item.panel_type === 'classic'" class="space-y-2 border-t border-stone-200 p-3 dark:border-neutral-700">
                            <a
                                v-for="(child, childIndex) in item.children"
                                :key="itemKey(child, childIndex)"
                                :href="preview ? '#' : itemHref(child)"
                                class="block text-sm font-medium text-stone-800 dark:text-neutral-100"
                                @click="preview ? $event.preventDefault() : null"
                            >
                                {{ child.label }}
                            </a>
                        </div>

                        <div v-if="isMobileOpen(item) && item.panel_type === 'mega'" class="space-y-5 border-t border-stone-200 p-3 dark:border-neutral-700">
                            <section
                                v-for="(column, columnIndex) in mainColumns(item)"
                                :key="column.builder_key || column.id || columnIndex"
                                class="space-y-3"
                            >
                                <div v-if="column.title" class="text-[11px] font-semibold uppercase tracking-[0.18em] text-stone-500 dark:text-neutral-400">
                                    {{ column.title }}
                                </div>
                                <MegaMenuBlockRenderer
                                    v-for="(block, blockIndex) in column.blocks"
                                    :key="block.builder_key || block.id || blockIndex"
                                    :block="block"
                                    :preview="preview"
                                />
                            </section>

                            <div v-if="footerColumns(item).length" class="space-y-4 border-t border-stone-200 pt-4 dark:border-neutral-700">
                                <section
                                    v-for="(column, columnIndex) in footerColumns(item)"
                                    :key="column.builder_key || column.id || `mobile-footer-${columnIndex}`"
                                    class="space-y-3"
                                >
                                    <div v-if="column.title" class="text-[11px] font-semibold uppercase tracking-[0.18em] text-stone-500 dark:text-neutral-400">
                                        {{ column.title }}
                                    </div>
                                    <MegaMenuBlockRenderer
                                        v-for="(block, blockIndex) in column.blocks"
                                        :key="block.builder_key || block.id || `mobile-footer-block-${blockIndex}`"
                                        :block="block"
                                        :preview="preview"
                                    />
                                </section>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
