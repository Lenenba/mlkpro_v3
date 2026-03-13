# AI Campaign Assistant - User Story and Delivery Phases

## Goal
Improve the existing AI assistant so it can use the existing Campaigns module as a true marketing copilot.

The assistant must not create a parallel campaigns system and must not replace the existing wizard. It must prepare a high-quality, editable campaign draft inside the current module from very little user input.

Target experience:
- minimum questions
- maximum deduction
- explicit assumptions
- fast draft generation
- editable result in the current campaign flow

## Product Vision
As a tenant owner or marketing manager, I can describe my intent in one short sentence such as:
- "I want to relaunch old clients"
- "I want to promote my new service"
- "I want a campaign for this weekend"
- "I want more bookings this month"

and the assistant prepares an almost complete campaign draft in the existing Campaigns module by:
- understanding the marketing objective
- choosing the most relevant campaign type
- selecting or suggesting the most relevant offer(s)
- choosing the best audience based on tenant data
- recommending the right channels
- generating a primary message and channel variants
- proposing a realistic schedule
- setting draft KPIs
- showing clearly what was deduced, proposed, and still needs confirmation

## Non-goals
- rebuild the Campaigns module
- build a new standalone assistant UI
- bypass current consent, fatigue, quiet hours, or tenant permissions
- auto-send campaigns without clear user validation
- redesign existing campaign tables or wizard structure in phase 1

## Current Baseline In This Repo
- The assistant already exists but only supports operational workflow intents, not campaign orchestration.
- The assistant frontend already sends context and supports action-based redirects.
- The Campaigns module already supports:
  - campaign types
  - channels
  - offers
  - segments
  - mailing lists
  - VIP tiers
  - templates
  - audience estimation
  - live preview
  - test send
  - send now / schedule
  - holdout and channel fallback
- The marketing settings and KPI layer already exist and can be reused as assistant context.

## Core Product Principles
- Reuse the existing domain model, services, policies, routes, and wizard.
- Ask the fewest possible questions.
- Ask only when the missing information would create an incoherent, invalid, or unusable campaign.
- Prefer deterministic backend resolution over freeform AI output.
- Keep every AI deduction explainable to the user.
- Persist assistant assumptions in campaign metadata for auditability.
- Keep the final campaign editable in the existing wizard.

## Primary User Story

### US-AICA-001 - One-shot campaign draft creation
As a tenant owner or marketing manager, I can describe a campaign goal in natural language and the assistant prepares an almost complete draft in the existing Campaigns module so I can review, adjust, and launch it quickly.

Acceptance criteria:
- The assistant recognizes campaign-related intent from free text.
- The assistant can create a real draft campaign using the existing backend flow.
- The assistant fills as many campaign fields as possible automatically.
- The assistant explains:
  - what was deduced
  - what was proposed
  - what still needs confirmation
- The draft opens in the existing campaign edit flow.
- The assistant never bypasses tenant permissions, consent, anti-fatigue, quiet hours, or existing validations.

## Supporting User Stories

### US-AICA-002 - Context-aware campaign type selection
As a marketer, I want the assistant to choose the right campaign type from my request so I do not need to know internal marketing categories.

Acceptance criteria:
- Requests like reactivation, launch, promotion, cross-sell, announcement, stock return map to the existing `campaign_type` values.
- If multiple interpretations are plausible, the assistant chooses the most likely one and exposes alternatives without blocking progress.

### US-AICA-003 - Intelligent audience preparation
As a marketer, I want the assistant to prepare the best available audience automatically so I do not need to manually rebuild targeting every time.

Acceptance criteria:
- The assistant first tries to reuse an existing segment when it matches the intent.
- If no strong existing segment exists, it builds `smart_filters` compatible with the current audience resolver.
- The assistant can combine segment logic, mailing lists, manual IDs, and exclusions using the existing source logic model.
- The assistant runs audience estimation before presenting a "ready" draft.

### US-AICA-004 - Channel and message recommendation
As a marketer, I want the assistant to recommend channels and messages based on available data and tenant settings so the campaign is usable immediately.

Acceptance criteria:
- Channel choices respect tenant-enabled channels and consent/compliance defaults.
- The assistant reuses default templates when possible.
- The assistant can generate channel-specific overrides while staying compatible with the current template renderer.
- The assistant validates previews and detects invalid tokens or oversized SMS content before marking the draft as ready.

