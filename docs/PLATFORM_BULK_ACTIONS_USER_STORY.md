# Platform Bulk Actions - User Story

Last updated: 2026-04-13

## Goal
Ajouter un systeme d actions de masse reutilisable sur les DataTables de la plateforme afin de permettre aux equipes d appliquer une action a plusieurs enregistrements en une seule operation, de facon rapide, sure et coherente.

## Product Vision
Aujourd hui, plusieurs modules ont deja une selection de lignes, mais les actions bulk restent:
- limitees a quelques ecrans
- heterogenes d un module a l autre
- peu extensibles
- souvent decouplees de la pagination, des filtres et des permissions metier

Le but est de faire passer la plateforme d un ensemble d actions bulk locales a un vrai cadre partage:
- meme pattern de selection
- meme emplacement des actions
- meme comportement de confirmation
- meme gestion des succes/erreurs
- meme logique de permissions
- meme auditabilite

Le composant partage doit permettre d ajouter rapidement une action bulk sur un module sans reinventer a chaque fois la selection, la validation, la confirmation, le reporting resultat et la gestion des cas partiels.

## Why This Matters
- les utilisateurs operationnels travaillent rarement ligne par ligne
- les equipes perdent du temps sur des operations repetitives simples
- les modules semblent incoherents quand certains ecrans ont des actions bulk et d autres non
- sans cadre partage, chaque nouvelle bulk action recree de la dette technique
- la valeur de la DataTable reutilisable augmente fortement si elle supporte aussi la selection multi-lignes

## Scope
- selection de plusieurs lignes dans les DataTables compatibles
- selection de la page courante
- affichage du nombre d elements selectionnes
- barre ou menu d actions bulk standardise
- confirmations avant actions sensibles
- execution synchrone ou asynchrone selon le volume et l action
- resultat detaille: succes total, succes partiel, echecs
- permissions et audit logs
- compatibilite avec filtres, pagination et `per_page`

## Non-goals
- supporter en V1 une selection globale inter-modules
- permettre un "undo" universel pour toutes les actions destructives
- lancer en V1 des bulk actions sur des experiences non tabulaires comme kanban, calendrier ou board live
- remplacer les workflows tres specialises deja embarques dans certains modules hybrides

## Principles
- reuse du shell `AdminDataTable`
- reuse des permissions et policies existantes
- separation claire entre selection UI et execution metier
- confirmations explicites pour les actions destructives ou irreversibles
- retour utilisateur clair en cas de succes partiel
- audit obligatoire pour toute action bulk metier significative
- traitement idempotent et robuste aux retries si l execution passe en queue

## Current Baseline (already in code)
- le socle `AdminDataTable` est en place sur une grande partie des listes standard
- une couche partagee de selection multi-lignes existe maintenant via le composable de selection et la bulk bar partagee
- `Customer`, `Product` et `Request` utilisent deja le meme pattern de selection et de barre d actions bulk
- la selection persiste maintenant entre les pages de pagination
- la checkbox de header fonctionne sur la page courante avec etat indetermine
- les actions bulk ne s affichent que lorsqu au moins une ligne est selectionnee
- des actions bulk metier existent deja de facon operationnelle:
  - `customer.bulk`
  - `product.bulk`
  - `request.bulk`
  - `campaigns.prospects.bulk-status`
- le module `Customer` va deja plus loin que le simple changement d etat avec une action `Contact selected`
- le module `Customer` sait maintenant aussi sauvegarder la selection comme mailing list et ouvrir `Campaigns` avec une audience pre-remplie

## Current Implementation Status

### Delivered
- selection partagee pour les DataTables compatibles
- bulk bar partagee et coherente au niveau UI
- persistance de la selection sur changement de page
- correction du bug premier rendu sur l ouverture des actions bulk dans `Customer`
- premiere action bulk a forte valeur metier livree: `Contact selected` dans `Customer`
- premier bridge bulk vers un workflow avance livre: `Customer -> mailing list -> Campaigns`

### In progress
- uniformiser le contrat backend de resultat bulk sur tous les modules
- sortir un vrai registre d actions bulk par module au lieu de menus encore cables localement
- mutualiser le feedback de succes partiel et d erreurs au niveau plateforme

### Remaining gaps
- le contrat `processed_count / success_count / failed_count / skipped_count / errors` n est pas encore applique de facon homogene sur tous les bulk handlers
- le cadre d audit et de permissions est encore partiellement local selon le module
- la documentation technique d enregistrement d une nouvelle action bulk reste a formaliser
- la couverture tests automatique est encore insuffisante

## Core Product Proposal
Introduire un cadre commun compose de 4 briques:

1. Une selection standard au niveau DataTable
- checkbox par ligne
- checkbox header pour la page courante
- compteur des elements selectionnes
- reset automatique apres succes ou refresh

2. Un registre d actions par module
- chaque module declare les actions bulk supportees
- chaque action expose son libelle, son niveau de risque, ses permissions et son mode d execution
- le shell partage se contente d afficher les actions autorisees et d envoyer la charge utile

3. Un contrat de resultat commun
- `processed_count`
- `success_count`
- `failed_count`
- `skipped_count`
- liste des erreurs par element ou par motif

4. Un cadre de confirmations et feedback
- confirmation obligatoire pour les actions destructives
- resume de l operation avant execution si besoin
- toast ou banner de resultat apres execution
- possibilite d ouvrir un detail d erreurs si l operation est partiellement reussie

## Primary User Story

### US-BULK-001 - Run a supported action on selected rows
As an operational user, I want to select multiple rows in a table and run a supported action in one step so I can process work faster without repeating the same click pattern.

