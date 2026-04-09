# Malikia Pro Website Copy - Phase 4 Sales & CRM

Dernière mise à jour: 2026-04-09

## Purpose
Ce document couvre la `Phase 4 - sales-crm` du chantier copywriting.

Objectif:
- auditer la page `sales-crm` actuellement visible
- renforcer le positionnement commercial du module
- remplacer les formulations internes ou hors-sujet par un vrai discours pipeline, devis, suivi, et conversion
- produire une base `source + FR + EN` directement applicable plus tard sans changer la structure

Ce document s'appuie sur:
- [MALIKIA_PRO_WEBSITE_COPY_PHASE_0_EDITORIAL_FOUNDATION.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASE_0_EDITORIAL_FOUNDATION.md)
- [MALIKIA_PRO_WEBSITE_COPY_PHASE_1_WELCOME.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASE_1_WELCOME.md)
- [MALIKIA_PRO_WEBSITE_COPY_PHASE_2_PRICING.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASE_2_PRICING.md)
- [MALIKIA_PRO_WEBSITE_COPY_PHASE_3_CONTACT_US.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASE_3_CONTACT_US.md)
- [MALIKIA_PRO_WEBSITE_COPY_PHASED_USER_STORY.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASED_USER_STORY.md)

## Real page structure
La page `sales-crm` rend actuellement les zones suivantes, dans cet ordre:
1. Hero aligne sur le front public
2. `sales-crm-flow` en `layout: feature_tabs`
3. `sales-crm-cta` en `layout: showcase_cta`
4. `sales-crm-proof` en `layout: story_grid`

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
- l'image hero dediee a `sales-crm`

### Sales flow
La section `sales-crm-flow` est une section `feature_tabs` avec:
- un kicker
- un titre
- un body introductif
- quatre onglets principaux
- plusieurs sous-elements par onglet avec titre, body, image et CTA

Les quatre onglets principaux actuellement visibles sont:
- `Se faire remarquer`
- `Gagner des jobs`
- `Travailler mieux`
- `Booster les profits`

### CTA block
La section `sales-crm-cta` est une section `showcase_cta` avec:
- kicker
- titre
- body
- badge de section
- CTA principal
- CTA secondaire
- lien editorial secondaire

### Proof block
La section `sales-crm-proof` est une section `story_grid` avec:
- un titre de section
- trois cartes de preuve avec image, titre, et body

## Audit

### What already works
- la page a déjà une structure assez solide pour raconter un parcours commercial complet
- la section `feature_tabs` permet de montrer plusieurs etapes du cycle commercial sans casser la lecture
- les sous-elements parlent déjà de capture, devis, relances, planning, et facturation
- le CTA block peut très bien faire le pont vers solution, pricing, et modules connexes

### Main weaknesses
- le hero reste encore trop descriptif et pas assez oriente resultat commercial
- le titre actuel `La solution tout-en-un pour les pros du service a domicile` est trop large et trop générique
- l'onglet `Se faire remarquer` tire la page vers le marketing plus que vers la conversion commerciale
- `sales-crm-cta` contient encore un texte interne de chantier:
  - `Sales & CRM suit maintenant le même format narratif que les autres pages modules`
- `sales-crm-proof` parle actuellement d'IA pour entreprises de terrain, ce qui n'est pas le bon angle principal pour cette page
- la page ne dit pas encore assez clairement qu'elle aide a faire avancer une opportunite de la demande au devis approuve

### Phase 4 decisions
- la page `sales-crm` doit parler d'abord de demande, qualification, devis, suivi, et conversion
- le mot `CRM` doit rester concret et operationnel, pas abstrait
- le module doit être presente comme un espace de revenus et de suivi commercial, pas seulement comme une base client
- le CTA block doit pousser vers l'action commerciale et le choix de la bonne suite
- la section de preuve doit renforcer la vitesse, la structure, et la visibilité, pas deriver vers un discours IA trop large

## Message architecture for sales-crm

