# G06 — Créer une promotion

Dernière mise à jour : 2026-08-11<br>
Niveau : intermédiaire<br>
Public : propriétaire, vente ou marketing autorisé<br>
Durée du master pédagogique : 11 à 13 minutes<br>
Durée de la capsule dérivée : 3 à 4 minutes

## État réel de production

| Élément | État | Preuve |
| --- | --- | --- |
| Règles de l'interface | Auditées | `Promotions/Index.vue`, `PromotionRequest.php` et `PromotionController.php` |
| Règles d'éligibilité | Auditées | `PromotionPricingService.php` et `PromotionDiscountSystemTest.php` |
| Exemple de données | Prêt | Bienvenue couleur, code et fenêtre détaillés ci-dessous |
| Script détaillé | Prêt | [Scénario de tournage](06-creer-promotion/scenario-detaille.md) |
| Plan des captures | Prêt | [Shot-list G06](06-creer-promotion/shot-list.csv) |
| PNG de l'interface | À produire | [Dossier des captures G06](../captures/G06/README.md) |
| QA finale | En attente des captures | [Checklist G06](06-creer-promotion/qa.md) |

Le mot **capture** désigne une image attendue, pas un PNG déjà disponible. Aucun faux visuel ne doit remplir la galerie.

## Question et résultat promis

**Question :** comment créer une promotion ciblée, bornée et vérifiable sans promettre une application que l'interface actuelle ne permet pas encore de démontrer ?

À la fin du master, **Bienvenue couleur** avec le code `BIENVENUE10` apparaît dans `/promotions`, cible uniquement **Consultation couleur**, accorde **10 %**, reste limitée à **50 utilisations** et porte l'état **Active / Valide maintenant** pour la fenêtre choisie.

Dans Salon Éclat, l'épisode montre également sa réutilisation dans le compositeur Pulse, sans publier de contenu. Il ne prétend pas appliquer ce code à un encaissement de service : cette preuve n'est pas disponible dans le parcours utilisateur actuel.

## Objectifs pédagogiques observables

Après la vidéo, la personne doit pouvoir :

1. distinguer une promotion globale, client, produit ou service ;
2. comprendre la différence entre code requis et promotion automatique sans code ;
3. choisir pourcentage ou montant fixe avec une valeur valide ;
4. définir une fenêtre de dates cohérente ;
5. utiliser limite globale et montant minimum sans les confondre ;
6. corriger un code déjà utilisé ;
7. vérifier les valeurs enregistrées dans la bibliothèque et en modification ;
8. distinguer une preuve de configuration, une réutilisation marketing et une vraie preuve de remise financière.

## Situation métier

Salon Éclat veut offrir 10 % aux nouvelles clientes qui réservent la prestation Consultation couleur. L'offre doit rester contrôlable, ne pas toucher le reste du catalogue et ne pas remplacer la campagne existante `RENTREE20`.

| Avant | Après |
| --- | --- |
| Seule la promotion de démonstration RENTREE20 existe. | BIENVENUE10 est configurée séparément, ciblée et réutilisable dans Pulse. |

## Périmètre

Le master couvre la bibliothèque, la modale complète, les types de cible et de remise, les dates, les limites, une erreur d'unicité, la sauvegarde, la réouverture et une preuve aval marketing dans Pulse.

Il ne publie aucun post, ne crée aucune campagne, ne consomme aucune utilisation et n'encaisse aucune vente. La démonstration financière d'un code ciblé sur une prestation nécessite un flux distinct qui n'est pas présent dans l'interface actuelle.

## Préparation reproductible

- Provisionner un clone jetable `salon_eclat_complete` contenant les modules Promotions, Services et Social.
- Se connecter comme propriétaire. Un membre peut aussi gérer les promotions s'il possède au moins une des permissions suivantes : `sales.manage`, `quotes.edit`, `jobs.edit`, `tasks.edit` ou `campaigns.manage`.
- Ouvrir `/promotions`. La page ne possède pas de recherche : parcourir la bibliothèque et vérifier visuellement que `BIENVENUE10` est absent.
- Confirmer que `RENTREE20` existe pour l'erreur contrôlée et que Consultation couleur créée dans G04 apparaît parmi les cibles Service spécifique.
- Définir **J**, le jour réel de la prise, et **J + 30 jours**. Pour une prise le 11 août 2026, utiliser `2026-08-11` et `2026-09-10`.
- Vérifier que la fenêtre couvre la date de capture afin que le badge `Valide maintenant` soit vrai.
- Conserver le français, le thème clair, le zoom 100 % et un viewport de 1920 × 1080.

## Exemple concret — Bienvenue couleur

| Champ visible | Valeur saisie | Statut | Pourquoi ce choix |
| --- | --- | --- | --- |
| Nom | Bienvenue couleur | Obligatoire, 150 caractères max. | Nom interne lisible dans la bibliothèque et les preuves aval. |
| Code promo | BIENVENUE10 | Optionnel, unique dans le compte, 50 caractères max. | Rend l'offre explicite; le serveur normalise le code en majuscules. |
| Type de cible | Service spécifique | Obligatoire | Empêche la remise de toucher tout le catalogue. |
| Cible | Consultation couleur | Obligatoire pour cette cible | Lie la promotion à l'identifiant exact de la prestation créée en G04. |
| Type de remise | Pourcentage | Obligatoire | Exprime une remise proportionnelle. |
| Valeur de la remise | 10 | Obligatoire, supérieur à 0 et maximum 100 pour un pourcentage | Produit une remise de 10 % sur les lignes admissibles. |
| Date de début | J, exemple 2026-08-11 | Obligatoire | Rend l'offre active le jour de la prise. |
| Date de fin | J + 30, exemple 2026-09-10 | Obligatoire, au moins égale au début | Borne l'offre sans annoncer oralement une date vite obsolète. |
| Statut | Active | Obligatoire | Nécessaire, mais pas suffisant sans dates valides. |
| Limite d'utilisation | 50 | Optionnel, entier minimum 1 | Plafond global d'utilisations comptées, pas 50 par cliente. |
| Montant minimum | Vide | Optionnel, minimum 0 | Aucune condition de panier supplémentaire dans ce scénario. |

