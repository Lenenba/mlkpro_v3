# Malikia Pro Website Copy - Phase 8 Marketing & Loyalty

Dernière mise à jour: 2026-04-09

## Purpose
Ce document couvre la `Phase 8 - marketing-loyalty` du chantier copywriting.

Objectif:
- auditer la page `marketing-loyalty` actuellement visible
- renforcer le positionnement du module autour de la réactivation, de la fidélisation, et du revenu récurrent
- remplacer les formulations trop méta par un vrai copy produit orienté signaux client, campagnes utiles, et relation durable
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
- [MALIKIA_PRO_WEBSITE_COPY_PHASED_USER_STORY.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASED_USER_STORY.md)

## Real page structure
La page `marketing-loyalty` rend actuellement les zones suivantes, dans cet ordre:
1. Hero aligné sur le front public
2. `marketing-loyalty-flow` en `layout: feature_tabs`
3. `marketing-loyalty-cta` en `layout: showcase_cta`
4. `marketing-loyalty-proof` en `layout: story_grid`

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
- l'image hero dédiée à `marketing-loyalty`

### Marketing flow
La section `marketing-loyalty-flow` est une section `feature_tabs` avec:
- un kicker
- un titre
- un body introductif
- quatre onglets principaux

Les quatre onglets actuellement visibles sont:
- `Écouter`
- `Segmenter`
- `Activer`
- `Fidéliser`

### CTA block
La section `marketing-loyalty-cta` est une section `showcase_cta` avec:
- kicker
- titre
- body
- badge
- CTA principal
- CTA secondaire
- lien éditorial secondaire

### Proof block
La section `marketing-loyalty-proof` est une section `story_grid` avec:
- kicker
- titre
- body
- trois cartes de preuve visuelle

## Audit

### What already works
- la page a déjà une bonne intuition de cycle: signal, segmentation, activation, retour client
- les liens vers `solution-marketing-loyalty`, `pricing`, `command-center`, `sales-crm`, et `commerce` créent une bonne continuité
- le module est bien positionné comme connecté au reste de la plateforme, pas comme un outil marketing isolé
- les images soutiennent bien l'idée de campagnes, de coordination, et de suivi client

### Main weaknesses
- plusieurs textes parlent encore de la page elle-même au lieu de parler du produit
- le CTA block actuel dit encore que le module "suit maintenant le même format narratif", ce qui ne doit jamais être visible côté prospect
- la preuve actuelle parle de lisibilité et de structure de page, pas assez de rétention, de timing, ni de revenu futur
- la promesse de fidélisation reste un peu abstraite et doit mieux faire sentir les déclencheurs concrets: activité, inactivité, valeur, retour, prochaine visite
- la page doit mieux montrer que Malikia Pro aide à relancer au bon moment, avec le bon contexte, et à mesurer un vrai impact business

### Phase 8 decisions
- la page `marketing-loyalty` doit parler de rétention utile, pas de communication générique
- chaque section doit faire sentir le lien entre activité client, ciblage, campagne, et retour en revenu
- le module doit apparaître comme une couche de relation client branchée sur les opérations, les ventes, et les visites
- le CTA block doit donner une prochaine étape claire vers la solution détaillée ou le pricing
- la preuve doit montrer des bénéfices concrets: signaux mieux utilisés, campagnes plus pertinentes, fidélisation plus mesurable

## Message architecture for marketing-loyalty

### Primary message
Lancez des campagnes, construisez des segments, et ramenez les clients au bon moment avec une fidélisation connectée à l'activité réelle.

### Supporting messages
- repérer les signaux qui méritent une action
- segmenter à partir du vrai comportement client
- activer des campagnes plus pertinentes
- transformer la rétention en visites, achats, et revenu récurrent

### CTA strategy
- flow section: CTA vers `solution-marketing-loyalty`, `sales-crm`, `command-center`, et `commerce`
- CTA block: `Voir la solution Marketing & fidélisation` en principal, `Voir les tarifs` en secondaire
- proof block: conserver le CTA vers la solution détaillée