### US-AICA-005 - Minimal clarification strategy
As a user, I want the assistant to avoid unnecessary back-and-forth so I can move fast.

Acceptance criteria:
- The assistant asks questions only when one of these is true:
  - no valid campaign can be created
  - no valid audience can be resolved
  - no enabled compliant channel is usable
  - the offer choice is materially ambiguous
  - the requested timing is invalid or contradictory
- If information is missing but non-blocking, the assistant makes a reasonable assumption, labels it, and continues.

### US-AICA-006 - Premium draft presentation
As a user, I want a fast and clear campaign draft summary so I understand the result immediately and can edit it with confidence.

Acceptance criteria:
- The assistant response includes a compact draft summary.
- The summary shows:
  - objective
  - campaign type
  - campaign name
  - offers
  - audience
  - channels
  - schedule
  - KPI focus
  - assumptions
  - confirmation needs
- The user can jump directly to the campaign edit page.

### US-AICA-007 - Continuous improvement from tenant history
As a marketing team, I want the assistant to improve its recommendations using tenant context and past performance so each new draft is smarter than a generic AI suggestion.

Acceptance criteria:
- The assistant can read recent campaigns and top-performing campaigns.
- The assistant can use tenant communication preferences and marketing settings.
- The assistant can prefer channels or structures that performed better for similar past campaigns when data exists.
- If performance history is missing, the assistant falls back to safe heuristics without blocking the draft.

## Required Context Inputs
The assistant orchestration layer should read these inputs before drafting:

### Tenant and business profile
- company type: products / services / hybrid
- tenant locale and language defaults
- enabled channels
- allowed offer modes
- quiet hours
- consent defaults
- anti-fatigue rules

### Marketing assets
- existing templates by channel, campaign type, and language
- reusable audience segments
- mailing lists
- VIP tiers
- marketing tracking settings

### Commercial context
- active offers and recent offers
- best-selling offers if available
- service vs product catalog structure
- tags and categories relevant to campaign targeting

### Customer and audience context
- VIP customers
- inactive / recent / loyal customers
- customers with email / phone / app account
- existing segment counts
- recent behavior and interest signals when available

### Historical performance context
- recent campaigns
- top-performing campaign
- delivery / click / conversion patterns
- reservation, invoice, and quote outcomes linked to campaigns

## Auto-fill Logic

### Fields the assistant should fill automatically
- `name`
- `campaign_type`
- `offer_mode`
- `language_mode`
- `offers`
- `channels`
- `audience_segment_id` when a reusable segment is a strong match
- `audience.smart_filters` when no reusable segment is a strong match
- `audience.include_mailing_list_ids` and exclusions when relevant
- `schedule_type`
- `scheduled_at`
- `locale`
- `cta_url`
- `channels[*].message_template_id`
- `channels[*].subject_template`
- `channels[*].title_template`
- `channels[*].body_template`
- `settings.holdout`
- `settings.channel_fallback`
- assistant-specific metadata in campaign settings

### Data that should remain confirmable by the user
- final send decision
- final schedule when timing was inferred
- offer choice when multiple strong commercial candidates exist
- large audience strategy changes
- discount / promotion details when financially sensitive

### Information that should force a clarification
- the request cannot be mapped to a valid campaign objective
- the draft would have no offer when an offer is required by the current domain flow
- the audience estimate resolves to zero useful recipients
- all candidate channels are blocked or disabled
- the user request contains contradictory timing or scope

## Decision Rules For Minimum Questions
- If one offer is explicitly named, use it.
- If no offer is named:
  - prefer the most recent active offer for launch intent
  - prefer a best-selling offer for promotion intent
  - prefer a broad retention audience for winback intent
- If no segment is named:
  - reuse an existing segment if the semantic match is strong
  - otherwise generate smart filters
- If no channel is named:
  - prefer email for rich promotion and launch
  - prefer SMS for urgent short-window campaigns
  - prefer in-app as complement, not primary, unless tenant data strongly supports it
- If no date is named:
  - propose the next valid sending slot
  - respect quiet hours and current date context
- If language is unknown:
  - use preferred language mode first
- If performance data is weak:
  - fall back to tenant-safe defaults instead of asking more questions

## Draft Presentation UX
The assistant should not answer with a long conversational paragraph only.

It should produce a compact response structure:

