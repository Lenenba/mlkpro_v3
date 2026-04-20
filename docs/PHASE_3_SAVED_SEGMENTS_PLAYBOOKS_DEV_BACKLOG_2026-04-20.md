# Phase 3 saved segments and playbooks dev backlog

Derniere mise a jour: 2026-04-20

## 0. Etat d'avancement implementation

Suivi courant:

- `P3-001` fait
- `P3-002` fait
- `P3-003` fait
- `P3-004` fait
- `P3-005` fait
- `P3-006` fait
- `P3-007` fait
- `P3-008` fait

Dernier bloc livre:

- cloture phase 2 quote recovery
- smoke E2E quote recovery vert
- cockpit devis stable
- quick actions recovery et ActivityLog en place
- sync `Quote -> Request status` reverifie

Bloc actuellement en cours:

- phase 3 saved segments and scheduled playbooks ouverte
- schema additif `saved_segments` livre
- schema additif `playbooks` et `playbook_runs` livre
- registre de resolution multi-module en place
- resolvers `Request / Customer / Quote` branches
- queries phase 1 et phase 2 reconsommables hors controller
- service d'execution manuelle de playbook livre
- actions bornees `request / customer / quote` branchees
- resume de run aligne sur le contrat `BulkActionResult`
- couverture feature playbook execution ajoutee
- scheduler simple playbook livre
- cadence `daily / weekly` branchee
- commande artisan et cron Laravel relies
- couverture feature scheduler ajoutee
- UI saved segments branchee sur `Request / Customer / Quote`
- CRUD backend `crm.saved-segments.*` livre
- controle owner-only stabilise pour l'exposition CRM
- couverture feature UI saved segments ajoutee
- sprint 6 en cours

## 1. But du document

Ce document transforme la phase 3 en backlog dev directement executable.

Le but est simple:

- savoir quoi coder d'abord
- savoir quels fichiers toucher
- savoir quels tests creer
- savoir quel ordre suivre pour ne pas fragiliser la base

## 2. Rappel du scope phase 3

La phase 3 doit transformer les routines manuelles en objets persistants, sans casser:

- les segments marketing existants
- les bulk actions `Customer` et `Request`
- le cockpit `Quote` livre en phase 2
- les feature flags et droits d'acces existants

Resultat V1 attendu:

- segments sauvegardes sur `Request / Customer / Quote`
- playbooks lies a un segment
- execution manuelle
- execution planifiee simple
- historique de runs avec compteurs fiables

## 3. Regles dev de la phase 3

Regles non negociables:

1. ne pas casser l'API `marketing.segments.*`
2. reutiliser le coeur metier des bulk actions existantes quand c'est possible
3. ne jamais exposer une action playbook si le module ou la feature n'est pas active
4. centraliser la resolution des segments cote backend
5. pousser l'execution en job des que le volume devient non trivial
6. ne pas bricoler `Quote` avec une logique bulk implicite sans contrat explicite

## 4. Fichiers coeur a proteger

### Backend

- `app/Support/BulkActions/BulkActionRegistry.php`
- `app/Support/BulkActions/Modules/CustomerBulkActionModule.php`
- `app/Support/BulkActions/Modules/RequestBulkActionModule.php`
- `app/Services/Campaigns/SegmentService.php`
- `app/Services/Campaigns/AudienceResolver.php`
- `app/Services/Campaigns/CampaignAutomationService.php`
- `app/Services/CompanyFeatureService.php`
- `app/Http/Controllers/CustomerController.php`
- `app/Http/Controllers/RequestController.php`
- `app/Http/Controllers/QuoteController.php`

### Frontend

- `resources/js/Pages/Customer/Index.vue`
- `resources/js/Pages/Customer/UI/CustomerTable.vue`
- `resources/js/Pages/Request/Index.vue`
- `resources/js/Pages/Request/UI/RequestTable.vue`
- `resources/js/Pages/Quote/Index.vue`
- `resources/js/Pages/Quote/UI/QuoteTable.vue`
- `resources/js/Pages/Campaigns/Components/SegmentManager.vue`

