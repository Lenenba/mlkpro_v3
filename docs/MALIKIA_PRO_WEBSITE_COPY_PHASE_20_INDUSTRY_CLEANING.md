# Malikia Pro Website Copy - Phase 20 Industry Cleaning

Dernière mise à jour: 2026-04-09

## Purpose
Ce document couvre la `Phase 20 - industry-cleaning` du chantier copywriting.

Objectif:
- auditer la page `industry-cleaning` actuellement visible
- renforcer la crédibilité métier du message pour les entreprises de nettoyage récurrent, multi-sites, et équipes terrain
- remplacer les formulations trop génériques par un vrai récit de récurrence, de présence, de qualité d’exécution, et de fidélisation client
- produire une base `source + FR + EN` directement applicable plus tard sans changer la structure

Ce document s'appuie sur:
- [MALIKIA_PRO_WEBSITE_COPY_PHASE_0_EDITORIAL_FOUNDATION.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASE_0_EDITORIAL_FOUNDATION.md)
- [MALIKIA_PRO_WEBSITE_COPY_PHASE_1_WELCOME.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASE_1_WELCOME.md)
- [MALIKIA_PRO_WEBSITE_COPY_PHASE_5_OPERATIONS.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASE_5_OPERATIONS.md)
- [MALIKIA_PRO_WEBSITE_COPY_PHASE_7_COMMERCE.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASE_7_COMMERCE.md)
- [MALIKIA_PRO_WEBSITE_COPY_PHASE_8_MARKETING_LOYALTY.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASE_8_MARKETING_LOYALTY.md)
- [MALIKIA_PRO_WEBSITE_COPY_PHASE_12_SOLUTION_FIELD_SERVICES.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASE_12_SOLUTION_FIELD_SERVICES.md)
- [MALIKIA_PRO_WEBSITE_COPY_PHASE_17_INDUSTRY_PLUMBING.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASE_17_INDUSTRY_PLUMBING.md)
- [MALIKIA_PRO_WEBSITE_COPY_PHASE_18_INDUSTRY_HVAC.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASE_18_INDUSTRY_HVAC.md)
- [MALIKIA_PRO_WEBSITE_COPY_PHASE_19_INDUSTRY_ELECTRICAL.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASE_19_INDUSTRY_ELECTRICAL.md)
- [MALIKIA_PRO_WEBSITE_COPY_PHASED_USER_STORY.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASED_USER_STORY.md)

## Real page structure
La page `industry-cleaning` rend actuellement les zones suivantes, dans cet ordre:
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
- l'image hero dédiée à `industry-cleaning`

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
- la page couvre déjà les bons thèmes métier: sites, récurrence, présence, qualité, et relation client
- la structure par onglets convient bien à une activité nettoyage avec travail répétitif et suivi continu
- le hero mentionne déjà équipes, jobs récurrents, et suivi client
- le workflow actuel parle déjà de planification par site, d’équipe, et de contrôle qualité

### Main weaknesses
- le hero reste encore un peu générique et ne fait pas assez sentir la réalité multi-sites, la présence, et la constance de qualité
- le titre showcase trop large “home service pros” affaiblit l’ancrage nettoyage
- le copy actuel ne fait pas assez sentir la gestion de la récurrence, des absences, des incidents, et des preuves de passage
- le CTA éditorial reste trop large et pas assez orienté adéquation au fonctionnement nettoyage
- le testimonial peut être rendu plus concret sur sites récurrents, équipes terrain, et relation client durable

### Phase 20 decisions
- la page `industry-cleaning` doit sonner comme une vraie page métier nettoyage, pas comme une simple page service terrain
- le texte doit faire sentir la répétition maîtrisée, la qualité suivie, et la fiabilité dans le temps
- la showcase section doit mieux raconter sites, équipes, présence, incidents, et reconduction client
- le CTA doit rester simple mais plus ancré dans les réalités d’opérations récurrentes
- le testimonial doit renforcer la confiance avec un langage plus concret et plus crédible

## Message architecture for industry-cleaning

### Primary message
Pilotez sites récurrents, équipes terrain, qualité d’exécution, et suivi client dans un même flux plus fiable et plus facile à tenir dans la durée.

### Supporting messages
- mieux structurer les sites et la récurrence
- mieux planifier équipes, présence, et remplacements
- mieux suivre qualité, incidents, et preuves de passage
- mieux garder la relation client et la facturation récurrente propres

### CTA strategy
- showcase section: les CTA onglets peuvent continuer à ouvrir les modules liés
- editorial CTA: garder un CTA principal simple orienté essai ou prise de contact
- testimonial section: pas de nouvelle action, seulement de la confiance

## Editorial guardrails

### Sell recurring cleaning operations, not generic task management
Le coeur de la page doit rester:
- sites
- récurrence
- présence
- qualité
- preuve de passage
- fidélisation client

Pas:
- une page trop large “team scheduling”
- un discours abstrait sur la productivité
- une promesse générique sans ancrage opérations répétitives