Acceptance criteria:
- the DataTable supports multi-row selection on compatible modules
- the user sees how many rows are selected
- bulk actions become visible only when at least one row is selected
- selecting rows across the current page does not break filters, sorting, or pagination
- after a successful bulk action, the table refreshes and the selection resets

## Supporting User Stories

### US-BULK-002 - Select the current page easily
As a user, I want to select all visible rows on the current page so I can act on the list I am currently reviewing.

Acceptance criteria:
- the header checkbox selects all visible rows on the current page
- unchecking it clears the page selection
- the checkbox supports indeterminate state when only some rows are selected

### US-BULK-003 - See only valid actions
As a user, I want to see only the bulk actions I am allowed to run so I do not get blocked by actions I cannot use.

Acceptance criteria:
- action visibility respects permissions and feature flags
- modules can hide actions depending on dataset or page context
- dangerous actions are visually distinct

### US-BULK-004 - Get pre-validation before execution
As a user, I want the system to warn me when some selected rows are not eligible so I understand what will happen before launching the action.

Acceptance criteria:
- modules can validate eligibility before execution
- the user can see whether the action will process all, some, or none of the selected rows
- the confirmation dialog can explain exclusions or warnings

### US-BULK-005 - Confirm destructive operations
As a user, I want a confirmation step before destructive or hard-to-reverse actions so I do not trigger accidental changes.

Acceptance criteria:
- delete, archive, irreversible status changes, or sends to customers require confirmation
- confirmation copy includes selected count
- when relevant, the confirmation mentions the business impact

### US-BULK-006 - Handle long-running actions safely
As a user, I want heavy bulk actions to run asynchronously so the interface remains responsive and the operation remains reliable.

Acceptance criteria:
- modules can mark an action as queued
- queued bulk actions return a pending/scheduled feedback state
- idempotency and retry-safe execution are documented for queued handlers

### US-BULK-007 - Understand partial success
As a user, I want clear feedback when only part of the selection succeeds so I can decide what to do next.

Acceptance criteria:
- the result summary exposes processed, succeeded, failed, and skipped counts
- the user can understand the main failure reasons
- the table refresh reflects successful changes even when some rows fail

### US-BULK-008 - Keep an audit trail
As an admin or compliance lead, I want bulk actions to be auditable so operational changes can be traced later.

Acceptance criteria:
- each bulk action logs actor, action type, target scope, and timestamp
- high-risk actions log identifiers or summary metadata of impacted records
- queued actions preserve who initiated the request

### US-BULK-009 - Reuse the same pattern across modules
As a platform team, I want a shared bulk actions framework so new modules can adopt it without duplicating selection and action plumbing.

Acceptance criteria:
- the platform exposes a shared UI pattern for bulk selection and action menus
- modules plug into a small contract rather than rebuilding the whole feature
- DataTable migrations can opt into bulk actions incrementally

## Action Families To Support

### Safe state changes
- enable or disable a flag
- archive or restore
- move a workflow status when the business rule is simple

### Ownership and classification
- assign a team member
- attach a tag
- move to a category or list

### Communication-oriented
- relance selected customers
- add selected customers to a mailing list
- send a bulk reminder or notification

### Destructive or sensitive
- delete
- mark as do not contact
- revoke access

## Business Rules
- a bulk action must always remain tenant-scoped
- the action handler must re-check permissions server-side for each impacted record or through an equivalent trusted query
- modules may reject mixed selections when business logic requires homogeneous records
- the frontend must never assume every selected row is eligible
- a queued action must preserve a snapshot of selected IDs or a trusted filtered scope payload

## UX Expectations
- selection is visible but does not pollute the table when nothing is selected
- action wording is business-oriented, not technical
- count and confirmation copy are explicit
- the result state should be understandable in less than 5 seconds

## Delivery Plan

### Phase 1 - Shared bulk framework
- done: add shared selection contract on top of the reusable DataTable
- done: standardize selected count and action area
- done: migrate `Customer`, `Product`, and `Request` onto the shared selection pattern
- remaining: finish standardizing the backend result contract across those modules

### Phase 2 - High-value operational actions
- done: add customer communication bulk entry points in `Customer`
- remaining: add reusable assignment / tagging hooks where relevant
- remaining: add better partial-failure feedback at shared UI level

### Phase 3 - Advanced scope handling
- support "all rows on current filtered result" when technically justified
- support queued progress tracking for heavy bulk jobs
- done: add an advanced customer adapter to save a selection as mailing list and hand off into `Campaigns`
- remaining: add more advanced adapters per module

## Technical Notes
- prefer a module-level registry or adapter pattern instead of hardcoding action names in the DataTable
- keep the DataTable generic: selection and action surface belong to the shell, business execution stays in controllers/services
- use common payload shape:
  - `action`
  - `ids`
  - optional `context`
- use common response shape:
  - `message`
  - `processed_count`
  - `success_count`
  - `failed_count`
  - `skipped_count`
  - `errors`

## Test Strategy
- unit: action registry visibility, payload shaping, server-side result formatting
- feature: bulk success, partial success, permission denial, empty selection rejection
- UI smoke: selection, select-all page, confirmation, feedback reset

## Done Definition
- a shared bulk actions framework exists on top of the reusable DataTable
- at least 3 modules use the same selection and result pattern
- destructive actions have explicit confirmation
- result summaries support partial success
- audit logging is documented and active for high-risk actions
- technical documentation explains how new modules register a bulk action

## Remaining Work Before We Can Call This Story Closed
- finish the shared backend result contract across `Customer`, `Product`, and `Request`
- extract a reusable module action registry / adapter pattern
- add a shared result feedback surface for partial success and per-item failures
- complete audit and permission parity for every high-risk bulk action
- add automated feature and UI smoke coverage for the shared flow
