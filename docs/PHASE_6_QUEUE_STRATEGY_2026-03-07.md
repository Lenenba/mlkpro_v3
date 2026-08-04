# Phase 6 Queue Strategy

> **Document historique — hors numérotation canonique.** Le titre « Phase 6 » provient de l'ancien découpage technique des files. Dans le programme d'audit actuel, seules les phases **0 à 4** sont canoniques ; ce document reste un runbook technique de référence et ne crée pas une phase 6 supplémentaire dans le suivi de l'audit.

## Objectif

Garder les requêtes HTTP centrées sur les écritures métier critiques et déplacer les effets secondaires asynchrones vers des files explicites, consommées et observables.

La source de vérité est désormais [config/async.php](../config/async.php). Les jobs et notifications résolvent leurs files et leurs délais de reprise par workload ; les profils de workers sont construits à partir de la même configuration afin d’éviter la dérive entre producteurs et consommateurs.

## Workloads canoniques

| Workload | File par défaut | Usage principal |
|---|---|---|
| `notifications` | `notifications` | Notifications et courriels asynchrones |
| `leads` | `leads` | Relances et traitements de prospects |
| `works` | `works` | Génération et traitements des travaux |
| `demos` | `demo-provisioning` | Provisionnement des espaces de démonstration |
| `plan_scans` | `plan-scans` | Extraction et analyse asynchrones des plans |
| `campaigns_dispatch` | `campaigns-dispatch` | Préparation et répartition des campagnes |
| `campaigns_send` | `campaigns-send` | Envoi aux destinataires de campagnes |
| `campaigns_maintenance` | `campaigns-maintenance` | Rapports, scores et maintenance des campagnes |
| `social_automation` | `social-automation` | Génération automatisée de contenu social |
| `social_publish` | `social-publish` | Publication vers les cibles sociales |

Chaque nom peut être surchargé par la variable `ASYNC_QUEUE_*` correspondante. Les anciennes variables `CAMPAIGNS_QUEUE_*` restent des alias de compatibilité, mais les nouvelles installations doivent utiliser `ASYNC_QUEUE_CAMPAIGNS_*`.

La file Laravel `default` reste consommée par les profils `development` et `operations`. Elle couvre les jobs sans workload explicite et permet de vider les anciens jobs `plan_scans` déjà déposés sur `default` pendant le déploiement de la file dédiée.

## Profils de workers dynamiques

Les profils ne recopient pas les noms physiques des files dans les scripts de déploiement. La commande `queue:workloads` les résout depuis `config/async.php`, y compris les surcharges d’environnement.

| Profil | Environnement | Files consommées |
|---|---|---|
| `development` | Développement local | `default` et les dix workloads |
| `operations` | Production | `default`, `notifications`, `leads`, `works`, `demos` |
| `plan-scans` | Production | `plan_scans` uniquement |
| `campaigns` | Production | Les trois workloads `campaigns_*` |
| `social` | Production | `social_automation` et `social_publish` |

Commandes recommandées :

```bash
# Développement : utilisé par le script Composer local
php artisan queue:workloads development --listen

# Processus persistants de production
php artisan queue:workloads operations
php artisan queue:workloads plan-scans
php artisan queue:workloads campaigns
php artisan queue:workloads social
```

En production, le gestionnaire de processus doit lancer et surveiller séparément les quatre profils de production. Le profil `development` ne doit pas remplacer ces processus persistants.

L’ordre résolu est un ordre de priorité Laravel. Les files explicites passent avant `default`; `campaigns-send` passe avant le dispatch de nouveaux lots et `social-publish` avant la génération de nouveaux contenus. Cette priorité réduit l’affamement des effets déjà promis aux utilisateurs.

Avant de démarrer un processus, sa résolution peut être inspectée sans consommer de job :

```bash
php artisan queue:workloads operations --dry-run --json
php artisan queue:workloads plan-scans --dry-run --json
php artisan queue:workloads campaigns --dry-run --json
php artisan queue:workloads social --dry-run --json
```

## Délais, retries et visibilité

