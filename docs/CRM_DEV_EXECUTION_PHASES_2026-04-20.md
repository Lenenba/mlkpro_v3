# CRM dev execution phases - step by step delivery plan

Derniere mise a jour: 2026-04-20

## 1. But du document

Ce document n'est pas une vision produit.

C'est un document de pilotage dev.

Son role est de transformer la roadmap CRM en phases executables, stables, et livrables etape par etape.

Le but est que l'equipe puisse avancer avec une logique simple:

1. prendre une phase
2. prendre ses tickets
3. livrer son V1
4. verifier ses garde-fous
5. passer a la phase suivante

## 1.1 Document complementaire

Pour une lecture transversale de tout ce qui a ete livre sur les phases 0 a 6, avec:

- le pourquoi
- les avantages
- les bonnes pratiques d'usage
- la meilleure facon de tirer de la valeur du CRM

voir:

- `docs/CRM_PHASES_0_TO_6_GUIDE_2026-04-21.md`

## 2. Regle de fonctionnement

Chaque phase doit etre traitee comme une mini-release autonome.

Une phase n'est pas "en cours pour toujours".

Elle doit avoir:

- un objectif unique
- un scope V1 limite
- un backlog clair
- des dependances visibles
- une definition de done
- des tests de non-regression

## 3. Structure standard a reutiliser pour chaque phase

Chaque phase dev doit toujours etre decrite avec les memes blocs:

1. objectif business
2. objectif technique
3. objets et modules touches
4. scope V1
5. hors-scope
6. backend
7. frontend
8. data / migrations
9. tests
10. rollout
11. definition de done

Cette structure doit rester la meme pour toutes les phases.

## 4. Ordre d'execution officiel

L'ordre d'execution recommande est:

1. Phase 0 - Cadrage Request-first
2. Phase 1 - Lead SLA Inbox and Smart Triage
3. Phase 2 - Quote Recovery and Conversion Cockpit
4. Phase 3 - Saved Segments and Scheduled Playbooks
5. Phase 4 - Sales Activity Layer
6. Phase 5 - Email and Calendar Foundations
7. Phase 6 - Opportunity Layer, Sales Inbox, Forecast

Regle:

- on ne saute pas directement a la phase 5 ou 6
- on ne travaille pas plusieurs phases CRM majeures en parallele
- on clot une phase avant d'ouvrir la suivante, sauf petit travail de cadrage

## 5. Vue d'ensemble des dependances

### Phase 0

Depend de:

- rien

Bloque:

- toutes les autres phases

### Phase 1

Depend de:

- Phase 0

Bloque:

- Phase 2
- Phase 4
- une partie de Phase 6

### Phase 2

Depend de:

- Phase 0
- idealement Phase 1

Bloque:

- Phase 3
- une partie de Phase 6

### Phase 3

Depend de:

- Phase 1
- Phase 2
- patterns bulk et campaigns existants

Bloque:

- industrialisation des routines commerciales

### Phase 4

Depend de:

- Phase 1 stable
- `ActivityLog`
- fiche `Customer`
- details `Request` et `Quote`

Bloque:

- Phase 6

### Phase 5

Depend de:

- Phase 4 stable

Bloque:

- inbox commerciale mature

### Phase 6

Depend de:

- Phase 1 stable
- Phase 2 stable
- Phase 3 stable
- Phase 4 stable
- idealement Phase 5 engagee

## 6. Phase 0 - Cadrage Request-first

### Objectif business

Eviter une mauvaise direction CRM qui casserait la base ou creerait trop d'objets trop tot.

### Objectif technique

Verrouiller les objets, invariants, champs cibles, KPIs et garde-fous avant d'ouvrir les grosses livraisons.

### Modules / objets touches

- `Request`
- `Quote`
- `Customer`
- `ActivityLog`
- `Pipeline`
- feature flags

### Scope V1

- glossaire
- contrat `Request-first`
- cartographie des points de lecture
- baseline KPI
- checklist anti-regression

### Hors-scope

- code fonctionnel CRM majeur
- nouvel objet `Opportunity`

