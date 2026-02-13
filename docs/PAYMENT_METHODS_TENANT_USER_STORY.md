# Payment Methods By Tenant - User Story

## Objectif
Sur toutes les pages/ecrans ou un paiement est possible, l utilisateur peut payer avec un mode de paiement disponible pour l entreprise courante.

Les choix affiches dependent strictement des methodes activees par le tenant.

## Regles de gestion

### 1) Configuration cote entreprise (admin)
- L entreprise configure les methodes autorisees:
  - `stripe` (carte)
  - `cash` (paiement sur place)
  - `other` (virement, terminal, etc.) - optionnel
- Cette configuration est la source de verite.

### 2) Affichage cote client/utilisateur
- Sur chaque ecran de paiement, afficher uniquement les methodes activees.
- Exemples:
  - tenant = `stripe` uniquement -> afficher seulement Stripe
  - tenant = `cash + stripe` -> afficher Cash et Stripe
  - tenant = `cash` uniquement -> afficher seulement Cash (aucun flow Stripe)

### 3) Validation backend obligatoire
- Le backend refuse toute tentative de paiement avec une methode non autorisee pour le tenant.
- Message erreur standard:
  - `Cette entreprise n accepte pas ce mode de paiement.`

### 4) Cohherence globale
- Meme logique appliquee sur:
  - reservation -> paiement
  - facture -> paiement
  - achat store -> paiement
  - tip/extra -> paiement
  - walk-in/ticket -> paiement (si applicable)

## UX recommandee
- Si une seule methode est disponible:
  - ne pas afficher de selecteur
  - afficher directement le moyen impose (ex: `Paiement par carte`)
- Si plusieurs methodes sont disponibles:
  - afficher un selecteur clair:
    - `Carte (Stripe)`
    - `Cash (sur place)`
    - `Autre` (si active)
- Si `cash` est choisi:
  - creer la transaction en etat `pending` (a encaisser)
  - permettre a l equipe/owner l action `Marquer comme paye`

## Statuts de paiement
Separer le statut metier (reservation/facture) du statut de paiement.

- `unpaid` (non paye)
- `pending` (en attente, ex cash a encaisser)
- `paid` (paye)
- `failed` (echec)
- `refunded` (rembourse)

## Bonus (optionnel)
- Methode par defaut par tenant (ex: `stripe`)
- Restriction contextuelle du cash (ex: autorise seulement pour certains services/contexte)
- Log et audit:
  - qui a marque `cash` comme paye
  - quand
  - sur quel objet (reservation/facture/store/tip/walk-in)

## User Story
### US-PAY-1 - Modes de paiement dynamiques par tenant
As a business owner, I can configure allowed payment methods for my tenant so users only see valid options and backend enforcement is guaranteed.

Acceptance criteria:
- Le tenant peut activer/desactiver `stripe`, `cash`, `other`.
- Le front n affiche que les methodes activees pour tous les ecrans de paiement.
- Si une seule methode est active, aucun selecteur n est affiche.
- Toute methode non autorisee est rejetee par le backend avec le message standard.
- Un paiement `cash` est cree en statut `pending` puis peut etre bascule en `paid` par staff/owner.
- Chaque action `Marquer comme paye` est auditee (user, horodatage, contexte).

## Phases d implementation

### Phase 1 - Configuration tenant et modeles de donnees
- Status: Implemented
- Objectif phase:
  - etablir une source de verite tenant pour les methodes de paiement
  - normaliser le modele de donnees sans regression
  - preparer la compatibilite avec les flows existants

- Source de verite tenant (MVP):
  - utiliser `users.payment_methods` comme base existante (deja en production)
  - ajouter `users.default_payment_method` (nullable string)
  - ajouter `users.cash_allowed_contexts` (nullable json array, optionnel)
  - exposer une vue metier normalisee pour le front:
    - `stripe` <-> `card` (mapping technique)
    - `cash` <-> `cash`
    - `other` <-> (`bank_transfer`, `check`)

- Normalisation table `payments` (sans rupture):
  - conserver `payments.method` et `payments.status` (deja utilises)
  - aligner les valeurs metier attendues:
    - methodes supportees: `cash`, `card`, `bank_transfer`, `check`
    - statut metier cible: `unpaid`, `pending`, `paid`, `failed`, `refunded`
  - compatibilite legacy:
    - `completed` est interprete comme `paid`
    - `reversed` reste supporte pour l historique tips/reversal

- Migrations et backfill:
  - migration 1: ajouter colonnes `default_payment_method`, `cash_allowed_contexts` sur `users`
  - migration 2: backfill des tenants sans config explicite (fallback sur valeurs existantes)
  - migration 3 (optionnelle): normaliser les statuts `completed` -> `paid` dans une passe controlee

- Contrats techniques de phase 1:
  - creer un resolver central (ex: `TenantPaymentMethodsResolver`) qui retourne:
    - `enabled_methods` (format metier: `stripe|cash|other`)
    - `enabled_methods_internal` (format technique: `card|cash|bank_transfer|check`)
    - `default_method`
  - pas de logique UI ni blocage backend global dans cette phase (arrive en phase 2/3)

- Definition of done (Phase 1):
  - schema DB deploye avec rollback valide
  - resolver central couvre mapping metier <-> technique
  - tests unitaires sur mapping, fallback tenant, et compatibilite statuts legacy

