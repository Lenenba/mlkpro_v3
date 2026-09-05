# G04 — Guide des champs du formulaire Prestation

Dernière mise à jour : 2026-08-11<br>
Route auditée : `/service`<br>
Contexte principal : preset `salon_eclat_complete`

Ce guide décrit la modale réellement rendue par `ServiceForm.vue`, les règles serveur de `ServiceRequest.php` et la persistance de `ServiceController.php`.

## Valeurs initiales à connaître

| Élément | Valeur initiale | Conséquence de tournage |
| --- | --- | --- |
| Nom | Vide | Requis avant soumission. |
| Catégorie | Première catégorie reçue | Choisir explicitement Coloration. |
| Unité | Vide | Optionnelle; ne fixe aucune durée. |
| Prix | 0 | Valide, mais à remplacer par 35. |
| Taux de taxe | 0 | Valide, mais à aligner sur le workspace. |
| Actif | Oui | Conserver pour la preuve Réservations. |
| Image | Vide | Le modèle expose ensuite un visuel de remplacement. |
| Matériaux | Aucun | La section reste vide pour le parcours principal. |

## 1. Nom et catégorie

| Champ | Règle | Subtilité métier |
| --- | --- | --- |
| Nom | Obligatoire, texte, 255 caractères max. | Aucune règle d'unicité : la recherche préalable reste nécessaire. |
| Catégorie | Obligatoire; doit appartenir au compte ou être une catégorie système autorisée | La première catégorie active est sélectionnée automatiquement à l'ouverture. |

La liste principale transmet les catégories actives à une création. Une catégorie archivée peut rester disponible lors de l'édition d'une prestation qui l'utilise déjà, mais ne doit pas être choisie pour une nouvelle prestation.

### Création de catégorie en ligne

Le lien **Ajouter catégorie** révèle un champ **Nom nouvelle catégorie** et le bouton **Créer**.

- Le nom est obligatoire et limité à 255 caractères.
- Les espaces de début, de fin et les suites d'espaces sont normalisés.
- Une catégorie existante peut être résolue et sélectionnée au lieu d'être dupliquée.
- Une catégorie archivée appartenant au compte peut être restaurée.
- L'opération est réservée au propriétaire.
- La catégorie créée est ajoutée et sélectionnée sans fermer la modale.

Pour G04, Coloration existe déjà : la variante est cadrée, mais pas soumise.

## 2. Unité

| Valeur technique | Libellé français | Interprétation sûre |
| --- | --- | --- |
| `piece` | Pièce | Un tarif par prestation ou rendez-vous. |
| `hour` | Heure | Un prix exprimé par heure; ne crée toujours pas une durée de réservation. |
| `m2` | m2 | Cas de services mesurés par surface. |
| `other` | Autre | Unité non couverte par les choix précédents. |

Le champ est optionnel et limité à 50 caractères côté serveur. Le choix `service`, utilisé par certaines données provisionnées, n'est pas proposé dans la liste de création actuelle. G04 utilise donc `piece`.

**Aucune colonne Durée n'est envoyée par ce formulaire.** La durée de 30 minutes appartient au scénario Réservation, pas à la fiche créée ici.

## 3. Prix, devise et taxe

| Élément | Règle | Ce que la vidéo doit dire |
| --- | --- | --- |
| Prix | Obligatoire, numérique, minimum 0, pas de maximum explicite | `35.00` représente 35 dans la devise du compte. |
| Devise | Lecture seule dans cette modale | Le modèle reçoit automatiquement la devise du propriétaire à la création. |
| Taux taxe (%) | Optionnel, numérique, de 0 à 100, pas de 0,0001 dans l'interface | `14.975` est le taux du preset Salon Éclat au moment de l'audit. |

La note visible est actuellement formulée en anglais : `Business currency: CAD. Service prices are stored in this currency.` Ne pas prétendre qu'un sélecteur permet de choisir CAD, USD ou une autre devise ici.

Le taux de taxe est un pourcentage, pas un montant. Zéro est techniquement valide, mais ne doit être conservé que si la prestation est réellement non taxable dans le contexte comptable du workspace.

## 4. Statut actif

La case **Actif** est cochée par défaut.

| État | Effet vérifié |
| --- | --- |
| Actif | La prestation est incluse dans la liste de sélection d'une réservation interne. |
| Inactif | La liste Services la présente comme Archivée et les sélecteurs de nouvelles réservations qui filtrent `is_active = true` l'excluent. |

Le formulaire parle d'activation, tandis que la liste traduit l'état inactif par **Archivé**. Il ne s'agit pas d'une suppression : la ligne existe toujours.

## 5. Description

La description est optionnelle et validée comme chaîne sans longueur maximale explicite dans `ServiceRequest.php`. La narration doit néanmoins rester concise, car le texte est réutilisé dans le catalogue et peut alimenter d'autres surfaces.

