# Multi-Currency Billing - User Story and Implementation Phases

## Goal
Build a robust multi-currency billing foundation for a multi-tenant Laravel application where each tenant has exactly one source-of-truth business currency and every billed amount is persisted in the actual charged currency.

Initial supported currencies:
- `CAD`
- `EUR`
- `USD`

Non-goals for this first implementation:
- runtime FX conversion as billing source of truth
- Stripe Terminal regional routing
- marketplace or Connect settlement redesign
- tenant-specific negotiated pricing rules

## Current Baseline
- The tenant root is currently the account owner `User` model.
- Tenant catalog items are stored in `products`, with services represented through `item_type = service`.
- Business documents already exist in:
  - `quotes`
  - `invoices`
  - `payments`
  - `sales`
  - `stripe_subscriptions`
  - reservation payment policy metadata
- Stripe online payments currently build currency from the global Cashier config, not from business context.
- Platform subscription plans are currently config-driven and keyed by a single `price_id` per plan.

## Product Decisions

### Tenant Currency
- Every tenant must have exactly one main business currency.
- The main business currency is the source of truth for tenant-owned catalog pricing and tenant-owned billing.
- The safest first implementation blocks tenant currency changes once business activity exists.
- Existing legacy tenants without a stored currency will be backfilled to `CAD`.

### Tenant Catalog
- Every tenant-owned product or service must persist:
  - `price_amount`
  - `currency_code`
- `currency_code` is stored on each row even if it matches the tenant currency.
- The tenant currency is the default on create.
- The currency is read-only in the first implementation.

### Global Subscription Plans
- Platform plans are global and not tenant-owned.
- A plan must store explicit prices per currency.
- Price lookup must resolve from a normalized `plan_prices` table, not from FX conversion and not from one global config price.

### Business Documents
- Quotes, invoices, sales/orders, subscription records, payments, and reservation payment snapshots must store explicit currency.
- Historical amounts must never be recomputed from a tenant’s current currency.
- Old rows without explicit currency must be backfilled safely.

### Stripe Online Payments
- Stripe Checkout / PaymentIntent currency must match the actual business context:
  - tenant currency for tenant-owned catalog/invoice/sale charges
  - selected plan price currency for platform subscription billing
- Minor-unit conversion must happen from persisted source-of-truth money values.
- Missing currency-specific plan pricing must fail with a clear domain exception.

## User Stories

### US-MCB-1 - Tenant Chooses a Main Currency
As a tenant owner, I can set my tenant main currency so all new business pricing is anchored to a real source-of-truth currency.

Acceptance criteria:
- A tenant has one and only one `currency_code`.
- New tenant creation defaults to `CAD`.
- Tenant creation supports `CAD`, `EUR`, and `USD`.
- Tenant settings display the main currency clearly.

### US-MCB-2 - Catalog Items Persist Currency
As a tenant owner, when I create a product or service, the item stores the tenant currency explicitly so historical pricing remains consistent.

Acceptance criteria:
- Product and service records persist `currency_code`.
- New catalog items default to the tenant currency.
- Currency is read-only in the first implementation.
- Legacy catalog items without currency are backfilled to `CAD`.

### US-MCB-3 - Unsafe Tenant Currency Changes Are Blocked
As the system, I prevent casual tenant currency changes when business activity already exists.

Acceptance criteria:
- Currency change is blocked if the tenant already has at least one of:
  - product/service
  - quote
  - invoice
  - sale
  - payment
  - subscription
  - reservation payment snapshot
- The failure is explicit and actionable.
- No silent migration or automatic conversion occurs.

### US-MCB-4 - Platform Plans Have Explicit Per-Currency Prices
As a platform admin, I can manage plan prices per currency so subscriptions are billed from explicit catalog prices.

Acceptance criteria:
- A global plan can have one active price row per `(plan_id, currency_code, billing_period)`.
- `CAD`, `EUR`, and `USD` prices are seedable by default.
- `stripe_price_id` can be nullable until Stripe catalog sync is completed.
- Future currencies can be added without schema redesign.

