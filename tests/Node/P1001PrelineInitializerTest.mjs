import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import test from 'node:test';

import { createPrelineInitializer, refreshPrelineOverlays } from '../../resources/js/utils/preline.js';

test('coalesces repeated requests into one Preline initialization per render', () => {
    const callbacks = [];
    const calls = [];
    const runtimeWindow = {
        HSStaticMethods: {
            autoInit: () => calls.push('auto-init'),
        },
    };
    const initialize = createPrelineInitializer({
        getWindow: () => runtimeWindow,
        beforeInitialize: () => calls.push('prepare-tabs'),
        schedule: (_runtimeWindow, callback) => callbacks.push(callback),
    });

    initialize();
    initialize();

    assert.equal(callbacks.length, 1);

    callbacks.shift()();

    assert.deepEqual(calls, ['prepare-tabs', 'auto-init']);

    initialize();

    assert.equal(callbacks.length, 1);
});

test('recovers after Preline initialization errors', () => {
    const callbacks = [];
    const errors = [];
    const runtimeWindow = {
        HSStaticMethods: {
            autoInit: () => {
                throw new Error('invalid fixture');
            },
        },
    };
    const initialize = createPrelineInitializer({
        getWindow: () => runtimeWindow,
        onError: (error) => errors.push(error.message),
        schedule: (_runtimeWindow, callback) => callbacks.push(callback),
    });

    initialize();
    callbacks.shift()();
    initialize();

    assert.deepEqual(errors, ['invalid fixture']);
    assert.equal(callbacks.length, 1);
});

test('removes stale Preline overlays before their replaced triggers are rebound', () => {
    const removedAttributes = [];
    const overlayClasses = new Set(['open', 'opened']);
    const overlay = {
        id: 'hs-pro-sidebar',
        classList: {
            add: (name) => overlayClasses.add(name),
        },
        removeAttribute: (name) => removedAttributes.push(name),
    };
    const staleToggle = {};
    const currentToggle = {
        getAttribute: () => '#hs-pro-sidebar',
    };
    const backdrop = {
        removed: false,
        remove() {
            this.removed = true;
        },
    };
    const runtimeWindow = {
        $hsOverlayCollection: [{
            element: {
                el: overlay,
                toggleButtons: [staleToggle],
                closed: false,
                destroyed: false,
                close() {
                    this.closed = true;
                },
                destroy() {
                    this.destroyed = true;
                },
            },
        }],
        document: {
            body: { style: { overflow: 'hidden' } },
            contains: (node) => node === currentToggle || node === overlay,
            getElementById: () => backdrop,
            querySelector: () => null,
            querySelectorAll: () => [currentToggle],
        },
    };

    refreshPrelineOverlays({ getWindow: () => runtimeWindow });

    assert.equal(runtimeWindow.$hsOverlayCollection[0].element.closed, true);
    assert.equal(runtimeWindow.$hsOverlayCollection[0].element.destroyed, true);
    assert.equal(overlayClasses.has('hidden'), true);
    assert.deepEqual(removedAttributes, ['aria-overlay', 'tabindex']);
    assert.equal(backdrop.removed, true);
    assert.equal(runtimeWindow.document.body.style.overflow, '');
});

test('wires the application to a single mount and Inertia navigation initializer', () => {
    const appSource = readFileSync(resolve('resources/js/app.js'), 'utf8');

    assert.match(appSource, /const initializePreline = createPrelineInitializer/);
    assert.match(appSource, /refreshPrelineOverlays\(\);\s+ensurePrelineTabsHaveActive\(\);/);
    assert.match(appSource, /const mountedApp = vueApp\.mount\(el\);\s+initializePreline\(\);/);
    assert.match(appSource, /router\.on\('navigate', initializePreline\);/);
    assert.doesNotMatch(appSource, /vueApp\.mixin\(/);
});
