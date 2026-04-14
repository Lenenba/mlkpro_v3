# Expenses Module - User Story

Last updated: 2026-04-14

## Goal
Ajouter un module `Expenses` pour capturer, classer, valider, payer, rembourser et analyser les depenses d entreprise dans la meme plateforme que `Sales`, `Invoices`, `Quotes`, `Requests` et `Products`, avec une couche optionnelle `AI-assisted intake` pour lire une facture ou un recu et preparer automatiquement un brouillon de depense.

## Product Vision
Aujourd hui, la plateforme voit surtout ce qui entre:
- ventes
- factures
- paiements
- un debut de marge produit

Le module `Expenses` doit faire passer la plateforme d une vision `revenu only` a une vision `cash et marge reelle`:
- chaque depense a une preuve
- chaque sortie d argent est categoriee
- chaque frais peut etre rattache a un contexte metier
- les owners savent ce qui est deja paye, a payer, a rembourser, ou recurrent
- quand une facture ou un recu arrive, l IA peut preparer le brouillon au lieu de forcer une ressaisie manuelle

## Why This Matters
- sans depenses, le chiffre d affaires donne une vision trompeuse de la sante business
- beaucoup de petites structures perdent des recus, oublient des remboursements, ou melangent achats stock et frais d exploitation
- les activites service ont besoin de relier les couts terrain aux jobs
- les activites commerce ont besoin de relier achats, fournisseurs et marge
- ce module est la base naturelle d une future couche `Accounting`

## Scope
- creation manuelle d une depense
- creation rapide depuis recu, facture fournisseur, ou note de frais
- lecture IA d une facture PDF ou image
- extraction IA de champs structures pour pre-remplir une depense
- creation d un brouillon de depense depuis un message a l assistant avec piece jointe
- gestion des fournisseurs
- categories de depenses
- montants `subtotal / tax / total`
- statuts de suivi
- dates `expense_date / due_date / paid_date`
- pieces jointes `image / pdf / receipt`
- depenses recurrentes
- remboursements d equipe
- rattachement optionnel a `work`, `sale`, `invoice`, `customer`, `campaign`, `team_member`
- reporting simple par categorie, fournisseur, periode, statut
- exports CSV standards
- moteur d approbation financier partage entre `expenses` et `invoices`
- regles d approbation dependantes du plan `solo` ou `team`
- configuration admin des roles approbateurs et des seuils de montant
- journal d audit complet des transitions et escalades
- experience mobile explicite pour capture et suivi terrain

## Non-goals
- comptabilite en partie double complete en V1
- synchronisation bancaire automatique en V1
- paie et RH
- amortissements et immobilisations complexes
- declarations fiscales legales par pays
- consolidation multi-entites
- auto-approbation ou auto-paiement d une depense critique sans revue humaine

## Current Baseline In This Repo
- la plateforme dispose deja d un socle solide de revenus avec `Sales`, `Invoices`, `Payments`, `Quotes` et `Requests`
- `Product` sait deja stocker un `cost_price` et calculer une marge simple
- un repertoire `Supplier` existe deja pour une partie des besoins commerce
- le repo dispose deja de patterns `Assistant` et `Plan Scan` qui montrent comment faire `piece jointe -> analyse IA -> brouillon metier -> revue`
- il n existe pas encore de vrai module `Expenses` avec statuts, workflow, pieces jointes, reporting et permissions dedies
- `expenses` n existe pas encore comme module first-class dans les feature flags et plans

## Core Product Proposal
Le module `Expenses` doit etre construit autour de 6 blocs:

### 1. Expense record
Une depense represente une sortie d argent ou une charge constatee avec:
- libelle
- categorie
- fournisseur
- montant HT / taxe / TTC
- devise
- date de depense
- date d echeance
- statut
- mode de paiement
- reference interne ou fournisseur

### 2. Proof first
Une depense utile doit avoir une preuve facilement retrouvable:
- photo de recu
- PDF de facture fournisseur
- note interne
- metadata de creation et de modification

