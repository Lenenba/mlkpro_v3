# Suivi global — programme d’amélioration MLK Pro

Dernière mise à jour : 2026-08-04

Ce document est la vue maître pour suivre l’évolution complète du programme, de la Phase 0 à la Phase 4. Les documents de phase décrivent le détail technique ; le [journal des validations](VALIDATION_LOG.md) contient les preuves qui autorisent un changement d’état.

- Plan canonique actuel : **cinq phases d’exécution, numérotées de 0 à 4**.
- Phase active autorisée : **Phase 0 — Sécurité et baseline**.
- État global : **Phase 0 en cours ; Phases 1 à 4 planifiées mais non ouvertes**.
- Progression des tickets canoniques : **4 terminés sur 33**. Cet indicateur compte les tickets, pas leur charge ni un pourcentage d’achèvement produit.
- Prochaine gate de sortie de phase : **P0-007 — décision GO / NO-GO signée pour ouvrir ou refuser la Phase 1**.
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
| 0 — Sécurité et baseline | Retirer les risques immédiats et produire une base de mesure fiable | P0-001 à P0-004 terminés ; harnais P0-005 et runner/import P0-006 v3 intégrés dans `develop` par `e91adf8`, validés localement et par la CI de la PR #135 | En cours | Valider P0-005 en exploitation, exécuter P0-006 puis signer P0-007 | Secrets remplacés, audits traités, queues vérifiées en exploitation, baseline représentative acceptée et décision P0-007 signée |
| 1 — Gains rapides de performance | Supprimer les coûts frontend globaux sans changer les workflows | Plan détaillé, baseline statique et cinq tickets définis ; aucune implémentation P1 validée | En attente | Avant P0-007, accepter la baseline, prioriser trois candidats P1 et nommer les responsables ; après le GO, démarrer P1-001 avec cette baseline | Gains mesurés, contrats frontend inchangés, Playwright vert et budgets frontend actifs en CI |
| 2 — Performance données et runtime | Stabiliser p95, mémoire, SQL, cache et queues lorsque les volumes augmentent | Sept tickets, principes de mode ombre et critères de comparaison définis ; aucun ticket P2 démarré | En attente | Après la Phase 1, sélectionner trois priorités initiales et faire accepter `MLK-DEC-005` avant toute migration Redis | Résultats ancien/nouveau identiques, requêtes et mémoire bornées, Redis contrôlé et canari multi-entreprise réussi |
| 3 — Expérience utilisateur premium | Réduire la complexité perçue et accélérer les tâches par rôle | Six tickets, rollout par rôle et critères utilisateur définis ; aucune validation P3 | En attente | Après les Phases 1 et 2, définir la baseline des cinq tâches, les pilotes et le protocole de validation | Cinq parcours améliorés, desktop/mobile/accessibilité/langues validés, pilotes favorables et rollback testé |
| 4 — Différenciation produit | Relier opérations et finance dans une chaîne traçable, réversible et difficile à copier | Huit tickets exploratoires et critères de pilote définis ; aucune validation P4 | En attente | Après la Phase 3, valider par recherche `MLK-DEC-007`, puis le segment et les cinq workflows P4-001 avant tout investissement majeur | Pilote concluant, chaîne opérations-finance auditée, intégrations et IA contrôlées, décision d’investissement consignée |

## Pourquoi l’exécution s’arrête actuellement avant la Phase 1

Le programme ne se termine pas à la Phase 1. Les Phases 1 à 4 sont planifiées ci-dessous, mais elles ne peuvent pas encore commencer parce que la gate de sortie de la Phase 0 n’est pas satisfaite :

1. le lot technique `6af521e`, inclus dans le SHA de PR `02dc5c3`, est désormais intégré dans `develop` par `e91adf8` après les gates locales et les trois jobs distants de la PR #135 ;
2. P0-005 attend le staging, le déploiement des quatre processus persistants, quatre sorties canari opérationnelles, les contrôles métier/santé, le redémarrage et un rollback exécuté ;
3. P0-006 attend l’acceptation ou le remplacement de `MLK-DEC-009`, puis une campagne représentative couvrant chacun des sept scénarios par exécution conforme ou blocage formel ;
4. P0-007 est bloqué : produit, technique et exploitation n’ont signé ni GO ni NO-GO.

