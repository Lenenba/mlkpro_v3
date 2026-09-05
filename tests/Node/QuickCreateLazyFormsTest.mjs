import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';
import { createRenderer, h, nextTick, reactive } from 'vue';
import { createI18n } from 'vue-i18n';
import '@inertiajs/vue3';
import { createQuickFormLoader, quickFormRetryUrl } from '../../resources/js/utils/quickFormLoader.js';

const moduleUrl = (source) => `data:text/javascript;base64,${Buffer.from(source).toString('base64')}`;
const moduleCache = new Map();
const componentUrl = (name) => {
    if (moduleCache.has(name)) return moduleCache.get(name);

    const filename = `resources/js/Components/${name}.vue`;
    const inlineTemplate = name.includes('Placeholder');
    const { descriptor } = parse(readFileSync(filename, 'utf8'), { filename });
    const compiled = descriptor.script || descriptor.scriptSetup
        ? compileScript(descriptor, { id: name, inlineTemplate }).content
        : `${compileTemplate({ source: descriptor.template.content, filename, id: name }).code.replace('export function render', 'function render')}\nexport default { render };`;
    const executable = compiled.replace(/(from\s+|import\s+)['"]([^'"]+)['"]/gu, (_, prefix, specifier) => {
        let url;
        if (specifier.endsWith('Placeholder.vue')) {
            url = componentUrl(specifier.replace('@/Components/', '').replace('.vue', ''));
        } else if (specifier.endsWith('.vue')) {
            url = moduleUrl('export default {};');
        } else if (specifier.includes('useAccountFeatures')) {
            url = moduleUrl('export const useAccountFeatures = () => ({ hasFeature: () => true });');
        } else if (specifier.includes('usePermissions')) {
            url = moduleUrl('export const usePermissions = () => ({ hasModuleAccess: () => true, hasPermission: () => true });');
        } else if (specifier.includes('useGeoapifyAddressAutocomplete')) {
            url = moduleUrl('export const assignGeoapifyAddress = () => {}; export const useGeoapifyAddressAutocomplete = () => ({ resetSearch: () => {} });');
        } else if (specifier === '@inertiajs/vue3') {
            url = moduleUrl(`export { useForm, router } from ${JSON.stringify(import.meta.resolve(specifier))}; export const usePage = () => ({ props: { auth: { account: { is_owner: true } } } });`);
        } else if (specifier.startsWith('@/utils/')) {
            url = new URL(`../../resources/js/${specifier.slice(2)}.js`, import.meta.url).href;
        } else {
            url = import.meta.resolve(specifier);
        }

        return `${prefix}${JSON.stringify(url)}`;
    });
    const url = moduleUrl(executable);
    moduleCache.set(name, url);
    return url;
};

const node = (type, text = '') => ({ type, text, props: {}, children: [], parent: null });
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

const mount = (component, props = {}) => {
    const state = reactive(props);
    const root = node('root');
    const app = renderer.createApp({ render: () => h(component, state) });
    app.use(createI18n({ legacy: false, locale: 'en', missingWarn: false, fallbackWarn: false }));
    app.mount(root);
    return { props: state, root, unmount: () => app.unmount() };
};

const mountControls = async (name, props = {}) => {
    const { default: component } = await import(componentUrl(`QuickCreate/${name}`));
    let controls;
    const mounted = mount({
        ...component,
        setup: (componentProps, context) => {
            controls = component.setup(componentProps, context);
            return () => null;
        },
    }, props);
    return { ...mounted, controls };
};

const settle = async () => {
    await new Promise((resolve) => setImmediate(resolve));
    await nextTick();
};

test('request prefill survives the first lazy mount and repeated events for the same customer', async () => {
    const previousWindow = globalThis.window;
    globalThis.window = new EventTarget();
    let modals;
    let form;
    const prefillCustomer = () => {
        const event = new Event('quick-create-request');
        event.detail = { customerId: 42 };
        window.dispatchEvent(event);
    };

    try {
        modals = await mountControls('QuickCreateModals');
        prefillCustomer();
        assert.equal(modals.controls.requestModalOpened.value, false);
        assert.deepEqual(modals.controls.requestPrefill.value, { customerId: 42 });

        form = await mountControls('RequestQuickForm', {
            prefill: modals.controls.requestPrefill.value,
            customers: [{ id: 42, company_name: 'Studio Test', email: 'test@example.test', phone: '555-0100' }],
        });
        await nextTick();
        assert.equal(form.controls.form.customer_id, '42');
        assert.equal(form.controls.form.contact_name, 'Studio Test');
        assert.equal(form.controls.relationMode.value, 'existing_customer');

        form.controls.resetForm();
        form.controls.form.title = 'A different draft';
        const firstPrefill = modals.controls.requestPrefill.value;
        prefillCustomer();
        assert.notEqual(modals.controls.requestPrefill.value, firstPrefill);
        form.props.prefill = modals.controls.requestPrefill.value;
        await nextTick();
        assert.equal(form.controls.form.customer_id, '42');
        assert.equal(form.controls.form.title, '');
        assert.equal(form.controls.form.contact_email, 'test@example.test');

        prefillCustomer();
        form.props.prefill = modals.controls.requestPrefill.value;
        await nextTick();
        assert.equal(form.controls.form.contact_name, 'Studio Test');
        assert.equal(form.controls.form.contact_email, 'test@example.test');
    } finally {
        form?.unmount();
        modals?.unmount();
        if (previousWindow === undefined) delete globalThis.window;
        else globalThis.window = previousWindow;
    }
});

