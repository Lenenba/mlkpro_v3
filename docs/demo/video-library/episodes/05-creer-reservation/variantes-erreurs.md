# G05 — Variantes, erreurs et décisions

Dernière mise à jour : 2026-08-11

## Arbre de décision

| Question | Si oui | Si non |
| --- | --- | --- |
| La personne est-elle déjà un client interne ? | Créer depuis `/app/reservations` et sélectionner sa fiche. | Utiliser un lien public ou créer d'abord le client selon le contexte. |
| La personne est-elle connectée au portail ? | Elle peut utiliser `/client/reservations/book`. | Le lien `/book/{company}/{slug}` reste un parcours invité. |
| Le salon veut-il contrôler chaque demande publique ? | Activer la confirmation manuelle; statut initial En attente. | Le lien peut confirmer automatiquement un créneau valide. |
| Le client a-t-il une préférence de professionnelle ? | Choisir une personne précise. | Garder Première personne disponible. |
| Le créneau est-il encore disponible à la soumission ? | La réservation peut être créée. | Choisir un autre horaire; ne jamais forcer. |

## Erreurs contrôlées à montrer

### Créneau déjà pris

- Préparer une réservation active sur le créneau de test.
- Soumettre la modale avec ce créneau.
- Attendre l'erreur associée au début.
- Vérifier qu'aucune seconde réservation n'a été créée.
- Corriger avec le créneau libre préparé.

Ne pas simuler le message dans le montage. Le test fonctionnel protège ce comportement contre le double booking.

### Hors disponibilité

Message attendu côté serveur : la plage est hors des disponibilités configurées. Causes possibles : mauvais jour, plage trop longue, exception fermée, membre sans disponibilité, ou heure saisie dans le mauvais fuseau.

Plan de secours : revenir au planning préparé. Ne pas modifier les horaires hebdomadaires pendant le master.

### Plage traversant minuit

Même avec une fin postérieure au début, une réservation ne peut pas commencer un jour local et finir le lendemain. Choisir une plage contenue dans la même journée.

### Fin antérieure ou égale au début

Le champ Fin est rejeté. Corriger la fin; ne pas simplement augmenter Durée, puisque la fin fournie reste utilisée.

### Statut futur incohérent

Terminé et Absence client sont refusés si le début est futur; Terminé est aussi refusé avant la fin. Utiliser Confirmée pour un rendez-vous accepté.

### Membre inactif

Le sélecteur est alimenté par les membres actifs, mais le serveur contrôle encore l'état à la réservation. Si Léa a été désactivée entre le chargement et la soumission, actualiser et choisir une membre active.

## Course à la réservation publique

Un créneau peut être visible, puis réservé par une autre personne avant le clic final. Le service public régénère les créneaux et vérifie la sélection : la seconde demande est rejetée. Préparer deux créneaux publics libres pour pouvoir refaire la prise proprement.

## Prospect public et client existant

Une réservation publique ne lie pas automatiquement un client, même si le courriel ressemble à une fiche existante. Dans la fenêtre interne :

1. ouvrir la zone Conversion client ;
2. lancer Vérifier ;
3. examiner les correspondances ;
4. choisir consciemment Lier ou Créer le client dans une capsule dédiée.

Dans G05, aucune conversion n'est exécutée. Cette limite évite de masquer un risque de doublon.

## Notes client et notes internes

| Contenu | Zone correcte | Exemple |
| --- | --- | --- |
| Préférence exprimée par le client | Notes | « Merci de confirmer tout changement d'horaire. » |
| Préparation du poste | Notes internes | « Prévoir le nuancier et le test de mèche. » |
| Diagnostic médical ou donnée sensible inutile | Aucune | Ne pas saisir. |
| Mot de passe, numéro de carte, jeton | Aucune | Interdit. |

## Permissions

- `reservations.view` permet la consultation selon la portée effective.
- `view_all_reservations` ouvre la portée Toute l'équipe.
- `reservations.manage` autorise les actions de gestion interne.
- Un membre assigné peut changer le statut de son propre rendez-vous sans gérer ceux des autres.
- Le profil opérationnel `admin` possède un raccourci de gestion dans la politique Réservations; ne pas l'utiliser pour résoudre paresseusement un problème de rôle.

## Mode solo propriétaire

En mode propriétaire seul, l'interface garde l'historique et certains réglages, mais désactive la réservation manuelle par membre. G05 doit être enregistré sur un plan d'équipe compatible; changer de narration ne contourne pas cette règle.

## Notifications et confidentialité

Les créations interne et publique peuvent déclencher des notifications. Avant la prise :

- confirmer le transport local de test ;
- s'assurer que toutes les adresses sont fictives ;
- ne pas démarrer un worker connecté à un transport externe ;
- ne pas capturer d'en-têtes, de jetons, de liens signés ou de contenu d'e-mail sensible.

Si l'isolation n'est pas prouvée, arrêter avant le clic Créer ou Confirmer la réservation.
