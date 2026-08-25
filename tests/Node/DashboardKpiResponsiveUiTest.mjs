import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import test from 'node:test';

const read = (path) => readFileSync(resolve(path), 'utf8');
const dashboard = read('resources/js/Pages/Dashboard.vue');
const panel = read('resources/js/Components/Dashboard/KpiCompositePanel.vue');
const metricGrid = read('resources/js/Components/Dashboard/KpiMetricGrid.vue');
const metricCard = read('resources/js/Components/Dashboard/KpiMetricCard.vue');

const extractFunction = (source, functionName, nextDeclaration) => {
    const start = source.indexOf(`const ${functionName} =`);
    const end = source.indexOf(`\nconst ${nextDeclaration}`, start);

    assert.notEqual(start, -1, `${functionName} declaration exists`);
    assert.notEqual(end, -1, `${functionName} declaration is bounded`);

    const declaration = source.slice(start, end);

    return Function(`"use strict"; ${declaration}; return ${functionName};`)();
};

test('dashboard overview gives finance a full row before two half-width operational panels', () => {
    const panelClass = extractFunction(dashboard, 'overviewPanelClass', 'displayCustomer');

    for (const panelName of ['finance', 'pipeline', 'inventory']) {
        assert.equal(panelClass(1, panelName), 'xl:col-span-12');
        assert.equal(panelClass(2, panelName), 'xl:col-span-6');
    }
    assert.equal(panelClass(3, 'finance'), 'xl:col-span-12');
    assert.equal(panelClass(3, 'pipeline'), 'xl:col-span-6');
    assert.equal(panelClass(3, 'inventory'), 'xl:col-span-6');

    assert.match(dashboard, /:class="overviewPanelClass\(overviewPanelCount, 'finance'\)"/u);
    assert.match(dashboard, /:class="overviewPanelClass\(overviewPanelCount, 'pipeline'\)"/u);
    assert.match(dashboard, /:class="overviewPanelClass\(overviewPanelCount, 'inventory'\)"/u);
    assert.match(dashboard, /data-testid="demo-dashboard-overview"/u);
    assert.match(dashboard, /grid min-w-0 grid-cols-1 gap-4 xl:grid-cols-12/u);
});

test('dashboard composite grids adapt one through five metrics without forcing narrow columns', () => {
    const gridClass = extractFunction(dashboard, 'adaptivePanelGridClass', 'financePanelGridClass');
    const gridRulesStart = dashboard.indexOf('const financePanelGridClass');
    const gridRulesEnd = dashboard.indexOf('const hasFinancePanel', gridRulesStart);
    const gridRules = dashboard.slice(gridRulesStart, gridRulesEnd);

    assert.equal(gridClass(1), 'grid-cols-1');
    for (const metricCount of [2, 3, 4, 5]) {
        assert.equal(
            gridClass(metricCount),
            'grid-cols-[repeat(auto-fit,minmax(min(100%,12rem),1fr))]',
        );
    }
    assert.equal(
        gridClass(4, true),
        'grid-cols-[repeat(auto-fit,minmax(min(100%,10rem),1fr))]',
    );

    assert.match(gridRules, /adaptivePanelGridClass\(financePanelMetrics\.value\.length\)/u);
    assert.match(gridRules, /adaptivePanelGridClass\(pipelinePanelMetrics\.value\.length\)/u);
    assert.match(gridRules, /adaptivePanelGridClass\(inventoryPanelMetrics\.value\.length\)/u);
    assert.match(gridRules, /adaptivePanelGridClass\(financePanelSummary\.value\.length, true\)/u);
    assert.match(gridRules, /adaptivePanelGridClass\(pipelinePanelSummary\.value\.length, true\)/u);
    assert.doesNotMatch(gridRules, /xl:grid-cols-[34]/u);
    assert.match(dashboard, /:metrics-grid-class="inventoryPanelGridClass"/u);
});

test('KPI panels and their shared metric cards preserve complete labels and values', () => {
    assert.match(panel, /flex flex-wrap items-start justify-between gap-3/u);
    assert.match(panel, /flex-\[1_1_14rem\]/u);
    assert.match(panel, /whitespace-normal/u);
    assert.match(metricCard, /grid-cols-\[auto_minmax\(0,1fr\)\]/u);
    assert.match(metricCard, /\[overflow-wrap:anywhere\]/u);
    assert.match(metricCard, /tabular-nums/u);
    assert.match(metricCard, /class="shrink-0 whitespace-nowrap"/u);
    assert.match(metricCard, /max-w-full whitespace-normal break-words font-semibold leading-7 tabular-nums/u);
    assert.match(metricCard, /line-clamp-2 min-w-0 break-words/u);
    assert.match(metricCard, /:title="metric\.label"/u);
    assert.doesNotMatch(metricCard, /whitespace-nowrap[^\n]*metric\.value/u);
    assert.match(metricCard, /flex h-full min-w-0 flex-col overflow-hidden/u);
    assert.match(metricCard, /line-clamp-2 min-w-0 break-words min-h-8/u);
    assert.match(metricCard, /hasContext \? '' : 'invisible'/u);
    assert.doesNotMatch(metricCard, /\btruncate\b/u);

    for (const source of [panel, metricGrid, metricCard]) {
        assert.doesNotMatch(source, /bg-gradient-|(?:dark:)?(?:from|via|to)-(?:stone|neutral|white)/u);
    }
});

