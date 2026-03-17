# Campaigns Prospect Providers Integrations - User Stories

Derniere mise a jour: 2026-03-16

## Goal
Permettre a la plateforme d importer et d utiliser des prospects provenant de fournisseurs externes via API, en commencant par:
- `Apollo`
- `Lusha`
- `UpLead`

L objectif n est pas seulement de "faire entrer des contacts". Il faut fournir une vraie experience produit:
- connexion d un provider
- choix explicite du fournisseur dans le flux audience
- import de prospects dans une campagne
- previsualisation des prospects avant import ou lancement
- mapping vers le modele prospect interne
- review et approbation
- usage normal dans le pipeline de prospection existant

## Scope
- connexion API a un fournisseur de prospects
- stockage securise des credentials par tenant
- choix du fournisseur dans l interface campagne
- previsualisation des prospects remontes par le fournisseur
- selection des prospects a importer
- import manuel declenche depuis la plateforme
- normalisation vers `campaign_prospects`
- tracabilite du `source_type` et `source_reference`
- support de `Apollo`, `Lusha` et `UpLead`
- experience unifiee dans le module `campaigns`

## Non-goals
- construire un moteur de scraping web libre
- contourner les conditions d utilisation des fournisseurs
- envoyer automatiquement a tous les prospects trouves sans review
- remplacer le pipeline de prospection existant
- lancer en V1 une synchronisation temps reel complexe entre plusieurs fournisseurs

## Product Principles
- chaque fournisseur doit s integrer dans le meme pipeline prospecting
- le fournisseur externe ne doit pas contourner la review humaine
- les imports doivent rester tenant-scoped
- le mapping fournisseur -> prospect interne doit etre explicite et tracable
- la plateforme doit pouvoir brancher d autres providers plus tard sans refaire l architecture

## Current Baseline
- le module `campaigns` supporte deja la prospection outbound
- les prospects peuvent deja etre importes par CSV ou payload manuel
- le modele `CampaignProspect` supporte deja des `source_type` comme:
  - `connector`
  - `directory_api`
  - `import`
  - `manual`
  - `csv`
- l API d import prospect existe deja
- le pipeline `analyze -> review -> approve -> outreach` existe deja

## Product Vision
La plateforme doit permettre a une entreprise de connecter un ou plusieurs fournisseurs de prospects et de les utiliser comme une source d intake gouvernee.

Le parcours cible est:

`provider account -> provider connector -> provider selection -> provider prospect preview -> normalized prospects -> campaign batch -> review -> approval -> outreach -> lead/customer conversion`

## Shared Business Rules

### Rule 1 - A provider import is not an immediate send audience
Tout prospect venant d un fournisseur externe entre d abord dans le pipeline prospecting.

Il doit passer par:
- import
- normalisation
- analyse
- dedupe
- review
- approbation

avant d entrer dans l audience eligible.

### Rule 2 - Provider origin must stay visible
Chaque prospect importe doit conserver:
- son `source_type`
- son `source_reference`
- si possible un `external_ref`
- les metadonnees utiles au provider

### Rule 3 - Provider credentials are tenant-owned
Chaque entreprise doit connecter ses propres credentials.

La plateforme ne doit pas mutualiser les credentials d un fournisseur entre plusieurs tenants.

### Rule 4 - Shared normalized contract
Quel que soit le fournisseur, les donnees doivent pouvoir alimenter au minimum:
- `company_name`
- `first_name`
- `last_name`
- `contact_name`
- `email`
- `phone`
- `website`
- `city`
- `state`
- `country`
- `industry`
- `company_size`
- `tags`
- `metadata`

### Rule 5 - Review stays mandatory for cold outbound
Un import provider ne doit pas automatiquement pousser les prospects dans une campagne active sans validation.

### Rule 6 - Preview before launch
Avant de lancer la campagne, l utilisateur doit pouvoir visualiser les prospects lies au fournisseur.

Cette previsualisation doit permettre au minimum de:
- voir les principaux champs utiles
- comprendre la provenance du prospect
- identifier les doublons ou blocages evidents
- selectionner ou deselectionner ce qui doit etre importe ou retenu

### Rule 7 - Failures must be explainable
Si un import provider echoue, la plateforme doit permettre de comprendre:
- si le probleme vient des credentials
- de la limite de quota
- d un mapping invalide
- d une erreur de pagination
- d un schema fournisseur incomplet

## Primary Epic

### EPIC-CMP-PROV-001 - Prospect provider integrations
As a platform owner, I want the campaigns module to connect to external prospect providers so tenant companies can source prospects directly inside the platform and run controlled prospecting workflows without depending only on CSV imports.

Success metrics:
- a tenant can connect at least one provider account
- a tenant can choose which provider to use at import time
- a tenant can preview provider prospects before importing them into a campaign
- a tenant can import prospects from a provider into a campaign
- imported prospects enter the existing review workflow
- provider-specific imports are distinguishable in reporting
- the architecture can be extended to additional providers later

