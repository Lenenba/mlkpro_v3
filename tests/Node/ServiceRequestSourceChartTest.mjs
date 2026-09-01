import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import test from 'node:test';
import { buildServiceRequestSourceChartData } from '../../resources/js/utils/serviceRequestSourceChart.js';

const read = (path) => readFileSync(resolve(path), 'utf8');
const emptyChartData = (isValid) => ({
    categories: [],
    series: [],
    details: [],
    isValid,
});

test('service request sources preserve exact counts and shares from the filtered total', () => {
    const rows = [
        { source: 'manual_admin', total: 7 },
        { source: 'public_form', total: 2 },
        { source: 'api', total: 1 },
    ];
    const originalRows = structuredClone(rows);

    const chart = buildServiceRequestSourceChartData(rows, {
        expectedTotal: 10,
        labelForSource: (source) => ({
            manual_admin: 'Manuel',
            public_form: 'Formulaire',
            api: 'API',
        })[source],
        totalLabel: 'Demandes',
    });

    assert.deepEqual(chart, {
        categories: ['Manuel', 'Formulaire', 'API'],
        series: [{ name: 'Demandes', data: [7, 2, 1] }],
        details: [
            { key: 'manual_admin', category: 'Manuel', total: 7, share: 70 },
            { key: 'public_form', category: 'Formulaire', total: 2, share: 20 },
            { key: 'api', category: 'API', total: 1, share: 10 },
        ],
        isValid: true,
    });
    assert.deepEqual(rows, originalRows);
});

test('service request sources reject mismatched, duplicated, and reconstructed values', () => {
    assert.deepEqual(
        buildServiceRequestSourceChartData([], { expectedTotal: 0 }),
        emptyChartData(true),
    );
    assert.deepEqual(
        buildServiceRequestSourceChartData([], { expectedTotal: 1 }),
        emptyChartData(false),
    );

    const validRows = [
        { source: 'manual_admin', total: 2 },
        { source: 'api', total: 1 },
    ];
    const invalidCases = [
        [null, { expectedTotal: 0 }],
        [validRows, { expectedTotal: -1 }],
        [validRows, { expectedTotal: 4 }],
        [[...validRows, { source: 'api', total: 0 }], { expectedTotal: 3 }],
        [[{ source: '', total: 3 }], { expectedTotal: 3 }],
        [[{ source: 'api', total: '3' }], { expectedTotal: 3 }],
        [[{ source: 'api', total: 1.5 }], { expectedTotal: 1 }],
        [[{ source: 'api', total: 3 }], { expectedTotal: 3, labelForSource: () => '' }],
        [[{ source: 'api', total: 0 }], { expectedTotal: 0 }],
    ];

    for (const [rows, options] of invalidCases) {
        assert.deepEqual(
            buildServiceRequestSourceChartData(rows, options),
            emptyChartData(false),
        );
    }
});

test('service request workspace lazy-loads truthful horizontal bars and removes the visual floor', () => {
    const page = read('resources/js/Pages/ServiceRequests/Index.vue');
    const component = read('resources/js/Pages/ServiceRequests/SourceBreakdownChart.vue');

    assert.match(page, /defineAsyncComponent\([\s\S]*?ServiceRequests\/SourceBreakdownChart\.vue/u);
    assert.match(page, /<Suspense>[\s\S]*?<SourceBreakdownChart/u);
    assert.match(page, /:rows="sourceBreakdown"/u);
    assert.match(page, /motion-safe:animate-pulse/u);
    assert.doesNotMatch(page, /Math\.max\(8|bg-emerald-500[\s\S]*?:style="\{ width/u);

    assert.match(component, /buildServiceRequestSourceChartData\(props\.rows/u);
    assert.match(component, /<Barchart/u);
    assert.match(component, /horizontal/u);
    assert.match(component, /:color-tones="\['emerald'\]"/u);
    assert.match(component, /count_and_share/u);
    assert.match(component, /table_caption/u);
    assert.match(component, /xaxis: \{[\s\S]*?min: 0/u);
    assert.doesNotMatch(component, /Math\.max\(8/u);
});

test('service request source chart copy is complete in every supported locale', () => {
    const keys = [
        'subtitle',
        'series',
        'category_label',
        'value_label',
        'table_caption',
        'count_and_share',
        'empty',
        'invalid',
    ];

    for (const locale of ['fr', 'en', 'es']) {
        const messages = JSON.parse(read(`resources/js/i18n/modules/${locale}/service_requests.json`));
        const copy = messages.service_requests?.source_chart;

        for (const key of keys) {
            assert.equal(typeof copy?.[key], 'string', `${locale}.source_chart.${key}`);
            assert.notEqual(copy[key].trim(), '', `${locale}.source_chart.${key} is not empty`);
        }

        assert.equal(copy.subtitle.includes('{count}'), true, `${locale} subtitle keeps {count}`);
        assert.equal(copy.count_and_share.includes('{count}'), true, `${locale} detail keeps {count}`);
        assert.equal(copy.count_and_share.includes('{share}'), true, `${locale} detail keeps {share}`);
    }
});
