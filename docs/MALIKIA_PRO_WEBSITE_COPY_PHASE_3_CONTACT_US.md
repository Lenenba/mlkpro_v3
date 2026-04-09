# Malikia Pro Website Copy - Phase 3 Contact Us

Dernière mise à jour: 2026-04-09

## Purpose
Ce document couvre la `Phase 3 - contact-us` du chantier copywriting.

Objectif:
- auditer la page `contact-us` actuellement visible
- remplacer le ton trop interne / admin par un vrai copy public et commercial
- renforcer la confiance, la clarté, et la qualité perçue du contact
- produire une base `source + FR + EN` directement applicable plus tard sans changer la structure

Ce document s'appuie sur:
- [MALIKIA_PRO_WEBSITE_COPY_PHASE_0_EDITORIAL_FOUNDATION.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASE_0_EDITORIAL_FOUNDATION.md)
- [MALIKIA_PRO_WEBSITE_COPY_PHASE_1_WELCOME.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASE_1_WELCOME.md)
- [MALIKIA_PRO_WEBSITE_COPY_PHASE_2_PRICING.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASE_2_PRICING.md)
- [MALIKIA_PRO_WEBSITE_COPY_PHASED_USER_STORY.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASED_USER_STORY.md)

## Real page structure
La page `contact-us` rend actuellement les zones suivantes, dans cet ordre:
1. Hero aligne sur le front public
2. `contact-overview` en `layout: split`
3. `contact-details` en `layout: contact`

Contrainte:
- aucun changement de structure
- aucun changement de layout
- aucun changement d'image
- aucun ajout de nouvelle section

## Real rendering behavior

### Hero
Le hero est rendu par `PublicFrontHero` avec:
- un eyebrow automatique `Contact` / `Contact us`
- `page_title`
- `page_subtitle`
- une image hero dediee a `contact-us`

### Contact overview
La section `contact-overview` est stockee en `split`, mais elle peut aussi embarquer:
- un formulaire commercial integre
- un CTA primaire vers le formulaire
- un CTA secondaire vers `pricing`

### Contact details
La section `contact-details` est rendue en `layout: contact` avec:
- un bloc principal
- une liste de points rassurants
- un aside de reassurance / coordonnees / disponibilite
- un lien secondaire dans la colonne de droite

### Important runtime note
Si un `lead form URL` est configure, le service hydrate automatiquement:
- `embed_url`
- `embed_title`
- `primary_label`
- `primary_href`

Le copy doit donc rester crédible:
- avec formulaire integre
- ou sans formulaire integre

## Audit

### What already works
- la page offre déjà un point d'entree simple pour un prospect
- la structure est suffisante pour combiner prise de contact et reassurance
- l'aside permet d'ajouter une presence plus humaine et plus concrete
- la page peut accueillir un formulaire commercial sans casser l'experience

### Main weaknesses
- le hero FR garde encore un titre anglais `Contact us`
- plusieurs textes parlent de l'admin Pages, de configuration editable, ou de remplacement de blocs
- la page sonne encore comme un setup CMS, pas comme une vraie page commerciale
- le mot `Support` peut brouiller la lecture si l'intention réelle est plutot commerciale
- la page ne dit pas encore assez clairement pourquoi contacter Malikia Pro et dans quels cas
- les promesses de delai ou de disponibilite doivent rester realistes et verifiables

### Phase 3 decisions
- la page `contact-us` doit être une page de contact commercial et d'orientation produit
- le message doit rassurer sur la compréhension des operations réelles
- le formulaire doit être presente comme un moyen rapide de cadrer le besoin
- l'aside doit renforcer la confiance, pas exposer des notes internes
- le copy doit aider le prospect a se projeter dans un prochain pas concret

## Message architecture for contact-us

### Primary message
Parlez-nous de votre activité, de votre workflow, et de vos priorites. Nous vous aidons a identifier la bonne configuration Malikia Pro et la meilleure suite pour votre equipe.

### Supporting messages
- nous comprenons les réalités des operations, pas seulement le logiciel
- la prise de contact peut servir a evaluer le bon plan, le bon workflow, ou le bon rythme de déploiement
- la page doit donner une impression de clarté, de sérieux, et de reponse utile

