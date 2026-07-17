# Registre des décisions — programme d’amélioration MLK Pro

Dernière mise à jour : 2026-07-16

## Règle

Une décision est requise lorsqu’un choix modifie le scope, l’ordre des phases, un contrat protégé, une cible, un risque accepté ou une stratégie de rollout/rollback.

Statuts possibles : **proposée**, **acceptée**, **rejetée**, **remplacée**, **à réévaluer**.

Une proposition n’autorise aucun changement tant qu’elle n’est pas acceptée par les validateurs nommés.

## Index

| ID | Décision | Statut | Responsable | Échéance |
|---|---|---|---|---|
| MLK-DEC-001 | Exécuter la Phase 0 avant les optimisations visibles | Proposée | À nommer | Avant démarrage |
| MLK-DEC-002 | Une seule phase active à la fois | Proposée | À nommer | Avant démarrage |
| MLK-DEC-003 | Garder `plan_scans` sur `default` ou créer une file dédiée | À décider | Technique / exploitation | Phase 0 |
| MLK-DEC-004 | Ajouter et exploiter le workload `social_publish` | Proposée | Technique / exploitation | Phase 0 |
| MLK-DEC-005 | Migrer Redis dans l’ordre cache → queue → décision sessions | Proposée | Technique / exploitation | Avant Phase 2 |
| MLK-DEC-006 | Utiliser des clés API Twilio en production pour les appels sortants | À réévaluer après rotation | Sécurité / exploitation | Après P0-001 |
| MLK-DEC-007 | Positionner MLK Pro comme OS opérationnel et financier des PME canadiennes de services | À valider par recherche | Produit | Avant Phase 4 |
| MLK-DEC-008 | Utiliser exclusivement `develop` comme base et cible des travaux automatisés | Acceptée | Jules Roger Sombangnen | Permanente |

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

- Statut : **à décider**
- État actuel : `config/async.php` résout `plan_scans` vers `default`; le worker local consomme `default`; le fallback du job est `plan-scans`.
- Option A : conserver `default` et aligner explicitement fallback, documentation et tests.
- Option B : utiliser `plan-scans`, ajouter le worker dédié et sa supervision.
- Critères : volume, durée, isolation nécessaire, capacité et simplicité d’exploitation.
- Décision : à compléter.

## MLK-DEC-004 — Workload `social_publish`

- Statut : **proposée**
- État actuel : clé absente de `config/async.php`, fallback `social-publish`, worker local non consommateur.
- Décision proposée : ajouter le workload canonique et l’inclure dans les workers appropriés avant tout routage supplémentaire.
- Validation requise : technique et exploitation.

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