### US-MCB-5 - Checkout Uses the Actual Charged Currency
As a customer, the checkout amount shown and charged matches the real billing currency, without display-only conversion tricks.

Acceptance criteria:
- Sale checkout uses the sale currency.
- Invoice checkout uses the invoice currency.
- Subscription checkout uses the resolved plan price currency.
- Checkout UI displays the same currency as the Stripe charge.

### US-MCB-6 - Historical Records Preserve Their Original Currency
As a finance user, I can inspect quotes, invoices, orders, subscriptions, and payments later without old records being reinterpreted by a changed tenant currency.

Acceptance criteria:
- Existing commercial records persist `currency_code`.
- New payment rows inherit the charged currency.
- Snapshot amounts remain unchanged after tenant settings updates.

### US-MCB-7 - Stripe Plan Price Resolution Is Deterministic
As the billing system, I can resolve the correct plan price row for a tenant currency and fail clearly when a supported plan price does not exist.

Acceptance criteria:
- A resolver returns the active `plan_prices` row for `(plan_code, tenant_currency, billing_period)`.
- Stripe subscription creation uses the resolved `stripe_price_id`.
- Missing price rows raise a domain exception with a clear message.

## Target Architecture

### Core Domain Types
- `App\Enums\CurrencyCode`
- `App\Enums\BillingPeriod`
- `App\Data\MoneyData`
- `App\Data\PlanPriceData`
- `App\Data\TenantCurrencyData`

### Core Services / Actions
- `ResolveTenantCurrency`
- `PreventUnsafeTenantCurrencyChange`
- `AssignCurrencyToCatalogItem`
- `ResolvePlanPriceForTenant`
- `ResolveStripeChargeMoney`
- `CreateStripeCheckoutForTenant`
- `CreateStripeSubscriptionForTenant`

### Suggested Persistence Model

#### Tenant Root
- Keep `users` as the current tenant root for backward compatibility.
- Add:
  - `currency_code`
  - `country_code` nullable
  - `locale` keep existing field

#### Catalog
- Update `products`:
  - add `currency_code`
  - keep `price` for backward compatibility in phase 1
  - introduce normalized accessors / DTOs so code can migrate toward explicit money semantics without a hard break

#### Global Plans
- Add `plans`
- Add `plan_prices`

`plan_prices` fields:
- `plan_id`
- `currency_code`
- `billing_period`
- `amount`
- `stripe_price_id` nullable
- `is_active`

Unique rule:
- unique `(plan_id, currency_code, billing_period)`

#### Commercial Documents
- Add `currency_code` to:
  - `quotes`
  - `invoices`
  - `sales`
  - `payments`
  - `stripe_subscriptions`
- Add `currency_code` to snapshot tables where relevant:
  - `quote_products`
  - `sale_items`
  - reservation payment metadata snapshots

## Phased Implementation Plan

### Phase 0 - Discovery and Safety Rails
- Status: Completed
- Confirm every place where money is created, mutated, displayed, or sent to Stripe.
- Add a short implementation note in `docs/` to document migration assumptions.
- Decide the canonical tenant root now: `users`.

Definition of done:
- All currency-sensitive flows are listed.
- Backfill assumptions are documented.

### Phase 1 - Core Currency Domain
- Status: Completed
- Introduce:
  - `CurrencyCode` enum
  - `BillingPeriod` enum
  - money DTO/value object layer
- Add tenant currency support on `users`.
- Default new tenants to `CAD`.
- Add a guard service to block unsafe tenant currency changes after business activity exists.

Definition of done:
- Tenant currency is persisted and validated.
- Legacy tenants are backfilled to `CAD`.
- Currency change is blocked once activity exists.

### Phase 2 - Catalog Currency Persistence
- Status: Completed
- Add `currency_code` to `products`.
- Ensure product/service create flows assign tenant currency automatically.
- Ensure edit flows preserve item currency.
- Update Stripe catalog sync to use item currency instead of global config currency.

Definition of done:
- New catalog items always persist a currency.
- Legacy catalog rows are backfilled safely.
- Stripe catalog sync reads row currency.

