# Galerie des captures — G04 Créer une prestation

Dernière mise à jour : 2026-08-11

## État honnête de la galerie

**Aucun PNG de G04 n'est encore présent ou validé dans ce dossier.** Les noms ci-dessous désignent les fichiers attendus.

Une capture devient consultable seulement lorsque son PNG existe dans `desktop/`, respecte la [QA G04](../../episodes/04-creer-prestation/qa.md), a été revu humainement et est intégré ici avec une légende.

La session est décrite dans [le runbook de captures](capture-session.md).

## Captures du parcours principal

| ID | Image attendue | Point pédagogique | État |
| --- | --- | --- | --- |
| G04-S01 | `G04-PRESTATION__S01-RECHERCHE-ABSENTE__desktop__fr__v01.png` | Recherche anti-doublon | À produire |
| G04-S02 | `G04-PRESTATION__S02-MODALE-VIERGE__desktop__fr__v01.png` | Création dans une modale | À produire |
| G04-S03 | `G04-PRESTATION__S03-NOM-CATEGORIE__desktop__fr__v01.png` | Nom et catégorie | À produire |
| G04-S04 | `G04-PRESTATION__S04-UNITE-TAXE__desktop__fr__v01.png` | Unité distincte de la durée | À produire |
| G04-S05 | `G04-PRESTATION__S05-PRIX-DEVISE__desktop__fr__v01.png` | Prix et devise du compte | À produire |
| G04-S06 | `G04-PRESTATION__S06-STATUT-DESCRIPTION-IMAGE__desktop__fr__v01.png` | Statut, description et image | À produire |
| G04-S07 | `G04-PRESTATION__S07-MATERIAUX-VIDES__desktop__fr__v01.png` | Matériaux volontairement vides | À produire |
| G04-S08 | `G04-PRESTATION__S08-ERREUR-NOM-REQUIS__desktop__fr__v01.png` | Erreur réelle et correction | À produire |
| G04-S09 | `G04-PRESTATION__S09-ACTIONS-ENREGISTREMENT__desktop__fr__v01.png` | Trois actions de la modale | À produire |
| G04-S10 | `G04-PRESTATION__S10-RETOUR-LISTE__desktop__fr__v01.png` | Retour réel à `/service` | À produire |
| G04-S11 | `G04-PRESTATION__S11-LIGNE-CREEE__desktop__fr__v01.png` | Preuve dans le catalogue | À produire |
| G04-S12 | `G04-PRESTATION__S12-REOUVERTURE-EDITION__desktop__fr__v01.png` | Valeurs persistées | À produire |
| G04-S13 | `G04-PRESTATION__S13-PREUVE-RESERVATION__desktop__fr__v01.png` | Preuve fonctionnelle aval | À produire |

## Annexe

| ID | Image attendue | Point pédagogique | État |
| --- | --- | --- | --- |
| G04-S14 | `G04-PRESTATION__S14-VARIANTES-CATEGORIE-MATERIAU__desktop__fr__v01.png` | Catégorie et matériau sans soumission | À produire |

## Modèle d'intégration après validation

```markdown
![Prix de 35 CAD et devise d'entreprise dans la modale Consultation couleur](desktop/G04-PRESTATION__S05-PRIX-DEVISE__desktop__fr__v01.png)

**G04-S05 — Prix et devise.** Le prix est stocké dans la devise CAD du compte; la modale ne propose pas de devise par prestation.
```

Les versions annotées vont dans `annotated/` avec le suffixe `-ANNOTATED` avant le viewport. L'original reste intact.
