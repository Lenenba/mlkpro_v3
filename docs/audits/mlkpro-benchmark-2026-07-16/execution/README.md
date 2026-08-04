# Cockpit d’exécution contrôlée — amélioration MLK Pro

- Dernière mise à jour : 2026-08-04
- Statut global : **Phase 0 en cours — P0-001 à P0-004 terminés ; harnais P0-005/P0-006 techniquement terminés avec validations locales et CI PR #135 vertes ; staging, campagne représentative et signatures requis**
- Phase active autorisée : **Phase 0 uniquement : intégration PR #135, exploitation P0-005, campagne P0-006 puis décision signée P0-007**
- Politique Git : **travail et pull requests uniquement depuis/vers `develop` ; `main` est réservée au propriétaire humain du dépôt**
- Responsable d’exécution locale : Codex
- Responsable exploitation : à nommer
- Validateur produit : demandeur
- Document maître de suivi : **[Suivi global des Phases 0 à 4](SUIVI_GLOBAL.md)**

## Décision recommandée

Terminer la [Phase 0 — Sécurité et baseline](PHASE_0_SECURITY_AND_BASELINE.md) avant d’ouvrir la Phase 1. Le [suivi global](SUIVI_GLOBAL.md) récapitule ce qui est déjà terminé et détaille tout le travail prévu jusqu’à la Phase 4.

Cette phase traite, dans cet ordre :

1. la rotation des identifiants Twilio exposés ;
2. la photographie vérifiable de l’état actuel ;
3. les dépendances comportant des avis de sécurité ;
4. la cohérence des files et des workers ;
5. la collecte d’une baseline de production ou de staging représentatif.

Les optimisations visuelles et les changements d’architecture attendent la fermeture de cette phase. Cela évite de rendre le produit plus joli alors qu’un risque de sécurité, un job non consommé ou une mesure insuffisante subsiste.

## Tableau de contrôle

| Phase | Document | Statut | Dépendance | Gate de sortie |
|---|---|---|---|---|
| 0 | [Sécurité et baseline](PHASE_0_SECURITY_AND_BASELINE.md) | En cours — technique locale et CI P0-005/P0-006 vertes ; exploitation, campagne et signatures ouvertes | Aucune | Secrets remplacés, audits traités, queues alignées en exploitation, baseline exploitable et décision P0-007 signée |
| 1 | [Gains rapides de performance](PHASE_1_QUICK_PERFORMANCE_WINS.md) | En attente | Phase 0 terminée | Coûts globaux réduits sans régression de workflow |
| 2 | [Performance données et runtime](PHASE_2_DATA_AND_RUNTIME_PERFORMANCE.md) | En attente | Phase 1 terminée | SQL, cache, props et infrastructure validés sous charge |
| 3 | [Expérience utilisateur premium](PHASE_3_PREMIUM_USER_EXPERIENCE.md) | En attente | Phases 1 et 2 terminées | Parcours plus rapides et plus clairs, validés par rôle |
| 4 | [Différenciation produit](PHASE_4_PRODUCT_DIFFERENTIATION.md) | En attente | Phase 3 terminée | Avantages opérations-finance validés avec des pilotes |

Le détail ticket par ticket, les acquis, les blocages et les prochaines actions de ces cinq phases sont centralisés dans [SUIVI_GLOBAL.md](SUIVI_GLOBAL.md).

## Règle de fonctionnement

Le séquencement ci-dessous correspond à la politique proposée dans `MLK-DEC-002`, appliquée prudemment par le cockpit. Son acceptation formelle reste à consigner dans [DECISIONS.md](DECISIONS.md) ; sans cette décision et sans gate signée, aucune phase future n’est considérée ouverte.

Une seule phase peut donc avoir le statut **en cours** dans le suivi actuel.

Une phase suit obligatoirement ce cycle :

```text
En attente → À valider → Validée → En cours → En validation → Terminée
                                           ↘ Bloquée
```

Le passage à l’état suivant exige une entrée dans [VALIDATION_LOG.md](VALIDATION_LOG.md). Toute décision qui modifie le scope, l’ordre, une cible ou un contrat protégé est enregistrée dans [DECISIONS.md](DECISIONS.md).

Le [protocole obligatoire de tests et de non-régression](QUALITY_GATES.md) s’applique à chaque ticket. Un ticket ne peut pas être déclaré terminé si un test ciblé, une gate applicable ou une vérification métier échoue.

## Contrats à protéger pendant tout le programme

- routes, noms de routes et middleware ;
- schémas API et intégrations externes ;
- props Inertia, DTO et événements déjà consommés ;
- permissions, isolation par entreprise et règles d’abonnement ;
- calculs de taxes, écritures comptables et piste d’audit ;
- workflows devis → travail → facture → paiement ;
- réservations, campagnes, notifications et portail client ;
- compatibilité français/anglais/espagnol ;
- restauration et retour arrière par lot.

