# Phase 6 opportunity layer, sales inbox, forecast dev backlog

Derniere mise a jour: 2026-04-21

## 0. Etat d'avancement implementation

Suivi courant:

- `P6-001` fait
- `P6-002` fait
- `P6-003` fait
- `P6-004` fait
- `P6-005` fait
- `P6-006` fait
- `P6-007` fait
- `P6-008` fait

Dernier bloc livre:

- contrat backend `opportunity_validation` ajoute sur le payload pipeline CRM
- decision `request_quote_first` formalisee avec `requires_opportunity = false`
- ancres `current / forecast / next_action` exposees pour preparer le pipeline commercial phase 6
- preuve explicite que les sources aval `invoice / job` restent commercialement rattachees au `Quote`
- suite feature dediee ajoutee pour verrouiller la decision phase 6 avant toute migration `Opportunity`
- contrat backend `opportunity` ajoute comme projection non persistante `Request / Quote`
- stage / forecast / next_action / anchors normalises pour les tickets `P6-003` a `P6-006`
- identifiant de projection stable ancre sur `Request` avec fallback `Quote`
- suite feature dediee ajoutee pour verrouiller les cas `request / quote / invoice / declined`
- query backend `sales pipeline` ajoutee sur la base du contrat `opportunity`
- stats `open / won / lost / weighted_open_amount / overdue_next_actions` normalisees
- board de stages `intake / contacted / qualified / quoted / won / lost` expose
- support des opportunites `request-backed` et `quote-only` verrouille en suite feature dediee
- sales inbox V1 ajoutee comme vue commerciale queue-first sur les opportunites ouvertes
- queues `overdue / no_next_action / quoted / needs_quote / active` normalisees
- acces manager `sales.manage` verrouille sans rouvrir l inbox aux membres standard
- page Inertia, route CRM et entree workspace hub revenue branchees sur le contrat phase 6
- service forecast V1 ajoute comme couche backend reutilisable sur la projection `Opportunity`
- snapshot `weighted_open_amount`, categories forecast, aging, next actions et wins periodises exposes
- filtres `search / customer_id / reference_time` poses pour les futurs dashboards manager
- suite feature dediee ajoutee pour verrouiller le calcul forecast sans UI prematuree
- dashboard manager revenue ajoute sur `forecast + pipeline + sales inbox`
- cartes `weighted open / month_to_date won / overdue next actions / quote pull-through` exposees
- vue `stage aging`, pression des queues et items d attention branchees pour owners et managers `sales.manage`
- page Inertia, route CRM et entree workspace hub revenue ajoutees pour la lecture manager phase 6
- contrat `crm_links` ajoute sur la projection `Opportunity` et les items pipeline / sales inbox
- ancres `subject / primary / request / quote / customer / job / invoice` normalisees pour la navigation cross-object phase 6
- actions UI `Request / Quote` alignees sur le meme contrat dans la sales inbox et le dashboard manager
- suite feature dediee ajoutee pour verrouiller le linking `request-backed / quote-only / downstream`
- suite `CrmFullNonRegressionPhaseSixTest` ajoutee pour couvrir le flux CRM transversal `Request / Quote / Customer / Pipeline / Next actions / Sales inbox / Manager dashboard`
- non-regression phase 4 et 5 reverifiee avec les suites detail, timeline et `My next actions` apres fermeture de la phase 6
- phase 6 verrouillee par un bundle complet `validation / schema / linking / pipeline / inbox / forecast / manager / full regression`
- ecrans revenue CRM `my next actions / sales inbox / manager dashboard` explicitement rattaches au module forfaitaire `sales` pour les routes, le workspace hub et les overrides super-admin
- aucun nouveau quota phase 6 ajoute: la couche revenue reutilise les limites existantes `requests / quotes / jobs / tasks`
- documentation de synthese et guide de seed local ajoutes pour la phase 6 dans `docs/CRM_PHASE_6_OPPORTUNITY_LAYER_GUIDE_2026-04-21.md`
- `LaunchSeeder` enrichi avec un dataset phase 6 deterministic et une date de reference stable `2026-04-25T09:00:00-04:00`

