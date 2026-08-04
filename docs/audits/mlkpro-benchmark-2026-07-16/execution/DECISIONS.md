# Registre des décisions — programme d’amélioration MLK Pro

Dernière mise à jour : 2026-08-04

## Règle

Une décision est requise lorsqu’un choix modifie le scope, l’ordre des phases, un contrat protégé, une cible, un risque accepté ou une stratégie de rollout/rollback.

Statuts possibles : **proposée**, **acceptée**, **rejetée**, **remplacée**, **à réévaluer**.

Une proposition n’autorise aucun changement tant qu’elle n’est pas acceptée par les validateurs nommés.

## Index

| ID | Décision | Statut | Responsable | Échéance |
|---|---|---|---|---|
| MLK-DEC-001 | Exécuter la Phase 0 avant les optimisations visibles | Proposée | À nommer | Avant démarrage |
| MLK-DEC-002 | Une seule phase active à la fois | Proposée | À nommer | Avant démarrage |
| MLK-DEC-003 | Isoler `plan_scans` sur une file et un worker dédiés | Acceptée | Technique / exploitation | Phase 0 |
| MLK-DEC-004 | Ajouter et exploiter le workload `social_publish` | Acceptée | Technique / exploitation | Phase 0 |
| MLK-DEC-005 | Migrer Redis dans l’ordre cache → queue → décision sessions | Proposée | Technique / exploitation | Avant Phase 2 |
| MLK-DEC-006 | Utiliser des clés API Twilio en production pour les appels sortants | À réévaluer après rotation | Sécurité / exploitation | Après P0-001 |
| MLK-DEC-007 | Positionner MLK Pro comme OS opérationnel et financier des PME canadiennes de services | À valider par recherche | Produit | Avant Phase 4 |
| MLK-DEC-008 | Utiliser exclusivement `develop` comme base et cible des travaux automatisés | Acceptée | Jules Roger Sombangnen | Permanente |
| MLK-DEC-009 | Collecter P0-006 sur un staging isolé avec preuves expurgées | Proposée | Technique / exploitation | Avant collecte représentative |

## MLK-DEC-001 — Phase 0 en premier

- Statut : **proposée**
- Contexte : identifiants exposés, avis de dépendances, queue confirmée non consommée et mesures insuffisantes.
- Décision proposée : fermer la Phase 0 avant Preline, Ziggy, SQL, Redis ou refonte visuelle.
- Avantage : réduit le risque et donne une baseline fiable.
- Coût : retarde de quelques jours les améliorations visibles.
- Validation requise : produit, technique et exploitation.

## MLK-DEC-002 — Une seule phase active

- Statut : **proposée**
- Décision proposée : une seule phase peut être `en cours`; des tickets d’une même phase peuvent être parallèles si leurs branches, propriétaires et gates sont indépendants.
- Avantage : rollback et responsabilité plus simples.
- Exception : incident de sécurité indépendant, toujours prioritaire.

## MLK-DEC-003 — Routage de `plan_scans`

- Statut : **acceptée**.
- Date : 2026-07-27.
- Options considérées : conserver `default` et aligner le job, ou utiliser une file `plan-scans` avec un profil de worker dédié.
- Décision : retenir la file dédiée `plan-scans`. Le profil de développement la consomme avec les autres workloads ; en production, le profil `plan-scans` la consomme seul.
- Raisons : le pipeline peut approcher 240 secondes et ne doit pas bloquer les notifications, travaux ou jobs implicites de `default`. L’isolation permet aussi de dimensionner et superviser ce traitement long séparément.
- Délais : timeout worker configuré de 240 secondes pour ce profil, backoff du job de 60 secondes et `retry_after` de 300 secondes pour les connexions base de données, Redis ou Beanstalkd. La visibilité SQS doit être strictement supérieure au timeout maximal et reste un contrôle externe explicite.
- Transition : les profils `development` et `operations` continuent de consommer `default` afin de traiter les jobs historiques déposés avant le changement de routage.
- Priorité : les workloads explicites précèdent `default` afin que le trafic historique ou implicite n’affame pas les files métier.
- Garde-fou : `queue:workload-audit` doit prouver l’assignation statique, mais l’exploitation doit encore vérifier les processus réels et exécuter un canari contrôlé avant de déclarer la production validée.
- Déploiement : préprovisionner les consommateurs compatibles ou maintenir le trafic pendant la bascule ; aucun producteur ne doit écrire sur la nouvelle file avant que son consommateur soit actif.
- Rollback : restaurer temporairement le routage vers `default`, conserver une release ou une commande brute de drainage et `retry_after=300` jusqu’au vidage des files, puis seulement retirer les nouveaux consommateurs. Aucun job déjà déposé sur `plan-scans` ne doit être abandonné.

