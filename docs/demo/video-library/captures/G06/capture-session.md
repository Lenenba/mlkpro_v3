# G06 — Runbook de la session de captures

Dernière mise à jour : 2026-08-11

Ce runbook transforme la [shot-list G06](../../episodes/06-creer-promotion/shot-list.csv) en PNG réels. Il ne contient aucun accès sensible.

## 1. Préparer un espace jetable

1. Provisionner un clone `salon_eclat_complete` réservé au tournage.
2. Se connecter comme propriétaire ou avec un accès explicitement autorisé à Promotions et Pulse.
3. Vérifier dans `/promotions` que BIENVENUE10 est absent et RENTREE20 présent.
4. Vérifier que Consultation couleur existe, est active et apparaît dans Service spécifique.
5. Calculer J et J+30 à partir du jour réel de capture; noter les deux valeurs sur la feuille de prise.
6. Confirmer qu'aucun compte social ne sera publié pendant G06.

## 2. Fixer le cadre

- viewport : 1920 × 1080 ;
- zoom : 100 % ;
- thème : clair ;
- langue : français ;
- barre de favoris et extensions : masquées ;
- aucune notification système ;
- aucun gestionnaire de mots de passe visible ;
- curseur placé dans une zone neutre avant chaque PNG.

## 3. Capturer dans l'ordre

| Lot | IDs | État de base |
| --- | --- | --- |
| Avant création | G06-S01 | BIENVENUE10 absent, RENTREE20 présent. |
| Modale initiale | G06-S02 | Valeurs par défaut du jour. |
| Formulaire rempli | G06-S03 à S07 | Données canoniques et dates de session. |
| Erreur contrôlée | G06-S08 | RENTREE20 temporaire. |
| Soumission | G06-S09 à S11 | BIENVENUE10 restauré, ligne créée. |
| Preuves | G06-S12 à S13 | Édition, puis Pulse sans publication. |
| Annexes | G06-S14 à S15 | Variantes ouvertes sans soumission. |

Pour chaque état :

1. enregistrer le PNG dans `desktop/` avec son nom canonique ;
2. vérifier code, cible, dates et compteur à taille réelle ;
3. conserver les dates authentiques de la session ;
4. produire une version annotée seulement après validation de l'original ;
5. faire revoir cohérence et confidentialité par une deuxième personne.

## 4. Vérifications après création

- Le navigateur est revenu à `/promotions` et la modale est fermée.
- La ligne affiche BIENVENUE10, Consultation couleur, 10 %, les dates, 0/50 et Active.
- Le badge Valide maintenant est présent si la date de session est dans la fenêtre.
- La modale Modifier conserve le minimum vide.
- Pulse reprend le nom, la remise, le code, la cible et la fenêtre.
- Aucun brouillon n'est sauvegardé et aucun post n'est publié.
- Le compteur reste 0/50 : aucune utilisation financière n'a été créée.

## 5. Fermer la session

1. Copier uniquement les 15 PNG sélectionnés.
2. Fermer Pulse sans enregistrer ni publier.
3. Fermer la session et les onglets sensibles.
4. Ne jamais versionner cookie, storage state, jeton social ou accès.
5. Faire valider les images avec [la checklist G06](../../episodes/06-creer-promotion/qa.md).
6. Réinitialiser le clone seulement dans un workflow explicitement autorisé.
