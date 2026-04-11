const cloneDeep = (value) => JSON.parse(JSON.stringify(value ?? null));
const isPlainObject = (value) => value !== null && typeof value === 'object' && !Array.isArray(value);

const normalizeLocales = (locales = [], defaultLocale = 'fr') =>
    Array.from(new Set([defaultLocale, ...(Array.isArray(locales) ? locales : [])].filter(Boolean).map((locale) => String(locale))));

const ensureObject = (target, key) => {
    if (!isPlainObject(target[key])) {
        target[key] = {};
    }

    return target[key];
};

const ensureTranslations = (container) => {
    if (!isPlainObject(container.translations)) {
        container.translations = {};
    }

    return container.translations;
};

const ensureLocaleBucket = (translations, locale) => {
    if (!isPlainObject(translations[locale])) {
        translations[locale] = {};
    }

    return translations[locale];
};

const resolveLocalizedValue = (translations, locale, fallbackLocale, field, fallbackValue = '') => {
    const currentBucket = isPlainObject(translations?.[locale]) ? translations[locale] : {};
    const fallbackBucket = isPlainObject(translations?.[fallbackLocale]) ? translations[fallbackLocale] : {};

    if (currentBucket[field] !== undefined) {
        return currentBucket[field];
    }

    if (fallbackBucket[field] !== undefined) {
        return fallbackBucket[field];
    }

    return fallbackValue;
};

const snapshotPayload = (block) => {
    const payload = cloneDeep(block?.payload ?? {});

    if (isPlainObject(payload)) {
        delete payload.translations;
        return payload;
    }

    return {};
};

const bootstrapMenuLocale = (menu, locale) => {
    const settings = ensureObject(menu, 'settings');
    const translations = ensureTranslations(settings);
    const bucket = ensureLocaleBucket(translations, locale);

    if (bucket.title === undefined) {
        bucket.title = menu.title ?? '';
    }

    if (bucket.description === undefined) {
        bucket.description = menu.description ?? '';
    }
};

const persistMenuLocale = (menu, locale) => {
    const settings = ensureObject(menu, 'settings');
    const translations = ensureTranslations(settings);
    const bucket = ensureLocaleBucket(translations, locale);

    bucket.title = menu.title ?? '';
    bucket.description = menu.description ?? '';
};

const applyMenuLocale = (menu, locale, fallbackLocale) => {
    const settings = ensureObject(menu, 'settings');
    const translations = ensureTranslations(settings);

    menu.title = resolveLocalizedValue(translations, locale, fallbackLocale, 'title', menu.title ?? '');
    menu.description = resolveLocalizedValue(translations, locale, fallbackLocale, 'description', menu.description ?? '');
};

const bootstrapItemLocale = (item, locale) => {
    const settings = ensureObject(item, 'settings');
    const translations = ensureTranslations(settings);
    const bucket = ensureLocaleBucket(translations, locale);

    if (bucket.label === undefined) {
        bucket.label = item.label ?? '';
    }

    if (bucket.description === undefined) {
        bucket.description = item.description ?? '';
    }

    if (bucket.badge_text === undefined) {
        bucket.badge_text = item.badge_text ?? '';
    }

    if (bucket.eyebrow === undefined) {
        bucket.eyebrow = settings.eyebrow ?? '';
    }

    if (bucket.note === undefined) {
        bucket.note = settings.note ?? '';
    }
};

const persistItemLocale = (item, locale) => {
    const settings = ensureObject(item, 'settings');
    const translations = ensureTranslations(settings);
    const bucket = ensureLocaleBucket(translations, locale);

    bucket.label = item.label ?? '';
    bucket.description = item.description ?? '';
    bucket.badge_text = item.badge_text ?? '';
    bucket.eyebrow = settings.eyebrow ?? '';
    bucket.note = settings.note ?? '';
};

const applyItemLocale = (item, locale, fallbackLocale) => {
    const settings = ensureObject(item, 'settings');
    const translations = ensureTranslations(settings);

    item.label = resolveLocalizedValue(translations, locale, fallbackLocale, 'label', item.label ?? '');
    item.description = resolveLocalizedValue(translations, locale, fallbackLocale, 'description', item.description ?? '');
    item.badge_text = resolveLocalizedValue(translations, locale, fallbackLocale, 'badge_text', item.badge_text ?? '');
    settings.eyebrow = resolveLocalizedValue(translations, locale, fallbackLocale, 'eyebrow', settings.eyebrow ?? '');
    settings.note = resolveLocalizedValue(translations, locale, fallbackLocale, 'note', settings.note ?? '');
};

const bootstrapColumnLocale = (column, locale) => {
    const settings = ensureObject(column, 'settings');
    const translations = ensureTranslations(settings);
    const bucket = ensureLocaleBucket(translations, locale);

    if (bucket.title === undefined) {
        bucket.title = column.title ?? '';
    }
};

const persistColumnLocale = (column, locale) => {
    const settings = ensureObject(column, 'settings');
    const translations = ensureTranslations(settings);
    const bucket = ensureLocaleBucket(translations, locale);

    bucket.title = column.title ?? '';
};