### Tickets dev

- `P0-001` Glossaire officiel CRM
- `P0-002` Contrat de donnees `Request-first`
- `P0-003` Cartographie des points de lecture
- `P0-004` Strategie feature flags CRM
- `P0-005` Baseline KPI
- `P0-006` Checklist anti-regression CRM

### Document de reference

- `docs/PHASE_0_CRM_REQUEST_FIRST_CADRAGE_2026-04-20.md`

### Definition de done

- choix `Request-first` acte
- invariants listes
- backlog des phases 1 a 3 clarifie

## 7. Phase 1 - Lead SLA Inbox and Smart Triage

### Objectif business

Augmenter la vitesse de traitement des leads et reduire les opportunites perdues par manque de suivi.

### Objectif technique

Faire de `Request` une vraie inbox de travail quotidienne sans casser la vue table, la vue board, ni la conversion en devis.

### Modules / objets touches

- `app/Models/Request.php`
- `app/Http/Controllers/RequestController.php`
- `resources/js/Pages/Request/Index.vue`
- `resources/js/Pages/Request/UI/RequestTable.vue`
- `resources/js/Pages/Request/UI/RequestBoard.vue`
- `resources/js/Pages/Request/UI/RequestAnalytics.vue`
- `resources/js/Components/UI/RequestStats.vue`
- `resources/js/utils/leadScore.js`

### Scope V1

- queues `new / due soon / stale / breached`
- priorite visible
- risque visible
- actions rapides
- KPI de triage

### Hors-scope

- inbox email
- objet `Opportunity`
- refonte totale du board

### Backend

- champs additifs sur `requests`
- service de classification SLA
- query de triage
- extensions analytics

### Frontend

- filtres rapides de queue
- badges visuels
- stats et analytics enrichies
- alignement table + board

### Data / migrations

- `first_response_at`
- `last_activity_at`
- `sla_due_at`
- `triage_priority`
- `risk_level`
- `stale_since_at`

### Tests

- classification des queues
- update simple
- bulk update
- table / board
- conversion `Request -> Quote`

### Tickets dev

- `P1-001` Schema additif Request Inbox
- `P1-002` Classification SLA et stale
- `P1-003` Request inbox query
- `P1-004` Extensions analytics Request
- `P1-005` UI inbox rapide
- `P1-006` Board alignment
- `P1-007` Quick actions manager / rep
- `P1-008` Non-regression suite

### Document de reference

- `docs/PHASE_1_LEAD_SLA_INBOX_SMART_TRIAGE_2026-04-20.md`
- `docs/PHASE_1_REQUEST_INBOX_DEV_BACKLOG_2026-04-20.md`

### Definition de done

- queues visibles
- triage stable
- flux `Request -> Quote` intact
- tests verts

## 8. Phase 2 - Quote Recovery and Conversion Cockpit

### Objectif business

Recuperer plus de devis en attente et reduire le revenu perdu entre devis emis et devis acceptes.

### Objectif technique

Faire de `Quote` une vraie file de suivi commercial et pas seulement une liste de devis.

### Modules / objets touches

- `app/Models/Quote.php`
- `app/Http/Controllers/QuoteController.php`
- `resources/js/Pages/Quote/Index.vue`
- `resources/js/Pages/Quote/UI/QuoteTable.vue`
- `resources/js/Pages/Quote/UI/QuoteActionsMenu.vue`
- `resources/js/Components/UI/QuoteStats.vue`
- `app/Models/ActivityLog.php`
- `app/Services/Customers/CustomerBulkContactService.php`

### Scope V1

- vues de devis en attente
- segments `never followed / due / viewed not accepted / expired / high value`
- actions rapides de relance
- timeline de suivi
- score simple de priorite

### Hors-scope

- forecast commercial avance
- nouvel objet deal
- automation complexe multi-canal

### Backend

- enrichir le modele `Quote`
- query de recovery cockpit
- service de priorisation simple
- journalisation d'activite de relance

### Frontend