## Editorial guardrails

### Keep the page grounded in customer timing
Le coeur de la page doit rester:
- signaux utiles
- segmentation réelle
- campagnes pertinentes
- fidélisation
- retour client
- revenu récurrent

Pas:
- une description de refonte
- une note sur le format de page
- un discours vague sur "faire du marketing"

### Do not sell broadcast, sell relevance
Le texte doit faire sentir que:
- la relance part d'un vrai contexte
- la campagne suit un moment client identifiable
- la fidélisation ne dépend pas d'une intuition floue
- l'équipe peut voir un lien entre l'action marketing et le retour business

### Keep retention connected to the platform
Le module doit apparaître comme relié:
- aux historiques client
- aux visites et achats
- aux signaux d'engagement
- aux prochaines actions commerciales ou relationnelles

## Section copy

### 1. Hero

#### Section goal
Faire comprendre très vite que `Marketing & Loyalty` aide à relancer, fidéliser, et ramener les clients avec des campagnes ancrées dans leur activité réelle.

#### Source copy
- Title: Marketing & Loyalty
- Subtitle: Launch campaigns, build smarter segments, and bring customers back with retention journeys tied to real activity instead of generic batch marketing.

#### FR final
- Title: Marketing & Loyalty
- Subtitle: Lancez des campagnes, créez des segments plus intelligents, et ramenez les clients avec des parcours de fidélisation reliés à leur activité réelle plutôt qu'à des envois génériques.

#### EN final
- Title: Marketing & Loyalty
- Subtitle: Launch campaigns, build smarter segments, and bring customers back with retention journeys tied to real activity instead of generic batch marketing.

### 2. Marketing flow

#### Real section shape
La section `marketing-loyalty-flow` contient:
- un kicker
- un titre
- un body
- quatre onglets principaux

#### Section goal
Montrer que le module relie écoute client, ciblage, activation, et fidélisation dans une vraie logique de rétention mesurable.

#### Source copy
- Kicker: One retention workflow from customer signal to returning revenue
- Title: Turn customer activity into retention actions that actually bring people back
- Body: Marketing & Loyalty connects signals, segmentation, campaigns, and loyalty journeys so teams can respond at the right moment and protect future revenue.

#### FR final
- Kicker: Un workflow de rétention du signal client au retour en revenu
- Title: Transformez l'activité client en actions de rétention qui font vraiment revenir
- Body: Marketing & Loyalty relie signaux, segmentation, campagnes, et parcours de fidélisation pour aider les équipes à agir au bon moment et à protéger le revenu futur.

#### EN final
- Kicker: One retention workflow from customer signal to returning revenue
- Title: Turn customer activity into retention actions that actually bring people back
- Body: Marketing & Loyalty connects signals, segmentation, campaigns, and loyalty journeys so teams can respond at the right moment and protect future revenue.

#### Recommended tab narrative

##### Tab 1
- Source label: Listen
- Source title: Surface the customer signals that deserve action
- Source body: Use reviews, visit history, inactivity, and behavioral changes to decide when a customer should hear from you again.
- Source CTA: See Sales & CRM

- FR label: Écouter
- FR title: Faites remonter les signaux client qui méritent une action
- FR body: Appuyez-vous sur les avis, l'historique de visites, l'inactivité, et les changements de comportement pour savoir quand il faut relancer.
- FR CTA: Voir Sales & CRM

- EN label: Listen
- EN title: Surface the customer signals that deserve action
- EN body: Use reviews, visit history, inactivity, and behavioral changes to decide when a customer should hear from you again.
- EN CTA: See Sales & CRM

##### Tab 2
- Source label: Segment
- Source title: Build segments from real behavior instead of guesswork
- Source body: Group customers by value, rhythm, visit history, or recent activity so targeting feels specific before a campaign is ever launched.
- Source CTA: See Command Center

