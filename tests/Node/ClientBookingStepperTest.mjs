import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import test from 'node:test';
import {
    CLIENT_BOOKING_STEP_KEYS,
    canVisitClientBookingStep,
    createClientBookingStepState,
    invalidateClientBookingStepsFrom,
    transitionClientBookingStep,
} from '../../resources/js/utils/clientBookingStepper.js';

const read = (path) => readFileSync(resolve(path), 'utf8');
const readJson = (path) => JSON.parse(read(path));
const bookingPage = read('resources/js/Pages/Reservation/ClientBook.vue');
const bookingJourney = read('resources/js/Components/Reservation/ClientBookingJourney.vue');

test('client booking steps prevent skipping ahead and retain visited progress', () => {
    const initialState = createClientBookingStepState();

    assert.deepEqual(CLIENT_BOOKING_STEP_KEYS, ['preferences', 'slot', 'review', 'done']);
    assert.equal(canVisitClientBookingStep(initialState, 1), false);
    assert.equal(transitionClientBookingStep(initialState, 2), initialState);

    const slotState = transitionClientBookingStep(initialState, 1, { force: true });
    const reviewState = transitionClientBookingStep(slotState, 2, { force: true });
    const returnedState = transitionClientBookingStep(reviewState, 0);

    assert.deepEqual(returnedState, {
        currentStep: 0,
        maxVisitedStep: 2,
        completed: false,
    });
    assert.equal(canVisitClientBookingStep(returnedState, 2), true);
});

test('client booking steps invalidate downstream choices and lock after completion', () => {
    const reviewState = transitionClientBookingStep(
        transitionClientBookingStep(createClientBookingStepState(), 1, { force: true }),
        2,
        { force: true },
    );
    const invalidatedState = invalidateClientBookingStepsFrom(reviewState, 1);
    const completedState = transitionClientBookingStep(reviewState, 3, { force: true });

    assert.deepEqual(invalidatedState, createClientBookingStepState());
    assert.equal(completedState.completed, true);
    assert.equal(canVisitClientBookingStep(completedState, 0), false);
    assert.equal(transitionClientBookingStep(completedState, 0), completedState);
});

