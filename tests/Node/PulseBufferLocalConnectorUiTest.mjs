import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import test from 'node:test';

const read = (path) => readFileSync(resolve(path), 'utf8');
const readJson = (path) => JSON.parse(read(path));

const accountsPage = read('resources/js/Pages/Social/Accounts.vue');
const bufferConnector = read('resources/js/Pages/Social/Components/SocialBufferConnectionCard.vue');

test('the Pulse accounts page mounts the Buffer card with its persisted connections', () => {
    assert.match(accountsPage, /import SocialBufferConnectionCard/u);
    assert.match(accountsPage, /buffer_connector:\s*\{/u);
    assert.match(accountsPage, /buffer_connections:\s*\{[\s\S]*?type:\s*Array/u);
    assert.match(accountsPage, /v-if="props\.buffer_connector"/u);
    assert.match(accountsPage, /:initial-connector="props\.buffer_connector"/u);
    assert.match(accountsPage, /:initial-connections="props\.buffer_connections"/u);
    assert.match(accountsPage, /:can-manage="Boolean\(props\.access\?\.can_manage_accounts\)"/u);
    assert.doesNotMatch(accountsPage, /SocialAccountManager/u);
    assert.doesNotMatch(accountsPage, /provider_definitions/u);
});

test('the Buffer card keeps synced accounts visible and only lists new Buffer channels', () => {
    assert.match(bufferConnector, /route\('social\.buffer\.connect'\)/u);
    assert.match(bufferConnector, /route\('social\.buffer\.disconnect'\)/u);
    assert.match(bufferConnector, /route\('social\.buffer\.catalog'\)/u);
    assert.match(bufferConnector, /route\('social\.buffer\.channels\.store'\)/u);
    assert.match(bufferConnector, /route\('social\.buffer\.channels\.sync'\)/u);
    assert.match(bufferConnector, /organization_id:\s*organization\.id/u);
    assert.match(bufferConnector, /channel_id:\s*channel\.id/u);
    assert.match(bufferConnector, /initialConnections:\s*\{[\s\S]*?type:\s*Array/u);
    assert.match(bufferConnector, /const synchronizedConnections = ref\(normalizeConnections\(props\.initialConnections\)\)/u);
    assert.match(bufferConnector, /watch\(\(\) => props\.initialConnections[\s\S]*?normalizeConnections\(value\)/u);
    assert.match(bufferConnector, /v-for="connection in sortedSynchronizedConnections"/u);
    assert.match(bufferConnector, /social\.buffer_connector\.synchronized_title/u);
    assert.match(bufferConnector, /social\.accounts_manager\.statuses/u);
    assert.match(bufferConnector, /connection\.is_active && connection\.is_connected/u);
    assert.match(bufferConnector, /connection\.needs_attention && connection\.last_error/u);
    assert.match(bufferConnector, /social\.buffer_connector\.states\.publishing_inactive/u);
    assert.match(bufferConnector, /const availableCatalogOrganizations = computed/u);
    assert.match(
        bufferConnector,
        /filter\(\(channel\) => \([\s\S]*?!Boolean\(channel\?\.imported\)[\s\S]*?!synchronizedConnectionIdentities\.value\.has/u,
    );
    assert.match(bufferConnector, /v-for="organization in availableCatalogOrganizations"/u);
    assert.match(bufferConnector, /v-for="channel in organization\.channels"/u);
    assert.doesNotMatch(bufferConnector, /v-for="organization in catalog\.organizations"/u);
    assert.match(bufferConnector, /v-if="props\.canManage"/u);
    assert.match(bufferConnector, /const syncing = ref\(false\)/u);
    assert.match(bufferConnector, /\|\| syncing\.value/u);
    assert.match(
        bufferConnector,
        /const hasChannelsAwaitingPublication = computed[\s\S]*?channel\?\.can_import[\s\S]*?!Boolean\(channel\?\.publication_enabled\)/u,
    );
    assert.match(
        bufferConnector,
        /const canSyncChannels = computed\(\(\) => \(\s*isDeliveryAuthorized\.value\s*\)\)/u,
    );
    assert.match(bufferConnector, /const shouldAuthorizePublishing = computed[\s\S]*?!isDeliveryAuthorized\.value/u);
    assert.match(
        bufferConnector,
        /Number\(requestError\?\.response\?\.status\) !== 422[\s\S]*?response\?\.data\?\.errors/u,
    );
    assert.doesNotMatch(bufferConnector, /requestError\?\.response\?\.data\?\.message/u);
    assert.doesNotMatch(bufferConnector, /requestError\?\.message/u);
    assert.match(
        bufferConnector,
        /const connectionIdentityKey = \(platform, externalAccountId\)[\s\S]*?JSON\.stringify\(\[normalizedPlatform, normalizedExternalAccountId\]\)/u,
    );
    assert.match(
        bufferConnector,
        /const upsertSynchronizedConnections = \(connections\)[\s\S]*?candidateId === id[\s\S]*?connectionIdentityKey\([\s\S]*?=== identity/u,
    );
    assert.match(
        bufferConnector,
        /applyConnectionPayloads[\s\S]*?upsertSynchronizedConnections\(normalizedConnections\)[\s\S]*?channel\.imported = true[\s\S]*?channel\.publication_enabled = Boolean\(connection\.is_connected\)/u,
    );
    assert.match(bufferConnector, /catalog\.value\.imported_count = catalogChannels\.value\.filter/u);
    assert.match(
        bufferConnector,
        /route\('social\.buffer\.disconnect'\)[\s\S]*?Array\.isArray\(response\.data\?\.connections\)[\s\S]*?replaceSynchronizedConnections\(response\.data\.connections\)/u,
    );
    assert.match(bufferConnector, /const applyConnectorPayload = \(responseConnector\)/u);
    assert.match(bufferConnector, /connector\.value = responseConnector/u);
    assert.match(
        bufferConnector,
        /route\('social\.buffer\.channels\.store'\)[\s\S]*?applyConnectorPayload\(response\.data\?\.connector\)/u,
    );
    assert.match(bufferConnector, /v-else-if="props\.canManage && canSyncChannels"/u);
    assert.match(bufferConnector, /@click="syncAllChannels"/u);
    assert.match(bufferConnector, /social\.buffer_connector\.actions\.sync_all/u);
    assert.match(bufferConnector, /v-if="props\.canManage && shouldAuthorizePublishing"/u);
    assert.match(bufferConnector, /social\.buffer_connector\.messages\.publishing_required/u);
    assert.match(bufferConnector, /@click="importChannel\(organization, channel\)"/u);
    assert.match(bufferConnector, /channelHealthToneClass\(channel\)/u);
    assert.match(bufferConnector, /social\.buffer_connector\.all_synchronized_title/u);
    assert.match(bufferConnector, /social\.buffer_connector\.actions\.search_new_accounts/u);
    assert.match(bufferConnector, /social\.buffer_connector\.actions\.refresh_search/u);
    assert.match(bufferConnector, /role="alert"/u);
    assert.match(bufferConnector, /aria-live="assertive"/u);
    assert.match(bufferConnector, /:aria-busy=/u);
    assert.match(bufferConnector, /href="https:\/\/publish\.buffer\.com\/channels"/u);
    assert.match(bufferConnector, /window\.location\.assign\(redirectUrl\)/u);
    assert.match(bufferConnector, /v-if="props\.canManage && !isConnected"/u);
    assert.match(bufferConnector, /:disabled="!canConnect \|\| busy"/u);
    assert.match(bufferConnector, /v-if="props\.canManage && isConnected && canDisconnect"/u);
    assert.match(
        bufferConnector,
        /const isDeliveryEnabled = computed\(\(\) => Boolean\(connector\.value\?\.delivery_enabled\)\)/u,
    );
    assert.match(
        bufferConnector,
        /const isDeliveryAuthorized = computed\(\(\) => Boolean\(connector\.value\?\.delivery_authorized\)\)/u,
    );
    assert.match(
        bufferConnector,
        /v-if="props\.canManage && connector\.mode === 'oauth' && isConnected && !isDeliveryAuthorized"/u,
    );
    assert.match(bufferConnector, /social\.buffer_connector\.actions\.enable_publishing/u);
    assert.match(
        bufferConnector,
        /isDeliveryEnabled[\s\S]*?social\.buffer_connector\.delivery_enabled[\s\S]*?social\.buffer_connector\.delivery_disabled/u,
    );
    assert.doesNotMatch(bufferConnector, /connector\.manage_url/u);
    assert.doesNotMatch(bufferConnector, /access[_-]?token/iu);
    assert.doesNotMatch(bufferConnector, /BUFFER_/u);
});

test('every locale explains persistent accounts, new-account discovery and Buffer delivery states', () => {
    for (const locale of ['fr', 'en', 'es']) {
        const translations = readJson(`resources/js/i18n/modules/${locale}/social.json`)
            .social.buffer_connector;

        assert.equal(typeof translations.title, 'string', `${locale}:title`);
        assert.equal(typeof translations.delivery_enabled, 'string', `${locale}:delivery_enabled`);
        assert.equal(typeof translations.delivery_disabled, 'string', `${locale}:delivery_disabled`);
        assert.equal(typeof translations.oauth_mode, 'string', `${locale}:oauth_mode`);
        assert.equal(typeof translations.actions.connect, 'string', `${locale}:connect`);
        assert.equal(typeof translations.actions.enable_publishing, 'string', `${locale}:enable_publishing`);
        assert.equal(typeof translations.actions.disconnect, 'string', `${locale}:disconnect`);
        assert.equal(typeof translations.actions.add_in_buffer, 'string', `${locale}:add_in_buffer`);
        assert.equal(typeof translations.actions.search_new_accounts, 'string', `${locale}:search_new_accounts`);
        assert.equal(typeof translations.actions.refresh_search, 'string', `${locale}:refresh_search`);
        assert.equal(typeof translations.actions.sync_all, 'string', `${locale}:sync_all`);
        assert.equal(typeof translations.actions.syncing_all, 'string', `${locale}:syncing_all`);
        assert.equal(typeof translations.actions.import, 'string', `${locale}:import`);
        assert.equal(typeof translations.actions.reactivate, 'string', `${locale}:reactivate`);
        assert.equal(typeof translations.synchronized_title, 'string', `${locale}:synchronized_title`);
        assert.equal(typeof translations.synchronized_description, 'string', `${locale}:synchronized_description`);
        assert.equal(typeof translations.synchronized_empty_title, 'string', `${locale}:synchronized_empty_title`);
        assert.equal(typeof translations.available_title, 'string', `${locale}:available_title`);
        assert.equal(typeof translations.available_description, 'string', `${locale}:available_description`);
        assert.equal(typeof translations.all_synchronized_title, 'string', `${locale}:all_synchronized_title`);
        assert.equal(typeof translations.states.publishing_active, 'string', `${locale}:publishing_active`);
        assert.equal(typeof translations.states.publishing_inactive, 'string', `${locale}:publishing_inactive`);
        assert.equal(typeof translations.block_reasons.disconnected, 'string', `${locale}:disconnected`);
        assert.equal(typeof translations.messages.disconnect_success, 'string', `${locale}:disconnect_success`);
        assert.equal(typeof translations.messages.publishing_required, 'string', `${locale}:publishing_required`);
        assert.equal(typeof translations.messages.sync_error, 'string', `${locale}:sync_error`);
        assert.equal(typeof translations.messages.sync_success, 'string', `${locale}:sync_success`);
    }
});
