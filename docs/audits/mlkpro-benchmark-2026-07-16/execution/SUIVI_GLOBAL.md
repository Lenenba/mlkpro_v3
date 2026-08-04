# Suivi global — programme d’amélioration MLK Pro

Dernière mise à jour : 2026-08-04

Ce document est la vue maître pour suivre l’évolution complète du programme, de la Phase 0 à la Phase 4. Les documents de phase décrivent le détail technique ; le [journal des validations](VALIDATION_LOG.md) contient les preuves qui autorisent un changement d’état.

- Plan canonique actuel : **cinq phases d’exécution, numérotées de 0 à 4**.
- Phase active autorisée : **Phase 1 — Gains rapides de performance**.
- État global : **Phase 0 clôturée sous dérogation MLK-DEC-010 ; Phase 1 ouverte ; Phases 2 à 4 planifiées mais non ouvertes**.
- Progression des tickets canoniques : **4 terminés avec preuve complète ; P0-005/P0-006 restent des preuves opérationnelles reportées sous dérogation et P0-007 est clôturé par GO**. Cet indicateur ne mesure ni la charge ni un pourcentage d’achèvement produit.
- Prochaine gate de sortie de phase : **P1-001 — première optimisation frontend avec mesures statiques, tests et rollback**.
- Règle Git : les travaux et pull requests ciblent `develop` ; `main` reste réservé au propriétaire humain du dépôt.

## Comment lire les états

| État | Signification |
|---|---|
| En attente | Travail planifié, mais gate d’entrée ou phase précédente non terminée |
| À valider | Prérequis, scope, responsables ou baseline à confirmer avant exécution |
| Validée | Gate d’entrée approuvée ; le travail peut passer à `En cours` lorsque son démarrage est autorisé |
| En cours | Travail autorisé et actuellement exécuté |
| En validation | Implémentation disponible, mais preuves techniques, métier ou d’exploitation encore incomplètes |
| Bloqué | Une dépendance, une preuve, une décision ou un accès empêche la progression |
| Terminé | Critères, validations et preuves attendus consignés |

Une validation locale ne vaut pas validation distante. Une fusion ne vaut pas déploiement, et une préparation technique ne vaut pas campagne représentative.

## Vue d’ensemble des phases 0 à 4

| Phase | Objectif | Récapitulatif acquis | État actuel | Prochaine action | Gate de sortie |
|---:|---|---|---|---|---|
| 0 — Sécurité et baseline | Retirer les risques immédiats et produire une base de mesure fiable | P0-001 à P0-004 terminés ; harnais P0-005 et runner/import P0-006 v3 intégrés dans develop par e91adf8, validés localement et par la CI de la PR #135 | Terminée sous dérogation | GO P0-007 accepté ; réaliser les preuves opérationnelles P0-005/P0-006 avant le 2027-08-04 | Dérogation MLK-DEC-010 tracée, risques acceptés et échéance ferme |
| 1 — Gains rapides de performance | Supprimer les coûts frontend globaux sans changer les workflows | Plan détaillé, baseline statique et cinq tickets définis ; aucune implémentation P1 validée | Ouverte — aucun ticket démarré | Démarrer P1-001 avec la baseline statique, sans attribuer de gain à une baseline dynamique absente | Gains mesurés, contrats frontend inchangés, Playwright vert et budgets frontend actifs en CI |
| 2 — Performance données et runtime | Stabiliser p95, mémoire, SQL, cache et queues lorsque les volumes augmentent | Sept tickets, principes de mode ombre et critères de comparaison définis ; aucun ticket P2 démarré | En attente | Après la Phase 1, sélectionner trois priorités initiales et faire accepter `MLK-DEC-005` avant toute migration Redis | Résultats ancien/nouveau identiques, requêtes et mémoire bornées, Redis contrôlé et canari multi-entreprise réussi |
| 3 — Expérience utilisateur premium | Réduire la complexité perçue et accélérer les tâches par rôle | Six tickets, rollout par rôle et critères utilisateur définis ; aucune validation P3 | En attente | Après les Phases 1 et 2, définir la baseline des cinq tâches, les pilotes et le protocole de validation | Cinq parcours améliorés, desktop/mobile/accessibilité/langues validés, pilotes favorables et rollback testé |
| 4 — Différenciation produit | Relier opérations et finance dans une chaîne traçable, réversible et difficile à copier | Huit tickets exploratoires et critères de pilote définis ; aucune validation P4 | En attente | Après la Phase 3, valider par recherche `MLK-DEC-007`, puis le segment et les cinq workflows P4-001 avant tout investissement majeur | Pilote concluant, chaîne opérations-finance auditée, intégrations et IA contrôlées, décision d’investissement consignée |

