# Malikia Pro Website Copy - Phase 17 Industry Plumbing

Dernière mise à jour: 2026-04-09

## Purpose
Ce document couvre la `Phase 17 - industry-plumbing` du chantier copywriting.

Objectif:
- auditer la page `industry-plumbing` actuellement visible
- renforcer la crédibilité métier du message pour la plomberie résidentielle et commerciale
- remplacer les formulations trop génériques par un vrai récit de demande entrante, devis, intervention, et encaissement
- produire une base `source + FR + EN` directement applicable plus tard sans changer la structure

Ce document s'appuie sur:
- [MALIKIA_PRO_WEBSITE_COPY_PHASE_0_EDITORIAL_FOUNDATION.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASE_0_EDITORIAL_FOUNDATION.md)
- [MALIKIA_PRO_WEBSITE_COPY_PHASE_1_WELCOME.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASE_1_WELCOME.md)
- [MALIKIA_PRO_WEBSITE_COPY_PHASE_4_SALES_CRM.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASE_4_SALES_CRM.md)
- [MALIKIA_PRO_WEBSITE_COPY_PHASE_5_OPERATIONS.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASE_5_OPERATIONS.md)
- [MALIKIA_PRO_WEBSITE_COPY_PHASE_7_COMMERCE.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASE_7_COMMERCE.md)
- [MALIKIA_PRO_WEBSITE_COPY_PHASE_11_SOLUTION_SALES_QUOTING.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASE_11_SOLUTION_SALES_QUOTING.md)
- [MALIKIA_PRO_WEBSITE_COPY_PHASE_12_SOLUTION_FIELD_SERVICES.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASE_12_SOLUTION_FIELD_SERVICES.md)
- [MALIKIA_PRO_WEBSITE_COPY_PHASED_USER_STORY.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASED_USER_STORY.md)

## Real page structure
La page `industry-plumbing` rend actuellement les zones suivantes, dans cet ordre:
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
- l'image hero dédiée à `industry-plumbing`

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
- la page raconte déjà un cycle métier assez cohérent entre acquisition, devis, terrain, et encaissement
- la structure par onglets fonctionne bien pour montrer les vrais moments du quotidien plomberie
- le hero parle déjà de demandes, devis, interventions, et paiements
- l’ensemble reste cohérent avec les modules `sales-crm`, `operations`, et `commerce`

### Main weaknesses
- le hero reste encore un peu générique et pourrait mieux faire sentir l’urgence, la réactivité, et la continuité du dossier client
- le titre showcase est très large “pros du service à domicile” et pas assez spécifique à la plomberie
- le copy actuel parle correctement du flux, mais pas assez des réalités plomberie: urgence, diagnostic, devis rapide, bon contexte avant déplacement, et encaissement plus vite
- le bloc CTA éditorial actuel est encore très générique et pas assez métier
- le testimonial est utile, mais mérite un wording plus crédible et mieux ancré dans la plomberie

### Phase 17 decisions
- la page `industry-plumbing` doit sonner comme une vraie page métier, pas comme une page “home services” trop large
- le texte doit faire sentir la réactivité, la clarté du devis, la bonne coordination terrain, et une fin de cycle propre
- la showcase section doit raconter les moments plomberie avec plus de précision métier
- le CTA doit rester simple mais plus orienté adéquation métier
- le testimonial doit renforcer la confiance sans tomber dans le slogan vague

## Message architecture for industry-plumbing

### Primary message
Gérez demandes, devis, interventions, et encaissement plomberie dans un même flux plus clair, plus rapide, et plus fiable.

### Supporting messages
- mieux capter et qualifier les demandes entrantes
- mieux préparer et envoyer les devis
- mieux coordonner le passage du bureau au terrain
- mieux transformer le travail réalisé en facture et paiement

### CTA strategy
- showcase section: les CTA onglets peuvent continuer à ouvrir les modules liés
- editorial CTA: garder un CTA principal simple orienté essai ou prise de contact
- testimonial section: pas de nouvelle action, seulement de la confiance

## Editorial guardrails

### Sell plumbing operations, not generic home services
Le coeur de la page doit rester:
- demande entrante
- urgence
- devis
- intervention
- preuve de travail
- facturation

Pas:
- une page trop large “service business”
- un discours trop abstrait sur la coordination
- une promesse générique qui pourrait convenir à n’importe quel métier

### Keep both office and field visible
Le texte doit faire sentir que:
- le bureau voit mieux la demande et le devis
- l’équipe terrain part avec le bon contexte
- le client garde une meilleure lecture du suivi
- la facture et le paiement ferment la boucle sans friction inutile

