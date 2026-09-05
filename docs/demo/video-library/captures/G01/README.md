# Galerie des captures — G01 Onboarding

Dernière mise à jour : 2026-08-11

## État honnête de la galerie

**Aucun PNG de G01 n'est encore présent ou validé dans ce dossier.** Les noms ci-dessous sont les fichiers attendus, pas des images déjà produites.

Une capture devient consultable dans la documentation seulement lorsque :

1. son PNG existe dans `desktop/` ;
2. son cadrage et ses données ont passé la [QA G01](../../episodes/01-onboarding/qa.md) ;
3. son statut est `validee` dans le plan de captures consolidé ;
4. elle est intégrée ci-dessous avec une légende et un texte alternatif.

La production pas à pas est décrite dans [le runbook de session](capture-session.md).

## Captures du parcours principal

| ID | Image attendue | Point pédagogique | État |
| --- | --- | --- | --- |
| G01-S01 | `G01-ONBOARDING__S01-ACCUEIL-INVITE__desktop__fr__v01.png` | Sept étapes et verrou invité | À produire |
| G01-S02 | `G01-ONBOARDING__S02-CREATION-COMPTE__desktop__fr__v01.png` | Création du propriétaire | À produire |
| G01-S03 | `G01-ONBOARDING__S03-RETOUR-PROPRIETAIRE__desktop__fr__v01.png` | Retour authentifié à six étapes | À produire |
| G01-S04 | `G01-ONBOARDING__S04-IDENTITE-ENTREPRISE__desktop__fr__v01.png` | Nom et description | À produire |
| G01-S05 | `G01-ONBOARDING__S05-ADRESSE-DEVISE__desktop__fr__v01.png` | Adresse réellement persistée et CAD | À produire |
| G01-S06 | `G01-ONBOARDING__S06-TYPE-SERVICES__desktop__fr__v01.png` | Type Services | À produire |
| G01-S07 | `G01-ONBOARDING__S07-SECTEUR-SALON__desktop__fr__v01.png` | Secteur Salon | À produire |
| G01-S08 | `G01-ONBOARDING__S08-TAILLE-EQUIPE__desktop__fr__v01.png` | Taille 3 et contexte Team | À produire |
| G01-S09 | `G01-ONBOARDING__S09-INVITATION-OPTIONNELLE__desktop__fr__v01.png` | Invitation temporaire retirée ensuite | À produire |
| G01-S10 | `G01-ONBOARDING__S10-RECOMMANDATION-TEAM__desktop__fr__v01.png` | Recommandation et capacité | À produire |
| G01-S11 | `G01-ONBOARDING__S11-PERIODE-FACTURATION__desktop__fr__v01.png` | Mensuel, Annuel, devise et essai | À produire |
| G01-S12 | `G01-ONBOARDING__S12-FORFAIT-CONDITIONS__desktop__fr__v01.png` | Team Core et conditions | À produire |
| G01-S13 | `G01-ONBOARDING__S13-SECURITE-EMAIL__desktop__fr__v01.png` | Méthode 2FA email | À produire |
| G01-S14 | `G01-ONBOARDING__S14-SOUMISSION-FINALE__desktop__fr__v01.png` | Contrôle final avant effet externe | À produire |
| G01-S15 | `G01-ONBOARDING__S15-STRIPE-CHECKOUT-TEST__desktop__fr__v01.png` | Checkout réel en mode test | À produire si requis |
| G01-S16 | `G01-ONBOARDING__S16-CHALLENGE-2FA__desktop__fr__v01.png` | Challenge avant le dashboard | À produire |
| G01-S17 | `G01-ONBOARDING__S17-TABLEAU-DE-BORD__desktop__fr__v01.png` | Preuve finale Salon Éclat | À produire |

## Annexes

| ID | Image attendue | Point pédagogique | État |
| --- | --- | --- | --- |
| G01-S18 | `G01-ONBOARDING__S18-MEMBRE-PENDING-OWNER__desktop__fr__v01.png` | Membre en attente du propriétaire | À produire |
| G01-S19 | `G01-ONBOARDING__S19-ERREUR-SOLO-TEAM__desktop__fr__v01.png` | Incompatibilité Solo/Team | À produire |
| G01-S20 | `G01-ONBOARDING__S20-CHECKOUT-ANNULE__desktop__fr__v01.png` | Retour après annulation | À produire |
| G01-S21 | `G01-ONBOARDING__S21-SECURITE-APP-PREFERENCE__desktop__fr__v01.png` | App choisie mais non provisionnée | À produire |

## Règles de confidentialité spécifiques

- G01-S02 peut montrer des champs de mot de passe remplis uniquement si les caractères restent totalement masqués.
- G01-S15 ne montre aucun numéro de carte, CVC, nom de porteur, email de paiement ni identifiant de session.
- G01-S16 est capturé avant la saisie ; aucun code 2FA n'apparaît.
- La barre d'adresse est recadrée ou masquée après le retour Stripe si elle contient `session_id`.
- G01-S09 ne doit jamais être suivi d'un écran de succès contenant le mot de passe temporaire de Léa ; la ligne est retirée avant soumission.

## Modèle d'intégration après validation

Remplacer le commentaire par une image réelle uniquement après QA :

```markdown
![Étape Sécurité de l'onboarding avec le code par courriel sélectionné](desktop/G01-ONBOARDING__S13-SECURITE-EMAIL__desktop__fr__v01.png)

**G01-S13 — Sécurité.** La méthode courriel est retenue pour un nouveau compte ; la configuration complète d'une application TOTP reste un parcours distinct.
```

Les versions annotées vont dans `annotated/` et conservent le même identifiant avec un suffixe `-ANNOTATED` avant le viewport. L'original non annoté reste disponible.
