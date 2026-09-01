import assert from 'node:assert/strict';
import test from 'node:test';
import {
    KPI_MINI_CHART_MIN_POINTS,
    KPI_MINI_CHART_TYPES,
    buildKpiChartGeometry,
    normalizeKpiMiniChart,
    resolveKpiChartTone,
} from '../../resources/js/utils/kpiChart.js';

test('KPI mini charts expose only the supported visual families', () => {
    assert.deepEqual(KPI_MINI_CHART_TYPES, ['column', 'line', 'area']);
    assert.equal(Object.isFrozen(KPI_MINI_CHART_TYPES), true);
    assert.equal(KPI_MINI_CHART_MIN_POINTS, 4);
});

test('KPI tones collapse into five harmonious roots and a neutral fallback', () => {
    const expectedRoots = {
        amber: 'amber',
        blue: 'blue',
        cyan: 'blue',
        emerald: 'emerald',
        fuchsia: 'violet',
        green: 'emerald',
        indigo: 'violet',
        lime: 'emerald',
        orange: 'amber',
        red: 'rose',
        rose: 'rose',
        sky: 'blue',
        slate: 'neutral',
        stone: 'neutral',
        teal: 'emerald',
        violet: 'violet',
    };

    for (const [tone, root] of Object.entries(expectedRoots)) {
        assert.equal(resolveKpiChartTone(tone), root, tone);
    }

    assert.equal(resolveKpiChartTone('  EMERALD '), 'emerald');
    assert.equal(resolveKpiChartTone('unknown'), 'neutral');
    assert.equal(resolveKpiChartTone(null), 'neutral');
});

test('structured KPI charts preserve their measurement contract and aligned finite observations', () => {
    const unit = { type: 'currency', code: 'CAD' };
    const period = {
        start: '2026-05-01',
        end: '2026-08-30',
        timezone: 'America/Toronto',
        comparisonMode: 'aligned_month_to_date',
    };
    const comparison = {
        current: -4,
        previous: 12,
        delta: -16,
        direction: 'down',
    };
    const chart = {
        type: 'area',
        ariaLabel: 'Revenus encaissés par mois',
        series: {
            labels: ['2026-05', '2026-06', '2026-07', '2026-08'],
            values: [0, '12', -4, 8],
            unit,
            period,
            granularity: 'month',
            measurement: 'flow',
            isTemporal: true,
            historyStatus: 'available',
            semanticDirection: 'higher_is_better',
            comparison,
        },
    };

    const model = normalizeKpiMiniChart({ chart });

    assert.deepEqual(model?.values, [0, 12, -4, 8]);
    assert.deepEqual(model?.labels, ['2026-05', '2026-06', '2026-07', '2026-08']);
    assert.equal(model?.type, 'area');
    assert.strictEqual(model?.unit, unit);
    assert.strictEqual(model?.period, period);
    assert.strictEqual(model?.comparison, comparison);
    assert.equal(model?.granularity, 'month');
    assert.equal(model?.measurement, 'flow');
    assert.equal(model?.historyStatus, 'available');
    assert.equal(model?.semanticDirection, 'higher_is_better');
    assert.equal(model?.ariaLabel, 'Revenus encaissés par mois');
    assert.equal(model?.isTemporal, true);
    assert.equal(model?.isLegacy, false);
    assert.equal(model?.pointCount, 4);
    assert.equal(model?.minValue, -4);
    assert.equal(model?.maxValue, 12);
    assert.equal(model?.hasNegativeValues, true);
    assert.equal(model?.hasPositiveValues, true);
    assert.equal(model?.hasZeroValues, true);
});

