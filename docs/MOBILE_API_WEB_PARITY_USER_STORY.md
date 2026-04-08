# Mobile API and Web Parity - User Story and Deep Analysis

## Goal
Align the mobile API surface with the full set of billing, pricing, onboarding, and plan-enforcement behaviors already implemented on the web so native clients can reproduce the same product rules without reverse-engineering web page props or reimplementing business logic locally.

This document is intentionally deeper than a route checklist. It captures:
- the current web source of truth
- the real parity gaps between web and API
- the business subtleties mobile must preserve
- user stories and acceptance criteria
- a proposed API contract strategy
- a phased implementation and QA plan

## Scope
Primary scope:
- public pricing data used by prospects and mobile upsell flows
- onboarding plan selection and checkout orchestration
- billing settings and subscription management
- promotion-aware price presentation
- plan restrictions and feature gating
- Stripe checkout and subscription flows exposed through the API

Out of scope for the first alignment cut:
- full redesign of mobile navigation
- payment provider redesign
- advanced analytics and experimentation infrastructure
- offline-first billing mutations

## Source of Truth Reviewed
The analysis below is based on the current implementation in:
- `routes/web.php`
- `routes/api.php`
- `app/Http/Controllers/Controller.php`
- `app/Http/Controllers/OnboardingController.php`
- `app/Http/Controllers/LegalController.php`
- `app/Http/Controllers/Settings/BillingSettingsController.php`
- `app/Http/Controllers/Settings/SubscriptionController.php`
- `app/Services/BillingPlanService.php`
- `app/Services/BillingSubscriptionService.php`
- `app/Services/SubscriptionPromotionService.php`
- `app/Services/CreateStripeSubscriptionForTenant.php`
- `app/Services/CompanyFeatureService.php`
- `docs/api/openapi.json`
- product tests that already codify behavior

## Executive Summary
The application already shares some controller logic between web and API through `inertiaOrJson()`, which is a useful bridge, but it is not enough to guarantee mobile parity.

Today, the main issue is not only missing endpoints. The larger issue is that the API often exposes raw web page props instead of a stable, intentional mobile contract. This creates five risks:
- mobile must infer product rules from page-shaped payloads
- some behaviors exist on the web but have no API route at all
- some important business rules are enforced server-side but not clearly discoverable through API metadata
- Stripe and plan logic are more nuanced than a simple `plan_key + amount`
- the generated OpenAPI file is too shallow to safely guide native implementation

The alignment strategy should therefore be:
1. keep the current business services as the single source of truth
2. stop asking mobile to recompute pricing, promotions, or plan restrictions locally
3. add explicit API contracts for public pricing, onboarding state, billing state, and billing mutations
4. normalize provider-specific differences behind capability flags and stable response objects
5. document every contract with real schemas and examples

## Current Baseline

### 1. Shared Controller Pattern
The application uses `Controller::inertiaOrJson()` to return JSON on `api/*` requests or standard Inertia props on web routes. This means the API already inherits some of the web behavior, but the response shape is often page-centric instead of mobile-centric.

Consequence:
- parity is partial
- behavior reuse is good
- contract stability is weak

### 2. Web Billing and Pricing Are Already Rich
The web experience already includes a large amount of product logic:
- solo vs team pricing catalogs
- monthly vs yearly billing periods
- annual monthly-equivalent display
- separate monthly and yearly promotions
- owner-only plan restrictions
- provider-specific billing capabilities
- assistant addon and assistant credits
- Stripe Connect readiness
- payment method settings
- loyalty settings
- feature gating based on plan, sector, and tenant overrides

### 3. API Coverage Exists but Is Incomplete
The API already exposes:
- `GET /api/v1/onboarding`
- `POST /api/v1/onboarding`
- `GET /api/v1/settings/billing`
- `PUT /api/v1/settings/billing`
- `POST /api/v1/settings/billing/checkout`
- `POST /api/v1/settings/billing/connect`
- `POST /api/v1/settings/billing/assistant-addon`
- `POST /api/v1/settings/billing/swap`
- `POST /api/v1/settings/billing/portal`
- `POST /api/v1/settings/billing/payment-method`

