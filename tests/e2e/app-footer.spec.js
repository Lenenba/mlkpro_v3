import { expect, test } from '@playwright/test';
import { loadFixtures, loginAs } from './helpers/app.mjs';

const seedCookieConsent = async (page) => {
    await page.context().addCookies([{
        name: 'mlk_cookie_prefs_v1',
        value: encodeURIComponent(JSON.stringify({ essential: true, analytics: false })),
        url: 'http://127.0.0.1:38103',
    }]);
};

test('workspace footer is unique, unobstructed and crosses safely to public legal pages', async ({ page }) => {
    const fixtures = loadFixtures();
    await seedCookieConsent(page);
    await loginAs(page, fixtures.serviceOwner);

    const footer = page.getByTestId('app-footer');
    await expect(footer).toHaveCount(1);
    await expect(footer).toHaveAttribute('data-variant', 'platform');
    await footer.scrollIntoViewIfNeeded();
    await expect(footer).toBeVisible();

    const footerState = await footer.evaluate((element) => {
        const rect = element.getBoundingClientRect();
        const cookieButton = element.querySelector('[data-testid="app-footer-cookie-preferences"]');
        const cookieRect = cookieButton.getBoundingClientRect();
        const topElement = document.elementFromPoint(
            cookieRect.left + (cookieRect.width / 2),
            cookieRect.top + (cookieRect.height / 2),
        );

        return {
            position: getComputedStyle(element).position,
            outsideMain: !element.closest('main'),
            insideViewport: rect.left >= 0 && rect.right <= window.innerWidth,
            cookieOnTop: topElement === cookieButton || cookieButton.contains(topElement),
        };
    });

    expect(footerState).toEqual({
        position: 'relative',
        outsideMain: true,
        insideViewport: true,
        cookieOnTop: true,
    });

    await Promise.all([
        page.waitForURL((url) => url.pathname === '/terms'),
        page.getByTestId('app-footer-terms').click(),
    ]);

    await expect(page.locator('html')).toHaveAttribute('data-ziggy-group', /(?:^|,)public(?:,|$)/);
    await expect(page.getByTestId('app-footer')).toHaveCount(0);
    await expect(page.locator('footer.public-site-footer')).toHaveCount(1);
});

test('nested settings inherit one footer and the reservation screen opts out', async ({ page }) => {
    const fixtures = loadFixtures();
    await seedCookieConsent(page);
    await loginAs(page, fixtures.serviceOwner);

    await page.goto('/settings/company');
    await expect(page.getByTestId('app-footer')).toHaveCount(1);

    await page.goto('/app/reservations/screen');
    await expect(page.getByTestId('app-footer')).toHaveCount(0);
});

test('guest footer stays responsive, uses tenant attribution and restores cookie-dialog focus', async ({ page }) => {
    const fixtures = loadFixtures();
    await seedCookieConsent(page);
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/login');

    const footer = page.getByTestId('app-footer');
    await expect(footer).toHaveCount(1);
    await expect(footer).toHaveAttribute('data-variant', 'platform');
    await footer.scrollIntoViewIfNeeded();

    const footerBox = await footer.boundingBox();
    expect(footerBox.x).toBeGreaterThanOrEqual(0);
    expect(footerBox.x + footerBox.width).toBeLessThanOrEqual(390);

    const cookieButton = page.getByTestId('app-footer-cookie-preferences');
    await cookieButton.click();

    const dialog = page.getByRole('dialog');
    await expect(dialog).toBeVisible();
    await expect(dialog.locator('input:not([disabled])')).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();
    await expect(cookieButton).toBeFocused();

    await page.goto(fixtures.tenantBranding.publicRequestPath);
    await expect(page.getByTestId('app-footer')).toHaveCount(1);
    await expect(page.getByTestId('app-footer')).toHaveAttribute('data-variant', 'powered-by');
    await expect(page.getByText(/Powered by Malikia Pro|Propulsé par Malikia Pro|Con tecnología de Malikia Pro/)).toBeVisible();
});
