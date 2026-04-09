# Malikia Pro Website Copy - Phase 5 Operations

Dernière mise à jour: 2026-04-09

## Purpose
Ce document couvre la `Phase 5 - operations` du chantier copywriting.

Objectif:
- auditer la page `operations` actuellement visible
- renforcer la sensation de maîtrise operationnelle
- remplacer les phrases de chantier interne par un vrai discours de coordination, execution, et clôture terrain
- produire une base `source + FR + EN` directement applicable plus tard sans changer la structure

Ce document s'appuie sur:
- [MALIKIA_PRO_WEBSITE_COPY_PHASE_0_EDITORIAL_FOUNDATION.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASE_0_EDITORIAL_FOUNDATION.md)
- [MALIKIA_PRO_WEBSITE_COPY_PHASE_1_WELCOME.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASE_1_WELCOME.md)
- [MALIKIA_PRO_WEBSITE_COPY_PHASE_2_PRICING.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASE_2_PRICING.md)
- [MALIKIA_PRO_WEBSITE_COPY_PHASE_3_CONTACT_US.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASE_3_CONTACT_US.md)
- [MALIKIA_PRO_WEBSITE_COPY_PHASE_4_SALES_CRM.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASE_4_SALES_CRM.md)
- [MALIKIA_PRO_WEBSITE_COPY_PHASED_USER_STORY.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASED_USER_STORY.md)

## Real page structure
La page `operations` rend actuellement les zones suivantes, dans cet ordre:
1. Hero aligne sur le front public
2. `operations-flow` en `layout: feature_tabs`
3. `operations-cta` en `layout: showcase_cta`
4. `operations-proof` en `layout: story_grid`

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
- l'image hero dediee a `operations`

### Operations flow
La section `operations-flow` est une section `feature_tabs` avec:
- un kicker
- un titre
- un body introductif
- quatre onglets principaux

Les quatre onglets principaux actuellement visibles sont:
- `Planifier`
- `Dispatcher`
- `Intervenir`
- `clôturer`

Chaque onglet porte:
- un titre
- un body
- une image
- un CTA vers une page solution ou module connexe

### CTA block
La section `operations-cta` est une section `showcase_cta` avec:
- kicker
- titre
- body
- badge
- CTA principal
- CTA secondaire
- lien editorial secondaire

### Proof block
La section `operations-proof` est une section `story_grid` avec:
- kicker
- titre
- body
- trois cartes de preuve visuelle

## Audit

### What already works
- la page a déjà une bonne logique en quatre temps: planifier, dispatcher, intervenir, clôturer
- les images et onglets rendent bien la difference entre bureau, coordination, et terrain
- la structure convient très bien a un discours operationnel concret
- les liens vers `solution-field-services`, `commerce`, et `command-center` donnent déjà une bonne continuation

### Main weaknesses
- plusieurs textes actuels parlent encore de la page elle-même ou de sa lisibilite, pas du produit
- le titre `Operations suit maintenant le même format narratif que les autres pages modules` est une note interne, pas un texte publiable
- le bloc `operations-proof` parle trop de la façon dont la page est ecrite et pas assez de la valeur operationnelle
- le flow actuel est utile mais pourrait mieux faire sentir la reduction des oublis, des retards, et des pertes de contexte
- la page doit mieux vendre la coordination entre bureau, dispatch, terrain, et preuve de travail

### Phase 5 decisions
- la page `operations` doit parler d'execution maîtrisée, pas seulement d'organisation
- le message doit rassurer sur la qualité de coordination entre les equipes
- la page doit faire sentir que Malikia Pro devient la couche commune entre planification et livraison
- les CTA doivent pousser vers la solution terrain, les tarifs, ou la vue globale command center
- la preuve doit parler d'alignement, de preparation, et de clôture propre

## Message architecture for operations

### Primary message
Planifiez, affectez, executez, et cloturez le travail depuis un même espace operationnel pour que le bureau et le terrain avancent avec la même lecture du job.

