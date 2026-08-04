import { expect, test } from '@playwright/test';
import { loadFixtures, loginAs } from './helpers/app.mjs';

test('public welcome page switches FR → ES → EN without displaying raw translation keys', async ({ page }) => {
    await page.goto('/');

    const languageToggle = page.getByTestId('public-language-switcher-toggle');
    const initialLocale = await page.locator('html').getAttribute('lang');

    if (initialLocale !== 'fr') {
        await languageToggle.click();
        await page.locator('[role="option"][data-locale="fr"]').click();
        await expect(page.locator('html')).toHaveAttribute('lang', 'fr');
    }

    await languageToggle.click();
    await page.locator('[role="option"][data-locale="es"]').click();
    await expect(page.locator('html')).toHaveAttribute('lang', 'es');
    await expect(page.locator('body')).not.toContainText('welcome.hero.title');

    await languageToggle.click();
    await page.locator('[role="option"][data-locale="en"]').click();
    await expect(page.locator('html')).toHaveAttribute('lang', 'en');
    await expect(page.locator('body')).not.toContainText('language.en');
});

test('authenticated dashboard preloads the target locale before its Inertia render', async ({ page }) => {
    const fixtures = loadFixtures();

    await loginAs(page, fixtures.serviceOwner);
    await expect(page.getByTestId('demo-dashboard-overview')).toBeVisible();

    const initialLocale = await page.locator('html').getAttribute('lang');
    const targetLocale = initialLocale === 'fr' ? 'en' : 'fr';

    await page.getByTestId('language-switcher-toggle').click();
    await page.locator(`[role="menuitemradio"][data-locale="${targetLocale}"]`).click();
    await expect(page.locator('html')).toHaveAttribute('lang', targetLocale);
    await expect(page.getByTestId('demo-dashboard-overview')).toBeVisible();
    await expect(page.locator('body')).not.toContainText('dashboard.title');

    await page.getByTestId('language-switcher-toggle').click();
    await page.locator(`[role="menuitemradio"][data-locale="${initialLocale}"]`).click();
    await expect(page.locator('html')).toHaveAttribute('lang', initialLocale);
});
