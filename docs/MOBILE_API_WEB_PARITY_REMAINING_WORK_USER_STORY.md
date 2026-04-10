# Mobile API and Web Parity - Remaining Work User Story

## Status
Draft started on 2026-04-10.

Current scope decisions finalized on 2026-04-10.

This document is the new working source of truth for the remaining mobile/web API parity work after the initial billing, pricing, and onboarding parity phases.

It does not replace the historical analysis in:
- `docs/MOBILE_API_WEB_PARITY_USER_STORY.md`
- `docs/MOBILE_API_PHASE_0_DISCOVERY_FREEZE.md`

It does supersede those documents for deciding what remains to be built next.

## Why This Document Exists
The first parity pass started as a billing and onboarding gap analysis. Since then, a large part of that work has already been implemented in the codebase:
- public pricing API now exists
- onboarding billing callback API now exists
- billing summary has a normalized mobile-oriented contract
- billing mutations now expose explicit mobile actions and stable domain error codes
- assistant credit checkout API now exists

The remaining work is no longer "find the first missing billing routes".

The remaining work is now:
- freeze the current delivered contracts as intentional mobile contracts
- bring generated API documentation back in sync with the real backend
- harden the mobile bootstrap contract
- close the next mobile-critical web/API parity gaps outside the initial billing block

## Source of Truth Precedence
When there is a mismatch, use this precedence:

1. feature tests and real backend behavior
2. this document
3. route and controller implementation
4. generated `docs/api/openapi.json`
5. generated `docs/api/postman_collection.json`
6. earlier discovery and draft parity documents

Reason:
- OpenAPI and Postman are currently lagging behind the real implementation for several mobile-relevant contracts
- earlier parity docs still describe some endpoints as missing even though they are now implemented

## Current Baseline Already Delivered

### Delivered billing and onboarding parity
The following mobile-critical endpoints are already present in `routes/api.php`:
- `GET /api/v1/public/pricing`
- `GET /api/v1/onboarding`
- `POST /api/v1/onboarding`
- `GET /api/v1/onboarding/billing`
- `GET /api/v1/settings/billing`
- `PUT /api/v1/settings/billing`
- `POST /api/v1/settings/billing/checkout`
- `POST /api/v1/settings/billing/connect`
- `POST /api/v1/settings/billing/assistant-addon`
- `POST /api/v1/settings/billing/assistant-credits`
- `POST /api/v1/settings/billing/swap`
- `POST /api/v1/settings/billing/portal`
- `POST /api/v1/settings/billing/payment-method`

### Existing proof in tests
The delivered billing/onboarding contract is already partially protected by tests:
- `tests/Feature/PublicPricingApiTest.php`
- `tests/Feature/OnboardingBillingApiTest.php`
- `tests/Feature/BillingSettingsApiTest.php`
- `tests/Feature/BillingMutationsApiTest.php`

### Important implication
The mobile/web API parity project is no longer primarily blocked by missing billing endpoints.

The project is now blocked more by:
- stale generated API documentation
- missing contract hardening for session bootstrap
- missing parity for the next set of mobile-critical web surfaces

## Scope Of This Remaining Work Phase

### Primary scope
- preserve and document the billing/onboarding contracts already delivered
- freeze `GET /api/v1/auth/me` as the mobile session bootstrap contract
- expose the next mobile-critical web behaviors through explicit API contracts
- avoid forcing mobile to read web-only routes or reverse-engineer controller behavior

### Secondary scope
- decide which web-only settings surfaces are actually in mobile product scope
- avoid accidental scope creep by distinguishing true parity needs from admin-only web flows

### Out of scope for this phase
- full mobile navigation redesign
- super-admin back-office parity
- demo workspace parity
- full parity for every web settings page without a mobile product need
- replacing the billing domain contracts already delivered unless a real mismatch is found

## Current Gap Summary

