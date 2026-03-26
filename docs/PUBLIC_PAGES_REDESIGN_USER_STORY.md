# Public Pages Redesign - User Story

Derniere mise a jour: 2026-03-24

## Goal
Repenser les pages publiques de MALIKIA pour qu elles soient plus claires, plus credibles, et plus orientees conversion en s appuyant sur:
- les nouvelles sections deja disponibles dans le mini CMS
- les vraies captures produit deja injectees dans plusieurs pages
- des images contextuelles et metier sourcables depuis Canva
- les patterns de structure observes sur des acteurs comme Jobber, Workiz, ServiceTitan, et Housecall Pro

Contrainte produit majeure:
- on ne touche pas au hero de la page `welcome`
- on ne touche a aucun footer des pages publiques

## Problem Statement
Le socle CMS est maintenant plus riche, mais l architecture editoriale des pages reste encore trop proche d un enchainement de sections descriptives.

Les pages doivent mieux faire 4 choses:
- expliquer rapidement la promesse de la plateforme
- relier chaque promesse a un vrai flux produit
- prouver que le systeme est concret avec des captures, des exemples et des outcomes
- faciliter la navigation entre `welcome`, pages modules, pages solutions et pages industries

## Scope
Ce chantier couvre:
- la page `welcome`, hors hero et hors footer
- les pages modules
- les pages solutions
- les pages industries

Pages cibles actuelles:
- `welcome`
- `sales-crm`
- `reservations`
- `operations`
- `commerce`
- `marketing-loyalty`
- `ai-automation`
- `command-center`
- `solution-sales-quoting`
- `solution-field-services`
- `solution-reservations-queues`
- `solution-commerce-catalog`
- `solution-marketing-loyalty`
- `solution-multi-entity-oversight`
- `industry-plumbing`
- `industry-hvac`
- `industry-electrical`
- `industry-cleaning`
- `industry-salon-beauty`
- `industry-restaurant`

## Non-goals
- modifier le hero `welcome`
- modifier n importe quel footer public
- refaire les pages `pricing`, `contact`, `legal`, ou le mega menu dans ce chantier
- ajouter un nouveau type technique de section si les types actuels suffisent
- copier visuellement un concurrent de facon trop directe

## Product Outcome
Le resultat attendu n est pas juste "des pages plus jolies".

Le resultat attendu est:
- une `welcome` qui oriente mieux vers les bons parcours
- des pages modules qui montrent le produit au lieu de seulement le decrire
- des pages solutions qui assemblent plusieurs modules dans une histoire de resultat
- des pages industries qui parlent le langage de chaque vertical plutot que du logiciel en general
- une direction image plus coherente, avec captures produit pour la preuve et Canva pour le contexte metier

## Benchmark Inputs
Notes basees sur les sites officiels consultes le 2026-03-24. Les conclusions ci-dessous sont des inferences produit a partir de leurs pages publiques, pas des citations contractuelles.

References:
- Jobber: https://www.getjobber.com/
- Workiz: https://www.workiz.com/
- ServiceTitan Features: https://www.servicetitan.com/features
- Housecall Pro: https://www.housecallpro.com/

### Benchmark Takeaways

#### Jobber
Patterns observes:
- decoupage tres lisible du message par grandes etapes de valeur: `Get Noticed`, `Win Jobs`, `Work Smarter`, `Boost Profits`
- forte articulation entre promesse business et preuve concrete
- gros usage de metriques et de temoignages relies a chaque bloc
- profondeur verticale importante avec beaucoup d industries visibles

Ce que MALIKIA doit reprendre:
- un cadrage par "systeme de croissance" plutot que par liste de features
- un rythme de page qui alterne promesse, capture, preuve, CTA
- une navigation facile entre pages modules et pages industries

Ce que MALIKIA ne doit pas reprendre:
- la surabondance de micro-liens dans une meme zone
- la repetition excessive de CTA identiques

#### Workiz
Patterns observes:
- discours tres operationnel et direct
- promesse forte autour de l efficacite terrain, de la communication, de la prise de rendez-vous et du paiement
- mise en avant de l AI et de l online booking comme leviers de conversion
- benefices tres lisibles pour des equipes terrain qui veulent "aller vite"

Ce que MALIKIA doit reprendre:
- des sections tres concretes, avec une promesse courte et un resultat visible
- des captures ou modules qui montrent l execution, pas seulement la strategie
- des blocs qui connectent acquisition, operations et paiement dans un meme recit

Ce que MALIKIA ne doit pas reprendre:
- un ton trop orientee "tool catalog"
- des pages trop generiques pour tous les metiers a la fois

