# G05 — Scénario détaillé de tournage

Dernière mise à jour : 2026-08-11<br>
Source : [fiche maître G05](../05-creer-reservation.md)

## Intention de la prise

La vidéo doit prouver trois choses : la réservation interne a réellement été enregistrée, le contrôle de disponibilité peut réellement refuser une plage, et une réservation publique n'est pas une fiche client créée en silence.

Enregistrer le master une fois, puis produire une coupe complète de 12 à 15 minutes et une capsule de 4 à 5 minutes.

## Pas-à-pas — parcours interne

| Étape | Capture | Action exacte | Valeur ou état | Réponse attendue | Narration principale | Erreur ou reprise |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | G05-S01 | Ouvrir `/app/reservations`, vue calendrier, puis cadrer le jour préparé. | Créneau occupé de test et créneau libre identifiés hors voix. | Le calendrier charge sans erreur. | « Nora vient de demander une consultation couleur. Avant de saisir, j'ai vérifié les disponibilités de Léa et préparé un créneau libre. » | Si la date est devenue passée ou si un autre tournage l'a occupée, choisir une nouvelle date avant d'enregistrer. |
| 2 | G05-S02 | Cliquer `Nouvelle réservation`. Ne rien saisir pendant deux secondes. | Modale vierge, durée 60 et statut Confirmée par défaut. | Titre `Créer une réservation`. | « La création s'ouvre dans une fenêtre au-dessus du calendrier; ce n'est pas une page séparée. » | Ne pas inventer une route `/create` dans l'incrustation. |
| 3 | G05-S03 | Sélectionner la membre, la cliente et le service. | Léa Moreau, Nora Bouchard, Consultation couleur. | Les trois valeurs restent sélectionnées. | « J'assigne la bonne professionnelle, la fiche créée dans G03 et la prestation créée dans G04. » | Si une valeur manque, arrêter : l'épisode précédent ou le clone n'est pas prêt. |
| 4 | G05-S04 | Renseigner le créneau occupé préparé, sa fin à +30 min, la durée 30 et Confirmée. | Plage future cohérente. | Aucun contrôle navigateur bloquant. | « Début et fin décrivent la plage. Je garde aussi trente minutes dans Durée : lorsque la fin est fournie, l'application calcule la durée enregistrée sur l'écart réel. » | Ne pas utiliser Terminé ou Absence client sur une date future. |
| 5 | G05-S05 | Remplir les deux zones de notes. | Texte canonique de Nora. | Les deux textes sont lisibles. | « La note client peut suivre le rendez-vous dans son espace; la note interne sert seulement à l'équipe. Je ne place aucune information sensible dans l'une ou l'autre. » | Vérifier que les textes ne sont pas inversés. |
| 6 | G05-S06 | Cliquer `Créer` avec le créneau déjà occupé. | Chevauchement d'une réservation active. | Erreur sur le début : créneau indisponible; modale ouverte; aucune réservation supplémentaire. | « Même si la date peut être saisie, le serveur revérifie le planning et le tampon. Le doublon est refusé. » | Si la soumission réussit, le faux conflit n'était pas actif : ne pas poursuivre; réinitialiser le clone et préparer correctement l'état. |
| 7 | G05-S07 | Remplacer début et fin par le créneau libre préparé; vérifier les notes et le statut. | Libre, +30 min, Confirmée. | L'erreur précédente disparaît après la prochaine soumission. | « Je corrige avec le créneau libre que j'avais vérifié, sans changer la cliente, le service ou les notes. » | Contrôler que le fuseau et le jour affichés sont ceux de Salon Éclat. |
| 8 | G05-S08 | Cliquer `Créer`. | Formulaire valide. | POST réussi, modale fermée, calendrier rafraîchi et événement Nora visible. | « Cette fois, l'enregistrement réussit et le calendrier se recharge. Voilà la première preuve persistante. » | Les notifications doivent rester captées par le transport de test. |
| 9 | G05-S09 | Cliquer sur l'événement créé. | Détails Nora. | Plage, service, membre, statut et notes visibles. | « J'ouvre le rendez-vous et je relis exactement ce qui a été enregistré. » | Si la durée implicite ne correspond pas à la plage, corriger le scénario et refaire la prise. |
| 10 | G05-S10 | Fermer les détails, passer en vue Liste et filtrer/rechercher Nora si nécessaire. | Ligne Nora, statut Confirmée. | Une seule ligne correspondant au rendez-vous. | « La vue Liste fournit une deuxième preuve indépendante du placement visuel dans le calendrier. » | Ne pas confondre une autre réservation Nora issue du preset; utiliser l'heure préparée comme second critère. |

