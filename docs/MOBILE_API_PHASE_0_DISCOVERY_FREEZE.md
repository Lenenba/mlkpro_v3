# Mobile API Phase 0 - Discovery Freeze

## Status
Draft completed on 2026-04-07.

This artifact turns Phase 0 of the mobile/web parity plan into an explicit baseline the backend and mobile teams can validate before endpoint work starts.

## Objective
Freeze the current state of:
- mobile-critical web billing behaviors
- existing API entry points mobile can already call
- missing routes and missing contracts
- the endpoint strategy for the next phases

This document is intentionally practical. It is the handoff from analysis to implementation planning.

## Source of Truth
Validated against:
- `routes/web.php`
- `routes/api.php`
- `app/Http/Controllers/Controller.php`
- `app/Http/Controllers/Api/AuthController.php`
- `app/Http/Controllers/OnboardingController.php`
- `app/Http/Controllers/LegalController.php`
- `app/Http/Controllers/Settings/BillingSettingsController.php`
- `app/Http/Controllers/Settings/SubscriptionController.php`
- `app/Services/BillingPlanService.php`
- `app/Services/BillingSubscriptionService.php`
- `app/Services/SubscriptionPromotionService.php`
- `app/Services/CompanyFeatureService.php`
- `docs/api/openapi.json`
- billing and pricing feature tests already present in the codebase

## Frozen Inventory of Mobile-Critical Web Behaviors

### 1. Session Bootstrap
Current mobile bootstrap API:
- `POST /api/v1/auth/login`
- `POST /api/v1/auth/register`
- `POST /api/v1/auth/two-factor/verify`
- `POST /api/v1/auth/two-factor/resend`
- `GET /api/v1/auth/me`

Current `auth/me` already returns:
- `user`
- `meta.role_name`
- `meta.owner_id`
- `meta.is_owner`
- `meta.is_client`
- `meta.is_superadmin`
- `meta.is_platform_admin`
- `meta.company`
- `meta.features`
- `meta.platform`
- `meta.team`

Decision:
- `GET /api/v1/auth/me` remains the session bootstrap contract.
- Mobile should use it for identity, onboarding state, and broad feature visibility.
- Billing-specific detail should not be stuffed into `auth/me` in Phase 1 unless a critical mobile blocker appears.

### 2. Public Pricing
Current web source of truth:
- `GET /pricing`
- `LegalController::pricing()`
- `BillingPlanService`

Current web payload includes:
- `pricingCatalogs`
- `defaultAudience`
- `pricingPlans`
- `highlightedPlanKey`
- `comparisonSections`
- plan metadata such as `audience`, `recommended`, `contact_only`, `onboarding_enabled`
- `prices_by_period`
- promotion-aware display values

Frozen behavior:
- pricing is audience-aware (`solo` and `team`)
- pricing is billing-period-aware (`monthly` and `yearly`)
- pricing can be promotion-aware with separate monthly and yearly discounts
- yearly presentation can be rendered as monthly-equivalent while still meaning yearly billing
- the backend already computes the display-ready pricing shape mobile needs

Current API status:
- no dedicated public pricing endpoint exists

Decision:
- a new dedicated endpoint is required
- mobile must not consume web page props or reconstruct pricing locally

### 3. Onboarding
Current web and API routes:
- `GET /onboarding`
- `POST /onboarding`
- `GET /onboarding/billing`
- `GET /api/v1/onboarding`
- `POST /api/v1/onboarding`

Frozen behavior:
- onboarding can preselect `plan_key`
- onboarding can preselect `billing_period`
- onboarding payload includes plan catalog, limits, supported currencies, and selected defaults
- onboarding decides whether checkout is required
- owner-only plan restrictions are enforced before checkout
- successful external Stripe checkout returns through `/onboarding/billing`

Current API status:
- onboarding index and submit routes exist
- onboarding billing callback route does not exist in API

Decision:
- keep `GET /api/v1/onboarding` and `POST /api/v1/onboarding`
- add `GET /api/v1/onboarding/billing` in Phase 2
- mobile must not infer checkout completion from browser return alone

### 4. Billing Summary
Current web and API routes:
- `GET /settings/billing`
- `PUT /settings/billing`
- `GET /api/v1/settings/billing`
- `PUT /api/v1/settings/billing`

