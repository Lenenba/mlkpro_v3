# Campaigns Prospecting - Delivery Blueprint

Derniere mise a jour: 2026-03-15

## Goal
Traduire la user story `Campaigns Prospecting -> Lead Generation` en blueprint de livraison exploitable par produit, design et developpement.

Ce document couvre:
- le decoupage en epics et tickets
- le schema metier recommande
- les flux UI principaux
- les choix d integration avec les modules existants `campaigns`, `leads` et `customers`

## Summary
Le chemin recommande est:

`campaign -> prospect batch -> prospect -> outreach -> lead -> customer`

Le module `campaigns` reste l orchestrateur.
Le module `leads` reste le pipeline de qualification commerciale.
Le module `customers` reste la cible finale de conversion.

## V1 Scope
La V1 doit livrer 4 capacites prioritaires:
- intake de prospects par batch de 100
- dedupe + scoring + review humaine
- outreach prospecting via l infra campagnes existante
- conversion `prospect -> lead` avec attribution preservee

## Recommended Architecture Decisions

### Decision 1 - Reuse campaign dispatch infrastructure
Ne pas reinventer un moteur d envoi.

Le prospecting doit reutiliser:
- `campaign_runs`
- `campaign_recipients`
- `campaign_messages`
- `campaign_events`
- `ConsentService`
- `CampaignTrackingService`

Recommendation:
- garder `campaign_recipients.customer_id` nullable
- stocker `prospect_id` dans `campaign_recipients.metadata`
- stocker `source=prospecting` dans `campaign_recipients.metadata`

### Decision 2 - Create dedicated prospecting tables
Ne pas surcharger `requests` pour stocker des contacts froids.

Recommendation:
- `campaign_prospect_batches`
- `campaign_prospects`
- `campaign_prospect_activities`

### Decision 3 - Keep lead attribution inside lead metadata in V1
Pour limiter l impact schema sur `leads`, la V1 peut stocker l attribution dans `requests.meta`.

Recommendation metadata keys:
- `source_kind=campaign_prospecting`
- `source_campaign_id`
- `source_campaign_run_id`
- `source_campaign_recipient_id`
- `source_prospect_id`
- `source_prospect_batch_id`
- `source_channel`
- `source_direction` (`inbound` / `outbound`)

### Decision 4 - Human review before cold send
Le premier envoi prospecting en V1 doit pouvoir etre valide humainement.

Recommendation:
- aucun batch `cold_outbound` ne peut etre envoye sans statut `approved`
- les campagnes clients classiques ne changent pas

## Epic Breakdown

## Epic A - Prospect Foundation
Objectif:
Creer les entites prospecting et leur cycle de base sans casser les flows existants.

### Ticket A1 - Campaign prospecting mode
Description:
- ajouter un mode `prospecting_enabled` au niveau campagne
- ajouter `campaign_direction` ou equivalent logique:
  - `customer_marketing`
  - `prospecting_outbound`
  - `lead_generation_inbound`

Done when:
- une campagne peut etre distinguee d une campagne marketing client standard
- les permissions et l UI adaptent les ecrans au bon mode

### Ticket A2 - Prospect batch entity
Description:
- creer une entite batch pour grouper les imports et analyses par 100

Done when:
- un batch est cree lors de l import
- le batch expose ses compteurs et son statut

### Ticket A3 - Prospect entity
Description:
- creer l entite `campaign_prospects`
- stocker les informations brutes, normalisees et de qualification

Done when:
- un prospect peut exister sans etre un lead ni un customer
- un prospect est toujours rattache a un tenant et a une campagne

### Ticket A4 - Prospect activity timeline
Description:
- journaliser toutes les actions sur les prospects

Done when:
- toute action importante cree une trace auditable

## Epic B - Intake, Dedupe, Scoring
Objectif:
Permettre un intake propre et exploitable des prospects.

### Ticket B1 - CSV import and manual intake
Description:
- intake manuel et import CSV
- taille de batch par defaut: `100`

Done when:
- l utilisateur peut charger 100 prospects et obtenir un resume d intake

### Ticket B2 - Normalization pipeline
Description:
- normaliser email, telephone, domaine, nom entreprise, site web

Done when:
- les cles de rapprochement sont stables pour dedupe et scoring

### Ticket B3 - Dedupe engine
Description:
- verifier doublons contre:
  - `customers`
  - `requests`
  - `campaign_prospects`
  - destinations desinscrites / bloquees

