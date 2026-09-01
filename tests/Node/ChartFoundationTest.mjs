import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import { relative, resolve } from 'node:path';
import test from 'node:test';
import {
    buildAccessibleSeriesFill,
    buildChartThemeOptions,
    hasChartData,
    mergeChartOptions,
    normalizeDonutSeries,
    resolveChartSeriesColors,
    resolveChartTheme,
} from '../../resources/js/utils/chartTheme.js';

const read = (path) => readFileSync(resolve(path), 'utf8');
const baseChart = read('resources/js/Components/Charts/BaseApexChart.vue');
const chartFrame = read('resources/js/Components/Charts/ChartFrame.vue');
const dataTable = read('resources/js/Components/Charts/ChartDataTable.vue');
const donutChart = read('resources/js/Components/UI/Donutchart.vue');
const managerDashboard = read('resources/js/Pages/CRM/ManagerDashboard.vue');
const adapters = new Map([
    ['area', read('resources/js/Components/UI/Areachart.vue')],
    ['bar', read('resources/js/Components/UI/Barchart.vue')],
    ['line', read('resources/js/Components/UI/LineChart.vue')],
]);
const sourceFiles = (directory) => readdirSync(directory, { withFileTypes: true })
    .flatMap((entry) => {
        const path = resolve(directory, entry.name);

        if (entry.isDirectory()) {
            return sourceFiles(path);
        }

        return /\.(?:js|mjs|ts|vue)$/u.test(entry.name) ? [path] : [];
    });

test('chart option merging preserves inputs, nested defaults, and non-plain values', () => {
    const annotationDate = new Date('2026-08-30T00:00:00Z');
    const defaults = {
        chart: { toolbar: { show: false }, height: 250 },
        colors: ['#2563eb'],
        annotationDate,
    };
    const overrides = {
        chart: { height: 320 },
        colors: ['#0f766e', '#d97706'],
    };

    const merged = mergeChartOptions(defaults, overrides);

    assert.deepEqual(merged.chart, { toolbar: { show: false }, height: 320 });
    assert.deepEqual(merged.colors, ['#0f766e', '#d97706']);
    assert.strictEqual(merged.annotationDate, annotationDate);
    assert.deepEqual(defaults.colors, ['#2563eb']);
    assert.equal(defaults.chart.height, 250);
});

test('chart data detection treats all-zero series as empty while retaining zeros in meaningful series', () => {
    assert.equal(hasChartData([]), false);
    assert.equal(hasChartData([{ name: 'A', data: [] }]), false);
    assert.equal(hasChartData([{ name: 'A', data: [null, undefined, ''] }]), false);
    assert.equal(hasChartData([{ name: 'A', data: [0] }]), false);
    assert.equal(hasChartData([{ name: 'A', data: [{ x: 'Aug', y: 0 }] }]), false);
    assert.equal(hasChartData([['Aug', 0]]), false);
    assert.equal(hasChartData([{ name: 'A', data: [0, 12] }]), true);
    assert.equal(hasChartData([{ name: 'A', data: [0, -4] }]), true);
});

test('shared theme disables motion and strengthens high-contrast geometry', () => {
    const options = buildChartThemeOptions({
        type: 'line',
        height: 280,
        theme: {
            isDark: true,
            isHighContrast: true,
            isReducedMotion: true,
            fontFamily: 'Inter',
            foreground: '#ffffff',
            mutedForeground: '#e5e7eb',
            grid: '#ffffff',
            palette: ['#93c5fd', '#fde68a'],
        },
    });

    assert.equal(options.chart.type, 'line');
    assert.equal(options.chart.height, 280);
    assert.equal(options.chart.animations.enabled, false);
    assert.equal(options.chart.animations.speed, 0);
    assert.equal(options.chart.animations.dynamicAnimation.enabled, false);
    assert.equal(options.chart.redrawOnParentResize, true);
    assert.equal(options.chart.redrawOnWindowResize, false);
    assert.equal(options.stroke.width, 3);
    assert.equal(options.grid.strokeDashArray, 0);
    assert.equal(options.theme.mode, 'dark');
    assert.deepEqual(options.colors, ['#93c5fd', '#fde68a']);
});

