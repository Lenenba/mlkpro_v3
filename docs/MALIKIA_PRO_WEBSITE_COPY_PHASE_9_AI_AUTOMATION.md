# Malikia Pro Website Copy - Phase 9 AI & Automation

Dernière mise à jour: 2026-04-09

## Purpose
Ce document couvre la `Phase 9 - ai-automation` du chantier copywriting.

Objectif:
- auditer la page `ai-automation` actuellement visible
- renforcer le positionnement du module autour des gains de temps, de l'aide à la décision, et de l'automatisation utile
- remplacer les formulations trop méta ou trop “technologie pour la technologie” par un vrai copy produit orienté usage concret, contexte, et contrôle
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
- [MALIKIA_PRO_WEBSITE_COPY_PHASED_USER_STORY.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASED_USER_STORY.md)

## Real page structure
La page `ai-automation` rend actuellement les zones suivantes, dans cet ordre:
1. Hero aligné sur le front public
2. `ai-automation-flow` en `layout: feature_tabs`
3. `ai-automation-cta` en `layout: showcase_cta`
4. `ai-automation-proof` en `layout: story_grid`

Contrainte:
- aucun changement de structure
- aucun changement de layout
- aucun changement d'image
- aucun ajout de nouvelle section

## Real rendering behavior

### Hero
Le hero est rendu par `PublicFrontHero` avec:
- un eyebrow automatique `Produits` / `Products`
- `page_title`
- `page_subtitle`
- l'image hero dédiée à `ai-automation`

### AI flow
La section `ai-automation-flow` est une section `feature_tabs` avec:
- un kicker
- un titre
- un body introductif
- quatre onglets principaux

Les quatre onglets actuellement visibles sont:
- `Repérer`
- `Suggester`
- `Automatiser`
- `Garder le contrôle`

### CTA block
La section `ai-automation-cta` est une section `showcase_cta` avec:
- kicker
- titre
- body
- badge
- CTA principal
- CTA secondaire
- lien éditorial secondaire

### Proof block
La section `ai-automation-proof` est une section `story_grid` avec:
- kicker
- titre
- body
- trois cartes de preuve visuelle

## Audit

### What already works
- la page a déjà la bonne intuition de fond: l'IA doit rester intégrée aux workflows réels
- les quatre moments visibles sont pertinents: repérer, suggérer, automatiser, garder la validation humaine
- les liens vers `command-center`, `operations`, et `sales-crm` soutiennent bien le positionnement transversal du module
- les images renforcent le côté assistance, analyse, et collaboration

### Main weaknesses
- plusieurs textes parlent encore du “format narratif” ou de la lisibilité de page au lieu de vendre un bénéfice produit réel
- le mot `IA` reste parfois présenté comme une mécanique abstraite plutôt que comme une aide concrète dans un contexte métier
- la preuve actuelle parle davantage de crédibilité de page que de crédibilité opérationnelle
- la promesse d'automatisation n'est pas encore assez précise sur ce qui change pour l'équipe: moins de répétition, moins de ressaisie, moins d'hésitation
- la page doit rassurer plus clairement sur le contrôle humain, pour éviter un discours “black box”

### Phase 9 decisions
- la page `ai-automation` doit rester centrée sur l'utilité métier, pas sur le buzz technologique
- chaque section doit faire sentir que l'IA aide à aller plus vite dans un vrai contexte existant
- l'automatisation doit être présentée comme une réduction de friction, pas comme une substitution totale à l'équipe
- le CTA block doit insister sur le gain de temps avec contrôle conservé
- la preuve doit montrer que l'IA devient crédible lorsqu'elle reste liée au bon contexte, à la bonne validation, et au bon moment

## Message architecture for ai-automation

### Primary message
Utilisez l'IA et l'automatisation pour repérer plus vite, proposer plus utilement, et faire avancer le travail sans perdre le contrôle humain.

### Supporting messages
- repérer les signaux ou répétitions qui comptent
- suggérer du contenu et des actions dans le bon contexte
- automatiser des transitions utiles sans casser le workflow
- garder la validation humaine sur les décisions sensibles

