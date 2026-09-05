import assert from 'node:assert/strict';
import test from 'node:test';
import { reservationCalendarUrl, reservationReloadProps } from '../../resources/js/utils/reservationNavigation.js';

test('future calendar navigation retains search, pagination and the chosen list view in its URL', () => {
    const url = reservationCalendarUrl('https://malikia.test/app/reservations?search=Jules+Roger&view_mode=list&page=2&calendar_date=2026-09-04#details', {
        view: 'month', date: '2026-11-15',
    });
    const parsed = new URL(url, 'https://malikia.test');

    assert.equal(parsed.searchParams.get('search'), 'Jules Roger');
    assert.equal(parsed.searchParams.get('view_mode'), 'list');
    assert.equal(parsed.searchParams.get('page'), '2');
    assert.equal(parsed.searchParams.get('calendar_date'), '2026-11-15');
    assert.equal(parsed.searchParams.getAll('calendar_date').length, 1);
    assert.equal(parsed.searchParams.get('calendar_view'), 'month');
    assert.equal(parsed.hash, '#details');
});

test('list searches refresh filtered summaries without loading calendar, clients, performance or other tabs', () => {
    const props = reservationReloadProps({ tab: 'reservations', view: 'list', reason: 'filters', changedFilters: ['search'] });
    assert.deepEqual(props, ['filters', 'reservationCount', 'reservations', 'quickCounts', 'stats']);
});

test('calendar filters refresh their count without paginating the hidden list', () => {
    const props = reservationReloadProps({ tab: 'reservations', view: 'calendar', reason: 'filters', changedFilters: ['status', 'date_from'] });
    assert.deepEqual(props, ['filters', 'reservationCount', 'quickCounts']);
});

test('scope, staff and service changes invalidate performance as well as filtered summaries', () => {
    for (const field of ['scope', 'team_member_id', 'service_id']) {
        const props = reservationReloadProps({ tab: 'reservations', view: 'list', reason: 'filters', changedFilters: [field] });
        assert.ok(props.includes('stats'), field);
        assert.ok(props.includes('performance'), field);
    }
});

test('sorting and tab navigation load only the relevant rows and counters', () => {
    assert.deepEqual(reservationReloadProps({ tab: 'reservations', view: 'list', reason: 'ordering' }), ['filters', 'reservationCount', 'reservations']);
    assert.deepEqual(reservationReloadProps({ tab: 'queue', view: 'calendar', reason: 'navigation' }), ['filters', 'queueItems', 'queueStats']);
    assert.deepEqual(reservationReloadProps({ tab: 'waitlist', view: 'calendar', reason: 'navigation' }), ['filters', 'waitlists', 'waitlistStats']);
});

test('reservation mutations invalidate linked queue and waitlist data and all summaries', () => {
    const props = reservationReloadProps({ tab: 'reservations', view: 'calendar' });
    for (const key of ['stats', 'performance', 'reservationCount', 'quickCounts', 'queueItems', 'queueStats', 'waitlists', 'waitlistStats']) {
        assert.ok(props.includes(key), key);
    }
    assert.ok(!props.includes('reservations'));
    assert.ok(!props.includes('events'));
});