### 3. Operational context
Une depense peut etre purement administrative ou rattachee a une realite metier:
- achat de stock
- achat materiaux pour un job
- carburant pour une intervention
- abonnement logiciel
- frais campagne
- remboursement collaborateur

### 4. Workflow and status
Le systeme doit suivre l etat de la depense:
- `draft`
- `submitted`
- `pending_approval`
- `approved`
- `rejected`
- `paid`
- `processed`

Notes de conception:
- sur un plan `solo`, une depense ou une facture creee doit entrer directement en `approved`
- sur un plan `team`, le createur ne doit pas pouvoir s auto-approuver ni faire avancer seul son propre document
- pour `invoices`, il faut separer `approval_status` du `billing_status` existant afin d eviter les regressions sur les statuts de facturation et de paiement

### 5. Cash and margin visibility
Le module doit permettre de voir rapidement:
- ce qui sort ce mois
- ce qui est en retard
- ce qui revient souvent
- ce qui est lie a une activite rentable ou non

### 6. AI-assisted expense intake
Quand l utilisateur fournit une facture ou un recu, le systeme peut:
- analyser le document
- detecter fournisseur, date, numero, devise et montants
- proposer une categorie
- creer un brouillon de depense
- attacher automatiquement le document source a la depense

L IA ne doit pas finaliser silencieusement une depense sensible.
Elle doit produire:
- des champs structures
- des hypotheses explicites
- un score de confiance
- un statut de revue si necessaire

## Primary User Story

### US-EXP-001 - Record and track a business expense
As an owner or finance operator,
I want to record a business expense with proof, category, status, and dates,
so I can track where cash leaves the business and what is really profitable.

Acceptance criteria:
- an expense can be created with a minimal required set: `title`, `total`, `expense_date`
- optional fields include supplier, category, taxes, due date, payment method, notes, and attachments
- the expense has a clear status lifecycle
- the expense remains tenant-scoped and auditable

## Supporting User Stories

### US-EXP-002 - Capture a receipt quickly from mobile
As a field worker or owner,
I want to create an expense from my phone right after spending,
so I do not lose the receipt or forget the business context.

Acceptance criteria:
- mobile supports camera upload for receipt images
- the user can create a draft expense with a minimal form
- the user can submit the draft immediately or finish it later on web
- attachment upload and preview work on mobile-friendly screens

### US-EXP-003 - Classify the expense correctly
As a finance operator,
I want to assign a category, supplier, and tax breakdown,
so reporting and later accounting exports stay reliable.

Acceptance criteria:
- categories are required before approval
- supplier is optional but encouraged when relevant
- subtotal, tax, and total stay mathematically consistent
- the system can expose validation when tax or total amounts conflict

### US-EXP-004 - Follow approval before payment
As an owner or admin,
I want submitted expenses to pass through an approval step when needed,
so sensitive or reimbursable spending is controlled before payment.

Acceptance criteria:
- expenses can move from `draft` to `submitted` puis `pending_approval` quand une revue supplementaire est requise
- authorized users can approve or reject submitted expenses
- approval actions keep actor, timestamp, and comment trace
- solo workspaces auto-approve on creation and do not expose unnecessary approval states

### US-EXP-005 - Track what is due and what is paid
As an owner,
I want to distinguish future cash-out from already settled expenses,
so I can anticipate cash pressure.

Acceptance criteria:
- due date and paid date are stored separately
- `due` and `paid` states are visible in lists, filters, and summaries
- the dashboard highlights overdue and upcoming expenses
- marking paid requires permission and leaves an audit trail

### US-EXP-006 - Manage recurring expenses
As an owner,
I want recurring expenses such as rent, software, or subscriptions to repeat automatically,
so I do not recreate the same charge every month.

Acceptance criteria:
- an expense can be marked as recurring
- recurrence frequency supports at least monthly and yearly in V1
- the system creates the next due expense from the template
- generated expenses remain editable and traceable to their recurrence source

### US-EXP-007 - Handle employee reimbursements
As an operator or manager,
I want to distinguish supplier expenses from employee reimbursements,
so team cash-outs are tracked correctly.