Bloc actuellement en cours:

- sprint 10 ouvert
- phase 6 demarree sans table `Opportunity`
- socle de projection `Opportunity` livre sans table persistante
- pipeline commercial, sales inbox V1, forecast service V1, linking cross-object et suite full non-regression livres pour cloturer la phase 6

## 1. But du document

Ce document transforme la phase 6 en backlog dev directement executable.

Le but est simple:

- ouvrir une couche commerciale plus sales-first sans casser le workflow coeur `Request / Quote / Customer`
- prouver d abord si un objet `Opportunity` est necessaire
- construire ensuite pipeline, inbox et forecast sur une base validee

## 2. Rappel du scope phase 6

La phase 6 doit apporter:

- une lecture pipeline commerciale plus generique
- une inbox commerciale simple
- un forecast simple exploitable
- des vues manager lisibles

La phase 6 ne doit pas lancer trop tot:

- un schema `Opportunity` sans preuve
- un forecast enterprise
- une inbox sales lourde type sequence engine
- une telephonie native complete

## 3. Regles dev de la phase 6

Regles non negociables:

1. ne pas creer de table `Opportunity` avant validation explicite du besoin
2. reutiliser `Request`, `Quote`, `Task`, `ActivityLog` et le graphe pipeline existant tant que possible
3. garder le pipeline commercial additif par rapport au workflow service/operations deja en place
4. proteger les contrats `Request / Quote / Customer / Pipeline` avec des tests dedies
5. ne pas lancer l inbox commerciale avant d avoir un contrat clair sur les ancres et le tri

## 4. Fichiers coeur a proteger

### Backend

- `app/Http/Controllers/PipelineController.php`
- `app/Models/Request.php`
- `app/Models/Quote.php`
- `app/Models/ActivityLog.php`
- `app/Support/CRM/OpportunityNeedValidation.php`

### Tests existants a proteger

- `tests/Feature/PipelineApiTest.php`
- `tests/Feature/MyNextActionsPhaseFourTest.php`
- `tests/Feature/ActivityTimelineConnectorIngressPhaseFiveTest.php`

## 5. Tests a creer pour la phase 6

Suites recommandees:

- `tests/Feature/OpportunityNeedValidationPhaseSixTest.php`
- `tests/Feature/SalesPipelineQueryPhaseSixTest.php`
- `tests/Feature/SalesInboxPhaseSixTest.php`
- `tests/Feature/ForecastServicePhaseSixTest.php`
- `tests/Feature/ManagerDashboardPhaseSixTest.php`
- `tests/Feature/CrmFullNonRegressionPhaseSixTest.php`

Regle:

- garder les nouveaux contrats phase 6 dans des suites dediees
- ne pas disperser la validation `Opportunity / pipeline / forecast` dans des tests generiques

## 6. Sprint 10

Objectif:

- verrouiller le besoin `Opportunity` avant toute migration ou nouveau board pipeline

### P6-001 - Opportunity need validation

#### But

Valider explicitement si la phase 6 peut rester `Request / Quote-first` sans nouvel objet `Opportunity`.

#### Etat

- livre le `2026-04-21`
- contrat `app/Support/CRM/OpportunityNeedValidation.php` ajoute
- payload pipeline enrichi avec `opportunity_validation`
- suite `tests/Feature/OpportunityNeedValidationPhaseSixTest.php` ajoutee

#### Livrables

- decision backend stable `request_quote_first`
- drapeau explicite `requires_opportunity = false`
- ancres normalisees `current_anchor / forecast_anchor / next_action_anchor`
- triggers documentes pour savoir quand promouvoir vers un vrai objet `Opportunity`

#### Fichiers touches

- `app/Support/CRM/OpportunityNeedValidation.php`
- `app/Http/Controllers/PipelineController.php`
- `tests/Feature/OpportunityNeedValidationPhaseSixTest.php`

