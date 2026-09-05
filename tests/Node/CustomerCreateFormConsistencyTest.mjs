import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import test from 'node:test';

const read = (path) => readFileSync(resolve(path), 'utf8');
const source = read('resources/js/Pages/Customer/Create.vue');
const actionBarSource = read('resources/js/Components/UI/FormActionBar.vue');

test('customer create uses a responsive shell and real navigation actions', () => {
    assert.match(source, /max-w-6xl/);
    assert.match(source, /xl:grid-cols-\[minmax\(0,1\.35fr\)_minmax\(300px,\.85fr\)\]/);
    assert.doesNotMatch(source, /lg:grid-cols-4/);

    assert.match(source, /const cancelHref = computed/);
    assert.match(source, /const handleCancelClick = \(event\) =>/);
    assert.match(source, /<Link[\s\S]*?:href="cancelHref"/);
    assert.match(source, /<FormActionBar :action-columns="isCreating \? 2 : 1">/);
    assert.match(actionBarSource, /sticky bottom-3/);
    assert.match(actionBarSource, /data-form-action-bar/);
    assert.match(actionBarSource, /<slot name="hint"/);
    assert.match(actionBarSource, /<slot name="secondary"/);
    assert.match(actionBarSource, /<slot \/>/);
    assert.match(actionBarSource, /validator: \(value\) => \[1, 2\]\.includes\(value\)/);
    assert.match(actionBarSource, /props\.reserveFloatingAction \? 'pe-14'/);
    assert.match(source, /data-testid="demo-customer-save"/);
    assert.match(source, /customers\.form\.actions\.saving/);
});

test('customer create explains consequential preferences and keeps module guards', () => {
    assert.match(source, /<PreferenceToggleRow[\s\S]*?v-model="form\.portal_access"/);
    assert.match(source, /portalAccessDescription/);
    assert.match(source, /hasAutoValidationFeatures/);

    for (const feature of ['quotes', 'jobs', 'tasks', 'invoices']) {
        assert.match(source, new RegExp(`${feature}FeatureEnabled\\.value`), feature);
    }

    for (const formKey of [
        'auto_accept_quotes',
        'auto_validate_jobs',
        'auto_validate_tasks',
        'auto_validate_invoices',
    ]) {
        assert.match(source, new RegExp(`formKey: '${formKey}'`), formKey);
    }

    assert.match(source, /v-for="option in autoValidationOptions"/);
    assert.match(source, /v-model="form\[option\.formKey\]"/);
});

test('customer address autocomplete exposes dynamic combobox semantics and keyboard controls', () => {
    assert.match(source, /id="customer-address-search"/);
    assert.match(source, /role="combobox"/);
    assert.match(source, /:aria-expanded="suggestions\.length > 0"/);
    assert.match(source, /aria-controls="customer-address-suggestions"/);
    assert.match(source, /id="customer-address-suggestions"[\s\S]*?role="listbox"/);
    assert.match(source, /role="option"/);
    assert.match(source, /@keydown\.down\.prevent="focusAddressSuggestion/);
    assert.match(source, /@keydown\.up\.prevent=/);
    assert.match(source, /@keydown\.enter\.prevent="chooseFirstAddressSuggestion"/);
    assert.match(source, /@keydown\.esc\.prevent/);
    assert.match(source, /:aria-selected="false"/);

    for (const field of ['street1', 'street2', 'city', 'state', 'zip', 'country']) {
        assert.match(source, new RegExp(`v-model="form\\.properties\\.${field}"`), field);
        assert.match(source, new RegExp(`form\\.errors\\['properties\\.${field}'\\]`), field);
    }
});

test('the shared preference row remains a native accessible switch', () => {
    const toggleSource = read('resources/js/Components/PreferenceToggleRow.vue');

    assert.match(toggleSource, /defineModel\(\{[\s\S]*?type: Boolean/);
    assert.match(toggleSource, /type="checkbox"/);
    assert.match(toggleSource, /role="switch"/);
    assert.match(toggleSource, /:aria-labelledby=/);
    assert.match(toggleSource, /:aria-describedby=/);
    assert.match(toggleSource, /peer-focus-visible:ring-2/);
});

test('customer form guidance is available in every supported locale', () => {
    const requiredPaths = [
        'intro.new',
        'intro.edit',
        'intro.required',
        'sections.profile',
        'section_help.client_details',
        'section_help.profile',
        'section_help.portal_access_create',
        'section_help.portal_access_edit',
        'section_help.auto_validation',
        'section_help.additional_details',
        'section_help.location',
        'section_help.billing_preferences',
        'fields.manual_address_hint',
        'fields.discount_rate_hint',
        'actions.saving',
        'actions_hint',
        'states.enabled',
        'states.disabled',
    ];

    for (const locale of ['fr', 'en', 'es']) {
        const messages = JSON.parse(read(`resources/js/i18n/modules/${locale}/customers.json`));

        for (const path of requiredPaths) {
            const value = path
                .split('.')
                .reduce((current, segment) => current?.[segment], messages.customers.form);

            assert.equal(typeof value, 'string', `${locale}:${path}`);
            assert.notEqual(value.trim(), '', `${locale}:${path}`);
        }
    }
});
