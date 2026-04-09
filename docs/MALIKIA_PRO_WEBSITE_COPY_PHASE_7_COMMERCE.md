# Malikia Pro Website Copy - Phase 7 Commerce

Dernière mise à jour: 2026-04-09

## Purpose
Ce document couvre la `Phase 7 - commerce` du chantier copywriting.

Objectif:
- auditer la page `commerce` actuellement visible
- renforcer le positionnement du module autour du catalogue, de la commande, de la facturation, et de l'encaissement
- remplacer les formulations de chantier interne par un vrai copy produit oriente vente, continuite commerciale, et revenu
- produire une base `source + FR + EN` directement applicable plus tard sans changer la structure

Ce document s'appuie sur:
- [MALIKIA_PRO_WEBSITE_COPY_PHASE_0_EDITORIAL_FOUNDATION.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASE_0_EDITORIAL_FOUNDATION.md)
- [MALIKIA_PRO_WEBSITE_COPY_PHASE_1_WELCOME.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASE_1_WELCOME.md)
- [MALIKIA_PRO_WEBSITE_COPY_PHASE_2_PRICING.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASE_2_PRICING.md)
- [MALIKIA_PRO_WEBSITE_COPY_PHASE_3_CONTACT_US.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASE_3_CONTACT_US.md)
- [MALIKIA_PRO_WEBSITE_COPY_PHASE_4_SALES_CRM.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASE_4_SALES_CRM.md)
- [MALIKIA_PRO_WEBSITE_COPY_PHASE_5_OPERATIONS.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASE_5_OPERATIONS.md)
- [MALIKIA_PRO_WEBSITE_COPY_PHASE_6_RESERVATIONS.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASE_6_RESERVATIONS.md)
- [MALIKIA_PRO_WEBSITE_COPY_PHASED_USER_STORY.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASED_USER_STORY.md)

## Real page structure
La page `commerce` rend actuellement les zones suivantes, dans cet ordre:
1. Hero aligne sur le front public
2. `commerce-flow` en `layout: feature_tabs`
3. `commerce-cta` en `layout: showcase_cta`
4. `commerce-proof` en `layout: story_grid`

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
- l'image hero dediee a `commerce`

### Commerce flow
La section `commerce-flow` est une section `feature_tabs` avec:
- un kicker
- un titre
- un body introductif
- quatre onglets principaux

Les quatre onglets principaux actuellement visibles sont:
- `Catalogue visible`
- `Commande guidee`
- `Facture sans rupture`
- `Encaissement protege`

### CTA block
La section `commerce-cta` est une section `showcase_cta` avec:
- kicker
- titre
- body
- badge
- CTA principal
- CTA secondaire
- lien editorial secondaire

### Proof block
La section `commerce-proof` est une section `story_grid` avec:
- kicker
- titre
- body
- trois cartes de preuve visuelle

## Audit

### What already works
- la page a déjà une bonne logique de chaine commerciale complete
- les quatre temps visibles sont bien choisis: offre, commande, facture, encaissement
- les images soutiennent bien le cote catalogue, vente, et paiement
- les liens vers `solution-commerce-catalog`, `pricing`, et `command-center` donnent de bonnes continuations

### Main weaknesses
- plusieurs textes actuels parlent encore de la page ou de la refonte au lieu de parler du produit
- le titre `Suivez le module par etapes visibles plutot que par menu` reste trop meta
- le CTA block parle encore du format de redesign public
- la preuve actuelle parle surtout de lisibilite de module, pas assez de continuite commerciale et transactionnelle
- la page doit mieux faire sentir que Malikia Pro n'est pas seulement un catalogue ou un add-on paiement, mais une vraie couche commerce connectee

### Phase 7 decisions
- la page `commerce` doit parler d'offre, commande, facturation, et encaissement comme un même flux
- le catalogue doit être positionne comme une porte d'entree commerciale, pas comme un simple back-office
- la page doit rassurer autant sur l'experience client que sur la visibilité revenu cote equipe
- le CTA block doit pousser vers la solution plus detaillee ou le pricing
- la preuve doit parler de continuite commerciale, de confiance, et de monetisation plus propre

