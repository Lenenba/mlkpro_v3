# Phase 3 — Expérience utilisateur premium

- Dernière mise à jour : 2026-07-16
- Statut : **en attente**
- Responsable : à nommer
- Validateurs : produit, design, support et utilisateurs pilotes à nommer
- Dépendances : Phases 1 et 2 terminées
- Risque de phase : moyen

## Objectif

Faire percevoir la profondeur de MLK Pro sans exposer toute sa complexité, en conservant les workflows métier déjà appris.

## Hypothèse produit

La base visuelle est distinctive. Le gain principal viendra d’une meilleure hiérarchie, de preuves produit réelles, d’actions quotidiennes par rôle et d’une divulgation progressive des détails.

## Scope

### MLK-IMP-P3-001 — Accueil et centre d’actions par rôle

- Statut : **en attente**
- Rôles pilotes : propriétaire, comptable, employé/terrain et approbateur.
- Actions : à facturer, à relancer, à approuver, à planifier et anomalies à vérifier.
- Critères : moins de clics/temps sur les cinq tâches clés ; mêmes routes et permissions ; chaque carte explique son origine.

### MLK-IMP-P3-002 — Système d’expérience cohérent

- Statut : **en attente**
- Livrables : composants partagés pour tableaux, filtres, états vides, chargements, erreurs, commandes, confirmations et raccourcis.
- Critères : inventaire des variantes réduit ; contrastes/clavier validés ; adoption progressive sans big-bang.

### MLK-IMP-P3-003 — Preuves produit du site public

- Statut : **en attente**
- Livrables : écrans réels annotés, courtes démonstrations, résultats clients vérifiables, CTA cohérents et localisation complète.
- Critères : aucune zone média vide ; chaque promesse renvoie à une preuve ou une démo ; performance publique maintenue.

### MLK-IMP-P3-004 — Mobile orienté tâche

- Statut : **en attente**
- Parcours : reçu/photo, temps, devis/facture, signature, paiement, statut et approbation.
- Critères : tâches principales réalisables d’une main, reprise après interruption, erreurs récupérables, appareils réels testés.

### MLK-IMP-P3-005 — Accessibilité et localisation

- Statut : **en attente**
- Critères : clavier, lecteur d’écran, focus, contraste, zoom, messages d’erreur et français/anglais/espagnol validés sur les parcours pilotes.

### MLK-IMP-P3-006 — Validation utilisateur contrôlée

- Statut : **en attente**
- Méthode : scénarios identiques avant/après avec utilisateurs pilotes représentatifs.
- Mesures : temps, taux de réussite, erreurs, demandes d’aide et satisfaction qualitative.
- Gate : aucun déploiement général si un workflow critique régresse, même si l’interface est préférée visuellement.

## Hors-scope

Réécriture des règles métier, remplacement complet de la navigation, suppression de fonctions avancées ou automatisation IA irréversible.

## Rollout

Activer par rôle, entreprise pilote et module. Conserver l’ancienne présentation derrière un drapeau pendant le canari. Les données et actions sous-jacentes restent communes aux deux expériences.

## Gate de sortie

- [ ] cinq parcours clés plus rapides ou plus fiables ;
- [ ] aucune régression de permission ou de résultat ;
- [ ] desktop et mobile validés ;
- [ ] accessibilité et langues validées ;
- [ ] support et pilotes approuvent le déploiement ;
- [ ] preuves produit publiques exactes et performantes ;
- [ ] rollback d’expérience testé.

## Definition of Done

La Phase 3 est terminée lorsque les utilisateurs trouvent plus vite ce qu’ils doivent faire, accomplissent les tâches prioritaires avec moins d’erreurs et conservent l’accès aux fonctions avancées sans surcharge initiale.

## Documents liés

- [Cockpit](README.md)
- [Phase 2](PHASE_2_DATA_AND_RUNTIME_PERFORMANCE.md)
- [Journal de validation](VALIDATION_LOG.md)