#### Notes d'implementation

- brancher la validation sur le payload pipeline existant au lieu d introduire une nouvelle route
- considerer `Quote` comme ancre commerciale des objets aval quand il existe
- garder le forecast V1 ancre sur le montant du devis tant qu un objet `Opportunity` n est pas prouve
- exposer des triggers precis pour l ouverture future de `P6-002`

#### Tests ajoutes

- flux `Request` seul avec prochaine action sur la demande
- flux aval `Invoice` confirme comme commercialement ancre sur le `Quote`
- non-regression `PipelineApiTest` reverifiee apres enrichissement du contrat

### P6-002 - Opportunity schema and contract

#### But

Poser un contrat `Opportunity` normalise reutilisable par la phase 6 sans introduire trop tot une table persistante.

#### Etat

- livre le `2026-04-21`
- projection backend `app/Support/CRM/OpportunitySchema.php` ajoutee
- payload pipeline enrichi avec `opportunity`
- suite `tests/Feature/OpportunitySchemaPhaseSixTest.php` ajoutee

#### Livrables

- projection stable `request_quote_projection`
- identifiant stable ancre sur `Request` avec fallback `Quote`
- champs normalises `stage / amount / forecast / next_action / anchors`
- flags explicites `is_projection = true` et `is_persisted = false`

#### Fichiers touches

- `app/Support/CRM/OpportunitySchema.php`
- `app/Http/Controllers/PipelineController.php`
- `tests/Feature/OpportunitySchemaPhaseSixTest.php`

#### Notes d'implementation

- ne pas creer de migration ni de table `opportunities` a ce stade
- reutiliser le graphe `Request / Quote / Job / Invoice` deja expose par le pipeline CRM
- garder le forecast V1 ancre sur `Quote.total` et un poids de stage simple
- sortir un contrat directement consomable par `P6-003`, `P6-004`, `P6-005` et `P6-006`

#### Tests ajoutes

- projection `Request` seule avec stage `qualified`
- projection `Quote` ouverte avec forecast pondéré
- projection `Invoice` aval qui conserve la meme cle opportunity apres gain
- projection `Quote declined` fermee en `closed_lost` sans perdre l ancre montant

### P6-003 - Sales pipeline query

#### But

Construire une query de lecture pipeline commercial qui projette un board et des stats manager sans introduire de schema `Opportunity` persistant.

#### Etat

- livre le `2026-04-21`
- query `app/Queries/CRM/BuildSalesPipelineIndexData.php` ajoutee
- analytics `app/Queries/CRM/BuildSalesPipelineAnalyticsData.php` ajoutees
- suite `tests/Feature/SalesPipelineQueryPhaseSixTest.php` ajoutee

#### Livrables

- collection normalisee des opportunites `request-backed` et `quote-only`
- filtres `stage / state / next_action / search / amount`
- stats `total / open / won / lost / weighted_open_amount / overdue_next_actions`
- board de stages `intake / contacted / qualified / quoted / won / lost`

#### Fichiers touches

- `app/Queries/CRM/BuildSalesPipelineIndexData.php`
- `app/Queries/CRM/BuildSalesPipelineAnalyticsData.php`
- `tests/Feature/SalesPipelineQueryPhaseSixTest.php`

#### Notes d'implementation

- reutiliser `OpportunitySchema` comme source canonique de stage et de forecast
- dedoublonner naturellement les flux `Request + Quote` via une opportunite ancree sur `Request`
- exposer aussi les devis sans demande via une opportunite ancree sur `Quote`
- garder une query collection reconsommable par la board, la sales inbox et les dashboards manager

#### Tests ajoutes

- pipeline mixte `request-only / quoted / won / lost / quote-only`
- stats et board stage-lockes sur le contrat phase 6
- filtres `stage`, `next_action`, `search`, `amount` verifies

### P6-004 - Sales inbox V1

#### But

