import { defineConfig } from '@playwright/test';

export default defineConfig({
    testDir: './tests/e2e',
    fullyParallel: false,
    workers: 1,
    retries: process.env.CI ? 1 : 0,
    timeout: 45_000,
    expect: {
        timeout: 10_000,
    },
    use: {
        baseURL: 'http://127.0.0.1:38103',
        browserName: 'chromium',
        headless: true,
        screenshot: 'only-on-failure',
        trace: 'retain-on-failure',
        video: 'retain-on-failure',
    },
    webServer: {
        command: 'node ./scripts/playwright-webserver.mjs',
        url: 'http://127.0.0.1:38103/login',
        timeout: 180_000,
        reuseExistingServer: false,
    },
});
