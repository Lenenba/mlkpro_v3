import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import { resolveFloatingMenuPosition } from '../../resources/js/Composables/useFloatingMenu.js';

const source = (path) => fs.readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

const rect = ({ left, right, top, bottom, width, height }) => ({
    left,
    right,
    top,
    bottom,
    width,
    height,
});

test('floating action menus align below their trigger when the viewport has room', () => {
    const position = resolveFloatingMenuPosition({
        toggleRect: rect({ left: 400, right: 440, top: 100, bottom: 128, width: 40, height: 28 }),
        menuRect: rect({ left: 0, right: 144, top: 0, bottom: 120, width: 144, height: 120 }),
        viewportWidth: 800,
        viewportHeight: 600,
    });

    assert.deepEqual(position, { left: 296, top: 136 });
});

test('floating action menus flip above rows near the bottom of the viewport', () => {
    const position = resolveFloatingMenuPosition({
        toggleRect: rect({ left: 700, right: 740, top: 540, bottom: 568, width: 40, height: 28 }),
        menuRect: rect({ left: 0, right: 144, top: 0, bottom: 120, width: 144, height: 120 }),
        viewportWidth: 800,
        viewportHeight: 600,
    });

    assert.deepEqual(position, { left: 596, top: 412 });
});

test('floating action menus stay inside narrow viewports for either alignment', () => {
    const common = {
        menuRect: rect({ left: 0, right: 180, top: 0, bottom: 100, width: 180, height: 100 }),
        viewportWidth: 220,
        viewportHeight: 500,
    };

    assert.equal(resolveFloatingMenuPosition({
        ...common,
        toggleRect: rect({ left: 4, right: 32, top: 80, bottom: 108, width: 28, height: 28 }),
        align: 'start',
    }).left, 12);
    assert.equal(resolveFloatingMenuPosition({
        ...common,
        toggleRect: rect({ left: 188, right: 216, top: 80, bottom: 108, width: 28, height: 28 }),
        align: 'end',
    }).left, 28);
});

test('start-aligned floating action menus keep their trigger edge when no clamp is needed', () => {
    const position = resolveFloatingMenuPosition({
        toggleRect: rect({ left: 80, right: 108, top: 80, bottom: 108, width: 28, height: 28 }),
        menuRect: rect({ left: 0, right: 120, top: 0, bottom: 100, width: 120, height: 100 }),
        viewportWidth: 320,
        viewportHeight: 500,
        align: 'start',
    });

    assert.equal(position.left, 80);
});

test('shared DataTable actions are Vue-controlled, teleported and independent from Preline scans', () => {
    const actions = source('resources/js/Components/DataTable/AdminDataTableActions.vue');
    const floatingMenu = source('resources/js/Composables/useFloatingMenu.js');

    assert.match(actions, /useFloatingMenu\(\{ align: props\.menuAlign \}\)/);
    assert.match(actions, /<Teleport to="body">/);
    assert.match(actions, /fixed z-\[90\]/);
    assert.match(actions, /max-h-\[calc\(100vh-1\.5rem\)\] overflow-y-auto/);
    assert.match(actions, /:aria-expanded="isOpen"/);
    assert.match(actions, /@click="handleMenuClick"/);
    assert.match(actions, /item\.setAttribute\('role', 'menuitem'\)/);
    assert.doesNotMatch(actions, /hs-dropdown/);
    assert.match(floatingMenu, /activeFloatingMenuClose/);
    assert.match(floatingMenu, /window\.addEventListener\('scroll', schedulePositionUpdate, true\)/);
    assert.match(floatingMenu, /document\.addEventListener\('focusin', handleOutsideFocus, true\)/);
    assert.match(floatingMenu, /toggleIsOutsideViewport/);
    assert.match(floatingMenu, /onBeforeUnmount\(\(\) => \{\s*closeMenu\(\);/);
});

test('known inline DataTable dropdowns use the shared floating action menu', () => {
    const reservation = source('resources/js/Pages/Reservation/Index.vue');
    const serviceCategories = source('resources/js/Pages/Service/Categories.vue');
    const requestTable = source('resources/js/Pages/Request/UI/RequestTable.vue');

    assert.equal((reservation.match(/<AdminDataTableActions/g) || []).length, 2);
    assert.match(reservation, /reservation-actions-trigger-/);
    assert.match(reservation, /waitlist-actions-trigger-/);
    assert.match(serviceCategories, /<AdminDataTableActions/);
    assert.match(requestTable, /<AdminDataTableActions[\s\S]*?menu-align="start"/);
    [reservation, serviceCategories, requestTable].forEach((contents) => {
        assert.doesNotMatch(contents, /hs-dropdown/);
    });
});

test('teleported action slots do not depend on dynamically initialized Preline controls', () => {
    const productActions = source('resources/js/Pages/Product/UI/ProductActionsMenu.vue');
    const productTable = source('resources/js/Pages/Product/UI/ProductTable.vue');

    assert.doesNotMatch(productActions, /data-hs-/);
    assert.match(productActions, /@click="\$emit\('edit'\)"/);
    assert.match(productTable, /@edit="openProductEdit\(product\)"/);
    assert.match(productTable, /window\.HSOverlay\.open\(`#hs-pro-edit\$\{product\.id\}`\)/);
});