Les backoffs sont centralisés dans `config/async.php` et résolus par [QueueWorkload.php](../app/Support/QueueWorkload.php). Le nombre d’essais propre à chaque job reste prioritaire. Pour les jobs sans politique explicite, notamment le wrapper Laravel des notifications, les profils `development` et `operations` utilisent trois tentatives au lieu d’annuler leurs backoffs avec une seule tentative. L’option `--tries` reste un fallback opérateur, pas un remplacement d’une propriété `$tries` portée par un job.

`plan_scans` possède un timeout de 240 secondes et une reprise après 60 secondes. Sa file dédiée évite qu’une analyse longue bloque les notifications et les travaux interactifs. Les échecs techniques doivent être relancés après persistance d’un état utile ; le hook terminal `failed` porte le scan à l’état d’échec. Les indisponibilités attendues de l’extracteur IA qui produisent un résultat métier de repli ne sont pas transformées en échecs techniques.

Les erreurs fournisseur ou de génération sociale déjà converties en état métier `failed` ne sont pas relancées automatiquement, afin d’éviter une publication externe en double. Les tentatives des jobs sociaux couvrent seulement les erreurs d’infrastructure ou de persistance qui échappent à ces handlers.

Les 240 secondes constituent le maximum **configuré** actuellement, pas une preuve de durée maximale de tous les workloads. Les canaris doivent encore mesurer les démos et les traitements campagnes en staging/production représentatif ; tout dépassement impose un timeout explicite et un `retry_after` supérieur avant élargissement du trafic.

Pour éviter qu’un même job soit repris par un second worker avant la fin du premier :

- `DB_QUEUE_RETRY_AFTER=300` pour la connexion base de données ;
- `REDIS_QUEUE_RETRY_AFTER=300` pour Redis ;
- `retry_after=300` pour Beanstalkd si cette connexion est utilisée ;
- pour SQS, un visibility timeout strictement supérieur au timeout maximal du worker, avec une marge opérationnelle équivalente.

La règle à conserver est `retry_after` (ou visibility timeout) strictement supérieur au plus grand timeout du profil. Pour `plan-scans`, `300 > 240` laisse une marge de 60 secondes.

## Audit et visibilité

L’inventaire statique workloads ↔ files ↔ workers est vérifié par :

```bash
php artisan queue:workload-audit
php artisan queue:workload-audit --json
```

La commande doit échouer si un workload est absent, si un workload de production n’est consommé par aucun profil, si un profil référence un workload inconnu, si deux profils de production consomment accidentellement la même file, si la connexion active ne peut pas exécuter de worker persistant ou si le délai de reprise d’une connexion est inférieur ou égal au timeout maximal des workers. Pour SQS, le JSON expose un contrôle externe restant : le visibility timeout n’est pas vérifiable depuis ce dépôt.

La santé opérationnelle reste exposée par :

```bash
php artisan queue:health
php artisan queue:health --json
```

Elle inclut la connexion, le volume en attente, le détail par file, l’âge du plus ancien job et les échecs récents. L’audit de topologie et la santé d’exécution sont complémentaires : un audit vert ne prouve pas que les processus de production tournent réellement.

### Harnais de consommation sans effet métier

`queue:workload-canary` dépose un job dédié sans effet métier sur **chaque** file résolue d’un profil de production, puis attend un accusé pour chacune d’elles :

```bash
php artisan queue:workload-canary operations --dry-run --json
php artisan queue:workload-canary operations --json
php artisan queue:workload-canary plan-scans --json
php artisan queue:workload-canary campaigns --json
php artisan queue:workload-canary social --json
```

La connexion peut être passée comme second argument et l’attente peut être bornée avec `--timeout=<secondes>`. Chaque exécution crée un identifiant de run et un identifiant distinct par file. La sortie JSON ne contient ni clé de cache, ni nom d’hôte, ni payload métier, ni exception brute. Dans un run opérationnel, le code de sortie est `0` uniquement lorsque toutes les files du profil ont produit un accusé exact avant le timeout.

Les accusés expirent automatiquement et utilisent le store défini par `ASYNC_QUEUE_CANARY_STORE`, avec le préfixe `ASYNC_QUEUE_CANARY_PREFIX`, la durée `ASYNC_QUEUE_CANARY_TTL_SECONDS` et le timeout par défaut `ASYNC_QUEUE_CANARY_TIMEOUT_SECONDS`. Le harnais accepte Redis, DynamoDB ou un cache `database` adossé à MySQL/MariaDB, PostgreSQL ou SQL Server. Il refuse les stores locaux ou éphémères, notamment `array`, `file`, `null`, `octane`, Memcached et une base SQLite.

