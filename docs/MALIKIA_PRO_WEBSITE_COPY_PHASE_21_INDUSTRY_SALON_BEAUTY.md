# Malikia Pro Website Copy - Phase 21 Industry Salon & Beauty

Dernière mise à jour: 2026-04-09

## Purpose
Ce document couvre la `Phase 21 - industry-salon-beauty` du chantier copywriting.

Objectif:
- auditer la page `industry-salon-beauty` actuellement visible
- renforcer le positionnement de la page autour de l’expérience client, des rendez-vous, et de la fidélisation
- remplacer les formulations trop génériques par un vrai récit de réservation, rappel, accueil, visite, et retour client
- produire une base `source + FR + EN` directement applicable plus tard sans changer la structure

Ce document s'appuie sur:
- [MALIKIA_PRO_WEBSITE_COPY_PHASE_0_EDITORIAL_FOUNDATION.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASE_0_EDITORIAL_FOUNDATION.md)
- [MALIKIA_PRO_WEBSITE_COPY_PHASE_1_WELCOME.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASE_1_WELCOME.md)
- [MALIKIA_PRO_WEBSITE_COPY_PHASE_6_RESERVATIONS.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASE_6_RESERVATIONS.md)
- [MALIKIA_PRO_WEBSITE_COPY_PHASE_8_MARKETING_LOYALTY.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASE_8_MARKETING_LOYALTY.md)
- [MALIKIA_PRO_WEBSITE_COPY_PHASE_13_SOLUTION_RESERVATIONS_QUEUES.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASE_13_SOLUTION_RESERVATIONS_QUEUES.md)
- [MALIKIA_PRO_WEBSITE_COPY_PHASE_15_SOLUTION_MARKETING_LOYALTY.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASE_15_SOLUTION_MARKETING_LOYALTY.md)
- [MALIKIA_PRO_WEBSITE_COPY_PHASE_17_INDUSTRY_PLUMBING.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASE_17_INDUSTRY_PLUMBING.md)
- [MALIKIA_PRO_WEBSITE_COPY_PHASE_20_INDUSTRY_CLEANING.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASE_20_INDUSTRY_CLEANING.md)
- [MALIKIA_PRO_WEBSITE_COPY_PHASED_USER_STORY.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASED_USER_STORY.md)

## Real page structure
La page `industry-salon-beauty` rend actuellement les zones suivantes, dans cet ordre:
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
- l'image hero dédiée à `industry-salon-beauty`

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
- la page couvre déjà les bons thèmes métier: réservation, rappels, no-show fees, fidélisation, et accueil
- la structure par onglets convient bien à un parcours client orienté rendez-vous
- le hero parle déjà d’une expérience pensée pour les salons
- le workflow actuel raconte correctement le passage de la réservation à la fidélisation

### Main weaknesses
- le hero reste encore un peu fonctionnel et ne fait pas assez sentir l’univers premium, le soin, et la fluidité d’expérience
- le titre showcase trop générique “home service pros” casse le positionnement salon / beauté
- le copy actuel parle bien de rendez-vous et de relation client, mais pas assez de réassurance, de confort de réservation, ni de valeur client long terme
- le CTA éditorial reste trop générique et pas assez aligné avec un service orienté expérience
- le testimonial peut être rendu plus élégant et plus crédible pour cet univers

### Phase 21 decisions
- la page `industry-salon-beauty` doit respirer une expérience plus soignée, plus fluide, et plus premium
- le texte doit relier réservation, accueil, visite, et fidélisation dans un même parcours
- la showcase section doit mieux raconter le confort client autant que la fluidité opérationnelle
- le CTA doit rester simple mais parler davantage d’adéquation pour une activité à rendez-vous
- le testimonial doit renforcer la confiance avec un ton plus propre et plus premium

## Message architecture for industry-salon-beauty

### Primary message
Gérez rendez-vous, rappels, accueil, et fidélisation dans un même flux plus fluide pour le client et plus simple à piloter pour l’équipe.

### Supporting messages
- mieux ouvrir les bons créneaux et capter les réservations
- mieux confirmer et rappeler les rendez-vous
- mieux gérer l’arrivée, le service, et le no-show
- mieux prolonger la relation après la visite

### CTA strategy
- showcase section: les CTA onglets peuvent continuer à ouvrir les modules liés
- editorial CTA: garder un CTA principal simple orienté essai ou prise de contact
- testimonial section: pas de nouvelle action, seulement de la confiance

## Editorial guardrails

### Sell the client experience, not just booking admin
Le coeur de la page doit rester:
- réservation
- rappel
- accueil
- visite
- fidélisation
- revenu récurrent

Pas:
- une simple page de calendrier
- un discours trop administratif sur les rendez-vous
- une promesse générique qui pourrait convenir à n’importe quelle activité service

### Keep both hospitality and operational control visible
Le texte doit faire sentir que:
- le client réserve plus facilement
- l’équipe garde une journée plus lisible
- l’accueil reste plus fluide
- la relation continue après la visite