### Supporting messages
- meilleure lecture du plan de charge
- moins de flottement au moment du dispatch
- equipes mieux preparees sur le terrain
- clôture plus propre avec preuve, notes, et prochaine action visible

### CTA strategy
- flow section: CTA vers `solution-field-services`, `command-center`, et `commerce`
- CTA block: `Voir la solution Services terrain` en principal, `Voir les tarifs` en secondaire
- proof block: pas besoin d'ajouter de nouveau CTA si la structure ne le porte pas

## Editorial guardrails

### Keep the page operational, not abstract
Le coeur de la page doit rester:
- planification
- affectation
- execution
- preuve de travail
- clôture

Pas:
- un discours trop general sur la productivite
- une note de refonte ou de design editorial

### Do not describe the page, describe the product
Ne pas publier de formulations comme:
- `la page donne une impression`
- `le module devient plus lisible`
- `même format narratif`
- `la page raconte mieux`

Le texte doit toujours parler de ce que l'equipe voit, fait, suit, ou valide dans l'outil.

### Keep the field-service angle broad enough
La page peut être fortement orientee terrain, mais elle doit rester utile a toutes les operations de service:
- equipes mobiles
- equipes de coordination
- interventions planifiees
- taches internes et suivi quotidien

## Section copy

### 1. Hero

#### Section goal
Faire comprendre en quelques secondes que `Operations` aide a garder bureau, planning, et terrain alignes du debut a la fin du job.

#### Source copy
- Title: Run planning, dispatch, field execution, and proof of work from one operational workspace
- Subtitle: Malikia Pro gives teams one live operating layer for scheduling, assignments, job context, field follow-through, and completion so work moves with less confusion and better accountability.

#### FR final
- Title: Pilotez planning, dispatch, execution terrain, et preuve de travail depuis un même espace operationnel
- Subtitle: Malikia Pro donne aux equipes une même couche de pilotage pour la planification, les affectations, le contexte du job, le suivi terrain, et la clôture afin que le travail avance avec moins de confusion et plus de responsabilite.

#### EN final
- Title: Run planning, dispatch, field execution, and proof of work from one operational workspace
- Subtitle: Malikia Pro gives teams one live operating layer for scheduling, assignments, job context, field follow-through, and completion so work moves with less confusion and better accountability.

### 2. Operations flow

#### Real section shape
La section `operations-flow` contient:
- un kicker
- un titre
- un body
- quatre onglets principaux

#### Section goal
Montrer clairement le cycle de travail entre bureau, coordination, terrain, et clôture sans tomber dans un simple inventaire de fonctionnalites.

#### Source copy
- Kicker: One operating workflow from plan to proof
- Title: Plan, assign, execute, and close work from one shared operational view
- Body: Operations keeps workload planning, dispatch, job details, field execution, and proof of completion aligned so the office and the field are not working from different versions of reality.

#### FR final
- Kicker: Un workflow operationnel du plan a la preuve
- Title: Planifiez, affectez, executez, et cloturez le travail depuis une même vue operationnelle
- Body: Operations garde le plan de charge, le dispatch, les details du job, l'execution terrain, et la preuve de completion alignes pour que le bureau et le terrain ne travaillent pas avec deux versions differentes de la réalité.

#### EN final
- Kicker: One operating workflow from plan to proof
- Title: Plan, assign, execute, and close work from one shared operational view
- Body: Operations keeps workload planning, dispatch, job details, field execution, and proof of completion aligned so the office and the field are not working from different versions of reality.

#### Recommended tab narrative

##### Tab 1
- Source label: Plan
- Source title: Read workload and priorities before the day starts
- Source body: Give planners a clearer view of capacity, urgency, and scheduling pressure before resources are committed.
- Source CTA: See the field services solution

