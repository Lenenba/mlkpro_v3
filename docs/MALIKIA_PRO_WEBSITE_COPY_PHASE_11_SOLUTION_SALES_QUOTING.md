# Malikia Pro Website Copy - Phase 11 Solution Sales & Quoting

Dernière mise à jour: 2026-04-09

## Purpose
Ce document couvre la `Phase 11 - solution-sales-quoting` du chantier copywriting.

Objectif:
- auditer la page `solution-sales-quoting` actuellement visible
- renforcer le positionnement de la solution autour d’un workflow commercial complet, de la demande au devis approuvé
- remplacer les formulations encore trop descriptives par un vrai récit de conversion, de qualification, et de suivi commercial
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
- [MALIKIA_PRO_WEBSITE_COPY_PHASED_USER_STORY.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASED_USER_STORY.md)

## Real page structure
La page `solution-sales-quoting` rend actuellement les zones suivantes, dans cet ordre:
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
- l'image hero dédiée à `solution-sales-quoting`

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
- la page a déjà une bonne logique de solution complète, pas seulement de module isolé
- la structure `overview -> workflow -> modules` est bien adaptée pour raconter un parcours commercial
- les modules cités sont cohérents: demandes, devis, clients, pipeline, scan de plans
- la promesse de conversion commerciale est présente et compréhensible

### Main weaknesses
- le titre `Une solution pour structurer la conversion commerciale` reste encore un peu générique
- le workflow pourrait mieux faire sentir le passage de la demande entrante à la décision approuvée, avec moins de friction
- la page doit mieux insister sur la continuité commerciale entre qualification, devis, relance, et conversion
- la section modules reste encore trop descriptive et pas assez orientée bénéfice concret
- la page doit davantage rivaliser avec les bons sites du domaine en parlant de vitesse, clarté pipeline, et cohérence commerciale

### Phase 11 decisions
- la page `solution-sales-quoting` doit raconter une histoire de conversion plus nette
- le mot `solution` doit porter l’idée d’un workflow complet partagé par l’équipe commerciale
- la page doit faire sentir moins de perte entre lead, devis, relance, et vente
- la section modules doit expliquer pourquoi ces espaces comptent dans la conversion, pas seulement les nommer
- le CTA principal vers pricing reste bon, mais tout le copy doit mieux préparer à cette action

## Message architecture for solution-sales-quoting

### Primary message
Passez de la demande entrante au devis approuvé dans un workflow commercial plus clair, plus rapide, et plus facile à suivre.

### Supporting messages
- centraliser les demandes au même endroit
- qualifier plus vite sans perdre le contexte client
- envoyer des devis plus cohérents et plus simples à suivre
- garder la relance et la conversion visibles jusqu’à la décision

### CTA strategy
- overview section: `Voir les tarifs` en principal, `Contact us` en secondaire
- workflow section: pas de nouveau CTA, la narration doit suffire
- modules section: garder une lecture produit concrète sans ajouter de nouvelles actions

## Editorial guardrails

### Sell a workflow, not a feature list
Le coeur de la page doit rester:
- demande
- qualification
- devis
- relance
- approbation
- conversion

Pas:
- une liste statique de pages
- une simple description de CRM
- un discours trop théorique sur le “sales process”

### Keep the page operational and commercial
Le texte doit faire sentir que:
- l’équipe voit les opportunités plus clairement
- le devis repart du bon contexte
- la relance ne se perd pas
- la conversion devient plus facile à piloter

### Make the solution feel end-to-end
La page doit montrer que la solution relie:
- la demande entrante
- la qualification
- la fiche client
- le devis
- le pipeline
- la décision finale

## Section copy

### 1. Hero

#### Section goal
Faire comprendre très vite que `Vente & devis` aide les équipes à centraliser la demande, qualifier plus vite, envoyer des devis propres, et suivre la décision jusqu’à la conversion.

#### Source copy
- Title: Sales & quoting
- Subtitle: Capture inbound demand, qualify opportunities, send cleaner quotes, and keep follow-up visible until the work is approved.

#### FR final
- Title: Vente & devis
- Subtitle: Captez les demandes entrantes, qualifiez les opportunités, envoyez des devis plus propres, et gardez la relance visible jusqu’à l’approbation du travail.

#### EN final
- Title: Sales & quoting
- Subtitle: Capture inbound demand, qualify opportunities, send cleaner quotes, and keep follow-up visible until the work is approved.

### 2. Overview section

#### Real section shape
La section `solution-overview` contient:
- un kicker automatique
- un titre
- un body
- une liste de points
- deux CTA

