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

export async function jsonRequest(page, method, url, body = undefined) {
    return page.evaluate(async ({ method, url, body }) => {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        const headers = {
            Accept: 'application/json',
            'X-CSRF-TOKEN': csrfToken,
            'X-Requested-With': 'XMLHttpRequest',
        };

        const request = {
            method,
            headers,
            credentials: 'same-origin',
        };

        if (body !== undefined) {
            headers['Content-Type'] = 'application/json';
            request.body = JSON.stringify(body);
        }

        const response = await fetch(url, request);

        let payload = null;

        try {
            payload = await response.json();
        } catch {
            payload = null;
        }

        return {
            ok: response.ok,
            status: response.status,
            body: payload,
        };
    }, {
        method,
        url,
        body,
    });
}