### Draft prepared
- Campaign name
- Objective
- Type
- Main offer
- Audience summary
- Channel plan
- Schedule
- KPI focus

### Deduced
- facts inferred from tenant data or request

### Proposed
- fields chosen by heuristics or history

### Needs confirmation
- only the truly blocking or high-impact items

### Next action
- open draft in campaign edit
- refine message
- change audience
- test send

## Technical Delivery Strategy

### Reuse-first architecture
- extend the existing assistant backend, do not fork it
- introduce a campaign-specific orchestration service
- keep final persistence in the current `CampaignService`
- keep final editing in the current `Campaigns/Wizard.vue`

### New backend components
- `AssistantCampaignService`
  - orchestrates campaign-specific AI and backend resolution
- `CampaignAssistantContextService`
  - gathers compact tenant marketing context
- `CampaignDraftScoringService`
  - ranks offers, segments, and channels
- `CampaignAssistantPresenter`
  - formats the assistant response payload

### Existing backend components to reuse
- `CampaignService`
- `AudienceResolver`
- `TemplateLibraryService`
- `TemplateRenderer`
- `MarketingSettingsService`
- `DashboardKpiService`

### Frontend adjustments
- extend the global assistant action handling to support campaign draft redirects
- add a campaign-oriented assistant response card
- keep the existing assistant panel and campaign wizard

## Delivery Phases

### Phase 0 - Discovery and contract
- define the assistant-to-campaign payload contract
- list the exact tenant data needed for context
- document blocking vs non-blocking campaign fields
- confirm where assistant metadata will be stored in campaign settings

Definition of done:
- JSON contract documented
- mapping table request -> campaign type documented
- blocking field policy documented

### Phase 1 - Campaign intent recognition and context loading
- add campaign-related intents to the assistant interpreter
- add campaign routing in the assistant controller
- build `CampaignAssistantContextService`
- expose compact campaign context to the assistant flow

Definition of done:
- assistant can recognize campaign drafting requests
- assistant can load tenant marketing context safely
- no regression on existing assistant workflows

### Phase 2 - Draft creation in the existing Campaigns module
- implement `AssistantCampaignService`
- map free-text intent to a valid campaign draft payload
- create a real draft via existing campaign persistence
- redirect to the existing campaign edit page

Definition of done:
- a simple user request can create a real draft campaign
- draft contains valid campaign core fields
- draft is editable in the current wizard

### Phase 3 - Intelligent audience, offer, and message deduction
- rank offers and audience candidates using tenant data
- reuse segments and templates when possible
- generate message overrides when needed
- run audience estimate and preview validation before marking draft ready

Definition of done:
- assistant can prepare a campaign with a realistic audience and message
- assistant clearly labels assumptions
- assistant avoids unnecessary clarification in common cases

### Phase 4 - Premium review experience and confirmation rules
- add a structured "deduced / proposed / needs confirmation" response
- support campaign-specific assistant actions in the frontend
- refine confirmation rules for high-risk decisions
- expose preview, test-send, and next-step suggestions

Definition of done:
- assistant output is fast to scan
- user sees what is ready and what remains to validate
- campaign refinement feels integrated, not bolted on

### Phase 5 - Learning and optimization
- use historical campaign outcomes to improve recommendations
- favor better-performing channels and templates for similar intents
- add internal scoring logs for assistant recommendation quality
- prepare future support for optimization loops without changing the draft flow

Definition of done:
- recommendations are measurably more context-aware
- fallback behavior remains deterministic when history is sparse

## Rollout Notes
- ship behind a feature flag first
- start with draft creation only, never auto-send
- keep all assistant-created campaigns in `draft` status initially
- add audit metadata so support and product can inspect assistant decisions

## Test Strategy
- unit:
  - intent mapping
  - offer ranking
  - audience strategy selection
  - blocking question logic
- feature:
  - one-line request creates a valid campaign draft
  - draft respects tenant settings
  - invalid or ambiguous cases trigger minimal clarification
- integration:
  - audience estimate
  - template preview
  - redirect to campaign edit
- regression:
  - existing assistant intents still work
  - existing campaign wizard flow remains unchanged

## Done Definition
- campaign drafting works from short natural-language prompts
- assistant fills most campaign fields without excessive questioning
- assistant output is explicit about deductions and assumptions
- real drafts are created in the existing Campaigns module
- users can edit and launch from the current campaign flow
- tests cover the critical decision paths
