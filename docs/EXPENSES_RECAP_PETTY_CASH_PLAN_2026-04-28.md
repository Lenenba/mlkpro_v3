# Expenses Recap And Petite Caisse - Plan Produit

Last updated: 2026-04-28

Related docs:
- `Expenses`: `docs/EXPENSES_MODULE_USER_STORY.md`
- `Nice to have`: `docs/EXPENSES_MODULE_NICE_TO_HAVE.md`
- `Accounting`: `docs/ACCOUNTING_MODULE_USER_STORY.md`

## Goal
Ajouter une couche de suivi financier plus lisible au module `Expenses` avec:
- un recap des depenses par periode
- des analyses simples pour comprendre ou part l argent
- une petite caisse pour suivre les petites sorties terrain, avances, remboursements et ajustements

L objectif n est pas de remplacer le module `Accounting`, mais de donner aux owners et equipes finance une lecture operationnelle quotidienne:
- combien a ete depense cette semaine ou ce mois
- ce qui reste a approuver, payer ou rembourser
- quelles categories ou personnes consomment le plus
- quel est le solde de la petite caisse
- quels mouvements doivent etre justifies ou rapproches

## Product Vision
Le module `Expenses` capture deja les sorties d argent, les recus, les statuts, les approbations et les remboursements.

La prochaine evolution doit transformer ces donnees en pilotage:
- un owner ouvre `Expenses` et comprend tout de suite la situation
- un manager voit les depenses terrain sans fouiller la table
- une equipe peut tenir une petite caisse sans cahier papier
- la future couche `Accounting` peut reutiliser des mouvements propres et audites

## Scope En 3 Etapes

### Etape 1 - Recap des depenses

Ajouter une vue de suivi dans le module `Expenses`.

#### Periodes supportees
- semaine courante
- mois courant
- trimestre courant
- annee courante
- periode personnalisee
- comparaison avec la periode precedente

#### KPI principaux
- total depense
- total approuve
- total paye
- total a payer
- total a rembourser
- depenses en attente d approbation
- depenses rejetees
- depenses sans recu
- depenses recurrentes du mois

#### Analyses
- repartition par categorie
- repartition par fournisseur
- repartition par membre d equipe
- repartition par methode de paiement
- depenses liees a `work`, `sale`, `invoice`, `customer` ou `campaign`
- top 5 categories
- top 5 fournisseurs
- evolution vs periode precedente

#### UX proposee
- ajouter des tabs ou sous-sections dans `Expenses`:
  - `Liste`
  - `Suivi`
  - `Petite caisse`
- dans `Suivi`, afficher:
  - une barre de periode en haut
  - une ligne de KPI compacts
  - deux blocs de repartition
  - une liste des alertes
  - un raccourci vers la liste filtree

#### Acceptance criteria
- l utilisateur peut choisir une periode et voir les KPI recalcules
- les montants respectent la devise du tenant
- les chiffres sont tenant-scoped
- les utilisateurs sans permission `expenses.view` ne voient pas la vue
- les cartes de repartition renvoient vers la liste filtree
- la comparaison avec la periode precedente est affichee quand elle est calculable

## Etape 2 - Petite caisse MVP

Creer une section `Petite caisse` dans le module `Expenses`.

### Definition
La petite caisse represente un solde interne disponible pour de petites depenses rapides.

Elle ne remplace pas les depenses classiques. Elle sert a tracer les mouvements de cash ou equivalents cash:
- approvisionnement de caisse
- achat terrain
- avance donnee a un membre
- remboursement recu
- ajustement controle

### Mouvements V1

#### `funding`
Approvisionnement de la caisse.

Exemples:
- retrait bancaire depose dans la caisse
- depot owner
- transfert interne

#### `expense`
Sortie de caisse pour une petite depense.

Exemples:
- carburant
- stationnement
- materiel urgent
- petite fourniture

#### `advance`
Avance donnee a un membre d equipe.