### Primary message
Centralisez vos demandes, vos devis, vos fiches clients, et vos suivis commerciaux dans un même espace pour convertir plus vite sans perdre le contexte.

### Supporting messages
- repondre plus vite a une demande entrante
- construire des devis plus propres et plus cohérents
- garder une vision claire du pipeline et des relances
- faire avancer l'opportunite vers le travail approuve puis la facturation

### CTA strategy
- flow section: CTA modules et solutions directement lies a la conversion
- CTA block: `Voir la solution Vente & devis` en principal, `Voir les tarifs` en secondaire
- preuve: pas besoin d'ajouter un nouveau CTA si la structure ne le porte pas

## Editorial guardrails

### Keep the page sales-led
Le coeur de la page doit rester:
- demandes entrantes
- qualification
- devis
- relances
- pipeline
- conversion

Pas:
- un discours IA trop general
- un message de terrain trop dominant
- un inventaire de fonctions sans fil narratif

### Avoid internal or migration language
Ne pas publier de formulations comme:
- `même format narratif`
- `la page raconte mieux`
- `module pages`
- toute phrase qui revele le chantier de refonte

### Keep CRM language practical
`CRM` doit signifier:
- contexte client partage
- historique accessible
- opportunites visibles
- suivi commercial plus propre

Pas un jargon logiciel detache du quotidien.

## Section copy

### 1. Hero

#### Section goal
Faire comprendre tout de suite que Sales & CRM aide a transformer une demande entrante en devis approuve avec plus de rapidite, de structure, et de suivi.

#### Source copy
- Title: Centralize demand, quotes, and customer follow-up in one sales workspace
- Subtitle: Malikia Pro helps your team capture inbound requests, build cleaner quotes, track every opportunity, and keep follow-up moving without losing context between tools.

#### FR final
- Title: Centralisez les demandes, les devis, et le suivi client dans un même espace commercial
- Subtitle: Malikia Pro aide votre equipe a capter les demandes entrantes, préparer des devis plus propres, suivre chaque opportunite, et faire avancer les relances sans perdre le contexte entre plusieurs outils.

#### EN final
- Title: Centralize demand, quotes, and customer follow-up in one sales workspace
- Subtitle: Malikia Pro helps your team capture inbound requests, build cleaner quotes, track every opportunity, and keep follow-up moving without losing context between tools.

### 2. Sales flow

#### Real section shape
La section `sales-crm-flow` contient:
- un kicker
- un titre
- un body
- quatre onglets principaux
- plusieurs sous-cartes par onglet

#### Section goal
Montrer le cycle commercial complet avec une logique très lisible:
- attirer la demande
- la qualifier
- la convertir en devis
- suivre l'opportunite
- la faire avancer vers le travail puis le revenu

#### Source copy
- Kicker: One system across the full sales workflow
- Title: Turn inbound demand into approved work with less friction
- Body: Sales & CRM keeps request capture, qualification, quoting, customer context, and follow-up connected so your team can move faster without losing track of the next step.

#### FR final
- Kicker: Un systeme sur tout le workflow commercial
- Title: Transformez la demande entrante en travail approuve avec moins de friction
- Body: Sales & CRM garde la capture de demande, la qualification, le devis, le contexte client, et le suivi commercial dans un même flux pour que l'equipe avance plus vite sans perdre la prochaine action.

#### EN final
- Kicker: One system across the full sales workflow
- Title: Turn inbound demand into approved work with less friction
- Body: Sales & CRM keeps request capture, qualification, quoting, customer context, and follow-up connected so your team can move faster without losing track of the next step.

#### Recommended tab narrative

##### Tab 1
- Source label: Capture demand
- Source title: Make it easier for the right prospects to reach you
- Source body: Bring inbound forms, online requests, reviews, and first-response workflows into one connected intake layer so demand starts clean and visible.
- Source CTA: Explore Marketing & Loyalty