test('shared Apex theme consumes the same five series tokens as mini charts', () => {
    const previousWindow = globalThis.window;
    const expectedPalette = ['#0101aa', '#0202bb', '#0303cc', '#0404dd', '#0505ee'];
    const tokens = new Map([
        ['--chart-series-blue', expectedPalette[0]],
        ['--chart-series-violet', expectedPalette[1]],
        ['--chart-series-emerald', expectedPalette[2]],
        ['--chart-series-amber', expectedPalette[3]],
        ['--chart-series-rose', expectedPalette[4]],
    ]);

    globalThis.window = {
        getComputedStyle: () => ({
            getPropertyValue: (property) => tokens.get(property) || '',
        }),
        matchMedia: () => ({ matches: false }),
    };

    try {
        const theme = resolveChartTheme({
            classList: { contains: () => false },
            dataset: {},
        });

        assert.deepEqual(theme.palette, expectedPalette);
    } finally {
        if (previousWindow === undefined) {
            delete globalThis.window;
        } else {
            globalThis.window = previousWindow;
        }
    }
});

test('semantic chart tones resolve against the active shared palette', () => {
    const palette = ['blue', 'violet', 'emerald', 'amber', 'rose'];

    assert.deepEqual(
        resolveChartSeriesColors(['emerald', 'amber'], palette),
        ['emerald', 'amber'],
    );
    assert.deepEqual(resolveChartSeriesColors(['unknown'], palette), ['blue']);
    assert.deepEqual(resolveChartSeriesColors([], palette), []);
});

test('shared charts consume chart-specific surface, label, axis, and grid tokens', () => {
    const previousWindow = globalThis.window;
    const tokens = new Map([
        ['--chart-label', '#111111'],
        ['--chart-axis', '#222222'],
        ['--chart-grid', '#333333'],
        ['--chart-surface', '#444444'],
        ['--app-foreground', '#aaaaaa'],
        ['--app-muted-foreground-1', '#bbbbbb'],
        ['--app-line-1', '#cccccc'],
        ['--app-layer', '#dddddd'],
    ]);

    globalThis.window = {
        getComputedStyle: () => ({
            getPropertyValue: (property) => tokens.get(property) || '',
        }),
        matchMedia: () => ({ matches: false }),
    };

    try {
        const theme = resolveChartTheme({
            classList: { contains: () => false },
            dataset: { contrast: 'high' },
        });
        const options = buildChartThemeOptions({ theme });

        assert.equal(theme.foreground, '#111111');
        assert.equal(theme.mutedForeground, '#222222');
        assert.equal(theme.grid, '#333333');
        assert.equal(theme.surface, '#444444');
        assert.equal(options.legend.labels.colors, '#111111');
        assert.equal(options.xaxis.labels.style.colors, '#222222');
        assert.equal(options.grid.borderColor, '#333333');
    } finally {
        if (previousWindow === undefined) {
            delete globalThis.window;
        } else {
            globalThis.window = previousWindow;
        }
    }
});

test('donut normalization preserves exact zero slices only when the composition has a positive total', () => {
    assert.deepEqual(normalizeDonutSeries([0, 3, 0]), [0, 3, 0]);
    assert.deepEqual(normalizeDonutSeries([]), []);
    assert.deepEqual(normalizeDonutSeries([0, 0]), []);
    assert.deepEqual(normalizeDonutSeries([-1, 2]), []);
    assert.deepEqual(normalizeDonutSeries([Number.NaN, 2]), []);
    assert.deepEqual(normalizeDonutSeries(['1', 2]), []);

    assert.match(donutChart, /normalizeDonutSeries\(props\.series\)/u);
    assert.match(donutChart, /:series="normalizedSeries"/u);
    assert.match(donutChart, /formatter: \(percentage\) => `\$\{Math\.round\(Number\(percentage\) \|\| 0\)\}%`/u);
    assert.doesNotMatch(donutChart, /globals\?\.labels/u);
});

