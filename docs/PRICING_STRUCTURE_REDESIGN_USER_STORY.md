# Pricing Structure Redesign - User Story

## Status
Draft started on 2026-04-10.

Phase 1 finalized on 2026-04-10.
Phase 2 completed on 2026-04-10.
Phase 3 completed on 2026-04-10.
Phase 4 completed on 2026-04-10.
Phase 5 completed on 2026-04-10.

Current architecture decisions are now frozen for implementation.

This document is the working source of truth for the next pricing architecture of the platform.

It supersedes `docs/SOLO_PRICING_USER_STORY.md` for:
- plan hierarchy
- public naming
- feature matrix
- limit progression
- Solo vs Team positioning
- removal of the free plan from the active commercial catalog

It does not replace:
- `docs/MULTI_CURRENCY_BILLING_USER_STORY.md`
- `docs/MULTI_CURRENCY_BILLING_IMPLEMENTATION_NOTE.md`

Those documents remain relevant for currency handling and billing mechanics.

## Why This Document Exists
The current plan structure mixes several problems:
- there is still a `free` plan in the billing architecture even though the business no longer wants a free offer
- Solo and Team plans do not feel aligned enough at equivalent maturity levels
- the public catalog is harder to understand than it should be
- some plan differences feel arbitrary instead of clearly tied to collaboration, administration, and usage scale
- the upsell logic is not as clean or premium as modern SaaS pricing should be

We need a pricing model that is:
- clearer
- easier to explain
- easier to implement
- more trustworthy for buyers
- more coherent between marketing, onboarding, billing, and entitlements

## Product Goals
- remove the free plan from the active pricing strategy
- organize plans on 2 clear axes: `audience` and `level`
- align equivalent Solo and Team tiers around almost the same core product value
- make Team value come primarily from collaboration, administration, permissions, and higher limits
- reduce frustrating feature cliffs on core business workflows
- create a premium but realistic upgrade path from `Core` to `Growth` to `Scale`
- keep the technical implementation feasible in the current codebase

## Non-Goals
- redesign the full Stripe architecture in this document
- redesign every billing screen in detail
- define final exact public copy for every pricing card
- introduce a new free trial plan
- create large product gaps between equivalent Solo and Team tiers

## Source Of Truth Precedence
When there is a mismatch, use this precedence:

1. this document for target pricing architecture
2. approved implementation in `config/billing.php`
3. plan entitlement sync defaults written to `plan_modules` and `plan_limits`
4. public pricing and onboarding behavior
5. older pricing docs

Important rule:
- do not reintroduce a free plan in public catalogs, onboarding selection, or new sales flows unless a new pricing decision explicitly reopens that choice

## Phase 1 Decisions Frozen
The following decisions are now approved for implementation:
- the active commercial architecture is `Solo` vs `Team`, each with `Core`, `Growth`, and `Scale`
- the public catalog does not include a free plan
- equivalent Solo and Team tiers should share nearly the same core modules
- Team plan differentiation should come mainly from collaboration, permissions, admin depth, and higher limits
- `Solo Growth` is the recommended Solo plan
- `Team Growth` is the recommended Team plan
- existing technical plan keys remain stable during the first implementation pass
- the first code migration target is display names, plan ordering, public comparison, onboarding alignment, modules, and limits

## Target Pricing Architecture

### Pricing axes
The pricing model is defined by 2 axes:

1. `Audience`
   - `Solo`
   - `Team`
2. `Level`
   - `Core`
   - `Growth`
   - `Scale`

### Final catalog
| Audience | Entry | Mid | Premium |
| --- | --- | --- | --- |
| Solo | Solo Core | Solo Growth | Solo Scale |
| Team | Team Core | Team Growth | Team Scale |

Additional tier:
- `Enterprise` above `Team Scale`

### Bundle logic
- `Solo Core` = Core bundle
- `Team Core` = Core bundle + Team layer
- `Solo Growth` = Core bundle + Growth layer
- `Team Growth` = Core bundle + Growth layer + Team layer
- `Solo Scale` = Core bundle + Growth layer + Scale layer
- `Team Scale` = Core bundle + Growth layer + Scale layer + Team layer
- `Enterprise` = Team Scale + custom governance, integrations, support, and commercial terms

## Technical Mapping To Existing Plan Keys
To keep implementation realistic, the existing technical plan keys remain the first migration target.