- FR label: Planifier
- FR title: Lisez la charge et les priorites avant le debut de la journee
- FR body: Donnez aux planificateurs une meilleure lecture de la capacite, de l'urgence, et de la pression de planning avant d'engager les ressources.
- FR CTA: Voir la solution Services terrain

- EN label: Plan
- EN title: Read workload and priorities before the day starts
- EN body: Give planners a clearer view of capacity, urgency, and scheduling pressure before resources are committed.
- EN CTA: See the field services solution

##### Tab 2
- Source label: Dispatch
- Source title: Give the right team the right context before they leave
- Source body: Keep assignments, preparation, and job details visible in the same coordination moment so handoff quality improves.
- Source CTA: See Command Center

- FR label: Dispatcher
- FR title: Donnez le bon contexte a la bonne equipe avant le depart
- FR body: Gardez les affectations, la preparation, et les details du job visibles dans un même moment de coordination pour ameliorer la qualité du handoff.
- FR CTA: Voir Command Center

- EN label: Dispatch
- EN title: Give the right team the right context before they leave
- EN body: Keep assignments, preparation, and job details visible in the same coordination moment so handoff quality improves.
- EN CTA: See Command Center

##### Tab 3
- Source label: Execute
- Source title: Help field teams work with a clearer read of the job
- Source body: Make status, customer context, checklists, and required proof easier to follow once the team is on site.
- Source CTA: See field services

- FR label: Intervenir
- FR title: Aidez le terrain a travailler avec une lecture plus nette du job
- FR body: Rendez le statut, le contexte client, les checklists, et les preuves attendues plus faciles a suivre une fois l'equipe sur place.
- FR CTA: Voir les services terrain

- EN label: Execute
- EN title: Help field teams work with a clearer read of the job
- EN body: Make status, customer context, checklists, and required proof easier to follow once the team is on site.
- EN CTA: See field services

##### Tab 4
- Source label: Close
- Source title: Close the loop with cleaner completion and better follow-through
- Source body: Keep validation, proof of work, revenue visibility, and next steps connected so work ends in a controlled way instead of a rushed one.
- Source CTA: See Commerce

- FR label: clôturer
- FR title: Fermez la boucle avec une clôture plus propre et un meilleur suivi
- FR body: Gardez la validation, la preuve de travail, la lecture du revenu, et les prochaines actions connectees pour que le travail se termine de façon maîtrisée plutot que precipitee.
- FR CTA: Voir Commerce

- EN label: Close
- EN title: Close the loop with cleaner completion and better follow-through
- EN body: Keep validation, proof of work, revenue visibility, and next steps connected so work ends in a controlled way instead of a rushed one.
- EN CTA: See Commerce

### 3. CTA block

#### Real section shape
La section `operations-cta` rend:
- kicker
- titre
- body
- badge
- CTA principal
- CTA secondaire
- lien secondaire editorial

#### Section goal
Transformer l'interet pour la coordination terrain en prochaine etape claire vers la solution plus detaillee ou le pricing.

#### Source copy
- Kicker: Ready to structure execution
- Title: Give every team the same source of operational truth
- Body: Replace fragmented planning, side-channel dispatch, and disconnected field follow-up with one workspace that helps planners, dispatchers, and field teams stay aligned from assignment to completion.
- Badge label: Module
- Badge value: Operations
- Badge note: Planning, dispatch, and field proof in one connected rhythm
- Primary CTA: See the field services solution
- Secondary CTA: View pricing
- Aside link: See Command Center

#### FR final
- Kicker: Pret a mieux structurer l'execution
- Title: Donnez a chaque equipe la même source de verite operationnelle
- Body: Remplacez un planning fragmente, un dispatch eclate, et un suivi terrain deconnecte par un même espace qui aide planificateurs, coordinateurs, et equipes terrain a rester alignes de l'affectation a la completion.
- Badge label: Module
- Badge value: Operations
- Badge note: Planning, dispatch, et preuve terrain dans un même rythme connecte
- Primary CTA: Voir la solution Services terrain
- Secondary CTA: Voir les tarifs
- Aside link: Voir Command Center

