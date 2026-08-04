# Phase 1 — Gains rapides de performance

- Dernière mise à jour : 2026-08-04
- Statut : **ouverte — P1-001 en validation locale**
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

- Statut : **en validation locale**
- But : remplacer le mixin global et les timers multiples par une initialisation après navigation.
- Changement livré : l’initialisation est demandée au montage racine et après navigation Inertia, coalescée au prochain rendu stable ; les overlays Preline dont les déclencheurs ont été remplacés sont fermés, détruits puis rebondés avant le scan global.
- Fichiers modifiés : `resources/js/app.js`, `resources/js/utils/preline.js`, tests Node et Playwright.
- Critères vérifiés localement : aucune initialisation issue d’un montage enfant ; une demande applicative coalescée par rendu stable ; menus, modales, onglets et navigation mobile protégés.
- Preuve : `VALID-P1-001-LOCAL-2026-08-04`.
- Validation restante : revue humaine et acceptation avant de déclarer le ticket terminé.
- Rollback : revert isolé du commit `71c1252c64b92d5153fa01f79afe146dd0a1628c`.

### MLK-IMP-P1-002 — Groupes de routes Ziggy

- Statut : **en attente**
- But : ne fournir à chaque surface que les routes nécessaires.
- Fichiers probables : configuration Ziggy, Blade d’entrée et tests de présence de routes.
- Critères : baisse mesurée du HTML ; aucune route appelée par le frontend n’est absente ; portail/public/admin testés séparément.
- Rollback : groupe complet temporaire par configuration.

### MLK-IMP-P1-003 — Traductions chargées par domaine

- Statut : **en attente**
- But : réduire les locales initiales sans changer les clés.
- Fichiers probables : `resources/js/i18n/catalog.js`, fichiers de locales et imports de pages.
- Critères : fallback anglais intact ; aucune clé manquante sur les parcours pilotes ; baisse mesurée du JavaScript initial.
- Rollback : chargeur complet derrière configuration.

### MLK-IMP-P1-004 — Images et polices du chemin critique

- Statut : **en attente**
- But : réduire le poids et améliorer le rendu initial des pages publiques.
- Livrables : AVIF/WebP, `srcset`, dimensions explicites, priorité du héros, Montserrat chargée sans `@import` bloquant.
- Critères : aucune régression visuelle ; LCP/CLS améliorés ; JPEG de repli conservé si nécessaire.
- Rollback : conserver les anciens médias jusqu’à validation.

### MLK-IMP-P1-005 — Budgets frontend en CI

- Statut : **en attente**
- But : empêcher le retour silencieux des coûts supprimés.
- Livrables : tailles gzip visibles, budget par entrée/parcours, tolérance documentée.
- Critères : CI échoue sur régression non approuvée ; exception possible uniquement via [DECISIONS.md](DECISIONS.md).

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
- [ ] budgets CI actifs ;
- [ ] rollback de chaque ticket documenté ;
- [ ] trois priorités de Phase 2 choisies à partir des données.

## Definition of Done

La Phase 1 est terminée lorsque les gains sont mesurés sur les parcours pilotes, que les workflows restent identiques et que la CI protège les budgets obtenus.

## Documents liés

- [Cockpit](README.md)
- [Phase 0](PHASE_0_SECURITY_AND_BASELINE.md)
- [Journal de validation](VALIDATION_LOG.md)