But it does not expose all web-equivalent capabilities and does not yet present a fully intentional mobile contract.

## Deep Gap Analysis

### Gap A - Public Pricing Has No Dedicated API Contract
The web pricing page has a rich source of truth through `LegalController::pricing()` and `BillingPlanService`.

Web currently exposes:
- `pricingCatalogs`
- `defaultAudience`
- `pricingPlans`
- `highlightedPlanKey`
- `comparisonSections`
- public menu/footer content
- plan-level `prices_by_period`
- promotion-aware price presentation
- plan metadata such as `audience`, `recommended`, `contact_only`, `onboarding_enabled`

There is no equivalent public API endpoint dedicated to mobile or prospect-facing use cases.

Why this matters:
- native apps cannot safely reproduce pricing cards
- upsell flows inside authenticated mobile screens cannot rely on a stable public catalog contract
- the current OpenAPI does not document a public pricing contract

Required parity behaviors:
- mobile must receive the same monthly and yearly options as the web
- mobile must receive original and discounted prices already computed
- mobile must receive promotion metadata per billing period
- mobile must receive `contact_only` and `onboarding_enabled`
- mobile must receive the same highlighted plan per audience

### Gap B - Onboarding State Is Only Partially API-Ready
`OnboardingController@index` and `OnboardingController@store` already work through API routes, but the flow still has web-shaped assumptions and one missing route.

Important web behaviors already implemented:
- onboarding can preselect a requested plan
- onboarding can preselect a billing period
- onboarding plan selection changes the expected company team size
- onboarding decides whether checkout is required
- owner-only plan restrictions are enforced before checkout
- legacy direct-completion behavior still exists for non-JSON web flows
- successful Stripe onboarding checkout returns to `/onboarding/billing`

Current gap:
- web has `GET /onboarding/billing`
- API does not expose `GET /api/v1/onboarding/billing`

Why this matters:
- mobile needs a safe callback or polling path to confirm checkout success
- without a mobile-friendly callback contract, native flows must guess when onboarding is complete
- the onboarding state machine is implicit in controller logic instead of explicit in API terms

Required parity behaviors:
- mobile must know whether checkout is required
- mobile must know the selected plan and billing period
- mobile must know whether onboarding is fully complete
- mobile must be able to finalize or refresh billing state after external Stripe checkout

### Gap C - Billing Settings Payload Is Powerful but Page-Oriented
`BillingSettingsController@edit()` already computes a lot of the right data, but it is exposed as a page payload rather than a clearly versioned mobile contract.

Billing page data already includes:
- billing provider metadata
- provider readiness
- tenant billing currency
- annual discount config
- plans with pricing data
- subscription summary
- seat quantity
- active plan key
- assistant addon state
- credit balance metadata
- Stripe Connect state
- payment methods configuration
- loyalty state
- checkout and connect status markers

Why this is not enough for mobile:
- the payload is designed for a page, not for a mobile domain contract
- provider-specific branches create shape variability
- some fields exist for web rendering convenience rather than mobile intent
- capability flags are mixed with presentational concerns

Required parity behaviors:
- mobile must get a normalized billing summary
- mobile must get available actions and whether each action is allowed
- mobile must get plan matrix data in a stable format
- mobile must get provider-neutral subscription state even when provider-specific details are also included

### Gap D - Assistant Credit Checkout Exists on Web but Not in API
The web has `POST /settings/billing/assistant-credits`, but the API route is missing.

Why this matters:
- the mobile billing experience cannot fully match the web assistant monetization flow
- mobile cannot initiate assistant credit purchases without a special-case workaround

Required parity behaviors:
- mobile must be able to create assistant credit checkout sessions
- mobile must receive the same eligibility gating used by the web
- mobile must receive a clear reason when the action is unavailable

### Gap E - Promotions Are More Subtle Than a Single Discount Field
The current promotion system already supports:
- active or inactive global promotion
- separate monthly and yearly discount percentages
- Stripe coupon mapping per billing period
- original and discounted displayed prices
- display-only yearly monthly-equivalent pricing on web

