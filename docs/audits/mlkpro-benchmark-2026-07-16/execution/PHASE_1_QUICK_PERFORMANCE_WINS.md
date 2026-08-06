# Phase 1 — Gains rapides de performance

- Dernière mise à jour : 2026-08-04
- Statut : **ouverte — P1-001 et P1-002 terminés ; P1-003/P1-004 en validation locale, P1-005 en validation humaine après CI verte**
- Responsable : Jules Roger Sombangnen
- Validateurs : à nommer (distinct du responsable)
- Dépendance : GO P0-007 sous dérogation MLK-DEC-010
- Risque de phase : faible à moyen
- Vue maître et état courant : [suivi global des Phases 0 à 4](SUIVI_GLOBAL.md)

## Objectif

Supprimer les coûts globaux payés sur de nombreuses pages, sans modifier les données, les routes ni les workflows métier.

## Baseline à reprendre de la Phase 0

- app JavaScript : 188,6 kB gzip ;
- locales française/anglaise/espagnole : 116,1 / 106,6 / 102,6 kB gzip ;
- table Ziggy : environ 101,7 k caractères par réponse HTML ;
- initialisations Preline par navigation et par montage ;
- LCP, INP, CLS et taille transférée des parcours pilotes ;
- captures desktop/mobile avant changement.

La baseline dynamique P0-006 n’existe pas encore : elle est reportée sous MLK-DEC-010 jusqu’au 2027-08-04. Les mesures Phase 1 partent donc de la baseline statique disponible et ne doivent pas être présentées comme une comparaison dynamique représentative ni comme une validation des workers.

## Workflows à protéger

Navigation Inertia, menus, dropdowns, modales, onglets, tableaux, traductions, routes générées côté client, assistant global et pages publiques.

## Scope

### MLK-IMP-P1-001 — Initialisation Preline unique et ciblée

- Statut : **terminé — acceptation humaine consignée le 2026-08-04**
- But : remplacer le mixin global et les timers multiples par une initialisation après navigation.
- Changement livré : l’initialisation est demandée au montage racine et après navigation Inertia, coalescée au prochain rendu stable ; les overlays Preline dont les déclencheurs ont été remplacés sont fermés, détruits puis rebondés avant le scan global.
- Fichiers modifiés : `resources/js/app.js`, `resources/js/utils/preline.js`, tests Node et Playwright.
- Critères vérifiés localement : aucune initialisation issue d’un montage enfant ; une demande applicative coalescée par rendu stable ; menus, modales, onglets et navigation mobile protégés.
- Preuves : `VALID-P1-001-LOCAL-2026-08-04` et `VALID-P1-001-ACCEPTATION-HUMAINE-2026-08-04`.
- Acceptation : Jules Roger Sombangnen, responsable Produit, Technique et Exploitation, a accepté la preuve locale et autorisé le passage à P1-002.
- Rollback : revert isolé du commit `71c1252c64b92d5153fa01f79afe146dd0a1628c`.

### MLK-IMP-P1-002 — Groupes de routes Ziggy

- Statut : **terminé**
- But : ne fournir à chaque surface que les routes nécessaires.
- Changement livré : groupes `public`, `portal` et `admin` dans `config/ziggy.php`, sélectionnés au rendu HTML par `ZiggyRouteGroupResolver` et `@routes($ziggyGroups)`. Les parcours authentification/onboarding utilisent temporairement la carte complète ; les franchissements de surface rechargent ensuite le document adapté.
- Frontières protégées : navigation vers authentification/onboarding, sortie login/2FA/onboarding, logout, login démo, suppression de compte et expiration d’un espace démo. L’admin et le superadmin restent volontairement dans une même surface afin de préserver l’impersonation.
- Mesure statique locale (sérialisation Ziggy homogène) : carte complète 711 routes / 83 324 octets JSON ; public 81 / 8 241 (-90,1 %), portail 134 / 14 793 (-82,2 %), admin 590 / 69 248 (-16,9 %). Cette mesure ne remplace pas la baseline dynamique reportée sous `MLK-DEC-010`.
- Critères vérifiés localement : audit de 494 noms `route()` littéraux frontend, aucun absent de l’union des groupes ; rendus public/portail/admin ; transitions Inertia et parcours navigateur public → onboarding, login → dashboard et boutique publique.
- Preuve : `VALID-P1-002-LOCAL-2026-08-04`, commit `477894887cd99fb9685daecaa281499ae6e9c973`.
- Acceptation : Jules Roger Sombangnen, responsable Produit, Technique et Exploitation, a accepté les preuves locales et le rollback, puis autorisé le GO P1-003 (`VALID-P1-002-ACCEPTATION-HUMAINE-2026-08-04`).
- Rollback : revert isolé du commit technique ci-dessus ; aucune migration ni donnée métier persistante.