### Make the page feel premium and practical
La page doit parler de:
- fluidité de réservation
- rappels et présence
- no-show et annulations
- accueil et service
- fidélisation et retour client

## Section copy

### 1. Hero

#### Section goal
Faire comprendre très vite que Malikia Pro aide les salons et activités beauté à offrir une expérience plus fluide, de la réservation jusqu’au retour client.

#### Source copy
- Title: Salon & beauty
- Subtitle: Keep bookings, reminders, no-show handling, and customer loyalty connected in one smoother experience for the team and the client.

#### FR final
- Title: Salon & beauté
- Subtitle: Gardez réservations, rappels, gestion des no-shows, et fidélisation dans une même expérience plus fluide pour l’équipe comme pour le client.

#### EN final
- Title: Salon & beauty
- Subtitle: Keep bookings, reminders, no-show handling, and customer loyalty connected in one smoother experience for the team and the client.

### 2. Industry showcase

#### Real section shape
La section `industry-showcase` contient:
- un kicker
- un titre
- un body
- quatre onglets principaux
- des sous-cartes dans chaque onglet

#### Section goal
Montrer les vrais moments du quotidien salon: attirer les réservations, mieux organiser la journée, fluidifier l’accueil, puis faire revenir les clientes et clients.

#### Source copy
- Kicker: One system across the full appointment-led journey
- Title: The connected operating system for salons and beauty teams
- Body: From online booking and reminders to front-desk flow, service follow-through, and customer loyalty, Malikia Pro helps beauty businesses keep the full experience more fluid and easier to run.

#### FR final
- Kicker: Un système sur tout le parcours à rendez-vous
- Title: Le système connecté pour les salons et équipes beauté
- Body: De la réservation en ligne et des rappels jusqu’au flux d’accueil, au suivi de visite, puis à la fidélisation, Malikia Pro aide les activités beauté à garder l’expérience plus fluide et plus simple à piloter.

#### EN final
- Kicker: One system across the full appointment-led journey
- Title: The connected operating system for salons and beauty teams
- Body: From online booking and reminders to front-desk flow, service follow-through, and customer loyalty, Malikia Pro helps beauty businesses keep the full experience more fluid and easier to run.

#### Tab direction
- FR:
  - `Se faire remarquer`: mieux capter les demandes et réservations
  - `Gagner des jobs`: mieux structurer offres, services, et relances
  - `Travailler mieux`: mieux organiser la journée, l’accueil, et le service
  - `Booster les profits`: mieux transformer présence, fidélité, et ventes récurrentes en revenu durable
- EN:
  - `Get noticed`: capture more demand and bookings
  - `Win jobs`: structure offers, services, and follow-up more clearly
  - `Work smarter`: run the day, reception, and service more smoothly
  - `Boost profits`: turn attendance, loyalty, and repeat sales into steadier revenue

### 3. Editorial CTA

#### Real section shape
La section `industry-editorial-cta` contient:
- un titre
- un body
- un CTA principal
- un lien secondaire

#### Section goal
Donner une prochaine étape simple à un salon ou une activité beauté qui veut valider si le produit colle à son expérience client et à son fonctionnement quotidien.

#### Source copy
- Title: See if Malikia Pro fits the way your salon experience actually runs
- Body: Explore how bookings, reminders, front-desk flow, no-show management, and loyalty can stay connected in one system instead of being split across inboxes, paper notes, and disconnected tools.

#### FR final
- Title: Vérifiez si Malikia Pro correspond à la façon dont votre expérience salon fonctionne vraiment
- Body: Découvrez comment réservations, rappels, flux d’accueil, gestion des no-shows, et fidélisation peuvent rester reliés dans un même système au lieu d’être dispersés entre messages, notes, et outils déconnectés.

#### EN final
- Title: See if Malikia Pro fits the way your salon experience actually runs
- Body: Explore how bookings, reminders, front-desk flow, no-show management, and loyalty can stay connected in one system instead of being split across inboxes, paper notes, and disconnected tools.

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
Renforcer la confiance avec une formulation simple, élégante, et crédible.

#### Source copy
- FR quote: More than a booking calendar, this is a smoother way to manage appointments, reminders, no-show fees, and loyalty in one flow.
- EN quote: This is more than a booking calendar. It is a smoother way to manage appointments, reminders, no-show fees, and loyalty in one flow.

#### FR final
- Quote: Ce n’est pas juste un agenda de rendez-vous. C’est une façon plus fluide de gérer réservations, rappels, no-show fees, et fidélisation dans un même parcours.

#### EN final
- Quote: This is more than a booking calendar. It is a smoother way to manage appointments, reminders, no-show fees, and loyalty in one flow.

## Final reminders for implementation
- ne pas changer la structure de la page
- ne pas ajouter de nouvelles sections
- ne pas promettre des capacités métier non visibles dans le produit
- éviter un ton trop large “booking software”
- garder un ton fluide, plus premium, et orienté expérience client

## Status
Phase 21 est maintenant documentée et prête pour application future au contenu live local.