Done when:
- chaque prospect porte un `match_status` ou equivalent
- les doublons critiques ne sont pas envoyables

### Ticket B4 - Scoring engine
Description:
- calculer:
  - `fit_score`
  - `intent_score`
  - `priority_score`
  - `qualification_summary`

Done when:
- chaque prospect a un score lisible et filtrable

### Ticket B5 - Batch review summary
Description:
- fournir un recap clair:
  - acceptes
  - bloques
  - doublons
  - top scores
  - review required

Done when:
- l operateur peut approuver ou rejeter le batch

## Epic C - Prospect Outreach
Objectif:
Brancher les prospects approuves sur le moteur de campagne deja existant.

### Ticket C1 - Approved prospects as campaign audience
Description:
- transformer les prospects approuves en destinataires de campagne
- reutiliser `campaign_recipients`

Done when:
- un prospect approuve peut etre cible par email / SMS / in-app si la logique le permet

### Ticket C2 - Prospect outreach timeline
Description:
- relier `campaign_events` et `campaign_prospect_activities`

Done when:
- ouverture, clic, unsubscribe, failure et relance sont visibles sur la fiche prospect

### Ticket C3 - Follow-up sequencing
Description:
- gerer:
  - first touch
  - follow-up 1
  - follow-up 2
  - stop conditions

Done when:
- les relances s arretent automatiquement si:
  - opt-out
  - do_not_contact
  - qualified
  - converted_to_lead

### Ticket C4 - Manual close / disqualify
Description:
- l utilisateur peut sortir un prospect du cycle

Done when:
- un prospect peut etre marque:
  - `disqualified`
  - `blocked`
  - `do_not_contact`
  - `duplicate`

## Epic D - Conversion and Attribution
Objectif:
Passer proprement du monde `prospecting` au monde `leads`.

### Ticket D1 - Prospect to lead conversion action
Description:
- action manuelle ou semi-automatique pour promouvoir un prospect en lead

Done when:
- un prospect peut devenir un `Request`
- l attribution campagne est preservee

### Ticket D2 - Lead attribution enrichment
Description:
- ajouter les cles d attribution dans `requests.meta`

Done when:
- la fiche lead peut afficher son origine prospecting

### Ticket D3 - Inbound campaign attribution
Description:
- si une campagne pousse vers un formulaire public, le lead cree doit etre rattache a la campagne

Done when:
- `campaign -> lead` est mesurable en inbound

### Ticket D4 - Funnel reporting
Description:
- reporting:
  - prospects
  - contacts
  - replies
  - qualified
  - leads
  - customers

Done when:
- la campagne affiche un funnel complet et exploitable

## Epic E - UX Workspace
Objectif:
Rendre le flow pilotable dans l interface.

### Ticket E1 - Prospecting dashboard in campaigns
Description:
- ajouter un onglet ou une vue `Prospecting` dans la campagne

Done when:
- la campagne expose les KPI prospecting

### Ticket E2 - Batch review table
Description:
- ecran de review par batch de 100

Done when:
- tri, filtre, bulk approve, bulk reject, bulk do_not_contact disponibles

### Ticket E3 - Prospect detail page or drawer
Description:
- fiche detaillee prospect

Done when:
- l utilisateur voit:
  - identity
  - source
  - score
  - dedupe matches
  - timeline
  - actions rapides

### Ticket E4 - Lead conversion action in UI
Description:
- bouton `Convert to lead`
- bouton `Link to existing lead`

Done when:
- l utilisateur peut promouvoir un prospect sans perdre le contexte

## Recommended Data Model

## 1) `campaign_prospect_batches`
Purpose:
Representer une vague de traitement de prospects pour une campagne.

Suggested columns:
- `id`
- `campaign_id`
- `user_id`
- `source_type`
- `source_reference`
- `batch_number`
- `input_count`
- `accepted_count`
- `rejected_count`
- `duplicate_count`
- `blocked_count`
- `scored_count`
- `contacted_count`
- `replied_count`
- `lead_count`
- `customer_count`
- `status`
- `analysis_summary` JSON
- `approved_at`
- `approved_by_user_id`
- `created_at`
- `updated_at`

Suggested status values:
- `draft`
- `analyzed`
- `approved`
- `running`
- `completed`
- `canceled`

Indexes:
- `(campaign_id, batch_number)`
- `(user_id, status, created_at)`

