import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import test from 'node:test';

import { getDomainsForPage } from '../../resources/js/i18n/domains.js';

const read = (path) => readFileSync(resolve(path), 'utf8');
const occurrences = (value, needle) => value.split(needle).length - 1;

const sourceFiles = (directory) => readdirSync(resolve(directory), { withFileTypes: true })
    .flatMap((entry) => {
        const path = `${directory}/${entry.name}`;

        if (entry.isDirectory()) {
            return sourceFiles(path);
        }

        return entry.name.endsWith('.vue') ? [path] : [];
    });

test('the compact application footer keeps one semantic and accessible brand surface', () => {
    const footer = read('resources/js/Components/UI/AppFooter.vue');

    assert.match(footer, /<footer\b/);
    assert.match(footer, /data-testid="app-footer"/);
    assert.match(footer, /account\.branding\.footer\.aria_label/);
    assert.match(footer, /account\.branding\.footer\.copyright/);
    assert.match(footer, /account\.branding\.powered_by/);
    assert.match(footer, /variant:[\s\S]*?default: 'platform'/);
    assert.match(footer, /powered-by/);
    assert.match(footer, /mlk-cookie-preferences/);
    assert.match(footer, /floatingActionReserve/);
    assert.match(footer, /compact: 'pe-14'/);
    assert.match(footer, /wide: 'pe-32'/);
    assert.match(footer, /<a[\s\S]*?:href="brandHref"/);
    assert.match(footer, /<a[\s\S]*?v-for="link in legalLinks"/);
});

test('application shells own one footer while nested and global shells avoid duplicates', () => {
    const authenticated = read('resources/js/Layouts/AuthenticatedLayout.vue');
    const guest = read('resources/js/Layouts/GuestLayout.vue');
    const publicKiosk = read('resources/js/Layouts/PublicKioskLayout.vue');
    const settings = read('resources/js/Layouts/SettingsLayout.vue');
    const app = read('resources/js/app.js');

    for (const [name, layout] of [
        ['authenticated', authenticated],
        ['guest', guest],
    ]) {
        assert.match(layout, /import AppFooter from '@\/Components\/UI\/AppFooter\.vue'/, name);
        assert.equal(occurrences(layout, '<AppFooter'), 1, name);
        assert.match(layout, /showFooter:[\s\S]*?type: Boolean[\s\S]*?default: true/, name);
        assert.match(layout, name === 'authenticated' ? /v-if="props\.showFooter"/ : /v-if="shouldShowFooter"/, name);
    }

    assert.match(authenticated, /:variant="isClient \? 'powered-by' : 'platform'"/);
    assert.match(guest, /:variant="showTenantAttribution \? 'powered-by' : 'platform'"/);
    assert.match(publicKiosk, /import AppFooter from '@\/Components\/UI\/AppFooter\.vue'/);
    assert.equal(occurrences(publicKiosk, '<AppFooter'), 1);
    assert.match(publicKiosk, /variant="powered-by"/);
    assert.match(publicKiosk, /<CookieBanner \/>/);
    assert.doesNotMatch(authenticated, /account\.branding\.powered_by/);
    assert.doesNotMatch(guest, /account\.branding\.powered_by/);
    assert.doesNotMatch(publicKiosk, /account\.branding\.powered_by/);

    assert.equal(occurrences(settings, '<AppFooter'), 0);
    assert.match(settings, /<AuthenticatedLayout>/);
    assert.doesNotMatch(app, /AppFooter/);

    const reservationScreen = read('resources/js/Pages/Reservation/Screen.vue');
    assert.match(reservationScreen, /<AuthenticatedLayout :show-footer="false">/);

    const directPageConsumers = sourceFiles('resources/js/Pages')
        .filter((path) => read(path).includes('AppFooter'));

    assert.deepEqual(directPageConsumers, []);
});

