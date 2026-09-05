import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import { pathToFileURL } from 'node:url';
import { compileScript, parse } from '@vue/compiler-sfc';
import { createRenderer, h, nextTick, reactive } from 'vue';

const componentModules = new Map();

const compiledComponentUrl = (filename) => {
    if (componentModules.has(filename)) {
        return componentModules.get(filename);
    }

    const { descriptor } = parse(fs.readFileSync(filename, 'utf8'), { filename });
    const compiled = compileScript(descriptor, { id: filename, inlineTemplate: true }).content;
    const executable = compiled.replace(/(from\s+|import\s+)['"]([^'"]+)['"]/gu, (_, prefix, specifier) => {
        let url;
        if (specifier.startsWith('@/')) {
            const dependency = `resources/js/${specifier.slice(2)}`;
            url = dependency.endsWith('.vue')
                ? compiledComponentUrl(dependency)
                : pathToFileURL(`${dependency}.js`).href;
        } else {
            url = import.meta.resolve(specifier);
        }

        return `${prefix}${JSON.stringify(url)}`;
    });
    const url = `data:text/javascript;base64,${Buffer.from(executable).toString('base64')}`;
    componentModules.set(filename, url);

    return url;
};

const mountShowcase = async (initialSection) => {
    const { default: component } = await import(compiledComponentUrl('resources/js/Components/Public/FeatureTabsShowcaseSection.vue'));
    const node = (type, text = '') => ({ type, text, children: [], props: {}, parent: null });
    const remove = (child) => {
        const siblings = child.parent?.children;
        if (siblings) {
            siblings.splice(siblings.indexOf(child), 1);
        }
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
    const state = reactive({ section: initialSection });
    const root = node('root');
    const app = renderer.createApp({ render: () => h(component, { section: state.section }) });
    app.mount(root);

    const withClass = (className) => {
        const matches = [];
        const visit = (element) => {
            if (String(element.props.class || '').split(/\s+/u).includes(className)) matches.push(element);
            element.children.forEach(visit);
        };
        visit(root);
        return matches;
    };

    return {
        state,
        withClass,
        panelTitle: () => withClass('feature-tabs-showcase__copy-title')[0]?.text,
        click: async (element) => { element.props.onClick(); await nextTick(); },
        unmount: () => app.unmount(),
    };
};

const sectionFixture = (style = 'editorial') => ({
    feature_tabs_style: style,
    feature_tabs: [
        {
            id: 'booking', label: 'Réservations', title: 'Gérer les réservations',
            children: [
                { id: 'calendar', label: 'Calendrier', title: 'Voir les créneaux' },
                { id: 'customers', label: 'Clients', title: 'Retrouver les clients' },
            ],
        },
        {
            id: 'payments', label: 'Paiements', title: 'Suivre les paiements',
            children: [{ id: 'invoices', label: 'Factures', title: 'Consulter les factures' }],
        },
    ],
});

for (const style of ['editorial', 'workflow']) {
    test(`${style} public feature tabs select children, collapse and switch panels through rendered buttons`, async () => {
        const showcase = await mountShowcase(sectionFixture(style));

        try {
            const triggers = () => showcase.withClass('feature-tabs-showcase__accordion-trigger');
            assert.equal(showcase.panelTitle(), 'Voir les créneaux');
            assert.deepEqual(triggers().map((button) => button.props['aria-expanded']), [false, false]);

            await showcase.click(triggers()[0]);
            assert.equal(triggers()[0].props['aria-expanded'], true);
            await showcase.click(showcase.withClass('feature-tabs-showcase__accordion-child')[1]);
            assert.equal(showcase.panelTitle(), 'Retrouver les clients');

            await showcase.click(triggers()[0]);
            assert.equal(triggers()[0].props['aria-expanded'], false);
            assert.equal(showcase.panelTitle(), 'Retrouver les clients');
            assert.equal(showcase.withClass('feature-tabs-showcase__accordion-content').length, 0);

            await showcase.click(triggers()[1]);
            assert.equal(showcase.panelTitle(), 'Consulter les factures');
            assert.deepEqual(triggers().map((button) => button.props['aria-expanded']), [false, true]);
        } finally {
            showcase.unmount();
        }
    });
}

test('public feature subtabs and changed editorial content keep a valid preview selection', async () => {
    const showcase = await mountShowcase(sectionFixture());

    try {
        await showcase.click(showcase.withClass('feature-tabs-showcase__subtab')[1]);
        assert.equal(showcase.panelTitle(), 'Retrouver les clients');
        assert.deepEqual(showcase.withClass('feature-tabs-showcase__subtab').map((button) => button.props['aria-selected']), [false, true]);

        showcase.state.section = { feature_tabs: [sectionFixture().feature_tabs[1]] };
        await nextTick();
        assert.equal(showcase.panelTitle(), 'Consulter les factures');

        showcase.state.section = { feature_tabs: [] };
        await nextTick();
        assert.equal(showcase.panelTitle(), undefined);
        assert.equal(showcase.withClass('feature-tabs-showcase__accordion-trigger').length, 0);
    } finally {
        showcase.unmount();
    }
});
