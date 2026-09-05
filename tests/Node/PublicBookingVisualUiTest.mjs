import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import test from 'node:test';

const bookingSource = readFileSync(resolve('resources/js/Pages/Public/PublicBooking.vue'), 'utf8');
const kioskSource = readFileSync(resolve('resources/js/Pages/Public/ReservationKiosk.vue'), 'utf8');

test('public booking uses the same configured image and fallback as the kiosk', () => {
    const fallbackImageUrl = '/images/landing/stock/salon-front-desk.jpg';

    assert.match(bookingSource, /props\.settings\?\.kiosk_image_url/u);
    assert.match(bookingSource, /const bookingHeroImageFailed = ref\(false\);/u);
    assert.ok(bookingSource.includes(fallbackImageUrl));
    assert.ok(kioskSource.includes(fallbackImageUrl));
    assert.match(bookingSource, /data-testid="public-booking-hero-image"/u);
    assert.match(bookingSource, /:src="bookingHeroImageUrl"[\s\S]*?alt=""[\s\S]*?aria-hidden="true"/u);
    assert.match(bookingSource, /@error="bookingHeroImageFailed = true"/u);
});

test('public booking image panel is flat, cropped and responsive', () => {
    assert.match(bookingSource, /grid overflow-hidden rounded-sm border border-stone-200 bg-white shadow-sm md:min-h-44 md:grid-cols-\[minmax\(15rem,19rem\)_minmax\(0,1fr\)\]/u);
    assert.match(bookingSource, /relative h-40 overflow-hidden border-b border-stone-200 bg-stone-100 sm:h-44 md:h-auto md:min-h-44 md:border-b-0 md:border-r/u);
    assert.match(bookingSource, /absolute inset-0 size-full object-cover object-center/u);
    assert.doesNotMatch(bookingSource, /(?:linear|radial)-gradient/u);
});
