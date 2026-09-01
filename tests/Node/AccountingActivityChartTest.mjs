import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import test from 'node:test';
import {
    ACCOUNTING_ACTIVITY_MIN_PERIODS,
    buildAccountingActivityChartData,
} from '../../resources/js/utils/accountingChart.js';

const read = (path) => readFileSync(resolve(path), 'utf8');
const accountingPage = read('resources/js/Pages/Accounting/Index.vue');
const activityChart = read('resources/js/Components/Accounting/AccountingActivityChart.vue');
const period = (periodKey, label, entryCount, batchCount) => ({
    period_key: periodKey,
    label,
    entry_count: entryCount,
    batch_count: batchCount,
});

test('accounting activity keeps real periods in chronological order and preserves zero counts', () => {
    const periods = [
        period('2026-06', 'June 2026', 18, 3),
        period('2026-05', 'May 2026', 0, 0),
        period('2026-04', 'April 2026', 12, 2),
        period('2026-03', 'March 2026', 8, 1),
    ];

    const result = buildAccountingActivityChartData(periods);

    assert.equal(ACCOUNTING_ACTIVITY_MIN_PERIODS, 4);
    assert.equal(result.available, true);
    assert.deepEqual(result.periodKeys, ['2026-03', '2026-04', '2026-05', '2026-06']);
    assert.deepEqual(result.categories, ['March 2026', 'April 2026', 'May 2026', 'June 2026']);
    assert.deepEqual(result.entryCounts, [8, 12, 0, 18]);
    assert.deepEqual(result.batchCounts, [1, 2, 0, 3]);
});

test('accounting activity renders only with four distinct valid periods', () => {
    const emptyResult = {
        available: false,
        periodKeys: [],
        categories: [],
        entryCounts: [],
        batchCounts: [],
    };
    const threePeriods = [
        period('2026-06', 'June 2026', 18, 3),
        period('2026-05', 'May 2026', 10, 2),
        period('2026-04', 'April 2026', 12, 2),
    ];

    assert.deepEqual(buildAccountingActivityChartData(threePeriods), emptyResult);
    assert.deepEqual(buildAccountingActivityChartData([
        ...threePeriods,
        period('2026-03', 'March 2026', '8', 1),
    ]), emptyResult);
    assert.deepEqual(buildAccountingActivityChartData([
        ...threePeriods,
        period('2026-06', 'June duplicate', 4, 1),
    ]), emptyResult);

    const withInvalidExtra = buildAccountingActivityChartData([
        ...threePeriods,
        period('2026-03', 'March 2026', 8, 1),
        period('2026-13', 'Invalid month', 99, 99),
    ]);

    assert.deepEqual(withInvalidExtra, emptyResult);
    assert.deepEqual(buildAccountingActivityChartData([
        period('2026-05', 'May 2026', 10, 2),
        period('2026-03', 'March 2026', 8, 1),
        period('2026-02', 'February 2026', 6, 1),
        period('2026-01', 'January 2026', 4, 1),
    ]), emptyResult);

    assert.equal(buildAccountingActivityChartData([
        period('2026-10', 'October 2026', 4, 1),
        period('2026-11', 'November 2026', 6, 1),
        period('2026-12', 'December 2026', 8, 1),
        period('2027-01', 'January 2027', 10, 2),
    ]).available, true);
});

test('accounting activity is lazy, grouped, accessible, and keeps the period list as fallback', () => {
    assert.match(accountingPage, /defineAsyncComponent\([\s\S]*?AccountingActivityChart\.vue/u);
    assert.doesNotMatch(accountingPage, /import AccountingActivityChart from/u);
    assert.match(accountingPage, /buildAccountingActivityChartData\(props\.periods\)/u);
    assert.match(accountingPage, /v-if="accountingActivityChartData\.available"/u);
    assert.match(accountingPage, /<Suspense>[\s\S]*?<AccountingActivityChart :chart-data="accountingActivityChartData"/u);
    assert.match(accountingPage, /v-for="period in periods \|\| \[\]"/u);

    assert.match(activityChart, /import Barchart from '@\/Components\/UI\/Barchart\.vue'/u);
    assert.match(activityChart, /data: props\.chartData\.entryCounts \|\| \[\]/u);
    assert.match(activityChart, /data: props\.chartData\.batchCounts \|\| \[\]/u);
    assert.match(activityChart, /:stacked="false"/u);
    assert.match(activityChart, /:color-tones="\['blue', 'violet'\]"/u);
    assert.match(activityChart, /:table-caption="\$t\('accounting\.periods\.activity_chart\.table_caption'\)"/u);
    assert.match(activityChart, /:value-formatter="formatCount"/u);
    assert.match(activityChart, /yaxis: \{[\s\S]*?min: 0/u);
    assert.match(activityChart, /:framed="false"/u);
    assert.doesNotMatch(activityChart, /ApexCharts|BaseApexChart/u);
});

test('accounting activity copy exists in every locale', () => {
    const keys = [
        'title',
        'subtitle',
        'period_range',
        'period_label',
        'count_label',
        'table_caption',
    ];

    for (const locale of ['fr', 'en', 'es']) {
        const messages = JSON.parse(read(`resources/js/i18n/modules/${locale}/accounting.json`));
        const chart = messages.accounting?.periods?.activity_chart;

        for (const key of keys) {
            assert.equal(typeof chart?.[key], 'string', `${locale}.accounting.periods.activity_chart.${key}`);
            assert.notEqual(chart[key].trim(), '', `${locale}.accounting.periods.activity_chart.${key} is not empty`);
        }
    }
});