## 2) `campaign_prospects`
Purpose:
Stocker les prospects individuels avant conversion en lead.

Suggested columns:
- `id`
- `campaign_id`
- `campaign_prospect_batch_id`
- `user_id`
- `source_type`
- `source_reference`
- `external_ref`
- `company_name`
- `contact_name`
- `first_name`
- `last_name`
- `email`
- `email_normalized`
- `phone`
- `phone_normalized`
- `website`
- `website_domain`
- `city`
- `state`
- `country`
- `industry`
- `company_size`
- `tags` JSON
- `raw_payload` JSON
- `normalized_payload` JSON
- `fit_score`
- `intent_score`
- `priority_score`
- `qualification_summary`
- `status`
- `match_status`
- `matched_customer_id`
- `matched_lead_id`
- `converted_to_lead_id`
- `converted_to_customer_id`
- `first_contacted_at`
- `last_contacted_at`
- `last_replied_at`
- `last_activity_at`
- `do_not_contact`
- `blocked_reason`
- `owner_notes`
- `metadata` JSON
- `created_at`
- `updated_at`

Suggested status values:
- `new`
- `enriched`
- `scored`
- `approved`
- `contacted`
- `follow_up_due`
- `replied`
- `qualified`
- `converted_to_lead`
- `converted_to_customer`
- `duplicate`
- `blocked`
- `disqualified`
- `do_not_contact`

Suggested `match_status` values:
- `none`
- `matched_customer`
- `matched_lead`
- `matched_prospect`
- `blocked_destination`
- `manual_review_required`

Indexes:
- `(campaign_id, status)`
- `(campaign_prospect_batch_id, status)`
- `(user_id, email_normalized)`
- `(user_id, phone_normalized)`
- `(user_id, website_domain)`
- `(user_id, matched_customer_id)`
- `(user_id, matched_lead_id)`
- `(user_id, priority_score)`

## 3) `campaign_prospect_activities`
Purpose:
Journal d activite auditable du cycle prospecting.

Suggested columns:
- `id`
- `campaign_prospect_id`
- `campaign_id`
- `campaign_run_id` nullable
- `campaign_recipient_id` nullable
- `user_id`
- `actor_user_id` nullable
- `activity_type`
- `channel` nullable
- `summary`
- `payload` JSON
- `occurred_at`
- `created_at`
- `updated_at`

Suggested activity types:
- `batch_imported`
- `normalized`
- `dedupe_matched`
- `scored`
- `approved`
- `rejected`
- `outreach_sent`
- `opened`
- `clicked`
- `replied`
- `unsubscribe`
- `follow_up_scheduled`
- `follow_up_skipped`
- `qualified_manually`
- `converted_to_lead`
- `linked_to_existing_lead`
- `converted_to_customer`
- `marked_do_not_contact`
- `blocked`

Indexes:
- `(campaign_prospect_id, occurred_at)`
- `(campaign_id, activity_type, occurred_at)`
- `(campaign_recipient_id, activity_type)`

## 4) Reuse of `campaign_recipients`
No new send-recipient table recommended in V1.

Recommendation:
- when a prospect is sent through a campaign:
  - create `campaign_recipient`
  - set `customer_id = null` if no customer exists
  - store in `metadata`:
    - `prospect_id`
    - `prospect_batch_id`
    - `source=prospecting`
    - `campaign_direction=outbound`

Benefit:
- maximum reuse of current dispatch/tracking stack
- lower migration risk

## 5) Reuse of `requests`
No new lead table recommended.

Recommendation:
- create standard `Request`
- enrich `meta` with campaign/prospect attribution

Benefit:
- no duplicate CRM pipeline
- minimal impact on current lead workflows

## Recommended API / Action Surface

## Campaign side
- `POST /campaigns/{campaign}/prospect-batches/import`
- `POST /campaigns/{campaign}/prospect-batches/analyze`
- `POST /campaigns/{campaign}/prospect-batches/{batch}/approve`
- `POST /campaigns/{campaign}/prospect-batches/{batch}/reject`
- `GET /campaigns/{campaign}/prospects`
- `GET /campaigns/{campaign}/prospects/{prospect}`
- `POST /campaigns/{campaign}/prospects/{prospect}/approve`
- `POST /campaigns/{campaign}/prospects/{prospect}/reject`
- `POST /campaigns/{campaign}/prospects/{prospect}/mark-do-not-contact`
- `POST /campaigns/{campaign}/prospects/{prospect}/convert-to-lead`
- `POST /campaigns/{campaign}/prospects/{prospect}/link-to-lead`