## Message architecture for commerce

### Primary message
Vendez produits et services, emettez les factures, et encaissez dans un même parcours commercial connecte.

### Supporting messages
- une offre plus claire a presenter
- une commande plus lisible a faire avancer
- une facture qui repart du bon contexte
- un encaissement relie a la transaction d'origine

### CTA strategy
- flow section: CTA vers `solution-commerce-catalog`, `command-center`, et `commerce`
- CTA block: `Voir les tarifs` en principal, `Voir la solution commerce & catalogue` en secondaire
- proof block: pas besoin d'ajouter un nouveau CTA si la structure ne le porte pas

## Editorial guardrails

### Keep the page commercial, not technical
Le coeur de la page doit rester:
- catalogue
- commande
- facture
- paiement
- revenu

Pas:
- une description de menu
- une note de refonte
- un discours purement administratif

### Do not describe the page, describe the buying flow
Ne pas publier de formulations comme:
- `la page clarifie`
- `format de refonte`
- `etapes visibles plutot que par menu`

Le texte doit toujours parler de ce que le client et l'equipe vivent dans la vente et l'encaissement.

### Keep catalog and payment in one story
Le module doit faire sentir que:
- l'offre est claire
- la commande suit logiquement
- la facture reprend le bon contexte
- l'encaissement clôture proprement la vente

## Section copy

### 1. Hero

#### Section goal
Faire comprendre très vite que `Commerce` aide a presenter l'offre, vendre, facturer, et encaisser sans casser le parcours client.

#### Source copy
- Title: Sell products and services, issue invoices, and collect payments in one connected customer journey
- Subtitle: Malikia Pro helps businesses present their offer clearly, guide customers through purchase, and keep invoicing and payment tied to the original transaction.

#### FR final
- Title: Vendez produits et services, emettez les factures, et encaissez dans un même parcours client connecte
- Subtitle: Malikia Pro aide les entreprises a presenter leur offre clairement, guider le client dans l'achat, et garder la facturation comme le paiement relies a la transaction d'origine.

#### EN final
- Title: Sell products and services, issue invoices, and collect payments in one connected customer journey
- Subtitle: Malikia Pro helps businesses present their offer clearly, guide customers through purchase, and keep invoicing and payment tied to the original transaction.

### 2. Commerce flow

#### Real section shape
La section `commerce-flow` contient:
- un kicker
- un titre
- un body
- quatre onglets principaux

#### Section goal
Montrer que le module n'est pas une juxtaposition de catalogue, boutique, et paiement, mais une vraie chaine commerciale continue.

#### Source copy
- Kicker: One commerce workflow from catalog to collection
- Title: Turn your catalog into revenue without fragmenting the experience
- Body: Commerce connects offer visibility, guided ordering, invoicing, and payment collection so the sale stays cohérent from first click to collected revenue.

#### FR final
- Kicker: Un workflow commerce du catalogue a l'encaissement
- Title: Transformez votre catalogue en revenu sans fragmenter l'experience
- Body: Commerce relie visibilité de l'offre, commande guidee, facturation, et encaissement pour que la vente reste cohérente du premier clic jusqu'au revenu collecte.

#### EN final
- Kicker: One commerce workflow from catalog to collection
- Title: Turn your catalog into revenue without fragmenting the experience
- Body: Commerce connects offer visibility, guided ordering, invoicing, and payment collection so the sale stays cohérent from first click to collected revenue.

#### Recommended tab narrative

##### Tab 1
- Source label: Visible catalog
- Source title: Make the offer easier to browse and easier to trust
- Source body: Present products, services, and categories in a clearer structure so the customer understands what is available before the order starts.
- Source CTA: See the commerce solution

- FR label: Catalogue visible
- FR title: Rendez l'offre plus simple a parcourir et plus facile a comprendre
- FR body: Presentez produits, services, et categories dans une structure plus claire pour que le client comprenne ce qui est disponible avant même de commander.
- FR CTA: Voir la solution commerce

