# Packs et forfaits - reste a faire

Date de suivi: 2026-05-12

Ce document resume ce qui reste a livrer apres le socle actuel des packs,
forfaits client, reservations, recurrence et factures de renouvellement.

## 1. Etat actuel livre

- catalogue interne des packs et forfaits
- vente via devis et factures
- attribution manuelle d un forfait a un client
- consommation manuelle
- consommation automatique via reservation terminee
- restauration du solde quand une reservation terminee est annulee ou change de statut
- expiration automatique
- alertes internes de solde bas et expiration proche
- forfaits recurrents mensuels, trimestriels et annuels
- renouvellement manuel
- facture de renouvellement manuelle
- facture de renouvellement automatique via `offer-packages:automation`
- renouvellement apres paiement d une facture de renouvellement
- suspension automatique si la facture de renouvellement reste impayee apres delai de grace
- report optionnel du reliquat non consomme vers la nouvelle periode

## 2. Regle de reliquat recurrent

Objectif:

- permettre a une entreprise de vendre un forfait mensuel avec avantages qui se
  renouvellent chaque mois sans perdre automatiquement les avantages non utilises
  du mois precedent.

Exemple:

- le client a un forfait mensuel de 4 visites
- a la fin du mois, il lui reste 2 visites
- au renouvellement, le nouveau mois ajoute 4 visites
- le nouveau solde disponible devient 6 visites

Regle actuelle implementee:

- le report est optionnel par offre recurrente avec `carry_over_unused_balance`
- si le report est actif, le renouvellement calcule:
  `nouvelle quantite = allocation de la periode + reliquat restant`
- la nouvelle periode garde:
  - `period_allocation_quantity`
  - `carried_over_quantity`
  - `carry_over_unused_balance`
  - `renewed_from_remaining_quantity`
- l ancienne periode est fermee mais conserve la trace du solde reporte

Limites volontaires pour cette premiere version:

- pas encore de plafond de report
- pas encore de date d expiration separee pour le reliquat
- pas encore de prorata automatique
- pas encore de regle differente par produit inclus

## 3. Reste a livrer pour fermer la V3

### 3.1 Paiement automatique Stripe

But:

- tenter automatiquement le paiement de renouvellement quand un moyen de paiement
  Stripe est disponible.

Livrables:

- liaison forfait recurrent, facture de renouvellement et moyen de paiement
- tentative de paiement Stripe sur les renouvellements dus
- fallback facture a payer si aucun moyen de paiement automatique n existe
- journal des tentatives et erreurs de paiement
- synchronisation facture, paiement et statut de forfait

### 3.2 Portail client forfaits

But:

- permettre au client final de suivre ses forfaits sans contacter l entreprise.

Livrables:

- liste des forfaits actifs, consommes, expires, annules et suspendus
- solde restant et quantite initiale
- reliquat reporte quand applicable
- expiration et prochaine date de renouvellement
- historique des consommations
- factures liees au forfait
- demande de renouvellement
- demande d annulation selon les regles de l entreprise

### 3.3 Annulation, upgrade et downgrade recurrents

But:

- modifier ou fermer proprement un forfait recurrent.

Livrables:

- annulation en fin de periode
- annulation immediate admin avec raison obligatoire
- upgrade vers une autre offre recurrente
- downgrade vers une autre offre recurrente
- historique dans les metadonnees et l activite CRM
- premiere version sans prorata automatique

### 3.4 Relances avancees

But:

- rendre les retards et reprises de paiement plus lisibles.

Livrables:

- notifications client pour facture de renouvellement impayee
- notification client quand le forfait est suspendu
- notification client quand le forfait reprend apres paiement
- preferences de communication respectees
- relances configurees par delai

### 3.5 Marketing et segmentation

But:

- utiliser les forfaits comme declencheurs de retention.

Livrables:

- evenement forfait achete
- evenement solde bas
- evenement expiration proche
- evenement forfait expire
- evenement forfait renouvele
- evenement forfait suspendu
- segments clients bases sur forfait actif, solde, expiration, recurrence et suspension

### 3.6 Reporting et QA finale

But:

- fermer l epique avec des indicateurs fiables et testables.

Livrables:

- rapport packs vendus
- rapport forfaits consommables vendus
- rapport forfaits recurrents actifs, suspendus, annules, expires et renouveles
- rapport solde reporte
- tests echec paiement et reprise
- tests portail client
- tests annulation, upgrade et downgrade
- tests declencheurs marketing
- verification de non-regression V1, V2 et V3

## 4. Ordre recommande

1. Portail client forfaits, car il rend la valeur visible au client.
2. Stripe automatique, car le renouvellement facture existe deja.
3. Annulation, upgrade et downgrade recurrents.
4. Relances client avancees.
5. Marketing, segmentation et reporting final.
