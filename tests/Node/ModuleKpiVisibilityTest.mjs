import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import test from 'node:test';
import {
    KPI_VISIBILITY_CHANGE_EVENT,
    KPI_VISIBILITY_STORAGE_PREFIX,
    buildKpiVisibilityStorageKey,
    parseKpiVisibilityValue,
    readKpiVisibility,
    writeKpiVisibility,
} from '../../resources/js/utils/kpiVisibility.js';

const read = (path) => readFileSync(resolve(path), 'utf8');

const wrapper = read('resources/js/Components/Dashboard/ModuleKpiSection.vue');
const metricGrid = read('resources/js/Components/Dashboard/KpiMetricGrid.vue');

const memoryStorage = (entries = []) => {
    const values = new Map(entries);

    return {
        getItem: (key) => values.has(key) ? values.get(key) : null,
        setItem: (key, value) => values.set(key, value),
        values,
    };
};

const moduleSections = (source) => [
    ...source.matchAll(/<ModuleKpiSection\b(?<attributes>[^>]*)>(?<body>[\s\S]*?)<\/ModuleKpiSection>/gu),
].map((match) => ({
    attributes: match.groups.attributes,
    body: match.groups.body,
}));

test('KPI visibility storage keys isolate every account, user, and module', () => {
    const base = {
        accountOwnerId: 42,
        userId: 7,
        moduleKey: 'reservations',
    };
    const key = buildKpiVisibilityStorageKey(base);

    assert.equal(KPI_VISIBILITY_STORAGE_PREFIX, 'mlkpro:ui:kpis:v1');
    assert.equal(key, 'mlkpro:ui:kpis:v1:42:7:reservations');
    assert.notEqual(key, buildKpiVisibilityStorageKey({ ...base, accountOwnerId: 43 }));
    assert.notEqual(key, buildKpiVisibilityStorageKey({ ...base, userId: 8 }));
    assert.notEqual(key, buildKpiVisibilityStorageKey({ ...base, moduleKey: 'tasks' }));
    assert.match(
        buildKpiVisibilityStorageKey({ ...base, moduleKey: 'finance approvals:owner' }),
        /finance%20approvals%3Aowner$/u,
    );
});

test('KPI visibility defaults safely and accepts only persisted 1 and 0 values', () => {
    const key = buildKpiVisibilityStorageKey({
        accountOwnerId: 42,
        userId: 7,
        moduleKey: 'reservations',
    });
    const storage = memoryStorage();

    assert.equal(parseKpiVisibilityValue(null), true);
    assert.equal(parseKpiVisibilityValue('invalid'), true);
    assert.equal(parseKpiVisibilityValue('invalid', false), false);
    assert.equal(parseKpiVisibilityValue('1', false), true);
    assert.equal(parseKpiVisibilityValue('0', true), false);
    assert.equal(readKpiVisibility(storage, key), true);
    assert.equal(readKpiVisibility(null, key), true);
    assert.equal(readKpiVisibility(null, key, false), false);

    assert.equal(writeKpiVisibility(storage, key, false), true);
    assert.equal(storage.values.get(key), '0');
    assert.equal(readKpiVisibility(storage, key), false);

    assert.equal(writeKpiVisibility(storage, key, true), true);
    assert.equal(storage.values.get(key), '1');
    assert.equal(readKpiVisibility(storage, key, false), true);
});

test('KPI visibility storage failures never break the current interface', () => {
    const key = 'mlkpro:ui:kpis:v1:42:7:reservations';
    const unreadableStorage = {
        getItem() {
            throw new Error('Storage access denied');
        },
    };
    const unwritableStorage = {
        setItem() {
            throw new Error('Storage quota exceeded');
        },
    };

    assert.doesNotThrow(() => readKpiVisibility(unreadableStorage, key));
    assert.equal(readKpiVisibility(unreadableStorage, key), true);
    assert.equal(readKpiVisibility(unreadableStorage, key, false), false);
    assert.doesNotThrow(() => writeKpiVisibility(unwritableStorage, key, false));
    assert.equal(writeKpiVisibility(unwritableStorage, key, false), false);
    assert.equal(writeKpiVisibility(null, key, false), false);
});

test('the shared module KPI disclosure has a native and explicit accessibility contract', () => {
    assert.match(
        wrapper,
        /moduleKey:\s*\{[\s\S]*?type:\s*String,[\s\S]*?required:\s*true,[\s\S]*?\}/u,
    );
    assert.match(wrapper, /import \{[^}]*\buseId\b[^}]*\} from 'vue'/u);
    assert.match(wrapper, /const contentId = `module-kpis-\$\{useId\(\)\.replaceAll\(':', ''\)\}`/u);
    assert.match(wrapper, /<button[\s\S]*?type="button"/u);
    assert.match(wrapper, /<section[^>]*:aria-label="t\('kpi_visibility\.title'\)"/u);
    assert.match(wrapper, /:aria-expanded="String\(isVisible\)"/u);
    assert.match(wrapper, /:aria-controls="contentId"/u);
    assert.match(wrapper, /min-h-11/u);
    assert.match(wrapper, /focus-visible:ring-2/u);
    assert.match(
        wrapper,
        /t\(isVisible \? 'kpi_visibility\.hide' : 'kpi_visibility\.show'\)/u,
    );

    const hiddenIcons = [...wrapper.matchAll(/<svg\b[\s\S]*?aria-hidden="true"[\s\S]*?<\/svg>/gu)];
    assert.equal(hiddenIcons.length, 2, 'both visibility icons remain decorative');
});

