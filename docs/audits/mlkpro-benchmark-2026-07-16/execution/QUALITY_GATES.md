# Protocole obligatoire de tests et de non-régression

Dernière mise à jour : 2026-08-04
Statut : **applicable à tous les tickets du programme**

## Règle de décision

Chaque changement est un lot atomique. Il doit posséder une preuve **avant**, une preuve **après** et un rollback.

Un ticket reste ouvert si l’un des éléments suivants est vrai :

- le comportement modifié ne possède pas de test ciblé ;
- un test ciblé ou une gate applicable échoue ;
- un workflow protégé produit un résultat différent sans décision approuvée ;
- la mesure après changement n’utilise pas la même méthode que la baseline ;
- le rollback n’est plus possible ou n’est pas documenté ;
- une preuve contient un secret ou une donnée client directe.

## Cycle obligatoire par lot

1. Identifier le comportement, les contrats et les workflows affectés.
2. Exécuter le test ciblé avant changement et enregistrer son résultat.
3. Ajouter d’abord un test de caractérisation si le comportement n’est pas couvert.
4. Appliquer un seul changement cohérent.
5. Relancer le test ciblé.
6. Exécuter la non-régression du module.
7. Exécuter les gates transversales applicables.
8. Comparer avant/après avec la même méthode.
9. Effectuer la vérification humaine ou le canari si le changement touche un workflow externe.
10. Enregistrer les preuves et le verdict dans `VALIDATION_LOG.md`.

Une correction de bug peut commencer par un test rouge qui reproduit le défaut. Dans ce cas, la baseline attendue est l’échec précis de ce test, pas un échec général de la suite.

## Gates communes

Pour tout changement PHP ou de configuration Laravel :

```powershell
composer qa:format
php vendor/bin/phpstan analyse --memory-limit=1G --no-progress
php -d memory_limit=512M vendor/bin/pest tests/chemin/TestCible.php --compact
php -d memory_limit=512M vendor/bin/pest --compact
git diff --check
```

`composer qa:format` est obligatoire après le dernier ajout, la dernière modification ou la dernière suppression PHP et après la dernière indexation (`git add`), puis juste avant le push ou la livraison. Le contrôle doit refuser les fichiers PHP partiellement indexés et les suppressions PHP non indexées. Le lot ne peut pas être déclaré prêt tant que `laravel-quality` n’est pas vert sur le commit exact ; une indisponibilité locale de PHP, Composer ou Pint doit être consignée comme un blocage, jamais comme une validation.

Pour tout changement frontend ou de dépendance JavaScript :

```powershell
npm run qa:build
npx playwright test tests/e2e/scenario-concerne.spec.js
npm run qa:e2e
git diff --check
```

Le build seul ne valide pas un parcours utilisateur. Le test navigateur ciblé est requis lorsqu’un écran, une navigation, une modale, un formulaire ou un composant interactif change.

## Matrice par type de changement

| Type | Test ciblé minimal | Non-régression | Contrôle complémentaire |
|---|---|---|---|
| Secret ou fournisseur externe | Client HTTP faké, configuration absente, succès et rejet | Module consommateur puis Pest complet | Canari contrôlé avant/après, journaux expurgés |
| Dépendance PHP | Tests des domaines utilisant le paquet | PHPStan, Pest complet, build | `composer audit`, installation depuis le lock, MySQL si données |
| Dépendance JavaScript | Build et scénario Playwright concerné | Playwright complet | `npm audit`, `npm ci`, comparaison des bundles |
| Queue ou job | Routage, payload, backoff, retry et échec terminal | Tests async et Pest complet | `queue:health`, worker prêt, job canari |
| Requête, modèle ou migration | Résultat métier et isolation tenant | SQLite complet et MySQL ciblé/complet | comparaison SQL, volume, rollback migration |
| API, route ou props Inertia | Schéma, statut, permissions et données | Tests Feature du module et Pest complet | OpenAPI/contrat consommateur si applicable |
| Interface ou design | Composant et scénario utilisateur | Build et Playwright complet | mobile, accessibilité, captures avant/après |
| Performance interne | Égalité fonctionnelle sur mêmes entrées | Suite du domaine et globale | p50/p95/p99, requêtes, mémoire, charge comparable |
| Documentation analytique | liens, formats structurés et secret scan | validation de l’artefact rendu | cohérence entre sources et rapport |

## Gate spécifique — queues et workers P0-005

La topologie, les retries et le harnais peuvent être validés localement, mais une preuve locale ne démontre pas que les processus persistants tournent dans l’environnement cible. La sortie P0-005 exige, pour chacun des profils `operations`, `plan-scans`, `campaigns` et `social` :

