# Customer Bulk Outreach and Follow-up - User Story

Last updated: 2026-04-13

## Goal
Permettre aux equipes de relancer ou contacter plusieurs clients selectionnes directement depuis le module `Customer`, par email ou SMS, sans devoir passer par le module `Campaigns` pour les cas operationnels simples.

## Product Vision
Le module `Customer` doit devenir un point d action direct, pas seulement un annuaire.

Quand un utilisateur voit un groupe de clients pertinents dans la liste `Customer`, il doit pouvoir:
- selectionner plusieurs clients
- choisir une action `Contacter`
- definir l objectif du message
- verifier les destinataires eligibles
- envoyer en lot

Le tout doit se faire depuis `Customer`, avec une UX courte et orientee action, tout en reutilisant les garde-fous existants de la plateforme pour le consentement, les templates, la traque et l audit.

## Why This Matters
- les equipes finance et relation client doivent souvent relancer plusieurs clients en attente de paiement
- les equipes commerciales veulent parfois envoyer une offre ou une annonce a une selection precise de clients sans ouvrir un workflow campagne plus lourd
- aujourd hui, on peut selectionner des clients en masse, mais pas encore les relancer en masse
- la valeur du module `Customer` augmente fortement s il devient un vrai poste de pilotage relationnel

## Scope
- bulk action `Contact selected` dans `Customer`
- envoi en lot a partir de la selection courante
- canaux initiaux:
  - `email`
  - `sms`
- 2 modes fonctionnels initiaux:
  - relance paiement / suivi facture
  - communication promotionnelle ou annonce simple
- preview des destinataires eligibles
- templates ou message libre
- respect du consentement, des opt-out et des canaux disponibles
- journalisation et suivi du resultat d envoi

## Non-goals
- remplacer le module `Campaigns` pour les campagnes marketing complexes
- reconstruire un nouveau moteur d envoi parallele a `Campaigns`
- permettre des sequences multi-etapes ou des automatisations avancees en V1
- ouvrir en V1 la relance bulk a tous les modules de la plateforme

## Principles
- entree UX dans `Customer`, execution via des briques existantes ou compatibles avec `Campaigns`
- experience courte pour les besoins simples
- aucun bypass des regles de consentement et de compliance
- distinction claire entre communication transactionnelle et marketing
- preview et validation avant envoi
- audit de l auteur, du motif et du perimetre

## Current Baseline (already in code)
- la DataTable `Customer` supporte maintenant la multi-selection via le pattern bulk partage
- la selection persiste entre les pages de pagination
- l ouverture des actions bulk fonctionne des le premier rendu en mode table
- le module `Customer` expose deja l action `Contact selected`
- les bulk actions `Customer` existantes restent disponibles:
  - `portal_enable`
  - `portal_disable`
  - `archive`
  - `restore`
  - `delete`
- le module `Campaigns` existe deja avec:
  - templates
  - estimation audience
  - test send
  - envoi immediat ou planifie
  - permissions `campaigns.view`, `campaigns.manage`, `campaigns.send`
  - mailing lists
  - tracking et compliance
- le detail `Customer` sait deja s interfacer avec les mailing lists
- les factures supportent deja un `send-email` individuel, mais pas une relance multi-clients depuis la liste client
- le flux `Customer bulk contact` dispose deja de:
  - preview et envoi via endpoints dedies
  - objectifs `payment_followup`, `promotion` et `manual_message`
  - canaux `email` et `sms`
  - selection d un produit ou service a promouvoir
  - email brande au format Malikia
  - WYSIWYG pour le contenu email
  - champ texte simple pour le SMS
  - resume de resultat avec `success_count`, `failed_count` et `skipped_count`

## Current Implementation Status

### Delivered
- action `Contact selected` depuis `Customer`
- modal courte avec choix objectif, canal, offre et message
- preview d eligibilite avec exclus et raisons principales
- support `payment_followup`
- support `promotion`
- support `manual_message`
- support `email`
- support `sms`
- rendu email brande proche du langage visuel Malikia

### In progress
- ouvrir l objectif `announcement`
- brancher les vrais templates sauvegardes du module `Campaigns`
- renforcer les tests automatiques du flux preview + send

