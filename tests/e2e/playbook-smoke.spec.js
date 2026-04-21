import { expect, test } from '@playwright/test';
import { jsonRequest, loadFixtures, loginAs } from './helpers/app.mjs';

test('service owner can save a request segment, run a manual playbook, and audit the run history', async ({ page }) => {
    const fixtures = loadFixtures();
    const inbox = fixtures.requestInbox;
    const segmentName = 'E2E Due Soon Segment';
    const playbookName = 'E2E Assign Due Soon Requests';

    await loginAs(page, fixtures.serviceOwner);
    await page.goto(inbox.path);

    await page.getByTestId('request-queue-filter-due_soon').click();
    await expect(page).toHaveURL(/(?:\?|&)queue=due_soon\b/);
    await expect(page.getByTestId(`request-row-${inbox.dueSoonLeadId}`)).toBeVisible();
    await expect(page.getByTestId(`request-row-${inbox.breachedLeadId}`)).toHaveCount(0);

    await page.getByTestId('saved-segment-open-request').click();
    await expect(page.getByTestId('saved-segment-modal-request')).toBeVisible();
    await page.getByTestId('saved-segment-name-request').fill(segmentName);
    await page.getByTestId('saved-segment-save-request').click();

    await expect(page.getByTestId('saved-segment-info-request')).toBeVisible();
    await expect(page.getByTestId('saved-segment-select-request')).toHaveValue(segmentName);

    const segmentsPayload = await jsonRequest(page, 'GET', '/crm/saved-segments?module=request');
    expect(segmentsPayload.ok).toBe(true);

    const segment = segmentsPayload.body?.segments?.find((item) => item.name === segmentName);
    expect(segment).toBeTruthy();

    await page.keyboard.press('Escape');
    await expect(page.getByTestId('saved-segment-modal-request')).toBeHidden();

    const createPlaybookPayload = await jsonRequest(page, 'POST', '/crm/playbooks', {
        saved_segment_id: segment.id,
        name: playbookName,
        action_key: 'assign_selected',
        action_payload: {
            assigned_team_member_id: inbox.assigneeId,
        },
    });

    expect(createPlaybookPayload.ok).toBe(true);
    expect(createPlaybookPayload.body?.playbook?.name).toBe(playbookName);

    const playbookId = createPlaybookPayload.body?.playbook?.id;

    const runPayload = await jsonRequest(page, 'POST', `/crm/playbooks/${playbookId}/run`, {});
    expect(runPayload.ok).toBe(true);
    expect(runPayload.body?.run?.status).toBe('completed');
    expect(runPayload.body?.run?.selected_count).toBe(1);
    expect(runPayload.body?.run?.processed_count).toBe(1);
    expect(runPayload.body?.run?.success_count).toBe(1);
    expect(runPayload.body?.run?.failed_count).toBe(0);
    expect(runPayload.body?.run?.skipped_count).toBe(0);
    expect(runPayload.body?.run?.summary?.message).toBe('Requests updated.');
    expect(runPayload.body?.run?.summary?.processed_ids).toEqual([inbox.dueSoonLeadId]);

    await page.getByTestId('saved-segment-history-request').click();
    await expect(page).toHaveURL(/\/crm\/playbook-runs(?:\?|$)/);
    await expect(page).toHaveURL(/(?:\?|&)module=request\b/);

    const runRow = page.getByTestId(`playbook-run-row-${runPayload.body.run.id}`);
    await expect(runRow).toBeVisible();
    await expect(runRow).toContainText(playbookName);
    await expect(runRow).toContainText(segmentName);
    await expect(runRow).toContainText('Requests updated.');
    await expect(page.getByTestId('playbook-runs-card-completed')).toContainText('1');
    await expect(page.getByTestId('playbook-runs-card-processed')).toContainText('1');
});