| Surface | Current web status | Current API status | Remaining gap | Priority |
| --- | --- | --- | --- | --- |
| Billing and onboarding contracts | Delivered on web | Delivered on API | Documentation and contract hardening | P0 |
| OpenAPI and Postman | Present but shallow | Present but lagging | Generated docs do not reflect real mobile contracts | P0 |
| `GET /api/v1/auth/me` | Used as bootstrap | Exists and now documented | Bootstrap contract is now protected by dedicated API tests | Completed 2026-04-10 |
| Security settings and 2FA management | Web settings flow exists | API settings parity now exists | Mobile security contract and stateless authenticator setup are now protected by dedicated API tests | Completed 2026-04-10 |
| Global search | Web route exists and already returns JSON | API route now exists | Mobile grouped search contract is now protected by dedicated API tests | Completed 2026-04-10 |
| Pipeline timeline entry point | Web timeline wrapper exists | Canonical data API now frozen | Mobile should use the existing JSON pipeline route, not the Inertia wrapper | Completed 2026-04-10 |
| AI image generation | Web route exists and returns JSON | API route now exists | Mobile AI image generation contract is now protected by dedicated API tests | Completed 2026-04-10 |
| Loyalty settings | Web settings page exists | Loyalty state is partly embedded in billing payload | Defer a dedicated API route until mobile ships an owner loyalty settings screen that needs more than the billing contract | Deferred |
| HR settings | Web-only | No API route | Intentionally web-only for the current mobile scope | Web-only |
| API tokens | Web-only | No API route | Intentionally web-only for the current mobile scope | Web-only |

## Delivered Areas That Should Not Be Reopened Lightly
The following areas should now be considered stable unless the mobile app reveals a real mismatch:
- pricing display and promotions
- onboarding checkout completion flow
- billing summary normalization
- billing mutation action responses
- billing mutation domain error codes
- assistant credits checkout flow

Rule:
- do not reopen these areas because an older draft says they were missing
- only reopen them if tests fail, the mobile app contract is insufficient, or product requirements changed

## Detailed Remaining Work

### Gap 1 - Generated API documentation is no longer trustworthy enough
The current generated API artifacts are useful as route inventories, but not as reliable mobile contracts.

Problems observed:
- `docs/api/openapi.json` still uses shallow generic responses such as `200 Success` or `201 Success`
- the generated docs do not describe real request and response schemas
- several delivered mobile-relevant endpoints are missing from the generated artifacts or are not represented with enough fidelity to guide mobile implementation
- the generator currently reads routes but not domain-specific schema knowledge

Why this matters:
- mobile engineering still has to read controllers and tests to understand the real contract
- the repo appears more complete than the machine-readable docs suggest
- parity confidence is lower than it should be

Required outcome:
- OpenAPI and Postman must reflect the real mobile contract shape for the delivered pricing, onboarding, billing, and mutation flows

### Gap 2 - `GET /api/v1/auth/me` bootstrap contract
Status update on 2026-04-10:
- dedicated feature coverage now exists in `tests/Feature/AuthMeApiTest.php`
- generated OpenAPI and Postman artifacts now describe `GET /api/v1/auth/me` as the lean mobile bootstrap contract
- owner, team member, client portal user, platform admin, and unauthorized cases are now explicitly covered

The current parity docs intentionally kept `auth/me` lean, and that remains the correct product decision.

What it already provides:
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

What remains important:
- keep the contract lean as other mobile APIs evolve
- avoid leaking billing-summary concerns into `auth/me`
- extend tests only when the bootstrap contract intentionally changes

Why this matters:
- mobile startup and routing decisions depend on this payload
- if this contract drifts silently, the app can route users incorrectly even if billing endpoints remain correct

Delivered outcome:
- `GET /api/v1/auth/me` is now frozen as the session bootstrap contract for the current scope
- onboarding and feature visibility semantics are explicit in generated docs
- the contract is protected by dedicated tests

### Gap 3 - Security settings and 2FA management parity is now delivered
Status update on 2026-04-10:
- `GET /api/v1/settings/security` now exists as the normalized mobile security payload
- the API now exposes `app/start`, `app/confirm`, `app/cancel`, `email`, and `sms` mutations for 2FA settings
- dedicated feature coverage now exists in `tests/Feature/SecuritySettingsApiTest.php`
- authenticator app setup is now stateless on API through a temporary setup token, while the web session-based flow remains unchanged

What already exists:
- `POST /api/v1/auth/two-factor/verify`
- `POST /api/v1/auth/two-factor/resend`
- `GET /api/v1/settings/security`
- `POST /api/v1/settings/security/2fa/app/start`
- `POST /api/v1/settings/security/2fa/app/confirm`
- `POST /api/v1/settings/security/2fa/app/cancel`
- `POST /api/v1/settings/security/2fa/email`
- `POST /api/v1/settings/security/2fa/sms`

