import { defineConfig } from '@playwright/test';
import baseConfig from './playwright.config.mjs';

export default defineConfig({
    ...baseConfig,
    testMatch: 'social-media-preview.spec.js',
    webServer: undefined,
    use: {
        ...baseConfig.use,
        baseURL: 'https://pulse-preview.test',
        serviceWorkers: 'block',
        video: 'off',
    },
});