## Shared Foundation Story

### US-CMP-PROV-001 - Shared connector foundation
As a platform owner, I want a reusable provider connector foundation so Apollo, Lusha, and UpLead can be integrated in a consistent way.

Acceptance criteria:
- the platform supports a tenant-level provider connection model
- each provider connection stores:
  - provider key
  - credential payload
  - connection status
  - last validation timestamp
  - optional account label
- provider credentials are stored securely
- a provider connection can be tested before use
- the import flow supports an explicit provider selection step
- the import flow supports a provider preview dataset before batch creation
- the import flow can create prospect batches from normalized provider payloads
- imported records preserve provider metadata in a safe and structured way
- the UI shows provider name, import time, and import result summary
- the import pipeline reuses the current `campaign_prospects` flow instead of bypassing it

## Provider Stories

### US-CMP-PROV-APOLLO-001 - Connect Apollo account
As a tenant owner, I want to connect my Apollo account so I can import Apollo prospects directly into prospecting campaigns.

Acceptance criteria:
- the platform provides a connector setup for `Apollo`
- the tenant can save the credentials required by Apollo
- the system can validate that the Apollo connection works
- the UI shows whether the Apollo connection is:
  - connected
  - invalid
  - expired
  - rate-limited
- the tenant can disconnect Apollo without deleting existing imported prospects

### US-CMP-PROV-APOLLO-002 - Import Apollo prospects into a campaign
As a sales or marketing owner, I want to import Apollo prospects into a campaign so I can work them inside the existing prospecting workflow.

Acceptance criteria:
- a user can choose an existing campaign in prospecting mode
- a user can launch an Apollo import from the platform
- before creating the batch, the user can preview the Apollo prospects returned by the query
- the user can choose which previewed Apollo prospects should be imported
- the platform can request one or more pages of Apollo results
- imported records are normalized into prospect batches
- imported prospects use:
  - `source_type=connector`
  - `source_reference=Apollo`
- if Apollo provides a stable external id, it is stored as `external_ref`
- the batch summary shows:
  - input count
  - analyzed count
  - duplicates
  - blocked
  - accepted for review
- Apollo imports can be reviewed and approved like any other prospect batch

### US-CMP-PROV-APOLLO-003 - Preserve Apollo source metadata
As a sales operator, I want Apollo-specific metadata to be preserved so I can understand where each imported prospect came from.

Acceptance criteria:
- the system stores Apollo metadata in the prospect `metadata` field
- metadata may include:
  - provider account label
  - provider object id
  - provider query context
  - import page reference
  - import timestamp
- the platform exposes Apollo origin details in prospect detail screens where useful
- metadata storage does not break the generic provider contract

### US-CMP-PROV-LUSHA-001 - Connect Lusha account
As a tenant owner, I want to connect my Lusha account so I can source B2B prospects from Lusha directly inside the platform.

Acceptance criteria:
- the platform provides a connector setup for `Lusha`
- the tenant can save and validate Lusha credentials
- the connector status is visible in settings or campaign integrations
- the tenant can revoke the connection cleanly
- failed validation shows a clear error state

### US-CMP-PROV-LUSHA-002 - Import Lusha prospects into a campaign
As a marketing or sales owner, I want to import Lusha prospects into a campaign so I can enrich my outbound workflow without manual exports.

Acceptance criteria:
- a user can trigger a Lusha import into a selected campaign
- before import confirmation, the user can preview the Lusha prospects found
- the user can keep or exclude rows from the preview selection
- the platform maps Lusha records to the normalized prospect schema
- imported prospects use:
  - `source_type=connector`
  - `source_reference=Lusha`
- Lusha records can be turned into campaign prospect batches
- duplicates and blocked contacts are flagged by the existing analysis engine
- imported Lusha prospects appear in the same review UI as other prospect sources

### US-CMP-PROV-LUSHA-003 - Lusha enrichment aware imports
As a sales operator, I want Lusha enrichment fields to be preserved when available so the review team has better context before approval.

Acceptance criteria:
- when available, enrichment data is stored in metadata without breaking the normalized schema
- enrichment details can include:
  - company data
  - role or title
  - location
  - confidence or match indicators
- the normalized prospect still works even if some enrichment fields are missing
- missing provider enrichment does not block the whole import

### US-CMP-PROV-UPLEAD-001 - Connect UpLead account
As a tenant owner, I want to connect my UpLead account so I can use UpLead as a direct source of prospects for campaigns.

Acceptance criteria:
- the platform provides a connector setup for `UpLead`
- UpLead credentials can be stored and validated
- connection health is visible to the tenant
- the tenant can disable the connection without losing import history

### US-CMP-PROV-UPLEAD-002 - Import UpLead prospects into a campaign
As a sales owner, I want to import UpLead prospects into a campaign so I can avoid repeated CSV exports and centralize prospecting inside the platform.

