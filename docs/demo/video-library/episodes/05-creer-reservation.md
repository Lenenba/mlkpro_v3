# G05 — Créer une réservation

Dernière mise à jour : 2026-08-11<br>
Niveau : débutant à intermédiaire<br>
Public : accueil, responsable de salon, propriétaire<br>
Durée du master pédagogique : 12 à 15 minutes<br>
Durée de la capsule dérivée : 4 à 5 minutes

## État réel de production

| Élément | État | Preuve |
| --- | --- | --- |
| Parcours interne | Audité | `Reservation/Index.vue`, `StoreReservationRequest.php`, `ReservationPolicy.php` |
| Disponibilités et conflits | Audités | `ReservationAvailabilityService.php` et `ReservationAvailabilityWindowService.php` |
| Parcours public | Audité | `PublicBooking.vue`, `PublicBookingController.php` et `PublicBookingService.php` |
| Exemple de données | Prêt | Nora pour l'interne, Mila pour la variante publique |
| Script détaillé | Prêt | [Scénario de tournage](05-creer-reservation/scenario-detaille.md) |
| Plan des captures | Prêt | [Shot-list G05](05-creer-reservation/shot-list.csv) |
| PNG de l'interface | À produire | [Galerie G05](../captures/G05/README.md) |
| QA finale | En attente des captures | [Checklist G05](05-creer-reservation/qa.md) |

Le mot **capture** désigne une cible de production tant que le PNG n'existe pas et n'a pas passé la QA. Aucun fichier image fictif n'est utilisé.

### Dette d'interface avant validation des PNG

Les réponses serveur de création sont actuellement en anglais : `Reservation created successfully.` pour l'interne et `Booking request sent. The company will confirm the appointment.` pour un lien public avec confirmation manuelle. Elles ne doivent être ni traduites artificiellement au montage ni présentées comme des libellés français. Pour une série entièrement francophone, les captures de succès restent bloquées tant que ces messages ne sont pas localisés dans l'application ou qu'une décision éditoriale explicite n'accepte cet écart.

## Question et résultat promis

**Question :** comment planifier un rendez-vous interne sans créer un conflit, puis distinguer cette opération d'une demande issue du lien public ?

À la fin du master :

- une réservation interne **Confirmée** relie Nora Bouchard, Consultation couleur, Léa Moreau et un créneau réellement disponible ;
- la réservation est visible dans le calendrier, la liste et sa fenêtre de détails ;
- une demande publique de démonstration est identifiée comme une réservation issue d'un **prospect**, pas comme la création automatique d'un client ;
- le spectateur sait qu'un lien public avec confirmation manuelle produit le statut **En attente**.

## Objectifs pédagogiques observables

Après la vidéo, la personne doit pouvoir :

1. vérifier le membre, le service et le créneau avant de saisir ;
2. distinguer les champs requis, recommandés et facultatifs ;
3. comprendre la relation entre début, fin et durée enregistrée ;
4. différencier une note visible côté client d'une note interne ;
5. reconnaître un conflit ou une plage hors disponibilité et corriger le créneau ;
6. vérifier la réservation dans deux vues et dans ses détails ;
7. expliquer la différence entre création interne, réservation portail et lien public ;
8. ne pas confondre un prospect public avec une fiche client existante.

## Situation métier

Nora Bouchard, créée dans G03, appelle Salon Éclat pour réserver la prestation Consultation couleur créée dans G04. Léa Moreau dispose d'un créneau futur de 30 minutes préparé avant la prise.

| Avant | Après |
| --- | --- |
| Aucun rendez-vous Nora n'occupe le créneau préparé. | Le calendrier et la liste affichent une réservation confirmée pour Nora, Léa et Consultation couleur. |

La variante publique utilise **Mila Tremblay**, une personne fictive qui n'est pas un client existant. Cette séparation rend visible le comportement réel : le lien public crée un prospect et une réservation, puis propose une conversion contrôlée dans l'espace interne.

## Périmètre

Le master couvre :

- la réservation manuelle dans la modale de `/app/reservations` ;
- la validation des disponibilités et le refus d'un chevauchement ;
- le succès, le calendrier, la liste et la fenêtre de détails ;
- une démonstration du lien public avec confirmation manuelle ;
- la preuve interne que la demande publique reste un prospect tant qu'elle n'est pas convertie.

Il ne couvre pas l'encaissement, la file hybride, la liste d'attente, la replanification complète, l'annulation, les ressources ou fauteuils, la création du lien public, ni la conversion finale du prospect. Ces sujets méritent des capsules séparées.

## Préparation reproductible

