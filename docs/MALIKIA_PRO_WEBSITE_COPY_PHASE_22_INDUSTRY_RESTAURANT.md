# Malikia Pro Website Copy - Phase 22 Industry Restaurant

Dernière mise à jour: 2026-04-09

## Purpose
Ce document couvre la `Phase 22 - industry-restaurant` du chantier copywriting.

Objectif:
- auditer la page `industry-restaurant` actuellement visible
- renforcer la crédibilité métier du message pour les restaurants, équipes d'accueil, et équipes de salle
- remplacer les formulations trop génériques par un vrai récit de réservation, dépôt, file d'attente, arrivée, et relation client après visite
- produire une base `source + FR + EN` directement applicable plus tard sans changer la structure

Ce document s'appuie sur:
- [MALIKIA_PRO_WEBSITE_COPY_PHASE_0_EDITORIAL_FOUNDATION.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASE_0_EDITORIAL_FOUNDATION.md)
- [MALIKIA_PRO_WEBSITE_COPY_PHASE_1_WELCOME.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASE_1_WELCOME.md)
- [MALIKIA_PRO_WEBSITE_COPY_PHASE_6_RESERVATIONS.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASE_6_RESERVATIONS.md)
- [MALIKIA_PRO_WEBSITE_COPY_PHASE_7_COMMERCE.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASE_7_COMMERCE.md)
- [MALIKIA_PRO_WEBSITE_COPY_PHASE_13_SOLUTION_RESERVATIONS_QUEUES.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASE_13_SOLUTION_RESERVATIONS_QUEUES.md)
- [MALIKIA_PRO_WEBSITE_COPY_PHASE_14_SOLUTION_COMMERCE_CATALOG.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASE_14_SOLUTION_COMMERCE_CATALOG.md)
- [MALIKIA_PRO_WEBSITE_COPY_PHASE_17_INDUSTRY_PLUMBING.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASE_17_INDUSTRY_PLUMBING.md)
- [MALIKIA_PRO_WEBSITE_COPY_PHASE_21_INDUSTRY_SALON_BEAUTY.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASE_21_INDUSTRY_SALON_BEAUTY.md)
- [MALIKIA_PRO_WEBSITE_COPY_PHASED_USER_STORY.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASED_USER_STORY.md)

## Real page structure
La page `industry-restaurant` rend actuellement les zones suivantes, dans cet ordre:
1. Hero aligné sur le front public
2. `industry-showcase` en `layout: feature_tabs`
3. `industry-editorial-cta` en `layout: showcase_cta`
4. `industry-testimonial` en `layout: testimonial`

Contrainte:
- aucun changement de structure
- aucun changement de layout
- aucun changement d'image
- aucun ajout de nouvelle section

## Real rendering behavior

### Hero
Le hero est rendu par `PublicFrontHero` avec:
- un eyebrow automatique `Industries` / `Industries`
- `page_title`
- `page_subtitle`
- l'image hero dédiée à `industry-restaurant`

### Showcase section
La section `industry-showcase` est une section `feature_tabs` avec:
- un kicker
- un titre
- un body introductif
- quatre onglets principaux
- pour chaque onglet: un titre, un body, un metric, un story, un CTA, puis plusieurs sous-cartes

### Editorial CTA section
La section `industry-editorial-cta` est une section `showcase_cta` avec:
- un titre
- un body
- un CTA principal
- un lien secondaire
- un visuel principal et un visuel latéral

### Testimonial section
La section `industry-testimonial` est une section `testimonial` avec:
- un quote principal
- une image
- un auteur
- un rôle

## Audit

### What already works
- la page couvre déjà les bons objets métier: réservations, file d'attente, check-in, dépôts, et communication client
- la structure par onglets convient bien à un parcours restaurant qui va de la réservation au retour client
- le hero parle déjà de fluidité de parcours et d'expérience salle
- le workflow actuel raconte correctement les étapes réservation, confirmation, arrivée, et relance

