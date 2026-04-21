# Phase 3 CRM - Saved Segments and Scheduled Playbooks

Derniere mise a jour: 2026-04-20

## 1. But de la phase 3

La phase 3 transforme les filtres repetitifs et les actions bulk manuelles en routines reexecutables.

Le but n'est pas de construire tout de suite un moteur d'automation enterprise.

Le but est de rendre persistantes, pilotables et auditables les routines les plus utiles sur:

1. `Request`
2. `Customer`
3. `Quote`

La phase 3 doit reduire le travail repetitif sans casser les modules de base ni multiplier les chemins d'execution.

## 2. Decision produit

Decision retenue:

- la phase 3 reste `operator-in-the-loop` en V1
- aucun builder low-code libre type `if / else / branch / delay` en V1
- aucune orchestration externe multi-app en V1
- aucune execution silencieuse sans historique de run
- aucun contournement des feature flags ni des permissions existantes

La phase 3 doit etre une evolution additive de:

- `app/Support/BulkActions/BulkActionRegistry.php`
- `app/Services/Campaigns/SegmentService.php`
- `app/Services/Campaigns/AudienceResolver.php`
- `app/Services/Campaigns/CampaignAutomationService.php`
- `app/Services/CompanyFeatureService.php`
- `app/Http/Controllers/CustomerController.php`
- `app/Http/Controllers/RequestController.php`
- `app/Http/Controllers/QuoteController.php`
- `resources/js/Pages/Customer/Index.vue`
- `resources/js/Pages/Request/Index.vue`
- `resources/js/Pages/Quote/Index.vue`
- `resources/js/Pages/Campaigns/Components/SegmentManager.vue`

## 3. Baseline actuelle observee

La plateforme a deja plusieurs briques solides:

- `AudienceSegment` existe deja pour les audiences marketing
- `SegmentService` et `MarketingSegmentController` savent deja stocker et recalculer des segments campaigns
- `AudienceResolver` sait resoudre des audiences avec exclusions, canaux et eligibilite
- `BulkActionRegistry` existe deja pour `customer`, `product` et `request`
- les modules `Request` et `Customer` ont deja des bulk actions visibles en UI
- `Quote` a maintenant des quick actions recovery, mais pas encore de bulk action module standardise
- `CampaignAutomationService` et `CampaignRun` prouvent qu'un pattern d'execution et d'audit existe deja
- `CompanyFeatureService` permet deja de masquer ou interdire certaines capacites par plan

Cela veut dire que la phase 3 n'est pas une creation from scratch.

C'est une industrialisation des patterns deja presents dans le produit.

## 4. Probleme que la phase 3 doit resoudre

Aujourd'hui, l'utilisateur peut souvent:

- filtrer une liste
- selectionner manuellement des lignes
- lancer une action

Mais il ne peut pas encore faire proprement:

- sauvegarder la requete utile pour la rejouer demain
- rattacher une action recurrente a cette selection
- planifier cette action
- auditer ce qui a vraiment ete traite

Le risque actuel est simple:

- les bonnes routines existent dans la tete de l'equipe
- mais elles ne vivent pas encore comme objets persistants du produit

## 5. Resultat attendu de la phase 3

En sortie de phase 3, un owner ou manager doit pouvoir:

1. sauvegarder un filtre utile sur `Request`, `Customer` ou `Quote`
2. nommer ce segment et le partager si besoin
3. lui rattacher une action compatible
4. lancer cette action immediatement ou a heure fixe
5. voir un historique clair des runs avec:
   - `selected`
   - `processed`
   - `success`
   - `failed`
   - `skipped`

## 6. Scope fonctionnel V1

### 6.1 Segments sauvegardes

La V1 doit permettre de sauvegarder des segments sur:

- `request`
- `customer`
- `quote`

Chaque segment V1 doit au minimum porter:

- le module cible
- un nom
- une description courte optionnelle
- les filtres serialises
- l'eventuel tri utile
- un flag `is_shared`
- un `cached_count`
- une date de dernier calcul

### 6.2 Playbooks

Un playbook V1 doit etre:

- lie a un segment sauvegarde
- lie a un module
- lie a une action compatible
- configurable avec un payload simple
- executable manuellement
- executable selon une cadence simple

Exemples V1 de playbooks utiles:

- assigner les nouveaux leads entrants a un rep
- pousser une relance simple sur des leads stale
- creer des taches pour les devis dus
- archiver des devis froids
- relancer un groupe de clients inactifs si le module contact est disponible

### 6.3 Execution manuelle

La V1 doit permettre un `Run now` sur un playbook.

Regle:

- le playbook doit reutiliser le plus possible les memes handlers que les bulk actions manuelles
- il ne doit pas introduire un deuxieme chemin metier divergent

### 6.4 Execution planifiee

La V1 doit supporter une planification simple:

- `manual`
- `daily`
- `weekly`

Le besoin n'est pas un cron editor libre.

Le besoin est une planification stable et comprehensible.

### 6.5 Historique de run

Chaque run doit exposer:

- son origine `manual` ou `scheduled`
- sa date de lancement
- sa date de fin
- son statut global
- ses compteurs
- un resume lisible en cas d'erreurs ou de skips

