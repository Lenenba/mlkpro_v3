# Malikia Pro Website Copy - Phase 12 Solution Field Services

Dernière mise à jour: 2026-04-09

## Purpose
Ce document couvre la `Phase 12 - solution-field-services` du chantier copywriting.

Objectif:
- auditer la page `solution-field-services` actuellement visible
- renforcer le positionnement de la solution autour d’un workflow terrain complet, de la planification à la clôture
- remplacer les formulations encore trop descriptives par un vrai récit d’exécution, de coordination, et de preuve de travail
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
- [MALIKIA_PRO_WEBSITE_COPY_PHASED_USER_STORY.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASED_USER_STORY.md)

## Real page structure
La page `solution-field-services` rend actuellement les zones suivantes, dans cet ordre:
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
- l'image hero dédiée à `solution-field-services`

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
- la page a déjà une bonne logique de solution terrain complète
- la structure `overview -> workflow -> modules` convient bien pour raconter un cycle terrain bout en bout
- les modules cités sont cohérents avec l’exécution: planning, jobs, tâches, présence, équipe, preuves de travail
- la promesse de coordination et de contexte partagé est déjà présente

### Main weaknesses
- le titre `Une solution complete pour les operations terrain` reste encore un peu générique face à des concurrents plus affûtés
- le workflow peut mieux faire sentir la continuité entre planification, dispatch, exécution, et clôture
- la page doit mieux insister sur la maîtrise terrain, la visibilité d’équipe, et la qualité de livraison
- la section modules reste un peu catalogue et pas assez orientée bénéfice opérationnel concret
- la page doit davantage parler de moins de perte d’information, moins d’aller-retour, et meilleure clôture de travail

### Phase 12 decisions
- la page `solution-field-services` doit raconter un workflow terrain complet et crédible
- le mot `solution` doit porter l’idée d’une exécution coordonnée, pas seulement d’une gestion de planning
- la page doit faire sentir que les équipes partent mieux préparées, exécutent avec plus de contexte, et clôturent plus proprement
- la section modules doit expliquer pourquoi ces espaces comptent dans la livraison du travail
- le CTA principal vers pricing reste pertinent, mais tout le copy doit mieux justifier cette suite

## Message architecture for solution-field-services

### Primary message
Coordonnez planning, dispatch, exécution terrain, et preuve de travail dans un workflow plus clair, plus fiable, et plus simple à piloter.

### Supporting messages
- planifier les ressources au bon moment
- envoyer les équipes avec le bon contexte
- suivre l’exécution sans perdre la visibilité
- clôturer le travail avec preuve, notes, et validation

### CTA strategy
- overview section: `Voir les tarifs` en principal, `Contact us` en secondaire
- workflow section: pas de nouveau CTA, la narration doit porter la valeur
- modules section: garder une lecture produit concrète sans ajouter de nouvelles actions

## Editorial guardrails

### Sell execution, not just scheduling
Le coeur de la page doit rester:
- planification
- dispatch
- exécution
- suivi
- preuve de travail
- clôture

Pas:
- une simple liste d’outils terrain
- un discours centré uniquement sur le calendrier
- une description vague de “gestion d’équipe”

### Keep the page operational and crédible
Le texte doit faire sentir que:
- les managers gardent une meilleure vue du terrain
- les équipes partent avec le bon contexte
- l’avancement reste visible pendant l’exécution
- la fin du travail est mieux documentée et plus facile à valider

### Make the solution feel end-to-end
La page doit montrer que la solution relie:
- la préparation du travail
- l’affectation des équipes
- l’intervention terrain
- les tâches internes
- la présence et la coordination
- la preuve de travail et la validation finale

## Section copy

### 1. Hero

#### Section goal
Faire comprendre très vite que `Services terrain` aide les équipes à planifier, exécuter, et clôturer le travail avec plus de clarté, de contrôle, et de preuve.

#### Source copy
- Title: Field services
- Subtitle: Coordinate scheduling, dispatch, field execution, and proof of work in one operating workflow built to keep teams aligned from assignment to completion.

#### FR final
- Title: Services terrain
- Subtitle: Coordonnez planification, dispatch, exécution terrain, et preuve de travail dans un même workflow opérationnel pensé pour garder les équipes alignées de l’affectation à la clôture.

#### EN final
- Title: Field services
- Subtitle: Coordinate scheduling, dispatch, field execution, and proof of work in one operating workflow built to keep teams aligned from assignment to completion.

### 2. Overview section

#### Real section shape
La section `solution-overview` contient:
- un kicker automatique
- un titre
- un body
- une liste de points
- deux CTA

