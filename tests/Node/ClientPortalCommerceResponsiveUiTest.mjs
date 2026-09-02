import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import test from 'node:test';

const read = (path) => readFileSync(resolve(path), 'utf8');
const commercePagePaths = [
    'resources/js/Pages/DashboardProductsClient.vue',
    'resources/js/Pages/Portal/Products/Shop.vue',
    'resources/js/Pages/Portal/Products/OrderShow.vue',
    'resources/js/Pages/Portal/InvoicesIndex.vue',
    'resources/js/Pages/Portal/InvoiceShow.vue',
    'resources/js/Pages/Portal/Packages/Index.vue',
    'resources/js/Pages/Portal/Loyalty/Index.vue',
];

test('client commerce pages use the shell width and compact structural rounding', () => {
    for (const pagePath of commercePagePaths) {
        const source = read(pagePath);
        const rootClasses = source.match(/<AuthenticatedLayout>[\s\S]*?<div class="([^"]+)"/u)?.[1] ?? '';

        assert.match(rootClasses, /\bw-full\b/u, `${pagePath} fills the shell width`);
        assert.match(rootClasses, /\bmin-w-0\b/u, `${pagePath} can shrink without horizontal overflow`);
        assert.match(rootClasses, /\bmax-w-full\b/u, `${pagePath} does not narrow the shell`);
        assert.doesNotMatch(rootClasses, /\bmax-w-(?:5xl|6xl)\b/u, `${pagePath} has no page-level width cap`);
        assert.doesNotMatch(
            source,
            /\brounded-(?:md|lg|xl|2xl|3xl|\[[^\]]+\])/u,
            `${pagePath} has no large structural radius`,
        );

        const fullRoundedElements = source.matchAll(
            /<([A-Za-z][\w.]*)\b[^>]*class="([^"]*\b!?rounded-full\b[^"]*)"[^>]*>/gu,
        );

        for (const [, tagName, classes] of fullRoundedElements) {
            const isBadgeOrCircularElement = tagName === 'span'
                || tagName === 'CompanyBrandLogo'
                || (tagName === 'div' && (/\binline-flex\b/u.test(classes)
                    || (/\bh-\S+\b/u.test(classes) && /\bw-\S+\b/u.test(classes))));

            assert.ok(
                isBadgeOrCircularElement,
                `${pagePath} keeps rounded-full only for badges, statuses, avatars, or circular visuals: <${tagName}>`,
            );
        }
    }
});

test('client commerce KPI cards opt into the compact portal radius', () => {
    for (const pagePath of [
        'resources/js/Pages/DashboardProductsClient.vue',
        'resources/js/Pages/Portal/Packages/Index.vue',
        'resources/js/Pages/Portal/Loyalty/Index.vue',
    ]) {
        assert.match(
            read(pagePath),
            /<KpiMetricGrid\b[^>]*class="\[&>\*\]:!rounded-sm"[^>]*\/>/u,
            `${pagePath} keeps KPI cards aligned with portal surfaces`,
        );
    }
});

test('invoice details keep customer information and totals readable on narrow screens', () => {
    const invoice = read('resources/js/Pages/Portal/InvoiceShow.vue');

    assert.match(invoice, /class="grid grid-cols-1 gap-6 lg:grid-cols-3"/u);
    assert.match(invoice, /class="lg:col-span-2"/u);
    assert.doesNotMatch(invoice, /class="col-span-2 space-x-2"/u);
    assert.match(invoice, /class="grid grid-cols-1 gap-3 sm:grid-cols-2"/u);
    assert.match(invoice, /class="grid grid-cols-1 gap-4[^"]*md:grid-cols-2"/u);
    assert.match(invoice, /class="rounded-sm border-stone-200 p-4[^"]*md:col-start-2 md:border-l"/u);
    assert.doesNotMatch(invoice, /class="p-5 grid grid-cols-2 gap-4 justify-between/u);
    assert.match(invoice, /class="flex flex-col items-start gap-2[^"]*sm:flex-row sm:items-center sm:justify-between"/u);
    assert.match(invoice, /class="break-words text-xs text-stone-600[^"]*"[\s\S]*?\{\{ contactEmail \}\}/u);
});

test('cart modal stays within the viewport and keeps long item controls usable', () => {
    const shop = read('resources/js/Pages/Portal/Products/Shop.vue');
    const cartModal = shop.match(/<Modal(?=[^>]*:show="showCart")[\s\S]*?<\/Modal>/u)?.[0] ?? '';

    assert.notEqual(cartModal, '');
    assert.match(cartModal, /flex max-h-\[calc\(100dvh-3rem\)\] min-h-0 flex-col/u);
    assert.match(cartModal, /flex shrink-0 items-start justify-between gap-3/u);
    assert.match(cartModal, /min-h-0 flex-1 space-y-4 overflow-y-auto overscroll-contain p-4/u);
    assert.match(cartModal, /flex flex-col items-stretch gap-2 sm:flex-row sm:items-center sm:justify-between/u);
    assert.match(cartModal, /class="min-w-0"[\s\S]*?entry\.product\.name/u);
    assert.match(cartModal, /flex flex-wrap items-center justify-end gap-2 sm:shrink-0/u);
});

test('order hero stacks its summary and wraps long commerce content on mobile', () => {
    const order = read('resources/js/Pages/Portal/Products/OrderShow.vue');

    assert.match(order, /flex flex-col items-stretch gap-4 sm:flex-row sm:items-start sm:justify-between/u);
    assert.match(order, /class="min-w-0 space-y-4"/u);
    assert.match(order, /inline-flex max-w-full items-center gap-2/u);
    assert.match(order, /class="min-w-0 break-words">\{\{ companyName \}\}<\/span>/u);
    assert.match(order, /break-words text-3xl font-semibold tracking-tight sm:text-\[2\.1rem\]/u);
    assert.match(order, /w-full rounded-sm[^"]*sm:w-auto sm:shrink-0 sm:text-right/u);
    assert.match(order, /flex flex-col items-start gap-2[^"]*sm:flex-row sm:items-center sm:justify-between/u);
    assert.match(order, /class="min-w-0 break-words"/u);
});

test('selected delivery and pickup options remain legible in dark mode', () => {
    const shop = read('resources/js/Pages/Portal/Products/Shop.vue');
    const selectedFulfillmentClasses = 'border-green-500 bg-green-50 text-green-700 dark:border-green-400 dark:bg-green-500/10 dark:text-green-300';

    assert.equal(shop.split(selectedFulfillmentClasses).length - 1, 2);
});
