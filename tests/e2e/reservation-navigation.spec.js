import { expect, test } from '@playwright/test';
import { installReservationUiFixture } from './helpers/reservation-ui.mjs';

test.use({ baseURL: 'http://reservation-ui.test', serviceWorkers: 'block' });

const path = '/app/reservations';
const futureQuery = '?scope=all&view_mode=calendar&calendar_view=month&calendar_date=2031-11-18';
const quickFiltersIn = (url) => [...new URL(url).searchParams]
    .filter(([key]) => /^quick_filters(?:\[\d*\])?$/u.test(key))
    .map(([, value]) => value)
    .sort();
const expectVisibleRows = async (page, ids) => expect.poll(() => page
    .locator('[data-testid^="reservation-actions-trigger-"]:visible')
    .evaluateAll((elements) => elements.map((element) => Number(element.dataset.testid.split('-').at(-1))).sort()))
    .toEqual([...ids].sort());

test('a future calendar period survives list navigation and a document reload', async ({ page }, testInfo) => {
    const requests = await installReservationUiFixture(page);
    await page.goto(path + futureQuery);
    const period = page.getByTestId('calendar-period');
    await expect(period).toContainText('2031');
    await expect(page.getByTestId('calendar-view-month')).toHaveAttribute('aria-pressed', 'true');
    await page.getByTestId('calendar-period').scrollIntoViewIfNeeded();
    await page.screenshot({ path: testInfo.outputPath('future-calendar.png') });
    await page.getByTestId('calendar-next').click();
    await expect(page).toHaveURL(/calendar_date=2031-12-/);
    await page.getByTestId('calendar-view-week').click();
    await expect(page).toHaveURL(/calendar_view=week/);
    await expect(period).toContainText('2031');
    const futurePeriod = (await period.textContent()).trim();

    await page.getByTestId('reservation-view-list').click();
    await expect(page).toHaveURL(/view_mode=list/);
    await expect(period).toHaveCount(0);
    await expect(page.getByTestId('reservation-actions-trigger-91001')).toBeVisible();
    await page.getByTestId('reservation-view-calendar').click();
    await expect(page).toHaveURL(/view_mode=calendar/);
    await expect(period).toHaveText(futurePeriod);
    await expect(page.getByTestId('calendar-view-week')).toHaveAttribute('aria-pressed', 'true');

    await page.reload();
    await expect(period).toHaveText(futurePeriod);
    await expect(page.getByTestId('calendar-view-week')).toHaveAttribute('aria-pressed', 'true');
    await expect.poll(() => requests.events.length).toBeGreaterThan(0);
    expect(requests.events.at(-1).searchParams.get('start')).toContain('2031-');
    expect(requests.pageErrors).toEqual([]);
    expect(requests.unexpected).toEqual([]);
});

test('active search is visible and removable while list sorting avoids calendar requests', async ({ page }) => {
    const requests = await installReservationUiFixture(page);
    await page.goto(`${path}?scope=all&view_mode=list&search=jules`);
    const activeFilters = page.getByTestId('reservation-active-filters');
    const search = page.getByTestId('reservation-search');
    await expect(activeFilters).toContainText('jules');
    await expect(search).toHaveValue('jules');
    await expect(page.getByTestId('reservation-actions-trigger-91001')).toBeVisible();
    await expect(page.getByTestId('reservation-actions-trigger-91002')).toHaveCount(0);

    await activeFilters.getByRole('button', { name: /^Retirer.*jules/u }).click();
    await expect(search).toHaveValue('');
    await expect(page).not.toHaveURL(/[?&]search=/);
    await expect(page.getByTestId('reservation-actions-trigger-91002')).toBeVisible();
    await expect(activeFilters.getByRole('button', { name: /^Retirer/u })).toHaveCount(0);

    await search.fill('alice');
    await expect(page).toHaveURL(/search=alice/);
    await expect(activeFilters).toContainText('alice');
    await expect(page.getByTestId('reservation-actions-trigger-91001')).toHaveCount(0);
    await page.locator('th[aria-sort]').first().getByRole('button').click();
    await expect(page).toHaveURL(/sort=date_desc/);
    await expect(page.locator('th[aria-sort]').first()).toHaveAttribute('aria-sort', 'descending');
    expect(requests.inertia.some((url) => url.searchParams.get('search') === 'alice')).toBe(true);
    const searchReload = requests.partialData.find(({ url }) => url.searchParams.get('search') === 'alice');
    expect(searchReload.only).toEqual(expect.arrayContaining(['filters', 'reservations', 'stats', 'quickCounts']));
    expect(searchReload.only).not.toContain('performance');
    expect(searchReload.only).not.toContain('queueItems');
    const sortReload = requests.partialData.at(-1);
    expect(sortReload.only).toEqual(expect.arrayContaining(['filters', 'reservations']));
    expect(sortReload.only).not.toContain('stats');
    expect(sortReload.only).not.toContain('events');
    expect(requests.events).toHaveLength(0);
    expect(requests.pageErrors).toEqual([]);
    expect(requests.unexpected).toEqual([]);
});

