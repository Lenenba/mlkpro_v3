# Accounting Module - User Story

Last updated: 2026-04-14

## Goal
Ajouter une couche `Accounting` au-dessus des revenus et des depenses afin de transformer les operations quotidiennes en verite financiere exploitable pour:
- cloture mensuelle
- preparation comptable
- suivi taxes
- collaboration avec un comptable

## Product Vision
Le module `Accounting` ne doit pas commencer comme un ERP complet et lourd.

La bonne approche est une `accounting bridge layer`:
- relier `Sales`, `Invoices`, `Payments`, `Products` et `Expenses`
- mapper les flux vers une structure comptable simple
- produire des journaux, syntheses taxes et exports fiables
- permettre a l owner de comprendre la situation sans sortir dans des fichiers bricolages

Autrement dit:
- `Expenses` capture la sortie d argent
- `Accounting` organise cette realite pour la cloture et la lecture financiere

## Why This Matters
- beaucoup de petites entreprises vendent, encaissent et depensent sans avoir une lecture finance propre
- la preparation pour le comptable est souvent manuelle et repetitive
- les taxes deviennent vite un point de friction si les categories et paiements ne sont pas relies
- sans couche comptable, les dashboards restent operationnels mais pas financiers
- la plateforme gagne un vrai niveau de maturite en passant de gestion metier a pilotage financier

## Scope
- plan comptable simplifie
- mapping entre categories metier et comptes comptables
- ecritures generees depuis `Invoices`, `Payments`, `Sales` et `Expenses`
- journal comptable consultable
- synthese taxes
- cloture mensuelle simple
- exports comptables
- statut de rapprochement leger
- audit et verrouillage de periode
- experience mobile pour lecture, validation, alertes et supervision

## Non-goals
- comptabilite exhaustive multi-pays en V1
- paie et ecritures RH
- amortissements complexes et immobilisations detaillees
- synchronisation bancaire automatique complete en V1
- consolidation multi-societes
- edition libre de toutes les ecritures par tous les utilisateurs

## Dependency And Sequencing
- `Accounting` doit etre pense comme dependant de `Expenses`
- `Accounting` reutilise aussi `Sales`, `Invoices`, `Payments`, `Products` et leurs signaux existants
- la priorite de livraison recommande est:
  1. `Expenses`
  2. `Accounting`

## Current Baseline In This Repo
- les modules revenus sont deja presents et matures: `Sales`, `Invoices`, `Payments`, `Quotes`
- `Product` expose deja des signaux simples de cout et marge
- aucune couche `Accounting` structuree n existe encore
- les plans et feature flags ne connaissent pas encore un module `accounting`
- le futur module `Expenses` doit devenir une des entrees fondatrices de cette couche

## Core Product Proposal
Le module `Accounting` doit etre structure autour de 5 blocs.

### 1. Lightweight chart of accounts
Un plan comptable simple mais suffisant pour V1:
- revenus
- taxes collectees
- encaissements
- depenses d exploitation
- achats stock / couts directs
- remboursements
- comptes d attente simples si necessaire

### 2. System-generated entries
Le systeme doit produire des ecritures a partir des flux applicatifs:
- emission de facture
- encaissement paiement
- vente produit
- depense approuvee ou payee
- remboursement

### 3. Tax-ready summaries
Le module doit aider a voir:
- taxes collectees
- taxes payees
- ecarts ou montants a verifier
- periodes concernees

### 4. Month-end discipline
Le module doit introduire une hygiene simple:
- periode ouverte
- periode en revue
- periode cloturee
- verrouillage leger apres cloture

### 5. Accountant handoff
Le produit doit simplifier la transmission au comptable:
- export standardise
- pieces justificatives reliees
- historique clair
- moins de retraitements manuels

## Primary User Story

### US-ACC-001 - Turn operations into accounting truth
As an owner or finance admin,
I want the platform to transform daily operations into structured accounting data,
so month-end and tax preparation stop depending on manual spreadsheets.

Acceptance criteria:
- the module can read operational events from revenue and expense domains
- entries are generated through trusted backend rules, not client-side inference
- each entry stays traceable to its operational source
- access is limited to authorized finance roles

## Supporting User Stories

### US-ACC-002 - Maintain a simple account mapping
As a finance operator,
I want categories and business events mapped to accounting accounts,
so exports and summaries stay consistent.

Acceptance criteria:
- the system exposes a manageable chart of accounts in V1
- expense categories can map to accounting accounts
- core revenue events have default mappings
- mappings are editable by authorized users only

### US-ACC-003 - Generate journal entries automatically
As an owner,
I want invoices, payments, sales, and expenses to create accounting traces automatically,
so I do not re-enter the same reality twice.

