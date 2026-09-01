import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import {
    SCENARIO_ACTIVITY_MIN_PERIODS,
    buildScenarioActivityChartData,
} from '../../resources/js/utils/scenarioActivityChart.js';

const source = (path) => fs.readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const component = source('resources/js/Components/Dashboard/ScenarioBusinessOverview.vue');
const fieldOperationKeys = [
    'jobs_today',
    'capacity_usage',
    'average_job_value',
    'completed_jobs',
    'quality_incidents',
    'top_employees',
    'top_products',
];

test('the scenario overview selects field-operation labels from the operating model', () => {
    assert.match(
        component,
        /const isFieldOperations = computed\(\(\) => props\.insights\?\.operating_model === 'field_operations'\);/u,
    );

    for (const key of fieldOperationKeys) {
        assert.match(
            component,
            new RegExp(`dashboard\\.scenario\\.field_operations\\.${key}`, 'u'),
            `ScenarioBusinessOverview must use the field_operations.${key} label`,
        );
    }
});

test('field team rankings prefer activity_count over appointment-specific reservations', () => {
    assert.match(
        component,
        /number\(item\.activity_count \?\? item\.reservations\)/u,
    );
});

test('scenario history uses the shared accessible column chart for operating activity', () => {
    const barChart = source('resources/js/Components/UI/Barchart.vue');

    assert.match(component, /import Barchart from '@\/Components\/UI\/Barchart\.vue'/u);
    assert.match(component, /buildScenarioActivityChartData\(monthly\.value/u);
    assert.match(component, /data-testid="scenario-monthly-activity-chart"/u);
    assert.match(component, /:color-tones="\['blue'\]"/u);
    assert.match(component, /:framed="false"/u);
    assert.doesNotMatch(component, /barHeight|Math\.max\(3|scenario-twelve-month-chart/u);
    assert.match(barChart, /:color-tones="colorTones"/u);
});

test('scenario activity chart requires aligned contiguous exact monthly counts', () => {
    const validMonthly = {
        labels: ['2026-05', '2026-06', '2026-07', '2026-08'],
        reservations: [0, 4, 2, 8],
    };
    const result = buildScenarioActivityChartData(validMonthly, {
        labelForPeriod: (periodKey) => `Month ${periodKey}`,
        seriesLabel: 'Jobs',
    });

    assert.equal(SCENARIO_ACTIVITY_MIN_PERIODS, 4);
    assert.deepEqual(result.categories, [
        'Month 2026-05',
        'Month 2026-06',
        'Month 2026-07',
        'Month 2026-08',
    ]);
    assert.deepEqual(result.series, [{ name: 'Jobs', data: [0, 4, 2, 8] }]);

    const emptyResult = { categories: [], series: [], rows: [] };
    const invalidPayloads = [
        { ...validMonthly, reservations: [0, 4, null, 8] },
        { ...validMonthly, reservations: [0, 4, 2] },
        { labels: ['2026-05', '2026-06', '2026-08', '2026-09'], reservations: [0, 4, 2, 8] },
        { labels: ['2026-05', '2026-06', '2026-06', '2026-07'], reservations: [0, 4, 2, 8] },
        { ...validMonthly, reservations: [0, 4, 2.5, 8] },
        { labels: validMonthly.labels.slice(0, 3), reservations: [0, 4, 2] },
    ];

    for (const payload of invalidPayloads) {
        assert.deepEqual(buildScenarioActivityChartData(payload), emptyResult);
    }

    assert.deepEqual(buildScenarioActivityChartData(validMonthly), emptyResult);
});

test('field-operation overview labels exist in French, English, and Spanish', () => {
    for (const locale of ['fr', 'en', 'es']) {
        const messages = JSON.parse(source(`resources/js/i18n/modules/${locale}/dashboard.json`));
        const labels = messages.dashboard?.scenario?.field_operations;
        const scenario = messages.dashboard?.scenario;

        assert.equal(typeof labels, 'object', `${locale} must define dashboard.scenario.field_operations`);

        for (const key of fieldOperationKeys) {
            assert.equal(typeof labels?.[key], 'string', `${locale} must define field_operations.${key}`);
            assert.notEqual(labels[key].trim(), '', `${locale} field_operations.${key} must not be empty`);
        }

        for (const key of [
            'history_title',
            'history_subtitle',
            'activity_series',
            'month_label',
            'activity_count_label',
            'activity_table_caption',
        ]) {
            assert.equal(typeof scenario?.[key], 'string', `${locale} must define scenario.${key}`);
            assert.notEqual(scenario[key].trim(), '', `${locale} scenario.${key} must not be empty`);
        }

        for (const key of ['history_subtitle', 'activity_series']) {
            assert.equal(typeof labels?.[key], 'string', `${locale} must define field_operations.${key}`);
            assert.notEqual(labels[key].trim(), '', `${locale} field_operations.${key} must not be empty`);
        }
    }
});
