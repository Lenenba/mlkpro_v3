# Malikia Pro Website Copy - Phase 16 Solution Multi-Entity Oversight

Dernière mise à jour: 2026-04-09

## Purpose
Ce document couvre la `Phase 16 - solution-multi-entity-oversight` du chantier copywriting.

Objectif:
- auditer la page `solution-multi-entity-oversight` actuellement visible
- renforcer le positionnement de la solution autour du pilotage multi-entité, de la comparaison utile, et de l’arbitrage dirigeant
- remplacer les formulations encore trop génériques par un vrai récit de visibilité transverse, de priorisation, et de coordination entre entités
- produire une base `source + FR + EN` directement applicable plus tard sans changer la structure

Ce document s'appuie sur:
- [MALIKIA_PRO_WEBSITE_COPY_PHASE_0_EDITORIAL_FOUNDATION.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASE_0_EDITORIAL_FOUNDATION.md)
- [MALIKIA_PRO_WEBSITE_COPY_PHASE_1_WELCOME.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASE_1_WELCOME.md)
- [MALIKIA_PRO_WEBSITE_COPY_PHASE_2_PRICING.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASE_2_PRICING.md)
- [MALIKIA_PRO_WEBSITE_COPY_PHASE_3_CONTACT_US.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASE_3_CONTACT_US.md)
- [MALIKIA_PRO_WEBSITE_COPY_PHASE_4_SALES_CRM.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASE_4_SALES_CRM.md)
- [MALIKIA_PRO_WEBSITE_COPY_PHASE_5_OPERATIONS.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASE_5_OPERATIONS.md)
- [MALIKIA_PRO_WEBSITE_COPY_PHASE_6_RESERVATIONS.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASE_6_RESERVATIONS.md)
- [MALIKIA_PRO_WEBSITE_COPY_PHASE_7_COMMERCE.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASE_7_COMMERCE.md)
- [MALIKIA_PRO_WEBSITE_COPY_PHASE_8_MARKETING_LOYALTY.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASE_8_MARKETING_LOYALTY.md)
- [MALIKIA_PRO_WEBSITE_COPY_PHASE_9_AI_AUTOMATION.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASE_9_AI_AUTOMATION.md)
- [MALIKIA_PRO_WEBSITE_COPY_PHASE_10_COMMAND_CENTER.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASE_10_COMMAND_CENTER.md)
- [MALIKIA_PRO_WEBSITE_COPY_PHASE_11_SOLUTION_SALES_QUOTING.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASE_11_SOLUTION_SALES_QUOTING.md)
- [MALIKIA_PRO_WEBSITE_COPY_PHASE_12_SOLUTION_FIELD_SERVICES.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASE_12_SOLUTION_FIELD_SERVICES.md)
- [MALIKIA_PRO_WEBSITE_COPY_PHASE_13_SOLUTION_RESERVATIONS_QUEUES.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASE_13_SOLUTION_RESERVATIONS_QUEUES.md)
- [MALIKIA_PRO_WEBSITE_COPY_PHASE_14_SOLUTION_COMMERCE_CATALOG.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASE_14_SOLUTION_COMMERCE_CATALOG.md)
- [MALIKIA_PRO_WEBSITE_COPY_PHASE_15_SOLUTION_MARKETING_LOYALTY.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASE_15_SOLUTION_MARKETING_LOYALTY.md)
- [MALIKIA_PRO_WEBSITE_COPY_PHASED_USER_STORY.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASED_USER_STORY.md)

## Real page structure
La page `solution-multi-entity-oversight` rend actuellement les zones suivantes, dans cet ordre:
1. Hero aligné sur le front public
2. `solution-overview` en `layout: split`
3. `solution-workflow` en `layout: split`
4. `solution-modules` en `layout: split`

Contrainte:
- aucun changement de structure
- aucun changement de layout
- aucun changement d'image
- aucun ajout de nouvelle section

## Real rendering behavior

### Hero
Le hero est rendu par `PublicFrontHero` avec:
- un eyebrow automatique `Solutions` / `Solutions`
- `page_title`
- `page_subtitle`
- l'image hero dédiée à `solution-multi-entity-oversight`

### Overview section
La section `solution-overview` est une section `split` avec:
- un kicker automatique `Solution`
- un titre
- un body
- une liste de points
- un CTA principal vers `/pricing`
- un CTA secondaire vers `/pages/contact-us`

