# Galerie des captures — G05 Créer une réservation

Dernière mise à jour : 2026-08-11

## État honnête de la galerie

**Aucun PNG de G05 n'est encore présent ou validé dans ce dossier.** Les noms ci-dessous sont des cibles de production, pas des images disponibles.

Une capture devient intégrable seulement lorsque :

1. le PNG réel existe dans `desktop/` ;
2. son état correspond à la [shot-list G05](../../episodes/05-creer-reservation/shot-list.csv) ;
3. sa confidentialité et son comportement ont passé la [QA G05](../../episodes/05-creer-reservation/qa.md) ;
4. son statut a été revu humainement avant d'être déclaré `validee`.

Le déroulé reproductible est dans [le runbook de capture](capture-session.md).

## Parcours interne

| ID | Image attendue | Point pédagogique | État |
| --- | --- | --- | --- |
| G05-S01 | `G05-RESERVATION__S01-CALENDRIER-AVANT__desktop__fr__v01.png` | Préparation du calendrier | À produire |
| G05-S02 | `G05-RESERVATION__S02-MODALE-VIERGE__desktop__fr__v01.png` | Formulaire dans une modale | À produire |
| G05-S03 | `G05-RESERVATION__S03-MEMBRE-CLIENT-SERVICE__desktop__fr__v01.png` | Relations principales | À produire |
| G05-S04 | `G05-RESERVATION__S04-PLAGE-STATUT__desktop__fr__v01.png` | Début, fin, durée et statut | À produire |
| G05-S05 | `G05-RESERVATION__S05-NOTES-SEPAREES__desktop__fr__v01.png` | Notes client et internes | À produire |
| G05-S06 | `G05-RESERVATION__S06-ERREUR-CONFLIT__desktop__fr__v01.png` | Refus réel d'un chevauchement | À produire |
| G05-S07 | `G05-RESERVATION__S07-CRENEAU-CORRIGE__desktop__fr__v01.png` | Correction du créneau | À produire |
| G05-S08 | `G05-RESERVATION__S08-SUCCES-CALENDRIER__desktop__fr__v01.png` | Preuve dans le calendrier | À produire |
| G05-S09 | `G05-RESERVATION__S09-DETAILS-CREES__desktop__fr__v01.png` | Détails persistés | À produire |
| G05-S10 | `G05-RESERVATION__S10-PREUVE-LISTE__desktop__fr__v01.png` | Preuve dans la liste | À produire |

## Parcours public et preuve interne

| ID | Image attendue | Point pédagogique | État |
| --- | --- | --- | --- |
| G05-S11 | `G05-RESERVATION__S11-LIEN-PUBLIC-ACTIF__desktop__fr__v01.png` | Lien obtenu depuis les réglages | À produire |
| G05-S12 | `G05-RESERVATION__S12-PUBLIC-SERVICE__desktop__fr__v01.png` | Service public autorisé | À produire |
| G05-S13 | `G05-RESERVATION__S13-PUBLIC-DATE-HEURE__desktop__fr__v01.png` | Date et heure disponibles | À produire |
| G05-S14 | `G05-RESERVATION__S14-PUBLIC-AFFECTATION-AUTO__desktop__fr__v01.png` | Première personne disponible | À produire |
| G05-S15 | `G05-RESERVATION__S15-PUBLIC-RECAPITULATIF__desktop__fr__v01.png` | Coordonnées et récapitulatif | À produire |
| G05-S16 | `G05-RESERVATION__S16-PUBLIC-SUCCES-ATTENTE__desktop__fr__v01.png` | Demande envoyée à confirmer | À produire |
| G05-S17 | `G05-RESERVATION__S17-PREUVE-PUBLIC-INTERNE__desktop__fr__v01.png` | Cartouche Réservation publique | À produire |
| G05-S18 | `G05-RESERVATION__S18-CONVERSION-SANS-EXECUTER__desktop__fr__v01.png` | Prospect non converti | À produire |

## Modèle d'intégration après validation

```markdown
![Erreur réelle indiquant que le créneau choisi pour Léa Moreau n'est plus disponible](desktop/G05-RESERVATION__S06-ERREUR-CONFLIT__desktop__fr__v01.png)

**G05-S06 — Conflit.** Le serveur refuse la plage occupée et aucune seconde réservation n'est créée.
```

Les versions annotées vont dans `annotated/` avec le suffixe `-ANNOTATED` avant le viewport. L'original non annoté reste la source de preuve.
