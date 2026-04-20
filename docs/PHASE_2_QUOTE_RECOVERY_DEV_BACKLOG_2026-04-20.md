# Phase 2 quote recovery dev backlog

Derniere mise a jour: 2026-04-20

## 0. Etat d'avancement implementation

Suivi courant:

- `P2-001` fait
- `P2-002` fait
- `P2-003` fait
- `P2-004` fait
- `P2-005` fait
- `P2-006` fait
- `P2-007` fait
- `P2-008` fait

Dernier bloc livre:

- phase 1 request inbox complete
- smoke E2E request inbox vert
- cloture sprint 2
- schema additif quote recovery
- suite feature phase 2 initiale
- verification workflow quote et workflow lead quote
- quote recovery query
- integration controller quote index
- couverture des queues et filtres recovery
- quote priority scorer
- labels et raisons de priorite recovery
- tri par priorite stable cote backend
- quote recovery analytics
- widgets manager recovery sur l'index quote
- labels analytics recovery cote frontend
- quick filters recovery sur l'index quote
- badges de queue et priorite visibles dans table et cartes
- signaux d'anciennete et prochaine action ajoutes sans casser la table
- quick actions recovery en place sur table et cartes
- endpoint leger de relance et creation de tache dediee au devis
- couverture feature des quick actions recovery
- ActivityLog recovery branche sur les relances devis et creation de tache
- timeline legere visible sur la fiche devis
- non-regression sur les messages et l'absence de doublons de log
- smoke E2E quote recovery ajoute
- verification du flux acceptation devis et sync `Quote -> Request status`
- cloture phase 2 quote recovery

Bloc actuellement en cours:

- phase 2 quote recovery complete
- sprint 4 cloture
- phase 3 saved segments and scheduled playbooks ouverte
- sprint 5 en preparation

## 1. But du document

Ce document transforme la phase 2 en backlog dev directement executable.

Le but est simple:

- savoir quoi coder d'abord
- savoir quels fichiers toucher
- savoir quels tests creer
- savoir quand un sprint est reellement termine

## 2. Rappel du scope phase 2

La phase 2 doit transformer `Quote` en cockpit de recovery commercial, sans casser:

- la vue index existante
- la creation et edition de devis
- le portail public de devis
- l'acceptation `Quote -> Work`
- la synchronisation `Quote -> Request`

Resultat V1 attendu:

- files `never_followed / due / viewed_not_accepted / expired / high_value`
- signaux de priorite et d'anciennete
- actions rapides de relance
- KPI de conversion devis

## 3. Regles dev de la phase 2

Regles non negociables:

1. aucun renommage de routes `quote.*`
2. aucune rupture du workflow `Request -> Quote -> Work`
3. migration additive uniquement
4. toute logique de recovery doit vivre cote backend, pas seulement dans Vue
5. le portail public doit rester fonctionnel pendant toute la phase

## 4. Fichiers coeur a proteger

### Backend

- `app/Models/Quote.php`
- `app/Http/Controllers/QuoteController.php`
- `app/Http/Controllers/Portal/PortalQuoteController.php`
- `app/Http/Controllers/PublicQuoteController.php`
- `app/Actions/Quotes/UpsertQuoteAction.php`
- `app/Actions/Leads/ConvertLeadRequestToQuoteAction.php`

### Frontend

- `resources/js/Pages/Quote/Index.vue`
- `resources/js/Pages/Quote/Create.vue`
- `resources/js/Pages/Quote/Show.vue`
- `resources/js/Pages/Quote/UI/QuoteTable.vue`
- `resources/js/Pages/Quote/UI/QuoteActionsMenu.vue`
- `resources/js/Components/UI/QuoteStats.vue`

### Tests existants a proteger

- `tests/Feature/WorkflowTest.php`
- `tests/Feature/WorkflowLeadTest.php`
- `tests/Feature/FinanceApprovalWorkflowTest.php`

## 5. Tests a creer pour la phase 2

Suites recommandees:

- `tests/Feature/QuoteRecoveryPhaseTwoTest.php`
- `tests/Feature/QuoteRecoveryAnalyticsPhaseTwoTest.php`
- `tests/e2e/quote-recovery-smoke.spec.js`

Regle:

- ne pas tout empiler dans `WorkflowTest`
- creer une vraie suite dediee au recovery quote

## 6. Sprint 3

Objectif:

- poser le socle data et metier du recovery devis

Tickets du sprint:

- `P2-001`
- `P2-002`
- `P2-003`

Sortie attendue:

- schema additif pret
- query de recovery disponible
- priorisation simple exploitable