### Phase 3 - Plan Catalog Normalization
- Status: Completed
- Create `plans` and `plan_prices`.
- Seed default plans with explicit prices for `CAD`, `EUR`, and `USD`.
- Introduce plan price resolver service for tenant currency and billing period.
- Keep the old config as a transitional fallback only where strictly necessary.

Definition of done:
- Global plans are resolvable from the database.
- Missing per-currency pricing fails clearly.
- Admin-facing payloads can expose all currency prices for each plan.

### Phase 4 - Commercial Document Currency Snapshots
- Status: Completed
- Add `currency_code` to business documents and money snapshots:
  - `quotes`
  - `quote_products`
  - `invoices`
  - `sales`
  - `sale_items`
  - `payments`
  - `stripe_subscriptions`
- Backfill legacy rows to `CAD` unless a more reliable business signal exists.
- Update creation flows so document currency is assigned from business context once and then preserved.

Definition of done:
- Every new commercial record persists currency.
- Existing records are backfilled without destructive conversion.
- Historical records no longer depend on current tenant settings.

### Phase 5 - Stripe Online Payments Refactor
- Status: Completed
- Replace global-config currency selection in:
  - `StripeCatalogService`
  - `StripeInvoiceService`
  - `StripeSaleService`
  - `StripeBillingService`
- Build Stripe payloads from resolved money DTOs.
- Add a clean plan price resolver for subscription checkout and swaps.
- Persist subscription currency and chosen plan price context.

Definition of done:
- Online invoice checkout uses invoice currency.
- Online sale checkout uses sale currency.
- Subscription checkout uses plan price currency.
- No CAD hardcoding remains in Stripe payload creation.

### Phase 6 - Settings, Admin UX, and API Contracts
- Status: Completed
- Expose tenant currency in company settings.
- Expose plan price matrix per currency in admin billing management.
- Make product/service forms show tenant currency explicitly.
- Remove ambiguous UI where display currency differs from billed currency.

Definition of done:
- Tenant admins see and understand their billing currency.
- Platform admins can manage per-currency plan prices.
- Checkout screens display the exact charged currency.

### Phase 7 - Tests and Rollout
- Status: Completed
- Add unit tests for:
  - tenant currency resolution
  - unsafe tenant currency change guard
  - plan price resolution
  - Stripe payload builders
- Add feature tests for:
  - tenant creation default currency
  - tenant creation with `EUR` and `USD`
  - product/service currency assignment
  - blocked unsafe currency change
  - legacy backfill behavior
  - missing plan price failure

Definition of done:
- Multi-currency flows are covered by unit and feature tests.
- Deployment caveats and manual Stripe mapping steps are documented.

## Migration Strategy

### Backfill Rules
- If tenant currency is missing, set `CAD`.
- If a tenant-owned catalog row has no currency, set `CAD`.
- If a commercial document has no currency, set `CAD`.
- Do not recalculate numeric amounts during backfill.

### Safety Rules
- Avoid destructive schema replacements.
- Prefer additive migrations.
- Keep legacy `price`/`amount` columns where possible.
- Move runtime code to new currency-aware services before removing old assumptions.

### Reversibility
- Structural migrations should be reversible where practical.
- Data backfill migrations are logically one-way, even if the schema rollback exists.

## Known Limitations for the First Cut
- Existing platform subscription config remains as a transitional compatibility layer until all read paths move to `plans` and `plan_prices`.
- Tenant currency change is blocked instead of migrated.
- Reservation money remains snapshot-oriented through metadata until a dedicated reservation payment table is introduced.
- Stripe Dashboard price mapping still requires manual `stripe_price_id` management or later sync tooling.

## Extension Path
- Add new currencies by:
  - extending `CurrencyCode`
  - seeding new `plan_prices`
  - adding validation support
  - syncing Stripe price IDs for the new currency
- Add per-country overrides by inserting scoped price rows on top of the existing resolver contract.
- Add tenant-specific negotiated plan pricing by layering a higher-priority resolver before the global plan price lookup.