#### EN final
- Kicker: Ready to structure execution
- Title: Give every team the same source of operational truth
- Body: Replace fragmented planning, side-channel dispatch, and disconnected field follow-up with one workspace that helps planners, dispatchers, and field teams stay aligned from assignment to completion.
- Badge label: Module
- Badge value: Operations
- Badge note: Planning, dispatch, and field proof in one connected rhythm
- Primary CTA: See the field services solution
- Secondary CTA: View pricing
- Aside link: See Command Center

### 4. Proof block

#### Real section shape
La section `operations-proof` est une `story_grid` avec trois cartes.

#### Section goal
Renforcer la confiance avec des preuves courtes autour du plan de charge, du dispatch, et de la clôture terrain.

#### Source copy
- Kicker: Clear operational moments
- Title: Built for cleaner execution in the real world
- Body: Keep planning, handoff, and completion visible as distinct moments so teams can prepare better, execute with more context, and close work with fewer gaps.

##### Card 1
- Title: A clearer read of workload before commitment
- Body: Give the office a stronger view of workload and pressure points before resources are locked into the day.

##### Card 2
- Title: A real dispatch moment before the team leaves
- Body: Surface the details that matter before departure so the team leaves with better context and fewer surprises.

##### Card 3
- Title: Proof stays connected to the same workflow
- Body: Keep notes, checklists, photos, and completion proof tied to the same job so closure is cleaner and easier to review.

#### FR final
- Kicker: Moments operationnels visibles
- Title: Conçu pour une execution plus propre dans le réel
- Body: Gardez la planification, le handoff, et la completion visibles comme des moments distincts pour que les equipes se preparent mieux, interviennent avec plus de contexte, et clôturent le travail avec moins de manques.

##### Carte 1
- Title: Une lecture plus claire du plan de charge avant engagement
- Body: Donnez au bureau une meilleure vue de la charge et des points de tension avant de verrouiller les ressources sur la journee.

##### Carte 2
- Title: Un vrai moment de dispatch avant le depart
- Body: Faites remonter les details utiles avant le depart pour que l'equipe parte avec un meilleur contexte et moins de surprises.

##### Carte 3
- Title: La preuve reste reliee au même workflow
- Body: Gardez notes, checklists, photos, et preuves de completion relies au même job pour une clôture plus propre et plus facile a verifier.

#### EN final
- Kicker: Clear operational moments
- Title: Built for cleaner execution in the real world
- Body: Keep planning, handoff, and completion visible as distinct moments so teams can prepare better, execute with more context, and close work with fewer gaps.

##### Card 1
- Title: A clearer read of workload before commitment
- Body: Give the office a stronger view of workload and pressure points before resources are locked into the day.

##### Card 2
- Title: A real dispatch moment before the team leaves
- Body: Surface the details that matter before departure so the team leaves with better context and fewer surprises.

##### Card 3
- Title: Proof stays connected to the same workflow
- Body: Keep notes, checklists, photos, and completion proof tied to the same job so closure is cleaner and easier to review.

## Final quality checklist for this page
- le hero doit faire sentir un vrai contrôle operationnel
- la section `feature_tabs` doit raconter un cycle clair entre bureau et terrain
- aucun texte ne doit sonner comme une note de chantier ou de redesign
- la page doit rassurer sur la coordination, pas seulement sur la planification
- la preuve doit rester concrete, terrain, et actionnable
- les CTA doivent mener naturellement vers solution, pricing, et pilotage global

## Recommendation before live application
Avant application live de cette phase:
- verifier si `Operations` reste le nom editorial voulu dans les deux langues
- verifier les liens CTA de chaque onglet pour s'assurer qu'ils pointent vers les pages publiques les plus pertinentes
- verifier si `Command Center` doit rester le lien secondaire le plus logique pour cette page