### P2-001 - Schema additif Quote Recovery

#### But

Ajouter les champs minimaux de suivi recovery sur `quotes`.

#### Etat

- livre le `2026-04-20`
- migration additive en place
- model `Quote` aligne
- suite feature phase 2 creee

#### Livrables

- migration additive
- mise a jour du model `Quote`
- casts et fillable alignes

#### Fichiers probables

- nouveau fichier dans `database/migrations/`
- `app/Models/Quote.php`

#### Champs recommandes

- `last_sent_at`
- `last_viewed_at`
- `last_followed_up_at`
- `next_follow_up_at`
- `follow_up_state`
- `follow_up_count`
- `recovery_priority`

#### Notes d'implementation

- garder tous les champs nullable en V1
- preferer des index sur `status`, `next_follow_up_at`, `archived_at`
- ne pas persister `quote_age_days`
- n'ajouter `expires_at` que si le besoin metier est confirme

#### Tests a ajouter

- assertions de casts et persistance dans `QuoteRecoveryPhaseTwoTest.php`

#### Acceptance criteria

1. la migration passe en sqlite et mysql
2. le model `Quote` expose les nouveaux champs
3. aucune route existante ne casse apres migration

#### Verification realisee

```powershell
php artisan test tests/Feature/QuoteRecoveryPhaseTwoTest.php
php artisan test tests/Feature/WorkflowTest.php
php artisan test tests/Feature/WorkflowLeadTest.php
```

### P2-002 - Quote recovery query

#### But

Centraliser le calcul des segments `never_followed / due / viewed_not_accepted / expired / high_value`.

#### Etat

- livre le `2026-04-20`
- query `Quote` centralisee cote backend
- index `Quote` aligne sur les queues recovery
- filtres `queue` disponibles via controller

#### Livrables

- query ou builder dedie
- sortie stable pour index et analytics
- filtres exploitables via controller

#### Fichiers probables

- nouveau query object, par exemple `app/Queries/Quotes/BuildQuoteRecoveryIndexData.php`
- `app/Http/Controllers/QuoteController.php`
- `tests/Feature/QuoteRecoveryPhaseTwoTest.php`

#### Regles V1 recommandees

- `never_followed`
  - quote `sent`
  - `follow_up_count = 0`
- `due`
  - quote `sent`
  - `next_follow_up_at` echu ou proche
- `viewed_not_accepted`
  - quote `sent`
  - `last_viewed_at` non nul
- `expired`
  - quote `sent`
  - age superieur au seuil V1
- `high_value`
  - quote ouverte
  - `total` au-dessus du seuil V1

#### Notes d'implementation

- ne jamais inclure `accepted`, `declined` ou `archived` dans les queues ouvertes
- garder les seuils simples, constants au debut
- preparer une sortie stable qui pourra etre reconsommee par controller et analytics

#### Tests a ajouter

- quote never followed
- quote due
- quote viewed not accepted
- quote expired
- quote accepted exclue des queues ouvertes

#### Acceptance criteria

1. les segments sont calcules cote backend
2. l'index `Quote` peut filtrer ces segments
3. les devis acceptes et archives ne polluent pas le cockpit

#### Verification realisee

```powershell
php artisan test tests/Feature/QuoteRecoveryPhaseTwoTest.php
php artisan test tests/Feature/WorkflowTest.php
php artisan test tests/Feature/WorkflowLeadTest.php
```

### P2-003 - Quote priority scorer

#### But

Donner un ordre de traitement simple et stable aux devis ouverts.

#### Etat

- livre le `2026-04-20`
- service de scoring dedie en place
- score, label et raison exposes par l'index `Quote`
- tri par priorite disponible par defaut

#### Livrables

- service de scoring simple
- score ou label de priorite exploitable par l'UI
- regles lisibles et testees

#### Fichiers probables

- nouveau service, par exemple `app/Services/Quotes/QuoteRecoveryPriorityScorer.php`
- `app/Queries/Quotes/BuildQuoteRecoveryIndexData.php`
- `tests/Feature/QuoteRecoveryPhaseTwoTest.php`

#### Regles V1 recommandees

- vue recente + non acceptee > priorite haute
- relance due > priorite haute
- montant eleve > priorite renforcee
- devis tres ancien sans action > priorite moyenne ou froide selon etat

#### Notes d'implementation

- garder un score simple, pas de pseudo IA opaque
- expliquer le score dans les metadonnees si possible
- eviter toute dependance a des settings non existants en V1

#### Acceptance criteria

