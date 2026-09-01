import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import test from 'node:test';
import {
    CUSTOMER_GROWTH_CATEGORY_COUNT,
    buildCustomerGrowthChartData,
    CUSTOMER_GROWTH_WEEK_COUNT,
} from '../../resources/js/utils/customerGrowthChart.js';

const read = (path) => readFileSync(resolve(path), 'utf8');
const previousCategories = [
    '2026-02-16',
    '2026-02-23',
    '2026-03-02',
    '2026-03-09',
    '2026-03-16',
    '2026-03-23',
    '2026-03-30',
    '2026-04-06',
    '2026-04-13',
    '2026-04-20',
    '2026-04-27',
    '2026-05-04',
];
const currentCategories = [
    '2026-05-11',
    '2026-05-18',
    '2026-05-25',
    '2026-06-01',
    '2026-06-08',
    '2026-06-15',
    '2026-06-22',
    '2026-06-29',
    '2026-07-06',
    '2026-07-13',
    '2026-07-20',
    '2026-07-27',
];
const categories = [...previousCategories, ...currentCategories];
const currentValues = [1, 0, 2, 0, 3, 0, 4, 0, 5, 0, 6, 0];
const previousValues = [0, 6, 0, 5, 0, 4, 0, 3, 0, 2, 0, 1];
const validTrend = (overrides = {}) => ({
    categories,
    series: [
        { key: 'current', data: [...Array(CUSTOMER_GROWTH_WEEK_COUNT).fill(null), ...currentValues] },
        { key: 'previous', data: [...previousValues, ...Array(CUSTOMER_GROWTH_WEEK_COUNT).fill(null)] },
    ],
    periods: {
        current: { start: '2026-05-11', end: '2026-08-02' },
        previous: { start: '2026-02-16', end: '2026-05-10' },
    },
    timezone: 'America/Toronto',
    ...overrides,
});

test('customer growth chart preserves twenty-four real weekly dates and truthful period gaps', () => {
    const trend = validTrend();
    const originalTrend = structuredClone(trend);

    const chart = buildCustomerGrowthChartData(trend, {
        currentLabel: 'Courantes',
        previousLabel: 'Précédentes',
    });

    assert.equal(CUSTOMER_GROWTH_WEEK_COUNT, 12);
    assert.equal(CUSTOMER_GROWTH_CATEGORY_COUNT, 24);
    assert.deepEqual(chart, {
        categories,
        series: [
            { name: 'Courantes', data: trend.series[0].data },
            { name: 'Précédentes', data: trend.series[1].data },
        ],
        periods: trend.periods,
        timezone: 'America/Toronto',
        isValid: true,
    });
    assert.deepEqual(trend, originalTrend);
});

test('customer growth chart rejects incomplete, discontinuous, or inexact comparisons', () => {
    const invalidTrends = [
        null,
        validTrend({ categories: categories.slice(1) }),
        validTrend({ categories: categories.map((date, index) => index === 5 ? '2026-06-23' : date) }),
        validTrend({ series: [validTrend().series[0]] }),
        validTrend({ series: [validTrend().series[0], validTrend().series[0]] }),
        validTrend({ series: [
            { key: 'current', data: validTrend().series[0].data.slice(1) },
            validTrend().series[1],
        ] }),
        validTrend({ series: [
            { key: 'current', data: validTrend().series[0].data.map((value, index) => index === 14 ? -1 : value) },
            validTrend().series[1],
        ] }),
        validTrend({ series: [
            { key: 'current', data: validTrend().series[0].data.map((value, index) => index === 14 ? '2' : value) },
            validTrend().series[1],
        ] }),
        validTrend({ series: [
            { key: 'current', data: validTrend().series[0].data.map((value, index) => index === 2 ? 0 : value) },
            validTrend().series[1],
        ] }),
        validTrend({ series: [
            validTrend().series[0],
            { key: 'previous', data: validTrend().series[1].data.map((value, index) => index === 14 ? 0 : value) },
        ] }),
        validTrend({ periods: {
            ...validTrend().periods,
            previous: { start: '2026-03-02', end: '2026-05-17' },
        } }),
        validTrend({ timezone: '' }),
    ];

    for (const trend of invalidTrends) {
        assert.deepEqual(buildCustomerGrowthChartData(trend), {
            categories: [],
            series: [],
            periods: {},
            timezone: null,
            isValid: false,
        });
    }
});

test('customer index lazy-loads an accessible universal trend and retains the operational ranking', () => {
    const index = read('resources/js/Pages/Customer/Index.vue');
    const component = read('resources/js/Components/UI/CustomerGrowthTrend.vue');

    assert.match(index, /defineAsyncComponent\(\(\) => import\('@\/Components\/UI\/CustomerGrowthTrend\.vue'\)\)/u);
    assert.doesNotMatch(index, /import CustomerGrowthTrend from/u);
    assert.match(index, /<Suspense>[\s\S]*?<CustomerGrowthTrend :trend="customerGrowthTrend"/u);
    assert.match(index, /role="status"[\s\S]*?aria-live="polite"/u);
    assert.match(index, /motion-safe:animate-pulse/u);
    assert.match(index, /<CustomerActivityStat[\s\S]*?:items="topCustomers"/u);
    assert.match(index, /v-if="showOperationalActivity"/u);

    assert.match(component, /<ChartFrame[\s\S]*?<BaseApexChart[\s\S]*?<\/ChartFrame>/u);
    assert.match(component, /type="line"/u);
    assert.match(component, /dashArray: \[0, 6\]/u);
    assert.match(component, /shape: \['circle', 'square'\]/u);
    assert.match(component, /:color-tones="\['blue', 'violet'\]"/u);
    assert.match(component, /:table-caption=/u);
    assert.match(component, /:category-label=/u);
    assert.match(component, /:value-formatter="formatCount"/u);
    assert.match(component, /const formatCount = \(value\) => value !== null/u);
    assert.match(component, /data-testid="customer-growth-trend"/u);
});

test('customer growth copy exists in every supported locale', () => {
    const keys = [
        'title',
        'subtitle',
        'period',
        'legend_label',
        'current_series',
        'previous_series',
        'week_label',
        'value_label',
        'table_caption',
        'empty',
        'invalid',
    ];

    const completenessTerms = {
        fr: 'complètes',
        en: 'complete',
        es: 'completas',
    };

    for (const locale of ['fr', 'en', 'es']) {
        const messages = JSON.parse(read(`resources/js/i18n/modules/${locale}/customer_index.json`));
        const growth = messages.customers?.growth;

        for (const key of keys) {
            assert.equal(typeof growth?.[key], 'string', `${locale}.customers.growth.${key}`);
            assert.notEqual(growth[key].trim(), '', `${locale}.customers.growth.${key} is not empty`);
        }

        for (const placeholder of ['{currentStart}', '{currentEnd}', '{previousStart}', '{previousEnd}']) {
            assert.equal(growth.period.includes(placeholder), true, `${locale} period keeps ${placeholder}`);
        }

        for (const key of ['subtitle', 'current_series', 'previous_series', 'empty']) {
            assert.equal(growth[key].includes(completenessTerms[locale]), true, `${locale}.${key} states complete weeks`);
        }
    }
});
