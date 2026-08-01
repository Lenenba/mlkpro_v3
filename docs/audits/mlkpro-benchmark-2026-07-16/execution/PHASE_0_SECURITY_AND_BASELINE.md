# Phase 0 — Sécurité et baseline

- Dernière mise à jour : 2026-08-01
- Statut : **en cours — P0-001 à P0-004 terminés ; P0-005 en validation côté exploitation ; P0-006 en validation avec correctif local vert, mais validation distante et campagne représentative bloquées**
- Responsable d’exécution locale : Codex
- Propriétaire exploitation : à nommer
- Validateur produit : demandeur
- Dépendances : aucune
- Risque de phase : élevé, car elle inclut secrets, dépendances et traitements asynchrones

## Objectif business

Réduire les risques qui pourraient affecter la confiance client, les communications et la fiabilité des automatisations avant d’investir dans la vitesse ou le design.

## Objectif technique

Obtenir un état de référence reproductible, supprimer les vulnérabilités prioritaires connues, rendre les files consommées vérifiables et disposer de mesures suffisantes pour choisir les optimisations suivantes.

## Pourquoi commencer ici

- des identifiants Twilio ont été exposés dans le contexte de travail ;
- les audits Composer et npm signalent des avis à traiter ;
- l’audit initial a révélé que `social_publish` n’était ni centralisé ni consommé par le worker de développement ; P0-005 corrige cet écart par un workload et un profil social explicites ;
- l’audit initial a révélé que la politique d’erreur de `AnalyzePlanScanJob` neutralisait ses retries pour les exceptions capturées ; P0-005 rend la reprise et l’échec terminal explicites ;
- les mesures d’observabilité actuelles sont insuffisantes pour comparer proprement les optimisations.

## Gate d’entrée

La phase ne passe à **validée** que si :

- [ ] responsable technique nommé ;
- [ ] propriétaire des comptes Twilio et environnements identifié ;
- [ ] environnement de référence choisi : staging représentatif ou production en lecture seule ;
- [x] branche de travail dédiée depuis `develop` convenue ;
- [ ] fenêtre de déploiement et contact de rollback définis ;
- [ ] validation que les preuves ne contiendront aucun secret ni donnée client directe.

## Baseline issue de l’audit

- build Vite de production réussi ;
- 1 119 tests Pest réussis en deux segments ;
- contrôle Pint réussi ;
- analyse PHPStan interrompue par le délai Composer à environ 90 %, sans erreur signalée jusque-là ;
- `composer audit --locked` et `npm audit --omit=dev` signalent des avis à traiter ;
- queue principale locale vide au moment de la mesure, mais inventaire workers/workloads incomplet ;
- observabilité `critical` fondée sur un seul échantillon ;
- scénarios de capacité sous le minimum d’échantillons configuré.

Cette baseline doit être confirmée au début de la phase et enregistrée dans [VALIDATION_LOG.md](VALIDATION_LOG.md).

## Workflows et contrats à protéger

- envoi SMS et WhatsApp ;
- authentification à deux facteurs par SMS ;
- notifications de tâches et agendas ;
- campagnes SMS ;
- publication sociale ;
- analyse asynchrone de plans ;
- traitement des campagnes, notifications, leads, travaux et démos ;
- installation reproductible à partir de `composer.lock` et `package-lock.json`.

## Scope V1

- rotation Twilio et vérification de l’ancien secret ;
- photographie reproductible avant changement ;
- correction des avis de sécurité prioritaires ;
- contrainte explicite de `twilio/sdk` ;
- centralisation et test des workloads asynchrones ;
- décision explicite sur les erreurs/retries de l’analyse de plans ;
- collecte d’une baseline exploitable par scénario ;
- documentation du rollout et du rollback.

## Hors-scope

- migration Redis générale ;
- optimisation SQL des écrans ;
- refonte de Preline, Ziggy ou i18n ;
- changement de workflow métier ;
- nouvelle fonctionnalité utilisateur ;
- refonte visuelle.

## Tickets

### MLK-IMP-P0-001 — Contenir l’exposition Twilio

