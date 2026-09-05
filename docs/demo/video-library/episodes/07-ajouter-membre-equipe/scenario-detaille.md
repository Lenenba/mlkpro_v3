# G07 — Scénario détaillé de tournage

Dernière mise à jour : 2026-08-11<br>
Source : [fiche maître G07](../07-ajouter-membre-equipe.md)

## Intention de la prise

L'épisode doit rendre visibles les décisions d'accès avant de cliquer Ajouter. La création d'Emma n'est valide que si son rôle a été relu, si l'adresse est fictive et si le dispatch d'invitation ne peut sortir de l'environnement local.

## Pas-à-pas de tournage

| Étape | Capture | Action exacte | Valeur ou état | Réponse attendue | Narration principale | Erreur ou reprise |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | G07-S01 | Ouvrir `/team`; rechercher `Emma Laurent`, puis `emma.laurent@example.test`. | Aucun résultat. | Emma absente de la liste. | « Avant de créer un accès, je vérifie le nom et l'adresse. Un compte existe parfois déjà sous un autre titre. » | Si Emma existe, utiliser un clone propre; ne pas modifier un ancien compte pour faire semblant d'une création. |
| 2 | G07-S02 | Ouvrir `/settings/roles-permissions`; sélectionner Praticienne salon — accès limité. | Permissions canoniques du rôle. | Les accès utiles sont cochés; finance, réglages, rôles et vue globale restent absents. | « Le rôle est préparé avant d'ajouter Emma. Je valide ce qu'il autorise réellement, pas seulement son nom. » | Si le rôle manque, le créer hors caméra ou reporter la prise; ne pas cocher au hasard pendant G07. |
| 3 | G07-S03 | Revenir à `/team`; cliquer `Ajouter un membre`; attendre deux secondes. | Modale vierge. | Titre Ajouter un membre et champs visibles. | « L'ajout se fait dans une modale sur la page Équipe, pas dans une page de création séparée. » | Le bouton nécessite que l'acteur puisse vraiment créer; un 403 n'est pas une erreur de formulaire. |
| 4 | G07-S04 | Saisir Emma et son courriel final; sélectionner une icône prédéfinie. | Emma Laurent, example.test, avatar preset. | Identité complète sans donnée réelle. | « Le nom et le courriel sont obligatoires. J'utilise une icône et une adresse de démonstration, jamais une photo ou un courriel personnel. » | Fermer l'autoremplissage et ne pas téléverser un fichier local réel. |
| 5 | G07-S05 | Choisir Profil opérationnel `Membre d'équipe`; choisir Rôle d'accès `Praticienne salon — accès limité`. | Deux sélecteurs distincts. | Les deux valeurs restent sélectionnées. | « Le profil décrit la famille opérationnelle. Le rôle d'accès porte les permissions précises. Administrateur serait trop large pour Emma. » | Si le rôle d'accès n'est pas visible, l'acteur n'a pas `assign_roles`; changer d'acteur avant la prise. |
| 6 | G07-S06 | Saisir titre et téléphone. | Esthéticienne, 514-555-0178. | Valeurs acceptées. | « Le titre aide l'équipe à comprendre la fonction. Le téléphone reste une donnée fictive dans le clone. » | Ne pas annoncer ces champs comme obligatoires. |
| 7 | G07-S07 | Saisir les règles de planning. | 15, 4, 8, 32. | Aucune erreur. | « Ces valeurs posent des limites de planification. Elles ne créent pas les journées de travail ni les créneaux réservables d'Emma. » | Si max jour est inférieur à min jour, corriger; ne pas masquer le message. |
| 8 | G07-S08 | Faire défiler jusqu'au texte sur l'invitation et aux boutons. | Formulaire final avant erreur contrôlée. | Texte `Un lien de connexion sera envoyé...` lisible. | « La création tente toujours d'envoyer un lien pour définir le mot de passe. Il n'y a pas de bouton pour désactiver cette étape; notre transport local est donc déjà vérifié. » | Ne pas cliquer avant la vérification du transport. |
| 9 | G07-S09 | Remplacer le courriel par une adresse `example.test` appartenant déjà à un utilisateur de test; soumettre. | Adresse existante relevée avant la prise. | Erreur d'unicité sur Email; aucun nouveau membre. | « L'adresse doit être unique parmi tous les utilisateurs, pas seulement dans la liste Équipe. » | Ne pas utiliser l'adresse réelle d'une personne; si l'erreur ne vient pas, le compte de test n'était pas présent. |
| 10 | G07-S10 | Restaurer `emma.laurent@example.test`; relire profil, rôle et planning. | Données canoniques. | Formulaire prêt. | « Je remets l'adresse réservée à Emma et je vérifie que l'erreur n'a pas changé le rôle ou les règles. » | Vérifier que l'avatar et le rôle n'ont pas été réinitialisés. |
| 11 | G07-S11 | Cliquer `Ajouter un membre`. | Soumission valide. | Retour à `/team`; succès si dispatch accepté ou avertissement si dispatch échoue. | « Le membre est créé avant le résultat du dispatch. Je lis le message exact : mis en file dans notre environnement local, ou créé avec un avertissement. » | Ne pas dire « courriel livré » sur la seule base du flash. |
| 12 | G07-S12 | Rechercher Emma dans la liste. | Emma active. | Ligne avec nom, profils, titre, téléphone et statut Actif. | « Emma est maintenant une membre active du workspace. » | Si la ligne n'apparaît pas, vérifier le filtre et le résultat du POST avant toute reprise. |
| 13 | G07-S13 | Ouvrir Actions puis Détails. | Emma. | Rôle, statut et permissions effectives visibles. | « Je contrôle les permissions effectives. Elles viennent du rôle d'accès et restent limitées au besoin métier annoncé. » | Si une permission finance ou administration apparaît, le rôle préparé est incorrect; ne pas publier. |
| 14 | G07-S14 | Ouvrir la liste du capteur de courriels local; cadrer seulement destinataire, sujet et état. | emma.laurent@example.test. | Une invitation locale, aucun contenu ni token visibles. | « La tentative d'invitation est captée localement. Cette preuve ne montre aucun jeton et aucun message n'est parti vers un fournisseur externe. » | Ne jamais ouvrir le corps du message dans la capture; si le transport n'est pas local, la prise est bloquée. |
| 15 | G07-S15 | Ouvrir une nouvelle réservation et afficher Emma dans le sélecteur Membre; fermer sans enregistrer. | Emma Laurent. | Emma est proposée comme membre actif. | « Emma peut maintenant être sélectionnée, mais cela ne signifie pas encore qu'elle possède des disponibilités. Le planning hebdomadaire sera configuré séparément. » | Ne pas soumettre : sans disponibilité, la réservation devrait être refusée. |