test('structured KPI charts require explicit truthful temporal history', () => {
    const validSeries = {
        labels: ['2026-05', '2026-06', '2026-07', '2026-08'],
        values: [2, 3, 4, 8],
        isTemporal: true,
        historyStatus: 'available',
    };
    const legacyPoints = [{ value: 2 }, { value: 3 }];
    const invalidCharts = [
        {},
        { type: 'donut', series: validSeries },
        { type: 'line', series: { ...validSeries, isTemporal: false } },
        { type: 'line', series: { ...validSeries, historyStatus: 'requires_snapshot' } },
        { type: 'line', series: { ...validSeries, historyStatus: undefined } },
        { type: 'line', series: { ...validSeries, values: [1, null, ''] } },
        { type: 'line', series: { ...validSeries, values: [1, 2, false, 4] } },
        { type: 'line', series: { ...validSeries, values: [1, Number.POSITIVE_INFINITY] } },
        { type: 'line', series: { ...validSeries, labels: ['2026-07'] } },
        { type: 'line', series: { ...validSeries, labels: ['2026-05', '2026-06', '2026-07', ''] } },
        { type: 'line', series: { ...validSeries, labels: ['2026-05', '2026-06', '2026-07', '2026-07'] } },
        { type: 'line', series: { ...validSeries, labels: ['2026-05', '2026-06', '2026-07', false] } },
        { type: 'line', series: { ...validSeries, values: [1, 2, 3] } },
        {
            type: 'line',
            series: {
                ...validSeries,
                granularity: 'month',
                labels: ['2026-05', '2026-06', '2026-08', '2026-09'],
            },
        },
        {
            type: 'line',
            series: {
                ...validSeries,
                granularity: 'month',
                labels: ['2026-08', '2026-07', '2026-06', '2026-05'],
            },
        },
        { type: 'line', series: { ...validSeries, values: null } },
        [],
    ];

    for (const chart of invalidCharts) {
        assert.equal(
            normalizeKpiMiniChart({ chart, points: legacyPoints }),
            null,
            `explicit invalid chart ${JSON.stringify(chart)} must not fall back to legacy points`,
        );
    }

    const directSeriesModel = normalizeKpiMiniChart({
        chart: {
            type: 'line',
            ...validSeries,
        },
    });
    assert.deepEqual(directSeriesModel?.values, [2, 3, 4, 8]);
    assert.deepEqual(directSeriesModel?.labels, ['2026-05', '2026-06', '2026-07', '2026-08']);
});

test('legacy KPI points remain an isolated column fallback without inventing metadata', () => {
    const model = normalizeKpiMiniChart({
        points: [
            { value: 0, label: 'Mai', height: '4px' },
            { value: '5', label: 'Juin', height: '28px' },
            { value: 3, label: 'Juillet' },
            -0,
        ],
    });

    assert.deepEqual(model?.values, [0, 5, 3, 0]);
    assert.deepEqual(model?.labels, ['Mai', 'Juin', 'Juillet', '']);
    assert.equal(model?.type, 'column');
    assert.equal(model?.unit, null);
    assert.equal(model?.period, null);
    assert.equal(model?.historyStatus, null);
    assert.equal(model?.isTemporal, null);
    assert.equal(model?.isLegacy, true);
    assert.equal(model?.pointCount, 4);
    assert.equal(model?.hasZeroValues, true);

    assert.equal(normalizeKpiMiniChart({ points: [{ value: 7 }] }), null);
    assert.equal(normalizeKpiMiniChart({ points: [{ value: 7 }, { value: 9 }] }), null);
    assert.equal(normalizeKpiMiniChart({ points: [{ value: null }, ''] }), null);
    assert.equal(normalizeKpiMiniChart({
        points: [{ value: 1 }, { value: 2 }, { value: '' }, { value: 4 }],
    }), null);
    assert.equal(normalizeKpiMiniChart({
        points: [{ value: 1 }, { value: 2 }, { value: false }, { value: 4 }],
    }), null);
    assert.equal(normalizeKpiMiniChart(), null);
});

test('column geometry uses a real zero baseline for positive, negative, and zero values', () => {
    const geometry = buildKpiChartGeometry(
        [0, 10, -5],
        'column',
        { width: 100, height: 50, padding: 5 },
    );

    assert.deepEqual(geometry?.domain, { min: -5, max: 10 });
    assert.equal(geometry?.zeroY, 31.667);
    assert.deepEqual(geometry?.columns, [
        { x: 6, y: 31.667, width: 28, height: 0, value: 0 },
        { x: 36, y: 5, width: 28, height: 26.667, value: 10 },
        { x: 66, y: 31.667, width: 28, height: 13.333, value: -5 },
    ]);
    assert.equal(geometry?.columns[0].height, 0, 'a true zero must not receive a decorative minimum height');
    assert.equal(geometry?.linePath, '');
    assert.equal(geometry?.areaPath, '');

    const negativeGeometry = buildKpiChartGeometry(
        [-10, 0],
        'column',
        { width: 100, height: 50, padding: 5 },
    );
    assert.deepEqual(negativeGeometry?.domain, { min: -10, max: 0 });
    assert.equal(negativeGeometry?.zeroY, 5);
    assert.equal(negativeGeometry?.columns[0].height, 40);
    assert.equal(negativeGeometry?.columns[1].height, 0);
});

