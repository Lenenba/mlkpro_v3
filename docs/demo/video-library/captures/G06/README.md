# Galerie des captures — G06 Créer une promotion

Dernière mise à jour : 2026-08-11

## État honnête de la galerie

**Aucun PNG de G06 n'est encore présent ou validé dans ce dossier.** Les noms suivants sont des cibles de production.

Une capture devient consultable seulement lorsque son PNG existe dans `desktop/`, respecte la [QA G06](../../episodes/06-creer-promotion/qa.md), a été revu humainement et est intégré avec une légende.

La session est décrite dans [le runbook de captures](capture-session.md).

## Captures du parcours principal

| ID | Image attendue | Point pédagogique | État |
| --- | --- | --- | --- |
| G06-S01 | `G06-PROMOTION__S01-BIBLIOTHEQUE-AVANT__desktop__fr__v01.png` | Vérification sans recherche inventée | À produire |
| G06-S02 | `G06-PROMOTION__S02-MODALE-VIERGE__desktop__fr__v01.png` | Valeurs initiales | À produire |
| G06-S03 | `G06-PROMOTION__S03-NOM-CODE__desktop__fr__v01.png` | Nom et code | À produire |
| G06-S04 | `G06-PROMOTION__S04-CIBLE-SERVICE__desktop__fr__v01.png` | Consultation couleur ciblée | À produire |
| G06-S05 | `G06-PROMOTION__S05-REMISE-10-POURCENT__desktop__fr__v01.png` | Type et valeur de remise | À produire |
| G06-S06 | `G06-PROMOTION__S06-FENETRE-DATES__desktop__fr__v01.png` | Fenêtre J à J+30 | À produire |
| G06-S07 | `G06-PROMOTION__S07-STATUT-LIMITE-MINIMUM__desktop__fr__v01.png` | Active, 50, minimum vide | À produire |
| G06-S08 | `G06-PROMOTION__S08-ERREUR-CODE-DUPLIQUE__desktop__fr__v01.png` | Erreur réelle RENTREE20 | À produire |
| G06-S09 | `G06-PROMOTION__S09-FORMULAIRE-CORRIGE__desktop__fr__v01.png` | Relecture avant sauvegarde | À produire |
| G06-S10 | `G06-PROMOTION__S10-RETOUR-BIBLIOTHEQUE__desktop__fr__v01.png` | Retour réel à `/promotions` | À produire |
| G06-S11 | `G06-PROMOTION__S11-LIGNE-CREEE-VALIDE__desktop__fr__v01.png` | Configuration complète enregistrée | À produire |
| G06-S12 | `G06-PROMOTION__S12-REOUVERTURE-EDITION__desktop__fr__v01.png` | Valeurs persistées | À produire |
| G06-S13 | `G06-PROMOTION__S13-PREUVE-PULSE__desktop__fr__v01.png` | Réutilisation marketing sans publication | À produire |

## Annexes

| ID | Image attendue | Point pédagogique | État |
| --- | --- | --- | --- |
| G06-S14 | `G06-PROMOTION__S14-VARIANTES-CIBLES__desktop__fr__v01.png` | Types de cible | À produire |
| G06-S15 | `G06-PROMOTION__S15-VARIANTES-REMISE-LIMITES__desktop__fr__v01.png` | Montant fixe, minimum et Inactive | À produire |

## Modèle d'intégration après validation

```markdown
![Ligne Bienvenue couleur active, ciblée sur Consultation couleur avec 10 pour cent et zéro utilisation sur cinquante](desktop/G06-PROMOTION__S11-LIGNE-CREEE-VALIDE__desktop__fr__v01.png)

**G06-S11 — Promotion enregistrée.** La bibliothèque confirme code, cible, remise, fenêtre, compteur et statut; le badge ne remplace pas un test de panier.
```

Les versions annotées vont dans `annotated/` avec le suffixe `-ANNOTATED` avant le viewport. L'original reste disponible.
