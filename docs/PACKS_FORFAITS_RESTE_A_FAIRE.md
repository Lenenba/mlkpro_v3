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
- tentative de paiement Stripe automatique sur facture de renouvellement quand
  un client Stripe et un moyen de paiement reutilisable sont lies au client
- renouvellement apres paiement d une facture de renouvellement
- suspension automatique si la facture de renouvellement reste impayee apres delai de grace
- fallback facture a payer quand le paiement automatique Stripe n est pas
  disponible ou echoue
- journal des tentatives Stripe automatiques dans l activite client et les
  metadonnees du forfait
- report optionnel du reliquat non consomme vers la nouvelle periode
- portail client Mes forfaits avec soldes, reliquats, factures liees,
  consommations recentes et demandes client
- annulation recurrente en fin de periode ou immediate avec raison obligatoire
- upgrade et downgrade admin vers une autre offre recurrente, sans prorata
  automatique
- relances client configurees par delai pour factures de renouvellement impayees
- notifications client quand un forfait recurrent est suspendu puis repris
  apres paiement
- evenements marketing forfaits et segments clients bases sur solde,
  expiration, recurrence et suspension

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

Statut: livre en premiere passe le 2026-05-12.

But:

- tenter automatiquement le paiement de renouvellement quand un moyen de paiement
  Stripe est disponible.

Livre:

- liaison forfait recurrent, facture de renouvellement et moyen de paiement
- stockage du client Stripe et du moyen de paiement reutilisable sur le client
  final
- sauvegarde possible du moyen de paiement depuis Stripe Checkout pour les
  factures de forfait recurrent
- tentative de paiement Stripe off-session sur les renouvellements dus
- fallback facture a payer si aucun moyen de paiement automatique n existe
- journal des tentatives et erreurs de paiement
- synchronisation facture, paiement et statut de forfait

Reste possible en amelioration:

- interface admin dediee pour verifier ou remplacer le moyen de paiement lie
- relance automatique client apres echec de paiement
- nouvelle tentative programmee apres mise a jour du moyen de paiement

### 3.2 Portail client forfaits

Statut: livre en premiere passe le 2026-05-12.

But:

- permettre au client final de suivre ses forfaits sans contacter l entreprise.

Livre:

- liste des forfaits actifs, consommes, expires, annules et suspendus
- solde restant et quantite initiale
- reliquat reporte quand applicable
- expiration et prochaine date de renouvellement
- historique des consommations
- factures liees au forfait
- demande de renouvellement
- demande d annulation selon les regles de l entreprise

Reste possible en amelioration:

- notifications automatiques vers l equipe quand une demande client est creee
- workflow admin dedie pour accepter ou refuser une demande client

### 3.3 Annulation, upgrade et downgrade recurrents

Statut: livre en premiere passe le 2026-05-12.

But:

- modifier ou fermer proprement un forfait recurrent.

Livre:

- annulation en fin de periode
- annulation immediate admin avec raison obligatoire
- upgrade vers une autre offre recurrente
- downgrade vers une autre offre recurrente
- annulation de la facture de renouvellement en attente quand le forfait est
  annule ou remplace
- interface admin depuis la fiche client pour programmer le changement ou
  l annulation
- historique dans les metadonnees et l activite CRM
- premiere version sans prorata automatique

Reste possible en amelioration:

- prorata automatique calcule selon la valeur restante de la periode
- confirmation client ou notification email lors d un changement programme
- workflow dedie pour transformer une demande du portail client en action admin

### 3.4 Relances avancees

Statut: livre en premiere passe le 2026-05-12.

But:

- rendre les retards et reprises de paiement plus lisibles.

Livre:

- notifications client pour facture de renouvellement impayee
- notification client quand le forfait est suspendu
- notification client quand le forfait reprend apres paiement
- preferences de communication respectees
- relances configurees par delai

Reste possible en amelioration:

- canaux SMS et WhatsApp pour les relances de factures impayees
- ecran dedie de suivi des relances envoyees par forfait
- modeles de message personnalisables par entreprise

### 3.5 Marketing et segmentation

Statut: livre en premiere passe le 2026-05-12.

But:

- utiliser les forfaits comme declencheurs de retention.

Livre:

- evenement forfait achete
- evenement solde bas
- evenement expiration proche
- evenement forfait expire
- evenement forfait renouvele
- evenement forfait suspendu
- segments clients bases sur forfait actif, solde, expiration, recurrence et suspension

Details:

- les evenements sont enregistres dans `customer_behavior_events`
- les segments marketing peuvent cibler les evenements via `behavior_event`
- les segments clients et audiences marketing peuvent filtrer par statut de
  forfait, solde restant, expiration proche, recurrence et suspension
- la liste clients expose ces filtres pour pouvoir sauvegarder des segments CRM

Reste possible en amelioration:

- recettes de campagnes preconfigurees pour relancer automatiquement les clients
  a solde bas ou suspendus
- templates marketing dedies aux forfaits recurrents
- tableau de bord retention par forfait

### 3.6 Reporting et QA finale

Statut: livre en premiere passe le 2026-05-12.

But:

- fermer l epique avec des indicateurs fiables et testables.

Livre:

- rapport packs vendus
- rapport forfaits consommables vendus
- rapport forfaits recurrents actifs, suspendus, annules, expires et renouveles
- rapport solde reporte
- tests echec paiement et reprise
- tests portail client
- tests annulation, upgrade et downgrade
- tests declencheurs marketing
- verification de non-regression V1, V2 et V3

Details:

- le catalogue packs et forfaits expose un reporting consolide sur les ventes,
  les meilleurs revenus, les forfaits recurrents et le solde reporte
- les packs vendus sont calcules depuis les lignes de facture non annulees
  contenant les metadonnees `offer_package_*`
- les forfaits consommables et recurrents sont consolides depuis
  `customer_packages`
- le solde reporte additionne les quantites `recurrence.carried_over_quantity`
  et les droits restants associes
- la QA couvre les suites catalogue, vente, consommation, automatisation,
  recurrence, portail client, relances, reprise apres paiement, annulation,
  upgrade, marketing et segmentation

Reste possible en amelioration:

- export CSV ou PDF du reporting
- filtres de periode sur les rapports
- graphique mensuel des renouvellements et suspensions

## 4. Ordre recommande

1. Portail client forfaits, car il rend la valeur visible au client.
2. Stripe automatique, car le renouvellement facture existe deja. (livre en
   premiere passe)
3. Annulation, upgrade et downgrade recurrents. (livre en premiere passe)
4. Relances client avancees. (livre en premiere passe)
5. Marketing et segmentation. (livre en premiere passe)
6. Reporting final et QA. (livre en premiere passe)