## Lead side
- no major new surface in V1
- only enrich lead detail and analytics to expose campaign origin

## UI Flows

## Flow 1 - Create a prospecting campaign
Entry:
- depuis index campagnes
- `Create campaign`

Wizard adaptation:
1. Setup
2. Prospect source
3. Batch analysis
4. Message and sequence
5. Review and launch
6. Results

### Screen 1 - Setup
Fields:
- campaign name
- campaign direction
- target offer / service
- channel(s)
- language mode
- prospecting enabled

Primary action:
- `Continue`

### Screen 2 - Prospect source
Modes:
- import CSV
- manual paste
- connector import
- inbound landing source

Visible widgets:
- source selector
- batch size indicator
- sample mapping preview
- source provenance summary

Primary actions:
- `Analyze batch`
- `Save draft`

### Screen 3 - Batch analysis
Main goal:
Afficher les 100 prospects analyses avant outreach.

Blocks:
- KPI strip:
  - total input
  - accepted
  - duplicates
  - blocked
  - review required
- review table:
  - identity
  - company
  - email / phone
  - fit score
  - priority score
  - dedupe result
  - recommended action
- side panel:
  - qualification summary
  - scoring explanation
  - duplicate matches

Bulk actions:
- approve selected
- reject selected
- mark do_not_contact
- export review result

Primary action:
- `Approve batch`

### Screen 4 - Message and sequence
Main goal:
Configurer le contenu et la logique de relance.

Blocks:
- channel templates
- follow-up strategy
- delay between touches
- stop conditions
- compliance warnings

Primary action:
- `Preview launch`

### Screen 5 - Review and launch
Main goal:
Valider le lot avant envoi.

Blocks:
- approved prospects count
- blocked / excluded count
- message preview
- sequence summary
- opt-out / suppression summary

Primary actions:
- `Launch batch`
- `Schedule batch`

### Screen 6 - Results
Main goal:
Voir le funnel complet.

Blocks:
- funnel:
  - prospects
  - contacted
  - opened
  - clicked
  - replied
  - qualified
  - leads
  - customers
- table by prospect
- table by batch
- top sources
- top score segments

## Flow 2 - Prospect detail
Entry:
- from campaign results table
- from batch review table

Sections:
- identity and source
- score and explanation
- dedupe matches
- outreach timeline
- latest activity
- conversion section

Actions:
- approve
- reject
- mark do_not_contact
- add note
- convert to lead
- link to existing lead

## Flow 3 - Lead detail with campaign origin
Entry:
- from lead detail page

New block recommended:
- `Campaign origin`

Content:
- source campaign
- direction (`inbound` / `outbound`)
- source prospect id
- source batch id
- first outreach date
- conversion date
- original channel

Actions:
- open campaign
- open source prospect

## Operational Rules for UI
- no long unpaginated lists
- default working unit: 100 prospects
- all review tables support filtering by:
  - status
  - score
  - duplicate state
  - source type
- dangerous actions require confirmation:
  - mark do_not_contact
  - bulk reject
  - launch cold outreach

## Suggested Rollout Plan

### Phase 1
- data model
- batch import
- dedupe
- scoring
- review UI

### Phase 2
- outreach integration
- prospect timeline
- follow-up logic

### Phase 3
- convert to lead
- inbound attribution
- lead detail enrichment

### Phase 4
- reporting funnel
- cost metrics
- source quality comparisons

## V1 KPIs
- imported prospects
- approved prospects
- duplicate rate
- blocked rate
- outreach sent
- reply rate
- qualification rate
- prospect to lead rate
- lead to customer rate

## Open Product Questions
- quels signaux definissent exactement `qualified` vs `converted_to_lead` ?
- une simple ouverture ou un simple clic suffit-il a creer un lead ?
- les prospects sans email mais avec telephone sont-ils autorises en V1 ?
- le mode `in_app` doit-il etre exclu du prospecting froid en V1 ?
- faut-il un workflow d approbation par role avant le premier envoi ?

## Recommended Next Step
Avant implementation, valider ces 4 points:
- statut exact du prospecting dans le business
- sources autorisees en V1
- seuil de conversion `prospect -> lead`
- ecran prioritaire a livrer en premier
