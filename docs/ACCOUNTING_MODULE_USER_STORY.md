# Accounting Module - User Story

Last updated: 2026-04-14

Current implementation status:
- `Phase 0`: delivered
- `Phase 1`: delivered
- `Phase 2`: delivered
- `Phase 3`: delivered
- `Phase 4`: delivered
- `Phase 5`: delivered
- `Phase 6`: delivered
- `Phase 7+`: pending

Related docs:
- `Expenses`: `docs/EXPENSES_MODULE_USER_STORY.md`
- `Roadmap`: `docs/NEXT_HIGH_VALUE_MODULES_USER_STORY.md`

## Goal
Ajouter un module `Accounting` qui transforme les flux deja presents dans la plateforme en verite comptable lisible, controlable et exportable, afin que l owner ou le finance admin puisse:
- suivre une periode comptable
- preparer les taxes
- produire un journal fiable
- faire une cloture simple
- transmettre un package propre au comptable

## Product Vision
Le module `Accounting` ne doit pas commencer comme un ERP lourd.

La bonne approche est une `accounting bridge layer` au-dessus de:
- `Invoices`
- `Payments`
- `Sales`
- `Expenses`
- `Products`

Autrement dit:
- `Expenses` capture les sorties
- `Invoices`, `Sales` et `Payments` capturent les entrees
- `Accounting` transforme cette realite metier en ecritures, periodes, taxes et exports

Le module doit donner une vraie lecture finance, sans forcer l equipe a re-saisir dans des tableurs ce que la plateforme sait deja.

## Why This Matters
- beaucoup de petites structures gerent ventes et depenses, mais restent faibles au moment de la cloture
- les taxes deviennent vite floues si les flux ne sont pas relies
- un comptable perd du temps quand les justificatifs, references et periodes ne sont pas propres
- sans couche comptable, les dashboards restent operationnels mais pas financiers
- le produit passe a un autre niveau quand il peut expliquer `ce qui s est passe` en compta, pas seulement `ce qui a ete vendu`

## Scope
- module `accounting` first-class avec feature flag
- plan comptable simplifie
- mapping entre categories metier et comptes comptables
- moteur backend de generation d ecritures
- journal comptable consultable
- lots d ecritures relies a leur source
- synthese taxes par periode
- periodes comptables `open / in_review / closed / reopened`
- review et reconciliation legeres
- exports comptables standards
- audit log des actions finance
- experience mobile de supervision et de validation
- surfaces `superadmin` et `demo` pour que le module soit visible et testable

## Non-goals
- comptabilite exhaustive multi-pays en V1
- paie et ecritures RH
- amortissements complexes et immobilisations detaillees
- synchronisation bancaire automatique complete en V1
- rapprochement bancaire lourd en V1
- consolidation multi-entites
- edition libre et silencieuse des ecritures generees

## Dependency And Sequencing
- `accounting` doit dependre du module `expenses`
- `accounting` reutilise aussi `sales`, `invoices`, `payments`, `products`
- `expenses` peut vivre seul
- `accounting` ne doit pas etre activable si les bases `expenses` et `finance approvals` ne sont pas stables
- sequence recommandee:
  1. `Expenses`
  2. `Accounting`

## Current Baseline In This Repo
- les briques revenus sont deja solides: `Sales`, `Invoices`, `Payments`, `Quotes`, `Work`
- le module `Expenses` existe maintenant comme module first-class avec:
  - intake manuel
  - scan IA
  - recurrence
  - remboursements
  - liens metier
  - reporting
  - approvals partages avec `Invoices`
- `Company settings` expose deja une couche finance:
  - devise business
  - approbations finance
  - seuils et roles
- le repo sait deja gerer:
  - exports CSV
  - audit trails metier
  - gating par feature flags et plans
  - surfaces `superadmin` et `demo`
- ce qui manque encore:
  - aucun module `accounting` first-class dans les plans et feature flags
  - aucune table ou modele de journal comptable
  - aucun ecran `Accounting`
  - aucune periode comptable
  - aucune synthese taxes centralisee
  - aucun export comptable structure comme produit dedie

## Core Product Proposal
Le module `Accounting` doit etre construit autour de 7 blocs.

### 1. Chart of accounts
Un plan comptable simple mais utile pour V1:
- revenus
- comptes clients
- encaissements
- taxes collectees
- depenses d exploitation
- achats et couts directs
- remboursements
- comptes d attente simples

### 2. Event-to-entry engine
Le systeme doit generer des ecritures a partir d evenements metier fiables:
- emission de facture
- encaissement
- vente comptoir
- depense approuvee ou payee
- remboursement
- ajustement controle si necessaire

### 3. Journal and batches
Les ecritures doivent etre lisibles dans un journal:
- filtre par periode
- filtre par source
- filtre par compte
- filtre par statut review / reconciliation
- regroupement par lot d origine