Acceptance criteria:
- an expense can be marked as `reimbursable`
- a reimbursable expense can be linked to a `team_member`
- reimbursement status is visible separately from supplier payment status
- history shows who submitted, approved, and reimbursed the expense

### US-EXP-008 - Link costs to operations
As an owner,
I want to link an expense to a job, sale, customer, or campaign,
so I can understand true profitability by activity.

Acceptance criteria:
- an expense can optionally reference `work`, `sale`, `invoice`, `customer`, or `campaign`
- linked expenses are visible from the relevant entity detail when useful
- profitability reports can group costs by linked context

### US-EXP-009 - Export and audit expenses
As an owner or accountant,
I want a clean export and audit history of expenses,
so month-end review is faster and safer.

Acceptance criteria:
- the list can be filtered by date, status, category, and supplier
- the filtered result can be exported to CSV
- each expense preserves creator, editor, approver, and payer traces when applicable
- attachments remain reachable from the exported record set through stable references or internal ids

### US-EXP-010 - Create an expense draft from AI invoice scan
As an owner or operator,
I want to upload a supplier invoice or receipt and let the AI prepare an expense draft,
so I do not retype the same information manually.

Acceptance criteria:
- the user can upload a PDF or image invoice/receipt from web or mobile
- the system extracts structured fields such as:
  - supplier name
  - invoice or receipt number
  - expense date
  - due date when present
  - subtotal
  - tax amount
  - total amount
  - currency
  - suggested category
- the system creates a draft expense from the extracted payload
- the source file is attached to the created expense
- low-confidence fields are flagged for review before approval

### US-EXP-011 - Create an expense from assistant chat attachment
As a user of the AI assistant,
I want to send a message with an attached invoice or receipt and ask the assistant to create the expense,
so I can use a conversational workflow instead of opening a dedicated form first.

Acceptance criteria:
- the assistant accepts a PDF or image invoice attachment
- the user can send prompts such as:
  - `cree une depense avec cette facture`
  - `analyse ce recu et prepare la depense`
  - `ajoute cette facture fournisseur dans mes depenses`
- the assistant can create a real `expense` draft in the standard module
- the assistant returns a readable summary with the detected fields and a link to the expense
- the uploaded file remains attached to the generated expense
- if the extraction is uncertain, the assistant says so clearly and keeps the expense in draft or review state

### US-EXP-012 - Keep AI extraction reviewable and safe
As an owner,
I want AI-created expenses to remain reviewable, traceable, and non-destructive,
so automation saves time without creating silent accounting mistakes.

Acceptance criteria:
- the system stores raw AI extraction separately from normalized expense fields
- important fields expose confidence or review flags
- AI can suggest but not silently force approval, payment status, or reimbursement status
- the user can correct extracted values before approval
- manual expense creation remains available even if AI is disabled or fails

### US-EXP-013 - Expose a finance snapshot in superadmin demo detail
As a superadmin or sales operator preparing a demo,
I want the demo workspace detail page to expose a quick finance snapshot for `Expenses`,
so I can instantly confirm that the seeded demo is finance-ready without opening the tenant first.

Acceptance criteria:
- a demo workspace seeded with `expenses` exposes a read-only finance snapshot in the superadmin detail page
- the snapshot is driven by `seed_summary` and includes at minimum:
  - `expenses`
  - `expenses_due`
  - `expenses_paid`
  - `expense_attachments`
- the snapshot is visible only when the demo includes the `expenses` module
- the snapshot is positioned as a quick verification block, not a replacement for the in-tenant expense views
- the values stay aligned with the seeded demo dataset used for walkthroughs

## Shared Finance Approval Extension
Cette phase etend `Expenses` vers un moteur d approbation financier reutilisable aussi par `Invoices`, sans casser le workflow de facturation existant.

### Why a shared approval engine
- les depenses et les factures ont besoin des memes regles de separation createur / approbateur
- les plans `solo` et `team` ne doivent pas avoir la meme complexite
- les seuils par role et les escalades doivent etre centralises plutot que recodes par module
- le journal d audit doit rester coherent sur toute la couche finance