- nouvelle segmentation dans `Quote`
- widgets d'ageing
- actions rapides `remind / call / task / archive`
- timeline legere dans detail devis ou index enrichi

### Data / migrations

- `last_sent_at`
- `last_viewed_at`
- `follow_up_state`
- `follow_up_count`
- `quote_age_days`

### Tests

- index devis enrichi
- statuts devis
- acceptation quote -> work
- non-regression `Quote -> Request status sync`
- relance et archivage

### Tickets dev

- `P2-001` Schema additif Quote Recovery
- `P2-002` Quote recovery query
- `P2-003` Quote priority scorer
- `P2-004` Quote recovery analytics
- `P2-005` Quote cockpit UI
- `P2-006` Quote follow-up actions
- `P2-007` ActivityLog integration quote follow-up
- `P2-008` Non-regression quote workflow suite

### Definition de done

- file des devis stale visible
- relances pilotables depuis `Quote`
- acceptation quote -> work intacte
- tests quotes et workflow verts

### Document de reference

- `docs/PHASE_2_QUOTE_RECOVERY_CONVERSION_COCKPIT_2026-04-20.md`
- `docs/PHASE_2_QUOTE_RECOVERY_DEV_BACKLOG_2026-04-20.md`

## 9. Phase 3 - Saved Segments and Scheduled Playbooks

### Objectif business

Transformer les actions manuelles repetitives en routines reexecutables.

### Objectif technique

Reutiliser `BulkActionRegistry`, les campagnes et les filtres existants pour creer un moteur de segments et playbooks.

### Modules / objets touches

- `app/Support/BulkActions/BulkActionRegistry.php`
- `app/Services/Campaigns/CampaignAutomationService.php`
- modules `Request`, `Customer`, `Quote`
- `resources/js/Pages/Campaigns/*`
- tables de segments / playbooks / runs a creer

### Scope V1

- segments sauvegardes
- playbooks lies a un segment
- execution manuelle
- execution planifiee
- historique des runs

### Hors-scope

- moteur d'automation tres complexe type enterprise
- orchestration cross-app temps reel

### Backend

- modele segment sauvegarde
- modele playbook
- modele run / audit
- resolveur de cible
- integration avec bulk actions et campaigns

### Frontend

- UI sauvegarde de segment
- UI attachement d'action
- historique des runs
- etat `selected / processed / success / failed / skipped`

### Data / migrations

- table segments sauvegardes
- table playbooks
- table runs
- table run_items si necessaire

### Tests

- segments persistants
- executions manuelles
- executions planifiees
- respect des feature flags
- contrat bulk result

### Tickets dev

- `P3-001` Saved segment model and schema
- `P3-002` Playbook model and run schema
- `P3-003` Segment resolver service
- `P3-004` Playbook execution service
- `P3-005` Playbook scheduler integration
- `P3-006` Saved segment UI
- `P3-007` Playbook run history UI
- `P3-008` Non-regression bulk and campaign bridge suite

### Definition de done

- un segment peut etre sauve
- un playbook peut etre rattache et execute
- un historique de run existe
- les feature flags sont respectes

### Document de reference

- `docs/PHASE_3_SAVED_SEGMENTS_SCHEDULED_PLAYBOOKS_2026-04-20.md`
- `docs/PHASE_3_SAVED_SEGMENTS_PLAYBOOKS_DEV_BACKLOG_2026-04-20.md`

## 10. Phase 4 - Sales Activity Layer

### Objectif business

Donner une vraie sensation de CRM commercial quotidien sans attendre l'inbox email complete.

### Objectif technique

Construire une couche d'activite commerciale structuree sur `ActivityLog`, visible sur `Request`, `Customer` et `Quote`.

### Modules / objets touches

- `app/Models/ActivityLog.php`
- `app/Queries/Customers/BuildCustomerDetailViewData.php`
- `resources/js/Pages/Customer/Show.vue`
- `resources/js/Pages/Request/Show.vue`
- `resources/js/Pages/Quote/Show.vue`
- `app/Models/Task.php`

### Scope V1

