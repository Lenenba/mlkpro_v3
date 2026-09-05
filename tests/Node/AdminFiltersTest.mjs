import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { pathToFileURL } from 'node:url';
import test from 'node:test';
import { compileScript, parse } from '@vue/compiler-sfc';
import { createRenderer, h, nextTick, reactive } from 'vue';
import { createI18n } from 'vue-i18n';

const compiledModules = new Map();
const componentUrl = (filename) => {
    if (compiledModules.has(filename)) return compiledModules.get(filename);

    const { descriptor } = parse(readFileSync(filename, 'utf8'), { filename });
    const compiled = compileScript(descriptor, { id: filename, inlineTemplate: true }).content;
    const executable = compiled.replace(/(from\s+|import\s+)['"]([^'"]+)['"]/gu, (_, prefix, specifier) => {
        let url;
        if (specifier.startsWith('@/')) {
            const dependency = `resources/js/${specifier.slice(2)}`;
            url = dependency.endsWith('.vue')
                ? componentUrl(dependency)
                : pathToFileURL(`${dependency}.js`).href;
        } else {
            url = import.meta.resolve(specifier);
        }

        return `${prefix}${JSON.stringify(url)}`;
    });
    const url = `data:text/javascript;base64,${Buffer.from(executable).toString('base64')}`;
    compiledModules.set(filename, url);
    return url;
};

const node = (type, text = '') => ({ type, text, children: [], props: {}, parent: null });
const remove = (child) => {
    const siblings = child.parent?.children;
    if (siblings) siblings.splice(siblings.indexOf(child), 1);
    child.parent = null;
};
const renderer = createRenderer({
    createElement: (type) => node(type),
    createText: (text) => node('#text', text),
    createComment: (text) => node('#comment', text),
    insert: (child, parent, anchor = null) => {
        if (child.parent) remove(child);
        child.parent = parent;
        parent.children.splice(anchor ? parent.children.indexOf(anchor) : parent.children.length, 0, child);
    },
    remove,
    setText: (element, text) => { element.text = text; },
    setElementText: (element, text) => { element.text = text; element.children = []; },
    parentNode: (element) => element.parent,
    nextSibling: (element) => element.parent?.children[element.parent.children.indexOf(element) + 1] || null,
    patchProp: (element, key, previous, value) => { element.props[key] = value; },
});

const textContent = (element) => element.type === '#comment'
    ? ''
    : `${element.text}${element.children.map(textContent).join('')}`;

const mountFilters = async (name, initialProps = {}) => {
    const { default: component } = await import(componentUrl(`resources/js/Components/${name}.vue`));
    const props = reactive(initialProps);
    const root = node('root');
    const app = renderer.createApp({ render: () => h(component, props) });
    const messages = JSON.parse(readFileSync('resources/js/i18n/modules/fr/customer_index.json', 'utf8'));
    app.use(createI18n({
        legacy: false,
        locale: 'fr',
        messages: {
            fr: {
                ...messages,
                records: { filter_summary: {
                    title: 'Record filters', results: '{count} records', updating: 'Updating',
                    active_count: '{count} active', mode_label: 'Quick filter mode',
                    modes: { all: 'Match all', any: 'Match any' },
                    remove: 'Remove {label}', clear_all: 'Clear filters',
                } },
            },
        },
    }));
    app.mount(root);

    const find = (predicate) => {
        const matches = [];
        const visit = (element) => {
            if (predicate(element)) matches.push(element);
            element.children.forEach(visit);
        };
        visit(root);
        return matches;
    };

    return {
        props,
        root,
        find,
        buttons: () => find((element) => element.type === 'button'),
        button: (label) => find((element) => element.type === 'button' && textContent(element).trim() === label)[0],
        unmount: () => app.unmount(),
    };
};

test('filter summaries use the supplied namespace and show combination controls only for multiple quick filters', async () => {
    const changes = [];
    const summary = await mountFilters('DataTable/AdminFilterSummary', {
        summaryId: 'record-summary', i18nPrefix: 'records', matchingCount: 12,
        quickFilterCount: 0, quickFilterMode: 'all', 'data-testid': 'record-active-filters',
        'onUpdate:quick-filter-mode': (mode) => changes.push(mode),
    });

    try {
        const section = summary.root.children[0];
        assert.equal(section.type, 'section');
        assert.equal(section.props['aria-labelledby'], 'record-summary');
        assert.equal(section.props['data-testid'], 'record-active-filters');
        assert.equal(summary.find((element) => element.type === 'h2')[0].props.id, 'record-summary');
        assert.match(textContent(section), /12 records/u);
        assert.equal(summary.buttons().length, 0);

        summary.props.quickFilterCount = 1;
        await nextTick();
        assert.equal(summary.find((element) => element.props.role === 'group').length, 0);

        summary.props.quickFilterCount = 2;
        await nextTick();
        assert.equal(summary.button('Match all').props['aria-pressed'], 'true');
        assert.equal(summary.button('Match any').props['aria-pressed'], 'false');
        summary.button('Match any').props.onClick();
        assert.deepEqual(changes, ['any']);
        summary.props.quickFilterMode = 'any';
        await nextTick();
        assert.equal(summary.button('Match all').props['aria-pressed'], 'false');
        assert.equal(summary.button('Match any').props['aria-pressed'], 'true');
    } finally {
        summary.unmount();
    }
});

