import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import test from 'node:test';

import {
    mergeCustomerActivityItems,
    normalizeCustomerActivityFilters,
    normalizeCustomerActivityPayload,
    serializeCustomerActivityFilters,
    toggleCustomerActivityType,
    validateCustomerActivityFilters,
} from '../../resources/js/utils/customerActivity.js';

const read = (path) => readFileSync(resolve(path), 'utf8');

test('customer history filters normalize aliases, dates, and API parameters', () => {
    assert.deepEqual(normalizeCustomerActivityFilters({
        period: 'custom',
        from: '2026-08-01',
        to: '2026-08-31',
        types: ['reservation', 'payments', 'payment', 'unknown'],
    }), {
        period: 'custom',
        from: '2026-08-01',
        to: '2026-08-31',
        types: ['appointments', 'payments'],
    });

    assert.equal(validateCustomerActivityFilters({ period: 'custom' }), 'dates_required');
    assert.equal(validateCustomerActivityFilters({
        period: 'custom',
        from: '2026-08-31',
        to: '2026-08-01',
    }), 'invalid_range');
    assert.deepEqual(toggleCustomerActivityType([], 'invoice'), ['invoices']);
    assert.deepEqual(serializeCustomerActivityFilters({
        period: 'last_30_days',
        types: ['notes'],
    }, {
        cursor: 'opaque-cursor',
        perPage: 100,
    }), {
        period: 'last_30_days',
        per_page: 50,
        types: ['notes'],
        cursor: 'opaque-cursor',
    });
});

test('customer history payloads normalize every source and merge pages without duplicates', () => {
    const payload = normalizeCustomerActivityPayload({
        data: [
            {
                id: 'reservation:1',
                occurred_at: '2026-08-21T12:00:00Z',
                type: 'appointments',
                status: 'confirmed',
                title: 'Appointment confirmed',
            },
            {
                id: 'payment:2',
                occurred_at: '2026-08-20T12:00:00Z',
                type: 'payments',
                amount: { value: -25, currency_code: 'CAD' },
                resource: { href: '/invoices/3' },
            },
        ],
        meta: {
            available_types: ['appointments', 'payments', 'profile_changes'],
            types: [],
            has_more: true,
            next_cursor: 'next',
            timezone: 'America/Toronto',
        },
    });

    assert.deepEqual(payload.meta.types, []);
    assert.equal(payload.data[0].category, 'appointments');
    assert.equal(payload.data[1].amount.value, -25);
    assert.equal(payload.links.next, null);
    assert.deepEqual(
        mergeCustomerActivityItems(payload.data, [payload.data[1], {
            id: 'activity:3',
            type: 'profile_changes',
        }]).map((item) => item.id),
        ['reservation:1', 'payment:2', 'activity:3'],
    );
});

test('customer history UI exposes responsive, accessible, cancellable, and permission-safe states', () => {
    const timeline = read('resources/js/Components/Customer/CustomerHistoryTimeline.vue');
    const show = read('resources/js/Pages/Customer/Show.vue');
    const salesPanel = read('resources/js/Components/CRM/SalesActivityPanel.vue');

    assert.match(timeline, /aria-busy/);
    assert.match(timeline, /aria-live="polite"/);
    assert.match(timeline, /filters\.period === 'custom'/);
    assert.match(timeline, /AbortController/);
    assert.match(timeline, /requestSequence/);
    assert.match(timeline, /resourceHref/);
    assert.match(timeline, /\^\\\/\(\?!\\\/\)/);
    assert.match(timeline, /states\.load_more/);
    assert.match(timeline, /motion-reduce:animate-none/);
    assert.match(show, /CustomerHistoryTimeline/);
    assert.match(show, /customerActivityEndpoint/);
    assert.match(show, /@logged="refreshCustomerHistory"/);
    assert.match(show, /:show-feed="false"/);
    assert.match(salesPanel, /showFeed/);
});

test('customer history copy exists in every supported locale', () => {
    const requiredPaths = [
        'details.history.title',
        'details.history.periods.last_7_days',
        'details.history.periods.custom',
        'details.history.types.all',
        'details.history.types.appointments',
        'details.history.types.profile_changes',
        'details.history.validation.invalid_range',
        'details.history.states.loading',
        'details.history.states.error_title',
        'details.history.states.empty_title',
        'details.history.states.no_results_title',
        'details.history.states.load_more',
    ];

    for (const locale of ['fr', 'en', 'es']) {
        const messages = JSON.parse(read(`resources/js/i18n/modules/${locale}/customers.json`));

        for (const path of requiredPaths) {
            const value = path
                .split('.')
                .reduce((current, segment) => current?.[segment], messages.customers);

            assert.equal(typeof value, 'string', `${locale}:${path}`);
            assert.notEqual(value.trim(), '', `${locale}:${path}`);
        }
    }
});
