import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import test from 'node:test';
import {
    PRODUCT_STOCK_PARTITION_KEYS,
    buildProductStockPartition,
} from '../../resources/js/utils/productStockChart.js';

const read = (path) => readFileSync(resolve(path), 'utf8');
const emptyPartition = {
    keys: [],
    values: [],
    total: null,
    isValid: false,
};

test('stock partition preserves exact zero categories when counts reconstruct the total', () => {
    const partition = buildProductStockPartition({
        total: 6,
        in_stock: 0,
        low_stock: 6,
        out_of_stock: 0,
    });

    assert.deepEqual(PRODUCT_STOCK_PARTITION_KEYS, [
        'in_stock',
        'low_stock',
        'out_of_stock',
    ]);
    assert.deepEqual(partition, {
        keys: [...PRODUCT_STOCK_PARTITION_KEYS],
        values: [0, 6, 0],
        total: 6,
        isValid: true,
    });
});

test('stock partition accepts a truthful empty catalog without inventing slices', () => {
    assert.deepEqual(buildProductStockPartition({
        total: 0,
        in_stock: 0,
        low_stock: 0,
        out_of_stock: 0,
    }), {
        keys: [...PRODUCT_STOCK_PARTITION_KEYS],
        values: [0, 0, 0],
        total: 0,
        isValid: true,
    });
});

test('stock partition rejects incomplete, fractional, negative, and mismatched counts', () => {
    const invalidStats = [
        null,
        [],
        { total: 3, in_stock: 1, low_stock: 1 },
        { total: 3, in_stock: 1, low_stock: 1, out_of_stock: 0 },
        { total: 3, in_stock: 1.5, low_stock: 1.5, out_of_stock: 0 },
        { total: 3, in_stock: -1, low_stock: 2, out_of_stock: 2 },
        { total: '3', in_stock: 1, low_stock: 1, out_of_stock: 1 },
        { total: 3, in_stock: Number.NaN, low_stock: 1, out_of_stock: 2 },
    ];

    for (const stats of invalidStats) {
        assert.deepEqual(buildProductStockPartition(stats), emptyPartition);
    }
});

test('shared donut delegates rendering and exact values to the accessible chart foundation', () => {
    const donut = read('resources/js/Components/UI/Donutchart.vue');

    assert.match(donut, /import BaseApexChart from '@\/Components\/Charts\/BaseApexChart\.vue'/u);
    assert.match(donut, /import ChartFrame from '@\/Components\/Charts\/ChartFrame\.vue'/u);
    assert.match(donut, /<ChartFrame[\s\S]*?<BaseApexChart[\s\S]*?<\/ChartFrame>/u);
    assert.match(donut, /type="donut"/u);
    assert.match(donut, /labels: props\.categories/u);
    assert.match(donut, /dataLabels: \{[\s\S]*?enabled: true/u);
    assert.match(donut, /minAngleToShowLabel: 12/u);
    assert.match(donut, /normalizeDonutSeries\(props\.series\)/u);
    assert.match(donut, /:series="normalizedSeries"/u);
    assert.match(donut, /:categories="categories"/u);
    assert.match(donut, /:color-tones="colorTones"/u);
    assert.match(donut, /:value-formatter="valueFormatter"/u);
    assert.doesNotMatch(donut, /show-data-table/u);
    assert.doesNotMatch(donut, /new ApexCharts|import ApexCharts/u);
});

test('product KPIs lazy-load the donut only for a positive valid partition', () => {
    const productStats = read('resources/js/Components/UI/ProductStats.vue');

    assert.match(productStats, /defineAsyncComponent\(\(\) => import\('@\/Components\/UI\/Donutchart\.vue'\)\)/u);
    assert.doesNotMatch(productStats, /import Donutchart from/u);
    assert.match(productStats, /buildProductStockPartition\(props\.stats\)/u);
    assert.match(productStats, /stockPartition\.value\.isValid && stockPartition\.value\.total > 0/u);
    assert.match(productStats, /v-if="hasStockChart"/u);
    assert.match(productStats, /:series="stockPartition\.values"/u);
    assert.match(productStats, /:categories="stockCategories"/u);
    assert.match(productStats, /:color-tones="stockChartTones"/u);
    assert.match(productStats, /<Suspense>[\s\S]*?<template #fallback>/u);
    assert.match(productStats, /role="status"/u);
});

test('stock chart copy exists in every product locale', () => {
    const keys = [
        'title',
        'subtitle',
        'total_label',
        'category_label',
        'value_label',
        'table_caption',
    ];

    for (const locale of ['fr', 'en', 'es']) {
        const messages = JSON.parse(read(`resources/js/i18n/modules/${locale}/products.json`));

        for (const key of keys) {
            assert.equal(typeof messages.products?.stock_chart?.[key], 'string');
            assert.notEqual(messages.products.stock_chart[key].trim(), '');
        }
    }
});