## MLK-DEC-004 — Workload `social_publish`

- Statut : **acceptée**.
- Date : 2026-07-27.
- Décision : ajouter le workload canonique `social_publish`, résolu par défaut vers `social-publish`, avec backoff `[30, 120, 300]`.
- Consommation : le profil `development` le consomme localement ; le profil de production `social` consomme `social_automation` et `social_publish`.
- Raisons : supprimer le fallback non déclaré, rendre l’inventaire vérifiable et isoler la publication externe des files opérationnelles et des campagnes.
- Politique d’erreur : une erreur fournisseur déjà transformée en échec métier n’est pas relancée automatiquement, afin d’éviter une publication en double ; seuls les défauts d’infrastructure ou de persistance non absorbés restent éligibles aux retries du job.
- Priorité : `social-publish` est sondée avant `social-automation` pour terminer les publications déjà promises avant de générer de nouveaux candidats.
- Garde-fou : aucun canal public réel ne sert de canari sans approbation. Utiliser une cible de test ou une publication privée contrôlée, puis confirmer la consommation et l’absence d’échec inattendu.
- Validation restante : la configuration et l’activité des workers de production ne sont pas considérées comme vérifiées avant déploiement, contrôle du gestionnaire de processus et canari consigné.
- Rollback : conserver le consommateur `social-publish` tant que sa file contient des jobs, même si le producteur revient à la version précédente.

## MLK-DEC-005 — Ordre Redis

- Statut : **proposée**
- Décision proposée : cache d’abord, queue ensuite, sessions uniquement sur besoin mesuré.
- Garde-fou : MySQL reste la source de vérité ; chaque bascule est configurable et réversible.

## MLK-DEC-006 — Identité Twilio de production

