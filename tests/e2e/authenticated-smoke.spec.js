import { expect, test } from '@playwright/test';
import { loadFixtures, loginAs } from './helpers/app.mjs';

test('service owner can open the dashboard and customer detail smoke flow', async ({ page }) => {
    const fixtures = loadFixtures();

    await loginAs(page, fixtures.serviceOwner);

    await expect(page.getByTestId('demo-dashboard-overview')).toBeVisible();

    await page.goto(fixtures.serviceCustomer.path);

    await expect(page.getByText(fixtures.serviceCustomer.companyName).first()).toBeVisible();
    await expect(page.getByText(fixtures.serviceCustomer.email).first()).toBeVisible();
});

test('product owner can open dashboard, sales create, and sale detail smoke flow', async ({ page }) => {
    const fixtures = loadFixtures();

    await loginAs(page, fixtures.productOwner);

    await expect(page.getByText(fixtures.productDashboard.lowStockProductName).first()).toBeVisible();

    await page.goto(fixtures.productSales.createPath);
    await expect(page.getByText(fixtures.productSales.productName).first()).toBeVisible();

    await page.goto(fixtures.productSales.showPath);

    await expect(page.getByText(fixtures.productSales.saleNumber).first()).toBeVisible();
    await expect(page.getByText(fixtures.productSales.customerCompany).first()).toBeVisible();
});