### 4. Tax center
Le module doit aider a suivre:
- taxes collectees
- taxes payees
- ecarts a verifier
- periodes concernees
- export des lignes utiles au comptable

### 5. Period discipline
Le module doit introduire une hygiene finance:
- `open`
- `in_review`
- `closed`
- `reopened`

Une periode cloturee ne doit plus deriv­er silencieusement.

### 6. Accountant handoff
Le produit doit rendre la transmission comptable simple:
- export par periode
- references de source
- pieces justificatives reliees
- moins de retraitement manuel

### 7. Mobile supervision
Le mobile ne doit pas refaire toute la compta.
Il doit permettre:
- lecture des KPI finance
- revue rapide
- actions de supervision owner / finance
- verification de periode et d alertes

## Suggested Data Model
Le design recommande pour V1:

### `accounting_accounts`
- `id`
- `user_id`
- `code`
- `name`
- `type`
- `is_system`
- `is_active`
- `sort_order`

### `accounting_mappings`
- `id`
- `user_id`
- `source_domain`
- `source_key`
- `debit_account_id`
- `credit_account_id`
- `tax_account_id`
- `meta`

### `accounting_entry_batches`
- `id`
- `user_id`
- `source_type`
- `source_id`
- `source_reference`
- `period_id`
- `generated_at`
- `status`
- `meta`

### `accounting_entries`
- `id`
- `user_id`
- `batch_id`
- `account_id`
- `direction`
- `amount`
- `tax_amount`
- `currency_code`
- `entry_date`
- `description`
- `review_status`
- `reconciliation_status`
- `locked_at`
- `meta`

### `accounting_periods`
- `id`
- `user_id`
- `period_key`
- `start_date`
- `end_date`
- `status`
- `closed_at`
- `closed_by`
- `reopened_at`
- `reopened_by`
- `meta`

### `accounting_exports`
- `id`
- `user_id`
- `period_id`
- `format`
- `status`
- `path`
- `generated_by`
- `generated_at`

## Primary User Story

### US-ACC-001 - Turn operations into accounting truth
As an owner or finance admin,
I want the platform to transform daily operations into structured accounting data,
so month-end and tax preparation stop depending on manual spreadsheets.

Acceptance criteria:
- the module reads operational events from revenue and expense domains
- entries are generated through backend rules, not client-side inference
- each entry stays traceable to its source
- finance access stays restricted to authorized roles

## Supporting User Stories

### US-ACC-002 - Maintain a simple chart of accounts
As a finance operator,
I want a manageable chart of accounts,
so the system can classify financial reality consistently.

Acceptance criteria:
- the module ships with a safe default account set
- the owner can activate, deactivate, and reorder accounts
- destructive changes are restricted when entries already exist
- system accounts stay protected

### US-ACC-003 - Map business events to accounting accounts
As a finance operator,
I want business categories and events mapped to accounts,
so accounting outputs stay consistent and exportable.

Acceptance criteria:
- expense categories can map to default accounts
- core invoice, payment, and sale events have default mappings
- mappings are editable only by authorized finance roles
- missing mappings surface a review state instead of silent bad output

### US-ACC-004 - Generate accounting entries automatically
As an owner,
I want invoices, payments, sales, and expenses to generate accounting traces automatically,
so I do not enter the same reality twice.

Acceptance criteria:
- entry batches are created from trusted domain events
- each batch references `source_type` and `source_id`
- generated entries are auditable
- manual adjustments, when introduced later, stay clearly separated

### US-ACC-005 - Review a journal by period
As a finance admin,
I want a filterable accounting journal,
so I can review what happened in a selected period without digging through multiple modules.

Acceptance criteria:
- filters support period, source, account, and review status
- the journal opens back to the source record
- totals can be grouped by account or source
- large result sets are paginated or progressively loaded

### US-ACC-006 - Review tax summaries by period
As an owner or accountant,
I want a tax summary by period,
so I can prepare filings and verify what is due.

Acceptance criteria:
- the module shows taxes collected and taxes paid by selected period
- tax summaries use stored transactional data
- discrepancies can be isolated for review
- exports preserve source references

### US-ACC-007 - Close a month safely
As an owner,
I want to close a month after review,
so the accounting view of that period stops drifting.

Acceptance criteria:
- a period can move through `open`, `in_review`, `closed`, `reopened`
- closing a period requires authorization
- closed periods protect against silent regeneration drift
- close and reopen actions are audited

### US-ACC-008 - Follow lightweight reconciliation
As a finance operator,
I want a simple reconciliation state,
so I can see what has been checked and what still needs review.

Acceptance criteria:
- entries or batches can be marked `unreviewed`, `reviewed`, or `reconciled`
- reconciliation state is visible in period summaries
- actor and timestamp are preserved