| Existing key | New public name | Audience | Level | Notes |
| --- | --- | --- | --- | --- |
| `solo_essential` | Solo Core | Solo | Core | keep owner-only behavior |
| `starter` | Team Core | Team | Core | replace old Starter naming in public surfaces |
| `solo_pro` | Solo Growth | Solo | Growth | recommended Solo plan |
| `growth` | Team Growth | Team | Growth | recommended Team plan |
| `solo_growth` | Solo Scale | Solo | Scale | premium Solo tier |
| `scale` | Team Scale | Team | Scale | premium Team tier |
| `enterprise` | Enterprise | Team | Enterprise | contact-only |
| `free` | deprecated | legacy only | none | remove from public and onboarding flows |

Important implementation note:
- phase 1 should prefer keeping the technical keys above
- the main changes should first happen through display names, plan ordering, comparison tables, modules, limits, and onboarding/public catalog behavior

## Core Packaging Principle
Equivalent Solo and Team tiers should share nearly the same core modules.

This means:
- `Solo Core` and `Team Core` must feel like the same product foundation
- `Solo Growth` and `Team Growth` must feel like the same business maturity step
- `Solo Scale` and `Team Scale` must feel like the same premium maturity step

The main differences for Team plans should be:
- team member access
- collaboration workflows
- shared assignment and coordination
- presence and workforce visibility
- roles and permissions
- admin controls
- higher quotas and limits

The main differences should not be:
- basic selling workflows
- invoicing
- requests
- quotes
- jobs
- tasks
- planning
- core catalog management
- client portal fundamentals

## Target Product Layers

### Core bundle
Available across all `Core`, `Growth`, and `Scale` plans:
- CRM / requests
- quotes
- invoices and payments
- products catalog
- services catalog
- jobs / work orders
- tasks
- planning / calendar
- sales / POS
- client portal and public pages
- limited AI assistant usage
- limited plan scan / AI quote assistance
- reservations for sectors where reservations are part of the default product experience

### Growth layer
Added at `Growth` and above:
- advanced dashboard depth
- higher AI assistant usage
- higher plan scan / AI quote assistance volume
- campaigns
- loyalty

### Scale layer
Added at `Scale` and above:
- higher AI and plan scan usage
- deeper reporting and management value
- premium support and onboarding posture
- broader operational headroom

### Team layer
Added on Team plans only:
- team members
- shared assignment / collaboration
- presence / time tracking
- roles and permissions
- admin controls and approvals

## Target Feature Matrix
| Module / capability | Solo Core | Team Core | Solo Growth | Team Growth | Solo Scale | Team Scale | Enterprise |
| --- | --- | --- | --- | --- | --- | --- | --- |
| CRM / Requests | Included | Included | Included | Included | Included | Included | Included |
| Quotes | Included | Included | Included | Included | Included | Included | Included |
| Invoices + payments | Included | Included | Included | Included | Included | Included | Included |
| Products catalog | Included | Included | Included | Included | Included | Included | Included |
| Services catalog | Included | Included | Included | Included | Included | Included | Included |
| Jobs / work orders | Included | Included | Included | Included | Included | Included | Included |
| Tasks | Included | Included | Included | Included | Included | Included | Included |
| Planning / calendar | Included | Included | Included | Included | Included | Included | Included |
| Reservations / check-in | Included if relevant | Included if relevant | Included if relevant | Included if relevant | Included if relevant | Included if relevant | Included |
| Sales / POS | Included | Included | Included | Included | Included | Included | Included |
| Client portal + public pages | Included | Included | Included | Included | Included | Included | Included |
| Performance dashboard | Basic | Basic | Advanced | Advanced | Advanced+ | Advanced+ | Custom |
| AI assistant | Limited | Limited | Included | Included | Included+ | Included+ | Custom |
| Plan scan / AI quote assist | Limited | Limited | Included | Included | Included+ | Included+ | Custom |
| Campaigns | No | No | Included | Included | Included | Included | Included |
| Loyalty | No | No | Included | Included | Included | Included | Included |
| Team members | No | Included | No | Included | No | Included | Included |
| Presence / time tracking | No | Included | No | Included | No | Included | Included |
| Roles & permissions | Owner only | Basic | Owner only | Advanced | Owner only | Advanced+ | Custom |
| Shared assignment / collaboration | No | Basic | No | Advanced | No | Advanced+ | Custom |
| Admin controls / approvals | No | Basic | No | Advanced | No | Advanced+ | Custom |
| Priority onboarding / support | Standard | Standard | Priority | Priority | Premium | Premium | Dedicated |

