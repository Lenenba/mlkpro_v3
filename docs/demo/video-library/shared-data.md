# Données communes de la série vidéo

Dernière mise à jour : 2026-08-11

## Choix de cohérence

La première série utilise **Salon Éclat** comme fil rouge visuel. Les gestes restent généralistes, mais cette continuité évite que le nom de l'entreprise, les clients et les prestations changent d'une vidéo à l'autre. Les futures séries métier peuvent citer ces épisodes même si leur propre jeu de données diffère.

Le preset canonique est `salon_eclat_complete`, provisionné depuis **Super Admin → Espaces de démo**. Ne pas utiliser les anciennes commandes `demo:seed` ou `demo:reset`.

## Identité visible

| Élément | Valeur de démonstration |
| --- | --- |
| Entreprise | Salon Éclat |
| Type | Entreprise de services |
| Secteur | Salon de coiffure / beauté |
| Ville | Montréal |
| Pays | Canada |
| Devise | CAD |
| Langue | Français |
| Fuseau | America/Toronto |
| Propriétaire | Amina Diallo |

`G01 — Onboarding` recrée cette identité à l'écran. Les épisodes suivants utilisent de préférence un workspace prérempli afin d'éviter la saisie répétitive.

### Compte de tournage G01

| Champ | Valeur |
| --- | --- |
| Nom | Amina Diallo |
| Courriel | amina.diallo.onboarding@example.test |
| Mot de passe | Secret local non versionné |
| Taille prévue | 3 personnes |
| Audience du forfait | Team |
| Période | Mensuelle |
| Invitations finales | Aucune ; elles sont traitées dans G07 |
| 2FA | Code par courriel |

Le workspace créé pendant G01 est jetable. Ne pas le confondre avec le preset prérempli utilisé par G02 à G08. Le premier accès au tableau de bord est précédé du challenge 2FA.

## Données à utiliser dans les épisodes courts

### Cliente créée dans G03

| Champ | Valeur |
| --- | --- |
| Type | Particulier |
| Prénom | Nora |
| Nom | Bouchard |
| Courriel | nora.bouchard@example.test |
| Téléphone | +1 514 555-0147 |
| Date de naissance | 1993-04-18 |
| Adresse fictive | 245 rue Démonstration, Montréal, Québec, H2X 3K4, Canada |
| Description | Nouvelle cliente intéressée par une consultation couleur; préfère les rendez-vous en fin de journée. |
| Référée par | Instagram |
| Remise fidélité | 0 % |
| Accès plateforme | Désactivé pour la prise |
| Validation automatique facture | Désactivée |
| Statut narratif | Nouvelle cliente intéressée par une consultation couleur |

`Julie Nadeau` existe déjà dans le preset et sert seulement à illustrer un courriel déjà utilisé. Elle ne doit pas être recréée. Le formulaire actuel n'affiche aucun champ Civilité : ne pas annoncer la saisie de « Mme ».

Nora et son courriel étaient absents du jeu local lors de la préparation. Comme le courriel Client est unique globalement, refaire une prise exige un clone propre ou une nouvelle adresse contrôlée. Le nom retenu doit ensuite rester identique dans les captures, la voix et les sous-titres.

### Prestation créée en direct

| Champ | Valeur |
| --- | --- |
| Nom | Consultation couleur |
| Catégorie | Coloration |
| Unité | Pièce |
| Durée métier annoncée | 30 minutes |
| Prix | 35 CAD |
| Taxe du clone canonique | 14,975 % |
| Statut | Active |
| Description | Diagnostic couleur et recommandation personnalisée. |

Le formulaire actuel stocke le nom, la catégorie, le prix, la taxe et la description. Si la durée n'est pas disponible dans ce formulaire précis, la montrer ensuite dans les réglages de réservation sans prétendre qu'elle vient d'être saisie ici.

### Réservation créée en direct

| Champ | Valeur |
| --- | --- |
| Cliente | Nora Bouchard |
| Prestation | Consultation couleur |
| Employée | Léa Moreau |
| Canal | Téléphone |
| Statut | Confirmée |
| Notes client | Première consultation couleur; merci de confirmer tout changement d'horaire. |
| Notes internes | Prévoir le nuancier et un test de mèche; aucune coloration pendant cette consultation. |

Choisir une date future visible dans l'agenda. Éviter une date figée dans la narration afin que la vidéo reste durable.

La variante publique utilise `Mila Tremblay`, `mila.tremblay.public@example.test` et `+1 514 555-0192`. Avec un lien en confirmation manuelle, elle doit produire une réservation **En attente** liée à un prospect, jamais une fiche Client automatique.

### Promotion créée en direct

| Champ | Valeur |
| --- | --- |
| Nom | Bienvenue couleur |
| Code | BIENVENUE10 |
| Cible | Service spécifique |
| Prestation | Consultation couleur |
| Type | Pourcentage |
| Valeur | 10 % |
| Limite | 50 utilisations |
| Montant minimum | Vide |
| Statut | Active |

Choisir une fenêtre de `J` à `J + 30 jours`, valide le jour du tournage. `RENTREE20` existe déjà dans le preset et sert à l'erreur d'unicité contrôlée. La preuve aval de G06 est le préremplissage de Pulse sans publication ; le checkout Réservations ne prouve pas actuellement l'application financière de ce code.

### Membre d'équipe créé en direct

| Champ | Valeur |
| --- | --- |
| Nom | Emma Laurent |
| Courriel | emma.laurent@example.test |
| Profil opérationnel | Membre d'équipe |
| Rôle d'accès | Praticienne salon — accès limité |
| Titre | Esthéticienne |
| Téléphone | 514-555-0178 |
| Pause | 15 minutes |
| Minimum journalier | 4 heures |
| Maximum journalier | 8 heures |
| Maximum hebdomadaire | 32 heures |
| Accès | Clients et services nécessaires; propres réservations et présence; aucune finance ni administration |

La création tente toujours l'invitation. Utiliser un transport local ou inerte et ne jamais envoyer vers une adresse réelle. Les règles de planning ne créent pas les disponibilités hebdomadaires d'Emma.

## Données financières

Pour `G08`, la chaîne provisionnée Marie Lefebvre + Coupe femme + brushing + Karim Benali contient : sous-total 65,00 CAD, taxes 9,73, facture payée 74,73, pourboire 18 % soit 11,70, total encaissé 86,43 et solde 0,00.

Le ticket canonique est déjà terminé. Pour montrer les clics, préparer un second ticket équivalent dans un clone jetable. Pour une preuve en lecture seule, utiliser la chaîne provisionnée et l'identifier clairement comme telle. Choisir Espèces et « Ne pas envoyer maintenant ».

Avant de parler d'un paiement par carte, relire [la frontière Stripe de l'audit Salon Éclat](../../audits/demo-salon/2026-08-07-salon-eclat-demo-coverage.md#frontière-stripe--règle-de-vérité). Une carte ne peut être présentée comme payée qu'après un vrai checkout Stripe en mode test, son retour et sa confirmation.

## Comptes et mots de passe

Les identifiants ne sont jamais stockés dans cette bibliothèque. Les accès temporaires sont récupérés depuis la fiche du workspace de démo juste avant la prise, puis conservés dans un gestionnaire de mots de passe ou une note locale non suivie par Git.
