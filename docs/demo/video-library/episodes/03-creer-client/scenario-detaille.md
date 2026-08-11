# G03 — Scénario détaillé de tournage

Dernière mise à jour : 2026-08-11<br>
Source : [fiche maître G03](../03-creer-client.md)

## Intention de la prise

La vidéo ne doit pas seulement montrer où cliquer. Elle doit rendre visibles les décisions qui évitent un doublon, un accès portail involontaire, une remise permanente mal réglée ou une adresse non enregistrée.

Le master est enregistré une fois, puis sert à produire une version complète de 10 à 12 minutes et une coupe rapide de 3 à 4 minutes.

## Pas-à-pas de tournage

| Étape | Capture | Action exacte | Valeur ou état | Réponse attendue | Narration principale | Erreur ou reprise |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | G03-S01 | Ouvrir `/customer`; rechercher le nom puis le courriel. | `Nora Bouchard`, puis `nora.bouchard@example.test` | Aucun résultat correspondant. | « Avant d'ajouter une personne, je vérifie son nom et son courriel. C'est le moyen le plus simple d'éviter deux historiques pour la même cliente. » | Si Nora existe, utiliser un clone propre ou une alternative contrôlée avant la prise et garder ce nom partout. |
| 2 | G03-S02 | Cliquer sur le bouton d'ajout. Faire défiler lentement sans saisir. | Formulaire vide. | Les grandes sections sont visibles. | « Le formulaire regroupe le type de client, l'identité, les accès, les informations complémentaires et l'adresse. Certaines sections changent selon les modules actifs. » | Ne pas survoler un champ si l'infobulle masque le titre. |
| 3 | G03-S03 | Laisser `Particulier`; choisir une icône prédéfinie. | Particulier + icône. | Les champs Entreprise restent masqués. | « Nora réserve pour elle-même : je garde Particulier. Une entreprise ferait apparaître son nom légal, son numéro d'immatriculation et son secteur. » | Ne pas téléverser une photo réelle. |
| 4 | G03-S04 | Remplir l'identité. | Nora, Bouchard, 1993-04-18, +1 514 555-0147, adresse example.test. | Aucun message d'erreur. | « Prénom, nom et courriel sont obligatoires. Le téléphone et la date de naissance sont facultatifs. La date ne peut pas être future. » | Si le navigateur propose l'autoremplissage personnel, le fermer et reprendre. |
| 5 | G03-S05 | Désactiver `Donner accès à la plateforme`. | Non. | Le toggle est visiblement désactivé. | « L'accès portail est activé par défaut. Je le désactive ici : Nora reste utilisable par l'équipe, mais aucun compte portail ni invitation n'est créé pendant cette démonstration. » | Si la prise a été enregistrée avec le toggle actif, ne pas conserver la création : recommencer avec une nouvelle donnée fictive. |
| 6 | G03-S06 | Afficher la section d'auto-validation. Laisser les toggles inactifs. | Factures : non. | Seules les options autorisées par les modules apparaissent. | « Ces automatismes dépendent des modules du workspace. Dans ce salon, je laisse la facture sous validation humaine. Je ne montre pas Devis, Jobs ou Tâches puisqu'ils ne sont pas actifs ici. » | Si l'interface montre d'autres modules, expliquer le workspace utilisé au lieu de prétendre que tous les écrans sont identiques. |
| 7 | G03-S07 | Remplir les informations complémentaires. | Description, Instagram, remise 0. | Valeurs acceptées. | « La description garde un contexte utile à l'équipe. “Référé par” mémorise l'origine du contact. La remise fidélité vaut zéro : une réduction permanente doit être volontaire et ne remplace pas une promotion. » | Une description de moins de 5 caractères est refusée. |
| 8 | G03-S08 | Essayer la recherche d'adresse, puis passer en saisie manuelle si nécessaire. | `245 rue Démonstration`. | Suggestions ou champs manuels disponibles. | « La recherche accélère la saisie, mais je peux toujours compléter les champs manuellement. Je n'utilise qu'une adresse fictive. » | Si le service d'autocomplétion ne répond pas, couper l'attente et utiliser directement les champs manuels. |
| 9 | G03-S09 | Compléter l'adresse, puis cocher `Adresse de facturation identique à l'adresse principale`. | Montréal, Québec, H2X 3K4, Canada ; case Oui. | Adresse complète et indicateur de facturation affichés. | « Le point subtil est la ville : côté application, c'est elle qui permet de créer l'adresse associée au client. Une rue seule ne suffit pas. La case de facturation mémorise ici que cette même adresse doit être utilisée ; elle ne crée pas une deuxième adresse. » | Vérifier que la ville n'a pas été effacée par l'autocomplétion et que la case, désactivée par défaut, est bien cochée. |
| 10 | G03-S10 | Remplacer temporairement le courriel par le courriel exact d'un client déjà présent, relevé sur le clone; soumettre; montrer l'erreur; restaurer Nora. | Courriel dynamique de Julie relevé en préproduction, puis `nora.bouchard@example.test`. | Erreur d'unicité, puis formulaire corrigé. | « Le courriel ne peut appartenir qu'à une fiche client. Ce contrôle protège les échanges et l'accès portail. Je corrige avec l'adresse de démonstration réservée à Nora. » | Le preset suffixe les courriels. Ne jamais supposer `julie.nadeau@example.test` : relever la valeur réellement affichée sur la fiche Julie du clone. |
| 11 | G03-S11 | Montrer les deux actions, sans quitter la page. | `Enregistrer et créer un autre`; `Enregistrer client`. | Les deux libellés sont lisibles. | « Le premier bouton convient à une saisie en série et revient au formulaire vide. Le second enregistre Nora et revient à la liste Clients. » | Ne pas annoncer que le bouton Annuler revient à la liste : son comportement doit être validé séparément. |
| 12 | G03-S12 | Cliquer `Enregistrer client`. | Formulaire valide. | Redirection `/customer`, message de succès et ligne Nora. | « La confirmation apparaît dans la liste. C'est la première preuve : l'application ne nous envoie pas directement sur la fiche. » | Si une invitation part, le portail n'a pas été désactivé : la prise n'est pas valide. |
| 13 | G03-S13 | Rechercher Nora dans la liste et ouvrir la ligne. | Nora Bouchard. | Fiche détail ouverte. | « J'ouvre maintenant la ligne créée pour vérifier que l'identité, les coordonnées et l'adresse correspondent à ce que nous avons saisi. » | Si une valeur manque, ne pas masquer l'écart au montage; corriger le scénario ou refaire la prise. |
| 14 | G03-S14 | Ouvrir le formulaire d'une nouvelle réservation; rechercher Nora; ne pas enregistrer. | Nora Bouchard sélectionnable. | Nora apparaît dans les résultats. | « La meilleure preuve n'est pas seulement la fiche : Nora est déjà disponible dans le prochain geste métier, la création d'une réservation. » | Fermer la modale sans créer un rendez-vous qui perturberait G05. |
| 15 | G03-S15 | Revenir sur un formulaire vierge; choisir `Entreprise`; cadrer les champs conditionnels. | Studio Boréal Beauté inc. | Nom entreprise, immatriculation et secteur visibles. | « Pour une entreprise, Malikia Pro conserve l'organisation et son contact principal. Le nom de l'entreprise devient obligatoire. » | Ne pas enregistrer cette variante dans le master principal. |
| 16 | G03-S16 | Utiliser un workspace de référence possédant Jobs ou Tâches, ou une capture déjà validée. | Préférences de facturation visibles. | Modes disponibles cohérents avec les modules. | « Les préférences avancées ne sont pas universelles. Elles apparaissent lorsque le contexte de facturation et les modules opérationnels le nécessitent. » | Ne jamais faire passer une capture d'un autre workspace pour l'écran de Salon Éclat; ajouter un cartouche explicite. |

## Script continu — master

> Dans cette vidéo, nous allons créer une fiche client propre, comprendre les choix importants du formulaire et vérifier que la cliente peut être réutilisée dans une réservation.
>
> Nora Bouchard appelle Salon Éclat pour une première consultation couleur. Avant de l'ajouter, je recherche d'abord son nom, puis son courriel. Cette vérification évite de séparer les rendez-vous, factures et notes d'une même personne entre plusieurs fiches.
>
> Je clique sur Ajouter client. Le formulaire commence par le type de client. Nora réserve pour elle-même, donc je garde Particulier. Le choix Entreprise ferait apparaître des informations supplémentaires pour l'organisation et son contact principal. Pour l'illustration, je sélectionne une icône prédéfinie au lieu d'utiliser une vraie photo.
>
> Je renseigne Nora, Bouchard et son courriel de démonstration. Le prénom, le nom et le courriel sont obligatoires. Le téléphone et la date de naissance sont facultatifs. La date de naissance ne peut pas être située dans le futur.
>
> Ici, l'accès à la plateforme est activé par défaut. Ce choix a une conséquence réelle : il peut créer un utilisateur portail et préparer une invitation. Pour cette démonstration, je le désactive. Nora existera bien dans Malikia Pro pour l'équipe, sans accès portail et sans envoi vers une adresse externe.
>
> Les options de validation automatique dépendent des modules actifs dans l'entreprise. Salon Éclat utilise les factures, mais pas les modules Devis, Jobs ou Tâches. Je laisse donc la validation automatique des factures désactivée : une nouvelle cliente reste sous contrôle de l'équipe.
>
> J'ajoute une courte description utile à l'accueil, puis Instagram comme origine du contact. La remise fidélité reste à zéro. Ce champ représente un avantage permanent ; une campagne comme BIENVENUE10 sera créée séparément dans l'épisode Promotions.
>
> Je complète ensuite une adresse entièrement fictive. La ville mérite une attention particulière : sans ville, l'application n'enregistre pas l'adresse associée à la fiche, même si une rue a été saisie. Je vérifie donc Montréal, Québec, le code postal et le pays. La case Adresse de facturation identique est désactivée par défaut ; je la coche volontairement. Elle enregistre ce choix, sans créer une deuxième adresse.
>
> Avant l'enregistrement final, je montre un cas fréquent : un courriel déjà utilisé. L'application refuse le doublon. Je remets l'adresse example.test réservée à Nora et le formulaire redevient valide.
>
> Deux actions sont proposées. Enregistrer et créer un autre convient à une série de saisies et revient sur un formulaire prêt pour la personne suivante. Enregistrer client termine cette fiche et nous ramène à la liste Clients. Je choisis cette deuxième option.
>
> Le message de succès et la ligne Nora Bouchard apparaissent dans la liste. J'ouvre sa fiche pour contrôler les informations. Enfin, j'ouvre une nouvelle réservation et je recherche Nora : elle est déjà disponible dans le sélecteur. C'est la preuve que la création est utilisable dans le flux métier suivant.
>
> Pour un client Entreprise, le même écran demande en plus le nom de l'organisation et peut conserver son numéro d'immatriculation et son secteur. Les préférences de facturation avancées, elles, n'apparaissent que dans les workspaces dont les modules les rendent pertinentes. Le guide joint à cet épisode détaille toutes ces variantes.

## Script continu — coupe rapide

> Nous allons créer Nora Bouchard et la rendre disponible dans une réservation. Je commence par vérifier qu'elle n'existe pas déjà dans Clients. Je choisis Particulier, puis je renseigne son identité, son téléphone et un courriel example.test.
>
> L'accès portail étant activé par défaut, je le désactive pour cette démonstration : Nora restera disponible pour l'équipe sans invitation. J'ajoute son contexte, sa provenance et une adresse fictive, en vérifiant la ville pour que l'adresse soit bien enregistrée.
>
> Je clique sur Enregistrer client. L'application revient à la liste et confirme la création. J'ouvre la fiche, puis une nouvelle réservation : Nora apparaît dans le sélecteur. La cliente est prête pour la suite du parcours.

## Notes de montage

- Conserver l'erreur de courriel en vitesse normale ; c'est un moment pédagogique, pas une imperfection à masquer.
- Couper les temps de chargement, mais laisser le message de succès lisible au moins deux secondes.
- Utiliser un zoom de montage uniquement sur le toggle Portail, l'erreur d'unicité, la ville et les deux boutons.
- Afficher « Workspace de référence avec Jobs/Tâches » sur G03-S16 si la capture ne vient pas de Salon Éclat.
- Ne jamais masquer au montage une donnée incohérente entre le formulaire, la liste et la fiche.