### Main weaknesses
- le hero reste encore un peu générique et ne fait pas assez sentir les réalités d'accueil, de salle, et de pression au service
- le titre showcase trop générique “home service pros” casse le positionnement restaurant
- le copy actuel parle bien de réservations et d'attente, mais pas assez de réassurance client, de dépôts, ni de cohérence entre accueil et relation après visite
- le CTA éditorial reste trop large et pas assez ancré dans le fonctionnement d'un restaurant
- le testimonial peut être rendu plus crédible pour un univers accueil / salle / fidélisation

### Phase 22 decisions
- la page `industry-restaurant` doit sonner comme une vraie page métier restauration, pas comme une simple page réservation
- le texte doit relier disponibilité, réservation, dépôts, attente, arrivée, et retour client dans un même récit
- la showcase section doit mieux faire sentir l'expérience d'accueil autant que la fluidité opérationnelle
- le CTA doit rester simple mais parler davantage d'adéquation avec le flux réel d'un restaurant
- le testimonial doit renforcer la confiance avec un vocabulaire plus concret et plus hospitality-friendly

## Message architecture for industry-restaurant

### Primary message
Gérez réservations, file d'attente, accueil, et relation client dans un même flux plus fluide pour l'équipe et plus rassurant pour les clients.

### Supporting messages
- mieux ouvrir les bons créneaux et les bonnes disponibilités
- mieux confirmer les réservations, dépôts, et règles d'annulation
- mieux gérer l'arrivée, l'attente, et le check-in
- mieux prolonger la relation après le repas ou la visite

### CTA strategy
- showcase section: les CTA onglets peuvent continuer à ouvrir les modules liés
- editorial CTA: garder un CTA principal simple orienté essai ou prise de contact
- testimonial section: pas de nouvelle action, seulement de la confiance

## Editorial guardrails

### Sell the full guest journey, not only booking admin
Le coeur de la page doit rester:
- réservation
- disponibilité
- dépôt
- file d'attente
- check-in
- accueil
- suivi après visite

Pas:
- une simple page de calendrier
- un discours trop administratif sur les réservations
- une promesse trop large qui pourrait convenir à n'importe quelle activité à rendez-vous

### Keep front-of-house flow visible
Le texte doit faire sentir que:
- les clients réservent plus facilement
- l'équipe confirme mieux et évite plus de flottement
- l'accueil garde une file plus lisible
- la relation continue après la visite

### Make the page feel hospitality-first and operationally credible
La page doit parler de:
- réservations en libre-service
- confirmations et dépôts
- arrivée et attente
- accueil en salle
- annulations et no-show
- retour client et fidélisation

## Section copy

### 1. Hero

#### Section goal
Faire comprendre très vite que Malikia Pro aide les restaurants à garder réservations, arrivée, accueil, et relation client dans un même système plus fluide.

#### Source copy
- Title: Restaurant
- Subtitle: Keep bookings, waitlist flow, check-in, and guest experience connected in one smoother journey for the front-of-house team.

#### FR final
- Title: Restaurant
- Subtitle: Gardez réservations, file d'attente, check-in, et expérience client reliés dans un même parcours plus fluide pour l'équipe d'accueil.

#### EN final
- Title: Restaurant
- Subtitle: Keep bookings, waitlist flow, check-in, and guest experience connected in one smoother journey for the front-of-house team.

### 2. Industry showcase

#### Real section shape
La section `industry-showcase` contient:
- un kicker
- un titre
- un body
- quatre onglets principaux
- des sous-cartes dans chaque onglet

#### Section goal
Montrer les vrais moments du quotidien restaurant: attirer les réservations, confirmer les présences, fluidifier l'accueil, puis faire revenir les meilleurs clients.

#### Source copy
- Kicker: One system across the full guest journey
- Title: The connected operating system for restaurants and front-of-house teams
- Body: From online reservations and deposits to arrival flow, waitlist handling, guest communication, and follow-up after the visit, Malikia Pro helps restaurants keep the full experience more fluid and easier to run.

