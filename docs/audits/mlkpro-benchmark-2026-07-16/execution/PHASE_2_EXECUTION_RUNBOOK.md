# Runbook d’exécution et de validation — Phase 2

- Dernière mise à jour : 2026-08-04
- Statut : **préparé, Phase 2 non ouverte**
- Responsable et validateur : à nommer avant le GO
- Référence d’état : [suivi global](SUIVI_GLOBAL.md)
- Référence de priorisation : [MLK-DEC-011](DECISIONS.md)

## Ce que ce document autorise et n’autorise pas

Ce document est le protocole complet à suivre lorsque la Phase 2 sera explicitement ouverte. Il ne constitue pas un GO Phase 2, ne démarre aucun ticket et ne modifie ni code, ni infrastructure, ni données.

Il n’autorise jamais, à lui seul :

- un test de charge ;
- un canari d’exploitation ;
- une action sur staging ;
- une écriture ou une modification de configuration en production ;
- une migration Redis ou une migration de sessions.

Les tests locaux contrôlés restent possibles seulement après un GO Phase 2 explicite pour le ticket concerné. Toute intervention hors local nécessite une nouvelle autorisation écrite, datée, bornée, avec environnement, fenêtre, responsable, rollback et critères d’arrêt.

## Contrats à protéger pendant toute la Phase 2

- Query Objects, DTO, noms et formes des props Inertia/JSON ;
- routes, middleware, traductions et comportements de navigation ;
- droits, isolation entreprise, filtres, tris, pages, compteurs et totaux ;
- taxes, montants, dates/fuseaux, audit et workflows métier ;
- MySQL comme source de vérité, y compris si un cache ou Redis est introduit ;
- données client, secrets, SQL brut et bindings, qui ne doivent jamais apparaître dans une preuve versionnée.

Chaque comparaison avant/après doit employer le même environnement, moteur MySQL, fixture expurgée, rôle, tenant, filtre, page, instant de référence et méthode. Un résultat qui ne respecte pas cette identité est une observation, pas une preuve de performance.

## 1. Gate d’entrée : arrêter ici tant que tout n’est pas vrai

Avant le premier ticket P2, cocher toutes les conditions suivantes dans une décision formelle. Une condition manquante bloque la Phase 2 ; elle ne peut pas être remplacée par une supposition.

| Condition | Preuve attendue | État |
|---|---|---|
| Phase 1 | P1-003, P1-004 et P1-005 acceptés et Phase 1 formellement clôturée |  |
| Dette P0 | P0-005/P0-006 prouvés sur staging, ou nouvelle exception GO P2 explicite, datée et bornée |  |
| Ordre P2 | MLK-DEC-011 acceptée, rejetée ou remplacée |  |
| Redis | MLK-DEC-005 acceptée, rejetée ou remplacée conformément à son échéance avant Phase 2 |  |
| Responsabilités | un responsable d’exécution et un validateur distinct nommés |  |
| Données | fixture synthétique locale non sensible pour les tests déterministes ; données staging représentatives requises pour une preuve dynamique, sauf exception GO P2 explicite ; aucune donnée client copiée dans le dépôt |  |
| Rollback | stratégie de drapeau, double lecture ou revert préparée pour le premier ticket |  |
| Autorisation externe | aucune action staging/production prévue sans mandat séparé |  |

Important : l’exception temporaire au validateur distinct de MLK-DEC-010 ne couvre que P0-006. Elle ne s’étend pas automatiquement à la Phase 2.

Lorsque toutes les lignes sont validées, créer dans VALIDATION_LOG.md et SUIVI_GLOBAL.md la gate GATE-P2-ENTRY-AAAA-MM-JJ : décisions, SHA de départ, environnement, scope/hors-scope, fixture, méthode de baseline, propriétaire du rollback et verdict GO/NO-GO. Tant qu’une seule ligne manque, inscrire « bloqué » et ne pas commencer de code P2.

### Texte de GO P2 à préparer

~~~text
Je suis responsable Produit, Technique et Exploitation.

Je confirme la clôture formelle de la Phase 1 au commit [SHA].
Je décide MLK-DEC-011 : acceptée / rejetée / remplacée par [référence].
Je décide MLK-DEC-005 : acceptée / rejetée / remplacée par [référence].

