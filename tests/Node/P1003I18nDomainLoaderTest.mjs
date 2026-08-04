import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import test from 'node:test';

import {
    createDomainMessageLoader,
    isDomainLoadingEnabled,
    normalizeLocale,
} from '../../resources/js/i18n/domain-loader.js';
import { getDomainsForPage, translationModules } from '../../resources/js/i18n/domains.js';

test('keeps the domain manifest aligned with every locale module set', () => {
    ['fr', 'en', 'es'].forEach((locale) => {
        const sourceDomains = readdirSync(resolve(`resources/js/i18n/modules/${locale}`))
            .filter((file) => file.endsWith('.json'))
            .map((file) => file.replace(/\.json$/, ''))
            .sort();

        assert.deepEqual([...translationModules].sort(), sourceDomains, locale);
    });
});

test('maps known page components to their shell and business domains', () => {
    const quoteDomains = getDomainsForPage('Quote/Create');
    const publicInvoiceDomains = getDomainsForPage('Public/InvoicePay');
    const authDomains = getDomainsForPage('Auth/Login');
    const customerShowDomains = getDomainsForPage('Customer/Show');
    const dashboardDomains = getDomainsForPage('Dashboard');

    assert.equal(quoteDomains.includes('quotes'), true);
    assert.equal(quoteDomains.includes('session'), true);
    assert.equal(quoteDomains.includes('super_admin'), false);
    assert.equal(publicInvoiceDomains.includes('public_invoice'), true);
    assert.equal(publicInvoiceDomains.includes('public_footer'), true);
    assert.equal(authDomains.includes('auth_pages'), true);
    assert.equal(authDomains.includes('two_factor'), true);
    assert.equal(authDomains.includes('alerts'), true);
    assert.equal(authDomains.includes('cookies'), true);
    assert.equal(customerShowDomains.includes('service_requests'), true);
    assert.equal(getDomainsForPage('DashboardClient').includes('dashboard'), true);
    assert.equal(getDomainsForPage('SuperAdmin/Announcements/Preview').includes('dashboard'), true);
    assert.equal(dashboardDomains.includes('settings'), true);
    assert.equal(dashboardDomains.includes('workspace_hub'), true);
    assert.deepEqual(getDomainsForPage('Future/Unknown'), [...translationModules]);
});

test('loads each locale domain once, merges messages, and keeps the English fallback independent', async () => {
    const calls = [];
    const messagesByLocaleAndDomain = {
        'fr:actions': { shared: { fr: true }, actions: { save: 'Enregistrer' } },
        'fr:quotes': { shared: { quote: true }, quotes: { title: 'Devis' } },
        'en:actions': { shared: { en: true }, actions: { save: 'Save' } },
        'en:quotes': { shared: { quote: true }, quotes: { title: 'Quotes' } },
    };
    const loader = createDomainMessageLoader({
        domains: ['actions', 'quotes'],
        loadModule: async (locale, domain) => {
            calls.push(`${locale}:${domain}`);

            return messagesByLocaleAndDomain[`${locale}:${domain}`];
        },
    });

    await Promise.all([
        loader.loadLocaleDomains('fr', ['actions', 'quotes']),
        loader.loadLocaleDomains('fr', ['actions']),
        loader.loadLocaleDomains('en', ['actions', 'quotes']),
    ]);

    assert.equal(calls.filter((call) => call === 'fr:actions').length, 1);
    assert.equal(calls.filter((call) => call === 'fr:quotes').length, 1);
    assert.deepEqual(loader.messages.fr, {
        shared: { fr: true, quote: true },
        actions: { save: 'Enregistrer' },
        quotes: { title: 'Devis' },
    });
    assert.deepEqual(loader.messages.en, {
        shared: { en: true, quote: true },
        actions: { save: 'Save' },
        quotes: { title: 'Quotes' },
    });
    assert.equal(loader.hasLoadedDomain('fr', 'quotes'), true);
    assert.equal(normalizeLocale('unsupported'), 'fr');
    assert.equal(isDomainLoadingEnabled(undefined), true);
    assert.equal(isDomainLoadingEnabled('false'), false);
    assert.equal(isDomainLoadingEnabled('FALSE'), false);
});

test('preloads page domains before the Inertia component is resolved', () => {
    const appSource = readFileSync(resolve('resources/js/app.js'), 'utf8');

    assert.match(appSource, /ensureI18nDomains\(i18nInstance, targetLocale, name\)/);
    assert.match(appSource, /createI18nInstance\(initialLocale, props\.initialPage\?\.component\)/);
});