## Pas-à-pas — variante publique

| Étape | Capture | Action exacte | Valeur ou état | Réponse attendue | Narration principale | Erreur ou reprise |
| --- | --- | --- | --- | --- | --- | --- |
| 11 | G05-S11 | Ouvrir `/settings/reservations`; cadrer le lien actif puis utiliser l'action de copie. | Lien limité à Consultation couleur, confirmation manuelle active. | URL publique copiée depuis l'application. | « Le parcours public part toujours d'un lien géré dans les réglages. Je ne fabrique pas son adresse à la main. » | Masquer les réglages qui ne servent pas à l'explication et ne jamais montrer un lien de kiosque signé. |
| 12 | G05-S12 | Ouvrir l'URL dans une session invitée propre; choisir Consultation couleur. | Étape 1. | Le service autorisé est sélectionné. | « Le visiteur n'accède pas à notre modale interne. Il suit un parcours public guidé. » | Si le lien retourne 404, vérifier qu'il est actif et que le module Réservations est disponible. |
| 13 | G05-S13 | Choisir une date marquée disponible, continuer puis choisir une heure. | Créneau public futur. | L'heure apparaît parmi les créneaux retournés. | « Les dates et heures proposées viennent des disponibilités réelles. Une heure disparue ne doit jamais être forcée. » | Garder un second créneau public libre pour la reprise. |
| 14 | G05-S14 | Choisir `Première personne disponible`. | Affectation auto. | Une personne disponible sera résolue à la soumission. | « Le client peut laisser l'application affecter la première personne libre ou choisir une personne précise quand l'option est proposée. » | Si une personne précise est choisie, la narration et le récapitulatif doivent être adaptés. |
| 15 | G05-S15 | Saisir Mila Tremblay, téléphone, courriel example.test et message; continuer au récapitulatif. | Données publiques canoniques. | Les six étapes sont complétées; avis de confirmation manuelle visible. | « Prénom, nom, téléphone et courriel sont obligatoires. Le message est facultatif et deviendra une note de la réservation. » | Fermer tout autoremplissage contenant une donnée personnelle. |
| 16 | G05-S16 | Cliquer `Confirmer la réservation`. | Lien en confirmation manuelle. | Réponse créée et résumé affiché avec un message indiquant que l'entreprise confirmera. | « Ce lien demande une validation humaine : l'écran confirme l'envoi de la demande, pas un rendez-vous déjà confirmé. Nous vérifierons le statut dans l'espace interne. » | Le message serveur est actuellement anglais; ne pas le traduire au montage. Si l'écran annonce une confirmation immédiate, le lien n'est pas configuré comme prévu. |
| 17 | G05-S17 | Revenir à la session interne, actualiser puis ouvrir la demande Mila. | Réservation publique En attente. | Cartouche `Réservation publique`, contact et nom du lien visibles. | « Dans l'espace interne, Mila arrive comme contact public lié à un prospect et au lien utilisé. » | Rechercher par Mila ou par l'heure si le calendrier est chargé. |
| 18 | G05-S18 | Lancer `Vérifier` dans Conversion client, montrer les correspondances ou le formulaire, puis fermer sans convertir. | Aucun client Mila créé. | Zone de conversion visible; réservation toujours liée au prospect. | « Malikia Pro nous demande de vérifier les doublons avant de lier ou créer une fiche. G05 s'arrête ici : aucune fiche client n'est créée automatiquement. » | Ne pas cliquer `Créer le client`; ce serait une mutation hors périmètre. |

## Script continu — master