## Conditions de la clôture sous dérogation

Le GO P0-007 a été explicitement accepté par Jules Roger Sombangnen, responsable Produit, Technique et Exploitation. La Phase 1 peut commencer, mais les preuves opérationnelles attendues de la Phase 0 ne sont pas supprimées :

1. P0-005 attend toujours le staging, le déploiement des quatre processus persistants, quatre sorties canari opérationnelles, les contrôles métier/santé, le redémarrage et un rollback exécuté ;
2. P0-006 attend toujours la campagne représentative de sept scénarios définie par MLK-DEC-009, un validateur distinct et un rapport strict ;
3. MLK-DEC-010 accepte jusqu’au 2027-08-04 les risques de workers non validés en exploitation et d’absence de baseline dynamique représentative ;
4. aucun test de charge, canari opérationnel, runner de capacité ou écriture en production n’est autorisé sans une nouvelle approbation explicite.

Cette ouverture est une exception ciblée aux prérequis de baseline dynamique des décisions MLK-DEC-001/MLK-DEC-002 ; elle ne rend pas ces décisions générales acceptées et ne permet pas d’affirmer un gain dynamique représentatif avant les preuves reportées.

## Chaîne de progression

```text
GO P0-007 sous dérogation → Phase 1 → Phase 2 → Phase 3 → Phase 4
              └→ staging, canaris P0-005, campagne P0-006 et rollback dus avant 2027-08-04
```

## Correspondance avec la roadmap historique

Le fichier [roadmap.csv](../roadmap.csv) est la synthèse initiale de l’audit. Le plan d’exécution a ensuite séparé les gains rapides frontend des travaux données/runtime. Le présent suivi porte la numérotation opérationnelle 0 à 4 ; toute modification de scope ou d’ordre ne devient toutefois autorisée qu’après acceptation et consignation dans [DECISIONS.md](DECISIONS.md).

| Roadmap historique | Plan d’exécution actuel | Explication |
|---|---|---|
| Phase 0 — Sécuriser et mesurer | Phase 0 — Sécurité et baseline | Correspondance directe |
| Phase 1 — Accélérer sans changer | Phases 1 et 2 | Le frontend rapide est en Phase 1 ; SQL, cache, Redis et runtime sont en Phase 2 |
| Phase 2 — Rendre premium | Phase 3 — Expérience utilisateur premium | Décalage d’un numéro après la séparation de l’ancienne Phase 1 |
| Phase 3 — Différencier durablement | Phase 4 — Différenciation produit | Décalage d’un numéro après la séparation de l’ancienne Phase 1 |

## Décisions de gouvernance encore ouvertes

Ces décisions ne doivent pas être considérées acceptées tant que leurs validateurs ne les ont pas formellement approuvées dans [DECISIONS.md](DECISIONS.md).