### CTA strategy
- flow section: CTA vers `command-center`, `operations`, et `sales-crm`
- CTA block: `Voir Command Center` en principal, `Voir les tarifs` en secondaire
- proof block: conserver le CTA vers `command-center`

## Editorial guardrails

### Keep AI practical, not theatrical
Le coeur de la page doit rester:
- aide concrète
- contexte métier
- gain de temps
- réduction de friction
- validation humaine
- confiance opérationnelle

Pas:
- un discours futuriste vague
- une célébration de la technologie elle-même
- une promesse générale du type “l’IA transforme tout”

### Do not sell magic, sell assistance
Le texte doit faire sentir que:
- l'assistant aide à voir plus vite
- les suggestions repartent du bon contexte
- l'automatisation enlève des étapes répétitives
- la décision finale reste au bon endroit quand elle doit rester humaine

### Keep human control visible
Le module doit toujours apparaître comme:
- relié aux données et workflows existants
- utile avant, pendant, et après l'action
- transparent dans sa place
- contrôlable par l'équipe

## Section copy

### 1. Hero

#### Section goal
Faire comprendre très vite que `AI & Automation` aide les équipes à aller plus vite dans leurs workflows réels, sans déplacer le travail hors contexte ni enlever la validation humaine.

#### Source copy
- Title: AI & Automation
- Subtitle: Use assistants, drafts, summaries, and workflow automation inside the work your team already does, with help that stays useful, contextual, and reviewable.

#### FR final
- Title: AI & Automation
- Subtitle: Utilisez assistants, brouillons, résumés, et automatisations dans le travail que votre équipe fait déjà, avec une aide qui reste utile, contextualisée, et révisable.

#### EN final
- Title: AI & Automation
- Subtitle: Use assistants, drafts, summaries, and workflow automation inside the work your team already does, with help that stays useful, contextual, and reviewable.

### 2. AI flow

#### Real section shape
La section `ai-automation-flow` contient:
- un kicker
- un titre
- un body
- quatre onglets principaux

#### Section goal
Montrer que le module suit une logique claire: voir plus vite, suggérer mieux, automatiser utilement, et garder le contrôle sur ce qui compte.

#### Source copy
- Kicker: One AI workflow from signal to assisted execution
- Title: Put AI to work where teams already need help, speed, and context
- Body: AI & Automation connects pattern detection, suggestions, workflow automation, and human review so teams can move faster without losing visibility or judgment.

#### FR final
- Kicker: Un workflow IA du signal jusqu'à l'exécution assistée
- Title: Mettez l'IA là où les équipes ont déjà besoin d'aide, de vitesse, et de contexte
- Body: AI & Automation relie détection de signaux, suggestions, automatisation de workflow, et revue humaine pour aider les équipes à aller plus vite sans perdre la visibilité ni le jugement.

#### EN final
- Kicker: One AI workflow from signal to assisted execution
- Title: Put AI to work where teams already need help, speed, and context
- Body: AI & Automation connects pattern detection, suggestions, workflow automation, and human review so teams can move faster without losing visibility or judgment.

#### Recommended tab narrative

##### Tab 1
- Source label: Spot
- Source title: Surface the signals and repetitions that deserve attention
- Source body: Help teams notice patterns, weak signals, and recurring friction earlier so the next action becomes clearer before time is wasted.
- Source CTA: See Operations

- FR label: Repérer
- FR title: Faites remonter les signaux et répétitions qui méritent l'attention
- FR body: Aidez les équipes à voir plus tôt les patterns, signaux faibles, et frictions récurrentes pour que la prochaine action devienne plus claire avant de perdre du temps.
- FR CTA: Voir Operations

- EN label: Spot
- EN title: Surface the signals and repetitions that deserve attention
- EN body: Help teams notice patterns, weak signals, and recurring friction earlier so the next action becomes clearer before time is wasted.
- EN CTA: See Operations

