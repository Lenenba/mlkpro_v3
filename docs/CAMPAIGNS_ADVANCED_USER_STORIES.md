# Campaigns Advanced Optimization - User Stories Backlog

Derniere mise a jour: 2026-03-05

## Goal
Construire la V2 avancee du module Campaigns pour augmenter la performance business, la qualite d envoi et la gouvernance des campagnes, sans casser les patterns existants de Malikia Pro.

## Scope
- A/B testing natif avec gagnant auto
- holdout group et mesure uplift
- send-time optimization par fuseau + historique client
- fallback canal intelligent
- centre de preferences client (canal + themes)
- template quality guard (spam/liens/tokens)
- attribution ROI avancee (revenu, cout SMS, marge)
- segment insights (overlap, drift, projection)
- workflow d approbation avant envoi massif
- UX/perf homogenes (DataTables + skeleton + composants floating)

## Principles
- Reuse strict des entites/services/jobs/policies/UI existants
- tenant isolation stricte
- backward compatible
- petites methodes testables
- performance: datasets 10k+ clients / 1k+ offres

## Current Baseline (already in code)
- A/B split de base (variant A/B sujet titre corps)
- holdout group applique au dispatch
- fallback canal apres echec provider (avec re-check consent/fatigue)
- consent + anti-fatigue existants
- dashboard marketing KPI de base (sent/delivery/click/conversion/top campaign)
- wizard avec floating inputs + skeletons de base

## Gaps to deliver
- A/B gagnant auto apres X envois + switch auto
- KPI uplift reel via holdout
- send-time optimization par profil client
- preference center self-service pour client final
- quality guard avance (spam score + broken links)
- ROI detaille (revenu attribue, cout SMS, marge)
- insights segments (overlap, drift, projected size)
- workflow draft -> review -> approved
- migration de toutes les tables campagnes vers composants DataTable standard

## Epics and User Stories

### Epic A - Experimentation and uplift

#### US-CMP-ADV-001 - A/B winner auto
As a marketing manager, I want a winner to be selected automatically after X sends so the campaign optimizes itself.

Acceptance criteria:
- campaign channel supports `ab_testing.auto_winner.enabled`
- supports `evaluation_after_sends` and `primary_metric` (open/click/conversion)
- once threshold reached, winner variant is locked for remaining recipients
- decision is stored in run summary and audit log
- manual override remains possible for authorized users

#### US-CMP-ADV-002 - A/B on CTA
As a marketing manager, I want CTA A/B testing to compare links/actions.

Acceptance criteria:
- each variant supports distinct CTA URL and CTA label
- rendered payload includes the selected variant CTA
- tracking keeps variant attribution for clicks and conversions

#### US-CMP-ADV-003 - Holdout uplift KPI
As an owner, I want to compare contacted vs holdout outcomes to measure real incremental value.

Acceptance criteria:
- holdout population stored per run snapshot
- dashboard exposes uplift by metric (conversion rate delta, revenue delta)
- uplift only shown when holdout sample size is statistically meaningful

### Epic B - Delivery intelligence

#### US-CMP-ADV-004 - Send-time optimization
As a marketer, I want sends scheduled at the best local time per customer.

Acceptance criteria:
- optimizer considers tenant timezone fallback + customer timezone if known
- optimizer uses historical open/click windows when available
- if no history, applies tenant default best-hour rules
- queued jobs receive resolved per-recipient `send_at`

#### US-CMP-ADV-005 - Fallback policy editor
As an admin, I want to configure fallback order per channel.

Acceptance criteria:
- settings page supports fallback map (email->sms->in_app, sms->in_app, etc.)
- max depth and loop protection configurable
- fallback respects consent/fatigue and destination validity

### Epic C - Customer communication preferences

#### US-CMP-ADV-006 - Preference center
As a customer, I want to choose which channels/themes I accept.

Acceptance criteria:
- public secure page via tokenized link
- per-channel opt-in/out and per-theme preferences (promo/news/product/service)
- preferences write to tenant-scoped consent/preferences data
- campaign audience resolver excludes non-eligible recipients accordingly

