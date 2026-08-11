# G04 — Scénario détaillé de tournage

Dernière mise à jour : 2026-08-11<br>
Source : [fiche maître G04](../04-creer-prestation.md)

## Intention de la prise

La vidéo doit rendre compréhensible la structure d'une prestation : son classement, son prix, sa fiscalité et sa disponibilité. Elle doit aussi empêcher deux confusions fréquentes : croire que l'unité définit la durée, ou que le prix peut être saisi dans une devise différente de celle du compte.

Le master est enregistré une fois, puis sert à produire une version complète de 9 à 11 minutes et une coupe rapide de 3 à 4 minutes.

## Pas-à-pas de tournage

| Étape | Capture | Action exacte | Valeur ou état | Réponse attendue | Narration principale | Erreur ou reprise |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | G04-S01 | Ouvrir `/service`; saisir le nom dans la recherche. | Consultation couleur | Aucun résultat exact. | « Je vérifie d'abord le catalogue. L'application n'interdit pas deux services portant le même nom, donc cette recherche protège la cohérence du parcours. » | Si la prestation existe, utiliser un clone propre. Ne pas la supprimer d'un workspace partagé. |
| 2 | G04-S02 | Cliquer `Ajouter service`; attendre la fin de l'animation. | Modale vierge. | Titre `Nouveau service`; URL toujours `/service`. | « La création se fait dans une modale, directement au-dessus de la liste. Il n'y a pas de page de création séparée dans ce parcours. » | Fermer le bandeau de cookies avant la prise s'il masque le pied de modale. |
| 3 | G04-S03 | Saisir le nom; ouvrir la catégorie et choisir Coloration. | Consultation couleur; Coloration. | Les deux champs requis sont remplis. | « Le nom doit être court dans les sélecteurs. La catégorie organise le catalogue et facilite les filtres. Je choisis Coloration explicitement, car la première catégorie est préremplie automatiquement. » | Si Coloration manque, ne pas improviser : utiliser la variante de création en ligne après avoir documenté le clone. |
| 4 | G04-S04 | Choisir l'unité Pièce; saisir le taux de taxe. | `piece`; `14.975`. | Valeurs acceptées. | « Pièce signifie ici une prestation vendue par rendez-vous. Ce n'est pas sa durée. Le taux reprend celui du workspace Salon Éclat. » | Si le workspace affiche un autre taux officiel, reprendre avec sa valeur réelle et actualiser toute la documentation. |
| 5 | G04-S05 | Saisir le prix; cadrer la note de devise. | `35.00`; CAD. | Prix non négatif et note `Business currency: CAD`. | « Le prix vaut 35 dans la devise du compte. La devise est informative dans cette modale : on ne la change pas prestation par prestation. » | Ne pas traduire en voix la note anglaise comme si l'écran était déjà localisé. |
| 6 | G04-S06 | Garder Actif; remplir Description; laisser Image vide. | Actif; texte canonique; aucune image. | Description visible et case active cochée. | « Une prestation active apparaîtra dans Réservations. La description précise le résultat. Je laisse l'image vide : l'application utilisera son visuel de remplacement. » | Ne pas téléverser une photo trouvée sur Internet. |
| 7 | G04-S07 | Faire défiler jusqu'à Matériaux; ne rien ajouter. | Aucun matériau. | Message `Aucun matériau pour l'instant.` | « Les matériaux sont facultatifs. Ils servent surtout aux flux opérationnels qui reprennent des consommables. Une consultation n'en consomme pas dans ce scénario. » | Ne pas ajouter un produit seulement pour remplir l'écran. |
| 8 | G04-S08 | Vider le nom; cliquer Créer service; cadrer l'erreur; restaurer le nom. | Nom vide, puis Consultation couleur. | Erreur de formulaire sur les champs requis; modale ouverte. | « Le nom, la catégorie et un prix non négatif constituent le contrôle minimal côté interface. Je corrige sans perdre le reste du formulaire. » | Si l'erreur n'est pas visible, cadrer la zone récapitulative en bas de la modale. |
| 9 | G04-S09 | Montrer les trois actions sans cliquer. | Annuler; Enregistrer et créer un nouveau; Créer service. | Libellés lisibles. | « Annuler ferme la modale. Enregistrer et créer un nouveau conserve la modale ouverte mais remet le formulaire à zéro. Créer service termine cette création et referme la modale. » | Ne pas cliquer le bouton d'enchaînement dans le parcours canonique. |
| 10 | G04-S10 | Cliquer `Créer service`. | Formulaire valide. | Requête réussie; modale fermée; liste `/service`. | « Le serveur crée la prestation, lui attribue la devise du compte, puis revient à la liste. » | Si la limite du plan est atteinte, arrêter la prise et préparer un clone compatible; ne pas contourner la limite. |
| 11 | G04-S11 | Rechercher immédiatement Consultation couleur. | Nouvelle ligne. | 35 CAD, Coloration et Actif visibles. | « La ligne confirme les quatre éléments visibles : identité, tarif, classement et disponibilité. » | Attendre la fin du filtre asynchrone avant la capture. |
| 12 | G04-S12 | Ouvrir le menu de la ligne, puis Modifier. | Modale d'édition. | Champs canoniques restaurés. | « Je rouvre la prestation pour vérifier les valeurs non toutes visibles dans la ligne, notamment l'unité, la taxe et la description. » | Fermer sans enregistrer pour éviter un changement parasite. |
| 13 | G04-S13 | Aller dans `/app/reservations`; ouvrir Nouvelle réservation; chercher la prestation; fermer. | Consultation couleur. | La prestation apparaît dans le sélecteur. | « La preuve utile se trouve dans le geste suivant : parce qu'elle est active, Consultation couleur est disponible dans une réservation. Les 30 minutes seront réglées ici, séparément du catalogue. » | Ne pas enregistrer la réservation réservée à G05. |
| 14 | G04-S14 | Sur un formulaire vierge, ouvrir Ajouter catégorie puis Ajouter matériau; ne pas soumettre. | Champs conditionnels visibles. | Création de catégorie et ligne matériau cadrées. | « Une catégorie peut être créée sans quitter la modale. Un matériau peut venir d'un produit du compte ou être décrit manuellement. Ces variantes ne changent pas le prix de base automatiquement. » | Fermer la modale; ne pas laisser de catégorie ni de matériau d'essai. |

## Script continu — master

> Dans cette vidéo, nous allons créer une prestation complète et vérifier qu'elle est réellement disponible dans une réservation.
>
> Salon Éclat veut proposer une consultation couleur de 30 minutes, facturée 35 dollars canadiens. Je commence dans la liste Services en recherchant « Consultation couleur ». Cette vérification est importante : le serveur n'impose pas un nom unique et accepterait donc un doublon.
>
> Je clique sur Ajouter service. La création s'ouvre dans une modale et l'adresse reste `/service`. Le formulaire est réservé au propriétaire du compte dans la version actuelle.
>
> Je saisis Consultation couleur. La catégorie affichée au départ correspond simplement à la première catégorie disponible; je sélectionne donc volontairement Coloration. Si la catégorie n'existait pas, je pourrais la créer ici, sans quitter la modale.
>
> Je choisis l'unité Pièce, car le prix est appliqué à une consultation. Cette unité n'est pas une durée. Le formulaire ne contient aucun champ Durée : les 30 minutes seront définies plus tard dans la réservation. Je renseigne ensuite le taux de taxe de 14,975 pour ce clone Salon Éclat.
>
> Le prix vaut 35. La note à droite confirme CAD, la devise de l'entreprise. Il n'y a pas de choix de devise pour cette prestation : le serveur utilise celle du compte.
>
> Je conserve le statut Actif. C'est ce statut qui permettra à la prestation d'apparaître dans les sélecteurs de réservation. J'ajoute la description « Diagnostic couleur et recommandation personnalisée. » et je laisse l'image vide; le visuel de remplacement suffit pour cette démonstration.
>
> La section Matériaux est facultative. Elle peut conserver un produit existant ou un consommable personnalisé, sa quantité, son prix unitaire et son caractère facturable. Une consultation ne consomme rien dans notre scénario, donc la section reste vide.
>
> Pour montrer la validation, je retire temporairement le nom et je tente d'enregistrer. Le formulaire signale les champs requis. Je remets Consultation couleur : les autres valeurs sont restées en place.
>
> Trois actions sont disponibles. Annuler ferme la modale. Enregistrer et créer un nouveau convient à une saisie en série : la prestation est créée, la modale reste ouverte et le formulaire est remis à zéro. Créer service termine cette fiche et referme la modale. Je choisis cette dernière action.
>
> Nous revenons sur la liste Services. Je recherche la nouvelle ligne : Consultation couleur, 35 dollars, catégorie Coloration, statut Actif. Je l'ouvre une fois en modification pour contrôler l'unité, la taxe et la description, puis je ferme sans changement.
>
> Enfin, j'ouvre une nouvelle réservation et je recherche Consultation couleur dans le sélecteur. Elle est disponible parce qu'elle est active. Je ne crée pas encore le rendez-vous : ce sera l'épisode G05, où nous réglerons aussi sa durée de 30 minutes.

## Script continu — coupe rapide

> Nous allons créer Consultation couleur et vérifier qu'elle est disponible dans Réservations. Je commence par rechercher son nom, car l'application permettrait techniquement un doublon.
>
> Dans Ajouter service, je saisis le nom, je choisis Coloration, l'unité Pièce, un taux de 14,975 et un prix de 35 CAD. L'unité ne fixe pas la durée : les 30 minutes seront ajoutées dans la réservation.
>
> Je garde la prestation active, j'ajoute une description et je laisse image et matériaux vides. Je crée le service, puis je retrouve sa ligne avec le bon prix, la bonne catégorie et le statut Actif.
>
> Dans une nouvelle réservation, Consultation couleur apparaît dans le sélecteur. La prestation est prête pour G05.

## Notes de montage

- Conserver la recherche initiale suffisamment longtemps pour lire l'absence du résultat exact.
- Laisser la note de devise anglaise visible sans la présenter comme une traduction française.
- Zoomer sur l'unité et afficher un cartouche « Unité ≠ durée ».
- Garder l'erreur de champ requis à vitesse normale.
- Montrer la ligne complète après création, puis la preuve Réservations sans enregistrer de rendez-vous.
- Présenter G04-S14 comme une annexe : aucune variante ne doit altérer Consultation couleur.