const applyColumnLocale = (column, locale, fallbackLocale) => {
    const settings = ensureObject(column, 'settings');
    const translations = ensureTranslations(settings);

    column.title = resolveLocalizedValue(translations, locale, fallbackLocale, 'title', column.title ?? '');
};

const bootstrapBlockLocale = (block, locale) => {
    const settings = ensureObject(block, 'settings');
    const settingTranslations = ensureTranslations(settings);
    const settingBucket = ensureLocaleBucket(settingTranslations, locale);

    if (settingBucket.title === undefined) {
        settingBucket.title = block.title ?? '';
    }

    if (!isPlainObject(block.payload)) {
        block.payload = {};
    }

    const payloadTranslations = ensureTranslations(block.payload);
    if (!isPlainObject(payloadTranslations[locale])) {
        payloadTranslations[locale] = snapshotPayload(block);
    }
};

const persistBlockLocale = (block, locale) => {
    const settings = ensureObject(block, 'settings');
    const settingTranslations = ensureTranslations(settings);
    const settingBucket = ensureLocaleBucket(settingTranslations, locale);
    settingBucket.title = block.title ?? '';

    if (!isPlainObject(block.payload)) {
        block.payload = {};
    }

    const payloadTranslations = ensureTranslations(block.payload);
    payloadTranslations[locale] = snapshotPayload(block);
};

const applyBlockLocale = (block, locale, fallbackLocale) => {
    const settings = ensureObject(block, 'settings');
    const settingTranslations = ensureTranslations(settings);
    block.title = resolveLocalizedValue(settingTranslations, locale, fallbackLocale, 'title', block.title ?? '');

    if (!isPlainObject(block.payload)) {
        block.payload = {};
    }

    const payloadTranslations = ensureTranslations(block.payload);
    const fallbackPayload = isPlainObject(payloadTranslations[fallbackLocale])
        ? cloneDeep(payloadTranslations[fallbackLocale])
        : snapshotPayload(block);
    const localizedPayload = isPlainObject(payloadTranslations[locale])
        ? cloneDeep(payloadTranslations[locale])
        : cloneDeep(fallbackPayload);

    block.payload = {
        ...localizedPayload,
        translations: payloadTranslations,
    };
};

const walkItems = (items, callbacks = {}) => {
    if (!Array.isArray(items)) {
        return;
    }

    items.forEach((item) => {
        callbacks.item?.(item);

        if (Array.isArray(item.children) && item.children.length) {
            walkItems(item.children, callbacks);
        }

        if (Array.isArray(item.columns) && item.columns.length) {
            item.columns.forEach((column) => {
                callbacks.column?.(column, item);

                if (Array.isArray(column.blocks) && column.blocks.length) {
                    column.blocks.forEach((block) => callbacks.block?.(block, column, item));
                }
            });
        }
    });
};

export const createBuilderKey = (prefix = 'node') =>
    `${prefix}-${Math.random().toString(36).slice(2, 10)}-${Date.now().toString(36)}`;

export const createMegaMenuBlock = (definition = {}, defaults = {}) => ({
    builder_key: defaults.builder_key || createBuilderKey('block'),
    id: defaults.id ?? null,
    type: defaults.type || definition.type || 'text',
    title: defaults.title ?? definition.label ?? '',
    css_classes: defaults.css_classes ?? '',
    settings: cloneDeep(defaults.settings ?? {}),
    payload: cloneDeep(defaults.payload ?? definition.default_payload ?? {}),
});

export const createMegaMenuColumn = (blockDefinitions = [], defaults = {}) => {
    const blocks = Array.isArray(defaults.blocks) && defaults.blocks.length
        ? defaults.blocks.map((block) => {
            const definition = blockDefinitions.find((candidate) => candidate.type === block.type) || blockDefinitions[0] || {};
            return createMegaMenuBlock(definition, block);
        })
        : [createMegaMenuBlock(blockDefinitions[0] || {})];

    return {
        builder_key: defaults.builder_key || createBuilderKey('column'),
        id: defaults.id ?? null,
        title: defaults.title ?? '',
        width: defaults.width ?? '1fr',
        css_classes: defaults.css_classes ?? '',
        settings: cloneDeep(defaults.settings ?? {}),
        blocks,
    };
};

export const createMegaMenuItem = (blockDefinitions = [], defaults = {}) => {
    const panelType = defaults.panel_type || 'mega';
    const item = {
        builder_key: defaults.builder_key || createBuilderKey('item'),
        id: defaults.id ?? null,
        label: defaults.label ?? 'Menu item',
        description: defaults.description ?? '',
        link_type: defaults.link_type ?? 'none',
        link_value: defaults.link_value ?? null,
        link_target: defaults.link_target ?? '_self',
        panel_type: panelType,
        icon: defaults.icon ?? '',
        badge_text: defaults.badge_text ?? '',
        badge_variant: defaults.badge_variant ?? null,
        is_visible: defaults.is_visible ?? true,
        css_classes: defaults.css_classes ?? '',
        settings: cloneDeep(defaults.settings ?? {}),
        children: [],
        columns: [],
    };

    if (panelType === 'classic') {
        item.children = Array.isArray(defaults.children) && defaults.children.length
            ? defaults.children.map((child) => createMegaMenuItem(blockDefinitions, child))
            : [
                createMegaMenuItem(blockDefinitions, {
                    label: 'Dropdown link',
                    panel_type: 'link',
                    link_type: 'internal_page',
                    link_value: '/',
                }),
            ];
    }

    if (panelType === 'mega') {
        item.columns = Array.isArray(defaults.columns) && defaults.columns.length
            ? defaults.columns.map((column) => createMegaMenuColumn(blockDefinitions, column))
            : [createMegaMenuColumn(blockDefinitions, {})];
    }

    return item;
};