test('the hidden KPI state stays compact while leaving the restore control available', () => {
    assert.match(
        wrapper,
        /<\/button>[\s\S]*?<div :id="contentId" v-show="isVisible">\s*<slot \/>\s*<\/div>/u,
        'the button stays outside the conditionally displayed KPI content',
    );
    assert.doesNotMatch(wrapper, /v-if="!isVisible"/u);
    assert.doesNotMatch(wrapper, /kpi_visibility\.(?:hidden|empty|placeholder)/u);
    assert.doesNotMatch(wrapper, /aria-live=/u);
});

test('module KPI visibility synchronizes local instances and browser tabs with cleanup', () => {
    assert.equal(KPI_VISIBILITY_CHANGE_EVENT, 'mlkpro:kpi-visibility-change');
    assert.match(wrapper, /window\.dispatchEvent\(new CustomEvent\(KPI_VISIBILITY_CHANGE_EVENT/u);
    assert.match(wrapper, /window\.addEventListener\('storage', handleStorageChange\)/u);
    assert.match(wrapper, /window\.addEventListener\(KPI_VISIBILITY_CHANGE_EVENT, handleVisibilityChange\)/u);
    assert.match(wrapper, /onBeforeUnmount\(\(\) => \{/u);
    assert.match(wrapper, /window\.removeEventListener\('storage', handleStorageChange\)/u);
    assert.match(wrapper, /window\.removeEventListener\(KPI_VISIBILITY_CHANGE_EVENT, handleVisibilityChange\)/u);
    assert.match(wrapper, /watch\(storageKey,[\s\S]*?restoreVisibility\(\)/u);
});

test('KPI visibility labels are complete in every supported locale', () => {
    for (const locale of ['fr', 'en', 'es']) {
        const messages = JSON.parse(read(`resources/js/i18n/modules/${locale}/shared_ui.json`));

        for (const key of ['title', 'show', 'hide']) {
            const value = messages.kpi_visibility?.[key];

            assert.equal(typeof value, 'string', `${locale}:kpi_visibility.${key}`);
            assert.notEqual(value.trim(), '', `${locale}:kpi_visibility.${key}`);
        }
    }
});

test('primary module pages opt in once with stable module scopes', () => {
    const pages = [
        ['resources/js/Pages/Customer/Index.vue', 'customers', 1],
        ['resources/js/Pages/Reservation/Index.vue', 'reservations', 1],
        ['resources/js/Pages/Reservation/ClientIndex.vue', 'reservations', 1],
        ['resources/js/Pages/Invoice/Index.vue', 'invoices', 1],
        ['resources/js/Pages/Orders/Index.vue', 'orders', 1],
        ['resources/js/Pages/PlanScan/Index.vue', 'plan-scans', 1],
        ['resources/js/Pages/Product/Index.vue', 'products', 1],
        ['resources/js/Pages/Quote/Index.vue', 'quotes', 1],
        ['resources/js/Pages/Request/Index.vue', 'requests', 1],
        ['resources/js/Pages/Sales/Index.vue', 'sales', 1],
        ['resources/js/Pages/Service/Index.vue', 'services', 1],
        ['resources/js/Pages/Task/Index.vue', 'tasks', 1],
        ['resources/js/Pages/Team/Index.vue', 'team', 1],
        ['resources/js/Pages/Work/Index.vue', 'work', 1],
        ['resources/js/Pages/Campaigns/Index.vue', 'campaigns', 1],
        ['resources/js/Pages/FinanceApprovals/Index.vue', 'finance-approvals', 1],
        ['resources/js/Pages/Loyalty/Index.vue', 'loyalty', 1],
        ['resources/js/Pages/Promotions/Index.vue', 'promotions', 1],
        ['resources/js/Pages/Support/Index.vue', 'support', 1],
        ['resources/js/Pages/ServiceRequests/Index.vue', 'service-requests', 1],
        ['resources/js/Pages/Performance/Index.vue', 'performance', 2],
        ['resources/js/Pages/Accounting/Index.vue', 'accounting', 1],
        ['resources/js/Pages/OfferPackages/Index.vue', 'offer-packages', 1],
        ['resources/js/Pages/AiAssistant/Conversations/Index.vue', 'ai-assistant', 1],
        ['resources/js/Pages/Tips/OwnerIndex.vue', 'tips', 1],
        ['resources/js/Pages/Tips/MemberIndex.vue', 'tips', 1],
    ];

    for (const [path, moduleKey, expectedCount] of pages) {
        const source = read(path);
        const sections = moduleSections(source);

        assert.match(
            source,
            /import ModuleKpiSection from '@\/Components\/Dashboard\/ModuleKpiSection\.vue'/u,
            `${path} imports the shared disclosure`,
        );
        assert.equal(sections.length, expectedCount, `${path} renders ${expectedCount} module KPI disclosure(s)`);

        for (const section of sections) {
            assert.match(
                section.attributes,
                new RegExp(`\\bmodule-key="${moduleKey}"`, 'u'),
                `${path} uses the stable ${moduleKey} scope`,
            );
            assert.match(
                section.body,
                /<(?:KpiMetricGrid|[A-Z][A-Za-z]+Stats)\b/u,
                `${path} keeps KPI content inside the disclosure`,
            );
        }
    }

    const expensePage = read('resources/js/Pages/Expense/Index.vue');
    const expenseStats = read('resources/js/Components/UI/ExpenseStats.vue');
    const expenseSections = moduleSections(expenseStats);

    assert.match(expensePage, /<ExpenseStats\b/u, 'the expense module delegates its KPI area to ExpenseStats');
    assert.match(
        expenseStats,
        /import ModuleKpiSection from '@\/Components\/Dashboard\/ModuleKpiSection\.vue'/u,
    );
    assert.equal(expenseSections.length, 1, 'the expense module renders one KPI disclosure');
    assert.match(expenseSections[0].attributes, /\bmodule-key="expenses"/u);
    assert.match(expenseSections[0].body, /<KpiMetricGrid\b/u);
});

test('secondary analytical grids remain independent from the module arrival preference', () => {
    const accounting = read('resources/js/Pages/Accounting/Index.vue');
    const accountingSections = moduleSections(accounting);

    assert.equal(accountingSections.length, 1);
    assert.match(accountingSections[0].body, /:metrics="sourceCards"/u);
    for (const metrics of [
        'mobileSummaryCards',
        'periodSummaryCards',
        'taxCards',
        'taxSourceCards',
        'reviewStatusCards',
        'summaryCards',
    ]) {
        assert.match(accounting, new RegExp(`:metrics="${metrics}"`, 'u'), `Accounting still renders ${metrics}`);
        assert.doesNotMatch(
            accountingSections[0].body,
            new RegExp(`:metrics="${metrics}"`, 'u'),
            `${metrics} stays outside the arrival disclosure`,
        );
    }

    const offerPackages = read('resources/js/Pages/OfferPackages/Index.vue');
    const offerPackageSections = moduleSections(offerPackages);

    assert.equal(offerPackageSections.length, 1);
    assert.match(offerPackageSections[0].body, /:metrics="statMetrics"/u);
    assert.match(offerPackages, /:metrics="reportCards"/u);
    assert.doesNotMatch(offerPackageSections[0].body, /:metrics="reportCards"/u);
});

test('dashboards, records, and embedded KPI surfaces stay outside module preferences', () => {
    const excludedPages = [
        'resources/js/Pages/Dashboard.vue',
        'resources/js/Pages/DashboardAdmin.vue',
        'resources/js/Pages/DashboardClient.vue',
        'resources/js/Pages/DashboardMember.vue',
        'resources/js/Pages/DashboardProductsClient.vue',
        'resources/js/Pages/DashboardProductsOwner.vue',
        'resources/js/Pages/CRM/ManagerDashboard.vue',
        'resources/js/Pages/Customer/Show.vue',
        'resources/js/Pages/Product/Show.vue',
        'resources/js/Pages/Campaigns/Show.vue',
        'resources/js/Pages/OfferPackages/Show.vue',
        'resources/js/Pages/Performance/EmployeeShow.vue',
        'resources/js/Pages/SuperAdmin/DemoWorkspaces/Show.vue',
        'resources/js/Pages/Customer/UI/CustomerBulkContactModal.vue',
        'resources/js/Pages/Customer/UI/CustomerPreviewCard.vue',
        'resources/js/Pages/Product/UI/ProductTable.vue',
        'resources/js/Pages/Service/Categories.vue',
        'resources/js/Pages/Portal/Loyalty/Index.vue',
        'resources/js/Pages/Portal/Packages/Index.vue',
        'resources/js/Pages/CRM/MyNextActions.vue',
        'resources/js/Pages/CRM/SalesInbox.vue',
        'resources/js/Pages/Campaigns/PlaybookRuns.vue',
        'resources/js/Pages/Campaigns/Components/ProspectProviderManager.vue',
        'resources/js/Pages/Campaigns/Components/TemplateManager.vue',
    ];

    for (const path of excludedPages) {
        assert.doesNotMatch(read(path), /ModuleKpiSection/u, path);
    }

    assert.doesNotMatch(metricGrid, /ModuleKpiSection/u);
    assert.doesNotMatch(metricGrid, /kpiVisibility/u);
    assert.doesNotMatch(metricGrid, /localStorage/u);
    assert.doesNotMatch(metricGrid, /KPI_VISIBILITY_CHANGE_EVENT/u);
});