#### ServiceTitan
Patterns observes:
- structure tres claire par familles: `Front Office`, `Field Operations`, `Client Experience`, `Management and Insight`
- forte capacite a relier les modules entre eux
- grand poids donne a la profondeur fonctionnelle et a la lisibilite de l architecture produit

Ce que MALIKIA doit reprendre:
- une meilleure logique de famille produit
- des pages solutions qui montrent comment plusieurs modules travaillent ensemble
- des renvois coherents entre modules, solutions et industries

Ce que MALIKIA ne doit pas reprendre:
- une complexite enterprise trop lourde pour nos pages publiques
- un discours trop abstrait sur la plateforme

#### Housecall Pro
Patterns observes:
- page d accueil tres orientee croissance + execution
- industries visibles tres tot dans la page
- preuves quantitatives et screenshots tres vite apres la promesse
- categories de croissance et gestion d operations faciles a scanner

Ce que MALIKIA doit reprendre:
- une meilleure visibilite des industries des le haut de la `welcome`
- une meilleure place des captures produit dans le flux de page
- un systeme de CTA simple et progressif

Ce que MALIKIA ne doit pas reprendre:
- une structure trop uniforme si elle nous empeche de differencier modules et solutions

## Available CMS Building Blocks
Le mini CMS nous donne deja assez de briques pour eviter une refonte technique lourde.

Sections/layouts reutilisables clefs:
- `feature_tabs`
- `feature_pairs`
- `showcase_cta`
- `industry_grid`
- `story_grid`
- `testimonial_grid`
- `duo`
- `split`
- `stack`
- `testimonial`
- `welcome_trust`
- `welcome_features`
- `welcome_workflow`
- `welcome_field`
- `welcome_cta`
- `welcome_custom`

## Recommended Use Of Each Section Type

### `feature_tabs`
A utiliser pour:
- expliquer des flux de travail
- structurer les 3 a 5 grands moments d une offre
- transformer une page module en histoire produit

Ne pas utiliser pour:
- un simple mur de features sans hierarchie

### `feature_pairs`
A utiliser pour:
- comparer deux dimensions d un meme sujet
- montrer `bureau + terrain`, `avant + apres`, `lead + execution`, `module + impact`

### `showcase_cta`
A utiliser pour:
- les blocs de conversion de fin de page
- les transitions entre modules et solutions
- les renvois vers contact, pricing ou demo

### `industry_grid`
A utiliser pour:
- rendre les verticales visibles depuis `welcome`
- montrer la couverture metier dans une page solution

### `story_grid`
A utiliser pour:
- raconter des cas d usage
- montrer des moments de parcours client
- rendre une page plus concrete que purement fonctionnelle

### `testimonial_grid`
A utiliser pour:
- preuve sociale
- citations client courtes
- logos, roles, et contexte metier

## Image Sourcing Rules
La refonte ne doit pas melanger au hasard illustrations, captures produit, et stock images.

Ordre de priorite recommande:
1. captures reelles de la plateforme quand la section parle d un flux, d un module, ou d une surface produit
2. images Canva quand la section a besoin de contexte metier, d ambiance, ou de representation humaine
3. illustrations existantes seulement si aucune capture produit ni image Canva pertinente n est disponible

Regles:
- les pages modules doivent prioriser les captures produit
- les pages solutions peuvent mixer captures produit et images Canva, mais la preuve produit doit rester dominante
- les pages industries doivent utiliser Canva pour montrer le metier, l environnement, l equipe, ou le client final
- les visuels Canva doivent rester coherents avec la palette, le ton, et la promesse premium de MALIKIA
- on evite les visuels Canva trop generiques, trop corporate, ou sans lien direct avec le metier vise
- on evite de reutiliser la meme image Canva sur trop de pages differentes

Usages cibles de Canva:
- `welcome`: scenes metier ou images d ambiance pour renforcer le recit, hors hero
- pages industries: images metier specifiques a chaque vertical
- `story_grid` et `testimonial_grid`: portraits, scenes client, equipe, intervention, accueil, service sur site
- sections de transition ou de contexte quand une capture produit serait trop froide ou trop repetitive

## Content Principles
- une section = une idee forte
- une page = une narration claire, pas un catalogue de blocs
- chaque page importante doit montrer le produit reel
- les captures doivent soutenir la promesse, pas la dupliquer
- les images Canva doivent apporter du contexte metier, pas remplacer la preuve produit
- les CTA doivent guider vers l action suivante logique
- on evite les formulations vagues du type `all-in-one`, `streamline`, `empower` si elles ne sont pas reliees a un cas concret

