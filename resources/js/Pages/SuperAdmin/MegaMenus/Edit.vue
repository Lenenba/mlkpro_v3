<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import draggable from 'vuedraggable';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Checkbox from '@/Components/Checkbox.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import AssetPickerModal from '@/Components/AssetPickerModal.vue';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import MegaMenuDisplay from '@/Components/MegaMenu/MegaMenuDisplay.vue';
import MegaMenuBlockPayloadEditor from '@/Components/MegaMenu/MegaMenuBlockPayloadEditor.vue';
import {
    applyMegaMenuLocale,
    cloneMegaMenu,
    createMegaMenuBlock,
    createMegaMenuColumn,
    createMegaMenuItem,
    ensureMegaMenuTranslations,
    normalizeMegaMenu,
    persistMegaMenuLocale,
    prepareMegaMenuForSubmit,
} from '@/utils/megaMenuBuilder';

const props = defineProps({
    mode: { type: String, default: 'create' },
    menu: { type: Object, default: () => ({}) },
    meta: { type: Object, default: () => ({}) },
    choices: { type: Object, default: () => ({}) },
    internal_page_options: { type: Array, default: () => [] },
    asset_list_url: { type: String, default: '' },
    asset_upload_url: { type: String, default: '' },
    dashboard_url: { type: String, required: true },
    index_url: { type: String, required: true },
    preview_url: { type: String, default: null },
    activate_url: { type: String, default: null },
    deactivate_url: { type: String, default: null },
});

const page = usePage();
const { t } = useI18n();
const tx = (key, params = {}) => t(`mega_menu.admin.${key}`, params);
const normalizeOptionKey = (value) => {
    const normalized = String(value || '').trim().replace(/^_+/, '');
    return normalized === '2xl' ? 'size_2xl' : normalized;
};
const translateOptionLabel = (prefix, value, fallback = '') => {
    const normalized = normalizeOptionKey(value);
    const translationKey = `mega_menu.admin.options.${prefix}.${normalized}`;
    const translated = t(translationKey);

    return translated === translationKey ? (fallback || String(value || '')) : translated;
};
const blockTypeLabel = (type, fallback = null) => {
    const translationKey = `mega_menu.admin.block_types.${type}`;
    const translated = t(translationKey);

    return translated === translationKey ? (fallback || type) : translated;
};
const isCreateMode = computed(() => props.mode === 'create');
const blockDefinitions = computed(() => props.choices?.block_types || []);
const defaults = computed(() => props.choices?.defaults || {});
const localeList = computed(() => page.props.locales || ['fr', 'en', 'es']);
const fallbackLocale = computed(() => localeList.value[0] || 'fr');
const editorLocale = ref(page.props.locale || fallbackLocale.value);
const localeOptions = computed(() =>
    localeList.value.map((locale) => ({ value: locale, label: locale.toUpperCase() }))
);
const editorLocaleCode = computed(() => String(editorLocale.value || fallbackLocale.value).toUpperCase());

const initialState = normalizeMegaMenu(cloneMegaMenu(props.menu || {}), defaults.value, blockDefinitions.value);
const form = useForm(initialState);
ensureMegaMenuTranslations(form, localeList.value, fallbackLocale.value);
applyMegaMenuLocale(form, editorLocale.value, fallbackLocale.value, localeList.value);

const previewDevice = ref('desktop');
const assetPickerOpen = ref(false);
const assetTarget = ref(null);

const selection = reactive({
    type: 'menu',
    itemKey: null,
    columnKey: null,
    blockKey: null,
});

const optionValues = (key) => props.choices?.[key] || [];
const localizedOptions = (key, prefix) =>
    optionValues(key).map((option) => ({
        ...option,
        label: translateOptionLabel(prefix, option.value, option.label),
    }));
const statusOptions = computed(() => localizedOptions('statuses', 'statuses'));
const locationOptions = computed(() => localizedOptions('display_locations', 'locations'));
const panelTypeOptions = computed(() => localizedOptions('panel_types', 'panel_types'));
const linkTypeOptions = computed(() => localizedOptions('link_types', 'link_types'));
const linkTargetOptions = computed(() => localizedOptions('link_targets', 'link_targets'));
const badgeVariantOptions = computed(() => [
    { value: '', label: tx('options.badge_variants.none') },
    ...optionValues('badge_variants'),
]);
const themeOptions = computed(() => [
    { value: 'default', label: tx('options.themes.default') },
    { value: 'brand', label: tx('options.themes.brand') },
    { value: 'contrast', label: tx('options.themes.contrast') },
]);
const containerWidthOptions = computed(() => [
    { value: 'lg', label: tx('options.container_widths.lg') },
    { value: 'xl', label: tx('options.container_widths.xl') },
    { value: '2xl', label: tx('options.container_widths.size_2xl') },
    { value: 'full', label: tx('options.container_widths.full') },
]);
const alignmentOptions = computed(() => [
    { value: 'start', label: tx('options.alignment.start') },
    { value: 'center', label: tx('options.alignment.center') },
    { value: 'end', label: tx('options.alignment.end') },
]);
const rowOptions = computed(() => [
    { value: 'main', label: tx('options.rows.main') },
    { value: 'footer', label: tx('options.rows.footer') },
]);
const toneOptions = computed(() => [
    { value: 'default', label: tx('options.tones.default') },
    { value: 'muted', label: tx('options.tones.muted') },
    { value: 'contrast', label: tx('options.tones.contrast') },
]);