Important implementation note:
- the web authenticator setup flow still uses session state
- the API now has its own stateless pending-setup contract so mobile does not depend on browser session state

Delivered outcome:
- mobile can inspect security posture and recent security activity through API
- mobile can manage 2FA with explicit success and denial responses
- mobile can start and confirm authenticator setup without browser session state
- the security contract is frozen by dedicated API tests

### Gap 4 - Global search parity is now delivered
Status update on 2026-04-10:
- `GET /api/v1/global-search` now exists and reuses `GlobalSearchController`
- dedicated feature coverage now exists in `tests/Feature/GlobalSearchApiTest.php`
- the short-query behavior and the existing product and permission gating are now frozen for mobile

Why this mattered:
- mobile needed the same grouped search surface through the official API namespace
- the backend logic already existed, but the contract was not yet exposed as a mobile API

Delivered outcome:
- the same grouped search contract is now exposed through the API namespace
- queries shorter than two characters still return an empty group list
- owner and team-member permissions continue to control which groups appear
- the contract is now documented and protected by dedicated API tests

### Gap 5 - Pipeline parity is now frozen on the existing JSON route
Status update on 2026-04-10:
- `GET /api/v1/pipeline` is now explicitly frozen as the canonical mobile pipeline entry point
- dedicated feature coverage now exists in `tests/Feature/PipelineApiTest.php`
- generated OpenAPI and Postman artifacts now document the request, quote, job, task, and invoice source variants

What exists now:
- mobile uses `GET /api/v1/pipeline`
- the web route `/pipeline/timeline/{entityType}/{entityId}` remains a presentation wrapper for Inertia and is not the mobile contract
- the owner-only access rule is now explicit and protected by tests

Delivered outcome:
- mobile has one canonical JSON route for pipeline and timeline data
- the top-level payload shape is frozen for complete and incomplete pipeline states
- the contract is protected for all supported source types: `request`, `quote`, `job`, `task`, and `invoice`

### Gap 6 - AI image generation parity is now delivered
Status update on 2026-04-10:
- `POST /api/v1/ai/images` now exists as the mobile AI image generation entry point
- dedicated feature coverage now exists in `tests/Feature/AiImageApiTest.php`
- generated OpenAPI and Postman artifacts now describe the request shape, `free` vs `credit` modes, and the main failure responses

What exists now:
- mobile can call the same `AiImageController` workflow through the API namespace
- the route keeps the existing per-account `ai-images` throttling
- the route preserves free daily usage, owner-level credit consumption, refund on failure, and stored image URL return

Important implementation note:
- credit balance is now read back from the current database state after consumption or refund
- this fixes a stale owner-balance issue that could otherwise affect employee-triggered image generation payloads

Delivered outcome:
- mobile can generate store and product images through the official API namespace
- the response shape is stable across `free` and `credit` modes
- `429` exhaustion and provider failure/refund paths are explicitly protected by tests

### Gap 7 - Scope decisions are still needed for some settings surfaces
Not every web-only surface should automatically become an API parity task.

Status update on 2026-04-10:
- the remaining settings surfaces now have explicit scope decisions
- no new settings API route will be added for this phase without a new product requirement

Scope decisions:
- `settings/loyalty`: deferred
  The normalized loyalty configuration already rides inside `GET/PUT /api/v1/settings/billing`, and loyalty runtime data already exists in other backend surfaces such as customer detail, sales checkout, and portal loyalty. A dedicated owner settings route should only be added if mobile ships a first-class loyalty settings screen that needs a narrower or richer contract than billing already provides.
- `settings/hr`: intentionally web-only
  The current HR surface is an owner-admin shift-template editor. It is configuration-heavy, back-office oriented, and not required by the current mobile runtime flows.
- `settings/api-tokens`: intentionally web-only
  API token creation is a sensitive developer-admin workflow with one-time secret reveal and explicit revocation. There is no current mobile product need strong enough to justify expanding this surface to mobile.

Delivered outcome:
- the remaining scope questions are now resolved for the current parity phase
- future API work on loyalty, HR, or API tokens now requires an explicit mobile product request instead of being implied by the web route inventory

## User Stories

### US-MOBILE-PARITY-REM-001 - Restore trustworthy API documentation
As a mobile engineer,
I want generated API documentation to reflect the real delivered backend contracts,
so that I can implement features without reading controller internals.