- notes commerciales
- appels
- resultats d'appel
- prochaine action
- rendez-vous interne
- file `my next actions`

### Hors-scope

- sync Gmail / Outlook
- inbox email bidirectionnelle
- VoIP native

### Backend

- enrichissements `ActivityLog`
- event types commerciaux normalises
- query `my next actions`
- timeline unifiee cross-subject

### Frontend

- quick actions de log d'activite
- timeline plus riche sur Request / Customer / Quote
- vue `mes prochaines actions`

### Data / migrations

- rester leger au maximum
- n'ajouter un nouvel objet que si `ActivityLog + Task` ne suffisent pas

### Tests

- log d'activite
- affichage timeline
- prochaine action
- non-regression fiche customer

### Tickets dev

- `P4-001` Sales activity taxonomy
- `P4-002` ActivityLog extension for sales events
- `P4-003` Next action query and service
- `P4-004` Request detail sales activity UI
- `P4-005` Customer detail sales timeline UI
- `P4-006` Quote detail sales timeline UI
- `P4-007` My next actions workspace
- `P4-008` Non-regression customer and request detail suite

### Definition de done

- activites commerciales journalisees
- timeline lisible sur objets coeur
- file des prochaines actions disponible

### Document de reference

- `docs/PHASE_4_SALES_ACTIVITY_LAYER_DEV_BACKLOG_2026-04-20.md`

## 11. Phase 5 - Email and Calendar Foundations

### Objectif business

Poser les bases d'une communication commerciale moderne sans promettre trop vite une inbox complete.

### Objectif technique

Creer les primitives de messages et de rendez-vous qui serviront plus tard a une vraie couche inbox.

### Modules / objets touches

- couche services nouvelle autour des messages
- `ActivityLog`
- `Request`, `Customer`, `Quote`
- futurs connecteurs email / calendrier

### Scope V1

- events `email sent / email received / meeting scheduled / meeting completed`
- liaison de ces events aux objets coeur
- journalisation des emails sortants produits
- points d'accroche pour Gmail / Outlook plus tard

### Hors-scope

- inbox email complete
- sync bidirectionnelle mature
- merge de threads complexe

### Backend

- message event model
- meeting event model
- liaison vers objets CRM
- services d'integration preparatoires

### Frontend

- visualisation simple des emails envoyes
- visualisation simple des rendez-vous
- pas de clone de boite mail

### Data / migrations

- tables messages / meetings ou events dedies selon implementation retenue
- liens polymorphiques si necessaire

### Tests

- journalisation email
- liaison aux objets
- meeting events
- non-regression timeline commerciale

### Tickets dev

- `P5-001` Message event contract
- `P5-002` Meeting event contract
- `P5-003` CRM object linking strategy for messages
- `P5-004` Outgoing email logging
- `P5-005` Meeting logging UI
- `P5-006` Email activity UI
- `P5-007` Connector-ready abstraction layer
- `P5-008` Non-regression activity timeline suite

### Definition de done

- emails et meetings peuvent exister comme activites CRM
- pas d'inbox complete
- base prete pour integrations futures

### Document de reference

- `docs/PHASE_5_EMAIL_CALENDAR_FOUNDATIONS_DEV_BACKLOG_2026-04-21.md`

## 12. Phase 6 - Opportunity Layer, Sales Inbox, Forecast

### Objectif business

Faire passer le produit d'un CRM operationnel fort a une couche commerciale plus proche des CRM sales-first.

### Objectif technique

Ajouter les abstractions avances seulement si les couches precedentes sont stables et adoptees.

### Modules / objets touches

- potentiel nouvel objet `Opportunity`
- pipeline commercial generaliste
- inbox commerciale
- vues manager
- couche de forecast

### Scope V1

- objet `Opportunity` seulement si necessaire
- board pipeline commercial
- inbox commerciale simple
- forecast simple
- vues manager `stage aging / weighted pipeline / overdue next actions`
- rattachement des vues `my next actions / sales inbox / manager dashboard` au module `sales` existant pour les forfaits et les overrides tenant

### Hors-scope