## Script continu — master

> Dans cette vidéo, nous allons ajouter Emma Laurent à Salon Éclat, lui attribuer un accès limité et vérifier que l'invitation reste entièrement dans notre environnement de démonstration.
>
> Je commence dans Équipe en recherchant son nom puis son courriel. Cette vérification évite de créer deux comptes pour une personne déjà invitée sous un autre titre.
>
> Avant d'ouvrir le formulaire, je relis le rôle Praticienne salon — accès limité dans Rôles et permissions. Emma peut voir les informations nécessaires à ses services et ses propres réservations. Elle ne peut pas gérer les finances, les rôles, les réglages ou toutes les réservations du salon. Le nom du rôle est utile, mais ce sont ses permissions qui font foi.
>
> De retour dans Équipe, je clique sur Ajouter un membre. La création s'ouvre dans une modale. Je saisis Emma Laurent et son adresse example.test, puis je choisis une icône prédéfinie.
>
> Voici la distinction la plus importante. Le profil opérationnel propose Administrateur, Membre d'équipe, Vendeur ou Responsable des ventes. Je choisis Membre d'équipe. Le rôle d'accès définit les permissions détaillées; je sélectionne Praticienne salon. Choisir Administrateur pour résoudre un problème d'accès serait trop large.
>
> J'ajoute le titre Esthéticienne et un téléphone de démonstration. Puis je renseigne une pause de quinze minutes, quatre heures minimum par jour, huit heures maximum par jour et trente-deux heures maximum par semaine. Ces règles encadrent la planification; elles ne créent pas les disponibilités hebdomadaires.
>
> En bas du formulaire, l'application annonce qu'un lien de connexion sera envoyé pour définir le mot de passe. Il n'existe pas de bouton pour désactiver l'invitation. Avant ce tournage, j'ai donc isolé le transport de courriel dans un capteur local.
>
> Je montre une erreur utile avec une adresse de test déjà présente. L'application refuse le doublon parce que le courriel est unique pour tous les utilisateurs. Je restaure maintenant emma.laurent@example.test et je relis le rôle ainsi que les règles.
>
> Je clique sur Ajouter un membre. Le compte est créé avec un mot de passe aléatoire inconnu, le membre devient actif et l'invitation est préparée pour définir son accès. Je lis le message exact de l'interface sans confondre mise en file et livraison réelle.
>
> Emma apparaît dans la liste avec son titre et son statut Actif. Dans Détails, je vérifie son profil, son rôle et les permissions effectives. Aucune zone finance ou administration n'a été ouverte.
>
> Enfin, le capteur local montre une invitation destinée à l'adresse example.test, sans afficher son contenu ni son jeton. Dans une nouvelle réservation, Emma apparaît comme membre actif. Je ferme sans enregistrer : être active ne lui crée pas encore d'horaires disponibles.

## Raccords et montage

- Afficher un cartouche `PRÉPARATION DU RÔLE` sur G07-S02, puis `AJOUT DU MEMBRE` sur G07-S03 à S13.
- Garder les deux sélecteurs Profil opérationnel et Rôle d'accès dans le même cadre pour éviter de les confondre.
- Masquer les autres comptes dans la liste du capteur local; seul le destinataire example.test et le sujet sont utiles.
- Ne jamais filmer le corps de l'invitation, le token de réinitialisation ou l'URL d'action.
- Dans G07-S15, conserver une incrustation `Membre actif ≠ créneaux configurés`.

## Critère de fin de prise

La prise est exploitable seulement si :

1. Emma était absente avant et apparaît une seule fois après ;
2. son profil est Membre d'équipe et son rôle est Praticienne salon ;
3. ses permissions effectives correspondent à la préparation ;
4. aucune permission sensible inattendue n'est visible ;
5. l'invitation a été interceptée localement sans exposition du token ;
6. aucune disponibilité ou livraison externe n'est prétendue sans preuve.
