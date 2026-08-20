import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import dayjs from 'dayjs';
import {
    currentReservationDay,
    reservationMonthGridDates,
    reservationWeekStart,
    resolveReservationViewAnchor,
} from '../../resources/js/utils/reservationCalendar.js';

const source = (path) => fs.readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

test('month to week resets a past anchor to the current day', () => {
    const anchor = dayjs('2026-06-12T10:30:00');
    const now = dayjs('2026-08-20T16:45:00');
    const resolved = resolveReservationViewAnchor({
        currentView: 'month',
        nextView: 'week',
        anchor,
        now,
    });

    assert.equal(resolved.format('YYYY-MM-DD HH:mm:ss'), '2026-08-20 00:00:00');
});

test('reservation weeks begin on Monday at midnight', () => {
    const start = reservationWeekStart('2026-08-20T16:45:00');

    assert.equal(start.format('YYYY-MM-DD HH:mm:ss'), '2026-08-17 00:00:00');
});

test('today is evaluated for each action instead of being captured once', () => {
    const beforeMidnight = currentReservationDay('2026-08-20T23:59:59');
    const afterMidnight = currentReservationDay('2026-08-21T00:00:01');

    assert.equal(beforeMidnight.format('YYYY-MM-DD HH:mm:ss'), '2026-08-20 00:00:00');
    assert.equal(afterMidnight.format('YYYY-MM-DD HH:mm:ss'), '2026-08-21 00:00:00');
});

test('the month grid remains chronological from its first Monday', () => {
    const dates = reservationMonthGridDates('2026-08-20T16:45:00');

    assert.equal(dates.length, 42);
    assert.equal(dates[0].format('YYYY-MM-DD'), '2026-07-27');
    assert.equal(dates[41].format('YYYY-MM-DD'), '2026-09-06');
    dates.slice(1).forEach((date, index) => {
        assert.equal(date.diff(dates[index], 'day'), 1);
    });
});

test('the staff page uses square metrics in two responsive compact groups', () => {
    const stats = source('resources/js/Components/Reservation/ReservationStats.vue');
    const staffPage = source('resources/js/Pages/Reservation/Index.vue');
    const clientPage = source('resources/js/Pages/Reservation/ClientIndex.vue');
    const calendar = source('resources/js/Components/Reservation/ReservationCalendarBoard.vue');

    assert.match(stats, /compact:\s*\{[\s\S]*?type:\s*Boolean[\s\S]*?default:\s*false/);
    assert.match(stats, /grid-cols-\[repeat\(auto-fill,minmax\(6\.25rem,1fr\)\)\]/);
    assert.match(stats, /aspect-square/);
    assert.match(stats, /xl:grid-cols-\[minmax\(0,5fr\)_minmax\(0,6fr\)\]/);
    assert.match(stats, /xl:grid-cols-\[minmax\(0,5fr\)_minmax\(0,7fr\)\]/);
    assert.match(stats, /return 'xl:grid-cols-2'/);
    assert.match(stats, /return 'xl:grid-cols-5'/);
    assert.match(stats, /return 'xl:grid-cols-6'/);
    assert.match(stats, /return 'xl:grid-cols-7'/);
    assert.match(stats, /<section[\s\S]*?v-if="compact && hasPerformance"/);
    assert.match(stats, /<details[\s\S]*?v-else-if="hasPerformance"/);
    assert.match(staffPage, /<ReservationStats\s+:stats="stats"\s+:performance="performance"\s+compact\s*\/>/);
    assert.doesNotMatch(clientPage, /<ReservationStats[^>]*\scompact(?:\s|\/|>)/);
    assert.match(calendar, /anchorDate\.value = currentReservationDay\(\)/);
    assert.match(calendar, /v-for="\(day, index\) in monthGrid"/);
});