### Make the page feel practical
La page doit parler de:
- vitesse de réponse
- contexte avant intervention
- qualité du handoff
- clarté du devis
- encaissement plus rapide

## Section copy

### 1. Hero

#### Section goal
Faire comprendre très vite que Malikia Pro aide les équipes plomberie à traiter plus proprement les demandes, les devis, les interventions, et les paiements.

#### Source copy
- Title: Plumbing
- Subtitle: Keep requests, quotes, field work, and payment connected in one operating flow built for plumbing teams.

#### FR final
- Title: Plomberie
- Subtitle: Gardez demandes, devis, interventions terrain, et paiements dans un même flux opérationnel conçu pour les équipes plomberie.

#### EN final
- Title: Plumbing
- Subtitle: Keep requests, quotes, field work, and payment connected in one operating flow built for plumbing teams.

### 2. Industry showcase

#### Real section shape
La section `industry-showcase` contient:
- un kicker
- un titre
- un body
- quatre onglets principaux
- des sous-cartes dans chaque onglet

#### Section goal
Montrer les vrais moments du quotidien plomberie: attirer la demande, convertir plus vite, mieux intervenir, et protéger le revenu.

#### Source copy
- Kicker: One system across the full plumbing workflow
- Title: The connected operating system for plumbing teams
- Body: From inbound requests to quoted work, field execution, and final payment, Malikia Pro helps plumbing businesses keep the full job flow clearer and easier to run.

#### FR final
- Kicker: Un système sur tout le workflow plomberie
- Title: Le système connecté pour les équipes plomberie
- Body: De la demande entrante au devis, à l’intervention terrain, puis au paiement final, Malikia Pro aide les entreprises de plomberie à garder tout le flux plus clair et plus simple à piloter.

#### EN final
- Kicker: One system across the full plumbing workflow
- Title: The connected operating system for plumbing teams
- Body: From inbound requests to quoted work, field execution, and final payment, Malikia Pro helps plumbing businesses keep the full job flow clearer and easier to run.

#### Tab direction
- FR:
  - `Se faire remarquer`: mieux capter les demandes locales et répondre vite
  - `Gagner des jobs`: mieux structurer devis, options, et relances
  - `Travailler mieux`: mieux préparer les équipes avant déplacement et suivre l’intervention
  - `Booster les profits`: mieux facturer, encaisser, et protéger la trésorerie
- EN:
  - `Get noticed`: capture local demand and answer faster
  - `Win jobs`: structure quotes, options, and follow-up more clearly
  - `Work smarter`: prepare crews better before dispatch and track work more cleanly
  - `Boost profits`: invoice faster, collect sooner, and protect cash flow

### 3. Editorial CTA

#### Real section shape
La section `industry-editorial-cta` contient:
- un titre
- un body
- un CTA principal
- un lien secondaire

#### Section goal
Donner une prochaine étape simple à un prospect plomberie qui veut valider rapidement si le produit colle à son mode de fonctionnement.

#### Source copy
- Title: See if Malikia Pro fits the way your plumbing business actually runs
- Body: Explore how requests, quotes, dispatch, job context, and payment can stay connected in one system instead of being split across office tools, team messages, and manual follow-up.

#### FR final
- Title: Vérifiez si Malikia Pro correspond à la façon dont votre activité plomberie fonctionne vraiment
- Body: Découvrez comment demandes, devis, dispatch, contexte d’intervention, et paiement peuvent rester reliés dans un même système au lieu d’être dispersés entre outils bureau, messages terrain, et relances manuelles.

#### EN final
- Title: See if Malikia Pro fits the way your plumbing business actually runs
- Body: Explore how requests, quotes, dispatch, job context, and payment can stay connected in one system instead of being split across office tools, team messages, and manual follow-up.

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
- FR quote: More than a plumbing job app, this is a simpler way to run requests, quotes, and payments in one flow.
- EN quote: This is more than a plumbing job app. It is a simpler way to run requests, quotes, and payments in one flow.

#### FR final
- Quote: Ce n’est pas juste une app pour gérer des interventions plomberie. C’est une façon plus simple de faire tenir demandes, devis, interventions, et paiements dans un même flux.

#### EN final
- Quote: This is more than a plumbing job app. It is a simpler way to keep requests, quotes, field work, and payments inside one flow.

## Final reminders for implementation
- ne pas changer la structure de la page
- ne pas ajouter de nouvelles sections
- ne pas promettre des capacités métier non visibles dans le produit
- éviter les formules trop larges “home services” si elles affaiblissent l’ancrage plomberie
- garder un ton concret, rapide, et crédible

## Status
Phase 17 est maintenant documentée et prête pour application future au contenu live local.
