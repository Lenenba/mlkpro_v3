# G07 — Checklist QA

Dernière mise à jour : 2026-08-11

## QA de préparation

- [ ] Le module Membres d'équipe est actif.
- [ ] La limite du plan laisse une place disponible.
- [ ] L'acteur possède `view_team_members`, `create_team_members` et `assign_roles` ou est propriétaire.
- [ ] Le rôle Praticienne salon — accès limité existe, est actif et appartient au bon workspace.
- [ ] Les permissions du rôle ont été relues avant la création.
- [ ] Emma Laurent et `emma.laurent@example.test` sont absentes avant la prise.
- [ ] Le compte utilisé pour l'erreur d'unicité possède une adresse `example.test` et existe réellement.

## QA fonctionnelle du membre

- [ ] Nom : Emma Laurent.
- [ ] Courriel final : `emma.laurent@example.test`.
- [ ] Profil opérationnel : Membre d'équipe, jamais Administrateur.
- [ ] Rôle d'accès : Praticienne salon — accès limité.
- [ ] Titre : Esthéticienne.
- [ ] Téléphone : 514-555-0178.
- [ ] Icône prédéfinie utilisée; aucune vraie photo.
- [ ] Règles : pause 15, minimum 4, maximum jour 8, maximum semaine 32.
- [ ] L'erreur de courriel déjà utilisé ne crée aucun membre.
- [ ] La soumission finale crée une seule Emma active.
- [ ] Le détail affiche uniquement les permissions attendues.
- [ ] Aucun droit Finance, Réglages, Rôles, Équipe ou Toutes les réservations n'est présent.
- [ ] Emma apparaît dans le sélecteur Membre.
- [ ] Aucun rendez-vous Emma n'est enregistré et aucune disponibilité n'est prétendue.

## QA invitation et absence d'envoi externe

- [ ] Le transport local ou inerte est actif avant le clic final.
- [ ] Aucun worker de la session n'est configuré vers un fournisseur externe.
- [ ] Le destinataire est exclusivement `emma.laurent@example.test`.
- [ ] Le capteur local reçoit au plus l'invitation attendue pour cette prise.
- [ ] Le flash applicatif est cité exactement comme succès ou avertissement.
- [ ] La narration ne transforme jamais « dispatch accepté » en « courriel livré ».
- [ ] Le flash serveur anglais n'est ni recouvert ni faussement traduit; pour une livraison entièrement française, sa localisation applicative est terminée avant validation.
- [ ] Le corps du message n'est pas ouvert pendant la capture.
- [ ] Aucun token, URL de réinitialisation, en-tête complet ou secret n'est visible.

## QA des captures

Pour chaque ligne de la [shot-list G07](shot-list.csv) :

- [ ] Le fichier existe sous `captures/G07/desktop/` avec son nom canonique.
- [ ] La route, l'état, le cadrage et les données correspondent au CSV.
- [ ] Le viewport applicatif est 1920 × 1080, thème clair, zoom 100 %.
- [ ] Les libellés restent lisibles et le pointeur ne les masque pas.
- [ ] G07-S02 montre les permissions du bon workspace.
- [ ] G07-S05 garde Profil et Rôle dans le même cadre.
- [ ] G07-S09 contient une vraie erreur d'unicité.
- [ ] G07-S11 garde le message exact de l'interface.
- [ ] G07-S14 est recadrée sur la liste locale et ne montre aucun contenu de message.
- [ ] G07-S15 est fermé sans soumission.
- [ ] Une version annotée reste distincte de l'original.
- [ ] Le statut n'est `validee` qu'après une revue humaine.

## QA narration et accessibilité

- [ ] Profil opérationnel et rôle d'accès sont définis séparément.
- [ ] Le rôle est expliqué par ses permissions, pas seulement par son nom.
- [ ] Le principe du minimum nécessaire est relié à un exemple concret.
- [ ] Les règles de planning ne sont jamais appelées horaires ou disponibilités.
- [ ] Le comportement de l'invitation est expliqué avant le clic Ajouter.
- [ ] Les incrustations ne reposent pas seulement sur la couleur Actif.
- [ ] Le débit reste entre 125 et 145 mots par minute.
- [ ] Les sous-titres sont synchronisés et relus.

## QA après export

- [ ] Regarder le master sans accélération.
- [ ] Vérifier que G07-S01 et S12 prouvent bien avant / après.
- [ ] Vérifier que G07-S02 et S13 montrent la même définition d'accès.
- [ ] Vérifier que le recadrage G07-S14 n'expose aucun autre destinataire.
- [ ] Vérifier les liens vers G01, G05 et le guide des champs.
- [ ] La miniature annonce « Ajouter un membre » sans promettre configuration du planning ou première connexion.

## Critère de sortie

Le lot est livrable lorsque les 15 captures sont validées, Emma est unique et active, ses permissions restent minimales, l'invitation est confinée au transport local et aucun token ou courriel externe n'a pu être exposé.
