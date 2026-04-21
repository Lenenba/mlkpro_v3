# Phase 4 sales activity layer dev backlog

Derniere mise a jour: 2026-04-21

## 0. Etat d'avancement implementation

Suivi courant:

- `P4-001` fait
- `P4-002` fait
- `P4-003` fait
- `P4-004` fait
- `P4-005` fait
- `P4-006` fait
- `P4-007` fait
- `P4-008` fait

Dernier bloc livre:

- UI d'activite commerciale branchee sur `Request`
- quick actions et modal manuelle disponibles sur la fiche demande
- timeline `Request` enrichie avec labels, types et due dates sales
- contrat backend et front localise pour les actions manuelles
- timeline commerciale `Customer` branchee avec logs `Request` lies
- couverture feature etendue pour la fiche client
- fiche `Quote` branchee sur le panneau commercial phase 4
- couverture quote et non-regression phase 2 validees
- workspace `my next actions` livre avec filtres, stats et hub revenue
- couverture feature ajoutee pour le filtrage overdue et le scope team member
- suite non-regression request/customer detail ajoutee pour verrouiller les contrats payload phase 4

Bloc actuellement en cours:

- sprint 8 cloture
- phase 4 V1 stabilisee avant fondations email/calendrier

## 1. But du document

Ce document transforme la phase 4 en backlog dev directement executable.

Le but est simple:

- fixer une taxonomie stable avant de multiplier les events
- reutiliser `ActivityLog` au maximum
- rendre les futures timelines `Request / Customer / Quote` coherentes entre elles
- garder la notion de prochaine action au centre

## 2. Rappel du scope phase 4

La phase 4 doit ajouter une vraie couche de suivi commercial quotidien sans construire trop tot:

- une inbox email complete
- une sync Gmail / Outlook lourde
- une couche VoIP native

Resultat V1 attendu:

- taxonomie claire des activites commerciales
- events sales normalises sur `ActivityLog`
- timeline lisible sur `Request / Customer / Quote`
- vue `my next actions`

## 3. Regles dev de la phase 4

Regles non negociables:

1. etendre `ActivityLog` avant d'inventer un nouvel objet
2. garder les events phase 1 et phase 2 lisibles via une taxonomie commune
3. separer clairement `touchpoint`, `resultat`, `prochaine action` et `meeting`
4. garder la prochaine action queryable sans coupler toute la phase a une UI specifique
5. ne pas melanger les fondations phase 4 avec la sync email / calendrier de phase 5

## 4. Fichiers coeur a proteger

### Backend

- `app/Models/ActivityLog.php`
- `app/Queries/Customers/BuildCustomerDetailViewData.php`
- `app/Http/Controllers/RequestController.php`
- `app/Http/Controllers/QuoteController.php`
- `app/Http/Controllers/CustomerController.php`
- `app/Models/Task.php`

### Frontend

- `resources/js/Pages/Request/Show.vue`
- `resources/js/Pages/Quote/Show.vue`
- `resources/js/Pages/Customer/Show.vue`

### Tests existants a proteger

- `tests/Feature/RequestInboxPhaseOneTest.php`
- `tests/Feature/QuoteRecoveryPhaseTwoTest.php`
- `tests/Feature/CustomerShowLeadRequestsTest.php`

## 5. Tests a creer pour la phase 4

Suites recommandees:

- `tests/Feature/SalesActivityTaxonomyPhaseFourTest.php`
- `tests/Feature/SalesActivityLogPhaseFourTest.php`
- `tests/Feature/MyNextActionsPhaseFourTest.php`

Regle:

- ne pas enterrer la phase 4 dans des vieux tests generiques
- garder un contrat explicite pour la taxonomie et les timelines

## 6. Sprint 7

Objectif:

- poser le socle metier et queryable de la couche sales activity

Tickets du sprint:

- `P4-001`
- `P4-002`
- `P4-003`

Sortie attendue:

- taxonomie stable
- `ActivityLog` enrichi
- base du service `my next actions`

### P4-003 - Next action query and service

#### But

Construire la premiere vraie query exploitable de `mes prochaines actions` sans introduire un nouvel objet metier.

#### Etat

- livre le `2026-04-20`
- service `app/Services/CRM/MyNextActionsService.php` ajoute
- aggregation cross-subject `Request / Quote / Task / ActivityLog` disponible
- fermeture et dedup des prochaines actions couverts

#### Livrables

