# Phase 0 Baseline

Date: 2026-03-06

## Verification Baseline

- Tests: `168 passed`, `998 assertions`
- Frontend build: `npm run build` passes
- Static analysis baseline: PHPStan/Larastan introduced at `level 0` on `app/`
- Formatting baseline: Laravel Pint introduced as a required dirty/diff guardrail for changed PHP files

## Repository Shape

- Controllers: `130`
- Form Requests: `23`
- Services: `85`
- Models: `98`
- Policies: `7`
- Jobs: `6`
- Notifications: `20`
- Enums: `6`
- Vue pages: `135`
- Vue components: `101`

## Route Surface

- Total routes: `711`
- `routes/web.php`: `708` lines
- `routes/api.php`: `415` lines

## Largest Backend Hotspots

- `app/Services/Assistant/AssistantWorkflowService.php`: `4421` lines
- `app/Http/Controllers/DashboardController.php`: `1791` lines
- `app/Http/Controllers/SaleController.php`: `1649` lines
- `app/Http/Controllers/ProductController.php`: `1622` lines
- `app/Http/Controllers/Reservation/StaffReservationController.php`: `1385` lines
- `app/Http/Controllers/CustomerController.php`: `1208` lines
- `app/Services/ReservationAvailabilityService.php`: `1207` lines
- `app/Http/Controllers/RequestController.php`: `1165` lines
- `app/Http/Controllers/TaskController.php`: `1006` lines

## Largest Frontend Hotspots

- `resources/js/Pages/Task/UI/TaskTable.vue`: `2863` lines
- `resources/js/Pages/Public/Store.vue`: `2069` lines
- `resources/js/Pages/Planning/Index.vue`: `2066` lines
- `resources/js/Pages/Settings/Company.vue`: `1933` lines
- `resources/js/Pages/Product/UI/ProductTable.vue`: `1870` lines
- `resources/js/Pages/Settings/Billing.vue`: `1778` lines
- `resources/js/Pages/Customer/Show.vue`: `1631` lines

## Frontend Bundle Baseline

- Main app chunk: about `982.55 kB`
- Schedule chunk: about `231.29 kB`
- `vuedraggable` vendor chunk: about `177.25 kB`

## Known Structural Baseline

- Around `187` validation calls remain inside controllers
- Heavy read models are still assembled inline in several controllers
- Policy coverage remains thin compared to the route and feature surface
- Several quick-create and detail screens still fetch too much data eagerly

## Phase 0 Success Criteria

Phase 0 is complete when:

- quality checks are runnable locally with one command set
- CI enforces format, static analysis, tests, and build
- the project has a documented baseline for future improvement
- the baseline remains green before Phase 1 starts

Out of scope for this phase:

- repo-wide PHP style normalization
- static analysis on `routes/` and `tests/`
- performance tuning and payload reduction
