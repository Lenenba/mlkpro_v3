import { expect, test } from '@playwright/test';
import { loadFixtures, loginAs } from './helpers/app.mjs';

test.use({ viewport: { width: 390, height: 844 } });

test('Preline reinitializes the mobile sidebar after an Inertia navigation', async ({ page }) => {
    const fixtures = loadFixtures();
    const sidebar = page.locator('#hs-pro-sidebar');
    const sidebarBackdrop = page.locator('#hs-pro-sidebar-backdrop');
    const sidebarToggle = page.getByRole('button', { name: 'Toggle navigation' });

    await loginAs(page, fixtures.serviceOwner);
    await page.goto(fixtures.requestInbox.path);
    await expect(page.getByTestId(`request-row-${fixtures.requestInbox.dueSoonLeadId}`)).toBeVisible();

    await sidebarToggle.click();
    await expect(sidebar).toBeVisible();

    const dashboardLink = sidebar.getByRole('link', { name: 'Dashboard', exact: true });
    await expect(dashboardLink).toBeVisible();

    await Promise.all([
        page.waitForURL((url) => url.pathname === fixtures.serviceOwner.dashboardPath),
        dashboardLink.click(),
    ]);

    await expect(page.getByTestId('demo-dashboard-overview')).toBeVisible();

    // Laisser passer le rendu déclenché par l'événement Inertia "navigate".
    await page.evaluate(() => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve))));

    await expect(sidebar).toBeHidden();
    await expect(sidebarBackdrop).toHaveCount(0);
    await expect(sidebarToggle).toHaveAttribute('aria-expanded', 'false');

    await sidebarToggle.click();
    await expect(sidebar).toHaveClass(/\bopened\b/);
    await expect(sidebarToggle).toHaveAttribute('aria-expanded', 'true');

    await page.keyboard.press('Escape');
    await expect(sidebar).toBeHidden();
});
