# Malikia Pro Website Copy - Phase 14 Solution Commerce & Catalog

Dernière mise à jour: 2026-04-09

## Purpose
Ce document couvre la `Phase 14 - solution-commerce-catalog` du chantier copywriting.

Objectif:
- auditer la page `solution-commerce-catalog` actuellement visible
- renforcer le positionnement de la solution autour de la continuité commerciale, du catalogue jusqu’à l’encaissement
- remplacer les formulations encore trop descriptives par un vrai récit de vente, de facturation, et de revenu connecté
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
- [MALIKIA_PRO_WEBSITE_COPY_PHASED_USER_STORY.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASED_USER_STORY.md)

## Real page structure
La page `solution-commerce-catalog` rend actuellement les zones suivantes, dans cet ordre:
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
- l'image hero dédiée à `solution-commerce-catalog`

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
- la page suit déjà une logique de continuité commerciale claire, de l’offre jusqu’au paiement
- la structure `overview -> workflow -> modules` convient bien à une page solution orientée revenu
- les modules cités sont cohérents: catalogue, boutique, commandes, factures, paiements
- la solution est déjà lisible comme un ensemble commercial plus large qu’un simple outil de caisse

### Main weaknesses
- le sous-titre actuel reste encore un peu générique et ne fait pas assez sentir la continuité du parcours client
- le body overview parle de monétisation, mais pas assez de confiance commerciale, de lisibilité, ni de structuration du revenu
- le workflow pourrait mieux raconter la transition entre offre publiée, commande, facture, et encaissement
- la section modules reste encore trop descriptive au lieu d’expliquer pourquoi ces espaces comptent dans la fluidité commerciale
- la page doit mieux faire sentir que la solution protège à la fois l’expérience client et la qualité du revenu suivi

### Phase 14 decisions
- la page `solution-commerce-catalog` doit raconter un vrai parcours commercial du catalogue au revenu collecté
- la page doit montrer que vente, facturation, et encaissement restent reliés dans un même système lisible
- le texte doit rassurer autant sur la simplicité côté client que sur le contrôle côté équipe
- la section modules doit expliquer pourquoi ces espaces comptent dans la continuité commerciale
- le CTA principal vers pricing reste pertinent, mais la page doit mieux préparer ce passage par une promesse plus nette

## Message architecture for solution-commerce-catalog

### Primary message
Transformez catalogue, commande, facturation, et paiement en un parcours commercial plus clair, plus fiable, et plus simple à piloter.

### Supporting messages
- mieux présenter l’offre avant l’achat
- garder la commande plus lisible jusqu’au récapitulatif
- faire de la facture la suite naturelle de la vente
- relier paiement et revenu à la transaction d’origine

### CTA strategy
- overview section: `Voir les tarifs` en principal, `Nous contacter` en secondaire
- workflow section: pas de nouveau CTA, la narration doit porter la valeur
- modules section: garder une lecture produit concrète sans ajouter de nouvelles actions

## Editorial guardrails

### Sell commercial continuity, not just catalog administration
Le coeur de la page doit rester:
- offre
- commande
- facture
- paiement
- revenu collecté

Pas:
- une simple page de catalogue
- un discours back-office trop technique
- une description générique de “commerce”

### Keep both sides visible: customer and business
Le texte doit faire sentir que:
- le client comprend mieux ce qu’il peut acheter
- l’équipe garde la main sur la commande et la facturation
- le paiement reste relié à la transaction d’origine
- la lecture du revenu devient plus claire

### Make the solution feel end-to-end
La page doit montrer que la solution relie:
- le catalogue
- la boutique
- la commande
- la facture
- l’encaissement
- le suivi du revenu

## Section copy

### 1. Hero

#### Section goal
Faire comprendre très vite que `Commerce & catalogue` aide les entreprises à vendre, facturer, et encaisser dans un même parcours commercial connecté.

#### Source copy
- Title: Commerce & catalog
- Subtitle: Publish your offer, guide the order, invoice cleanly, and collect payment inside one connected commercial journey.

#### FR final
- Title: Commerce & catalogue
- Subtitle: Publiez votre offre, guidez la commande, facturez proprement, et encaissez dans un même parcours commercial connecté.

#### EN final
- Title: Commerce & catalog
- Subtitle: Publish your offer, guide the order, invoice cleanly, and collect payment inside one connected commercial journey.

