import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import test from 'node:test';

import { getDomainsForPage } from '../../resources/js/i18n/domains.js';

const read = (path) => readFileSync(resolve(path), 'utf8');
const readJson = (path) => JSON.parse(read(path));
const valueAt = (messages, key) => key
    .split('.')
    .reduce((value, segment) => value?.[segment], messages);

test('onboarding and company settings localize their currency controls', () => {
    const onboardingSource = read('resources/js/Pages/Onboarding/Index.vue');
    const settingsSource = read('resources/js/Pages/Settings/Company.vue');

    assert.match(onboardingSource, /\$t\('onboarding\.company\.currency_label'\)/);
    assert.match(onboardingSource, /\$t\('onboarding\.company\.currency_hint', \{ currency: selectedCurrencyCode \}\)/);
    assert.doesNotMatch(onboardingSource, /Main business currency/);
    assert.doesNotMatch(onboardingSource, /Products, services, invoices, and Stripe online charges will use/);

    assert.match(settingsSource, /\$t\('settings\.company\.currency\.label'\)/);
    assert.match(settingsSource, /\$t\('settings\.company\.currency\.change_hint'\)/);
    assert.match(settingsSource, /\$t\('settings\.company\.currency\.locked_hint'\)/);
    assert.doesNotMatch(settingsSource, /Main business currency/);
    assert.doesNotMatch(settingsSource, /Currency changes are locked because business activity already exists/);
});

test('currency labels and hints exist in every supported interface language', () => {
    const requiredKeysByDomain = {
        onboarding: [
            'onboarding.company.currency_label',
            'onboarding.company.currency_hint',
        ],
        settings: [
            'settings.company.currency.label',
            'settings.company.currency.change_hint',
            'settings.company.currency.locked_hint',
        ],
    };

    for (const locale of ['fr', 'en', 'es']) {
        for (const [domain, requiredKeys] of Object.entries(requiredKeysByDomain)) {
            const messages = readJson(`resources/js/i18n/modules/${locale}/${domain}.json`);

            for (const key of requiredKeys) {
                const value = valueAt(messages, key);

                assert.equal(typeof value, 'string', `${locale}:${key}`);
                assert.notEqual(value.trim(), '', `${locale}:${key}`);
            }
        }
    }
});

test('the shared dropzone uses translated copy on every page shell', () => {
    const source = read('resources/js/Components/DropzoneInput.vue');
    const requiredKeys = [
        'dropzone.upload_image',
        'dropzone.image_preview',
        'dropzone.current_image',
        'dropzone.preview_alt',
        'dropzone.replace',
        'dropzone.remove',
        'dropzone.attached_hint',
        'dropzone.drop_here',
        'dropzone.browse',
        'dropzone.optimization_hint',
        'dropzone.errors.svg_not_allowed',
        'dropzone.errors.unsupported_format',
        'dropzone.errors.processing_failed',
        'dropzone.errors.too_large',
        'dropzone.errors.video_too_large',
        'dropzone.errors.document_too_large',
    ];
    const dynamicallyResolvedErrorKeys = new Set([
        'dropzone.errors.too_large',
        'dropzone.errors.video_too_large',
        'dropzone.errors.document_too_large',
    ]);

    for (const key of requiredKeys) {
        const sourceKey = dynamicallyResolvedErrorKeys.has(key)
            ? key.replace('dropzone.errors.', '')
            : key;

        assert.equal(source.includes(`'${sourceKey}'`), true, key);
    }

    assert.match(source, /const resolvedLabel = computed\(\(\) => props\.label \|\| t\(/u);
    assert.match(source, /const resolvedHint = computed\(\(\) => t\(/u);

    assert.doesNotMatch(source, />\s*Drop your file here or\s*</);
    assert.doesNotMatch(source, />\s*Large images are optimized automatically\.\s*</);
    assert.doesNotMatch(source, />\s*Replace\s*</);
    assert.doesNotMatch(source, />\s*Remove\s*</);

    for (const page of ['Onboarding/Index', 'Settings/Company', 'Product/Create']) {
        assert.equal(getDomainsForPage(page).includes('shared_ui'), true, page);
    }

    for (const locale of ['fr', 'en', 'es']) {
        const messages = readJson(`resources/js/i18n/modules/${locale}/shared_ui.json`);

        for (const key of requiredKeys) {
            const value = valueAt(messages, key);

            assert.equal(typeof value, 'string', `${locale}:${key}`);
            assert.notEqual(value.trim(), '', `${locale}:${key}`);
        }
    }
});