test('queue and waitlist tabs preserve their URL state without fetching hidden calendar data', async ({ page }) => {
    const requests = await installReservationUiFixture(page);
    await page.goto(`${path}?scope=all&view_mode=calendar&data_tab=queue&calendar_date=2031-11-18`);
    await expect(page.getByTestId('reservation-queue-view-table')).toBeVisible();
    await expect(page.getByTestId('calendar-period')).toHaveCount(0);
    await page.reload();
    await expect(page.getByTestId('reservation-queue-view-table')).toBeVisible();

    await page.getByTestId('reservation-tab-waitlist').click();
    await expect(page).toHaveURL(/data_tab=waitlist/);
    await expect(page.getByTestId('calendar-period')).toHaveCount(0);
    await page.getByTestId('reservation-tab-queue').click();
    await expect(page).toHaveURL(/data_tab=queue/);
    await expect(page.getByTestId('reservation-queue-view-table')).toBeVisible();
    expect(requests.partialData.at(-1).only).toEqual(expect.arrayContaining(['filters', 'queueItems', 'queueStats']));
    expect(requests.partialData.at(-1).only).not.toContain('reservations');
    expect(requests.partialData.at(-1).only).not.toContain('events');
    expect(requests.events).toHaveLength(0);
    expect(requests.pageErrors).toEqual([]);
    expect(requests.unexpected).toEqual([]);
});

test('rapid view switches and a delayed filter response do not restore an obsolete calendar date', async ({ page }) => {
    const requests = await installReservationUiFixture(page);
    await page.goto(path + futureQuery);
    await expect(page.getByTestId('calendar-view-month')).toHaveAttribute('aria-pressed', 'true');
    await expect(page.getByTestId('calendar-period')).toContainText('2031');

    const pendingList = requests.holdNextReload();
    await page.getByTestId('reservation-view-list').click();
    await pendingList.started;
    await page.getByTestId('reservation-view-calendar').click();
    await expect(page).toHaveURL(/view_mode=calendar/);
    pendingList.release();
    await expect(page.getByTestId('calendar-view-month')).toHaveAttribute('aria-pressed', 'true');

    const pendingSearch = requests.holdNextReload();
    await page.getByTestId('reservation-search').fill('jules');
    await pendingSearch.started;
    await page.getByTestId('calendar-next').click();
    await expect(page).toHaveURL(/calendar_date=2031-12-/);
    const navigatedPeriod = (await page.getByTestId('calendar-period').textContent()).trim();
    pendingSearch.release();
    await expect(page).toHaveURL(/search=jules/);
    await expect(page).toHaveURL(/calendar_date=2031-12-/);
    await expect(page.getByTestId('calendar-period')).toHaveText(navigatedPeriod);
    await expect(page.getByTestId('calendar-view-month')).toHaveAttribute('aria-pressed', 'true');
    expect(requests.pageErrors).toEqual([]);
    expect(requests.unexpected).toEqual([]);
});