const findItemContext = (key, items = form.items, parentList = null, parentItem = null) => {
    for (let index = 0; index < items.length; index += 1) {
        const item = items[index];
        if (item.builder_key === key) {
            return { item, list: items, index, parentList, parentItem };
        }
        if (Array.isArray(item.children) && item.children.length) {
            const result = findItemContext(key, item.children, items, item);
            if (result) return result;
        }
    }
    return null;
};

const findColumnContext = (key) => {
    for (const item of form.items) {
        for (let index = 0; index < (item.columns || []).length; index += 1) {
            const column = item.columns[index];
            if (column.builder_key === key) {
                return { column, item, list: item.columns, index };
            }
        }
    }
    return null;
};

const findBlockContext = (key) => {
    for (const item of form.items) {
        for (const column of item.columns || []) {
            for (let index = 0; index < (column.blocks || []).length; index += 1) {
                const block = column.blocks[index];
                if (block.builder_key === key) {
                    return { block, column, item, list: column.blocks, index };
                }
            }
        }
    }
    return null;
};

const selectedItemContext = computed(() => selection.itemKey ? findItemContext(selection.itemKey) : null);
const selectedColumnContext = computed(() => selection.columnKey ? findColumnContext(selection.columnKey) : null);
const selectedBlockContext = computed(() => selection.blockKey ? findBlockContext(selection.blockKey) : null);

const selectedEntity = computed(() => {
    if (selection.type === 'block') return selectedBlockContext.value?.block || null;
    if (selection.type === 'column') return selectedColumnContext.value?.column || null;
    if (selection.type === 'item') return selectedItemContext.value?.item || null;
    return form;
});

const selectionTitle = computed(() => {
    if (selection.type === 'block') {
        const type = selectedBlockContext.value?.block?.type;
        return type
            ? tx('edit.block_settings_with_type', { type: blockTypeLabel(type, type) })
            : tx('edit.block_settings');
    }
    if (selection.type === 'column') return tx('edit.column_settings');
    if (selection.type === 'item') return tx('edit.item_settings');
    return tx('edit.menu_settings');
});

const errorEntries = computed(() => Object.entries(form.errors || {}));
const headTitle = computed(() => (isCreateMode.value
    ? tx('edit.head_create')
    : tx('edit.head_edit', { title: form.title || tx('common.mega_menu') })));
const linkValueLabel = computed(() => {
    if (activeSelection.value?.link_type === 'route') return tx('edit.route_name');
    if (activeSelection.value?.link_type === 'anchor') return tx('edit.anchor');
    return tx('edit.href_or_url');
});

const formatDate = (value) => {
    if (!value) return tx('common.not_available');
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? value : date.toLocaleString();
};

const findBlockDefinition = (type) => blockDefinitions.value.find((definition) => definition.type === type) || blockDefinitions.value[0] || {
    type: 'text',
    label: blockTypeLabel('text', 'text'),
    default_payload: { title: tx('edit.text_block_title'), body: tx('edit.text_block_content') },
};

const createBlock = (type = null, overrides = {}) => {
    const definition = findBlockDefinition(type || blockDefinitions.value[0]?.type || 'navigation_group');

    return createMegaMenuBlock(definition, {
        ...overrides,
        type: definition.type,
        settings: {
            ...(cloneMegaMenu(defaults.value.block_settings) || {}),
            ...(cloneMegaMenu(overrides.settings) || {}),
        },
        payload: cloneMegaMenu(overrides.payload ?? definition.default_payload),
    });
};

const createColumn = (overrides = {}) => {
    const column = createMegaMenuColumn([], {
        ...overrides,
        settings: {
            ...(cloneMegaMenu(defaults.value.column_settings) || {}),
            ...(cloneMegaMenu(overrides.settings) || {}),
        },
        blocks: [],
    });

    column.blocks = Array.isArray(overrides.blocks) && overrides.blocks.length
        ? overrides.blocks.map((block) => createBlock(block.type, block))
        : [createBlock()];

    return column;
};

const createItem = (overrides = {}) => {
    const item = createMegaMenuItem([], {
        ...overrides,
        settings: {
            ...(cloneMegaMenu(defaults.value.item_settings) || {}),
            ...(cloneMegaMenu(overrides.settings) || {}),
        },
        children: [],
        columns: [],
    });

    if (item.panel_type === 'classic') {
        item.children = Array.isArray(overrides.children) && overrides.children.length
            ? overrides.children.map((child) => createItem(child))
            : [createItem({ panel_type: 'link', label: tx('edit.dropdown_link'), link_type: 'internal_page', link_value: '/' })];
    }

    if (item.panel_type === 'mega') {
        item.columns = Array.isArray(overrides.columns) && overrides.columns.length
            ? overrides.columns.map((column) => createColumn(column))
            : [createColumn()];
    }

    return item;
};

const resetSelectionToMenu = () => {
    selection.type = 'menu';
    selection.itemKey = null;
    selection.columnKey = null;
    selection.blockKey = null;
};

