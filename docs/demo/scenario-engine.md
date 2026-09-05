# Demo scenario engine

## Purpose

The demo scenario engine creates isolated, reproducible Malikia Pro workspaces that tell a connected business story. It extends the existing **Super Admin > Demo Workspaces** lifecycle instead of introducing a second tenant or billing system.

The first complete scenario is `studio_naya_coiffure`, a Montreal hair salon with 18 months of operational history. The scenario is opt-in: the regular salon preset remains intentionally lean, and Studio Naya is created only when its scenario key is selected.

Demo scenario data must never be provisioned in production.

## Architecture

The modern flow is:

```text
Demo Workspace UI or demo:scenario:create
    -> DemoWorkspaceCatalog (preset and selectable options)
    -> DemoWorkspaceProvisioner (tenant lifecycle and isolation)
    -> DemoScenarioManager
    -> DemoScenarioRegistry
    -> category-specific DemoScenario
    -> shared domain generators and existing domain services
    -> DemoScenarioInvariantValidator
```

The main responsibilities are:

| Component | Responsibility |
| --- | --- |
| `DemoWorkspaceCatalog` | Exposes presets, modules, scenario definitions, and volume choices to the UI and CLI. |
| `DemoWorkspaceProvisioner` | Creates the demo owner, persists the workspace, dispatches the selected scenario, stores the baseline, and performs isolated reset and purge operations. |
| `DemoScenario` | Contract implemented by each category-specific scenario. It defines a key, version, default volume, and generation entry point. |
| `DemoScenarioRegistry` | Resolves a scenario by its stable snake-case key and rejects duplicate or invalid registrations. |
| `DemoScenarioManager` | Confirms that the scenario version and context match the workspace before generation. |
| `DemoScenarioContext` | Carries the exact workspace, owner, data volume, reference date, timezone, and random seed. It also provides independent deterministic random streams. |
| Scenario blueprint | Contains category-specific business identity, staff, catalog, demand, seasonality, suppliers, and named customer stories. |
| Shared generators | Create reusable team, catalog, customer, reservation, billing, inventory, expense, and notification records. |
| `DemoScenarioInvariantValidator` | Checks tenant ownership, dates, reservation overlap, invoice/payment consistency, stock, and twelve-month reporting coverage. |
| `DemoScenarioFingerprint` | Hashes semantic business data while excluding tenant IDs and access credentials, so a reset can be compared across newly created owner IDs. |

Scenario metadata is persisted on both `demo_workspaces` and `demo_workspace_templates`:

- `scenario_key`
- `scenario_version`
- `data_volume`
- `reference_date`
- `random_seed`

The same values are saved in the workspace baseline. A reset therefore recreates the same story even though the tenant owner receives a new database ID.

Each scenario configuration may also declare `required_modules`. The catalog exposes this contract to the builder, where required module toggles are selected and locked. The controller and provisioner both reject an incomplete module list, including requests coming from templates or CLI code. Scenario generators must not silently enable a feature that was not part of the validated workspace configuration.

## Why the legacy demo path was not extended

The repository previously had two demo paths:

- the modern Demo Workspace lifecycle with provisioning, templates, clone, baseline reset, expiration, and tenant purge;
- legacy `demo:seed` and `demo:reset` commands tied to the older global bootstrap.

The legacy commands remain disabled. Re-enabling them would bypass workspace ownership, baseline metadata, lifecycle status, and the modern purge service. Scenario commands call `DemoWorkspaceProvisioner` and use the same lifecycle as the Super Admin UI.

The previous lightweight seed profiles also had very small counts and relied on the current clock in several places. They are kept for quick generic demos. Narrative scenarios add an explicit reference date, seed, version, and configurable volume without changing legacy preset behavior.

## Determinism rules

A scenario is reproducible only when all four inputs are unchanged:

1. scenario key and version;
2. data volume;
3. reference date and timezone;
4. random seed.

Generation code must follow these rules:

- Derive business dates from `DemoScenarioContext::referenceDate`, never from an unscoped `now()` call.
- Obtain randomness from a named context stream such as `customers`, `reservations`, or `payments`.
- Keep stream names stable. Independent streams prevent a new customer field from changing every reservation or payment.
- Use deterministic name, service, supplier, and narrative pools instead of unrestricted Faker data.
- Include the workspace or owner identifier only where a global unique constraint requires tenant-specific values, such as email addresses.
- Exclude tenant IDs, generated credentials, and tenant-specific email suffixes from semantic fingerprints.
- Use existing domain actions for invoice payments, quotes, paid sales, and inventory adjustments so observers and calculations still run.