Tests already codify important subtleties:
- a plan can be discounted monthly and yearly with different percentages
- yearly display still keeps monthly-style presentation while preserving annual billing semantics
- the web uses precomputed presentation fields from the backend

Why this matters for mobile:
- mobile must never recompute discount rules from partial fields
- mobile must not assume one global discount percentage
- mobile must know whether a displayed monthly-equivalent price is actually billed yearly

Required parity behaviors:
- each price option must expose:
  - `billing_period`
  - `currency_code`
  - `display_price`
  - `original_display_price`
  - `discounted_display_price`
  - `is_discounted`
  - `promotion.is_active`
  - `promotion.discount_percent`
  - period-specific legal copy or billing subtitle
- checkout amount and displayed discounted amount must remain aligned through backend resolution

### Gap F - Plan Restrictions and Feature Gating Are Critical and Easy to Miss
Web behavior is not driven by price data alone. It also depends on plan restrictions enforced by:
- `BillingSubscriptionService`
- `CompanyFeatureService`

Important business rules already in place:
- owner-only solo plans force quantity `1`
- owner-only solo plans reject invites and extra team size
- some collaborative modules are hidden or blocked even if stale data exists
- sector defaults can enable or disable modules
- plan defaults, sector defaults, and tenant overrides are merged into effective features
- owner-only plans force-disable `team_members` and `presence`
- some modules remain partially available in owner-only simplified mode

Why this matters for mobile:
- mobile must not infer available features from `plan_key` alone
- mobile must not show screens or actions blocked by effective features
- mobile must not rely on stale plan module settings

Required parity behaviors:
- mobile must receive effective feature flags from the backend
- mobile must receive capability flags for plan mutations and billing actions
- mobile must receive normalized restriction errors for disallowed plan selections

### Gap G - Provider Differences Are Not Fully Normalized
The platform supports different billing providers, but Stripe currently has richer subscription metadata and richer flows than Paddle.

Current observed differences:
- Stripe exposes plan code and billing period more clearly
- Stripe supports checkout, coupons, assistant credits, connect status, and richer metadata
- Paddle branches exist and affect which actions are available

Why this matters for mobile:
- mobile should not be forced to understand provider internals before rendering a billing screen
- mobile needs stable cross-provider state plus optional provider-specific details

Required parity behaviors:
- normalized top-level `billing.provider`
- normalized top-level `subscription`
- normalized capability booleans such as:
  - `can_checkout`
  - `can_swap`
  - `can_open_portal`
  - `can_buy_assistant_credits`
  - `can_manage_payment_methods`
- provider-specific nested objects only when relevant

### Gap H - API Documentation Is Not Ready for Native Consumption
`docs/api/openapi.json` exists, but billing and onboarding contracts are still shallow:
- generic summaries such as `api.`
- missing request schemas
- missing response schemas
- missing examples
- missing domain-specific error documentation

Why this matters:
- mobile engineering will fill the gaps by reading controllers
- that increases coupling to implementation details
- parity bugs become more likely during future iterations

Required parity behaviors:
- every mobile-relevant billing and onboarding endpoint must have:
  - a request schema
  - a response schema
  - success examples
  - validation error examples
  - domain error examples
  - notes for external checkout flows

## Product Principles for Mobile Alignment

### 1. Backend-Computed Truth
Mobile must consume backend-computed pricing and capability data. It must not recompute:
- discounts
- annual monthly-equivalent display
- owner-only restrictions
- seat quantity logic
- feature availability

### 2. Stable Domain Contracts Over Page Props
The API should not simply expose whatever the web page needs. It should expose mobile-friendly, versioned domain contracts.

### 3. Explicit Capabilities Over Implicit Logic
If the mobile app can or cannot do something, the API should say so directly through capability flags and typed errors.

### 4. External Checkout Must Have an Explicit Return Model
Any flow that leaves the app for Stripe must have a clear re-entry model:
- callback endpoint
- state refresh endpoint
- stable success and cancel semantics

