# Phase 4 — Différenciation produit

- Dernière mise à jour : 2026-08-04
- Statut : **en attente**
- Responsable : à nommer
- Validateurs : direction produit, finance/comptabilité, sécurité et pilotes à nommer
- Dépendance : Phase 3 terminée
- Risque de phase : élevé
- Vue maître et état courant : [suivi global des Phases 0 à 4](SUIVI_GLOBAL.md)

## Objectif

Construire l’avantage difficile à copier de MLK Pro : les opérations terrain et commerciales produisent automatiquement une donnée financière traçable, révisable et exploitable par l’entreprise et son comptable.

## Positionnement à valider

« De la demande client à l’encaissement et à la comptabilité, un seul système bilingue conçu pour les PME canadiennes de services. »

Cette proposition doit être validée par entretiens et pilotes avant tout investissement majeur.

## Scope exploratoire contrôlé

### MLK-IMP-P4-001 — Segment d’entrée et offre

- Statut : **en attente**
- Décision : choisir un segment prioritaire et ses cinq workflows critiques.
- Critères : problème fréquent, valeur mesurable, budget, intégrations et conformité compris.

### MLK-IMP-P4-002 — Chaîne opération → écriture

- Statut : **en attente**
- But : relier devis, travail, temps/dépenses, facture, paiement et écriture sans double saisie.
- Critères : règles explicites, prévisualisation, idempotence, correction, annulation et piste d’audit.

### MLK-IMP-P4-003 — Espace de collaboration comptable

- Statut : **en attente**
- Livrables : rôles externes, demandes de pièces, commentaires, revue, clôture et historique.
- Critères : séparation des entreprises, permissions minimales et journal complet.

### MLK-IMP-P4-004 — Banque, documents et rapprochement

- Statut : **en attente**
- Livrables possibles : flux bancaires, OCR avec confiance, brouillon, approbation, paiement et rapprochement.
- Critères : fournisseur et couverture canadienne validés ; erreurs récupérables ; aucune écriture silencieuse.

### MLK-IMP-P4-005 — IA explicable et contrôlable

- Statut : **en attente**
- Exigences : contexte et sources visibles, aperçu du diff, approbation, permissions, journal, annulation et mesure de confiance.
- Interdiction : action financière irréversible ou envoi externe sans validation conforme à la politique décidée.

### MLK-IMP-P4-006 — Écosystème ouvert

- Statut : **en attente**
- Livrables : API documentée, webhooks, OAuth par scopes, sandbox, quotas et éventuellement MCP après revue de sécurité.
- Critères : idempotence, rotation des secrets, audit, versionnement et compatibilité documentée.

### MLK-IMP-P4-007 — Centre de confiance

- Statut : **en attente**
- Contenu : résidence des données, sauvegarde/reprise, disponibilité, chiffrement, permissions, audit et traitement des incidents.
- Critères : chaque affirmation possède une preuve opérationnelle et un propriétaire.

### MLK-IMP-P4-008 — Pilote et décision d’investissement

- Statut : **en attente**
- Méthode : petit nombre d’entreprises pilotes, métriques avant/après, support rapproché et option de retrait.
- Gate : élargir uniquement si l’automatisation réduit le travail sans diminuer la confiance ni la correction comptable.

## Hors-scope sans décision séparée

- remplacement immédiat de QuickBooks/Xero/Sage pour tous les clients ;
- promesse fiscale ou bancaire non validée ;
- expansion géographique avant conformité ;
- IA autonome sans contrôle ;
- marketplace publique sans modèle de sécurité et de support.

## Gate de sortie

- [ ] segment et proposition validés par des pilotes ;
- [ ] chaîne opération-finance auditée et réversible ;
- [ ] collaboration comptable et permissions validées ;
- [ ] intégrations canadiennes contractualisées et testées ;
- [ ] IA prévisible, explicable, approuvable et annulable ;
- [ ] API/webhooks sécurisés et versionnés ;
- [ ] mesures de valeur et de confiance atteintes ;
- [ ] décision d’investissement/extension consignée.

## Definition of Done

La Phase 4 est terminée lorsqu’un pilote démontre que MLK Pro réduit réellement les doubles saisies et les délais entre opérations et finance, sans compromettre exactitude, permissions, auditabilité ou confiance.

## Documents liés

- [Cockpit](README.md)
- [Phase 3](PHASE_3_PREMIUM_USER_EXPERIENCE.md)
- [Décisions](DECISIONS.md)
- [Journal de validation](VALIDATION_LOG.md)
