# Phase 1 CRM - Lead SLA Inbox and Smart Triage

Derniere mise a jour: 2026-04-20

## 1. But de la phase 1

La phase 1 transforme le module `Request` en vraie inbox commerciale quotidienne.

Le but n'est pas de refaire tout le module.

Le but est de rendre visible, actionnable et priorisee la file des leads ouverts pour:

1. reduire le temps de premiere reponse
2. reduire les leads sans action
3. augmenter le taux lead -> quote
4. proteger la base existante sans ajouter d'objet inutile

## 2. Decision produit

Decision retenue:

- la phase 1 reste strictement `Request-first`
- aucune creation d'objet `Opportunity`
- aucune refonte globale du pipeline existant
- aucune inbox email native en phase 1

La phase 1 doit etre une evolution additive de:

- `app/Models/Request.php`
- `app/Http/Controllers/RequestController.php`
- `resources/js/Pages/Request/UI/RequestTable.vue`
- `resources/js/Pages/Request/UI/RequestBoard.vue`
- `resources/js/Pages/Request/UI/RequestAnalytics.vue`
- `resources/js/Components/UI/RequestStats.vue`

## 3. Baseline actuelle observee

Le module `Request` a deja de vraies forces:

- statuts clairs
- vue table
- vue board
- assignation
- `next_follow_up_at`
- bulk actions
- conversion en devis
- analytics de premiere reponse
- conversion par source
- vue `risk leads`
- scoring front simple via `resources/js/utils/leadScore.js`

Cela veut dire que la phase 1 n'est pas une creation.

C'est une montee en puissance.

## 4. Probleme que la phase 1 doit resoudre

Aujourd'hui, le module est deja utile.

Mais il manque encore une logique de triage quotidien plus explicite:

- tous les leads ouverts ne sont pas clairement classes par urgence de traitement
- les leads sans action recente sont visibles, mais pas encore transformes en file de travail centrale
- la notion de SLA reste implicite
- les actions manager et rep ne sont pas encore organisees autour d'une inbox priorisee

Le risque actuel est simple:

- l'information existe
- mais la discipline d'execution n'est pas encore assez guidee par l'interface

## 5. Resultat attendu de la phase 1

En sortie de phase 1, un owner ou sales manager doit pouvoir:

1. ouvrir `Requests`
2. voir immediatement quels leads exigent une action maintenant
3. comprendre quels leads sont `new`, `due soon`, `stale`, `breached`
4. assigner, relancer, qualifier ou convertir sans chercher dans plusieurs ecrans
5. suivre quelques KPI simples qui montrent si l'equipe traite bien les leads

## 6. Scope fonctionnel V1

### 6.1 Nouvelles files de travail

La V1 doit introduire des vues ou segments de triage minimum:

- `new`
- `due soon`
- `stale`
- `breached`

Definition metier recommandee:

- `new`: lead ouvert sans premiere action significative recente
- `due soon`: lead avec relance ou SLA proche
- `stale`: lead ouvert sans activite depuis un seuil defini
- `breached`: lead dont le delai de traitement attendu est depasse

### 6.2 Priorisation visible

Chaque lead ouvert doit exposer clairement:

- priorite de triage
- niveau de risque
- assignee
- date de prochaine action
- age du lead
- age de la derniere activite

### 6.3 Actions rapides

La phase 1 doit rendre plus visibles les actions quotidiennes suivantes:

- assigner
- reassigner
- definir ou modifier `next_follow_up_at`
- faire avancer le statut
- marquer perdu
- convertir en devis
- ouvrir la fiche detail rapidement

### 6.4 Widgets manager

La V1 doit afficher des indicateurs simples et utiles:

- temps moyen avant premiere reponse
- leads sans assignee
- leads sans action
- leads `breached`
- leads `stale`

### 6.5 Detail rapide

Sans supprimer la fiche detail existante, la phase 1 peut ajouter un detail rapide ou drawer leger pour:

- voir le contact
- voir le customer lie
- voir le contexte de service
- voir la prochaine relance
- lancer une action simple

Regle:

- cette vue doit etre complementaire
- elle ne doit pas remplacer brutalement `request.show`

