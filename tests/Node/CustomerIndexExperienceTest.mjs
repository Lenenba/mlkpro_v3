import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import test from 'node:test';

import { deepMerge } from '../../resources/js/i18n/locales/merge.js';

import {
    compactCustomerFilterPayload,
    createCustomerAdvancedFilters,
    initialCustomerQuickFilters,
    normalizeCustomerQuickFilters,
    toggleCustomerQuickFilter,
} from '../../resources/js/utils/customerFilters.js';

const read = (path) => readFileSync(resolve(path), 'utf8');

test('customer quick filters are canonical, deduplicated, and legacy-safe', () => {
    const available = ['vip', 'inactive', 'outstanding_balance'];

    assert.deepEqual(
        normalizeCustomerQuickFilters(['vip', 'vip', 'unknown', 'unpaid'], available),
        ['vip', 'outstanding_balance'],
    );
    assert.deepEqual(
        initialCustomerQuickFilters({
            quick_filters: ['inactive'],
            operational_filter: 'vip',
        }, available),
        ['inactive'],
    );
    assert.deepEqual(toggleCustomerQuickFilter(['vip'], 'inactive'), ['vip', 'inactive']);
    assert.deepEqual(toggleCustomerQuickFilter(['vip', 'inactive'], 'vip'), ['inactive']);
});

test('customer advanced-filter payloads preserve arrays and remove only empty values', () => {
    const filters = createCustomerAdvancedFilters({
        tags: ['priority', 'priority', 'retail'],
        payment_statuses: 'paid',
        appointments_min: 0,
    });

    assert.deepEqual(filters.tags, ['priority', 'retail']);
    assert.deepEqual(filters.payment_statuses, ['paid']);
    assert.equal(filters.appointments_min, '0');
    assert.deepEqual(compactCustomerFilterPayload({
        ...filters,
        status: '',
        enabled: false,
    }).enabled, false);
});

test('customer filtering UI keeps URL history, partial-prop stability, and modal recovery', () => {
    const table = read('resources/js/Pages/Customer/UI/CustomerTable.vue');
    const modal = read('resources/js/Components/Modal.vue');
    const dialog = read('resources/js/Components/Customer/CustomerAdvancedFiltersDialog.vue');

    assert.match(table, /quick_filter_mode: filterForm\.quick_filter_mode/);
    assert.match(table, /replace: false/);
    assert.match(table, /watch\(\(\) => props\.filters/);
    assert.match(table, /only: \['customers', 'filters', 'count', 'filterMeta', 'topCustomers'\]/);
    assert.doesNotMatch(table, /onException:/);
    assert.match(table, /router\.on\('exception'/);
    assert.match(dialog, /full-screen-mobile/);
    assert.match(dialog, /customer-advanced-filters-title/);
    assert.match(modal, /cancelPendingClose/);
    assert.match(modal, /!dialog\.value\.open/);
});

test('customer index experience copy exists in every supported locale', () => {
    const requiredPaths = [
        'stats.new_this_month',
        'stats.inactive',
        'stats.outstanding',
        'kpis.show_more',
        'filter_summary.modes.all',
        'filter_summary.modes.any',
        'filter_summary.clear_all',
        'advanced_filters.title',
        'advanced_filters.sections.appointments',
        'advanced_filters.sections.billing',
        'advanced_filters.fields.acquisition_source',
        'advanced_filters.fields.payment_statuses',
        'states.empty.title',
        'states.no-results.description',
        'states.error.action',
        'accessibility.select_all',
        'appointment.quick_filters.new_this_month',
        'appointment.quick_filters.upcoming_appointment',
        'appointment.quick_filters.outstanding_balance',
        'appointment.labels.no_unpaid_balance',
        'actions.edit',
        'actions.archive',
        'labels.logo_alt',
        'labels.selected',
        'details.preview.title',
        'form.client_types.individual',
    ];

    for (const locale of ['fr', 'en', 'es']) {
        const commonMessages = JSON.parse(read(`resources/js/i18n/modules/${locale}/customers.json`));
        const pageMessages = JSON.parse(read(`resources/js/i18n/modules/${locale}/customer_index.json`));
        const messages = deepMerge(commonMessages, pageMessages);

        assert.equal(commonMessages.customers.stats, undefined, `${locale}:common customers stays page-agnostic`);
        assert.equal(typeof pageMessages.customers.stats, 'object', `${locale}:index owns its stats`);

        for (const path of requiredPaths) {
            const value = path
                .split('.')
                .reduce((current, segment) => current?.[segment], messages.customers);

            assert.equal(typeof value, 'string', `${locale}:${path}`);
            assert.notEqual(value.trim(), '', `${locale}:${path}`);
        }
    }
});
