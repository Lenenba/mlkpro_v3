# Phase 2 — Performance données et runtime

- Dernière mise à jour : 2026-08-04
- Statut : **en attente**
- Responsable : à nommer
- Validateurs : à nommer
- Dépendance : Phase 1 terminée
- Risque de phase : moyen à élevé
- Vue maître et état courant : [suivi global des Phases 0 à 4](SUIVI_GLOBAL.md)

## Objectif

Rendre les temps de réponse et l’usage mémoire stables lorsque le nombre de clients, demandes, devis, prospects, activités et jobs augmente.

## Principe de sécurité

Les Query Objects, DTO et props existants restent les frontières. Chaque nouvelle requête fonctionne d’abord en mode ombre et son résultat est comparé à l’ancienne implémentation avant activation.

## Pré-sélection factuelle — sans ouverture de Phase 2

La décision [MLK-DEC-011](DECISIONS.md) est **proposée**, non acceptée : elle ne démarre aucun ticket P2, ne modifie ni code ni infrastructure et ne lève aucune gate. L’ordre recommandé s’appuie sur l’analyse statique des collections non bornées et doit être validé avant tout GO P2 ; [MLK-DEC-005](DECISIONS.md) doit aussi être acceptée, rejetée ou remplacée avant l’ouverture de la Phase 2.

| Rang proposé | Ticket | Indice statique | Garde-fou de démarrage |
|---:|---|---|---|
| 1 | P2-001 — Pagination SQL des demandes | `BuildRequestInboxIndexData` filtre, trie et pagine des collections chargées en mémoire ; contrat `RequestInboxPhaseOneTest` déjà présent | Résultats JSON/Inertia identiques, mode ombre, budget SQL/mémoire/p95 sur données représentatives |
| 2 | P2-002 — Pipeline CRM borné | `BuildSalesPipelineIndexData` charge et fusionne demandes/devis avant de rendre les cartes ; contrat `SalesPipelineQueryPhaseSixTest` présent | Double lecture, plafonds par colonne, « charger plus » sans rupture du kanban |
| 3 | P2-003 — Relance devis et dashboard prospects en SQL | `BuildQuoteRecoveryIndexData` et `BuildProspectDashboardData` chargent puis agrègent en PHP des collections de volume croissant | Égalité SQL/PHP, index MySQL vérifiés, requête historique pendant le canari |
| 4 | P2-004 — Cache et agrégats dashboard | Candidat suivant : `count`/`sum` multiples, mais cache et rôles doivent être mesurés froid/chaud avant priorisation | Comparaison par rôle, exactitude des montants et invalidation contrôlée |

Les quatre constats sont locaux et statiques. Ils ne remplacent pas P0-006, n’établissent pas de p95 ou de mémoire représentatifs et n’autorisent aucun test de charge, canari ni écriture production.

## Scope

### MLK-IMP-P2-001 — Pagination SQL de la boîte de demandes

- Statut : **en attente**
- Cible : `BuildRequestInboxIndexData`.
- Critères : mêmes filtres, tris, droits, totaux et props ; nombre de lignes chargées borné ; test de contrat JSON/Inertia.
- Rollback : bascule vers l’ancien Query Object par drapeau.

### MLK-IMP-P2-002 — Pipeline CRM borné

- Statut : **en attente**
- Cible : `BuildSalesPipelineIndexData`.
- Critères : compte par colonne séparé des cartes ; nombre plafonné de cartes ; « charger plus » sans rupture de kanban.
- Rollback : double lecture et bascule contrôlée.

### MLK-IMP-P2-003 — Relance devis et dashboard prospects en SQL

- Statut : **en attente**
- Cibles : `BuildQuoteRecoveryIndexData`, `BuildProspectDashboardData`.
- Critères : agrégats SQL identiques aux résultats PHP ; index vérifiés sur MySQL ; mémoire et p95 améliorés.
- Rollback : requête historique conservée pendant le canari.

### MLK-IMP-P2-004 — Dashboard : cache précoce et agrégats regroupés

- Statut : **en attente**
- But : consulter le cache avant les calculs secondaires et remplacer les séries de `count`/`sum` par des agrégats conditionnels.
- Critères : mêmes montants et permissions ; budget de requêtes ; invalidation testée ; première charge et charge chaude mesurées séparément.

### MLK-IMP-P2-005 — Props Inertia et polling allégés

- Statut : **en attente**
- But : DTO utilisateur minimal, props différées, cache permissions/features et endpoint notification incrémental.
- Critères : navigation, badges et planning identiques ; baisse des requêtes par navigation et par onglet ; multi-onglet testé.

### MLK-IMP-P2-006 — Redis : cache puis queue, sessions sur décision

- Statut : **en attente**
- Référence : [REDIS_PERFORMANCE_USER_STORY.md](../../../REDIS_PERFORMANCE_USER_STORY.md).
- Ordre : cache → queue → décision explicite sur sessions.
- Critères : préfixes par environnement, santé, reprise, mémoire/TTL, rollback de configuration, jobs canaris consommés.
- Non-objectif : utiliser Redis comme source de vérité métier.

### MLK-IMP-P2-007 — Observabilité atomique et budgets serveur

- Statut : **en attente**
- But : remplacer le read-modify-write de grands tableaux par compteurs/listes bornés Redis ou APM.
- Critères : aucune perte d’échantillons sous concurrence ; coût de mesure borné ; p95/p99, requêtes, erreurs et queues visibles.

## Cibles de succès

Les cibles finales sont fixées avec la baseline Phase 0. Les seuils déjà présents dans `config/capacity.php` servent de premier repère, pas de preuve de production.

À mesurer pour chaque ticket : p50/p95/p99, nombre de requêtes, temps SQL, mémoire, taille de réponse, erreurs et différence de résultat ancien/nouveau.

## Vérification minimale

```powershell
composer qa:format
php vendor/bin/phpstan analyse --memory-limit=1G
php -d memory_limit=512M vendor/bin/pest
composer qa:test:mysql
npm run qa:e2e
php artisan observability:report --json
php artisan capacity:report --json
php artisan queue:health --json
git diff --check
```

## Gate de sortie

- [ ] résultats ancien/nouveau comparés sur données représentatives ;
- [ ] tests MySQL et budgets de requêtes verts ;
- [ ] p95 et mémoire améliorés ou décision documentée ;
- [ ] cache froid et cache chaud mesurés séparément ;
- [ ] Redis possède santé, préfixes et rollback ;
- [ ] sessions non migrées sans décision explicite ;
- [ ] canari multi-entreprise terminé sans fuite de données.

## Definition of Done

La Phase 2 est terminée lorsque les écrans à volume sont bornés, les résultats restent identiques, l’infrastructure transitoire ne concurrence plus inutilement MySQL et les budgets serveur sont surveillés.

## Documents liés

- [Cockpit](README.md)
- [Runbook d’exécution et de validation Phase 2](PHASE_2_EXECUTION_RUNBOOK.md)
- [Phase 1](PHASE_1_QUICK_PERFORMANCE_WINS.md)
- [Journal de validation](VALIDATION_LOG.md)