- FR label: Capter la demande
- FR title: Facilitez l'entree des bonnes demandes dans votre pipeline
- FR body: Rassemblez formulaires, demandes web, avis, et premiers messages dans une même couche d'acquisition pour que la demande arrive plus proprement et reste visible.
- FR CTA: Explorer Marketing & Loyalty

- EN label: Capture demand
- EN title: Make it easier for the right prospects to reach you
- EN body: Bring inbound forms, online requests, reviews, and first-response workflows into one connected intake layer so demand starts clean and visible.
- EN CTA: Explore Marketing & Loyalty

##### Tab 2
- Source label: Quote and follow up
- Source title: Move faster from request to quote without losing the customer context
- Source body: Qualify the request, build the quote, add options, and keep follow-up moving from one commercial workspace instead of scattered notes and inboxes.
- Source CTA: Explore Sales & CRM

- FR label: Devis et relance
- FR title: Passez plus vite de la demande au devis sans perdre le contexte client
- FR body: Qualifiez la demande, préparez le devis, ajoutez des options, et faites avancer le suivi depuis un même espace commercial au lieu de disperser l'information entre notes et boîtes mail.
- FR CTA: Explorer Sales & CRM

- EN label: Quote and follow up
- EN title: Move faster from request to quote without losing the customer context
- EN body: Qualify the request, build the quote, add options, and keep follow-up moving from one commercial workspace instead of scattered notes and inboxes.
- EN CTA: Explore Sales & CRM

##### Tab 3
- Source label: Coordinate delivery
- Source title: Hand off approved work to operations with less confusion
- Source body: Once the opportunity is approved, scheduling, job details, assignments, and field execution can continue from the same operating context.
- Source CTA: Explore Operations

- FR label: Coordonner l'execution
- FR title: Transmettez le travail approuve aux operations avec moins de confusion
- FR body: Une fois l'opportunite approuvee, le planning, les details du job, les affectations, et l'execution terrain peuvent continuer depuis le même contexte operationnel.
- FR CTA: Explorer Operations

- EN label: Coordinate delivery
- EN title: Hand off approved work to operations with less confusion
- EN body: Once the opportunity is approved, scheduling, job details, assignments, and field execution can continue from the same operating context.
- EN CTA: Explore Operations

##### Tab 4
- Source label: Protect revenue
- Source title: Turn approved work into invoicing and payments with better visibility
- Source body: Keep billing, reminders, payment collection, and revenue tracking connected to the original request so the commercial cycle ends cleanly.
- Source CTA: Explore Commerce

- FR label: Proteger le revenu
- FR title: Transformez le travail approuve en facturation et paiements avec plus de visibilité
- FR body: Gardez la facturation, les rappels, l'encaissement, et le suivi du revenu relies a la demande d'origine pour que le cycle commercial se termine proprement.
- FR CTA: Explorer Commerce

- EN label: Protect revenue
- EN title: Turn approved work into invoicing and payments with better visibility
- EN body: Keep billing, reminders, payment collection, and revenue tracking connected to the original request so the commercial cycle ends cleanly.
- EN CTA: Explore Commerce

### 3. CTA block

#### Real section shape
La section `sales-crm-cta` rend:
- kicker
- titre
- body
- badge
- CTA principal
- CTA secondaire
- lien secondaire editorial

#### Section goal
Donner une prochaine etape claire a un prospect qui a compris la valeur du module et veut maintenant soit voir la solution plus complete, soit verifier le pricing.

#### Source copy
- Kicker: Ready to structure conversion
- Title: Start converting more of the demand you already generate
- Body: Replace fragmented intake, quoting, and follow-up with one commercial workspace that helps your team stay faster, more consistent, and easier to manage from first contact to approved work.
- Badge label: Module
- Badge value: Sales & CRM
- Badge note: Intake, quoting, and follow-up in one connected flow
- Primary CTA: See the Sales & Quoting solution
- Secondary CTA: View pricing
- Aside link: See Command Center

