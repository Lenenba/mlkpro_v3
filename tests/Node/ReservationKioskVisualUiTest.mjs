import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import test from 'node:test';

const source = readFileSync(resolve('resources/js/Pages/Public/ReservationKiosk.vue'), 'utf8');
const layoutSource = readFileSync(resolve('resources/js/Layouts/PublicKioskLayout.vue'), 'utf8');
const publicBrandBarSource = readFileSync(resolve('resources/js/Components/Public/PublicBrandBar.vue'), 'utf8');
const localeMessages = Object.fromEntries(['fr', 'en', 'es'].map((locale) => [
    locale,
    JSON.parse(readFileSync(resolve(`resources/js/i18n/modules/${locale}/reservations.json`), 'utf8')),
]));

test('the kiosk presents one premium two-panel guided workspace', () => {
    assert.match(source, /data-testid="reservation-kiosk-guided-shell"/u);
    assert.match(source, /<aside class="reservation-kiosk-portrait"[\s\S]*?data-testid="reservation-kiosk-brand-panel"/u);
    assert.match(source, /reservation-kiosk-portrait__image[\s\S]*?reservation-kiosk-portrait__scrim[\s\S]*?reservation-kiosk-brand-content/u);
    assert.match(source, /reservation-kiosk-brand-metrics/u);
    assert.match(source, /data-testid="reservation-kiosk-journey"/u);
    assert.match(source, /id="reservation-kiosk-welcome-title"/u);
    assert.match(source, /alt=""/u);
    assert.doesNotMatch(source, /sm:whitespace-nowrap/u);
});

test('the kiosk delegates its unframed tenant logo to the shared public brand bar', () => {
    const customLogoStyles = publicBrandBarSource.match(/\.public-brand-bar :deep\(\.company-brand-logo--custom\) \{([^}]*)\}/u)?.[1] || '';

    assert.match(layoutSource, /import PublicBrandBar from '@\/Components\/Public\/PublicBrandBar\.vue'/u);
    assert.match(layoutSource, /<PublicBrandBar[\s\S]*?:company="props\.company"[\s\S]*?<slot name="brand-actions" \/>/u);
    assert.match(source, /<PublicKioskLayout\b[^>]*:company="company"[^>]*logo-href=""/u);
    assert.doesNotMatch(source, /import CompanyBrandLogo/u);
    assert.doesNotMatch(source, /<CompanyBrandLogo/u);
    assert.match(publicBrandBarSource, /data-testid="public-brand-bar"/u);
    assert.match(publicBrandBarSource, /container-class="h-11 w-24 p-0 sm:h-12 sm:w-44"/u);
    assert.match(publicBrandBarSource, /logo-class="h-full w-auto max-w-full object-contain object-left"/u);
    assert.match(customLogoStyles, /justify-content: flex-start;/u);
    assert.match(customLogoStyles, /border: 0;/u);
    assert.match(customLogoStyles, /background-color: transparent;/u);
    assert.match(customLogoStyles, /background-image: none;/u);
    assert.match(customLogoStyles, /box-shadow: none;/u);
});