### CTA strategy
- Hero: pas de CTA structurel a ajouter
- `contact-overview`: CTA primaire oriente formulaire, CTA secondaire vers `pricing`
- `contact-details`: CTA principal plus humain et plus direct
- Aside: lien de contact ou de prise de rendez-vous si un canal réel existe

## Editorial guardrails

### Do not publish internal implementation language
Ne plus utiliser en copy final des formulations comme:
- `modifiable depuis l'admin Pages`
- `remplacez cette colonne`
- `URL editable`
- `point d'entree public`

### Do not promise what the team cannot guarantee
Ne pas figer publiquement:
- un SLA de reponse
- des horaires
- des zones geographiques

si ces informations ne sont pas vraies et tenues operationnellement.

### Keep the page commercial, not support-generic
Si la page est destinee aux prospects, elle doit parler:
- d'evaluation du besoin
- de choix de plan
- de workflow
- d'onboarding

Elle ne doit pas sonner comme une page SAV générique, sauf si c'est vraiment le role voulu.

## Section copy

### 1. Hero

#### Section goal
Faire sentir immédiatement que le prospect peut parler a une equipe serieuse qui comprend les operations et peut l'orienter clairement.

#### Source copy
- Title: Tell us how your business runs today
- Subtitle: Share your workflow, team setup, and the areas where you want more speed, visibility, or control. We will help you evaluate the right Malikia Pro setup for what comes next.

#### FR final
- Title: Parlez-nous de la façon dont votre activité fonctionne aujourd'hui
- Subtitle: Decrivez votre workflow, votre organisation, et les zones ou vous voulez plus de rapidite, de visibilité, ou de contrôle. Nous vous aidons a evaluer la bonne configuration Malikia Pro pour la suite.

#### EN final
- Title: Tell us how your business runs today
- Subtitle: Share your workflow, team setup, and the areas where you want more speed, visibility, or control. We will help you evaluate the right Malikia Pro setup for what comes next.

### 2. Contact overview

#### Real section shape
Cette section peut contenir:
- `kicker`
- `title`
- `body`
- `items`
- `primary_label`
- `secondary_label`
- formulaire integre si disponible

#### Section goal
Encourager un prospect qualifie a donner le bon contexte des le premier contact, sans ajouter de friction inutile.

#### Source copy
- Kicker: Commercial contact
- Title: Start the conversation with the context that matters
- Body: Tell us about your services, team size, workflow, and the points where you need more structure. We will use that context to guide you toward the best Malikia Pro setup for your business.
- Items:
  - Your business model, team size, and operating context
  - The workflow gaps you want to fix first
  - Questions about sales, scheduling, jobs, reservations, invoicing, or coordination
  - The next step you are evaluating: pricing, rollout, or product fit
- Primary CTA: Open the form
- Secondary CTA: View pricing
- Embed title if shown: Commercial inquiry form

#### FR final
- Kicker: Contact commercial
- Title: Commencez la conversation avec le bon contexte
- Body: Parlez-nous de vos services, de la taille de votre equipe, de votre façon de travailler, et des points ou vous voulez plus de structure. Nous utiliserons ce contexte pour vous orienter vers la configuration Malikia Pro la plus adaptee a votre activité.
- Items:
  - Votre modele d'activité, la taille de l'equipe, et le contexte operationnel
  - Les points de friction que vous voulez regler en priorite
  - Vos questions sur les devis, le planning, les jobs, les reservations, la facturation, ou la coordination
  - Le prochain sujet que vous evaluez: tarification, déploiement, ou adéquation produit
- Primary CTA: Ouvrir le formulaire
- Secondary CTA: Voir les tarifs
- Embed title if shown: Formulaire de demande commerciale

#### EN final
- Kicker: Commercial contact
- Title: Start the conversation with the context that matters
- Body: Tell us about your services, team size, workflow, and the points where you need more structure. We will use that context to guide you toward the best Malikia Pro setup for your business.
- Items:
  - Your business model, team size, and operating context
  - The workflow gaps you want to fix first
  - Questions about sales, scheduling, jobs, reservations, invoicing, or coordination
  - The next step you are evaluating: pricing, rollout, or product fit
- Primary CTA: Open the form
- Secondary CTA: View pricing
- Embed title if shown: Commercial inquiry form

### 3. Contact details

