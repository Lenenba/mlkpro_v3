import { defineConfig } from '@playwright/test';
import localConfig from './playwright.reservation.config.mjs';

export default defineConfig({
    ...localConfig,
    testMatch: ['reservation-navigation.spec.js', 'frontend-loading.spec.js'],
});