- forecast enterprise complexe
- sales engagement niveau Outreach
- telephonie native complete

### Backend

- data model `Opportunity` si valide
- query pipeline commercial
- calcul forecast simple
- liaison avec `Request`, `Quote`, activites

### Frontend

- pipeline board generaliste
- inbox commerciale
- dashboards manager

### Data / migrations

- aucun schema `Opportunity` tant que la preuve de besoin n'est pas validee
- aucun nouveau `limit key` de forfait pour la phase 6: on reutilise les limites `requests / quotes / jobs / tasks` deja en place

### Tests

- pipeline commercial
- calcul forecast simple
- non-regression `Request`, `Quote`, `Customer`

### Tickets dev

- `P6-001` Opportunity need validation
- `P6-002` Opportunity schema and contract
- `P6-003` Sales pipeline query
- `P6-004` Sales inbox V1
- `P6-005` Forecast service V1
- `P6-006` Manager dashboards
- `P6-007` CRM cross-object linking refinement
- `P6-008` Non-regression full CRM suite

### Definition de done

- pipeline commercial lisible
- inbox commerciale V1 presente
- forecast simple exploitable
- aucune degradation des workflows coeur

### Document de reference

- `docs/PHASE_6_OPPORTUNITY_LAYER_SALES_INBOX_FORECAST_DEV_BACKLOG_2026-04-21.md`

## 13. Decoupage sprint recommande

### Bloc A

- Phase 0 complete

### Bloc B

- Phase 1

Sprint recommande:

- Sprint 1: `P1-001`, `P1-002`, `P1-003`
- Sprint 2: `P1-004`, `P1-005`, `P1-006`, `P1-007`, `P1-008`

### Bloc C

- Phase 2

Sprint recommande:

- Sprint 3: `P2-001`, `P2-002`, `P2-003`
- Sprint 4: `P2-004`, `P2-005`, `P2-006`, `P2-007`, `P2-008`

### Bloc D

- Phase 3

Sprint recommande:

- Sprint 5: `P3-001`, `P3-002`, `P3-003`
- Sprint 6: `P3-004`, `P3-005`, `P3-006`, `P3-007`, `P3-008`

### Bloc E

- Phase 4

Sprint recommande:

- Sprint 7: `P4-001`, `P4-002`, `P4-003`
- Sprint 8: `P4-004`, `P4-005`, `P4-006`, `P4-007`, `P4-008`

### Bloc F

- Phase 5 puis Phase 6

Ces phases ne doivent commencer qu'apres stabilisation reelle des blocs precedents.

## 14. Regles d'avancement pour l'equipe dev

Avant d'ouvrir une phase:

1. verifier que la precedente est consideree done
2. verifier que les KPI de base existent
3. verifier que les tests critiques du flux coeur sont verts

Pendant la phase:

1. travailler derriere feature flag si risque non trivial
2. livrer additive first
3. garder un backlog de non-regression a jour

A la fin de la phase:

1. verifier les tests backend
2. verifier le build frontend
3. verifier les smoke critiques
4. verifier les KPIs d'avant / apres
5. faire une review de regression avant d'ouvrir la phase suivante

## 15. Recommandation finale

Ce qu'il faut faire maintenant n'est pas de repartir dans l'abstrait.

Ce qu'il faut faire, c'est piloter le dev avec cette logique:

- une phase
- un scope V1
- des tickets
- des tests
- une sortie claire

Si on garde cette discipline, le CRM avancera vraiment et la base restera stable.

## 16. Documents lies

- `docs/PLAN_STRATEGIQUE_CRM_COMPETITIF_2026-04-20.md`
- `docs/PHASE_0_CRM_REQUEST_FIRST_CADRAGE_2026-04-20.md`
- `docs/PHASE_1_LEAD_SLA_INBOX_SMART_TRIAGE_2026-04-20.md`
- `docs/PHASE_1_REQUEST_INBOX_DEV_BACKLOG_2026-04-20.md`
- `docs/NEXT_HIGH_VALUE_MODULES_USER_STORY.md`
