import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import test from 'node:test';

const read = (path) => readFileSync(resolve(path), 'utf8');

const reservationIndex = read('resources/js/Pages/Reservation/ClientIndex.vue');
const reservationBook = read('resources/js/Pages/Reservation/ClientBook.vue');
const bookingJourney = read('resources/js/Components/Reservation/ClientBookingJourney.vue');
const reservationCalendar = read('resources/js/Components/Reservation/ReservationCalendarBoard.vue');
const reservationStatus = read('resources/js/Components/Reservation/status.js');

test('client reservation overview makes useful use of the small breakpoint', () => {
    assert.match(reservationIndex, /grid-class="grid-cols-1 sm:grid-cols-2 lg:grid-cols-3"/u);
    assert.doesNotMatch(reservationIndex, /grid-class="grid-cols-1 md:grid-cols-3"/u);
    assert.doesNotMatch(`${reservationBook}\n${bookingJourney}`, /<KpiMetricGrid/u);
});

test('client reservation surfaces fill the shell and keep structural corners compact', () => {
    const compactCalendarClass = '!rounded-sm [&_.rounded-md]:!rounded-sm [&_.rounded-lg]:!rounded-sm [&_.rounded-xl]:!rounded-sm [&_.rounded-2xl]:!rounded-sm';
    const inheritedCalendarRadii = new Set(
        [...`${reservationCalendar}\n${reservationStatus}`.matchAll(/\brounded-(md|lg|xl|2xl|3xl)\b/gu)]
            .map(([, radius]) => radius),
    );

    for (const radius of inheritedCalendarRadii) {
        assert.ok(
            compactCalendarClass.includes(`[&_.rounded-${radius}]:!rounded-sm`),
            `the client calendar overrides inherited rounded-${radius} surfaces`,
        );
    }

    for (const source of [reservationIndex, reservationBook, bookingJourney]) {
        const pageOwnedMarkup = source.replaceAll(compactCalendarClass, '');

        assert.doesNotMatch(pageOwnedMarkup, /\brounded-(?:md|lg|xl|2xl|3xl)\b/u);
        assert.doesNotMatch(
            pageOwnedMarkup,
            /<button\b[^>]*\brounded-full\b[^>]*>/u,
        );
    }

    assert.match(reservationIndex, /<div class="w-full min-w-0 max-w-full space-y-4">/u);
    assert.match(reservationBook, /<div class="w-full min-w-0 max-w-full space-y-4">/u);
    assert.match(reservationIndex, /<KpiMetricGrid[\s\S]*?class="mt-4 \[&>\*\]:!rounded-sm"[\s\S]*?\/>/u);
    assert.equal(reservationIndex.split(compactCalendarClass).length - 1, 2);
    assert.equal(bookingJourney.split(compactCalendarClass).length - 1, 0);
    assert.match(reservationIndex, /<ReservationStats class="\[&_article\]:!rounded-sm"/u);
    assert.match(reservationBook, /<ClientBookingJourney/u);
    assert.doesNotMatch(bookingJourney, /ReservationCalendarBoard|<main\b/u);
    assert.match(bookingJourney, /grid min-w-\[42rem\] grid-cols-7 gap-2 px-1 sm:min-w-0/u);
    assert.match(bookingJourney, /mt-3 grid gap-2 sm:grid-cols-2 xl:grid-cols-3/u);
});

test('client reservation feedback delegates its visual and live semantics to the shared notice', () => {
    for (const [state, tone] of [
        ['queueActionError', 'error'],
        ['queueActionSuccess', 'success'],
        ['detailsActionError', 'error'],
        ['reviewError', 'error'],
    ]) {
        assert.match(
            reservationIndex,
            new RegExp(`<ClientPortalNotice[^>]*v-if="${state}"[^>]*tone="${tone}"[^>]*:message="${state}"`, 'u'),
        );
    }

    for (const [state, tone] of [
        ['submitError', 'error'],
        ['waitlistError', 'error'],
        ['waitlistSuccess', 'success'],
    ]) {
        assert.match(
            bookingJourney,
            new RegExp(`<ClientPortalNotice[^>]*v-if="${state}"[^>]*tone="${tone}"[^>]*:message="${state}"`, 'u'),
        );
    }

    assert.match(
        bookingJourney,
        /<section v-else[^>]*role="status"[^>]*aria-live="polite"[\s\S]*?\{\{ successMessage \|\| successDescription \}\}/u,
        'ClientBookingJourney must announce booking success through its completion live region',
    );
});