- service applicatif unique pour calculer la file `my next actions`
- inclusion des suivis legacy `next_follow_up_at` sur `Request` et `Quote`
- inclusion des `Task` ouvertes avec `due_date`
- inclusion des activites sales ouvertes avec due date sur `ActivityLog`
- rejet des actions fermees et des doublons `task-backed` / `quote follow-up`

#### Fichiers touches

- `app/Services/CRM/MyNextActionsService.php`
- `tests/Feature/MyNextActionsPhaseFourTest.php`

#### Notes d'implementation

- prendre le dernier event pertinent qui ouvre ou ferme une prochaine action par sujet
- ne pas remonter `quote_follow_up_task_created` quand la `Task` existe deja dans la file
- deduper les suivis `Quote / Request` quand un `ActivityLog` deja ouvert porte la meme echeance
- garder la sortie orientee service pour preparer `P4-007` sans couplage UI premature

#### Tests ajoutes

- aggregation `Request / Quote / Task / sales activity`
- exclusion des actions fermees
- dedup `quote next follow-up` et `task-backed activity`
- visibilite team member sur les tasks assignees

### P4-002 - ActivityLog extension for sales events

#### But

Permettre d'enregistrer de vraies activites commerciales phase 4 sur `Request`, `Customer` et `Quote`.

#### Etat

- livre le `2026-04-20`
- logger central `app/Services/CRM/SalesActivityLogger.php` ajoute
- controller `app/Http/Controllers/SalesActivityController.php` ajoute
- endpoints `crm.sales-activities.*.store` branches
- payloads detail enrichis avec `canLogSalesActivity` et `salesActivityQuickActions`

#### Livrables

- endpoints JSON de log manuel
- resolution `quick_action -> action canonique`
- due dates portees dans `properties` pour preparer `my next actions`
- rejection explicite des actions legacy sur les nouveaux endpoints

#### Fichiers touches

- `app/Services/CRM/SalesActivityLogger.php`
- `app/Http/Controllers/SalesActivityController.php`
- `routes/web.php`
- `app/Http/Controllers/RequestController.php`
- `app/Http/Controllers/QuoteController.php`
- `app/Http/Controllers/CustomerController.php`
- `tests/Feature/SalesActivityLogPhaseFourTest.php`

#### Notes d'implementation

- garder les nouveaux endpoints sur les actions canoniques `sales_*`
- ne pas ecrire les vieux events legacy depuis la phase 4
- stocker les dates clefs dans `properties` pour les futures queries

#### Tests ajoutes

- log manuel sur `Request`
- quick action sur `Quote`
- exposition metadata sur `Request / Quote`
- rejet d'une action legacy sur le nouvel endpoint

### P4-001 - Sales activity taxonomy

#### But

Definir une taxonomie unique pour toutes les activites commerciales de la phase 4.

#### Etat

- livre le `2026-04-20`
- taxonomie centralisee dans `app/Support/CRM/SalesActivityTaxonomy.php`
- mapping legacy `quote_follow_up_*`, `lead_call_requested`, `contacted` inclus
- enrichissement `ActivityLog` disponible via `is_sales_activity` et `sales_activity`

#### Livrables

- taxonomie centrale des types `note / call / call_outcome / next_action / meeting`
- quick actions de reference phase 4
- compatibilite avec les events deja produits par phases 1 et 2
- exposition du contrat dans les payloads timeline existants

#### Fichiers touches

- `app/Support/CRM/SalesActivityTaxonomy.php`
- `app/Models/ActivityLog.php`
- `app/Queries/Customers/BuildCustomerDetailViewData.php`
- `tests/Feature/SalesActivityTaxonomyPhaseFourTest.php`

#### Notes d'implementation

- garder une taxonomie additive et explicite
- ne pas renommer brutalement les vieux `action` ActivityLog
- introduire des `activity_key` stables pour les futures UI

#### Tests ajoutes

- reconnaissance des actions canonique et legacy
- serialisation `ActivityLog -> sales_activity`
- exposition de la metadata dans le detail customer

#### Acceptance criteria

1. la taxonomie couvre le scope phase 4 V1
2. les events deja livres en phase 2 peuvent etre interpretes comme activite commerciale
3. les payloads detail `Customer` et `Quote/Request` peuvent consommer cette taxonomie sans rework backend majeur

## 7. Sprint 8

Objectif:

- brancher la couche sales activity sur les ecrans coeur et livrer la file des prochaines actions