### 5. One Pricing Story Across Marketing, Onboarding, and Billing
Public pricing, onboarding pricing, billing settings, and checkout must all consume the same underlying plan and promotion presentation model.

## User Stories

### US-MOBILE-BILLING-1 - Public Pricing Parity
As a mobile prospect or logged-in user, I can view the same pricing catalog as the web so I see the same plans, periods, promotions, and recommended options.

Acceptance criteria:
- mobile can request a public pricing catalog for `solo` and `team`
- each returned plan includes `prices_by_period`
- each price option includes original and discounted display values
- the response includes the highlighted plan per audience
- `contact_only` plans are clearly marked
- comparison rows are available when the mobile product wants to show them

### US-MOBILE-BILLING-2 - Onboarding Plan Selection Parity
As a new mobile user, I can select a plan during onboarding with the same business rules as the web so I do not hit inconsistent checkout or completion behavior.

Acceptance criteria:
- mobile receives the selected plan and billing period defaults
- mobile receives whether checkout is required
- owner-only plan validation matches the web
- the API returns actionable errors when a solo plan conflicts with invites or team size
- onboarding completion state can be refreshed after external checkout

### US-MOBILE-BILLING-3 - Billing Summary Parity
As a subscribed mobile user, I can see the same current plan, billing period, pricing, and available billing actions as on the web.

Acceptance criteria:
- the billing summary includes active plan key and billing period
- the billing summary includes current subscription state and trial state
- the billing summary includes available plan actions
- the billing summary includes provider readiness and provider label
- the billing summary includes normalized capabilities for mobile UI decisions

### US-MOBILE-BILLING-4 - Promotion Display Parity
As a mobile user, I can see discounted and original prices exactly as the web shows them so the app never presents misleading billing information.

Acceptance criteria:
- separate monthly and yearly promotion percentages are supported
- yearly prices can be shown as monthly-equivalent while preserving yearly billing semantics
- if no promotion is active, only the regular price is shown
- if a promotion is active, original and discounted display values are returned together
- the Stripe-applied discount matches the displayed backend-computed price

### US-MOBILE-BILLING-5 - Checkout and Swap Parity
As a mobile user, I can start checkout or swap plans from the app using the same plan resolution and restriction logic as the web.

Acceptance criteria:
- mobile sends `plan_key` and `billing_period`, not raw money calculations
- the backend resolves the correct plan price and Stripe coupon
- owner-only restrictions are enforced exactly as on the web
- successful responses return the next action needed by the app, including checkout URL when relevant
- domain failures return normalized machine-readable error codes

### US-MOBILE-BILLING-6 - Assistant Monetization Parity
As a mobile user, I can manage the assistant addon and buy assistant credits when eligible, just like on the web.

Acceptance criteria:
- mobile can retrieve assistant addon eligibility and status
- mobile can enable or disable the addon when allowed
- mobile can initiate assistant credit checkout when allowed
- mobile receives a clear denial reason when the feature is unavailable for the current plan or provider

### US-MOBILE-BILLING-7 - Feature Gating Parity
As a mobile user, I only see features and screens my plan and workspace actually allow, even in owner-only or sector-specific cases.

Acceptance criteria:
- the API exposes effective features, not just stored raw feature overrides
- owner-only plans hide collaborative capabilities on mobile
- sector defaults are reflected in returned capabilities
- stale configuration cannot re-enable blocked modules in mobile payloads

### US-MOBILE-BILLING-8 - API Contract Reliability
As a mobile engineer, I can implement billing and onboarding flows from documented contracts instead of reading controller internals.

Acceptance criteria:
- all mobile-relevant endpoints are in OpenAPI
- requests and responses have schemas
- domain errors are documented
- example payloads include pricing, promotion, and checkout use cases

## Proposed API Contract Strategy

### Option Recommended
Keep the existing domain services, but add explicit mobile-ready API resources around them instead of reusing raw Inertia page props forever.