export const normalizeMegaMenu = (menu = {}, defaults = {}, blockDefinitions = []) => {
    const root = {
        id: menu.id ?? null,
        title: menu.title ?? '',
        slug: menu.slug ?? '',
        status: menu.status ?? 'draft',
        display_location: menu.display_location ?? 'header',
        custom_zone: menu.custom_zone ?? '',
        description: menu.description ?? '',
        css_classes: menu.css_classes ?? '',
        ordering: menu.ordering ?? 0,
        settings: {
            ...(cloneDeep(defaults.menu_settings) || {}),
            ...(cloneDeep(menu.settings) || {}),
        },
        items: [],
    };

    const incomingItems = Array.isArray(menu.items) ? menu.items : [];
    root.items = incomingItems.length
        ? incomingItems.map((item) => createMegaMenuItem(blockDefinitions, item))
        : [createMegaMenuItem(blockDefinitions, {})];

    return root;
};

export const cloneMegaMenu = (menu) => cloneDeep(menu);

export const ensureMegaMenuTranslations = (menu, locales = ['fr', 'en', 'es'], defaultLocale = 'fr') => {
    const fallbackLocale = defaultLocale || normalizeLocales(locales, defaultLocale)[0] || 'fr';

    bootstrapMenuLocale(menu, fallbackLocale);
    walkItems(menu.items, {
        item: (item) => bootstrapItemLocale(item, fallbackLocale),
        column: (column) => bootstrapColumnLocale(column, fallbackLocale),
        block: (block) => bootstrapBlockLocale(block, fallbackLocale),
    });

    return menu;
};

export const persistMegaMenuLocale = (menu, locale, defaultLocale = 'fr') => {
    ensureMegaMenuTranslations(menu, [locale], defaultLocale);

    persistMenuLocale(menu, locale);
    walkItems(menu.items, {
        item: (item) => persistItemLocale(item, locale),
        column: (column) => persistColumnLocale(column, locale),
        block: (block) => persistBlockLocale(block, locale),
    });

    return menu;
};

export const applyMegaMenuLocale = (menu, locale, defaultLocale = 'fr') => {
    ensureMegaMenuTranslations(menu, [locale], defaultLocale);

    applyMenuLocale(menu, locale, defaultLocale);
    walkItems(menu.items, {
        item: (item) => applyItemLocale(item, locale, defaultLocale),
        column: (column) => applyColumnLocale(column, locale, defaultLocale),
        block: (block) => applyBlockLocale(block, locale, defaultLocale),
    });

    return menu;
};

export const prepareMegaMenuForSubmit = (menu) => {
    const prepareBlock = (block) => ({
        id: block.id ?? null,
        type: block.type ?? 'text',
        title: block.title ?? '',
        css_classes: block.css_classes ?? '',
        settings: cloneDeep(block.settings ?? {}),
        payload: cloneDeep(block.payload ?? {}),
    });

    const prepareColumn = (column) => ({
        id: column.id ?? null,
        title: column.title ?? '',
        width: column.width ?? '1fr',
        css_classes: column.css_classes ?? '',
        settings: cloneDeep(column.settings ?? {}),
        blocks: (column.blocks || []).map(prepareBlock),
    });

    const prepareItem = (item) => ({
        id: item.id ?? null,
        label: item.label ?? '',
        description: item.description ?? '',
        link_type: item.link_type ?? 'none',
        link_value: item.link_value ?? null,
        link_target: item.link_target ?? '_self',
        panel_type: item.panel_type ?? 'link',
        icon: item.icon ?? '',
        badge_text: item.badge_text ?? '',
        badge_variant: item.badge_variant ?? null,
        is_visible: item.is_visible ?? true,
        css_classes: item.css_classes ?? '',
        settings: cloneDeep(item.settings ?? {}),
        children: item.panel_type === 'classic' ? (item.children || []).map(prepareItem) : [],
        columns: item.panel_type === 'mega' ? (item.columns || []).map(prepareColumn) : [],
    });

    return {
        id: menu.id ?? null,
        title: menu.title ?? '',
        slug: menu.slug ?? '',
        status: menu.status ?? 'draft',
        display_location: menu.display_location ?? 'header',
        custom_zone: menu.custom_zone ?? '',
        description: menu.description ?? '',
        css_classes: menu.css_classes ?? '',
        ordering: Number(menu.ordering ?? 0),
        settings: cloneDeep(menu.settings ?? {}),
        items: (menu.items || []).map(prepareItem),
    };
};
