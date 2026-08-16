import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import test from 'node:test';

import {
    isMediaOnlyAnnouncement,
    selectPanelAnnouncements,
} from '../../resources/js/Components/Dashboard/announcementPanel.js';

const standard = { id: 1, display_style: 'standard', media_type: 'image', media_url: '/standard.jpg' };
const fullBleed = { id: 2, display_style: 'media_only', media_type: 'video', media_url: '/full-bleed.mp4' };

test('a highest-priority full-bleed announcement owns the panel', () => {
    assert.equal(isMediaOnlyAnnouncement(fullBleed), true);
    assert.deepEqual(selectPanelAnnouncements([fullBleed, standard], 3), [fullBleed]);
});

test('standard announcements retain the configured panel limit', () => {
    const items = [standard, { ...standard, id: 3 }, { ...standard, id: 4 }];
    assert.deepEqual(selectPanelAnnouncements(items, 2), items.slice(0, 2));
});

test('an editorial panel excludes lower-priority full-bleed announcements before applying its limit', () => {
    const secondStandard = { ...standard, id: 3 };
    const thirdStandard = { ...standard, id: 4 };

    assert.deepEqual(
        selectPanelAnnouncements([standard, fullBleed, secondStandard, thirdStandard], 2),
        [standard, secondStandard],
    );
});

test('legacy media-only data without valid media falls back safely to editorial rendering', () => {
    const missingMedia = { id: 5, display_style: 'media_only', media_type: 'none', media_url: null };
    assert.equal(isMediaOnlyAnnouncement(missingMedia), false);
    assert.deepEqual(selectPanelAnnouncements([missingMedia, standard], 3), [missingMedia, standard]);
});

test('the panel template keeps both layouts aligned, frameless, full-bleed, and accessible', () => {
    const source = readFileSync(
        resolve('resources/js/Components/Dashboard/AnnouncementsPanel.vue'),
        'utf8',
    );

    assert.match(source, /v-if="!isMediaOnlyPanel"/);
    assert.match(source, /:data-display-style="isMediaOnlyPanel \? 'media_only' : 'standard'"/);
    assert.match(source, /alt=""/);
    assert.match(source, /:alt="item\.title \|\| resolvedTitle"/);
    assert.match(source, /:aria-label="item\.title \|\| resolvedTitle"/);
    assert.match(source, /relative bg-white dark:bg-neutral-900 xl:h-full xl:min-h-0 xl:self-stretch/);
    assert.match(source, /grid xl:absolute xl:inset-0 xl:min-h-0/);
    assert.match(source, /h-auto max-h-full w-full object-cover object-center xl:h-full/);
    assert.match(source, /object-contain/);
    assert.match(source, /:style="sectionStyle"/);
    assert.doesNotMatch(source, /bg-black/);
    assert.doesNotMatch(source, /cardStyle/);
    assert.doesNotMatch(source, /classes\.push\('p-3'\)/);
    assert.doesNotMatch(source, /mt-3 overflow-hidden rounded-sm border border-stone-200/);
});
