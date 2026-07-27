# Phase 6 Queue Strategy

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

## Procédure de déploiement

1. Définir toutes les variables `ASYNC_QUEUE_*`, `DB_QUEUE_RETRY_AFTER=300` et/ou `REDIS_QUEUE_RETRY_AFTER=300` dans l’environnement ciblé, sans encore basculer les producteurs web.
2. Préprovisionner les quatre processus persistants `operations`, `plan-scans`, `campaigns` et `social` sur une release compatible avec `queue:workloads`, ou placer le trafic en maintenance pendant la bascule.
3. Déployer la release, recharger la configuration Laravel, puis exécuter `php artisan queue:workload-audit --json`. Arrêter le déploiement si le code de sortie est non nul ou si un contrôle externe requis reste non confirmé.
4. Exécuter les quatre commandes `queue:workloads ... --dry-run --json` et confirmer les files, la connexion, l’ordre, les tentatives et les timeouts résolus.
5. Démarrer et vérifier les nouveaux consommateurs avant de rouvrir le trafic ou d’autoriser les producteurs à déposer des jobs sur `plan-scans` et `social-publish`.
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
