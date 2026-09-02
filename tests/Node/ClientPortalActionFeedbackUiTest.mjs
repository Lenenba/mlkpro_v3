import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import test from 'node:test';

const read = (path) => readFileSync(resolve(path), 'utf8');
const readJson = (path) => JSON.parse(read(path));

const reservationIndex = read('resources/js/Pages/Reservation/ClientIndex.vue');
const reservationBook = read('resources/js/Pages/Reservation/ClientBook.vue');
const bookingJourney = read('resources/js/Components/Reservation/ClientBookingJourney.vue');
const clientDashboard = read('resources/js/Pages/DashboardClient.vue');

test('closing the cancellation prompt leaves the reservation unchanged', () => {
    const cancelAction = reservationIndex.match(
        /const cancelReservation = async \(reservation\) => \{[\s\S]*?\n\};/u,
    )?.[0] || '';
    const promptIndex = cancelAction.indexOf('const reason = window.prompt');
    const abortIndex = cancelAction.indexOf('if (reason === null)');
    const requestIndex = cancelAction.indexOf('await axios.patch');

    assert.ok(cancelAction, 'the cancellation action exists');
    assert.ok(promptIndex >= 0, 'the raw prompt result is retained');
    assert.ok(abortIndex > promptIndex, 'a null prompt result aborts after the prompt');
    assert.ok(requestIndex > abortIndex, 'the request can only start after the null guard');
    assert.doesNotMatch(cancelAction, /window\.prompt\([^\n]+\) \|\| null/u);
    assert.match(cancelAction, /cancellingReservationId\.value !== null/u);
    assert.match(cancelAction, /cancellingReservationId\.value = reservation\.id/u);
    assert.match(cancelAction, /finally \{\s*cancellingReservationId\.value = null;/u);

    assert.match(reservationIndex, /:disabled="cancellingReservationId !== null"/u);
    assert.match(reservationIndex, /:aria-busy="cancellingReservationId === activeReservation\.id"/u);
    assert.match(
        reservationIndex,
        /cancellingReservationId === activeReservation\.id[\s\S]*?reservations\.client\.book\.actions\.submitting/u,
    );
});

test('reservation feedback uses the shared accessible portal notice', () => {
    for (const source of [reservationIndex, bookingJourney]) {
        assert.match(source, /import ClientPortalNotice from '@\/Components\/Portal\/ClientPortalNotice\.vue';/u);
    }
    assert.match(reservationBook, /import ClientBookingJourney from '@\/Components\/Reservation\/ClientBookingJourney\.vue';/u);

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
        assert.doesNotMatch(reservationIndex, new RegExp(`<div[^>]*v-if="${state}"`, 'u'));
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
        assert.doesNotMatch(bookingJourney, new RegExp(`<div[^>]*v-if="${state}"`, 'u'));
    }

    assert.match(
        bookingJourney,
        /<section v-else[^>]*role="status"[^>]*aria-live="polite"[\s\S]*?\{\{ successMessage \|\| successDescription \}\}/u,
        'ClientBookingJourney must announce booking success through its completion live region',
    );
});

test('reservation writes expose a busy state and reject duplicate actions', () => {
    assert.match(reservationIndex, /v-if="queueModeEnabled"[\s\S]*?:aria-busy="queueActionTicketId !== null"/u);
    assert.doesNotMatch(reservationIndex, /v-if="false"/u);
    assert.match(reservationIndex, /queueActionTicketId\.value !== null/u);
    assert.match(reservationIndex, /:disabled="queueActionTicketId !== null"/u);
    assert.match(reservationIndex, /:aria-busy="queueActionTicketId === ticket\.id"/u);
    assert.match(reservationIndex, /:aria-busy="rescheduleSubmitting"/u);
    assert.match(reservationIndex, /:aria-busy="reviewSubmitting"/u);

    assert.match(bookingJourney, /if \(submitting\.value\) \{\s*return;/u);
    assert.match(bookingJourney, /!waitlistEnabled\.value \|\| waitlistSubmitting\.value/u);
    assert.match(bookingJourney, /:aria-busy="waitlistSubmitting"/u);
    assert.match(bookingJourney, /:aria-busy="slotsLoading \|\| submitting"/u);
    assert.doesNotMatch(bookingJourney, /cancellingWaitlistId|cancelWaitlist|upcomingReservations|waitlistEntries/u);
});

test('the invoice payment method label is translated in every portal locale', () => {
    const expectedLabels = {
        fr: 'Mode de paiement',
        en: 'Payment method',
        es: 'Método de pago',
    };

    assert.match(clientDashboard, /\$t\('client_dashboard\.labels\.payment_method'\)/u);
    assert.doesNotMatch(clientDashboard, />\s*Payment method:/u);

    for (const [locale, expectedLabel] of Object.entries(expectedLabels)) {
        const messages = readJson(`resources/js/i18n/modules/${locale}/client_dashboard.json`);

        assert.equal(messages.client_dashboard?.labels?.payment_method, expectedLabel, locale);
    }
});
