# G06 — Scénario détaillé de tournage

Dernière mise à jour : 2026-08-11<br>
Source : [fiche maître G06](../06-creer-promotion.md)

## Intention de la prise

La vidéo doit enseigner qu'une promotion est une combinaison de cible, remise, fenêtre et limites. Elle doit aussi séparer trois preuves : la configuration sauvegardée, la réutilisation marketing et l'application financière. Seules les deux premières sont honnêtement capturables pour une promotion Service spécifique dans le parcours actuel.

## Pas-à-pas de tournage

| Étape | Capture | Action exacte | Valeur ou état | Réponse attendue | Narration principale | Erreur ou reprise |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | G06-S01 | Ouvrir `/promotions`; lire la liste et repérer RENTREE20. | BIENVENUE10 absent. | Bibliothèque et statistiques visibles. | « Cette page ne possède pas de recherche. Je parcours donc les codes visibles pour confirmer que BIENVENUE10 n'existe pas et je préserve RENTREE20, qui appartient à un autre scénario. » | Si BIENVENUE10 existe, reprovisionner un clone; ne pas supprimer une donnée partagée. |
| 2 | G06-S02 | Cliquer Nouvelle promotion; ne rien saisir. | Global, Pourcentage, début et fin issus de la date UTC du navigateur, Active. | Modale `Créer une promotion`. | « La modale démarre sur une promotion globale, en pourcentage, active et limitée à la date initiale affichée. Chacune de ces valeurs doit être décidée, pas simplement acceptée. » | Le formulaire dérive la date de `toISOString`; près de minuit local, vérifier l'écart possible avec le jour métier. |
| 3 | G06-S03 | Remplir le nom et le code. | Bienvenue couleur; BIENVENUE10. | Aucun message d'erreur. | « Le nom sert à l'équipe. Le code, optionnel, sera normalisé en majuscules et devra rester unique dans ce workspace. » | Ne pas afficher un code utilisé dans un autre compte réel. |
| 4 | G06-S04 | Choisir Service spécifique; attendre le champ Cible; choisir Consultation couleur. | `service`; prestation G04. | Cible exacte sélectionnée. | « Service spécifique fait apparaître une cible obligatoire. Je choisis Consultation couleur : la promotion ne couvre ni toute la catégorie Coloration ni les autres services. » | Si la prestation manque, revenir à G04; ne pas sélectionner Couleur racines par commodité. |
| 5 | G06-S05 | Garder Pourcentage; saisir 10. | `percentage`; 10. | Valeur valide. | « Dix signifie dix pour cent. Un pourcentage doit être supérieur à zéro et ne peut pas dépasser cent. » | Ne pas saisir `0.10`, qui signifierait 0,10 %. |
| 6 | G06-S06 | Définir début J et fin J+30. | Exemple 2026-08-11 à 2026-09-10. | Fenêtre valide couvrant aujourd'hui. | « Je commence aujourd'hui et je termine dans trente jours. La fin peut égaler le début, mais elle ne peut jamais le précéder. » | Si la prise est faite un autre jour, recalculer les deux dates avant l'enregistrement. |
| 7 | G06-S07 | Garder Active; saisir 50; laisser Montant minimum vide. | Active; 50; vide. | Trois choix visibles. | « Active permet l'évaluation dans la fenêtre. Cinquante est une limite globale d'usages. Le minimum reste vide : ce scénario n'impose aucun sous-total supplémentaire. » | Ne pas saisir zéro comme limite : une limite renseignée doit être au moins 1. |
| 8 | G06-S08 | Remplacer le code par RENTREE20; soumettre; cadrer l'erreur; remettre BIENVENUE10. | Code existant puis code canonique. | Erreur d'unicité rattachée au code. | « Les codes sont uniques à l'intérieur du compte. Le serveur met aussi les minuscules en majuscules avant ce contrôle. Je restaure le code réservé à cette offre. » | Confirmer RENTREE20 dans le clone avant la prise; sinon utiliser un autre code réellement présent. |
| 9 | G06-S09 | Revoir toute la modale corrigée, du haut vers le bas. | Toutes les valeurs canoniques. | Aucune erreur restante. | « Avant d'enregistrer, je vérifie ensemble le nom, la cible, la remise, les dates, le statut, la limite et l'absence de minimum. » | Une reprise de l'erreur ne doit pas avoir remis la cible à Global. |
| 10 | G06-S10 | Cliquer Enregistrer la promotion. | Formulaire valide. | Modale fermée; route `/promotions`; liste actualisée. | « L'application revient sur la même bibliothèque après création. » | Laisser le message de succès visible s'il apparaît, sans le fabriquer au montage. |
| 11 | G06-S11 | Cadrer la ligne Bienvenue couleur. | BIENVENUE10; Service spécifique; Consultation couleur; 10 %; dates; 0/50; Active. | Badge Valide maintenant si J est inclus. | « La ligne prouve la configuration. Valide maintenant signifie ici Active et dans la fenêtre; l'éligibilité d'une opération dépendrait aussi de la cible et des limites. » | Si le badge manque, vérifier les dates et le fuseau avant de refaire la prise. |
| 12 | G06-S12 | Cliquer Modifier; faire défiler; fermer sans sauvegarder. | Valeurs persistées. | Modale `Modifier la promotion`. | « Je rouvre la fiche pour contrôler le minimum vide et la cible, deux données que la ligne résume seulement. » | Fermer avec Annuler pour ne pas modifier la date ou le statut. |
| 13 | G06-S13 | Sur la ligne, cliquer Publier avec Pulse; cadrer le préremplissage; ne rien publier. | Texte issu de la promotion. | `/social/composer`; nom, 10 %, code, cible, fenêtre repris. | « Pulse réutilise la promotion comme source. C'est une preuve aval marketing; je ne publie rien et je ne la présente pas comme une preuve d'encaissement. » | Si le bouton est absent, vérifier le module Social et l'accès; exclure ce plan plutôt que simuler le compositeur. |
| 14 | G06-S14 | Revenir sur une modale vierge; faire défiler Global, Client, Produit, Service. | Quatre types. | Cible masquée pour Global, visible pour les trois autres. | « Global s'applique à toutes les lignes admissibles. Client cible une personne et couvre ses lignes. Produit et Service ciblent un identifiant exact de leur catalogue. » | Ne pas soumettre la variante. |
| 15 | G06-S15 | Montrer Montant fixe, Minimum, Inactive, puis fermer. | Valeurs d'annexe. | Contrôles conditionnels compris. | « Un montant fixe est plafonné au sous-total admissible. Le minimum porte sur le sous-total total. Inactive empêche toute application même si les dates sont valides. » | Restaurer aucun état : fermer la modale sans sauvegarder. |