- Statut : **terminé — promotion, révocation, rejet de l’ancien jeton, canaris et revue d’activité confirmés**
- Priorité : immédiate
- Propriétaire attendu : administrateur Twilio / exploitation
- But : rendre inutilisable tout jeton exposé et confirmer les communications avec un nouveau secret.
- Livrables : jeton révoqué, nouveau jeton injecté dans les environnements autorisés, services redémarrés, test SMS/WhatsApp, revue des journaux Twilio.
- Fichiers probables : aucun fichier versionné ; configuration sécurisée des environnements uniquement.
- Notes : ne jamais copier le nouveau secret dans un ticket, une capture, un commit, un terminal partagé ou le journal de validation.
- Procédure de rotation recommandée :
  1. examiner l’activité, les dépenses et les accès depuis l’exposition ;
  2. créer le jeton secondaire Twilio ;
  3. l’injecter dans chaque gestionnaire de secrets/environnement ;
  4. recharger la configuration et redémarrer les processus persistants ;
  5. tester SMS et WhatsApp sur un destinataire contrôlé ;
  6. si des webhooks entrants valident la signature Twilio, les tester avant promotion ;
  7. promouvoir le jeton secondaire en primaire, ce qui invalide l’ancien ;
  8. surveiller erreurs, délivrabilité, consommation et sous-comptes.
- Suite recommandée : ouvrir une décision séparée pour utiliser une clé API dédiée ou restreinte pour les appels sortants de production. Twilio recommande les clés API en production, tandis que l’Auth Token reste nécessaire à la validation de signatures de webhooks.
- Tests ciblés : `SecuritySettingsApiTest`, `NotificationSettingsTest`, `TaskLifecycleManagementTest`, puis un envoi canari vers un numéro contrôlé.
- Rollback : générer un autre jeton et réinjecter ; ne jamais réactiver le jeton exposé.
- Critères d’acceptation :
  - l’ancien jeton est rejeté par Twilio ;
  - le nouveau jeton n’apparaît dans aucun fichier suivi ;
  - SMS, WhatsApp et capacités 2FA configurées répondent comme attendu ;
  - les journaux Twilio depuis l’exposition ont été examinés ;
  - toute activité suspecte possède un incident séparé.