| Décision | État | Impact sur le programme | Prochaine action |
|---|---|---|---|
| `MLK-DEC-001` — Phase 0 avant les optimisations visibles | Proposée | Gouverne l’ordre entre la fermeture de P0 et le démarrage de P1 | Nommer produit, technique et exploitation, puis accepter, rejeter ou remplacer la proposition |
| `MLK-DEC-002` — Une seule phase active | Proposée | Gouverne le séquencement global et les exceptions de parallélisation | Faire statuer la politique avant l’ouverture d’une nouvelle phase |
| `MLK-DEC-005` — Redis cache → queue → décision sessions | Proposée | Prérequis à toute migration Redis de P2-006 | Faire accepter, rejeter ou remplacer la stratégie avant Phase 2 |
| `MLK-DEC-006` — Identité Twilio de production | À réévaluer après rotation | Peut séparer les clés d’appels sortants du secret de validation des webhooks | Réévaluer l’architecture maintenant que P0-001 est terminé |
| `MLK-DEC-007` — Positionnement opérations-finance | À valider par recherche | Prérequis produit avant l’investissement majeur de Phase 4 | Réaliser entretiens, analyse des tâches, volonté de payer et pilotes par segment |
| `MLK-DEC-009` — Collecte P0-006 sur staging isolé | Proposée — reportée | Gouverne l’environnement et les preuves de la future campagne représentative | Réévaluer dès qu’un staging existe, avant le 2027-08-04 |
| `MLK-DEC-010` — Dérogation temporaire de sortie P0 sans staging | Acceptée | Autorise le GO P0-007 tout en reportant les preuves P0-005/P0-006 | Fournir les preuves avant expiration le 2027-08-04 |

## Détail de la Phase 0 — Sécurité et baseline

Document détaillé : [PHASE_0_SECURITY_AND_BASELINE.md](PHASE_0_SECURITY_AND_BASELINE.md)

| Ordre | Ticket ou étape | Ce qui était prévu | État prouvé | Acquis et prochaine action |
|---:|---|---|---|---|
| 1 | P0-001 — Rotation Twilio | Révoquer l’ancien secret, promouvoir le nouveau et réussir les canaris | Terminé | Révocation, rejet, canaris et revue d’activité consignés dans `VALID-P0-001-CLOSEOUT-2026-07-17` |
| 2 | P0-002 — Baseline initiale | Geler versions, tests, audits, build, queues et mesures de départ | Terminé | Baseline enregistrée ; replays Node 20 et MySQL confirmés |
| 3 | P0-003 — Dépendances PHP | Retirer les avis applicables et contraindre explicitement les dépendances sensibles | Terminé | PR #131 fusionnée ; Laravel 12.64, audit Composer sans avis et gates vertes |
| 4 | P0-004 — Dépendances JavaScript | Corriger les avis npm sans mise à niveau globale incontrôlée | Terminé | PR #132 ; audits, build et Playwright verts sous Node 20 |
| 5 | P0-005 — Queues et workers | Déclarer les workloads, vérifier les consommateurs, retries, timeouts et visibilité | Dérogation acceptée jusqu’au 2027-08-04 | Harnais validé localement/CI ; preuves de staging, quatre processus, canaris et rollback toujours dues |
| 6 | P0-006 — Observabilité et baseline | Traiter sept scénarios par exécution conforme ou blocage formel, puis produire les mesures représentatives acceptables | Dérogation acceptée jusqu’au 2027-08-04 | Runner/import v3 et garde-fous intégrés ; staging, campagne représentative et validateur distinct toujours dus |
| 7 | P0-007 — Revue GO / NO-GO | Examiner les preuves, préparer la recommandation et obtenir la décision signée | Terminé — GO sous dérogation | Décision explicite de Jules Roger Sombangnen en Produit, Technique et Exploitation ; MLK-DEC-010 |

La Phase 0 ne sera terminée qu’après une gate P0-007 entièrement déterminée et signée. Un GO ouvre la Phase 1 ; un NO-GO signé maintient la Phase 0 active avec des actions correctives.

### Matrice factuelle P0-007 au 2026-08-04

