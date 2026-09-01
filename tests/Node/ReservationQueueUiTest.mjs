import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import test from 'node:test';

const source = (path) => readFileSync(resolve(path), 'utf8');
const messages = (locale) => JSON.parse(source(`resources/js/i18n/modules/${locale}/reservations.json`));

test('hybrid queue offers persistent accessible table and card views', () => {
    const index = source('resources/js/Pages/Reservation/Index.vue');

    assert.match(index, /const queueViewModes = \['table', 'cards'\];/u);
    assert.match(index, /window\.localStorage\.getItem\('reservation_queue_view_mode'\)/u);
    assert.match(index, /window\.localStorage\.setItem\('reservation_queue_view_mode', mode\)/u);
    assert.match(index, /data-testid="reservation-queue-view-table"[\s\S]*?:aria-pressed="queueViewMode === 'table'"/u);
    assert.match(index, /data-testid="reservation-queue-view-cards"[\s\S]*?:aria-pressed="queueViewMode === 'cards'"/u);
    assert.match(index, /<AdminDataTable v-if="queueViewMode === 'table'" embedded :rows="queueRows"/u);
    assert.match(index, /v-else-if="queueRows\.length"[\s\S]*?data-testid="reservation-queue-card-grid"/u);
    assert.match(index, /grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3/u);
    assert.match(index, /dark:border-neutral-700 dark:bg-neutral-900/u);
});

test('queue cards expose operational detail and open appointment details explicitly', () => {
    const index = source('resources/js/Pages/Reservation/Index.vue');
    const queueCardOpeningTag = index.match(/<article[\s\S]*?:aria-labelledby="`queue-card-title-\$\{item\.id\}`"\s*>/u)?.[0] || '';

    assert.notEqual(queueCardOpeningTag, '');
    assert.doesNotMatch(queueCardOpeningTag, /@click=/u, 'nested action buttons must not make the whole card clickable');
    assert.match(index, /const formatQueueSchedule = \(item\) =>/u);
    assert.match(index, /item\?\.reservation_ends_at/u);
    assert.match(index, /item\?\.checked_in_at/u);
    assert.match(index, /item\.estimated_duration_minutes/u);
    assert.match(index, /item\.call_expires_at/u);
    assert.match(index, /const openQueueReservation = \(item\) => \{[\s\S]*?item\?\.reservation_id[\s\S]*?openDetails\(/u);
    assert.match(index, /v-if="item\.reservation_id"[\s\S]*?:aria-label="queueOpenReservationLabel\(item\)"[\s\S]*?@click="openQueueReservation\(item\)"/u);
    assert.match(index, /:ref="\(element\) => setQueueActionButtonRef\(item\.id, element\)"/u);
    assert.match(index, /role="menu"[\s\S]*?role="menuitem"/u);
    assert.match(index, /\['ArrowDown', 'ArrowUp', 'Home', 'End'\]\.includes\(event\.key\)/u);
    assert.match(index, /querySelector\('\[role="menuitem"\]:not\(\[disabled\]\)'\)\?\.focus\(\)/u);
    assert.match(index, /const trigger = openQueueActionsFor\.value[\s\S]*?trigger\?\.focus\(\)/u);
});

test('hybrid queue view and detail copy is complete in every reservation locale', () => {
    const requiredViewKeys = ['label', 'table', 'cards'];
    const requiredDetailKeys = [
        'schedule',
        'check_in',
        'duration',
        'call_deadline',
        'view_reservation',
        'view_reservation_for',
    ];

    for (const locale of ['fr', 'en', 'es']) {
        const queue = messages(locale).reservations.queue;

        for (const key of requiredViewKeys) {
            assert.equal(typeof queue.view[key], 'string', `${locale}.queue.view.${key}`);
            assert.notEqual(queue.view[key].trim(), '', `${locale}.queue.view.${key}`);
        }

        for (const key of requiredDetailKeys) {
            assert.equal(typeof queue.details[key], 'string', `${locale}.queue.details.${key}`);
            assert.notEqual(queue.details[key].trim(), '', `${locale}.queue.details.${key}`);
        }

        assert.match(queue.details.view_reservation_for, /\{client\}/u, `${locale} detail label keeps client interpolation`);
    }
});
