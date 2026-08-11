# G06 — Guide des champs du formulaire Promotion

Dernière mise à jour : 2026-08-11<br>
Route auditée : `/promotions`<br>
Contexte principal : preset `salon_eclat_complete`

## Valeurs initiales à connaître

| Élément | Valeur initiale | Conséquence de tournage |
| --- | --- | --- |
| Nom | Vide | Obligatoire. |
| Code | Vide | Une promotion sans code peut être automatique. |
| Type de cible | Tous les clients | Le champ Cible est masqué. |
| Type de remise | Pourcentage | La valeur reste vide et obligatoire. |
| Date de début | Date UTC courante | Calculée avec `new Date().toISOString()` dans le navigateur; à contrôler près de minuit local. |
| Date de fin | Même date UTC | À déplacer à J+30 pour G06. |
| Statut | Active | Nécessaire mais pas suffisant pour être applicable. |
| Limite | Vide | Illimitée si elle reste vide. |
| Montant minimum | Vide | Aucun seuil. |

## 1. Nom et code

| Champ | Règle | Subtilité |
| --- | --- | --- |
| Nom | Obligatoire, texte, 150 caractères max. | Apparaît dans la bibliothèque, les remises et Pulse. |
| Code promo | Optionnel, 50 caractères max., unique par compte | Espaces retirés aux extrémités et valeur convertie en majuscules. |

L'unicité ignore la promotion elle-même lors d'une modification. Un code absent est stocké à `null`, pas comme une chaîne vide.

### Code ou promotion automatique

| Configuration | Évaluation réelle du moteur |
| --- | --- |
| Code renseigné | La promotion est recherchée lorsque ce code est demandé; la comparaison d'utilisation est insensible à la casse. |
| Code vide | La promotion active peut être candidate automatique; le moteur choisit la meilleure remise admissible. |

Pour les promotions automatiques, le meilleur résultat est choisi d'abord par montant de remise, puis par spécificité de cible, puis par identifiant le plus ancien en cas d'égalité complète.

## 2. Type de cible et cible

| Type visible | Valeur | Cible requise | Portée du moteur |
| --- | --- | --- | --- |
| Tous les clients | `global` | Non | Toutes les lignes admissibles. |
| Client spécifique | `client` | Oui, client du compte | Toutes les lignes, seulement pour ce client. |
| Produit spécifique | `product` | Oui, produit du compte | Seulement la ligne du produit exact. |
| Service spécifique | `service` | Oui, prestation du compte | Seulement la ligne de la prestation exacte. |

Quand Global est sélectionné, l'interface envoie `target_id = null`. Pour les trois autres types, un identifiant valide du bon type et du même compte est obligatoire.

Les listes de cibles sont triées : clients par entreprise/prénom/nom, produits et services par nom. Le contrôleur Promotions ne filtre pas les services sur `is_active`; une prestation inactive peut donc apparaître comme cible, même si elle n'est plus réservable. G06 choisit une prestation active.

## 3. Type et valeur de remise

| Type | Règle de saisie | Calcul réel |
| --- | --- | --- |
| Pourcentage | Nombre supérieur à 0, maximum 100 | Pourcentage du sous-total des lignes ciblées. |
| Montant fixe | Nombre supérieur à 0, sans maximum explicite | Plafonné au sous-total admissible; ne rend jamais le total négatif. |

Le champ HTML accepte un pas de 0,01 et affiche `min=0`, mais le serveur exige strictement une valeur supérieure à zéro. `0` est donc refusé.

Après remise, le moteur recalcule les taxes sur les sous-totaux remisés des lignes concernées.

## 4. Dates

| Champ | Règle |
| --- | --- |
| Date de début | Obligatoire, date valide. |
| Date de fin | Obligatoire, date valide, égale ou postérieure au début. |

Le statut **Valide maintenant** de la bibliothèque exige :

```text
statut Active
ET date de début <= aujourd'hui
ET date de fin >= aujourd'hui
```

Ce badge ne contrôle pas la cible d'une opération, le montant minimum ni l'épuisement de la limite. La vraie éligibilité est évaluée lors du calcul d'une opération compatible.

## 5. Statut

Deux valeurs existent : **Active** et **Inactive**.

- Active autorise l'évaluation si les autres conditions sont remplies.
- Inactive bloque toujours l'application.
- La bibliothèque propose une action directe Activer/Désactiver, distincte de la modale Modifier.