Exemples:
- avance pour acheter du materiel
- avance pour frais de route

#### `reimbursement`
Retour d argent dans la caisse.

Exemples:
- membre qui rend le reste d une avance
- correction apres depense inferieure au montant avance

#### `adjustment`
Ajustement manuel controle.

Exemples:
- ecart de caisse
- correction apres verification
- erreur de saisie

### Champs minimum
- type de mouvement
- montant
- devise
- date
- responsable
- note
- piece jointe optionnelle
- lien optionnel vers une depense existante
- statut: `draft`, `posted`, `voided`

### UX proposee
- bloc en haut: solde courant
- bouton `Ajouter un mouvement`
- liste chronologique des mouvements
- filtres par periode, type, responsable et statut
- badge indiquant les mouvements sans justificatif
- action pour convertir une sortie de caisse en vraie depense si necessaire
- option dans le scan IA de depense pour creer un mouvement de petite caisse lie, si la depense a ete payee depuis la caisse

### Acceptance criteria
- un owner ou utilisateur autorise peut creer un mouvement
- le solde courant se met a jour avec les mouvements `posted`
- les mouvements `draft` ne modifient pas le solde
- un mouvement peut etre annule via `voided`, jamais supprime silencieusement
- un mouvement peut etre lie a une depense
- l historique conserve acteur, date et modification

## Etape 3 - Controles, cloture et lien comptable

Ajouter les garde-fous pour rendre la petite caisse fiable.

### Regles de controle
- responsable de caisse obligatoire
- seuil de solde bas
- recu obligatoire au-dessus d un montant configurable
- commentaire obligatoire pour les ajustements
- permission dediee pour les ajustements
- blocage de modification apres cloture

### Cloture de caisse
Permettre une cloture hebdomadaire ou mensuelle.

Une cloture contient:
- periode
- solde attendu
- solde compte physiquement
- ecart
- commentaire
- statut: `open`, `in_review`, `closed`, `reopened`
- pieces jointes de verification si besoin

### Rapprochement
Le rapprochement doit aider a repondre:
- quels mouvements sont justifies
- quels mouvements manquent de recu
- quels mouvements ne sont pas encore lies a une depense
- quel ecart existe entre solde theorique et solde physique

### Lien avec Accounting
La petite caisse peut rester operationnelle en V1, puis alimenter `Accounting` plus tard.

Evenements potentiels:
- `petty_cash_funded`
- `petty_cash_expense_posted`
- `petty_cash_advance_posted`
- `petty_cash_reimbursement_posted`
- `petty_cash_adjustment_posted`
- `petty_cash_period_closed`

### Acceptance criteria
- une periode cloturee ne peut plus etre modifiee sans reouverture explicite
- les ecarts de cloture sont visibles
- les mouvements non justifies sont listables
- les exports incluent mouvements, responsables, notes, liens et justificatifs
- l audit trail permet de reconstruire tout le solde

## Suggested Data Model

### `petty_cash_accounts`
- `id`
- `user_id`
- `name`
- `currency_code`
- `opening_balance`
- `current_balance`
- `low_balance_threshold`
- `responsible_user_id`
- `is_active`
- `meta`
- timestamps

### `petty_cash_movements`
- `id`
- `user_id`
- `petty_cash_account_id`
- `expense_id`
- `team_member_id`
- `created_by_user_id`
- `type`
- `status`
- `amount`
- `currency_code`
- `movement_date`
- `note`
- `requires_receipt`
- `receipt_attached`
- `posted_at`
- `voided_at`
- `void_reason`
- `meta`
- timestamps

### `petty_cash_closures`
- `id`
- `user_id`
- `petty_cash_account_id`
- `period_start`
- `period_end`
- `expected_balance`
- `counted_balance`
- `difference`
- `status`
- `reviewed_by_user_id`
- `closed_by_user_id`
- `closed_at`
- `comment`
- `meta`
- timestamps