## Limits Policy
The pricing model should avoid hostile gating on core selling and operating workflows.

### Strong rule
Keep these limits `unlimited` on all paid plans unless a later business decision explicitly changes them:
- requests
- quotes
- invoices
- products
- services

### Main cost and complexity limits
Use limits primarily on:
- team members
- active jobs
- active tasks
- plan scan quotes per month
- AI assistant requests per month

### Target limit progression
| Limit | Solo Core | Team Core | Solo Growth | Team Growth | Solo Scale | Team Scale | Enterprise |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Team members | - | 5 | - | 15 | - | 50 | Custom |
| Active jobs | 300 | 1000 | 1500 | 5000 | 5000 | 20000 | Custom |
| Active tasks | 1000 | 3000 | 5000 | 15000 | 20000 | 75000 | Custom |
| Plan scan quotes / month | 10 | 25 | 150 | 400 | 500 | 1500 | Custom |
| AI assistant requests / month | 150 | 500 | 800 | 2500 | 3000 | 10000 | Custom |
| Included admin complexity | - | Basic | - | Advanced | - | Advanced+ | Custom |

### Limit scaling rules
- `Core -> Growth` should usually feel like a 3x to 5x jump on advanced usage
- `Growth -> Scale` should usually feel like a 3x to 4x jump
- `Solo -> Team` at the same level should feel like the same product maturity with collaboration and higher operational headroom

## Commercial Positioning

### Solo value
Solo plans should be sold on:
- speed
- simplicity
- professionalization
- more output with less admin load
- the ability to scale alone before hiring

### Team value
Team plans should be sold on:
- collaboration
- delegation
- role clarity
- team coordination
- admin control
- shared visibility
- larger operating capacity

### Recommended plan positioning
- `Solo Growth` should be the recommended Solo plan
- `Team Growth` should be the recommended Team plan
- `Scale` tiers should feel premium and aspirational, not punitive
- `Enterprise` should be reserved for governance, custom support, integrations, and special commercial needs, not for basic product access

## Anti-Frustration Rules
- do not hide or aggressively gate core workflows that define the platform value
- do not make equivalent Solo and Team tiers feel like different products
- do not require a buyer to jump to a Team plan only to unlock normal day-to-day operational basics
- do not use `Enterprise` as a trap for ordinary admin needs
- warn before hard-blocking on usage limits
- add soft warning thresholds around 80 percent and 90 percent where possible
- add a small grace buffer or a clear upgrade path before operational lockout on expensive limits
- prefer add-ons for expensive overages such as AI or plan scan usage instead of large feature cliffs

## Main User Stories

### US-PRICING-1 - Clear catalog architecture
As a prospect, I can understand the pricing in less than 10 seconds because the catalog is organized by `Solo` vs `Team` and then by `Core`, `Growth`, `Scale`.

Acceptance criteria:
- the public catalog clearly exposes a Solo view and a Team view
- each view presents `Core`, `Growth`, and `Scale` in order
- `Enterprise` is visually separate from the 3 main tiers
- the old `free` plan is not shown in public pricing or onboarding

### US-PRICING-2 - Equivalent Solo and Team tiers feel aligned
As a buyer, I can compare Solo and Team tiers at the same maturity level without feeling that one is artificially crippled.

Acceptance criteria:
- `Solo Core` and `Team Core` share the same core operational modules
- `Solo Growth` and `Team Growth` share the same growth modules
- `Solo Scale` and `Team Scale` share the same premium growth stack
- Team plans mainly add collaboration, administration, permissions, and higher limits

### US-PRICING-3 - Core plans remain truly buyable
As a new customer, I can buy the entry plan without feeling that the product is intentionally incomplete.

Acceptance criteria:
- all paid plans include the core operational value of the platform
- requests, quotes, invoices, products, and services are not harshly limited on paid plans
- entry plans can support real business usage

### US-PRICING-4 - Upgrade logic is obvious
As a growing customer, I can easily understand why I would move from `Core` to `Growth` or from `Growth` to `Scale`.

