import { expect, test } from '@playwright/test';
import { loadFixtures, loginAs } from './helpers/app.mjs';

test.use({ viewport: { width: 1024, height: 480 } });

test('reservation actions open immediately after switching to the locally rendered list', async ({ page }) => {
    const fixtures = loadFixtures();
    const reservations = fixtures.reservations;

    await page.goto('/login');
    await page.getByRole('button', { name: /reject all/i }).click();
    await loginAs(page, fixtures.serviceOwner);
    await page.goto(reservations.path);
    await expect(page.getByTestId('reservation-view-calendar')).toBeVisible();

    const immediateOpen = await page.evaluate(({ firstId }) => new Promise((resolve, reject) => {
        const probe = crypto.randomUUID();
        const startedAt = performance.now();
        let triggerClicked = false;

        window.__reservationMenuProbe = probe;

        const cleanup = () => {
            observer.disconnect();
            clearTimeout(timeout);
        };
        const inspect = () => {
            const trigger = document.querySelector(`[data-testid="reservation-actions-trigger-${firstId}"]`);
            if (trigger && !triggerClicked) {
                triggerClicked = true;
                trigger.scrollIntoView({ block: 'nearest', inline: 'nearest' });
                trigger.click();
            }

            const menu = document.querySelector(`[data-testid="reservation-actions-menu-${firstId}"]`);
            if (menu && window.getComputedStyle(menu).visibility !== 'hidden') {
                cleanup();
                resolve({ elapsed: performance.now() - startedAt, probe });
            }
        };
        const observer = new MutationObserver(inspect);
        const timeout = setTimeout(() => {
            cleanup();
            reject(new Error('Reservation action menu did not open before the list refresh.'));
        }, 275);

        observer.observe(document.body, {
            attributes: true,
            childList: true,
            subtree: true,
        });
        document.querySelector('[data-testid="reservation-view-list"]')?.click();
        inspect();
    }), { firstId: reservations.firstId });

    expect(immediateOpen.elapsed).toBeLessThan(275);
    await expect(page.getByTestId(`reservation-actions-menu-${reservations.firstId}`)).toBeVisible();
    expect(await page.evaluate(() => window.__reservationMenuProbe)).toBe(immediateOpen.probe);

    await expect(page).toHaveURL(/(?:\?|&)view_mode=list\b/);
    await expect(page.getByTestId(`reservation-actions-trigger-${reservations.lastId}`)).toBeVisible();

    const lastTrigger = page.getByTestId(`reservation-actions-trigger-${reservations.lastId}`);
    await lastTrigger.evaluate((trigger) => {
        trigger.scrollIntoView({ block: 'end', inline: 'nearest' });
        window.scrollBy(0, -12);
    });
    await lastTrigger.click();

    const lastMenu = page.getByTestId(`reservation-actions-menu-${reservations.lastId}`);
    await expect(lastMenu).toBeVisible();
    await expect(page.locator('[data-admin-data-table-actions-menu]')).toHaveCount(1);

    const menuLayer = await lastMenu.evaluate((menu, triggerTestId) => {
        const trigger = document.querySelector(`[data-testid="${triggerTestId}"]`);
        const menuBounds = menu.getBoundingClientRect();
        const triggerBounds = trigger.getBoundingClientRect();
        const topElement = document.elementFromPoint(
            menuBounds.left + (menuBounds.width / 2),
            menuBounds.top + (menuBounds.height / 2),
        );

        return {
            teleported: menu.parentElement === document.body,
            triggerInLowerHalf: triggerBounds.top > window.innerHeight / 2,
            flippedAbove: menuBounds.bottom <= triggerBounds.top,
            insideViewport: menuBounds.left >= 0
                && menuBounds.top >= 0
                && menuBounds.right <= window.innerWidth
                && menuBounds.bottom <= window.innerHeight,
            onTop: menu === topElement || menu.contains(topElement),
        };
    }, `reservation-actions-trigger-${reservations.lastId}`);

    expect(menuLayer).toEqual({
        teleported: true,
        triggerInLowerHalf: true,
        flippedAbove: true,
        insideViewport: true,
        onTop: true,
    });

    await page.keyboard.press('Escape');
    await expect(lastMenu).toHaveCount(0);
    await expect(lastTrigger).toBeFocused();
});