#### Section goal
Positionner la solution comme une couche terrain complète qui aide à organiser, exécuter, et suivre le travail sans perte de contexte.

#### Source copy
- Title: Run field work with clearer coordination from planning to proof
- Body: Field services brings scheduling, dispatch, jobs, task follow-through, and proof of work together so teams can deliver more consistently without rebuilding context at every step.

#### FR final
- Title: Pilotez le terrain avec une coordination plus claire de la planification jusqu’à la preuve
- Body: Services terrain réunit planning, dispatch, jobs, suivi des tâches, et preuve de travail pour aider les équipes à livrer plus régulièrement sans reconstruire le contexte à chaque étape.

#### EN final
- Title: Run field work with clearer coordination from planning to proof
- Body: Field services brings scheduling, dispatch, jobs, task follow-through, and proof of work together so teams can deliver more consistently without rebuilding context at every step.

#### Recommended items
- FR:
  - Planning équipe et dispatch centralisés
  - Jobs terrain avec preuves, notes, et statuts
  - Tâches internes visibles par rôle ou équipe
  - Suivi opérationnel sans perte de contexte
- EN:
  - Centralized team scheduling and dispatch
  - Field jobs with proof, notes, and statuses
  - Internal tasks visible by role or team
  - Operational follow-up without context loss

### 3. Workflow section

#### Real section shape
La section `solution-workflow` contient:
- un kicker automatique
- un titre
- un body
- une liste d’étapes

#### Section goal
Montrer un parcours terrain concret, de la préparation à la clôture, sans rupture d’information.

#### Source copy
- Title: A clear field workflow from assignment to completion
- Body: Managers plan and assign the work, teams execute with the right context in hand, and completed jobs come back with proof, notes, and validation ready to review.

#### FR final
- Title: Un parcours terrain clair de l’affectation à la clôture
- Body: Les responsables planifient et affectent le travail, les équipes exécutent avec le bon contexte en main, puis les jobs reviennent avec preuves, notes, et validation prêtes à être revues.

#### EN final
- Title: A clear field workflow from assignment to completion
- Body: Managers plan and assign the work, teams execute with the right context in hand, and completed jobs come back with proof, notes, and validation ready to review.

#### Recommended workflow items
- FR:
  - 1. Planifier les ressources et les plages de travail
  - 2. Assigner les jobs et interventions
  - 3. Suivre l’avancement sur le terrain
  - 4. Clôturer avec preuve de travail et validation
- EN:
  - 1. Plan resources and work windows
  - 2. Assign jobs and field visits
  - 3. Track execution on the ground
  - 4. Close the work with proof and validation

### 4. Modules section

#### Real section shape
La section `solution-modules` contient:
- un kicker automatique
- un titre
- un body
- une liste de modules/pages

#### Section goal
Montrer les espaces concrets qui rendent l’exécution terrain pilotable au quotidien, du back-office à la preuve finale.

#### Source copy
- Title: The field workspaces that keep teams aligned through execution
- Body: The solution activates the operational pages teams rely on to schedule, assign, execute, document, and close work with fewer gaps between the office and the field.

#### FR final
- Title: Les espaces terrain qui gardent les équipes alignées pendant l’exécution
- Body: La solution active les pages opérationnelles sur lesquelles les équipes s’appuient pour planifier, affecter, exécuter, documenter, et clôturer le travail avec moins d’écart entre le bureau et le terrain.

#### EN final
- Title: The field workspaces that keep teams aligned through execution
- Body: The solution activates the operational pages teams rely on to schedule, assign, execute, document, and close work with fewer gaps between the office and the field.

#### Recommended module items
- FR:
  - Planning
  - Jobs
  - Tâches
  - Présence
  - Équipe
  - Preuves de travail
- EN:
  - Planning
  - Jobs
  - Tasks
  - Presence
  - Team
  - Proofs

## Final quality checklist for this page
- le hero doit faire sentir une vraie solution terrain bout en bout
- la section overview doit clarifier la promesse de coordination et d’exécution
- la section workflow doit raconter un passage net de l’affectation à la clôture
- la section modules doit soutenir la maîtrise terrain, pas seulement lister des espaces
- la page doit mieux faire sentir fiabilité, visibilité, et qualité de livraison
- les CTA doivent mener naturellement vers pricing ou prise de contact

## Recommendation before live application
Avant application live de cette phase:
- vérifier si `Services terrain` reste bien le nom éditorial souhaité en français
- vérifier si le CTA secondaire `Contact us` doit être localisé différemment sur les pages solution
- vérifier si `Preuves de travail` reste la meilleure formulation produit ou si `preuve de travail` doit être harmonisé ailleurs dans le site