### Target workflow model
Pour `expenses`, le workflow principal cible devient:
- `draft`
- `submitted`
- `pending_approval`
- `approved`
- `rejected`
- `paid`
- `processed`

Pour `invoices`, il faut eviter de remplacer brutalement le `billing_status` actuel. La bonne approche est:
- conserver `billing_status` pour la facturation et l encaissement (`draft`, `sent`, `partial`, `paid`, `overdue`, `void`)
- ajouter un `approval_status` partage avec `expenses`
- utiliser `approval_status` pour bloquer ou autoriser les actions metier sensibles avant emission, traitement ou validation finale

Pourquoi:
- une facture peut etre approuvee avant envoi puis plus tard devenir `paid`
- melanger approbation et encaissement dans un seul champ de statut casserait les ecrans, les paiements et les integrations existantes

### Plan-based approval rules
#### Solo plan
- toute depense ou facture creee entre directement en `approved`
- les etats `submitted` et `pending_approval` ne doivent pas etre exposes en UX normale
- le createur unique peut ensuite aller vers les etats metier finaux autorises (`paid`, `processed`)
- le journal d audit doit quand meme tracer `created -> approved`

#### Team plan
- un employee peut creer une depense ou une facture
- apres creation, le submitter ne peut pas faire avancer seul son propre document vers `approved`, `rejected`, `paid` ou `processed`
- les transitions sensibles doivent etre reservees aux roles approbateurs configures
- si le montant depasse le seuil du role courant, le document passe en escalation vers un role superieur
- le owner garde toujours un droit d approbation sans limite

### Configuration model in admin settings
La configuration doit etre pensee comme un moteur partage et extensible:
- `approval_mode` derive du plan: `solo` ou `team`
- matrice par type de document: `expense`, `invoice`
- liste de roles approbateurs avec:
  - `role_key`
  - `max_amount`
  - `approval_order`
  - `can_reject`
  - `can_mark_paid`
  - `can_mark_processed`
- regle de fallback: si aucun role ne couvre le montant, escalation automatique vers le owner
- snapshot des regles appliquees au document au moment de la soumission, pour garder un audit stable meme si les settings changent plus tard

### Extensible implementation approach
Pour preparer la logique multi-niveaux future, la bonne structure est:
- un champ `approval_status` ou `workflow_status` explicite selon le module
- un `submitted_by_user_id`
- un `current_approval_level`
- un `current_approver_role_key`
- un snapshot `approval_policy_snapshot` dans les metadata
- une table ou collection d evenements de workflow telle que `approval_events`

Chaque evenement doit stocker au minimum:
- type de document
- document id
- actor id
- submitter id
- role utilise pour la transition
- ancien statut
- nouveau statut
- montant au moment de la decision
- commentaire
- raison d escalation si applicable
- timestamp

### Supporting user stories for this phase

### US-EXP-014 - Auto-approve finance records on solo plans
As a solo owner,
I want expenses and invoices to auto-approve at creation time,
so the workflow stays fast and does not pretend there is a separate approval team.

Acceptance criteria:
- in `solo`, created expenses enter `approved` immediately
- in `solo`, created invoices enter `approved` immediately on the approval axis
- `submitted` and `pending_approval` are skipped by default in solo UX
- the audit log records both creation and auto-approval

### US-EXP-015 - Isolate the submitter from approval actions on team plans
As a company owner,
I want the submitter to be blocked from approving or advancing their own expense or invoice,
so the platform enforces real separation of duties.

Acceptance criteria:
- the submitter cannot approve, reject, mark paid, or mark processed their own submission in `team`
- action menus and API capabilities hide forbidden actions for the submitter
- server-side checks still block the transition even if a crafted request is sent
- the owner can always override within audit trace

### US-EXP-016 - Configure approval roles and amount thresholds
As an owner or admin,
I want to configure approver roles and amount ceilings,
so approvals scale with team structure and spending risk.