test('public brand bars, page content and footers share the max-w-7xl responsive gutters', () => {
    const guest = read('resources/js/Layouts/GuestLayout.vue');
    const publicKiosk = read('resources/js/Layouts/PublicKioskLayout.vue');
    const booking = read('resources/js/Pages/Public/PublicBooking.vue');
    const kiosk = read('resources/js/Pages/Public/ReservationKiosk.vue');

    assert.match(guest, /<header v-if="props\.brandBar"[\s\S]*?<div class="mx-auto w-full max-w-7xl px-4 sm:px-6 lg:px-8">[\s\S]*?<PublicBrandBar/);
    assert.match(guest, /\? 'mx-auto w-full max-w-7xl px-4 pb-3 pt-5 sm:px-6 sm:pb-5 lg:px-8'/);
    assert.equal(occurrences(guest, 'max-w-7xl'), 2);

    assert.match(publicKiosk, /<header[\s\S]*?<div class="mx-auto w-full max-w-7xl px-4 sm:px-6 lg:px-8">[\s\S]*?<PublicBrandBar/);
    assert.match(publicKiosk, /<div class="mx-auto w-full min-w-0 max-w-7xl px-4 pb-3 pt-4 sm:px-6 sm:pb-5 sm:pt-5 lg:px-8">[\s\S]*?<AppFooter/);
    assert.equal(occurrences(publicKiosk, 'max-w-7xl'), 2);

    assert.match(booking, /class="mx-auto flex w-full max-w-7xl flex-col gap-5 px-4 py-5 sm:px-6 lg:px-8"/);
    assert.match(kiosk, /class="mx-auto w-full max-w-7xl px-4 py-4 sm:px-6 sm:py-5 lg:px-8"/);
    assert.doesNotMatch(guest, /max-w-\[1280px\]/);
    assert.doesNotMatch(publicKiosk, /max-w-\[1280px\]/);
});

test('large guest experiences let GuestLayout own viewport height', () => {
    for (const path of [
        'resources/js/Pages/Public/PublicBooking.vue',
        'resources/js/Pages/Public/AiAssistantChat.vue',
    ]) {
        const source = read(path);

        assert.match(source, /<GuestLayout/);
        assert.doesNotMatch(source, /<div class="min-h-screen/, path);
    }
});

test('full public pages retain their richer footer instead of receiving the compact shell footer', () => {
    const publicFooterPages = [
        'resources/js/Pages/Welcome.vue',
        'resources/js/Pages/Pricing.vue',
        'resources/js/Pages/Privacy.vue',
        'resources/js/Pages/Refund.vue',
        'resources/js/Pages/Terms.vue',
        'resources/js/Pages/Public/Page.vue',
    ];

    for (const path of publicFooterPages) {
        const source = read(path);

        assert.match(source, /import PublicFooterMenu/, path);
        assert.equal(occurrences(source, '<PublicFooterMenu'), 1, path);
        assert.doesNotMatch(source, /AppFooter/, path);
    }
});

test('footer copy is complete in every locale and its domain is loaded by every shell', () => {
    const requiredKeys = [
        'aria_label',
        'navigation_aria_label',
        'copyright',
        'support',
        'privacy',
        'terms',
        'cookie_preferences',
    ];

    for (const locale of ['fr', 'en', 'es']) {
        const messages = JSON.parse(read(`resources/js/i18n/modules/${locale}/account.json`));
        const branding = messages.account?.branding;

        assert.equal(typeof branding?.powered_by, 'string', `${locale}:powered_by`);
        assert.notEqual(branding.powered_by.trim(), '', `${locale}:powered_by`);

        for (const key of requiredKeys) {
            const value = branding?.footer?.[key];

            assert.equal(typeof value, 'string', `${locale}:${key}`);
            assert.notEqual(value.trim(), '', `${locale}:${key}`);
        }

        assert.match(branding.footer.copyright, /\{year\}/, `${locale}:copyright`);
    }

    for (const page of ['Dashboard', 'Auth/Login', 'Public/InvoicePay']) {
        assert.equal(getDomainsForPage(page).includes('account'), true, page);
    }
});

test('cookie preferences use an accessible modal lifecycle', () => {
    const cookieBanner = read('resources/js/Components/UI/CookieBanner.vue');

    assert.match(cookieBanner, /role="dialog"/);
    assert.match(cookieBanner, /aria-modal="true"/);
    assert.match(cookieBanner, /aria-labelledby="cookie-preferences-title"/);
    assert.match(cookieBanner, /aria-describedby="cookie-preferences-description"/);
    assert.match(cookieBanner, /event\.key === 'Escape'/);
    assert.match(cookieBanner, /event\.key !== 'Tab'/);
    assert.match(cookieBanner, /restorePreferencesFocus/);
    assert.match(cookieBanner, /preferencesDialogRef\.value\?\.querySelector\(focusableSelector\)/);
});