#### Real section shape
Cette section rend:
- un bloc principal avec titre, body, liste, et CTA
- un aside avec `aside_kicker`, `aside_title`, `aside_body`, `aside_items`, et `aside_link_label`

#### Section goal
Renforcer la confiance, humaniser la prise de contact, et rassurer les prospects qui veulent parler a une vraie equipe avant d'aller plus loin.

#### Source copy
- Kicker: Talk to our team
- Title: Get guidance from people who understand operational workflows
- Body: If you want to validate fit before going deeper, reach out and we will help you understand where Malikia Pro can support your sales flow, coordination, scheduling, reservations, field work, and revenue operations.
- Items:
  - Help evaluating fit and operational scope
  - Guidance on rollout, onboarding, and next steps
  - Commercial follow-up for qualified requests
- Primary CTA: Contact our team

##### Aside
- Kicker: Sales desk
- Title: A commercial team focused on fit, rollout, and next steps
- Body: Reach out if you need help choosing a plan, evaluating your workflow, or understanding how Malikia Pro can support a more structured operation. If you publish direct contact details or office hours here, make sure they reflect your real availability.
- Items:
  - Relevant for new prospects, pricing questions, and rollout discussions
  - Suitable for businesses operating across service workflows with growing coordination needs
- Aside CTA: Contact our team

#### FR final
- Kicker: Parler a l'equipe
- Title: Obtenez des reponses aupres d'une equipe qui comprend les workflows operationnels
- Body: Si vous voulez valider l'adéquation avant d'aller plus loin, contactez-nous et nous vous aiderons a comprendre ou Malikia Pro peut soutenir vos ventes, votre coordination, votre planning, vos reservations, votre execution, et vos operations de revenus.
- Items:
  - Aide pour evaluer l'adéquation produit et le perimetre operationnel
  - Orientation sur le déploiement, l'onboarding, et les prochaines etapes
  - Suivi commercial pour les demandes qualifiees
- Primary CTA: Nous contacter

##### Aside
- Kicker: Equipe commerciale
- Title: Une equipe orientee adéquation, déploiement, et prochaines etapes
- Body: Contactez-nous si vous avez besoin d'aide pour choisir un plan, evaluer votre workflow, ou comprendre comment Malikia Pro peut soutenir une operation plus structuree. Si vous affichez ici des coordonnees directes ou des horaires, ils doivent refleter votre disponibilite réelle.
- Items:
  - Pertinent pour les nouveaux prospects, les questions tarifaires, et les echanges sur le déploiement
  - Adapte aux entreprises qui gerent plusieurs flux de service et plus de coordination au quotidien
- Aside CTA: Nous contacter

#### EN final
- Kicker: Talk to our team
- Title: Get guidance from people who understand operational workflows
- Body: If you want to validate fit before going deeper, reach out and we will help you understand where Malikia Pro can support your sales flow, coordination, scheduling, reservations, field work, and revenue operations.
- Items:
  - Help evaluating fit and operational scope
  - Guidance on rollout, onboarding, and next steps
  - Commercial follow-up for qualified requests
- Primary CTA: Contact our team

##### Aside
- Kicker: Sales desk
- Title: A commercial team focused on fit, rollout, and next steps
- Body: Reach out if you need help choosing a plan, evaluating your workflow, or understanding how Malikia Pro can support a more structured operation. If you publish direct contact details or office hours here, make sure they reflect your real availability.
- Items:
  - Relevant for new prospects, pricing questions, and rollout discussions
  - Suitable for businesses operating across service workflows with growing coordination needs
- Aside CTA: Contact our team

## Final quality checklist for this page
- le hero doit sonner comme une invitation serieuse, pas comme une page vide a remplir
- `contact-overview` doit aider le prospect a savoir quoi partager
- `contact-details` doit renforcer la confiance humaine et commerciale
- aucun texte public ne doit parler de CMS, admin, ou blocs editables
- les promesses de reponse et de disponibilite doivent rester vraies
- la page doit rester compatible avec la logique de formulaire embarque quand elle est active

## Recommendation before live application
Avant application live de cette phase:
- verifier le vrai canal de contact a mettre en avant
- verifier si `contact-us` est une page commerciale, support, ou hybride
- verifier les délais de reponse et horaires reels
- verifier si le lien primaire doit ouvrir un formulaire, une prise de rendez-vous, ou un canal direct

