import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import test from 'node:test';
import {
    SUPER_ADMIN_TRAFFIC_MINIMUM_POINTS,
    buildSuperAdminTrafficChartData,
} from '../../resources/js/utils/superAdminTrafficChart.js';

const read = (path) => readFileSync(resolve(path), 'utf8');
const emptyChartData = (isValid) => ({
    categories: [],
    series: [],
    period: null,
    isValid,
});
const validRows = () => Array.from({ length: SUPER_ADMIN_TRAFFIC_MINIMUM_POINTS }, (_, index) => ({
    date: `2026-08-${String(index + 1).padStart(2, '0')}`,
    total: index * 2,
    unique: index,
}));

test('traffic chart preserves chronological daily totals, unique visitors, and real zeros', () => {
    const rows = validRows();
    const originalRows = structuredClone(rows);

    const chart = buildSuperAdminTrafficChartData(rows, {
        totalLabel: 'Visites',
        uniqueLabel: 'Uniques',
    });

    assert.deepEqual(chart, {
        categories: rows.map((row) => row.date),
        series: [
            { name: 'Visites', data: [0, 2, 4, 6, 8, 10, 12, 14] },
            { name: 'Uniques', data: [0, 1, 2, 3, 4, 5, 6, 7] },
        ],
        period: {
            start: '2026-08-01',
            end: '2026-08-08',
        },
        isValid: true,
    });
    assert.deepEqual(rows, originalRows);
});

test('traffic chart accepts an explicit empty payload but rejects short or incoherent histories', () => {
    assert.deepEqual(buildSuperAdminTrafficChartData([]), emptyChartData(true));
    assert.deepEqual(buildSuperAdminTrafficChartData(null), emptyChartData(false));
    assert.deepEqual(
        buildSuperAdminTrafficChartData(validRows().slice(0, SUPER_ADMIN_TRAFFIC_MINIMUM_POINTS - 1)),
        emptyChartData(false),
    );

    const rows = validRows();
    const invalidRows = [
        rows.map((row, index) => index === 2 ? { ...row, date: '2026-02-30' } : row),
        rows.map((row, index) => index === 2 ? { ...row, date: rows[1].date } : row),
        rows.map((row, index) => index === 2 ? { ...row, date: '2026-07-31' } : row),
        rows.map((row, index) => index === 2 ? { ...row, total: -1 } : row),
        rows.map((row, index) => index === 2 ? { ...row, total: 1.5 } : row),
        rows.map((row, index) => index === 2 ? { ...row, total: '4' } : row),
        rows.map((row, index) => index === 2 ? { ...row, unique: row.total + 1 } : row),
    ];

    for (const invalid of invalidRows) {
        assert.deepEqual(buildSuperAdminTrafficChartData(invalid), emptyChartData(false));
    }
});

test('Super Admin lazy-loads a shared accessible line chart without the legacy SVG', () => {
    const dashboard = read('resources/js/Pages/SuperAdmin/Dashboard.vue');
    const component = read('resources/js/Components/Dashboard/SiteTrafficChart.vue');

    assert.match(dashboard, /defineAsyncComponent\([\s\S]*?Dashboard\/SiteTrafficChart\.vue/u);
    assert.match(dashboard, /<Suspense>[\s\S]*?<SiteTrafficChart/u);
    assert.match(dashboard, /motion-safe:animate-pulse/u);
    assert.doesNotMatch(dashboard, /buildSparklinePoints|trafficTotalPoints|<polyline/u);

    assert.match(component, /buildSuperAdminTrafficChartData\(props\.rows/u);
    assert.match(component, /<ChartFrame[\s\S]*?<BaseApexChart[\s\S]*?<\/ChartFrame>/u);
    assert.match(component, /type="line"/u);
    assert.match(component, /dashArray: \[0, 6\]/u);
    assert.match(component, /shape: \['circle', 'square'\]/u);
    assert.match(component, /:color-tones="\['emerald', 'violet'\]"/u);
    assert.match(component, /table_caption/u);
    assert.match(component, /legend_label/u);
    assert.match(component, /yaxis: \{[\s\S]*?min: 0/u);
});

test('Super Admin traffic chart copy is complete in every supported locale', () => {
    const keys = [
        'legend_label',
        'trend_title',
        'trend_subtitle',
        'period',
        'date_label',
        'value_label',
        'table_caption',
        'empty',
        'invalid',
    ];

    for (const locale of ['fr', 'en', 'es']) {
        const messages = JSON.parse(read(`resources/js/i18n/modules/${locale}/super_admin.json`));
        const copy = messages.super_admin?.dashboard?.site_traffic;

        for (const key of keys) {
            assert.equal(typeof copy?.[key], 'string', `${locale}.site_traffic.${key}`);
            assert.notEqual(copy[key].trim(), '', `${locale}.site_traffic.${key} is not empty`);
        }

        assert.equal(copy.period.includes('{start}'), true, `${locale} period keeps {start}`);
        assert.equal(copy.period.includes('{end}'), true, `${locale} period keeps {end}`);
    }
});