##### Tab 2
- Source label: Suggest
- Source title: Suggest useful drafts and actions without losing source context
- Source body: Keep summaries, drafts, and recommendations tied to the customer, job, request, or record they came from so assistance stays crédible.
- Source CTA: See Sales & CRM

- FR label: Suggester
- FR title: Suggérez des brouillons et actions utiles sans perdre le contexte source
- FR body: Gardez résumés, brouillons, et recommandations reliés au client, au job, à la demande, ou au dossier dont ils partent pour que l'aide reste crédible.
- FR CTA: Voir Sales & CRM

- EN label: Suggest
- EN title: Suggest useful drafts and actions without losing source context
- EN body: Keep summaries, drafts, and recommendations tied to the customer, job, request, or record they came from so assistance stays crédible.
- EN CTA: See Sales & CRM

##### Tab 3
- Source label: Automate
- Source title: Remove useful steps from repeat work without breaking the workflow
- Source body: Automate routing, follow-up, preparation, and repetitive transitions where teams gain speed, consistency, and less manual overhead.
- Source CTA: See the platform

- FR label: Automatiser
- FR title: Retirez des étapes utiles du travail répétitif sans casser le workflow
- FR body: Automatisez routage, relance, préparation, et transitions répétitives là où l'équipe gagne en vitesse, en cohérence, et en charge manuelle réduite.
- FR CTA: Voir la plateforme

- EN label: Automate
- EN title: Remove useful steps from repeat work without breaking the workflow
- EN body: Automate routing, follow-up, preparation, and repetitive transitions where teams gain speed, consistency, and less manual overhead.
- EN CTA: See the platform

##### Tab 4
- Source label: Keep control
- Source title: Leave human review where judgment still matters
- Source body: Keep approval, exceptions, and sensitive decisions visible so automation supports the team instead of quietly taking over the wrong step.
- Source CTA: See Command Center

- FR label: Garder le contrôle
- FR title: Laissez la revue humaine là où le jugement compte encore
- FR body: Gardez validations, exceptions, et décisions sensibles visibles pour que l'automatisation soutienne l'équipe au lieu de prendre silencieusement la mauvaise place.
- FR CTA: Voir Command Center

- EN label: Keep control
- EN title: Leave human review where judgment still matters
- EN body: Keep approval, exceptions, and sensitive decisions visible so automation supports the team instead of quietly taking over the wrong step.
- EN CTA: See Command Center

### 3. CTA block

#### Real section shape
La section `ai-automation-cta` rend:
- kicker
- titre
- body
- badge
- CTA principal
- CTA secondaire
- lien éditorial secondaire

#### Section goal
Donner une prochaine étape claire à un prospect qui comprend la valeur du module et veut approfondir la logique de pilotage ou vérifier le pricing.

#### Source copy
- Kicker: Ready to save time without giving up control
- Title: Use AI and automation to move work forward with less friction
- Body: Replace disconnected assistants and vague automation promises with a system where suggestions, summaries, workflow steps, and human review stay connected to the work itself.
- Badge label: Module
- Badge value: AI & Automation
- Badge note: Suggestions, automation, and human review in one connected flow
- Primary CTA: See Command Center
- Secondary CTA: View pricing
- Aside link: See Operations

#### FR final
- Kicker: Prêt à gagner du temps sans abandonner le contrôle
- Title: Utilisez l'IA et l'automatisation pour faire avancer le travail avec moins de friction
- Body: Remplacez des assistants déconnectés et des promesses d'automatisation floues par un système où suggestions, résumés, étapes de workflow, et revue humaine restent reliés au travail lui-même.
- Badge label: Module
- Badge value: AI & Automation
- Badge note: Suggestions, automatisation, et revue humaine dans un même flux connecté
- Primary CTA: Voir Command Center
- Secondary CTA: Voir les tarifs
- Aside link: Voir Operations

#### EN final
- Kicker: Ready to save time without giving up control
- Title: Use AI and automation to move work forward with less friction
- Body: Replace disconnected assistants and vague automation promises with a system where suggestions, summaries, workflow steps, and human review stay connected to the work itself.
- Badge label: Module
- Badge value: AI & Automation
- Badge note: Suggestions, automation, and human review in one connected flow
- Primary CTA: See Command Center
- Secondary CTA: View pricing
- Aside link: See Operations