## 7. Evolutions de donnees recommandees

Pour rester additives et compatibles avec la base existante, les evolutions prioritaires sont:

- `first_response_at`
- `last_activity_at`
- `sla_due_at`
- `triage_priority`
- `risk_level`
- `stale_since_at`

### 7.1 Sens recommande des champs

- `first_response_at`
  - date de premiere action commerciale significative
- `last_activity_at`
  - derniere activite pertinente sur le lead
- `sla_due_at`
  - date limite attendue de traitement initial ou de prochaine reaction
- `triage_priority`
  - niveau de priorite normalise pour le classement
- `risk_level`
  - lecture simple du danger commercial
- `stale_since_at`
  - moment ou le lead est entre dans l'etat stale

### 7.2 Regle d'implementation

Ces champs doivent etre:

- ajoutes de facon additive
- nullable au debut
- backfilles progressivement si necessaire
- exploitables sans casser le calcul actuel base sur `ActivityLog`

## 8. Regles de triage recommandees

Les regles doivent rester simples, lisibles et stables.

### 8.1 Classification recommandee V1

- `new`
  - statut ouvert
  - pas de `first_response_at`
- `due soon`
  - statut ouvert
  - `next_follow_up_at` ou `sla_due_at` dans une fenetre proche
- `stale`
  - statut ouvert
  - aucune activite depuis au moins X jours
- `breached`
  - statut ouvert
  - `sla_due_at` depasse ou aucune action dans un delai critique

### 8.2 Seuils recommandes V1

Les seuils doivent rester parametrables plus tard, mais une V1 simple peut partir sur:

- `due soon`: dans les prochaines 24 heures
- `stale`: 7 jours sans activite
- `breached`: SLA depasse ou lead neuf sans prise en charge au-dela d'un seuil fixe

### 8.3 Priorite recommandee

Ordre de tri recommande:

1. `breached`
2. `due soon`
3. `new`
4. `stale`
5. autres leads ouverts

Puis a l'interieur:

- `triage_priority`
- score existant
- `next_follow_up_at`
- date de creation

## 9. UX recommandee

### 9.1 Vue table

La vue table reste la vue la plus puissante pour le pilotage fin.

Elle doit gagner:

- filtres rapides de queue
- indicateurs visuels `stale / breached / due soon`
- colonne ou badge de priorite
- acces plus rapide aux actions critiques

### 9.2 Vue board

La vue board doit rester disponible.

Mais elle ne doit pas devenir la seule reponse au triage.

Role recommande du board:

- lecture visuelle du pipeline
- rituel d'equipe
- progression de statut

Role recommande de la vue inbox:

- execution quotidienne
- tri par urgence
- reduction du temps de reaction

### 9.3 Analytics

Les analytics existantes de `RequestAnalytics.vue` doivent etre conservees.

La phase 1 doit plutot ajouter:

- compte de leads stale
- compte de leads breached
- delai moyen avant prise en charge

### 9.4 Stats

`RequestStats.vue` peut etre etendu sans etre remplace.

Ajouts recommandes:

- `due soon`
- `stale`
- `breached`

## 10. Architecture recommande

Pour limiter la dette dans `RequestController`, la phase 1 devrait extraire la logique metier dans des services ou queries dedies.

Pieces recommandees:

- `RequestInboxQuery` ou equivalent
- `LeadTriageClassifier` ou equivalent
- `LeadSlaService` ou equivalent
- `RequestAnalyticsQuery` si la logique grossit davantage

Le controleur doit rester:

- autorisation
- orchestration
- reponse Inertia / JSON

La logique de classification ne doit pas rester encodee uniquement dans la vue.

## 11. Backlog recommande de la phase 1

### P1-001 - Schema additif Request Inbox

But:

- ajouter les champs de triage sur `requests`

Livrable:

- migration additive
- model casts / fillable
- aucun effet destructif

### P1-002 - Classification SLA et stale

But:

- calculer les etats `new / due soon / stale / breached`

Livrable:

- service de classification
- contrats clairs des seuils
- tests unitaires ou feature sur la classification

### P1-003 - Request inbox query

But:

