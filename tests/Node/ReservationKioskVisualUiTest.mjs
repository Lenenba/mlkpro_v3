import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import test from 'node:test';

const source = readFileSync(resolve('resources/js/Pages/Public/ReservationKiosk.vue'), 'utf8');
const layoutSource = readFileSync(resolve('resources/js/Layouts/PublicKioskLayout.vue'), 'utf8');

test('the kiosk welcome and portrait form one premium visual panel', () => {
    assert.match(source, /<section class="reservation-kiosk-portrait" aria-labelledby="reservation-kiosk-welcome-title">[\s\S]*?reservation-kiosk-portrait__image[\s\S]*?reservation-kiosk-portrait__scrim[\s\S]*?reservation-kiosk-intro[\s\S]*?<\/section>/u);
    assert.match(source, /id="reservation-kiosk-welcome-title"/u);
    assert.match(source, /alt=""/u);
    assert.doesNotMatch(source, /sm:whitespace-nowrap/u);
});

test('the kiosk choices expose their selected state and controlled form', () => {
    assert.match(source, /:aria-pressed="activeMode === item\.key"/u);
    assert.match(source, /aria-controls="kiosk-form-panel"/u);
    assert.match(source, /id="kiosk-form-panel"[\s\S]*?data-kiosk-form/u);
    assert.match(source, /:class="\{ 'mt-4': hasKioskFeedback \}"[^>]*aria-live="polite"/u);
    assert.match(source, /role="alert"/u);
});

test('the kiosk remains scrollable and respects reduced motion', () => {
    const pageStyles = source.match(/\.reservation-kiosk-page \{([^}]*)\}/u)?.[1] || '';

    assert.match(layoutSource, /min-h-screen/u);
    assert.doesNotMatch(pageStyles, /100dvh/u);
    assert.doesNotMatch(pageStyles, /overflow: hidden;/u);
    assert.match(source, /prefers-reduced-motion: reduce/u);
    assert.match(source, /window\.matchMedia\('\(prefers-reduced-motion: reduce\)'\)/u);
});

test('the shared application footer finishes the kiosk outside its operational shell', () => {
    assert.match(source, /import PublicKioskLayout from '@\/Layouts\/PublicKioskLayout\.vue'/u);
    assert.match(source, /<PublicKioskLayout>[\s\S]*?<main class="reservation-kiosk-page">[\s\S]*?<\/main>[\s\S]*?<\/PublicKioskLayout>/u);
    assert.doesNotMatch(source, /reservation-kiosk-footer/u);
    assert.doesNotMatch(source, /account\.branding\.powered_by/u);

    assert.match(layoutSource, /import AppFooter from '@\/Components\/UI\/AppFooter\.vue'/u);
    assert.match(layoutSource, /<slot \/>[\s\S]*?<AppFooter[\s\S]*?variant="powered-by"/u);
    assert.match(layoutSource, /import CookieBanner from '@\/Components\/UI\/CookieBanner\.vue'/u);
    assert.match(layoutSource, /<CookieBanner \/>/u);
});

test('the visual redesign preserves all three kiosk journeys', () => {
    const activeFormCount = [...source.matchAll(/<form\b[^>]*data-kiosk-active-form/gu)].length;

    assert.equal(activeFormCount, 3);
    assert.match(source, /@submit\.prevent="submitWalkIn"/u);
    assert.match(source, /@submit\.prevent="lookupClient"/u);
    assert.match(source, /@submit\.prevent="trackTicket"/u);
});

test('the kiosk uses the compact site-wide corner radius', () => {
    const radiusValues = [...source.matchAll(/border-radius: ([^;]+);/gu)]
        .map((match) => match[1]);
    const nonDotRadiusValues = radiusValues.filter((value) => value !== '999px');

    assert.ok(nonDotRadiusValues.length > 0);
    assert.equal(radiusValues.filter((value) => value === '999px').length, 1);
    assert.ok(nonDotRadiusValues.every((value) => value === '0.125rem'));
    assert.doesNotMatch(source, /rounded-(?:md|lg|xl|2xl|3xl|full)/u);
});
