import { expect, test } from '@playwright/test';
import { loadFixtures, loginAs } from './helpers/app.mjs';

test('service owner can run the quote recovery smoke flow', async ({ page }) => {
    const fixtures = loadFixtures();
    const recovery = fixtures.quoteRecovery;

    await loginAs(page, fixtures.serviceOwner);
    await page.goto(recovery.path);

    const dueRow = page.getByTestId(`quote-row-${recovery.dueQuoteId}`);
    await expect(dueRow).toBeVisible();

    await page.getByTestId('quote-queue-filter-due').click();
    await expect(page).toHaveURL(/(?:\?|&)queue=due\b/);
    await expect(dueRow).toBeVisible();

    await page.getByTestId(`quote-follow-up-seven_days-${recovery.dueQuoteId}`).click();
    await expect(dueRow).toHaveCount(0);

    await page.getByTestId('quote-queue-filter-due').click();
    await expect(page).not.toHaveURL(/(?:\?|&)queue=due\b/);

    const searchInput = page.getByTestId('demo-quote-search');

    await searchInput.fill(recovery.archiveQuoteNumber);
    const archiveRow = page.getByTestId(`quote-row-${recovery.archiveQuoteId}`);
    await expect(archiveRow).toBeVisible();
    page.once('dialog', (dialog) => dialog.accept());
    await page.getByTestId(`quote-archive-inline-${recovery.archiveQuoteId}`).click();
    await expect(archiveRow).toHaveCount(0);

    await searchInput.fill(recovery.acceptQuoteNumber);
    const acceptRow = page.getByTestId(`quote-row-${recovery.acceptQuoteId}`);
    await expect(acceptRow).toBeVisible();

    await acceptRow.getByTestId(`quote-actions-trigger-${recovery.acceptQuoteId}`).click();
    await expect(page.getByTestId(`quote-accept-${recovery.acceptQuoteId}`)).toBeVisible();

    await Promise.all([
        page.waitForURL(/\/work\/\d+\/edit/),
        page.getByTestId(`quote-accept-${recovery.acceptQuoteId}`).click(),
    ]);

    await expect(page).toHaveURL(/\/work\/\d+\/edit/);
    await expect(page.getByLabel('Job title')).toHaveValue(recovery.acceptQuoteTitle);

    const requestPayload = await page.evaluate(async ({ requestPath }) => {
        const response = await fetch(requestPath, {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        return {
            ok: response.ok,
            status: response.status,
            body: await response.json(),
        };
    }, {
        requestPath: recovery.requestPath,
    });

    expect(requestPayload.ok).toBe(true);
    expect(requestPayload.body.lead.status).toBe('REQ_WON');
    expect(requestPayload.body.lead.quote.id).toBe(recovery.acceptQuoteId);
});