- Statut : **à réévaluer après rotation**
- Contexte : Twilio recommande des clés API pour les appels API de production ; l’Auth Token reste utilisé pour valider des signatures de webhooks entrants.
- Décision à prendre : adapter la configuration/services pour séparer appels sortants et validation de webhooks, avec clé restreinte si compatible.
- Sources : [clés API Twilio](https://www.twilio.com/docs/iam/api-keys), [bonnes pratiques](https://www.twilio.com/docs/usage/rest-api-best-practices).

## MLK-DEC-007 — Positionnement

- Statut : **à valider par recherche**
- Hypothèse : MLK Pro gagne davantage en étant l’OS opérations-finance des PME canadiennes de services qu’en copiant QuickBooks fonction par fonction.
- Validation : entretiens, analyse des tâches, volonté de payer et pilotes par segment.

## MLK-DEC-008 — Politique Git `develop` uniquement

- Statut : **acceptée**.
- Date : 2026-07-17.
- Responsable et validateur : Jules Roger Sombangnen.
- Décision : tout agent ou collaborateur automatisé travaille sur `develop` ou sur une branche créée depuis `develop`, et toute pull request automatisée cible `develop`.
- Protection de `main` : aucun agent ne crée de commit, ne pousse, ne fusionne ni n’ouvre de pull request vers `main`. Seul Jules Roger Sombangnen peut effectuer ces opérations.
- Procédure : avant toute modification, vérifier la branche active ; si elle est `main`, basculer sur `develop` puis créer une branche dédiée.
- Cas ambigu : livrer le travail jusqu’à `develop` et laisser au propriétaire toute décision ou opération ultérieure concernant `main`.
- Référence impérative : `AGENTS.md` à la racine du dépôt.

## MLK-DEC-009 — Protocole de baseline d’observabilité P0-006

- Statut : **proposée — contrat v3 techniquement validé localement, validation représentative absente**.
- Date : 2026-08-04.
- Contexte : la baseline locale P0-002 est insuffisante pour choisir les optimisations ; aucun environnement ni jeu d’échantillons représentatif P0-006 n’est encore validé.
- Décision proposée : utiliser un staging isolé et représentatif. Une production en lecture seule n’est permise qu’après approbation explicite du produit, de la technique et de l’exploitation.
- Activation : conserver l’observabilité opt-in et désactivée par défaut (`OBSERVABILITY_ENABLED=false`). Une campagne approuvée active temporairement la collecte, exige Redis comme cache partagé (`OBSERVABILITY_CACHE_STORE=redis`) et identifie la release avec `OBSERVABILITY_RELEASE`.
- Périmètre : six familles et sept scénarios exécutables — dashboard, détail client, création de réservation, création de vente, demande publique, consultation boutique et checkout boutique.
- Mesures : latence client p50/p95/p99/max, temps de traitement applicatif séparé, échantillons, résultats métier, erreurs, requêtes lentes, taille de réponse, nombre de requêtes SQL et santé des queues.
- Contexte exigé : identifiant de run, commit, environnement, fenêtre UTC, profil et origine du trafic, runner externe et `CAPACITY_BASELINE_RUNNER_HASH`, fixture privée et `CAPACITY_BASELINE_FIXTURE_HASH`, origines HTTPS exactes dans `CAPACITY_BASELINE_ALLOWED_ORIGINS`, exclusions, mode, représentativité, approbation et référence, canaris P0-005, configuration runtime cache/base/queue, responsable et validateur distincts. Le mode staging exige l’appartenance à `CAPACITY_ALLOWED_STAGING_ENVIRONMENTS` ; toute écriture non bloquée exige `CAPACITY_BASELINE_ISOLATED_TENANT_VERIFIED=true` sous la même approbation.
- Préflight : exiger un `capacity:plan --json` prêt avant le harness. Le plan doit refuser un contexte incomplet, une release absente, tout driver effectif autre que Redis, un échec de lecture/écriture, une perte de télémétrie, des mesures de queue incomplètes ou des seuils queue déjà dépassés.
- Séquençage : pour chaque scénario, imposer `capacity:scenario:start` → harness externe approuvé sans suivi automatique des redirections → `capacity:scenario:stop` → `capacity:result:import`; exécuter `capacity:report` uniquement après les imports. Le start exige la durée complète plus `CAPACITY_SCENARIO_START_BUFFER_SECONDS` dans la fenêtre restante et interdit de rejouer la même clé dans le run. `queue:health --record --json` couvre tout l’intervalle runner à une cadence nominale de 60 s, avec au plus 120 s entre captures et 30 s de grâce aux extrémités ; le start et le stop seuls ne suffisent pas.
- Preuve externe : accepter uniquement un résultat JSON agrégé au schéma fermé v3. `manifest_hash` lie le scénario, `fixture_hash` la fixture v2, `baseline_fingerprint` le contexte complet, `target_origin_hash` l’origine approuvée sans l’exposer et `runner_hash` le harness exact. La cadence `request_interval_ms` et le délai `request_timeout_ms` font partie du profil vérifié. `attempted_requests` et `completed_requests` atteignent le `profile.minimum_completed_requests` ; tout champ brut ou inconnu est refusé.
- Sémantique de latence : appliquer les seuils de capacité aux percentiles de latence client mesurés par le harness externe. Conserver le traitement applicatif Laravel comme mesure de diagnostic séparée, car il n’inclut pas à lui seul réseau, proxy et runtime HTTP.
- Protection des données : ne versionner que des agrégats expurgés. Les sorties brutes, chemins, paramètres, messages d’exception, SQL, bindings, identifiants, secrets et données client restent dans un stockage contrôlé ou sont supprimés.
- Garde-fous : ne pas mélanger les environnements, commits ou méthodes ; vérifier explicitement l’environnement staging et l’isolation du tenant pour toute écriture ; satisfaire `targets.min_samples`, `profile.minimum_completed_requests` et la couverture temporelle des queues, ou documenter le blocage avec propriétaire et échéance ; ne pas choisir les candidats Phase 1 avant une baseline recevable.
- Rollback : remettre `OBSERVABILITY_ENABLED=false`, recharger la configuration, redémarrer les processus PHP persistants et vérifier l’arrêt des snapshots planifiés si la collecte dégrade latence, mémoire ou stockage.
- Validation manquante : environnement, fenêtre, propriétaire exploitation, validateur distinct, canaris P0-005 et échantillons représentatifs. Le contrat v3 est techniquement validé localement et par la CI PR #135, mais aucune mesure représentative n’est réputée verte ; la proposition reste non acceptée et ne clôt ni P0-005 ni P0-006.

## Gabarit d’une nouvelle décision

```markdown
## MLK-DEC-XXX — Titre

- Statut : proposée
- Date : YYYY-MM-DD
- Responsable :
- Validateurs :
- Contexte :
- Options considérées :
- Décision :
- Raisons :
- Contrats affectés :
- Risques :
- Rollback ou remplacement :
- Date de réévaluation :
- Preuves/liens :
```
