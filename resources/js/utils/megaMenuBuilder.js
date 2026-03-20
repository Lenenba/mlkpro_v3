const cloneDeep = (value) => JSON.parse(JSON.stringify(value ?? null));

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
