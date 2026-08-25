import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import test from 'node:test';

const component = readFileSync(resolve('resources/js/Pages/Customer/Show.vue'), 'utf8');

const purchasedPacksSection = () => {
    const start = component.indexOf('<section\n                                data-customer-purchased-packs');
    const end = component.indexOf('</section>', start);

    assert.notEqual(start, -1, 'purchased packs section is present');
    assert.notEqual(end, -1, 'purchased packs section is closed');

    return component.slice(start, end + '</section>'.length);
};

test('customer detail accepts purchased packs and combines their count with forfaits', () => {
    assert.match(component, /customerPurchasedPacks:\s*\{[\s\S]*?type: Array,[\s\S]*?default: \(\) => \[\]/u);
    assert.match(component, /customerPurchasedPackSummary:\s*\{[\s\S]*?total_lines: 0,[\s\S]*?total_quantity: 0,[\s\S]*?currency_breakdown: \[\]/u);
    assert.match(component, /const purchasedPacks = computed\(\(\) => props\.customerPurchasedPacks \|\| \[\]\)/u);
    assert.match(component, /const customerOfferCount = computed\([\s\S]*?packageSummary\.value\.total[\s\S]*?purchasedPackSummary\.value\.total_lines/u);
    assert.match(component, /showCustomerPackages[\s\S]*?purchasedPacks\.value\.length > 0/u);
    assert.match(component, /customers\.details\.customer_packages\.combined_title/u);
    assert.match(component, /customers\.details\.customer_packages\.summary\.packs/u);
});

test('purchased pack cards expose the read-only purchase contract and currency summary', () => {
    const section = purchasedPacksSection();

    assert.match(section, /v-for="purchasedPack in purchasedPacks"/u);
    assert.match(section, /purchasedPack\.name/u);
    assert.match(section, /purchasedPack\.description/u);
    assert.match(section, /purchasedPack\.quantity/u);
    assert.match(section, /purchasedPack\.unit_price/u);
    assert.match(section, /purchasedPack\.total/u);
    assert.match(section, /purchasedPack\.currency_code/u);
    assert.match(section, /purchasedPack\.purchased_at/u);
    assert.match(section, /purchasedPack\.invoice\?\.status/u);
    assert.match(section, /packageStatusClass/u);
    assert.match(section, /customer_packages\.invoice_statuses/u);
    assert.match(section, /purchasedPackSummary\.currency_breakdown/u);
    assert.match(section, /currency\.total_spent/u);
    assert.match(section, /customers\.details\.customer_packages\.purchased_packs_empty/u);
    assert.doesNotMatch(section, /<form|<button|@click=/u);
});

test('purchased pack invoice links fail closed and never reconstruct a route', () => {
    const section = purchasedPacksSection();

    assert.match(component, /invoice\.can_view === false/u);
    assert.match(component, /typeof invoice\.href !== 'string'/u);
    assert.match(component, /\^\\\/\(\?!\\\/\)\/u/u);
    assert.match(section, /v-if="authorizedPurchasedPackInvoiceHref\(purchasedPack\)"/u);
    assert.match(section, /:href="authorizedPurchasedPackInvoiceHref\(purchasedPack\)"/u);
    assert.doesNotMatch(section, /route\(['"]invoice\.show/u);
});

test('pack and forfait panels remain distinct, flat, responsive, and accessible', () => {
    const section = purchasedPacksSection();

    assert.match(component, /data-customer-forfaits/u);
    assert.match(component, /aria-labelledby="customer-forfaits-title"/u);
    assert.match(section, /aria-labelledby="customer-purchased-packs-title"/u);
    assert.match(section, /role="list"/u);
    assert.match(section, /role="listitem"/u);
    assert.match(section, /role="status"/u);
    assert.match(section, /flex flex-wrap/u);
    assert.match(section, /grid grid-cols-2/u);
    assert.doesNotMatch(section, /gradient/u);

    assert.match(component, /@click="startRenewPackage\(customerPackage\)"/u);
    assert.match(component, /@click="startChangePackage\(customerPackage\)"/u);
    assert.match(component, /@click="startCancelPackage\(customerPackage\)"/u);
    assert.match(component, /@click="startConsumePackage\(customerPackage\)"/u);
});
