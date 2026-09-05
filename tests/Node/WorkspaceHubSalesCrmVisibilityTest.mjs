import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import test from 'node:test';

const read = (path) => readFileSync(resolve(path), 'utf8');

const loadWorkspaceHubBuilder = async () => {
    const source = read('resources/js/utils/workspaceHub.js')
        .replace(/^import[\s\S]*?from ['"][^'"]+['"];\n/gm, '')
        .replace('export function buildWorkspaceHubCategories', 'function buildWorkspaceHubCategories');
    const dependencies = `
        const isFeatureEnabled = (features, key) => features?.[key] === true;
        const hasAccountPermission = (account, permission) => (account?.permissions || []).includes(permission);
        const hasAnyAccountPermission = (account, permissions) => permissions.some((permission) => hasAccountPermission(account, permission));
        const hasAccountModuleAccess = (account, module) => account?.module_access
            && Object.prototype.hasOwnProperty.call(account.module_access, module)
            ? Boolean(account.module_access[module])
            : Boolean(account?.is_owner) || Boolean(account?.is_superadmin);
    `;
    const moduleSource = `${dependencies}\n${source}\nexport { buildWorkspaceHubCategories };`;

    return import(`data:text/javascript;base64,${Buffer.from(moduleSource).toString('base64')}`);
};

