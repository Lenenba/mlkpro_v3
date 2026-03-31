# Plan Scan AI - User Story

Derniere mise a jour: 2026-03-31

## Goal
Faire evoluer le module `Plan Scan` afin qu il passe d un generateur d estimation base sur quelques metriques manuelles a un vrai lecteur de plans assiste par IA:
- capable de lire un PDF ou une image
- capable de detecter des elements metier utiles
- capable de proposer des quantites et hypotheses structurees
- capable d alimenter le moteur de devis existant sans casser la logique metier

L objectif n est pas de laisser l IA fabriquer seule un devis final sans controle, mais de reduire fortement le temps de preparation et d augmenter la qualite du premier brouillon.

## Current Baseline
Le module actuel couvre deja:
- upload d un plan en PDF ou image
- choix du metier principal
- saisie optionnelle de `surface`, `rooms` et `priority`
- generation de variantes de devis `eco / standard / premium`
- attachement de sources prix live quand disponibles
- conversion rapide d une variante en devis

Mais l analyse actuelle reste surtout basee sur:
- les metriques saisies par l utilisateur
- des catalogues metier predefinis
- des regles heuristiques

Le systeme ne lit pas encore vraiment le contenu visuel du plan.

## Product Problem
Aujourd hui, plusieurs frictions limitent la valeur du module:
- le nom `scan` promet plus que ce que le produit fait vraiment
- l utilisateur doit encore saisir des metriques clefs a la main
- le plan importe n est pas exploite comme une vraie source d extraction
- le score de confiance ne reflete pas une lecture document reelle
- les estimations peuvent etre rapides, mais pas encore assez credibles pour des plans plus riches
- il manque une etape de revue claire entre l extraction et le devis

Consequences:
- promesse produit partiellement tenue
- charge manuelle encore importante pour l estimateur
- difficulte a vendre une experience `AI-assisted estimating`
- risque de confusion entre estimation heuristique et lecture de plan

## Scope
- lecture multimodale de plans PDF et images par IA
- extraction structuree des metriques et des elements detectes
- pipeline asynchrone avec statuts clairs
- revue humaine des champs detectes avant conversion en devis
- integration avec le moteur de pricing et de variantes deja en place
- stockage des hypotheses, incertitudes, flags de revue et usages IA
- strategie de fallback manuel si l IA echoue ou manque de confiance
- controle des couts et de la latence du moteur IA
- possibilite d envoyer un plan ou une image directement dans le chat IA pour lancer un scan et obtenir un brouillon de devis

## Non-goals
- remplacer un logiciel CAD ou BIM
- garantir une precision legale ou technique de niveau expert sans validation humaine
- produire automatiquement un devis final envoye au client sans revue
- detecter parfaitement toutes les cotes complexes, annotations minuscules ou symbols exotiques
- faire disparaitre la logique metier actuelle de pricing, marges et variantes

## Product Principles
- l IA doit extraire des faits et hypotheses, pas decider seule du devis final
- la logique metier de prix doit rester deterministe et auditable
- les champs incertains doivent etre visibles, pas caches
- une revue humaine doit etre simple, rapide et naturelle
- une erreur de lecture ne doit jamais bloquer totalement le workflow
- le module doit rester utile meme sans IA grace a un mode manuel degrade
- le cout IA doit etre pilote, observable et justifiable
- le chat IA doit pouvoir devenir un point d entree rapide, mais jamais contourner les garde-fous de revue

## Personas

### Persona 1 - Owner / estimator
Veut gagner du temps sur la lecture initiale du plan sans perdre le controle sur le devis final.

### Persona 2 - Sales / pre-sales
Veut produire une premiere estimation plus vite pour repondre au prospect avec un brouillon credible.

### Persona 3 - Operations admin
Veut un pipeline stable, observable, auditable et simple a maintenir.

### Persona 4 - User of the AI assistant
Veut deposer un plan dans le chat, poser une question simple en langage naturel, et obtenir un scan exploitable sans ouvrir un workflow separe.

## Primary User Story

