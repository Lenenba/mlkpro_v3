# Malikia Pro Website Copy - Phase 19 Industry Electrical

Dernière mise à jour: 2026-04-09

## Purpose
Ce document couvre la `Phase 19 - industry-electrical` du chantier copywriting.

Objectif:
- auditer la page `industry-electrical` actuellement visible
- renforcer la crédibilité métier du message pour les équipes électricité, installation, et intervention
- remplacer les formulations trop génériques par un vrai récit de demande, devis, scope, exécution, et facturation
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
- [MALIKIA_PRO_WEBSITE_COPY_PHASE_18_INDUSTRY_HVAC.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASE_18_INDUSTRY_HVAC.md)
- [MALIKIA_PRO_WEBSITE_COPY_PHASED_USER_STORY.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASED_USER_STORY.md)

## Real page structure
La page `industry-electrical` rend actuellement les zones suivantes, dans cet ordre:
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
- l'image hero dédiée à `industry-electrical`

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
- la page couvre déjà les bons objets métier: devis, chantiers, intervention, suivi terrain, et encaissement
- la structure par onglets est utile pour raconter l’ensemble du cycle électrique
- le hero parle déjà d’exécution terrain et de lecture claire du dossier
- le workflow actuel relie correctement commercial, opérations, et facture finale

### Main weaknesses
- le hero reste encore un peu descriptif et ne fait pas assez sentir la précision du devis, la validation du scope, et la continuité d’exécution
- le titre showcase trop générique “home service pros” affaiblit la lecture métier électricité
- le copy actuel ne fait pas assez sentir la logique chantier / intervention / clôture avec contexte partagé
- le CTA éditorial reste trop large et pas assez ancré dans les réalités électricité
- le testimonial peut être rendu plus crédible autour de devis, exécution, et clôture

### Phase 19 decisions
- la page `industry-electrical` doit sonner comme une vraie page métier électricité, pas comme une simple page “services terrain”
- le texte doit faire sentir la précision commerciale, la clarté du scope, et la qualité du handoff vers le terrain
- la showcase section doit mieux raconter devis, exécution, coordination, et clôture
- le CTA doit rester simple mais plus orienté adéquation au fonctionnement électricité
- le testimonial doit renforcer la confiance avec un langage plus concret

## Message architecture for industry-electrical

### Primary message
Suivez demandes, devis, chantiers et facturation électrique dans un même flux plus clair, plus rigoureux, et plus facile à piloter.

### Supporting messages
- mieux qualifier la demande électrique
- mieux cadrer le devis et le scope
- mieux coordonner techniciens, chantier, et intervention
- mieux fermer la boucle avec facture et suivi client

### CTA strategy
- showcase section: les CTA onglets peuvent continuer à ouvrir les modules liés
- editorial CTA: garder un CTA principal simple orienté essai ou prise de contact
- testimonial section: pas de nouvelle action, seulement de la confiance

## Editorial guardrails

### Sell electrical execution, not generic field work
Le coeur de la page doit rester:
- demande électrique
- devis
- scope
- chantier ou intervention
- coordination terrain
- clôture et facture

Pas:
- une page trop large “contractor software”
- un discours abstrait sur la productivité
- une promesse générique sans ancrage devis / chantier / clôture

### Keep both commercial rigor and field execution visible
Le texte doit faire sentir que:
- le bureau structure mieux la demande et le devis
- le scope reste plus clair avant exécution
- le terrain travaille avec un meilleur contexte
- la clôture et la facture restent propres

### Make the page feel practical
La page doit parler de:
- qualification du besoin
- validation du scope
- coordination chantier ou intervention
- contexte partagé entre bureau et terrain
- facturation plus nette après exécution

## Section copy

### 1. Hero

#### Section goal
Faire comprendre très vite que Malikia Pro aide les équipes électricité à mieux suivre devis, interventions, et clôture terrain dans un même système.

#### Source copy
- Title: Electrical
- Subtitle: Keep electrical requests, quotes, field work, and invoicing connected in one operating flow with a clearer view of every job.

