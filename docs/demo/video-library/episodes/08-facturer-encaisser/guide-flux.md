# G08 — Guide des états, montants et preuves

Dernière mise à jour : 2026-08-11<br>
Route principale : `/app/reservations`

## États de la file utiles au checkout

| État | Libellé | Sens | Action suivante |
| --- | --- | --- | --- |
| `in_service` | En service | Prestation commencée | Terminer ou Encaisser et terminer. |
| `awaiting_payment` | À encaisser | Prestation finie, règlement absent | Ouvrir Encaisser. |
| `done` | Terminé | Ticket clôturé | Consulter facture/reçu. |

Depuis `called` ou `in_service`, l'ouverture de l'encaissement peut terminer la prestation et préparer automatiquement l'état À encaisser. Un montant nul peut terminer le ticket sans paiement.

## Formulaire d'encaissement

| Zone | Règle réelle | Choix G08 |
| --- | --- | --- |
| Cliente/service/employé | Données du ticket | Marie / Coupe femme + brushing / Karim. |
| Sous-total | Prix hors taxes utilisé par la prestation | 65,00 CAD. |
| Taxes | Calcul du résumé checkout | 9,73 CAD. |
| Total de facture | Sous-total + taxes | 74,73 CAD. |
| Moyen de paiement | Doit être autorisé par le compte | Espèces. |
| Envoi reçu | Vide, email ou SMS | Vide : Ne pas envoyer maintenant. |
| Pourboire | Aucun, pourcentage ou montant fixe | Pourcentage 18 %. |
| Référence | Optionnelle, 255 caractères max. | Référence DEMO. |
| Note | Optionnelle, 2 000 caractères max. | Mention aucun débit externe. |

Les moyens affichables sont Espèces, Carte, Virement bancaire et Chèque, mais la liste réelle vient des réglages du compte. Le master ne doit montrer que les moyens effectivement autorisés.

## Calcul du pourboire

```text
Sous-total de prestation    65,00
Pourboire 18 %              11,70
Taxes                        9,73
Total facture               74,73
Total encaissé              86,43
```

Le calcul du pourboire utilise `65,00 × 18 %`, et non `74,73 × 18 %`. Les valeurs maximum viennent des réglages : l'interface utilise par défaut 30 % et 200 CAD si le serveur n'en fournit pas.

## Effets d'un paiement manuel réussi

Dans une transaction locale cohérente, l'application :

1. crée ou retrouve la facture du ticket ;
2. crée un paiement au montant de facture ;
3. enregistre séparément type, pourcentage, base et montant du pourboire ;
4. attribue le pourboire au membre résolu depuis la facture ;
5. rafraîchit le statut et le solde de la facture ;
6. fait passer le ticket à Terminé ;
7. consigne une activité ;
8. prépare ou livre le reçu selon l'option choisie.

Le paiement manuel utilise le fournisseur `manual` et un statut réglé. Un paiement déjà existant n'est pas dupliqué silencieusement.

## Facture et paiement

| Valeur sur la facture | Inclut le pourboire ? | Référence G08 |
| --- | --- | --- |
| Sous-total | Non | 65,00 |
| Taxes | Non | 9,73 |
| Total facture | Non | 74,73 |
| Montant payé | Non | 74,73 |
| Pourboires | Oui, ligne séparée | 11,70 |
| Total encaissé | Oui | 86,43 |
| Solde dû | Non | 0,00 |

Le bloc Paiements peut afficher le moyen, le statut, le montant de paiement, le pourboire, le total payé/encaissé et le bénéficiaire.

## Reçu

`Ne pas envoyer maintenant` laisse `receipt_delivery` vide, mais l'API peut tout de même retourner l'URL du PDF. Email et SMS déclenchent un mécanisme de livraison après confirmation ; ces branches ne sont pas validées dans G08.

## Frontière Stripe

Avec Carte :

- le moyen normalisé devient Stripe ;
- une facture et une tentative de checkout sont préparées ;
- le navigateur est envoyé sur une URL externe ;
- le ticket reste à encaisser tant que la tentative n'est pas confirmée ;
- retour, polling et annulation possèdent des états distincts.

Il est interdit de couper directement du bouton Carte vers une facture payée sans preuve du checkout test complet.

## Sources de vérité

- `resources/js/Pages/Reservation/Index.vue`
- `resources/js/i18n/modules/fr/reservations.json`
- `app/Http/Controllers/Reservation/StaffReservationController.php`
- `app/Services/ReservationQueueCheckoutService.php`
- `resources/js/Pages/Invoice/Show.vue`
- `app/Services/Demo/DemoWorkspaceProvisioner.php`
- `tests/Feature/InvoiceTipsTest.php`
- `tests/Feature/DemoSalonEclatDatasetTest.php`