const selectMenu = () => resetSelectionToMenu();
const selectItem = (item) => {
    selection.type = 'item';
    selection.itemKey = item.builder_key;
    selection.columnKey = null;
    selection.blockKey = null;
};
const selectColumn = (item, column) => {
    selectItem(item);
    selection.type = 'column';
    selection.columnKey = column.builder_key;
};
const selectBlock = (item, column, block) => {
    selectColumn(item, column);
    selection.type = 'block';
    selection.blockKey = block.builder_key;
};

const ensurePanelStructure = (item) => {
    if (item.panel_type === 'classic') {
        item.columns = [];
        if (!Array.isArray(item.children) || !item.children.length) {
            item.children = [createItem({ panel_type: 'link', label: tx('edit.dropdown_link'), link_type: 'internal_page', link_value: '/' })];
        }
    } else if (item.panel_type === 'mega') {
        item.children = [];
        if (!Array.isArray(item.columns) || !item.columns.length) {
            item.columns = [createColumn()];
        }
    } else {
        item.children = [];
        item.columns = [];
    }
};

const addTopLevelItem = () => {
    const item = createItem({ label: tx('edit.new_menu_item'), panel_type: 'mega' });
    form.items.push(item);
    selectItem(item);
};

const addChildItem = (item) => {
    item.panel_type = 'classic';
    ensurePanelStructure(item);
    const child = createItem({ label: tx('edit.new_child_link'), panel_type: 'link', link_type: 'internal_page', link_value: '/' });
    item.children.push(child);
    selectItem(child);
};

const addColumnToItem = (item) => {
    item.panel_type = 'mega';
    ensurePanelStructure(item);
    const column = createColumn({ title: tx('edit.new_column') });
    item.columns.push(column);
    selectColumn(item, column);
};

const addBlockToColumn = (item, column, type = null) => {
    const block = createBlock(type);
    column.blocks.push(block);
    selectBlock(item, column, block);
};

const duplicateItem = (context) => {
    const copy = createItem({
        ...cloneMegaMenu(context.item),
        id: null,
        label: `${context.item.label} ${tx('edit.copy_suffix')}`,
    });
    context.list.splice(context.index + 1, 0, copy);
    selectItem(copy);
};

const removeItem = (context) => {
    if (!window.confirm(tx('edit.delete_item_confirm', { title: context.item.label }))) return;
    context.list.splice(context.index, 1);
    resetSelectionToMenu();
};

const duplicateColumn = (context) => {
    const copy = createColumn({
        ...cloneMegaMenu(context.column),
        id: null,
        title: `${context.column.title || tx('edit.column_fallback_title')} ${tx('edit.copy_suffix')}`,
    });
    context.list.splice(context.index + 1, 0, copy);
    selectColumn(context.item, copy);
};

const removeColumn = (context) => {
    if (!window.confirm(tx('edit.delete_column_confirm'))) return;
    context.list.splice(context.index, 1);
    if (!context.item.columns.length) {
        context.item.columns.push(createColumn());
    }
    resetSelectionToMenu();
};

const duplicateBlock = (context) => {
    const copy = createBlock(context.block.type, {
        ...cloneMegaMenu(context.block),
        id: null,
        title: `${context.block.title || blockTypeLabel(context.block.type, context.block.type)} ${tx('edit.copy_suffix')}`,
    });
    context.list.splice(context.index + 1, 0, copy);
    selectBlock(context.item, context.column, copy);
};

const removeBlock = (context) => {
    if (!window.confirm(tx('edit.delete_block_confirm'))) return;
    context.list.splice(context.index, 1);
    if (!context.column.blocks.length) {
        context.column.blocks.push(createBlock());
    }
    resetSelectionToMenu();
};

const changeBlockType = (block) => {
    const definition = findBlockDefinition(block.type);
    block.payload = cloneMegaMenu(definition.default_payload);
    if (!block.title) {
        block.title = blockTypeLabel(definition.type, definition.label);
    }
};

const openAssetPicker = (target, field = 'image_url', altField = null) => {
    assetTarget.value = { target, field, altField };
    assetPickerOpen.value = true;
};

const handleAssetSelect = (asset) => {
    if (!assetTarget.value) return;
    assetTarget.value.target[assetTarget.value.field] = asset.url || '';
    if (assetTarget.value.altField && !assetTarget.value.target[assetTarget.value.altField]) {
        assetTarget.value.target[assetTarget.value.altField] = asset.alt || asset.name || '';
    }
    assetPickerOpen.value = false;
    assetTarget.value = null;
};

const closeAssetPicker = () => {
    assetPickerOpen.value = false;
    assetTarget.value = null;
};

const resolveRoutePreview = (name) => {
    try {
        return route(name);
    } catch (error) {
        return null;
    }
};

const previewMenu = computed(() => {
    const decorateItem = (item) => ({
        ...item,
        resolved_href: item.link_type === 'route' ? resolveRoutePreview(item.link_value) : item.link_value,
        children: (item.children || []).map(decorateItem),
        columns: (item.columns || []).map((column) => ({
            ...column,
            blocks: (column.blocks || []).map((block) => {
                if (block.type === 'module_shortcut') {
                    return {
                        ...block,
                        payload: {
                            ...block.payload,
                            shortcuts: (block.payload?.shortcuts || []).map((shortcut) => ({
                                ...shortcut,
                                resolved_href: resolveRoutePreview(shortcut.route_name),
                            })),
                        },
                    };
                }
                return block;
            }),
        })),
    });

    return {
        ...form,
        items: (form.items || []).map(decorateItem),
    };
});