## 7. Evolutions de donnees recommandees

Pour rester additives et compatibles avec l'existant, la phase 3 doit introduire des tables dediees.

### 7.1 Table `saved_segments`

Champs recommandes:

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

### 7.2 Table `playbooks`

Champs recommandes:

- `user_id`
- `saved_segment_id`
- `created_by_user_id`
- `updated_by_user_id`
- `module`
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

### 7.3 Table `playbook_runs`

Champs recommandes:

- `user_id`
- `playbook_id`
- `saved_segment_id`
- `requested_by_user_id`
- `module`
- `action_key`
- `origin`
- `status`
- `selected_count`
- `processed_count`
- `success_count`
- `failed_count`
- `skipped_count`
- `started_at`
- `finished_at`
- `summary`

### 7.4 Table `playbook_run_items`

Cette table n'est pas strictement obligatoire en toute premiere iteration.

Mais elle est recommandee si on veut:

- tracer les erreurs par element
- afficher une vraie UI d'audit
- faciliter le debug des runs partiellement reussis

Champs recommandes:

- `playbook_run_id`
- `subject_type`
- `subject_id`
- `status`
- `message`
- `metadata`

## 8. Regles de resolution et d'execution recommandees

Les regles doivent rester simples, explicables et compatibles avec la base actuelle.

### 8.1 Resolution des segments

Chaque module doit avoir son resolver backend:

- `request`
  - reutiliser les patterns de triage et de filtres de la phase 1
- `customer`
  - reutiliser les patterns de filtres customer et, quand utile, les conventions d'audience campaigns
- `quote`
  - reutiliser les patterns de recovery et de priorisation de la phase 2

Regle:

- la resolution se fait cote backend
- on ne reconstruit pas la logique du segment dans Vue

### 8.2 Compatibilite des actions

Un playbook ne doit pouvoir proposer qu'une action compatible avec son module.

Exemples V1:

- `request`
  - assignation
  - mise a jour de statut
  - planification de follow-up
- `customer`
  - tagging
  - bulk contact si la feature est active
  - pont vers campagnes si la feature est active
- `quote`
  - planification de relance
  - creation de tache
  - archivage

Note importante:

- `Quote` ne dispose pas aujourd'hui d'un `BulkActionModule` standardise
- la phase 3 doit donc soit introduire `QuoteBulkActionModule`, soit un bridge d'execution explicite et borne

### 8.3 Cadence et securite d'execution

Les regles recommandees V1 sont:

- un seul run actif par playbook a la fois
- limite de taille configurable plus tard, mais borne V1 obligatoire
- execution asynchrone pour les runs de taille non triviale
- mise a jour de `next_run_at` seulement apres reservation ou completion claire du run
- comptage fiable meme si certains elements sont skips

### 8.4 Audit et idempotence

Chaque run doit laisser une trace meme s'il ne traite rien.

Exemples:

- segment vide
- action non disponible pour le plan courant
- permission absente
- run annule car deja en cours

Le systeme doit preferer:

- `skipped`
- `failed`
- `completed`

plutot qu'une execution silencieuse ou opaque.

## 9. Regles anti-regression

La phase 3 doit respecter des contraintes fortes:

1. aucune rupture des segments marketing existants
2. aucune rupture des bulk actions `Customer` et `Request`
3. aucune execution de playbook ne doit contourner les policies, permissions ou feature flags
4. les actions de playbook doivent reutiliser le meme coeur metier que les actions bulk quand c'est possible
5. `Quote` ne doit pas recevoir une couche bulk fragile qui casserait le recovery livre en phase 2
6. migrations additives uniquement
7. aucune UI de segment ou playbook ne doit devenir visible si la capacite necessaire n'est pas activee

## 10. Fichiers coeur a proteger

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
- `app/Models/AudienceSegment.php`

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

## 11. Tests recommandes pour la phase 3

Suites recommandees:

- `tests/Feature/SavedSegmentsPhaseThreeTest.php`
- `tests/Feature/PlaybookExecutionPhaseThreeTest.php`
- `tests/Feature/PlaybookSchedulerPhaseThreeTest.php`
- `tests/Unit/SegmentResolverRegistryTest.php`
- `tests/e2e/playbook-smoke.spec.js`

Regle:

- ne pas entasser toute la logique dans les tests campaigns existants
- creer une vraie couche de tests dediee aux segments sauvegardes et playbooks

## 12. Gate de sortie de la phase 3

La phase 3 n'est consideree complete que si:

1. un segment peut etre sauvegarde sur `Request`, `Customer` et `Quote`
2. un playbook compatible peut etre cree et execute manuellement
3. un playbook peut etre planifie avec une cadence simple
4. un historique de run expose `selected / processed / success / failed / skipped`
5. les feature flags et permissions sont respectes partout
6. les workflows bulk existants restent intacts
7. les tests de la phase et les smokes lies sont verts

## 13. Documents de reference

- `docs/CRM_DEV_EXECUTION_PHASES_2026-04-20.md`
- `docs/PLAN_STRATEGIQUE_CRM_COMPETITIF_2026-04-20.md`
- `docs/NEXT_HIGH_VALUE_MODULES_USER_STORY.md`
- `docs/CAMPAIGNS_MODULE.md`
