import assert from 'node:assert/strict';
import test from 'node:test';
import {
    countReservationAdvancedFilters,
    createReservationAdvancedFilters,
    initialReservationQuickFilters,
    reservationFilterPayload,
    toggleReservationQuickFilter,
} from '../../resources/js/utils/reservationFilters.js';
import { reservationCalendarUrl, reservationReloadProps } from '../../resources/js/utils/reservationNavigation.js';

test('saved legacy links work while an explicit empty canonical selection clears the legacy filter', () => {
    assert.deepEqual(initialReservationQuickFilters({ quick: 'today' }), ['today']);
    assert.deepEqual(initialReservationQuickFilters({ quick: 'today', quick_filters: [] }), []);
    assert.deepEqual(initialReservationQuickFilters({ quick: 'today', quick_filters: '' }), []);
    assert.deepEqual(initialReservationQuickFilters({ quick_filters: ['pending', 'pending', ['today'], 'unknown', 'today'] }), ['pending', 'today']);
});

test('quick filters accumulate and toggle off independently without mutating the current selection', () => {
    const original = ['pending'];
    const combined = toggleReservationQuickFilter(original, 'today');
    assert.deepEqual(original, ['pending']);
    assert.deepEqual(combined, ['pending', 'today']);
    assert.deepEqual(toggleReservationQuickFilter(combined, 'pending'), ['today']);
    assert.deepEqual(toggleReservationQuickFilter(combined, 'unknown'), combined);
});

test('advanced drafts preserve applied values and resetting mine retains the implicit staff constraint', () => {
    const applied = { search: 'Jules', status: 'confirmed', service_id: 8, date_from: '2031-11-18', scope: 'mine', team_member_id: 99 };
    const draft = createReservationAdvancedFilters(applied, '7');
    assert.deepEqual(draft, { status: 'confirmed', service_id: '8', team_member_id: '7', date_from: '2031-11-18', date_to: '' });
    draft.status = 'cancelled';
    assert.equal(applied.status, 'confirmed');
    assert.equal(countReservationAdvancedFilters({ ...draft, scope: 'mine' }), 3);
    assert.equal(countReservationAdvancedFilters({ ...draft, scope: 'all' }), 4);
    const cleared = createReservationAdvancedFilters({ scope: 'mine' }, '7');
    assert.equal(cleared.team_member_id, '7');
    assert.equal(countReservationAdvancedFilters({ ...cleared, scope: 'mine' }), 0);
    assert.equal(createReservationAdvancedFilters({ scope: 'all' }, '7').team_member_id, '');
});

test('list and calendar payloads keep search and advanced filters alongside the quick combination', () => {
    const filters = {
        search: 'Jules', status: 'confirmed', service_id: '8', team_member_id: '7',
        date_from: '2031-11-18', date_to: '', scope: 'all', quick_filters: ['pending', 'today'],
        quick_filter_mode: 'any', quick: 'cancelled', sort: 'date_desc', view_mode: 'list',
    };
    assert.deepEqual(reservationFilterPayload(filters), {
        search: 'Jules', status: 'confirmed', service_id: '8', team_member_id: '7',
        date_from: '2031-11-18', date_to: undefined, scope: 'all',
        quick_filters: ['pending', 'today'], quick_filter_mode: 'any',
    });
    assert.equal(reservationFilterPayload({ quick_filter_mode: ['any'] }).quick_filter_mode, 'all');
    assert.deepEqual(reservationFilterPayload({ quick_filters: [], quick: 'today', quick_filter_mode: 'any' }).quick_filters, []);
});

test('quick combinations refresh matching rows and survive calendar navigation without reloading global metrics', () => {
    for (const field of ['quick_filters', 'quick_filter_mode']) {
        assert.deepEqual(reservationReloadProps({ tab: 'reservations', view: 'list', reason: 'filters', changedFilters: [field] }), ['filters', 'reservationCount', 'reservations', 'quickCounts']);
        assert.deepEqual(reservationReloadProps({ tab: 'reservations', view: 'calendar', reason: 'filters', changedFilters: [field] }), ['filters', 'reservationCount', 'quickCounts']);
    }
    const url = new URL(reservationCalendarUrl('https://malikia.test/app/reservations?quick_filters[0]=pending&quick_filters[1]=today&quick_filter_mode=any&search=Jules', {
        view: 'week', date: '2031-11-18',
    }), 'https://malikia.test');
    assert.equal(url.searchParams.get('quick_filters[0]'), 'pending');
    assert.equal(url.searchParams.get('quick_filters[1]'), 'today');
    assert.equal(url.searchParams.get('quick_filter_mode'), 'any');
    assert.equal(url.searchParams.get('search'), 'Jules');
});
