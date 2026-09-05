# G05 — Guide des champs et règles réelles

Dernière mise à jour : 2026-08-11

Ce guide sépare les trois surfaces de réservation réellement disponibles : la modale interne, le portail d'un client authentifié et le lien public invité.

## 1. Accès interne

| Élément | Règle réelle | Conséquence pour la démonstration |
| --- | --- | --- |
| Module | `reservations` doit être actif. | Sans le module, les routes internes sont protégées. |
| Consultation | Propriétaire ou membre interne actif. | Un membre peut être limité à ses propres réservations. |
| Création / modification / suppression | Propriétaire, profil opérationnel `admin`, ou permission directe effective `reservations.manage`. | Utiliser le propriétaire pour une prise reproductible. |
| Statut d'une réservation assignée | Un membre actif assigné peut changer son propre statut sans gérer tout le calendrier. | Ne pas présenter cette capacité comme un droit de création. |
| Plan solo propriétaire | La réservation manuelle par membre est désactivée. | Le bouton Nouvelle réservation est masqué et une soumission directe est refusée. |

La route de consultation est `/app/reservations`. La création reste dans une modale et envoie un POST à la même ressource. Après succès, la réponse redirige vers l'index; côté interface la modale se ferme et les vues sont rafraîchies.

## 2. Champs de la modale interne

| Libellé visible | Clé | Obligatoire | Règles serveur | Subtilité métier |
| --- | --- | --- | --- | --- |
| Membre d'équipe | `team_member_id` | Oui | Entier, membre du même compte; le service exige aussi qu'il soit actif. | La disponibilité et les conflits sont contrôlés pour ce membre. |
| Client | `client_id` | Non | Client du même compte. | Dans G05, Nora est sélectionnée pour relier l'historique. |
| Produit ou service | `service_id` | Non | Produit du même compte dont le type est `service`. | Le sélecteur visuel ne propose que les services chargés. |
| Début | `starts_at` | Oui | Date interprétée avec le fuseau fourni. | La plage doit entrer dans une disponibilité réelle. |
| Fin | `ends_at` | Non | Date strictement après le début. | Si absente, elle est calculée avec Durée. Si présente, la durée persistée devient l'écart réel. |
| Durée (minutes) | `duration_minutes` | Non | Entier de 5 à 720; valeur initiale 60. | G05 la remplace par 30. Une valeur incohérente avec la fin n'est pas la source finale. |
| Statut | `status` | Non | En attente, Confirmée, Annulée, Replanifiée, Terminée, Absence client ou Expirée. | La création interne prend Confirmée par défaut. Terminé et Absence sont refusés dans le futur. |
| Notes | `client_notes` | Non | Chaîne, 5 000 caractères max. | Visible dans les détails du portail client; ne pas y mettre une instruction confidentielle. |
| Notes internes | `internal_notes` | Non | Chaîne, 5 000 caractères max. | Visible dans l'espace interne, pas dans le détail portail client. |
| Fuseau | `timezone` | Envoyé par l'interface | Identifiant de fuseau valide. | N'est pas un champ éditable de la modale; vient du workspace. |

Le serveur accepte aussi des métadonnées, une taille de groupe et des ressources, mais la modale interne actuelle ne rend pas ces contrôles. Ils ne doivent pas être annoncés comme des champs visibles dans G05.

## 3. Calcul de la plage

Le service suit cet ordre :

1. résoudre une durée fournie, sinon prendre la valeur par défaut ;
2. convertir le début dans le fuseau du compte puis en UTC ;
3. utiliser la fin fournie, ou la calculer avec la durée ;
4. vérifier que la fin est après le début ;
5. recalculer la durée persistée comme différence entre les deux dates ;
6. vérifier disponibilité, conflit, tampon et ressources applicables.

Exemple cohérent : début 14:00, fin 14:30, durée affichée 30. Exemple à éviter : début 14:00, fin 15:00 et durée affichée 30; la réservation enregistrée durera 60 minutes.

## 4. Disponibilités et conflits

