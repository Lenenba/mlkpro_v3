import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import test from 'node:test';

const read = (path) => readFileSync(resolve(path), 'utf8');

const loadWorkspaceHubBuilder = async () => {
    const source = read('resources/js/utils/workspaceHub.js')
        .replace(/^import .*;\n/gm, '')
        .replace('export function buildWorkspaceHubCategories', 'function buildWorkspaceHubCategories');
    const dependencies = `
        const isFeatureEnabled = (features, key) => features?.[key] === true;
        const hasAccountPermission = (account, permission) => (account?.permissions || []).includes(permission);
        const hasAnyAccountPermission = (account, permissions) => permissions.some((permission) => hasAccountPermission(account, permission));
    `;
    const moduleSource = `${dependencies}\n${source}\nexport { buildWorkspaceHubCategories };`;

    return import(`data:text/javascript;base64,${Buffer.from(moduleSource).toString('base64')}`);
};

const serviceOwner = (features) => ({
    is_owner: true,
    is_client: false,
    features,
    permissions: ['sales.manage'],
    company: { type: 'services' },
    team: { role: null },
});

test('advanced sales CRM cards stay hidden until sales_crm is explicitly enabled', async () => {
    const { buildWorkspaceHubCategories } = await loadWorkspaceHubBuilder();
    const advancedCards = ['next_actions', 'sales_inbox', 'manager_dashboard'];

    const disabledRevenue = buildWorkspaceHubCategories({
        account: serviceOwner({ sales: true, sales_crm: false }),
    }).find((category) => category.key === 'revenue');

    assert.ok(disabledRevenue);
    assert.ok(disabledRevenue.modules.some((module) => module.key === 'customers'));
    assert.deepEqual(
        disabledRevenue.modules.filter((module) => advancedCards.includes(module.key)),
        [],
    );

    const enabledRevenue = buildWorkspaceHubCategories({
        account: serviceOwner({ sales: true, sales_crm: true }),
    }).find((category) => category.key === 'revenue');

    assert.deepEqual(
        enabledRevenue.modules
            .filter((module) => advancedCards.includes(module.key))
            .map((module) => module.key)
            .sort(),
        [...advancedCards].sort(),
    );
});

test('advanced sales CRM routes require both sales and sales_crm', () => {
    const routes = read('routes/web.php');

    assert.match(
        routes,
        /Route::middleware\(\['company\.feature:sales', 'company\.feature:sales_crm'\]\)->group/,
    );
});