### Tests existants a proteger

- `tests/Unit/BulkActionRegistryTest.php`
- `tests/Feature/BulkActionResultContractTest.php`
- `tests/Feature/CustomerBulkContactFeatureAvailabilityTest.php`
- `tests/Feature/CampaignsMarketingModuleTest.php`
- `tests/Feature/WorkflowLeadTest.php`
- `tests/Feature/QuoteRecoveryPhaseTwoTest.php`

## 5. Tests a creer pour la phase 3

Suites recommandees:

- `tests/Feature/SavedSegmentsPhaseThreeTest.php`
- `tests/Feature/PlaybookExecutionPhaseThreeTest.php`
- `tests/Feature/PlaybookSchedulerPhaseThreeTest.php`
- `tests/Unit/SegmentResolverRegistryTest.php`
- `tests/e2e/playbook-smoke.spec.js`

Regle:

- ne pas diluer la phase 3 dans les vieux tests campaigns
- creer un vrai socle de non-regression dedie aux segments et playbooks

## 6. Sprint 5

Objectif:

- poser le socle data et metier des segments sauvegardes et de l'execution manuelle

Tickets du sprint:

- `P3-001`
- `P3-002`
- `P3-003`
- `P3-004`

Sortie attendue:

- schema additif pret
- segment generique persistable
- playbook et run persistables
- resolveur multi-module disponible
- execution manuelle auditée

### P3-001 - Saved segment model and schema

#### But

Ajouter une structure generique de segment sauvegarde reutilisable par `Request`, `Customer` et `Quote`.

#### Etat

- livre le `2026-04-20`
- migration additive `saved_segments` en place
- model `SavedSegment` aligne
- suite feature phase 3 creee

#### Livrables

- migration additive `saved_segments`
- model `SavedSegment`
- casts et fillable alignes
- index et unicite metier de base

#### Fichiers probables

- nouveau fichier dans `database/migrations/`
- nouveau model dans `app/Models/`
- nouvelle factory si utile
- `tests/Feature/SavedSegmentsPhaseThreeTest.php`

#### Champs recommandes

- `user_id`
- `created_by_user_id`
- `updated_by_user_id`
- `module`
- `name`
- `description`
- `filters`
- `sort`
- `search_term`
- `is_shared`
- `cached_count`
- `last_resolved_at`

#### Notes d'implementation

- ne pas surcharger `AudienceSegment` pour tous les usages CRM
- garder l'approche marketing existante intacte
- garantir une unicite simple par `tenant + module + nom`
- garder les champs serialises en `array/json`

#### Tests a ajouter

- persistance
- casts
- unicite par module
- isolation tenant

#### Acceptance criteria

1. la migration passe en sqlite et mysql
2. le model de segment generique est pret
3. aucun segment marketing existant n'est casse

#### Verification realisee

```powershell
php artisan test tests/Feature/SavedSegmentsPhaseThreeTest.php
php artisan test tests/Feature/CampaignsMarketingModuleTest.php --filter="segment"
php artisan test tests/Unit/BulkActionRegistryTest.php
```

### P3-002 - Playbook model and run schema

#### But

Ajouter les objets persistants qui decrivent une routine et ses executions.

#### Etat

- livre le `2026-04-20`
- migrations additives `playbooks` et `playbook_runs` en place
- modeles `Playbook` et `PlaybookRun` alignes
- relations de base vers `SavedSegment` et `User` en place
- suite feature phase 3 etendue

#### Livrables

- migration additive `playbooks`
- migration additive `playbook_runs`
- `playbook_run_items` si le besoin d'audit detaille est retenu
- models et relations de base

#### Fichiers probables