### P4-004 - Request detail sales activity UI

#### But

Donner a la fiche `Request` une vraie UI phase 4 pour journaliser et lire l'activite commerciale sans quitter le detail.

#### Etat

- livre le `2026-04-21`
- panneau UI ajoute sur `resources/js/Components/CRM/SalesActivityPanel.vue`
- fiche `resources/js/Pages/Request/Show.vue` branchee sur les quick actions et le log manuel
- contrat `salesActivityManualActions` expose par le controller

#### Livrables

- quick actions visibles sur le detail request
- modal manuelle pour `note / call / next action / meeting`
- timeline request enrichie avec labels localises, type et due date
- visualisation de la prochaine action active si une action ouverte existe

#### Fichiers touches

- `app/Support/CRM/SalesActivityTaxonomy.php`
- `app/Http/Controllers/RequestController.php`
- `resources/js/Components/CRM/SalesActivityPanel.vue`
- `resources/js/Pages/Request/Show.vue`
- `resources/js/i18n/modules/fr/requests.json`
- `resources/js/i18n/modules/en/requests.json`
- `resources/js/i18n/modules/es/requests.json`
- `tests/Feature/SalesActivityTaxonomyPhaseFourTest.php`
- `tests/Feature/SalesActivityLogPhaseFourTest.php`

#### Notes d'implementation

- garder la timeline request unique en enrichissant les logs existants au lieu de dupliquer les flux
- localiser les labels cote front pour ne pas coupler l'UI aux libelles backend hardcodes
- exposer un catalogue explicite d'actions manuelles pour preparer `Quote` et `Customer`

#### Tests ajoutes

- contrat `manualActionDefinitions`
- exposition `salesActivityManualActions` sur le detail request
- verification front via `npm run build`

### P4-005 - Customer detail sales timeline UI

#### But

Donner a la fiche `Customer` une timeline commerciale phase 4 coherente avec `Request`, sans dupliquer un nouveau flux d'activite.

#### Etat

- livre le `2026-04-21`
- detail `resources/js/Pages/Customer/Show.vue` branche sur le panneau reusable
- payload customer enrichi avec `salesActivityManualActions`
- activites `Request` liees au client remontees dans la timeline

#### Livrables

- panneau commercial visible sur la fiche client
- quick actions et modal manuelle reutilisant le meme contrat que `Request`
- timeline client enrichie avec labels sales, acteur, note et lien de sujet
- inclusion des logs `Request` dans l'historique client pour garder une vue commerciale unifiee

#### Fichiers touches

- `app/Http/Controllers/CustomerController.php`
- `app/Queries/Customers/BuildCustomerDetailViewData.php`
- `app/Queries/Customers/CustomerReadSelects.php`
- `resources/js/Components/CRM/SalesActivityPanel.vue`
- `resources/js/Pages/Customer/Show.vue`
- `resources/js/i18n/modules/fr/customers.json`
- `resources/js/i18n/modules/en/customers.json`
- `resources/js/i18n/modules/es/customers.json`
- `tests/Feature/SalesActivityLogPhaseFourTest.php`

#### Notes d'implementation

- reutiliser le meme panneau que `Request` pour garder une vraie coherence de phase 4
- enrichir la query customer plutot que construire une timeline parallele cote front
- remonter l'acteur et les `properties` des logs pour afficher les notes et echeances sales sans re-fetch

#### Tests ajoutes

- exposition `salesActivityManualActions` sur le detail customer
- inclusion d'une activite `Request` dans la timeline customer
- verification du type, de l'acteur et de l'echeance sales dans le payload detail

### P4-006 - Quote detail sales activity UI

#### But

Donner a la fiche `Quote` la meme UI commerciale phase 4 que `Request` et `Customer`, en restant compatible avec l'historique de recovery phase 2.

#### Etat

- livre le `2026-04-21`
- detail `resources/js/Pages/Quote/Show.vue` branche sur le panneau reusable
- controller quote enrichi avec `salesActivityManualActions`
- timeline devise affiche notes, actions rapides et prochaine action active

#### Livrables

- panneau commercial visible sur la fiche devis
- quick actions et modal manuelle reutilisant le contrat central phase 4
- timeline quote enrichie avec labels sales, note et due date
- compatibilite conservee avec les activites legacy `quote_follow_up_*`

#### Fichiers touches

