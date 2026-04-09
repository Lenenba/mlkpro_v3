# Malikia Pro Website Copy - Phase 18 Industry HVAC

Dernière mise à jour: 2026-04-09

## Purpose
Ce document couvre la `Phase 18 - industry-hvac` du chantier copywriting.

Objectif:
- auditer la page `industry-hvac` actuellement visible
- renforcer la crédibilité métier du message pour les équipes chauffage, climatisation, et maintenance CVC
- remplacer les formulations trop génériques par un vrai récit d’appels de service, de maintenance, d’intervention, et de facturation
- produire une base `source + FR + EN` directement applicable plus tard sans changer la structure

Ce document s'appuie sur:
- [MALIKIA_PRO_WEBSITE_COPY_PHASE_0_EDITORIAL_FOUNDATION.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASE_0_EDITORIAL_FOUNDATION.md)
- [MALIKIA_PRO_WEBSITE_COPY_PHASE_1_WELCOME.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASE_1_WELCOME.md)
- [MALIKIA_PRO_WEBSITE_COPY_PHASE_4_SALES_CRM.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASE_4_SALES_CRM.md)
- [MALIKIA_PRO_WEBSITE_COPY_PHASE_5_OPERATIONS.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASE_5_OPERATIONS.md)
- [MALIKIA_PRO_WEBSITE_COPY_PHASE_7_COMMERCE.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASE_7_COMMERCE.md)
- [MALIKIA_PRO_WEBSITE_COPY_PHASE_11_SOLUTION_SALES_QUOTING.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASE_11_SOLUTION_SALES_QUOTING.md)
- [MALIKIA_PRO_WEBSITE_COPY_PHASE_12_SOLUTION_FIELD_SERVICES.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASE_12_SOLUTION_FIELD_SERVICES.md)
- [MALIKIA_PRO_WEBSITE_COPY_PHASE_17_INDUSTRY_PLUMBING.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASE_17_INDUSTRY_PLUMBING.md)
- [MALIKIA_PRO_WEBSITE_COPY_PHASED_USER_STORY.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASED_USER_STORY.md)

## Real page structure
La page `industry-hvac` rend actuellement les zones suivantes, dans cet ordre:
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
- l'image hero dédiée à `industry-hvac`

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
- la page couvre déjà les bons objets HVAC: demandes, maintenance, interventions, et facturation
- la structure par onglets est utile pour montrer le cycle complet d’une activité CVC
- le hero mentionne déjà coordination, maintenance, terrain, et billing
- le workflow métier actuel est cohérent avec les besoins de dispatch et de suivi de visite

### Main weaknesses
- le hero reste encore un peu large et ne fait pas assez sentir la réalité HVAC entre urgences, contrats d’entretien, et installations
- le titre showcase trop générique “home service pros” affaiblit la spécificité métier
- le copy actuel parle bien de cockpit et de visibilité, mais pas assez de planification d’entretien, de créneaux, ni de suivi technique après visite
- le CTA éditorial reste trop générique et pas assez ancré dans les flux HVAC
- le testimonial peut être rendu plus concret sur maintenance, interventions, et facturation

### Phase 18 decisions
- la page `industry-hvac` doit sonner comme une vraie page métier CVC, pas comme une simple variante “services terrain”
- le texte doit faire sentir la coexistence entre appels urgents, tournées d’entretien, installations, et facturation
- la showcase section doit mieux raconter les moments HVAC avec plus de précision métier
- le CTA doit rester simple mais parler davantage d’adéquation au fonctionnement HVAC
- le testimonial doit renforcer la confiance avec un vocabulaire plus crédible pour ce secteur

## Message architecture for industry-hvac

### Primary message
Coordonnez appels de service, maintenance, interventions HVAC, et facturation dans un même flux plus clair, plus rapide, et plus fiable.

### Supporting messages
- mieux capter et organiser les demandes et contrats
- mieux planifier techniciens, plages, et tournées
- mieux documenter la visite et le contexte technique
- mieux fermer la boucle avec facture et suivi client

### CTA strategy
- showcase section: les CTA onglets peuvent continuer à ouvrir les modules liés
- editorial CTA: garder un CTA principal simple orienté essai ou prise de contact
- testimonial section: pas de nouvelle action, seulement de la confiance

## Editorial guardrails

### Sell HVAC operations, not generic field service
Le coeur de la page doit rester:
- appels de service
- maintenance récurrente
- installations
- techniciens et créneaux
- compte rendu de visite
- facturation

Pas:
- une page trop large “field service”
- un discours trop vague sur la coordination
- une promesse générique sans ancrage maintenance / intervention

### Keep both planning and field execution visible
Le texte doit faire sentir que:
- le bureau organise mieux appels, contrats, et créneaux
- les techniciens arrivent avec plus de contexte
- les visites sont mieux documentées
- la suite client et la facturation restent propres

