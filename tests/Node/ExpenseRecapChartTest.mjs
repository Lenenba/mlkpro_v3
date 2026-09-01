import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import test from 'node:test';
import { buildExpenseBreakdownChartData } from '../../resources/js/utils/expenseRecapChart.js';

const read = (path) => readFileSync(resolve(path), 'utf8');
const expenseIndex = read('resources/js/Pages/Expense/Index.vue');
const recap = read('resources/js/Pages/Expense/UI/ExpensePeriodRecap.vue');
const charts = read('resources/js/Pages/Expense/UI/ExpenseBreakdownCharts.vue');

test('expense workspace opens the chart recap by default', () => {
    assert.match(expenseIndex, /const activeTab = ref\('recap'\);/u);
    assert.match(expenseIndex, /v-if="activeTab === 'recap'"/u);
});

test('expense breakdown charts preserve exact totals and the explicit remainder', () => {
    const payload = {
        rows: [
            { key: 'software', label: 'software', count: 2, total: 70 },
            { key: 'fuel', label: 'fuel', count: 1, total: 20 },
            { key: '__remaining', label: '__remaining', count: 3, total: 10, is_remainder: true },
        ],
        covered_total: 90,
        other_total: 10,
        is_truncated: true,
    };

    const result = buildExpenseBreakdownChartData(payload, {
        expectedTotal: 100,
        labelForItem: (item) => item.key === '__remaining' ? 'Other categories' : item.label,
        seriesLabel: 'Recorded amount',
    });

    assert.equal(result.isValid, true);
    assert.deepEqual(result.categories, ['software', 'fuel', 'Other categories']);
    assert.deepEqual(result.values, [70, 20, 10]);
    assert.deepEqual(result.series, [{ name: 'Recorded amount', data: [70, 20, 10] }]);
    assert.equal(result.rows[2].isRemainder, true);
});

test('expense breakdown charts preserve real zero values in a complete composition', () => {
    const result = buildExpenseBreakdownChartData({
        rows: [{ key: 'cash', label: 'Cash', count: 1, total: 0 }],
        covered_total: 0,
        other_total: 0,
        is_truncated: false,
    }, {
        expectedTotal: 0,
        seriesLabel: 'Amount',
    });

    assert.equal(result.isValid, true);
    assert.deepEqual(result.values, [0]);
});

test('expense breakdown charts reject incomplete or inconsistent compositions', () => {
    const valid = {
        rows: [{ key: 'cash', label: 'Cash', count: 1, total: 20 }],
        covered_total: 20,
        other_total: 0,
        is_truncated: false,
    };

    assert.equal(buildExpenseBreakdownChartData(valid, { expectedTotal: 25 }).isValid, false);
    assert.equal(buildExpenseBreakdownChartData({ ...valid, is_truncated: true }, { expectedTotal: 20 }).isValid, false);
    assert.equal(buildExpenseBreakdownChartData({
        ...valid,
        rows: [...valid.rows, { ...valid.rows[0] }],
        covered_total: 40,
    }, { expectedTotal: 40 }).isValid, false);

    for (const missingValue of [null, undefined, '', '   ', false]) {
        assert.equal(buildExpenseBreakdownChartData(valid, {
            expectedTotal: missingValue,
        }).isValid, false);
        assert.equal(buildExpenseBreakdownChartData({
            ...valid,
            covered_total: missingValue,
        }, { expectedTotal: 20 }).isValid, false);
    }

    assert.equal(buildExpenseBreakdownChartData({
        rows: [{ key: 'cash', label: 'Cash', count: 1, total: 99.99 }],
        covered_total: 99.99,
        other_total: 0,
        is_truncated: false,
    }, { expectedTotal: 100 }).isValid, false);
});

test('expense recap lazy-loads varied shared charts and removes the two-point sparkline', () => {
    assert.match(recap, /defineAsyncComponent\(\(\) => import\([\s\S]*?ExpenseBreakdownCharts\.vue/u);
    assert.match(recap, /<Suspense>[\s\S]*?<ExpenseBreakdownCharts/u);
    assert.match(recap, /data-expense-currency-summary/u);
    assert.match(recap, /currency\.value\?\.has_additional_currencies/u);
    assert.doesNotMatch(recap, /buildSparklinePoints|buildTrend|totalSpentSeries/u);

    assert.match(charts, /<Barchart/u);
    assert.match(charts, /<Donutchart/u);
    assert.match(charts, /:color-tones="\['rose', 'amber', 'emerald', 'blue', 'violet'\]"/u);
    assert.match(charts, /horizontal/u);
    assert.match(charts, /xaxis:[\s\S]*?min: 0/u);
    assert.match(charts, /buildExpenseBreakdownChartData/u);
});

test('expense chart copy exists in every supported locale', () => {
    for (const locale of ['fr', 'en', 'es']) {
        const messages = JSON.parse(read(`resources/js/i18n/modules/${locale}/expenses.json`));

        for (const key of [
            'categories_title',
            'categories_subtitle',
            'payment_methods_title',
            'payment_methods_subtitle',
            'amount_series',
            'categories_table_caption',
            'payment_methods_table_caption',
            'invalid',
        ]) {
            assert.equal(typeof messages.expenses?.recap?.charts?.[key], 'string', `${locale}.${key}`);
        }

        assert.equal(typeof messages.expenses?.recap?.currency?.description, 'string', `${locale}.currency.description`);
        assert.equal(typeof messages.expenses?.recap?.linked_contexts_note, 'string', `${locale}.linked_contexts_note`);
    }
});