Recommended shape:
- a small set of domain endpoints
- normalized DTO-style response objects
- provider-specific nested objects only when needed

## Proposed Endpoints

### 1. Public Pricing
`GET /api/v1/public/pricing`

Suggested query params:
- `audience=solo|team`
- `currency=CAD|USD|EUR`
- `include=comparison_sections`

Suggested response shape:
```json
{
  "audience": "solo",
  "highlighted_plan_key": "solo_pro",
  "plans": [
    {
      "key": "solo_pro",
      "name": "Solo Pro",
      "audience": "solo",
      "recommended": true,
      "contact_only": false,
      "onboarding_enabled": true,
      "annual_discount_percent": 20,
      "prices_by_period": {
        "monthly": {
          "billing_period": "monthly",
          "currency_code": "CAD",
          "display_price": "$39.00/mo",
          "original_display_price": "$60.00/mo",
          "discounted_display_price": "$39.00/mo",
          "is_discounted": true,
          "promotion": {
            "is_active": true,
            "discount_percent": 35
          },
          "billing_subtitle": "Billed monthly"
        },
        "yearly": {
          "billing_period": "yearly",
          "currency_code": "CAD",
          "display_price": "$31.20/mo",
          "original_display_price": "$60.00/mo",
          "discounted_display_price": "$31.20/mo",
          "is_discounted": true,
          "promotion": {
            "is_active": true,
            "discount_percent": 35
          },
          "billing_subtitle": "For 12 months, billed annually"
        }
      }
    }
  ],
  "comparison_sections": []
}
```

Notes:
- mobile should not derive yearly display copy from local calculations
- `billing_subtitle` should be backend-authored

### 2. Onboarding State
`GET /api/v1/onboarding`

Keep the existing route, but normalize the response into:
- tenant/account preset
- plan catalog subset needed by onboarding
- selected plan and billing period
- whether checkout is required
- whether onboarding is completed
- capabilities and restriction hints

Recommended additions:
- `GET /api/v1/onboarding/billing`
- `GET /api/v1/onboarding/status`

Purpose:
- finalize external checkout flows
- allow mobile polling after returning from the browser

### 3. Billing Summary
`GET /api/v1/settings/billing`

Keep the existing route, but formalize the contract into sections:
- `billing`
- `subscription`
- `plan_catalog`
- `capabilities`
- `assistant`
- `provider_details`
- `payment_methods`
- `loyalty`
- `flow_state`

Recommended billing section:
```json
{
  "billing": {
    "provider": "stripe",
    "provider_effective": "stripe",
    "provider_label": "Stripe",
    "provider_ready": true,
    "tenant_currency_code": "CAD"
  },
  "subscription": {
    "active": true,
    "on_trial": false,
    "status": "active",
    "plan_key": "solo_pro",
    "billing_period": "yearly"
  },
  "capabilities": {
    "can_checkout": true,
    "can_swap": true,
    "can_open_portal": true,
    "can_manage_payment_methods": false,
    "can_buy_assistant_credits": true
  }
}
```

### 4. Subscription Mutations
Existing routes to keep and formalize:
- `POST /api/v1/settings/billing/checkout`
- `POST /api/v1/settings/billing/swap`
- `POST /api/v1/settings/billing/portal`
- `POST /api/v1/settings/billing/payment-method`

Recommended request contract for checkout and swap:
```json
{
  "plan_key": "solo_pro",
  "billing_period": "yearly"
}
```

Recommended response contract:
```json
{
  "status": "requires_redirect",
  "action": "open_checkout",
  "url": "https://checkout.stripe.com/...",
  "resolved_plan": {
    "plan_key": "solo_pro",
    "billing_period": "yearly",
    "currency_code": "CAD",
    "promotion_discount_percent": 35
  },
  "return_urls": {
    "success_url": "mlkpro://billing/subscription-success?session_id={CHECKOUT_SESSION_ID}",
    "cancel_url": "mlkpro://billing/subscription-cancel"
  }
}
```

### 5. Assistant Credits
Add:
- `POST /api/v1/settings/billing/assistant-credits`

