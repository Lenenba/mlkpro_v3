import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import test from 'node:test';
import {
    buildFinanceHistoryChartData,
    buildZeroInclusiveFinanceDomain,
    FINANCE_TREND_POINT_COUNT,
} from '../../resources/js/utils/financeChart.js';

const read = (path) => readFileSync(resolve(path), 'utf8');
const labels = Array.from({ length: FINANCE_TREND_POINT_COUNT }, (_, index) => (
    `2025-${String(index + 1).padStart(2, '0')}`
));
const temporalFlow = (overrides = {}) => ({
    labels,
    values: labels.map((_, index) => index * 10),
    granularity: 'month',
    period: {
        start: '2025-01-01',
        end: '2025-12-30',
        timezone: 'America/Toronto',
        comparisonMode: 'aligned_month_to_date',
    },
    unit: { type: 'currency', code: 'CAD' },
    measurement: 'flow',
    isTemporal: true,
    historyStatus: 'available',
    ...overrides,
});

test('finance history keeps twelve exact monthly observations and truthful zeros', () => {
    const revenue = temporalFlow({ values: labels.map(() => 0) });
    const expenses = temporalFlow({ values: labels.map((_, index) => index + 1) });

    const result = buildFinanceHistoryChartData({
        revenueSeries: revenue,
        expenseSeries: expenses,
        includeExpenses: true,
        revenueLabel: 'Collections',
        expenseLabel: 'Expenses',
    });

    assert.deepEqual(result.labels, labels);
    assert.deepEqual(result.series, [
        { name: 'Collections', data: labels.map(() => 0) },
        { name: 'Expenses', data: labels.map((_, index) => index + 1) },
    ]);
    assert.deepEqual(result.period, revenue.period);
    assert.equal(result.currencyCode, 'CAD');
});

test('finance history rejects incomplete history and never invents missing expense values', () => {
    const revenue = temporalFlow();
    const invalidRevenueCases = [
        temporalFlow({ labels: labels.slice(1), values: labels.slice(1).map(() => 10) }),
        temporalFlow({ values: labels.map((_, index) => index === 3 ? null : 10) }),
        temporalFlow({ measurement: 'current_state', isTemporal: false }),
        temporalFlow({ historyStatus: 'requires_snapshot' }),
        temporalFlow({ period: { ...temporalFlow().period, comparisonMode: 'calendar_month' } }),
    ];

    for (const invalidRevenue of invalidRevenueCases) {
        assert.deepEqual(
            buildFinanceHistoryChartData({ revenueSeries: invalidRevenue }),
            { labels: [], series: [], period: {}, currencyCode: null },
        );
    }

    for (const invalidExpenses of [
        temporalFlow({ values: labels.map((_, index) => index === 4 ? null : 10) }),
        temporalFlow({ unit: { type: 'currency', code: 'USD' } }),
        temporalFlow({ labels: [...labels.slice(1), labels[0]] }),
        temporalFlow({ historyStatus: 'unavailable' }),
    ]) {
        const result = buildFinanceHistoryChartData({
            revenueSeries: revenue,
            expenseSeries: invalidExpenses,
            includeExpenses: true,
        });

        assert.equal(result.series.length, 1);
        assert.deepEqual(result.series[0].data, revenue.values);
    }
});

test('finance chart domains include zero without clipping negative values', () => {
    assert.deepEqual(buildZeroInclusiveFinanceDomain([
        { data: [10, 25] },
    ]), { min: 0, max: 25 });
    assert.deepEqual(buildZeroInclusiveFinanceDomain([
        { data: [-10, -25] },
    ]), { min: -25, max: 0 });
    assert.deepEqual(buildZeroInclusiveFinanceDomain([
        { data: [-10, 25] },
        { data: [0, 5] },
    ]), { min: -10, max: 25 });
    assert.deepEqual(buildZeroInclusiveFinanceDomain([
        { data: [0, 0] },
    ]), { min: 0, max: 1 });
});

test('finance chart is a lazy, accessible two-line comparison with an honest zero axis', () => {
    const dashboard = read('resources/js/Pages/Dashboard.vue');
    const panel = read('resources/js/Components/Dashboard/KpiCompositePanel.vue');
    const chart = read('resources/js/Components/Dashboard/FinanceHistoryChart.vue');

    assert.match(dashboard, /defineAsyncComponent\([\s\S]*?FinanceHistoryChart\.vue/u);
    assert.doesNotMatch(dashboard, /import FinanceHistoryChart from/u);
    assert.match(dashboard, /:show-visual="hasFinanceHistory"/u);
    assert.match(dashboard, /:revenue-series="kpiSeries\.revenue_paid"/u);
    assert.match(dashboard, /:expense-series="kpiSeries\.expenses_paid"/u);
    assert.match(panel, /v-if="showVisual && \$slots\.visual"/u);

    assert.match(chart, /import BaseApexChart from '@\/Components\/Charts\/BaseApexChart\.vue'/u);
    assert.match(chart, /import ChartFrame from '@\/Components\/Charts\/ChartFrame\.vue'/u);
    assert.match(chart, /type="line"/u);
    assert.match(chart, /dashArray: chartData\.value\.series\.length > 1 \? \[0, 6\] : 0/u);
    assert.match(chart, /shape: chartData\.value\.series\.length > 1 \? \['circle', 'square'\] : 'circle'/u);
    assert.match(chart, /buildZeroInclusiveFinanceDomain\(chartData\.value\.series\)/u);
    assert.match(chart, /yaxis: \{[\s\S]*?min: chartDomain\.value\.min,[\s\S]*?max: chartDomain\.value\.max/u);
    assert.match(chart, /:color-tones="colorTones"/u);
    assert.match(chart, /:value-formatter="exactCurrency"/u);
    assert.match(chart, /const exactCurrency = \(value\) => isFiniteValue\(value\)/u);
    assert.match(chart, /--chart-series-emerald/u);
    assert.match(chart, /--chart-series-amber/u);
    assert.match(chart, /:framed="false"/u);
    assert.doesNotMatch(chart, /gradient|type="area"|show-data-table/u);
});

test('finance chart copy exists in every dashboard locale', () => {
    const keys = [
        'title',
        'revenue_only_title',
        'subtitle',
        'period',
        'legend_label',
        'month_label',
        'amount_label',
        'table_caption',
        'revenue_only_table_caption',
    ];

    for (const locale of ['fr', 'en', 'es']) {
        const messages = JSON.parse(read(`resources/js/i18n/modules/${locale}/dashboard.json`));
        const revenue = messages.dashboard?.revenue;

        for (const key of keys) {
            assert.equal(typeof revenue?.[key], 'string', `${locale}.dashboard.revenue.${key}`);
            assert.notEqual(revenue[key].trim(), '', `${locale}.dashboard.revenue.${key} is not empty`);
        }

        assert.equal(typeof revenue?.legend?.revenue, 'string');
        assert.equal(typeof revenue?.legend?.expenses, 'string');
    }
});
