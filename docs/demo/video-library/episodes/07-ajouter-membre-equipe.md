# G07 — Ajouter un membre à l'équipe

Dernière mise à jour : 2026-08-11<br>
Niveau : débutant à intermédiaire<br>
Public : propriétaire, responsable de salon, administrateur des accès<br>
Durée du master pédagogique : 10 à 12 minutes<br>
Durée de la capsule dérivée : 3 à 4 minutes

## État réel de production

| Élément | État | Preuve |
| --- | --- | --- |
| Formulaire et redirection | Audités | `TeamTable.vue` et `TeamMemberController.php` |
| Rôles et permissions | Audités | `PermissionCatalog.php`, `TeamMember.php` et tests RBAC |
| Invitation | Auditée | `InviteUserNotification.php` et `NotificationDispatcher.php` |
| Exemple de données | Prêt | Emma Laurent et rôle Praticienne salon |
| Script détaillé | Prêt | [Scénario de tournage](07-ajouter-membre-equipe/scenario-detaille.md) |
| Plan des captures | Prêt | [Shot-list G07](07-ajouter-membre-equipe/shot-list.csv) |
| PNG de l'interface | À produire | [Galerie G07](../captures/G07/README.md) |
| QA finale | En attente des captures | [Checklist G07](07-ajouter-membre-equipe/qa.md) |

Le mot **capture** désigne un état à produire tant que le PNG n'existe pas et n'a pas passé la QA. Aucun visuel simulé ne remplace l'interface réelle.

### Dette d'interface avant validation des PNG

Les flashs serveur après création sont actuellement en anglais : `Team member created. Invite sent by email.` ou, en cas d'échec du dispatch, `Team member created, but the invite email could not be sent.`. Ils ne doivent pas être masqués ou traduits artificiellement dans les captures. Une série entièrement francophone exige leur localisation applicative avant de valider G07-S11, sauf décision éditoriale explicite.

## Question et résultat promis

**Question :** comment ajouter une collaboratrice sans lui donner trop de droits et sans envoyer un courriel vers l'extérieur pendant la démonstration ?

À la fin du master, **Emma Laurent** apparaît active dans `/team`, avec :

- le profil opérationnel **Membre d'équipe** ;
- le titre **Esthéticienne** ;
- le rôle d'accès préparé **Praticienne salon — accès limité** ;
- des règles de planning explicites mais aucune fausse disponibilité créée ;
- une invitation interceptée exclusivement par le transport local de test.

## Objectifs pédagogiques observables

Après la vidéo, la personne doit pouvoir :

1. vérifier que l'adresse n'appartient pas déjà à un utilisateur ;
2. distinguer le **profil opérationnel** du **rôle d'accès** ;
3. sélectionner un rôle préparé selon le principe du minimum nécessaire ;
4. comprendre que les permissions effectives viennent du rôle et, éventuellement, de permissions directes ;
5. saisir des règles de planning valides sans les confondre avec des disponibilités hebdomadaires ;
6. comprendre ce que la création fait au compte utilisateur et à l'invitation ;
7. vérifier le membre et ses permissions effectives après la redirection ;
8. empêcher tout envoi vers une infrastructure de courriel externe pendant le tournage.

## Situation métier

Emma Laurent rejoint Salon Éclat comme esthéticienne à temps partiel. Elle doit voir ses clientes, ses services et ses propres réservations, mais ne doit pas administrer les finances, les rôles, les réglages généraux ou toutes les réservations du salon.

| Avant | Après |
| --- | --- |
| Emma est absente de l'équipe et ne possède aucun compte dans le clone. | Emma possède un compte membre actif, un rôle limité et une invitation captée localement. |

## Périmètre

Le master couvre :

- la préparation et la lecture d'un rôle d'accès existant ;
- la création depuis la modale de `/team` ;
- l'identité, l'avatar, le profil, le rôle, le titre, le téléphone et les règles de planning ;
- une erreur réelle de courriel déjà utilisé ;
- la création, le retour à la liste et le détail des permissions effectives ;
- la preuve que l'invitation reste dans le transport local.

Il ne couvre pas la création détaillée du rôle, la définition d'un mot de passe depuis le lien, la double authentification, la création de disponibilités, la présence, la paie, la désactivation ou le remplacement d'un membre. Ces sujets nécessitent leurs propres capsules.

## Préparation reproductible