Construire une inbox commerciale legere, queue-first et manager-friendly sur les opportunites ouvertes sans introduire de moteur de sequence ni de schema `Opportunity` persistant.

#### Etat

- livre le `2026-04-21`
- query `app/Queries/CRM/BuildSalesInboxIndexData.php` ajoutee
- analytics `app/Queries/CRM/BuildSalesInboxAnalyticsData.php` ajoutees
- route et page `crm.sales-inbox.index` branchees sur `CRM/SalesInbox`
- suite `tests/Feature/SalesInboxPhaseSixTest.php` ajoutee

#### Livrables

- queues prioritaires `overdue / no_next_action / quoted / needs_quote / active`
- tri commercial unique pour remonter d abord les opportunites qui derapent
- filtres `search / queue / stage / per_page`
- stats ouvertes et resume par queue reutilisables pour la suite phase 6
- entree revenue hub pour les owners et managers `sales.manage`

#### Fichiers touches

- `app/Queries/CRM/BuildSalesInboxIndexData.php`
- `app/Queries/CRM/BuildSalesInboxAnalyticsData.php`
- `app/Http/Controllers/SalesInboxController.php`
- `routes/web.php`
- `resources/js/Pages/CRM/SalesInbox.vue`
- `resources/js/utils/workspaceHub.js`
- `resources/js/i18n/modules/en/crm_sales_inbox.json`
- `resources/js/i18n/modules/fr/crm_sales_inbox.json`
- `resources/js/i18n/modules/es/crm_sales_inbox.json`
- `resources/js/i18n/modules/en/workspace_hub.json`
- `resources/js/i18n/modules/fr/workspace_hub.json`
- `resources/js/i18n/modules/es/workspace_hub.json`
- `tests/Feature/SalesInboxPhaseSixTest.php`

#### Notes d'implementation

- reutiliser `BuildSalesPipelineIndexData` comme source canonique des opportunites ouvertes
- garder une classification purement derivee au lieu de persister une file commerciale
- exposer un `primary_subject_type` et un `primary_subject_id` pour ouvrir rapidement `Request` ou `Quote`
- limiter l acces aux owners et aux membres actifs avec `sales.manage`
- masquer l inbox pour les workspaces `products` afin de rester coherents avec le scope services phase 6
- raccorder la route et la carte revenue hub au module `sales` existant au lieu d ajouter un module ou une limite dedies phase 6

#### Tests ajoutes

- priorisation des queues et stats commerciales sur un set mixte d opportunites ouvertes
- filtre `queue + search` avec contrat Inertia `CRM/SalesInbox`
- acces owner / manager autorise et membre standard bloque

### P6-005 - Forecast service V1

#### But

Construire un service de forecast simple, exploitable et derive de la projection `Opportunity` sans introduire de date de close enterprise ni d objet `Opportunity` persistant.

#### Etat

- livre le `2026-04-21`
- service `app/Services/CRM/SalesForecastService.php` ajoute
- suite `tests/Feature/ForecastServicePhaseSixTest.php` ajoutee

#### Livrables

- snapshot forecast `open / weighted / best_case / pipeline`
- categories forecast `pipeline / best_case / closed_won / closed_lost`
- resume par stage avec base pour `weighted pipeline` et `stage aging`
- buckets d aging ouverts `0_7 / 8_14 / 15_30 / 31_plus`
- etat des prochaines actions `overdue / scheduled / none`
- fenetres de wins `month_to_date / quarter_to_date / year_to_date`

#### Fichiers touches

- `app/Services/CRM/SalesForecastService.php`
- `tests/Feature/ForecastServicePhaseSixTest.php`

#### Notes d'implementation

- reutiliser `BuildSalesPipelineIndexData` comme source canonique de collection forecastable
- garder un forecast snapshot base sur `Quote.total` et les poids de stage deja portes par `OpportunitySchema`
- exposer des filtres simples `search / customer_id / reference_time` pour preparer `P6-006`
- laisser la couche UI manager au ticket suivant pour ne pas figer trop tot le rendu