Concernant P0-005/P0-006 : [preuves staging réalisées / exception GO P2 décrite ici avec échéance].
J’autorise uniquement le démarrage local de P2-001 avec ses tests, ses comparaisons déterministes locales ancien/nouveau et son rollback. Un mode ombre sur trafic exige une autorisation staging distincte.

Je n’autorise aucun test de charge, aucun canari, aucune action staging ni écriture production sans autorisation distincte.
Responsable d’exécution : [nom].
Validateur distinct : [nom].
~~~

Ne passer à l’étape 2 qu’après la décision. La proposition MLK-DEC-011 actuelle recommande P2-001 → P2-002 → P2-003 ; elle reste une proposition tant qu’elle n’est pas acceptée.

## 2. Préparer le premier lot P2

1. Vérifier que develop est la branche courante et que le dépôt est propre.

   ~~~powershell
   git branch --show-current
   git status --short
   git log -1 --oneline
   ~~~

2. Créer un lot atomique à partir de develop. Un seul ticket P2 en cours à la fois, sauf décision explicite qui isole les contrats, responsables et rollbacks.

3. Dans [VALIDATION_LOG.md](VALIDATION_LOG.md), ouvrir une entrée au statut « en cours » avec :

   - ticket et SHA de départ ;
   - environnement local ;
   - responsable et validateur ;
   - contrat métier à préserver ;
   - fixture non sensible ;
   - méthode de mesure avant/après ;
   - mécanisme de rollback ;
   - autorisations reçues et limites.

4. Identifier les routes, props Inertia/JSON, Query Objects, DTO, permissions, filtres, tris, totaux, dates et tenants affectés.

5. Exécuter le test ciblé avant toute modification. Si le comportement n’est pas couvert, ajouter un test de caractérisation d’abord. Pour un écart de performance, le test ne doit pas devenir vert en supprimant une règle métier.

6. Préparer un échantillon déterministe couvrant au minimum :

   - deux entreprises isolées ;
   - utilisateur autorisé et non autorisé ;
   - état vide, petit volume et volume supérieur à une page ;
   - filtres combinés, tri ascendant/descendant et pagination ;
   - dates/heure et fuseau si une agrégation temporelle existe ;
   - montants nuls, remises, taxes ou statuts lorsque le ticket les lit.

7. Ne jamais placer les données brutes de l’échantillon dans Git si elles proviennent d’un client. Utiliser une fixture synthétique ou un hash/compte rendu expurgé.

## 3. Protocole commun, obligatoire pour chaque ticket P2

Suivre ces dix étapes dans l’ordre.

1. **Caractériser l’ancien comportement.** Capturer le résultat métier de l’ancienne requête sur les mêmes entrées : IDs rendus, ordre, compteurs, totaux, props et autorisations. Conserver seulement une comparaison expurgée.

2. **Mesurer avant.** Sur le même environnement et la même fixture, consigner le nombre de requêtes, temps SQL, mémoire, taille de réponse, durée applicative et, seulement si une campagne est autorisée, p50/p95/p99 client.

3. **Écrire ou renforcer les tests.** Couvrir contrats, autorisations tenant, données limites et égalité ancien/nouveau. Toute migration SQL doit aussi avoir un test MySQL.

4. **Conserver un rollback activable.** Prévoir un drapeau de bascule, une double lecture passive ou un revert isolé avant d’activer la nouvelle logique. Aucun changement irréversible de données n’est accepté dans un premier lot.

5. **Implémenter derrière la frontière existante.** Préserver Query Object, DTO, nom de prop et forme JSON/Inertia, sauf décision de contrat explicitement acceptée.

6. **Comparer sans modifier la réponse servie.** Localement, comparer les deux chemins dans un test déterministe. Un véritable mode ombre sur trafic existe seulement sur staging autorisé : l’ancien chemin sert la réponse, le candidat reçoit les mêmes entrées et seules les différences canoniques expurgées sont journalisées. Toute divergence bloque l’activation tant qu’elle n’est pas expliquée et approuvée.

7. **Valider localement.** Lancer le test ciblé, les tests de module, PHPStan, Pest, MySQL, build et parcours navigateur applicable. Répéter la comparaison avec la fixture multi-tenant.

8. **Mesurer après.** Employer exactement la même méthode qu’avant. Ne pas comparer deux environnements, deux commits ou deux jeux de données comme s’ils étaient équivalents.