- Utiliser un clone jetable de **Salon Éclat** avec le module Membres d'équipe actif.
- Se connecter comme propriétaire. Pour un gestionnaire délégué, il faut au minimum `view_team_members`, `create_team_members` et `assign_roles` ; la création du rôle exige séparément `manage_roles_permissions`.
- Vérifier la limite de membres du plan avant la prise.
- Rechercher `Emma Laurent`, puis `emma.laurent@example.test`, dans `/team` et dans le jeu de comptes de démonstration. Emma doit être absente.
- Préparer dans `/settings/roles-permissions` un rôle d'entreprise actif nommé **Praticienne salon — accès limité**.
- Vérifier que ce rôle contient seulement les permissions nécessaires décrites ci-dessous et qu'il n'est assigné à aucun ancien compte Emma.
- Configurer un transport de courriel local ou inerte et prouver qu'aucun worker ne livre vers un fournisseur externe. La création ne possède pas de bouton « ne pas inviter » : elle tente toujours de préparer et dispatcher l'invitation.
- Garder une adresse `example.test`; ne jamais saisir l'adresse réelle d'une collaboratrice.
- Conserver le navigateur en français, thème clair, zoom 100 %, viewport 1920 × 1080.

## Rôle d'accès préparé

Le rôle **Praticienne salon — accès limité** est préparé avant la prise avec les permissions suivantes :

| Groupe | Permission | Pourquoi |
| --- | --- | --- |
| Clients | Voir les clients | Retrouver la personne liée à son service. |
| Clients | Voir les notes client | Lire le contexte nécessaire au rendez-vous. |
| Services | Voir les services | Identifier la prestation assignée. |
| Réservations | Voir ses propres réservations | Limiter la portée au travail d'Emma. |
| Réservations | Mettre à jour les réservations | Permettre les actions prévues sur ses rendez-vous sans accorder la gestion globale. |
| Présence | Voir la présence | Accéder à son contexte de présence. |
| Présence | Gérer sa propre présence | Pointer pour elle-même uniquement. |

Le rôle exclut explicitement : gestion des rôles, réglages, finance, factures, dépenses, rapports globaux, création ou annulation de toutes les réservations, gestion de l'équipe et accès à toutes les réservations.

Les libellés exacts dépendent de la traduction affichée dans l'écran Rôles et permissions. La preuve fonctionnelle repose sur les permissions effectives, pas seulement sur le nom du rôle.

## Exemple concret — Emma Laurent

| Champ visible | Valeur | Statut | Pourquoi ce choix |
| --- | --- | --- | --- |
| Nom | Emma Laurent | Obligatoire, 255 caractères max. | Identité fictive stable de la série. |
| Email | emma.laurent@example.test | Obligatoire, unique dans tous les utilisateurs | Domaine réservé; aucun destinataire réel. |
| Photo ou icône | Icône prédéfinie | Facultatif | Évite une vraie photo et un fichier personnel. |
| Profil opérationnel | Membre d'équipe | Obligatoire | Évite le raccourci large du profil Administrateur. |
| Rôle d'accès | Praticienne salon — accès limité | Conditionnel à `assign_roles` | Applique la permission minimale préparée. |
| Titre | Esthéticienne | Facultatif, 255 caractères max. | Fonction lisible dans la liste. |
| Téléphone | 514-555-0178 | Facultatif, 255 caractères max. | Numéro de démonstration. |
| Pause | 15 minutes | Facultatif, 0 à 240 | Règle de planification interne. |
| Min heures/jour | 4 | Facultatif, 0 à 24 | Seuil compatible avec un horaire à temps partiel. |
| Max heures/jour | 8 | Facultatif, 0 à 24 | Supérieur au minimum. |
| Max heures/semaine | 32 | Facultatif, 0 à 168 | Limite illustrative cohérente. |

La création ne demande aucun mot de passe visible et ne propose aucun toggle Actif. Le serveur crée un mot de passe aléatoire inconnu, marque le compte comme devant changer son mot de passe et crée le membre actif.

## Parcours de tournage en 15 plans