Une preuve opérationnelle exige en plus :

- `APP_ENV=staging` ou `APP_ENV=production` ; tout autre environnement est refusé en mode réel ;
- `ASYNC_QUEUE_CANARY_RELEASE` non vide, limité à 128 caractères et à l'alphabet sûr `A-Z`, `a-z`, `0-9`, `.`, `_`, `:`, `+`, `-` ;
- `ASYNC_QUEUE_CANARY_COMMIT` contenant un SHA-1 ou SHA-256 hexadécimal complet, donc exactement 40 ou 64 caractères ;
- si `CAPACITY_BASELINE_COMMIT` est défini, sa valeur doit être identique à `ASYNC_QUEUE_CANARY_COMMIT` ; si `OBSERVABILITY_RELEASE` est défini, sa valeur doit être identique à `ASYNC_QUEUE_CANARY_RELEASE` ; en leur absence, les deux variables canari explicites restent la source d'identité ;
- une connexion de file dont le driver appartient à la liste fermée `database`, `redis`, `sqs` ou `beanstalkd`. Le préflight résout réellement la connexion et exige respectivement un objet Laravel `DatabaseQueue`, `RedisQueue`, `SqsQueue` ou `BeanstalkdQueue` — les sous-classes restent acceptées. Les drivers `sync`, `null`, les connecteurs inconnus et un connecteur détourné qui annonce `database` mais retourne `SyncQueue` sont refusés. Pour `database`, SQLite est refusé hors du harnais interne de tests car il ne constitue pas une file partagée entre hôtes.

Le job exige l'enveloppe fournie par un vrai worker, compare la connexion et la file observées par cette enveloppe aux cibles attendues, puis écrit dans l'accusé les valeurs **observées**. Les URL physiques SQS sont normalisées à partir du transport Laravel résolu. Il compare aussi l'environnement, la release et le commit configurés côté worker à ceux du lanceur et lie ces valeurs à l'accusé. Ces contrôles détectent une dérive de configuration ou de routage ; l'identité reste cependant une identité de build/release **configurée**, pas une attestation cryptographique du binaire effectivement exécuté.

La sortie et chaque accusé portent un mode fermé : `operational` ou `internal_test`, ainsi qu'un booléen `evidence_eligible`. Le harnais PHPUnit interne retourne explicitement `passed_internal_test` et `evidence_eligible=false` ; il ne peut jamais être confondu avec un succès d'exploitation. Le mode `--dry-run` valide le store, le transport résolu et la topologie sans écrire dans le store et sans déposer de job ; le mode réel effectue d’abord une sonde lecture/écriture expurgée. En local, le dry-run peut retourner le code `0` avec le statut `ready_with_requirements` et lister les identités manquantes. **Ni `ready_with_requirements` ni `passed_internal_test` ne sont une preuve de consommation recevable** : seule une sortie réelle combinant `status=passed`, `mode=operational` et `evidence_eligible=true`, dans `staging` ou `production`, avec ses accusés liés à la release/au commit, constitue la preuve P0-005.

Une sortie recevable (`passed`, `operational`, `evidence_eligible=true`) établit qu’un consommateur a traité chaque file pendant la fenêtre du run. Elle n’établit ni le nom du processus dans Supervisor/systemd, ni son redémarrage automatique, ni sa disponibilité durable. Le contrôle du gestionnaire de processus, `queue:health --json`, les canaris métier représentatifs et le rollback restent donc complémentaires.

## Procédure de déploiement

