import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import test from 'node:test';

import {
    createAnnouncementTranslations,
    normalizeAnnouncementTranslations,
} from '../../resources/js/Pages/SuperAdmin/Announcements/announcementForm.js';

const read = (path) => readFileSync(resolve(path), 'utf8');

test('the announcement modal uses the shared floating form controls', () => {
    const source = read('resources/js/Pages/SuperAdmin/Announcements/Index.vue');
    const modal = source.slice(
        source.indexOf('<Modal :show="showForm"'),
        source.indexOf('</Modal>') + '</Modal>'.length,
    );

    assert.match(modal, /<FloatingInput/);
    assert.match(modal, /<FloatingTextarea/);
    assert.match(modal, /<FloatingSelect/);
    assert.match(modal, /<FloatingFileInput/);
    assert.match(modal, /<DatePicker/);
    assert.match(modal, /<Checkbox/);
    assert.doesNotMatch(modal, /<textarea\b/);
    assert.doesNotMatch(modal, /<select\b/);

    const nativeInputs = [...modal.matchAll(/<input\b[\s\S]*?>/g)].map(([input]) => input);
    assert.equal(nativeInputs.length, 1);
    assert.match(nativeInputs[0], /type="color"/);
});

test('the announcement editor retains separate French, English, and Spanish drafts', () => {
    const source = read('resources/js/Pages/SuperAdmin/Announcements/Index.vue');

    assert.match(source, /translations: createEmptyTranslations\(\)/);
    assert.match(source, /form\.translations\?\.\[editorLocale\.value\]/);
    assert.match(source, /translations\.\$\{editorLocale\.value\}\.\$\{field\}/);
    assert.match(source, /delete payload\.title/);
    assert.match(source, /delete payload\.body/);
    assert.match(source, /delete payload\.link_label/);
    assert.match(source, /key\.startsWith\(`translations\.\$\{locale\}\.\`\)/);
    assert.doesNotMatch(source, /JSON\.stringify\(item\.translations/);
});

test('translation normalization materializes only missing field fallbacks in French', () => {
    const legacyOnly = normalizeAnnouncementTranslations({
        locales: ['fr', 'en', 'es'],
        defaultLocale: 'fr',
        legacy: {
            title: 'Ancienne annonce',
            body: 'Ancien message',
        },
    });

    assert.equal(legacyOnly.fr.title, 'Ancienne annonce');
    assert.equal(legacyOnly.fr.body, 'Ancien message');

    const englishOnly = normalizeAnnouncementTranslations({
        locales: ['fr', 'en', 'es'],
        defaultLocale: 'fr',
        translations: {
            en: { title: 'English only' },
        },
        legacy: {
            title: 'English only',
        },
    });

    assert.deepEqual(englishOnly.fr, createAnnouncementTranslations(['fr']).fr);
    assert.equal(englishOnly.en.title, 'English only');

    const mixedContent = normalizeAnnouncementTranslations({
        locales: ['fr', 'en', 'es'],
        defaultLocale: 'fr',
        translations: {
            en: { title: 'Translated title' },
        },
        legacy: {
            title: 'Legacy title',
            body: 'Legacy body',
            link_label: 'Legacy link',
        },
    });

    assert.equal(mixedContent.fr.title, '');
    assert.equal(mixedContent.en.title, 'Translated title');
    assert.equal(mixedContent.fr.body, 'Legacy body');
    assert.equal(mixedContent.fr.link_label, 'Legacy link');
});

test('announcement form labels exist in every supported interface language', () => {
    const requiredKeys = [
        'content_section',
        'content_hint',
        'editing_locale',
        'locale_error',
        'delivery_section',
        'delivery_hint',
        'color_picker',
        'upload_placeholder',
        'image_processing_error',
        'image_too_large',
        'video_too_large',
        'unsupported_file_type',
    ];

    for (const locale of ['fr', 'en', 'es']) {
        const messages = JSON.parse(read(`resources/js/i18n/modules/${locale}/super_admin.json`));
        const form = messages.super_admin.announcements.form;

        for (const key of requiredKeys) {
            assert.equal(typeof form[key], 'string', `${locale}.${key} must be translated`);
            assert.notEqual(form[key].trim(), '', `${locale}.${key} must not be empty`);
        }

        assert.deepEqual(Object.keys(form.locales).sort(), ['en', 'es', 'fr']);
    }
});

test('the floating file picker exposes one visible keyboard control', () => {
    const source = read('resources/js/Components/FloatingFileInput.vue');

    assert.match(source, /<button[\s\S]*?@click="openPicker"/);
    assert.match(source, /<input[\s\S]*?tabindex="-1"[\s\S]*?aria-hidden="true"/);
});
