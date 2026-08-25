import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import test from 'node:test';
import dayjs from 'dayjs';
import {
    addReservationCalendarTime,
    currentReservationDay,
    reservationCalendarDay,
    reservationCalendarEndOf,
    reservationMonthGridDates,
    reservationCalendarStartOf,
    reservationWeekStart,
    resolveReservationViewAnchor,
} from '../../resources/js/utils/reservationCalendar.js';

const source = (path) => fs.readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const json = (path) => JSON.parse(source(path));
let calendarComponentModulePromise = null;

const calendarComponentModule = () => {
    if (calendarComponentModulePromise) {
        return calendarComponentModulePromise;
    }

    const calendar = source('resources/js/Components/Reservation/ReservationCalendarBoard.vue');
    const moduleScript = calendar.match(/<script>\s*([\s\S]*?)<\/script>/)?.[1];
    assert.ok(moduleScript, 'ReservationCalendarBoard must expose its calendar indexing helpers');

    const utilityUrl = new URL(
        '../../resources/js/utils/reservationCalendar.js',
        import.meta.url
    ).href;
    const executable = moduleScript.replace(
        /from '@\/utils\/reservationCalendar';/g,
        `from ${JSON.stringify(utilityUrl)};`
    );
    const moduleUrl = `data:text/javascript;base64,${Buffer.from(executable).toString('base64')}`;
    calendarComponentModulePromise = import(moduleUrl);

    return calendarComponentModulePromise;
};

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

test('UTC calendar arithmetic is independent from the browser timezone', () => {
    const utilityUrl = new URL(
        '../../resources/js/utils/reservationCalendar.js',
        import.meta.url
    ).href;
    const script = `
        import {
            addReservationCalendarTime,
            reservationCalendarStartOf,
            reservationMonthGridDates,
        } from ${JSON.stringify(utilityUrl)};

        const grid = reservationMonthGridDates('2026-03-15T00:00:00Z', 42, 'UTC');
        const nextDay = addReservationCalendarTime('2026-08-24T00:00:00Z', 1, 'day', 'UTC');
        const yearStart = reservationCalendarStartOf('2026-08-24T00:00:00Z', 'year', 'UTC');
        const december = addReservationCalendarTime(yearStart, 11, 'month', 'UTC');

        process.stdout.write(JSON.stringify({
            first: grid[0].format('YYYY-MM-DD HH:mm Z'),
            last: grid.at(-1).format('YYYY-MM-DD HH:mm Z'),
            nextDay: nextDay.format('YYYY-MM-DD HH:mm Z'),
            december: december.format('YYYY-MM-DD HH:mm Z'),
        }));
    `;

    for (const hostTimezone of ['Europe/Paris', 'America/Toronto']) {
        const result = spawnSync(process.execPath, ['--input-type=module', '--eval', script], {
            cwd: process.cwd(),
            encoding: 'utf8',
            env: { ...process.env, TZ: hostTimezone },
        });

        assert.equal(result.status, 0, result.stderr);
        assert.deepEqual(JSON.parse(result.stdout), {
            first: '2026-02-23 00:00 +00:00',
            last: '2026-04-05 00:00 +00:00',
            nextDay: '2026-08-25 00:00 +00:00',
            december: '2026-12-01 00:00 +00:00',
        });
    }
});

