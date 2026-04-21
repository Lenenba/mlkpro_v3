# CRM guide global phases 0 a 6

Derniere mise a jour: 2026-04-21

## 1. But du document

Ce document explique la logique d'ensemble du chantier CRM livre entre la phase 0 et la phase 6.

Le but n'est pas de lister tous les tickets un par un.

Le but est de repondre clairement a 4 questions:

1. qu est-ce qui a ete construit
2. pourquoi cela a ete construit comme ca
3. quels sont les avantages concrets pour l equipe
4. comment utiliser cette couche CRM a son meilleur potentiel

## 2. Vision d'ensemble

La trajectoire retenue n'est pas celle d'un CRM enterprise lourd construit autour d'un objet `Opportunity` impose des le debut.

La strategie retenue a ete:

- partir du vrai coeur deja solide
- renforcer `Request`
- renforcer `Quote`
- rendre les routines repetitives rejouables
- unifier les activites et les prochaines actions
- preparer les evenements email et calendrier
- seulement ensuite ouvrir une lecture commerciale plus revenue

La decision structurante est donc:

- approche `Request-first`
- `Customer` reste le referentiel
- `Quote` reste le pivot commercial principal apres qualification
- `ActivityLog` reste la timeline commune
- `Opportunity` reste d abord une projection, pas une table persistante

## 3. Pourquoi cette approche a ete choisie

Cette approche a ete retenue pour 5 raisons:

- elle limite le risque de regression sur les workflows deja vivants
- elle evite de multiplier les objets metier trop tot
- elle garde une transition naturelle entre operations et commercial
- elle permet de livrer de la valeur phase par phase
- elle laisse la porte ouverte a une future couche `Opportunity` si le besoin devient reel

En pratique, on a prefere:

- ajouter une meilleure lecture du travail
- avant d ajouter une nouvelle complexite structurelle

## 4. Ce qui a ete livre phase par phase

### Phase 0 - Cadrage Request-first

#### Pourquoi

Avant de coder du CRM plus lourd, il fallait verrouiller les invariants:

- quels objets portent le CRM
- quels objets ne doivent pas etre introduits trop tot
- quels garde-fous doivent rester intacts

#### Ce qui a ete decide

- `Request` porte le CRM amont
- `Customer` reste la fiche de reference
- `Quote` reste le pivot commercial principal
- `ActivityLog` porte la timeline commune
- `next_follow_up_at` et `Task` suffisent en V1 pour la prochaine action
- pas de modele `Opportunity` autonome en debut de trajectoire

#### Avantages

- base plus lisible
- moins de duplication de logique
- moins de migrations speculatives
- trajectoire plus sure pour les phases suivantes

#### Ce que cela change pour l usage

- l equipe doit penser le lead comme un `Request` ouvert
- le commercial ne commence pas dans une table a part
- la progression commerciale se lit d abord dans `Request`, puis `Quote`

### Phase 1 - Lead SLA Inbox and Smart Triage

#### Pourquoi

Le module `Request` existait deja, mais il ne jouait pas encore pleinement son role de file commerciale quotidienne.

Il fallait rendre visible:

- ce qui exige une action maintenant
- ce qui devient stale
- ce qui a depasse le niveau de service attendu

#### Ce qui a ete ajoute

- schema additif request inbox
- classification SLA et stale cote backend
- queues `new / due soon / stale / breached`
- query inbox request
- extensions analytics request
- UI inbox rapide
- board alignment
- quick actions manager et rep
- suite de non-regression phase 1

#### Avantages

- meilleure vitesse de prise en charge
- moins de leads oublies
- meilleure lisibilite manager
- logique de priorisation stable entre table et board

#### Meilleur usage

- ouvrir `Requests` comme point d entree quotidien
- commencer par `breached`, puis `due soon`
- utiliser les quick actions pour eviter les aller-retours inutiles
- traiter `stale` comme une file de recuperation, pas comme un backlog passif

### Phase 2 - Quote Recovery and Conversion Cockpit

#### Pourquoi

Une fois le lead mieux gere, il fallait traiter le trou principal entre devis envoye et revenu reel.

Le besoin etait de transformer `Quote` en file de recovery commerciale orientee conversion.

#### Ce qui a ete ajoute

- schema additif quote recovery
- query recovery cote backend
- queues `never_followed / due / viewed_not_accepted / expired / high_value`
- quote priority scorer
- reasons et priorites de recovery
- analytics recovery
- widgets manager sur l index quote
- quick actions recovery
- endpoint leger de relance et creation de tache
- timeline legere sur la fiche devis
- logs `ActivityLog` de recovery
- non-regression du flux `Quote -> Request -> Work`

#### Avantages

- meilleure conversion `sent -> accepted`
- moins de devis qui vieillissent sans action
- meilleure visibilite sur le revenu en attente
- relances plus systematiques et moins artisanales