- EN label: Visible catalog
- EN title: Make the offer easier to browse and easier to trust
- EN body: Present products, services, and categories in a clearer structure so the customer understands what is available before the order starts.
- EN CTA: See the commerce solution

##### Tab 2
- Source label: Guided order
- Source title: Keep the order readable from selection to recap
- Source body: Help the customer and the team move through cart, quantities, and product choices without breaking the commercial flow.
- Source CTA: See the storefront

- FR label: Commande guidee
- FR title: Gardez la commande lisible du choix jusqu'au recapitulatif
- FR body: Aidez le client comme l'equipe a avancer dans le panier, les quantites, et les choix produits sans casser le flux commercial.
- FR CTA: Voir la boutique

- EN label: Guided order
- EN title: Keep the order readable from selection to recap
- EN body: Help the customer and the team move through cart, quantities, and product choices without breaking the commercial flow.
- EN CTA: See the storefront

##### Tab 3
- Source label: Invoice without friction
- Source title: Let invoicing pick up the right context instead of starting over
- Source body: Keep the commercial logic, useful line items, and internal validation tied to the same thread so billing feels like the continuation of the sale.
- Source CTA: See Command Center

- FR label: Facture sans rupture
- FR title: Laissez la facturation repartir du bon contexte au lieu de repartir de zero
- FR body: Gardez la logique commerciale, les lignes utiles, et la validation interne dans le même fil pour que la facture ressemble a la suite naturelle de la vente.
- FR CTA: Voir Command Center

- EN label: Invoice without friction
- EN title: Let invoicing pick up the right context instead of starting over
- EN body: Keep the commercial logic, useful line items, and internal validation tied to the same thread so billing feels like the continuation of the sale.
- EN CTA: See Command Center

##### Tab 4
- Source label: Protected collection
- Source title: Keep payment and revenue visibility tied to the transaction
- Source body: Connect collection, reminders, and revenue tracking to the original sale so invoicing and payment do not drift into separate workflows.
- Source CTA: See Commerce

- FR label: Encaissement protege
- FR title: Gardez paiement et lecture du revenu relies a la transaction
- FR body: Reliez encaissement, rappels, et suivi du revenu a la vente d'origine pour que la facturation et le paiement ne derivent pas dans des workflows separes.
- FR CTA: Voir Commerce

- EN label: Protected collection
- EN title: Keep payment and revenue visibility tied to the transaction
- EN body: Connect collection, reminders, and revenue tracking to the original sale so invoicing and payment do not drift into separate workflows.
- EN CTA: See Commerce

### 3. CTA block

#### Real section shape
La section `commerce-cta` rend:
- kicker
- titre
- body
- badge
- CTA principal
- CTA secondaire
- lien editorial secondaire

#### Section goal
Donner une prochaine etape claire a un prospect qui a compris la valeur du module et veut approfondir la solution ou verifier le pricing.

#### Source copy
- Kicker: Ready to monetize
- Title: Sell, invoice, and collect from one platform
- Body: Replace disconnected storefront, admin, and payment workflows with a system that keeps the commercial journey easier to manage, easier to trust, and easier to monitor from catalog to collected payment.
- Badge label: Module
- Badge value: Commerce
- Badge note: Catalog, order, invoice, and payment in one connected flow
- Primary CTA: View pricing
- Secondary CTA: See the Commerce & Catalog solution
- Aside link: See Command Center

#### FR final
- Kicker: Pret a monetiser plus proprement
- Title: Vendez, facturez, et encaissez depuis une même plateforme
- Body: Remplacez une boutique, une administration, et des workflows de paiement deconnectes par un systeme qui rend le parcours commercial plus simple a gerer, plus facile a faire confiance, et plus lisible du catalogue jusqu'au paiement collecte.
- Badge label: Module
- Badge value: Commerce
- Badge note: Catalogue, commande, facture, et paiement dans un même flux connecte
- Primary CTA: Voir les tarifs
- Secondary CTA: Voir la solution commerce & catalogue
- Aside link: Voir Command Center

