import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const source = readFileSync(new URL('../../resources/js/Pages/Request/UI/RequestTable.vue', import.meta.url), 'utf8');
const tableProps = source.match(/const tableReloadProps = \[[\s\S]*?\];/u)?.[0] ?? '';

function handler(pattern, name, environment) {
    const declaration = source.match(pattern)?.[0];
    assert.ok(declaration, `${name} must exist`);
    return new Function(...Object.keys(environment), `${tableProps}\n${declaration}\nreturn ${name};`)(...Object.values(environment));
}

test('prospect filters reload table data while preserving the global analytics already displayed', () => {
    const visits = [];
    const reload = handler(/const autoFilter = \(\) => \{[\s\S]*?\n\};/u, 'autoFilter', {
        filterTimeout: null,
        clearTimeout: () => {},
        setTimeout: (callback) => { callback(); return 1; },
        isLoading: { value: false },
        route: () => '/prospects',
        filterPayload: () => ({ search: 'inspection' }),
        router: { get: (url, data, options) => visits.push({ url, data, options }) },
    });

    reload();

    assert.equal(visits[0].url, '/prospects');
    assert.deepEqual(visits[0].data, { search: 'inspection' });
    assert.deepEqual(visits[0].options.only, ['requests', 'filters', 'stats']);
    assert.equal(visits[0].options.preserveState, true);
});

test('prospect bulk mutations refresh global analytics', async () => {
    const visits = [];
    const reload = handler(/const reloadBulkContext = \(\) => new Promise\(\(resolve\) => \{[\s\S]*?\n\}\);/u, 'reloadBulkContext', {
        router: { reload: (options) => { visits.push(options); options.onFinish(); } },
    });

    await reload();

    assert.ok(visits[0].only.includes('analytics'));
    assert.ok(visits[0].only.includes('requests'));
    assert.ok(visits[0].only.includes('stats'));
});

test('prospect pagination and page-size changes use the same partial table props', () => {
    assert.ok(/:pagination-only="tableReloadProps"/u.test(source));
    const props = new Function(`${tableProps}\nreturn tableReloadProps;`)();
    assert.deepEqual(props, ['requests', 'filters', 'stats']);
});

test('quick status and assignment changes request updated global analytics', () => {
    const visits = [];
    const update = handler(/const runQuickLeadUpdate = \(lead, payload, options = \{\}\) => \{[\s\S]*?\n\};/u, 'runQuickLeadUpdate', {
        processingId: { value: null },
        route: () => '/prospects/12',
        router: { put: (url, data, options) => visits.push({ url, data, options }) },
    });

    update({ id: 12 }, { status: 'REQ_WON' });

    assert.deepEqual(visits[0].data, { status: 'REQ_WON' });
    assert.ok(visits[0].options.only.includes('analytics'));
});