- Preuves : horodatage de rotation, identifiant d’audit Twilio non secret, résultats de tests expurgés.
- Sources officielles : [confinement et rotation](https://www.twilio.com/docs/usage/fraud-response-guide/contain), [jeton secondaire](https://www.twilio.com/docs/iam/api/secondary_authtoken), [clés API](https://www.twilio.com/docs/iam/api-keys), [bonnes pratiques API et webhooks](https://www.twilio.com/docs/usage/rest-api-best-practices).

### MLK-IMP-P0-002 — Geler la baseline avant changement

- Statut : **terminé — baseline locale enregistrée ; replays Node 20 et MySQL confirmés par la CI de clôture P0-003**
- Dépendance : P0-001 terminé
- But : disposer d’un point de comparaison réexécutable.
- Livrables : commit de départ, versions PHP/Node, audits, tests, build, santé queue et rapports d’observabilité/capacité consignés.
- Fichiers probables : [VALIDATION_LOG.md](VALIDATION_LOG.md) uniquement.
- Notes : exécuter PHPStan et Pest directement si le wrapper Composer atteint son délai fixe ; consigner la commande réellement utilisée.
- Rollback : sans objet, ticket en lecture seule.
- Critères d’acceptation : toutes les commandes de la section « Vérification » ont un résultat, une date, un environnement et un responsable.
- Preuves : entrée `BASELINE-P0` dans le journal.

### MLK-IMP-P0-003 — Remédier les dépendances PHP

- Statut : **terminé — Laravel 12.64, Carbon 3 et audit Composer sans avis ; installation propre, MySQL 8, Node 20 et Playwright confirmés par la CI**
- Dépendance : P0-002 terminé
- But : retirer les avis élevés/critiques applicables sans mise à jour globale non maîtrisée.
- Livrables : contraintes Composer revues, `twilio/sdk` explicitement contraint, `composer.lock` mis à jour, justification de chaque exception.
- Fichiers probables : `composer.json`, `composer.lock`.
- Notes : la famille Laravel/Inertia/Larastan/Breeze et les paquets avisés ont été renouvelés ensemble avec `--with-all-dependencies`. Les trois exceptions d’audit obsolètes ont été supprimées ; aucun avis n’est masqué.
- Tests : suite ciblée du domaine touché, PHPStan, Pest complet, MySQL ciblé et build frontend.
- Rollback : revenir au lock précédent par revert du commit du ticket ; aucun `git reset --hard`.
- Critères d’acceptation :
  - aucun avis élevé/critique de production non traité ;
  - chaque avis restant possède une décision, un propriétaire et une date de réévaluation ;
  - installation propre depuis le lock réussie ;
  - suite qualité verte.

### MLK-IMP-P0-004 — Remédier les dépendances JavaScript

- Statut : **terminé — audits npm sans avis ; installation propre, gate d’audit, build et Playwright confirmés sous Node 20 en CI**
- Dépendance : P0-002 terminé
- But : corriger les avis lodash/PostCSS sans upgrade forcé incontrôlé.
- Livrables : contraintes et lock npm revus, build reproductible, note sur tout breaking change.
- Fichiers probables : `package.json`, `package-lock.json`.
- Notes : ne pas utiliser `npm audit fix --force` sans revue explicite du diff et décision enregistrée.
- Tests : `npm ci`, `npm run qa:build`, `npm run qa:e2e` et tests des écrans utilisant les dépendances modifiées.
- Rollback : revert du commit de lock et réinstallation `npm ci`.
- Critères d’acceptation : aucun avis élevé/critique de production non traité, build et Playwright verts.

### MLK-IMP-P0-005 — Rendre l’inventaire queues/workers vérifiable

- Statut : **en validation — implémentation et gates locales/CI vertes ; déploiement et canari exploitation à consigner**
- Dépendances : P0-002 terminé
- But : garantir que tout job explicitement routé possède une configuration et au moins un worker consommateur.
- Livrables :
  - workloads `social_publish` et `plan_scans` explicitement routés vers `social-publish` et `plan-scans` ;
  - profils dynamiques `development`, `operations`, `plan-scans`, `campaigns` et `social` issus de `config/async.php` ;
  - commande `queue:workloads` pour résoudre et lancer les profils sans dupliquer les noms de files ;
  - commande `queue:workload-audit` pour contrôler workloads, profils, connexions, collisions physiques, files orphelines et cohérence timeout/visibilité ;
  - worker local et documentation de déploiement de production alignés ;
  - décision acceptée de dédier une file et un worker aux analyses de plans ;
  - test automatique qui compare les queues explicites aux queues consommées ;
  - politique d’exception de `AnalyzePlanScanJob` rendue explicite.
- Fichiers probables : `config/async.php`, `config/queue.php`, `.env.example`, `composer.json`, commandes Artisan, `app/Jobs/AnalyzePlanScanJob.php`, routage des jobs/notifications, tests unitaires/feature et documentation de déploiement.
- Topologie retenue : `operations` consomme `default`, notifications, leads, works et demos ; `plan-scans` isole les analyses longues ; `campaigns` regroupe les trois files de campagne ; `social` regroupe automatisation et publication. Le profil local `development` couvre `default` et les dix workloads.
- Délais retenus : timeout **configuré** `plan_scans` de 240 secondes, reprise après 60 secondes et `retry_after` de 300 secondes. Le délai de visibilité reste strictement supérieur au timeout maximal du profil ; SQS demeure un contrôle externe à confirmer.
- Notes : conserver les payloads, les statuts métier et les noms externes existants. Les exceptions techniques de l’analyse de plans sont relancées après persistance de l’état utile ; le hook `failed` porte l’échec terminal. Les résultats métier de repli produits par l’extracteur IA ne deviennent pas artificiellement des échecs de queue. Les profils `development` et `operations` gardent trois tentatives de fallback pour les notifications sans `$tries`, tandis que les jobs IA implicites conservent explicitement une tentative.
- Tests locaux : configuration async, audit positif/négatif, workload orphelin, connexion invalide, collision de files, priorité, résolution à sec de chaque profil, payload database réel, retry/backoff, journalisation best-effort, échec terminal et `queue:health --json`. La suite complète finale compte 1 179 tests et 12 236 assertions réussis.
- Déploiement : préprovisionner les consommateurs ou maintenir le trafic, exécuter `queue:workload-audit --json`, inspecter chaque profil avec `queue:workloads <profil> --dry-run --json`, démarrer les nouveaux consommateurs avant les producteurs, conserver `default` pendant le drainage, puis réaliser un canari contrôlé.
- Rollback : restaurer le routage précédent et redémarrer les workers, tout en conservant une release/commande de drainage, les consommateurs des anciennes et nouvelles files et `retry_after=300` jusqu’au traitement contrôlé des jobs déjà déposés. Voir [la stratégie de queues](../../../PHASE_6_QUEUE_STRATEGY_2026-03-07.md).
- Limite de preuve : la configuration des processus persistants et les workers de production ne sont pas déclarés vérifiés à ce stade ; leur contrôle et le canari doivent être consignés par l’exploitation.
- Critères d’acceptation :
  - aucun workload demandé n’est absent de `config/async.php` ;
  - toute queue configurée est consommée dans chaque environnement ;
  - les retries se produisent conformément à la décision ;
  - aucun job canari ne reste en attente ;
  - le test d’inventaire échoue volontairement lorsqu’une queue fictive non consommée est ajoutée.

### MLK-IMP-P0-006 — Établir la baseline d’observabilité

- Statut : **en validation — préparation technique fusionnée et correctif local vert ; validation distante, campagne représentative et canaris d’exploitation P0-005 bloquants**
- Dépendances : P0-002 terminé ; canaris d’exploitation P0-005 vérifiés avant toute acceptation de baseline
- But : obtenir assez d’échantillons pour décider Phase 1 et Phase 2 sur des faits.
- Livrables : latence client p50/p95/p99/max, temps de traitement applicatif, erreurs, résultats métier, requêtes lentes, taille de réponse, nombre de requêtes SQL et santé des queues par scénario critique.
- Activation : l’observabilité est opt-in et reste désactivée par défaut avec `OBSERVABILITY_ENABLED=false`. Une campagne requiert explicitement `OBSERVABILITY_ENABLED=true`, `OBSERVABILITY_CACHE_STORE=redis` et une valeur non vide de `OBSERVABILITY_RELEASE`.
- Stockage : Redis est requis pour une collecte partagée et à faible surcharge entre processus. Un cache local, `array`, `file` ou `database` ne constitue pas une preuve P0-006 recevable.
- Échantillonnage minimal : respecter `targets.min_samples` et le plancher de charge `profile.minimum_completed_requests` de `config/capacity.php` pour chaque scénario ; ne pas conclure à partir d’un seul échantillon ni d’une charge incomplète.
- Périmètre : six familles métier et sept scénarios exécutables, car la famille boutique publique est mesurée séparément en consultation et checkout :
  1. dashboard — `dashboard` ;
  2. détail client — `customer.show` ;
  3. création de réservation — `client.reservations.store` ;
  4. création de vente — `sales.store` ;
  5. demande publique — `public.requests.store` ;
  6. boutique publique — consultation `public.store.show` et checkout `public.store.checkout`.
- Protocole de collecte : utiliser un staging isolé et représentatif, explicitement inscrit dans `CAPACITY_ALLOWED_STAGING_ENVIRONMENTS`, ou une production en lecture seule explicitement approuvée. Isoler la fenêtre de mesure du trafic non contrôlé et ne mélanger ni environnements, ni commits, ni méthodes de collecte.
- Contexte obligatoire pour chaque campagne :
  - `CAPACITY_BASELINE_RUN_ID` : identifiant unique non client ;
  - `CAPACITY_BASELINE_ENVIRONMENT` : doit correspondre à l’environnement Laravel actif ;
  - `CAPACITY_BASELINE_COMMIT` : commit réellement déployé ;
  - `CAPACITY_BASELINE_STARTED_AT` et `CAPACITY_BASELINE_ENDED_AT` : fenêtre valide avec offset UTC explicite et comprise dans la rétention ;
  - `CAPACITY_BASELINE_TRAFFIC` et `CAPACITY_BASELINE_RUNNER` : origine/profil du trafic et runner externe approuvé ;
  - `CAPACITY_BASELINE_RUNNER_HASH` : empreinte SHA-256 hexadécimale du harness approuvé ; chaque résultat importé doit porter exactement la même empreinte ;
  - `CAPACITY_BASELINE_EXCLUSIONS` : exclusions explicites, `none` si aucune ;
  - `CAPACITY_BASELINE_MODE` : `staging` ou `production_read_only` ;
  - `CAPACITY_BASELINE_REPRESENTATIVE=true` ;
  - `CAPACITY_BASELINE_APPROVED=true` et `CAPACITY_BASELINE_APPROVAL_REFERENCE` non vide ;
  - `CAPACITY_BASELINE_QUEUE_CANARIES_VERIFIED=true` uniquement après preuve d’exploitation P0-005 ;
  - `CAPACITY_BASELINE_ISOLATED_TENANT_VERIFIED=true` lorsqu’au moins un scénario d’écriture non bloqué sera exécuté ; cette attestation est couverte par la référence d’approbation ;
  - `CAPACITY_BASELINE_OWNER` et `CAPACITY_BASELINE_VALIDATOR` renseignés et distincts.
- Contexte runtime : le plan et le rapport consignent aussi les connexions effectives de cache, base de données et queue. Elles ne doivent pas être déduites uniquement d’un fichier `.env` non chargé.
- Préflight et plan : `php artisan capacity:plan --json` doit retourner `ready_for_approved_harness`. Il refuse notamment l’observabilité inactive, une release absente, tout driver de cache effectif autre que Redis, un cache non partagé ou à surcharge élevée, un échec de lecture/écriture, des pertes de télémétrie, des métriques de queue non mesurables et tout dépassement déjà présent des seuils de backlog, d’âge du plus ancien job ou d’échecs sur 24 h.
- Ordre d’exécution obligatoire pour chaque clé de scénario :
  1. exécuter `php artisan capacity:scenario:start <scenario>` ; ce marqueur lie la télémétrie au scénario et capture le snapshot de queue initial. La fenêtre restante doit couvrir toute la durée du profil plus `CAPACITY_SCENARIO_START_BUFFER_SECONDS`, et une même clé ne peut démarrer qu’une fois dans le run ;
  2. exécuter le harness HTTP externe approuvé selon le manifeste produit par `capacity:plan`, avec le suivi automatique des redirections désactivé ;
  3. exécuter `php artisan capacity:scenario:stop <scenario>` ; le snapshot de queue final est capturé avant l’arrêt du marqueur ;
  4. déposer le seul agrégat JSON expurgé, de 64 Kio maximum et nommé sans segment de chemin, dans `storage/app/capacity-imports`, puis exécuter `php artisan capacity:result:import <scenario> <fichier.json> --json` ;
  5. après tous les scénarios, exécuter `php artisan capacity:report --json --strict`.
- Sémantique stricte : la commande retourne le code `0` pour `healthy` ou `accepted_with_blockers`. Le second statut indique qu’un ou plusieurs scénarios sont restés non exécutés sous un blocage formel valide ; il ne constitue pas une preuve d’exécution des sept scénarios. Les pipelines doivent archiver le rapport JSON et traiter ces deux statuts séparément.
- Snapshots de queue : le scheduler exécute `queue:health --record --json` à une cadence nominale de 60 s pendant toute la charge. Pour chaque scénario, la série attribuée au bon run doit couvrir l’intervalle complet du runner avec une grâce maximale de 30 s au début et à la fin, un écart maximal de 120 s entre deux captures et le nombre de captures imposé par cette cadence. Les seuls snapshots de début et de fin ne suffisent pas. Backlog, âge du plus ancien job prêt et échecs doivent tous être mesurables.
- Contrat runner : le payload agrégé utilise `schema_version: 1` et les champs stricts `run_id`, `environment`, `commit`, `scenario_key`, `manifest_hash`, `runner`, `runner_hash`, `started_at`, `ended_at`, `virtual_users`, `duration_seconds`, `ramp_up_seconds`, `attempted_requests`, `completed_requests`, `transport_errors`, `assertion_failures` et `client_latency_ms`. `manifest_hash` et `runner_hash` sont des empreintes SHA-256 hexadécimales de 64 caractères ; le premier doit correspondre exactement au scénario du plan, le second doit être exactement égal à `CAPACITY_BASELINE_RUNNER_HASH`, calculé sur le harness approuvé. Voir [l’exemple JSON](capacity-runner-result.example.json), dont les identifiants et empreintes fictifs doivent impérativement être remplacés.
- Validation du résultat runner : identité de campagne et profil de charge doivent correspondre au plan, `attempted_requests` et `completed_requests` doivent atteindre `profile.minimum_completed_requests`, les horodatages doivent rester dans la fenêtre baseline, les compteurs doivent être cohérents, et `transport_errors` comme `assertion_failures` doivent être à zéro. Tout champ supplémentaire est refusé afin d’empêcher l’import de sorties brutes.
- Deux familles de temps : `client_latency_ms.p50/p95/p99/max`, calculée par le runner externe, représente le temps de bout en bout vu par le client et porte les seuils de capacité. `app_processing_ms`, collecté par Laravel, représente seulement le traitement applicatif et sert au diagnostic ; il ne remplace pas la latence client.
- Protection des données : seules des métriques agrégées et expurgées sont versionnées. Les chemins bruts, paramètres, messages d’exception, SQL, bindings, identifiants et données client restent hors du dépôt ; un artefact brut éventuel demeure dans un stockage contrôlé avec accès limité.
- Rollback : positionner `OBSERVABILITY_ENABLED=false`, recharger la configuration avec la procédure de l’environnement, redémarrer les processus PHP persistants et vérifier que le scheduler ne crée plus de snapshots. Conserver uniquement les résultats déjà collectés et expurgés ; les fichiers d’import contrôlés peuvent être archivés ou supprimés selon la politique d’exploitation.
- Blocage actuel : l’instrumentation, les manifestes, le préflight et le contrat d’import sont préparés techniquement, mais la CI liée à la PR #134 a échoué sur cinq tests d’observabilité. Le correctif est vert localement sans validation distante, et aucune campagne représentative complète n’est consignée. Les canaris d’exploitation P0-005, l’environnement, la fenêtre, le propriétaire/validateur et les mesures représentatives restent bloquants. Aucun test P0-006 non exécuté n’est revendiqué comme vert.
- Critères d’acceptation :
  - les sept scénarios exécutables atteignent `targets.min_samples` et `profile.minimum_completed_requests`, ou sont explicitement marqués bloqués avec propriétaire, justification et échéance ;
  - le préflight et le plan sont recevables sur le commit et l’environnement mesurés ;
  - l’environnement, la période, le trafic, les exclusions, les approbations et les canaris P0-005 sont consignés ;
  - le résultat runner agrégé de chaque scénario correspond au manifeste, au hash du harness, au profil et au compteur interne de requêtes ;
  - les snapshots de queue couvrent tout l’intervalle runner de chaque scénario à 60 s de cadence nominale, sans écart supérieur à 120 s et avec au plus 30 s de grâce aux extrémités ;
  - les mesures ne contiennent aucune donnée client directe ;
  - latence client, traitement applicatif, erreurs, résultats métier, requêtes lentes, taille de réponse, nombre de requêtes SQL et santé des queues sont disponibles selon une méthode reproductible ;
  - les trois premiers candidats de Phase 1 sont choisis à partir de la baseline.

### MLK-IMP-P0-007 — Revue GO / NO-GO de Phase 0

- Statut : **à valider**
- Dépendances : P0-001 à P0-006 terminés ou exception formellement acceptée
- But : autoriser ou refuser l’ouverture de la Phase 1.
- Livrable : décision signée dans [VALIDATION_LOG.md](VALIDATION_LOG.md).
- Critère d’acceptation : aucune case de la gate de sortie ne reste indéterminée.

## Ordre d’exécution

```text
P0-001 Rotation Twilio
       ↓
P0-002 Baseline gelée
       ↓
P0-003 Dépendances PHP ─┐
P0-004 Dépendances JS  ├─→ P0-007 GO / NO-GO
P0-005 Queues/workers  ┤
P0-006 Observabilité ──┘
```

## Commandes de vérification

Les commandes sont exécutées depuis la racine du dépôt. Les résultats sont consignés, pas collés intégralement s’ils contiennent des chemins ou données sensibles.

```powershell
composer audit --locked
npm audit --omit=dev
composer qa:format
php vendor/bin/phpstan analyse --memory-limit=1G
php -d memory_limit=512M vendor/bin/pest
composer qa:test:mysql
npm run qa:build
npm run qa:e2e
php artisan queue:health --json
php artisan observability:report --json
php artisan capacity:plan --json
php artisan capacity:scenario:start dashboard_usage
# exécuter ici le harness externe approuvé
php artisan capacity:scenario:stop dashboard_usage
php artisan capacity:result:import dashboard_usage dashboard-usage.json --json
php artisan capacity:report --json --strict
git diff --check
```

Après une rotation de configuration :

```powershell
php artisan config:cache
php artisan queue:restart
```

`config:cache` remplace le cache de configuration existant ; il n’est pas nécessaire de le précéder de `config:clear`. Sur un environnement local sans cache de configuration, `config:clear` suffit si un ancien cache doit être retiré et il ne faut pas créer un cache uniquement pour la rotation. `queue:restart` demande aux workers de sortir après leur travail courant mais ne les redémarre pas : le gestionnaire de processus de chaque environnement doit être vérifié. Les workers concernés sont ensuite contrôlés par un job canari sans donnée client.

## Rollout et rollback

| Changement | Rollout | Signal de succès | Déclencheur de rollback | Rollback |
|---|---|---|---|---|
| Twilio | Un environnement puis canari | SMS/WhatsApp contrôlé livré | Auth rejetée ou envoi erroné | Nouveau jeton de remplacement et réinjection |
| Composer/npm | Une famille par PR | CI, tests et build verts | Régression ou incompatibilité | Revert du commit de lock |
| Queues | Worker prêt avant routage | Job canari consommé, backlog stable | Job ancien ou échecs en hausse | Rétablir routage, redémarrer worker, drainer l’ancienne file |
| Observabilité | Échantillonnage limité | Données suffisantes sans surcharge | Latence ou stockage anormal | Désactivation/configuration précédente |

## Gate de sortie

- [ ] l’ancien jeton Twilio est inutilisable et la rotation est prouvée sans exposer le nouveau ;
- [ ] aucun avis élevé/critique de production ne reste sans décision de risque ;
- [ ] `twilio/sdk` n’utilise plus une contrainte ouverte `*` ;
- [ ] toutes les queues explicites sont centralisées et consommées ;
- [ ] la politique de retry de l’analyse de plans est testée ;
- [ ] PHPStan, Pest, MySQL ciblé, build et Playwright sont verts ou une exception datée est acceptée ;
- [ ] les scénarios critiques possèdent une baseline exploitable ;
- [ ] rollout et rollback ont été vérifiés ;
- [ ] décision GO / NO-GO consignée.

## Definition of Done

La Phase 0 est terminée uniquement lorsque la gate de sortie est entièrement renseignée, les preuves sont dans le journal, les décisions ouvertes ont un propriétaire et la Phase 1 possède une liste priorisée fondée sur les mesures obtenues.

## Documents liés

- [Cockpit](README.md)
- [Décisions](DECISIONS.md)
- [Journal de validation](VALIDATION_LOG.md)
- [Rapport d’audit](../report.html)
- [Stratégie queue existante](../../../PHASE_6_QUEUE_STRATEGY_2026-03-07.md)
- [User story Redis existante](../../../REDIS_PERFORMANCE_USER_STORY.md)