### US-SCAN-001 - AI-assisted plan reading
As an owner or estimator,
I want the platform to read an uploaded plan with a real AI extraction engine,
so I can get a structured first estimate without manually rebuilding the scope from scratch.

Acceptance criteria:
- the user can upload a PDF or image plan as today
- the system runs an AI extraction pass in background
- the extraction returns structured fields instead of free text only
- the user sees the detected metrics, detected elements and flagged uncertainties
- the user can review and adjust the extracted data before creating a quote
- the pricing engine keeps generating the quote variants from validated data

## Supporting User Stories

### US-SCAN-002 - Async multimodal analysis
As an estimator,
I want plan analysis to run asynchronously,
so the upload flow stays fast and I can follow the progress instead of waiting on a blocking request.

Acceptance criteria:
- after upload, the scan enters a queued or processing status
- the UI exposes statuses such as:
  - queued
  - extracting
  - review_required
  - ready
  - failed
- the user can open the scan detail while analysis is still running
- failures expose a readable reason and a retry action

### US-SCAN-003 - Structured AI extraction
As an estimator,
I want the AI engine to return structured JSON fields,
so the output can feed the pricing engine and the review UI safely.

Acceptance criteria:
- the extraction payload includes:
  - trade guess
  - estimated surface
  - estimated room count
  - detected elements
  - units and quantity assumptions
  - assumptions list
  - missing data list
  - confidence indicators
- the extraction format is schema-driven and stable
- the raw extraction payload is stored for audit
- the normalized extraction payload is stored separately for UI and business logic

### US-SCAN-004 - Review before quote conversion
As an estimator,
I want to review and correct the AI-detected fields before converting to a quote,
so I stay in control of the final commercial output.

Acceptance criteria:
- the scan detail page shows extracted metrics in editable form
- the user can add, remove or edit detected elements
- low-confidence fields are highlighted
- the user can confirm the reviewed extraction
- quote conversion uses the reviewed extraction, not the unreviewed raw payload

### US-SCAN-005 - Preserve deterministic pricing
As a product owner,
I want the existing pricing engine to remain the source of truth for margins and quote variants,
so the commercial logic stays predictable and testable.

Acceptance criteria:
- AI extraction does not directly define final sale price logic
- the existing variant model `eco / standard / premium` remains supported
- supplier price lookup remains compatible with extracted line items
- the system can still generate quote variants even when some extracted fields were corrected manually

### US-SCAN-006 - Fallback and degraded manual mode
As an estimator,
I want the workflow to keep working even if the AI pass fails or is disabled,
so the module never becomes unusable.

Acceptance criteria:
- if the AI service is unavailable, the scan can still continue in manual mode
- the user can manually fill core metrics and proceed
- the system clearly distinguishes `manual estimation` from `AI-assisted extraction`
- the user can relaunch AI analysis later on the same scan

### US-SCAN-007 - Confidence and review flags
As an estimator,
I want visible confidence markers and review flags,
so I know where the AI is solid and where I should verify.

Acceptance criteria:
- each important extraction block exposes a confidence indicator
- the scan shows a global confidence score
- the UI highlights fields such as:
  - small text not readable
  - scale not detected
  - quantity inferred heuristically
  - room count ambiguous
  - element category uncertain
- the user can filter or jump directly to flagged fields

### US-SCAN-008 - Cost and latency control
As an operations admin,
I want the AI scan flow to stay cost-controlled,
so the feature remains viable in production and at scale.

Acceptance criteria:
- the system records model, token usage and request cost metadata when available
- the pipeline supports a default model and a stronger fallback model
- retries are limited and observable
- repeated uploads of the same file can be deduplicated or cached when appropriate
- large plans do not create unbounded analysis loops

### US-SCAN-009 - Audit and explainability
As an admin or owner,
I want traceability for AI-generated scan results,
so I can understand what was extracted, reviewed and converted.

Acceptance criteria:
- the scan stores:
  - uploaded file metadata
  - extraction timestamp
  - model used
  - normalized result
  - raw result
  - review edits
  - conversion history