### `petty_cash_attachments`
- `id`
- `user_id`
- `petty_cash_movement_id`
- `petty_cash_closure_id`
- `uploaded_by_user_id`
- `disk`
- `path`
- `original_name`
- `mime_type`
- `size`
- `meta`
- timestamps

## Permissions

Proposition:
- `expenses.view`: voir recap et petite caisse
- `expenses.create`: creer un mouvement draft
- `expenses.edit`: modifier un mouvement draft
- `expenses.pay`: poster un mouvement de caisse
- `expenses.approve`: revoir une cloture
- `expenses.approve_high`: approuver les ajustements sensibles
- `expenses.manage_petty_cash`: configurer les comptes de petite caisse

Note:
- si on veut eviter une nouvelle permission en V1, `expenses.pay` peut couvrir les actions de caisse, et `expenses.approve_high` les ajustements.

## UI Implementation Notes

### Surfaces
- `resources/js/Pages/Expense/Index.vue`
  - ajouter tabs ou switch de vue
  - charger les donnees recap et petite caisse depuis le controller
- `resources/js/Components/UI/ExpenseStats.vue`
  - garder les KPI existants
  - ajouter une version plus detaillee dans une nouvelle section si besoin
- nouveau composant possible:
  - `ExpensePeriodRecap.vue`
  - `ExpensePettyCashPanel.vue`
  - `ExpensePettyCashMovementModal.vue`
  - `ExpensePettyCashClosurePanel.vue`

### Design
- utiliser les composants floating existants pour les formulaires
- garder une densite dashboard sobre
- eviter les grandes cartes marketing
- privilegier KPI compacts, tableaux lisibles et filtres rapides

## Backend Implementation Notes

### Controller
Le controller `Expense` peut exposer:
- `stats` actuels
- `periodRecap`
- `pettyCash`
- `pettyCashMovements`
- `pettyCashClosures`

Selon la taille, separer ensuite:
- `ExpenseRecapController`
- `PettyCashController`
- `PettyCashMovementController`
- `PettyCashClosureController`

### Services
- `ExpenseRecapService`
  - calcule les KPI par periode
  - calcule les comparaisons
  - prepare les breakdowns
- `PettyCashService`
  - poste les mouvements
  - recalcule le solde
  - gere les annulations
- `PettyCashClosureService`
  - cloture une periode
  - calcule les ecarts
  - bloque les modifications apres cloture

## Phase Delivery Checklist

### Phase 1 - Recap
- [x] backend recap par periode
- [x] filtres periode
- [x] KPI periode
- [x] breakdown categorie / fournisseur / membre
- [x] comparaison periode precedente
- [x] liens vers liste filtree

### Phase 2 - Petite caisse MVP
- [x] tables petite caisse
- [x] compte de caisse par defaut
- [x] creation mouvement
- [x] liste mouvements
- [x] calcul solde
- [x] annulation controlee
- [x] lien optionnel avec une depense

### Phase 3 - Controle et cloture
- [x] seuil solde bas
- [x] recu obligatoire selon montant
- [x] cloture periode
- [x] rapprochement simple
- [x] exports
- [x] audit trail complet
- [x] hooks accounting-ready

## Open Questions
- Est-ce qu un tenant peut avoir plusieurs petites caisses ou une seule en V1 ?
- Est-ce que la petite caisse doit etre liee a une devise unique ou suivre la devise du tenant seulement ?
- Qui peut compter et cloturer la caisse ?
- Est-ce qu une avance doit creer automatiquement une depense attendue ?
- Est-ce que les mouvements de caisse doivent apparaitre dans la table principale `Expenses` ou rester dans une vue separee ?

## Recommended MVP
Pour aller vite sans sur-architecturer:
1. livrer le recap periode dans `Expenses`
2. ajouter une seule petite caisse par tenant
3. supporter `funding`, `expense`, `reimbursement`, `adjustment`
4. garder les avances pour une V1.1 si le flux devient plus complexe
5. ajouter la cloture mensuelle apres validation terrain du MVP
