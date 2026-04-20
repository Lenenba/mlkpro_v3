# Phase 1 request inbox dev backlog

Derniere mise a jour: 2026-04-20

## 0. Etat d'avancement implementation

Suivi courant:

- `P1-001` fait
- `P1-002` fait
- `P1-003` fait
- `P1-004` fait
- `P1-005` fait
- `P1-006` fait
- `P1-007` fait
- `P1-008` fait

Dernier bloc livre:

- schema additif request inbox
- classification SLA et stale
- query inbox request
- extensions analytics request
- UI inbox rapide
- board alignment
- quick actions manager / rep
- non-regression suite phase 1
- smoke E2E request inbox

Bloc actuellement en cours:

- phase 1 cloturee
- phase 2 cadree
- sprint 3 pret a demarrer

## 1. But du document

Ce document transforme la phase 1 en backlog dev directement executable.

Le but est simple:

- savoir quoi coder d'abord
- savoir quels fichiers toucher
- savoir quels tests creer
- savoir quand un sprint est reellement termine

## 2. Rappel du scope phase 1

La phase 1 doit transformer `Request` en inbox commerciale priorisee, sans casser:

- la vue table
- la vue board
- le bulk update
- la fiche detail
- la conversion `Request -> Quote`

Resultat V1 attendu:

- queues `new / due soon / stale / breached`
- signaux de priorite et de risque
- actions rapides plus visibles
- KPI de triage

## 3. Regles dev de la phase 1

Regles non negociables:

1. aucun renommage de routes `request.*`
2. aucune rupture du workflow `Request -> Quote`
3. migration additive uniquement
4. toute logique de triage doit vivre cote backend, pas seulement dans Vue
5. la vue board reste fonctionnelle pendant toute la phase

## 4. Fichiers coeur a proteger

### Backend

- `app/Models/Request.php`
- `app/Http/Controllers/RequestController.php`
- `app/Http/Requests/Leads/UpdateLeadRequest.php`
- `database/migrations/2025_12_22_000003_create_requests_table.php`
- `database/migrations/2026_01_25_000011_add_lead_pipeline_fields_to_requests_table.php`

### Frontend

- `resources/js/Pages/Request/Index.vue`
- `resources/js/Pages/Request/UI/RequestTable.vue`
- `resources/js/Pages/Request/UI/RequestBoard.vue`
- `resources/js/Pages/Request/UI/RequestAnalytics.vue`
- `resources/js/Components/UI/RequestStats.vue`
- `resources/js/utils/leadScore.js`

### Tests existants a proteger

- `tests/Feature/WorkflowLeadTest.php`
- `tests/Feature/BulkActionResultContractTest.php`
- `tests/Unit/BulkActionRegistryTest.php`

## 5. Tests a creer pour la phase 1

Suites recommandees:

- `tests/Feature/RequestInboxPhaseOneTest.php`
- `tests/Feature/RequestAnalyticsPhaseOneTest.php`
- `tests/e2e/request-inbox-smoke.spec.js`

Regle:

- ne pas tout empiler dans `WorkflowLeadTest`
- creer une vraie suite dediee au triage request

## 6. Sprint 1

Objectif:

- poser le socle data et metier du triage

Tickets du sprint:

- `P1-001`
- `P1-002`
- `P1-003`

Sortie attendue:

- schema additif pret
- classifier stable
- query de triage disponible

### P1-001 - Schema additif Request Inbox

#### But

Ajouter les champs minimaux de triage sur `requests`.

#### Livrables

- migration additive
- mise a jour du model `Request`
- casts et fillable alignes

#### Fichiers probables

- nouveau fichier dans `database/migrations/`
- `app/Models/Request.php`

#### Champs recommandes

- `first_response_at`
- `last_activity_at`
- `sla_due_at`
- `triage_priority`
- `risk_level`
- `stale_since_at`

#### Notes d'implementation

- garder tous les champs nullable en V1
- ajouter des index utiles si le tri en depend
- ne pas supprimer ni modifier le comportement de `next_follow_up_at`

#### Tests a ajouter

- migration test simple si necessaire
- assertions de casts / persistance dans `RequestInboxPhaseOneTest.php`

#### Acceptance criteria

1. la migration passe en sqlite et mysql
2. le model `Request` expose les nouveaux champs
3. aucune route existante ne casse apres migration

### P1-002 - Classification SLA et stale

#### But

