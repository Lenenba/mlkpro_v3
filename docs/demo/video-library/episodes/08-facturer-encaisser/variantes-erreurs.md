# G08 — Variantes, erreurs et récupération

Dernière mise à jour : 2026-08-11

## Arbre de décision du moyen de paiement

```text
Le moyen est-il autorisé dans le compte ?
├── Non → ne pas le proposer; corriger les réglages avant la prise
└── Oui
    ├── Moyen manuel (espèces, virement, chèque)
    │   └── paiement local → facture mise à jour → ticket terminé
    └── Carte / Stripe
        ├── Stripe non configuré → erreur, aucun faux paiement
        └── Stripe configuré → checkout externe → confirmation E2E obligatoire
```

## Variantes

| Variante | Ce qui change | Épisode conseillé |
| --- | --- | --- |
| Pourboire fixe | Saisir un montant au lieu d'un pourcentage | Annexe G08 ou capsule Pourboires. |
| Virement/chèque | Moyen et référence de rapprochement | Capsule Paiements manuels. |
| Reçu email/SMS | Livraison externe et preuve de réception | Capsule Reçus après validation d'intégration. |
| Paiement partiel | Facture reste partielle et solde positif | Capsule Factures, pas checkout de service standard. |
| Service gratuit | Ticket terminé sans paiement | Capsule File d'attente. |
| Carte | Redirection et confirmation Stripe | Démonstration E2E séparée. |
| Inversion de pourboire | Affecte le net du pourboire, pas le montant payé de facture | Capsule Finance propriétaire. |

## Erreurs utiles

| Symptôme | Cause | État conservé | Correction |
| --- | --- | --- | --- |
| Encaissement impossible à ouvrir | Ticket non gérable, file désactivée ou mauvais compte | Ticket inchangé | Vérifier rôle, compte et état. |
| « Terminer le service avant le paiement » | Ticket pas encore À encaisser | État opérationnel actuel | Faire progresser le service normalement. |
| Aucun paiement requis | Total nul | Le ticket peut être terminé gratuitement | Choisir une prestation payante pour G08. |
| Moyen refusé | Moyen non autorisé par les réglages | À encaisser | Choisir un moyen permis ou corriger le clone avant prise. |
| Stripe non configuré | Carte sélectionnée sans configuration | À encaisser, pas de paiement | Retirer le plan Carte de G08. |
| Paiement en attente existe déjà | Tentative externe non réglée | À encaisser/pending | Réconcilier ou annuler la tentative, sans recréer un paiement. |
| Montants différents | Prix, taxe, remise ou dataset modifié | Pas de soumission | Relire l'écran et mettre à jour le script avant de continuer. |
| Pourboire plafonné | Maximum des réglages dépassé | Valeur normalisée | Utiliser une valeur autorisée et visible. |
| Reçu non envoyé | Option vide, adresse absente ou livraison échouée | Paiement peut être réussi | Distinguer paiement et livraison du reçu. |

## Plan de secours

- Si le rejeu échoue avant paiement, laisser le ticket à encaisser, expliquer l'erreur et ne pas utiliser un montage de succès.
- Si un paiement a réussi mais le PNG du message manque, retrouver la facture nouvellement créée par cliente et heure ; ne pas refaire le paiement.
- Si le clone n'est pas disponible, ouvrir directement dans Factures le numéro canonique `I…` relevé pendant le prévol. `SAL-ECLAT-PAID-001` identifie le ticket, mais l'interface n'offre aucun lien navigable du ticket terminé vers la facture. Ajouter le cartouche « Preuve provisionnée — lecture seule ».
- Si les montants du clone diffèrent, remplacer tous les nombres du master, de la voix et des incrustations ; ne pas mélanger les chiffres canoniques et l'écran du rejeu.
- Le bouton PDF force un téléchargement. Ouvrir ensuite le fichier dans un lecteur propre et recadrer le document sans imprimante, fichiers récents ni chemin local.