1. un processus déclaré, actif, supervisé et redémarré avec succès ;
2. `queue:workload-audit --json` et `queue:workloads <profil> --dry-run --json` conformes ;
3. `queue:workload-canary <profil> --json` avec `status=passed`, `mode=operational` et `evidence_eligible=true` ;
4. des accusés liés à la connexion/file réellement observée ainsi qu’à l’environnement, la release et le commit attendus ;
5. `queue:health --json`, un canari métier représentatif et le rollback vérifiés.

`passed_internal_test`, `ready_with_requirements` et le dry-run ne sont jamais des preuves d’exploitation. Le harnais local du commit `6af521e` est une preuve technique, pas une clôture P0-005.

## Gate spécifique — baseline d’observabilité P0-006

La préparation technique ne vaut pas validation de la baseline. L’instrumentation est opt-in et inactive par défaut. Avant toute charge, les trois conditions d’activation suivantes sont obligatoires :

- `OBSERVABILITY_ENABLED=true` uniquement pendant la campagne approuvée ;
- `OBSERVABILITY_CACHE_STORE=redis`, le driver effectif devant être exactement Redis pour corréler les processus avec une faible surcharge ;
- `OBSERVABILITY_RELEASE` non vide et propre au commit/release déployé.

`php artisan capacity:plan --json` constitue le préflight exécutable. Son statut doit être `ready_for_approved_harness` et ses listes d’erreurs doivent être vides. La gate refuse notamment :

- un contexte baseline incomplet ou incohérent ;
- l’observabilité désactivée ou une release absente ;
- tout driver effectif autre que Redis, ou un cache non partagé, à surcharge élevée, illisible, non inscriptible ou ayant perdu de la télémétrie ;
- une santé de queue partiellement non mesurable, contenant des erreurs de mesure ou dépassant déjà un seuil de backlog, d’âge du plus ancien job ou d’échecs sur 24 h ;
- un environnement `staging` absent de `CAPACITY_ALLOWED_STAGING_ENVIRONMENTS`, ou un scénario `controlled_write` qui n’exige pas un tenant isolé ;
- un scénario, une route, un profil, un statut HTTP, un fixture ou une stratégie de résultat métier invalide, ainsi qu’un protocole autorisant le suivi automatique des redirections.

Le contexte complet exige : run ID, environnement correspondant à l’application, commit, début et fin avec offset UTC explicite, trafic, runner et `CAPACITY_BASELINE_RUNNER_HASH`, fixture privée et `CAPACITY_BASELINE_FIXTURE_HASH`, origines HTTPS exactes dans `CAPACITY_BASELINE_ALLOWED_ORIGINS`, exclusions, mode `staging` ou `production_read_only`, représentativité explicite, approbation explicite avec référence, canaris P0-005 vérifiés, propriétaire et validateur distincts. Si un scénario d’écriture non bloqué est prévu, `CAPACITY_BASELINE_ISOLATED_TENANT_VERIFIED=true` atteste l’isolation. Les connexions effectives de cache, base et queue sont consignées dans le contexte runtime.

Chaque scénario suit sans réordonnancement :

1. `capacity:scenario:start <scenario>` et snapshot queue initial, seulement si la fenêtre restante couvre le profil plus `CAPACITY_SCENARIO_START_BUFFER_SECONDS` et si cette clé n’a pas déjà été exécutée dans le run ;
2. harness HTTP externe approuvé exécuté avec le manifeste exact et les redirections automatiques désactivées ;
3. `capacity:scenario:stop <scenario>` et snapshot queue final ;
4. import du résultat agrégé, limité à 64 Kio et nommé sans segment de chemin, depuis le dossier contrôlé `storage/app/capacity-imports` avec `capacity:result:import <scenario> <fichier.json> --json` ;
5. `capacity:report --json --strict` après l’ensemble des scénarios.

Le mode `--strict` retourne le code `0` pour `healthy` et `accepted_with_blockers`. Un rapport `accepted_with_blockers` contient au moins un scénario non exécuté couvert par un blocage formel valide ; il ne prouve pas que les sept scénarios ont été exécutés. Une gate automatisée doit donc lire et conserver le statut JSON, distinguer ces deux résultats et ne réserver la preuve d’exécution complète qu’à `healthy`.

Le scheduler `queue:health --record --json` ajoute un snapshot selon une cadence nominale de 60 s lorsque l’observabilité est active. La série attribuée au bon run/scénario doit couvrir tout l’intervalle du runner : première et dernière captures à au plus 30 s des extrémités, aucun écart supérieur à 120 s et nombre de captures conforme à la cadence. Les seuls snapshots de début et de fin ne suffisent pas ; backlog, âge du plus ancien job prêt et échecs récents doivent rester mesurables.

