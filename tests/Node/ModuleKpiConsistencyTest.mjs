import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import test from 'node:test';
import { buildKpiProgress } from '../../resources/js/utils/kpi.js';

const read = (path) => readFileSync(resolve(path), 'utf8');

const sharedGridImport = /import\s+KpiMetricGrid\s+from\s+['"]@\/Components\/Dashboard\/KpiMetricGrid\.vue['"]/u;
const sharedGridRender = /<KpiMetricGrid\b/u;

const assertUsesSharedGrid = (path, label) => {
    const source = read(path);

    assert.match(source, sharedGridImport, `${label} imports KpiMetricGrid`);
    assert.match(source, sharedGridRender, `${label} renders KpiMetricGrid`);
};

test('primary migrated KPI components use the shared KPI grid', () => {
    const statsComponents = [
        'resources/js/Components/Customer/CustomerKpiGrid.vue',
        'resources/js/Components/Reservation/ReservationStats.vue',
        'resources/js/Components/UI/ExpenseStats.vue',
        'resources/js/Components/UI/InvoiceStats.vue',
        'resources/js/Components/UI/OrdersStats.vue',
        'resources/js/Components/UI/PlanScanStats.vue',
        'resources/js/Components/UI/ProductStats.vue',
        'resources/js/Components/UI/QuoteStats.vue',
        'resources/js/Components/UI/RequestStats.vue',
        'resources/js/Components/UI/SalesStats.vue',
        'resources/js/Components/UI/ServiceStats.vue',
        'resources/js/Components/UI/TaskStats.vue',
        'resources/js/Components/UI/TeamStats.vue',
        'resources/js/Components/UI/WorkStats.vue',
    ];

    for (const path of statsComponents) {
        assertUsesSharedGrid(path, path);
    }
});

test('representative module pages use the shared KPI grid', () => {
    const representativePages = [
        { domain: 'CRM', path: 'resources/js/Pages/CRM/ManagerDashboard.vue' },
        { domain: 'campaigns', path: 'resources/js/Pages/Campaigns/Index.vue' },
        { domain: 'finance', path: 'resources/js/Pages/FinanceApprovals/Index.vue' },
        { domain: 'performance', path: 'resources/js/Pages/Performance/Index.vue' },
        { domain: 'portal', path: 'resources/js/Pages/Portal/Packages/Index.vue' },
        { domain: 'reservations', path: 'resources/js/Pages/Reservation/Screen.vue' },
        { domain: 'social', path: 'resources/js/Pages/Social/Components/SocialAutomationManager.vue' },
        { domain: 'super-admin', path: 'resources/js/Pages/SuperAdmin/Dashboard.vue' },
    ];

    for (const { domain, path } of representativePages) {
        assertUsesSharedGrid(path, `${domain} representative (${path})`);
    }
});

test('shared KPI contract preserves loading, test IDs, interaction, and auto-fit layout', () => {
    const metricGrid = read('resources/js/Components/Dashboard/KpiMetricGrid.vue');
    const metricCard = read('resources/js/Components/Dashboard/KpiMetricCard.vue');

    assert.match(metricCard, /:aria-busy="metric\.loading \? 'true' : undefined"/u);
    assert.match(metricCard, /v-if="metric\.loading"/u);
    assert.match(metricCard, /animate-pulse/u);
    assert.match(metricCard, /:data-testid="metric\.testId"/u);
    assert.match(metricCard, /:data-measurement-status="metric\.measurementStatus"/u);
    assert.match(metricCard, /const progress = computed/u);
    assert.match(metricCard, /role="progressbar"/u);
    assert.match(metricCard, /progress\.label \|\| metric\.label/u);

    assert.match(metricCard, /defineEmits\(\['activate'\]\)/u);
    assert.match(metricCard, /const interactive = computed/u);
    assert.match(metricCard, /@click="activate"/u);
    assert.match(metricCard, /emit\('activate', props\.metric\?\.action \?\? props\.metric\)/u);
    assert.match(metricGrid, /defineEmits\(\['activate'\]\)/u);
    assert.match(metricGrid, /@activate="\$emit\('activate', \$event\)"/u);

    assert.match(
        metricGrid,
        /grid-cols-\[repeat\(auto-fit,minmax\(min\(100%,12rem\),1fr\)\)\]/u,
    );
    assert.match(metricGrid, /v-for="metric in metrics"/u);
    assert.match(metricGrid, /:metric="metric"/u);
});

test('customer KPI graphics use real proportions and Performance never exposes a raw translation key', () => {
    const customerStats = read('resources/js/Components/UI/CustomerStats.vue');
    const customerGrid = read('resources/js/Components/Customer/CustomerKpiGrid.vue');
    const performanceIndex = read('resources/js/Pages/Performance/Index.vue');
    const performanceEmployee = read('resources/js/Pages/Performance/EmployeeShow.vue');

    assert.match(customerStats, /const customerShare = \(value, label\) =>/u);
    assert.match(customerStats, /max: maximum/u);
    assert.match(customerStats, /progress: customerShare\(source\.value\.new_this_month/u);
    assert.match(customerStats, /progress: percentageProgress\(source\.value\.return_rate/u);
    assert.match(customerGrid, /progress: card\.progress/u);

    for (const source of [performanceIndex, performanceEmployee]) {
        assert.doesNotMatch(source, /reservations\.performance\.avg_service_value/u);
        assert.match(source, /performance\.kpi\.avg_service/u);
    }
});

test('shared KPI progress rejects invalid denominators and status modules use real totals', () => {
    assert.deepEqual(buildKpiProgress(3, 12), { value: 3, max: 12 });
    assert.deepEqual(buildKpiProgress(-2, 12, 'Status'), { value: 0, max: 12, label: 'Status' });
    assert.deepEqual(buildKpiProgress(15, 12), { value: 12, max: 12 });
    assert.equal(buildKpiProgress(null, 12), null);
    assert.equal(buildKpiProgress(2, 0), null);
    assert.equal(buildKpiProgress('invalid', 12), null);

    const statusComponents = [
        'resources/js/Components/UI/ExpenseStats.vue',
        'resources/js/Components/UI/InvoiceStats.vue',
        'resources/js/Components/UI/OrdersStats.vue',
        'resources/js/Components/UI/PlanScanStats.vue',
        'resources/js/Components/UI/ProductStats.vue',
        'resources/js/Components/UI/QuoteStats.vue',
        'resources/js/Components/UI/RequestStats.vue',
        'resources/js/Components/UI/SalesStats.vue',
        'resources/js/Components/UI/ServiceStats.vue',
        'resources/js/Components/UI/TaskStats.vue',
        'resources/js/Components/UI/TeamStats.vue',
        'resources/js/Components/UI/WorkStats.vue',
    ];

    for (const path of statusComponents) {
        const source = read(path);
        assert.match(source, /import \{ buildKpiProgress \} from '@\/utils\/kpi'/u, path);
        assert.match(source, /progress: buildKpiProgress\(/u, path);
    }
});

test('temporal KPI graphics use chronological source data instead of unrelated snapshots', () => {
    const accounting = read('resources/js/Pages/Accounting/Index.vue');
    const campaign = read('resources/js/Pages/Campaigns/Show.vue');
    const expenseRecap = read('resources/js/Pages/Expense/UI/ExpensePeriodRecap.vue');
    const product = read('resources/js/Pages/Product/Show.vue');
    const performance = read('resources/js/Pages/Performance/Index.vue');

    assert.match(accounting, /props\.periods\.slice\(0, 6\)\.reverse\(\)/u);
    assert.match(accounting, /accountingPeriods\.value\.map\(\(period\) => Number\(period\?\.\[key\]/u);

    assert.match(campaign, /runs\.value\.slice\(0, 12\)\.reverse\(\)/u);
    assert.match(campaign, /runSummaryValue\(run, field\.key\)/u);
    assert.match(campaign, /points: series\.length \? buildSparklinePoints\(series\) : \[\]/u);

    assert.match(expenseRecap, /const values = \[Number\(previous\), Number\(current\)\]/u);
    assert.match(product, /runningTotal -= Number\(movement\.quantity\)/u);
    assert.match(product, /newestToOldest\.reverse\(\)/u);

    assert.doesNotMatch(performance, /buildSparklinePoints/u);
});

test('dense KPI layouts retain a readable minimum card width', () => {
    const responsiveSources = [
        'resources/js/Components/Dashboard/ScenarioBusinessOverview.vue',
        'resources/js/Components/Reservation/ReservationStats.vue',
        'resources/js/Pages/Accounting/Index.vue',
        'resources/js/Pages/Campaigns/Show.vue',
        'resources/js/Pages/DashboardProductsOwner.vue',
        'resources/js/Pages/Performance/Index.vue',
        'resources/js/Pages/Request/UI/RequestAnalytics.vue',
        'resources/js/Pages/Social/Components/SocialAutomationManager.vue',
        'resources/js/Pages/SuperAdmin/DemoWorkspaces/Index.vue',
        'resources/js/Pages/SuperAdmin/Support/Index.vue',
    ];

    for (const path of responsiveSources) {
        const source = read(path);
        assert.match(source, /<KpiMetricGrid\b/u, path);
        assert.doesNotMatch(
            source,
            /grid-class="[^"]*(?<!2)(?:lg|xl):grid-cols-[678]/u,
            path,
        );
    }
});