> Dans cette vidéo, nous allons planifier un rendez-vous interne complet, provoquer un conflit contrôlé pour comprendre la protection du planning, puis comparer ce parcours à une demande publique.
>
> Nora Bouchard souhaite une consultation couleur avec Léa Moreau. J'ouvre Réservations et je cadre le jour que j'ai préparé. La date exacte peut changer d'une prise à l'autre; ce qui compte est qu'elle soit future et que Léa ait une vraie disponibilité.
>
> Je clique sur Nouvelle réservation. La création s'ouvre dans une modale, au-dessus du calendrier. Je sélectionne Léa, Nora et Consultation couleur. Le membre est indispensable. La cliente et le service donnent à l'équipe un contexte exploitable et relient les épisodes précédents.
>
> Je saisis le début, puis la fin trente minutes plus tard. Je mets aussi trente dans Durée et je garde le statut Confirmée. La cohérence est importante : quand une fin est fournie, la durée enregistrée correspond à la différence réelle entre le début et la fin.
>
> Les deux notes n'ont pas le même usage. La première accompagne le client dans son espace. La note interne sert à préparer le service pour l'équipe. Aucune ne doit contenir une donnée inutilement sensible.
>
> Je vais d'abord choisir un créneau déjà occupé. La saisie est possible, mais à la soumission l'application revérifie les horaires, les exceptions, les rendez-vous actifs et les tampons. Le message indique que le créneau n'est plus disponible et aucune deuxième réservation n'est créée.
>
> Je remets maintenant le créneau libre préparé. Les autres données sont inchangées. Je clique sur Créer : la modale se ferme et Nora apparaît dans le calendrier. J'ouvre le rendez-vous pour contrôler la plage, le service, Léa, le statut et les notes. Je passe ensuite en vue Liste pour retrouver la même réservation sous une autre forme.
>
> Comparons avec le parcours public. Dans Réglages Réservations, je copie un lien actif limité à Consultation couleur et configuré avec confirmation manuelle. Je l'ouvre dans une session invitée.
>
> Le visiteur choisit d'abord le service, puis une date, une heure et éventuellement une personne. Je garde Première personne disponible : l'application choisira une membre réellement disponible lorsque la demande sera envoyée.
>
> J'utilise Mila Tremblay, un contact fictif distinct de Nora. Le prénom, le nom, le téléphone et le courriel sont obligatoires. Le message est optionnel. Le récapitulatif rappelle aussi que le salon doit confirmer la demande.
>
> Après le clic, l'écran indique que la demande a été envoyée et que l'entreprise la confirmera. Le message serveur est actuellement en anglais : je ne le remplace pas artificiellement. De retour dans l'espace interne, le badge En attente fournit la preuve du statut, avec le contact et le lien public. Mila est un prospect, pas une nouvelle fiche client. La zone Conversion client nous invite à rechercher un doublon avant de lier ou créer une fiche. Je ne lance pas cette conversion dans cet épisode.
>
> Nous avons donc deux preuves différentes : Nora est une cliente existante réservée directement et confirmée; Mila est une demande publique en attente, liée à un prospect jusqu'à une décision de l'équipe.

## Raccords et montage

- Couper les temps de chargement entre la sélection de date et les créneaux publics, mais conserver un bref indicateur de chargement pour ne pas faire croire à des horaires statiques.
- Afficher un cartouche `PARCOURS INTERNE` pour G05-S01 à S10 et `PARCOURS PUBLIC` pour G05-S11 à S18.
- Pendant le conflit, cadrer le message réel et le champ Début; ne pas recréer l'erreur en postproduction.
- Dans G05-S16, faire lire l'avis de confirmation manuelle avant le bouton final.
- Dans G05-S18, cadrer la zone Conversion client sans afficher de donnée autre que Mila et sans déclencher la conversion.

## Critère de fin de prise

La prise est exploitable seulement si :

1. le conflit a été réellement refusé et n'a créé aucune ligne ;
2. la réservation Nora apparaît ensuite une seule fois dans calendrier, liste et détails ;
3. la demande Mila affiche En attente et le cartouche public ;
4. Mila n'existe pas dans Clients à la fin ;
5. tous les courriels ont été captés localement et aucun destinataire réel n'a été utilisé.