test('Toronto calendar days keep their civil date and offset across DST', () => {
    const timezone = 'America/Toronto';
    const springStart = reservationCalendarStartOf(
        '2026-03-08T12:00:00-04:00',
        'day',
        timezone
    );
    const springEnd = reservationCalendarEndOf(springStart, 'day', timezone);
    const fallStart = reservationCalendarStartOf(
        '2026-11-01T12:00:00-05:00',
        'day',
        timezone
    );
    const fallEnd = reservationCalendarEndOf(fallStart, 'day', timezone);

    assert.equal(springStart.format('YYYY-MM-DD HH:mm Z'), '2026-03-08 00:00 -05:00');
    assert.equal(springEnd.format('YYYY-MM-DD HH:mm:ss.SSS Z'), '2026-03-08 23:59:59.999 -04:00');
    assert.ok(Math.abs(springEnd.diff(springStart, 'hour', true) - 23) < 0.001);
    assert.equal(fallStart.format('YYYY-MM-DD HH:mm Z'), '2026-11-01 00:00 -04:00');
    assert.equal(fallEnd.format('YYYY-MM-DD HH:mm:ss.SSS Z'), '2026-11-01 23:59:59.999 -05:00');
    assert.ok(Math.abs(fallEnd.diff(fallStart, 'hour', true) - 25) < 0.001);

    const springMonday = addReservationCalendarTime(
        '2026-03-07T00:00:00-05:00',
        2,
        'day',
        timezone
    );
    const fallMonday = addReservationCalendarTime(
        '2026-10-31T00:00:00-04:00',
        2,
        'day',
        timezone
    );

    assert.equal(springMonday.format('YYYY-MM-DD HH:mm Z'), '2026-03-09 00:00 -04:00');
    assert.equal(fallMonday.format('YYYY-MM-DD HH:mm Z'), '2026-11-02 00:00 -05:00');

    const springGridMonday = reservationMonthGridDates(
        '2026-03-15T12:00:00-04:00',
        42,
        timezone
    ).find((date) => date.format('YYYY-MM-DD') === '2026-03-09');
    const fallGridMonday = reservationMonthGridDates(
        '2026-11-15T12:00:00-05:00',
        42,
        timezone
    ).find((date) => date.format('YYYY-MM-DD') === '2026-11-02');

    assert.equal(springGridMonday?.format('YYYY-MM-DD HH:mm Z'), '2026-03-09 00:00 -04:00');
    assert.equal(fallGridMonday?.format('YYYY-MM-DD HH:mm Z'), '2026-11-02 00:00 -05:00');
});

test('opening a displayed DST date never falls back to the previous day', () => {
    const timezone = 'America/Toronto';
    const staleOffsetDate = dayjs.tz('2026-10-31T00:00:00', timezone).add(2, 'day');
    const openedDay = reservationCalendarDay(staleOffsetDate, timezone);

    assert.equal(staleOffsetDate.format('YYYY-MM-DD HH:mm Z'), '2026-11-02 00:00 -04:00');
    assert.equal(openedDay.format('YYYY-MM-DD HH:mm Z'), '2026-11-02 00:00 -05:00');
    assert.equal(openedDay.toISOString(), '2026-11-02T05:00:00.000Z');
});

test('calendar events are indexed on every overlapped civil day without including an exclusive midnight end', async () => {
    const { indexReservationEventsByDay } = await calendarComponentModule();

    for (const scenario of [
        { timezone: 'America/Toronto', offset: '-04:00' },
        { timezone: 'Europe/Paris', offset: '+02:00' },
    ]) {
        const startedBeforeRange = {
            id: 41,
            startAt: `2026-08-23T23:00:00${scenario.offset}`,
            endAt: `2026-08-24T01:00:00${scenario.offset}`,
            original: { id: 41 },
        };
        const overnight = {
            id: 42,
            startAt: `2026-08-24T23:30:00${scenario.offset}`,
            endAt: `2026-08-25T02:00:00${scenario.offset}`,
            original: { id: 42 },
        };
        const midnightEnd = {
            id: 43,
            startAt: `2026-08-25T23:30:00${scenario.offset}`,
            endAt: `2026-08-26T00:00:00${scenario.offset}`,
            original: { id: 43 },
        };
        const events = [startedBeforeRange, overnight, midnightEnd];
        const eventsByDay = indexReservationEventsByDay(events, {
            timezone: scenario.timezone,
            rangeStart: `2026-08-24T00:00:00${scenario.offset}`,
            rangeEnd: `2026-08-27T00:00:00${scenario.offset}`,
        });

        assert.deepEqual(
            [...eventsByDay.keys()],
            ['2026-08-24', '2026-08-25'],
            scenario.timezone
        );
        assert.deepEqual(
            eventsByDay.get('2026-08-24').map((event) => event.id),
            [41, 42],
            scenario.timezone
        );
        assert.deepEqual(
            eventsByDay.get('2026-08-25').map((event) => event.id),
            [42, 43],
            scenario.timezone
        );
        assert.equal(eventsByDay.has('2026-08-23'), false, scenario.timezone);
        assert.equal(eventsByDay.has('2026-08-26'), false, scenario.timezone);
        assert.strictEqual(eventsByDay.get('2026-08-24')[0], startedBeforeRange);
        assert.strictEqual(eventsByDay.get('2026-08-25')[0].original, overnight.original);
    }
});

