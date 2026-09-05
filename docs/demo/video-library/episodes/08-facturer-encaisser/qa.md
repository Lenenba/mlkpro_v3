# G08 — Checklist de validation

Dernière mise à jour : 2026-08-11

## Prévol

- [ ] Clone Salon Éclat jetable, distinct du workspace partagé.
- [ ] Ticket de rejeu créé par l'interface et réellement En service.
- [ ] Marie, Coupe femme + brushing et Karim cohérents.
- [ ] Espèces autorisé dans les moyens du compte.
- [ ] Aucun envoi de reçu sélectionné.
- [ ] Preuve canonique seedée intacte.

## États et checkout

- [ ] En service, À encaisser et Terminé sont montrés séparément.
- [ ] Le ticket canonique terminé n'est jamais présenté comme À encaisser.
- [ ] Le contexte de la modale correspond au ticket.
- [ ] Sous-total 65,00 + taxes 9,73 = facture 74,73.
- [ ] Pourboire 18 % de 65,00 = 11,70.
- [ ] Total encaissé 74,73 + 11,70 = 86,43.
- [ ] Moyen Espèces et reçu « Ne pas envoyer maintenant » visibles.
- [ ] Référence et note ne contiennent aucune donnée réelle.
- [ ] Le succès provient d'une vraie réponse du clone.
- [ ] L'heure exacte du succès est notée pour retrouver la nouvelle facture ; aucun numéro de facture n'est prétendu visible dans ce message.

## Facture et paiement

- [ ] La facture ouverte est celle du rejeu ou porte un cartouche canonique explicite.
- [ ] Statut Payée et solde 0,00 visibles.
- [ ] Montant payé de facture = 74,73, sans pourboire.
- [ ] Pourboire = 11,70 et total encaissé = 86,43.
- [ ] Bloc Paiements : Espèces, statut réglé et bénéficiaire Karim.
- [ ] Le PDF téléchargé correspond au même numéro de facture et a été ouvert dans un lecteur propre.
- [ ] L'allocation Pourboires correspond au même paiement si S17 est publiée.

## Frontières et confidentialité

- [ ] Le mot Stripe n'est jamais associé au paiement Espèces.
- [ ] Aucun plan Carte sans checkout test complet.
- [ ] Aucun identifiant fournisseur, session, cookie ou URL signée.
- [ ] Aucun email/SMS envoyé pendant la prise.
- [ ] G08-S16 ne prétend pas ouvrir une page inline : le téléchargement a réellement eu lieu et aucun dialogue système, fichier récent ou chemin local n'est visible.
- [ ] G08-S18 affiche le vrai numéro de facture `I…` relevé dans Factures pendant le prévol ; `SAL-ECLAT-PAID-001` n'est utilisé que pour identifier le ticket canonique et aucun lien UI ticket → facture n'est prétendu.
- [ ] Les captures de rejeu et de preuve canonique ne sont pas mélangées sans label.

## Média

- [ ] G08-S01 à S16 validées ; S17/S18 annexes clairement gérées.
- [ ] Chaque fichier porte le nom canonique et le bon statut.
- [ ] Les montants restent lisibles à la résolution d'export.
- [ ] L'équation ajoutée au montage ne masque pas les valeurs sources.
- [ ] Sous-titres et narration emploient exactement les nombres visibles.

## Critère de sortie

G08 est publiable lorsque la chaîne ticket → checkout → facture → paiement → reçu est reliée par les mêmes cliente, service, membre et montants, et lorsqu'aucun spectateur ne peut confondre le paiement manuel avec Stripe.