Acceptance criteria:
- a user can trigger an UpLead import for a selected campaign
- the platform shows a preview of the UpLead records before creating the batch
- the user can confirm which UpLead records should enter the campaign
- UpLead records are paginated and normalized into prospect batches
- imported prospects use:
  - `source_type=directory_api` or `source_type=connector`
  - `source_reference=UpLead`
- the selected source type is consistent across the product and documented
- imported prospects pass through the normal scoring and review flow
- imported prospects can later be approved and sent through outreach like any other batch

### US-CMP-PROV-UPLEAD-003 - Provider query traceability
As an operator, I want UpLead query context to remain traceable so I can understand which search generated each prospect batch.

Acceptance criteria:
- the batch keeps a readable import reference
- the provider query or filter context can be stored in metadata
- the review team can see enough context to distinguish one import from another
- traceability works without exposing sensitive credentials in the UI

## Shared UX Stories

### US-CMP-PROV-UX-001 - Choose provider during import
As a user, I want to choose a provider directly from the audience step so I can import prospects without leaving the campaign flow.

Acceptance criteria:
- the audience step supports a provider import mode in addition to manual and CSV
- the user can choose:
  - Apollo
  - Lusha
  - UpLead
- the user can change provider before import without losing the current campaign context
- the UI indicates whether the selected provider is already connected
- if no provider is connected, the user is guided to connect one

### US-CMP-PROV-UX-002 - Preview provider prospects before import
As a user, I want to preview the prospects returned by the selected provider before import so I can decide what should really enter the campaign.

Acceptance criteria:
- after choosing a provider and launching a fetch, the UI displays a preview list of returned prospects
- each preview row shows key fields such as:
  - company name
  - contact name
  - email and or phone when available
  - website or domain when available
  - location when available
- the preview highlights obvious missing fields
- the user can select all, deselect all, or choose rows individually
- the user can confirm import only for the selected rows
- no campaign launch is allowed directly from raw provider results without this preview step

### US-CMP-PROV-UX-003 - Provider import review summary
As a user, I want a clear import summary after a provider pull so I can decide whether the batch is worth reviewing and approving.

Acceptance criteria:
- after import, the UI displays:
  - provider name
  - imported count
  - analyzed count
  - duplicates
  - blocked
  - accepted
- the summary links directly to the imported batch detail
- provider imports are visually distinguishable from manual and CSV imports

## Shared Technical Stories

### US-CMP-PROV-TECH-001 - Provider adapter contract
As a developer, I want each provider to implement the same adapter contract so integrations remain maintainable.

Acceptance criteria:
- each provider adapter exposes standard operations for:
  - credential validation
  - prospect search or fetch
  - preview mapping
  - normalization
  - pagination handling
- the rest of the campaign import flow does not depend on provider-specific controller logic
- adding a fourth provider does not require redesigning the import pipeline

### US-CMP-PROV-TECH-002 - Safe import orchestration
As a platform owner, I want provider imports to be safe and resilient so temporary API issues do not corrupt prospect data.

Acceptance criteria:
- imports can fail without creating partial unusable state where possible
- errors are logged with provider context
- rate-limit or timeout failures are surfaced clearly
- imports preserve idempotency as much as possible
- repeated imports do not silently create uncontrolled duplicates

## Delivery Order

### Phase 1 - Shared connector base
- US-CMP-PROV-001
- US-CMP-PROV-TECH-001
- US-CMP-PROV-TECH-002
- US-CMP-PROV-UX-001
- US-CMP-PROV-UX-002

### Phase 2 - Apollo
- US-CMP-PROV-APOLLO-001
- US-CMP-PROV-APOLLO-002
- US-CMP-PROV-APOLLO-003

### Phase 3 - Lusha
- US-CMP-PROV-LUSHA-001
- US-CMP-PROV-LUSHA-002
- US-CMP-PROV-LUSHA-003

### Phase 4 - UpLead
- US-CMP-PROV-UPLEAD-001
- US-CMP-PROV-UPLEAD-002
- US-CMP-PROV-UPLEAD-003

## Technical Notes
- reuse the existing prospect batch import flow
- map provider imports to the same normalized `CampaignProspect` contract
- preserve provider provenance in `source_type`, `source_reference`, `external_ref`, and `metadata`
- avoid provider-specific branching inside the audience resolution engine
- keep the review and approval workflow unchanged for imported providers

## Test Strategy
- unit:
  - provider adapter normalization
  - provider credential validation
  - batch metadata mapping
- feature:
  - connect provider
  - preview provider prospects before import
  - import provider prospects into campaign
  - reuse review and approval flow on imported batches
- integration:
  - provider pagination handling
  - provider auth failures
  - duplicate prevention across repeated imports

## Done Definition
- a tenant can connect Apollo, Lusha, and UpLead
- a tenant can choose the provider to use inside the audience flow
- a tenant can preview prospects before importing or launching the campaign
- each provider can import prospects into a campaign
- each import becomes a reviewable prospect batch
- provenance is preserved for each provider
- the existing outreach pipeline continues to work without special-case hacks
- the architecture is ready for future providers