## Cross-page IA Target
L architecture cible doit permettre a un visiteur de naviguer comme suit:
- `welcome` -> comprendre la promesse globale
- `welcome` -> choisir un angle `module`, `solution`, ou `industrie`
- page module -> comprendre un bloc fonctionnel precis
- page solution -> comprendre comment plusieurs blocs resolvent un probleme metier
- page industrie -> comprendre pourquoi MALIKIA correspond a un metier specifique

## Target Information Architecture

### 1. Welcome Page
Contrainte:
- hero intact
- footer intact sur toute page publique

Le redesign doit porter sur le corps de page entre ces deux zones.

Structure cible recommandee:
1. `welcome_trust`
2. `feature_tabs`
3. bloc preuve avec captures et outcomes
4. bloc industries visibles
5. bloc `story_grid` ou `feature_pairs`
6. bloc de CTA de fin de parcours

Role de chaque zone:
- `welcome_trust`: rassurer vite
- `feature_tabs`: faire comprendre le systeme par grands moments de valeur
- preuve: montrer des captures reelles et de vrais outcomes
- industries: aider l auto-selection
- story/proof: montrer des parcours concrets
- CTA: pousser vers modules, pricing, contact, ou demo

### 2. Module Pages
Pages concernees:
- `sales-crm`
- `reservations`
- `operations`
- `commerce`
- `marketing-loyalty`
- `ai-automation`
- `command-center`

Structure cible recommandee:
1. overview `split` ou `duo` avec capture forte
2. `feature_tabs` pour le workflow du module
3. `feature_pairs` ou `story_grid` pour montrer 2 ou 3 usages cles
4. bloc surfaces/pages incluses
5. bloc CTA vers solution ou contact

Regle:
- une page module doit donner une impression de profondeur sans devenir une doc produit
- on privilegie 1 a 2 captures reelles fortes plutot que 4 visuels repetitifs

### 3. Solution Pages
Pages concernees:
- `solution-sales-quoting`
- `solution-field-services`
- `solution-reservations-queues`
- `solution-commerce-catalog`
- `solution-marketing-loyalty`
- `solution-multi-entity-oversight`

Structure cible recommandee:
1. probleme ou promesse metier
2. parcours de solution via `feature_tabs`
3. modules relies a cette solution
4. preuve ou cas d usage
5. CTA vers contact ou pricing

Regle:
- une page solution doit assembler plusieurs modules dans une histoire de resultat
- elle ne doit pas juste re-sommer des pages modules

### 4. Industry Pages
Pages concernees:
- `industry-plumbing`
- `industry-hvac`
- `industry-electrical`
- `industry-cleaning`
- `industry-salon-beauty`
- `industry-restaurant`

Structure cible recommandee:
1. promesse metier specifique
2. 3 a 5 enjeux du metier
3. workflow ou systeme adapte a ce vertical
4. modules les plus pertinents
5. preuve sociale ou scenarii
6. CTA

Regle:
- chaque page industrie doit sembler ecrite pour ce metier
- elle ne doit pas etre un simple clone textuel des autres verticales

## Welcome Page Specific User Stories

### US-PUB-001 - Better system framing on welcome
As a first-time visitor, I can understand the main value chain of MALIKIA after the hero so that I know whether the platform is relevant for my business.

Acceptance criteria:
- the first non-hero section clarifies the operating model in a few seconds
- the page explains the platform through business stages, not just features
- each stage can route to a deeper page

### US-PUB-002 - Welcome uses visible proof
As a visitor, I can see real product proof in the welcome body so that the platform feels credible and not abstract.

Acceptance criteria:
- at least one section after the hero uses real product screenshots
- the proof sections connect to a business claim
- generic illustrations are reduced where a real screenshot is available
- non-product contextual imagery should preferentially come from Canva

### US-PUB-003 - Welcome improves self-selection
As a service business owner, I can quickly choose my most relevant path from the welcome page so that I can continue toward the content that matches my need.

Acceptance criteria:
- the body of the welcome page exposes clear entry points toward:
  - modules
  - solutions
  - industries
- these entry points appear before the final CTA

### US-PUB-004 - Welcome hero and all public footers stay unchanged
As a product stakeholder, I want the redesign to preserve the current welcome hero and every public footer so that the redesign stays within scope.

Acceptance criteria:
- the hero source content remains unchanged
- every public footer source content remains unchanged
- redesign work on `welcome` happens only between the hero and the shared footer

## Module Page User Stories

