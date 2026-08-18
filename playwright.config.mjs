import { defineConfig } from '@playwright/test';

export default defineConfig({
    testDir: './tests/e2e',
    fullyParallel: false,
    workers: 1,
    retries: process.env.CI ? 1 : 0,
    timeout: 90_000,
    expect: {
        timeout: 10_000,
    },
    use: {
        baseURL: 'http://127.0.0.1:38103',
        browserName: 'chromium',
        headless: true,
        launchOptions: process.env.PLAYWRIGHT_EXECUTABLE_PATH
            ? { executablePath: process.env.PLAYWRIGHT_EXECUTABLE_PATH }
            : {},
        screenshot: 'only-on-failure',
        trace: 'retain-on-failure',
        video: process.env.PLAYWRIGHT_DISABLE_VIDEO === '1' ? 'off' : 'retain-on-failure',
    },
    webServer: {
        command: 'node ./scripts/playwright-webserver.mjs',
        url: 'http://127.0.0.1:38103/login',
        timeout: 180_000,
        reuseExistingServer: false,
        stderr: process.env.PLAYWRIGHT_SERVER_LOGS === '1' ? 'pipe' : 'ignore',
    },
});