test('mobile dark mode keeps active filters and reset usable', async ({ page }, testInfo) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.addInitScript(() => localStorage.setItem('hs_theme', 'dark'));
    const requests = await installReservationUiFixture(page);
    await page.goto(`${path}?scope=all&view_mode=list&search=jules&status=confirmed`);
    await expect(page.locator('html')).toHaveClass(/dark/);
    await expect(page.getByTestId('reservation-active-filters')).toContainText('jules');
    await page.getByTestId('reservation-active-filters').scrollIntoViewIfNeeded();
    await page.screenshot({ path: testInfo.outputPath('mobile-dark-filters.png') });
    await page.getByTestId('reservation-clear-filters').click();
    await expect(page.getByTestId('reservation-search')).toHaveValue('');
    await expect(page).not.toHaveURL(/[?&](?:search|status)=/);
    await expect(page.getByTestId('reservation-active-filters').getByRole('button', { name: /^Retirer/u })).toHaveCount(0);
    await page.getByTestId('reservation-mobile-per-page').selectOption('25');
    await expect(page).toHaveURL(/per_page=25/);
    await expect(page.getByTestId('reservation-mobile-per-page')).toHaveValue('25');
    expect(requests.partialData.at(-1).only).toEqual(expect.arrayContaining(['filters', 'reservations']));
    expect(requests.partialData.at(-1).only).not.toContain('performance');
    expect(requests.partialData.at(-1).only).not.toContain('stats');
    expect(requests.partialData.at(-1).only).not.toContain('queueItems');
    const dimensions = await page.evaluate(() => ({
        viewport: document.documentElement.clientWidth,
        content: document.documentElement.scrollWidth,
    }));
    expect(dimensions.content).toBeLessThanOrEqual(dimensions.viewport);
    expect(requests.events).toHaveLength(0);
    expect(requests.pageErrors).toEqual([]);
    expect(requests.unexpected).toEqual([]);
});

for (const viewport of [{ width: 1280, height: 800 }, { width: 390, height: 844 }]) {
    test(`page links at ${viewport.width}px only reload reservation list data`, async ({ page }) => {
        await page.setViewportSize(viewport);
        const requests = await installReservationUiFixture(page, { paginate: true });
        await page.goto(`${path}?scope=all&view_mode=list&per_page=10`);
        await expect(page.getByText('Client 0', { exact: true }).filter({ visible: true })).toBeVisible();
        await expect(page.getByText('Client 8', { exact: true })).toHaveCount(0);
        await page.getByRole('link', { name: '2', exact: true }).click();
        await expect(page).toHaveURL(/page=2/);
        await expect(page.getByRole('link', { name: '2', exact: true })).toHaveAttribute('aria-current', 'page');
        await expect(page.getByText('Client 8', { exact: true }).filter({ visible: true })).toBeVisible();
        await expect(page.getByText('Client 0', { exact: true })).toHaveCount(0);
        expect(requests.partialData.at(-1).only).toEqual(expect.arrayContaining(['filters', 'reservations']));
        expect(requests.partialData.at(-1).only).not.toContain('stats');
        expect(requests.partialData.at(-1).only).not.toContain('performance');
        expect(requests.partialData.at(-1).only).not.toContain('queueItems');
        expect(requests.events).toHaveLength(0);
        expect(requests.pageErrors).toEqual([]);
        expect(requests.unexpected).toEqual([]);
    });
}

test('multiple quick filters switch between intersection and union and can be removed independently', async ({ page }, testInfo) => {
    const requests = await installReservationUiFixture(page, { multiCriteria: true });
    await page.goto(`${path}?scope=all&view_mode=list`);
    const summary = page.getByTestId('reservation-active-filters');
    await expectVisibleRows(page, [91001, 91002, 91003, 91004, 91005, 91006]);

    await page.getByTestId('reservation-quick-filter-pending').click();
    await expectVisibleRows(page, [91003, 91004]);
    await page.getByTestId('reservation-quick-filter-today').click();
    await expect(summary.getByRole('button', { name: 'Tous les critères', exact: true })).toHaveAttribute('aria-pressed', 'true');
    await expect.poll(() => quickFiltersIn(page.url())).toEqual(['pending', 'today']);
    await expectVisibleRows(page, [91003]);

    await summary.getByRole('button', { name: 'Au moins un critère', exact: true }).click();
    await expect(page).toHaveURL(/quick_filter_mode=any/u);
    await expectVisibleRows(page, [91001, 91003, 91004, 91005]);
    await expect(summary.getByRole('button', { name: 'Au moins un critère', exact: true })).toHaveAttribute('aria-pressed', 'true');
    await summary.scrollIntoViewIfNeeded();
    await page.screenshot({ path: testInfo.outputPath('desktop-multiple-filters.png') });

    await page.goBack();
    await expect(summary.getByRole('button', { name: 'Tous les critères', exact: true })).toHaveAttribute('aria-pressed', 'true');
    await expectVisibleRows(page, [91003]);
    await page.goForward();
    await expect(summary.getByRole('button', { name: 'Au moins un critère', exact: true })).toHaveAttribute('aria-pressed', 'true');
    await expectVisibleRows(page, [91001, 91003, 91004, 91005]);

    await summary.getByRole('button', { name: 'Retirer En attente', exact: true }).click();
    await expect(page.getByTestId('reservation-quick-filter-pending')).toHaveAttribute('aria-pressed', 'false');
    await expect(page.getByTestId('reservation-quick-filter-today')).toHaveAttribute('aria-pressed', 'true');
    await expect.poll(() => quickFiltersIn(page.url())).toEqual(['today']);
    await expectVisibleRows(page, [91001, 91003, 91005]);

    await page.getByTestId('reservation-search').fill('Marie');
    await expectVisibleRows(page, [91003]);
    await page.getByTestId('reservation-quick-filter-all').click();
    await expect(page.getByTestId('reservation-quick-filter-all')).toHaveAttribute('aria-pressed', 'true');
    await expect.poll(() => quickFiltersIn(page.url())).toEqual([]);
    await expect(page.getByTestId('reservation-search')).toHaveValue('Marie');
    await expect(page).toHaveURL(/quick_filter_mode=any/u);
    await expectVisibleRows(page, [91003]);

    await summary.getByRole('button', { name: 'Effacer les filtres', exact: true }).click();
    await expect(page.getByTestId('reservation-search')).toHaveValue('');
    await expect(summary.getByRole('button', { name: /^Retirer/u })).toHaveCount(0);
    await expect.poll(() => new URL(page.url()).searchParams.get('quick_filter_mode') || 'all').toBe('all');
    await expectVisibleRows(page, [91001, 91002, 91003, 91004, 91005, 91006]);
    expect(requests.partialData.at(-1).only).toEqual(expect.arrayContaining(['filters', 'reservations', 'stats', 'quickCounts']));
    expect(requests.events).toHaveLength(0);
    expect(requests.pageErrors).toEqual([]);
    expect(requests.unexpected).toEqual([]);
});

