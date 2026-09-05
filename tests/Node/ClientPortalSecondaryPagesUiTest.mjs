import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import test from 'node:test';

const read = (path) => readFileSync(resolve(path), 'utf8');
const readJson = (path) => JSON.parse(read(path));

test('package selection and requests expose state without allowing concurrent actions', () => {
    const packages = read('resources/js/Pages/Portal/Packages/Index.vue');

    assert.match(packages, /import ClientPortalNotice/u);
    assert.match(packages, /const requestErrors = computed/u);
    assert.match(packages, /:aria-pressed="selectedPackage\?\.id === item\.id"/u);
    assert.match(packages, /role="progressbar"/u);
    assert.match(packages, /:aria-valuenow="balancePercent\(item\)"/u);
    assert.match(packages, /:aria-busy="requestForm\.processing"/u);
    assert.match(packages, /<ClientPortalNotice v-if="requestErrors\.length"[^>]*tone="error"/u);
    assert.match(packages, /requestForm\.processing \? \$t\('client_packages\.actions\.submitting_request'\)/u);
});

test('order payment and receipt failures use the shared live notice', () => {
    const order = read('resources/js/Pages/Portal/Products/OrderShow.vue');

    assert.match(order, /<ClientPortalNotice v-if="paymentError"[^>]*tone="error"/u);
    assert.match(order, /:aria-busy="paymentProcessing"/u);
    assert.match(order, /:aria-busy="confirmForm\.processing"/u);
    assert.match(order, /<ClientPortalNotice v-if="confirmForm\.errors\.proof"[^>]*tone="error"/u);
});

test('invoice pagination has a descriptive localized name and current-page state', () => {
    const invoices = read('resources/js/Pages/Portal/InvoicesIndex.vue');

    assert.match(invoices, /invoices\.pagination\.label/u);
    assert.match(invoices, /:aria-current="link\.active \? 'page' : undefined"/u);
    assert.match(invoices, /aria-disabled="true"/u);
    assert.doesNotMatch(invoices, /:aria-label="\$t\('invoices\.pagination\.of'\)"/u);

    for (const locale of ['fr', 'en', 'es']) {
        assert.ok(readJson(`resources/js/i18n/modules/${locale}/invoices.json`).invoices.pagination.label);
        assert.ok(readJson(`resources/js/i18n/modules/${locale}/client_packages.json`).client_packages.actions.submitting_request);
        assert.ok(readJson(`resources/js/i18n/modules/${locale}/client_packages.json`).client_packages.labels.balance_progress);
    }
});