#### Meilleur usage

- utiliser l index `Quotes` comme cockpit de relance
- commencer par `due` et `high_value`
- creer une tache seulement quand la relance sort du simple suivi
- utiliser la timeline du devis pour garder une trace propre des relances

### Phase 3 - Saved Segments and Scheduled Playbooks

#### Pourquoi

Les equipes repetaient les memes filtres et les memes actions sur:

- `Request`
- `Customer`
- `Quote`

Il fallait rendre ces routines persistantes, rejouables et auditables.

#### Ce qui a ete ajoute

- schema additif `saved_segments`
- schema additif `playbooks` et `playbook_runs`
- registre de resolution multi-module
- resolvers `Request / Customer / Quote`
- execution manuelle de playbook
- scheduler simple `daily / weekly`
- commande artisan et cron relies
- UI saved segments
- CRUD backend `crm.saved-segments.*`
- resume de run aligne sur `BulkActionResult`
- couverture feature sur execution, scheduler et UI

#### Avantages

- moins de travail repetitif
- moins d erreurs manuelles sur les selections
- routines plus auditables
- meilleure industrialisation des actions simples sans moteur low-code lourd

#### Meilleur usage

- sauvegarder les segments vraiment recurrents
- nommer les segments selon le resultat attendu, pas selon un filtre technique
- utiliser les playbooks pour les routines stables et bornees
- garder un humain dans la boucle pour les actions sensibles ou a fort impact

### Phase 4 - Sales Activity Layer

#### Pourquoi

Les actions commerciales existaient deja de maniere dispersee, mais il manquait une couche commune pour:

- noter un touchpoint
- qualifier un resultat
- programmer une prochaine action
- afficher une timeline coherente sur plusieurs objets

#### Ce qui a ete ajoute

- taxonomie stable des activites commerciales
- enrichissement de `ActivityLog`
- UI commerciale branchee sur `Request`
- timeline `Request` enrichie
- timeline `Customer` branchee
- fiche `Quote` branchee sur le panneau commercial
- workspace `My next actions`
- filtres, stats et hub revenue
- non-regressions detail `Request / Customer / Quote`

#### Avantages

- langage commun des activites commerciales
- meilleures timelines cross-object
- prochaines actions queryables sans nouvel objet metier
- meilleure discipline commerciale au quotidien

#### Meilleur usage

- enregistrer les interactions significatives dans la couche sales activity
- utiliser `My next actions` comme agenda commercial individuel
- distinguer clairement note, resultat, prochaine action et meeting
- garder la timeline vivante au lieu de laisser l historique hors systeme

### Phase 5 - Email and Calendar Foundations

#### Pourquoi

La phase 4 a stabilise la couche d activite commerciale.

Il fallait ensuite preparer:

- les messages email
- les evenements meeting
- les futurs connecteurs Gmail / Outlook

sans lancer trop tot une inbox complete.

#### Ce qui a ete ajoute

- taxonomie des events message/email
- taxonomie des events meeting/calendar
- enrichissement `ActivityLog` avec `message_event` et `meeting_event`
- contrat central de linking CRM pour message et meeting
- logging email sortant canonique
- projection UI unifiee `sales / message / meeting`
- socle connector-ready `gmail / outlook`
- endpoint d ingestion connecteur CRM
- support `occurred_at` pour respecter la chronologie reelle
- suites de non-regression dediees

#### Avantages

- email et rendez-vous lisibles dans les timelines coeur
- meilleure preparation des futures integrations
- chronologie plus fidele
- moins de fragmentation entre evenement commercial, message et meeting

#### Meilleur usage

- utiliser la timeline client / request / quote comme source de verite de l historique
- lire les messages et meetings comme des evenements CRM relies, pas comme des modules isoles
- conserver `ActivityLog` comme colonne vertebrale avant toute inbox plus lourde

### Phase 6 - Opportunity Layer, Sales Inbox, Forecast

#### Pourquoi

Une fois les leads, devis, routines, activites et foundations message/meeting en place, il fallait ouvrir une vraie lecture commerciale revenue-oriented.

Mais sans casser l approche `Request-first`.

#### Ce qui a ete ajoute

- contrat `opportunity_validation`
- projection `Opportunity` non persistante
- stages `intake / contacted / qualified / quoted / won / lost`
- forecast `pipeline / best_case / closed_won / closed_lost`
- query `sales pipeline`
- `sales inbox` queue-first
- `sales forecast service`
- `manager dashboard`
- contrat `crm_links`
- rattachement des vues revenue au module forfaitaire `sales`
- suite full non-regression phase 6

#### Avantages

- vue commerciale plus generique sans migration prematuree
- meme contrat de donnees pour pipeline, inbox et dashboard
- meilleure priorisation manager
- lecture revenue plus claire
- navigation cross-object plus sure

