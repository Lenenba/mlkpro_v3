# M00 — garde automatique de la couleur primaire

Date : 2026-08-18

Module : `M00` — Taxonomie et garde automatique

Statut avant / après : À démarrer → Conforme

## Résultat

La dette de couleur est désormais mesurée et protégée avant la migration des modules métier. Le manifeste rattache chaque page Vue et chaque fichier contenant une couleur candidate à un seul lot. La CI refuse une hausse de la dette dans un fichier en attente et refuse toute occurrence non classée dans un lot vérifié.

Baseline canonique :

- 36 lots, de `M00` à `M35`;
- 220 pages Vue sur 220 affectées exactement une fois;
- 259 fichiers candidats sur 259 affectés exactement une fois;
- 4 914 occurrences candidates;
- 1 664 occurrences à risque fort;
- 0 chevauchement et 0 fichier non affecté;
- 0 exception initiale implicite.

## Périmètre livré

- manifeste versionné avec état, chemins, baseline totale et baseline par fichier;
- scanner des familles `green`, `emerald`, `lime` et `teal`, plus les hexadécimaux Malikia Pro;
- détection renforcée des CTA, survols, focus, sélections, contrôles interactifs et couleurs fixes;
- exceptions granulaires obligatoires par chemin, ancre, tokens, nombre attendu, classe et raison;
- rejet des exceptions orphelines ou superposées;
- mode `--module`, sortie `--json` et mode `--strict`;
- tests Node du contrat, du ratchet, des exceptions, des globs et de la couverture;
- helpers Playwright pour couleurs calculées, hover, focus, contraste WCAG et statuts sémantiques;
- premier scénario navigateur M00 sur un vrai formulaire public violet;
- exécution automatique de la garde stricte dans le workflow qualité.

## Politique de progression

Un lot `pending` ou `in_progress` ne peut ni augmenter son nombre de candidats ou de risques forts, ni introduire un nouveau fichier candidat non baseliné. Un lot `verified` doit avoir zéro occurrence inconnue : chaque couleur restante doit être une exception sémantique exacte.

M00 ne modifie aucun bouton métier. Son rôle est d'empêcher que la migration suivante soit déclarée complète sur la seule présence d'un token primaire dans une page.

## Preuves

- `npm run qa:branding-colors -- --strict` : vert, 36 lots;
- `node --test tests/Node/CompanyBrandingColorAuditTest.mjs` : 9/9 verts;
- scénario Playwright `M00` : 1/1 vert;
- couverture : 220 pages Vue et 259 fichiers candidats;
- baseline : 4 914 candidats, 1 664 risques forts;
- `git diff --check` : vert.

Les validations globales Node, build, budgets frontend et Playwright sont consignées dans le compte rendu de livraison du commit contenant ce rapport.

## Décision

M00 est conforme. Le seul prochain lot autorisé est `M01 — Composants partagés`, qui part de 178 candidats dont 80 à risque fort. Aucun autre module métier ne commence avant la conformité de M01.