1. l'ordre de tri des devis est explicable
2. le score peut etre affiche en UI sans logique dupliquee
3. le classement reste stable entre requetes identiques

#### Verification realisee

```powershell
php artisan test tests/Feature/QuoteRecoveryPhaseTwoTest.php
php artisan test tests/Feature/WorkflowTest.php
php artisan test tests/Feature/WorkflowLeadTest.php
```

## 7. Sprint 4

Objectif:

- rendre le cockpit visible, actionnable et teste

Tickets du sprint:

- `P2-004`
- `P2-005`
- `P2-006`
- `P2-007`
- `P2-008`

Sortie attendue:

- analytics recovery visibles
- UI cockpit exploitable
- actions rapides de suivi
- activite journalisee
- couverture non-regression solide

### P2-004 - Quote recovery analytics

#### But

Ajouter les KPI manager du recovery devis.

#### Etat

- livre le `2026-04-20`
- query analytics recovery en place
- stats `Quote` enrichies cote backend
- widgets manager visibles dans `QuoteStats`

#### Livrables

- stats enrichies cote backend
- widgets manager cote frontend
- traduction des nouveaux labels

#### Fichiers probables

- nouveau query object, par exemple `app/Queries/Quotes/BuildQuoteRecoveryAnalyticsData.php`
- `app/Http/Controllers/QuoteController.php`
- `resources/js/Components/UI/QuoteStats.vue`
- fichiers `resources/js/i18n/modules/*/quotes.json`

#### KPI minimum

- devis ouverts
- devis sans relance
- relances dues
- devis a forte valeur ouverts
- taux `sent -> accepted`

#### Acceptance criteria

1. les KPI reflètent les memes segments que l'index
2. les stats restent coherentes avec les filtres
3. aucun widget existant n'est casse

#### Verification realisee

```powershell
php artisan test tests/Feature/QuoteRecoveryAnalyticsPhaseTwoTest.php
php artisan test tests/Feature/QuoteRecoveryPhaseTwoTest.php
php artisan test tests/Feature/WorkflowTest.php
php artisan test tests/Feature/WorkflowLeadTest.php
npm run build
```

### P2-005 - Quote cockpit UI

#### But

Faire de l'index `Quote` une vraie vue de travail quotidienne.

#### Etat

- livre le `2026-04-20`
- quick filters recovery visibles sur l'index
- table `Quote` alignee sur les signaux recovery du backend
- vue cartes enrichie avec priorite, queue, anciennete et prochaine action

#### Livrables

- quick filters de recovery
- badges de queue et priorite
- signaux d'anciennete et prochaine action
- visibilite des devis critiques

#### Fichiers probables

- `resources/js/Pages/Quote/Index.vue`
- `resources/js/Pages/Quote/UI/QuoteTable.vue`
- eventuellement `resources/js/Pages/Quote/UI/QuoteActionsMenu.vue`

#### Notes d'implementation

- conserver la lisibilite de la table actuelle
- rester additif et proche des patterns de la phase 1
- reutiliser les composants existants si possible

#### Acceptance criteria

1. les queues sont filtrables en un clic
2. les devis critiques sautent aux yeux
3. la table reste claire sur desktop et mobile

#### Verification realisee

```powershell
php artisan test tests/Feature/QuoteRecoveryAnalyticsPhaseTwoTest.php
php artisan test tests/Feature/QuoteRecoveryPhaseTwoTest.php
npm run build
```

### P2-006 - Quote follow-up actions

#### But

Reduire le nombre de clics pour piloter les relances devis.

#### Etat

- livre le `2026-04-20`
- presets de relance rapide visibles sur table et cartes
- action rapide `fait`, creation de tache et archivage visibles depuis l'index
- endpoints recovery additifs en place dans `QuoteController`

#### Livrables

- action rapide `planifier relance`
- action rapide `marquer relance faite`
- action rapide `creer tache`
- action rapide `archiver`

#### Fichiers probables

- `resources/js/Pages/Quote/UI/QuoteTable.vue`
- `resources/js/Pages/Quote/UI/QuoteActionsMenu.vue`
- `app/Http/Controllers/QuoteController.php`

#### Notes d'implementation

- reutiliser les endpoints existants si possible
- si un nouvel endpoint est necessaire, le garder minimal et sans rupture
- ne pas casser l'edition complete du devis

#### Acceptance criteria

1. les actions critiques sont visibles depuis l'index
2. l'utilisateur n'a pas besoin d'ouvrir chaque devis pour agir
3. l'archivage reste reversible comme aujourd'hui

#### Verification realisee