| Domaine | État | Preuve acquise | Élément manquant pour fermer |
|---|---|---|---|
| P0-001 à P0-004 | Conforme | Tickets terminés et preuves historiques consignées | Aucun |
| Harnais P0-005 | Conforme localement et en CI | `6af521e` inclus dans PR #135 ; 33 tests/457 assertions ; workflow `30911394066` vert | Aucun manque technique ; preuve d’exploitation séparée ci-dessous |
| Exploitation P0-005 | Dérogation acceptée jusqu’au 2027-08-04 | Procédure, audit, canari et rollback documentés | Staging, quatre processus, quatre canaris opérationnels, santé/métier/redémarrage/rollback restent dus |
| Harnais P0-006 | Conforme localement et en CI | Runner Node 20 : 12/12 ; import v3 ; workflow `30911394066` vert | Aucun manque technique ; campagne séparée ci-dessous |
| Campagne P0-006 | Dérogation acceptée jusqu’au 2027-08-04 | Contrat et guide reproductibles | MLK-DEC-009, staging isolé, fenêtre, validateur distinct, sept résultats/imports et rapport strict restent dus |
| Qualité globale | Conforme localement et en CI | Dernier lot `e91adf8` : PHPStan sans erreur, Pest 1 286/13 194, Node 16/16, Vite, format et diff verts ; CI PR #135 : trois jobs verts | Aucun manque technique |
| Gouvernance P0-007 | GO sous dérogation signé | Décision explicite du responsable Produit, Technique et Exploitation ; MLK-DEC-010 | Réévaluer la dérogation avant le 2027-08-04 |

Verdict actuel : **Phase 0 terminée sous dérogation et Phase 1 ouverte**. Les preuves opérationnelles P0-005/P0-006 restent absentes, ne sont pas décrites comme vertes et doivent être fournies avant le 2027-08-04.

### Accès d’exploitation constaté au 2026-08-04

L’inventaire en lecture seule ne trouve qu’un workflow GitHub `quality`. Le dépôt ne possède aucun environnement GitHub, aucune variable Actions, aucun secret Actions et aucun workflow de déploiement. Le checkout local est configuré en `APP_ENV=local` et ne contient aucune valeur de campagne ou de canari staging. Aucun script Forge, Supervisor, systemd, Horizon, Docker ou autre cible de déploiement n’est versionné. En conséquence, Codex peut intégrer et valider le code, mais ne peut pas produire honnêtement les preuves P0-005/P0-006 sans qu’un environnement staging et son mécanisme d’accès soient fournis.

## Détail de la Phase 1 — Gains rapides de performance

Document détaillé : [PHASE_1_QUICK_PERFORMANCE_WINS.md](PHASE_1_QUICK_PERFORMANCE_WINS.md)

Baseline statique déjà documentée : application JavaScript à 188,6 kB gzip, locales FR/EN/ES à 116,1/106,6/102,6 kB gzip et table Ziggy d’environ 101,7 k caractères par réponse HTML. MLK-DEC-010 autorise P1-001 à partir de cette baseline statique, mais P0-006 doit toujours compléter les mesures dynamiques avant toute affirmation de gain dynamique représentatif.

| Ordre | Ticket | Ce que l’on compte faire | État | Déclencheur ou prochaine action |
|---:|---|---|---|---|
| 1 | P1-001 — Initialisation Preline ciblée | Remplacer le mixin global et les timers multiples par une initialisation unique après navigation | Ouverte — non démarrée | GO P0-007 sous MLK-DEC-010 ; mesurer depuis la baseline statique sans revendication dynamique |
| 2 | P1-002 — Groupes de routes Ziggy | Fournir uniquement les routes nécessaires aux surfaces public, portail et admin | En attente | Après P1-001 ; mesurer la baisse HTML et tester chaque surface |
| 3 | P1-003 — Traductions par domaine | Charger les catalogues nécessaires sans changer les clés ni le fallback anglais | En attente | Après P1-002 ; mesurer le JavaScript initial et vérifier FR/EN/ES |
| 4 | P1-004 — Images et polices critiques | Ajouter AVIF/WebP, `srcset`, dimensions, priorité du héros et police non bloquante | En attente | Après P1-002, parallélisable avec P1-003 ; conserver les anciens médias pour rollback |
| 5 | P1-005 — Budgets frontend CI | Versionner les tailles gzip et budgets par entrée/parcours | En attente | Après P1-001 à P1-004 ; toute exception doit être consignée dans `DECISIONS.md` |