#### US-CMP-ADV-007 - Preferences in customer profile
As support staff, I want to view/edit customer communication preferences from the customer profile.

Acceptance criteria:
- customer profile shows channel status + theme matrix + last update source
- role/permission checks enforced
- changes are auditable

### Epic D - Template quality guard

#### US-CMP-ADV-008 - Pre-send quality checks
As a marketer, I want quality warnings before sending.

Acceptance criteria:
- check tokens missing/invalid
- check broken links (HTTP validation with timeout/retry limits)
- basic spam heuristics score for email body/subject
- send blocked only on hard errors; warnings remain overridable by permission

### Epic E - ROI attribution

#### US-CMP-ADV-009 - Revenue and margin attribution
As an owner, I want campaign ROI metrics to decide budget allocation.

Acceptance criteria:
- dashboard and campaign results include:
  - attributed revenue
  - estimated SMS cost
  - gross margin attributed
  - ROI ratio
- attribution window configurable in marketing settings
- mapping reuses existing conversions entities (reservation/invoice/quote/purchase)

### Epic F - Segment intelligence

#### US-CMP-ADV-010 - Segment overlap
As a marketer, I want overlap visibility to avoid over-targeting the same audience.

Acceptance criteria:
- segment manager can compare 2+ segments
- returns overlap count and overlap %
- supports include/exclude logic awareness

#### US-CMP-ADV-011 - Segment drift and projection
As a marketer, I want to know how a segment evolves before launching.

Acceptance criteria:
- drift indicator compares current vs previous cached membership
- projected size shown for scheduled send date (based on trend and exclusions)
- warnings shown when projected size is too low/high vs historical

### Epic G - Governance and approval

#### US-CMP-ADV-012 - Approval workflow
As a compliance lead, I want mandatory review before mass send.

Acceptance criteria:
- campaign status flow supports `draft -> review -> approved -> scheduled/running`
- only approvers can approve/reject
- dispatch blocked when status is not approved (except test send)
- review decision logged with reason and timestamp

### Epic H - UX and performance consistency

#### US-CMP-ADV-013 - DataTable standardization
As a user, I want long campaign tables to behave like clients/products tables.

Acceptance criteria:
- campaign index/results/managers use shared DataTable pattern (pagination, sorting, filters)
- no infinitely long page tables
- consistent bulk actions and export hooks

#### US-CMP-ADV-014 - Unified loading/inputs
As a user, I want consistent form and loading experience across campaigns pages.

Acceptance criteria:
- floating components reused everywhere (inputs/selects/textarea/number/date)
- skeleton loaders shown for list and detail async states
- iconography follows existing UI kit

## Delivery Plan (phased)

### Phase 1 - High impact / low risk
- US-CMP-ADV-001
- US-CMP-ADV-003
- US-CMP-ADV-008
- US-CMP-ADV-013

### Phase 2 - Revenue and intelligence
- US-CMP-ADV-004
- US-CMP-ADV-009
- US-CMP-ADV-010
- US-CMP-ADV-011

### Phase 3 - Governance and customer control
- US-CMP-ADV-006
- US-CMP-ADV-007
- US-CMP-ADV-012
- US-CMP-ADV-014

## Technical Notes
- Reuse existing: `AudienceResolver`, `ConsentService`, `FatigueLimiter`, campaign jobs, dashboard infra, audit logs.
- New logic must remain idempotent for queue retries.
- Add feature flags where rollout risk exists (auto-winner, optimizer, approval gating).
- Keep API payloads backward compatible for existing wizard pages.

## Test Strategy (minimum)
- unit: auto winner selection logic, send-time scoring, ROI calculator, overlap/drift math
- feature: approval gating, preference center updates, quality guard hard/soft failures
- integration: end-to-end dispatch with fallback + consent + fatigue + approvals
- performance: segment compare and dashboard KPI queries under large tenant datasets

## Done Definition
- all acceptance criteria validated
- docs admin updated
- tests green (unit + feature + critical integration)
- no regression on existing campaign create/send flow
- UI aligned with platform design system

