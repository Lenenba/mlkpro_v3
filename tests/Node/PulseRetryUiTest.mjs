import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import test from 'node:test';

const read = (path) => readFileSync(resolve(path), 'utf8');
const readJson = (path) => JSON.parse(read(path));

const calendar = read('resources/js/Pages/Social/Components/SocialEditorialCalendar.vue');
const composer = read('resources/js/Pages/Social/Components/SocialPostComposer.vue');
const history = read('resources/js/Pages/Social/Components/SocialPostHistory.vue');

test('visible frontend branding consistently uses Pulse', () => {
    const brandedModules = ['nav', 'social', 'super_admin', 'team', 'workspace_hub'];

    for (const locale of ['fr', 'en', 'es']) {
        for (const module of brandedModules) {
            const source = read(`resources/js/i18n/modules/${locale}/${module}.json`);

            assert.doesNotMatch(source, /malikia pulse/iu, `${locale}:${module}`);
        }

        const nav = readJson(`resources/js/i18n/modules/${locale}/nav.json`);
        const social = readJson(`resources/js/i18n/modules/${locale}/social.json`).social;

        assert.equal(nav.nav.social, 'Pulse', `${locale}:navigation`);
        assert.equal(social.workspace.eyebrow, 'Pulse', `${locale}:workspace`);
    }
});

test('visible backend and console copy consistently uses Pulse', () => {
    const runtimeCopyFiles = [
        'app/Http/Controllers/SuperAdmin/TenantController.php',
        'app/Http/Controllers/TeamMemberController.php',
        'app/Services/Social/SocialAccountConnectionService.php',
        'app/Services/Social/SocialAiCreativeService.php',
        'app/Services/Social/SocialAutomationRunnerService.php',
        'routes/console.php',
    ];

    for (const path of runtimeCopyFiles) {
        assert.doesNotMatch(read(path), /malikia pulse/iu, path);
    }
});

test('the calendar retries only server-approved failures and refreshes the visible payload', () => {
    assert.match(calendar, /const retryPost = async \(post\) => \{\s*if \(!post\?\.can_retry\)/u);
    assert.match(calendar, /axios\.post\(route\('social\.posts\.retry', post\.id\)\)/u);
    assert.match(calendar, /refreshFromPayload\(response\.data\)/u);
    assert.match(calendar, /v-if="activePost\.can_retry"/u);
    assert.match(calendar, /:disabled="busyPostId === activePost\.id \|\| isLoading"/u);
    assert.match(calendar, /retryingPostId === activePost\.id[\s\S]*?actions\.retrying_post/u);
    assert.match(calendar, /v-if="activePost\.failure_reason"[\s\S]*?role="status"[\s\S]*?aria-live="polite"/u);
    assert.match(calendar, /social\.delivery_axes\.failure_reason_label[\s\S]*?activePost\.failure_reason/u);
});

test('history and composer expose a guarded retry with accessible progress and result messages', () => {
    for (const [name, component] of Object.entries({ history, composer })) {
        assert.match(component, /axios\.post\(route\('social\.posts\.retry'/u, name);
        assert.match(component, /actions\.retrying_post/u, name);
        assert.match(component, /messages\.retry_success/u, name);
        assert.match(component, /messages\.retry_error/u, name);
        assert.match(component, /aria-live="assertive"/u, name);
        assert.match(component, /aria-live="polite"/u, name);
    }

    assert.match(history, /const retryPost = async \(post\) => \{\s*if \(!post\?\.can_retry\)/u);
    assert.match(history, /axios\.post\(route\('social\.posts\.retry', post\.id\)\);\s*await load\(\)/u);
    assert.match(history, /axios\.get\(route\('social\.history', buildParams\(\)\)\)/u);
    assert.doesNotMatch(
        history,
        /const retryPost = async \(post\)[\s\S]*?refreshFromPayload\(response\.data\)[\s\S]*?const resolveApproval/u,
    );
    assert.match(history, /v-if="post\.can_retry"[\s\S]*?:disabled="busy"/u);
    assert.match(composer, /refreshFromPayload\(response\.data\)/u);
    assert.match(composer, /const canRetryPublication = computed\(\(\) => Boolean\(draftSnapshot\.value\?\.can_retry\)\)/u);
    assert.match(composer, /const retryPublication = async \(\) => \{\s*if \(!canRetryPublication\.value\)/u);
    assert.match(composer, /v-if="canRetryPublication"[\s\S]*?:disabled="busy \|\| isLoading"/u);
    assert.match(composer, /!isFailedPublication/u);
    assert.match(composer, /v-if="draftSnapshot\?\.failure_reason"[\s\S]*?aria-live="polite"/u);
});

test('retry and failure reason copy exists in every locale', () => {
    for (const locale of ['fr', 'en', 'es']) {
        const social = readJson(`resources/js/i18n/modules/${locale}/social.json`).social;

        for (const manager of ['calendar_manager', 'composer_manager', 'history_manager']) {
            for (const action of ['retry_post', 'retrying_post']) {
                assert.equal(typeof social[manager].actions[action], 'string', `${locale}:${manager}:${action}`);
                assert.notEqual(social[manager].actions[action].trim(), '', `${locale}:${manager}:${action}`);
            }

            for (const message of ['retry_error', 'retry_success']) {
                assert.equal(typeof social[manager].messages[message], 'string', `${locale}:${manager}:${message}`);
                assert.notEqual(social[manager].messages[message].trim(), '', `${locale}:${manager}:${message}`);
            }
        }

        assert.equal(typeof social.delivery_axes.failure_reason_label, 'string', `${locale}:failure-reason`);
        assert.notEqual(social.delivery_axes.failure_reason_label.trim(), '', `${locale}:failure-reason`);
    }

    const french = readJson('resources/js/i18n/modules/fr/social.json').social;

    assert.equal(french.calendar_manager.actions.retry_post, 'Réessayer');
});