Gate de sortie : contrats frontend inchangés, Playwright desktop/mobile vert, tailles et Web Vitals avant/après consignés, routes et traductions complètes, budgets CI actifs, rollback de chaque ticket documenté et trois priorités P2 choisies à partir des mesures.

## Détail de la Phase 2 — Performance données et runtime

Document détaillé : [PHASE_2_DATA_AND_RUNTIME_PERFORMANCE.md](PHASE_2_DATA_AND_RUNTIME_PERFORMANCE.md)

Aucun ticket P2 n’a commencé. P0-005 et P0-006 sont des prérequis indirects via la fermeture de la Phase 0 et la production de la baseline ; ils ne valent pas validation de P2-006 ou P2-007. La Phase 1 sélectionnera trois priorités initiales à partir des mesures ; leur ordre de démarrage sera ensuite consigné.

| Ordre documentaire | Ticket | Ce que l’on compte faire | État | Déclencheur ou prochaine action |
|---:|---|---|---|---|
| 1 | P2-001 — Pagination SQL des demandes | Borner `BuildRequestInboxIndexData` tout en conservant filtres, tris, droits, totaux et props | En attente | Après Phase 1, s’il fait partie des trois priorités retenues ; ouvrir en mode ombre avec contrat JSON/Inertia et rollback |
| 2 | P2-002 — Pipeline CRM borné | Séparer les comptes des cartes, plafonner les cartes et ajouter « charger plus » | En attente | Priorité à confirmer par les mesures ; prévoir double lecture et bascule |
| 3 | P2-003 — Relance devis et prospects en SQL | Remplacer les agrégats PHP par SQL et vérifier les index MySQL | En attente | Comparer ancien/nouveau, p95 et mémoire avant activation |
| 4 | P2-004 — Cache et agrégats dashboard | Lire le cache avant les calculs et regrouper les agrégats conditionnels | En attente | Définir budget de requêtes, invalidation et mesures cache froid/chaud |
| 5 | P2-005 — Props Inertia et polling | Réduire DTO, différer les props et rendre les notifications incrémentales | En attente | Préserver navigation, badges, planning et comportement multi-onglet |
| 6 | P2-006 — Redis cache puis queue | Si `MLK-DEC-005` est acceptée, migrer d’abord le cache, ensuite les queues ; décider séparément pour les sessions | En attente | Faire accepter `MLK-DEC-005`, puis définir santé, préfixes, TTL, mémoire, canaris et rollback ; MySQL reste source de vérité |
| 7 | P2-007 — Observabilité atomique | Utiliser compteurs/listes bornés Redis ou APM et activer les budgets serveur | En attente | Prouver l’absence de perte sous concurrence et le coût de mesure borné |

Gate de sortie : résultats ancien/nouveau identiques, tests MySQL et budgets de requêtes verts, p95/mémoire améliorés ou décision documentée, cache froid/chaud mesuré, Redis réversible, sessions non migrées sans décision et canari multi-entreprise sans fuite.

## Détail de la Phase 3 — Expérience utilisateur premium

Document détaillé : [PHASE_3_PREMIUM_USER_EXPERIENCE.md](PHASE_3_PREMIUM_USER_EXPERIENCE.md)

Les fondations fonctionnelles, multilingues et mobiles existent déjà, mais aucune entrée P3 n’est validée. Les métriques utilisateur avant/après et les pilotes restent à définir.