Acceptance criteria:
- `Growth` clearly represents AI, automation, campaigns, loyalty, and more business leverage
- `Scale` clearly represents premium headroom, deeper control, and higher operational limits
- the recommended plan in each audience is `Growth`

### US-PRICING-5 - Implementation remains realistic
As a platform admin and developer, I can implement the new pricing structure without rewriting the whole billing stack at once.

Acceptance criteria:
- existing technical plan keys remain valid during the first implementation pass
- display names can change independently from plan keys
- entitlements can be synced from `config/billing.php`
- onboarding, billing, and public pricing can consume the same target structure

### US-PRICING-6 - No new free-plan drift
As a product owner, I can prevent the free plan from creeping back into the active offer by accident.

Acceptance criteria:
- `free` is removed from active public catalogs
- `free` is removed from onboarding plan selection for new customers
- no new subscription provisioning flow treats `free` as the default commercial plan
- if legacy tenants still rely on `free`, they are explicitly treated as a migration case, not as an active offer

## Implementation Scope

### In scope
- public plan naming
- plan hierarchy
- plan ordering
- public catalog presentation
- comparison matrix alignment
- plan modules alignment
- plan limits alignment
- onboarding plan selection alignment
- removal of the free plan from active acquisition flows

### Out of scope for this story
- final price-point optimization by market experiments
- full Stripe subscription migration playbook
- complete entitlement redesign outside the defined module list
- detailed sales copy writing for every plan card

## Implementation Phases

### Phase 1 - Freeze pricing architecture
Status: Completed on 2026-04-10

- approve the final Solo vs Team and Core/Growth/Scale structure
- freeze the public names mapped to existing plan keys
- freeze the rules for no free plan, Team differentiation, and anti-frustration gating

### Phase 2 - Update billing configuration and public catalog
Status: Completed on 2026-04-10

- update `config/billing.php` display names and feature summaries
- update `public_catalogs` ordering and highlighted plans
- remove `free` from active public ordering and onboarding selection
- align comparison tables with the new architecture

### Phase 3 - Align entitlements
Status: Completed on 2026-04-10

- update default modules for each plan
- update default limits for each plan
- sync plan entitlements through `php artisan billing:sync-plan-entitlements`
- validate that equivalent Solo and Team tiers share the intended product core

### Phase 4 - Align onboarding and billing UX
Status: Completed on 2026-04-10

- ensure onboarding presents the new plan names and ordering
- ensure selected plan context resolves the intended entitlements after onboarding
- ensure billing settings and pricing surfaces use the same naming and audience logic

### Phase 5 - Legacy migration and cleanup
Status: Completed on 2026-04-10

- keep `free` as an explicit `legacy_only` grandfathered plan for older workspaces only
- hide deprecated legacy plans from active billing upgrade catalogs unless the tenant is currently on that legacy plan
- centralize legacy fallback resolution through billing services instead of relying on implicit hard-coded `free` assumptions
- replace public `start free` wording with trial/onboarding or contact wording that matches the actual destination
- update remaining pricing copy fragments that still referenced `Solo Essential`, `Solo Pro`, `Starter`, or `Growth` as public labels

## Implementation Notes For The Current Codebase
The first expected touchpoints are:
- `config/billing.php`
- public pricing comparison data
- onboarding plan selection and plan display surfaces
- billing settings plan display surfaces
- plan entitlement sync service and related tests

Implementation preference:
- keep technical keys stable first
- change display names and mappings first
- then update modules and limits
- then migrate legacy tenant edge cases

## Open Decisions
- whether the exact recommended limit numbers should be adopted as-is or adjusted after reviewing current tenant usage data
- whether `Enterprise` should remain visible in the Team catalog grid or be rendered as a separate contact card
- whether some advanced support promises should be represented in code or remain marketing-only copy
- whether AI and plan scan overages should ship as add-ons in the same phase or later

## Definition Of Done
This pricing redesign is considered complete when:
- the active pricing model is clearly `Solo` vs `Team`, each with `Core`, `Growth`, and `Scale`
- `free` is no longer part of the active acquisition architecture
- equivalent Solo and Team tiers share nearly the same core modules
- Team differentiation is primarily collaboration, admin depth, and higher limits
- plan modules and limits match the structure defined here
- the public pricing page, onboarding, and billing surfaces present the same plan logic
- the implementation is stable enough to be reused across environments through configuration and sync commands
- legacy `free` handling is explicit and isolated as a migration concern rather than an active offer
