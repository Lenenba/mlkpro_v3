import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import test from 'node:test';
import { getDomainsForPage } from '../../resources/js/i18n/domains.js';

const source = (path) => readFileSync(resolve(path), 'utf8');
const component = source('resources/js/Pages/OfferPackages/Show.vue');
const messages = (locale) => JSON.parse(source(`resources/js/i18n/modules/${locale}/offer_packages.json`));

const messageAt = (catalog, key) => key.split('.').reduce((value, segment) => value?.[segment], catalog);

test('offer package detail selects the correct history and KPI contract for each offer type', () => {
    assert.match(component, /sales:\s*\{\s*type:\s*Array,\s*default:\s*\(\)\s*=>\s*\[\]/u);
    assert.match(component, /const isPack = computed\(\(\) => props\.offer\?\.type === 'pack'\)/u);
    assert.match(component, /data-offer-type="isPack \? 'pack' : 'forfait'"/u);
    assert.match(component, /v-if="isPack"[\s\S]*?data-pack-sales-history/u);
    assert.match(component, /<template v-else>[\s\S]*?data-forfait-customer-history[\s\S]*?data-forfait-usage-history/u);
    assert.match(component, /props\.kpis\.total_billed \?\? props\.kpis\.total_revenue/u);
    assert.match(component, /props\.kpis\.total_collected/u);
    assert.match(component, /props\.kpis\.balance_due/u);
    assert.match(component, /props\.kpis\.paid_invoice_count/u);
    assert.match(component, /props\.kpis\.outstanding_invoice_count/u);
    assert.match(component, /sale\.collected_amount/u);
    assert.match(component, /sale\.balance_due/u);
    assert.match(component, /Array\.isArray\(sale\?\.payments\)/u);
    assert.match(component, /salesMeta:\s*\{\s*type:\s*Object,\s*default:\s*\(\)\s*=>\s*\(\{\}\)/u);
    assert.match(component, /sales_meta:\s*\{\s*type:\s*Object,\s*default:\s*\(\)\s*=>\s*\(\{\}\)/u);
    assert.match(component, /Object\.keys\(props\.sales_meta \|\| \{\}\)\.length \? props\.sales_meta : props\.salesMeta/u);
    assert.match(component, /salesMetadata\.value\?\.displayed \?\? salesMetadata\.value\?\.count/u);
    assert.match(component, /salesMetadata\.value\?\.total \?\? salesMetadata\.value\?\.total_count/u);
    assert.match(component, /offer_packages\.common\.displayed_of_total/u);
});

test('mixed-currency sales are disclosed and summarized without adding unlike amounts', () => {
    assert.match(component, /props\.kpis\.has_mixed_currencies/u);
    assert.match(component, /props\.kpis\.currency_breakdown/u);
    assert.match(component, /data-mixed-currency-summary/u);
    assert.match(component, /role="note"/u);
    assert.match(component, /aria-labelledby="offer-package-currencies-title"/u);
    assert.match(component, /v-for="currency in currencyBreakdown"/u);
    assert.match(component, /money\(currency\.total_billed, currency\.currency_code\)/u);
    assert.match(component, /money\(currency\.total_collected, currency\.currency_code\)/u);
    assert.match(component, /money\(currency\.balance_due, currency\.currency_code\)/u);
    assert.match(component, /offer_packages\.currencies\.description/u);
    assert.doesNotMatch(component, /currencyBreakdown[\s\S]{0,100}\.reduce\(/u);
});

test('offer package entity links fail closed and only consume server-authorized hrefs', () => {
    assert.match(component, /entity\?\.can_view !== true/u);
    assert.match(component, /typeof entity\?\.href !== 'string'/u);
    assert.match(component, /authorizedHref\(sale\.customer\)/u);
    assert.match(component, /authorizedHref\(sale\.invoice\)/u);
    assert.match(component, /authorizedHref\(item\.renewal_invoice \|\| item\.invoice\)/u);
    assert.match(component, /authorizedHref\(usage\.customer\)/u);
    assert.doesNotMatch(component, /route\(['"]invoice\.show/u);
    assert.doesNotMatch(component, /route\(['"]customer\.show/u);
});

test('offer package histories are responsive, accessible, flat, and have type-specific empty states', () => {
    assert.match(component, /data-pack-sales-mobile/u);
    assert.match(component, /class="mt-4 hidden overflow-x-auto lg:block"/u);
    assert.match(component, /class="mt-4 space-y-3 lg:hidden"/u);
    assert.match(component, /<caption class="sr-only">/u);
    assert.match(component, /scope="col"/u);
    assert.match(component, /role="progressbar"/u);
    assert.match(component, /aria-valuemin="0"/u);
    assert.match(component, /role="status"/u);
    assert.match(component, /offer_packages\.pack_history\.empty_title/u);
    assert.match(component, /offer_packages\.forfait_history\.empty_title/u);
    assert.match(component, /offer_packages\.usages\.empty_title/u);
    assert.match(component, /focus-visible:ring/u);
    assert.match(component, /dark:bg-/u);
    assert.doesNotMatch(component, /\b(?:bg|from|via|to)-gradient\b|linear-gradient|radial-gradient/u);
});

test('offer package detail copy is complete in French, English, and Spanish', () => {
    const staticKeys = [...component.matchAll(/t\('([^']+)'/gu)].map((match) => match[1]);
    const dynamicValues = {
        types: ['pack', 'forfait', 'unknown'],
        statuses: ['draft', 'active', 'archived', 'consumed', 'expired', 'cancelled', 'canceled', 'sent', 'paid', 'void', 'payment_due', 'suspended', 'overdue', 'partial', 'pending', 'completed', 'failed', 'refunded', 'accepted', 'awaiting_acceptance', 'rejected', 'unknown'],
        units: ['session', 'hour', 'visit', 'credit', 'month', 'unknown'],
        recurrence: ['monthly', 'quarterly', 'yearly', 'unknown'],
        payment_methods: ['cash', 'card', 'stripe', 'bank_transfer', 'e_transfer', 'etransfer', 'check', 'cheque', 'manual', 'unknown'],
    };

    assert.ok(staticKeys.length > 40);

    for (const locale of ['fr', 'en', 'es']) {
        const catalog = messages(locale);

        for (const key of staticKeys) {
            const value = messageAt(catalog, key);
            assert.equal(typeof value, 'string', `${locale}:${key}`);
            assert.notEqual(value.trim(), '', `${locale}:${key}`);
        }

        for (const [group, values] of Object.entries(dynamicValues)) {
            for (const value of values) {
                const key = `offer_packages.${group}.${value}`;
                assert.equal(typeof messageAt(catalog, key), 'string', `${locale}:${key}`);
            }
        }
    }

    assert.equal(getDomainsForPage('OfferPackages/Show').includes('offer_packages'), true);
});
