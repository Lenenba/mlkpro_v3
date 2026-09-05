# Galerie des captures — G03 Créer un client

Dernière mise à jour : 2026-08-11

## État honnête de la galerie

**Aucun PNG de G03 n'est encore présent ou validé dans ce dossier.** Les noms ci-dessous sont les fichiers attendus, pas des images déjà produites.

Une capture devient consultable dans la documentation seulement lorsque :

1. son PNG existe dans `desktop/` ;
2. son cadrage et ses données ont passé la [QA G03](../../episodes/03-creer-client/qa.md) ;
3. son statut est `validee` dans [le plan global](../../capture-plan.csv) ;
4. elle est intégrée ci-dessous avec une légende et un texte alternatif.

La production pas à pas est décrite dans [le runbook de session](capture-session.md).

## Captures du parcours principal

| ID | Image attendue | Point pédagogique | État |
| --- | --- | --- | --- |
| G03-S01 | `G03-CLIENT__S01-RECHERCHE-ABSENTE__desktop__fr__v01.png` | Vérification du doublon | À produire |
| G03-S02 | `G03-CLIENT__S02-FORMULAIRE-VIERGE__desktop__fr__v01.png` | Vue générale du formulaire | À produire |
| G03-S03 | `G03-CLIENT__S03-TYPE-AVATAR__desktop__fr__v01.png` | Type Particulier et avatar | À produire |
| G03-S04 | `G03-CLIENT__S04-IDENTITE-CONTACT__desktop__fr__v01.png` | Identité et coordonnées | À produire |
| G03-S05 | `G03-CLIENT__S05-PORTAIL-DESACTIVE__desktop__fr__v01.png` | Accès portail désactivé | À produire |
| G03-S06 | `G03-CLIENT__S06-AUTO-VALIDATION-FACTURES__desktop__fr__v01.png` | Option Factures propre au preset | À produire |
| G03-S07 | `G03-CLIENT__S07-DETAILS-ADDITIONNELS__desktop__fr__v01.png` | Contexte, provenance et remise | À produire |
| G03-S08 | `G03-CLIENT__S08-RECHERCHE-ADRESSE__desktop__fr__v01.png` | Recherche d'adresse | À produire |
| G03-S09 | `G03-CLIENT__S09-ADRESSE-COMPLETE__desktop__fr__v01.png` | Adresse persistable grâce à la ville | À produire |
| G03-S10 | `G03-CLIENT__S10-ERREUR-COURRIEL-DUPLIQUE__desktop__fr__v01.png` | Erreur d'unicité | À produire |
| G03-S11 | `G03-CLIENT__S11-ACTIONS-ENREGISTREMENT__desktop__fr__v01.png` | Choix entre les deux actions | À produire |
| G03-S12 | `G03-CLIENT__S12-SUCCES-LISTE__desktop__fr__v01.png` | Retour réel dans la liste | À produire |
| G03-S13 | `G03-CLIENT__S13-FICHE-CREEE__desktop__fr__v01.png` | Fiche ouverte après la liste | À produire |
| G03-S14 | `G03-CLIENT__S14-PREUVE-RESERVATION__desktop__fr__v01.png` | Nora réutilisable dans une réservation | À produire |

## Annexes

| ID | Image attendue | Point pédagogique | État |
| --- | --- | --- | --- |
| G03-S15 | `G03-CLIENT__S15-VARIANTE-ENTREPRISE__desktop__fr__v01.png` | Champs conditionnels Entreprise | À produire |
| G03-S16 | `G03-CLIENT__S16-FACTURATION-CONDITIONNELLE__desktop__fr__v01.png` | Préférences d'un autre contexte modulaire | À produire |

## Modèle d'intégration après validation

Remplacer le commentaire par une image réelle uniquement après QA :

```markdown
![Formulaire de création client, accès portail désactivé pour Nora Bouchard](desktop/G03-CLIENT__S05-PORTAIL-DESACTIVE__desktop__fr__v01.png)

**G03-S05 — Accès portail.** Le toggle est désactivé afin de créer une fiche interne sans invitation pendant la démonstration.
```

Les versions annotées vont dans `annotated/` et conservent le même identifiant avec un suffixe `-ANNOTATED` avant le viewport. L'original non annoté reste disponible.
