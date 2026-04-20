import { expect, test } from '@playwright/test';
import { loadFixtures, loginAs } from './helpers/app.mjs';

test('service owner can run the request inbox smoke flow', async ({ page }) => {
    const fixtures = loadFixtures();
    const inbox = fixtures.requestInbox;

    await loginAs(page, fixtures.serviceOwner);
    await page.goto(inbox.path);

    await expect(page.getByText(inbox.breachedLeadTitle).first()).toBeVisible();
    await expect(page.getByText(inbox.dueSoonLeadTitle).first()).toBeVisible();

    const statusTrigger = page.getByTestId(`request-status-trigger-${inbox.newLeadId}`);
    await statusTrigger.click();

    const contactedOption = page.getByTestId(`request-status-option-${inbox.newLeadId}-REQ_CONTACTED`);
    await expect(contactedOption).toBeVisible();
    const contactedLabel = ((await contactedOption.textContent()) || '').trim();
    await contactedOption.click();
    await expect(statusTrigger).toContainText(contactedLabel);

    await page.getByTestId(`request-select-${inbox.staleLeadId}`).check();
    await expect(page.getByTestId('request-bulk-bar')).toBeVisible();
    await page.getByTestId('request-bulk-assignee').selectOption(String(inbox.assigneeId));
    await page.getByTestId('request-bulk-assign-submit').click();
    await expect(page.getByTestId(`request-row-${inbox.staleLeadId}`)).toContainText(inbox.assigneeName);

    await page.getByTestId('request-queue-filter-due_soon').click();
    await expect(page).toHaveURL(/(?:\?|&)queue=due_soon\b/);
    await expect(page.getByTestId(`request-row-${inbox.dueSoonLeadId}`)).toBeVisible();
    await expect(page.getByTestId(`request-row-${inbox.staleLeadId}`)).toHaveCount(0);

    await page.getByTestId('request-view-board').click();
    await expect(page).toHaveURL(/(?:\?|&)view=board\b/);
    await expect(page.getByTestId(`request-board-card-${inbox.dueSoonLeadId}`)).toBeVisible();

    await page.getByTestId('request-view-table').click();
    await expect(page).toHaveURL(/(?:\?|&)view=table\b/);
    await page.getByTestId('request-queue-filter-all').click();
    await expect(page.getByTestId(`request-convert-${inbox.convertLeadId}`)).toBeVisible();

    await page.getByTestId(`request-convert-${inbox.convertLeadId}`).click();
    await expect(page.getByTestId('request-convert-submit')).toBeVisible();
    await page.getByTestId('request-convert-submit').click();

    await expect(page).toHaveURL(/\/customer\/quote\/\d+\/edit/);
    await expect(page.getByRole('heading', { name: /Quote for/i })).toBeVisible();
    await expect(page.getByRole('textbox', { name: 'Job title' })).toHaveValue(inbox.convertLeadTitle);
});