#### FR final
- Kicker: Un système sur tout le parcours client
- Title: Le système connecté pour les restaurants et équipes d'accueil
- Body: Des réservations en ligne et des dépôts jusqu'au flux d'arrivée, à la gestion de la file, à la communication client, puis au suivi après la visite, Malikia Pro aide les restaurants à garder l'expérience plus fluide et plus simple à piloter.

#### EN final
- Kicker: One system across the full guest journey
- Title: The connected operating system for restaurants and front-of-house teams
- Body: From online reservations and deposits to arrival flow, waitlist handling, guest communication, and follow-up after the visit, Malikia Pro helps restaurants keep the full experience more fluid and easier to run.

#### Tab direction
- FR:
  - `Se faire remarquer`: mieux capter les réservations et les demandes locales
  - `Gagner des jobs`: mieux structurer offres, créneaux, et confirmations
  - `Travailler mieux`: mieux gérer l'attente, l'arrivée, et l'accueil en salle
  - `Booster les profits`: mieux transformer présence, retour client, et fidélité en revenu plus stable
- EN:
  - `Get noticed`: capture more local reservations and dining demand
  - `Win jobs`: structure offers, availability, and confirmations more clearly
  - `Work smarter`: manage waitlist, arrivals, and front-of-house flow more smoothly
  - `Boost profits`: turn attendance, return visits, and loyalty into steadier revenue

### 3. Editorial CTA

#### Real section shape
La section `industry-editorial-cta` contient:
- un titre
- un body
- un CTA principal
- un lien secondaire

#### Section goal
Donner une prochaine étape simple à un restaurant qui veut valider si le produit colle à sa réservation, à son accueil, et à son rythme de service.

#### Source copy
- Title: See if Malikia Pro fits the way your restaurant service actually runs
- Body: Explore how bookings, deposits, waitlist flow, check-in, and guest follow-up can stay connected in one system instead of being split across disconnected tools, inboxes, and front-desk workarounds.

#### FR final
- Title: Vérifiez si Malikia Pro correspond à la façon dont votre service restaurant fonctionne vraiment
- Body: Découvrez comment réservations, dépôts, file d'attente, check-in, et suivi client peuvent rester reliés dans un même système au lieu d'être dispersés entre outils déconnectés, messages, et solutions de contournement à l'accueil.

#### EN final
- Title: See if Malikia Pro fits the way your restaurant service actually runs
- Body: Explore how bookings, deposits, waitlist flow, check-in, and guest follow-up can stay connected in one system instead of being split across disconnected tools, inboxes, and front-desk workarounds.

#### CTA recommendation
- FR primary: `Commencer gratuitement`
- FR secondary link: `Voir la visite produit`
- EN primary: `Start for free`
- EN secondary link: `See the product tour`

### 4. Testimonial

#### Real section shape
La section `industry-testimonial` contient:
- un quote
- un auteur
- un rôle

#### Section goal
Renforcer la confiance avec une formulation simple, crédible, et adaptée au quotidien restaurant.

#### Source copy
- FR quote: This is more than a reservation tool. It is a clearer way to run availability, arrivals, guest flow, and return visits in one connected experience.
- EN quote: This is more than a reservation tool. It is a clearer way to run availability, arrivals, guest flow, and return visits in one connected experience.

#### FR final
- Quote: Ce n'est pas juste un outil de réservation. C'est une façon plus claire de gérer disponibilités, arrivées, flux client, et retour des habitués dans une même expérience connectée.

#### EN final
- Quote: This is more than a reservation tool. It is a clearer way to run availability, arrivals, guest flow, and return visits in one connected experience.

## Final reminders for implementation
- ne pas changer la structure de la page
- ne pas ajouter de nouvelles sections
- ne pas promettre des capacités métier non visibles dans le produit
- éviter un ton trop large “booking platform”
- garder un ton concret, fluide, et orienté accueil client

## Status
Phase 22 est maintenant documentée et prête pour application future au contenu live local.