### MLK-IMP-P1-003 — Traductions chargées par domaine

- Statut : **en validation locale**
- But : réduire les locales initiales sans changer les clés.
- Changement livré : les 70 modules de traduction sont déclarés par domaine et chargés à la demande selon la page Inertia. Le shell de chaque surface et les domaines métier de la page sont chargés pour la langue active et le fallback anglais ; une page inconnue reçoit volontairement le catalogue complet afin de ne jamais afficher une clé brute.
- Navigation et langues : les domaines de la destination sont préchargés avant la résolution Inertia ; le changement de langue précharge la langue cible avant le POST `/locale`. Les anciens consommateurs de catalogue complet restent compatibles.
- Mesure statique locale du **payload i18n additionnel au démarrage** (le bundle applicatif déjà chargé est exclu) :

  | Parcours | Catalogues complets historiques | Domaines chargés | Réduction |
  |---|---:|---:|---:|
  | Dashboard FR + fallback EN | 142 actifs, 769 852 o bruts / 269 430 o gzip | 40 actifs, 227 318 o bruts / 79 734 o gzip | -70,5 % brut / -70,4 % gzip |
  | Boutique publique ES + fallback EN | 142 actifs, 722 981 o bruts / 255 431 o gzip | 32 actifs, 66 427 o bruts / 28 965 o gzip | -90,8 % brut / -88,7 % gzip |

  Ces mesures sont locales et statiques ; elles ne remplacent pas la baseline dynamique P0-006 reportée sous `MLK-DEC-010`.
- Critères vérifiés localement : fallback anglais, absence de clé brute pendant les bascules FR/ES/EN, cache par langue/domaine, parcours public et authentifié, build Vite des deux configurations et rollback fonctionnel.
- Autorisation : GO explicitement donné après l’acceptation de P1-002 ; validations locales uniquement, sans staging, production ni test de charge.
- Preuve technique : `VALID-P1-003-LOCAL-2026-08-04`, commit `a27fdea4e1b54fdf41060bcb4880faa0672c2c2f`.
- Validation restante : acceptation humaine de P1-003 avant la clôture de la Phase 1. P1-004 était explicitement parallélisable après P1-002 et ne modifie ni ses contrats i18n ni son rollback.
- Rollback : définir `VITE_I18N_DOMAIN_LOADING=false` dans l’environnement **de build**, reconstruire puis déployer les actifs Vite ; le chargeur complet historique est alors utilisé. La variable `VITE_*` étant compilée, modifier l’environnement après le build ne suffit pas. Un revert isolé du commit technique reste disponible ; aucune migration ni donnée métier persistante n’est concernée.

### MLK-IMP-P1-004 — Images et polices du chemin critique

- Statut : **en validation locale**
- But : réduire le poids et améliorer le rendu initial des pages publiques.
- Changement livré : 25 JPEG stock locaux disposent chacun de variantes AVIF et WebP en `640w` et `1280w` (100 fichiers) ; `PublicResponsiveImage` ne construit un `picture/srcset` que pour ce catalogue connu. Il conserve le JPEG source comme repli et conserve exactement l’URL pour les images tenant, externes ou `data:`.
- Chemins critiques : les héros accueil, page produit/solution, boutique et vitrine disposent de dimensions intrinsèques lorsque l’image est stock, chargent la première diapositive en `eager` + priorité haute, puis laissent les diapositives suivantes et les images sous le pli en `lazy`. Les styles de couverture du héros vitrine restent vérifiés après le composant Vue.
- Police : Montserrat sort de l’`@import` CSS ; le document établit une préconnexion Bunny et charge directement la feuille `display=swap`. Aucun hack JavaScript inline incompatible CSP n’est ajouté.
- Mesure locale statique des médias : le catalogue JPEG historique totalise 7 843 029 o ; les 100 variantes ajoutées totalisent 6 132 235 o au déploiement. Elles ne sont pas toutes transférées : par exemple, `hero-team.jpg` pèse 301 175 o, contre 107 488 o en AVIF 1280w (-64,3 %) et 40 433 o en AVIF 640w (-86,6 %). Ce sont des tailles de fichiers locales, pas une mesure LCP/Web Vitals représentative.
- Critères vérifiés localement : générateur et dimensions des 100 variantes ; repli AVIF → WebP → JPEG ; MIME de l’actif choisi ; absence de collapse desktop/mobile ; priorité limitée à la première diapositive ; héros vitrine `object-fit: cover` conservé ; images personnalisées non réécrites.
- Preuve technique : `VALID-P1-004-LOCAL-2026-08-04`, commit `4fa1ac3f`.
- Validation restante : acceptation humaine des preuves locales et du rollback. La baseline P0-006 étant reportée sous `MLK-DEC-010`, aucune amélioration dynamique LCP/CLS n’est revendiquée.
- Rollback : revert isolé du commit technique ci-dessus ; les JPEG historiques restent présents et aucune migration ni donnée métier persistante n’est concernée.