Acceptance criteria:
- admin settings expose approver roles per document type
- each approver role has a configurable `max_amount`
- if the amount exceeds the role threshold, the document escalates to the next role
- if no role matches, the document escalates to the owner
- the applied threshold path is stored in audit metadata

### US-EXP-017 - Reuse the approval engine for invoices without breaking billing
As a product team,
I want invoices to reuse the same approval engine as expenses while keeping billing states separate,
so the platform gains finance governance without regressing payment flows.

Acceptance criteria:
- invoices keep their current `billing_status`
- invoices gain a separate `approval_status`
- invoice emission or other sensitive next steps can be gated by `approval_status`
- approval audit is shared in structure with expenses
- the design leaves room for future multi-level approval chains

## Mobile Experience
Le module `Expenses` doit etre mobile-first sur les usages terrain, pas desktop-first adapte ensuite.

### Mobile goals
- capture rapide apres une depense reelle
- lecture facile des statuts et echeances
- validation owner/admin en deplacement
- consultation du cash-out a venir sans table lourde
- scan IA immediat depuis appareil photo ou piece jointe
- creation conversationnelle via assistant quand c est plus rapide qu un formulaire

### Mobile V1 expectations
- ecran `New expense` simplifie avec champs essentiels
- capture appareil photo pour recu ou facture
- bouton `scanner avec IA` sur capture de facture ou recu
- possibilite d envoyer la facture a l assistant depuis le mobile pour creer un brouillon
- liste mobile avec filtres rapides `due`, `paid`, `submitted`, `reimbursable`
- detail d une depense lisible sur petit ecran
- actions rapides `submit`, `approve`, `mark paid`, `mark reimbursed` selon permissions
- sur plan `team`, le submitter ne voit pas d actions d approbation sur son propre document
- les escalades et commentaires d approbation restent lisibles en mobile sans exposer de logique locale fragile

### Mobile constraints
- les tableaux complexes, exports avances et edition de masse restent web-first en V1
- mobile ne doit pas reconstituer localement les permissions ou statuts
- les ecrans mobiles doivent consommer les flags backend et capabilities officielles

## Business Rules
- toute depense est strictement tenant-scoped
- les statuts sensibles doivent etre verifies cote serveur
- un montant total ne peut pas etre negatif
- une depense `paid` doit conserver une date de paiement
- une depense `reimbursed` doit etre rattachee a un contexte de remboursement valide
- les pieces jointes doivent rester accessibles uniquement au tenant autorise
- une depense creee par IA doit rester en `draft` ou `review_required` tant qu elle n a pas ete validee
- l IA peut suggerer une categorie ou un fournisseur, mais ne doit pas masquer les champs incertains
- le document source doit rester attache a la depense creee depuis le scan ou depuis le chat assistant
- en `solo`, une depense ou une facture creee passe immediatement en `approved`
- en `team`, le submitter ne peut pas approuver, rejeter, marquer paye, ou marquer traite son propre document
- les transitions d approbation doivent etre resolues via les roles et seuils configures en admin settings
- si aucun role ne couvre le montant, escalation automatique vers le owner
- le owner peut toujours approuver sans limite
- chaque transition, rejet, escalation et override owner doit produire un evenement d audit
- la logique doit etre extensible pour des chains d approbation multi-niveaux futures

## Permissions And Roles
- owner: acces complet
- admin finance ou admin equipe: creation, approbation, paiement selon matrice et seuils
- membre standard: creation de drafts et soumission si autorise, sans auto-approbation en `team`
- comptable externe futur: lecture / export selon role dedie quand `Accounting` existera
- approbateur de niveau 1, 2, 3 futur: roles derives des settings de workflow et non hardcodes par module