#### FR final
- Kicker: Pret a mieux structurer la conversion
- Title: Convertissez davantage la demande que vous generee déjà
- Body: Remplacez une capture de demande, des devis, et des relances fragmentes par un même espace commercial qui aide votre equipe a aller plus vite, a rester plus cohérente, et a mieux piloter le passage du premier contact au travail approuve.
- Badge label: Module
- Badge value: Sales & CRM
- Badge note: Capture, devis, et relance dans un même flux connecte
- Primary CTA: Voir la solution Vente & devis
- Secondary CTA: Voir les tarifs
- Aside link: Voir Command Center

#### EN final
- Kicker: Ready to structure conversion
- Title: Start converting more of the demand you already generate
- Body: Replace fragmented intake, quoting, and follow-up with one commercial workspace that helps your team stay faster, more consistent, and easier to manage from first contact to approved work.
- Badge label: Module
- Badge value: Sales & CRM
- Badge note: Intake, quoting, and follow-up in one connected flow
- Primary CTA: See the Sales & Quoting solution
- Secondary CTA: View pricing
- Aside link: See Command Center

### 4. Proof block

#### Real section shape
La section `sales-crm-proof` est une `story_grid` avec trois cartes.

#### Section goal
Renforcer la confiance avec des preuves courtes et concretes autour du pipeline, du devis, et du suivi commercial.

#### Source copy
- Section title: Built for clearer pipelines and faster quote turnaround

##### Card 1
- Title: Keep every opportunity visible
- Body: Give the team one shared view of incoming requests, status changes, next actions, and deal movement so fewer opportunities go cold.

##### Card 2
- Title: Quote with more consistency
- Body: Reuse customer context, services, options, and sales logic to send cleaner proposals without rebuilding the same work every time.

##### Card 3
- Title: Follow up without losing momentum
- Body: Keep reminders, messages, and handoffs tied to the same customer record so the next step stays obvious until the work is approved.

#### FR final
- Section title: Un pipeline plus clair et des devis plus rapides a faire avancer

##### Carte 1
- Title: Gardez chaque opportunite visible
- Body: Donnez a l'equipe une vue partagee des demandes entrantes, des changements de statut, des prochaines actions, et de l'avancement du pipeline pour laisser moins d'opportunites se refroidir.

##### Carte 2
- Title: Devis plus cohérents, plus faciles a envoyer
- Body: Reutilisez le contexte client, les services, les options, et la logique commerciale pour envoyer des propositions plus propres sans refaire le même travail a chaque fois.

##### Carte 3
- Title: Relancez sans perdre l'elan commercial
- Body: Gardez rappels, messages, et handoffs relies a la même fiche client pour que la prochaine action reste evidente jusqu'a l'approbation du travail.

#### EN final
- Section title: Built for clearer pipelines and faster quote turnaround

##### Card 1
- Title: Keep every opportunity visible
- Body: Give the team one shared view of incoming requests, status changes, next actions, and deal movement so fewer opportunities go cold.

##### Card 2
- Title: Quote with more consistency
- Body: Reuse customer context, services, options, and sales logic to send cleaner proposals without rebuilding the same work every time.

##### Card 3
- Title: Follow up without losing momentum
- Body: Keep reminders, messages, and handoffs tied to the same customer record so the next step stays obvious until the work is approved.

## Final quality checklist for this page
- le hero doit promettre un resultat commercial clair
- la section `feature_tabs` doit raconter un vrai parcours de conversion
- la page doit rester centree sur demande, devis, suivi, et conversion
- aucun texte ne doit sonner comme une note interne de refonte
- la section de preuve doit renforcer la valeur commerciale, pas faire sortir la page de son sujet
- les CTA doivent ouvrir une suite logique vers solution, pricing, ou module connexe

## Recommendation before live application
Avant application live de cette phase:
- verifier si le titre du module doit rester `Sales & CRM` dans les deux langues ou evoluer editorialement
- verifier les labels des sous-onglets pour garder une bonne cohérence avec les autres pages modules
- verifier les CTA de chaque sous-element pour qu'ils pointent vers les bonnes pages publiques

