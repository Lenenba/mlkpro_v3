# Phase 2 CRM - Quote Recovery and Conversion Cockpit

Derniere mise a jour: 2026-04-20

## 1. But de la phase 2

La phase 2 transforme le module `Quote` en vraie file de suivi commercial orientee revenu.

Le but n'est pas de recreer un CRM sales-first complet.

Le but est de rendre visible, priorisee et actionnable la couche qui se situe entre:

1. devis emis
2. devis consultes
3. devis relances
4. devis acceptes

La phase 2 doit augmenter le taux `quote -> accepted` sans casser les workflows existants.

## 2. Decision produit

Decision retenue:

- la phase 2 reste strictement `Quote-first`
- aucun nouvel objet `Opportunity`
- aucun forecast commercial avance en V1
- aucune automation complexe multi-canal en V1
- aucune rupture du flux `Request -> Quote -> Work`

La phase 2 doit etre une evolution additive de:

- `app/Models/Quote.php`
- `app/Http/Controllers/QuoteController.php`
- `resources/js/Pages/Quote/Index.vue`
- `resources/js/Pages/Quote/UI/QuoteTable.vue`
- `resources/js/Pages/Quote/UI/QuoteActionsMenu.vue`
- `resources/js/Components/UI/QuoteStats.vue`

## 3. Baseline actuelle observee

Le module `Quote` a deja de vraies bases solides:

- index avec filtres et stats globales
- statuts `draft / sent / accepted / declined`
- archivage `archived_at`
- lien `Quote -> Customer`
- lien `Quote -> Request`
- acceptation et conversion vers `Work`
- synchronisation `Quote -> Request status`
- portail public de consultation et d'acceptation
- journalisation d'activite deja disponible dans la plateforme

Cela veut dire que la phase 2 n'est pas une creation.

C'est une montee en puissance du suivi devis.

## 4. Probleme que la phase 2 doit resoudre

Aujourd'hui, le module `Quote` sait stocker, afficher et accepter un devis.

Mais il manque encore une vraie logique de recuperation commerciale:

- les devis ouverts ne sont pas classes en file de relance
- l'anciennete et l'absence de suivi ne sont pas le centre de la vue
- les actions de relance ne sont pas assez visibles depuis l'index
- les signaux "vu mais non accepte" ou "a forte valeur" ne sont pas organises en cockpit

Le risque actuel est simple:

- les devis existent
- mais le revenu perdu entre envoi et acceptation n'est pas encore traite comme une file de travail quotidienne

## 5. Resultat attendu de la phase 2

En sortie de phase 2, un owner ou sales rep doit pouvoir:

1. ouvrir `Quotes`
2. voir immediatement quels devis demandent une action
3. comprendre quels devis sont `never followed`, `due`, `viewed not accepted`, `expired` ou `high value`
4. relancer, planifier, assigner ou archiver depuis l'index
5. suivre quelques KPI simples qui montrent si l'equipe recupere bien les devis emis

## 6. Scope fonctionnel V1

### 6.1 Nouvelles files de travail

La V1 doit introduire des segments de recovery minimum:

- `never_followed`
- `due`
- `viewed_not_accepted`
- `expired`
- `high_value`

Definition metier recommandee:

- `never_followed`: devis `sent` sans relance depuis l'envoi
- `due`: devis `sent` avec prochaine relance echue ou proche
- `viewed_not_accepted`: devis consulte mais pas encore accepte
- `expired`: devis echu ou trop ancien sans decision
- `high_value`: devis ouvert au-dessus d'un seuil de valeur

### 6.2 Priorisation visible

Chaque devis ouvert doit exposer clairement:

- priorite de recovery
- age du devis
- derniere relance
- prochaine action
- statut de suivi
- montant
- signal de consultation si disponible

### 6.3 Actions rapides

La phase 2 doit rendre plus visibles les actions suivantes:

- envoyer une relance email
- creer une tache de suivi
- planifier ou modifier la prochaine relance
- marquer le suivi comme fait
- archiver un devis froid
- ouvrir le detail ou le portail public rapidement

### 6.4 Widgets manager

La V1 doit afficher des indicateurs simples et utiles:

- devis ouverts
- devis sans relance
- devis avec relance due
- devis a forte valeur non signes
- taux de conversion `sent -> accepted`

### 6.5 Timeline legere

Sans imposer une refonte lourde du detail `Quote`, la phase 2 peut ajouter une timeline legere pour:

- envoi du devis
- consultation du devis
- relances effectuees
- prochaine action
- acceptation ou declin

