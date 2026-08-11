# G04 — Runbook de la session de captures

Dernière mise à jour : 2026-08-11

Ce runbook transforme la [shot-list G04](../../episodes/04-creer-prestation/shot-list.csv) en PNG réels. Il ne contient aucun identifiant ni mot de passe.

## 1. Préparer un espace jetable

1. Provisionner un clone `salon_eclat_complete` réservé au tournage.
2. Se connecter avec l'accès propriétaire du clone.
3. Ouvrir `/service` et vérifier l'absence de Consultation couleur.
4. Vérifier Coloration, CAD, le taux 14.975 et une place disponible dans la limite du plan.
5. Noter comment réinitialiser le clone après validation, sans lancer d'opération destructive pendant la prise.

## 2. Fixer le cadre

- viewport : 1920 × 1080 ;
- zoom : 100 % ;
- thème : clair ;
- langue : français ;
- barre de favoris et extensions : masquées ;
- bandeau cookies fermé ;
- aucune notification système ;
- curseur placé dans une zone neutre avant chaque PNG.

## 3. Capturer dans l'ordre

| Lot | IDs | État de base |
| --- | --- | --- |
| Avant création | G04-S01 | Consultation couleur absente. |
| Modale initiale | G04-S02 | Formulaire vierge, catégorie initiale visible. |
| Formulaire rempli | G04-S03 à S07 | Données canoniques, aucun matériau. |
| Erreur contrôlée | G04-S08 | Nom temporairement vide. |
| Soumission | G04-S09 à S11 | Nom restauré, création et ligne finale. |
| Preuves | G04-S12 à S13 | Édition puis sélection dans Réservations. |
| Annexe | G04-S14 | Contrôles ouverts, aucune soumission. |

Pour chaque état :

1. enregistrer le PNG dans `desktop/` avec le nom canonique ;
2. vérifier le fichier à sa taille réelle hors navigateur ;
3. confirmer que le prix, la taxe et la devise sont lisibles ;
4. produire une version annotée seulement après validation de l'original ;
5. faire revoir cadrage, cohérence et confidentialité par une deuxième personne.

## 4. Vérifications après création

- La modale s'est fermée et le navigateur est resté sur `/service`.
- Consultation couleur est retrouvable par son nom.
- Sa ligne indique 35 CAD, Coloration et Actif.
- La modale Modifier contient Pièce, 14.975 et la description canonique.
- Aucun matériau n'a été enregistré.
- Consultation couleur apparaît dans une nouvelle réservation.
- Aucune réservation n'a été créée pendant G04.

## 5. Fermer la session

1. Copier uniquement les 14 PNG sélectionnés.
2. Fermer la session propriétaire et les onglets sensibles.
3. Ne jamais versionner cookie, storage state, jeton ou accès.
4. Faire valider les images avec [la checklist G04](../../episodes/04-creer-prestation/qa.md).
5. Réinitialiser le clone seulement dans un workflow explicitement autorisé.
