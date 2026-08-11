# G04 — Créer une prestation

Dernière mise à jour : 2026-08-11<br>
Niveau : débutant à intermédiaire<br>
Public : propriétaire du salon<br>
Durée du master pédagogique : 9 à 11 minutes<br>
Durée de la capsule dérivée : 3 à 4 minutes

## État réel de production

| Élément | État | Preuve |
| --- | --- | --- |
| Règles de l'interface | Auditées | `ServiceForm.vue`, `ServiceRequest.php` et `ServiceController.php` |
| Exemple de données | Prêt | Consultation couleur, valeurs fictives et contrôlées ci-dessous |
| Script détaillé | Prêt | [Scénario de tournage](04-creer-prestation/scenario-detaille.md) |
| Plan des captures | Prêt | [Shot-list G04](04-creer-prestation/shot-list.csv) |
| PNG de l'interface | À produire | [Dossier des captures G04](../captures/G04/README.md) |
| QA finale | En attente des captures | [Checklist G04](04-creer-prestation/qa.md) |

Le mot **capture** désigne une cible de production tant que le PNG correspondant n'existe pas. Aucun visuel de ce dossier ne doit être présenté comme une capture réelle avant sa validation.

## Question et résultat promis

**Question :** comment créer une prestation exploitable, comprendre chaque choix du formulaire et vérifier qu'elle est réellement disponible dans une réservation ?

À la fin du master, **Consultation couleur** existe dans `/service`, appartient à la catégorie **Coloration**, coûte **35 CAD**, porte un taux de taxe de **14,975 %**, reste active et apparaît dans le sélecteur d'une nouvelle réservation.

## Objectifs pédagogiques observables

Après la vidéo, la personne doit pouvoir :

1. vérifier l'absence d'un doublon, même si l'application n'impose pas l'unicité du nom ;
2. ouvrir et lire la modale de création sans confondre unité et durée ;
3. choisir une catégorie existante ou en créer une sans quitter la modale ;
4. distinguer prix, devise et taux de taxe ;
5. décider du statut actif, de l'image et des matériaux ;
6. corriger une erreur de champ requis ;
7. choisir entre enregistrer une prestation ou enchaîner plusieurs créations ;
8. retrouver la prestation puis la vérifier dans le flux Réservations.

## Situation métier

Salon Éclat souhaite proposer un rendez-vous d'analyse de 30 minutes avant une coloration importante. Le catalogue possède déjà la catégorie Coloration, mais pas cette prestation courte.

| Avant | Après |
| --- | --- |
| « Consultation couleur » est absente du catalogue. | La prestation active est classée, tarifée et sélectionnable dans Réservations. |

## Périmètre

Le master couvre la recherche préalable, la modale complète, les champs de base, une erreur contrôlée, les deux actions d'enregistrement, le retour réel à la liste, la réouverture en modification et la preuve dans une réservation.

Il n'enregistre pas de nouvelle catégorie ni de matériau dans le parcours principal. Ces variantes sont montrées sans soumission. Il ne règle pas la durée, la disponibilité d'un membre, le lien public de réservation, la promotion ni l'encaissement.

## Préparation reproductible

- Provisionner un clone jetable du preset `salon_eclat_complete` ; ne pas modifier un espace client.
- Se connecter comme **propriétaire du compte**. Le contrôleur Prestations refuse actuellement les membres d'équipe, même dotés de permissions catalogue.
- Ouvrir `/service`, rechercher `Consultation couleur` et confirmer son absence. Le serveur accepterait deux prestations de même nom : cette vérification est donc indispensable.
- Confirmer que la catégorie active `Coloration` existe.
- Vérifier que la devise du compte affichée dans la modale est `CAD`.
- Vérifier le taux réellement utilisé par le workspace. Le preset Salon Éclat est provisionné à `14.975`; si l'écran a été reconfiguré, utiliser sa valeur réelle et mettre à jour script, captures et sous-titres ensemble.
- Conserver le français, le thème clair, le zoom 100 % et un viewport de 1920 × 1080.

## Exemple concret — Consultation couleur

| Champ visible | Valeur saisie | Statut | Pourquoi ce choix |
| --- | --- | --- | --- |
| Nom | Consultation couleur | Obligatoire, 255 caractères max. | Nom court, reconnaissable dans la réservation. |
| Catégorie | Coloration | Obligatoire | Regroupe la consultation avec les autres prestations couleur. |
| Unité | Pièce (`piece`) | Optionnel | La prestation est vendue comme un rendez-vous, pas comme un tarif horaire. |
| Taux taxe (%) | 14.975 | Optionnel, de 0 à 100 | Correspond au jeu Salon Éclat préparé au Québec. |
| Prix | 35.00 | Obligatoire, valeur positive ou nulle | Prix dans la devise du compte, soit CAD. |
| Actif | Oui | Activé par défaut | Rend la prestation disponible dans le sélecteur Réservations. |
| Description | Diagnostic couleur et recommandation personnalisée. | Optionnel | Explique le résultat sans promettre la réalisation de la coloration. |
| Image | Aucune | Optionnel | Utilise le visuel de remplacement et évite un fichier sans droits. |
| Matériaux | Aucun | Optionnel | Une consultation ne consomme pas de produit dans ce scénario. |