### MLK-IMP-P1-005 — Budgets frontend en CI

- Statut : **en validation humaine — CI distante verte**
- But : empêcher le retour silencieux des coûts supprimés.
- Changement livré : `config/frontend-budgets.json` versionne, par parcours, les tailles JavaScript, CSS et i18n brutes et gzip ainsi que quatre profils locaux AVIF/WebP (`640w` / `1280w`). Le contrôle suit l’entrée `app` et celle de la page avec leurs imports **statiques** Vite, dédupliqués ; les imports dynamiques sont volontairement exclus du chargement initial. Les domaines i18n de la locale et du fallback sont comptés séparément des actifs déjà chargés par la route.
- Parcours protégés : accueil, connexion, dashboard, détail client, planning, boutique publique et vitrine publique (`Public/Showcase`). Les images sont limitées au catalogue versionné `public/images/landing/stock/optimized` : aucune image tenant, upload utilisateur ou CDN n’est mesurée ni présentée comme couverte.
- Tolérance : baseline initiale mesurée localement, plafond égal à `ceil(baseline × 1,05)` pour chaque métrique ; le rapport JSON rend valeurs mesurées, baseline et plafond visibles. `npm run qa:frontend-budgets:measure` permet de consulter les mesures sans faire échouer le contrôle.
- CI : le job `quality` teste le garde, construit les actifs, puis exécute `npm run qa:frontend-budgets` avec le SHA de base de la PR ou du push. Toute hausse de baseline/plafond, suppression de profil, changement d’identité de parcours/profil ou changement de version est refusé.
- Dérogation : elle exige `FRONTEND_BUDGET_EXCEPTION=MLK-DEC-XXX` et une section dans [DECISIONS.md](DECISIONS.md) explicitement **acceptée**, non expirée, dédiée à **P1-005** et aux budgets frontend. Aucune dérogation n’a été utilisée pour l’initialisation.
- Preuves : `VALID-P1-005-LOCAL-2026-08-04`, commit `2ff77eea71b591523cd1f8c4780a2295bd5109ed` ; `VALID-P1-005-CI-2026-08-04`, exécution GitHub Actions `30964242010` verte (quality, compatibilité MySQL et navigateur).
- Validation locale : test Node **3/3**, build Vite (2 611 modules), contrôle réel des sept parcours / quatre profils d’images, Playwright complet **16/16** et Pest complet **1 297 tests / 13 270 assertions** réussis. Aucune écriture, charge, action staging ou production n’a été réalisée.
- CI distante : le test du garde et son exécution après le build ont réussi sous Node 20 dans le job `quality`; les jobs MySQL et navigateur sont aussi verts.
- Validation restante : acceptation humaine des preuves locales, de la CI et du rollback avant la clôture de la Phase 1. Le garde ne remplace pas la baseline dynamique P0-006 et ne revendique aucun gain LCP, INP ou CLS.
- Rollback : revert isolé du commit technique ci-dessus ; il retire la configuration, le script et l’étape CI sans migration ni donnée métier persistante.

## Hors-scope

Pagination SQL, migration Redis, refonte du dashboard, nouvelle navigation produit, changement de design global ou nouvelle fonctionnalité métier.

## Ordre d’exécution

```text
P1-001 Preline → P1-002 Ziggy → P1-003 i18n
                            └→ P1-004 médias/polices
Tous les résultats ─────────→ P1-005 budgets CI
```

## Vérification minimale

```powershell
npm run qa:build
npm run qa:frontend-budgets
node --test tests/Node/P1005FrontendBudgetsTest.mjs
npm run qa:e2e
php -d memory_limit=512M vendor/bin/pest
git diff --check
```

Mesurer avant/après sur accueil public, connexion, dashboard, détail client et planning.

## Gate de sortie

- [ ] contrats frontend inchangés ;
- [ ] Playwright desktop/mobile vert ;
- [ ] tailles et Web Vitals avant/après consignés ;
- [ ] aucune clé de traduction ni route manquante ;
- [x] budgets CI actifs et contrôle distant vert (`quality` `30964242010`) ;
- [ ] rollback de chaque ticket documenté ;
- [ ] trois priorités de Phase 2 choisies à partir des données.

## Definition of Done

La Phase 1 est terminée lorsque les gains sont mesurés sur les parcours pilotes, que les workflows restent identiques et que la CI protège les budgets obtenus.

## Documents liés

- [Cockpit](README.md)
- [Runbook de validation Phase 1](PHASE_1_VALIDATION_RUNBOOK.md)
- [Phase 0](PHASE_0_SECURITY_AND_BASELINE.md)
- [Journal de validation](VALIDATION_LOG.md)