Le résultat du harness utilise le schéma fermé `schema_version: 3`. `manifest_hash` lie le scénario et son blocage éventuel, `fixture_hash` les octets exacts de la fixture v3, `baseline_fingerprint` le contexte approuvé, `target_origin_hash` l’origine autorisée sans l’exposer et `runner_hash` le harness exact. La fixture v3 doit couvrir exactement les scénarios du plan, avec une requête `repeat` ou le budget intégral de variantes `one_shot` selon la stratégie signée ; tout `controlled_write` porte au moins un groupe métier `unique_by` et ses valeurs restent uniques. Le profil signé comprend notamment `request_interval_ms` et `request_timeout_ms`. L’import recalcule le préflight et refuse un plan devenu invalide, un manifeste/catalogue/blocage modifié, une identité ou une empreinte divergente, une origine non approuvée, un cycle `start` → `stop` absent/annulé ou des horodatages hors de ce cycle. Les erreurs de transport et d’assertion doivent être nulles ; les compteurs atteignent `profile.minimum_completed_requests` et restent cohérents avec la télémétrie interne.

Les seuils p95/p99 utilisent la **latence client de bout en bout** du harness externe. Le **temps de traitement applicatif** mesuré dans Laravel est conservé séparément pour expliquer un écart, mais ne peut pas remplacer la latence client, qui inclut réseau, proxy et runtime HTTP.

La collecte recevable respecte en plus toutes les conditions suivantes :

- staging isolé et représentatif, ou production en lecture seule approuvée explicitement ;
- six familles et sept scénarios exécutables : dashboard, détail client, création de réservation, création de vente, demande publique, consultation boutique et checkout boutique ;
- profil du manifeste respecté, `targets.min_samples` et `profile.minimum_completed_requests` atteints pour chaque scénario, ou blocage formel associé à une raison, un propriétaire et une date de réévaluation future ;
- résultats métier valides en plus des codes HTTP attendus ; une réponse techniquement acceptée mais métier en avertissement ne compte pas comme succès ;
- erreurs, requêtes lentes, taille de réponse, nombre de requêtes SQL et santé des queues disponibles selon une méthode reproductible ;
- même méthode, même environnement, même commit ou changement explicite, et charge comparable pour toute comparaison ultérieure ;
- seuls les agrégats expurgés sont versionnés. Aucun chemin ou paramètre brut, message d’exception, SQL, binding, identifiant, secret ou donnée client directe ne doit apparaître dans le dépôt ;
- les sorties brutes éventuelles restent dans un stockage contrôlé et le journal ne conserve qu’un lien non sensible ;
- la surcharge de collecte est surveillée et le rollback reste immédiatement applicable.

Le rollback consiste à remettre `OBSERVABILITY_ENABLED=false`, recharger la configuration puis redémarrer les processus PHP persistants ; il faut ensuite confirmer que la collecte HTTP et les snapshots planifiés ont cessé.

La gate finale P0-006 reste bloquée tant que l’environnement, la fenêtre, le propriétaire exploitation, le validateur distinct, les canaris P0-005 et les échantillons représentatifs ne sont pas consignés. L’état actuel est **en validation** : le runner/import v3, les gates locales et la CI PR #135 sont verts, mais la campagne manque. Aucun résultat de staging non exécuté ne doit être décrit comme vert.

## Données et MySQL

Tout changement de migration, SQL, pagination, agrégation, verrou ou transaction doit être testé sur SQLite et MySQL.

```powershell
composer qa:test:mysql -- tests/Feature/TestConcerne.php
composer qa:test:mysql
```

La commande `composer test:safe` n’est pas utilisée comme contrôle courant, car elle manipule la base configurée dans `.env`. La suite MySQL isolée est privilégiée.

## Twilio et autres canaris externes

- Les tests automatisés utilisent exclusivement `Http::fake` et des identifiants fictifs.
- Les variables fournisseur sont forcées à vide dans PHPUnit afin qu’un test ne puisse pas utiliser accidentellement le compte réel.
- Un canari réel exige un environnement et un destinataire contrôlés.
- Aucun numéro complet, jeton ou en-tête d’authentification n’est copié dans les preuves.
- Un succès HTTP ne suffit pas : le statut de livraison doit être vérifié côté fournisseur lorsqu’il est disponible.
- Après rotation, les processus persistants et workers sont redémarrés avant le canari final.

## Preuve minimale dans le journal

Chaque entrée de validation indique :

- commit et environnement ;
- commande ciblée avant et résultat ;
- changement exact ;
- commande ciblée après et résultat ;
- suites de non-régression et résultats ;
- mesure avant/après si performance ;
- vérification métier ou canari ;
- rollback ;
- risque restant ;
- verdict `validé`, `refusé` ou `bloqué`.

Les journaux complets restent dans la CI ou un stockage contrôlé. Le journal versionné ne conserve que des résultats synthétiques expurgés.
