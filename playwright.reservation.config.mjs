import { defineConfig } from '@playwright/test';
import baseConfig from './playwright.config.mjs';

export default defineConfig({
    ...baseConfig,
    testMatch: 'reservation-navigation.spec.js',
    webServer: undefined,
    use: {
        ...baseConfig.use,
        baseURL: 'http://reservation-ui.test',
        serviceWorkers: 'block',
        video: 'off',
    },
});