### 4. Proof block

#### Real section shape
La section `ai-automation-proof` est une `story_grid` avec trois cartes.

#### Section goal
Renforcer la confiance autour de l'utilité réelle de l'IA, de la qualité des suggestions, et de la place claire laissée à la décision humaine.

#### Source copy
- Kicker: AI that stays tied to real work
- Title: Built for teams that want assistance to feel useful, crédible, and controllable
- Body: Keep AI tied to the right context, the right review moments, and the right workflows so time savings feel real without turning decisions into guesswork.

##### Card 1
- Title: Useful patterns become easier to spot
- Body: Help teams see the repeated signals, blockers, and weak patterns worth acting on before they disappear into day-to-day noise.

##### Card 2
- Title: Suggestions stay grounded in context
- Body: Generate drafts, summaries, and proposed actions from the record already in front of the team so the output feels relevant instead of generic.

##### Card 3
- Title: Human review stays visible where it matters
- Body: Leave approvals, exceptions, and sensitive steps in clear view so the team knows exactly where automation helps and where judgment still leads.

#### FR final
- Kicker: Une IA qui reste branchée sur le vrai travail
- Title: Conçu pour les équipes qui veulent une aide utile, crédible, et contrôlable
- Body: Gardez l'IA reliée au bon contexte, aux bons moments de revue, et aux bons workflows pour que le gain de temps soit réel sans transformer les décisions en approximation.

##### Carte 1
- Title: Les patterns utiles deviennent plus faciles à repérer
- Body: Aidez les équipes à voir les signaux répétés, les blocages, et les schémas faibles qui méritent une action avant qu'ils se perdent dans le bruit du quotidien.

##### Carte 2
- Title: Les suggestions restent ancrées dans le contexte
- Body: Générez brouillons, résumés, et actions proposées à partir du dossier déjà ouvert devant l'équipe pour que le résultat paraisse pertinent plutôt que générique.

##### Carte 3
- Title: La revue humaine reste visible là où elle compte
- Body: Laissez validations, exceptions, et étapes sensibles bien visibles pour que l'équipe sache exactement où l'automatisation aide et où le jugement doit encore mener.

#### EN final
- Kicker: AI that stays tied to real work
- Title: Built for teams that want assistance to feel useful, crédible, and controllable
- Body: Keep AI tied to the right context, the right review moments, and the right workflows so time savings feel real without turning decisions into guesswork.

##### Card 1
- Title: Useful patterns become easier to spot
- Body: Help teams see the repeated signals, blockers, and weak patterns worth acting on before they disappear into day-to-day noise.

##### Card 2
- Title: Suggestions stay grounded in context
- Body: Generate drafts, summaries, and proposed actions from the record already in front of the team so the output feels relevant instead of generic.

##### Card 3
- Title: Human review stays visible where it matters
- Body: Leave approvals, exceptions, and sensitive steps in clear view so the team knows exactly where automation helps and where judgment still leads.

## Final quality checklist for this page
- le hero doit faire sentir une IA utile dans les workflows réels
- la section `feature_tabs` doit raconter un cycle clair: repérer -> suggérer -> automatiser -> garder le contrôle
- aucun texte ne doit parler du format narratif de la page
- la page doit rassurer sur le contrôle humain autant qu'elle promet un gain de temps
- la preuve doit rester orientée contexte, crédibilité, et assistance concrète
- les CTA doivent mener naturellement vers pilotage global, opérations, ou pricing

## Recommendation before live application
Avant application live de cette phase:
- vérifier si `AI & Automation` reste bien le nom éditorial souhaité dans les deux langues
- vérifier si `Voir Operations` est le meilleur lien secondaire ou si un autre module renforce mieux la démonstration
- vérifier si certains termes doivent être harmonisés entre `assistant`, `drafts`, `summaries`, `suggestions`, et `automation`

