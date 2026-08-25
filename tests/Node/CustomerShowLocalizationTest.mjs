import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import test from 'node:test';

import { getDomainsForPage } from '../../resources/js/i18n/domains.js';
import { deepMerge } from '../../resources/js/i18n/locales/merge.js';

const locales = ['fr', 'en', 'es'];
const pageComponent = 'Customer/Show';

const read = (path) => readFileSync(resolve(path), 'utf8');
const readJson = (path) => JSON.parse(read(path));

const resolveMessage = (messages, path) => path
    .split('.')
    .reduce((current, segment) => current?.[segment], messages);

const loadPageMessages = (locale) => getDomainsForPage(pageComponent)
    .reduce((messages, domain) => {
        const modulePath = `resources/js/i18n/modules/${locale}/${domain}.json`;

        return existsSync(resolve(modulePath))
            ? deepMerge(messages, readJson(modulePath))
            : messages;
    }, {});

test('Customer/Show loads every customer preview translation used by the card', () => {
    const domains = getDomainsForPage(pageComponent);
    const card = read('resources/js/Pages/Customer/UI/CustomerPreviewCard.vue');
    const previewKeys = [...new Set(
        [...card.matchAll(/\$t\('([^']+)'/g)]
            .map((match) => match[1])
            .filter((key) => key.startsWith('customers.details.preview.')),
    )].sort();

    assert.equal(domains.includes('customers'), true);
    assert.equal(domains.includes('customer_index'), false);
    assert.equal(previewKeys.length, 19);

    for (const locale of locales) {
        const messages = loadPageMessages(locale);

        for (const key of previewKeys) {
            const translated = resolveMessage(messages, key);

            assert.equal(typeof translated, 'string', `${locale}:${key}`);
            assert.notEqual(translated.trim(), '', `${locale}:${key}`);
            assert.notEqual(translated, key, `${locale}:${key}`);
        }
    }
});

test('customer preview copy belongs to the shared customers domain with locale parity', () => {
    const previews = Object.fromEntries(locales.map((locale) => {
        const customers = readJson(`resources/js/i18n/modules/${locale}/customers.json`);
        const customerIndex = readJson(`resources/js/i18n/modules/${locale}/customer_index.json`);

        assert.equal(customerIndex.customers.details, undefined, `${locale}: index-only module must not own show-page copy`);

        return [locale, customers.customers.details.preview];
    }));

    const expectedKeys = Object.keys(previews.fr).sort();

    assert.deepEqual(Object.keys(previews.en).sort(), expectedKeys);
    assert.deepEqual(Object.keys(previews.es).sort(), expectedKeys);
    assert.equal(previews.fr.title, 'Aperçu client');
    assert.equal(previews.fr.balance_due, 'Solde dû');
    assert.equal(previews.fr.latest_invoice, 'Dernière facture');
});

test('Customer/Show has complete pack and forfait labels in every locale', () => {
    const requiredPaths = [
        'combined_title',
        'pack_type',
        'forfait_type',
        'purchased_packs_title',
        'purchased_packs_empty',
        'purchased_on',
        'quantity',
        'amount',
        'invoice',
        'summary.packs',
        'invoice_statuses.draft',
        'invoice_statuses.sent',
        'invoice_statuses.awaiting_acceptance',
        'invoice_statuses.accepted',
        'invoice_statuses.rejected',
        'invoice_statuses.partial',
        'invoice_statuses.paid',
        'invoice_statuses.overdue',
        'invoice_statuses.void',
    ];

    for (const locale of locales) {
        const messages = loadPageMessages(locale);
        const packageMessages = messages.customers.details.customer_packages;
        const sidebarLabel = messages.customers.details.sidebar.tabs.packages;

        assert.equal(typeof sidebarLabel, 'string', `${locale}:sidebar.tabs.packages`);
        assert.match(sidebarLabel, /pack/i, `${locale}:sidebar.tabs.packages`);

        for (const path of requiredPaths) {
            const translated = resolveMessage(packageMessages, path);

            assert.equal(typeof translated, 'string', `${locale}:customer_packages.${path}`);
            assert.notEqual(translated.trim(), '', `${locale}:customer_packages.${path}`);
        }
    }
});