### Remaining gaps
- `announcement` est encore prevu par la story mais pas expose dans le flux courant
- les templates `Campaigns/Templates` ne sont pas encore branches comme source selectable
- la planification d envoi n est pas encore disponible
- la sauvegarde de selection comme mailing list ou audience seed n est pas encore disponible
- le pont explicite vers `Campaigns` pour les cas plus avances reste a faire

## Product Proposal
Ajouter une nouvelle famille d actions bulk dans `Customer`:

### A) Contact selected
Action generique lancee depuis la liste des clients selectionnes.

Le flux ouvre un panneau ou modal avec:
- objectif:
  - `payment_followup`
  - `promotion`
  - `manual_message`
  - `announcement` en prochaine iteration
- canal:
  - `email`
  - `sms`
  - `auto_best_channel` plus tard
- source du contenu:
  - template existant
  - message libre
- options:
  - piece jointe ou lien de paiement plus tard
  - planification plus tard

### B) Direct but governed
Le lancement se fait depuis `Customer`, mais le moteur doit reutiliser au maximum les briques d envoi existantes:
- templates marketing si pertinents
- garde-fous consentement
- tracking et audit
- services email/SMS existants

Autrement dit: pas besoin d ouvrir le module `Campaigns`, mais il ne faut pas non plus contourner son infrastructure utile.

## Primary User Story

### US-CUST-OUTREACH-001 - Contact selected customers directly from Customer list
As an owner, marketer, or finance operator, I want to contact selected customers directly from the Customer table so I can act immediately on a filtered customer segment without switching modules.

Acceptance criteria:
- the `Customer` DataTable exposes a bulk action `Contact selected`
- the action is available only when at least one customer is selected
- the user can choose a channel and a communication objective
- the flow opens from `Customer` and returns to `Customer` after send or cancel
- the user receives a clear result summary after execution

## Supporting User Stories

### US-CUST-OUTREACH-002 - Bulk payment follow-up for selected customers
As a finance or admin user, I want to relaunch selected customers who still have unpaid invoices so I can accelerate payment collection without handling each case manually.

Acceptance criteria:
- the user can choose an objective `payment_followup`
- the system can detect whether the selected customers have eligible open or overdue invoices
- the preview shows how many selected customers are eligible for the reminder
- the message can include invoice-aware content such as customer name, invoice reference, amount due, or payment link when available
- customers without eligible unpaid invoices are excluded with an explicit reason

### US-CUST-OUTREACH-003 - Promotional or announcement outreach without opening Campaigns
As a marketer or owner, I want to send a simple promotional or announcement message to selected customers from the Customer module so I can run a focused outreach quickly.

Acceptance criteria:
- the user can choose an objective `promotion` or `announcement`
- the user can use a saved template or write a short custom message
- the send flow can remain lightweight and does not require opening the full campaign wizard
- the communication still respects channel consent and compliance rules

### US-CUST-OUTREACH-004 - Email and SMS eligibility control
As a user, I want to know which selected customers can actually receive the chosen channel so I do not send blindly.

Acceptance criteria:
- the preview distinguishes:
  - eligible recipients
  - recipients missing destination data
  - recipients blocked by consent or opt-out
  - recipients skipped by business rules
- email requires a valid email
- SMS requires a valid phone in a sendable format
- the send action is blocked if no eligible recipient remains

### US-CUST-OUTREACH-005 - Preview the exact send scope before confirming
As a user, I want a clear preview before launching the bulk send so I understand who will be contacted and why some people are excluded.

Acceptance criteria:
- the confirmation screen shows:
  - selected count
  - eligible count
  - excluded count
  - main exclusion reasons
- the user can confirm or cancel before any send starts
- for payment follow-up, the preview can summarize impacted invoices or balances

### US-CUST-OUTREACH-006 - Use templates or a quick message
As a user, I want to choose between a template and a quick freeform message so the feature is useful for both standardized and ad hoc outreach.

Acceptance criteria:
- the user can select an existing email or SMS template when available
- the user can instead write a custom subject/body or SMS content
- required variables are validated before send
- message preview uses the current language or chosen language when applicable

### US-CUST-OUTREACH-007 - Keep marketing and transactional rules distinct
As a compliance-conscious operator, I want the system to apply the right rules depending on the nature of the outreach so the platform remains safe and coherent.