| Ordre documentaire | Ticket | Ce que l’on compte faire | État | Déclencheur ou prochaine action |
|---:|---|---|---|---|
| 1 | P3-001 — Accueil et actions par rôle | Présenter aux propriétaires, comptables, employés terrain et approbateurs leurs actions prioritaires | En attente | Après Phases 1 et 2 ; mesurer les cinq tâches avant de modifier l’expérience |
| 2 | P3-002 — Système d’expérience cohérent | Unifier tableaux, filtres, états, erreurs, commandes, confirmations et raccourcis | En attente | Inventorier les variantes et planifier une adoption progressive |
| 3 | P3-003 — Preuves produit publiques | Montrer écrans réels, démonstrations, résultats vérifiables et CTA localisés | En attente | Vérifier chaque promesse et préserver la performance publique |
| 4 | P3-004 — Mobile orienté tâche | Optimiser photo/reçu, temps, devis/facture, signature, paiement et approbation | En attente | Définir appareils pilotes, reprise après interruption et erreurs récupérables |
| 5 | P3-005 — Accessibilité et localisation | Valider clavier, lecteur d’écran, focus, contraste, zoom, erreurs et FR/EN/ES | En attente | Construire la matrice de tests sur les parcours pilotes |
| 6 | P3-006 — Validation utilisateur | Comparer avant/après : temps, réussite, erreurs, aide et satisfaction | En attente | Concevoir le protocole dès l’ouverture de P3 ; aucun rollout général si un workflow régresse |

Gate de sortie : cinq parcours plus rapides ou fiables, permissions et résultats inchangés, desktop/mobile/accessibilité/langues validés, support et pilotes favorables, preuves publiques exactes et rollback d’expérience testé.

## Détail de la Phase 4 — Différenciation produit

Document détaillé : [PHASE_4_PRODUCT_DIFFERENTIATION.md](PHASE_4_PRODUCT_DIFFERENTIATION.md)

MLK Pro possède déjà des fondations CRM, opérations, facturation, paiements, comptabilité, IA et API. Elles ne prouvent toutefois ni la valeur du segment, ni un pilote, ni une intégration bancaire canadienne. P4 commence par la recherche exigée par `MLK-DEC-007` et une décision produit, pas par un développement coûteux.

| Ordre documentaire | Ticket | Ce que l’on compte faire | État | Déclencheur ou prochaine action |
|---:|---|---|---|---|
| 1 | P4-001 — Segment et offre | Choisir un segment prioritaire et ses cinq workflows critiques | En attente | Après Phase 3 ; mener la recherche de `MLK-DEC-007`, faire statuer la décision et consigner fréquence, valeur, budget, intégrations et conformité |
| 2 | P4-002 — Chaîne opération vers écriture | Relier devis, travail, temps/dépenses, facture, paiement et écriture sans double saisie | En attente | Exiger prévisualisation, idempotence, correction, annulation et piste d’audit |
| 3 | P4-003 — Collaboration comptable | Ajouter rôles externes, demandes de pièces, commentaires, revue et clôture | En attente | Définir permissions minimales, séparation des entreprises et journal complet |
| 4 | P4-004 — Banque, documents et rapprochement | Évaluer flux bancaires, OCR, brouillons, approbation, paiement et rapprochement | En attente | Valider fournisseur et couverture canadienne avant implémentation |
| 5 | P4-005 — IA explicable | Rendre sources, contexte, diff, approbation, permissions, confiance et annulation visibles | En attente | Interdire toute action financière irréversible ou envoi externe non autorisé |
| 6 | P4-006 — Écosystème ouvert | Fournir API, webhooks, OAuth par scopes, sandbox, quotas et éventuellement MCP | En attente | Revue sécurité préalable ; garantir idempotence, rotation, audit et versionnement |
| 7 | P4-007 — Centre de confiance | Publier des preuves sur données, reprise, disponibilité, chiffrement, permissions et incidents | En attente | Attribuer un propriétaire et une preuve opérationnelle à chaque affirmation |
| 8 | P4-008 — Pilote et investissement | Mesurer un petit groupe d’entreprises avec support rapproché et retrait possible | En attente | Étendre uniquement si le travail diminue sans perte d’exactitude ni de confiance |