9. **Obtenir une autorisation spécifique avant toute étape externe.** Staging, canari ou production ne sont possibles que si l’autorisation précise l’environnement, fenêtre, tenant isolé le cas échéant, trafic, responsable, validateur, seuils d’arrêt et rollback.

10. **Décider et journaliser.** Un ticket reste « en validation » jusqu’à la signature humaine. Il est validé seulement si le résultat métier est identique, les gates sont vertes, la régression de ressource est démontrée ou une décision explique l’absence de gain, le rollback est encore applicable et la validation humaine est consignée.

## 4. Commandes de validation par lot

Les commandes suivantes sont la base. Sélectionner le test ciblé du ticket avant de lancer les suites complètes.

~~~powershell
# Après avoir indexé tous les fichiers PHP modifiés du lot
composer qa:format

# Analyse et tests ciblés
php vendor/bin/phpstan analyse --memory-limit=1G --no-progress
php -d memory_limit=512M vendor/bin/pest tests/Feature/<TestCible>.php --compact

# Compatibilité MySQL obligatoire pour SQL, pagination, agrégat, index ou migration
composer qa:test:mysql -- tests/Feature/<TestCible>.php

# Non-régression
php -d memory_limit=512M vendor/bin/pest --compact
composer qa:test:mysql
npm run qa:build
npx playwright test tests/e2e/<scenario-concerne>.spec.js
npm run qa:e2e
git diff --check
~~~

Les commandes de capacité et d’observabilité ci-dessous ne sont pas des feux verts automatiques. Elles deviennent pertinentes uniquement dans l’environnement et la fenêtre explicitement autorisés :

~~~powershell
php artisan observability:report --json
php artisan queue:health --json
php artisan capacity:plan --json
php artisan capacity:report --json --strict
~~~

Un statut capacity « accepted_with_blockers » ne prouve pas l’exécution complète des scénarios. Consulter le JSON et conserver le statut précis.

Pour tout lot PHP, indexer d’abord tous les fichiers PHP concernés, exécuter composer qa:format puis git diff --cached --check et git diff --check. Relancer composer qa:format et les deux contrôles immédiatement avant le push ou la livraison. Le lot reste non prêt tant que laravel-quality n’est pas vert sur le SHA exact ; une indisponibilité locale de Composer ou PHP est un blocage de validation, jamais une validation implicite.

## 5. Exécuter P2-001 — Pagination SQL de la boîte de demandes

Cible : BuildRequestInboxIndexData.

1. Lire le Query Object, RequestController et les consommateurs de resolveCollection, notamment RequestSegmentResolver et ses scénarios de segments s’ils sont affectés. Lister chaque filtre, tri, droit, total et prop rendu aujourd’hui.
2. Exécuter et compléter RequestInboxPhaseOneTest pour caractériser :

   - isolation entreprise ;
   - recherche, statut, client, queue, assigné, source, type de demande, priorité, suivi, non assignée et archivée ;
   - ordre stable, table et board ;
   - total global distinct du nombre de lignes de page et liens de pagination ;
   - première page et pages suivantes ;
   - accès refusé et état vide.

   Ajouter la non-régression des consommateurs indirects de RequestSegmentResolver avant toute activation.

3. Construire une fixture déterministe où le résultat dépasse au moins deux pages et où deux entreprises possèdent des demandes semblables.
4. Mesurer l’ancienne implémentation sur cette fixture : nombre d’objets hydratés, requêtes, temps SQL et mémoire.
5. Implémenter la pagination dans SQL en conservant les noms et la forme des props. Éviter de filtrer ou trier une collection complète en PHP après récupération.
6. Ajouter une comparaison ancien/nouveau des IDs, ordre, compteurs et totaux. Le total de pagination doit conserver le sens fonctionnel du total historique.
7. Exécuter le test ciblé sur SQLite puis MySQL. Examiner le plan MySQL seulement avec des données synthétiques et sans copier SQL/bindings sensibles dans le journal.
8. Activer la nouvelle requête seulement derrière un drapeau ou après une double lecture sans divergence.
9. Tester dans le navigateur : recherche, filtre, ordre de triage historique stable, navigation page suivante/précédente, retour à la première page après changement de filtre et interdiction d’accès d’un tenant voisin. Si le ticket crée un tri utilisateur, son contrat doit être décidé et testé séparément.
10. Conserver le Query Object historique ou une bascule documentée jusqu’à la période de rollback définie dans le ticket.