test('quote customer selection handles options resolved before or after the lazy mount', async () => {
    for (const loading of [false, true]) {
        const quote = await mountControls('QuoteQuickDialog', { customers: [], loading });
        try {
            assert.equal(quote.controls.mode.value, loading ? 'existing' : 'new');
            quote.props.loading = false;
            await nextTick();
            assert.equal(quote.controls.mode.value, 'new');
        } finally {
            quote.unmount();
        }
    }

    const quote = await mountControls('QuoteQuickDialog', { customers: [{ id: 42 }], loading: false });
    assert.equal(quote.controls.mode.value, 'existing');
    quote.unmount();
});

test('a failed lazy form exposes a retry action and resolves after the user retries', async () => {
    const previousWindow = globalThis.window;
    globalThis.window = new EventTarget();
    let modals;
    let asyncForm;

    try {
        modals = await mountControls('QuickCreateModals');
        let attempts = 0;
        const component = modals.controls.asyncForm('ProductQuickForm', async () => {
            attempts += 1;
            if (attempts === 1) throw new Error('Connection interrupted');
            return { render: () => h('form', { 'data-loaded': true }, 'Ready') };
        });
        assert.equal(attempts, 0);
        asyncForm = mount(component);
        await settle();
        assert.equal(attempts, 1);
        assert.equal(asyncForm.root.children[0].props.role, 'alert');

        const retry = asyncForm.root.children[0].children.find((child) => child.type === 'button');
        assert.ok(retry);
        retry.props.onClick();
        await settle();
        assert.equal(attempts, 2);
        assert.equal(asyncForm.root.children[0].type, 'form');
        assert.equal(asyncForm.root.children[0].props['data-loaded'], true);
        assert.equal(modals.controls.requestModalOpened.value, false);
    } finally {
        asyncForm?.unmount();
        modals?.unmount();
        if (previousWindow === undefined) delete globalThis.window;
        else globalThis.window = previousWindow;
    }
});

test('only the requested form asset can bypass a failed import cache', () => {
    const origin = 'https://malikia.test';
    const address = `${origin}/build/assets/ProductQuickForm-hash_123.js?lang=fr#module`;
    assert.equal(quickFormRetryUrl(new TypeError(`Failed to fetch dynamically imported module: ${address}`), 'ProductQuickForm', origin)?.href, address);

    for (const address of [
        'https://other.test/build/assets/ProductQuickForm-hash.js',
        `${origin}/build/assets/ProductQuickForm-hash.css`,
        `${origin}/build/assets/CustomerQuickForm-hash.js`,
        `${origin}/build/assets/shared-dependency.js`,
        `${origin}/uploads/ProductQuickForm-hash.js`,
        `${origin}/build/assets/ProductQuickForm-hash.js/unexpected`,
        'https://user:password@malikia.test/build/assets/ProductQuickForm-hash.js',
        'javascript:alert(1)',
        'data:text/javascript,export default {}',
    ]) {
        assert.equal(quickFormRetryUrl(new TypeError(`Failed to load: ${address}`), 'ProductQuickForm', origin), null, address);
    }

    assert.equal(quickFormRetryUrl(new TypeError('Unable to load module'), 'ProductQuickForm', origin), null);
    assert.equal(quickFormRetryUrl(new Error(`Application error: ${address}`), 'ProductQuickForm', origin), null);
    assert.equal(quickFormRetryUrl(new TypeError(`Failed to load: ${address}`), 'UnexpectedForm', origin), null);
});

test('manual form retries preserve URL parameters and fragments and use distinct session URLs', async () => {
    const origin = 'https://malikia.test';
    const address = `${origin}/build/assets/ProductQuickForm-hash.js?lang=fr#module`;
    const requested = [];
    let initialAttempts = 0;
    const loader = createQuickFormLoader('ProductQuickForm', async () => {
        initialAttempts += 1;
        throw new TypeError(`Failed to fetch dynamically imported module: ${address}`);
    }, {
        origin,
        importModule: async (url) => {
            requested.push(url);
            if (requested.length === 1) throw new TypeError(`Failed to load: ${url}`);
            return { default: 'Loaded form' };
        },
    });

    await assert.rejects(loader());
    assert.deepEqual(requested, []);
    await assert.rejects(loader());
    assert.deepEqual(await loader(), { default: 'Loaded form' });
    assert.equal(initialAttempts, 1);
    assert.equal(requested.length, 2);
    assert.notEqual(requested[0], requested[1]);
    for (const address of requested) {
        const url = new URL(address);
        assert.equal(url.origin, origin);
        assert.equal(url.pathname, '/build/assets/ProductQuickForm-hash.js');
        assert.equal(url.searchParams.get('lang'), 'fr');
        assert.ok(url.searchParams.get('mlk_form_retry'));
        assert.equal(url.hash, '#module');
    }

    const anotherLoader = createQuickFormLoader('ProductQuickForm', async () => {
        throw new TypeError(`Failed to fetch dynamically imported module: ${address}`);
    }, { origin, importModule: async (url) => requested.push(url) });
    await assert.rejects(anotherLoader());
    await anotherLoader();
    assert.equal(new Set(requested).size, 3);
});

test('errors without an eligible form URL keep using the original loader', async () => {
    const origin = 'https://malikia.test';
    for (const message of ['Unable to load module', `${origin}/build/assets/ProductQuickForm-hash.css`, `${origin}/build/assets/shared-dependency.js`]) {
        let attempts = 0;
        let alternateImports = 0;
        const loader = createQuickFormLoader('ProductQuickForm', async () => {
            attempts += 1;
            throw new TypeError(message);
        }, { origin, importModule: async () => { alternateImports += 1; } });
        await assert.rejects(loader());
        await assert.rejects(loader());
        assert.equal(attempts, 2);
        assert.equal(alternateImports, 0);
    }
});
