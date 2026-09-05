import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import test from 'node:test';

const read = (path) => readFileSync(resolve(path), 'utf8');

const inbox = read('resources/js/Pages/AiAssistant/Conversations/Index.vue');
const settings = read('resources/js/Pages/AiAssistant/Settings.vue');
const conversation = read('resources/js/Pages/AiAssistant/Conversations/Show.vue');

test('the AI inbox exposes a submitted search while preserving the active filters', () => {
    assert.match(inbox, /q:\s*props\.filters\?\.q \|\| ''/u);
    assert.match(inbox, /role="search"/u);
    assert.match(inbox, /@submit\.prevent="applySearch"/u);
    assert.match(inbox, /data-ai-conversation-search/u);
    assert.match(inbox, /type="search"/u);
    assert.match(inbox, /v-model="searchQuery"/u);
    assert.match(inbox, /applyFilter\('q', searchQuery\.value\.trim\(\)\)/u);
    assert.match(inbox, /\.\.\.filterState\.value/u);
});

test('the AI inbox rows expose contact activity and reservation context', () => {
    assert.match(inbox, /conversation\.visitor_email/u);
    assert.match(inbox, /conversation\.visitor_phone/u);
    assert.match(inbox, /conversation\.public_uuid/u);
    assert.match(inbox, /conversation\.last_activity_at/u);
    assert.match(inbox, /data-ai-reservation-preview/u);
    assert.match(inbox, /conversation\.reservation\.id/u);
    assert.match(inbox, /conversation\.reservation\.status/u);
    assert.match(inbox, /conversation\.reservation\.service_name/u);
    assert.match(inbox, /conversation\.reservation\.starts_at/u);
});

test('settings and conversation detail return to the complete AI inbox', () => {
    const forcedReviewLink = /route\('admin\.ai-assistant\.conversations\.index',\s*\{\s*queue:\s*'review'\s*\}\)/u;

    assert.doesNotMatch(settings, forcedReviewLink);
    assert.doesNotMatch(conversation, forcedReviewLink);
    assert.match(settings, /route\('admin\.ai-assistant\.conversations\.index'\)/u);
    assert.match(conversation, /route\('admin\.ai-assistant\.conversations\.index'\)/u);
    assert.match(inbox, /params:\s*\{\s*queue:\s*'review',\s*status:\s*undefined\s*\}/u);
});