### US-PUB-005 - Module pages feel product-led
As a visitor evaluating one module, I can see how the module works in practice so that I understand more than a marketing promise.

Acceptance criteria:
- the first visual section of each key module page uses a real or realistic product view
- the module page shows a practical workflow
- the page has a clear next CTA
- contextual support imagery, when needed, should come from Canva rather than random stock sources

### US-PUB-006 - Module pages reduce repetition
As a visitor browsing several module pages, I do not feel like I am reading the same page six times so that each module feels distinct.

Acceptance criteria:
- section order can vary per module
- page copy reflects the specific job of the module
- visual proof differs where the product surface differs

## Solution Page User Stories

### US-PUB-007 - Solution pages connect modules into one outcome
As a visitor with a specific business problem, I can understand how multiple modules work together so that I see a full solution instead of isolated features.

Acceptance criteria:
- each solution page describes a business problem
- each solution page maps at least two modules into one journey
- the CTA points toward a relevant next step

### US-PUB-008 - Solution pages are not duplicate module pages
As a returning visitor, I can see why a solution page exists separately from module pages so that the site architecture feels intentional.

Acceptance criteria:
- the solution page copy is centered on outcome and workflow
- the page includes cross-module framing
- it does not simply restate module headlines one by one

## Industry Page User Stories

### US-PUB-009 - Industry pages speak the language of the trade
As a plumbing, HVAC, cleaning, salon, restaurant, or electrical business owner, I can recognize my constraints and workflow on the industry page so that I feel the platform understands my context.

Acceptance criteria:
- each industry page uses trade-specific wording
- each industry page highlights the most relevant workflows and modules
- the page includes at least one concrete scenario

### US-PUB-010 - Industry pages guide visitors toward the right product depth
As a visitor entering from an industry page, I can continue toward the most relevant module or solution page so that I do not hit a dead end.

Acceptance criteria:
- each industry page links to relevant module pages
- each industry page links to one relevant solution page when applicable
- CTAs are aligned with the vertical

## Design Direction
La direction attendue n est pas une copie de Jobber ou Workiz.

La direction attendue est:
- plus structuree
- plus "systeme"
- plus credibilisee par le produit reel
- plus verticale
- plus lisible en scan mobile et desktop

Le ton doit rester MALIKIA:
- plus premium que `cheap SaaS`
- plus proche du terrain que `enterprise abstraction`
- plus clair que demonstratif

## Content And UX Rules
- pas plus de 1 idee forte par section
- pas plus de 2 CTA principaux concurrents dans une meme bande
- pas de repetition mot pour mot du meme bloc entre `welcome`, pages modules, et pages solutions
- les captures doivent etre lisibles au format de section publique
- les images de contexte hors produit doivent venir en priorite de Canva
- les pages modules et solutions doivent utiliser des renvois mutuels coherents
- les pages industries doivent donner un sentiment de personnalisation sans exiger un contenu totalement bespoke pour chaque metier

## Delivery Sequence

### Phase 1 - Welcome body redesign
Objectif:
- rearchitecturer le corps de `welcome` sans toucher au hero ni au footer

### Phase 2 - Key module pages redesign
Priorite:
- `sales-crm`
- `operations`
- `reservations`
- `commerce`
- `marketing-loyalty`
- `command-center`

### Phase 3 - Solution pages redesign
Objectif:
- faire des pages solutions de vrais assembleurs de modules

### Phase 4 - Industry pages redesign
Objectif:
- verticaliser les pages industries a partir des workflows et preuves les plus pertinents

### Phase 5 - Copy and consistency pass
Objectif:
- harmoniser CTA, preuves, liens croises, et repetition de wording

## Definition Of Done
- la `welcome` a ete repensee entre hero et footer uniquement
- le hero `welcome` est intact
- tous les footers publics sont intacts
- les pages modules montrent mieux le produit reel
- les pages solutions racontent un resultat cross-module
- les pages industries semblent plus specifiques a leur vertical
- les nouvelles sections du CMS sont utilisees de facon intentionnelle
- les captures reelles sont privilegiees sur les illustrations quand elles apportent plus de credibilite
- les images de contexte ou d ambiance sont selectionnees depuis Canva quand elles sont pertinentes
- la navigation entre `welcome`, modules, solutions et industries est plus claire qu avant

## Implementation Note
Avant toute execution visuelle, il faudra valider:
- l ordre cible des sections par page
- le niveau de profondeur par type de page
- quelles captures doivent rester en place
- quelles pages doivent etre traitees en premier

Ce document sert de base produit avant la phase de redesign effective.