Centraliser le calcul des queues `new / due soon / stale / breached`.

#### Livrables

- service ou classe de classification
- regles explicites
- tests de cas limites

#### Fichiers probables

- nouveau service, par exemple `app/Services/Requests/LeadTriageClassifier.php`
- eventuellement config ou constantes associees
- `tests/Feature/RequestInboxPhaseOneTest.php`

#### Regles V1 recommandees

- `new`
  - lead ouvert
  - pas de `first_response_at`
- `due soon`
  - lead ouvert
  - `next_follow_up_at` ou `sla_due_at` proche
- `stale`
  - lead ouvert
  - pas d'activite depuis 7 jours
- `breached`
  - lead ouvert
  - `sla_due_at` depasse ou delai critique depasse

#### Notes d'implementation

- prendre `ActivityLog` comme source de secours si `last_activity_at` est vide
- garder les seuils simples, constants au debut
- prevoir une sortie stable qui pourra etre reconsommee par controller et analytics

#### Tests a ajouter

- lead neuf sans reponse
- lead avec follow-up bientot
- lead stale sans activite
- lead breached
- lead ferme exclu des queues ouvertes

#### Acceptance criteria

1. la classification est testee de facon deterministe
2. les leads `REQ_WON` et `REQ_LOST` ne polluent pas les queues ouvertes
3. la logique n'est pas dupliquee dans la vue

### P1-003 - Request inbox query

#### But

Creer la query ou couche d'orchestration qui alimente l'index `Request` avec les nouveaux signaux.

#### Livrables

- query dediee ou refacto du controller
- support des filtres par queue
- tri par priorite stable

#### Fichiers probables

- nouveau query object, par exemple `app/Queries/Requests/RequestInboxQuery.php`
- `app/Http/Controllers/RequestController.php`
- `tests/Feature/RequestInboxPhaseOneTest.php`

#### Notes d'implementation

- ne pas tout laisser grossir dans `RequestController@index`
- conserver `request.index` et le format de reponse actuel autant que possible
- enrichir les rows plutot que casser leur contrat

#### Filtres recommandes

- `queue`
- `status`
- `customer_id`
- `assignee`
- `search`

#### Tests a ajouter

- filtre `queue=new`
- filtre `queue=stale`
- tri des breached avant les autres
- preservation des filtres existants

#### Acceptance criteria

1. l'index peut filtrer par queue
2. les lignes remontent avec les nouveaux signaux de triage
3. les filtres existants continuent de fonctionner

### Gate de sortie Sprint 1

Le sprint 1 est termine si:

1. la migration est mergeable
2. la classification est testee
3. la query de triage existe
4. `php artisan test` reste vert sur les suites touchees

## 7. Sprint 2

Objectif:

- rendre le triage visible et utilisable par les utilisateurs

Tickets du sprint:

- `P1-004`
- `P1-005`
- `P1-006`
- `P1-007`
- `P1-008`

Sortie attendue:

- UI de triage
- KPI visibles
- board aligne
- couverture de non-regression

### P1-004 - Extensions analytics Request

#### But

Ajouter les KPI de triage sans casser les analytics deja en place.

#### Livrables

- stale count
- breached count
- temps moyen avant prise en charge

#### Fichiers probables

- `app/Http/Controllers/RequestController.php`
- eventuel query analytics dedie
- `resources/js/Pages/Request/UI/RequestAnalytics.vue`
- `resources/js/Components/UI/RequestStats.vue`
- `tests/Feature/RequestAnalyticsPhaseOneTest.php`

#### Notes d'implementation

- conserver `avg_first_response_hours`
- conserver `conversion_rate`
- ajouter les nouvelles valeurs sans casser le contrat existant

#### Acceptance criteria

1. les analytics existantes restent presentes
2. les nouveaux KPI sont exposes cote web et JSON
3. aucun calcul existant ne regresse

### P1-005 - UI inbox rapide

#### But

Afficher clairement les queues et les signaux de triage dans la vue table.

#### Livrables

- filtre de queue
- badges visuels
- colonne ou signal de priorite
- meilleure lisibilite des leads urgents

#### Fichiers probables

- `resources/js/Pages/Request/UI/RequestTable.vue`
- `resources/js/Pages/Request/Index.vue`
- eventuellement i18n `requests`

#### Notes d'implementation

- ne pas refaire la table de zero
- conserver le switch table / board
- ajouter des test ids si le smoke E2E en a besoin

