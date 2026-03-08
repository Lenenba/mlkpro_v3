import fs from 'node:fs';
import path from 'node:path';
import { expect } from '@playwright/test';

const fixturePath = path.resolve(process.cwd(), 'storage', 'app', 'e2e-fixtures.json');

export function loadFixtures() {
    return JSON.parse(fs.readFileSync(fixturePath, 'utf8'));
}

export async function loginAs(page, user) {
    await page.goto('/login');
    await page.getByLabel('Email').fill(user.email);
    await page.getByLabel('Password').fill(user.password);

    await Promise.all([
        page.waitForURL(/\/dashboard/),
        page.getByRole('button', { name: /log in/i }).click(),
    ]);

    await expect(page).not.toHaveURL(/\/login$/);
}