Un contrat ne peut changer que par une décision explicite validée. Une optimisation interne doit produire les mêmes résultats fonctionnels avant d’être activée.

## Contrôle minimal de chaque ticket

Chaque ticket doit contenir :

- un propriétaire et un réviseur différents ;
- un scope et un hors-scope ;
- une baseline avant modification ;
- des critères d’acceptation observables ;
- les tests ciblés et de non-régression ;
- une stratégie de déploiement progressif ;
- une procédure de rollback testable ;
- des preuves sans secret dans le journal de validation.

## Gates obligatoires

Avant de commencer un ticket :

- [ ] responsable et validateur nommés ;
- [ ] dépendances du ticket terminées ;
- [ ] baseline enregistrée ;
- [ ] workflows et contrats protégés identifiés ;
- [ ] rollback documenté ;
- [ ] données ou accès nécessaires disponibles.

Avant de terminer un ticket :

- [ ] critères d’acceptation satisfaits ;
- [ ] tests ciblés verts ;
- [ ] suite de non-régression proportionnée au risque verte ;
- [ ] mesures avant/après consignées ;
- [ ] aucun secret dans les logs ou captures ;
- [ ] validation humaine consignée ;
- [ ] rollback encore applicable ou remplacé par une procédure validée.

## Précision importante sur les files

P0-005 retient une topologie centralisée dans `config/async.php` :

- les dix workloads sont déclarés, dont `plan_scans` vers `plan-scans` et `social_publish` vers `social-publish` ;
- les profils `development`, `operations`, `plan-scans`, `campaigns` et `social` résolvent dynamiquement leurs files avec `queue:workloads` ;
- `plan-scans` est isolé avec un timeout de 240 secondes et un `retry_after` de 300 secondes ;
- `default` reste consommée localement et par `operations` pour les jobs implicites et le drainage des anciens scans ;
- les files explicites sont prioritaires sur `default`, avec `campaigns-send` avant dispatch et `social-publish` avant automation ;
- `queue:workload-audit` contrôle la correspondance workloads/files/workers, les connexions persistantes, les collisions et la cohérence timeout/visibilité ;
- les exceptions techniques de `AnalyzePlanScanJob` doivent être relancées pour activer le retry, tandis que `failed` matérialise l’échec terminal.

La preuve historique de la PR #133 compte 1 179 tests/12 236 assertions et une CI verte sous PHP 8.4, MySQL 8.4 et Chromium. Le lot courant `6af521e` ajoute `queue:workload-canary` et 33 tests/457 assertions ciblés ; le rejeu global atteint 1 284 tests/13 180 assertions. La commande exige, pour chaque file, un accusé produit par un vrai worker et lié à la connexion, la file, l’environnement, la release et le commit. La procédure complète est décrite dans [Phase 6 Queue Strategy](../../../PHASE_6_QUEUE_STRATEGY_2026-03-07.md). Les modes `internal_test` et `dry-run` sont explicitement inéligibles comme preuves : le gestionnaire de processus, quatre sorties opérationnelles, la santé, les canaris métier et le rollback restent à vérifier en staging/production.

## Préparation de la baseline d’observabilité P0-006

P0-006 couvre six familles métier et sept scénarios exécutables : dashboard, détail client, création de réservation, création de vente, demande publique, consultation de la boutique publique et checkout de la boutique publique.

L’instrumentation est volontairement inactive par défaut : `OBSERVABILITY_ENABLED=false`. Une campagne recevable exige `OBSERVABILITY_ENABLED=true`, le driver Redis effectif via `OBSERVABILITY_CACHE_STORE=redis` et une identité de release non vide dans `OBSERVABILITY_RELEASE`. Le préflight refuse une collecte si l’observabilité est inactive, si la release manque, si le driver effectif n’est pas exactement Redis, si le cache ne répond pas en lecture/écriture, si des pertes de télémétrie sont détectées, si la santé des queues n’est pas mesurable ou si les seuils de backlog, d’âge du plus ancien job ou d’échecs sur 24 h sont déjà dépassés.

