# Malikia Pro Website Copy - Phase 13 Solution Reservations & Queues

Dernière mise à jour: 2026-04-09

## Purpose
Ce document couvre la `Phase 13 - solution-reservations-queues` du chantier copywriting.

Objectif:
- auditer la page `solution-reservations-queues` actuellement visible
- renforcer le positionnement de la solution autour d’un parcours client complet, de la réservation à l’arrivée sur site
- remplacer les formulations encore trop descriptives par un vrai récit de fluidité client, de coordination d’accueil, et de visibilité temps réel
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
- [MALIKIA_PRO_WEBSITE_COPY_PHASED_USER_STORY.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASED_USER_STORY.md)

## Real page structure
La page `solution-reservations-queues` rend actuellement les zones suivantes, dans cet ordre:
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
- l'image hero dédiée à `solution-reservations-queues`

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
- la page a déjà une bonne logique de parcours client, de la réservation à l’accueil
- la structure `overview -> workflow -> modules` est adaptée à un récit fluide avant / pendant la visite
- les modules cités sont cohérents: agenda, disponibilités, réservations, kiosques, file d’attente
- la promesse de visibilité temps réel et de coordination sur site est déjà présente

### Main weaknesses
- le titre `Une solution pour gerer le flux client avant et pendant la visite` reste encore un peu générique
- la page doit mieux faire sentir que l’expérience client devient plus simple sans faire perdre le contrôle à l’équipe
- le workflow pourrait mieux raconter la continuité entre réservation, confirmation, arrivée, et file d’attente
- la section modules reste encore descriptive et pas assez orientée bénéfice d’accueil ou de service
- le terme `Reservations & files` en français mérite d’être surveillé, car il est fonctionnel mais moins naturel que le reste du site

### Phase 13 decisions
- la page `solution-reservations-queues` doit raconter un vrai parcours client complet
- la page doit mieux relier confort client, maîtrise opérationnelle, et visibilité temps réel
- le texte doit faire sentir moins de friction entre réservation, accueil, et gestion de l’attente
- la section modules doit expliquer pourquoi ces espaces comptent dans la fluidité de visite
- le CTA principal vers pricing reste pertinent, mais la page doit mieux préparer ce passage par une promesse plus forte

## Message architecture for solution-reservations-queues

### Primary message
Transformez réservation, confirmation, accueil, et file d’attente en un parcours client plus fluide, plus lisible, et plus simple à piloter.

### Supporting messages
- rendre la réservation plus claire dès le premier choix
- confirmer et préparer la visite avec moins de friction
- mieux absorber les arrivées sur site
- garder la file et les changements visibles pour toute l’équipe

### CTA strategy
- overview section: `Voir les tarifs` en principal, `Nous contacter` en secondaire
- workflow section: pas de nouveau CTA, la narration doit porter la valeur
- modules section: garder une lecture produit concrète sans ajouter de nouvelles actions

## Editorial guardrails

### Sell the visit journey, not just booking
Le coeur de la page doit rester:
- réservation
- confirmation
- arrivée
- check-in
- file d’attente
- visibilité temps réel

Pas:
- une simple page de calendrier
- une liste de widgets d’accueil
- un discours trop abstrait sur “l’expérience client”

### Keep both sides visible: customer and team
Le texte doit faire sentir que:
- le client comprend plus facilement quoi réserver et quand venir
- l’équipe sait mieux absorber les arrivées et les changements
- l’accueil reste plus fluide même quand le flux se tend
- la visibilité en temps réel aide à mieux coordonner le service

### Make the solution feel end-to-end
La page doit montrer que la solution relie:
- les disponibilités
- la réservation
- les confirmations
- le check-in
- la file d’attente
- l’accueil sur site

## Section copy

### 1. Hero

#### Section goal
Faire comprendre très vite que `Reservations & files` aide les entreprises à rendre la réservation plus simple pour le client tout en gardant un meilleur contrôle sur l’arrivée et l’attente sur place.

#### Source copy
- Title: Reservations & queues
- Subtitle: Turn booking, confirmations, arrival handling, and queue visibility into one smoother customer journey from first reservation to on-site reception.

#### FR final
- Title: Reservations & files
- Subtitle: Transformez réservation, confirmations, gestion de l’arrivée, et visibilité sur la file en un parcours client plus fluide, de la première réservation jusqu’à l’accueil sur site.

#### EN final
- Title: Reservations & queues
- Subtitle: Turn booking, confirmations, arrival handling, and queue visibility into one smoother customer journey from first reservation to on-site reception.

### 2. Overview section

#### Real section shape
La section `solution-overview` contient:
- un kicker automatique
- un titre
- un body
- une liste de points
- deux CTA