Le cockpit applique prudemment le séquencement proposé dans `MLK-DEC-002` — une seule phase active — mais son acceptation formelle reste à consigner. Tant que cette gouvernance et la gate P0-007 ne sont pas résolues, aucune phase future n’est considérée ouverte. Commencer P1 avant les preuves ci-dessus rendrait impossible de comparer correctement les gains et mélangerait les causes de régression.

## Chaîne de progression

```text
Validation exploitation et canaris P0-005 → campagne représentative P0-006
                                            → décision signée P0-007
                                            → Phase 1 → Phase 2 → Phase 3 → Phase 4
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
| `MLK-DEC-009` — Collecte P0-006 sur staging isolé | Proposée | Gouverne l’environnement et les preuves de la campagne représentative | Faire statuer la proposition avant toute collecte P0-006 |

## Détail de la Phase 0 — Sécurité et baseline

Document détaillé : [PHASE_0_SECURITY_AND_BASELINE.md](PHASE_0_SECURITY_AND_BASELINE.md)

| Ordre | Ticket ou étape | Ce qui était prévu | État prouvé | Acquis et prochaine action |
|---:|---|---|---|---|
| 1 | P0-001 — Rotation Twilio | Révoquer l’ancien secret, promouvoir le nouveau et réussir les canaris | Terminé | Révocation, rejet, canaris et revue d’activité consignés dans `VALID-P0-001-CLOSEOUT-2026-07-17` |
| 2 | P0-002 — Baseline initiale | Geler versions, tests, audits, build, queues et mesures de départ | Terminé | Baseline enregistrée ; replays Node 20 et MySQL confirmés |
| 3 | P0-003 — Dépendances PHP | Retirer les avis applicables et contraindre explicitement les dépendances sensibles | Terminé | PR #131 fusionnée ; Laravel 12.64, audit Composer sans avis et gates vertes |
| 4 | P0-004 — Dépendances JavaScript | Corriger les avis npm sans mise à niveau globale incontrôlée | Terminé | PR #132 ; audits, build et Playwright verts sous Node 20 |
| 5 | P0-005 — Queues et workers | Déclarer les workloads, vérifier les consommateurs, retries, timeouts et visibilité | En validation | Harnais `queue:workload-canary` de `6af521e` intégré dans `develop` par `e91adf8`, validé localement et dans la PR #135. Restent le staging, quatre processus, les preuves opérationnelles et le rollback |
| 6 | P0-006 — Observabilité et baseline | Traiter sept scénarios par exécution conforme ou blocage formel, puis produire les mesures représentatives acceptables | En validation | Runner Node 20, contrat de fixture v3 `strategy/requests`, résultat/import v3 et garde-fous intégrés dans `develop`. Restent la décision `MLK-DEC-009`, l’environnement et la campagne représentative |
| 7 | P0-007 — Revue GO / NO-GO | Examiner les preuves, préparer la recommandation et obtenir la décision signée | Bloqué | Matrice factuelle ci-dessous actualisée ; recommandation NO-GO non signée tant que P0-005/P0-006 et les signatures restent absents |

La Phase 0 ne sera terminée qu’après une gate P0-007 entièrement déterminée et signée. Un GO ouvre la Phase 1 ; un NO-GO signé maintient la Phase 0 active avec des actions correctives.

### Matrice factuelle P0-007 au 2026-08-04

| Domaine | État | Preuve acquise | Élément manquant pour fermer |
|---|---|---|---|
| P0-001 à P0-004 | Conforme | Tickets terminés et preuves historiques consignées | Aucun |
| Harnais P0-005 | Conforme localement et en CI | `6af521e` inclus dans PR #135 ; 33 tests/457 assertions ; workflow `30911394066` vert | Aucun manque technique ; preuve d’exploitation séparée ci-dessous |
| Exploitation P0-005 | Bloqué | Procédure, audit, canari et rollback documentés | Staging, propriétaire, quatre processus, quatre canaris opérationnels, santé/métier/redémarrage/rollback |
| Harnais P0-006 | Conforme localement et en CI | Runner Node 20 : 12/12 ; import v3 ; workflow `30911394066` vert | Aucun manque technique ; campagne séparée ci-dessous |
| Campagne P0-006 | Bloqué | Contrat et guide reproductibles | Décision `MLK-DEC-009`, staging isolé, fenêtre, propriétaire/validateur, sept résultats/imports et rapport strict |
| Qualité globale | Conforme localement et en CI | Dernier lot `e91adf8` : PHPStan sans erreur, Pest 1 286/13 194, Node 16/16, Vite, format et diff verts ; CI PR #135 : trois jobs verts | Aucun manque technique |
| Gouvernance P0-007 | Bloqué | Recommandation factuelle : **NO-GO** | Signatures produit, technique et exploitation ; aucune signature n’est simulée par ce document |

Verdict actuel : **Phase 0 non terminée et Phase 1 fermée**. Ce NO-GO est une recommandation documentaire, pas une décision humaine signée.

### Accès d’exploitation constaté au 2026-08-04

L’inventaire en lecture seule ne trouve qu’un workflow GitHub `quality`. Le dépôt ne possède aucun environnement GitHub, aucune variable Actions, aucun secret Actions et aucun workflow de déploiement. Le checkout local est configuré en `APP_ENV=local` et ne contient aucune valeur de campagne ou de canari staging. Aucun script Forge, Supervisor, systemd, Horizon, Docker ou autre cible de déploiement n’est versionné. En conséquence, Codex peut intégrer et valider le code, mais ne peut pas produire honnêtement les preuves P0-005/P0-006 sans qu’un environnement staging et son mécanisme d’accès soient fournis.

## Détail de la Phase 1 — Gains rapides de performance

Document détaillé : [PHASE_1_QUICK_PERFORMANCE_WINS.md](PHASE_1_QUICK_PERFORMANCE_WINS.md)

Baseline statique déjà documentée : application JavaScript à 188,6 kB gzip, locales FR/EN/ES à 116,1/106,6/102,6 kB gzip et table Ziggy d’environ 101,7 k caractères par réponse HTML. P0-006 doit compléter et faire accepter les mesures dynamiques avant P0-007 ; après un GO, P1-001 reprend cette baseline sans la réinventer.

| Ordre | Ticket | Ce que l’on compte faire | État | Déclencheur ou prochaine action |
|---:|---|---|---|---|
| 1 | P1-001 — Initialisation Preline ciblée | Remplacer le mixin global et les timers multiples par une initialisation unique après navigation | En attente | Responsable/validateur nommés et baseline acceptée avant P0-007 ; démarrage uniquement après le GO |
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
| 2 | Fermer P0-005 côté exploitation | Responsable, environnement et fenêtre à nommer | Quatre processus actifs, quatre canaris opérationnels, santé/métier, redémarrage et rollback testés |
| 3 | Faire statuer `MLK-DEC-009`, parallèlement à P0-005 | Proposition non acceptée | Environnement et protocole de collecte P0-006 autorisés ou remplacés explicitement |
| 4 | Traiter les sept scénarios P0-006 | Bloqué par P0-005 et la gouvernance de campagne | Résultats v3 importés, empreintes approuvées, télémétrie et rapport strict représentatif |
| 5 | Faire statuer `MLK-DEC-001`/`002`, puis signer P0-007 | Bloqué par P0-005/P0-006 et décisions proposées | Décision GO ou NO-GO datée et signée par produit, technique et exploitation |
| 6 | Ouvrir la Phase 1 et P1-001 | En attente d’un GO | Reprise de la baseline acceptée et première optimisation contrôlée |
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
