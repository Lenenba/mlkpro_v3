# Registre des décisions — programme d’amélioration MLK Pro

Dernière mise à jour : 2026-07-27

## Règle

Une décision est requise lorsqu’un choix modifie le scope, l’ordre des phases, un contrat protégé, une cible, un risque accepté ou une stratégie de rollout/rollback.

Statuts possibles : **proposée**, **acceptée**, **rejetée**, **remplacée**, **à réévaluer**.

Une proposition n’autorise aucun changement tant qu’elle n’est pas acceptée par les validateurs nommés.

## Index

| ID | Décision | Statut | Responsable | Échéance |
|---|---|---|---|---|
| MLK-DEC-001 | Exécuter la Phase 0 avant les optimisations visibles | Proposée | À nommer | Avant démarrage |
| MLK-DEC-002 | Une seule phase active à la fois | Proposée | À nommer | Avant démarrage |
| MLK-DEC-003 | Isoler `plan_scans` sur une file et un worker dédiés | Acceptée | Technique / exploitation | Phase 0 |
| MLK-DEC-004 | Ajouter et exploiter le workload `social_publish` | Acceptée | Technique / exploitation | Phase 0 |
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

- Statut : **acceptée**.
- Date : 2026-07-27.
- Options considérées : conserver `default` et aligner le job, ou utiliser une file `plan-scans` avec un profil de worker dédié.
- Décision : retenir la file dédiée `plan-scans`. Le profil de développement la consomme avec les autres workloads ; en production, le profil `plan-scans` la consomme seul.
- Raisons : le pipeline peut approcher 240 secondes et ne doit pas bloquer les notifications, travaux ou jobs implicites de `default`. L’isolation permet aussi de dimensionner et superviser ce traitement long séparément.
- Délais : timeout worker configuré de 240 secondes pour ce profil, backoff du job de 60 secondes et `retry_after` de 300 secondes pour les connexions base de données, Redis ou Beanstalkd. La visibilité SQS doit être strictement supérieure au timeout maximal et reste un contrôle externe explicite.
- Transition : les profils `development` et `operations` continuent de consommer `default` afin de traiter les jobs historiques déposés avant le changement de routage.
- Priorité : les workloads explicites précèdent `default` afin que le trafic historique ou implicite n’affame pas les files métier.
- Garde-fou : `queue:workload-audit` doit prouver l’assignation statique, mais l’exploitation doit encore vérifier les processus réels et exécuter un canari contrôlé avant de déclarer la production validée.
- Déploiement : préprovisionner les consommateurs compatibles ou maintenir le trafic pendant la bascule ; aucun producteur ne doit écrire sur la nouvelle file avant que son consommateur soit actif.
- Rollback : restaurer temporairement le routage vers `default`, conserver une release ou une commande brute de drainage et `retry_after=300` jusqu’au vidage des files, puis seulement retirer les nouveaux consommateurs. Aucun job déjà déposé sur `plan-scans` ne doit être abandonné.

## MLK-DEC-004 — Workload `social_publish`

- Statut : **acceptée**.
- Date : 2026-07-27.
- Décision : ajouter le workload canonique `social_publish`, résolu par défaut vers `social-publish`, avec backoff `[30, 120, 300]`.
- Consommation : le profil `development` le consomme localement ; le profil de production `social` consomme `social_automation` et `social_publish`.
- Raisons : supprimer le fallback non déclaré, rendre l’inventaire vérifiable et isoler la publication externe des files opérationnelles et des campagnes.
- Politique d’erreur : une erreur fournisseur déjà transformée en échec métier n’est pas relancée automatiquement, afin d’éviter une publication en double ; seuls les défauts d’infrastructure ou de persistance non absorbés restent éligibles aux retries du job.
- Priorité : `social-publish` est sondée avant `social-automation` pour terminer les publications déjà promises avant de générer de nouveaux candidats.
- Garde-fou : aucun canal public réel ne sert de canari sans approbation. Utiliser une cible de test ou une publication privée contrôlée, puis confirmer la consommation et l’absence d’échec inattendu.
- Validation restante : la configuration et l’activité des workers de production ne sont pas considérées comme vérifiées avant déploiement, contrôle du gestionnaire de processus et canari consigné.
- Rollback : conserver le consommateur `social-publish` tant que sa file contient des jobs, même si le producteur revient à la version précédente.

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