Changer le statut redirige vers la même bibliothèque avec la position de défilement conservée.

## 6. Limite d'utilisation

| État du champ | Effet |
| --- | --- |
| Vide | Illimitée. |
| Entier de 1 ou plus | Plafond global des usages consommés. |
| 0, négatif ou décimal | Refusé. |

Le compteur de la bibliothèque affiche `usage_count / usage_limit`. Il compte les usages dont la vente n'est pas annulée. Annuler une vente l'exclut du compteur effectif.

La limite n'est ni quotidienne, ni mensuelle, ni par cliente. Le formulaire ne possède aucun réglage de fréquence ou de limite individuelle.

## 7. Montant minimum

Le montant minimum est optionnel, numérique et supérieur ou égal à zéro. Vide, il devient `null`.

Le moteur compare le minimum au **sous-total total de l'opération**, avant d'identifier les lignes sur lesquelles appliquer la remise ciblée. Exemple : une opération à 100 CAD contenant une Consultation couleur à 35 CAD peut satisfaire un minimum de 75 CAD, mais les 10 % ne portent que sur les 35 CAD de la consultation.

G06 laisse ce champ vide pour ne pas introduire cette condition dans l'offre de bienvenue.

## 8. Actions et redirections

| Action | Comportement |
| --- | --- |
| Annuler | Ferme la modale et réinitialise le formulaire. |
| Enregistrer la promotion | Crée, redirige vers `/promotions`, ferme la modale après succès. |
| Enregistrer en édition | Met à jour, redirige vers `/promotions`, ferme la modale. |
| Activer/Désactiver | Met à jour uniquement le statut. |
| Supprimer | Demande une confirmation navigateur puis supprime. |
| Publier avec Pulse | Visible si Social et l'accès requis sont actifs; ouvre `/social/composer` avec une source Promotion. |

Pulse préremplit le nom, la remise, le code, la cible, la fenêtre et le minimum éventuel. L'action n'envoie aucun post tant que l'utilisateur ne poursuit pas le workflow du compositeur.

## 9. Accès

Le module Promotions doit être actif. L'accès de gestion est accordé :

- au propriétaire ;
- ou à un membre possédant `sales.manage`, `quotes.edit`, `jobs.edit`, `tasks.edit` ou `campaigns.manage`.

La cible et la promotion sont toujours limitées au compte propriétaire. Un identifiant provenant d'un autre workspace est refusé ou masqué.

## 10. Ce que le système évalue réellement

Pour une opération compatible, une promotion codée est rejetée si :

- le code n'existe pas ;
- le statut est Inactive ;
- la date est hors fenêtre ;
- le montant minimum n'est pas atteint ;
- la limite globale est épuisée ;
- le client ou la ligne ciblée ne correspond pas.

Une promotion fixe est plafonnée au sous-total des lignes admissibles. Une promotion Service spécifique ne réduit que la ligne de ce service.

## 11. Limite de preuve dans Salon Éclat

Le moteur `PromotionPricingService` sait reconnaître une ligne de type Service. Toutefois :

- le contrôleur Vente refuse une entreprise de type Services comme Salon Éclat ;
- dans une entreprise de type Produits, `/sales/create` charge uniquement les articles de type Produit ;
- le checkout de file/réservation calcule prix et taxes sans appeler ce moteur Promotions ;
- le test fonctionnel actuel prouve la création d'une cible Service, mais ne constitue pas une surface utilisateur d'encaissement de cette prestation.

Le master doit donc s'arrêter à la configuration, la réouverture et le préremplissage Pulse. Toute capture d'une remise financière sur Consultation couleur serait trompeuse dans l'état actuel.

## Sources de vérité dans le dépôt

- Interface : `resources/js/Pages/Promotions/Index.vue`
- Traductions : `resources/js/i18n/modules/fr/promotions.json`
- Validation et normalisation : `app/Http/Requests/PromotionRequest.php`
- Accès, liste et redirections : `app/Http/Controllers/PromotionController.php`
- Types : `app/Enums/PromotionTargetType.php`, `PromotionDiscountType.php`, `PromotionStatus.php`
- Modèle : `app/Models/Promotion.php`
- Calcul : `app/Services/Promotions/PromotionPricingService.php`
- Preuve Pulse : `app/Services/Social/SocialPrefillService.php`
- Tests : `tests/Feature/PromotionDiscountSystemTest.php`
- Limite Vente : `app/Http/Controllers/SaleController.php`
