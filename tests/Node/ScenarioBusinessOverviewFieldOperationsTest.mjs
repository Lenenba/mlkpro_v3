import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

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

test('field-operation overview labels exist in French, English, and Spanish', () => {
    for (const locale of ['fr', 'en', 'es']) {
        const messages = JSON.parse(source(`resources/js/i18n/modules/${locale}/dashboard.json`));
        const labels = messages.dashboard?.scenario?.field_operations;

        assert.equal(typeof labels, 'object', `${locale} must define dashboard.scenario.field_operations`);

        for (const key of fieldOperationKeys) {
            assert.equal(typeof labels?.[key], 'string', `${locale} must define field_operations.${key}`);
            assert.notEqual(labels[key].trim(), '', `${locale} field_operations.${key} must not be empty`);
        }
    }
});