Frozen behavior:
- billing summary is provider-aware
- billing summary includes subscription state, plan catalog, seat quantity, payment methods, assistant addon state, loyalty state, Stripe Connect state, and provider readiness
- billing summary already uses backend-computed plan data from `BillingPlanService`
- some payload sections are currently page-oriented rather than mobile-oriented

Decision:
- keep `GET /api/v1/settings/billing`
- normalize and document the contract instead of replacing the route
- keep `PUT /api/v1/settings/billing` for settings mutation

### 5. Billing Mutations
Current web and API routes:
- `POST /settings/billing/checkout`
- `POST /settings/billing/connect`
- `POST /settings/billing/assistant-addon`
- `POST /settings/billing/swap`
- `POST /settings/billing/portal`
- `POST /settings/billing/payment-method`
- `POST /api/v1/settings/billing/checkout`
- `POST /api/v1/settings/billing/connect`
- `POST /api/v1/settings/billing/assistant-addon`
- `POST /api/v1/settings/billing/swap`
- `POST /api/v1/settings/billing/portal`
- `POST /api/v1/settings/billing/payment-method`

Frozen behavior:
- checkout and swap resolve plans by backend plan catalog and billing period
- owner-only restrictions are enforced server-side
- Stripe coupon application is resolved server-side
- provider differences affect which actions are actually allowed

Decision:
- keep all existing billing mutation routes
- formalize their request and response schemas before mobile uses them as stable contracts
- add machine-readable domain error codes in later phases

### 6. Assistant Credits
Current routes:
- `POST /settings/billing/assistant-credits`

Current API status:
- no `/api/v1/settings/billing/assistant-credits`

Frozen behavior:
- assistant credit checkout has real gating on provider, subscription state, and addon eligibility

Decision:
- add `POST /api/v1/settings/billing/assistant-credits` in Phase 4

### 7. Feature Gating and Owner-Only Restrictions
Current source of truth:
- `BillingSubscriptionService`
- `CompanyFeatureService`
- related tests for solo owner-only behavior

Frozen behavior:
- plan access is not determined by `plan_key` alone
- owner-only solo plans force single-user semantics
- sector defaults can enable or disable modules
- stale plan module settings must not leak into effective behavior
- some modules can be available in simplified owner-only mode

Decision:
- mobile must consume backend-provided effective features and capabilities
- mobile must not derive screen availability from plan key alone

## Mobile Entry Points That Need Billing-Aware Data

### EP-1 - Logged-Out Marketing Pricing
User need:
- browse plans and compare `solo` vs `team`

Required data:
- public pricing catalog
- highlighted plan
- prices by billing period
- promotion metadata
- optional comparison sections

Decision:
- served by new `GET /api/v1/public/pricing`

### EP-2 - Auth Bootstrap
User need:
- restore authenticated session and decide whether to route to onboarding, dashboard, or restricted states

Required data:
- identity
- company onboarding state
- broad feature visibility

Decision:
- served by existing `GET /api/v1/auth/me`

### EP-3 - Onboarding Plan Selection
User need:
- choose plan, period, company setup, and complete onboarding

Required data:
- selected plan and period defaults
- plan options safe for onboarding
- whether checkout is required
- validation rules and owner-only restrictions

Decision:
- served by existing `GET /api/v1/onboarding` and `POST /api/v1/onboarding`

### EP-4 - Post-Checkout Onboarding Return
User need:
- return from Stripe checkout and finalize onboarding state

Required data:
- checkout result
- refreshed subscription state
- onboarding completion status

Decision:
- not fully served today
- add `GET /api/v1/onboarding/billing`

### EP-5 - Authenticated Billing Screen
User need:
- inspect current plan, billing period, pricing, addon state, and available actions

Required data:
- normalized billing summary
- subscription summary
- plan catalog for upgrade and downgrade UI
- capability flags

Decision:
- served by existing `GET /api/v1/settings/billing`, but contract must be stabilized

### EP-6 - In-App Upsell or Plan Change
User need:
- open a paywall or upgrade sheet from anywhere in the app

Required data:
- current plan
- available target plans
- checkout or swap capability
- promotion-aware display values

Decision:
- primary data from `GET /api/v1/settings/billing`
- public parity data from `GET /api/v1/public/pricing` when needed

### EP-7 - Assistant Monetization
User need:
- enable assistant addon or buy extra credits