### Workflow section
La section `solution-workflow` est une section `split` avec:
- un kicker automatique `Mode operatoire` / `Operating model`
- un titre
- un body
- une liste d’étapes
- un fond gris clair

### Modules section
La section `solution-modules` est une section `split` avec:
- un kicker automatique `Modules et pages` / `Modules and pages`
- un titre
- un body
- une liste de modules/pages incluses

## Audit

### What already works
- la page porte déjà une vraie promesse de pilotage transversal
- la structure `overview -> workflow -> modules` est adaptée à une lecture direction / management
- les éléments cités sont cohérents avec un usage multi-entité: indicateurs, vues revenue, operations, équipe, alertes
- le workflow actuel fait déjà sentir le passage de la lecture globale vers l’action locale

### Main weaknesses
- le sous-titre actuel reste un peu descriptif et ne fait pas assez sentir la tension entre vue globale et réalité locale
- le body overview parle de vue transverse, mais pas assez d’arbitrage, de comparaison utile, ni de décision plus rapide
- le workflow pourrait mieux raconter la chaîne signal -> comparaison -> priorité -> action coordonnée
- la section modules reste encore trop orientée inventaire de pages au lieu d’expliquer leur rôle dans la gouvernance
- la page doit davantage respirer le niveau “leadership / multi-site / franchise / groupe” qu’un simple dashboard consolidé

### Phase 16 decisions
- la page `solution-multi-entity-oversight` doit raconter un vrai système de pilotage multi-entité
- la page doit relier visibilité transverse, comparaison, priorisation, et arbitrage dirigeant dans un même récit
- le texte doit rassurer à la fois sur la vue globale et sur la capacité à redescendre vers le bon niveau local
- la section modules doit montrer pourquoi ces espaces comptent dans une gouvernance plus claire
- le CTA principal vers pricing reste pertinent, mais la page doit mieux préparer ce passage par une promesse plus mature et plus premium

## Message architecture for solution-multi-entity-oversight

### Primary message
Pilotez plusieurs entités, sites, ou équipes avec une lecture plus claire des écarts, des priorités, et des actions à coordonner.

### Supporting messages
- mieux lire les signaux transverses
- mieux comparer entités, équipes, et périodes
- mieux prioriser les écarts qui méritent une décision
- mieux redescendre l’action vers le bon niveau local

### CTA strategy
- overview section: `Voir les tarifs` en principal, `Nous contacter` en secondaire
- workflow section: pas de nouveau CTA, la narration doit porter la valeur
- modules section: garder une lecture produit concrète sans ajouter de nouvelles actions

## Editorial guardrails

### Sell leadership clarity, not just dashboard consolidation
Le coeur de la page doit rester:
- visibilité transverse
- comparaison utile
- priorisation
- arbitrage
- coordination d’action

Pas:
- une simple page de reporting
- un discours abstrait sur “la data”
- une promesse de contrôle vague sans sortie vers l’action

### Keep both levels visible: global and local
Le texte doit faire sentir que:
- la direction voit mieux ce qui bouge entre les entités
- les écarts importants ressortent plus vite
- les décisions peuvent être orientées vers la bonne équipe ou le bon module
- la vue globale n’écrase pas la réalité locale

### Make the solution feel end-to-end
La page doit montrer que la solution relie:
- les indicateurs globaux
- les comparaisons entre entités
- les points d’attention
- les priorités
- la bascule vers les modules métiers
- la coordination des prochaines actions

## Section copy

### 1. Hero

#### Section goal
Faire comprendre très vite que `Pilotage multi-entreprise` aide les groupes, franchises, et organisations multi-site à garder une vue claire sans perdre la lecture locale.

#### Source copy
- Title: Multi-entity oversight
- Subtitle: Get one shared leadership view across entities, performance, and priorities so teams can compare faster, decide more clearly, and act without losing local context.

#### FR final
- Title: Pilotage multi-entreprise
- Subtitle: Obtenez une vue dirigeante partagée sur les entités, la performance, et les priorités pour comparer plus vite, décider plus clairement, et agir sans perdre le contexte local.

#### EN final
- Title: Multi-entity oversight
- Subtitle: Get one shared leadership view across entities, performance, and priorities so teams can compare faster, decide more clearly, and act without losing local context.

### 2. Overview section

#### Real section shape
La section `solution-overview` contient:
- un kicker automatique
- un titre
- un body
- une liste de points
- deux CTA