### Phase 2 - Enforcement backend centralise
- Status: Implemented
- Objectif phase:
  - rendre le backend source unique de validation des methodes de paiement
  - supprimer les validations hardcodees par endpoint
  - garantir la meme erreur metier sur tous les canaux

- Service central (obligatoire):
  - creer `TenantPaymentMethodGuardService`
  - entrees:
    - `account_id`
    - `requested_method` (metier ou technique)
    - `context` (`invoice_manual`, `invoice_portal`, `invoice_public`, `sale_manual`, `sale_stripe`, `portal_order_pay`, `reservation`, `tip_extra`, `walk_in`)
  - sorties:
    - `allowed` (bool)
    - `canonical_method` (ex: `card`, `cash`, `bank_transfer`, `check`)
    - `normalized_business_method` (ex: `stripe`, `cash`, `other`)
  - comportement:
    - lit la config via `TenantPaymentMethodsResolver`
    - applique le mapping metier <-> technique
    - rejette si methode non autorisee

- Contrat erreur uniforme:
  - HTTP `422`
  - code metier: `payment_method_not_allowed`
  - message: `Cette entreprise n accepte pas ce mode de paiement.`

- Points d integration backend (code actuel):
  - `PaymentController::store` (invoice interne)
  - `PortalInvoiceController::storePayment` (invoice portail client)
  - `PublicInvoiceController::storePayment` (invoice lien public signe)
  - `PortalInvoiceController::createStripeCheckout` (invoice stripe portail)
  - `PublicInvoiceController::createStripeCheckout` (invoice stripe public)
  - `SalePaymentController::store` (paiement manuel vente)
  - `SaleController::createStripeCheckout` (vente stripe interne)
  - `PortalProductOrderController::pay` (paiement commande portail)

- Webhook et asynchrone Stripe:
  - point de controle: `StripeWebhookController` via `StripeInvoiceService` et `StripeSaleService`
  - regle:
    - ne jamais perdre un evenement de paiement recu
    - journaliser une alerte si la methode ne correspond plus a la policy courante (`payment_method_policy_mismatch`)

- Regles de validation a appliquer:
  - remplacer les `Rule::in(...)` statiques par la liste dynamique retournee par le resolver
  - pour les routes Stripe checkout (sans `method` dans payload), considerer `requested_method = stripe`
  - interdire les valeurs libres `method` non mappees

- Compatibilite immediate (phase 2):
  - conserver `status = completed` dans les flux existants pour ne pas casser les aggregations actuelles
  - la normalisation fine `pending/paid` du cash est traitee en phase 4

- Definition of done (Phase 2):
  - tous les endpoints listes ci-dessus appellent le guard avant creation paiement / checkout
  - format d erreur unifie sur tous les points d entree
  - tests feature de non-contournement passent sur web + api + portal + public

### Phase 3 - UX Frontend et affichage conditionnel
- Status: Implemented
- `paymentMethodSettings` charge cote pages de paiement (invoice, public invoice, sales, dashboard client, portal orders).
- Regle UI appliquee:
  - 1 methode active -> pas de selecteur, methode imposee affichee
  - >1 methode active -> selecteur limite aux methodes autorisees
- Flows Stripe conditionnes:
  - bouton Stripe masque si `card` non autorise par tenant
  - pages portal orders/shop et dashboard client alignees sur la policy tenant

### Phase 4 - Flux cash et operations staff
- Status: Implemented
- Si paiement cash selectionne:
  - creer la transaction en `pending` (invoice interne, portal/public, vente manuelle)
  - afficher le badge `Paiement en attente (cash)` / `A encaisser`
- Ajouter action staff/owner:
  - endpoint `PATCH /payments/{payment}/mark-paid` pour transition `pending` vers `paid`
  - journaliser acteur, horodatage et contexte metier
- Compatibilite agregations:
  - `amount_paid`/`balance_due` calcules sur statuts regles (`completed` + `paid`)
  - `pending` n est plus compte comme encaisse

### Phase 5 - Validation, tests et non-regression
- Status: Implemented
- Feature tests backend:
  - tenant `stripe` only: cash/other rejetes (web + json)
  - tenant `cash` only: stripe rejete (interne + public signe)
  - tenant `cash + stripe`: les deux acceptes (cash `pending`, stripe `completed`)
  - endpoint portal client protege contre methodes non autorisees
  - cash cree en `pending`, puis `paid` via action staff (couvert en phase 4)
- UI tests:
  - contrat Inertia `Invoice/Show`: 1 methode => payload unique (selecteur masque cote UI)
  - contrat Inertia `Invoice/Show`: plusieurs methodes => payload multiple (selecteur affiche cote UI)
  - contrat Inertia `Public/InvoicePay`: aucune methode non autorisee exposee

Deliverables:
- `tests/Feature/TenantPaymentMethodsPhase5Test.php`
- couverture enforcement backend (web/json/portal/public)
- couverture contrat UI Inertia sur ecrans de paiement

### Phase 6 - Rollout progressif
- Status: Planned
- Activation par tenant (feature flag si necessaire).
- Deploiement progressif:
  1. tenants pilotes
  2. generalisation par lots
  3. activation globale
- Monitoring:
  - taux d erreurs `mode de paiement non autorise`
  - volume de paiements `pending` cash
  - temps moyen de regularisation cash (`pending` -> `paid`)