- centraliser le tri et les filtres du cockpit `Request`

Livrable:

- query claire
- tri stable
- support web et JSON

### P1-004 - Extensions analytics Request

But:

- ajouter les KPI de triage sans casser les KPI existants

Livrable:

- stale count
- breached count
- temps de prise en charge

### P1-005 - UI inbox rapide

But:

- faire apparaitre la file de triage dans `RequestTable.vue`

Livrable:

- segments rapides
- badges visuels
- filtres queue
- conservation de la table existante

### P1-006 - Board alignment

But:

- garder le board utile et coherent avec la logique inbox

Livrable:

- badges triage sur les cartes
- alignement des signaux visuels
- aucune regression drag and drop

### P1-007 - Quick actions manager / rep

But:

- reduire le nombre de clics pour les actions critiques

Livrable:

- assigner
- follow-up
- status change
- convert

### P1-008 - Non-regression suite

But:

- proteger le module `Request` pendant la livraison

Livrable:

- tests feature sur index et update
- tests bulk
- smoke UI sur table, board, convert

## 12. Acceptance criteria V1

La phase 1 est reussie si:

1. un lead ouvert peut etre classe automatiquement en queue de triage
2. la vue `Requests` permet de filtrer rapidement par queue
3. les leads a risque sont plus visibles qu'aujourd'hui
4. les actions assignation / relance / conversion restent simples
5. la conversion `Request -> Quote` n'est pas degradee
6. les analytics existantes restent disponibles
7. la vue board reste fonctionnelle
8. les bulk actions restent conformes au contrat existant

## 13. Non-regression obligatoire

Les parcours critiques a proteger sont:

1. affichage `request.index`
2. bascule table / board
3. update simple de statut
4. bulk update
5. conversion en devis
6. affichage detail `request.show`
7. pipeline `request -> quote`

Tests minimaux recommandes:

- feature tests sur classification de queue
- feature tests sur filtres inbox
- feature tests sur update `lost_reason`
- feature tests sur conversion en devis
- smoke UI sur table et board

## 14. KPIs de succes

Les KPIs a suivre pendant et apres la phase 1 sont:

- temps moyen premiere reponse
- temps moyen avant prise en charge
- volume de leads stale
- volume de leads breached
- taux lead -> quote
- volume de leads sans assignee

## 15. Rollout recommande

La phase 1 doit etre livree progressivement:

1. feature flag interne
2. verification analytics et classement
3. ouverture a un groupe pilote
4. validation des workflows table / board / convert
5. activation elargie

## 16. Definition de done de la phase 1

La phase 1 est terminee si et seulement si:

1. le schema additif est en place
2. les files `new / due soon / stale / breached` sont calculees
3. la vue `Requests` expose ces files clairement
4. les actions critiques restent disponibles sans friction supplementaire
5. les tests backend et frontend critiques sont verts
6. le flux `Request -> Quote` reste intact
7. la regression sur board et bulk actions est couverte

## 17. Recommendation finale

La meilleure phase 1 n'est pas une surcouche compliquee.

La meilleure phase 1 est:

- un cockpit `Request` plus net
- une logique de triage stable
- une priorisation visible
- des actions rapides
- des KPI simples

Si on livre cela proprement, on augmente la valeur du CRM sans toucher a l'architecture coeur de facon dangereuse.

## 18. Sources internes

- `app/Models/Request.php`
- `app/Http/Controllers/RequestController.php`
- `app/Http/Requests/Leads/UpdateLeadRequest.php`
- `resources/js/Pages/Request/Index.vue`
- `resources/js/Pages/Request/UI/RequestTable.vue`
- `resources/js/Pages/Request/UI/RequestBoard.vue`
- `resources/js/Pages/Request/UI/RequestAnalytics.vue`
- `resources/js/Components/UI/RequestStats.vue`
- `resources/js/utils/leadScore.js`
- `tests/Feature/WorkflowLeadTest.php`
- `tests/Feature/BulkActionResultContractTest.php`
- `docs/demo/module-requests-demo-20min.md`
- `docs/PHASE_0_CRM_REQUEST_FIRST_CADRAGE_2026-04-20.md`