#### Meilleur usage

- utiliser `Sales inbox` pour le triage commercial d equipe
- utiliser `Manager dashboard` pour lire la pression commerciale globale
- utiliser `My next actions` pour l execution individuelle
- utiliser le pipeline et le forecast comme vues de lecture et de pilotage, pas comme source concurrente de verite

## 5. Comment toutes les phases travaillent ensemble

Les phases ne sont pas des modules isoles.

Elles forment une progression logique:

1. `Request` capte et trie
2. `Quote` convertit et recupere
3. `Segments` et `Playbooks` operationalisent les routines
4. `Sales activity` garde la memoire relationnelle et la prochaine action
5. `Email / Calendar` enrichissent la timeline commune
6. `Opportunity / Sales inbox / Forecast` donnent une lecture revenue manager

En clair:

- le quotidien de l executeur vit surtout dans `Requests`, `Quotes` et `My next actions`
- le quotidien du manager vit surtout dans `Requests`, `Sales inbox` et `Manager dashboard`

## 6. Comment l utiliser a son meilleur potentiel

### Routine quotidienne recommande pour un owner ou sales manager

1. ouvrir `Sales inbox`
2. traiter `overdue`, puis `no next action`
3. basculer sur `Requests` pour le triage amont
4. passer par `Quotes` pour la recovery devis
5. relire `Manager dashboard` pour le pilotage et les desequilibres

### Routine quotidienne recommande pour un rep

1. ouvrir `My next actions`
2. executer les suivis dus aujourd hui
3. ouvrir `Requests` pour les nouveaux leads et les leads critiques
4. ouvrir `Quotes` pour les devis a relancer
5. consigner les activites dans la timeline plutot que hors systeme

### Routine hebdomadaire recommande

- revoir les segments sauvegardes utiles
- ajuster les playbooks trop bruyants ou trop faibles
- regarder les zones de friction dans `Manager dashboard`
- verifier les leads stale et les devis jamais relances
- nettoyer les routines qui n apportent plus de valeur

## 7. Ce que le systeme fait bien

- il reste proche du workflow reel des equipes services
- il garde un pont propre entre commercial et operations
- il evite la duplication d objets trop tot
- il rend les prochaines actions visibles et queryables
- il garde une timeline unifiee sur les objets coeur
- il permet une montee en puissance progressive

## 8. Ce qu il ne faut pas sur-vendre

Le chantier CRM livre aujourd hui:

- un CRM operationnel solide
- une couche revenue utile
- une base propre pour evoluer

Mais il ne faut pas le presenter comme:

- un sequence engine enterprise complet
- une inbox email commerciale complete
- une synchronisation bi-directionnelle Gmail / Outlook finalisee
- un moteur d automation low-code libre
- un modele `Opportunity` persistant mature

## 9. Resultat global

Au terme des phases 0 a 6, on a obtenu:

- une base CRM plus claire
- une execution quotidienne mieux cadree
- un meilleur suivi lead et devis
- des routines rejouables
- une timeline commerciale unifiee
- des fondations message/meeting prêtes
- une lecture revenue manager exploitable

Le point fort de cette trajectoire est simple:

- on a gagne de la valeur CRM sans casser la logique coeur du produit

## 10. Docs de reference

- `docs/CRM_DEV_EXECUTION_PHASES_2026-04-20.md`
- `docs/PHASE_0_CRM_REQUEST_FIRST_CADRAGE_2026-04-20.md`
- `docs/PHASE_1_LEAD_SLA_INBOX_SMART_TRIAGE_2026-04-20.md`
- `docs/PHASE_1_REQUEST_INBOX_DEV_BACKLOG_2026-04-20.md`
- `docs/PHASE_2_QUOTE_RECOVERY_CONVERSION_COCKPIT_2026-04-20.md`
- `docs/PHASE_2_QUOTE_RECOVERY_DEV_BACKLOG_2026-04-20.md`
- `docs/PHASE_3_SAVED_SEGMENTS_SCHEDULED_PLAYBOOKS_2026-04-20.md`
- `docs/PHASE_3_SAVED_SEGMENTS_PLAYBOOKS_DEV_BACKLOG_2026-04-20.md`
- `docs/PHASE_4_SALES_ACTIVITY_LAYER_DEV_BACKLOG_2026-04-20.md`
- `docs/PHASE_5_EMAIL_CALENDAR_FOUNDATIONS_DEV_BACKLOG_2026-04-21.md`
- `docs/PHASE_6_OPPORTUNITY_LAYER_SALES_INBOX_FORECAST_DEV_BACKLOG_2026-04-21.md`
- `docs/CRM_PHASE_6_OPPORTUNITY_LAYER_GUIDE_2026-04-21.md`