- Utiliser un clone jetable du preset **Salon Éclat**, jamais un workspace client réel.
- Activer le module Réservations et éviter un plan solo propriétaire : ce mode masque l'action de réservation manuelle par membre.
- Se connecter comme propriétaire, administrateur, ou membre possédant réellement `reservations.manage`.
- Vérifier que Nora Bouchard, Consultation couleur et Léa Moreau existent et sont actifs.
- Vérifier une disponibilité hebdomadaire de Léa et préparer deux créneaux futurs le même jour : un créneau occupé pour l'erreur contrôlée et un créneau libre pour le succès.
- Le rendez-vous ne doit pas traverser minuit. Préparer début et fin dans le fuseau `America/Toronto` du workspace.
- Isoler les courriels de réservation dans un transport local de test avant toute soumission. Les notifications de création sont actives par défaut et peuvent viser le client, le propriétaire ou la membre assignée.
- Pour la variante publique, utiliser un lien actif limité à Consultation couleur et configuré avec **confirmation manuelle**.
- Ouvrir ce lien depuis l'URL copiée dans les réglages. Ne jamais fabriquer le slug et ne jamais exposer de lien signé de kiosque.
- Conserver le navigateur en français, thème clair, zoom 100 %, viewport 1920 × 1080.

## Exemple principal — réservation interne de Nora

La date exacte reste dynamique. Au tournage, choisir le créneau libre préparé et conserver la même date dans toutes les captures.

| Champ visible | Valeur | Statut | Pourquoi ce choix |
| --- | --- | --- | --- |
| Membre d'équipe | Léa Moreau | Obligatoire | La réservation doit être assignée à une membre active du workspace. |
| Client | Nora Bouchard | Facultatif côté serveur, requis dans ce scénario | Relie le rendez-vous à la fiche créée dans G03. |
| Produit ou service | Consultation couleur | Facultatif côté serveur, requis dans ce scénario | Relie la prestation créée dans G04. |
| Début | Créneau futur libre préparé | Obligatoire | Doit se situer dans les disponibilités de Léa. |
| Fin | 30 minutes après le début | Facultatif mais renseigné | Rend la plage explicite. La fin doit être postérieure au début. |
| Durée | 30 | 5 à 720 minutes | Cohérente avec la prestation annoncée. Si une fin est fournie, la durée persistée est recalculée sur la plage réelle. |
| Statut | Confirmée | Choix explicite | La réservation interne est acceptée au moment de la saisie. |
| Notes | Première consultation couleur; merci de confirmer tout changement d'horaire. | Facultatif, 5 000 caractères max. | Contexte que Nora peut retrouver dans son espace si elle dispose d'un accès. |
| Notes internes | Prévoir le nuancier et un test de mèche; aucune coloration pendant cette consultation. | Facultatif, 5 000 caractères max. | Instruction réservée à l'équipe. |

Le formulaire interne ne possède pas de sélecteur automatique de créneau : il présente des champs date-heure. La disponibilité est contrôlée à la soumission. Il faut donc préparer le créneau et montrer honnêtement le message si le contrôle le refuse.

## Variante publique — Mila Tremblay

| Élément | Valeur | Conséquence réelle |
| --- | --- | --- |
| Lien | URL active copiée depuis Réglages Réservations | Route publique canonique `/book/{company}/{slug}`. |
| Service | Consultation couleur | Le service doit être autorisé par ce lien. |
| Date et heure | Créneau futur retourné comme disponible | Le créneau est revérifié au moment de confirmer. |
| Personne | Première personne disponible | L'affectation automatique choisit une membre disponible. |
| Prénom / nom | Mila / Tremblay | Données fictives propres à la variante. |
| Téléphone | +1 514 555-0192 | Champ public obligatoire. |
| Courriel | mila.tremblay.public@example.test | Champ public obligatoire, sans destinataire réel. |
| Message | Première visite; souhaite valider la couleur avant le rendez-vous. | Devient la note client de la réservation. |
| Confirmation manuelle | Activée sur le lien | Le résultat est **En attente**, pas Confirmée. |

Le parcours public crée un prospect et une réservation liés au lien public. Il ne recherche ni ne crée automatiquement une fiche Client. Dans l'espace interne, l'équipe peut ensuite vérifier les doublons et choisir de lier un client existant ou d'en créer un : cette conversion n'est pas exécutée dans G05.

## Parcours de tournage en 18 plans

