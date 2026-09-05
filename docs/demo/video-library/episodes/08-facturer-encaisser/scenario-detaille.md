# G08 — Scénario détaillé de tournage

Dernière mise à jour : 2026-08-11<br>
Source : [fiche maître G08](../08-facturer-encaisser.md)

## Préparation du rejeu

Dans un clone jetable, créer par l'interface un second rendez-vous Marie Lefebvre + Coupe femme + brushing + Karim Benali. Le faire progresser par Check-in, Appeler et Démarrer. Couper les étapes déjà enseignées dans G05, mais ne pas modifier directement le statut en base.

Vérifier avant la prise :

- la prestation affiche 65,00 CAD ;
- le ticket appartient au clone ;
- Karim est bien assigné ;
- Espèces est un moyen autorisé ;
- aucun envoi de reçu n'est sélectionné ;
- le ticket canonique `SAL-ECLAT-PAID-001` reste intact comme preuve de secours ;
- avant de créer le rejeu, ouvrir Factures, rechercher Marie, identifier la facture canonique payée à 74,73 avec le paiement Espèces et le pourboire de 11,70, puis relever son numéro `I…`. L'interface ne relie pas le ticket terminé à cette facture.

## Pas-à-pas

| Étape | Capture | Action exacte | Valeur/état | Réponse attendue | Narration | Reprise sûre |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | G08-S01 | Ouvrir l'onglet File et cadrer le ticket de rejeu. | Marie, Karim, En service. | Action de fin disponible. | « Nous partons d'un vrai service en cours, relié à une cliente, une prestation et un membre. » | Si le ticket canonique est déjà Terminé, ne pas le présenter comme ce rejeu. |
| 2 | G08-S02 | Ouvrir les actions du ticket et choisir Encaisser et terminer. | Ticket En service. | Transition vers À encaisser et ouverture possible du checkout. | « Terminer la prestation prépare son encaissement. Le ticket n'est pas encore payé. » | Si le montant est nul, le service se termine sans paiement : choisir la bonne prestation. |
| 3 | G08-S03 | Fermer brièvement la modale si nécessaire et montrer le statut À encaisser. | `awaiting_payment`. | Statut visible. | « À encaisser sépare clairement le travail terminé du règlement encore attendu. » | Ne pas recharger entre transition et capture si l'état disparaît du filtre courant. |
| 4 | G08-S04 | Ouvrir Encaisser. | Marie, prestation, Karim. | En-tête de modale cohérent. | « Je vérifie d'abord la cliente, la prestation et le bénéficiaire potentiel du pourboire. » | Fermer sans soumettre si une identité ne correspond pas. |
| 5 | G08-S05 | Cadrer le récapitulatif financier. | 65,00 ; 9,73 ; 74,73. | Somme exacte. | « Le sous-total vaut 65 dollars. Les taxes ajoutent 9,73 et la facture atteint 74,73. » | Lire les chiffres de l'écran si le taux du clone a changé ; ne pas imposer ceux du script. |
| 6 | G08-S06 | Choisir Espèces et Ne pas envoyer maintenant. | cash, aucun reçu envoyé. | Libellés visibles. | « Espèces enregistre un règlement manuel local. Je n'envoie pas le reçu pendant la démonstration. » | Si Espèces est absent, corriger les moyens du clone avant la prise, sans choisir Carte par défaut. |
| 7 | G08-S07 | Montrer le pourboire désactivé puis cliquer Ajouter. | Off → On. | Choix Pourcentage/Montant fixe visible. | « Le pourboire est facultatif et séparé du montant de facture. » | Aucun clic final à cette étape. |
| 8 | G08-S08 | Choisir Pourcentage et saisir 18. | 18 %. | Pourboire 11,70 ; total 86,43. | « Dix-huit pour cent s'applique aux 65 dollars de prestation, soit 11,70. » | Si la saisie est plafonnée, vérifier le maximum fourni par les réglages. |
| 9 | G08-S09 | Saisir référence et note. | `DEMO-ECLAT-CASH-G08-001`; `Démonstration locale — aucun débit externe.` | Valeurs acceptées. | « La référence facilite le rapprochement. La note rappelle qu'aucun débit externe n'a eu lieu. » | Les deux champs sont optionnels ; ne pas y mettre un numéro de carte. |
| 10 | G08-S10 | Revenir au récapitulatif avant soumission. | 65,00 + 9,73 + 11,70 = 86,43. | Tous les montants visibles. | « Je distingue le total de facture, 74,73, du total encaissé, 86,43. » | Ne pas confirmer si une somme diffère. |
| 11 | G08-S11 | Cliquer Encaisser et terminer. | Paiement Espèces. | Succès, ticket Terminé, lien Ouvrir le reçu. | « La confirmation crée le paiement, met la facture à jour et termine le ticket dans une seule chaîne. » | Si erreur, conserver le ticket À encaisser et expliquer la cause ; ne pas fabriquer un succès. |
| 12 | G08-S12 | Noter l'heure exacte de l'encaissement, ouvrir Factures, rechercher Marie, puis identifier la nouvelle ligne par l'heure, le statut Payée et le montant 74,73. Relever alors son numéro `I…` affiché dans la liste ou la fiche. | Nouvelle facture de Marie. | Ligne payée du rejeu visible et distinguée de la facture canonique. | « Le message de succès ne montre pas le numéro. Je recherche donc Marie et je reconnais la nouvelle facture grâce à l'heure, au statut et au montant ; son propre numéro devient ensuite notre identifiant de contrôle. » | La recherche Factures ne couvre ni la référence du paiement ni un numéro absent de l'écran de succès. Si l'heure et le montant ne suffisent pas, arrêter et contrôler le clone avant d'ouvrir une ligne. |
| 13 | G08-S13 | Ouvrir la facture. | Marie, statut Payée. | En-tête et contexte cohérents. | « J'ouvre la facture pour réconcilier chaque montant, pas seulement son badge. » | Ne pas confondre avec la facture canonique seedée. |
| 14 | G08-S14 | Cadrer le résumé. | Sous-total 65, taxes 9,73, payé 74,73, pourboire 11,70, encaissé 86,43, solde 0. | Égalités vérifiées. | « Le pourboire augmente l'encaissement, mais le solde de facture est éteint par 74,73. » | Si le pourboire est absent, vérifier que son toggle était actif avant la soumission. |
| 15 | G08-S15 | Cadrer le bloc Paiements. | Espèces, completed/paid, Karim. | Moyen, date, pourboire et bénéficiaire visibles. | « Le paiement garde son moyen, son statut et l'employé auquel le pourboire est attribué. » | Masquer toute référence technique de fournisseur si elle apparaît. |
| 16 | G08-S16 | Depuis la facture, cliquer `Télécharger PDF`, puis ouvrir le fichier téléchargé dans un lecteur PDF et recadrer le document. | Reçu téléchargé. | Document lisible, sans chemin local ni interface système sensible. | « Le reçu peut être téléchargé même si nous avons choisi de ne pas l'envoyer maintenant. » | La route force un téléchargement, elle n'ouvre pas une page PDF inline. Ne jamais montrer le chemin local, les fichiers récents ou un dialogue système. |
| 17 | G08-S17 | Ouvrir `/payments/tips`. | Allocation Karim. | Pourboire net visible. | « La vue Pourboires permet au propriétaire de suivre l'allocation séparément de la facture. » | Si le rejeu n'apparaît pas, laisser la capture en annexe et vérifier l'allocation avant publication. |
| 18 | G08-S18 | Ouvrir depuis Factures le numéro canonique `I…` relevé pendant le prévol et montrer ses montants et son paiement Espèces. | Facture canonique `I…`, 74,73 payé, 11,70 de pourboire. | Chaîne finale cohérente en lecture seule. | « Cette facture a été identifiée dans Factures avant le rejeu. SAL-ECLAT-PAID-001 reste le numéro du ticket canonique, mais l'interface ne fournit pas de lien navigable entre les deux. Cette preuve confirme le flux comptant, pas un checkout Stripe. » | Ne pas prétendre avoir suivi un lien depuis le ticket. Cartouche obligatoire : « Preuve provisionnée — lecture seule ». |

