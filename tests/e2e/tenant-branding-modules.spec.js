import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { expect, test } from '@playwright/test';
import { loadFixtures } from './helpers/app.mjs';
import {
    assertCompanyBrandingE2ERegistry,
    COMPANY_BRANDING_E2E_SCENARIOS,
    expectComputedColor,
    expectElementContrast,
    expectFocusColor,
    expectHoverColor,
    expectRootBrandVariable,
    readRootBrandVariable,
} from './helpers/companyBranding.mjs';

const brandingAuditManifest = JSON.parse(readFileSync(
    resolve(process.cwd(), 'config/company-branding-color-audit.json'),
    'utf8',
));
const registry = assertCompanyBrandingE2ERegistry(brandingAuditManifest);
const VIEW_MODES = Object.freeze([
    Object.freeze({ key: 'desktop-light', colorScheme: 'light', theme: 'default', viewport: { width: 1440, height: 900 } }),
    Object.freeze({ key: 'desktop-dark', colorScheme: 'dark', theme: 'dark', viewport: { width: 1440, height: 900 } }),
    Object.freeze({ key: 'mobile-light', colorScheme: 'light', theme: 'default', viewport: { width: 390, height: 844 } }),
    Object.freeze({ key: 'mobile-dark', colorScheme: 'dark', theme: 'dark', viewport: { width: 390, height: 844 } }),
]);

test.describe('certification progressive des couleurs par module', () => {
    test('[registre] chaque module vérifié possède exactement un scénario E2E', () => {
        expect(registry.scenarioIds).toEqual(registry.verifiedModuleIds);
    });

    for (const scenario of COMPANY_BRANDING_E2E_SCENARIOS) {
        for (const viewMode of VIEW_MODES) {
            test(`[${scenario.id}] ${scenario.label} — ${viewMode.key}`, async ({ page }) => {
                const fixtures = loadFixtures();
                const fixture = fixtures[scenario.fixtureKey];

                await page.setViewportSize(viewMode.viewport);
                await page.emulateMedia({ colorScheme: viewMode.colorScheme });
                await page.addInitScript((theme) => {
                    localStorage.setItem('hs_theme', theme);
                }, viewMode.theme);
                await page.goto(fixture[scenario.pathKey]);

                await expect(page.locator('html')).toHaveClass(
                    viewMode.colorScheme === 'dark' ? /\bdark\b/ : /\bdefault\b/,
                );
                await expectRootBrandVariable(page, { expected: fixture.primaryColor });

                const primaryControl = page.locator(scenario.primarySelector).last();
                await expect(primaryControl).toBeVisible();
                await expectComputedColor(primaryControl, {
                    expected: fixture.primaryColor,
                    property: 'background-color',
                    description: `${scenario.id} primary control`,
                });
                await expectElementContrast(primaryControl);

                const hoverColor = await readRootBrandVariable(page, '--app-primary-hover');
                await expectHoverColor(primaryControl, { expected: hoverColor });

                const focusControl = page.locator(scenario.focusSelector).first();
                await expect(focusControl).toBeVisible();
                const focusColor = await readRootBrandVariable(page, '--app-primary-line');
                await expectFocusColor(focusControl, { expected: focusColor });
                await expectElementContrast(focusControl, {
                    foregroundProperty: 'border-color',
                    backgroundProperty: 'background-color',
                    minimum: 3,
                });
            });
        }
    }
});