| Temps | Capture | Action et point à expliquer | Preuve attendue |
| --- | --- | --- | --- |
| 00:00–00:35 | G05-S01 | Ouvrir le calendrier et vérifier le jour préparé. | Créneau final libre et contexte visibles. |
| 00:35–01:10 | G05-S02 | Ouvrir « Nouvelle réservation ». | Modale réelle, sans route `/create`. |
| 01:10–02:05 | G05-S03 | Sélectionner Léa, Nora et Consultation couleur. | Les trois relations sont explicites. |
| 02:05–03:05 | G05-S04 | Saisir début, fin, durée et statut. | Plage de 30 minutes cohérente et statut Confirmée. |
| 03:05–03:50 | G05-S05 | Distinguer Notes et Notes internes. | Deux destinataires compris. |
| 03:50–04:45 | G05-S06 | Soumettre temporairement le créneau occupé. | Erreur réelle « créneau indisponible », aucun doublon créé. |
| 04:45–05:25 | G05-S07 | Remettre le créneau libre préparé et relire le formulaire. | Erreur corrigée, données canoniques intactes. |
| 05:25–06:10 | G05-S08 | Créer la réservation. | Modale fermée et réservation dans le calendrier. |
| 06:10–06:55 | G05-S09 | Ouvrir l'événement. | Cliente, service, membre, plage, statut et notes visibles. |
| 06:55–07:35 | G05-S10 | Passer en vue Liste et retrouver Nora. | Deuxième preuve persistante, sans dépendre de la couleur du calendrier. |
| 07:35–08:10 | G05-S11 | Ouvrir les réglages et copier le lien public actif. | URL obtenue depuis l'application. |
| 08:10–08:50 | G05-S12 | Ouvrir le lien dans une session invitée et choisir le service. | Parcours public non authentifié. |
| 08:50–09:45 | G05-S13 | Choisir une date puis un horaire disponible. | Disponibilités renvoyées par l'application. |
| 09:45–10:25 | G05-S14 | Garder « Première personne disponible ». | Affectation automatique expliquée. |
| 10:25–11:10 | G05-S15 | Saisir Mila puis relire le récapitulatif. | Champs publics obligatoires et message visibles. |
| 11:10–11:50 | G05-S16 | Confirmer la demande. | Message de demande envoyée; le statut sera prouvé côté interne. |
| 11:50–12:45 | G05-S17 | Revenir à l'interne et ouvrir la demande Mila. | Cartouche « Réservation publique » et contact prospect. |
| 12:45–14:00 | G05-S18 | Montrer la zone Conversion client sans l'exécuter. | Mila reste un prospect; aucune fiche client automatique. |

Le minutage, la narration et les reprises figurent dans [le scénario détaillé](05-creer-reservation/scenario-detaille.md).

## Les huit subtilités à ne pas rater

1. **La création interne est une modale.** Il n'existe pas de page `/app/reservations/create`; l'enregistrement est un POST puis l'interface recharge calendrier et liste.
2. **Le membre est obligatoire.** Il doit appartenir au workspace et être actif au moment où le service réserve.
3. **La disponibilité est contrôlée côté serveur.** Une plage hors horaire, une exception fermée, un chevauchement actif ou le tampon d'un autre rendez-vous peuvent refuser la soumission.
4. **La fin domine la durée lorsqu'elle est renseignée.** La durée persistée correspond à la différence réelle entre début et fin; les trois valeurs doivent donc raconter la même chose à l'écran.
5. **Une réservation ne traverse pas deux dates locales.** Même si la fin est après le début, une plage passant minuit est refusée.
6. **Un statut futur ne peut pas être Terminé ou Absence client.** Pour le scénario interne, utiliser Confirmée.
7. **Le public n'est pas l'interne.** Le lien public propose six étapes, revalide le créneau, crée un prospect et peut produire En attente selon sa configuration. L'écran public confirme l'envoi de la demande; le badge En attente est prouvé dans l'espace interne.
8. **Voir n'est pas gérer.** Un membre limité peut voir ses réservations et changer le statut des rendez-vous qui lui sont assignés, sans pouvoir créer, déplacer ou supprimer les réservations de toute l'équipe.

## Version courte dérivée

La capsule de 4 à 5 minutes conserve G05-S01 à S05, S07 à S10 et une comparaison synthétique basée sur G05-S16 à S18. Elle ne rejoue pas une seconde création publique : elle renvoie au master pour les règles de prospect, de concurrence et de confirmation manuelle.

## Dossier de production

- [Scénario détaillé et narration](05-creer-reservation/scenario-detaille.md)
- [Guide exhaustif des champs](05-creer-reservation/guide-champs.md)
- [Variantes, erreurs et décisions](05-creer-reservation/variantes-erreurs.md)
- [Shot-list CSV](05-creer-reservation/shot-list.csv)
- [QA fonctionnelle et média](05-creer-reservation/qa.md)
- [Galerie des captures G05](../captures/G05/README.md)
- [Données communes de la série](../shared-data.md)

## Références croisées

- Avant : `G03 — Créer un client` et `G04 — Créer une prestation`
- Après : `G08 — Facturer et encaisser`
- Approfondissements à prévoir : disponibilités, lien public, conversion prospect-client, replanification, liste d'attente et file salon.

## Sources techniques auditées

- Routes internes et publiques : `routes/web.php`
- Interface interne : `resources/js/Pages/Reservation/Index.vue`
- Interface publique : `resources/js/Pages/Public/PublicBooking.vue`
- Validation : `StoreReservationRequest.php`, `UpdateReservationRequest.php` et `SlotRequest.php`
- Autorisations : `ReservationPolicy.php` et accès résolus par `StaffReservationController.php`
- Disponibilités : `ReservationAvailabilityService.php` et `ReservationAvailabilityWindowService.php`
- Public et conversion : `PublicBookingController.php`, `PublicBookingService.php` et `PublicBookingConversionController.php`
- Tests de preuve : `ReservationModuleTest.php` pour le double booking, les portées et les statuts assignés; `PublicBookingLinksTest.php` pour le prospect sans client, l'affectation automatique et la course au dernier créneau.
