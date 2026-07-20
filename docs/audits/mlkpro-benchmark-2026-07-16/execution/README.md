# Cockpit d’exécution contrôlée — amélioration MLK Pro

- Dernière mise à jour : 2026-07-17
- Statut global : **Phase 0 en cours — rotation Twilio terminée et baseline locale P0-002 gelée ; remédiation des dépendances à ouvrir**
- Phase active autorisée : **Phase 0, tickets P0-003 à P0-006**
- Politique Git : **travail et pull requests uniquement depuis/vers `develop` ; `main` est réservée au propriétaire humain du dépôt**
- Responsable d’exécution locale : Codex
- Responsable exploitation : à nommer
- Validateur produit : demandeur

## Décision recommandée

Commencer par la [Phase 0 — Sécurité et baseline](PHASE_0_SECURITY_AND_BASELINE.md).

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
| 0 | [Sécurité et baseline](PHASE_0_SECURITY_AND_BASELINE.md) | En cours — P0-001 et P0-002 terminés | Aucune | Secrets remplacés, audits traités, queues alignées, baseline exploitable |
| 1 | [Gains rapides de performance](PHASE_1_QUICK_PERFORMANCE_WINS.md) | En attente | Phase 0 terminée | Coûts globaux réduits sans régression de workflow |
| 2 | [Performance données et runtime](PHASE_2_DATA_AND_RUNTIME_PERFORMANCE.md) | En attente | Phase 1 terminée | SQL, cache, props et infrastructure validés sous charge |
| 3 | [Expérience utilisateur premium](PHASE_3_PREMIUM_USER_EXPERIENCE.md) | En attente | Phases 1 et 2 terminées | Parcours plus rapides et plus clairs, validés par rôle |
| 4 | [Différenciation produit](PHASE_4_PRODUCT_DIFFERENTIATION.md) | En attente | Phase 3 terminée | Avantages opérations-finance validés avec des pilotes |

## Règle de fonctionnement

Une seule phase peut avoir le statut **en cours**.

Une phase suit obligatoirement ce cycle :

```text
À valider → Validée → En cours → En validation → Terminée
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

L’audit initial doit être lu avec cette précision :

- `AnalyzePlanScanJob` demande le workload `plan_scans`. Dans la configuration actuelle, ce workload pointe par défaut vers `default`, et le worker de développement consomme bien `default`. Le risque est un **écart futur de configuration** si `ASYNC_QUEUE_PLAN_SCANS` devient `plan-scans` sans mise à jour des workers.
- `PublishSocialPostTargetJob` demande `social_publish`, mais ce workload n’est pas déclaré dans `config/async.php`. Son fallback `social-publish` n’est pas présent dans le worker de développement : ce risque est actuel.
- `AnalyzePlanScanJob` capture une exception sans la relancer, ce qui empêche Laravel d’appliquer normalement `tries` et `backoff` pour cette erreur.

La Phase 0 doit centraliser ces décisions et ajouter un test de cohérence automatique.

## Artefacts de référence

- [Rapport HTML](../report.html)
- [Artefact analytique canonique](../artifact.json)
- [Preuves de l’audit](../evidence.md)
- [Backlog priorisé](../priorities.csv)
- [Roadmap de synthèse](../roadmap.csv)
- [Registre des décisions](DECISIONS.md)
- [Journal des validations](VALIDATION_LOG.md)
- [Protocole de tests et de non-régression](QUALITY_GATES.md)

## Prochaine réunion de validation

Ordre du jour proposé :

1. nommer le responsable produit, le responsable technique et le validateur métier ;
2. approuver le scope de la Phase 0 ;
3. confirmer qui possède l’accès administrateur Twilio et aux environnements ;
4. choisir l’environnement de baseline ;
5. décider si `plan_scans` reste sur `default` ou reçoit une file dédiée ;
6. autoriser une branche dédiée aux remédiations de dépendances ;
7. signer la gate d’entrée de la Phase 0 dans le journal.
