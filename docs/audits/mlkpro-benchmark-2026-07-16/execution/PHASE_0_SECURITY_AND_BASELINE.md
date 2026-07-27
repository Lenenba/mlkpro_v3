# Phase 0 — Sécurité et baseline

- Dernière mise à jour : 2026-07-27
- Statut : **en cours — P0-003 en validation après migration Laravel 12.64 et audit Composer sans avis**
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
- `social_publish` n’est pas centralisé dans la configuration asynchrone ni consommé par le worker de développement ;
- la politique d’erreur de `AnalyzePlanScanJob` neutralise ses retries pour les exceptions capturées ;
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

- Statut : **terminé — baseline locale enregistrée avec limites Node 20 et MySQL explicitées**
- Dépendance : P0-001 terminé
- But : disposer d’un point de comparaison réexécutable.
- Livrables : commit de départ, versions PHP/Node, audits, tests, build, santé queue et rapports d’observabilité/capacité consignés.
- Fichiers probables : [VALIDATION_LOG.md](VALIDATION_LOG.md) uniquement.
- Notes : exécuter PHPStan et Pest directement si le wrapper Composer atteint son délai fixe ; consigner la commande réellement utilisée.
- Rollback : sans objet, ticket en lecture seule.
- Critères d’acceptation : toutes les commandes de la section « Vérification » ont un résultat, une date, un environnement et un responsable.
- Preuves : entrée `BASELINE-P0` dans le journal.

### MLK-IMP-P0-003 — Remédier les dépendances PHP

- Statut : **en validation — Laravel 12.64, Carbon 3 et audit Composer validés localement ; installation propre CI, MySQL et Node 20 restent à rejouer**
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

- Statut : **à valider**
- Dépendance : P0-002 terminé ; peut avancer en parallèle de P0-003 sur une branche distincte
- But : corriger les avis lodash/PostCSS sans upgrade forcé incontrôlé.
- Livrables : contraintes et lock npm revus, build reproductible, note sur tout breaking change.
- Fichiers probables : `package.json`, `package-lock.json`.
- Notes : ne pas utiliser `npm audit fix --force` sans revue explicite du diff et décision enregistrée.
- Tests : `npm ci`, `npm run qa:build`, `npm run qa:e2e` et tests des écrans utilisant les dépendances modifiées.
- Rollback : revert du commit de lock et réinstallation `npm ci`.
- Critères d’acceptation : aucun avis élevé/critique de production non traité, build et Playwright verts.

### MLK-IMP-P0-005 — Rendre l’inventaire queues/workers vérifiable

- Statut : **à valider**
- Dépendances : P0-002 terminé
- But : garantir que tout job explicitement routé possède une configuration et au moins un worker consommateur.
- Livrables :
  - workload `social_publish` ajouté à `config/async.php` ou décision de routage différente enregistrée ;
  - worker local et documentation de production alignés ;
  - décision sur `plan_scans` : `default` assumé ou file dédiée ;
  - test automatique qui compare les queues explicites aux queues consommées ;
  - politique d’exception de `AnalyzePlanScanJob` rendue explicite.
- Fichiers probables : `config/async.php`, `composer.json`, configuration Supervisor/Horizon/deployment, `app/Jobs/AnalyzePlanScanJob.php`, tests unitaires/feature.
- Notes : conserver les payloads, les statuts métier et les noms externes existants. Si une exception doit être terminale, l’indiquer et tester `failed`; si elle doit être réessayée, la relancer après persistance de l’état utile.
- Tests : configuration async, dispatch de chaque job, retry/backoff, échec terminal, `queue:health --json`.
- Rollback : restaurer le routage précédent et redémarrer les workers ; prévoir le traitement des jobs déjà présents dans l’ancienne file.
- Critères d’acceptation :
  - aucun workload demandé n’est absent de `config/async.php` ;
  - toute queue configurée est consommée dans chaque environnement ;
  - les retries se produisent conformément à la décision ;
  - aucun job canari ne reste en attente ;
  - le test d’inventaire échoue volontairement lorsqu’une queue fictive non consommée est ajoutée.

### MLK-IMP-P0-006 — Établir la baseline d’observabilité

- Statut : **à valider**
- Dépendances : P0-002 terminé ; P0-005 recommandé avant les mesures de queue finales
- But : obtenir assez d’échantillons pour décider Phase 1 et Phase 2 sur des faits.
- Livrables : p50/p95/p99, erreurs, requêtes lentes, taille de réponse, nombre de requêtes et santé des queues par scénario critique.
- Fichiers probables : configuration d’environnement et journal de validation ; code uniquement si l’instrumentation actuelle ne peut pas produire la mesure.
- Échantillonnage minimal : respecter `targets.min_samples` de `config/capacity.php` pour chaque scénario ; ne pas conclure à partir d’un seul échantillon.
- Scénarios prioritaires : dashboard, détail client, création de réservation, création de vente, demande publique et boutique publique.
- Rollback : désactiver l’instrumentation ajoutée par configuration si elle augmente la charge ; conserver les résultats déjà collectés.
- Critères d’acceptation :
  - tous les scénarios prioritaires atteignent leur minimum d’échantillons ou sont explicitement marqués bloqués ;
  - l’environnement, la période, le trafic et les exclusions sont consignés ;
  - les mesures ne contiennent aucune donnée client directe ;
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
php artisan capacity:report --json
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
