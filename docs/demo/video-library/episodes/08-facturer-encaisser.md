# G08 — Facturer et encaisser un service

Dernière mise à jour : 2026-08-11<br>
Niveau : intermédiaire<br>
Public : propriétaire, réception et finance<br>
Durée du master pédagogique : 10 à 13 minutes<br>
Durée de la capsule dérivée : 4 à 5 minutes

## État réel de production

| Élément | État | Preuve |
| --- | --- | --- |
| File, checkout, facture, paiement et pourboire | Audités | Reservation Index, checkout service, Invoice Show et tests |
| Preuve canonique comptant | Provisionnée | Marie + Coupe femme + Karim + facture payée |
| Script détaillé | Prêt | [Scénario de tournage](08-facturer-encaisser/scenario-detaille.md) |
| Plan des captures | Prêt | [Shot-list G08](08-facturer-encaisser/shot-list.csv) |
| PNG de l'interface | À produire | [Galerie G08](../captures/G08/README.md) |
| Stripe réel | Hors de ce master | Validation E2E séparée |
| QA finale | En attente des captures | [Checklist G08](08-facturer-encaisser/qa.md) |

## Question et résultat promis

**Question :** comment clôturer un service, enregistrer un paiement comptant avec pourboire et vérifier toute la trace financière ?

À la fin, le ticket est terminé, la facture est payée, son solde vaut zéro, le paiement comptant est visible, le pourboire est attribué à Karim et le reçu est consultable.

## Objectifs pédagogiques observables

Après la vidéo, la personne doit pouvoir :

1. distinguer `En service`, `À encaisser` et `Terminé` ;
2. lire sous-total, taxes, total de facture et total à encaisser ;
3. choisir un moyen de paiement réellement autorisé ;
4. ajouter un pourboire en comprenant sa base de calcul ;
5. éviter un envoi de reçu non préparé ;
6. vérifier la facture, le paiement, le bénéficiaire du pourboire et le solde ;
7. distinguer un paiement manuel comptant d'un checkout Stripe.

## Situation métier

Marie Lefebvre termine une Coupe femme + brushing réalisée par Karim Benali. La réception encaisse en espèces, ajoute un pourboire de 18 % et ouvre ensuite la facture et le reçu.

| Avant | Après |
| --- | --- |
| Ticket en service ou à encaisser, aucune preuve de règlement. | Ticket terminé, facture payée, paiement et pourboire traçables. |

## Deux modes de tournage à ne pas mélanger

| Mode | Utilisation | Limite |
| --- | --- | --- |
| Rejeu dans un clone jetable | Montrer les clics de la file jusqu'au succès | Crée réellement une nouvelle facture et un paiement local dans ce clone. |
| Preuve canonique en lecture seule | Montrer la chaîne déjà provisionnée | Le ticket seedé est déjà terminé ; il ne peut pas servir de plan `À encaisser`. |

Le preset contient déjà une preuve finale portée par le ticket dont le numéro de file est `SAL-ECLAT-PAID-001`. Ce n'est pas le numéro de la facture, qui commence par `I` et doit être relevé directement dans **Factures** pendant le prévol. L'interface ne propose actuellement aucun lien ticket terminé → facture. Pour filmer le formulaire et le clic final, préparer **un autre rendez-vous Marie + même prestation + Karim** dans un clone, puis le faire progresser normalement. Ne jamais prétendre que le ticket canonique terminé est encore à encaisser.

## Données et réconciliation

| Élément | Valeur de référence | Signification |
| --- | --- | --- |
| Cliente | Marie Lefebvre | Cliente existante du preset. |
| Prestation | Coupe femme + brushing | Prix de base 65,00 CAD. |
| Employé | Karim Benali | Bénéficiaire du pourboire. |
| Sous-total | 65,00 CAD | Base du pourboire. |
| Taxes | 9,73 CAD | Taxes arrondies affichées. |
| Total de facture | 74,73 CAD | Montant appliqué au solde de facture. |
| Moyen | Espèces/comptant | Paiement manuel local, fournisseur `manual`. |
| Pourboire | 18 % = 11,70 CAD | Calculé sur 65,00, pas sur 74,73. |
| Total encaissé | 86,43 CAD | 74,73 + 11,70. |
| Montant payé de la facture | 74,73 CAD | Le pourboire n'éteint pas davantage le solde. |
| Solde dû | 0,00 CAD | Facture payée. |
| Référence du rejeu | DEMO-ECLAT-CASH-G08-001 | Valeur optionnelle et fictive. |
| Envoi du reçu | Ne pas envoyer maintenant | Empêche email/SMS pendant la prise. |