### 2. Overview section

#### Real section shape
La section `solution-overview` contient:
- un kicker automatique
- un titre
- un body
- une liste de points
- deux CTA

#### Section goal
Positionner la solution comme une couche commerciale complète, de l’offre publiée jusqu’au revenu encaissé.

#### Source copy
- Title: Structure the offer, the order, and the revenue inside one commercial system
- Body: Commerce & catalog keeps the catalog, storefront, order flow, invoicing, and payment connected so selling stays clear from first selection to collected revenue.

#### FR final
- Title: Structurez l’offre, la commande, et le revenu dans un même système commercial
- Body: Commerce & catalogue relie catalogue, boutique, commande, facture, et paiement pour que la vente reste lisible du premier choix client jusqu’au revenu encaissé.

#### EN final
- Title: Structure the offer, the order, and the revenue inside one commercial system
- Body: Commerce & catalog keeps the catalog, storefront, order flow, invoicing, and payment connected so selling stays clear from first selection to collected revenue.

#### Recommended items
- FR:
  - Catalogue produits et services clair
  - Parcours de commande guidé et cohérent
  - Facturation reliée à la transaction
  - Paiements et suivi du revenu au même endroit
- EN:
  - Clear product and service catalog
  - Guided and consistent order flow
  - Invoicing tied to the transaction
  - Payments and revenue follow-through in one place

### 3. Workflow section

#### Real section shape
La section `solution-workflow` contient:
- un kicker automatique
- un titre
- un body
- une liste d’étapes

#### Section goal
Montrer une chaîne commerciale concrète, de l’offre publiée jusqu’à l’encaissement, avec moins de rupture entre la vente et le revenu.

#### Source copy
- Title: Move from published offer to collected revenue without breaking the flow
- Body: The team structures the offer, opens the sale, generates the order or invoice, and follows payment without re-entering the same context at each step.

#### FR final
- Title: Passez de l’offre publiée au revenu encaissé sans casser le flux
- Body: L’équipe structure l’offre, ouvre la vente, génère la commande ou la facture, puis suit l’encaissement sans ressaisir le même contexte à chaque étape.

#### EN final
- Title: Move from published offer to collected revenue without breaking the flow
- Body: The team structures the offer, opens the sale, generates the order or invoice, and follows payment without re-entering the same context at each step.

#### Recommended items
- FR:
  - 1. Publier les produits, services, et prix
  - 2. Ouvrir la vente via la boutique ou l’équipe
  - 3. Générer la commande ou la facture
  - 4. Suivre l’encaissement et la finalisation
- EN:
  - 1. Publish products, services, and pricing
  - 2. Open the sale through the storefront or the team
  - 3. Generate the order or invoice
  - 4. Track payment and completion

### 4. Modules section

#### Real section shape
La section `solution-modules` contient:
- un kicker automatique
- un titre
- un body
- une liste de modules/pages

#### Section goal
Montrer que la solution active les bons espaces produit pour faire tenir ensemble offre, commande, facture, et paiement.

#### Source copy
- Title: The workspaces that keep catalog, sale, and collection inside the same commercial thread
- Body: The solution brings together the pages teams use to publish the offer, guide the purchase, generate billing, and keep collection tied to the original transaction.

#### FR final
- Title: Les espaces qui gardent catalogue, vente, et encaissement dans le même fil commercial
- Body: La solution réunit les pages qui permettent de présenter l’offre, guider l’achat, structurer la facturation, et garder le paiement relié à la transaction d’origine.

#### EN final
- Title: The workspaces that keep catalog, sale, and collection inside the same commercial thread
- Body: The solution brings together the pages teams use to publish the offer, guide the purchase, generate billing, and keep collection tied to the original transaction.

#### Recommended items
- FR:
  - Produits
  - Services
  - Boutique
  - Commandes
  - Factures
  - Paiements
- EN:
  - Products
  - Services
  - Storefront
  - Orders
  - Invoices
  - Payments

## Final reminders for implementation
- ne pas changer la structure de la page
- ne pas ajouter de nouvelles sections
- ne pas surpromettre des capacités non visibles dans le produit
- éviter un ton trop “e-commerce générique”
- garder le lien clair entre expérience client, facturation, et revenu collecté

## Status
Phase 14 est maintenant documentée et prête pour application future au contenu live local.