## Script continu — master

> Dans cette vidéo, nous allons créer une promotion ciblée et comprendre ce qui la rend réellement applicable.
>
> J'ouvre la bibliothèque Promotions. Elle affiche les statistiques et toutes les promotions, mais elle ne propose pas de recherche. Je parcours les codes pour vérifier que BIENVENUE10 est absent. Je garde RENTREE20 intact : cette promotion existe déjà pour un autre récit Salon Éclat.
>
> Je clique sur Nouvelle promotion. Le formulaire part sur Tous les clients, Pourcentage, les dates du jour et le statut Active. Ces valeurs sont des valeurs initiales; je dois les adapter au besoin métier.
>
> Je nomme l'offre Bienvenue couleur et je saisis BIENVENUE10. Le nom est interne. Le code est facultatif, mais sa présence change le comportement : il devra être saisi pour demander cette offre. Sans code, une promotion admissible peut être évaluée automatiquement. Le serveur normalise les codes en majuscules.
>
> Je choisis Service spécifique. Un champ Cible apparaît et devient obligatoire. Je sélectionne Consultation couleur, créée dans G04. Cela vise cette prestation exacte, pas toute la catégorie Coloration.
>
> Le type de remise reste Pourcentage et la valeur vaut 10. Cela signifie dix pour cent, et non 0,10. Une remise en pourcentage doit être supérieure à zéro et ne peut pas dépasser cent.
>
> Je définis une fenêtre qui commence le jour du tournage et se termine trente jours plus tard. Dans cet exemple, du 11 août au 10 septembre 2026. À l'oral, je parle de J et J plus trente afin que la vidéo reste durable. La date de fin peut égaler la date de début, mais ne peut pas être antérieure.
>
> Je conserve le statut Active. J'ajoute une limite de cinquante utilisations. Cette limite est globale : ce n'est pas cinquante utilisations par cliente. Je laisse le montant minimum vide, car aucune condition de panier supplémentaire n'est prévue.
>
> Pour montrer la validation, je remplace temporairement le code par RENTREE20. Le serveur refuse ce code déjà présent dans le compte. Je remets BIENVENUE10 et je contrôle une dernière fois la cible, la remise, les dates, le statut et les limites.
>
> J'enregistre. La modale se ferme et la promotion apparaît dans la bibliothèque. La ligne montre BIENVENUE10, Service spécifique, Consultation couleur, dix pour cent, les dates, zéro utilisation sur cinquante et le statut Active. Le badge Valide maintenant confirme seulement que le statut et la fenêtre couvrent aujourd'hui; il ne teste pas un panier réel.
>
> Je rouvre la promotion en modification pour vérifier la cible et le minimum resté vide, puis je ferme sans changement.
>
> Dans Salon Éclat, le bouton Publier avec Pulse réutilise cette promotion. Le compositeur reçoit le nom, la remise, le code, la cible et la fenêtre. Je ne publie rien. Cette scène prouve que la configuration alimente un geste marketing.
>
> Elle ne prouve pas une remise financière. Aujourd'hui, le contrôleur Vente est réservé aux entreprises de type Produits et son formulaire ne charge que leurs produits, tandis que le checkout de réservation n'appelle pas le moteur Promotions. Je ne vais donc pas simuler l'application de BIENVENUE10 à Consultation couleur. Cette preuve devra faire l'objet d'une capsule distincte lorsqu'un flux compatible sera disponible.
>
> Pour terminer, la cible peut être globale, limitée à un client, à un produit ou à un service. Une remise peut être un pourcentage ou un montant fixe. Un minimum peut conditionner l'ordre entier, et le statut Inactive bloque l'application même lorsque les dates sont bonnes.

## Script continu — coupe rapide

> Nous allons créer Bienvenue couleur. Dans la bibliothèque, je vérifie que BIENVENUE10 n'existe pas et je préserve RENTREE20.
>
> Je saisis le nom et le code, puis je choisis Service spécifique et Consultation couleur. La remise est de dix pour cent. Je définis une fenêtre allant du jour du tournage à trente jours plus tard, je conserve Active, je limite l'offre à cinquante utilisations et je laisse le montant minimum vide.
>
> J'enregistre. La bibliothèque montre le code, la cible, dix pour cent, les dates, zéro sur cinquante et Valide maintenant. La promotion est configurée; son application financière à une prestation n'est pas simulée dans cette capsule.

## Notes de montage

- Afficher un cartouche `Code présent = saisie du code requise` pendant G06-S03.
- Laisser l'erreur RENTREE20 visible au moins deux secondes.
- Ne pas masquer les dates réelles dans la capture; parler de J et J+30 à l'oral.
- Zoomer sur `0 / 50` et préciser `limite globale`.
- Sur G06-S13, afficher `Preuve marketing — aucun post publié`.
- Ne jamais monter une page Vente produits comme si Consultation couleur y était sélectionnable.