## Parcours de tournage

| Temps | Captures | Action | Preuve attendue |
| --- | --- | --- | --- |
| 00:00–00:45 | G08-S01 à S03 | Montrer le ticket en service, terminer la prestation et atteindre À encaisser. | Transition métier réelle. |
| 00:45–01:40 | G08-S04 à S05 | Ouvrir l'encaissement ; contrôler cliente, service, employé et montants. | 65,00 + 9,73 = 74,73. |
| 01:40–02:25 | G08-S06 | Choisir Espèces et aucun envoi de reçu. | Aucun fournisseur externe ni notification. |
| 02:25–03:30 | G08-S07 à S08 | Activer le pourboire, choisir pourcentage et saisir 18. | Pourboire 11,70 ; total 86,43. |
| 03:30–04:15 | G08-S09 à S10 | Ajouter référence/note et relire les quatre montants. | Décision finale cohérente. |
| 04:15–05:00 | G08-S11 | Cliquer Encaisser et terminer dans le clone. | Succès, ticket terminé, lien reçu. |
| 05:00–06:00 | G08-S12 à S13 | Ouvrir Factures, retrouver la facture et son statut. | Facture liée à Marie, payée. |
| 06:00–07:10 | G08-S14 | Réconcilier sous-total, taxes, montant payé, pourboire, total encaissé et solde. | Aucun écart entre calcul et écran. |
| 07:10–08:10 | G08-S15 | Examiner le bloc Paiements. | Espèces, statut réglé, Karim bénéficiaire. |
| 08:10–09:00 | G08-S16 | Cliquer Télécharger PDF, puis ouvrir le fichier téléchargé dans un lecteur propre. | Reçu téléchargé et lisible sans envoi. |
| 09:00–09:45 | G08-S17 | Ouvrir la vue Pourboires propriétaire. | Allocation nette visible si le rejeu l'a créée. |
| 09:45–11:30 | G08-S18 | Montrer la preuve canonique si le rejeu n'est pas disponible et expliquer Stripe séparément. | Frontière de vérité explicite. |

## Subtilités essentielles

1. **Le pourboire est calculé sur le sous-total de 65,00**, pas sur le total avec taxes.
2. **La facture reçoit 74,73 comme montant payé** ; le pourboire de 11,70 reste séparé. Le total réellement encaissé est 86,43.
3. **Choisir Espèces clôture localement le paiement** avec un fournisseur manuel. Ce n'est ni un débit bancaire ni une transaction Stripe.
4. **Carte change le parcours** : le bouton devient Continuer vers Stripe et le ticket ne doit être terminé qu'après confirmation réelle.
5. **Le reçu téléchargeable n'implique pas qu'il a été envoyé.** G08 choisit « Ne pas envoyer maintenant » ; l'application force le téléchargement du PDF.
6. **Un paiement déjà existant protège contre le double encaissement.** Le service renvoie la preuve existante ou refuse une confirmation déjà en attente.
7. **Un service gratuit peut se terminer sans modale de paiement.** Ne pas l'utiliser pour expliquer une facture payée.

## Périmètre

Le master couvre un paiement comptant complet, le pourboire, la facture, le reçu et l'allocation. Il ne couvre pas remboursement, annulation de facture, paiement partiel, inversion de pourboire, envoi email/SMS, dépôt, no-show ou Stripe. Chacun nécessite sa propre preuve.

## Version courte dérivée

La capsule conserve S03–S05, S06, S08, S10–S11 et S14–S16. Elle montre le calcul, l'encaissement comptant et le reçu téléchargé, puis renvoie au master pour les états, erreurs et frontières Stripe.

## Dossier de production

- [Scénario détaillé](08-facturer-encaisser/scenario-detaille.md)
- [Guide des montants et états](08-facturer-encaisser/guide-flux.md)
- [Variantes et erreurs](08-facturer-encaisser/variantes-erreurs.md)
- [Shot-list CSV](08-facturer-encaisser/shot-list.csv)
- [QA G08](08-facturer-encaisser/qa.md)
- [Galerie des captures](../captures/G08/README.md)
- [Audit de couverture Salon Éclat](../../../audits/demo-salon/2026-08-07-salon-eclat-demo-coverage.md)

## Références croisées

- Avant : `G05 — Créer une réservation`
- Réutilisé par : `M01 — Démo Salon Éclat`, acte Encaisser.
- Approfondissement : [script long Factures et paiements](../../module-invoices-paiements-demo-20min.md)