Une réservation interne est refusée quand :

- le membre est inactif ou n'appartient pas au compte ;
- la plage ne tient dans aucune disponibilité hebdomadaire active ;
- une exception ferme tout ou partie de la plage ;
- la plage traverse deux dates locales ;
- elle chevauche une réservation En attente, Confirmée ou Replanifiée ;
- le tampon de la nouvelle ou de l'ancienne réservation crée un chevauchement ;
- une ressource demandée n'a plus de capacité.

Les statuts Annulée, Terminée, Absence client et Expirée ne font pas partie des statuts actifs utilisés pour bloquer les créneaux. Cela ne justifie pas de modifier artificiellement un statut pour forcer une place : corriger le planning ou choisir une autre plage.

## 5. Statuts

| Statut | Usage | Action de tournage |
| --- | --- | --- |
| En attente | Demande à confirmer. | Utilisé par la variante publique avec confirmation manuelle. |
| Confirmée | Rendez-vous accepté et planifié. | Utilisé pour Nora. |
| Replanifiée | Plage modifiée, encore active. | Hors périmètre de G05. |
| Terminée | Service achevé après sa fin. | Interdit pour une réservation future. |
| Absence client | Le client ne s'est pas présenté après le début. | Interdit pour une réservation future. |
| Annulée | Réservation retirée du planning actif. | Hors périmètre. |
| Expirée | Demande arrivée à expiration. | Hors périmètre. |

## 6. Lien public invité

Route canonique : `/book/{company}/{slug}`. Le lien doit appartenir au compte, être actif, ne pas être expiré et être accessible avec le module Réservations.

### Étapes visibles

1. Service
2. Date
3. Horaire
4. Personne
5. Coordonnées
6. Vérification

### Champs de contact

| Champ | Obligatoire | Règles |
| --- | --- | --- |
| Prénom | Oui | Texte, 120 caractères max. |
| Nom | Oui | Texte, 120 caractères max. |
| Téléphone | Oui | Texte, 50 caractères max. |
| Adresse courriel | Oui | Courriel valide, 255 caractères max. |
| Message ou note optionnelle | Non | Texte, 2 000 caractères max. |

Le service choisi doit être autorisé par le lien. Le créneau doit commencer au moins cinq minutes dans le futur et est recalculé le jour sélectionné au moment de la soumission. Si une autre personne prend le créneau entre l'affichage et le clic final, la demande est refusée au lieu de créer un double rendez-vous.

### Affectation

- **Première personne disponible** envoie une affectation automatique; le serveur choisit un créneau encore libre.
- **Personne précise** limite la recherche à ce membre.

### Résultat

- `requires_manual_confirmation = true` : réservation **En attente** ;
- `requires_manual_confirmation = false` : réservation **Confirmée**.

Dans les deux cas, la source est `public_booking`. Le système crée un prospect et une réservation, avec le contact dans leurs métadonnées. Il ne crée pas de client automatiquement.

## 7. Portail client authentifié

Le portail se trouve sous `/client/reservations`. Il associe la réservation à la fiche et à l'utilisateur client authentifié, et crée normalement une demande En attente à partir d'un créneau disponible.

Ce parcours n'est pas celui du lien public :

- le client est déjà connu et connecté ;
- la réservation possède `client_id` et `client_user_id` ;
- elle n'a pas besoin de créer un prospect public.

G05 le cite seulement pour éviter de regrouper trois parcours différents sous le même mot « réservation ».

## 8. Notifications

La création appelle le service de notifications. Les réglages par défaut activent la notification de création, par courriel et dans l'application. Selon les relations présentes, les destinataires peuvent inclure le propriétaire, la membre assignée et le client ou son adresse de fiche.

Pour une démonstration :

- utiliser exclusivement des adresses `example.test` ;
- activer un transport local ou inerte avant la soumission ;
- ne pas interpréter « mise en file » comme « livrée » ;
- ne jamais montrer le contenu d'un lien d'action ou un jeton dans une capture.
