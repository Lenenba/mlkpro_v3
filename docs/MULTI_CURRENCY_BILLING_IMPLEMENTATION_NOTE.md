# Multi-Currency Billing Implementation Note

## Architecture

The billing source of truth is now explicit and currency-aware at every business layer.

- Tenant currency lives on `users.currency_code` because the account owner represents the tenant in the current architecture.
- Tenant-owned catalog records persist both amount and `currency_code` on each row.
- Platform subscription plans remain global through `plans` and `plan_prices`.
- `plan_prices` stores one explicit price row per `(plan, currency, billing_period)` and optionally stores the mapped Stripe price ID.
- Stripe subscription and checkout flows resolve the tenant-aware `plan_prices` row first, then build Stripe payloads from that resolved row.
- Commercial records now persist `currency_code` so historical amounts remain immutable after tenant settings change.

The main domain services introduced for this foundation are:

- `ResolveTenantCurrency`
- `AssignCurrencyToCatalogItem`
- `PreventUnsafeTenantCurrencyChange`
- `BillingPlanService`
- `ResolvePlanPriceForTenant`
- `CreateStripeSubscriptionForTenant`

## Migration Strategy

The application was previously CAD-centric. The migration keeps backward compatibility by assuming CAD when legacy records had no persisted currency.

- Existing tenants are backfilled to `CAD` when no currency existed.
- Existing tenant catalog and commercial rows are backfilled from the tenant currency, with `CAD` as the final fallback.
- Platform plans are seeded into `plans` and `plan_prices` using explicit CAD, EUR, and USD values.
- Existing Stripe subscription rows are backfilled with `currency_code`, `plan_code`, `plan_price_id`, and `billing_period` when the local `price_id` matches a seeded `plan_prices` row.
- Tenant currency changes are blocked once business activity exists, which is the safest initial policy.

The backfill logic was implemented with chunked row updates so the migration remains compatible with the SQLite test environment.

## Known Limitations

- Tenant currency changes are blocked after business activity instead of offering a guided migration flow.
- Stripe plan checkout requires `plan_prices.stripe_price_id` to be populated for the target currency.
- Super-admin analytics still inherit some legacy assumptions outside the direct billing source-of-truth path and may need a separate reporting pass if cross-currency MRR reporting is required.
- Stripe Terminal regional rules were intentionally left out of scope for this phase.

## Extending To More Currencies

To add another supported currency later:

1. Add the new case to `CurrencyCode`.
2. Seed new `plan_prices` rows for each supported plan and billing period.
3. Add the new Stripe price IDs in the environment or admin pricing UI.
4. Expose the currency in any supported-currency selector UI.
5. Add targeted tests for tenant defaults, plan resolution, and Stripe checkout payload selection.

No runtime FX conversion is required for billing correctness because each billed currency has its own explicit persisted price row.