#### Tests ajoutes

- snapshot complet avec categories forecast, stages, aging, next actions et wins
- filtres `search` et `customer_id` verifies pour les drill-downs manager

### P6-006 - Manager dashboards

#### But

Construire une vue manager revenue lisible qui consomme la projection `Opportunity`, la sales inbox et le forecast sans introduire une pipeline board enterprise ni un schema `Opportunity` persistant.

#### Etat

- livre le `2026-04-21`
- aggregateur `app/Queries/CRM/BuildSalesManagerDashboardData.php` ajoute
- controller `app/Http/Controllers/SalesManagerDashboardController.php` ajoute
- page `resources/js/Pages/CRM/ManagerDashboard.vue` ajoutee
- suite `tests/Feature/ManagerDashboardPhaseSixTest.php` ajoutee

#### Livrables

- cartes manager `weighted_open_amount / month_to_date_won_amount / overdue_next_actions / quote_pull_through`
- lecture `weighted pipeline` issue du forecast phase 6
- lecture `stage aging` sur les stages ouverts avec part ponderee et overdue par stage
- synthese des queues `sales inbox` et top `attention_items` pour le triage manager
- filtres `search / customer_id / reference_time` sur la page manager
- entree revenue hub et route CRM dediee pour owners et managers `sales.manage`

#### Fichiers touches

- `app/Queries/CRM/BuildSalesManagerDashboardData.php`
- `app/Http/Controllers/SalesManagerDashboardController.php`
- `app/Queries/CRM/BuildSalesInboxIndexData.php`
- `routes/web.php`
- `resources/js/Pages/CRM/ManagerDashboard.vue`
- `resources/js/utils/workspaceHub.js`
- `resources/js/Components/Workspace/WorkspaceModuleIcon.vue`
- `resources/js/i18n/modules/en/crm_manager_dashboard.json`
- `resources/js/i18n/modules/fr/crm_manager_dashboard.json`
- `resources/js/i18n/modules/es/crm_manager_dashboard.json`
- `resources/js/i18n/modules/en/workspace_hub.json`
- `resources/js/i18n/modules/fr/workspace_hub.json`
- `resources/js/i18n/modules/es/workspace_hub.json`
- `tests/Feature/ManagerDashboardPhaseSixTest.php`

#### Notes d'implementation

- reutiliser `SalesForecastService` comme couche de calcul canonique pour les cartes manager
- recroiser la queue commerciale via `BuildSalesInboxIndexData` pour exposer pression des files et items d attention
- garder `quote pull-through` derive de la population des opportunites avec devis plutot qu un nouvel objet conversion
- rester volontairement revenue-first et manager-friendly sans figer une board pipeline UI plus lourde
- reutiliser le module `sales` pour le gating forfaitaire et les overrides `company_features` au lieu d introduire un nouveau flag manager

#### Tests ajoutes

- snapshot JSON du dashboard manager avec `weighted pipeline`, `stage aging`, `next actions`, wins et queues
- contrat Inertia `CRM/ManagerDashboard` avec filtres `search + customer_id`
- acces owner / manager autorise, membre standard bloque et workspace `products` masque

### P6-007 - CRM cross-object linking refinement

#### But

Aligner la couche `Opportunity` phase 6 sur un contrat de linking CRM unique pour que les vues sales puissent ouvrir de maniere coherente `Request`, `Quote`, `Customer` et les ancres aval sans logique ad hoc dispersee.

#### Etat

- livre le `2026-04-21`
- contrat `app/Support/CRM/CrmOpportunityLinking.php` ajoute
- projection `Opportunity` et items pipeline / inbox alignes sur `crm_links`
- actions UI `Request / Quote` recablees sur le meme contrat dans `CRM/SalesInbox` et `CRM/ManagerDashboard`

#### Livrables

