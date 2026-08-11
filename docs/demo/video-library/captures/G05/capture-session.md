# G05 — Runbook de la session de captures

Dernière mise à jour : 2026-08-11

Ce runbook transforme la [shot-list G05](../../episodes/05-creer-reservation/shot-list.csv) en captures réelles sans identifiant, cookie ou donnée personnelle versionnée.

## 1. Préparer le clone

1. Provisionner un clone `salon_eclat_complete` réservé au tournage.
2. Vérifier qu'il n'est ni partagé ni utilisé par une autre prise.
3. Confirmer le module Réservations et un plan compatible avec les membres d'équipe.
4. Confirmer Nora Bouchard, Léa Moreau et Consultation couleur.
5. Choisir un jour futur couvert par la disponibilité de Léa.
6. Préparer une réservation active sur le premier créneau de 30 minutes pour G05-S06.
7. Préparer un second créneau libre de 30 minutes pour la création finale.
8. Noter localement les deux heures; cette note ne doit pas être suivie par Git.

## 2. Isoler les notifications

1. Vérifier le transport de courriel local ou inerte.
2. Vérifier qu'aucun worker associé à la session ne livre vers un fournisseur externe.
3. Confirmer que Nora et Mila utilisent `example.test`.
4. Envoyer un message de contrôle local si la procédure d'environnement le prévoit.
5. Ne pas poursuivre si l'isolation ne peut pas être prouvée.

La création d'une réservation peut notifier le propriétaire, la membre assignée et le client. Le domaine fictif ne remplace pas l'isolation du transport.

## 3. Préparer le lien public

1. Dans `/settings/reservations`, choisir un lien actif limité à Consultation couleur.
2. Activer la confirmation manuelle.
3. Vérifier que le lien n'est pas expiré.
4. Copier l'URL depuis l'interface.
5. L'ouvrir dans une session invitée séparée, sans stockage d'authentification interne.
6. Préparer deux créneaux publics libres pour absorber une reprise.

## 4. Fixer le cadre

- viewport applicatif : 1920 × 1080 ;
- zoom : 100 % ;
- thème : clair ;
- langue : français ;
- barre de favoris et extensions : masquées ;
- aucune notification système ;
- autoremplissage personnel désactivé ;
- curseur dans une zone neutre avant chaque PNG.

## 5. Capturer le parcours interne

| Lot | IDs | État de base |
| --- | --- | --- |
| Avant | G05-S01 | Jour futur et créneaux préparés. |
| Modale | G05-S02 à S05 | Nora, Léa, service, plage occupée et notes. |
| Erreur | G05-S06 | Soumission du créneau actif; aucune création. |
| Correction | G05-S07 | Créneau libre, reste du formulaire inchangé. |
| Preuves | G05-S08 à S10 | Calendrier, détails et liste concordants. |

Après G05-S06, contrôler le nombre de réservations avant de poursuivre. Si une nouvelle ligne existe, l'état de conflit était incorrect : ne pas utiliser la capture et recommencer sur un clone propre.

## 6. Capturer le parcours public

| Lot | IDs | État de base |
| --- | --- | --- |
| Origine | G05-S11 | Lien actif et confirmation manuelle. |
| Sélection | G05-S12 à S14 | Service, date, heure et affectation auto. |
| Contact | G05-S15 | Mila et récapitulatif. |
| Succès | G05-S16 | Demande En attente. |
| Preuves internes | G05-S17 à S18 | Prospect public visible, conversion non exécutée. |

Ne pas créer Mila dans Clients. Après G05-S18, fermer la fenêtre de détails sans cliquer Lier ou Créer le client.

## 7. Enregistrer chaque fichier

1. Utiliser exactement le nom canonique du CSV.
2. Enregistrer l'original sous `desktop/`.
3. Vérifier l'image à sa taille réelle.
4. Marquer l'état `capturee`, jamais directement `validee`.
5. Produire une annotation seulement après validation de l'original.
6. Faire revoir comportement, données, cadrage et confidentialité par une deuxième personne.

## 8. Vérifications finales

- Une seule réservation Nora existe à l'heure libre.
- La plage et la durée valent 30 minutes.
- Calendar, liste et détails concordent.
- Mila est une réservation publique En attente.
- Mila reste absente de Clients.
- Les notifications sont présentes seulement dans le transport local attendu.
- Aucun jeton, lien signé ou stockage d'authentification n'a été exporté.

Réinitialiser ou retirer le clone seulement dans le cadre d'une opération explicitement décidée; ce runbook n'autorise aucune suppression.
