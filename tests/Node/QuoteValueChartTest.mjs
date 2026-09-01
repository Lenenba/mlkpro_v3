import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import test from 'node:test';
import { buildQuoteValueChartData } from '../../resources/js/utils/quoteValueChart.js';

const read = (path) => readFileSync(resolve(path), 'utf8');
const component = read('resources/js/Components/UI/QuoteValueStat.vue');
const index = read('resources/js/Pages/Quote/Index.vue');
const stats = read('resources/js/Components/UI/QuoteStats.vue');
const table = read('resources/js/Pages/Quote/UI/QuoteTable.vue');

const quotes = [
    { id: 3, number: 'Q003', total: '300.50', currency_code: 'CAD' },
    { id: 2, number: 'Q002', total: '200.25', currency_code: 'CAD' },
    { id: 1, number: 'Q001', total: '0.00', currency_code: 'CAD' },
];

test('quote value chart keeps exact ordered values, decimals, and zero', () => {
    const snapshot = structuredClone(quotes);
    const result = buildQuoteValueChartData(quotes, {
        filteredCount: 3,
        filteredTotal: 500.75,
        filteredCurrencyCodes: ['CAD'],
        currencyCode: 'cad',
        labelForItem: (item) => item.number,
        seriesLabel: 'Quote value',
    });

    assert.equal(result.isValid, true);
    assert.deepEqual(result.categories, ['Q003', 'Q002', 'Q001']);
    assert.deepEqual(result.series, [{ name: 'Quote value', data: [300.5, 200.25, 0] }]);
    assert.deepEqual(quotes, snapshot);
});

test('quote value chart validates the top-five and single-currency contract', () => {
    assert.equal(buildQuoteValueChartData(quotes, {
        filteredCount: 3,
        filteredTotal: 500.75,
        currencyCode: 'CAD',
    }).isValid, false);

    assert.equal(buildQuoteValueChartData(quotes.map((quote) => ({
        ...quote,
        currency_code: 'USD',
    })), {
        filteredCount: 3,
        filteredTotal: 500.75,
        filteredCurrencyCodes: ['USD'],
        currencyCode: 'CAD',
    }).isValid, false);

    assert.equal(buildQuoteValueChartData(quotes, {
        filteredCount: 4,
        filteredTotal: 500.75,
        filteredCurrencyCodes: ['CAD'],
        currencyCode: 'CAD',
    }).isValid, false);

    assert.equal(buildQuoteValueChartData([
        quotes[0],
        { ...quotes[1], currency_code: 'USD' },
        quotes[2],
    ], {
        filteredCount: 3,
        filteredTotal: 500.75,
        filteredCurrencyCodes: ['CAD', 'USD'],
        currencyCode: 'CAD',
    }).isValid, false);

    assert.equal(buildQuoteValueChartData([...quotes].reverse(), {
        filteredCount: 3,
        filteredTotal: 500.75,
        filteredCurrencyCodes: ['CAD'],
        currencyCode: 'CAD',
    }).isValid, false);

    assert.equal(buildQuoteValueChartData(quotes, {
        filteredCount: 3,
        filteredTotal: 400,
        filteredCurrencyCodes: ['CAD'],
        currencyCode: 'CAD',
    }).isValid, false);

    const hiddenForeignCurrency = [
        { id: 5, number: 'Q005', total: 500, currency_code: 'CAD' },
        { id: 4, number: 'Q004', total: 400, currency_code: 'CAD' },
        { id: 3, number: 'Q003', total: 300, currency_code: 'CAD' },
        { id: 2, number: 'Q002', total: 200, currency_code: 'CAD' },
        { id: 1, number: 'Q001', total: 100, currency_code: 'CAD' },
    ];

    assert.equal(buildQuoteValueChartData(hiddenForeignCurrency, {
        filteredCount: 6,
        filteredTotal: 1550,
        filteredCurrencyCodes: ['CAD', 'USD'],
        currencyCode: 'CAD',
    }).isValid, false);

    for (const missingValue of [null, undefined, '', '   ', false]) {
        assert.equal(buildQuoteValueChartData([], {
            filteredCount: 0,
            filteredTotal: missingValue,
            filteredCurrencyCodes: [],
            currencyCode: 'CAD',
        }).isValid, false);
    }

    assert.equal(buildQuoteValueChartData([
        { id: 1, number: 'Q001', total: 99.99, currency_code: 'CAD' },
    ], {
        filteredCount: 1,
        filteredTotal: 100,
        filteredCurrencyCodes: ['CAD'],
        currencyCode: 'CAD',
        labelForItem: (item) => item.number,
    }).isValid, false);
});

test('quote value stat uses a lazy accessible horizontal bar chart without reconstructed percentages', () => {
    assert.match(component, /defineAsyncComponent\(\(\) => import\('@\/Components\/UI\/Barchart\.vue'\)\)/u);
    assert.match(component, /<Suspense>[\s\S]*?<Barchart/u);
    assert.match(component, /xaxis:[\s\S]*?min: 0/u);
    assert.match(component, /:value-formatter="formatExactCurrency"/u);
    assert.match(component, /:table-caption=/u);
    assert.match(component, /:framed="false"/u);
    assert.doesNotMatch(component, /role="progressbar"|getPercent|Math\.round\(\(Number\(value/u);

    assert.match(index, /:filtered-count="count"/u);
    assert.match(index, /:filtered-total="Number\(stats\?\.total_value \|\| 0\)"/u);
    assert.match(index, /:filtered-currency-codes="quoteValueMeta\?\.currency_codes \|\| \[\]"/u);
    assert.match(index, /:currency-code="tenantCurrencyCode"/u);
    assert.match(stats, /hasComparableCurrency/u);
    assert.match(stats, /mixed_currency_context/u);
    assert.match(stats, /formatComparableCurrency/u);
    assert.equal(table.match(/only: \[[^\]]*'quoteValueMeta'/gu)?.length, 3);
});

test('quote value chart copy exists in every supported locale', () => {
    for (const locale of ['fr', 'en', 'es']) {
        const messages = JSON.parse(read(`resources/js/i18n/modules/${locale}/quotes.json`));

        for (const key of [
            'top_chart_subtitle',
            'value_series',
            'quote_label',
            'value_label',
            'top_chart_table_caption',
            'chart_invalid',
            'mixed_currency_context',
        ]) {
            assert.equal(typeof messages.quotes?.stats?.[key], 'string', `${locale}.${key}`);
        }
    }
});
