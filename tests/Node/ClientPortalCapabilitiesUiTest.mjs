import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import test from 'node:test';
import {
    buildClientPortalNavigation,
    resolveClientPortalMode,
    shouldShowPublicBookingReservationsLink,
} from '../../resources/js/utils/clientPortalNavigation.js';

const read = (path) => readFileSync(resolve(path), 'utf8');

test('client navigation is isolated from the internal application', () => {
    const sidebar = read('resources/js/Layouts/UI/Sidebar.vue');

    assert.match(sidebar, /auth\?\.account\?\.portal_capabilities/);
    assert.match(sidebar, /auth\?\.account\?\.portal_context\?\.mode/);
    assert.match(sidebar, /<template v-if="isClient">[\s\S]*ClientPortalSidebarLink[\s\S]*<template v-else>/);
    assert.doesNotMatch(sidebar, /nav\.book_reservation|nav\.my_reservations/);
});

test('portal mode follows active service and product capabilities', () => {
    assert.equal(resolveClientPortalMode({}), 'minimal');
    assert.equal(resolveClientPortalMode({ reservations: { view: true } }), 'service');
    assert.equal(resolveClientPortalMode({ orders: { view: true } }), 'product');
    assert.equal(resolveClientPortalMode({
        orders: { view: true },
        works: { view: true },
    }), 'hybrid');
    assert.equal(resolveClientPortalMode({
        invoices: { view: true, history: true },
        packages: { view: true },
    }), 'minimal');
});

test('portal navigation exposes one reservation entry with a safe book fallback', () => {
    const historyNavigation = buildClientPortalNavigation({
        reservations: { view: true, book: true },
        invoices: { history: true },
    });
    const bookOnlyNavigation = buildClientPortalNavigation({
        reservations: { view: false, book: true },
    });

    assert.deepEqual(
        historyNavigation.map((item) => item.key),
        ['dashboard', 'reservations', 'invoices'],
    );
    assert.equal(
        historyNavigation.filter((item) => item.key === 'reservations').length,
        1,
    );
    assert.equal(
        historyNavigation.find((item) => item.key === 'reservations').routeName,
        'client.reservations.index',
    );
    assert.equal(
        bookOnlyNavigation.find((item) => item.key === 'reservations').routeName,
        'client.reservations.book',
    );
});

test('public booking exposes client reservations only for the matching enabled portal', () => {
    const matchingClientAccount = {
        is_client: true,
        owner_id: 76,
        portal_capabilities: {
            reservations: { view: true },
        },
    };

    assert.equal(shouldShowPublicBookingReservationsLink(matchingClientAccount, 76), true);
    assert.equal(shouldShowPublicBookingReservationsLink(matchingClientAccount, '76'), true);
    assert.equal(shouldShowPublicBookingReservationsLink(matchingClientAccount, 77), false);
    assert.equal(shouldShowPublicBookingReservationsLink({
        ...matchingClientAccount,
        is_client: false,
    }, 76), false);
    assert.equal(shouldShowPublicBookingReservationsLink({
        ...matchingClientAccount,
        portal_capabilities: { reservations: { view: false } },
    }, 76), false);
    assert.equal(shouldShowPublicBookingReservationsLink(null, 76), false);
});

test('hybrid navigation keeps both client domains and ignores unrelated capability data', () => {
    const navigation = buildClientPortalNavigation({
        orders: { view: true },
        reservations: { view: true, book: true },
        invoices: { history: true },
        packages: { view: true },
        loyalty: { view: true },
        customers: { view: true },
    }, 'hybrid');

    assert.deepEqual(
        navigation.map((item) => item.key),
        ['dashboard', 'reservations', 'orders', 'invoices', 'packages', 'loyalty'],
    );
});

test('client dashboard gates each business area independently', () => {
    const dashboard = read('resources/js/Pages/DashboardClient.vue');

    assert.match(dashboard, /portalCapabilities:/);
    assert.match(dashboard, /v-if="canViewPendingQuotes"/);
    assert.match(dashboard, /v-if="canManageSchedules"/);
    assert.match(dashboard, /v-if="canManagePendingWorks"/);
    assert.match(dashboard, /v-if="!profileMissing && canViewTaskProofs"/);
    assert.match(dashboard, /v-if="canPayInvoices"/);
    assert.match(dashboard, /v-if="hasRatings"/);
    assert.match(dashboard, /v-if="canViewQuoteHistory"/);
    assert.match(dashboard, /hasPortalCapability\('reservations', 'view'\)/);
    assert.match(dashboard, /v-if="!profileMissing && reservationEntry"/);
    assert.match(dashboard, /v-if="!profileMissing && portalMode === 'minimal'"/);
    assert.match(dashboard, /:columns="Math\.min\(portalTabs\.length, 4\)"/);
    assert.match(dashboard, /case 'canceled':/);
});

test('product client dashboard gates mutable order actions', () => {
    const dashboard = read('resources/js/Pages/DashboardProductsClient.vue');

    assert.match(dashboard, /portalCapabilities:/);
    assert.match(dashboard, /hasPortalCapability\('create'\)/);
    assert.match(dashboard, /hasPortalCapability\('update'\)/);
    assert.match(dashboard, /hasPortalCapability\('reorder'\)/);
    assert.match(dashboard, /v-if="sale\.status === 'paid' && canReorderOrders"/);
});

test('reservation pages use one shared internal switch without duplicate actions', () => {
    const index = read('resources/js/Pages/Reservation/ClientIndex.vue');
    const book = read('resources/js/Pages/Reservation/ClientBook.vue');

    assert.match(index, /<ClientPortalTabs/);
    assert.match(book, /<ClientPortalTabs/);
    assert.doesNotMatch(index, /crmSegmentedControl|crmButtonClass/);
    assert.doesNotMatch(book, /crmSegmentedControl|crmButtonClass/);
    assert.equal(index.match(/route\('client\.reservations\.book'\)/g)?.length, 1);
    assert.equal(book.match(/route\('client\.reservations\.index'\)/g)?.length, 1);
});