| Temps | Capture | Action et point à expliquer | Preuve attendue |
| --- | --- | --- | --- |
| 00:00–00:40 | G07-S01 | Rechercher Emma par nom et courriel. | Emma absente avant création. |
| 00:40–01:30 | G07-S02 | Ouvrir le rôle Praticienne salon dans les réglages. | Permissions minimales et exclusions visibles. |
| 01:30–02:00 | G07-S03 | Revenir à `/team` et ouvrir Ajouter un membre. | Modale réelle, aucune route `/team/create`. |
| 02:00–02:50 | G07-S04 | Saisir nom, courriel final et choisir une icône. | Identité fictive et avatar sans photo réelle. |
| 02:50–04:05 | G07-S05 | Choisir Membre d'équipe puis Praticienne salon. | Profil et rôle séparés. |
| 04:05–04:45 | G07-S06 | Saisir titre et téléphone. | Fonction et coordonnée de démo. |
| 04:45–05:45 | G07-S07 | Saisir les quatre règles de planning. | Valeurs valides et cohérentes. |
| 05:45–06:25 | G07-S08 | Montrer le texte d'invitation et les actions. | Conséquence annoncée avant le clic. |
| 06:25–07:05 | G07-S09 | Remplacer temporairement le courriel par une adresse test déjà utilisée et soumettre. | Erreur d'unicité réelle, aucun membre Emma. |
| 07:05–07:35 | G07-S10 | Restaurer `emma.laurent@example.test` et relire. | Formulaire corrigé, rôle intact. |
| 07:35–08:15 | G07-S11 | Ajouter le membre. | Retour à `/team`, succès ou avertissement honnête. |
| 08:15–08:55 | G07-S12 | Rechercher Emma dans la liste. | Emma active, titre et rôles visibles. |
| 08:55–09:45 | G07-S13 | Ouvrir Détails du membre. | Profil, rôle et permissions effectives. |
| 09:45–10:25 | G07-S14 | Ouvrir le capteur de courriels local, liste seulement. | Invitation adressée à example.test, aucun transport externe. |
| 10:25–11:15 | G07-S15 | Montrer Emma dans le sélecteur Membre d'une réservation sans enregistrer. | Membre actif réutilisable; disponibilité non prétendue. |

Le détail du texte, des reprises et du cadrage figure dans [le scénario complet](07-ajouter-membre-equipe/scenario-detaille.md).

## Les neuf subtilités à ne pas rater

1. **Profil opérationnel et rôle d'accès ne sont pas la même chose.** Le premier contient les valeurs Administrateur, Membre d'équipe, Vendeur et Responsable des ventes; le second est un rôle RBAC préparé dans les réglages.
2. **Administrateur est un choix large.** Certaines politiques lui accordent directement la gestion, notamment sur les réservations. Emma doit rester Membre d'équipe.
3. **Le rôle d'accès n'apparaît qu'avec `assign_roles`.** Sans cette permission, le gestionnaire peut voir un formulaire différent et ne doit pas prétendre avoir choisi le rôle.
4. **La modale ne possède pas de cases de permissions directes.** La démonstration sélectionne un rôle existant; les permissions se gèrent dans Réglages.
5. **Les permissions disponibles suivent les modules actifs.** Le serveur refuse une permission directe appartenant à un module non disponible.
6. **Le courriel est unique dans tous les utilisateurs.** Une adresse de client portail ou d'un autre membre peut bloquer la création.
7. **Les règles de planning ne créent pas d'horaires.** Emma n'aura des créneaux réservables qu'après configuration de ses disponibilités hebdomadaires.
8. **L'invitation est toujours tentée.** Le formulaire n'offre aucun opt-out; le transport local est donc une condition de sécurité, pas une préférence de montage.
9. **Un échec d'invitation ne supprime pas Emma.** La création peut réussir avec un avertissement si le dispatch échoue. Le message doit être montré honnêtement.

## Version courte dérivée

La capsule de 3 à 4 minutes conserve G07-S01, S03 à S08 et S11 à S13. Elle explique profil, rôle, invitation et preuve finale. L'erreur d'unicité, le capteur local et la nuance sur les disponibilités restent dans le master.

## Dossier de production

- [Scénario détaillé et narration](07-ajouter-membre-equipe/scenario-detaille.md)
- [Guide exhaustif des champs](07-ajouter-membre-equipe/guide-champs.md)
- [Variantes, erreurs et décisions](07-ajouter-membre-equipe/variantes-erreurs.md)
- [Shot-list CSV](07-ajouter-membre-equipe/shot-list.csv)
- [QA fonctionnelle et média](07-ajouter-membre-equipe/qa.md)
- [Galerie des captures G07](../captures/G07/README.md)
- [Données communes de la série](../shared-data.md)

## Références croisées

- Avant : `G01 — Terminer l'onboarding`
- Utilisé par : `G05 — Créer une réservation`, planning, présence et file salon.
- Approfondissements à prévoir : créer un rôle, disponibilités hebdomadaires, première connexion, modifier ou désactiver un membre.

## Sources techniques auditées

- Routes Équipe et Rôles : `routes/web.php`
- Interface : `resources/js/Pages/Team/UI/TeamTable.vue`
- Création, validation, limite et redirection : `TeamMemberController.php`
- Permissions effectives : `TeamMember.php`, `PermissionCatalog.php` et `AccessControl.php`
- Invitation : `InviteUserNotification.php`, `NotificationDispatcher.php` et `WebLoginResponseService.php`
- Tests de preuve : `TeamMemberPermissionModulesTest.php` pour le filtrage des modules et le refus des permissions indisponibles; `RbacPhaseThreeRolesPermissionsTest.php` pour la protection des rôles, `assign_roles` et la séparation entre permission scoped et gestion globale.