test('mobile dark multi filters preserve calendar date and mode across list navigation and reload', async ({ page }, testInfo) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.addInitScript(() => localStorage.setItem('hs_theme', 'dark'));
    const requests = await installReservationUiFixture(page, { multiCriteria: true });
    await page.goto(`${path}?scope=all&view_mode=list&calendar_view=week&calendar_date=2031-11-18&quick_filters[]=pending&quick_filters[]=today&quick_filter_mode=any`);
    const summary = page.getByTestId('reservation-active-filters');
    await expect(page.locator('html')).toHaveClass(/dark/u);
    await expect(summary.getByRole('button', { name: 'Retirer En attente', exact: true })).toBeVisible();
    await expect(summary.getByRole('button', { name: "Retirer Aujourd'hui", exact: true })).toBeVisible();
    await expect(summary.getByRole('button', { name: 'Au moins un critère', exact: true })).toHaveAttribute('aria-pressed', 'true');
    expect(requests.events).toHaveLength(0);
    await summary.scrollIntoViewIfNeeded();
    await page.screenshot({ path: testInfo.outputPath('mobile-dark-multiple-filters.png') });

    await page.getByTestId('reservation-view-calendar').click();
    await expect(page.getByTestId('calendar-period')).toContainText('2031');
    await expect(page.getByRole('button', { name: /Marie Dupont/u })).toBeVisible();
    await expect.poll(() => requests.events.length).toBeGreaterThan(0);
    expect(quickFiltersIn(requests.events.at(-1).href)).toEqual(['pending', 'today']);
    expect(requests.events.at(-1).searchParams.get('quick_filter_mode')).toBe('any');
    await page.getByTestId('calendar-next').click();
    await expect(page).toHaveURL(/calendar_date=2031-11-25/u);
    const nextPeriod = (await page.getByTestId('calendar-period').textContent()).trim();

    await page.getByTestId('reservation-view-list').click();
    await expect(page.getByTestId('calendar-period')).toHaveCount(0);
    const eventsInList = requests.events.length;
    await page.getByTestId('reservation-search').fill('Marie');
    await expect(page).toHaveURL(/search=Marie/u);
    await expect(page.getByText('Marie Dupont', { exact: true }).filter({ visible: true })).toBeVisible();
    expect(requests.events).toHaveLength(eventsInList);
    await page.getByTestId('reservation-view-calendar').click();
    await expect(page.getByTestId('calendar-period')).toHaveText(nextPeriod);
    await page.reload();
    await expect(page.getByTestId('calendar-period')).toHaveText(nextPeriod);
    await expect(page.getByTestId('calendar-view-week')).toHaveAttribute('aria-pressed', 'true');
    await expect(summary.getByRole('button', { name: 'Au moins un critère', exact: true })).toHaveAttribute('aria-pressed', 'true');
    await expect.poll(() => quickFiltersIn(page.url())).toEqual(['pending', 'today']);
    await expect(page.getByTestId('reservation-search')).toHaveValue('Marie');

    await page.getByTestId('reservation-clear-filters').click();
    await expect(summary.getByRole('button', { name: /^Retirer/u })).toHaveCount(0);
    await expect(page.getByTestId('calendar-period')).toHaveText(nextPeriod);
    await expect.poll(() => quickFiltersIn(page.url())).toEqual([]);
    await expect.poll(() => new URL(page.url()).searchParams.get('quick_filter_mode') || 'all').toBe('all');
    const dimensions = await page.evaluate(() => ({ viewport: document.documentElement.clientWidth, content: document.documentElement.scrollWidth }));
    expect(dimensions.content).toBeLessThanOrEqual(dimensions.viewport);
    expect(requests.pageErrors).toEqual([]);
    expect(requests.unexpected).toEqual([]);
});