## Parcours de tournage en 15 plans

| Temps | Capture | Action et point à expliquer | Preuve attendue |
| --- | --- | --- | --- |
| 00:00–00:45 | G06-S01 | Parcourir la bibliothèque et distinguer RENTREE20. | BIENVENUE10 absent, promotion existante préservée. |
| 00:45–01:20 | G06-S02 | Cliquer Nouvelle promotion et montrer les valeurs initiales. | Global, Pourcentage, dates du jour et Active par défaut. |
| 01:20–02:00 | G06-S03 | Saisir nom et code. | Identité de l'offre cohérente. |
| 02:00–02:55 | G06-S04 | Choisir Service spécifique puis Consultation couleur. | Champ Cible apparu et valeur exacte sélectionnée. |
| 02:55–03:40 | G06-S05 | Choisir Pourcentage et saisir 10. | Type et valeur sans ambiguïté. |
| 03:40–04:35 | G06-S06 | Saisir J et J + 30. | Fin égale ou postérieure au début; aujourd'hui inclus. |
| 04:35–05:20 | G06-S07 | Garder Active, saisir 50, laisser le minimum vide. | Limite globale et absence de seuil comprises. |
| 05:20–06:20 | G06-S08 | Remplacer le code par RENTREE20 et soumettre. | Erreur réelle d'unicité, puis code restauré. |
| 06:20–07:00 | G06-S09 | Revoir toute la modale corrigée. | Cible, remise, dates et limites synchronisées. |
| 07:00–07:40 | G06-S10 | Enregistrer la promotion. | Modale fermée; retour à `/promotions`. |
| 07:40–08:35 | G06-S11 | Cadrer la ligne créée dans la bibliothèque. | Code, cible, 10 %, dates, 0/50, Active et Valide maintenant. |
| 08:35–09:15 | G06-S12 | Ouvrir Modifier puis fermer sans changement. | Valeurs persistées. |
| 09:15–10:20 | G06-S13 | Cliquer Publier avec Pulse et cadrer le préremplissage; ne pas publier. | Nom, 10 %, code, cible et fenêtre réutilisés. |
| 10:20–11:10 | G06-S14 | Montrer les quatre types de cible sans soumettre. | Champ Cible masqué pour Global et requis pour les autres. |
| 11:10–12:30 | G06-S15 | Montrer Montant fixe, minimum et Inactive sans soumettre. | Variantes distinguées du scénario canonique. |

## Les huit subtilités à ne pas rater

1. **Un code change le mode d'application.** Une promotion avec code doit être demandée avec ce code; les promotions automatiques sont celles dont le code est vide.
2. **Le code est normalisé en majuscules** et doit être unique dans le compte. `bienvenue10` devient `BIENVENUE10`.
3. **Service spécifique vise une prestation exacte**, pas toute la catégorie Coloration.
4. **Active ne signifie pas toujours applicable.** Les dates, la cible, la limite et le minimum sont également évalués.
5. **Le badge Valide maintenant est partiel.** Dans la bibliothèque, il reflète le statut et la fenêtre de dates; il ne garantit pas l'éligibilité d'un panier donné.
6. **La limite est globale.** Le compteur exclut les usages rattachés à des ventes annulées; ce n'est pas une limite par cliente.
7. **Le montant minimum porte sur le sous-total total**, tandis qu'une remise Service spécifique ne réduit que la ligne de la prestation ciblée.
8. **La preuve financière n'est pas disponible ici.** Le contrôleur Vente est réservé aux entreprises de type Produits et son formulaire ne charge que des produits; le checkout Réservations n'appelle pas le moteur Promotions. G06 montre donc la configuration et Pulse, pas un faux total remisé.

## Preuve aval honnête

La preuve aval capturable de G06 est **Publier avec Pulse** : le compositeur reçoit le nom, la remise, le code, la cible et les dates de la promotion. La prise s'arrête avant toute publication.

Cette preuve démontre la réutilisation marketing de la configuration. Elle ne prouve pas que le code a réduit un montant. Une future capsule d'encaissement promotionnel devra utiliser une surface produit compatible ou attendre qu'un checkout de prestations soit relié au moteur Promotions.

## Version courte dérivée

La capsule de 3 à 4 minutes conserve G06-S01, S03 à S07, S10 à S12. Elle explique la configuration et la vérification dans la bibliothèque. Le master conserve l'erreur, les variantes, Pulse et la limite d'application financière.

## Dossier de production

- [Scénario détaillé et narration](06-creer-promotion/scenario-detaille.md)
- [Guide exhaustif des champs](06-creer-promotion/guide-champs.md)
- [Variantes, erreurs et décisions](06-creer-promotion/variantes-erreurs.md)
- [Shot-list CSV](06-creer-promotion/shot-list.csv)
- [QA fonctionnelle et média](06-creer-promotion/qa.md)
- [Galerie des captures G06](../captures/G06/README.md)
- [Données communes de la série](../shared-data.md)

## Références croisées

- Avant : `G04 — Créer une prestation`
- Après : communication Pulse, campagnes et futur épisode d'application financière.
- À distinguer : `RENTREE20`, promotion déjà provisionnée et réservée au récit métier Salon Éclat.