#### FR final
- Title: Électricité
- Subtitle: Gardez demandes électriques, devis, exécution terrain, et facturation dans un même flux opérationnel avec une lecture plus claire de chaque dossier.

#### EN final
- Title: Electrical
- Subtitle: Keep electrical requests, quotes, field work, and invoicing connected in one operating flow with a clearer view of every job.

### 2. Industry showcase

#### Real section shape
La section `industry-showcase` contient:
- un kicker
- un titre
- un body
- quatre onglets principaux
- des sous-cartes dans chaque onglet

#### Section goal
Montrer les vrais moments du quotidien électricité: capter la demande, cadrer le devis, mieux exécuter, puis protéger le revenu.

#### Source copy
- Kicker: One system across the full electrical workflow
- Title: The connected operating system for electrical teams
- Body: From incoming requests and quoting to job execution, closeout, and final invoicing, Malikia Pro helps electrical businesses keep every job clearer from first need to final bill.

#### FR final
- Kicker: Un système sur tout le workflow électricité
- Title: Le système connecté pour les équipes électricité
- Body: De la demande entrante et du devis jusqu’à l’exécution, à la clôture, puis à la facture finale, Malikia Pro aide les entreprises électricité à garder chaque dossier plus clair du premier besoin jusqu’au règlement.

#### EN final
- Kicker: One system across the full electrical workflow
- Title: The connected operating system for electrical teams
- Body: From incoming requests and quoting to job execution, closeout, and final invoicing, Malikia Pro helps electrical businesses keep every job clearer from first need to final bill.

#### Tab direction
- FR:
  - `Se faire remarquer`: mieux capter les demandes et opportunités locales
  - `Gagner des jobs`: mieux cadrer devis, options, et relances
  - `Travailler mieux`: mieux préparer les techniciens et suivre l’exécution
  - `Booster les profits`: mieux transformer la clôture en facture et paiement
- EN:
  - `Get noticed`: capture more local demand and opportunities
  - `Win jobs`: clarify quotes, options, and follow-up
  - `Work smarter`: prepare technicians better and track execution more cleanly
  - `Boost profits`: turn closeout into faster invoicing and payment

### 3. Editorial CTA

#### Real section shape
La section `industry-editorial-cta` contient:
- un titre
- un body
- un CTA principal
- un lien secondaire

#### Section goal
Donner une prochaine étape simple à un prospect électricité qui veut valider si le produit colle à son mode de fonctionnement entre devis, terrain, et clôture.

#### Source copy
- Title: See if Malikia Pro fits the way your electrical business actually runs
- Body: Explore how demand, quoting, execution, field context, and invoicing can stay connected in one system instead of being split across office tools, messages, and manual follow-up.

#### FR final
- Title: Vérifiez si Malikia Pro correspond à la façon dont votre activité électricité fonctionne vraiment
- Body: Découvrez comment demande, devis, exécution, contexte terrain, et facturation peuvent rester reliés dans un même système au lieu d’être dispersés entre outils bureau, messages d’équipe, et suivi manuel.

#### EN final
- Title: See if Malikia Pro fits the way your electrical business actually runs
- Body: Explore how demand, quoting, execution, field context, and invoicing can stay connected in one system instead of being split across office tools, messages, and manual follow-up.

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
- FR quote: More than an electrical job tracker, this is a clearer way to manage demand, quotes, execution, and collection in one flow.
- EN quote: This is more than an electrical job tracker. It is a clearer way to manage demand, quotes, execution, and collection in one flow.

#### FR final
- Quote: Ce n’est pas juste un outil pour suivre des jobs électriques. C’est une façon plus claire de gérer demandes, devis, exécution, et encaissement dans un même flux.

#### EN final
- Quote: This is more than an electrical job tracker. It is a clearer way to manage demand, quotes, execution, and collection in one flow.

## Final reminders for implementation
- ne pas changer la structure de la page
- ne pas ajouter de nouvelles sections
- ne pas promettre des capacités métier non visibles dans le produit
- éviter un ton trop large “field operations platform”
- garder un ton précis, professionnel, et concret

## Status
Phase 19 est maintenant documentée et prête pour application future au contenu live local.