### US-ACC-009 - Export a clean accountant package
As an owner,
I want a structured accounting export,
so my accountant can work from a cleaner package.

Acceptance criteria:
- exports support CSV in V1
- export can be scoped by period
- exported rows preserve account, amount, tax, source, and reference metadata
- linked internal ids or file references remain available

### US-ACC-010 - Keep accounting access separate from operations
As an owner,
I want finance permissions clearly separated from daily operational permissions,
so accounting data stays safe.

Acceptance criteria:
- non-finance users cannot browse accounting screens by default
- source modules still generate traces even when users cannot see accounting
- server-side permissions control every screen and action

## Accounting Status Model
Le module doit garder ses propres statuts, distincts des statuts metier sources.

### Entry review status
- `unreviewed`
- `reviewed`
- `reconciled`

### Period status
- `open`
- `in_review`
- `closed`
- `reopened`

Important:
- `invoice.status`, `invoice.approval_status`, `expense.status`, etc. restent dans leur domaine
- `accounting` lit ces flux, mais ne les remplace pas

## Business Rules
- `accounting` ne doit pas etre activable sans `expenses`
- les ecritures generees doivent toujours rester reliees a leur source
- aucun calcul comptable critique ne doit vivre seulement dans le frontend
- une periode `closed` ne doit pas accepter de drift silencieux
- les exports doivent etre reproductibles a partir des donnees stockees
- les actions finance sensibles doivent toutes laisser un audit trail

## Solo vs Team Rules

### Solo
- acces owner-only par defaut
- pas de separation de roles complexe obligatoire
- review et cloture simplifiees
- experience mobile centree owner

### Team
- roles finance dedies possibles
- separation claire entre operationnel et finance
- revue et reconciliation partagees
- cloture reservee a l owner ou au finance admin

## Permissions And Roles
- owner: acces complet
- finance_admin: acces complet hors parametres globaux les plus sensibles si besoin
- finance_reviewer: lecture, review, reconciliation, export selon role
- accountant_readonly: lecture et export futur
- operator standard: pas d acces par defaut

## Module And Plan Strategy
- introduire un module `accounting`
- `accounting` depend du module `expenses`
- `accounting` doit etre backend-gated par feature flags et capabilities
- `superadmin` doit pouvoir l activer et le voir dans les settings, les tenants, les demos
- recommandation produit:
  - `solo`: version owner-only possible
  - `team`: version complete avec roles finance
  - plans basiques: pas d `accounting` en V1

## Screens And UX Proposal

### Web V1
- `Accounting / Dashboard`
- `Accounting / Journal`
- `Accounting / Accounts and mappings`
- `Accounting / Taxes`
- `Accounting / Periods`
- `Accounting / Exports`

### Mobile V1
- `Accounting / Summary`
- `Accounting / Period detail`
- `Accounting / Journal review`
- `Accounting / Alerts and pending review`

## Mobile Experience
Le module `Accounting` doit avoir une vraie experience mobile utile, mais sans essayer de refaire toute la compta lourde sur petit ecran.

### Mobile goals
- supervision financiere rapide
- alertes de periode et de taxes
- lecture des chiffres clefs
- validation owner ou finance admin en deplacement

### Mobile V1 expectations
- ecran resume `cash in / cash out / taxes / open periods / unreconciled`
- detail de periode avec statut `open / in_review / closed`
- consultation d un journal filtre avec lecture confortable
- action mobile pour `mark reviewed`, `mark reconciled`, `close period` si autorise
- acces rapide aux exports deja generes

### Mobile constraints
- edition avancee du plan comptable reste web-first en V1
- mappings comptables complexes restent web-first
- mobile ne doit jamais recalculer lui-meme la logique comptable
- les ecrans mobiles doivent se baser sur des contrats backend stables

## Delivery Plan

### Phase 0 - Prerequisites and module plumbing
- ajouter le module `accounting` dans les feature flags et plans
- brancher `superadmin`, `demo`, `tenant labels`, navigation, permissions
- figer les champs minimums utilises par `expenses`, `invoices`, `payments`, `sales`
- definir les premiers comptes systeme et conventions de mapping

Phase 0 delivery notes:
- module `accounting` expose dans feature flags, billing defaults, `superadmin`, `demo`, labels tenant et navigation
- dependance `accounting -> expenses` enforcee serveur
- page `Accounting` V0 disponible en web et API comme point d entree safe
- premier fichier `config/accounting.php` cree pour figer comptes systeme et conventions de mapping

### Phase 1 - Accounting bridge foundation
- tables `accounts`, `mappings`, `entry_batches`, `entries`
- generation des ecritures depuis `Invoices`, `Payments`, `Sales`, `Expenses`
- journal comptable consultable
- liens vers les sources metier