test('shared KPI cards use a structured visual hierarchy without synthetic decoration', () => {
    const sparkline = read('resources/js/Components/Dashboard/KpiSparkline.vue');
    const trendBadge = read('resources/js/Components/Dashboard/KpiTrendBadge.vue');

    assert.match(metricCard, /flex h-full min-w-0/u);
    assert.match(metricCard, /rounded-lg border border-stone-200/u);
    assert.match(metricCard, /shadow-sm/u);
    assert.match(metricCard, /class="w-1 rounded-full"/u);
    assert.match(metricCard, /v-if="hasContext \|\| metric\.points\?\.length \|\| progress"/u);
    assert.match(metricCard, /border-t border-stone-100 bg-stone-50/u);
    assert.match(metricCard, /text-xl sm:text-2xl/u);
    assert.match(metricCard, /v-if="metric\.points\?\.length"/u);
    assert.match(metricCard, /v-else-if="progress"/u);
    assert.match(sparkline, /flex h-10 items-end gap-1 border-b/u);
    assert.match(sparkline, /rounded-t-sm opacity-80/u);
    assert.match(trendBadge, /:aria-label="title"/u);
    assert.match(trendBadge, /aria-hidden="true"/u);
});

test('KPI panels, grids, and interactive cards keep their accessibility contract', () => {
    assert.match(panel, /<section[\s\S]*?:aria-label="title"/u);
    assert.match(panel, /<h2 class="break-words/u);
    assert.match(panel, /focus-visible:ring-2/u);

    assert.match(metricGrid, /:role="labelledBy \|\| ariaLabel \? 'group' : undefined"/u);
    assert.match(metricGrid, /:aria-labelledby="labelledBy"/u);
    assert.match(metricGrid, /:aria-label="ariaLabel"/u);

    assert.match(metricCard, /const rootElement = computed\(\(\) => interactive\.value \? 'button' : 'article'\)/u);
    assert.match(metricCard, /:type="interactive \? 'button' : undefined"/u);
    assert.match(metricCard, /:disabled="interactive \? Boolean\(metric\.disabled\) : undefined"/u);
    assert.match(metricCard, /:aria-label="interactive \? metric\.ariaLabel : undefined"/u);
    assert.match(metricCard, /:aria-pressed="interactive && metric\.active !== undefined/u);
    assert.match(metricCard, /aria-hidden="true"/u);
    assert.match(metricCard, /focus-visible:ring-2/u);
    assert.match(metricCard, /emit\('activate', props\.metric\?\.action \?\? props\.metric\)/u);
    assert.match(metricCard, /role="progressbar"/u);
    assert.match(metricCard, /:aria-valuemax="progress\.max"/u);
    assert.match(metricCard, /:aria-valuenow="progress\.value"/u);
});

test('KPI panel delegates through the shared grid and card while preserving grid customization', () => {
    const productTeamDashboard = read('resources/js/Pages/DashboardProductsTeam.vue');
    const productOwnerDashboard = read('resources/js/Pages/DashboardProductsOwner.vue');

    assert.match(panel, /import KpiMetricGrid from '@\/Components\/Dashboard\/KpiMetricGrid\.vue'/u);
    assert.match(panel, /<KpiMetricGrid[\s\S]*?:metrics="metrics"[\s\S]*?:grid-class="metricsGridClass"[\s\S]*?:compact="compactMetrics"/u);
    assert.match(metricGrid, /import KpiMetricCard from '@\/Components\/Dashboard\/KpiMetricCard\.vue'/u);
    assert.match(metricGrid, /<KpiMetricCard[\s\S]*?v-for="metric in metrics"[\s\S]*?:metric="metric"[\s\S]*?:compact="compact"/u);
    assert.match(metricGrid, /@activate="\$emit\('activate', \$event\)"/u);
    assert.match(metricGrid, /default: 'grid-cols-\[repeat\(auto-fit,minmax\(min\(100%,12rem\),1fr\)\)\]'/u);
    assert.match(panel, /metricsGridClass:[\s\S]*?default: 'sm:grid-cols-2'/u);
    assert.match(panel, /summaryGridClass:[\s\S]*?default: 'sm:grid-cols-3'/u);
    assert.match(productTeamDashboard, /metrics-grid-class="sm:grid-cols-2 xl:grid-cols-4"/u);
    assert.match(productOwnerDashboard, /metrics-grid-class="sm:grid-cols-2 xl:grid-cols-2"/u);
});