- nouveaux fichiers dans `database/migrations/`
- nouveaux models dans `app/Models/`
- `tests/Feature/PlaybookExecutionPhaseThreeTest.php`

#### Champs recommandes

- `module`
- `saved_segment_id`
- `name`
- `action_key`
- `action_payload`
- `schedule_type`
- `schedule_timezone`
- `schedule_day_of_week`
- `schedule_time`
- `next_run_at`
- `last_run_at`
- `is_active`

#### Notes d'implementation

- garder `schedule_type` simple en V1: `manual / daily / weekly`
- ne pas partir sur un cron parser complet
- stocker les compteurs de run comme colonnes explicites
- prevoir `summary` ou `metadata` pour le debug des runs

#### Tests a ajouter

- relations `playbook -> segment`
- relations `run -> playbook`
- persistence des compteurs et statuts

#### Acceptance criteria

1. un playbook peut exister sans scheduler complexe
2. un run peut etre journalise meme si zero element est traite
3. le schema reste additif et lisible

#### Verification realisee

```powershell
php artisan test tests/Feature/PlaybookExecutionPhaseThreeTest.php
php artisan test tests/Feature/SavedSegmentsPhaseThreeTest.php
php artisan test tests/Feature/CampaignsMarketingModuleTest.php --filter="segment"
```

### P3-003 - Segment resolver service

#### But

Centraliser la resolution d'un segment sauvegarde vers une liste d'IDs cible.

#### Etat

- livre le `2026-04-20`
- registre et resolvers module par module en place
- resolution `request / customer / quote` centralisee cote backend
- couverture feature et unit ajoutee

#### Livrables

- registre ou service de resolution par module
- support `request / customer / quote`
- sortie stable `ids + counts + metadata`

#### Fichiers probables

- nouveau service dans `app/Services/Segments/` ou `app/Queries/Segments/`
- `app/Http/Controllers/CustomerController.php`
- `app/Http/Controllers/RequestController.php`
- `app/Http/Controllers/QuoteController.php`
- `tests/Unit/SegmentResolverRegistryTest.php`

#### Notes d'implementation

- `request` doit reutiliser les patterns de phase 1
- `customer` doit reutiliser les patterns du module customer et des audiences quand utile
- `quote` doit reutiliser les patterns de recovery de phase 2
- ne pas evaluer les segments en frontend
- sortir des IDs stables, pas seulement des objets affichage

#### Tests a ajouter

- resolution `request`
- resolution `customer`
- resolution `quote`
- respect des filtres et du tri
- exclusion des enregistrements hors tenant

#### Acceptance criteria

1. les trois modules cibles sont resolus cote backend
2. le resolver renvoie un contrat stable reutilisable
3. la logique de resolution n'est pas dupliquee dans Vue

#### Verification realisee

```powershell
php artisan test tests/Feature/SegmentResolverPhaseThreeTest.php
php artisan test tests/Unit/SegmentResolverRegistryTest.php
php artisan test tests/Feature/PlaybookExecutionPhaseThreeTest.php
php artisan test tests/Feature/SavedSegmentsPhaseThreeTest.php
php artisan test tests/Feature/CampaignsMarketingModuleTest.php --filter="segment"
```

### P3-004 - Playbook execution service

#### But

Executer un playbook manuellement en reutilisant les handlers bulk existants ou leurs equivalents bornes.

#### Etat

- livre

#### Livrables

- service d'execution de playbook
- creation du run
- comptage fiable `selected / processed / success / failed / skipped`
- bridge vers les actions bulk compatibles

#### Fichiers probables

- nouveau service dans `app/Services/Playbooks/`
- `tests/Feature/PlaybookExecutionPhaseThreeTest.php`

#### Livraison

- `PlaybookExecutionService` en place
- run manuel supporte pour:
  - `request -> assign_selected`
  - `request -> update_status`
  - `customer -> portal_enable / portal_disable / archive / restore`
  - `quote -> schedule_follow_up / mark_followed_up / create_follow_up_task / archive`