### Keep both service quality and operational discipline visible
Le texte doit faire sentir que:
- le bureau structure mieux les sites et la récurrence
- les équipes terrain savent où aller et quoi faire
- la qualité et les incidents restent visibles
- le client garde une meilleure continuité de service

### Make the page feel practical
La page doit parler de:
- sites récurrents
- équipes et présence
- standards de qualité
- incidents et suivi terrain
- reconduction et relation client

## Section copy

### 1. Hero

#### Section goal
Faire comprendre très vite que Malikia Pro aide les entreprises de nettoyage à mieux piloter leurs sites, leurs équipes, et la qualité de service dans la durée.

#### Source copy
- Title: Cleaning
- Subtitle: Keep recurring sites, field teams, service quality, and customer follow-up connected in one operating flow built for cleaning businesses.

#### FR final
- Title: Nettoyage
- Subtitle: Gardez sites récurrents, équipes terrain, qualité de service, et suivi client dans un même flux opérationnel conçu pour les entreprises de nettoyage.

#### EN final
- Title: Cleaning
- Subtitle: Keep recurring sites, field teams, service quality, and customer follow-up connected in one operating flow built for cleaning businesses.

### 2. Industry showcase

#### Real section shape
La section `industry-showcase` contient:
- un kicker
- un titre
- un body
- quatre onglets principaux
- des sous-cartes dans chaque onglet

#### Section goal
Montrer les vrais moments du quotidien nettoyage: capter les bons contrats, mieux organiser les sites, mieux suivre la qualité, puis protéger le revenu récurrent.

#### Source copy
- Kicker: One system across recurring cleaning operations
- Title: The connected operating system for cleaning businesses
- Body: From recurring site planning and attendance to quality follow-up, customer communication, and invoicing, Malikia Pro helps cleaning teams keep the operation consistent over time.

#### FR final
- Kicker: Un système sur tout le workflow nettoyage récurrent
- Title: Le système connecté pour les entreprises de nettoyage
- Body: De la planification des sites récurrents et de la présence jusqu’au suivi qualité, à la communication client, puis à la facturation, Malikia Pro aide les équipes de nettoyage à garder l’opération plus constante dans le temps.

#### EN final
- Kicker: One system across recurring cleaning operations
- Title: The connected operating system for cleaning businesses
- Body: From recurring site planning and attendance to quality follow-up, customer communication, and invoicing, Malikia Pro helps cleaning teams keep the operation consistent over time.

#### Tab direction
- FR:
  - `Se faire remarquer`: mieux capter les bons clients et demandes récurrentes
  - `Gagner des jobs`: mieux structurer devis, offres, et relances
  - `Travailler mieux`: mieux organiser les sites, équipes, et contrôles qualité
  - `Booster les profits`: mieux transformer récurrence, facture, et fidélisation en revenu durable
- EN:
  - `Get noticed`: capture the right recurring clients and opportunities
  - `Win jobs`: structure quotes, offers, and follow-up more clearly
  - `Work smarter`: organize sites, teams, and quality controls better
  - `Boost profits`: turn recurring work, invoicing, and retention into steadier revenue

### 3. Editorial CTA

#### Real section shape
La section `industry-editorial-cta` contient:
- un titre
- un body
- un CTA principal
- un lien secondaire

#### Section goal
Donner une prochaine étape simple à un prospect nettoyage qui veut valider si le produit colle à son fonctionnement multi-sites et récurrent.

#### Source copy
- Title: See if Malikia Pro fits the way your cleaning operation actually runs
- Body: Explore how recurring sites, attendance, field notes, service quality, and customer follow-up can stay connected in one system instead of being split across spreadsheets, messages, and manual checks.

#### FR final
- Title: Vérifiez si Malikia Pro correspond à la façon dont votre activité nettoyage fonctionne vraiment
- Body: Découvrez comment sites récurrents, présence, notes terrain, qualité de service, et suivi client peuvent rester reliés dans un même système au lieu d’être dispersés entre feuilles de calcul, messages, et contrôles manuels.

#### EN final
- Title: See if Malikia Pro fits the way your cleaning operation actually runs
- Body: Explore how recurring sites, attendance, field notes, service quality, and customer follow-up can stay connected in one system instead of being split across spreadsheets, messages, and manual checks.

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
- FR quote: More than a task assignment app, this is a more reliable way to run recurring sites, field teams, and customer follow-up in one flow.
- EN quote: This is more than a task assignment app. It is a more reliable way to run recurring sites, field teams, and customer follow-up in one flow.

#### FR final
- Quote: Ce n’est pas juste une app pour assigner des tâches. C’est une façon plus fiable de piloter sites récurrents, équipes terrain, et suivi client dans un même flux.

#### EN final
- Quote: This is more than a task assignment app. It is a more reliable way to run recurring sites, field teams, and customer follow-up in one flow.

## Final reminders for implementation
- ne pas changer la structure de la page
- ne pas ajouter de nouvelles sections
- ne pas promettre des capacités métier non visibles dans le produit
- éviter un ton trop large “workforce tool”
- garder un ton concret, fiable, et orienté constance opérationnelle

## Status
Phase 20 est maintenant documentée et prête pour application future au contenu live local.
