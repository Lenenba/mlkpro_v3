import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import test from 'node:test';

const read = (path) => readFileSync(resolve(path), 'utf8');

const layout = read('resources/js/Layouts/AuthenticatedLayout.vue');
const settingsLayout = read('resources/js/Layouts/SettingsLayout.vue');
const tabs = read('resources/js/Components/Portal/ClientPortalTabs.vue');
const notice = read('resources/js/Components/Portal/ClientPortalNotice.vue');
const dashboard = read('resources/js/Pages/DashboardClient.vue');
const profile = read('resources/js/Pages/Profile/Edit.vue');
const profilePartials = [
    read('resources/js/Pages/Profile/Partials/UpdateProfileInformationForm.vue'),
    read('resources/js/Pages/Profile/Partials/UpdatePasswordForm.vue'),
    read('resources/js/Pages/Profile/Partials/DeleteUserForm.vue'),
];

test('the client shell keeps consistent gutters and a bounded content width at every breakpoint', () => {
    assert.match(layout, /isClient\s*\?\s*'w-full lg:ml-16 lg:w-auto'\s*:\s*'w-full lg:ps-16'/u);
    assert.match(layout, /isClient\s*\?\s*'mx-auto w-full max-w-7xl p-2 sm:p-5'/u);
    assert.match(layout, /:\s*'p-2 sm:p-5 sm:py-0 md:pt-5'/u);
    assert.match(layout, /:class="isClient \? 'mx-auto max-w-7xl' : null"/u);
    assert.match(layout, /<AppFooter[\s\S]*?:class="isClient \? '!rounded-sm' : null"/u);
    assert.match(layout, /<div v-if="isClient" class="w-full min-w-0">[\s\S]*?<slot \/>[\s\S]*?<slot v-else \/>/u);
    assert.doesNotMatch(layout, /class="[^"]*p-2 sm:p-5 sm:py-0 md:pt-5"/u);
});

test('the hybrid client dashboard can shrink inside the client shell from the small breakpoint', () => {
    assert.match(dashboard, /w-full min-w-0 max-w-full space-y-4 sm:space-y-6/u);
    assert.match(dashboard, /grid-class="grid-cols-1 sm:grid-cols-2 xl:grid-cols-4"/u);
    assert.doesNotMatch(dashboard, /<div class="space-y-6">/u);
    assert.equal(dashboard.match(/\[&>\*\]:!rounded-sm/gu)?.length, 2);
});

test('shared client portal surfaces use small structural corners and keep only semantic pills round', () => {
    const sharedSurfaces = [tabs, notice, dashboard, profile, settingsLayout, ...profilePartials];

    assert.match(tabs, /w-full min-w-0 max-w-full rounded-sm border/u);
    assert.match(tabs, /group min-w-0 rounded-sm border/u);
    assert.match(tabs, /shrink-0 rounded-full border/u);
    assert.match(notice, /w-full min-w-0 max-w-full break-words rounded-sm border/u);
    assert.match(profilePartials[0], /avatarIconPresets[\s\S]*?rounded-full border/u);

    for (const surface of sharedSurfaces) {
        assert.doesNotMatch(surface, /rounded-(?:\[[^\]]+\]|(?:md|lg|xl|2xl|3xl)\b)/u);
    }
});

test('client profile delegates maximum width and gutters to the authenticated shell', () => {
    assert.match(settingsLayout, /isClient\.value\s*\? 'w-full min-w-0 max-w-full'\s*: props\.contentClass/u);
    assert.match(settingsLayout, /settings-shell w-full min-w-0 max-w-full/u);
    assert.match(settingsLayout, /'settings-hero__inner--client': isClient/u);
    assert.match(settingsLayout, /'settings-main--client': isClient/u);
    assert.match(settingsLayout, /\.settings-hero__inner--client,\s*\.settings-main--client\s*\{\s*padding-inline: 0;/u);
    assert.match(profile, /<SettingsLayout active="profile">[\s\S]*?w-full min-w-0 max-w-full space-y-5/u);
    assert.doesNotMatch(profile, /content-class="w-\[1400px\]/u);
});

test('client portal navigation uses the small breakpoint without creating narrow tab labels', () => {
    assert.match(tabs, /props\.columns <= 1[\s\S]*?return 'grid-cols-1'/u);
    assert.match(tabs, /grid-cols-1 sm:grid-cols-2 xl:grid-cols-4/u);
    assert.match(tabs, /grid-cols-1 sm:grid-cols-2 lg:grid-cols-3/u);
    assert.match(tabs, /grid-cols-1 sm:grid-cols-2/u);
    assert.match(tabs, /w-full min-w-0 max-w-full/u);
    assert.match(tabs, /min-w-0 break-words/u);
    assert.doesNotMatch(tabs, /grid-cols-1 md:grid-cols-2/u);
});

test('client portal links expose navigation semantics while local switches retain tab semantics', () => {
    assert.match(tabs, /const isNavigation = computed/u);
    assert.match(tabs, /:is="isNavigation \? 'nav' : 'div'"/u);
    assert.match(tabs, /:role="isNavigation \? undefined : 'tablist'"/u);
    assert.match(tabs, /:aria-label="isNavigation \? undefined : ariaLabel"/u);
    assert.match(tabs, /:role="isNavigation \? undefined : 'tab'"/u);
    assert.match(tabs, /:aria-selected="isNavigation \? undefined : isActive\(tab\)"/u);
    assert.match(tabs, /:aria-current="isNavigation && isActive\(tab\) \? 'page' : undefined"/u);
});
