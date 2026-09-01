import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import test from 'node:test';
import {
    buildCustomerActivityChartData,
    buildProductUsageChartData,
} from '../../resources/js/utils/moduleRankingCharts.js';

const read = (path) => readFileSync(resolve(path), 'utf8');
const emptyChartData = {
    categories: [],
    series: [],
    details: [],
};

test('customer activity ranking preserves exact feature-specific counts', () => {
    const rows = [
        { id: 7, name: 'Atelier Nord', quotes_count: 4, works_count: 2 },
        { id: 8, name: 'Studio Sud', quotes_count: 1, works_count: 3 },
    ];
    const originalRows = structuredClone(rows);

    const chart = buildCustomerActivityChartData(rows, {
        labelForCustomer: (row) => row.name,
        quotesLabel: 'Quotes',
        jobsLabel: 'Jobs',
    });

    assert.deepEqual(chart, {
        categories: ['Atelier Nord', 'Studio Sud'],
        series: [
            { name: 'Quotes', data: [4, 1] },
            { name: 'Jobs', data: [2, 3] },
        ],
        details: [
            { key: '7', category: 'Atelier Nord', quotes: 4, jobs: 2, total: 6 },
            { key: '8', category: 'Studio Sud', quotes: 1, jobs: 3, total: 4 },
        ],
    });
    assert.deepEqual(rows, originalRows);

    assert.deepEqual(buildCustomerActivityChartData(rows, {
        labelForCustomer: (row) => row.name,
        quotesEnabled: false,
        jobsLabel: 'Jobs',
    }).series, [{ name: 'Jobs', data: [2, 3] }]);
});

test('module rankings reject partial, duplicated, fractional, and reconstructed values', () => {
    const invalidCustomerRows = [
        [{ id: 1, name: 'A', quotes_count: '2', works_count: 1 }],
        [{ id: 1, name: 'A', quotes_count: 2, works_count: -1 }],
        [{ id: 1, name: 'A', quotes_count: 2.5, works_count: 1 }],
        [{ id: 1, name: '', quotes_count: 2, works_count: 1 }],
        [
            { id: 1, name: 'A', quotes_count: 2, works_count: 1 },
            { id: 1, name: 'B', quotes_count: 1, works_count: 1 },
        ],
    ];

    for (const rows of invalidCustomerRows) {
        assert.deepEqual(buildCustomerActivityChartData(rows, {
            labelForCustomer: (row) => row.name,
        }), emptyChartData);
    }

    assert.deepEqual(buildCustomerActivityChartData([
        { id: 1, name: 'A', quotes_count: 2, works_count: 1 },
    ], {
        labelForCustomer: (row) => row.name,
        quotesEnabled: false,
        jobsEnabled: false,
    }), emptyChartData);

    const invalidProductRows = [
        [{ id: 1, name: 'Shampoo', quantity: '4' }],
        [{ id: 1, name: 'Shampoo', quantity: -1 }],
        [{ id: 1, name: 'Shampoo', quantity: 1.5 }],
        [{ id: 1, name: '', quantity: 4 }],
        [
            { id: 1, name: 'Shampoo', quantity: 4 },
            { id: 1, name: 'Conditioner', quantity: 2 },
        ],
    ];

    for (const rows of invalidProductRows) {
        assert.deepEqual(buildProductUsageChartData(rows), emptyChartData);
    }
});

test('product usage ranking preserves exact zero and positive quantities in source order', () => {
    const chart = buildProductUsageChartData([
        { id: 3, name: 'Shampoo', quantity: 12 },
        { id: 4, name: 'Conditioner', quantity: 0 },
    ], {
        labelForProduct: (row) => row.name,
        usageLabel: 'Used quantity',
    });

    assert.deepEqual(chart, {
        categories: ['Shampoo', 'Conditioner'],
        series: [{ name: 'Used quantity', data: [12, 0] }],
        details: [
            { key: '3', category: 'Shampoo', quantity: 12 },
            { key: '4', category: 'Conditioner', quantity: 0 },
        ],
    });
});

