# G03 — Runbook de la session de captures

Dernière mise à jour : 2026-08-11

Ce runbook transforme la [shot-list](../../episodes/03-creer-client/shot-list.csv) en PNG réels. Il ne contient aucun identifiant ni mot de passe.

## 1. Préparer un espace jetable

1. Depuis **Super Admin → Espaces de démo**, provisionner un clone `salon_eclat_complete` réservé au tournage.
2. Attendre l'état `ready` et récupérer l'accès propriétaire sur la fiche du clone.
3. Ne pas utiliser un workspace partagé déjà marqué comme envoyé : la création ferait passer son jeu de 20 à 21 clients.
4. Se connecter comme propriétaire et vérifier `/customer/create`.
5. Rechercher Nora par nom et par courriel avant la prise.
6. Noter localement le moyen de supprimer ou réinitialiser ce clone après validation ; aucune commande destructive ne fait partie de ce runbook.

## 2. Fixer le cadre

- viewport : 1920 × 1080 ;
- zoom : 100 % ;
- thème : clair ;
- langue : français ;
- barre de favoris et extensions : masquées ;
- aucune notification système ;
- autoremplissage personnel désactivé ;
- curseur placé dans une zone neutre avant chaque PNG.

## 3. Capturer dans l'ordre

| Lot | IDs | État de base |
| --- | --- | --- |
| Avant création | G03-S01 | Nora absente de la liste. |
| Formulaire initial | G03-S02 à S03 | Formulaire vierge, Particulier. |
| Formulaire rempli | G03-S04 à S09 | Données canoniques Nora, portail désactivé. |
| Erreur contrôlée | G03-S10 | Courriel Julie temporaire, aucune autre donnée personnelle visible. |
| Soumission | G03-S11 à S12 | Courriel Nora restauré, succès dans la liste. |
| Preuves | G03-S13 à S14 | Fiche ouverte, puis Nora visible dans Réservations. |
| Annexes | G03-S15 à S16 | Variante non soumise et contexte modulaire étiqueté. |

Pour chaque état :

1. enregistrer le PNG dans `desktop/` avec le nom de la shot-list ;
2. passer son statut de `a_produire` à `capturee`, jamais directement à `validee` ;
3. vérifier le PNG hors navigateur à sa taille réelle ;
4. produire une version annotée seulement après validation de l'original ;
5. faire revoir données, cadrage et confidentialité par une deuxième personne.

## 4. Vérifications après création

- Le navigateur est revenu sur `/customer`.
- Le message de succès correspond bien à la création de Nora.
- Nora est retrouvable par nom et par courriel.
- Sa fiche affiche l'adresse et la ville attendues.
- Aucun utilisateur Nora n'a été créé dans le portail.
- Aucune invitation n'a été mise en file ou envoyée.
- Nora apparaît dans une nouvelle réservation, qui est ensuite fermée sans enregistrement.

## 5. Fermer la session

1. Exporter ou copier uniquement les 16 PNG sélectionnés.
2. Fermer la session propriétaire et les onglets Super Admin.
3. Ne pas versionner de trace, cookie, storage state ou fichier d'identifiants.
4. Faire valider les images avec [la checklist G03](../../episodes/03-creer-client/qa.md).
5. Réinitialiser ou retirer le clone uniquement dans le cadre d'une opération explicitement décidée.