test('the kiosk renders its booking shortcut only when a public booking URL is available', () => {
    assert.match(source, /public_navigation:[\s\S]*?booking_url: null/u);
    assert.match(source, /const publicBookingHref = computed\(\(\) => String\(props\.public_navigation\?\.booking_url \|\| ''\)\.trim\(\)\);/u);
    assert.match(source, /<template #brand-actions>[\s\S]*?<nav[\s\S]*?v-if="publicBookingHref"/u);
    assert.match(source, /<a[\s\S]*?:href="publicBookingHref"[\s\S]*?data-testid="reservation-kiosk-booking-link"/u);
    assert.match(source, /:aria-label="t\('reservations\.public_navigation\.book'\)"/u);
    assert.match(source, /<span class="hidden sm:inline">\{\{ t\('reservations\.public_navigation\.book'\) \}\}<\/span>/u);
});

test('the kiosk begins with a neutral intent step and exposes accessible progress', () => {
    assert.match(source, /const activeMode = ref\(''\);/u);
    assert.match(source, /const currentJourneyStep = ref\(0\);/u);
    assert.match(source, /data-kiosk-stepper/u);
    assert.match(source, /:aria-current="currentJourneyStep === index \? 'step' : undefined"/u);
    assert.match(source, /data-testid="kiosk-intent-step"/u);
    assert.match(source, /:aria-pressed="activeMode === item\.key"/u);
    assert.match(source, /aria-controls="kiosk-journey-panel"/u);
    assert.match(source, /:disabled="!activeMode"/u);
    assert.match(source, /id="kiosk-journey-panel"[\s\S]*?data-kiosk-form/u);
    assert.match(source, /:aria-labelledby="currentJourneyHeadingId"/u);
    assert.match(source, /:class="\{ 'has-feedback': hasKioskFeedback \}"/u);
    assert.match(source, /role="alert"/u);
});

test('the kiosk remains scrollable and respects reduced motion', () => {
    const pageStyles = source.match(/\.reservation-kiosk-page \{([^}]*)\}/u)?.[1] || '';
    const shellStyles = source.match(/\.reservation-kiosk-shell \{([^}]*)\}/u)?.[1] || '';

    assert.match(layoutSource, /min-h-screen/u);
    assert.doesNotMatch(pageStyles, /100dvh/u);
    assert.doesNotMatch(pageStyles, /overflow: hidden;/u);
    assert.match(shellStyles, /overflow: visible;/u);
    assert.doesNotMatch(shellStyles, /overflow: hidden;/u);
    assert.match(source, /prefers-reduced-motion: reduce/u);
    assert.match(source, /scrollIntoView\(\{ behavior: 'auto'/u);
    assert.doesNotMatch(source, /(?:linear|radial)-gradient/u);
    assert.doesNotMatch(source, /^\s*transform:/mu);
    assert.doesNotMatch(source, /^\s*transition:/mu);
    assert.doesNotMatch(source, /behavior: 'smooth'/u);
});

test('the kiosk follows the compact flat visual language of public booking', () => {
    assert.match(source, /max-width: 1280px;/u);
    assert.match(source, /grid-template-columns: 420px minmax\(0, 1fr\);/u);
    assert.match(source, /grid-template-columns: repeat\(3, minmax\(0, 168px\)\);/u);
    assert.match(source, /grid-template-columns: repeat\(2, minmax\(0, 1fr\)\);/u);
    assert.match(source, /aspect-ratio: 1;/u);
    assert.match(source, /class="reservation-kiosk-action__selected"/u);
    assert.doesNotMatch(source, /ChevronRight/u);
    assert.doesNotMatch(source, /shadow-(?:md|lg|xl|2xl)/u);
});

test('the photo panel contains the wait metrics without clipping adjacent cards', () => {
    assert.match(source, /class="reservation-kiosk-metric__copy"/u);
    assert.match(source, /\.reservation-kiosk-brand-metrics \{[\s\S]*?grid-template-columns: minmax\(0, 1fr\) 70px 70px;/u);
    assert.match(source, /\.reservation-kiosk-metric \{[\s\S]*?min-width: 0;[\s\S]*?overflow: hidden;/u);
    assert.match(source, /\.reservation-kiosk-metric__copy \{[\s\S]*?min-width: 0;[\s\S]*?overflow: hidden;/u);
    assert.match(source, /\.reservation-kiosk-metric__value,[\s\S]*?white-space: nowrap;/u);
    assert.match(source, /\.reservation-kiosk-metric__value \{[\s\S]*?font-size: 22px;[\s\S]*?text-overflow: ellipsis;/u);
    assert.match(source, /\.reservation-kiosk-metric:not\(\.reservation-kiosk-metric--primary\) \{[\s\S]*?grid-template-rows: 24px 22px;/u);
    assert.match(source, /\.reservation-kiosk-metric:not\(\.reservation-kiosk-metric--primary\) \{[\s\S]*?display: none;/u);
});

test('the three intent cards reserve identical rows for their variable-length copy', () => {
    assert.match(source, /\.reservation-kiosk-action__copy \{[\s\S]*?display: grid;[\s\S]*?grid-template-rows: 11px 40px 32px;/u);
    assert.match(source, /\.reservation-kiosk-action__title \{[\s\S]*?min-height: 40px;[\s\S]*?max-height: 40px;[\s\S]*?-webkit-line-clamp: 2;/u);
    assert.match(source, /\.reservation-kiosk-action__subtitle \{[\s\S]*?min-height: 32px;[\s\S]*?max-height: 32px;[\s\S]*?-webkit-line-clamp: 2;/u);
    assert.match(source, /@media \(max-width: 639px\) \{[\s\S]*?\.reservation-kiosk-action__copy \{[\s\S]*?display: block;/u);
});

test('the main card fills the photo height and anchors its primary action', () => {
    assert.match(source, /\.reservation-kiosk-stage \{[\s\S]*?flex: 1 1 auto;[\s\S]*?align-items: stretch;/u);
    assert.match(source, /\.reservation-kiosk-step \{[\s\S]*?display: flex;[\s\S]*?flex-direction: column;/u);
    assert.match(source, /\.reservation-kiosk-step__footer--end \{[\s\S]*?margin-top: auto;/u);
});

test('the shared application footer finishes the kiosk outside its operational shell', () => {
    assert.match(source, /import PublicKioskLayout from '@\/Layouts\/PublicKioskLayout\.vue'/u);
    assert.match(source, /<PublicKioskLayout\b[^>]*>[\s\S]*?<main class="reservation-kiosk-page">[\s\S]*?<\/main>[\s\S]*?<\/PublicKioskLayout>/u);
    assert.doesNotMatch(source, /reservation-kiosk-footer/u);
    assert.doesNotMatch(source, /account\.branding\.powered_by/u);

    assert.match(layoutSource, /import AppFooter from '@\/Components\/UI\/AppFooter\.vue'/u);
    assert.match(layoutSource, /<slot \/>[\s\S]*?<AppFooter[\s\S]*?variant="powered-by"/u);
    assert.equal((layoutSource.match(/max-w-7xl/gu) || []).length, 2);
    assert.match(layoutSource, /mx-auto w-full max-w-7xl px-4 sm:px-6 lg:px-8/u);
    assert.match(layoutSource, /mx-auto w-full min-w-0 max-w-7xl px-4 pb-3 pt-4 sm:px-6 sm:pb-5 sm:pt-5 lg:px-8/u);
    assert.doesNotMatch(layoutSource, /max-w-\[1280px\]/u);
    assert.match(layoutSource, /overflow-x-hidden/u);
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

test('the kiosk renders server-driven decisions and a dedicated ticket result', () => {
    assert.match(source, /const nextAction = computed\(\(\) => String\(lookupResult\.value\?\.intent\?\.next_action/u);
    assert.match(source, /nextAction\.value === 'take_ticket'/u);
    assert.match(source, /nextAction\.value === 'track_ticket'/u);
    assert.match(source, /nextAction\.value === 'check_in'/u);
    assert.match(source, /data-testid="kiosk-result-step"/u);
    assert.match(source, /data-testid="kiosk-ticket-result"/u);
    assert.match(source, /v-if="hasNearbyReservation"/u);
    assert.match(source, /v-else-if="canCreateClientTicket"/u);
});

test('the kiosk clears personal data after the privacy timeout or a manual restart', () => {
    const resetBlock = source.match(/const resetKioskJourney = async \(\) => \{([\s\S]*?)\n\};/u)?.[1] || '';

    assert.match(source, /const KIOSK_PRIVACY_RESET_MS = 60_000;/u);
    assert.match(source, /window\.setTimeout\(resetKioskJourney, KIOSK_PRIVACY_RESET_MS\)/u);
    assert.match(source, /@input\.capture="schedulePrivacyReset"/u);
    assert.match(source, /@keydown\.capture="schedulePrivacyReset"/u);
    assert.match(source, /@pointerdown\.capture="schedulePrivacyReset"/u);
    assert.match(resetBlock, /activeMode\.value = '';/u);
    assert.match(resetBlock, /currentJourneyStep\.value = 0;/u);
    assert.match(resetBlock, /cancelKioskRequests\(\);/u);

    for (const formName of ['walkInForm', 'lookupForm', 'verifyForm', 'clientTicketForm', 'trackForm']) {
        assert.match(resetBlock, new RegExp(`${formName}\\.reset\\(\\);`, 'u'));
        assert.match(resetBlock, new RegExp(`${formName}\\.clearErrors\\(\\);`, 'u'));
    }
});

test('the kiosk prevents duplicate submissions and ignores responses received after a reset', () => {
    const abortSignalCount = [...source.matchAll(/signal: request\.controller\.signal/gu)].length;

    assert.equal(abortSignalCount, 6);
    assert.match(source, /const kioskRequests = new Set\(\);/u);
    assert.match(source, /controller: new AbortController\(\)/u);
    assert.match(source, /request\.revision === kioskJourneyRevision/u);
    assert.match(source, /kioskRequests\.forEach\(\(request\) => request\.controller\.abort\(\)\);/u);
    assert.match(source, /axios\.isCancel\(error\)/u);
    assert.doesNotMatch(source, /Form\.processing/u);

    for (const stateName of ['walkInProcessing', 'lookupProcessing', 'verifyProcessing', 'clientTicketProcessing', 'trackProcessing']) {
        assert.match(source, new RegExp(`const ${stateName} = ref\\(false\\);`, 'u'));
        assert.match(source, new RegExp(`:disabled="${stateName}"`, 'u'));
    }
});

test('the compact mobile controls retain their accessible name and reading order', () => {
    assert.match(source, /class="reservation-kiosk-tool-button"[\s\S]*?:aria-label="\$t\('reservations\.kiosk\.guided\.restart'\)"/u);
    assert.doesNotMatch(source, /flex-direction: column-reverse;/u);
});

test('the guided design no longer ships legacy kiosk layout selectors', () => {
    assert.doesNotMatch(source, /\.reservation-kiosk-(?:header|hero-grid|wait-card|form-panel|submit|continue)\b/u);
});

test('the guided kiosk copy remains complete in every supported locale', () => {
    const expectedKeys = Object.keys(localeMessages.fr.reservations.kiosk.guided).sort();

    assert.ok(expectedKeys.length > 0);
    assert.deepEqual(Object.keys(localeMessages.en.reservations.kiosk.guided).sort(), expectedKeys);
    assert.deepEqual(Object.keys(localeMessages.es.reservations.kiosk.guided).sort(), expectedKeys);
});

test('the kiosk uses the compact site-wide corner radius', () => {
    const radiusValues = [...source.matchAll(/border-radius: ([^;]+);/gu)]
        .map((match) => match[1]);

    assert.ok(radiusValues.length > 0);
    assert.ok(radiusValues.every((value) => value === '0.125rem'));
    assert.doesNotMatch(source, /rounded-(?:md|lg|xl|2xl|3xl|full)/u);
});