const serviceOwner = (features) => ({
    is_owner: true,
    is_client: false,
    features,
    permissions: ['sales.manage'],
    module_access: { customers: true, products: true },
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

test('sales and orders cards follow the sales capability for service and product tenants', async () => {
    const { buildWorkspaceHubCategories } = await loadWorkspaceHubBuilder();

    for (const companyType of ['services', 'products']) {
        const categories = buildWorkspaceHubCategories({
            account: {
                is_owner: true,
                is_client: false,
                features: { sales: true },
                permissions: ['sales.manage'],
                company: { type: companyType },
                team: { role: null },
            },
        });
        const visibleModules = categories.flatMap((category) => category.modules).map((module) => module.key);

        assert.ok(visibleModules.includes('sales'));
        assert.ok(visibleModules.includes('orders'));
    }
});

test('sales navigation still requires the tenant feature and a member permission', async () => {
    const { buildWorkspaceHubCategories } = await loadWorkspaceHubBuilder();
    const visibleModuleKeys = (account) => buildWorkspaceHubCategories({ account })
        .flatMap((category) => category.modules)
        .map((module) => module.key);
    const account = {
        is_owner: false,
        is_client: false,
        features: { sales: true },
        permissions: [],
        module_access: { customers: false, products: false },
        company: { type: 'services' },
        team: { role: 'member' },
    };

    assert.ok(!visibleModuleKeys(account).includes('sales'));
    assert.ok(!visibleModuleKeys(account).includes('orders'));
    assert.ok(visibleModuleKeys({ ...account, permissions: ['sales.pos'] }).includes('sales'));
    assert.ok(visibleModuleKeys({ ...account, permissions: ['sales.pos'] }).includes('orders'));
    assert.ok(!visibleModuleKeys({ ...account, features: { sales: false }, permissions: ['sales.pos'] }).includes('sales'));
});

test('sidebar fallback uses the same sales capability contract', () => {
    const sidebar = read('resources/js/Layouts/UI/Sidebar.vue');

    assert.match(sidebar, /v-if="hasFeature\('sales'\) && canSales"[^>]*nav\.orders/);
    assert.match(sidebar, /v-if="hasFeature\('sales'\) && canSales"[^>]*nav\.sales/);
    assert.doesNotMatch(sidebar, /companyType === 'products' && hasFeature\('sales'\) && canSales"[^>]*nav\.(orders|sales)/);
});

test('operational cards use enabled capabilities instead of company type', async () => {
    const { buildWorkspaceHubCategories } = await loadWorkspaceHubBuilder();
    const visibleModuleKeys = (account) => buildWorkspaceHubCategories({ account })
        .flatMap((category) => category.modules)
        .map((module) => module.key);

    const productReservations = {
        is_owner: false,
        is_client: false,
        features: { reservations: true, performance: true, planning: true },
        permissions: ['reservations.view', 'reports.view'],
        module_access: { customers: false, products: false },
        company: { type: 'products' },
        team: { role: 'member' },
    };
    const reservationModules = visibleModuleKeys(productReservations);

    assert.ok(reservationModules.includes('reservations'));
    assert.ok(reservationModules.includes('performance'));
    assert.ok(reservationModules.includes('planning'));

    const noSourceModules = visibleModuleKeys({
        ...productReservations,
        features: { performance: true, planning: true },
    });
    assert.ok(!noSourceModules.includes('performance'));
    assert.ok(!noSourceModules.includes('planning'));

    const serviceSalesModules = visibleModuleKeys({
        is_owner: false,
        is_client: false,
        features: { sales: true, products: true, performance: true, planning: true },
        permissions: ['sales.manage'],
        module_access: { customers: false, products: false },
        company: { type: 'services' },
        team: { role: 'manager' },
    });
    assert.ok(!serviceSalesModules.includes('customers'));
    assert.ok(!serviceSalesModules.includes('products'));
    assert.ok(serviceSalesModules.includes('performance'));
    assert.ok(serviceSalesModules.includes('planning'));
});

test('presence card requires both the enabled module and the member permission', async () => {
    const { buildWorkspaceHubCategories } = await loadWorkspaceHubBuilder();
    const visibleModuleKeys = (account) => buildWorkspaceHubCategories({ account })
        .flatMap((category) => category.modules)
        .map((module) => module.key);
    const member = {
        is_owner: false,
        is_client: false,
        features: { presence: true },
        permissions: ['view_presence'],
        module_access: { customers: false, products: false },
        company: { type: 'services' },
        team: { role: 'member' },
    };

    assert.ok(visibleModuleKeys(member).includes('presence'));
    assert.ok(!visibleModuleKeys({ ...member, features: { presence: false } }).includes('presence'));
    assert.ok(!visibleModuleKeys({ ...member, permissions: [] }).includes('presence'));
});

test('customer and product cards follow module access independently from sales permissions', async () => {
    const { buildWorkspaceHubCategories } = await loadWorkspaceHubBuilder();
    const visibleModuleKeys = (account) => buildWorkspaceHubCategories({ account })
        .flatMap((category) => category.modules)
        .map((module) => module.key);
    const salesMember = {
        is_owner: false,
        is_client: false,
        features: { sales: true, products: true },
        permissions: ['sales.manage'],
        module_access: { customers: false, products: false },
        company: { type: 'services' },
        team: { role: 'manager' },
    };

    for (const permission of ['sales.manage', 'sales.pos']) {
        const salesOnlyModules = visibleModuleKeys({ ...salesMember, permissions: [permission] });

        assert.ok(!salesOnlyModules.includes('customers'));
        assert.ok(!salesOnlyModules.includes('products'));
    }

    const customerModules = visibleModuleKeys({
        ...salesMember,
        module_access: { customers: true, products: false },
    });
    assert.ok(customerModules.includes('customers'));
    assert.ok(!customerModules.includes('products'));

    const productModules = visibleModuleKeys({
        ...salesMember,
        module_access: { customers: false, products: true },
    });
    assert.ok(!productModules.includes('customers'));
    assert.ok(productModules.includes('products'));

    const sellerModules = visibleModuleKeys({
        ...salesMember,
        team: { role: 'seller' },
        module_access: { customers: true, products: true },
    });
    assert.ok(sellerModules.includes('customers'));
    assert.ok(sellerModules.includes('products'));
});

test('product and customer UI gates follow features and permissions', () => {
    const customerShow = read('resources/js/Pages/Customer/Show.vue');
    const productTable = read('resources/js/Pages/Product/UI/ProductTable.vue');
    const globalSearch = read('resources/js/Components/UI/GlobalSearch.vue');
    const quickMenu = read('resources/js/Components/UI/LinkAncor2.vue');
    const quickCreateModals = read('resources/js/Components/QuickCreate/QuickCreateModals.vue');
    const productTableList = read('resources/js/Components/ProductTableList.vue');
    const quoteCreate = read('resources/js/Pages/Quote/Create.vue');
    const workCreate = read('resources/js/Pages/Work/Create.vue');
    const sidebar = read('resources/js/Layouts/UI/Sidebar.vue');
    const dashboard = read('resources/js/Pages/Dashboard.vue');

    assert.match(customerShow, /showSales = computed\(\(\) => hasFeature\('sales'\) && allowsDetail\('sales'\)\)/);
    assert.match(customerShow, /v-if="canViewNotes"/);
    assert.match(customerShow, /v-if="canManageNotes"/);
    assert.doesNotMatch(customerShow, /showServiceOps = computed\(\(\) => companyType/);
    assert.match(productTable, /canCreateBulkOrder = computed\(\(\) => resolveAbility\('create_order'\)\)/);
    assert.match(productTable, /v-if="canAdjustStock"/);
    assert.match(productTable, /:can-delete="canDelete"/);
    assert.doesNotMatch(globalSearch, /companyType\.value === 'products' && hasFeature\('sales'\)/);
    assert.match(globalSearch, /hasModuleAccess\('products'\)[\s\S]*hasPermission\('products\.create'\)/);
    assert.doesNotMatch(globalSearch, /isOwner\.value && hasFeature\('products'\)/);
    assert.doesNotMatch(quickMenu, /companyType\.value === 'products' && hasFeature\('sales'\)/);
    assert.match(quickMenu, /hasModuleAccess\('products'\)[\s\S]*hasPermission\('products\.create'\)/);
    assert.match(quickCreateModals, /hasModuleAccess\('products'\)[\s\S]*hasPermission\('products\.create'\)/);
    assert.match(productTable, /<Modal v-if="canCreate"[^>]+hs-pro-dasadpm/);
    assert.match(productTableList, /scope: props\.searchScope \|\| undefined/);
    assert.match(quoteCreate, /search-scope="quote"/);
    assert.match(workCreate, /search-scope="job"/);
    assert.match(sidebar, /v-if="hasFeature\('reservations'\) && canReservations/);
    assert.doesNotMatch(sidebar, /showServices && hasFeature\('reservations'\)/);
    assert.match(dashboard, /!hasFeature\('products'\) \|\| !canProducts\.value/);
    assert.match(dashboard, /v-if="inventoryPanelMetrics\.length"/);
});