test('legacy quick links select one criterion while an explicit array takes precedence', async ({ page }) => {
    const requests = await installReservationUiFixture(page, { multiCriteria: true });
    await page.goto(`${path}?scope=all&view_mode=list&quick=pending`);
    await expect(page.getByTestId('reservation-quick-filter-pending')).toHaveAttribute('aria-pressed', 'true');
    await expectVisibleRows(page, [91003, 91004]);

    await page.getByTestId('reservation-quick-filter-today').click();
    await expect.poll(() => quickFiltersIn(page.url())).toEqual(['pending', 'today']);
    await expect(page).not.toHaveURL(/[?&]quick=/u);
    await expectVisibleRows(page, [91003]);

    await page.goto(`${path}?scope=all&view_mode=list&quick=pending&quick_filters[]=completed`);
    await expect(page.getByTestId('reservation-quick-filter-pending')).toHaveAttribute('aria-pressed', 'false');
    await expect(page.getByTestId('reservation-quick-filter-completed')).toHaveAttribute('aria-pressed', 'true');
    await expectVisibleRows(page, [91005]);
    expect(requests.events).toHaveLength(0);
    expect(requests.pageErrors).toEqual([]);
    expect(requests.unexpected).toEqual([]);
});

test('advanced filter drafts apply once and cancel without changing active criteria on mobile', async ({ page }, testInfo) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.addInitScript(() => localStorage.setItem('hs_theme', 'dark'));
    const requests = await installReservationUiFixture(page, { multiCriteria: true });
    await page.goto(`${path}?scope=all&view_mode=list&quick_filters[]=today&quick_filter_mode=any`);
    const openFilters = page.locator('button[aria-controls="reservation-advanced-filters"]');
    const dialog = page.getByRole('dialog', { name: 'Filtres avancés', exact: true });
    const from = dialog.getByLabel('Du', { exact: true });
    const selectFromDay = async () => {
        await from.click();
        await dialog.getByRole('button', { name: '19', exact: true }).click();
        await expect(from).toHaveValue('19 nov. 2031');
    };
    await openFilters.click();
    await selectFromDay();
    await dialog.getByLabel('Statut', { exact: true }).selectOption('pending');
    await dialog.getByLabel('Produit ou service', { exact: true }).selectOption('91');
    expect(requests.inertia).toHaveLength(0);
    await expect(page).not.toHaveURL(/[?&](?:date_from|status|service_id)=/u);
    await dialog.getByRole('button', { name: 'Annuler', exact: true }).click();
    await expect(dialog).toBeHidden();

    await openFilters.click();
    await expect(from).toHaveValue('');
    await expect(dialog.getByLabel('Statut', { exact: true })).toHaveValue('');
    await selectFromDay();
    await dialog.getByLabel('Statut', { exact: true }).selectOption('pending');
    await dialog.getByLabel('Produit ou service', { exact: true }).selectOption('91');
    await page.screenshot({ path: testInfo.outputPath('mobile-dark-advanced-filters.png') });
    await dialog.getByRole('button', { name: 'Appliquer les filtres', exact: true }).click();
    await expect(dialog).toBeHidden();
    await expect(page).toHaveURL(/date_from=2031-11-19/u);
    await expect(page).toHaveURL(/status=pending/u);
    await expect(page).toHaveURL(/service_id=91/u);
    await expect(page.getByText('Marie Dupont', { exact: true })).toHaveCount(0);
    expect(requests.inertia).toHaveLength(1);

    await page.getByTestId('reservation-quick-filter-all').click();
    await expect.poll(() => quickFiltersIn(page.url())).toEqual([]);
    await expect(page).toHaveURL(/status=pending/u);
    await expect(page).toHaveURL(/service_id=91/u);
    await expect(page).toHaveURL(/date_from=2031-11-19/u);
    await expect(page).toHaveURL(/quick_filter_mode=any/u);

    const visitsBeforeDraftReset = requests.inertia.length;
    await openFilters.click();
    await expect(from).toHaveValue('19 nov. 2031');
    await dialog.getByRole('button', { name: 'Réinitialiser', exact: true }).click();
    await expect(from).toHaveValue('');
    expect(requests.inertia).toHaveLength(visitsBeforeDraftReset);
    await dialog.getByRole('button', { name: 'Annuler', exact: true }).click();
    await expect(page).toHaveURL(/date_from=2031-11-19/u);
    await expect(page).toHaveURL(/status=pending/u);

    await openFilters.click();
    await expect(from).toHaveValue('19 nov. 2031');
    await dialog.getByRole('button', { name: 'Réinitialiser', exact: true }).click();
    await dialog.getByRole('button', { name: 'Appliquer les filtres', exact: true }).click();
    await expect(dialog).toBeHidden();
    await expect(page).not.toHaveURL(/[?&](?:date_from|status|service_id)=/u);
    await expect(page.getByTestId('reservation-active-filters').getByRole('button', { name: /^Retirer/u })).toHaveCount(0);
    await expect(page.getByText('Marie Dupont', { exact: true }).filter({ visible: true })).toBeVisible();
    expect(requests.inertia).toHaveLength(visitsBeforeDraftReset + 1);
    expect(requests.events).toHaveLength(0);
    expect(requests.pageErrors).toEqual([]);
    expect(requests.unexpected).toEqual([]);
});

