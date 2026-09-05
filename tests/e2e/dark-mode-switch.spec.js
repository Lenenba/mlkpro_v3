import { expect, test } from '@playwright/test';
import { loadFixtures, loginAs } from './helpers/app.mjs';

const themeState = (page) => page.evaluate(() => ({
    isDark: document.documentElement.classList.contains('dark'),
    storedTheme: localStorage.getItem('hs_theme'),
}));

test('the account switch applies and persists dark mode without a refresh workaround', async ({ page }) => {
    const fixtures = loadFixtures();
    const accountMenuTrigger = page.getByTestId('account-menu-trigger');
    const accountMenu = page.getByTestId('account-menu');
    const darkModeSwitch = page.getByTestId('dark-mode-switch');

    await loginAs(page, fixtures.serviceOwner);
    await page.evaluate(() => {
        localStorage.setItem('hs_theme', 'default');
        document.documentElement.classList.remove('light', 'dark', 'auto');
        document.documentElement.classList.add('default');
    });
    await page.goto('/settings/billing');

    await accountMenuTrigger.click();
    await expect(darkModeSwitch).not.toBeChecked();
    await darkModeSwitch.click();

    await expect.poll(() => themeState(page)).toEqual({
        isDark: true,
        storedTheme: 'dark',
    });

    await Promise.all([
        page.waitForURL((url) => url.pathname === '/profile'),
        accountMenu.locator('a[href$="/profile"]').click(),
    ]);

    await accountMenuTrigger.click();
    await expect(darkModeSwitch).toBeChecked();
    await expect.poll(() => themeState(page)).toEqual({
        isDark: true,
        storedTheme: 'dark',
    });

    await page.reload();
    await expect.poll(() => themeState(page)).toEqual({
        isDark: true,
        storedTheme: 'dark',
    });

    await accountMenuTrigger.click();
    await expect(darkModeSwitch).toBeChecked();
    await darkModeSwitch.click();

    await expect.poll(() => themeState(page)).toEqual({
        isDark: false,
        storedTheme: 'default',
    });
});