#### Section goal
Positionner la solution comme une couche complète de gestion du flux client avant et pendant la visite.

#### Source copy
- Title: Manage customer flow more clearly before and during the visit
- Body: Reservations & queues brings availability, confirmations, check-in, and queue handling together so customers move through the visit more smoothly and teams keep better control on site.

#### FR final
- Title: Gérez le flux client plus clairement avant et pendant la visite
- Body: Reservations & files réunit disponibilités, confirmations, check-in, et gestion de la file pour que les clients avancent plus fluidement dans la visite et que l’équipe garde un meilleur contrôle sur place.

#### EN final
- Title: Manage customer flow more clearly before and during the visit
- Body: Reservations & queues brings availability, confirmations, check-in, and queue handling together so customers move through the visit more smoothly and teams keep better control on site.

#### Recommended items
- FR:
  - Réservations en ligne avec disponibilité en direct
  - Rappels et confirmations automatisés
  - Check-in sur site et kiosques en libre-service
  - Vue temps réel sur la file et les arrivées
- EN:
  - Online booking with live availability
  - Automated reminders and confirmations
  - On-site check-in and self-service kiosks
  - Real-time view of queues and arrivals

### 3. Workflow section

#### Real section shape
La section `solution-workflow` contient:
- un kicker automatique
- un titre
- un body
- une liste d’étapes

#### Section goal
Montrer un parcours client concret, de la réservation jusqu’à l’arrivée, avec moins de rupture entre le digital et le sur place.

#### Source copy
- Title: A clear visit workflow from booking to arrival
- Body: The customer books, the team confirms, the visit is prepared and handled on site, and every change stays visible so the front-desk flow remains easier to manage.

#### FR final
- Title: Un parcours de visite clair de la réservation jusqu’à l’arrivée
- Body: Le client réserve, l’équipe confirme, la visite est préparée puis gérée sur place, et chaque changement reste visible pour que le flux d’accueil soit plus simple à piloter.

#### EN final
- Title: A clear visit workflow from booking to arrival
- Body: The customer books, the team confirms, the visit is prepared and handled on site, and every change stays visible so the front-desk flow remains easier to manage.

#### Recommended workflow items
- FR:
  - 1. Publier les plages et les ressources
  - 2. Accepter les réservations en self-service
  - 3. Gérer confirmations, retards et annulations
  - 4. Suivre la file et le check-in en direct
- EN:
  - 1. Publish slots and resources
  - 2. Accept self-service bookings
  - 3. Handle confirmations, delays, and cancellations
  - 4. Track queue and check-in activity live

### 4. Modules section

#### Real section shape
La section `solution-modules` contient:
- un kicker automatique
- un titre
- un body
- une liste de modules/pages

#### Section goal
Montrer les espaces concrets qui rendent la réservation et l’accueil pilotables au quotidien.

#### Source copy
- Title: The booking and reception spaces that keep the visit flowing
- Body: The solution activates the pages teams rely on to publish availability, accept bookings, guide arrivals, and keep reception and queue handling visible in real time.

#### FR final
- Title: Les espaces de réservation et d’accueil qui gardent la visite fluide
- Body: La solution active les pages sur lesquelles l’équipe s’appuie pour publier les disponibilités, accepter les réservations, guider les arrivées, et garder l’accueil comme la file visibles en temps réel.

#### EN final
- Title: The booking and reception spaces that keep the visit flowing
- Body: The solution activates the pages teams rely on to publish availability, accept bookings, guide arrivals, and keep reception and queue handling visible in real time.

#### Recommended module items
- FR:
  - Agenda
  - Disponibilités
  - Réservations client
  - Kiosque client
  - Kiosque public
  - File d’attente
- EN:
  - Calendar
  - Availability
  - Customer bookings
  - Client kiosk
  - Public kiosk
  - Queue board

## Final quality checklist for this page
- le hero doit faire sentir un vrai parcours client avant et pendant la visite
- la section overview doit clarifier la promesse de fluidité et de maîtrise d’accueil
- la section workflow doit raconter une progression nette de la réservation à l’arrivée
- la section modules doit soutenir la coordination front-desk, pas seulement lister des espaces
- la page doit mieux faire sentir confort client, visibilité temps réel, et contrôle de l’équipe
- les CTA doivent mener naturellement vers pricing ou prise de contact

## Recommendation before live application
Avant application live de cette phase:
- vérifier si `Reservations & files` reste bien le meilleur nom éditorial en français
- vérifier si `self-service` doit être harmonisé avec d’autres formulations françaises du site
- vérifier si `file d’attente` et `check-in` doivent être normalisés davantage dans le vocabulaire produit