The fingerprint is a regression signal, not a replacement for invariant validation. A stable but invalid dataset must still fail validation.

## Data volumes

Volume counts are configured in `config/demo_scenarios.php`, not inside the scenario manager or provisioner.

| Volume | Intended use | Studio Naya profile |
| --- | --- | --- |
| `small` | Smoke tests and short walkthroughs | Compact customer and transaction history. |
| `medium` | Standard sales and training demo | Default; rich enough for linked histories and twelve-month reporting. |
| `large` | Advanced demonstrations and performance checks | Several thousand operational and financial records. |

Changing a volume does not change the five named Studio Naya employees, the core service catalog, or the named customer stories. It changes the size of the surrounding activity.

## Studio Naya story

`studio_naya_coiffure` is defined separately from the shared generators. Its blueprint describes:

- the Studio Naya identity, Montreal location, CAD currency, and `America/Toronto` timezone;
- five employees with different schedules, specialties, availability, and performance profiles;
- service categories, service duration metadata, retail and consumable products, suppliers, and reorder thresholds;
- Tuesday-to-Saturday demand, seasonal multipliers, and operating expenses;
- named customer journeys for Aïcha Martin, Samantha Joseph, Nadia Pierre, Marc-André Beaulieu, and Chloé Nguyen;
- connected appointments, invoices, payments, quotes, sales, inventory movements, expenses, notifications, and dashboard history.

The named journeys are persisted as business records, not only as explanatory labels. For example, Samantha's wedding quote is sent and accepted on the blueprint dates, carries Québec taxes, has a completed 30% deposit transaction, three linked appointments, and an open follow-up task. Marc's recurring series includes eight tipped payments and two product sales. Activity records include the relevant reservation, quote, transaction, task, invoice, payment, or sale identifier whenever the domain provides one.

Category-specific facts belong in the blueprint. Generic persistence and lifecycle behavior belong in shared services.

## CLI prerequisites

Scenario commands require migrations to be current and demo mode to be explicitly enabled outside production:

```dotenv
DEMO_ENABLED=true
DEMO_ALLOW_RESET=true
```

`DEMO_ALLOW_RESET` is required only for reset. All scenario commands refuse to run when the application environment is `production`, even if these flags are set.

Use an authorized actor explicitly when the database has more than one superadmin:

```bash
--admin=superadmin@example.com
```

Without `--admin`, the command uses the only superadmin. If there is no superadmin, it can use the only active platform admin with demo-management permission. Ambiguous actor selection fails safely.

## Create a scenario

Create Studio Naya synchronously with the default medium volume:

```bash
php artisan demo:scenario:create studio_naya_coiffure \
  --admin=superadmin@example.com
```

Choose all deterministic inputs explicitly:

```bash
php artisan demo:scenario:create studio_naya_coiffure \
  --volume=medium \
  --reference-date=2026-08-20 \
  --seed=12345 \
  --admin=superadmin@example.com
```

For medium or large datasets in a normal deployed environment, queue provisioning:

```bash
php artisan demo:scenario:create studio_naya_coiffure \
  --volume=large \
  --reference-date=2026-08-20 \
  --seed=12345 \
  --admin=superadmin@example.com \
  --queue
```

The command prints the exact workspace ID. Keep that ID for validation and reset. Queued generation uses the existing demo workload queue and lifecycle status.

The default medium profile intentionally generates thousands of related Eloquent records. Run demo workers with a PHP memory limit of at least 512 MB and the configured 900-second workload timeout, for example `php artisan queue:workloads operations --memory=512`. Queue `retry_after`/visibility must exceed 900 seconds; the repository defaults the database, Redis, and Beanstalkd values to 1200 seconds. The repository's `composer qa:test` command already uses a 512 MB test limit. The queue job also fails on timeout and keeps the live baseline untouched during queued reset.

## Validate a scenario

Validation always targets one exact workspace ID:

```bash
php artisan demo:scenario:validate 42
```

Use JSON output in automation:

```bash
php artisan demo:scenario:validate 42 --json
```

The command returns a non-zero exit code when an invariant fails. It also prints a tenant-independent semantic fingerprint. Important checks include:

- every relation belongs to the same tenant;
- completed reservations are in the past and future reservations are not completed;
- active appointments assigned to the same employee do not overlap;
- paid invoices have a zero balance;
- partial invoices have at least one settled payment and a remaining balance;
- quote deposits and follow-up tasks belong to the same tenant and occur after their customer and quote were created;
- stock is non-negative;
- record creation and business dates do not occur after the reference boundary;
- reporting data covers at least twelve months.

## Reset a scenario