#### EN final
- Kicker: Ready to monetize
- Title: Sell, invoice, and collect from one platform
- Body: Replace disconnected storefront, admin, and payment workflows with a system that keeps the commercial journey easier to manage, easier to trust, and easier to monitor from catalog to collected payment.
- Badge label: Module
- Badge value: Commerce
- Badge note: Catalog, order, invoice, and payment in one connected flow
- Primary CTA: View pricing
- Secondary CTA: See the Commerce & Catalog solution
- Aside link: See Command Center

### 4. Proof block

#### Real section shape
La section `commerce-proof` est une `story_grid` avec trois cartes.

#### Section goal
Renforcer la confiance avec des preuves courtes autour de l'offre, de la logistique commerciale, et de l'encaissement.

#### Source copy
- Kicker: Commercial continuity
- Title: Built for businesses that want better commercial continuity
- Body: Keep the sale connected from first click to collected payment so the catalog, the order, the invoice, and the revenue feel like one commercial system instead of disconnected tools.

##### Card 1
- Title: The catalog becomes a clearer commercial entry point
- Body: Structure the offer so the customer understands faster what can be bought, booked, or added before the transaction starts.

##### Card 2
- Title: Logistics stays connected to the sale
- Body: Keep stock, preparation, and fulfillment visible in the same story so the team does not manage revenue separately from delivery.

##### Card 3
- Title: Revenue feels like the natural continuation of the order
- Body: Let invoicing and collection close the loop so payment does not feel disconnected from the original purchase.

#### FR final
- Kicker: Continuite commerciale
- Title: Conçu pour les entreprises qui veulent une chaine commerciale plus propre
- Body: Gardez la vente reliee du premier clic jusqu'au paiement collecte pour que catalogue, commande, facture, et revenu ressemblent a un même systeme commercial plutot qu'a des outils deconnectes.

##### Carte 1
- Title: Le catalogue redevient une vraie porte d'entree commerciale
- Body: Structurez l'offre pour que le client comprenne plus vite ce qu'il peut acheter, reserver, ou ajouter avant même que la transaction commence.

##### Carte 2
- Title: La logistique reste reliee a la vente
- Body: Gardez stock, preparation, et execution visibles dans la même histoire pour que l'equipe ne pilote pas le revenu a part de la livraison.

##### Carte 3
- Title: Le revenu devient la suite naturelle de la commande
- Body: Laissez facture et encaissement fermer la boucle pour que le paiement ne paraisse pas deconnecte de l'achat d'origine.

#### EN final
- Kicker: Commercial continuity
- Title: Built for businesses that want better commercial continuity
- Body: Keep the sale connected from first click to collected payment so the catalog, the order, the invoice, and the revenue feel like one commercial system instead of disconnected tools.

##### Card 1
- Title: The catalog becomes a clearer commercial entry point
- Body: Structure the offer so the customer understands faster what can be bought, booked, or added before the transaction starts.

##### Card 2
- Title: Logistics stays connected to the sale
- Body: Keep stock, preparation, and fulfillment visible in the same story so the team does not manage revenue separately from delivery.

##### Card 3
- Title: Revenue feels like the natural continuation of the order
- Body: Let invoicing and collection close the loop so payment does not feel disconnected from the original purchase.

## Final quality checklist for this page
- le hero doit faire sentir un vrai parcours commercial connecte
- la section `feature_tabs` doit raconter une chaine catalogue -> commande -> facture -> paiement
- aucun texte ne doit parler de refonte ou de lisibilite de page
- la page doit rassurer autant sur l'experience client que sur la lecture revenu cote equipe
- la preuve doit rester orientee continuite commerciale et transactionnelle
- les CTA doivent mener naturellement vers solution, pricing, ou pilotage global

## Recommendation before live application
Avant application live de cette phase:
- verifier si `Commerce` reste le bon nom editorial dans les deux langues
- verifier les CTA de chaque onglet pour s'assurer qu'ils pointent vers les pages publiques les plus pertinentes
- verifier si `Command Center` est bien le meilleur lien secondaire pour cette page