- FR label: Segmenter
- FR title: Construisez les segments à partir du vrai comportement, pas d'hypothèses
- FR body: Regroupez les clients selon leur valeur, leur rythme, leur historique, ou leur activité récente pour que le ciblage soit précis avant même le lancement d'une campagne.
- FR CTA: Voir Command Center

- EN label: Segment
- EN title: Build segments from real behavior instead of guesswork
- EN body: Group customers by value, rhythm, visit history, or recent activity so targeting feels specific before a campaign is ever launched.
- EN CTA: See Command Center

##### Tab 3
- Source label: Activate
- Source title: Launch follow-up campaigns that feel timely and relevant
- Source body: Connect the right audience, message, and offer so campaigns feel like useful follow-up instead of generic noise.
- Source CTA: See the solution

- FR label: Activer
- FR title: Lancez des campagnes qui arrivent au bon moment et avec le bon message
- FR body: Reliez la bonne audience, le bon message, et la bonne offre pour que la campagne ressemble à une relance utile plutôt qu'à un bruit générique.
- FR CTA: Voir la solution

- EN label: Activate
- EN title: Launch follow-up campaigns that feel timely and relevant
- EN body: Connect the right audience, message, and offer so campaigns feel like useful follow-up instead of generic noise.
- EN CTA: See the solution

##### Tab 4
- Source label: Retain
- Source title: Turn loyalty into the next visit, order, or renewal
- Source body: Keep reactivation, rewards, and the next transaction in the same story so retention can be felt in repeat business, not just in open rates.
- Source CTA: See Commerce

- FR label: Fidéliser
- FR title: Transformez la fidélisation en prochaine visite, prochain achat, ou renouvellement
- FR body: Gardez réactivation, avantages, et prochaine transaction dans la même histoire pour que la fidélisation se voie dans le retour business, pas seulement dans les taux d'ouverture.
- FR CTA: Voir Commerce

- EN label: Retain
- EN title: Turn loyalty into the next visit, order, or renewal
- EN body: Keep reactivation, rewards, and the next transaction in the same story so retention can be felt in repeat business, not just in open rates.
- EN CTA: See Commerce

### 3. CTA block

#### Real section shape
La section `marketing-loyalty-cta` rend:
- kicker
- titre
- body
- badge
- CTA principal
- CTA secondaire
- lien éditorial secondaire

#### Section goal
Donner une prochaine étape claire à un prospect qui comprend la valeur du module et veut approfondir la solution ou vérifier le pricing.

#### Source copy
- Kicker: Ready to bring customers back with more consistency
- Title: Turn customer activity into campaigns and loyalty that drive repeat revenue
- Body: Replace disconnected mailing tools and guesswork with a system where signals, audience, campaigns, and loyalty outcomes stay tied to the customer record.
- Badge label: Module
- Badge value: Marketing & Loyalty
- Badge note: Signals, campaigns, loyalty, and returning revenue in one connected flow
- Primary CTA: See the Marketing & Loyalty solution
- Secondary CTA: View pricing
- Aside link: See Command Center

#### FR final
- Kicker: Prêt à faire revenir les clients plus régulièrement
- Title: Transformez l'activité client en campagnes et fidélisation qui génèrent du revenu récurrent
- Body: Remplacez des outils de mailing déconnectés et un ciblage au hasard par un système où signaux, audience, campagnes, et résultats de fidélisation restent reliés à la fiche client.
- Badge label: Module
- Badge value: Marketing & Loyalty
- Badge note: Signaux, campagnes, fidélisation, et retour en revenu dans un même flux connecté
- Primary CTA: Voir la solution Marketing & fidélisation
- Secondary CTA: Voir les tarifs
- Aside link: Voir Command Center

#### EN final
- Kicker: Ready to bring customers back with more consistency
- Title: Turn customer activity into campaigns and loyalty that drive repeat revenue
- Body: Replace disconnected mailing tools and guesswork with a system where signals, audience, campaigns, and loyalty outcomes stay tied to the customer record.
- Badge label: Module
- Badge value: Marketing & Loyalty
- Badge note: Signals, campaigns, loyalty, and returning revenue in one connected flow
- Primary CTA: See the Marketing & Loyalty solution
- Secondary CTA: View pricing
- Aside link: See Command Center