test('module rankings preserve distinct records that share the same display name', () => {
    const customers = buildCustomerActivityChartData([
        { id: 7, name: 'Atelier Nord', quotes_count: 4, works_count: 2 },
        { id: 8, name: 'Atelier Nord', quotes_count: 1, works_count: 3 },
    ], {
        labelForCustomer: (row) => row.name,
    });
    const products = buildProductUsageChartData([
        { id: 3, name: 'Shampoo', quantity: 12 },
        { id: 4, name: 'Shampoo', quantity: 0 },
    ]);

    assert.deepEqual(customers.categories, ['Atelier Nord (#7)', 'Atelier Nord (#8)']);
    assert.deepEqual(customers.details.map((row) => row.key), ['7', '8']);
    assert.deepEqual(products.categories, ['Shampoo (#3)', 'Shampoo (#4)']);
    assert.deepEqual(products.details.map((row) => row.key), ['3', '4']);
});

test('active module rankings lazy-load the shared accessible horizontal chart', () => {
    const components = [
        {
            path: 'resources/js/Components/UI/CustomerActivityStat.vue',
            builder: 'buildCustomerActivityChartData',
        },
        {
            path: 'resources/js/Components/UI/ProductUsageStat.vue',
            builder: 'buildProductUsageChartData',
        },
    ];

    for (const { path, builder } of components) {
        const source = read(path);

        assert.match(source, /defineAsyncComponent\(\(\) => import\('@\/Components\/UI\/Barchart\.vue'\)\)/u, path);
        assert.match(source, new RegExp(`${builder}\\(`, 'u'), path);
        assert.match(source, /<Suspense>[\s\S]*?<Barchart[\s\S]*?horizontal[\s\S]*?<template #fallback>/u, path);
        assert.match(source, /motion-safe:animate-pulse/u, path);
        assert.match(source, /role="status"/u, path);
        assert.match(source, /xaxis: \{[\s\S]*?min: 0/u, path);
        assert.doesNotMatch(source, /role="progressbar"/u, path);
        assert.doesNotMatch(source, /:style="\{ width:/u, path);
    }
});

test('module ranking layouts keep charts full-width below the extra-large breakpoint', () => {
    const customerIndex = read('resources/js/Pages/Customer/Index.vue');
    const productIndex = read('resources/js/Pages/Product/Index.vue');

    for (const source of [customerIndex, productIndex]) {
        assert.match(source, /grid grid-cols-1 gap-2 md:gap-3 xl:grid-cols-4 xl:gap-5/u);
        assert.match(source, /xl:col-span-3/u);
        assert.doesNotMatch(source, /lg:grid-cols-4|lg:col-span-3/u);
    }
});

test('customer ranking chart precedes the table below xl and remains lateral at xl', () => {
    const customerIndex = read('resources/js/Pages/Customer/Index.vue');

    assert.match(customerIndex, /class="order-2 col-span-1 xl:order-1"/u);
    assert.match(customerIndex, /<CustomerActivityStat[\s\S]*?class="order-1 xl:order-2"/u);
    assert.match(customerIndex, /xl:grid-cols-4/u);
    assert.match(customerIndex, /xl:col-span-3/u);
});

test('module ranking chart copy exists in French, English, and Spanish', () => {
    const customerKeys = [
        'quotes_series',
        'jobs_series',
        'category_label',
        'value_label',
        'table_caption',
    ];
    const productKeys = [
        'series_label',
        'category_label',
        'value_label',
        'table_caption',
    ];

    for (const locale of ['fr', 'en', 'es']) {
        const customerMessages = JSON.parse(read(
            `resources/js/i18n/modules/${locale}/customer_index.json`,
        ));
        const productMessages = JSON.parse(read(
            `resources/js/i18n/modules/${locale}/products.json`,
        ));

        for (const key of customerKeys) {
            assert.equal(typeof customerMessages.customers?.activity?.[key], 'string');
            assert.notEqual(customerMessages.customers.activity[key].trim(), '');
        }

        for (const key of productKeys) {
            assert.equal(typeof productMessages.products?.usage?.[key], 'string');
            assert.notEqual(productMessages.products.usage[key].trim(), '');
        }
    }
});