## Module And Plan Strategy
- introduire un nouveau module `expenses`
- `expenses` doit pouvoir fonctionner sans `accounting`
- `accounting` dependra ensuite de `expenses`
- les workspaces `solo` doivent pouvoir utiliser `expenses`
- les workflows d approbation doivent etre auto-simplifies pour `solo` avec auto-approval a la creation
- la creation manuelle d une depense depend uniquement de `expenses`
- la capture IA de facture doit dependre de `expenses` + `assistant` ou d une capability IA equivalente
- si l IA n est pas disponible, le parcours manuel doit rester completement fonctionnel
- la logique d approbation stricte doit etre partagee ensuite avec `invoices` via un moteur reutilisable
- `invoices` ne doit pas casser son `billing_status` historique pour adopter la nouvelle couche d approbation

## Delivery Plan

### Phase 1 - Expense foundation
- nouveau domaine `Expense`
- CRUD principal
- categories
- fournisseurs
- statuts de base
- pieces jointes

### Phase 2 - Mobile capture and operational workflow
- capture mobile camera
- soumission / approbation
- marquage paye
- filtres mobile et desktop

### Phase 3 - AI-assisted expense intake
- extraction IA de facture ou recu
- brouillon de depense pre-rempli
- piece jointe conservee automatiquement
- revue des champs detectes et flags de confiance
- entree conversationnelle via `Assistant`
- detection de doublons probables avant creation
- choix assistant `ouvrir la depense existante` ou `creer quand meme` quand un doublon est suspecte

### Phase 4 - Recurrence and reimbursement
- templates de depenses recurrentes
- logique de remboursement
- liens vers `team_member`
- historique enrichi
- `V1 delivered`:
  - creation et edition de depenses `reimbursable` avec lien optionnel vers `team_member`
  - statut de remboursement separe (`not_applicable`, `pending`, `reimbursed`)
  - action workflow `mark_reimbursed` avec audit trail et reference optionnelle
  - templates recurrents `monthly` et `yearly`
  - commande `expenses:generate-recurring` planifiable pour generer la prochaine depense sans doublon
  - tracabilite `recurrence_source_expense_id` et compteur de depenses generees
  - quick filters `reimbursement_pending` et `recurring`

### Phase 5 - Profitability and reporting
- vues par categorie, fournisseur, periode, statut
- couts lies a `work`, `sale`, `campaign`, `customer`
- export CSV
- base de donnees propre pour la future couche `Accounting`

### Phase 6 - Plan-based approval engine and invoice alignment
- introduire un moteur d approbation partage `expenses` / `invoices`
- auto-approval immediat pour `solo`
- separation stricte submitter / approbateur pour `team`
- settings admin pour roles approbateurs et seuils de montant
- escalation vers role superieur puis owner si besoin
- journal d audit complet des transitions et overrides
- ajout d un `approval_status` distinct sur `invoices` sans casser le `billing_status`

### Cross-cutting - Demo and superadmin enablement
- enrichir `seed_summary` demo pour exposer `expenses`, `expenses_due`, `expenses_paid`, `expense_attachments`
- afficher un bloc `finance snapshot` dans la fiche detail superadmin demo
- garder ce bloc en lecture rapide pour support sales / QA, sans dupliquer tout le module `Expenses`

## Definition Of Done
- un owner peut creer, consulter, filtrer, approuver et payer des depenses
- les recus et factures peuvent etre attaches et retrouves
- une facture ou un recu peut aussi produire un brouillon de depense assiste par IA avec piece jointe conservee
- les statuts sont clairs et audites
- la capture mobile est utilisable en conditions reelles
- les depenses recurrentes peuvent generer la prochaine charge sans doublon et restent tracables a leur source
- les remboursements equipe restent distingues du statut global de depense avec trace du `team_member` et du rembourseur
- les depenses peuvent etre reliees a des contextes metier utiles
- le dashboard montre au minimum `due`, `overdue`, `paid this period`, `top categories`
- la fiche detail superadmin demo peut afficher un `finance snapshot` rapide quand `expenses` est seed
- le module est correctement gate par feature flags et plans
- la logique `solo` vs `team` est enforcee cote serveur et visible en admin settings
- le moteur d approbation est reutilisable par `expenses` puis `invoices`
- les invoices conservent leur `billing_status` existant tout en gagnant une couche `approval_status` auditable