test('calendar Back restores criteria and cancels an unsubmitted search before its debounce fires', async ({ page }) => {
    await page.clock.install({ time: new Date('2031-11-18T12:00:00Z') });
    const requests = await installReservationUiFixture(page, { multiCriteria: true });
    await page.goto(`${path}?scope=all&view_mode=calendar&calendar_view=week&calendar_date=2031-11-18&quick_filters[]=pending`);
    const summary = page.getByTestId('reservation-active-filters');
    const search = page.getByTestId('reservation-search');
    await expect(page.getByRole('button', { name: /Paul Bernard/u })).toBeVisible();
    await page.getByTestId('reservation-quick-filter-today').click();
    await expect(page.getByRole('button', { name: /Marie Dupont/u })).toBeVisible();
    await expect(page.getByRole('button', { name: /Paul Bernard/u })).toHaveCount(0);
    await expect.poll(() => quickFiltersIn(page.url())).toEqual(['pending', 'today']);
    const visitsBeforeBack = requests.inertia.length;

    await page.goBack();
    await expect.poll(() => quickFiltersIn(page.url())).toEqual(['pending']);
    await expect(summary.getByRole('button', { name: "Retirer Aujourd'hui", exact: true })).toHaveCount(0);
    await expect(page.getByRole('button', { name: /Paul Bernard/u })).toBeVisible();
    expect(quickFiltersIn(requests.events.at(-1).href)).toEqual(['pending']);
    expect(requests.inertia).toHaveLength(visitsBeforeBack);

    await page.goForward();
    await expect.poll(() => quickFiltersIn(page.url())).toEqual(['pending', 'today']);
    await expect(page.getByRole('button', { name: /Paul Bernard/u })).toHaveCount(0);
    await search.fill('obsolete search');
    await page.goBack();
    await expect(search).toHaveValue('');
    await page.clock.runFor(600);
    await expect.poll(() => quickFiltersIn(page.url())).toEqual(['pending']);
    await expect(page).not.toHaveURL(/[?&]search=/u);
    await expect(page.getByRole('button', { name: /Paul Bernard/u })).toBeVisible();
    expect(requests.inertia).toHaveLength(visitsBeforeBack);
    expect(requests.events.every((url) => !url.searchParams.get('search'))).toBe(true);
    expect(requests.pageErrors).toEqual([]);
    expect(requests.unexpected).toEqual([]);
});
