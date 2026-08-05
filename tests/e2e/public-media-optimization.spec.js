import { expect, test } from '@playwright/test';
import { loadFixtures } from './helpers/app.mjs';

const expectResponsiveWelcomeHero = async (page) => {
    await page.goto('/');

    const hero = page.locator('picture.welcome-hero-visual-image').first();
    const image = hero.locator('img');

    await expect(hero).toBeVisible();
    await expect(hero.locator('source[type="image/avif"]')).toHaveCount(1);
    await expect(hero.locator('source[type="image/webp"]')).toHaveCount(1);
    await expect(image).toHaveAttribute('loading', 'eager');
    await expect(image).toHaveAttribute('fetchpriority', 'high');
    await expect(image).toHaveAttribute('width', /\d+/);
    await expect(image).toHaveAttribute('height', /\d+/);

    const box = await hero.boundingBox();
    expect(box?.width).toBeGreaterThan(0);
    expect(box?.height).toBeGreaterThan(0);
    await expect(hero).toHaveCSS('position', 'absolute');
    await expect(image).toHaveCSS('object-fit', 'cover');

    await expect(image).toHaveAttribute('src', /\/images\/landing\/stock\/.+\.jpg$/);

    await expect.poll(async () => image.evaluate((node) => node.currentSrc)).toMatch(
        /\/images\/landing\/stock\/optimized\/.+-\d+w\.(avif|webp)$/,
    );

    const currentSrc = await image.evaluate((node) => node.currentSrc);

    const response = await page.request.get(new URL(currentSrc).pathname);
    expect(response.headers()['content-type']).toContain(currentSrc.endsWith('.avif') ? 'image/avif' : 'image/webp');
};

test('public welcome hero uses responsive critical media without layout collapse on desktop', async ({ page }) => {
    await expectResponsiveWelcomeHero(page);
});

test('public showcase preserves the cover hero styling through the responsive image component', async ({ page }) => {
    const fixtures = loadFixtures();

    await page.goto(fixtures.publicShowcase.path);

    const hero = page.locator('.showcase-hero-image picture').first();
    const image = hero.locator('img');

    await expect(hero).toBeVisible();
    await expect(image).toHaveCSS('object-fit', 'cover');
    await expect(image).toHaveAttribute('loading', 'eager');
    await expect(image).toHaveAttribute('fetchpriority', 'high');
});

test.describe('on mobile', () => {
    test.use({ viewport: { width: 390, height: 844 } });

    test('public welcome hero uses responsive critical media without layout collapse', async ({ page }) => {
        await expectResponsiveWelcomeHero(page);
    });
});