#### Section goal
Positionner la solution comme une couche de pilotage transverse pour les structures qui doivent garder ensemble lecture globale et pilotage local.

#### Source copy
- Title: Lead multiple entities with clearer visibility and stronger alignment
- Body: Multi-entity oversight connects cross-functional indicators, shared leadership views, and comparison logic so teams can read performance more clearly and coordinate action without breaking context.

#### FR final
- Title: Pilotez plusieurs entités avec plus de visibilité et un meilleur alignement
- Body: Pilotage multi-entreprise relie indicateurs transverses, vues partagées, et logique de comparaison pour aider la direction à lire la performance plus clairement et à coordonner l’action sans casser le contexte.

#### EN final
- Title: Lead multiple entities with clearer visibility and stronger alignment
- Body: Multi-entity oversight connects cross-functional indicators, shared leadership views, and comparison logic so teams can read performance more clearly and coordinate action without breaking context.

#### Recommended items
- FR:
  - Vue consolidée sur plusieurs entités ou business units
  - Indicateurs partagés entre les modules clés
  - Priorités et points d’attention visibles rapidement
  - Pilotage direction et responsables dans un même espace
- EN:
  - Consolidated view across entities or business units
  - Shared indicators across core modules
  - Attention points surfaced quickly
  - Leadership and managers working from the same view

### 3. Workflow section

#### Real section shape
La section `solution-workflow` contient:
- un kicker automatique
- un titre
- un body
- une liste d’étapes

#### Section goal
Montrer une boucle de pilotage claire, de la lecture globale jusqu’à l’action coordonnée sur le terrain ou dans les modules concernés.

#### Source copy
- Title: Move from shared visibility to coordinated action without losing the local picture
- Body: Leadership reviews the top-level signals, identifies the gaps that matter, drills into the right team or entity, and turns priorities into action through the right module or owner.

#### FR final
- Title: Passez de la visibilité partagée à l’action coordonnée sans perdre la lecture locale
- Body: La direction lit les signaux globaux, identifie les écarts qui comptent, descend au bon niveau équipe ou entité, puis transforme les priorités en action via le bon module ou le bon responsable.

#### EN final
- Title: Move from shared visibility to coordinated action without losing the local picture
- Body: Leadership reviews the top-level signals, identifies the gaps that matter, drills into the right team or entity, and turns priorities into action through the right module or owner.

#### Recommended items
- FR:
  - 1. Lire les indicateurs globaux
  - 2. Identifier les écarts ou opportunités
  - 3. Explorer le détail par équipe ou entité
  - 4. Coordonner les prochaines actions
- EN:
  - 1. Review top-level indicators
  - 2. Identify gaps or opportunities
  - 3. Drill into detail by team or entity
  - 4. Coordinate next actions

### 4. Modules section

#### Real section shape
La section `solution-modules` contient:
- un kicker automatique
- un titre
- un body
- une liste de modules/pages

#### Section goal
Montrer que la solution s’appuie sur les bons espaces de commandement et de lecture transverse pour transformer la visibilité en gouvernance actionnable.

#### Source copy
- Title: The shared command views that keep governance, comparison, and follow-through connected
- Body: The solution brings together the views leadership relies on to compare entities, track signals, surface priorities, and redirect action without managing each entity in isolation.

#### FR final
- Title: Les vues de commandement qui gardent gouvernance, comparaison, et suivi dans le même système
- Body: La solution réunit les vues sur lesquelles la direction s’appuie pour comparer les entités, suivre les signaux, faire ressortir les priorités, et rediriger l’action sans piloter chaque entité isolément.

#### EN final
- Title: The shared command views that keep governance, comparison, and follow-through connected
- Body: The solution brings together the views leadership relies on to compare entities, track signals, surface priorities, and redirect action without managing each entity in isolation.

#### Recommended items
- FR:
  - Dashboard global
  - Vue revenu
  - Vue opérations
  - Vue équipe
  - Alertes
  - Rapports partagés
- EN:
  - Global dashboard
  - Revenue view
  - Operations view
  - Team view
  - Alerts
  - Shared reports

## Final reminders for implementation
- ne pas changer la structure de la page
- ne pas ajouter de nouvelles sections
- ne pas promettre de gouvernance complexe non soutenue par le produit
- éviter le ton “BI / analytics tool” générique
- garder le lien clair entre lecture globale, arbitrage, et action locale

## Status
Phase 16 est maintenant documentée et prête pour application future au contenu live local.