Response:
- checkout URL or explicit denial reason
- should support mobile-provided return URLs for browser handoff

### 6. Effective Feature Flags
Recommended addition:
- `GET /api/v1/account/capabilities`

Purpose:
- return `effective_features`
- return owner-only and sector-aware capability flags
- reduce leakage of billing logic into unrelated mobile screens

This can also be embedded into `GET /api/v1/auth/me` if the product prefers fewer requests, but the source of truth must remain `CompanyFeatureService`.

## Error Contract Recommendations
Validation errors are not enough for mobile parity. The API should distinguish domain errors from field-level validation.

Recommended domain error codes:
- `PLAN_OWNER_ONLY_REQUIRES_SINGLE_USER`
- `PLAN_OWNER_ONLY_DISALLOWS_INVITES`
- `BILLING_PROVIDER_NOT_READY`
- `SUBSCRIPTION_REQUIRED`
- `ASSISTANT_ADDON_NOT_AVAILABLE`
- `ASSISTANT_CREDITS_NOT_AVAILABLE`
- `CHECKOUT_SESSION_EXPIRED`
- `PROMOTION_CONFIGURATION_INVALID`
- `PLAN_PRICE_NOT_FOUND`

Recommended error shape:
```json
{
  "message": "Solo plans do not allow additional team members.",
  "code": "PLAN_OWNER_ONLY_REQUIRES_SINGLE_USER",
  "meta": {
    "plan_key": "solo_pro",
    "max_team_size": 1
  }
}
```

## Implementation Phases

### Phase 0 - Contract Discovery Freeze
Status: Draft completed

Phase 0 discovery artifact:
- `docs/MOBILE_API_PHASE_0_DISCOVERY_FREEZE.md`

Tasks:
- freeze the list of mobile-critical web billing behaviors
- list all mobile entry points that need billing data
- decide which current JSON responses can be stabilized versus replaced

Definition of done:
- this document is validated by backend and mobile stakeholders
- the target endpoint list is agreed

### Phase 1 - Public Pricing Contract
Status: Completed

Delivered endpoint:
- `GET /api/v1/public/pricing`

Tasks:
- add `GET /api/v1/public/pricing`
- expose audience-specific plan catalogs
- expose promotion-aware `prices_by_period`
- document the endpoint in OpenAPI

Definition of done:
- mobile can render pricing cards without web-specific assumptions
- mobile receives the same price presentation inputs as the web

### Phase 2 - Onboarding Contract Completion
Status: Completed

Delivered endpoint:
- `GET /api/v1/onboarding` normalized JSON contract
- `GET /api/v1/onboarding/billing`

Tasks:
- formalize `GET /api/v1/onboarding`
- add `GET /api/v1/onboarding/billing`
- add explicit onboarding status or finalize endpoint
- document checkout-required semantics

Definition of done:
- mobile can complete onboarding with or without external Stripe checkout
- mobile can refresh state after browser return

### Phase 3 - Billing Summary Normalization
Status: Completed

Tasks:
- formalize `GET /api/v1/settings/billing`
- add explicit `capabilities`
- normalize subscription and provider sections
- ensure assistant addon and loyalty sections are stable
- preserve billing flow query feedback through `flow_state`

Definition of done:
- mobile billing screen is driven by one intentional contract
- provider-specific branches do not break the core shape

### Phase 4 - Billing Mutations and Assistant Credits
Status: Completed

Tasks:
- formalize request and response schemas for checkout, swap, portal, and payment-method routes
- add `POST /api/v1/settings/billing/assistant-credits`
- add domain error codes

Delivered so far:
- `POST /api/v1/settings/billing/assistant-credits` now exists
- checkout and assistant credits return `status`, `action`, `url`, and `return_urls`
- swap, portal, and payment-method JSON responses now expose explicit mobile actions in addition to legacy fields
- assistant addon and Stripe Connect mutations now expose explicit mobile actions
- billing mutations now return stable domain `code` values for business errors