Le contexte de campagne doit être complet avant le plan : identifiant de run, environnement, commit, fenêtre UTC, trafic, runner externe approuvé et `CAPACITY_BASELINE_RUNNER_HASH`, fixture privée et `CAPACITY_BASELINE_FIXTURE_HASH`, allowlist d’origines HTTPS exactes dans `CAPACITY_BASELINE_ALLOWED_ORIGINS`, exclusions, mode `staging` ou `production_read_only`, représentativité, approbation et sa référence, canaris P0-005 vérifiés, propriétaire et validateur distincts. En mode `staging`, l’environnement doit appartenir à `CAPACITY_ALLOWED_STAGING_ENVIRONMENTS`. Tout scénario d’écriture non bloqué exige `CAPACITY_BASELINE_ISOLATED_TENANT_VERIFIED=true`. Le cache, la base et la connexion de queue effectifs sont ajoutés au contexte d’exécution.

L’ordre contrôlé d’un scénario est :

```text
capacity:plan
  → capacity:scenario:start
  → harness externe approuvé
  → capacity:scenario:stop
  → capacity:result:import
  → capacity:report
```

Avec `capacity:report --json --strict`, le code de sortie est `0` pour les statuts `healthy` **et** `accepted_with_blockers`. `accepted_with_blockers` signifie qu’au moins un scénario possède un blocage formel encore valide ; ce scénario n’a pas été exécuté ni validé par la campagne. Ce succès de commande ne prouve donc jamais que les sept scénarios ont été exécutés. Toute automatisation doit conserver le JSON et distinguer explicitement ces deux statuts.

Le démarrage et l’arrêt encadrent les snapshots de queue propres au scénario. Au démarrage, la fenêtre restante doit contenir toute la durée du profil plus `CAPACITY_SCENARIO_START_BUFFER_SECONDS` ; une clé de scénario ne peut être exécutée qu’une fois dans un même run. La tâche planifiée `queue:health --record --json` doit couvrir tout l’intervalle du runner avec une cadence nominale de 60 s, un écart maximal de 120 s et une grâce de 30 s aux deux extrémités. Le résultat fermé `schema_version: 3` lie séparément le scénario (`manifest_hash`), la fixture v2 (`fixture_hash`), le contexte approuvé (`baseline_fingerprint`), l’origine sans l’exposer (`target_origin_hash`) et le harness (`runner_hash`). Il signe aussi `request_interval_ms` et `request_timeout_ms`. Le runner Node 20 refuse les redirections, les origines non allowlistées, les méthodes/routes divergentes, les en-têtes dangereux, les fixtures altérées et tout plan ou préflight devenu invalide. Les p50/p95/p99/max mesurent la **latence client de bout en bout** ; le **temps de traitement applicatif** Laravel reste diagnostique.

Chaque scénario doit atteindre à la fois `targets.min_samples` et le plancher de charge `profile.minimum_completed_requests` défini dans `config/capacity.php`, ou être marqué bloqué avec raison, propriétaire et date de réévaluation. L’import refuse un résultat dont les requêtes tentées ou complétées restent sous cette enveloppe. Seuls des agrégats expurgés sont versionnés ; chemins, paramètres, messages d’exception, SQL, bindings, identifiants, secrets, données client et fichiers bruts du harness restent hors du dépôt.

Le rollback opérationnel consiste à positionner `OBSERVABILITY_ENABLED=false`, recharger la configuration puis redémarrer les processus persistants concernés. Le runner et l’import sont **techniquement validés localement et en CI**, mais aucune campagne représentative n’est consignée et les canaris d’exploitation P0-005 restent ouverts : **P0-006 ne peut pas être déclaré terminé**.

## Artefacts de référence

- [Rapport HTML](../report.html)
- [Artefact analytique canonique](../artifact.json)
- [Preuves de l’audit](../evidence.md)
- [Backlog priorisé](../priorities.csv)
- [Suivi global des Phases 0 à 4](SUIVI_GLOBAL.md)
- [Roadmap historique de synthèse](../roadmap.csv)
- [Registre des décisions](DECISIONS.md)
- [Journal des validations](VALIDATION_LOG.md)
- [Protocole de tests et de non-régression](QUALITY_GATES.md)
- [Guide d’exécution du runner P0-006](P0_006_RUNNER.md)
- [Exemple de fixture privée P0-006](capacity-runner-fixtures.example.json)
- [Gabarit agrégé du résultat runner P0-006](capacity-runner-result.example.json)

## Prochaine réunion de validation

Ordre du jour proposé :

1. intégrer la PR #135 dans `develop` ;
2. nommer le propriétaire exploitation et le validateur distinct ;
3. choisir le staging isolé et la fenêtre de déploiement P0-005 ;
4. exécuter les quatre canaris de profils, les canaris métier, le redémarrage et le rollback ;
5. accepter, rejeter ou remplacer `MLK-DEC-009`, puis approuver le trafic et l’expurgation P0-006 ;
6. exécuter et importer les sept scénarios, puis archiver le rapport strict ;
7. signer P0-007 comme GO ou NO-GO avant toute ouverture de la Phase 1.