const submit = () => {
    const workingCopy = cloneMegaMenu(form);
    persistMegaMenuLocale(workingCopy, editorLocale.value, fallbackLocale.value, localeList.value);
    applyMegaMenuLocale(workingCopy, fallbackLocale.value, fallbackLocale.value, localeList.value);
    const payload = prepareMegaMenuForSubmit(workingCopy);
    form.transform(() => payload);

    if (isCreateMode.value) {
        form.post(route('superadmin.mega-menus.store'));
        return;
    }

    form.put(route('superadmin.mega-menus.update', form.id));
};

const deleteCurrent = () => {
    if (!form.id || !window.confirm(tx('edit.delete_menu_confirm', { title: form.title }))) return;
    router.delete(route('superadmin.mega-menus.destroy', form.id));
};

const activeSelection = computed(() => selectedEntity.value);

watch(editorLocale, (nextLocale, previousLocale) => {
    if (!nextLocale || nextLocale === previousLocale) {
        return;
    }

    persistMegaMenuLocale(form, previousLocale || fallbackLocale.value, fallbackLocale.value, localeList.value);
    applyMegaMenuLocale(form, nextLocale, fallbackLocale.value, localeList.value);
});
</script>

<template>
    <Head :title="headTitle" />

    <AuthenticatedLayout>
        <div class="space-y-5">
            <section class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="space-y-1">
                        <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">
                            {{ isCreateMode ? tx('edit.title_create') : tx('edit.title_edit') }}
                        </h1>
                        <p class="text-sm text-stone-600 dark:text-neutral-400">
                            {{ tx('edit.description') }}
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <Link :href="dashboard_url"
                            class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800">
                            {{ t('nav.dashboard') }}
                        </Link>
                        <Link :href="index_url"
                            class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800">
                            {{ tx('common.mega_menus') }}
                        </Link>
                        <Link v-if="preview_url" :href="preview_url"
                            class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800">
                            {{ tx('edit.preview_page') }}
                        </Link>
                        <button v-if="activate_url && form.status !== 'active'" type="button"
                            class="rounded-sm border border-emerald-200 px-3 py-2 text-sm font-semibold text-emerald-700 hover:bg-emerald-50"
                            @click="router.post(activate_url)">
                            {{ tx('common.activate') }}
                        </button>
                        <button v-if="deactivate_url && form.status === 'active'" type="button"
                            class="rounded-sm border border-amber-200 px-3 py-2 text-sm font-semibold text-amber-700 hover:bg-amber-50"
                            @click="router.post(deactivate_url)">
                            {{ tx('common.deactivate') }}
                        </button>
                        <button type="button"
                            class="rounded-sm border border-transparent bg-green-600 px-4 py-2 text-sm font-semibold text-white hover:bg-green-700"
                            :disabled="form.processing"
                            @click="submit">
                            {{ form.processing ? tx('common.saving') : tx('common.save') }}
                        </button>
                    </div>
                </div>
            </section>

            <section v-if="errorEntries.length" class="rounded-sm border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                <div class="font-semibold">{{ tx('edit.validation_issues') }}</div>
                <ul class="mt-2 space-y-1">
                    <li v-for="[key, message] in errorEntries" :key="key">
                        <span class="font-mono text-xs">{{ key }}</span> · {{ message }}
                    </li>
                </ul>
            </section>

            <section class="grid gap-5 xl:grid-cols-[320px_minmax(0,1fr)_360px]">
                <aside class="space-y-4">
                    <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <div class="flex items-center justify-between">
                            <div>
                                <div class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ tx('edit.structure_title') }}</div>
                                <div class="text-xs text-stone-500 dark:text-neutral-400">{{ tx('edit.structure_description') }}</div>
                            </div>
                            <button type="button"
                                class="rounded-sm border border-transparent bg-green-600 px-3 py-2 text-xs font-semibold text-white hover:bg-green-700"
                                @click="addTopLevelItem">
                                {{ tx('common.add_item') }}
                            </button>
                        </div>
                    </div>

                    <div class="rounded-sm border border-stone-200 bg-white p-3 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <button type="button"
                            class="mb-3 w-full rounded-sm border px-3 py-2 text-left text-sm font-semibold"
                            :class="selection.type === 'menu'
                                ? 'border-green-600 bg-emerald-50 text-emerald-700'
                                : 'border-stone-200 text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800'"
                            @click="selectMenu">
                            {{ tx('edit.menu_root') }}
                        </button>

                        <draggable v-model="form.items" item-key="builder_key" handle=".builder-handle" class="space-y-3">
                            <template #item="{ element: item }">
                                <div class="rounded-sm border border-stone-200 p-3 dark:border-neutral-700">
                                    <div class="flex items-start gap-2">
                                        <button type="button" class="builder-handle mt-1 cursor-grab rounded-sm border border-stone-200 px-2 py-1 text-[11px] font-semibold text-stone-500 dark:border-neutral-700 dark:text-neutral-400">
                                            {{ tx('common.drag') }}
                                        </button>
                                        <button type="button" class="min-w-0 flex-1 text-left" @click="selectItem(item)">
                                            <div class="truncate text-sm font-semibold" :class="selection.itemKey === item.builder_key ? 'text-emerald-700 dark:text-emerald-300' : 'text-stone-800 dark:text-neutral-100'">
                                                {{ item.label || tx('edit.untitled_item') }}
                                            </div>
                                            <div class="text-[11px] uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                                {{ item.panel_type }} · {{ item.link_type }}
                                            </div>
                                        </button>
                                    </div>
                                    <div class="mt-3 flex flex-wrap gap-2 text-[11px]">
                                        <button type="button" class="rounded-sm border border-stone-200 px-2 py-1 text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800" @click="duplicateItem(findItemContext(item.builder_key))">
                                            {{ tx('common.duplicate') }}
                                        </button>
                                        <button type="button" class="rounded-sm border border-stone-200 px-2 py-1 text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800" @click="addChildItem(item)">
                                            {{ tx('common.add_child') }}
                                        </button>
                                        <button type="button" class="rounded-sm border border-stone-200 px-2 py-1 text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800" @click="addColumnToItem(item)">
                                            {{ tx('common.add_column') }}
                                        </button>
                                        <button type="button" class="rounded-sm border border-red-200 px-2 py-1 text-red-700 hover:bg-red-50" @click="removeItem(findItemContext(item.builder_key))">
                                            {{ tx('common.remove') }}
                                        </button>
                                    </div>

                                    <div v-if="item.panel_type === 'classic'" class="mt-3 space-y-2 border-t border-stone-200 pt-3 dark:border-neutral-700">
                                        <div class="text-[11px] font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">{{ tx('edit.children') }}</div>
                                        <draggable v-model="item.children" item-key="builder_key" handle=".builder-child-handle" class="space-y-2">
                                            <template #item="{ element: child }">
                                                <div class="rounded-sm border border-stone-200 bg-stone-50 p-2 dark:border-neutral-700 dark:bg-neutral-800">
                                                    <div class="flex items-start gap-2">
                                                        <button type="button" class="builder-child-handle mt-1 cursor-grab rounded-sm border border-stone-200 px-2 py-1 text-[10px] font-semibold text-stone-500 dark:border-neutral-700 dark:text-neutral-400">{{ tx('common.drag') }}</button>
                                                        <button type="button" class="min-w-0 flex-1 text-left" @click="selectItem(child)">
                                                            <div class="truncate text-sm font-medium">{{ child.label || tx('edit.untitled_child') }}</div>
                                                            <div class="text-[10px] uppercase tracking-wide text-stone-500 dark:text-neutral-400">{{ child.link_type }}</div>
                                                        </button>
                                                        <button type="button" class="rounded-sm border border-red-200 px-2 py-1 text-[10px] font-semibold text-red-700 hover:bg-red-50" @click="removeItem(findItemContext(child.builder_key))">X</button>
                                                    </div>
                                                </div>
                                            </template>
                                        </draggable>
                                    </div>

                                    <div v-if="item.panel_type === 'mega'" class="mt-3 space-y-3 border-t border-stone-200 pt-3 dark:border-neutral-700">
                                        <div class="text-[11px] font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">{{ tx('edit.columns') }}</div>
                                        <draggable v-model="item.columns" item-key="builder_key" handle=".builder-column-handle" class="space-y-3">
                                            <template #item="{ element: column }">
                                                <div class="rounded-sm border border-stone-200 bg-stone-50 p-2 dark:border-neutral-700 dark:bg-neutral-800">
                                                    <div class="flex items-start gap-2">
                                                        <button type="button" class="builder-column-handle mt-1 cursor-grab rounded-sm border border-stone-200 px-2 py-1 text-[10px] font-semibold text-stone-500 dark:border-neutral-700 dark:text-neutral-400">{{ tx('common.drag') }}</button>
                                                        <button type="button" class="min-w-0 flex-1 text-left" @click="selectColumn(item, column)">
                                                            <div class="truncate text-sm font-medium">{{ column.title || tx('edit.untitled_column') }}</div>
                                                            <div class="text-[10px] uppercase tracking-wide text-stone-500 dark:text-neutral-400">{{ column.width }}</div>
                                                        </button>
                                                        <button type="button" class="rounded-sm border border-stone-200 px-2 py-1 text-[10px] font-semibold text-stone-700 hover:bg-white dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-900" @click="duplicateColumn(findColumnContext(column.builder_key))">{{ tx('common.copy') }}</button>
                                                        <button type="button" class="rounded-sm border border-red-200 px-2 py-1 text-[10px] font-semibold text-red-700 hover:bg-red-50" @click="removeColumn(findColumnContext(column.builder_key))">X</button>
                                                    </div>
                                                    <div class="mt-2 flex justify-end gap-2">
                                                        <button type="button" class="rounded-sm border border-emerald-200 px-2 py-1 text-[10px] font-semibold text-emerald-700 hover:bg-emerald-50" @click="addBlockToColumn(item, column, 'image')">
                                                            {{ tx('common.add_image') }}
                                                        </button>
                                                        <button type="button" class="rounded-sm border border-stone-200 px-2 py-1 text-[10px] font-semibold text-stone-700 hover:bg-white dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-900" @click="addBlockToColumn(item, column)">
                                                            {{ tx('common.add_block') }}
                                                        </button>
                                                    </div>
                                                    <draggable v-model="column.blocks" item-key="builder_key" handle=".builder-block-handle" class="mt-2 space-y-2">
                                                        <template #item="{ element: block }">
                                                            <div class="rounded-sm border border-stone-200 bg-white p-2 dark:border-neutral-700 dark:bg-neutral-900">
                                                                <div class="flex items-start gap-2">
                                                                    <button type="button" class="builder-block-handle mt-1 cursor-grab rounded-sm border border-stone-200 px-2 py-1 text-[10px] font-semibold text-stone-500 dark:border-neutral-700 dark:text-neutral-400">{{ tx('common.drag') }}</button>
                                                                    <button type="button" class="min-w-0 flex-1 text-left" @click="selectBlock(item, column, block)">
                                                                        <div class="truncate text-sm font-medium">{{ block.title || blockTypeLabel(block.type, block.type) }}</div>
                                                                        <div class="text-[10px] uppercase tracking-wide text-stone-500 dark:text-neutral-400">{{ block.type }}</div>
                                                                    </button>
                                                                    <button type="button" class="rounded-sm border border-stone-200 px-2 py-1 text-[10px] font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800" @click="duplicateBlock(findBlockContext(block.builder_key))">{{ tx('common.copy') }}</button>
                                                                    <button type="button" class="rounded-sm border border-red-200 px-2 py-1 text-[10px] font-semibold text-red-700 hover:bg-red-50" @click="removeBlock(findBlockContext(block.builder_key))">X</button>
                                                                </div>
                                                            </div>
                                                        </template>
                                                    </draggable>
                                                </div>
                                            </template>
                                        </draggable>
                                    </div>
                                </div>
                            </template>
                        </draggable>
                    </div>
                </aside>

                <div class="space-y-4">
                    <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <div class="flex items-center justify-between">
                            <div>
                                <div class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ tx('edit.live_preview_title') }}</div>
                                <div class="text-xs text-stone-500 dark:text-neutral-400">{{ tx('edit.live_preview_description') }}</div>
                            </div>
                            <div class="flex gap-2 text-xs">
                                <button type="button" class="rounded-sm border px-2 py-1 font-semibold"
                                    :class="previewDevice === 'desktop' ? 'border-green-600 bg-emerald-50 text-emerald-700' : 'border-stone-200 text-stone-600 dark:border-neutral-700 dark:text-neutral-300'"
                                    @click="previewDevice = 'desktop'">
                                    {{ tx('common.desktop') }}
                                </button>
                                <button type="button" class="rounded-sm border px-2 py-1 font-semibold"
                                    :class="previewDevice === 'tablet' ? 'border-green-600 bg-emerald-50 text-emerald-700' : 'border-stone-200 text-stone-600 dark:border-neutral-700 dark:text-neutral-300'"
                                    @click="previewDevice = 'tablet'">
                                    {{ tx('common.tablet') }}
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="overflow-hidden rounded-sm border border-stone-200 bg-gradient-to-br from-stone-100 via-white to-emerald-50 p-4 shadow-sm dark:border-neutral-700 dark:from-neutral-900 dark:via-neutral-900 dark:to-neutral-800">
                        <div class="mx-auto rounded-sm border border-stone-200 bg-white shadow-xl transition-all dark:border-neutral-700 dark:bg-neutral-950"
                            :class="previewDevice === 'tablet' ? 'max-w-3xl' : 'max-w-[1400px]'">
                            <div class="border-b border-stone-200 dark:border-neutral-700">
                                <div class="mx-auto flex w-full max-w-[88rem] items-center gap-5 px-5 py-5 xl:px-8">
                                    <div class="flex shrink-0 items-center">
                                        <ApplicationLogo class="h-10 w-36 sm:h-11 sm:w-40" />
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <MegaMenuDisplay :menu="previewMenu" preview default-open-first-panel />
                                    </div>
                                    <button
                                        type="button"
                                        class="inline-flex shrink-0 items-center gap-2 rounded-sm border border-stone-200 bg-white px-4 py-2 text-sm font-semibold text-stone-800 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100"
                                    >
                                        <span>{{ editorLocaleCode }}</span>
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <path d="m6 9 6 6 6-6" />
                                        </svg>
                                    </button>
                                </div>
                            </div>
                            <div class="grid gap-6 p-6 lg:grid-cols-[1.1fr_0.9fr]">
                                <div class="space-y-4">
                                    <div class="text-xs font-semibold uppercase tracking-[0.18em] text-stone-500 dark:text-neutral-400">{{ tx('edit.context_area') }}</div>
                                    <h2 class="text-3xl font-semibold tracking-tight text-stone-900 dark:text-white">
                                        {{ tx('edit.preview_headline') }}
                                    </h2>
                                    <p class="text-sm text-stone-600 dark:text-neutral-300">
                                        {{ tx('edit.preview_body') }}
                                    </p>
                                </div>
                                <div class="rounded-sm border border-stone-200 bg-stone-50 p-4 text-sm text-stone-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300">
                                    <div class="font-semibold text-stone-900 dark:text-white">{{ tx('common.metadata') }}</div>
                                    <div class="mt-2 space-y-2 text-xs">
                                        <div>{{ tx('common.created') }}: {{ formatDate(meta.created_at) }}</div>
                                        <div>{{ tx('common.updated') }}: {{ formatDate(meta.updated_at) }}</div>
                                        <div>{{ tx('common.created_by') }}: {{ meta.created_by?.name || meta.created_by?.email || tx('common.unknown') }}</div>
                                        <div>{{ tx('common.updated_by') }}: {{ meta.updated_by?.name || meta.updated_by?.email || tx('common.unknown') }}</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <aside class="space-y-4">
                    <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <FloatingSelect v-model="editorLocale" :options="localeOptions" :label="tx('edit.editing_locale')" />
                        <div class="mt-2 text-xs text-stone-500 dark:text-neutral-400">
                            {{ tx('edit.editing_locale_help') }}
                        </div>
                    </div>

                    <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <div class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ selectionTitle }}</div>
                        <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                            {{ tx('edit.selection_help') }}
                        </div>
                    </div>

                    <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <template v-if="selection.type === 'menu'">
                            <div class="space-y-4">
                                <FloatingInput v-model="form.title" :label="tx('common.title')" />
                                <FloatingInput v-model="form.slug" :label="tx('common.slug')" />
                                <div class="grid gap-3 md:grid-cols-2">
                                    <FloatingSelect v-model="form.status" :options="statusOptions" :label="tx('index.status')" />
                                    <FloatingSelect v-model="form.display_location" :options="locationOptions" :label="tx('edit.display_location')" />
                                </div>
                                <FloatingInput v-if="form.display_location === 'custom'" v-model="form.custom_zone" :label="tx('edit.custom_zone')" />
                                <div>
                                    <label class="block text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">{{ tx('common.description') }}</label>
                                    <textarea v-model="form.description" rows="3" class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"></textarea>
                                </div>
                                <div class="grid gap-3 md:grid-cols-2">
                                    <FloatingInput v-model="form.css_classes" :label="tx('edit.css_classes')" />
                                    <FloatingInput v-model="form.ordering" type="number" :label="tx('edit.priority')" />
                                </div>
                                <div class="grid gap-3 md:grid-cols-2">
                                    <FloatingSelect v-model="form.settings.theme" :options="themeOptions" :label="tx('common.theme')" />
                                    <FloatingSelect v-model="form.settings.container_width" :options="containerWidthOptions" :label="tx('edit.container_width')" />
                                </div>
                                <div class="grid gap-3 md:grid-cols-2">
                                    <div>
                                        <label class="block text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">{{ tx('edit.accent_color') }}</label>
                                        <div class="mt-1 flex gap-2">
                                            <input v-model="form.settings.accent_color" type="color" class="h-11 w-14 rounded-sm border border-stone-200 bg-white p-1 dark:border-neutral-700 dark:bg-neutral-900" />
                                            <input v-model="form.settings.accent_color" type="text" class="block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                                        </div>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">{{ tx('edit.panel_background') }}</label>
                                        <div class="mt-1 flex gap-2">
                                            <input v-model="form.settings.panel_background" type="color" class="h-11 w-14 rounded-sm border border-stone-200 bg-white p-1 dark:border-neutral-700 dark:bg-neutral-900" />
                                            <input v-model="form.settings.panel_background" type="text" class="block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                                        </div>
                                    </div>
                                </div>
                                <div class="grid gap-3 md:grid-cols-2">
                                    <label class="flex items-center gap-2 text-sm text-stone-700 dark:text-neutral-200">
                                        <Checkbox v-model:checked="form.settings.open_on_hover" />
                                        <span>{{ tx('edit.open_on_hover') }}</span>
                                    </label>
                                    <label class="flex items-center gap-2 text-sm text-stone-700 dark:text-neutral-200">
                                        <Checkbox v-model:checked="form.settings.show_dividers" />
                                        <span>{{ tx('edit.show_dividers') }}</span>
                                    </label>
                                </div>
                                <div v-if="!isCreateMode" class="pt-2">
                                    <button type="button" class="rounded-sm border border-red-200 px-3 py-2 text-sm font-semibold text-red-700 hover:bg-red-50" @click="deleteCurrent">
                                        {{ tx('edit.delete_menu') }}
                                    </button>
                                </div>
                            </div>
                        </template>

                        <template v-else-if="selection.type === 'item' && activeSelection">
                            <div class="space-y-4">
                                <FloatingInput v-model="activeSelection.label" :label="tx('common.label')" />
                                <FloatingInput v-model="activeSelection.description" :label="tx('common.description')" />
                                <div class="grid gap-3 md:grid-cols-2">
                                    <FloatingSelect v-model="activeSelection.panel_type" :options="panelTypeOptions" :label="tx('edit.panel_type')" @update:modelValue="ensurePanelStructure(activeSelection)" />
                                    <FloatingSelect v-model="activeSelection.link_type" :options="linkTypeOptions" :label="tx('edit.link_type')" />
                                </div>
                                <div class="grid gap-3 md:grid-cols-2">
                                    <div v-if="activeSelection.link_type === 'internal_page'">
                                        <FloatingSelect v-model="activeSelection.link_value" :options="internal_page_options" :label="tx('edit.internal_page')" option-value="value" option-label="label" filterable />
                                    </div>
                                    <FloatingInput v-else-if="activeSelection.link_type !== 'none'" v-model="activeSelection.link_value" :label="linkValueLabel" />
                                    <FloatingSelect v-model="activeSelection.link_target" :options="linkTargetOptions" :label="tx('common.target')" />
                                </div>
                                <div class="grid gap-3 md:grid-cols-2">
                                    <FloatingInput v-model="activeSelection.icon" :label="tx('common.icon')" />
                                    <FloatingInput v-model="activeSelection.css_classes" :label="tx('edit.css_classes')" />
                                </div>
                                <div class="grid gap-3 md:grid-cols-2">
                                    <FloatingInput v-model="activeSelection.badge_text" :label="tx('edit.badge_text')" />
                                    <FloatingSelect v-model="activeSelection.badge_variant" :options="badgeVariantOptions" :label="tx('edit.badge_variant')" />
                                </div>
                                <div class="grid gap-3 md:grid-cols-2">
                                    <FloatingInput v-model="activeSelection.settings.eyebrow" :label="tx('edit.eyebrow')" />
                                    <FloatingInput v-model="activeSelection.settings.note" :label="tx('common.note')" />
                                </div>
                                <div class="grid gap-3 md:grid-cols-2">
                                    <label class="flex items-center gap-2 text-sm text-stone-700 dark:text-neutral-200">
                                        <Checkbox v-model:checked="activeSelection.is_visible" />
                                        <span>{{ tx('common.visible') }}</span>
                                    </label>
                                    <label class="flex items-center gap-2 text-sm text-stone-700 dark:text-neutral-200">
                                        <Checkbox v-model:checked="activeSelection.settings.featured" />
                                        <span>{{ tx('edit.featured_item') }}</span>
                                    </label>
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">{{ tx('edit.highlight_color') }}</label>
                                    <div class="mt-1 flex gap-2">
                                        <input v-model="activeSelection.settings.highlight_color" type="color" class="h-11 w-14 rounded-sm border border-stone-200 bg-white p-1 dark:border-neutral-700 dark:bg-neutral-900" />
                                        <input v-model="activeSelection.settings.highlight_color" type="text" class="block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                                    </div>
                                </div>
                            </div>
                        </template>

                        <template v-else-if="selection.type === 'column' && activeSelection">
                            <div class="space-y-4">
                                <FloatingInput v-model="activeSelection.title" :label="tx('edit.column_title')" />
                                <FloatingInput v-model="activeSelection.width" :label="tx('edit.width')" />
                                <FloatingInput v-model="activeSelection.css_classes" :label="tx('edit.css_classes')" />
                                <div class="grid gap-3 md:grid-cols-2">
                                    <FloatingSelect v-model="activeSelection.settings.alignment" :options="alignmentOptions" :label="tx('edit.alignment')" />
                                    <FloatingSelect v-model="activeSelection.settings.row" :options="rowOptions" :label="tx('common.row')" />
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">{{ tx('edit.background_color') }}</label>
                                    <div class="mt-1 flex gap-2">
                                        <input v-model="activeSelection.settings.background_color" type="color" class="h-11 w-14 rounded-sm border border-stone-200 bg-white p-1 dark:border-neutral-700 dark:bg-neutral-900" />
                                        <input v-model="activeSelection.settings.background_color" type="text" class="block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                                    </div>
                                </div>
                            </div>
                        </template>

                        <template v-else-if="selection.type === 'block' && activeSelection">
                            <div class="space-y-4">
                                <FloatingSelect v-model="activeSelection.type" :options="blockDefinitions.map((definition) => ({ value: definition.type, label: blockTypeLabel(definition.type, definition.label) }))" :label="tx('edit.block_type')" @update:modelValue="changeBlockType(activeSelection)" />
                                <FloatingInput v-model="activeSelection.title" :label="tx('edit.block_title')" />
                                <FloatingInput v-model="activeSelection.css_classes" :label="tx('edit.css_classes')" />
                                <div class="grid gap-3 md:grid-cols-2">
                                    <FloatingSelect v-model="activeSelection.settings.tone" :options="toneOptions" :label="tx('common.tone')" />
                                    <label class="flex items-center gap-2 text-sm text-stone-700 dark:text-neutral-200">
                                        <Checkbox v-model:checked="activeSelection.settings.show_border" />
                                        <span>{{ tx('edit.show_border') }}</span>
                                    </label>
                                </div>
                                <div class="border-t border-stone-200 pt-4 dark:border-neutral-700">
                                    <MegaMenuBlockPayloadEditor :block="activeSelection" @pick-asset="({ target, field, altField }) => openAssetPicker(target || activeSelection.payload, field, altField)" />
                                </div>
                            </div>
                        </template>
                    </div>
                </aside>
            </section>
        </div>

        <AssetPickerModal
            :show="assetPickerOpen"
            :list-url="asset_list_url"
            :upload-url="asset_upload_url"
            :title="tx('edit.asset_picker_title')"
            @close="closeAssetPicker"
            @select="handleAssetSelect"
        />
    </AuthenticatedLayout>
</template>