test('multi-series charts expose a pattern channel in addition to color', () => {
    assert.deepEqual(buildAccessibleSeriesFill(1), {});
    assert.deepEqual(buildAccessibleSeriesFill(3), {
        fill: {
            type: ['solid', 'pattern', 'pattern'],
            opacity: 1,
            pattern: {
                style: ['circles', 'slantedLines', 'verticalLines'],
                width: 6,
                height: 6,
                strokeWidth: 2,
            },
        },
    });

    assert.match(adapters.get('bar'), /buildAccessibleSeriesFill\(props\.series\.length\)/u);
    assert.match(donutChart, /buildAccessibleSeriesFill\(normalizedSeries\.value\.length\)/u);
});

test('Apex runtime remains isolated in the shared client-only renderer', () => {
    const apexRuntimePattern = /(?:from\s*['"]apexcharts['"]|import\s*\(\s*['"]apexcharts['"]\s*\)|new\s+ApexCharts\s*\()/u;
    const offenders = sourceFiles(resolve('resources/js'))
        .filter((path) => relative(resolve('.'), path) !== 'resources/js/Components/Charts/BaseApexChart.vue')
        .filter((path) => apexRuntimePattern.test(readFileSync(path, 'utf8')))
        .map((path) => relative(resolve('.'), path));

    assert.deepEqual(offenders, []);
});

test('operational shares preserve exact widths and expose progress semantics', () => {
    assert.match(managerDashboard, /const normalizedProgressValue = \(value\) =>/u);
    assert.match(managerDashboard, /width: `\$\{normalizedProgressValue\(value\)\}%`/u);
    assert.doesNotMatch(managerDashboard, /Math\.max\(4,/u);
    assert.equal(managerDashboard.match(/role="progressbar"/gu)?.length, 2);
    assert.equal(managerDashboard.match(/:aria-valuenow="normalizedProgressValue\(/gu)?.length, 2);
});

test('base Apex chart is client-only, reactive, theme-aware, and destroys its instance', () => {
    assert.match(baseChart, /import\('apexcharts'\)/u);
    assert.match(baseChart, /createChartSynchronization\(synchronizeChart\)/u);
    assert.doesNotMatch(baseChart, /import\s+ApexCharts\s+from\s+['"]apexcharts['"]/u);
    assert.match(baseChart, /typeof window === 'undefined'/u);
    assert.match(baseChart, /watch\([\s\S]*?resolvedOptions[\s\S]*?chartSynchronization\.request/u);
    assert.match(baseChart, /chartInstance\.updateOptions\(\s*resolvedOptions\.value,\s*false,\s*!theme\.value\.isReducedMotion,\s*true,\s*\)/u);
    assert.match(baseChart, /chartInstance\.destroy\(\)/u);
    assert.match(baseChart, /onBeforeUnmount\([\s\S]*?destroyChart\(\)/u);
    assert.match(baseChart, /MutationObserver/u);
    assert.match(baseChart, /resolveChartSeriesColors\(props\.colorTones, theme\.value\.palette\)/u);
    assert.match(baseChart, /attributeFilter: \['class', 'data-contrast', 'data-reduce-motion'\]/u);
    assert.match(baseChart, /on-hs-appearance-change/u);
    assert.match(baseChart, /if \(typeof reducedMotionQuery\.addEventListener === 'function'\)[\s\S]*?else if \(typeof reducedMotionQuery\.addListener === 'function'\)/u);
    assert.match(baseChart, /aria-hidden="true"/u);
    assert.match(baseChart, /role="alert"/u);
    assert.match(baseChart, /role="status"/u);
    assert.match(baseChart, /v-if="internalError"[\s\S]*?charts\.retry/u);
    assert.ok(
        baseChart.indexOf('await nextChart.render()') < baseChart.indexOf('chartInstance = nextChart'),
        'the instance is published only after Apex finishes its initial render',
    );
    assert.match(baseChart, /catch \(error\) \{[\s\S]*?if \(isUnmounted\) \{[\s\S]*?nextChart\?\.destroy\(\)/u);
});

test('chart frame exposes loading, empty, and error states with an exact data table', () => {
    assert.match(chartFrame, /<figure/u);
    assert.match(chartFrame, /:aria-labelledby="titleId"/u);
    assert.match(chartFrame, /:aria-busy="loading \? 'true' : undefined"/u);
    assert.match(chartFrame, /v-if="loading"[\s\S]*?role="status"/u);
    assert.match(chartFrame, /v-else-if="resolvedErrorMessage"[\s\S]*?role="alert"/u);
    assert.match(chartFrame, /v-else-if="!hasData"[\s\S]*?role="status"/u);
    assert.match(chartFrame, /<ChartDataTable/u);
    assert.match(chartFrame, /v-if="showDataTable && hasData && !loading && !resolvedErrorMessage"/u);
    assert.match(chartFrame, /motion-safe:animate-pulse/u);
    assert.match(chartFrame, /:chart-title="resolvedTitle"/u);

    assert.match(dataTable, /<details/u);
    assert.match(dataTable, /<table/u);
    assert.match(dataTable, /<caption class="sr-only">/u);
    assert.match(dataTable, /<th scope="row"/u);
    assert.match(dataTable, /normalizedSeries\.value\.map/u);
    assert.match(dataTable, /pointValue\(item\.data\[index\]\)/u);
    assert.match(dataTable, /:aria-label="toggleAccessibleLabel"/u);
    assert.match(dataTable, /charts\.show_named_data/u);
});

test('legacy adapters delegate all visuals to the shared accessible foundation without sample data', () => {
    for (const [type, source] of adapters) {
        assert.match(source, /import BaseApexChart from '@\/Components\/Charts\/BaseApexChart\.vue'/u, type);
        assert.match(source, /import ChartFrame from '@\/Components\/Charts\/ChartFrame\.vue'/u, type);
        assert.match(source, /<ChartFrame[\s\S]*?<BaseApexChart[\s\S]*?<\/ChartFrame>/u, type);
        assert.match(source, new RegExp(`type="${type}"`, 'u'), type);
        assert.match(source, /series: \{ type: Array, default: \(\) => \[\] \}/u, type);
        assert.match(source, /categories: \{ type: Array, default: \(\) => \[\] \}/u, type);
        assert.doesNotMatch(source, /new ApexCharts|import ApexCharts/u, type);
        assert.doesNotMatch(source, /In-store|Online|January|February|March/u, type);
        assert.doesNotMatch(source, /show-data-table/u, `${type} must not disable ChartFrame's table`);
        assert.match(source, /colorTones: \{ type: Array, default: \(\) => \[\] \}/u, type);
        assert.match(source, /:color-tones="colorTones"/u, type);
        assert.ok(source.split('\n').length < 140, `${type} adapter stays intentionally small`);
    }
});

test('all shared chart adapters forward semantic color tones without importing Apex directly', () => {
    for (const path of [
        'resources/js/Components/UI/Areachart.vue',
        'resources/js/Components/UI/Barchart.vue',
        'resources/js/Components/UI/Donutchart.vue',
        'resources/js/Components/UI/LineChart.vue',
    ]) {
        const source = read(path);

        assert.match(source, /colorTones: \{ type: Array, default: \(\) => \[\] \}/u, path);
        assert.match(source, /:color-tones="colorTones"/u, path);
        assert.doesNotMatch(source, /new ApexCharts|import ApexCharts/u, path);
    }
});

test('generic chart copy exists in every shared locale', () => {
    for (const locale of ['fr', 'en', 'es']) {
        const messages = JSON.parse(read(`resources/js/i18n/modules/${locale}/shared_ui.json`));

        for (const key of [
            'default_title',
            'loading',
            'empty',
            'error',
            'retry',
            'mini_summary',
            'show_data',
            'hide_data',
            'show_named_data',
            'hide_named_data',
            'data_table_caption',
            'named_data_table_caption',
            'category',
            'value',
            'series',
        ]) {
            assert.equal(typeof messages.charts?.[key], 'string', `${locale}.charts.${key}`);
            assert.ok(messages.charts[key].length > 0, `${locale}.charts.${key} is not empty`);
        }
    }
});