test('the customer summary wrapper preserves copy, removable badge order, events and busy controls', async () => {
    const removed = [];
    const modes = [];
    let clears = 0;
    const summary = await mountFilters('Customer/CustomerFilterSummary', {
        matchingCount: 2,
        activeFilters: [{ id: 'search', label: 'Recherche : Jules' }, { id: 'quick:vip', label: 'VIP' }],
        quickFilterCount: 2,
        onRemove: (filter) => removed.push(filter), onClear: () => { clears += 1; },
        'onUpdate:quick-filter-mode': (mode) => modes.push(mode),
    });

    try {
        assert.equal(summary.root.children.length, 1);
        assert.equal(summary.root.children[0].type, 'section');
        assert.equal(summary.root.children[0].props['aria-labelledby'], 'customer-filter-summary-title');
        assert.match(textContent(summary.root), /2 client\(s\)/u);
        assert.match(textContent(summary.root), /2 critère\(s\) actif\(s\)/u);
        const removals = summary.buttons().filter((button) => button.props['aria-label']?.startsWith('Retirer'));
        assert.deepEqual(removals.map((button) => button.props['aria-label']), ['Retirer Recherche : Jules', 'Retirer VIP']);
        removals[1].props.onClick();
        summary.button('Effacer les filtres').props.onClick();
        summary.button('Au moins un critère').props.onClick();
        assert.deepEqual(removed, [summary.props.activeFilters[1]]);
        assert.equal(clears, 1);
        assert.deepEqual(modes, ['any']);

        summary.props.busy = true;
        await nextTick();
        assert.ok(summary.buttons().every((button) => button.props.disabled === true));
        assert.match(textContent(summary.root), /Mise à jour…/u);
        summary.props.busy = false;
        summary.props.activeFilters = [];
        summary.props.quickFilterCount = 0;
        await nextTick();
        assert.equal(summary.buttons().length, 0);
        assert.match(textContent(summary.root), /2 client\(s\)/u);
    } finally {
        summary.unmount();
    }
});

test('quick filter buttons preserve option order, cumulative selection and clearing only the quick group', async () => {
    const toggles = [];
    let clears = 0;
    const quick = await mountFilters('DataTable/AdminQuickFilters', {
        options: [{ value: 'vip', label: 'VIP' }, { value: 'inactive', label: 'Inactive' }],
        selectedValues: [], allLabel: 'Tous', ariaLabel: 'Filtres rapides',
        testIdPrefix: 'quick-filter', 'data-testid': 'quick-filters',
        onToggle: (value) => toggles.push(value), onClear: () => { clears += 1; },
    });

    try {
        assert.deepEqual(quick.buttons().map((button) => textContent(button).trim()), ['Tous', 'VIP', 'Inactive']);
        assert.deepEqual(quick.buttons().map((button) => button.props['data-testid']), ['quick-filter-all', 'quick-filter-vip', 'quick-filter-inactive']);
        assert.equal(quick.root.children[0].props['data-testid'], 'quick-filters');
        assert.equal(quick.root.children[0].props['aria-label'], 'Filtres rapides');
        quick.button('Tous').props.onClick();
        assert.equal(clears, 0);
        quick.button('VIP').props.onClick();
        assert.deepEqual(toggles, ['vip']);

        quick.props.selectedValues = ['vip', 'inactive'];
        await nextTick();
        assert.deepEqual(quick.buttons().map((button) => button.props['aria-pressed']), ['false', 'true', 'true']);
        quick.button('VIP').props.onClick();
        assert.deepEqual(toggles, ['vip', 'vip']);
        quick.button('Tous').props.onClick();
        assert.equal(clears, 1);

        quick.props.selectedValues = [];
        quick.props.busy = true;
        await nextTick();
        assert.equal(quick.button('Tous').props['aria-pressed'], 'true');
        assert.ok(quick.buttons().every((button) => button.props.disabled === true));
        quick.props.busy = false;
        quick.props.testIdPrefix = '';
        await nextTick();
        assert.ok(quick.buttons().every((button) => !button.props.disabled));
        assert.ok(quick.buttons().every((button) => button.props['data-testid'] === undefined));
    } finally {
        quick.unmount();
    }
});