```powershell
php artisan test tests/Feature/QuoteRecoveryPhaseTwoTest.php
php artisan test tests/Feature/QuoteRecoveryAnalyticsPhaseTwoTest.php
npm run build
```

### P2-007 - ActivityLog integration quote follow-up

#### But

Tracer proprement les relances et prochaines actions sur les devis.

#### Etat

- livre le `2026-04-20`
- evenements recovery ajoutes dans `QuoteController`
- timeline legere visible dans `Quote/Show`
- couverture feature ajoutee pour l'ordre, les messages et l'absence de doublons

#### Livrables

- evenements `ActivityLog` pour suivi devis
- messages lisibles
- timeline legere exploitable par l'UI

#### Fichiers probables

- `app/Models/ActivityLog.php`
- `app/Http/Controllers/QuoteController.php`
- eventuellement `resources/js/Pages/Quote/Show.vue`

#### Notes d'implementation

- rester coherent avec la convention de logging existante
- privilegier des payloads simples
- ne pas noyer le journal avec du bruit faible valeur

#### Acceptance criteria

1. une relance ou planification cree une trace utile
2. la timeline peut etre lue sans ambiguite
3. aucune activite parasite n'est generee en double

#### Verification realisee

```powershell
php artisan test tests/Feature/QuoteRecoveryPhaseTwoTest.php
npm run build
```

### P2-008 - Non-regression quote workflow suite

#### But

Fermer proprement la phase 2 avec une vraie couverture.

#### Etat

- livre le `2026-04-20`
- smoke E2E `quote recovery` en place
- couverture workflow `accept -> work edit -> request won` verifiee
- hooks UI minimaux ajoutes pour fiabiliser le smoke sans alourdir la table

#### Livrables

- tests feature quote recovery
- tests analytics quote
- smoke E2E quote recovery

#### Fichiers probables

- `tests/Feature/QuoteRecoveryPhaseTwoTest.php`
- `tests/Feature/QuoteRecoveryAnalyticsPhaseTwoTest.php`
- `tests/e2e/quote-recovery-smoke.spec.js`

#### Cas minimum a couvrir

- ouverture `quote.index`
- filtre queue
- mise a jour relance
- archivage
- acceptation quote
- sync `Quote -> Request status`

#### Acceptance criteria

1. la phase 2 a une suite test dediee
2. le workflow principal reste couvert
3. la regression la plus probable est capturee avant merge

#### Verification realisee

```powershell
php artisan test tests/Feature/QuoteRecoveryPhaseTwoTest.php
php artisan test tests/Feature/QuoteRecoveryAnalyticsPhaseTwoTest.php
php artisan test tests/Feature/WorkflowTest.php
php artisan test tests/Feature/WorkflowLeadTest.php
npx playwright test tests/e2e/quote-recovery-smoke.spec.js
npm run build
```

### Gate de sortie Sprint 4

Le sprint 4 est termine si:

1. la table expose les queues de recovery
2. les analytics sont enrichies
3. les actions rapides de suivi sont utilisables
4. le logging d'activite est en place
5. les tests phase 2 sont verts

## 8. Ordre d'execution recommande

L'ordre recommande est:

1. `P2-001`
2. `P2-002`
3. `P2-003`
4. `P2-004`
5. `P2-005`
6. `P2-006`
7. `P2-007`
8. `P2-008`

Regle:

- ne pas commencer les quick actions tant que la query et la priorisation ne sont pas stables

## 9. Commandes de verification recommandees

Pendant la phase:

```powershell
php artisan test tests/Feature/WorkflowTest.php
php artisan test tests/Feature/WorkflowLeadTest.php
php artisan test tests/Feature/QuoteRecoveryPhaseTwoTest.php
php artisan test tests/Feature/QuoteRecoveryAnalyticsPhaseTwoTest.php
npm run build
```

Avant cloture:

```powershell
php artisan test
npx playwright test tests/e2e/quote-recovery-smoke.spec.js
```

## 10. Definition de done de la phase 2

La phase 2 est terminee si:

1. les champs additifs sont en place
2. les queues de recovery sont calculees cote backend
3. l'index `Quote` permet de filtrer ces queues
4. le portail public reste stable
5. l'acceptation `Quote -> Work` reste intacte
6. une suite feature et un smoke E2E existent pour cette phase

## 11. Documents lies

- `docs/PHASE_2_QUOTE_RECOVERY_CONVERSION_COCKPIT_2026-04-20.md`
- `docs/CRM_DEV_EXECUTION_PHASES_2026-04-20.md`
- `docs/PLAN_STRATEGIQUE_CRM_COMPETITIF_2026-04-20.md`
