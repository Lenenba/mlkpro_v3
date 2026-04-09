# Malikia Pro Website Copy - Phase 15 Solution Marketing & Loyalty

Dernière mise à jour: 2026-04-09

## Purpose
Ce document couvre la `Phase 15 - solution-marketing-loyalty` du chantier copywriting.

Objectif:
- auditer la page `solution-marketing-loyalty` actuellement visible
- renforcer le positionnement de la solution autour de la rétention, de la relance utile, et du revenu récurrent
- remplacer les formulations encore trop descriptives par un vrai récit de signal client, de ciblage, d’activation, et de retour en revenu
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
- [MALIKIA_PRO_WEBSITE_COPY_PHASED_USER_STORY.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASED_USER_STORY.md)

## Real page structure
La page `solution-marketing-loyalty` rend actuellement les zones suivantes, dans cet ordre:
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
- l'image hero dédiée à `solution-marketing-loyalty`

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
- la page raconte déjà une logique utile entre audience, campagnes, programmes VIP, et relance
- la structure `overview -> workflow -> modules` convient bien à une solution orientée rétention
- la promesse “à partir des bons signaux, pas de listes statiques” est déjà un bon différenciateur
- les modules cités sont cohérents: campagnes, listes, segments, loyalty, VIP tiers

### Main weaknesses
- le sous-titre actuel reste encore un peu fonctionnel et ne parle pas assez de revenu récurrent ni de pertinence
- le body overview décrit correctement la mécanique, mais ne fait pas assez sentir le moment d’activation ni la valeur business du bon timing
- le workflow reste encore un peu générique et pourrait mieux raconter la chaîne signal -> segment -> message -> retour client
- la section modules reste descriptive au lieu de montrer pourquoi ces espaces comptent dans une vraie logique de réactivation
- la page doit mieux éviter l’image d’un simple outil de campagne et se positionner comme une couche de croissance client connectée au produit

### Phase 15 decisions
- la page `solution-marketing-loyalty` doit raconter une vraie logique de rétention ancrée dans l’activité client
- la page doit relier signaux, ciblage, activation, fidélisation, et revenu récurrent dans un même récit
- le texte doit faire sentir qu’on agit au bon moment, avec le bon contexte, et pas via des envois génériques
- la section modules doit expliquer pourquoi ces espaces comptent dans la continuité relationnelle
- le CTA principal vers pricing reste pertinent, mais la page doit mieux préparer ce passage avec une promesse plus concrète

## Message architecture for solution-marketing-loyalty

### Primary message
Transformez signaux client, campagnes, et fidélisation en un système plus pertinent pour faire revenir les clients et mieux protéger le revenu futur.

### Supporting messages
- mieux repérer les bons signaux de relance
- mieux cibler à partir du vrai comportement client
- mieux activer campagnes et parcours de fidélisation
- mieux relier rétention et revenu récurrent

### CTA strategy
- overview section: `Voir les tarifs` en principal, `Nous contacter` en secondaire
- workflow section: pas de nouveau CTA, la narration doit porter la valeur
- modules section: garder une lecture produit concrète sans ajouter de nouvelles actions

## Editorial guardrails

### Sell useful retention, not generic broadcasting
Le coeur de la page doit rester:
- signaux client
- segmentation
- campagnes
- fidélisation
- retour en revenu

Pas:
- un simple outil de newsletters
- une page marketing trop abstraite
- un discours vague sur “l’engagement”

### Keep both sides visible: customer relevance and business impact
Le texte doit faire sentir que:
- le client reçoit des messages plus pertinents
- l’équipe agit au bon moment
- la relance s’appuie sur le vrai contexte client
- le revenu récurrent devient plus lisible

### Make the solution feel end-to-end
La page doit montrer que la solution relie:
- les signaux
- les segments
- les campagnes
- les programmes VIP
- les parcours de fidélisation
- les retours en revenu

## Section copy

### 1. Hero

#### Section goal
Faire comprendre très vite que `Marketing & fidélisation` aide les entreprises à réactiver, fidéliser, et faire revenir les clients à partir du bon contexte.

#### Source copy
- Title: Marketing & loyalty
- Subtitle: Turn customer signals, targeted campaigns, and loyalty journeys into a more relevant way to bring people back and grow repeat revenue.

#### FR final
- Title: Marketing & fidélisation
- Subtitle: Transformez signaux client, campagnes ciblées, et parcours de fidélisation en une façon plus pertinente de faire revenir les clients et de développer le revenu récurrent.

#### EN final
- Title: Marketing & loyalty
- Subtitle: Turn customer signals, targeted campaigns, and loyalty journeys into a more relevant way to bring people back and grow repeat revenue.