- the system can tell whether a quote came from:
  - manual metrics only
  - AI extraction
  - AI extraction with manual edits

### US-SCAN-010 - Trade-aware extraction behavior
As an estimator,
I want extraction prompts and normalization rules to adapt to the selected trade,
so the engine detects more relevant elements for plumbing, electricity, painting and other workflows.

Acceptance criteria:
- the selected trade biases the extraction prompt and normalization
- the system can still override the trade if the detected document strongly suggests another one
- each trade can define its own expected elements and units
- unsupported trades fall back to a general extraction profile

### US-SCAN-011 - Scan from AI chat attachment
As a user of the AI assistant,
I want to drag a plan PDF or an image into the AI chat and ask for a scan or a quote draft,
so I can start from a conversational workflow instead of opening the dedicated scan module first.

Acceptance criteria:
- the AI chat accepts a plan PDF or image attachment
- the user can write prompts such as:
  - `analyse ce plan`
  - `scanne ce document et prepare un devis`
  - `detecte les elements pour un devis plomberie`
- the assistant can create a real `plan scan` record from the conversation
- the assistant returns a readable summary in chat and a link to the generated scan
- if enough data is available, the assistant can also prepare a quote draft candidate from the reviewed scan payload
- if confidence is low, the assistant must ask for review instead of pretending the draft is final
- the scan and quote remain visible in the standard application workflows, not only inside chat

### US-SCAN-012 - Conversational review and refinement
As a user of the AI assistant,
I want to refine the extracted scan through follow-up chat instructions,
so I can adjust the result quickly before opening the full detail screen.

Acceptance criteria:
- the user can send follow-up instructions such as:
  - `considere plutot 3 salles de bain`
  - `retire la baignoire`
  - `passe en mode premium`
- the assistant updates the structured draft safely
- the assistant never mutates a finalized quote silently
- the chat clearly distinguishes:
  - AI suggestion
  - reviewed scan data
  - created quote draft

## UX Requirements

### Upload experience
- the create screen must still feel simple
- the user should not be forced to fill many manual fields before the AI pass
- `surface` and `rooms` should become reviewable outputs, not only manual inputs

### Chat entry experience
- the AI chat should expose an attachment action for plan PDF and image files
- the first response should summarize:
  - detected trade
  - estimated metrics
  - detected elements
  - confidence level
  - next recommended action
- the assistant should provide a direct link to:
  - open the scan
  - review the scan
  - create a quote draft when allowed

### Review cockpit
- the detail screen should expose:
  - detected metrics
  - detected line items
  - confidence flags
  - assumptions
  - missing information
- the user must be able to edit without digging into raw JSON

### Status design
- statuses must clearly distinguish:
  - uploaded
  - extracting
  - review required
  - ready for quote
  - failed
- failures must be understandable by non-technical users

## Data Model Direction
Recommended additions around `plan_scans`:
- `ai_status`
- `ai_model`
- `ai_usage`
- `ai_extraction_raw`
- `ai_extraction_normalized`
- `ai_reviewed_payload`
- `ai_review_required`
- `ai_failed_at`
- `ai_error_message`

Possible nested payload fields:
- `document`
- `metrics`
- `detected_elements`
- `dimensions`
- `fixtures`
- `assumptions`
- `missing_data`
- `review_flags`
- `confidence`

Recommended linkage for chat-driven scans:
- `source = assistant_chat`
- `source_thread_id`
- `source_message_id`
- `assistant_summary`
- `assistant_last_instruction`

## Technical Direction
- keep the current pricing and quote-generation engine
- add a dedicated AI extraction service ahead of pricing
- run extraction in a queued job
- use a schema-based structured output
- normalize AI output before handing it to pricing
- keep a manual fallback path
- expose the same extraction pipeline both from the dedicated scan UI and from the AI chat

Recommended flow:
1. user uploads plan
2. scan record is created
3. async AI extraction job starts
4. normalized extraction is stored
5. review UI is unlocked
6. user confirms or edits extracted values
7. pricing engine generates quote variants
8. user converts chosen variant to a quote

