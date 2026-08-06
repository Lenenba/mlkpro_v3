import { expect, test } from '@playwright/test';
import { loadFixtures, loginAs } from './helpers/app.mjs';

const MODAL = '#hs-service-upsert';

// The cookie banner is a fixed bottom overlay that intercepts clicks on the
// modal footer buttons.
const dismissCookieBanner = async (page) => {
    const accept = page.getByRole('button', { name: 'Accept all' });
    // The banner mounts a moment after the page becomes interactive.
    await accept.waitFor({ state: 'visible', timeout: 10_000 }).catch(() => {});
    if (await accept.isVisible().catch(() => false)) {
        await accept.click();
        await expect(accept).toBeHidden();
    }
};

const openCreateModal = async (page) => {
    await page.getByRole('button', { name: 'Add service' }).click();
    await expect(page.locator(MODAL)).toBeVisible();
    // Preline animates the overlay in; wait for it to settle before interacting.
    await page.waitForTimeout(500);
};

// Required FloatingInput labels render as "<label> *".
const nameField = (page) => page.locator(MODAL).getByLabel('Name *', { exact: true });

test('the create form starts empty after a service has just been created', async ({ page }) => {
    const fixtures = loadFixtures();
    await loginAs(page, fixtures.serviceOwner);

    await page.goto('/service');
    await dismissCookieBanner(page);

    await openCreateModal(page);
    await nameField(page).fill('E2E Reset Probe');
    await page.locator(MODAL).getByRole('button', { name: 'Create service' }).click();

    await expect(page.locator(MODAL)).toBeHidden();
    await expect(page.getByText('E2E Reset Probe').first()).toBeVisible();

    // Reopening the create modal must give a blank form, not the values that
    // were just saved.
    await openCreateModal(page);
    await expect(nameField(page)).toHaveValue('');
});

test('save and create another keeps the modal open with a blank form', async ({ page }) => {
    const fixtures = loadFixtures();
    await loginAs(page, fixtures.serviceOwner);

    await page.goto('/service');
    await dismissCookieBanner(page);

    await openCreateModal(page);
    await nameField(page).fill('E2E Chained Probe');
    await page.locator(MODAL).getByRole('button', { name: 'Save and create another' }).click();

    await expect(page.locator(MODAL)).toBeVisible();
    await expect(nameField(page)).toHaveValue('');

    await nameField(page).fill('E2E Chained Probe 2');
    await page.locator(MODAL).getByRole('button', { name: 'Create service' }).click();

    await expect(page.locator(MODAL)).toBeHidden();
    await expect(page.getByText('E2E Chained Probe', { exact: true }).first()).toBeVisible();
    await expect(page.getByText('E2E Chained Probe 2', { exact: true }).first()).toBeVisible();
});