### 2. Overview section

#### Real section shape
La section `solution-overview` contient:
- un kicker automatique
- un titre
- un body
- une liste de points
- deux CTA

#### Section goal
Positionner la solution comme une couche complète de rétention et de relance utile, pas comme un simple outil d’envoi.

#### Source copy
- Title: Re-engage customers with more relevance and less guesswork
- Body: Marketing & loyalty connects audience signals, campaigns, VIP logic, and follow-up journeys so teams can act with better timing and keep repeat revenue more visible.

#### FR final
- Title: Réactivez les clients avec plus de pertinence et moins d’approximation
- Body: Marketing & fidélisation relie signaux d’audience, campagnes, logique VIP, et parcours de relance pour aider l’équipe à agir avec un meilleur timing et à garder le revenu récurrent plus visible.

#### EN final
- Title: Re-engage customers with more relevance and less guesswork
- Body: Marketing & loyalty connects audience signals, campaigns, VIP logic, and follow-up journeys so teams can act with better timing and keep repeat revenue more visible.

#### Recommended items
- FR:
  - Campagnes email et SMS plus pertinentes
  - Segments construits à partir du comportement réel
  - Programmes VIP et avantages fidélité
  - Relances utiles au bon moment
- EN:
  - More relevant email and SMS campaigns
  - Segments built from real behavior
  - VIP programs and loyalty benefits
  - Useful follow-up triggered at the right time

### 3. Workflow section

#### Real section shape
La section `solution-workflow` contient:
- un kicker automatique
- un titre
- un body
- une liste d’étapes

#### Section goal
Montrer une chaîne de rétention concrète, du signal client jusqu’au retour en revenu, avec moins de travail aveugle.

#### Source copy
- Title: Move from customer signal to repeat revenue with a clearer retention flow
- Body: The team identifies the right audience, prepares the message or journey, activates delivery on the right channel, and measures return using the same customer context as the rest of the platform.

#### FR final
- Title: Passez du signal client au revenu récurrent avec un flux de rétention plus clair
- Body: L’équipe identifie la bonne audience, prépare le message ou le parcours, active la diffusion sur le bon canal, puis mesure le retour avec le même contexte client que le reste de la plateforme.

#### EN final
- Title: Move from customer signal to repeat revenue with a clearer retention flow
- Body: The team identifies the right audience, prepares the message or journey, activates delivery on the right channel, and measures return using the same customer context as the rest of the platform.

#### Recommended items
- FR:
- 1. Repérer les signaux utiles dans l’activité client
- 2. Construire le segment ou le scénario adapté
- 3. Activer la campagne sur le bon canal
- 4. Mesurer réactivation, fidélité, et retour en revenu
- EN:
- 1. Spot the signals that deserve action
- 2. Build the right segment or journey
- 3. Launch the campaign on the right channel
- 4. Measure reactivation, loyalty, and repeat revenue

### 4. Modules section

#### Real section shape
La section `solution-modules` contient:
- un kicker automatique
- un titre
- un body
- une liste de modules/pages

#### Section goal
Montrer que la solution active les bons espaces produit pour tenir ensemble audience, campagne, fidélisation, et retour client.

#### Source copy
- Title: The workspaces that keep campaigns, segments, and retention inside the same customer story
- Body: The solution brings together the pages teams use to build audiences, launch campaigns, structure VIP logic, and keep reactivation tied to the real customer journey.

#### FR final
- Title: Les espaces qui gardent campagnes, segments, et fidélisation dans la même histoire client
- Body: La solution réunit les pages qui permettent de construire l’audience, lancer les campagnes, structurer la logique VIP, et garder la réactivation reliée au parcours réel du client.

#### EN final
- Title: The workspaces that keep campaigns, segments, and retention inside the same customer story
- Body: The solution brings together the pages teams use to build audiences, launch campaigns, structure VIP logic, and keep reactivation tied to the real customer journey.

#### Recommended items
- FR:
  - Campagnes
  - Mailing lists
  - Audience segments
  - Loyalty
  - VIP tiers
  - Prospect providers
- EN:
  - Campaigns
  - Mailing lists
  - Audience segments
  - Loyalty
  - VIP tiers
  - Prospect providers

## Final reminders for implementation
- ne pas changer la structure de la page
- ne pas ajouter de nouvelles sections
- ne pas promettre une sophistication marketing non visible dans le produit
- éviter le ton “marketing tool” générique
- garder le lien clair entre signaux, campagnes, fidélisation, et revenu récurrent

## Status
Phase 15 est maintenant documentée et prête pour application future au contenu live local.
