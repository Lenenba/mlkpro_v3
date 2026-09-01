import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import test from 'node:test';

const read = (path) => readFileSync(resolve(path), 'utf8');

const metricCard = read('resources/js/Components/Dashboard/KpiMetricCard.vue');
const metricGrid = read('resources/js/Components/Dashboard/KpiMetricGrid.vue');
const compositePanel = read('resources/js/Components/Dashboard/KpiCompositePanel.vue');
const tailwindConfig = read('tailwind.config.js');

const openingTags = (source, component) => [
    ...source.matchAll(new RegExp(`<${component}\\b[\\s\\S]*?\\/>`, 'gu')),
].map((match) => match[0]);

const assertEveryGridUsesVariant = (path, expectedCount, variant) => {
    const tags = openingTags(read(path), 'KpiMetricGrid');

    assert.equal(tags.length, expectedCount, `${path} renders ${expectedCount} KPI grid(s)`);
    for (const tag of tags) {
        assert.match(tag, new RegExp(`\\bvariant="${variant}"`, 'u'), `${path} assigns ${variant} to every KPI grid`);
    }
};

test('shared KPI card and grid default to the module variant and propagate explicit variants', () => {
    for (const [label, source] of [['card', metricCard], ['grid', metricGrid]]) {
        assert.match(
            source,
            /variant:\s*\{[\s\S]*?type:\s*String,[\s\S]*?default:\s*'module',[\s\S]*?\}/u,
            `${label} defaults to the module variant`,
        );
    }
    assert.match(
        metricGrid,
        /<KpiMetricCard[\s\S]*?:metric="metric"[\s\S]*?:compact="compact"[\s\S]*?:variant="variant"/u,
        'grid propagates its variant to every card',
    );
    assert.match(
        compositePanel,
        /<KpiMetricGrid[\s\S]*?:metrics="metrics"[\s\S]*?variant="dashboard"/u,
        'dashboard composite panels force the dashboard variant',
    );
});

test('the bounded KPI tone palette is generated safely by Tailwind', () => {
    const runtimeMatch = metricCard.match(/const tones = '([^']+)'\.split\(' '\)/u);
    const safelistedTones = [
        ...tailwindConfig.matchAll(/'bg-([a-z]+)-600'/gu),
    ].map((match) => match[1]);

    assert.ok(runtimeMatch, 'the card declares its bounded runtime tones');
    assert.match(metricCard, /tones\.includes\(tone\) \? `bg-\$\{tone\}-600`/u);
    assert.deepEqual(runtimeMatch[1].split(' ').sort(), safelistedTones.sort());
});

test('dashboard is the only variant with mini charts and every visual requires real data', () => {
    assert.match(
        metricCard,
        /const showMiniChart = computed\(\(\) => !props\.metric\?\.loading[\s\S]*?&& props\.variant === 'dashboard'[\s\S]*?&& hasMiniChartCandidate\.value\)/u,
    );
    assert.match(
        metricCard,
        /const showProgress = computed\(\(\) => !props\.metric\?\.loading[\s\S]*?&& props\.variant !== 'record'[\s\S]*?&& progress\.value\)/u,
    );
    assert.match(metricCard, /v-if="hasContext \|\| showMiniChart \|\| showProgress"/u);
    assert.match(metricCard, /v-if="showMiniChart"[\s\S]*?<KpiMiniChart/u);
    assert.match(metricCard, /v-else-if="showProgress"/u);
    assert.match(metricCard, /Boolean\(props\.metric\?\.chart\)/u);
    assert.match(metricCard, /props\.metric\.points\.length >= 4/u);
    assert.match(metricCard, /defineAsyncComponent\(\(\) => import\('@\/Components\/Dashboard\/KpiMiniChart\.vue'\)\)/u);
    assert.doesNotMatch(metricCard, /v-else class="h-10"/u);
    assert.doesNotMatch(metricCard, /\binvisible\b/u);
});

test('record KPI grids form one group and record cards have no autonomous shell', () => {
    assert.match(
        metricGrid,
        /variant === 'record'[\s\S]*?'gap-px overflow-hidden rounded-lg border border-stone-200 bg-stone-200 dark:border-neutral-700 dark:bg-neutral-700'/u,
    );
    assert.match(
        metricCard,
        /variant === 'dashboard'[\s\S]*?\? 'rounded-lg border border-stone-200 shadow-sm dark:border-neutral-700'\s*:\s*variant === 'record'\s*\? ''\s*:\s*'rounded-md border border-stone-200 dark:border-neutral-700'/u,
    );
    assert.match(
        metricCard,
        /variant === 'dashboard'[\s\S]*?\? \(compact \? 'min-h-32' : 'min-h-40'\)[\s\S]*?: \(compact \? 'min-h-24' : 'min-h-28'\)/u,
        'compact remains an independent density control for every variant',
    );
});

test('key dashboard, record, and module pages select the intended KPI variant', () => {
    const dashboardGrids = [
        ['resources/js/Components/Dashboard/ScenarioBusinessOverview.vue', 1],
        ['resources/js/Pages/CRM/ManagerDashboard.vue', 1],
        ['resources/js/Pages/Dashboard.vue', 4],
        ['resources/js/Pages/DashboardAdmin.vue', 1],
        ['resources/js/Pages/DashboardClient.vue', 1],
        ['resources/js/Pages/DashboardMember.vue', 1],
        ['resources/js/Pages/DashboardProductsClient.vue', 1],
        ['resources/js/Pages/DashboardProductsOwner.vue', 4],
        ['resources/js/Pages/SuperAdmin/Dashboard.vue', 2],
    ];
    const recordGrids = [
        ['resources/js/Pages/Campaigns/Show.vue', 5],
        ['resources/js/Pages/Customer/Show.vue', 5],
        ['resources/js/Pages/Customer/UI/CustomerPreviewCard.vue', 1],
        ['resources/js/Pages/OfferPackages/Show.vue', 1],
        ['resources/js/Pages/Performance/EmployeeShow.vue', 2],
        ['resources/js/Pages/Product/Show.vue', 1],
        ['resources/js/Pages/SuperAdmin/DemoWorkspaces/Show.vue', 1],
    ];

    for (const [path, count] of dashboardGrids) {
        assertEveryGridUsesVariant(path, count, 'dashboard');
    }
    for (const [path, count] of recordGrids) {
        assertEveryGridUsesVariant(path, count, 'record');
    }

    const superAdminCards = openingTags(read('resources/js/Pages/SuperAdmin/Dashboard.vue'), 'KpiMetricCard');
    assert.equal(superAdminCards.length, 3);
    for (const tag of superAdminCards) {
        assert.match(tag, /\bvariant="dashboard"/u);
    }

    const moduleGrids = [
        ['resources/js/Pages/Performance/Index.vue', 2],
        ['resources/js/Pages/Request/UI/ProspectDashboardAnalytics.vue', 1],
    ];
    for (const [path, count] of moduleGrids) {
        const tags = openingTags(read(path), 'KpiMetricGrid');

        assert.equal(tags.length, count, `${path} renders ${count} module KPI grid(s)`);
        for (const tag of tags) {
            assert.doesNotMatch(tag, /\bvariant=/u, `${path} keeps the default module variant`);
        }
    }
});
