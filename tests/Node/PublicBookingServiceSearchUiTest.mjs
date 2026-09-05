import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import test from 'node:test';

const source = readFileSync(resolve('resources/js/Pages/Public/PublicBooking.vue'), 'utf8');

test('public booking starts without a selected service and keeps step one compact', () => {
    assert.match(source, /const selectedServiceId = ref\(''\);/u);
    assert.match(source, /const serviceOptions = computed\(\(\) => \(props\.services \|\| \[\]\)\.map\(\(service\) => \(\{[\s\S]*?value: String\(service\.id\),[\s\S]*?search: \[service\.name, service\.description\]/u);
    assert.match(source, /<FloatingSelect[\s\S]*?id="public-booking-service-search"[\s\S]*?:model-value="selectedServiceId"[\s\S]*?:options="serviceOptions"[\s\S]*?filterable[\s\S]*?select-on-focus[\s\S]*?required[\s\S]*?@update:model-value="selectServiceById"/u);
    assert.match(source, /aria-describedby="public-booking-service-search-hint"/u);
    assert.match(source, /data-testid="public-booking-selected-service"[\s\S]*?aria-live="polite"|aria-live="polite"[\s\S]*?data-testid="public-booking-selected-service"/u);
    assert.match(source, /<div v-if="selectedService" class="text-xs text-stone-500">\{\{ durationLabel\(durationMinutes\) \}\}<\/div>/u);
    assert.match(source, /currentStepKey === 'service' \? 'min-h-0' : 'min-h-\[560px\]'/u);
    assert.doesNotMatch(source, /v-for="service in services"/u);
});

test('public booking service suggestions preserve selection side effects', () => {
    assert.match(source, /const selectServiceById = \(serviceId\) => \{[\s\S]*?\.find\(\(candidate\) => String\(candidate\.id\) === String\(serviceId \|\| ''\)\);[\s\S]*?selectService\(service \|\| null\);[\s\S]*?\};/u);
    assert.match(source, /const selectService = \(service\) => \{[\s\S]*?selectedServiceId\.value = service\?\.id \? String\(service\.id\) : '';[\s\S]*?serviceWasChosen\.value = Boolean\(service\);[\s\S]*?selectedDate\.value = '';[\s\S]*?selectedTime\.value = '';[\s\S]*?selectedTeamMemberId\.value = 'auto';[\s\S]*?monthAvailableDates\.value = \[\];/u);
    assert.match(source, /const resetBooking = \(\) => \{[\s\S]*?selectedServiceId\.value = '';/u);
    assert.match(source, /if \(!selectedService\.value\) \{[\s\S]*?stepError\.value = 'Selectionnez un service pour continuer\.'/u);
});

test('public booking shows the selected service image with an accessible fallback', () => {
    assert.match(source, /const selectedServiceImageFailed = ref\(false\);/u);
    assert.match(source, /const selectService = \(service\) => \{[\s\S]*?selectedServiceImageFailed\.value = false;/u);
    assert.match(source, /v-if="selectedService\.has_image && selectedService\.image_url && !selectedServiceImageFailed"[\s\S]*?:src="selectedService\.image_url"[\s\S]*?alt=""[\s\S]*?data-testid="public-booking-selected-service-image"[\s\S]*?@error="selectedServiceImageFailed = true"/u);
    assert.match(source, /v-if="selectedService\?\.has_image && selectedService\.image_url && !selectedServiceImageFailed"[\s\S]*?:src="selectedService\.image_url"[\s\S]*?<Sparkles v-else/u);
});
