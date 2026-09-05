import { expect, test } from '@playwright/test';
import { loadFixtures, loginAs } from './helpers/app.mjs';

test('tenant branding is primary in the workspace and on a public request page', async ({ page }) => {
    const fixtures = loadFixtures();
    const branding = fixtures.tenantBranding;

    await loginAs(page, fixtures.serviceOwner);

    const sidebarBrand = page.locator('aside header').getByRole('link', {
        name: branding.companyName,
    });
    await expect(sidebarBrand).toBeVisible();
    await expect(sidebarBrand.locator('img').first()).toHaveAttribute('src', branding.logoUrl);

    await page.goto(branding.publicRequestPath);

    await expect(page.locator(`img[alt="${branding.companyName}"]`)).toHaveCount(1);
    await expect(page.getByText(branding.companyName).first()).toBeVisible();
    await expect(page.getByText(/Powered by Malikia Pro|Propulsé par Malikia Pro|Con tecnología de Malikia Pro/)).toBeVisible();
});

test('an unreachable tenant logo falls back without leaving a broken image', async ({ page }) => {
    const fixtures = loadFixtures();
    const branding = fixtures.tenantBranding;

    await page.route(`**${branding.logoUrl}`, async (route) => {
        await route.abort('failed');
    });

    await page.goto(branding.publicRequestPath);

    await expect(page.locator(`img[alt="${branding.companyName}"]`)).toHaveCount(0);
    await expect(page.locator('img[alt="Malikia pro logo"]:visible')).toHaveCount(1);
    await expect(page.getByText(branding.companyName).first()).toBeVisible();
    await expect(page.getByText(/Powered by Malikia Pro|Propulsé par Malikia Pro|Con tecnología de Malikia Pro/)).toBeVisible();
});

test('public company pages never leak the logo of another tenant', async ({ page }) => {
    const fixtures = loadFixtures();
    const showcase = fixtures.publicShowcase;
    const store = fixtures.publicStore;

    await page.goto(showcase.path);

    await expect(page.locator(`img[alt="${showcase.companyName}"]`)).toHaveCount(1);
    await expect(page.locator(`img[src="${store.logoUrl}"]`)).toHaveCount(0);

    await page.goto(store.path);

    await expect(page.locator(`img[alt="${store.companyName}"]`)).toHaveCount(1);
    await expect(page.locator(`img[src="${showcase.logoUrl}"]`)).toHaveCount(0);
    await expect(page.getByRole('link', { name: store.companyName, exact: true })).toHaveAttribute('href', store.path);
});
