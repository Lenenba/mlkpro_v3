# G07 — Guide des champs, rôles et invitation

Dernière mise à jour : 2026-08-11

## 1. Accès à la page et aux actions

| Action | Module / permission | Remarque |
| --- | --- | --- |
| Ouvrir `/team` | Module `team_members` + `view_team_members` | Le profil Coiffeur système n'a pas cette permission par défaut. |
| Ajouter | `create_team_members` | La limite du plan est contrôlée avant la création. |
| Attribuer un rôle d'accès | `assign_roles` | Le sélecteur Rôle d'accès est masqué dans l'interface sans cette permission. |
| Préparer ou modifier un rôle | `manage_roles_permissions` | Route distincte `/settings/roles-permissions`. |
| Modifier un membre | `update_team_members` | Le changement de rôle exige aussi `assign_roles`. |
| Désactiver | `deactivate_team_members` | La suppression logique rend le membre inactif; elle ne supprime pas son utilisateur. |

Pour une démonstration stable, utiliser le propriétaire. Une personne qui peut seulement voir l'équipe ne doit pas être présentée comme capable d'ajouter ou d'attribuer des rôles.

## 2. Champs de création

| Libellé visible | Clé | Obligatoire | Règles serveur | Valeur G07 |
| --- | --- | --- | --- | --- |
| Nom | `name` | Oui | Texte, 255 caractères max. | Emma Laurent |
| Email | `email` | Oui | Minuscules, courriel valide, 255 caractères max., unique dans `users`. | emma.laurent@example.test |
| Photo | `profile_picture` | Non | Image JPG, JPEG ou PNG, 2 Mo max. | Non utilisée |
| Icône d'avatar | `avatar_icon` | Non | Doit appartenir aux icônes prédéfinies. | Icône prédéfinie |
| Profil opérationnel | `role` | Oui | `admin`, `member`, `seller` ou `sales_manager`. | member |
| Rôle d'accès | `company_role_id` | Non côté serveur | Rôle actif, système ou appartenant au compte; attribution protégée. | Praticienne salon |
| Titre | `title` | Non | Texte, 255 caractères max. | Esthéticienne |
| Téléphone | `phone` | Non | Texte, 255 caractères max. | 514-555-0178 |
| Pause | `planning_rules.break_minutes` | Non | Entier de 0 à 240. | 15 |
| Min heures/jour | `planning_rules.min_hours_day` | Non | Nombre de 0 à 24. | 4 |
| Max heures/jour | `planning_rules.max_hours_day` | Non | Nombre de 0 à 24 et supérieur ou égal au minimum. | 8 |
| Max heures/semaine | `planning_rules.max_hours_week` | Non | Nombre de 0 à 168. | 32 |

La création ne rend pas :

- de mot de passe ;
- de toggle Actif ;
- de cases de permissions directes ;
- de grille de disponibilités hebdomadaires.

Le champ Nouveau mot de passe et le toggle Actif apparaissent seulement lors de la modification.

## 3. Profil opérationnel

| Valeur | Usage | Risque de sur-attribution |
| --- | --- | --- |
| Administrateur | Gestion opérationnelle large. | Certaines politiques, dont Réservations, lui accordent la gestion même sans permission fine correspondante. |
| Membre d'équipe | Collaborateur standard. | Choix recommandé pour Emma avec un rôle d'accès limité. |
| Vendeur (POS) | Vente et point de vente. | Ne convient pas au scénario esthéticienne. |
| Responsable des ventes | Gestion commerciale. | Trop large et hors métier pour Emma. |

Si aucun rôle d'accès et aucune permission directe ne sont fournis, le serveur applique un ensemble de permissions par défaut selon ce profil, filtré par les modules actifs. Avec un rôle d'accès sélectionné dans le formulaire, G07 s'appuie sur ce rôle et laisse les permissions directes vides.

## 4. Rôle d'accès et permissions effectives

Un rôle d'accès peut être :

- un rôle système actif ;
- un rôle personnalisé actif appartenant à l'entreprise.

Les permissions effectives du membre sont l'union :

1. des permissions directes stockées sur le membre ;
2. des permissions du rôle d'accès actif.

La modale de création actuelle ne permet pas de cocher des permissions directes. Elle propose le rôle d'accès seulement si l'acteur possède `assign_roles`. Les permissions détaillées se préparent dans les réglages.

Quand un rôle est modifié lors d'une édition et qu'aucune permission directe n'est envoyée, les permissions directes du membre sont vidées. Cette règle évite de conserver silencieusement d'anciens accès en plus du nouveau rôle.

## 5. Modules actifs

Les anciennes permissions directes proposées par le contrôleur sont filtrées selon les modules actifs du compte : réservations, ventes, campagnes, social, dépenses, comptabilité, factures, tâches, jobs, devis et prospects.

Une permission envoyée pour un module indisponible est refusée. Un rôle système peut néanmoins contenir des permissions couvrant plusieurs modules; les routes restent aussi protégées par leurs modules. Pour une démonstration lisible, un rôle personnalisé réduit évite d'afficher des droits sans rapport avec Salon Éclat.

## 6. Compte utilisateur créé

Après validation, le serveur :

1. crée ou récupère le rôle global `employee` ;
2. crée un utilisateur avec le nom et le courriel ;
3. génère un mot de passe aléatoire de 32 caractères puis le hache ;
4. marque le courriel comme vérifié ;
5. active `must_change_password` ;
6. crée un `TeamMember` actif lié au compte ;
7. crée un token via le broker de mots de passe ;
8. tente de dispatcher `InviteUserNotification`.

Le membre n'a pas besoin de connaître le mot de passe aléatoire. Le lien d'invitation sert à définir son accès. Lors d'une connexion avec `must_change_password` encore actif, l'application redirige vers le profil pour imposer la mise à jour.

## 7. Invitation

L'invitation est une notification mise en queue et envoyée par courriel. Elle contient un lien de réinitialisation avec token et adresse courriel.

Points de vérité :

- la création n'offre aucun opt-out ;
- un résultat de dispatch positif permet le message « invitation envoyée », mais ne prouve pas une livraison externe ;
- si le dispatch lève une erreur, le membre reste créé et l'application revient avec un avertissement ;
- le token et le lien ne doivent jamais être capturés ou versionnés.

Pour G07, le transport doit être local ou inerte, l'adresse doit être `example.test`, et la capture éventuelle du capteur se limite à la liste des messages.

## 8. Redirection et preuve

La soumission POST utilise `/team`. En réponse web, le contrôleur redirige vers la page précédente avec un flash de succès ou d'avertissement. Côté Vue, la modale se ferme et le formulaire est réinitialisé après un succès Inertia.

La preuve complète comporte :

- la ligne Emma active dans la liste ;
- la fenêtre Détails avec le rôle et les permissions effectives ;
- l'invitation captée localement sans token ;
- Emma disponible dans un sélecteur Membre, sans prétendre qu'elle possède déjà des horaires.
