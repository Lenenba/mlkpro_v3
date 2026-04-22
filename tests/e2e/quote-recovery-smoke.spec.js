import { expect, test } from '@playwright/test';
import { loadFixtures, loginAs } from './helpers/app.mjs';

const buildQuoteSearchPath = (basePath, quoteNumber) => {
    const url = new URL(basePath, 'http://127.0.0.1');

    url.searchParams.set('direction', 'desc');
    url.searchParams.set('per_page', '10');
    url.searchParams.set('sort', 'recovery_priority');
    url.searchParams.set('search', quoteNumber);

    return `${url.pathname}${url.search}`;
};

const openQuoteTable = async (page, path) => {
    await page.goto(path);
    await page.getByTestId('quote-view-table').click();
};

test('service owner can run the quote recovery smoke flow', async ({ page }) => {
    const fixtures = loadFixtures();
    const recovery = fixtures.quoteRecovery;

    await loginAs(page, fixtures.serviceOwner);
    const resetResponse = await page.goto('/_e2e/reset/quote-recovery');
    expect(resetResponse?.ok()).toBe(true);

    await openQuoteTable(page, recovery.path);

    const dueRow = page.getByTestId(`quote-row-${recovery.dueQuoteId}`);
    await expect(dueRow).toBeVisible();

    await page.getByTestId('quote-queue-filter-due').click();
    await expect(page).toHaveURL(/(?:\?|&)queue=due\b/);
    await expect(dueRow).toBeVisible();

    await page.getByTestId(`quote-follow-up-seven_days-${recovery.dueQuoteId}`).click();
    await expect(dueRow).toHaveCount(0);

    await page.getByTestId('quote-queue-filter-due').click();
    await expect(page).not.toHaveURL(/(?:\?|&)queue=due\b/);

    await openQuoteTable(page, buildQuoteSearchPath(recovery.path, recovery.archiveQuoteNumber));
    const archiveRow = page.getByTestId(`quote-row-${recovery.archiveQuoteId}`);
    await expect(archiveRow).toBeVisible();
    page.once('dialog', (dialog) => dialog.accept());
    await page.getByTestId(`quote-archive-inline-${recovery.archiveQuoteId}`).click();
    await expect(archiveRow).toHaveCount(0);

    await openQuoteTable(page, buildQuoteSearchPath(recovery.path, recovery.acceptQuoteNumber));
    const acceptRow = page.getByTestId(`quote-row-${recovery.acceptQuoteId}`);
    await expect(acceptRow).toBeVisible();

    await acceptRow.scrollIntoViewIfNeeded();
    await acceptRow.getByTestId(`quote-actions-trigger-${recovery.acceptQuoteId}`).click();
    const acceptAction = acceptRow.getByTestId(`quote-accept-${recovery.acceptQuoteId}`);
    await expect(acceptAction).toBeVisible();

    await Promise.all([
        page.waitForURL(/\/work\/\d+\/edit/),
        acceptAction.click(),
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