La durée métier annoncée est **30 minutes**, mais aucun champ Durée n'existe dans cette modale. Elle sera renseignée dans `G05` lors de la réservation. L'unité « Pièce » ne crée aucune durée.

## Parcours de tournage en 14 plans

| Temps | Capture | Action et point à expliquer | Preuve attendue |
| --- | --- | --- | --- |
| 00:00–00:35 | G04-S01 | Rechercher le nom dans `/service`. | Aucun doublon visible. |
| 00:35–01:05 | G04-S02 | Ouvrir « Ajouter service » et cadrer la modale vierge. | Modale « Nouveau service », route inchangée. |
| 01:05–01:50 | G04-S03 | Saisir le nom et sélectionner Coloration. | Identité et classement explicites. |
| 01:50–02:35 | G04-S04 | Choisir Pièce et saisir 14.975. | Unité distincte de la durée ; taxe lisible. |
| 02:35–03:20 | G04-S05 | Saisir 35 et montrer la note CAD. | Prix rattaché à la devise du compte. |
| 03:20–04:10 | G04-S06 | Garder Actif, saisir la description, laisser l'image vide. | Statut et contenu cohérents. |
| 04:10–04:50 | G04-S07 | Montrer la section Matériaux vide. | Aucun coût ou produit inventé. |
| 04:50–05:35 | G04-S08 | Vider temporairement le nom et soumettre. | Erreur réelle « champs requis », puis correction. |
| 05:35–06:15 | G04-S09 | Comparer les deux actions de sauvegarde. | Conséquence de chaque bouton comprise. |
| 06:15–06:55 | G04-S10 | Cliquer « Créer service ». | Modale fermée, retour sur `/service`. |
| 06:55–07:35 | G04-S11 | Filtrer puis cadrer la nouvelle ligne. | Nom, 35 CAD, Coloration et Actif visibles. |
| 07:35–08:15 | G04-S12 | Rouvrir la prestation en modification. | Valeurs persistées dans la même modale. |
| 08:15–09:00 | G04-S13 | Ouvrir une nouvelle réservation et chercher la prestation, sans enregistrer. | Consultation couleur sélectionnable. |
| 09:00–10:30 | G04-S14 | Montrer sans soumettre la création de catégorie et un matériau. | Variantes expliquées sans altérer la prestation. |

Le minutage, la narration et les reprises figurent dans [le scénario détaillé](04-creer-prestation/scenario-detaille.md).

## Les sept subtilités à ne pas rater

1. **La route reste `/service`.** La création se déroule dans une modale et le contrôleur redirige vers la même liste ; il n'existe pas de page `/service/create` dans ce parcours.
2. **Le propriétaire est requis.** L'accès serveur à ce catalogue est actuellement owner-only.
3. **La catégorie est préremplie avec la première option.** Il faut sélectionner volontairement Coloration au lieu de supposer que la valeur affichée convient.
4. **Le nom n'est pas unique.** Le contrôle anti-doublon est une règle de travail, pas une validation automatique.
5. **L'unité n'est pas la durée.** Pièce, Heure, m² et Autre décrivent une unité commerciale ; aucune ne fixe les 30 minutes de la réservation.
6. **Le prix est dans la devise du compte.** Le formulaire n'offre pas de sélecteur de devise par prestation.
7. **Actif conditionne la preuve aval.** Le formulaire de réservation ne charge que les prestations actives.

## Version courte dérivée

La capsule de 3 à 4 minutes conserve G04-S01, S03 à S06, S10, S11 et S13. Elle montre la création essentielle et la preuve Réservations. La catégorie en ligne, les matériaux, l'erreur et les limites restent dans le master.

## Dossier de production

- [Scénario détaillé et narration](04-creer-prestation/scenario-detaille.md)
- [Guide exhaustif des champs](04-creer-prestation/guide-champs.md)
- [Variantes, erreurs et décisions](04-creer-prestation/variantes-erreurs.md)
- [Shot-list CSV](04-creer-prestation/shot-list.csv)
- [QA fonctionnelle et média](04-creer-prestation/qa.md)
- [Galerie des captures G04](../captures/G04/README.md)
- [Données communes de la série](../shared-data.md)

## Références croisées

- Avant : `G02 — Se repérer dans Malikia Pro`
- Après : `G05 — Créer une réservation`
- Réutilisé par : `G06 — Créer une promotion`, réservations, lien de réservation public et catalogue.