test('zero and constant column series keep honest non-degenerate render domains', () => {
    const zeroGeometry = buildKpiChartGeometry(
        [0, 0, 0],
        'column',
        { width: 100, height: 50, padding: 5 },
    );

    assert.deepEqual(zeroGeometry?.domain, { min: 0, max: 1 });
    assert.equal(zeroGeometry?.zeroY, 45);
    assert.deepEqual(zeroGeometry?.columns.map((column) => column.height), [0, 0, 0]);

    const constantGeometry = buildKpiChartGeometry(
        [4, 4],
        'column',
        { width: 100, height: 50, padding: 5 },
    );
    assert.deepEqual(constantGeometry?.domain, { min: 0, max: 4 });
    assert.deepEqual(constantGeometry?.columns.map((column) => column.height), [40, 40]);
});

test('line geometry renders constant and changing series without fake columns or areas', () => {
    const constantGeometry = buildKpiChartGeometry(
        [5, 5, 5],
        'line',
        { width: 100, height: 50, padding: 5 },
    );

    assert.deepEqual(constantGeometry?.domain, { min: 4, max: 6 });
    assert.equal(constantGeometry?.zeroY, null);
    assert.deepEqual(constantGeometry?.points, [
        { x: 5, y: 25, value: 5 },
        { x: 50, y: 25, value: 5 },
        { x: 95, y: 25, value: 5 },
    ]);
    assert.equal(constantGeometry?.linePath, 'M 5 25 L 50 25 L 95 25');
    assert.equal(constantGeometry?.areaPath, '');
    assert.deepEqual(constantGeometry?.columns, []);

    const zeroGeometry = buildKpiChartGeometry(
        [0, 0],
        'line',
        { width: 100, height: 50, padding: 5 },
    );
    assert.deepEqual(zeroGeometry?.domain, { min: -1, max: 1 });
    assert.equal(zeroGeometry?.zeroY, 25);
    assert.deepEqual(zeroGeometry?.points.map((point) => point.y), [25, 25]);

    const onePointGeometry = buildKpiChartGeometry(
        [7],
        'line',
        { width: 100, height: 50, padding: 5 },
    );
    assert.equal(onePointGeometry?.points[0].x, 50);
});

test('area geometry closes against zero and supports positive, negative, and constant values', () => {
    const positiveGeometry = buildKpiChartGeometry(
        [0, 10],
        'area',
        { width: 100, height: 50, padding: 5 },
    );

    assert.deepEqual(positiveGeometry?.domain, { min: 0, max: 10 });
    assert.equal(positiveGeometry?.zeroY, 45);
    assert.equal(positiveGeometry?.linePath, 'M 5 45 L 95 5');
    assert.equal(positiveGeometry?.areaPath, 'M 5 45 L 5 45 L 95 5 L 95 45 Z');
    assert.deepEqual(positiveGeometry?.columns, []);

    const mixedGeometry = buildKpiChartGeometry(
        [-3, 6],
        'area',
        { width: 100, height: 50, padding: 5 },
    );
    assert.deepEqual(mixedGeometry?.domain, { min: -3, max: 6 });
    assert.ok(mixedGeometry?.zeroY > 5 && mixedGeometry.zeroY < 45);
    assert.match(mixedGeometry?.areaPath ?? '', / Z$/u);

    const constantGeometry = buildKpiChartGeometry(
        [4, 4],
        'area',
        { width: 100, height: 50, padding: 5 },
    );
    assert.deepEqual(constantGeometry?.domain, { min: 0, max: 4 });
    assert.equal(constantGeometry?.linePath, 'M 5 5 L 95 5');
    assert.equal(constantGeometry?.areaPath, 'M 5 45 L 5 5 L 95 5 L 95 45 Z');
});

test('geometry rejects unusable inputs and bounds invalid dimensions safely', () => {
    assert.equal(buildKpiChartGeometry([1, 2], 'donut'), null);
    assert.equal(buildKpiChartGeometry([], 'line'), null);
    assert.equal(buildKpiChartGeometry([null, '', Number.NaN], 'area'), null);

    const geometry = buildKpiChartGeometry(
        [0, '10', null, Number.POSITIVE_INFINITY],
        'column',
        { width: -1, height: 0, padding: 100 },
    );

    assert.equal(geometry?.width, 120);
    assert.equal(geometry?.height, 40);
    assert.equal(geometry?.padding, 19.5);
    assert.deepEqual(geometry?.columns.map((column) => column.value), [0, 10]);
    for (const column of geometry?.columns ?? []) {
        assert.equal(Number.isFinite(column.x), true);
        assert.equal(Number.isFinite(column.y), true);
        assert.equal(Number.isFinite(column.width), true);
        assert.equal(Number.isFinite(column.height), true);
    }
});