#### Section goal
Positionner la solution comme une couche commerciale complète qui aide à structurer la conversion, pas seulement à gérer des leads.

#### Source copy
- Title: Move from first request to approved quote without friction
- Body: Sales & quoting connects inbound demand, qualification, customer context, quoting, and follow-up so the team can convert faster without rebuilding the same information at every step.

#### FR final
- Title: Passez de la première demande au devis approuvé sans friction
- Body: Vente & devis relie la demande entrante, la qualification, le contexte client, le devis, et la relance pour aider l’équipe à convertir plus vite sans reconstruire les mêmes informations à chaque étape.

#### EN final
- Title: Move from first request to approved quote without friction
- Body: Sales & quoting connects inbound demand, qualification, customer context, quoting, and follow-up so the team can convert faster without rebuilding the same information at every step.

#### Recommended items
- FR:
  - Demandes centralisées depuis le web ou l’équipe
  - Fiches clients partagées avec historique visible
  - Devis suivis par statut, relance, et approbation
  - Pipeline commercial plus clair pour prioriser les opportunités
- EN:
  - Centralized requests from the web or the team
  - Shared customer records with visible history
  - Quotes tracked through status, follow-up, and approval
  - A clearer sales pipeline to prioritize opportunities

### 3. Workflow section

#### Real section shape
La section `solution-workflow` contient:
- un kicker automatique
- un titre
- un body
- une liste d’étapes

#### Section goal
Montrer un parcours de vente concret, simple à suivre, et cohérent du lead jusqu’à la conversion.

#### Source copy
- Title: A clear operating flow for sales teams
- Body: The team captures the request, qualifies the opportunity, prepares the quote, follows up with the right context, and moves the work forward without jumping between disconnected tools.

#### FR final
- Title: Un parcours clair pour les équipes commerciales
- Body: L’équipe capte la demande, qualifie l’opportunité, prépare le devis, relance avec le bon contexte, puis fait avancer la décision sans sauter entre des outils déconnectés.

#### EN final
- Title: A clear operating flow for sales teams
- Body: The team captures the request, qualifies the opportunity, prepares the quote, follows up with the right context, and moves the work forward without jumping between disconnected tools.

#### Recommended workflow items
- FR:
  - 1. Capturer le lead entrant
  - 2. Qualifier et enrichir le dossier client
  - 3. Préparer puis envoyer le devis
  - 4. Relancer et convertir en vente ou intervention
- EN:
  - 1. Capture inbound leads
  - 2. Qualify and enrich the customer record
  - 3. Prepare and send the quote
  - 4. Follow up and convert into work or revenue

### 4. Modules section

#### Real section shape
La section `solution-modules` contient:
- un kicker automatique
- un titre
- un body
- une liste de modules/pages

#### Section goal
Montrer les espaces concrets qui rendent le workflow commercial exécutable et visible au quotidien.

#### Source copy
- Title: The commercial spaces that keep quoting and conversion moving
- Body: The solution brings together the pages teams rely on to capture demand, structure quotes, track opportunity movement, and keep customer context available from first request to approval.

#### FR final
- Title: Les espaces commerciaux qui font avancer devis et conversion
- Body: La solution réunit les pages sur lesquelles l’équipe s’appuie pour capter la demande, structurer les devis, suivre le mouvement des opportunités, et garder le contexte client disponible de la première demande jusqu’à l’approbation.

#### EN final
- Title: The commercial spaces that keep quoting and conversion moving
- Body: The solution brings together the pages teams rely on to capture demand, structure quotes, track opportunity movement, and keep customer context available from first request to approval.

#### Recommended module items
- FR:
  - Dashboard commercial
  - Demandes
  - Devis
  - Clients
  - Pipeline
  - Scan de plans
- EN:
  - Sales dashboard
  - Requests
  - Quotes
  - Customers
  - Pipeline
  - Plan scan

## Final quality checklist for this page
- le hero doit faire sentir un vrai flux commercial, pas juste un CRM
- la section overview doit clarifier la promesse de conversion commerciale
- la section workflow doit raconter une progression nette de la demande vers l’approbation
- la section modules doit soutenir la conversion, pas seulement énumérer des espaces
- la page doit mieux faire sentir vitesse, clarté, et continuité commerciale
- les CTA doivent mener naturellement vers pricing ou prise de contact

## Recommendation before live application
Avant application live de cette phase:
- vérifier si `Vente & devis` reste bien le nom éditorial souhaité en français
- vérifier si le CTA secondaire `Contact us` doit être localisé différemment dans la page solution
- vérifier si `Scan de plans` reste bien un module pertinent à montrer dans cette solution ou s’il doit être formulé plus clairement