Critère de sortie : même résultat métier, lignes chargées bornées, tests SQLite/MySQL verts et régression de mémoire/requêtes mesurée par une méthode identique.

## 6. Exécuter P2-002 — Pipeline CRM borné

Cible : BuildSalesPipelineIndexData.

1. Cartographier séparément les demandes, devis, comptes par colonne, cartes, badges, montants et permissions du kanban, ainsi que les consommateurs indirects BuildSalesManagerDashboardData, BuildSalesInboxIndexData et SalesForecastService.
2. Avant toute implémentation, obtenir une décision produit sur le plafond initial, l’ordre des cartes, la signification du total de colonne et le comportement de « charger plus ». Ce changement de présentation ne doit pas être dissimulé comme une optimisation interne.
3. Ajouter des tests pour :

   - total par colonne indépendant des cartes visibles ;
   - ordre des cartes ;
   - plafond de cartes ;
   - action « charger plus » ;
   - filtres et permissions ;
   - cartes d’une autre entreprise invisibles.
   - non-régression de SalesPipelineQueryPhaseSixTest, ManagerDashboardPhaseSixTest, SalesInboxPhaseSixTest et ForecastServicePhaseSixTest.

4. Établir une fixture à plusieurs colonnes, avec une colonne vide, une sous le plafond et une au-dessus.
5. Mesurer l’ancienne fusion mémoire et noter les résultats rendus.
6. Produire une double lecture : la requête bornée construit les mêmes cartes, pendant que l’ancienne sert encore la réponse tant que la comparaison n’est pas propre.
7. Définir un curseur ou une pagination déterministe. Ne pas utiliser un offset instable si le tri peut changer entre deux chargements.
8. Ajouter l’interface « charger plus » sans modifier le statut de cartes déjà affichées ni casser les interactions kanban effectivement présentes au moment du ticket. Si glisser-déposer entre dans le scope, définir son contrat et son test séparément.
9. Tester bureau et mobile, plusieurs chargements successifs, changement de filtre en cours et deux onglets ouverts.
10. Rejouer MySQL, Playwright et la comparaison multi-tenant.
11. N’activer qu’après absence de divergence observée et décision du validateur.

Critère de sortie : le kanban reste fonctionnel, les comptes complets restent corrects, la quantité de cartes chargées est bornée et l’ancien chemin peut être restauré immédiatement.

## 7. Exécuter P2-003 — Relance devis et dashboard prospects en SQL

Cibles : BuildQuoteRecoveryIndexData et BuildProspectDashboardData.

1. Traiter les deux Query Objects comme P2-003a (relance devis) et P2-003b (dashboard prospects), avec deux validations propres. Ne pas accepter une régression dans l’un au motif que l’autre progresse.
2. Pour P2-003a, étendre QuoteRecoveryPhaseTwoTest et QuoteRecoveryAnalyticsPhaseTwoTest : queue, priorité, raison, tri, filtre, pagination, compteurs, statistiques, devis vedettes, montants, valeurs nulles et dates avec référence temporelle fixe. Ajouter aussi les non-régressions de QuoteSegmentResolver et SegmentResolverPhaseThreeTest lorsque ces consommateurs sont affectés.
3. Pour P2-003b, étendre ProspectDashboardAnalyticsTest : résumé, ventilations statut/source/assigné, conversion, moyennes, droits owner/manager et props Inertia/JSON.
4. Inventorier les agrégats PHP : statut, période, fuseau, montant, remise, taxe, devis expiré, relance, prospect et permissions.
5. Écrire des tests de caractérisation qui couvrent les bords de période, valeurs nulles, doublons éventuels et deux tenants.
6. Construire les agrégats SQL en préservant la sémantique historique, notamment les filtres de statut et de date, les arrondis monétaires et les règles de fuseau.
7. Vérifier les index et plans MySQL sur une fixture synthétique de volume. Un index ajouté doit avoir une migration avec down() testée en environnement isolé et un test MySQL. Le rollback opérationnel immédiat reste le drapeau legacy ; ne pas supprimer un index sur staging ou production comme réflexe de rollback sans décision et fenêtre dédiées.
8. Comparer ancien/nouveau au niveau des compteurs, montants, IDs, ordre et règles d’inclusion/exclusion. Toute différence de centime ou de permission bloque le sous-lot.
9. Mesurer requêtes, temps SQL, mémoire et taille de réponse avant/après dans le même environnement.
10. Exécuter les scénarios de navigation concernés : relance, filtres dashboard, accès autorisé/non autorisé et pages sans données.
11. Garder la requête historique disponible pendant le canari autorisé. Aucun canari n’est implicite.
12. Journaliser séparément le verdict de chaque Query Object et ne clôturer P2-003 que si les deux sont conformes.

