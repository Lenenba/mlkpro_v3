import { expect, test } from '@playwright/test';
import { loadFixtures } from './helpers/app.mjs';

test('public storefront exposes its Ziggy routes without the portal or platform map', async ({ page }) => {
    const fixtures = loadFixtures();

    await page.goto(fixtures.publicStore.path);
    await expect(page.getByText(fixtures.publicStore.companyName).first()).toBeVisible();

    const routeNames = await page.evaluate(() => Object.keys(Ziggy.routes));

    expect(routeNames).toContain('public.store.show');
    expect(routeNames).toContain('login');
    expect(routeNames).not.toContain('portal.orders.index');
    expect(routeNames).not.toContain('superadmin.tenants.index');
});

test('crossing from public to onboarding reloads the full Ziggy boundary document', async ({ page }) => {
    await page.goto('/');
    await expect(page.locator('html')).toHaveAttribute('data-ziggy-group', 'public');

    const onboardingLink = page.locator('a[href^="/onboarding"]').first();
    await expect(onboardingLink).toBeVisible();

    await Promise.all([
        page.waitForURL((url) => url.pathname === '/onboarding'),
        onboardingLink.click(),
    ]);

    await expect(page.locator('html')).toHaveAttribute('data-ziggy-group', 'full');
});

test('login replaces the complete Ziggy map with the admin surface map', async ({ page }) => {
    const fixtures = loadFixtures();

    await page.goto('/login');
    await expect(page.locator('html')).toHaveAttribute('data-ziggy-group', 'full');

    await page.getByLabel('Email').fill(fixtures.serviceOwner.email);
    await page.getByLabel('Password').fill(fixtures.serviceOwner.password);

    await Promise.all([
        page.waitForURL((url) => url.pathname === fixtures.serviceOwner.dashboardPath),
        page.getByRole('button', { name: /log in/i }).click(),
    ]);

    await expect(page.locator('html')).toHaveAttribute('data-ziggy-group', 'admin');

    const routeNames = await page.evaluate(() => Object.keys(Ziggy.routes));

    expect(routeNames).toContain('dashboard', 'customer.index');
    expect(routeNames).not.toContain('public.store.show', 'portal.orders.index');
});