- verification des features avant execution:
  - `requests`
  - `quotes`
  - `tasks`
- mise a jour du segment:
  - `cached_count`
  - `last_resolved_at`
- `summary` du run aligne sur:
  - `message`
  - `ids`
  - `processed_ids`
  - `selected_count`
  - `processed_count`
  - `success_count`
  - `failed_count`
  - `skipped_count`
  - `errors`

#### Notes d'implementation

- preferer appeler le meme coeur que les bulk actions manuelles
- si `Quote` n'a pas encore de bulk module, ajouter un contrat explicite au lieu d'un hack
- verifier les features et permissions avant toute execution
- commencer par l'execution manuelle avant le scheduler

#### Tests a ajouter

- run manuel `request`
- run manuel `customer`
- run manuel `quote`
- refus si action incompatible
- respect du contrat `BulkActionResult`

#### Verification

```bash
php artisan test tests/Feature/PlaybookExecutionPhaseThreeTest.php
php artisan test tests/Feature/SegmentResolverPhaseThreeTest.php tests/Feature/SavedSegmentsPhaseThreeTest.php tests/Feature/QuoteRecoveryPhaseTwoTest.php tests/Feature/RequestInboxPhaseOneTest.php
php artisan test tests/Feature/CampaignsMarketingModuleTest.php --filter="segment"
php artisan test tests/Feature/BulkActionResultContractTest.php tests/Unit/SegmentResolverRegistryTest.php
```

#### Acceptance criteria

1. un playbook manuel peut etre lance
2. les compteurs du run sont fiables
3. les actions interdites ou indisponibles sont skips ou erreurs explicites

## 7. Sprint 6

Objectif:

- rendre les playbooks visibles, planifiables et audités de bout en bout

Tickets du sprint:

- `P3-005`
- `P3-006`
- `P3-007`
- `P3-008`

Sortie attendue:

- scheduler simple actif
- UI segments sauvegardes disponible
- historique de runs lisible
- non-regression bulk et campaigns verrouillee

### P3-005 - Playbook scheduler integration

#### But

Ajouter l'execution planifiee simple des playbooks.

#### Etat

- livre

#### Livrables

- commande ou job scheduler
- reservation d'un run
- prevention des overlaps
- mise a jour de `next_run_at`

#### Fichiers probables

- `app/Console/Kernel.php`
- nouveau job ou commande dans `app/Jobs/` ou `app/Console/Commands/`
- `tests/Feature/PlaybookSchedulerPhaseThreeTest.php`

#### Livraison

- `PlaybookSchedulerService` en place
- reservation d'un run planifie avant execution
- execution planifiee rebranchee sur `PlaybookExecutionService`
- prevention simple des overlaps sur runs `pending / running`
- calcul de `next_run_at` pour:
  - `daily`
  - `weekly`
- commande artisan:
  - `playbooks:run-scheduled`
  - support `--account_id`
- cron Laravel branche:
  - `everyFiveMinutes()`
  - `withoutOverlapping()`

#### Notes d'implementation

- commencer par `daily` et `weekly`
- ne pas lancer deux fois le meme playbook simultanement
- garder une origine `scheduled`
- journaliser les runs vides et les skips

#### Verification

```bash
php artisan test tests/Feature/PlaybookSchedulerPhaseThreeTest.php
php artisan test tests/Feature/PlaybookExecutionPhaseThreeTest.php tests/Feature/PlaybookSchedulerPhaseThreeTest.php tests/Feature/SegmentResolverPhaseThreeTest.php tests/Feature/SavedSegmentsPhaseThreeTest.php tests/Feature/QuoteRecoveryPhaseTwoTest.php tests/Feature/RequestInboxPhaseOneTest.php tests/Feature/BulkActionResultContractTest.php tests/Unit/SegmentResolverRegistryTest.php
php artisan test tests/Feature/CampaignsMarketingModuleTest.php --filter="segment"
```