Gate de sortie : segment et proposition validés, chaîne opérations-finance auditée et réversible, collaboration et intégrations canadiennes validées, IA contrôlable, API sécurisée, valeur/confiance mesurées et décision d’investissement consignée.

## Prochaines actions dans l’ordre

| Priorité | Action | État actuel | Résultat attendu |
|---|---|---|---|
| 1 | Intégrer le lot P0-005/P0-006 dans `develop` | Terminé — commit `e91adf8` poussé sur `develop` | Lot disponible dans `develop` pour déploiement |
| 2 | Fournir un staging et fermer P0-005 côté exploitation | Dérogation MLK-DEC-010 active jusqu’au 2027-08-04 | Quatre processus actifs, quatre canaris opérationnels, santé/métier, redémarrage et rollback testés |
| 3 | Réévaluer MLK-DEC-009 et préparer P0-006 | Dérogation MLK-DEC-010 active jusqu’au 2027-08-04 | Environnement, validateur distinct et protocole de collecte autorisés |
| 4 | Traiter les sept scénarios P0-006 | Reporté sous dérogation | Résultats v3 importés, empreintes approuvées, télémétrie et rapport strict représentatif |
| 5 | Réévaluer ou clôturer MLK-DEC-010 | Échéance ferme : 2027-08-04 | Preuves P0-005/P0-006 réalisées ou nouvelle décision explicite |
| 6 | Démarrer P1-001 | Ouverte | Première optimisation contrôlée à partir de la baseline statique |
| 7 | Terminer Phase 1, prioriser Phase 2 et faire statuer `MLK-DEC-005` | En attente | Trois priorités P2 fondées sur les mesures et décision Redis explicite |
| 8 | Exécuter Phase 2 puis ouvrir Phase 3 | En attente | Runtime borné, puis expérience utilisateur mesurable |
| 9 | Valider Phase 3 puis conduire la recherche `MLK-DEC-007` avant Phase 4 | En attente | Pilotes UX concluants, positionnement décidé, puis différenciation produit validée par le marché |

## Règles de mise à jour du suivi

1. Tout changement d’état doit être soutenu par une entrée dans [VALIDATION_LOG.md](VALIDATION_LOG.md).
2. Le présent document, le document de phase concerné et le journal sont mis à jour dans le même lot documentaire.
3. Chaque preuve indique date, SHA exact, environnement, responsable, validateur et résultat des gates applicables.
4. `Terminé` n’est utilisé qu’après satisfaction des critères de sortie ; `validé localement` ne doit jamais être transformé en `livré` ou `fusionnable` sans CI distante requise.
5. Les responsables, validateurs, échéances calendaires et rollbacks sont nommés avant l’ouverture d’une phase ou d’un ticket.
6. Toute modification de l’ordre, du scope, d’un seuil ou d’un contrat protégé est consignée dans [DECISIONS.md](DECISIONS.md).

## Documents de référence

- [Cockpit d’exécution](README.md)
- [Phase 0 — Sécurité et baseline](PHASE_0_SECURITY_AND_BASELINE.md)
- [Phase 1 — Gains rapides de performance](PHASE_1_QUICK_PERFORMANCE_WINS.md)
- [Phase 2 — Performance données et runtime](PHASE_2_DATA_AND_RUNTIME_PERFORMANCE.md)
- [Phase 3 — Expérience utilisateur premium](PHASE_3_PREMIUM_USER_EXPERIENCE.md)
- [Phase 4 — Différenciation produit](PHASE_4_PRODUCT_DIFFERENTIATION.md)
- [Journal des validations](VALIDATION_LOG.md)
- [Registre des décisions](DECISIONS.md)
- [Protocole qualité](QUALITY_GATES.md)
- [Roadmap historique de synthèse](../roadmap.csv)
