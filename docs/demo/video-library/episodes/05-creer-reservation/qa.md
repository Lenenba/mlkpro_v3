# G05 — Checklist QA

Dernière mise à jour : 2026-08-11

## QA fonctionnelle — parcours interne

- [ ] Le module Réservations est actif et le compte n'est pas en mode solo propriétaire.
- [ ] L'acteur est propriétaire ou possède réellement `reservations.manage`.
- [ ] Léa Moreau est active et possède une disponibilité couvrant le créneau libre.
- [ ] Nora Bouchard et Consultation couleur correspondent à G03 et G04.
- [ ] Le créneau de conflit est occupé par une réservation au statut actif.
- [ ] La première soumission échoue sur `starts_at` et ne crée aucune ligne.
- [ ] Le créneau corrigé est futur, dans la même journée locale et réellement libre.
- [ ] Début, fin et durée racontent tous une plage de 30 minutes.
- [ ] Le statut final de Nora est Confirmée.
- [ ] La note client et la note interne ne sont pas inversées.
- [ ] Une seule réservation Nora apparaît à l'heure préparée.
- [ ] Calendrier, liste et détails affichent les mêmes données.

## QA fonctionnelle — parcours public

- [ ] L'URL est copiée depuis les réglages et le lien est actif.
- [ ] Le lien autorise Consultation couleur.
- [ ] La confirmation manuelle est active avant la prise.
- [ ] Le créneau public vient de la liste des disponibilités, au moins cinq minutes dans le futur.
- [ ] L'affectation reste Première personne disponible dans tous les plans.
- [ ] Mila Tremblay n'existe ni dans Clients ni comme prospect avant la prise.
- [ ] La confirmation publique indique que la demande est envoyée et doit être confirmée; elle n'est pas utilisée comme preuve visuelle du statut.
- [ ] L'espace interne affiche En attente, le cartouche Réservation publique, le contact et le lien.
- [ ] La zone Conversion client est montrée sans cliquer Lier ni Créer le client.
- [ ] Mila n'existe toujours pas dans Clients à la fin.

## QA notifications et confidentialité

- [ ] Le transport de courriel local ou inerte a été vérifié avant toute soumission.
- [ ] Les notifications de création ne peuvent pas atteindre un serveur externe.
- [ ] Nora et Mila utilisent exclusivement des adresses `example.test`.
- [ ] Aucun cookie, mot de passe, en-tête, jeton, lien signé ou identifiant de transport n'est visible.
- [ ] Aucun aperçu d'e-mail contenant une URL d'action n'entre dans les captures.
- [ ] Les messages serveur anglais de succès ne sont ni recouverts ni faussement traduits; pour une livraison entièrement française, leur localisation applicative est terminée avant validation.

## QA des captures

Pour chaque ligne G05 de la [shot-list locale](shot-list.csv) :

- [ ] Le fichier existe sous `captures/G05/desktop/` avec son nom canonique.
- [ ] La route, l'état et les données correspondent au CSV.
- [ ] Le viewport est 1920 × 1080, sans étirement.
- [ ] Le titre ou le contexte de navigation reste visible.
- [ ] Le pointeur ne masque ni un libellé ni un message.
- [ ] Les erreurs G05-S06 sont de vraies erreurs d'interface.
- [ ] G05-S11 ne révèle aucun réglage sensible.
- [ ] G05-S12 à S16 sont clairement identifiées comme parcours public.
- [ ] G05-S18 ne laisse croire à aucune conversion exécutée.
- [ ] Le statut passe seulement de `a_produire` à `capturee`, puis à `validee` après revue humaine.

## QA narration et accessibilité

- [ ] Les termes Client, Prospect et Réservation publique ne sont jamais employés comme synonymes.
- [ ] En attente et Confirmée sont lus exactement selon le contexte.
- [ ] Le conflit est expliqué avant la correction.
- [ ] La différence entre Notes et Notes internes est explicite.
- [ ] La différence entre date saisie et disponibilité contrôlée est explicite.
- [ ] Les incrustations ne reposent pas uniquement sur la couleur du statut.
- [ ] Le débit reste entre 125 et 145 mots par minute.
- [ ] Les sous-titres sont synchronisés et relus.

## QA après export

- [ ] Regarder le master de bout en bout sans accélération.
- [ ] Vérifier qu'aucune coupe ne transforme l'erreur en succès immédiat inexpliqué.
- [ ] Vérifier que G05-S08, S09 et S10 montrent la même heure.
- [ ] Vérifier que G05-S16 et S17 montrent Mila et le même créneau.
- [ ] Vérifier les liens vers G03, G04, G08 et le guide des champs.
- [ ] La miniature annonce « Créer une réservation » sans promettre paiement ou conversion client.

## Critère de sortie

Le lot est livrable quand les 18 captures sont validées, le conflit n'a créé aucun doublon, les deux preuves Nora concordent, la demande Mila reste un prospect en attente, et aucune notification externe n'a été possible.