Acceptance criteria:
- journal entries are generated from trusted domain events
- each entry references its source entity and source type
- generated entries cannot be silently edited without audit
- the system distinguishes generated entries from future manual adjustments

### US-ACC-004 - Review tax summaries by period
As an owner or accountant,
I want a tax summary by period,
so I can prepare filings and verify what is due.

Acceptance criteria:
- the module shows taxes collected and taxes paid by selected period
- the summary can be filtered by open or closed periods
- tax calculations use stored transactional data, not frontend approximations
- the result can be exported

### US-ACC-005 - Close a month safely
As an owner,
I want to close a month after review,
so the accounting view of that period stops drifting.

Acceptance criteria:
- a period can move through `open`, `in_review`, and `closed`
- closing a period requires permission
- closed periods restrict sensitive edits or require explicit reopen flow
- the close action is audited

### US-ACC-006 - Export data for an accountant
As an owner,
I want a structured accounting export,
so my accountant can work from a cleaner package.

Acceptance criteria:
- exports support at least CSV in V1
- export can be filtered by period
- exported rows preserve account, amount, tax, source, and reference metadata
- export can reference linked justification documents or internal ids

### US-ACC-007 - Follow reconciliation status lightly
As a finance operator,
I want a lightweight reconciliation state,
so I can see what has been checked versus what still needs review.

Acceptance criteria:
- entries or batches can be marked `unreviewed`, `reviewed`, or `reconciled`
- reconciliation status is visible in period summaries
- actor and timestamp are preserved for reconciliation actions

### US-ACC-008 - Separate operational users from finance users
As an owner,
I want finance permissions clearly separated from daily operational permissions,
so accounting data stays safe.

Acceptance criteria:
- non-finance users cannot browse accounting screens by default
- generated accounting traces still exist even when the user cannot see them
- role and plan restrictions are enforced server-side

## Mobile Experience
Le module `Accounting` doit avoir une experience mobile utile, mais pas essayer de refaire toute la compta lourde sur petit ecran.

### Mobile goals
- supervision financiere rapide
- alertes de periode et de taxes
- lecture des chiffres clefs
- validation owner ou finance admin en deplacement

### Mobile V1 expectations
- ecran resume `cash in / cash out / taxes / overdue / unreconciled`
- detail de periode avec statut `open / in_review / closed`
- consultation d un journal filtre avec lecture confortable
- action mobile pour `mark reviewed`, `approve`, `close period` si autorise
- partage ou telechargement simple des exports deja generes

### Mobile constraints
- edition avancee du plan comptable reste web-first en V1
- ecriture manuelle complexe reste web-first
- mobile ne doit jamais recalculer lui-meme la logique comptable
- les ecrans mobiles doivent se baser sur des contrats backend stables

## Business Rules
- `accounting` ne doit pas etre activable sans base de donnees fiable pour `expenses`
- les ecritures generees doivent rester tracees a leur source
- la cloture de periode doit laisser un audit trail complet
- les permissions finance doivent etre distinctes des permissions commerciales ou operationnelles
- les resumes taxes doivent utiliser les donnees de transaction stockees

## Permissions And Roles
- owner: acces complet
- finance admin: acces complet hors configuration globale sensible selon role
- operator standard: pas d acces par defaut
- comptable externe: lecture / export futur selon role dedie

## Module And Plan Strategy
- introduire un module `accounting`
- `accounting` depend du module `expenses`
- `expenses` peut exister seul
- `accounting` doit etre backend-gated par feature flags et capabilities explicites
- sur mobile comme sur web, la visibilite doit venir du backend et jamais du plan seul

## Delivery Plan

### Phase 0 - Prerequisite
- livrer `Expenses` comme source fiable de depenses
- figer les categories utiles et les champs fiscaux necessaires

### Phase 1 - Accounting bridge foundation
- plan comptable simple
- mappings par categorie et par evenement
- generation des ecritures depuis revenus et depenses
- journal consultable

### Phase 2 - Tax and export layer
- synthese taxes
- exports par periode
- references documentaires et audit

### Phase 3 - Period discipline and mobile supervision
- workflow `open / in_review / closed`
- verrouillage leger
- lecture mobile des chiffres clefs
- alertes et actions mobiles de supervision

### Phase 4 - Deeper accounting capabilities
- rapprochement plus riche
- ajustements manuels controles
- integrations comptables futures si la demande le justifie

## Definition Of Done
- la plateforme peut transformer flux revenus + depenses en donnees comptables lisibles
- un owner peut suivre une periode, ses taxes, et son etat de cloture
- le journal preserve la source de chaque ecriture
- les exports sont assez propres pour reduire le bricolage comptable
- mobile permet la supervision financiere essentielle sans imposer le desktop pour chaque verification
- le module respecte strictement les feature flags, permissions et dependances

