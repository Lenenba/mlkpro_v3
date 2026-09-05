# Galerie des captures — G07 Ajouter un membre à l'équipe

Dernière mise à jour : 2026-08-11

## État honnête de la galerie

**Aucun PNG de G07 n'est encore présent ou validé dans ce dossier.** Les fichiers listés sont attendus, mais n'existent pas encore comme preuves visuelles.

Une capture devient intégrable seulement lorsque :

1. son PNG existe réellement dans `desktop/` ;
2. l'état correspond à la [shot-list G07](../../episodes/07-ajouter-membre-equipe/shot-list.csv) ;
3. les rôles, l'invitation et la confidentialité ont passé la [QA G07](../../episodes/07-ajouter-membre-equipe/qa.md) ;
4. une personne a validé le fichier indépendamment de l'auteur de la prise.

Le déroulé de production est dans [le runbook de capture](capture-session.md).

## Captures du parcours

| ID | Image attendue | Point pédagogique | État |
| --- | --- | --- | --- |
| G07-S01 | `G07-EQUIPE__S01-RECHERCHE-ABSENTE__desktop__fr__v01.png` | Emma absente avant création | À produire |
| G07-S02 | `G07-EQUIPE__S02-ROLE-ACCES-PREPARE__desktop__fr__v01.png` | Rôle minimal préparé | À produire |
| G07-S03 | `G07-EQUIPE__S03-MODALE-VIERGE__desktop__fr__v01.png` | Formulaire réel | À produire |
| G07-S04 | `G07-EQUIPE__S04-IDENTITE-AVATAR__desktop__fr__v01.png` | Identité et icône | À produire |
| G07-S05 | `G07-EQUIPE__S05-PROFIL-ROLE__desktop__fr__v01.png` | Profil versus rôle d'accès | À produire |
| G07-S06 | `G07-EQUIPE__S06-TITRE-TELEPHONE__desktop__fr__v01.png` | Fonction et coordonnée | À produire |
| G07-S07 | `G07-EQUIPE__S07-REGLES-PLANNING__desktop__fr__v01.png` | Règles sans disponibilités | À produire |
| G07-S08 | `G07-EQUIPE__S08-AVIS-INVITATION__desktop__fr__v01.png` | Invitation annoncée avant le clic | À produire |
| G07-S09 | `G07-EQUIPE__S09-ERREUR-COURRIEL-DUPLIQUE__desktop__fr__v01.png` | Courriel unique | À produire |
| G07-S10 | `G07-EQUIPE__S10-FORMULAIRE-CORRIGE__desktop__fr__v01.png` | Formulaire final relu | À produire |
| G07-S11 | `G07-EQUIPE__S11-RETOUR-CREATION__desktop__fr__v01.png` | Succès ou avertissement exact | À produire |
| G07-S12 | `G07-EQUIPE__S12-MEMBRE-ACTIF__desktop__fr__v01.png` | Emma active dans la liste | À produire |
| G07-S13 | `G07-EQUIPE__S13-DETAILS-PERMISSIONS__desktop__fr__v01.png` | Permissions effectives | À produire |
| G07-S14 | `G07-EQUIPE__S14-INVITATION-LOCALE__desktop__fr__v01.png` | Invitation captée localement | À produire |
| G07-S15 | `G07-EQUIPE__S15-PREUVE-SELECTEUR__desktop__fr__v01.png` | Membre actif sans disponibilité prétendue | À produire |

## Règle particulière pour G07-S14

Le cadrage autorisé se limite à la ligne du capteur local : destinataire `example.test`, sujet et état local. Le corps, le token, l'URL de réinitialisation, les en-têtes et les autres messages doivent rester hors champ.

## Modèle d'intégration après validation

```markdown
![Détails d'Emma Laurent avec son rôle Praticienne salon et ses permissions effectives limitées](desktop/G07-EQUIPE__S13-DETAILS-PERMISSIONS__desktop__fr__v01.png)

**G07-S13 — Vérification.** Les permissions effectives confirment le rôle minimal sélectionné avant la création.
```

Les versions annotées vont dans `annotated/` avec le suffixe `-ANNOTATED`. L'original non annoté reste la source de preuve.