Acceptance criteria:
- `docs/api/openapi.json` includes the real delivered mobile-relevant pricing, onboarding, billing, and mutation routes
- the docs describe intentional request and response sections instead of only generic success responses
- `docs/api/postman_collection.json` includes the same route inventory
- the docs are regenerated from a workflow that can evolve with the real contract

### US-MOBILE-PARITY-REM-002 - Freeze the session bootstrap contract
As a mobile app,
I want `GET /api/v1/auth/me` to be a stable, documented bootstrap contract,
so that session restore and routing remain reliable.

Acceptance criteria:
- the contract documents all expected `meta` sections and their meaning
- dedicated feature tests cover at least owner, team member, and client-relevant interpretations where applicable
- the contract clearly states what must stay out of `auth/me`

### US-MOBILE-PARITY-REM-003 - Add security settings parity
As an authenticated mobile account owner,
I want to inspect and manage security and 2FA settings through API,
so that I can complete the same security tasks as on web.

Acceptance criteria:
- mobile can read a normalized security settings payload
- mobile can start and complete authenticator app setup without depending on browser session state
- mobile can switch between supported 2FA methods with clear success and denial responses
- recent security activity is available in a stable shape

Status:
- Delivered on 2026-04-10

### US-MOBILE-PARITY-REM-004 - Add global search parity
As a mobile user,
I want to query the same global search logic as the web,
so that I can find customers, tasks, quotes, and employees consistently.

Acceptance criteria:
- a mobile API route exposes grouped search results
- the short-query behavior remains consistent
- product feature gating and permission checks match the current web behavior

Status:
- Delivered on 2026-04-10

### US-MOBILE-PARITY-REM-005 - Freeze the mobile pipeline contract
As a mobile user,
I want one clear API contract for pipeline and timeline data,
so that the app does not depend on an Inertia-only web wrapper.

Acceptance criteria:
- the canonical pipeline API route is explicitly chosen
- the payload for request, quote, job, task, and invoice sources is documented
- the contract is covered by tests for the main entity variants

Status:
- Delivered on 2026-04-10

### US-MOBILE-PARITY-REM-006 - Add AI image generation parity
As a mobile user with assistant access,
I want to generate AI images through the API,
so that the mobile app can offer the same workflow as the web.

Acceptance criteria:
- the AI image workflow is reachable through the API namespace
- the contract documents free usage, paid credit usage, and hard failure cases
- the response includes the generated image URL and remaining usage metadata

Status:
- Delivered on 2026-04-10

### US-MOBILE-PARITY-REM-007 - Freeze scope for secondary settings surfaces
As the backend and product team,
I want explicit decisions for loyalty, HR, and API-token settings parity,
so that we do not add accidental scope without product justification.

Acceptance criteria:
- each surface is explicitly categorized as required now, deferred, or intentionally web-only
- the decision is recorded in this document before implementation starts

Status:
- Delivered on 2026-04-10

## Frozen Decisions For The Next Phase
- Billing, pricing, onboarding, and assistant credit parity are considered delivered unless a concrete mobile mismatch is discovered.
- `auth/me` stays lean and should not absorb the full billing payload.
- New API routes should only be added for real mobile product needs, not just because the web has a route.
- If an existing controller already returns good JSON, prefer stabilizing and exposing it rather than rewriting the business logic.
- Generated docs are not sufficient proof of parity until they are upgraded to reflect real schemas.

## Recommended Execution Order
1. Keep the source-of-truth documentation and generated API artifacts aligned with delivered pricing, auth/me, security, global search, pipeline, and AI image parity.
2. Reopen a new parity story only if mobile product scope expands beyond the current delivered contracts.

## QA Strategy
- prefer feature tests that validate mobile-facing JSON contracts directly
- treat generated docs as artifacts, not as proof of correctness
- for every newly exposed mobile endpoint, test both happy path and product-rule denial path
- for every stabilized contract, ensure at least one test protects the top-level payload shape

## Definition Of Done For This Remaining Work Phase
This phase is complete when:
- the delivered billing/onboarding parity contracts are documented and trusted
- `auth/me` is a tested and frozen bootstrap contract
- the next mobile-critical web-only surfaces chosen for scope have explicit API contracts
- generated API docs no longer lag behind the contracts mobile actually consumes
- this document can be used as the authoritative backlog for continuing mobile/web parity work

Status note:
- For the current scope, this definition is now satisfied.