- `app/Http/Controllers/QuoteController.php`
- `resources/js/Pages/Quote/Show.vue`
- `resources/js/i18n/modules/fr/quotes.json`
- `resources/js/i18n/modules/en/quotes.json`
- `resources/js/i18n/modules/es/quotes.json`
- `tests/Feature/SalesActivityLogPhaseFourTest.php`

#### Notes d'implementation

- reutiliser `SalesActivityPanel.vue` pour garder un langage UI unique sur les trois details coeur
- laisser les events phase 2 visibles dans la meme timeline au lieu de separer recovery et sales activity
- exposer le catalogue manuel cote backend pour garder un contrat identique a `Request` et `Customer`

#### Tests ajoutes

- exposition `salesActivityManualActions` sur le detail quote
- verification de la note commerciale et de l'acteur dans le payload quote
- non-regression du detail quote recovery historique

### P4-007 - My next actions workspace

#### But

Transformer le service `my next actions` en vraie vue de travail consultable depuis le hub revenu.

#### Etat

- livre le `2026-04-21`
- controller `app/Http/Controllers/MyNextActionsController.php` ajoute
- route `crm.next-actions.index` exposee
- page `resources/js/Pages/CRM/MyNextActions.vue` branchee avec filtres et liens directs

#### Livrables

- vue Inertia dediee pour `mes prochaines actions`
- filtres `search / source / type / due state`
- compteurs de synthese et liste priorisee des actions ouvertes
- liens directs vers `Request / Quote / Task / Customer`
- entree discoverable dans le hub `revenue`

#### Fichiers touches

- `app/Http/Controllers/MyNextActionsController.php`
- `app/Services/CRM/MyNextActionsService.php`
- `routes/web.php`
- `resources/js/Pages/CRM/MyNextActions.vue`
- `resources/js/utils/workspaceHub.js`
- `resources/js/Pages/Workspace/CategoryHub.vue`
- `resources/js/Components/Workspace/WorkspaceModuleIcon.vue`
- `resources/js/i18n/modules/fr/crm_next_actions.json`
- `resources/js/i18n/modules/en/crm_next_actions.json`
- `resources/js/i18n/modules/es/crm_next_actions.json`
- `resources/js/i18n/modules/fr/workspace_hub.json`
- `resources/js/i18n/modules/en/workspace_hub.json`
- `resources/js/i18n/modules/es/workspace_hub.json`
- `tests/Feature/MyNextActionsPhaseFourTest.php`

#### Notes d'implementation

- reutiliser le service existant au lieu de reconstruire la logique cote controller
- garder les filtres sur la sortie du service pour preserver la logique de dedup et de visibilite
- rendre la vue accessible depuis le hub revenue pour l'ancrer dans le flux CRM quotidien

#### Tests ajoutes

- filtrage overdue et payload Inertia du workspace
- respect du scope team member sur les taches visibles
- non-regression de l'agregation service initiale

### P4-008 - Non-regression customer and request detail suite

#### But

Verrouiller les payloads detail `Request` et `Customer` apres l'ajout de la couche sales activity pour eviter une regression silencieuse sur les champs historiques.

#### Etat

- livre le `2026-04-21`
- suite `tests/Feature/CustomerRequestDetailNonRegressionPhaseFourTest.php` ajoutee
- coexistence `detail legacy + sales activity` couverte sur `Request` et `Customer`

#### Livrables

- couverture request detail sur `duplicates`, `campaignOrigin`, `assignees`, `statuses` et metadata sales
- couverture customer detail sur `requests`, `quote linkee` et timeline mixte `Customer + Request`
- verification de l'ordre et des due dates sur les activites detail phase 4

#### Fichiers touches

- `tests/Feature/CustomerRequestDetailNonRegressionPhaseFourTest.php`
- `docs/PHASE_4_SALES_ACTIVITY_LAYER_DEV_BACKLOG_2026-04-20.md`

#### Notes d'implementation

- garder le ticket entierement oriente non-regression pour ne pas re-disperser la couverture dans des tests generiques
- tester des scenarios de coexistence reelle entre contrats historiques et nouveaux champs sales
- verrouiller les payloads detail au niveau feature plutot qu'au niveau composant isole

#### Tests ajoutes

- request detail avec attribution campagne, detection de doublons et activite sales ouverte
- customer detail avec liaison `Request -> Quote` et timeline mixte `Customer / Request`

Tickets du sprint:

- `P4-004`
- `P4-005`
- `P4-006`
- `P4-007`
- `P4-008`
