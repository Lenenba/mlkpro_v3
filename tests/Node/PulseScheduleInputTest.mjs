import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import test from 'node:test';
import { socialScheduleInputValue } from '../../resources/js/utils/socialScheduleInput.js';

const read = (path) => readFileSync(resolve(path), 'utf8');

const composer = read('resources/js/Pages/Social/Components/SocialPostComposer.vue');
const calendar = read('resources/js/Pages/Social/Components/SocialEditorialCalendar.vue');
const approvalInbox = read('resources/js/Pages/Social/Components/SocialApprovalInbox.vue');
const scheduleInputSource = read('resources/js/utils/socialScheduleInput.js');

test('the canonical tenant-local schedule takes precedence over the legacy instant', () => {
    assert.equal(socialScheduleInputValue({
        scheduled_local_time: '2026-08-29 10:30:00',
        scheduled_for: '2026-08-29T14:30:00+00:00',
        scheduled_timezone: 'America/Toronto',
    }), '2026-08-29T10:30');

    assert.equal(socialScheduleInputValue({
        scheduled_local_time: '2026-08-29T10:30:00.000000Z',
        scheduled_for: '2026-08-29T14:30:00+00:00',
    }), '2026-08-29T10:30');
});

test('legacy instants become valid datetime-local values in the persisted tenant timezone', () => {
    assert.equal(socialScheduleInputValue({
        scheduled_for: '2026-08-29T14:30:00+00:00',
        scheduled_timezone: 'America/Toronto',
    }), '2026-08-29T10:30');

    assert.equal(socialScheduleInputValue({
        scheduled_for: '2026-01-29T15:30:00Z',
        scheduled_timezone: 'America/Toronto',
    }), '2026-01-29T10:30');

    assert.equal(socialScheduleInputValue({
        scheduled_for: '2026-08-29T16:30',
    }), '2026-08-29T16:30');
});

test('legacy fallback never returns an offset ISO or an invalid local date', () => {
    const browserFallback = socialScheduleInputValue({
        scheduled_for: '2026-08-29T14:30:00+00:00',
    });

    assert.match(browserFallback, /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/u);
    assert.doesNotMatch(browserFallback, /(?:Z|[+-]\d{2}:?\d{2})$/iu);
    assert.equal(socialScheduleInputValue({ scheduled_local_time: '2026-02-30 10:00:00' }), '');
    assert.equal(socialScheduleInputValue({
        scheduled_for: '2026-08-29T14:30:00Z',
        scheduled_timezone: 'Invalid/Timezone',
    }), '');
    assert.equal(socialScheduleInputValue(null), '');
});

test('every editable Pulse schedule hydrates through the shared provider-neutral helper', () => {
    for (const [name, component] of Object.entries({ composer, calendar, approvalInbox })) {
        assert.match(component, /from '@\/utils\/socialScheduleInput'/u, name);
        assert.match(component, /socialScheduleInputValue\(/u, name);
    }

    assert.match(composer, /scheduled_for: socialScheduleInputValue\(draft\)/u);
    assert.match(calendar, /socialScheduleInputValue\(post\) \|\| suggestedScheduleInput\(\)/u);
    assert.match(approvalInbox, /socialScheduleInputValue\(post\) \|\| nextScheduleInput\(\)/u);
    assert.doesNotMatch(composer, /scheduled_for: String\(draft\?\.scheduled_for/u);
    assert.doesNotMatch(calendar, /localInputValue\(post\.scheduled_for\)/u);
    assert.doesNotMatch(approvalInbox, /String\(post\?\.scheduled_for \|\| nextScheduleInput\(\)\)/u);
    assert.doesNotMatch(scheduleInputSource, /buffer/iu);
});