Acceptance criteria:
- `payment_followup` is treated as operational/transactional communication according to the platform rules
- `promotion` and `announcement` use marketing eligibility rules and channel consent
- the system does not let a marketing message bypass opt-out logic
- the audit trail captures the selected objective and channel

### US-CUST-OUTREACH-008 - Track outcome and failures
As a user, I want the system to tell me what happened after the send so I can trust the result and take follow-up action if needed.

Acceptance criteria:
- the result summary exposes:
  - queued or sent count
  - failed count
  - skipped count
- the UI can show the main failure reasons
- the operation is logged with actor, timestamp, channel, objective, and recipient scope

### US-CUST-OUTREACH-009 - Escalate to a full campaign when needed
As a marketer, I want a lightweight path for simple sends but an upgrade path to the Campaigns module when the use case becomes more advanced.

Acceptance criteria:
- the bulk contact flow can optionally save the current customer selection as a mailing list or campaign audience seed
- advanced campaign features remain in the `Campaigns` module
- the quick-send flow does not try to duplicate the full campaign wizard

## Recommended UX Flow

### Entry point
- user filters customers in `Customer`
- user selects one or more rows
- user opens `Bulk actions`
- user clicks `Contact selected`

### Composer step
- choose objective
- choose channel
- choose template or quick message
- optional schedule later in a later phase

### Preview step
- total selected
- eligible recipients
- excluded recipients with reasons
- message preview

### Confirmation step
- explicit send confirmation
- success banner with summary

## Business Rules
- all sends remain tenant-scoped
- the system must validate channel destination per customer at execution time
- marketing sends must respect opt-out and consent rules
- payment follow-up must only target customers with eligible open or overdue invoices when that mode is selected
- the same customer should not receive duplicate sends from accidental double submit
- heavy sends may go through queued jobs

## Proposed Permission Model
- keep the entry point inside `Customer`
- gate the communication capability with dedicated permission(s) or a strict mapping to existing permissions

Recommended approach:
- `customers.bulk_contact` for access from the customer module
- and optionally require:
  - `campaigns.send` for promotional sends
  - finance/admin capability for payment follow-up sends

This keeps the UX simple while preserving governance.

## Architecture Recommendation
- do not build a second messaging engine only for `Customer`
- add a `CustomerBulkOutreach` flow as an orchestration layer
- reuse existing email/SMS services, consent checks, templates, audit logs, and queue patterns
- record the source context as something like:
  - `source_module=customer`
  - `source_action=bulk_contact`
  - `objective=payment_followup|promotion|announcement|manual_message`

This preserves coherence and reduces future maintenance cost.

## Delivery Plan

### Phase 1 - High value operational send
- done: add `Contact selected` in `Customer`
- done: support `email`
- done: support `payment_followup`
- done: support simple template or quick message
- done: add preview with eligible/excluded counts

### Phase 2 - Promotional quick outreach
- done: add `sms`
- done: add `promotion`
- remaining: add `announcement`
- remaining: connect to reusable templates from `Campaigns`
- in progress: improve result reporting and shared feedback consistency

### Phase 3 - Bridge to advanced marketing
- optional scheduling
- save selection as mailing list
- handoff into `Campaigns` for advanced follow-up or re-use

## Success Metrics
- reduction in time needed to relaunch unpaid customers
- increase in number of operational follow-ups executed from `Customer`
- lower friction for simple targeted outreach
- low rate of support incidents related to wrong recipients or consent issues

## Test Strategy
- feature: send payment follow-up to selected eligible customers
- feature: exclude customers without valid channel destination
- feature: enforce consent for promotional sends
- feature: partial success reporting
- UI smoke: select rows, open contact action, preview, confirm, refresh result

## Done Definition
- `Customer` supports bulk contact for selected rows
- at least one operational use case and one marketing-lite use case are supported
- preview clearly explains eligibility and exclusions
- audit logging and permission checks are active
- implementation reuses shared messaging/compliance infrastructure instead of creating a parallel silo

## Remaining Work Before We Can Call This Story Closed
- expose `announcement` in the objective list and backend handling
- let the user choose a saved template from `Campaigns/Templates`
- add scheduling and a bridge to save the current selection as a mailing list or campaign seed
- add stronger feature coverage for preview, eligibility, consent, and partial-success reporting
