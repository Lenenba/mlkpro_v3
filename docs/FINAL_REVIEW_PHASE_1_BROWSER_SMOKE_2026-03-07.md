# Final Review Phase 1 - Browser Smoke Baseline (2026-03-07)

## Objective
Add a real browser-level smoke layer on top of the existing backend, MySQL, and build validations.

This phase closes a gap left by green feature tests alone:
- server-rendered pages can still fail at runtime in real browser navigation
- hidden selectors and UI regressions can pass backend tests but break operator workflows
- SQLite and MySQL validation do not cover browser boot, asset loading, or route hydration

## Delivered

### Playwright harness
- `playwright.config.mjs`
- `scripts/playwright-webserver.mjs`
- `scripts/playwright-router.php`
- `tests/e2e/helpers/app.mjs`

### Seeded browser fixtures
- `database/seeders/E2ESmokeSeeder.php`

The seeder creates deterministic smoke fixtures for:
- one services owner account
- one services customer with lead and quote
- one products owner account
- one low-stock product
- one public store product
- one product customer and one seeded sale

### Browser smoke coverage
- `tests/e2e/authenticated-smoke.spec.js`
- `tests/e2e/public-store-smoke.spec.js`

Covered flows:
1. Services owner login -> dashboard -> customer detail
2. Products owner login -> dashboard -> sales create -> sales show
3. Public storefront render for a seeded tenant

## Commands
- Install browser locally: `npm run qa:e2e:install`
- Run browser smoke: `npm run qa:e2e`

`qa:e2e` runs:
1. `npm run qa:build`
2. Playwright with an isolated Laravel bootstrap

## Isolation strategy
The browser suite does not depend on the developer's running app server.

It uses:
- a dedicated PHP built-in server
- an isolated SQLite database file for E2E bootstrap
- a dedicated seeder
- generated fixture data consumed by Playwright specs

This keeps browser smoke deterministic and safe to run in CI.

## CI integration
The quality workflow now includes a browser smoke job in addition to:
- SQLite unit/feature checks
- MySQL suite execution
- frontend build verification

## Verified state
Local verification completed on 2026-03-07:
- `composer qa:format` : OK
- `composer qa:analyse` : OK
- `php artisan test` : 192 passed, 1178 assertions
- `composer qa:test:mysql` : 192 passed, 1178 assertions
- `npm run qa:e2e` : 3 passed

## Known limits
This phase is intentionally minimal.

It does not yet provide:
- full end-to-end coverage of all business workflows
- mutation-heavy browser journeys with form submission assertions across the platform
- visual regression coverage
- load validation for browser-driven concurrency

## Next logical step
Extend browser hardening only where it materially reduces risk:
- quote to work to invoice operator flow
- reservation client booking flow
- payment and portal client flows
- public lead capture flow