- contrat normalise `crm_links` avec `subject / primary / customer / request / quote / job / invoice / anchors`
- priorite `request-first` preservee comme ancre primaire pour les opportunites `request-backed`
- sujet commercial `quote-first` preserve pour la navigation rapide quand un devis existe
- meme contrat reutilisable par la pipeline query, la sales inbox et les vues manager phase 6

#### Fichiers touches

- `app/Support/CRM/CrmOpportunityLinking.php`
- `app/Support/CRM/OpportunitySchema.php`
- `app/Queries/CRM/BuildSalesPipelineIndexData.php`
- `app/Queries/CRM/BuildSalesInboxIndexData.php`
- `resources/js/Pages/CRM/SalesInbox.vue`
- `resources/js/Pages/CRM/ManagerDashboard.vue`
- `tests/Feature/OpportunityLinkingPhaseSixTest.php`
- `tests/Feature/OpportunitySchemaPhaseSixTest.php`
- `tests/Feature/SalesPipelineQueryPhaseSixTest.php`
- `tests/Feature/SalesInboxPhaseSixTest.php`
- `tests/Feature/ManagerDashboardPhaseSixTest.php`

#### Notes d'implementation

- rester additif en exposant `crm_links` sans casser les champs `primary_subject_*` deja utilises par la phase 6
- distinguer le `subject` commercial a ouvrir vite du `primary` request-first pour garder une lecture cross-object stable
- inclure aussi `job / invoice` dans les ancres pour les opportunites gagnees sans reintroduire d objet `Opportunity` persistant
- recabler les CTA UI sur le contrat backend plutot que recalculer request vs quote dans chaque vue

#### Tests ajoutes

- contrat dedie `CrmOpportunityLinking` sur les cas `request-backed` et `quote-only`
- projection pipeline `Opportunity` verrouillee avec `crm_links` sur `request / quote / customer / job / invoice`
- contrats Inertia `SalesInbox` et `ManagerDashboard` verifies avec les nouvelles ancres cross-object

### P6-008 - Non-regression full CRM suite

#### But

Clore la phase 6 avec une suite de non-regression transverse qui prouve que la couche commerciale ajoutee ne degrade ni les ecrans coeur CRM ni les surfaces phase 4 a phase 6.

#### Etat

- livre le `2026-04-21`
- suite `tests/Feature/CrmFullNonRegressionPhaseSixTest.php` ajoutee
- verification transverse rejouee sur les suites non-regression phase 4, phase 5 et phase 6

#### Livrables

- scenario `quoted` qui verrouille la coherence `Request / Quote / Customer / Pipeline / My next actions / Sales inbox / Manager dashboard`
- scenario `won downstream` qui verrouille l ancrage `request-first` jusqu a `Work / Invoice` sans reouvrir les queues commerciales
- preuve que la phase 6 reste additive sur le workflow coeur et sur les timelines commerciales precedentes
- bundle de validation clair pour fermer la phase 6 avant ouverture d une nouvelle phase CRM

#### Fichiers touches

- `tests/Feature/CrmFullNonRegressionPhaseSixTest.php`
- `docs/PHASE_6_OPPORTUNITY_LAYER_SALES_INBOX_FORECAST_DEV_BACKLOG_2026-04-21.md`

#### Notes d'implementation

- couvrir les surfaces critiques avec un meme jeu de donnees plutot que multiplier les micro-tests redondants
- reverifier explicitement les suites phase 4 et phase 5 qui portent le coeur detail, timeline et next actions
- garder la fermeture phase 6 centree sur les contrats backend et Inertia deja exposes sans inventer de nouvelle UI

#### Tests ajoutes

- flow `quoted` complet sur `request show`, `quote show`, `customer show`, `pipeline api`, `crm.next-actions`, `crm.sales-inbox` et `crm.manager-dashboard`
- flow `won downstream` complet sur `pipeline api`, exclusion inbox ouverte et wins manager
- re-execution verte des suites `CustomerRequestDetailNonRegressionPhaseFourTest`, `ActivityTimelinePhaseFiveNonRegressionTest`, `MyNextActionsPhaseFourTest` et du bundle phase 6