Reset uses the saved baseline and never accepts a company name, category alias, or fuzzy lookup. The target must be the exact `demo_workspaces.id`:

```bash
php artisan demo:scenario:reset 42 \
  --admin=superadmin@example.com
```

The command asks for confirmation. Use `--force` only in controlled automation:

```bash
php artisan demo:scenario:reset 42 \
  --admin=superadmin@example.com \
  --force
```

Queue a potentially expensive reset with `--queue`.

A queued reset generates a shadow tenant while the current tenant and credentials remain usable. After generation succeeds, a short database transaction revokes the old tenant access, transfers the stable email and company slug, and points the `DemoWorkspace` at the replacement owner. Only then is the retired owner deleted. Generation or activation failure deletes the shadow tenant and leaves the live owner, credentials, and dataset unchanged. Jobs for the same workspace use a shared overlap lock and completed jobs are idempotently ignored.

The synchronous reset path remains available for controlled local workflows. Both paths recreate the saved scenario key, version, volume, reference date, seed, and selected modules. Neither path truncates shared tables or selects tenants by company name.

After reset, validate again and compare the semantic fingerprint:

```bash
php artisan demo:scenario:validate 42
```

## Expiration and cleanup

Expired demo tenants continue to use the existing targeted purge service:

```bash
php artisan demo:purge-expired
```

The purge job resolves expired `DemoWorkspace` records and deletes their linked demo accounts. Do not replace it with table truncation or a global delete query. Real tenants must never be selected by demo cleanup.

## Add another business category

To add a scenario such as `cleaning_company`:

1. Create a category blueprint containing business-specific identity, team roles, services or products, schedules, seasonality, and named stories.
2. Implement `App\Services\Demo\Contracts\DemoScenario` with a stable snake-case key and positive version.
3. Reuse shared generators and existing domain services. Add a shared generator only when the behavior is reusable across categories.
4. Add the blueprint, generator class, supported volumes, and every generator dependency under `required_modules` in `config/demo_scenarios.php`. The catalog discovers the UI scenario definition from that configured blueprint.
5. Add an explicit preset in `DemoWorkspaceCatalog` so the UI and CLI can provision the category with the correct modules and branding.
6. Keep the ordinary category defaults lean. A rich narrative preset must remain opt-in.
7. Add deterministic unit tests for the blueprint and random streams.
8. Add integration tests for creation, reset reproducibility, cross-tenant isolation, linked records, and domain calculations.
9. Add invariant expectations specific to the category only when they cannot be expressed by the shared validator.
10. Run the create, validate, reset, and validate sequence with fixed inputs.

Do not copy the entire Studio Naya generator and rename it. New categories should supply different blueprints while sharing lifecycle, context, billing, payments, inventory, validation, and fingerprint infrastructure.

## Verification

The focused automated suite includes scenario configuration, context, registry, manager, Studio Naya blueprint, workspace integration, notification, and invariant tests. Run it with the project PHP 8.4 runtime:

```bash
php artisan test \
  tests/Unit/DemoDataVolumeTest.php \
  tests/Unit/DemoScenarioConfigurationTest.php \
  tests/Unit/DemoScenarioContextTest.php \
  tests/Unit/DemoScenarioRegistryTest.php \
  tests/Unit/DemoScenarioManagerTest.php \
  tests/Unit/StudioNayaBlueprintTest.php \
  tests/Unit/AsyncQueueConfigurationTest.php \
  tests/Unit/DemoActionNotificationTest.php \
  tests/Feature/DemoScenarioCommandSafetyTest.php \
  tests/Feature/DemoScenarioWorkspaceIntegrationTest.php \
  tests/Feature/DemoScenarioInvariantValidatorTest.php \
  tests/Feature/DemoCommerceAccountingIntegrationTest.php \
  tests/Feature/StudioNayaNarrativeReservationTest.php \
  tests/Feature/InvoiceTipsTest.php
```

For changes to any PHP file, follow the repository PHP gate: stage the complete PHP change set, run `composer qa:format`, rerun it after any formatting correction, and finish with `git diff --check` before delivery.

## Known model boundaries

The scenario stays within the current domain model:

- A reservation has one primary service. Additional treatment context can be recorded as scenario metadata, but must not pretend that a non-existent multi-service relation exists.
- Service preparation, cleanup, buffer, and display color metadata are represented through the supported catalog and reservation metadata until dedicated columns exist.
- Supplier identity is stored through existing product and expense fields; there is no separate supplier aggregate in the current model.
- Customer narrative notes use supported customer descriptions and activity records rather than an invented note table.

These boundaries should be revisited when the core domain gains dedicated relations. The demo engine must not become an alternate source of business truth.