Valeur canonique : `Diagnostic couleur et recommandation personnalisée.`

## 6. Image

| Règle serveur | Valeur |
| --- | --- |
| Statut | Optionnelle |
| Formats | JPG, JPEG, PNG, WEBP |
| Taille maximale | 5 000 Ko |

Sans téléversement, la prestation est enregistrée sans chemin d'image et son modèle fournit le visuel `service-default.jpg`. Un SVG ou un GIF n'est pas accepté par cette requête.

Lors d'une modification, retirer une image existante supprime le fichier et rétablit le visuel de remplacement. Ce comportement ne fait pas partie du parcours de création principal.

## 7. Matériaux

Chaque clic sur **Ajouter matériau** crée une ligne. Toute la section est optionnelle.

| Champ | Valeur initiale | Règle | Comportement important |
| --- | --- | --- | --- |
| Produit | Personnalisé | Produit optionnel | Un produit du compte peut préremplir libellé, unité et prix unitaire si ces champs sont vides. |
| Libellé | Vide | Optionnel, 255 caractères max. | Sans produit ni libellé, la ligne est éliminée avant soumission. |
| Quantité | 1 | Numérique, minimum 0 | Multiplie le gabarit dans les flux de travaux compatibles. |
| Prix unitaire | 0 | Numérique, minimum 0 | Ne remplace pas automatiquement le prix de base de la prestation. |
| Unité | Vide | 50 caractères max. | Peut reprendre l'unité du produit. |
| Facturable | Oui | Booléen | Conservé dans le gabarit du matériau. |
| Description | Vide | 2 000 caractères max. | Précision facultative. |

Le serveur ne conserve une ligne que si un libellé peut être résolu. Un identifiant produit étranger au compte n'est pas associé : seuls les produits du propriétaire et de type Produit sont résolus.

À la modification, les matériaux existants sont supprimés puis recréés dans l'ordre envoyé. Il faut donc revoir toute la liste avant une mise à jour.

Dans Salon Éclat, les modules Jobs et Tâches ne sont pas actifs. Les matériaux peuvent être stockés, mais le parcours Réservations ne les transforme ni en consommation automatique ni en supplément de prix. G04 les laisse vides.

## 8. Actions de la modale

| Bouton | Comportement vérifié |
| --- | --- |
| Annuler | Ferme l'overlay grâce à sa cible de modale. |
| Enregistrer et créer un nouveau | Crée la prestation, garde la modale ouverte et réinitialise le formulaire. |
| Créer service | Crée la prestation, réinitialise le composant puis ferme la modale. |
| Mettre à jour service | Visible en édition; enregistre puis ferme la modale. |

Après une création web, le contrôleur redirige vers `service.index`, soit `/service`. Une erreur de synchronisation Stripe est signalée en interne mais n'annule pas la création locale.

## 9. Accès, limites et données générées

- Le module Services doit être actif.
- Le contrôleur exige un propriétaire de compte et renvoie 403 pour un membre d'équipe.
- Une entreprise de type Produits reçoit une réponse 404 sur ce contrôleur.
- La limite de prestations du plan est vérifiée avant la création.
- Le stock et le stock minimum sont forcés à 0.
- Le type d'article est forcé à `service`.
- Un numéro séquentiel préfixé par `S` est généré automatiquement; il ne se saisit pas dans la modale.

## 10. Éléments qui ne sont pas créés ici

- durée de réservation ;
- disponibilité ou horaire d'une employée ;
- ressource de salon comme un fauteuil ;
- présence sur un lien public de réservation ;
- promotion ou code promo ;
- stock de consommables ;
- règle de paiement, acompte ou annulation.

## Sources de vérité dans le dépôt

- Page et modale : `resources/js/Pages/Service/Index.vue`, `resources/js/Pages/Service/UI/ServiceTable.vue`, `resources/js/Pages/Service/UI/ServiceForm.vue`
- Traductions : `resources/js/i18n/modules/fr/services.json`
- Validation : `app/Http/Requests/ServiceRequest.php`
- Création, accès, redirection et matériaux : `app/Http/Controllers/ServiceController.php`
- Catégorie en ligne : `app/Http/Controllers/Settings/ProductCategoryController.php`
- Modèle : `app/Models/Product.php`, `app/Models/ServiceMaterial.php`
- Preuve Réservations : `app/Queries/Reservations/BuildStaffReservationIndexData.php`
- Réinitialisation de la modale : `tests/e2e/service-form-reset.spec.js`
- Image : `tests/Feature/ServiceImageManagementTest.php`
- Jeu Salon Éclat : `app/Services/Demo/DemoWorkspaceProvisioner.php`