## Script continu — master

> Dans cette vidéo, nous allons terminer un service, enregistrer son règlement comptant avec un pourboire et vérifier toute la trace jusqu'au reçu.
>
> Nous partons d'un second ticket préparé dans un clone de Salon Éclat. Marie Lefebvre reçoit une Coupe femme plus brushing réalisée par Karim Benali. Le ticket est réellement En service ; ce n'est pas le ticket canonique déjà payé du preset.
>
> Je choisis Encaisser et terminer. La prestation passe d'abord à À encaisser. Cet état est important : le travail est terminé, mais aucun règlement n'est encore enregistré.
>
> J'ouvre l'encaissement et je contrôle la cliente, la prestation et le membre. Le sous-total est de 65 dollars. Les taxes sont de 9,73, pour un total de facture de 74,73.
>
> Je choisis Espèces. Ce moyen crée un paiement manuel local ; aucun prestataire externe n'est débité. Pour le reçu, je garde Ne pas envoyer maintenant afin qu'aucun email ou SMS ne parte pendant la prise.
>
> J'active ensuite le pourboire, je choisis Pourcentage et je saisis 18. Le calcul part du sous-total de la prestation, 65 dollars, et donne 11,70. Le total à encaisser devient donc 86,43.
>
> La référence et la note sont facultatives. J'ajoute une référence de démonstration et j'indique qu'aucun débit externe n'a eu lieu. Avant de confirmer, je relis quatre nombres : 65 de prestation, 9,73 de taxes, 74,73 de facture et 86,43 réellement encaissés avec le pourboire.
>
> Je clique sur Encaisser et terminer. L'application crée le paiement, actualise la facture et ferme le ticket. Le message de succès propose aussi d'ouvrir le reçu.
>
> Le message de succès ne montre pas le numéro de facture. Je note donc l'heure, puis je recherche Marie dans Factures. L'heure, le badge Payée et le montant 74,73 permettent d'identifier la nouvelle ligne. Je relève alors son numéro commençant par I et j'ouvre son détail. La référence du paiement n'est pas un critère de recherche de cette liste. Le montant payé de la facture vaut 74,73 et son solde est nul. Le pourboire reste séparé à 11,70, ce qui porte le total encaissé à 86,43.
>
> Le bloc Paiements confirme Espèces, le statut du règlement et Karim comme bénéficiaire du pourboire. Je clique enfin sur Télécharger PDF, puis j'ouvre le fichier téléchargé dans un lecteur propre. Le reçu est lisible même si aucun envoi n'a été demandé.
>
> Cette preuve concerne un règlement comptant manuel. Avec Carte, le bouton conduit vers Stripe et le ticket ne doit être considéré comme payé qu'après un vrai checkout de test, son retour et sa confirmation. Cette validation reste une démonstration séparée.

## Script continu — coupe rapide

> Le service de Marie est terminé et passe à À encaisser. J'ouvre le checkout : 65 dollars de prestation, 9,73 de taxes et 74,73 de facture.
>
> Je choisis Espèces et aucun envoi de reçu. J'ajoute 18 % de pourboire, soit 11,70, pour un total encaissé de 86,43. Je confirme.
>
> La facture est payée, son solde vaut zéro et le paiement montre Espèces ainsi que Karim comme bénéficiaire du pourboire. Le reçu reste téléchargeable. Aucun débit Stripe n'a été simulé.

## Notes de montage

- Ne jamais monter ensemble l'avant du rejeu et l'après de la preuve canonique sans cartouche.
- Laisser le récapitulatif G08-S10 au moins trois secondes.
- Afficher l'équation `74,73 + 11,70 = 86,43` sans masquer l'écran.
- Retirer tout plan de carte si le checkout E2E n'a pas été réellement exécuté.