### 4. Proof block

#### Real section shape
La section `marketing-loyalty-proof` est une `story_grid` avec trois cartes.

#### Section goal
Renforcer la confiance autour de la pertinence des campagnes, de la qualité du ciblage, et de la capacité à transformer la relation client en revenu futur.

#### Source copy
- Kicker: Retention that stays grounded in real activity
- Title: Built for teams that want customer marketing to feel timely, useful, and measurable
- Body: Keep campaigns tied to the real customer journey so follow-up feels more relevant, loyalty feels more earned, and repeat revenue becomes easier to understand.

##### Card 1
- Title: Signals become easier to act on
- Body: Give teams a clearer way to spot the reviews, lapses, behavior shifts, and return patterns that deserve the next message.

##### Card 2
- Title: Campaigns start from real context
- Body: Launch campaigns from customer history, value, and activity so the message feels connected to what actually happened.

##### Card 3
- Title: Loyalty turns into visible repeat revenue
- Body: Keep the link between retention actions and the next visit, order, or upgrade clear enough to measure what brings people back.

#### FR final
- Kicker: Une rétention ancrée dans l'activité réelle
- Title: Conçu pour les équipes qui veulent un marketing client utile, opportun, et mesurable
- Body: Gardez les campagnes reliées au vrai parcours client pour que la relance soit plus pertinente, que la fidélisation paraisse plus naturelle, et que le revenu récurrent soit plus facile à comprendre.

##### Carte 1
- Title: Les signaux deviennent plus faciles à exploiter
- Body: Donnez à l'équipe une façon plus claire de voir les avis, les périodes d'absence, les changements de comportement, et les retours qui méritent la prochaine action.

##### Carte 2
- Title: Les campagnes partent d'un vrai contexte
- Body: Lancez les campagnes à partir de l'historique, de la valeur, et de l'activité du client pour que le message paraisse relié à ce qui s'est réellement passé.

##### Carte 3
- Title: La fidélisation se traduit en revenu récurrent visible
- Body: Gardez le lien entre les actions de rétention et la prochaine visite, le prochain achat, ou la prochaine montée en gamme assez lisible pour savoir ce qui fait revenir.

#### EN final
- Kicker: Retention that stays grounded in real activity
- Title: Built for teams that want customer marketing to feel timely, useful, and measurable
- Body: Keep campaigns tied to the real customer journey so follow-up feels more relevant, loyalty feels more earned, and repeat revenue becomes easier to understand.

##### Card 1
- Title: Signals become easier to act on
- Body: Give teams a clearer way to spot the reviews, lapses, behavior shifts, and return patterns that deserve the next message.

##### Card 2
- Title: Campaigns start from real context
- Body: Launch campaigns from customer history, value, and activity so the message feels connected to what actually happened.

##### Card 3
- Title: Loyalty turns into visible repeat revenue
- Body: Keep the link between retention actions and the next visit, order, or upgrade clear enough to measure what brings people back.

## Final quality checklist for this page
- le hero doit faire sentir une rétention connectée à l'activité réelle
- la section `feature_tabs` doit raconter un cycle clair: écouter -> segmenter -> activer -> fidéliser
- aucun texte ne doit parler du format narratif ou de la structure de la page
- la page doit mieux faire sentir le bon timing client, pas seulement l'envoi de campagnes
- la preuve doit rester orientée pertinence, fidélisation, et revenu récurrent
- les CTA doivent mener naturellement vers solution, pricing, ou pilotage global

## Recommendation before live application
Avant application live de cette phase:
- vérifier si `Marketing & Loyalty` reste bien le nom éditorial souhaité dans les deux langues
- vérifier si les CTA vers `Sales & CRM`, `Command Center`, et `Commerce` sont les meilleurs renvois pour chaque onglet
- vérifier si certains termes produit doivent être harmonisés entre `campaigns`, `segments`, `loyalty`, et `VIP`