test('client booking wrapper delegates a focused and responsive four-step journey', () => {
    assert.match(bookingPage, /import ClientBookingJourney from '@\/Components\/Reservation\/ClientBookingJourney\.vue';/u);
    assert.match(bookingPage, /<ClientBookingJourney[\s\S]*?:timezone="timezone"[\s\S]*?:team-members="teamMembers"[\s\S]*?:services="services"[\s\S]*?:client="client"[\s\S]*?:capabilities="capabilities"[\s\S]*?:settings="settings"[\s\S]*?\/>/u);

    assert.match(bookingJourney, /CLIENT_BOOKING_STEP_KEYS/u);
    assert.match(bookingJourney, /:aria-current="index === currentStep \? 'step' : undefined"/u);
    assert.match(bookingJourney, /const serviceOptions = computed\(\(\) => \[[\s\S]*?value: '',[\s\S]*?\.\.\.\(props\.services \|\| \[\]\)\.map\(\(service\) => \(\{[\s\S]*?value: String\(service\.id\),[\s\S]*?search: \[service\.name, service\.description\]/u);
    assert.match(bookingJourney, /id="client-booking-service-search"[\s\S]*?v-model="selectedServiceId"[\s\S]*?:options="serviceOptions"[\s\S]*?filterable[\s\S]*?select-on-focus[\s\S]*?:filter-placeholder="\$t\('reservations\.client\.book\.fields\.service_search_placeholder'\)"[\s\S]*?:empty-label="\$t\('reservations\.client\.book\.states\.no_service_match'\)"/u);
    assert.match(bookingJourney, /\{\{ selectedServiceLabel \}\}[\s\S]*?\{\{ selectedServiceDescription \}\}/u);
    assert.doesNotMatch(bookingJourney, /v-for="service in services"|selectServicePreference/u);
    assert.match(bookingJourney, /ref="stepPanel"[\s\S]*?tabindex="-1"/u);
    assert.match(bookingJourney, /nextTick\(\(\) => stepPanel\.value\?\.focus/u);
    assert.match(bookingJourney, /class="sm:hidden"[\s\S]*?progressWidthClass/u);
    assert.match(bookingJourney, /class="hidden grid-cols-4 gap-2 sm:grid"/u);
    assert.match(bookingJourney, /lg:grid-cols-\[minmax\(0,1fr\)_20rem\]/u);
    assert.match(bookingJourney, /lg:sticky lg:top-5/u);
    assert.doesNotMatch(bookingJourney, /ReservationCalendarBoard|<main\b/u);
    assert.doesNotMatch(`${bookingPage}\n${bookingJourney}`, /<KpiMetricGrid/u);
});

test('client booking exposes a compact accessible week day and slot selector', () => {
    assert.match(bookingJourney, /const weekDays = computed/u);
    assert.match(bookingJourney, /const selectedDaySlots = computed/u);
    assert.match(bookingJourney, /@click="changeWeek\(-1\)"/u);
    assert.match(bookingJourney, /@click="changeWeek\(1\)"/u);
    assert.match(bookingJourney, /v-for="day in weekDays"[\s\S]*?:aria-pressed="selectedDayKey === day\.key"[\s\S]*?@click="selectDay\(day\)"/u);
    assert.match(bookingJourney, /v-for="slot in selectedDaySlots"[\s\S]*?:aria-pressed="selectedSlot\?\.team_member_id === slot\.team_member_id && selectedSlot\?\.starts_at === slot\.starts_at"[\s\S]*?@click="selectSlot\(slot\)"/u);
    assert.match(bookingJourney, /overflow-x-auto[\s\S]*?min-w-\[42rem\][\s\S]*?sm:min-w-0/u);
    assert.match(bookingJourney, /selectedDaySlots\.length[\s\S]*?sm:grid-cols-2 xl:grid-cols-3/u);
});

test('client booking fields connect validation messages to their controls', () => {
    for (const field of [
        'party-size',
        'contact-name',
        'contact-email',
        'contact-phone',
        'client-notes',
        'waitlist-party-size',
        'waitlist-notes',
    ]) {
        assert.match(bookingJourney, new RegExp(`id="client-booking-${field}"[\\s\\S]*?:aria-invalid=`, 'u'));
        assert.match(bookingJourney, new RegExp(`client-booking-${field}-error`, 'u'));
    }
});

test('client booking formats compact selector dates in the tenant timezone', () => {
    assert.match(bookingJourney, /const resolveDisplayTimezone = \(value\) =>/u);
    assert.match(bookingJourney, /new Intl\.DateTimeFormat\('en', \{ timeZone: timezone \}\)/u);
    assert.match(bookingJourney, /catch \{\s*return 'UTC';/u);
    assert.match(bookingJourney, /const initialTimezone = resolveDisplayTimezone\(props\.timezone\)/u);
    assert.match(bookingJourney, /reservationWeekStart\(dayjs\(\), 1, initialTimezone\)/u);
    assert.match(bookingJourney, /new Intl\.DateTimeFormat\(localeCode\.value, \{[\s\S]*?timeZone: displayTimezone\.value/u);
    assert.match(bookingJourney, /reservationCalendarDate\(slot\.starts_at, displayTimezone\.value\)/u);
    assert.match(bookingJourney, /typeof formatter\.formatRange === 'function'/u);
    assert.doesNotMatch(bookingJourney, /dayjs\([^)]*starts_at[^)]*\)\.format/u);
});

test('client booking navigation follows the reservation view capability', () => {
    assert.match(bookingPage, /capabilities:\s*\{[\s\S]*?view:\s*false/u);
    assert.match(bookingPage, /\.\.\.\(props\.capabilities\?\.view \? \[\{[\s\S]*?id: 'reservations'[\s\S]*?\}\] : \[\]\)/u);
    assert.match(bookingPage, /:columns="serviceTabs\.length"/u);
    assert.match(bookingJourney, /const canViewReservations = computed\(\(\) => Boolean\(props\.capabilities\?\.view\)\)/u);
    assert.match(bookingJourney, /canViewReservations\.value[\s\S]*?route\('client\.reservations\.index'\)[\s\S]*?route\('dashboard'\)/u);
    assert.match(bookingJourney, /canViewReservations\.value[\s\S]*?actions\.view_reservations[\s\S]*?actions\.back_to_dashboard/u);
});

test('client booking flow preserves the business guards around slot selection', () => {
    assert.match(bookingJourney, /invalidateClientBookingStepsFrom\(stepState\.value, 1\)/u);
    assert.match(bookingJourney, /selectedSlot\.value = null/u);
    assert.match(bookingJourney, /currentStepKey\.value === 'slot'[\s\S]*?!selectedSlot\.value/u);
    assert.match(bookingJourney, /hasSlotConflict[\s\S]*?await loadSlots\(\)[\s\S]*?setCurrentStep\(1\)/u);
    assert.match(bookingJourney, /const validationErrors = error\?\.response\?\.status === 422[\s\S]*?bookingForm\.setError\(validationErrors\)/u);
    assert.match(bookingJourney, /const slotsReady = await loadSlots\(\);[\s\S]*?if \(!slotsReady\) \{[\s\S]*?return;/u);
    assert.match(bookingJourney, /v-model="bookingForm\.party_size"[\s\S]*?min="1"[\s\S]*?max="500"/u);
    assert.match(bookingJourney, /if \(submitting\.value\) \{\s*return;/u);
    assert.match(bookingJourney, /v-if="!slotBookingAvailable"/u);
    assert.match(bookingJourney, /waitlistEnabled && \(hasNoSlots \|\| ownerOnlyMode \|\| !slotBookingAvailable\)/u);
});

test('client booking stepper copy is available in every portal locale', () => {
    const requiredPaths = [
        'stepper.aria_label',
        'stepper.progress',
        'stepper.completed',
        'steps.preferences.label',
        'steps.preferences.title',
        'steps.slot.label',
        'steps.slot.title',
        'steps.slot.week',
        'steps.slot.previous_week',
        'steps.slot.next_week',
        'steps.slot.day_availability',
        'steps.slot.available_count',
        'steps.slot.unavailable',
        'steps.slot.times_for',
        'steps.slot.time_hint',
        'steps.slot.slot_label',
        'steps.slot.no_slots_for_day',
        'steps.review.label',
        'steps.review.title',
        'steps.done.label',
        'service_search_hint',
        'fields.service_search',
        'fields.service_search_placeholder',
        'states.no_service_match',
        'success.title',
        'success.description_book_only',
        'actions.continue',
        'actions.back',
        'actions.show_service_suggestions',
        'actions.back_to_dashboard',
        'actions.start_over',
        'actions.view_reservations',
    ];

    for (const locale of ['fr', 'en', 'es']) {
        const messages = readJson(`resources/js/i18n/modules/${locale}/reservations.json`);
        const booking = messages.reservations?.client?.book;

        for (const path of requiredPaths) {
            const value = path.split('.').reduce((current, segment) => current?.[segment], booking);

            assert.equal(typeof value, 'string', `${locale}:reservations.client.book.${path}`);
            assert.notEqual(value.trim(), '', `${locale}:reservations.client.book.${path}`);
        }
    }
});
