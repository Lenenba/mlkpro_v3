import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import test from 'node:test';

const read = (path) => readFileSync(resolve(path), 'utf8');
const readJson = (path) => JSON.parse(read(path));

test('the client topbar identifies the portal instead of leaving an empty search area', () => {
    const header = read('resources/js/Layouts/UI/Header.vue');

    assert.match(header, /<Link[\s\S]*?v-if="isClient"[\s\S]*?:href="route\('dashboard'\)"[\s\S]*?data-testid="client-portal-identity"/u);
    assert.match(header, /t\('account\.client_portal\.title'\)/u);
    assert.match(header, /companyName \|\| t\('account\.client_portal\.company_fallback'\)/u);
    assert.match(header, /<div v-else class="min-w-0 flex-1">\s*<GlobalSearch \/>/u);
    assert.match(header, /data-testid="sidebar-toggle"/u);
    assert.match(header, /size-11[^"]*sm:size-9/u);
    assert.doesNotMatch(header, /aria-label="(?:Toggle navigation|Settings)"/u);
});

test('client shell and account controls use translated accessible names', () => {
    const sidebar = read('resources/js/Layouts/UI/Sidebar.vue');
    const accountMenu = read('resources/js/Components/UI/SidebarAccountMenu.vue');
    const shop = read('resources/js/Pages/Portal/Products/Shop.vue');
    const order = read('resources/js/Pages/Portal/Products/OrderShow.vue');

    assert.match(sidebar, /account\.client_portal\.navigation/u);
    assert.match(accountMenu, /t\('account\.open_menu', \{ name: accountName \}\)/u);
    assert.match(accountMenu, /t\('account\.avatar_alt', \{ name: accountName \}\)/u);
    assert.doesNotMatch(accountMenu, /'Account menu'|'Avatar'/u);
    assert.match(shop, /:aria-label="\$t\('client_orders\.portal_navigation'\)"/u);
    assert.match(order, /:aria-label="t\('client_orders\.portal_navigation'\)"/u);
    assert.doesNotMatch(`${shop}\n${order}`, /aria-label="Product client sections"/u);
});

test('client portal shell labels are available in every supported locale', () => {
    for (const locale of ['fr', 'en', 'es']) {
        const account = readJson(`resources/js/i18n/modules/${locale}/account.json`).account;

        assert.ok(account.open_menu);
        assert.ok(account.avatar_alt);
        assert.ok(account.open_settings);
        assert.ok(account.client_portal.title);
        assert.ok(account.client_portal.company_fallback);
        assert.ok(account.client_portal.navigation);
        assert.ok(account.client_portal.open_navigation);
    }
});

test('the shared portal notice gives errors and confirmations distinct live semantics', () => {
    const notice = read('resources/js/Components/Portal/ClientPortalNotice.vue');

    assert.match(notice, /props\.tone === 'error' \? 'alert' : 'status'/u);
    assert.match(notice, /props\.tone === 'error' \? 'assertive' : 'polite'/u);
    assert.match(notice, /aria-atomic="true"/u);
    assert.match(notice, /dark:border-rose-500\/30/u);
    assert.match(notice, /dark:border-emerald-500\/30/u);
});

test('the cart keeps every server error visible and focuses the local summary', () => {
    const shop = read('resources/js/Pages/Portal/Products/Shop.vue');
    const cartModal = shop.match(/<Modal(?=[^>]*:show="showCart")[\s\S]*?<\/Modal>/u)?.[0] ?? '';

    assert.match(shop, /import \{ computed, nextTick, ref, watch \} from 'vue';/u);
    assert.match(shop, /import ClientPortalNotice from '@\/Components\/Portal\/ClientPortalNotice\.vue';/u);
    assert.match(shop, /Object\.values\(form\.errors \|\| \{\}\)[\s\S]*?\.flatMap\([\s\S]*?\.filter\(Boolean\)/u);
    assert.equal(shop.match(/onError: focusCartErrorSummary/gu)?.length, 3);
    assert.match(shop, /await nextTick\(\);[\s\S]*?cartErrorSummary\.value\?\.\$el\?\.focus\?\.\(\);/u);
    assert.match(cartModal, /<ClientPortalNotice[\s\S]*?ref="cartErrorSummary"[\s\S]*?tone="error"[\s\S]*?tabindex="-1"/u);
    assert.match(cartModal, /portal_shop\.validation\.title/u);
    assert.match(cartModal, /v-for="\(message, index\) in cartErrorMessages"[^>]*:key="`\$\{index\}-\$\{message\}`"/u);
    assert.match(cartModal, /:aria-busy="form\.processing"[\s\S]*?portal_shop\.summary\.checkout_processing/u);
    assert.match(cartModal, /:closeable="!form\.processing"/u);
    assert.match(cartModal, /:aria-label="\$t\('portal_shop\.actions\.close_cart'\)"[\s\S]*?:disabled="form\.processing"/u);
    assert.match(cartModal, /:disabled="cartInteractionLocked"/u);
    assert.match(cartModal, /v-if="isEditing && canEditOrder"[\s\S]*?:disabled="form\.processing"/u);
});

test('the cart exposes translated names and selected fulfillment state', () => {
    const shop = read('resources/js/Pages/Portal/Products/Shop.vue');
    const cartModal = shop.match(/<Modal(?=[^>]*:show="showCart")[\s\S]*?<\/Modal>/u)?.[0] ?? '';

    assert.match(cartModal, /aria-labelledby="portal-shop-cart-title"/u);
    assert.match(cartModal, /portal_shop\.actions\.close_cart/u);
    assert.equal(cartModal.match(/:aria-pressed="form\.fulfillment_method === '(?:delivery|pickup)'"/gu)?.length, 2);
    assert.match(cartModal, /portal_shop\.actions\.decrease_quantity/u);
    assert.match(cartModal, /portal_shop\.actions\.increase_quantity/u);
});

test('loyalty filters expose loading failures and a retry action', () => {
    const loyalty = read('resources/js/Pages/Portal/Loyalty/Index.vue');

    assert.match(loyalty, /import ClientPortalNotice from '@\/Components\/Portal\/ClientPortalNotice\.vue';/u);
    assert.match(loyalty, /const loadError = ref\(''\);/u);
    assert.match(loyalty, /onSuccess: \(\) => \{[\s\S]*?loadError\.value = '';/u);
    assert.match(loyalty, /onError: \(\) => \{[\s\S]*?client_loyalty\.feedback\.load_error/u);
    assert.match(loyalty, /const clearFilters = \(\) => \{[\s\S]*?nextTick\(applyFilters\);/u);
    assert.match(loyalty, /<ClientPortalNotice v-if="loadError" tone="error">[\s\S]*?@click="applyFilters"/u);
    assert.match(loyalty, /:aria-busy="isLoading"/u);
    assert.match(loyalty, /client_loyalty\.feedback\.loading/u);
    assert.match(loyalty, /client_loyalty\.feedback\.retrying/u);
    assert.match(loyalty, /<FloatingSelect v-model="filterForm\.period"[^>]*:disabled="isLoading"/u);
    assert.match(loyalty, /<FloatingSelect v-model="filterForm\.event"[^>]*:disabled="isLoading"/u);
    assert.match(loyalty, /<FloatingInput[^>]*v-model="filterForm\.from"[^>]*:disabled="isLoading"/u);
    assert.match(loyalty, /<FloatingInput[^>]*v-model="filterForm\.to"[^>]*:disabled="isLoading"/u);
    assert.match(loyalty, /:disabled="isLoading"[\s\S]*?@click="showAdvanced = !showAdvanced"/u);
    assert.match(loyalty, /:disabled="isLoading"[\s\S]*?@click="applyFilters"/u);
    assert.match(loyalty, /:disabled="isLoading"[^>]*@click="clearFilters"/u);
    for (const column of ['processed_at', 'event', 'points', 'amount']) {
        assert.match(loyalty, new RegExp(`:disabled="isLoading"[^>]*@click="toggleSort\\('${column}'\\)"`, 'u'));
    }
});

test('portal feedback and accessibility labels exist in every supported locale', () => {
    for (const locale of ['fr', 'en', 'es']) {
        const shop = readJson(`resources/js/i18n/modules/${locale}/portal_shop.json`).portal_shop;
        const loyalty = readJson(`resources/js/i18n/modules/${locale}/client_loyalty.json`).client_loyalty;

        for (const key of ['close_cart', 'close_product_details', 'decrease_quantity', 'increase_quantity']) {
            assert.ok(shop.actions[key], `${locale}:portal_shop.actions.${key}`);
        }
        assert.match(shop.actions.decrease_quantity, /\{product\}/u, `${locale}:portal_shop.actions.decrease_quantity`);
        assert.match(shop.actions.increase_quantity, /\{product\}/u, `${locale}:portal_shop.actions.increase_quantity`);
        assert.ok(shop.validation.title, `${locale}:portal_shop.validation.title`);
        assert.ok(shop.summary.checkout_processing, `${locale}:portal_shop.summary.checkout_processing`);

        for (const key of ['load_error', 'loading', 'retry', 'retrying']) {
            assert.ok(loyalty.feedback[key], `${locale}:client_loyalty.feedback.${key}`);
        }
    }
});

test('package, order, and invoice feedback contracts remain accessible', () => {
    const packages = read('resources/js/Pages/Portal/Packages/Index.vue');
    const order = read('resources/js/Pages/Portal/Products/OrderShow.vue');
    const invoices = read('resources/js/Pages/Portal/InvoicesIndex.vue');

    assert.match(packages, /:aria-pressed="selectedPackage\?\.id === item\.id"/u);
    assert.match(packages, /:aria-busy="requestForm\.processing"/u);
    assert.match(packages, /<ClientPortalNotice v-if="requestErrors\.length"/u);
    assert.match(order, /:aria-busy="paymentProcessing"/u);
    assert.match(order, /<ClientPortalNotice v-if="paymentError"/u);
    assert.match(order, /:aria-busy="confirmForm\.processing"/u);
    assert.match(invoices, /:aria-label="\$t\('invoices\.pagination\.label'\)"/u);
});
