import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import test from 'node:test';

const read = (path) => readFileSync(resolve(path), 'utf8');
const readJson = (path) => JSON.parse(read(path));

const accountsPage = read('resources/js/Pages/Social/Accounts.vue');
const bufferConnector = read('resources/js/Pages/Social/Components/SocialBufferConnectionCard.vue');

test('the Pulse accounts page mounts the local Buffer discovery card', () => {
    assert.match(accountsPage, /import SocialBufferConnectionCard/u);
    assert.match(accountsPage, /buffer_connector:\s*\{/u);
    assert.match(accountsPage, /v-if="props\.buffer_connector"/u);
    assert.match(accountsPage, /:initial-connector="props\.buffer_connector"/u);
    assert.match(accountsPage, /:can-manage="Boolean\(props\.access\?\.can_manage_accounts\)"/u);
});

test('the Buffer card lists and imports server-discovered channels without accepting a token', () => {
    assert.match(bufferConnector, /route\('social\.buffer\.catalog'\)/u);
    assert.match(bufferConnector, /route\('social\.buffer\.channels\.store'\)/u);
    assert.match(bufferConnector, /organization_id:\s*organization\.id/u);
    assert.match(bufferConnector, /channel_id:\s*channel\.id/u);
    assert.match(bufferConnector, /v-for="organization in catalog\.organizations"/u);
    assert.match(bufferConnector, /v-for="channel in organization\.channels"/u);
    assert.match(bufferConnector, /v-if="props\.canManage"/u);
    assert.match(bufferConnector, /channel\.imported \|\| !channel\.can_import/u);
    assert.match(bufferConnector, /channelHealthToneClass\(channel\)/u);
    assert.match(bufferConnector, /v-if="channel\.imported"/u);
    assert.match(bufferConnector, /role="alert"/u);
    assert.match(bufferConnector, /aria-live="assertive"/u);
    assert.match(bufferConnector, /:aria-busy=/u);
    assert.match(bufferConnector, /href="https:\/\/publish\.buffer\.com\/channels"/u);
    assert.doesNotMatch(bufferConnector, /connector\.manage_url/u);
    assert.doesNotMatch(bufferConnector, /access[_-]?token/iu);
    assert.doesNotMatch(bufferConnector, /BUFFER_/u);
});

test('every locale explains local discovery and the disabled Buffer delivery boundary', () => {
    for (const locale of ['fr', 'en', 'es']) {
        const translations = readJson(`resources/js/i18n/modules/${locale}/social.json`)
            .social.buffer_connector;

        assert.equal(typeof translations.title, 'string', `${locale}:title`);
        assert.equal(typeof translations.delivery_disabled, 'string', `${locale}:delivery_disabled`);
        assert.equal(typeof translations.actions.connect, 'string', `${locale}:connect`);
        assert.equal(typeof translations.actions.add_in_buffer, 'string', `${locale}:add_in_buffer`);
        assert.equal(typeof translations.actions.import, 'string', `${locale}:import`);
        assert.equal(typeof translations.block_reasons.disconnected, 'string', `${locale}:disconnected`);
    }
});
