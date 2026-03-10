import { expect, test } from '@playwright/test';
import { loadFixtures } from './helpers/app.mjs';

test('public storefront smoke flow renders seeded company and product data', async ({ page }) => {
    const fixtures = loadFixtures();

    await page.goto(fixtures.publicStore.path);

    await expect(page.getByText(fixtures.publicStore.companyName).first()).toBeVisible();
    await expect(page.getByText(fixtures.publicStore.productName).first()).toBeVisible();
});