Critère de sortie : égalité fonctionnelle démontrée, index MySQL testés, agrégats bornés et rollback de chaque sous-lot disponible.

P2-004 à P2-007 restent du backlog. Chacun exige une repriorisation et un GO ticket explicite ; l’achèvement de P2-003 ne les ouvre pas automatiquement.

## 8. Exécuter P2-004 — Cache et agrégats dashboard

P2-004 est le quatrième candidat proposé. Ne l’ouvrir qu’après décision de priorité et mesure par rôle.

1. Identifier les compteurs et sommes qui peuvent être groupés en agrégats SQL sans modifier les droits.
2. Mesurer la charge froide et chaude séparément, par rôle et par entreprise. Une réponse chaude ne peut pas masquer une réponse froide lente.
3. Définir les clés de cache avec environnement, entreprise, rôle, filtre et version de contrat. Toute omission qui permettrait une fuite de tenant bloque le ticket.
4. Documenter TTL, événements d’invalidation, comportement d’échec et limite mémoire.
5. Écrire des tests pour cache vide, cache chaud, invalidation après écriture locale contrôlée, changement de rôle, changement de tenant et erreur du store.
6. Activer le cache seulement après que la source de vérité MySQL et le fallback sont prouvés.
7. Mesurer gain de requêtes et exactitude de montant avec la même fixture.
8. Rollback : désactiver le cache ciblé et revenir aux agrégats SQL ; ne jamais supprimer des données métier pour purger un cache.

Critère de sortie : aucune fuite par clé, montants/permissions exacts, mesures froid/chaud distinctes et fallback contrôlé.

## 9. Exécuter P2-005 — Props Inertia et polling allégés

1. Lister chaque prop partagée ou de page, sa taille, son consommateur et sa fréquence de rafraîchissement.
2. Définir le contrat de DTO minimal : supprimer seulement les données non consommées, jamais un champ public sans décision de contrat.
3. Ajouter des tests de forme de props, permissions, navigation et badge de notification.
4. Rendre les props lourdes différées de façon que l’écran reste cohérent avant et après leur arrivée.
5. Pour le polling, définir un curseur ou timestamp fiable ; contrôler les doublons, événements perdus, multi-onglet et reconnexion.
6. Mesurer requêtes par navigation, taille de réponse et fréquence par onglet.
7. Tester navigateur sur bureau/mobile, navigation rapide, deux onglets et retour après inactivité.
8. Rollback : conserver le chemin de props complet/polling antérieur derrière une bascule courte.

Critère de sortie : aucune régression visible de badges, planning, navigation ou droits ; payload et requêtes diminuent avec preuve reproductible.

## 10. Exécuter P2-006 — Redis cache puis queue, sessions sur décision

P2-006 ne peut pas commencer avant la décision explicite MLK-DEC-005, les preuves opérationnelles P0-005 des workers, redémarrages et rollbacks, ainsi qu’une autorisation d’infrastructure dédiée. Une éventuelle exception GO P2 n’autorise que le travail local ; elle ne permet ni cutover Redis/queue ni canari.

1. Confirmer l’ordre accepté : cache, puis queue, puis décision séparée sur sessions.
2. Définir préfixes par environnement et tenant quand nécessaire, TTL, limites mémoire, éviction, supervision et alertes.
3. Commencer par un cache non critique dont MySQL demeure la source de vérité.
4. Vérifier lecture, écriture, expiration, indisponibilité, fallback et rollback de configuration localement.
5. Obtenir une autorisation séparée avant toute configuration staging/production. Elle doit préciser la connexion, les processus persistants, les clés, la fenêtre, le seuil d’arrêt et le rollback.
6. Après succès du cache, garder la queue bloquée jusqu’à la preuve P0-005 et à une autorisation staging/production dédiée. La traiter ensuite comme un lot distinct : routage, consommateurs, retries, santé, canari approuvé et drainage. Ne pas migrer une session par effet de bord.
7. Écrire une décision séparée avant toute migration de sessions, avec sécurité, persistance, invalidation et rollback.