#### Acceptance criteria

1. un user peut filtrer `new / due soon / stale / breached`
2. les signaux visuels ne cassent pas les actions existantes
3. la table reste lisible sur desktop et mobile raisonnable

### P1-006 - Board alignment

#### But

Aligner la vue board sur les nouveaux signaux de triage sans la transformer en inbox principale.

#### Livrables

- badges de triage sur les cartes
- signal overdue / breached coherent
- aucune regression drag and drop

#### Fichiers probables

- `resources/js/Pages/Request/UI/RequestBoard.vue`
- `resources/js/utils/leadScore.js`
- eventuellement i18n `requests`

#### Notes d'implementation

- ne pas casser `handleBoardChange`
- conserver la logique `lost_reason`
- ne pas modifier les routes d'update

#### Acceptance criteria

1. le board affiche les nouveaux signaux utiles
2. le drag and drop continue de fonctionner
3. la mise a jour de statut reste stable

### P1-007 - Quick actions manager / rep

#### But

Reduire le nombre de clics pour les actions de base.

#### Livrables

- assigner vite
- follow-up vite
- changement de statut vite
- conversion vite

#### Fichiers probables

- `resources/js/Pages/Request/UI/RequestTable.vue`
- `resources/js/Pages/Request/UI/RequestTableActionsMenu.vue`
- `resources/js/Pages/Request/Show.vue`

#### Notes d'implementation

- reutiliser les modals existantes si possible
- ne pas introduire une nouvelle couche de complexite UI sans besoin

#### Acceptance criteria

1. les actions critiques sont plus visibles qu'avant
2. aucun parcours existant n'est supprime
3. la conversion en devis reste immediate

### P1-008 - Non-regression suite

#### But

Fermer proprement la phase 1 avec une vraie couverture.

#### Etat

- livre le `2026-04-20`
- smoke request inbox valide sur le flux `table -> queue -> bulk -> board -> convert`

#### Livrables

- tests feature request inbox
- tests analytics request
- smoke E2E request

#### Fichiers probables

- `tests/Feature/RequestInboxPhaseOneTest.php`
- `tests/Feature/RequestAnalyticsPhaseOneTest.php`
- `tests/e2e/request-inbox-smoke.spec.js`

#### Cas minimum a couvrir

- ouverture `request.index`
- filtre queue
- mise a jour statut
- bulk update
- board visible
- conversion `Request -> Quote`

#### Acceptance criteria

1. la phase 1 a une suite test dediee
2. le workflow principal reste couvert
3. la regression la plus probable est capturee avant merge

#### Verification realisee

```powershell
npx playwright test tests/e2e/request-inbox-smoke.spec.js
```

### Gate de sortie Sprint 2

Le sprint 2 est termine si:

1. la table expose les queues
2. le board est aligne
3. les analytics sont enrichies
4. les quick actions sont utilisables
5. les tests phase 1 sont verts

## 8. Ordre d'execution recommande

L'ordre recommande est:

1. `P1-001`
2. `P1-002`
3. `P1-003`
4. `P1-004`
5. `P1-005`
6. `P1-006`
7. `P1-007`
8. `P1-008`

Regle:

- ne pas commencer l'UI de queue avant que la classification et la query soient stables

## 9. Commandes de verification recommandees

Pendant la phase:

```powershell
php artisan test tests/Feature/WorkflowLeadTest.php
php artisan test tests/Feature/BulkActionResultContractTest.php
php artisan test tests/Feature/RequestInboxPhaseOneTest.php
php artisan test tests/Feature/RequestAnalyticsPhaseOneTest.php
npm run qa:build
```

Avant cloture:

```powershell
php artisan test
npm run qa:e2e
```

## 10. Definition de done de la phase 1

La phase 1 est terminee si:

1. les champs additifs sont en place
2. les queues sont calculees cote backend
3. l'index `Request` permet de filtrer ces queues
4. la vue board reste stable
5. la conversion `Request -> Quote` reste intacte
6. une suite feature et un smoke E2E existent pour cette phase

## 11. Documents lies

- `docs/PHASE_1_LEAD_SLA_INBOX_SMART_TRIAGE_2026-04-20.md`
- `docs/CRM_DEV_EXECUTION_PHASES_2026-04-20.md`
- `docs/PLAN_STRATEGIQUE_CRM_COMPETITIF_2026-04-20.md`