Recommended chat flow:
1. user drops a plan or image in AI chat
2. assistant recognizes `scan / quote` intent
3. assistant creates a real scan record
4. async AI extraction runs on the same backend pipeline
5. assistant posts a structured summary in chat
6. user can refine by chat or open the scan detail page
7. quote draft creation only happens from validated payload

## Proposed AI Strategy
- default engine: fast multimodal model for most scans
- fallback engine: stronger multimodal model for hard or low-confidence scans
- prompt style: trade-aware and schema-first
- output style: structured JSON only
- no direct final pricing authority given to the model

## Example Extraction Targets
- `surface_m2_estimate`
- `room_count_estimate`
- `opening_count`
- `fixture_count`
- `wall_length_estimate`
- `detected_service_lines`
- `detected_material_lines`
- `document_type`
- `trade_guess`
- `notes_for_review`

## Example Chat Prompts
- `Analyse ce plan et donne moi les principaux elements a chiffrer.`
- `Scanne cette image et prepare un brouillon de devis en electricite.`
- `Lis ce PDF, estime la surface, les pieces, puis prepare une variante standard.`
- `A partir de ce plan, cree un scan puis un devis brouillon pour plomberie.`
- `Reprends ce scan et retire les elements incertains.`

## Success Metrics
- reduction in time to first draft quote
- reduction in manual fields entered on scan creation
- higher conversion from scan to quote
- lower rework after first draft
- better perceived usefulness of the scan module
- acceptable cost per processed scan
- adoption of chat-driven scan creation
- percentage of scan-to-quote flows started from chat

## Risks
- overconfidence on ambiguous plans
- poor extraction on noisy scans or tiny annotations
- confusion between AI suggestion and validated commercial output
- runaway cost on large or repeated scans
- mismatch between extracted items and existing product or service catalog
- chat creating records too eagerly without enough user intent confirmation
- users assuming a chat summary is already a validated quote

## Mitigations
- explicit confidence flags
- mandatory review before quote conversion for low-confidence scans
- deterministic pricing engine kept separate
- async retries with limits
- audit trail for extraction and edits
- manual fallback path always available
- clear chat states for `draft suggestion`, `scan created`, and `quote draft created`
- require an explicit create action before final quote draft generation when confidence is medium or low

## Delivery Phases

### Phase 1 - AI-assisted metrics
Ship first:
- trade guess
- surface estimate
- room count estimate
- assumptions
- confidence flags

Outcome:
- the module becomes a real `reader` of the uploaded plan
- manual fields become optional confirmation fields

### Phase 2 - Detected elements
Ship next:
- extracted elements and quantities
- editable review table
- normalized payload reused by pricing

Outcome:
- quote variants become far more grounded in the uploaded file

### Phase 3 - Confidence-led review
Ship next:
- review required status
- per-field flags
- retry or escalate AI analysis

Outcome:
- safer workflow for production use

### Phase 4 - Optimization and scale
Ship next:
- model fallback strategy
- caching and deduplication
- usage and cost observability

Outcome:
- production-grade cost and performance control

### Phase 5 - Chat-native scan and quote workflow
Ship next:
- attachment support in AI chat for plans and images
- intent detection for `scan` and `quote from plan`
- chat summaries linked to real scan records
- conversational refinement before opening full review UI

Outcome:
- the assistant becomes a fast entry point to the scan module without creating a second disconnected workflow

## Implementation Notes
This initiative should reuse as much of the current module as possible:
- upload flow
- scan listing
- quote conversion
- pricing variants
- supplier lookup

The main change is architectural:
- `today`: metrics drive the estimate, the file mostly proves context
- `target`: the file drives a structured extraction, then pricing uses the validated extraction

## Summary
This user story transforms `Plan Scan` from a metrics-assisted estimator into a true AI-assisted plan reading workflow.

The target product behavior is:
- upload a real plan
- let AI extract structured facts
- let a human review quickly
- let the existing pricing engine generate commercial variants

That is the right balance between:
- speed
- credibility
- control
- auditability
- production safety