test('the staff page keeps compact metric groups readable without narrow square cards', () => {
    const stats = source('resources/js/Components/Reservation/ReservationStats.vue');
    const staffPage = source('resources/js/Pages/Reservation/Index.vue');
    const clientPage = source('resources/js/Pages/Reservation/ClientIndex.vue');
    const calendar = source('resources/js/Components/Reservation/ReservationCalendarBoard.vue');

    assert.match(stats, /compact:\s*\{[\s\S]*?type:\s*Boolean[\s\S]*?default:\s*false/);
    assert.match(stats, /grid-cols-\[repeat\(auto-fit,minmax\(min\(100%,12rem\),1fr\)\)\]/);
    assert.match(stats, /'grid grid-cols-1 items-start gap-3'/);
    assert.doesNotMatch(stats, /auto-fill|minmax\(6\.25rem|aspect-square/);
    assert.doesNotMatch(stats, /xl:grid-cols-[567]/);
    assert.match(stats, /<section[\s\S]*?v-if="compact && hasPerformance"/);
    assert.match(stats, /<details[\s\S]*?v-else-if="hasPerformance"/);
    assert.match(staffPage, /<ReservationStats\s+:stats="stats"\s+:performance="performance"\s+compact\s*\/>/);
    assert.doesNotMatch(clientPage, /<ReservationStats[^>]*\scompact(?:\s|\/|>)/);
    assert.match(calendar, /anchorDate\.value = currentReservationDay\(/);
    assert.match(calendar, /v-for="\(day, index\) in monthGrid"/);
});

test('the staff reservation calendar opens in a single-row week view with a neutral header', () => {
    const calendar = source('resources/js/Components/Reservation/ReservationCalendarBoard.vue');
    const staffPage = source('resources/js/Pages/Reservation/Index.vue');
    const clientPage = source('resources/js/Pages/Reservation/ClientIndex.vue');
    const staffCalendar = staffPage.match(/<ReservationCalendarBoard\b[\s\S]*?\/>/u)?.[0] || '';
    const weekView = calendar.match(
        /<div v-else-if="viewMode === 'week'"[\s\S]*?(?=<div v-else-if="viewMode === 'day'")/u
    )?.[0] || '';
    const header = calendar.match(/<header[\s\S]*?<\/header>/u)?.[0] || '';

    assert.ok(staffCalendar, 'the staff page must render the reservation calendar');
    assert.match(staffCalendar, /\binitial-view="week"/u);
    assert.match(weekView, /sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-7/u);
    assert.doesNotMatch(weekView, /xl:grid-cols-4|2xl:grid-cols-7/u);
    assert.match(header, /border-stone-200 bg-stone-50\/80/u);
    assert.doesNotMatch(header, /(?:bg|border)-amber-/u);
    assert.doesNotMatch(calendar, /(?:bg|border)-amber-/u);
    assert.match(staffPage, /\$t\('reservations\.view\.calendar'\)/u);
    assert.match(clientPage, /\$t\('reservations\.view\.calendar'\)/u);
});

test('calendar navigation copy is complete and parameterized in every locale', () => {
    for (const locale of ['fr', 'en', 'es']) {
        const messages = json(`resources/js/i18n/modules/${locale}/planning.json`);
        const reservationMessages = json(`resources/js/i18n/modules/${locale}/reservations.json`);
        const calendar = messages.planning?.calendar;

        assert.ok(calendar, `${locale} must expose planning.calendar`);
        for (const key of ['day', 'week', 'month', 'year', 'today', 'previous', 'next']) {
            assert.equal(typeof calendar[key], 'string', `${locale} is missing planning.calendar.${key}`);
            assert.ok(calendar[key].trim(), `${locale} has an empty planning.calendar.${key}`);
        }

        assert.equal(typeof calendar.open_day, 'string', `${locale} is missing planning.calendar.open_day`);
        assert.match(calendar.open_day, /\{date\}/, `${locale} open_day must keep its date placeholder`);
        assert.match(messages.planning?.preview?.more || '', /\{count\}/, `${locale} preview.more must keep its count placeholder`);
        assert.match(messages.planning?.preview?.count_services || '', /\{count\}/, `${locale} preview.count_services must keep its count placeholder`);
        assert.ok(
            reservationMessages.reservations?.calendar?.loading?.trim(),
            `${locale} is missing reservations.calendar.loading`
        );
        assert.ok(
            reservationMessages.reservations?.view?.calendar?.trim(),
            `${locale} is missing reservations.view.calendar`
        );
    }
});

test('the reservation calendar remains responsive, accessible and visually informative', () => {
    const calendar = source('resources/js/Components/Reservation/ReservationCalendarBoard.vue');
    const staffPage = source('resources/js/Pages/Reservation/Index.vue');
    const clientPage = source('resources/js/Pages/Reservation/ClientIndex.vue');
    const clientBook = source('resources/js/Pages/Reservation/ClientBook.vue');
    const statusStyles = source('resources/js/Components/Reservation/status.js');

    assert.match(calendar, /overflow-x-auto/);
    assert.match(calendar, /min-w-\[52rem\]/);
    assert.match(calendar, /extendedProps\??\.service_name/);
    assert.match(calendar, /extendedProps\??\.client_name/);
    assert.match(calendar, /extendedProps\??\.team_member_name/);
    assert.match(calendar, /extendedProps\??\.source/);
    assert.match(calendar, /(?:const\s+personInitials\s*=|function\s+personInitials\s*\()/);
    assert.match(calendar, /(?:const\s+eventAccessibleLabel\s*=|function\s+eventAccessibleLabel\s*\()/);
    assert.match(calendar, /export const indexReservationEventsByDay/);
    assert.match(calendar, /overlapEnd\.subtract\(1, 'millisecond'\)/);
    assert.equal(
        [...calendar.matchAll(/\{\{\s*getEventStatusLabel\(event\)\s*\}\}/g)].length,
        4,
        'month, week, day and year views must expose a visible status label'
    );
    assert.match(calendar, /:aria-label="yearMonthAccessibleLabel\(month\)"/);

    assert.match(calendar, /aria-current[^\n>]*date/);
    assert.match(calendar, /aria-pressed/);
    assert.match(calendar, /focus-visible:ring/);
    assert.match(calendar, /role="status"/);
    assert.match(calendar, /role="alert"/);
    assert.match(calendar, /motion-reduce:animate-none/);
    assert.match(calendar, /timezone:\s*\{[\s\S]*?default:\s*'UTC'/);
    assert.match(calendar, /dayjs\.extend\(utc\)/);
    assert.match(calendar, /dayjs\.extend\(timezonePlugin\)/);
    assert.match(calendar, /\.tz\(calendarTimezone\.value\)/);
    assert.match(calendar, /addReservationCalendarTime/);
    assert.match(calendar, /reservationMonthGridDates\([\s\S]*?calendarTimezone\.value/);
    assert.match(calendar, /reservationCalendarDay\(date, calendarTimezone\.value\)/);
    assert.match(calendar, /window\.setInterval/);
    assert.match(calendar, /window\.clearInterval/);
    assert.match(calendar, /Intl\.DateTimeFormat\(calendarLocale\.value/);
    assert.doesNotMatch(calendar, /\.format\('M{3,4} D, YYYY'\)/);
    assert.match(staffPage, /:timezone="timezone"/);
    assert.equal(
        [...`${staffPage}\n${clientPage}\n${clientBook}`.matchAll(/<ReservationCalendarBoard[\s\S]*?:timezone="timezone"[\s\S]*?\/>/g)].length,
        4,
        'every reservation calendar receives the tenant timezone'
    );
    assert.match(staffPage, /calendarAbortController\?\.abort\(\)/);
    assert.match(staffPage, /calendarRequestSequence/);
    assert.match(calendar, /const openDay = \(date\) => \{[\s\S]*?viewMode\.value = 'day'/);

    assert.match(calendar, /t\('planning\.calendar\.previous'\)/);
    assert.match(calendar, /t\('planning\.calendar\.next'\)/);
    assert.match(calendar, /t\('planning\.calendar\.open_day'/);
    assert.match(calendar, /t\((?:'planning\.calendar\.year'|`planning\.calendar\.\$\{mode\}`)\)/);
    assert.match(calendar, /t\('planning\.preview\.more'/);
    assert.doesNotMatch(calendar, />\s*Year\s*</);
    assert.doesNotMatch(calendar, /}}\s+more\s*</);
    assert.doesNotMatch(
        `${calendar}\n${statusStyles}\n${staffPage}\n${clientPage}\n${clientBook}`,
        /gradient/i
    );

    assert.match(calendar, /emit\('event-click', event\.original \|\| event\)/);
    assert.match(calendar, /availableViews\s*=\s*\['day', 'week', 'month', 'year'\]/);
});