Regle:

- cette timeline doit etre additive
- elle ne doit pas casser `Quote/Create`, `Quote/Show` ni le portail public

## 7. Evolutions de donnees recommandees

Pour rester additives et compatibles avec la base existante, les evolutions prioritaires sont:

- `last_sent_at`
- `last_viewed_at`
- `last_followed_up_at`
- `next_follow_up_at`
- `follow_up_state`
- `follow_up_count`
- `recovery_priority`

### 7.1 Sens recommande des champs

- `last_sent_at`
  - date du dernier envoi explicite du devis
- `last_viewed_at`
  - date de la derniere consultation detectee
- `last_followed_up_at`
  - date de la derniere relance commerciale
- `next_follow_up_at`
  - prochaine action attendue sur le devis
- `follow_up_state`
  - lecture simple du statut de suivi
- `follow_up_count`
  - nombre de relances effectuees
- `recovery_priority`
  - niveau de priorite normalise pour le classement

### 7.2 Note importante sur l'expiration

Le schema actuel ne semble pas porter de vrai champ `expires_at` sur `Quote`.

Decision recommandee:

- ne pas persister `quote_age_days`
- calculer l'age a la vollee dans la query ou le serializer
- introduire `expires_at` seulement si le metier a une vraie regle d'expiration

Si `expires_at` n'est pas introduit en V1, le segment `expired` doit etre interprete comme:

- devis `sent`
- plus vieux qu'un seuil defini
- sans acceptation, declin ni archivage

## 8. Regles de recovery recommandees

Les regles doivent rester simples, lisibles et stables.

### 8.1 Classification recommandee V1

- `never_followed`
  - statut `sent`
  - `follow_up_count = 0`
- `due`
  - statut `sent`
  - `next_follow_up_at` dans la fenetre proche ou deja depassee
- `viewed_not_accepted`
  - statut `sent`
  - `last_viewed_at` non nul
  - `accepted_at` nul
- `expired`
  - statut `sent`
  - anciennete superieure au seuil V1
- `high_value`
  - statut ouvert
  - `total` au-dessus d'un seuil configurable plus tard

### 8.2 Seuils recommandes V1

Les seuils doivent rester parametrables plus tard, mais une V1 simple peut partir sur:

- `due`: aujourd'hui ou dans les prochaines 48 heures
- `expired`: 14 jours apres envoi si aucun vrai `expires_at`
- `high_value`: au-dessus de la mediane historique ou d'un seuil fixe simple

### 8.3 Priorite recommandee

Ordre de tri recommande:

1. `viewed_not_accepted`
2. `due`
3. `high_value`
4. `never_followed`
5. `expired`
6. autres devis ouverts

Puis a l'interieur:

- montant desc
- prochaine action la plus proche
- anciennete la plus forte

## 9. Regles anti-regression

La phase 2 doit respecter des contraintes fortes:

1. aucune rupture du portail public de devis
2. aucune rupture de `QuoteController@edit`, `store`, `update`, `accept`, `archive`
3. aucune rupture du sync `Quote -> Request status`
4. aucune rupture du flux `accepted -> Work`
5. migrations additives uniquement
6. les nouveaux signaux ne doivent jamais rendre un devis accepte ou archive editable par erreur

## 10. Fichiers coeur a proteger

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

## 11. Suites de tests recommandees

Suites dediees:

- `tests/Feature/QuoteRecoveryPhaseTwoTest.php`
- `tests/Feature/QuoteRecoveryAnalyticsPhaseTwoTest.php`
- `tests/e2e/quote-recovery-smoke.spec.js`

Regle:

- ne pas tout empiler dans `WorkflowTest`
- creer une vraie suite dediee au recovery devis

## 12. Definition de done de la phase 2

La phase 2 est terminee si:

1. les signaux additifs de suivi devis sont en place
2. la file `Quote` expose les segments de recovery
3. les actions rapides de relance sont pilotables depuis `Quote`
4. la timeline legere de suivi existe
5. le flux `Request -> Quote -> Work` reste intact
6. une suite feature et un smoke E2E existent pour cette phase

## 13. Documents lies

- `docs/PLAN_STRATEGIQUE_CRM_COMPETITIF_2026-04-20.md`
- `docs/CRM_DEV_EXECUTION_PHASES_2026-04-20.md`
- `docs/PHASE_1_LEAD_SLA_INBOX_SMART_TRIAGE_2026-04-20.md`
- `docs/PHASE_1_REQUEST_INBOX_DEV_BACKLOG_2026-04-20.md`