Critère de sortie : cache et queue sont réversibles, observables, isolés par environnement, et les sessions sont inchangées sans décision distincte.

## 11. Exécuter P2-007 — Observabilité atomique et budgets serveur

1. Réutiliser le contrat d’observabilité P0-006 ; ne pas prétendre qu’il est validé en exploitation tant que sa campagne reste due.
2. Inventorier les opérations read-modify-write et les structures qui grossissent sans borne.
3. Définir compteurs/listes bornés ou APM, cardinalité, rétention, échantillonnage, coût de lecture et politique d’échec.
4. Ajouter des tests de concurrence, absence de perte, bornage, nettoyage et absence de fuite entre run/scénario/tenant.
5. Mesurer la surcharge de la collecte activée versus désactivée sur la même fixture contrôlée.
6. Définir les budgets serveur : p50/p95/p99 client lorsque l’environnement autorisé existe, temps applicatif, requêtes SQL, mémoire, taille de réponse, erreurs et santé des queues.
7. Vérifier le rollback : désactiver la collecte, recharger la configuration et redémarrer les processus persistants uniquement dans l’environnement autorisé ; confirmer l’arrêt des snapshots.

Critère de sortie : données de mesure bornées et fiables, coût de collecte contrôlé et aucun résultat local présenté comme baseline représentative.

## 12. Mesures et décision par ticket

Compléter ce tableau avec la même méthode avant et après. Une cellule inconnue vaut « non mesuré », pas zéro.

| Indicateur | Avant | Après | Méthode identique | Seuil / interprétation | Verdict |
|---|---:|---:|---|---|---|
| Résultat métier ancien/nouveau |  |  | oui/non | égalité obligatoire |  |
| Nombre de requêtes SQL |  |  | oui/non | borné ou réduit |  |
| Temps SQL |  |  | oui/non | documenter variation |  |
| Mémoire |  |  | oui/non | bornée ou réduite |  |
| Taille de réponse |  |  | oui/non | documenter variation |  |
| Durée applicative |  |  | oui/non | diagnostic local |  |
| p50 / p95 / p99 client |  |  | oui/non | seulement campagne autorisée |  |
| Erreurs |  |  | oui/non | aucune hausse non expliquée |  |
| Santé queue/cache |  |  | oui/non | conforme au ticket |  |

Un gain de ressource ne justifie jamais une divergence de résultat, de droit, de total ou de tenant.

## 13. Checklist de clôture de la Phase 2

- [ ] P2-001 à P2-007 ont chacun une entrée de validation, ou le scope Phase 2 a été formellement réduit/remplacé par une décision acceptée ; un report crée une dette et interdit de déclarer la phase terminée selon le DoD actuel ;
- [ ] tous les résultats ancien/nouveau sont comparés sur données représentatives et expurgées ;
- [ ] SQLite et MySQL sont verts pour chaque changement SQL, pagination, agrégat, index ou migration ;
- [ ] p50/p95/p99, mémoire, requêtes, taille de réponse et erreurs sont documentés avec une méthode comparable lorsqu’ils sont requis ;
- [ ] cache froid/chaud est distinctement mesuré ;
- [ ] Redis possède préfixes, santé, limites et rollback ; les sessions n’ont pas migré sans décision ;
- [ ] aucun canari multi-entreprise n’est déclaré vert sans autorisation et preuve ;
- [ ] chaque rollback est encore applicable ;
- [ ] le validateur humain distinct a signé chaque ticket et la clôture de phase ;
- [ ] les preuves versionnées sont expurgées.

## Documents liés

- [Phase 2 — performance données et runtime](PHASE_2_DATA_AND_RUNTIME_PERFORMANCE.md)
- [Runbook de validation Phase 1](PHASE_1_VALIDATION_RUNBOOK.md)
- [Registre des décisions](DECISIONS.md)
- [Protocole obligatoire de tests et de non-régression](QUALITY_GATES.md)
- [Journal de validation](VALIDATION_LOG.md)