Implemented billing mutation error codes:
- `billing_provider_not_stripe`
- `billing_provider_not_paddle`
- `billing_provider_not_configured`
- `billing_portal_unavailable`
- `billing_subscription_required`
- `billing_plans_not_configured`
- `billing_invalid_plan_selection`
- `billing_plan_unchanged`
- `billing_plan_restricted`
- `billing_mutation_failed`
- `billing_payment_method_update_failed`
- `billing_invalid_provider_response`
- `assistant_unavailable_for_provider`
- `assistant_already_included`
- `assistant_not_configured`
- `assistant_activation_required`
- `assistant_credit_price_missing`
- `assistant_credit_pack_missing`
- `assistant_checkout_failed`
- `assistant_addon_update_failed`
- `stripe_not_configured`
- `stripe_connect_not_configured`
- `stripe_connect_onboarding_failed`

Notes:
- validation errors still use the standard Laravel `errors` payload
- business rule failures now also include `status=error` and a stable `code`

Definition of done:
- mobile can execute the same billing mutations as the web
- assistant monetization parity is complete

### Phase 5 - Capability and Feature Parity
Status: Proposed

Tasks:
- expose effective features from `CompanyFeatureService`
- ensure owner-only and sector restrictions are visible through API capabilities
- remove any mobile need to infer restricted screens from plan key alone

Definition of done:
- mobile feature visibility matches the web
- stale plan settings cannot create false-positive access on mobile

### Phase 6 - OpenAPI and Example Payloads
Status: Proposed

Tasks:
- replace shallow billing and onboarding path entries in `docs/api/openapi.json`
- add request and response schemas
- add error examples
- add external checkout notes

Definition of done:
- mobile implementation no longer depends on controller spelunking

### Phase 7 - Test Matrix and Regression Protection
Status: Proposed

Tasks:
- add API feature tests for public pricing
- add API feature tests for onboarding billing callback flow
- add API feature tests for assistant credit checkout
- add API feature tests for billing capability flags
- add regression tests for separate monthly and yearly promotions
- add owner-only API tests for restricted plan selection and hidden capabilities

Definition of done:
- the mobile contract is covered by tests, not just the web experience

## QA Matrix

### Pricing
- active monthly promo only
- active yearly promo only
- different monthly and yearly promo percentages
- no active promotion
- contact-only plan
- yearly display shown as monthly-equivalent with yearly billing subtitle

### Onboarding
- onboarding without checkout
- onboarding with Stripe checkout
- successful browser return from Stripe
- canceled browser return from Stripe
- solo plan with team size > 1
- solo plan with invites present

### Billing
- active subscription
- trialing subscription
- canceled subscription
- provider not ready
- Stripe vs Paddle behavior
- assistant included plan vs credit-based plan vs unavailable plan

### Feature Gating
- owner-only solo plan
- sector-enabled reservations
- stale stored plan modules that should not leak into effective features

## Risks
- continuing to expose raw Inertia props to mobile will create hidden contract debt
- undocumented provider branches will cause native-specific bugs
- local discount calculations in mobile will drift from Stripe-backed truth
- missing capability flags will push product logic into the app layer

## Recommended Next Steps
1. Validate this document with backend and mobile owners.
2. Ship `GET /api/v1/public/pricing` first because it unlocks both public mobile pricing and in-app upsell surfaces.
3. Add `GET /api/v1/onboarding/billing` and `POST /api/v1/settings/billing/assistant-credits` next because they are the clearest parity holes.
4. Normalize `GET /api/v1/settings/billing` around a stable domain contract before adding more mobile UI on top of the current page-shaped payload.
5. Upgrade OpenAPI only after the contract is intentionally shaped, not before.

## Definition of Done
- mobile can render pricing, onboarding, and billing screens from API contracts without reading web page props
- mobile receives backend-computed prices, promotions, and restriction metadata
- external Stripe flows have an explicit mobile-safe re-entry path
- assistant credits are available through API
- effective features and billing capabilities match the web
- OpenAPI documents the final mobile-relevant billing contract with examples
- API feature tests protect the parity layer against future regressions