1. Définir toutes les variables `ASYNC_QUEUE_*`, dont obligatoirement `ASYNC_QUEUE_CANARY_RELEASE` et `ASYNC_QUEUE_CANARY_COMMIT`, ainsi que `DB_QUEUE_RETRY_AFTER=300` et/ou `REDIS_QUEUE_RETRY_AFTER=300` dans l’environnement `staging` ou `production` ciblé, sans encore basculer les producteurs web.
2. Préprovisionner les quatre processus persistants `operations`, `plan-scans`, `campaigns` et `social` sur une release compatible avec `queue:workloads`, ou placer le trafic en maintenance pendant la bascule.
3. Déployer la release, recharger la configuration Laravel, puis exécuter `php artisan queue:workload-audit --json`. Arrêter le déploiement si le code de sortie est non nul ou si un contrôle externe requis reste non confirmé.
4. Exécuter les quatre commandes `queue:workloads ... --dry-run --json` et confirmer les files, la connexion, l’ordre, les tentatives et les timeouts résolus.
5. Démarrer et vérifier les nouveaux consommateurs, puis exécuter `queue:workload-canary <profil> --json` pour chacun des quatre profils avant de rouvrir le trafic ou d’autoriser les producteurs à déposer des jobs sur `plan-scans` et `social-publish`.
6. Conserver le consommateur de `default` pendant toute la transition afin de vider les jobs créés par l’ancienne version.
7. Exécuter `php artisan queue:restart`, vérifier que chaque processus redémarre, puis contrôler `queue:health --json`, les journaux et `failed_jobs`.
8. Procéder au canari décrit ci-dessous avant l’élargissement du trafic.

La présente documentation décrit la cible. Elle ne constitue pas une preuve que Supervisor, Horizon ou un autre gestionnaire de processus de production a été configuré ou vérifié.

## Canari contrôlé

Le canari est exécuté d’abord en staging représentatif, puis dans un périmètre de production explicitement autorisé :

1. prendre un instantané `queue:health --json` et confirmer que les quatre profils sont actifs dans le gestionnaire de processus ;
2. déclencher un job représentatif et sans impact externe non maîtrisé pour chaque profil ;
3. pour `plan-scans`, utiliser un petit fichier de test non client et confirmer la transition jusqu’au statut attendu ;
4. pour `social-publish`, utiliser un compte/cible de test ou une publication privée contrôlée, jamais un canal public réel sans approbation ;
5. confirmer que les jobs apparaissent sur les files attendues, sont consommés, ne restent pas en attente et ne créent pas d’échec terminal inattendu ;
6. comparer le nouvel instantané de santé, les journaux applicatifs et `failed_jobs`, puis consigner l’heure, l’environnement et les identifiants non secrets dans le journal de validation.

Aucun canari de production ni aucune supervision réelle des workers n’est déclaré validé tant que cette procédure n’a pas été exécutée et consignée par l’exploitation.

## Rollback

1. Suspendre l’élargissement du trafic et identifier les files qui contiennent encore des jobs.
2. Conserver `DB_QUEUE_RETRY_AFTER=300`, `REDIS_QUEUE_RETRY_AFTER=300` et/ou `BEANSTALKD_QUEUE_RETRY_AFTER=300` tant qu’un job sérialisé avec timeout 240 secondes peut encore être exécuté.
3. Maintenir une release de drainage qui contient `queue:workloads`; si elle n’est plus disponible, lancer temporairement des commandes brutes compatibles, par exemple `queue:work --queue=plan-scans --timeout=240 --tries=1` et `queue:work --queue=social-publish --timeout=60 --tries=1`.
4. Restaurer la version précédente de l’application et ses variables de routage par un revert de déploiement ; ne pas utiliser `git reset --hard`.
5. Réactiver l’ancien routage de `plan_scans` vers `default` si nécessaire, sans retirer immédiatement les consommateurs `plan-scans` ou `social-publish`.
6. Maintenir simultanément les anciens et nouveaux consommateurs jusqu’à ce que les files créées avant le rollback soient vidées ou déplacées selon une procédure contrôlée. Ne jamais abandonner silencieusement des jobs dans une file renommée.
7. Exécuter `php artisan queue:restart`, contrôler le redémarrage des processus, puis vérifier `queue:health --json`, `failed_jobs` et les statuts métier.
8. Réessayer ou annuler manuellement les seuls jobs identifiés après validation du risque d’idempotence et d’effets externes.
9. Retirer seulement après drainage les consommateurs temporaires et les valeurs 300, puis consigner la cause, le périmètre, les jobs restants et la décision de reprise.

## Notes

- Cette phase ne change pas les contrats visibles par les utilisateurs.
- La séparation des profils empêche les campagnes, analyses longues et publications sociales d’affamer les files opérationnelles.
- Les seuils d’alerte, l’autoscaling et les SLO de backlog restent des travaux d’exploitation ultérieurs.