Required data:
- eligibility
- current assistant mode
- current credit balance
- checkout creation endpoint

Decision:
- addon toggle uses existing `POST /api/v1/settings/billing/assistant-addon`
- credit purchase requires new `POST /api/v1/settings/billing/assistant-credits`

## Endpoint Strategy Freeze

### Stabilize In Place
These routes already represent the right domain area and should be formalized rather than replaced.

| Endpoint | Decision | Reason |
| --- | --- | --- |
| `GET /api/v1/auth/me` | Stabilize | Already the right bootstrap route for session, role, company, and features |
| `GET /api/v1/onboarding` | Stabilize | Correct domain route; payload needs documentation and normalization, not route replacement |
| `POST /api/v1/onboarding` | Stabilize | Correct mutation route; response states need formalization |
| `GET /api/v1/settings/billing` | Stabilize | Correct domain route; current issue is contract shape, not route ownership |
| `PUT /api/v1/settings/billing` | Stabilize | Correct settings mutation route |
| `POST /api/v1/settings/billing/checkout` | Stabilize | Correct checkout entry point |
| `POST /api/v1/settings/billing/swap` | Stabilize | Correct plan-change entry point |
| `POST /api/v1/settings/billing/portal` | Stabilize | Correct billing portal entry point |
| `POST /api/v1/settings/billing/connect` | Stabilize | Correct provider connect entry point |
| `POST /api/v1/settings/billing/assistant-addon` | Stabilize | Correct assistant addon mutation route |
| `POST /api/v1/settings/billing/payment-method` | Stabilize | Correct payment-method flow route, even if provider-specific |

### Add New Endpoints
These routes are missing and should be introduced rather than worked around on mobile.

| Endpoint | Decision | Reason |
| --- | --- | --- |
| `GET /api/v1/public/pricing` | Add | No public pricing contract exists today |
| `GET /api/v1/onboarding/billing` | Add | Web has a billing callback path, API does not |
| `POST /api/v1/settings/billing/assistant-credits` | Add | Web capability exists, API parity missing |

### Explicitly Avoid in Phase 1
These patterns should not become the mobile integration strategy.

Avoid:
- reading undocumented raw Inertia page props as a permanent API contract
- reconstructing promotions locally from partial fields
- deriving owner-only restrictions from `plan_key` in the app
- copying public menu and footer payloads into mobile pricing scope unless the product explicitly needs them

## Contract Decisions Frozen for Phase 1

### Decision 1 - Pricing Display Is Backend-Authored
Mobile will consume:
- `display_price`
- `original_display_price`
- `discounted_display_price`
- `promotion`
- `billing_subtitle`

Mobile will not compute:
- monthly vs yearly display formatting rules
- promo percent selection
- yearly monthly-equivalent copy

### Decision 2 - Billing Period Remains a First-Class Input
Mutations and pricing contracts must keep:
- `monthly`
- `yearly`

No mobile flow should downgrade to a single ambiguous price field.

### Decision 3 - Public Pricing and Authenticated Billing Are Separate Contracts
The public pricing API and the authenticated billing summary API can share the same backend plan services, but they should not be the same endpoint.

Reason:
- public pricing serves prospects and upsell
- billing summary serves the current tenant state and capabilities

### Decision 4 - `auth/me` Stays Lean
`auth/me` remains the session bootstrap contract.

It can keep:
- role and company state
- onboarding state
- broad feature visibility

It should not become the full billing summary contract.

### Decision 5 - OpenAPI Is Not the Source of Truth Yet
`docs/api/openapi.json` remains incomplete for these flows and must be treated as lagging documentation until later phases update it.

## Phase 0 Exit Criteria
Phase 0 is considered complete when the team agrees that:
- the frozen web behaviors above are the product truth mobile must match
- the mobile entry points above cover the billing-related native flows
- the stabilization vs addition matrix above is accepted
- Phase 1 starts with `GET /api/v1/public/pricing`

## Recommended Immediate Backlog After Phase 0
1. Implement `GET /api/v1/public/pricing`.
2. Formalize `GET /api/v1/settings/billing` response sections.
3. Add `GET /api/v1/onboarding/billing`.
4. Add `POST /api/v1/settings/billing/assistant-credits`.
5. Upgrade OpenAPI only after the stabilized contracts are real.