Phase 1 delivery notes:
- tables `accounting_accounts`, `accounting_mappings`, `accounting_entry_batches` et `accounting_entries` creees
- bootstrap automatique des comptes systeme et mappings par owner workspace
- generation serveur des lots/ecritures depuis `Invoices`, `Payments`, `Sales` et `Expenses`
- support des depenses remboursables avec lot de charge puis lot de remboursement
- journal `Accounting` disponible en web et API avec filtres `period / source / account / review status`
- regroupements lisibles par compte et par source, avec liens vers les sources metier

### Phase 2 - Tax and export layer
- synthese taxes par periode
- exports CSV
- references documentaires et audit
- premier package `accountant handoff`

Phase 2 delivery notes:
- synthese taxes disponible en web et API avec `taxes collected / taxes paid / net tax due`
- lecture des lignes taxe encore a revoir pour isoler les anomalies avant declaration ou handoff
- table `accounting_exports` ajoutee pour garder un historique des exports generes
- export CSV comptable reproductible depuis le scope filtre courant
- telechargement des exports deja generes depuis l historique recent
- audit trail sur la generation des exports comptables

### Phase 3 - Period discipline
- table `accounting_periods`
- workflow `open / in_review / closed / reopened`
- verrouillage leger des periodes
- audit log des clotures et reouvertures

Phase 3 delivery notes:
- table `accounting_periods` ajoutee avec statuts `open / in_review / closed / reopened`
- timeline recente des periodes visible dans l ecran `Accounting`
- actions web et API pour `open`, `in review`, `close` et `reopen`
- audit trail des transitions de periode
- la synchro comptable ne regenere plus silencieusement les lots d une periode `closed`
- reouverture d une periode permet de reprendre la synchro normale sur ce mois

### Phase 4 - Review and reconciliation
- statuts `unreviewed / reviewed / reconciled`
- filtres de journal par review status
- vues de lots a verifier
- actions de review/reconciliation cote web et mobile

Phase 4 delivery notes:
- workspace de revue visible dans l ecran `Accounting` avec compteurs `unreviewed / reviewed / reconciled`
- actions web et API pour marquer une ecriture ou un lot `unreviewed`, `reviewed` ou `reconciled`
- audit trail sur les transitions de revue et de rapprochement
- les statuts de revue survivent maintenant aux resynchronisations du journal quand le lot source reste coherent
- separation permissionnelle entre simple lecture comptable et gestion/reconciliation

### Phase 5 - Mobile supervision
- dashboard mobile finance
- journal mobile ergonomique
- detail de periode mobile
- alertes et actions owner / finance admin

Phase 5 delivery notes:
- contrat backend `mobile_summary` expose pour garder les ecrans mobiles stables cote web et API
- board mobile finance ajoute avec `cash in / cash out / net tax / open periods / unreconciled / pending batches`
- alertes mobiles prioritaires pour revue en attente, periodes actives, position taxe et export manquant
- journal mobile rendu en cartes lisibles avec actions `reviewed / reconciled` quand autorise
- sections avancees `accounts / mappings` gardees web-first pour ne pas surcharger le mobile

### Phase 6 - Cross-cutting demo and superadmin enablement
- module visible dans `superadmin`
- support `demo workspace`
- seed comptable minimal pour demos
- snapshot finance lisible depuis les surfaces admin/demo

Phase 6 delivery notes:
- `accounting` reste visible dans `superadmin`, `tenant labels`, `demo builder` et `demo account` surfaces
- `demo workspace provisioner` synchronise maintenant le journal comptable quand le module `accounting` est selectionne
- `seed_summary` expose des compteurs comptables utiles pour la QA demo: `accounting_entries`, `accounting_batches`, `accounting_review_required_batches`, `accounting_active_periods`, `accounting_exports`
- la fiche detail `superadmin / demo workspace` affiche ces compteurs dans le bloc `Finance snapshot`
- la description du module demo `Accounting` est alignee avec le scope reel livre (`journal + taxes + review + periods + mobile supervision`)

## Definition Of Done
- la plateforme peut transformer flux revenus + depenses en donnees comptables lisibles
- un owner peut suivre une periode, ses taxes, son journal, et son etat de cloture
- chaque ecriture garde une trace de sa source
- les exports reduisent clairement le bricolage comptable
- le module respecte les feature flags, permissions, dependances et plans
- mobile permet la supervision financiere essentielle sans imposer le desktop pour chaque verification

## Recommended Starting Point
Si on demarre l implementation maintenant, le meilleur premier slice est:
1. `Phase 0`
2. `Phase 1`
3. un journal simple avec generation d ecritures depuis `Expenses`, `Invoices` et `Payments`

Pourquoi:
- c est la premiere vraie valeur comptable
- ca reutilise directement le socle deja livre
- ca pose les contrats backend qui serviront au reste du module