#### Acceptance criteria

1. un playbook planifie peut etre reserve et lance
2. `next_run_at` evolue correctement
3. les overlaps sont evites

### P3-006 - Saved segment UI

#### But

Rendre les segments sauvegardes accessibles depuis les index `Request`, `Customer` et `Quote`.

#### Etat

- livre le `2026-04-20`

#### Livrables

- action `save current filter`
- selecteur ou liste de segments sauvegardes
- feedback de creation / edition / partage

#### Fichiers probables

- `resources/js/Pages/Request/Index.vue`
- `resources/js/Pages/Request/UI/RequestTable.vue`
- `resources/js/Pages/Customer/Index.vue`
- `resources/js/Pages/Customer/UI/CustomerTable.vue`
- `resources/js/Pages/Quote/Index.vue`
- `resources/js/Pages/Quote/UI/QuoteTable.vue`
- composant partageable eventuel dans `resources/js/Components/`

#### Notes d'implementation

- garder le pattern UI existant des filtres
- ne pas casser les quick filters de phase 1 et 2
- afficher seulement les segments compatibles avec le module courant

#### Livraison

- controller `SavedSegmentController` ajoute avec routes:
  - `crm.saved-segments.index`
  - `crm.saved-segments.store`
  - `crm.saved-segments.update`
  - `crm.saved-segments.destroy`
- composant partage `SavedSegmentBar` branche sur:
  - `RequestTable`
  - `CustomerTable`
  - `QuoteTable`
- props `savedSegments` et `canManageSavedSegments` exposes sur:
  - `Request/Index`
  - `Customer/Index`
  - `Quote/Index`
- gestion owner-only alignee pour la surface CRM:
  - `Request` deja owner-only
  - `Quote` owner-only pour la gestion des segments
  - `Customer` force owner-only pour cette V1 meme si l'index supporte d'autres scopes
- libelles i18n ajoutes en:
  - `fr`
  - `en`
  - `es`

#### Verification

```bash
php artisan test tests/Feature/SavedSegmentUiPhaseThreeTest.php tests/Feature/SavedSegmentsPhaseThreeTest.php tests/Feature/SegmentResolverPhaseThreeTest.php tests/Feature/PlaybookExecutionPhaseThreeTest.php
php artisan test tests/Feature/QuoteRecoveryPhaseTwoTest.php tests/Feature/RequestInboxPhaseOneTest.php
npm run build
```

#### Acceptance criteria

1. un utilisateur peut sauvegarder un filtre utile
2. il peut reappliquer un segment depuis l'index
3. l'UI reste legere et non intrusive

### P3-007 - Playbook run history UI

#### But

Afficher l'historique et l'audit des runs de playbook.

#### Etat

- livre le `2026-04-20`

#### Livrables

- liste des runs
- compteurs visibles
- statut global
- resume des erreurs ou skips

#### Livraison

- route `crm.playbook-runs.index` exposee
- controller dedie `PlaybookRunController`
- page Inertia `Campaigns/PlaybookRuns`
- filtrage simple `module / status / origin`
- compteurs de synthese visibles
- resume d'erreurs et de skips visible par run
- lien d'entree ajoute depuis les barres `SavedSegment` de:
  - `Request`
  - `Customer`
  - `Quote`
- couverture feature dediee ajoutee

#### Fichiers probables

- nouvelle page ou nouveau panneau dans `resources/js/Pages/Campaigns/` ou module dedie
- nouveau controller ou extension de controller existant
- `tests/Feature/PlaybookExecutionPhaseThreeTest.php`

#### Notes d'implementation

- rester proche des patterns deja utilises pour les campaigns runs
- ne pas imposer une UI lourde si un tableau clair suffit
- afficher les details seulement quand ils existent

#### Verification

```bash
php artisan test tests/Feature/PlaybookRunHistoryPhaseThreeTest.php
npm run build
```

