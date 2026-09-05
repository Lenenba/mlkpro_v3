import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import test from 'node:test';

const read = (path) => readFileSync(resolve(path), 'utf8');
const settingsLayout = read('resources/js/Layouts/SettingsLayout.vue');

const sourceBetween = (start, end, from = 0) => {
    const startIndex = settingsLayout.indexOf(start, from);
    const endIndex = settingsLayout.indexOf(end, startIndex + start.length);

    assert.notEqual(startIndex, -1, `Missing source marker: ${start}`);
    assert.notEqual(endIndex, -1, `Missing source marker: ${end}`);

    return settingsLayout.slice(startIndex, endIndex);
};

test('client settings navigation resolves only the profile route before internal groups', () => {
    assert.match(
        settingsLayout,
        /const isClient = computed\(\(\) => Boolean\(page\.props\.auth\?\.account\?\.is_client\)\)/u,
    );

    const navStart = settingsLayout.indexOf('const navTabs = computed');
    const clientBranch = sourceBetween('if (isClient.value) {', 'const groups = [', navStart);

    assert.match(clientBranch, /id: 'profile'/u);
    assert.match(clientBranch, /label: t\('account\.profile'\)/u);
    assert.match(clientBranch, /href: route\('profile\.edit'\)/u);
    assert.doesNotMatch(clientBranch, /route\('(?:settings\.|workspace\.hubs\.show)/u);
});

test('client breadcrumbs stop after dashboard and profile before resolving workspace routes', () => {
    const breadcrumbStart = settingsLayout.indexOf('const breadcrumbItems = computed');
    const clientBranch = sourceBetween(
        'if (isClient.value) {',
        'items.push(',
        breadcrumbStart,
    );

    assert.match(clientBranch, /return \[\s*\.\.\.items,/u);
    assert.match(clientBranch, /key: 'profile'/u);
    assert.match(clientBranch, /label: t\('account\.profile'\)/u);
    assert.doesNotMatch(clientBranch, /href:/u);
    assert.doesNotMatch(clientBranch, /settings\.|workspace\.hubs\.show/u);

    const internalBreadcrumbs = settingsLayout.slice(
        settingsLayout.indexOf('items.push(', breadcrumbStart),
        settingsLayout.indexOf('const openCookiePreferences'),
    );

    assert.match(internalBreadcrumbs, /route\('workspace\.hubs\.show'/u);
    assert.match(internalBreadcrumbs, /supportTicketBreadcrumb\.value/u);
});

test('non-client settings keep their existing permission-filtered route construction', () => {
    const navStart = settingsLayout.indexOf('const navTabs = computed');
    const internalNavigation = sourceBetween('const groups = [', 'const activeNavItem', navStart);

    assert.match(internalNavigation, /ownerOnly: true/u);
    assert.match(internalNavigation, /hidden: !canManageRoles\.value/u);
    assert.match(internalNavigation, /items: group\.items\.filter/u);
    assert.match(internalNavigation, /href: item\.route \? route\(item\.route\) : null/u);
});

test('profile sections use the shared account catalogue and announce save progress', () => {
    const files = [
        'resources/js/Pages/Profile/Edit.vue',
        'resources/js/Pages/Profile/Partials/UpdateProfileInformationForm.vue',
        'resources/js/Pages/Profile/Partials/UpdatePasswordForm.vue',
        'resources/js/Pages/Profile/Partials/DeleteUserForm.vue',
    ];
    const sources = files.map(read);

    for (const source of sources) {
        assert.match(source, /useI18n/u);
        assert.match(source, /account\.profile_page/u);
    }

    const combined = sources.join('\n');
    assert.doesNotMatch(combined, />\s*(?:Profile Information|Update Password|Delete Account|Save|Saved\.|Cancel)\s*</u);
    assert.match(combined, /:aria-busy="form\.processing"/u);
    assert.match(combined, /role="status"[\s\S]*?aria-live="polite"/u);
});

test('profile and portal shell translations have matching keys in every supported locale', () => {
    const profileKeys = (locale) => Object.keys(
        JSON.parse(read(`resources/js/i18n/modules/${locale}/account.json`)).account.profile_page,
    ).sort();
    const reference = profileKeys('fr');

    assert.deepEqual(profileKeys('en'), reference);
    assert.deepEqual(profileKeys('es'), reference);

    for (const locale of ['fr', 'en', 'es']) {
        const profile = JSON.parse(read(`resources/js/i18n/modules/${locale}/account.json`)).account.profile_page;

        assert.equal(Object.keys(profile.tabs).length, 6);
        assert.equal(Object.keys(profile.information).length, 14);
        assert.equal(Object.keys(profile.password).length, 5);
        assert.equal(Object.keys(profile.deletion).length, 5);
        assert.equal(Object.keys(profile.actions).length, 6);
    }
});