### Make the page feel practical
La page doit parler de:
- planification des visites
- priorisation des appels
- suivi des contrats d’entretien
- qualité du compte rendu terrain
- continuité entre visite et facturation

## Section copy

### 1. Hero

#### Section goal
Faire comprendre très vite que Malikia Pro aide les équipes HVAC à coordonner appels, maintenance, interventions, et paiements dans un même système.

#### Source copy
- Title: HVAC / Heating & Cooling
- Subtitle: Keep service calls, maintenance visits, field work, and billing connected in one operating flow built for HVAC teams.

#### FR final
- Title: HVAC / Climatisation
- Subtitle: Gardez appels de service, maintenance, interventions terrain, et facturation dans un même flux opérationnel conçu pour les équipes HVAC.

#### EN final
- Title: HVAC / Heating & Cooling
- Subtitle: Keep service calls, maintenance visits, field work, and billing connected in one operating flow built for HVAC teams.

### 2. Industry showcase

#### Real section shape
La section `industry-showcase` contient:
- un kicker
- un titre
- un body
- quatre onglets principaux
- des sous-cartes dans chaque onglet

#### Section goal
Montrer les vrais moments du quotidien HVAC: attirer la demande, convertir plus vite, mieux planifier et exécuter, puis protéger le revenu.

#### Source copy
- Kicker: One system across the full HVAC workflow
- Title: The connected operating system for HVAC teams
- Body: From service requests and maintenance scheduling to technician dispatch, visit reporting, and final billing, Malikia Pro helps HVAC businesses run a clearer operating flow.

#### FR final
- Kicker: Un système sur tout le workflow HVAC
- Title: Le système connecté pour les équipes HVAC
- Body: Des appels de service et de la planification d’entretien jusqu’au dispatch technicien, au compte rendu de visite, puis à la facturation finale, Malikia Pro aide les entreprises HVAC à faire tenir le flux dans un cadre plus clair.

#### EN final
- Kicker: One system across the full HVAC workflow
- Title: The connected operating system for HVAC teams
- Body: From service requests and maintenance scheduling to technician dispatch, visit reporting, and final billing, Malikia Pro helps HVAC businesses run a clearer operating flow.

#### Tab direction
- FR:
  - `Se faire remarquer`: mieux capter les appels et les demandes locales
  - `Gagner des jobs`: mieux structurer devis, offres, et relances
  - `Travailler mieux`: mieux planifier techniciens, créneaux, et interventions
  - `Booster les profits`: mieux transformer visite, facture, et paiement en revenu plus lisible
- EN:
  - `Get noticed`: capture more local calls and demand
  - `Win jobs`: structure quotes, offers, and follow-up more clearly
  - `Work smarter`: plan technicians, time windows, and visits more cleanly
  - `Boost profits`: turn visits, invoices, and payment into clearer revenue follow-through

### 3. Editorial CTA

#### Real section shape
La section `industry-editorial-cta` contient:
- un titre
- un body
- un CTA principal
- un lien secondaire

#### Section goal
Donner une prochaine étape simple à un prospect HVAC qui veut valider rapidement si le produit colle à son organisation terrain et maintenance.

#### Source copy
- Title: See if Malikia Pro fits the way your HVAC business actually runs
- Body: Explore how service calls, maintenance planning, technician dispatch, visit context, and billing can stay connected in one system instead of being split across office tools and manual follow-up.

#### FR final
- Title: Vérifiez si Malikia Pro correspond à la façon dont votre activité HVAC fonctionne vraiment
- Body: Découvrez comment appels de service, planification d’entretien, dispatch technicien, contexte de visite, et facturation peuvent rester reliés dans un même système au lieu d’être dispersés entre outils bureau, messages terrain, et suivi manuel.

#### EN final
- Title: See if Malikia Pro fits the way your HVAC business actually runs
- Body: Explore how service calls, maintenance planning, technician dispatch, visit context, and billing can stay connected in one system instead of being split across office tools and manual follow-up.

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
Renforcer la confiance avec une formulation simple, crédible, et métier.

#### Source copy
- FR quote: More than HVAC scheduling software, this is a simpler way to coordinate maintenance, field work, and billing in one operating flow.
- EN quote: This is more than HVAC scheduling software. It is a simpler way to coordinate maintenance, field work, and billing in one operating flow.

#### FR final
- Quote: Ce n’est pas juste un outil de planification HVAC. C’est une façon plus simple de coordonner maintenance, interventions terrain, et facturation dans un même flux.

#### EN final
- Quote: This is more than HVAC scheduling software. It is a simpler way to coordinate maintenance, field work, and billing in one operating flow.

## Final reminders for implementation
- ne pas changer la structure de la page
- ne pas ajouter de nouvelles sections
- ne pas promettre des capacités métier non visibles dans le produit
- éviter un ton trop large “field service software”
- garder un ton concret, technique juste ce qu’il faut, et crédible

## Status
Phase 18 est maintenant documentée et prête pour application future au contenu live local.