#### Acceptance criteria

1. un manager peut voir les derniers runs
2. les compteurs et statuts sont lisibles
3. les erreurs principales sont diagnostiquables

### P3-008 - Non-regression bulk and campaign bridge suite

#### But

Verrouiller la phase 3 par tests feature et smoke E2E.

#### Etat

- livre le `2026-04-20`

#### Livrables

- extension des suites bulk et campaigns
- smoke E2E playbook
- verification des feature flags et des permissions

#### Fichiers probables

- `app/Http/Controllers/PlaybookController.php`
- `routes/web.php`
- `tests/Feature/SavedSegmentsPhaseThreeTest.php`
- `tests/Feature/PlaybookExecutionPhaseThreeTest.php`
- `tests/Feature/PlaybookSchedulerPhaseThreeTest.php`
- `tests/e2e/helpers/app.mjs`
- `tests/e2e/playbook-smoke.spec.js`

#### Livraison

- endpoints JSON `crm.playbooks.store` et `crm.playbooks.run` exposes pour creer et lancer un playbook manuel
- smoke E2E `save segment -> create playbook -> run -> history` branche sur le vrai contrat phase 3
- garde feature et permissions ajoutee sur:
  - `crm.saved-segments.*`
  - `crm.playbooks.*`
  - scheduler playbook si la feature `requests` disparait avant l'execution
- non-regression campaigns reverifiee avec coexistence `marketing.segments.*` et `saved_segments`
- UI smoke stabilisee avec `data-testid` sur la barre de segments sauvegardes et l'historique des runs

#### Scenarios minimum

- sauvegarder un segment `Request`
- lancer un playbook manuel dessus
- verifier les compteurs du run
- verifier qu'une feature absente masque ou bloque l'action
- verifier qu'un segment marketing existant reste fonctionnel

#### Acceptance criteria

1. les parcours critiques phase 3 sont testes
2. les bulk actions existantes restent vertes
3. les bridges campaigns ne regressent pas

#### Verification realisee

```powershell
php artisan test tests/Feature/SavedSegmentsPhaseThreeTest.php tests/Feature/PlaybookExecutionPhaseThreeTest.php tests/Feature/PlaybookSchedulerPhaseThreeTest.php tests/Feature/CampaignsMarketingModuleTest.php --filter="segment|playbook|crm playbook|saved segment|request playbook|feature gated"
php artisan test tests/Feature/CustomerBulkContactFeatureAvailabilityTest.php
npm run build
npx playwright test tests/e2e/playbook-smoke.spec.js
```

## 8. Ordre d'execution recommande

Ordre recommande:

1. `P3-001`
2. `P3-002`
3. `P3-003`
4. `P3-004`
5. `P3-005`
6. `P3-006`
7. `P3-007`
8. `P3-008`

Regle:

- ne pas ouvrir l'UI avant d'avoir le contrat data et execution
- ne pas brancher le scheduler avant d'avoir un run manuel stable

## 9. Commandes de verification recommandees

Pendant la phase 3, la verification minimale recommandee est:

```powershell
php artisan test tests/Unit/BulkActionRegistryTest.php
php artisan test tests/Feature/BulkActionResultContractTest.php
php artisan test tests/Feature/CustomerBulkContactFeatureAvailabilityTest.php
php artisan test tests/Feature/CampaignsMarketingModuleTest.php
php artisan test tests/Feature/WorkflowLeadTest.php
php artisan test tests/Feature/QuoteRecoveryPhaseTwoTest.php
npm run build
```

En sortie de phase:

```powershell
php artisan test tests/Feature/SavedSegmentsPhaseThreeTest.php
php artisan test tests/Feature/PlaybookExecutionPhaseThreeTest.php
php artisan test tests/Feature/PlaybookSchedulerPhaseThreeTest.php
npx playwright test tests/e2e/playbook-smoke.spec.js
```
