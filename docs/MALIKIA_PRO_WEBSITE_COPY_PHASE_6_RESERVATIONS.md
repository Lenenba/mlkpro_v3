# Malikia Pro Website Copy - Phase 6 Reservations

Dernière mise à jour: 2026-04-09

## Purpose
Ce document couvre la `Phase 6 - reservations` du chantier copywriting.

Objectif:
- auditer la page `reservations` actuellement visible
- renforcer le positionnement du module autour du parcours client, des confirmations, de l'accueil, et du suivi
- remplacer les formulations de chantier interne par un vrai copy produit et orienté experience
- produire une base `source + FR + EN` directement applicable plus tard sans changer la structure

Ce document s'appuie sur:
- [MALIKIA_PRO_WEBSITE_COPY_PHASE_0_EDITORIAL_FOUNDATION.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASE_0_EDITORIAL_FOUNDATION.md)
- [MALIKIA_PRO_WEBSITE_COPY_PHASE_1_WELCOME.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASE_1_WELCOME.md)
- [MALIKIA_PRO_WEBSITE_COPY_PHASE_2_PRICING.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASE_2_PRICING.md)
- [MALIKIA_PRO_WEBSITE_COPY_PHASE_3_CONTACT_US.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASE_3_CONTACT_US.md)
- [MALIKIA_PRO_WEBSITE_COPY_PHASE_4_SALES_CRM.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASE_4_SALES_CRM.md)
- [MALIKIA_PRO_WEBSITE_COPY_PHASE_5_OPERATIONS.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASE_5_OPERATIONS.md)
- [MALIKIA_PRO_WEBSITE_COPY_PHASED_USER_STORY.md](C:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/docs/MALIKIA_PRO_WEBSITE_COPY_PHASED_USER_STORY.md)

## Real page structure
La page `reservations` rend actuellement les zones suivantes, dans cet ordre:
1. Hero aligne sur le front public
2. `reservations-flow` en `layout: feature_tabs`
3. `reservations-cta` en `layout: showcase_cta`
4. `reservations-proof` en `layout: story_grid`

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
- l'image hero dediee a `reservations`

### Reservations flow
La section `reservations-flow` est une section `feature_tabs` avec:
- un kicker
- un titre
- un body introductif
- quatre onglets principaux

Les quatre onglets principaux actuellement visibles sont:
- `Proposer`
- `Confirmer`
- `Accueillir`
- `Suivre`

Chaque onglet porte:
- un titre
- un body
- une image
- un CTA vers une page solution ou module connexe

### CTA block
La section `reservations-cta` est une section `showcase_cta` avec:
- kicker
- titre
- body
- badge
- CTA principal
- CTA secondaire
- lien editorial secondaire

### Proof block
La section `reservations-proof` est une section `story_grid` avec:
- kicker
- titre
- body
- trois cartes de preuve visuelle

## Audit

### What already works
- la page a déjà une très bonne structure pour raconter le parcours complet d'une reservation
- les quatre temps `proposer -> confirmer -> accueillir -> suivre` sont clairs et utiles
- les liens vers `solution-reservations-queues`, `marketing-loyalty`, et `command-center` donnent de bonnes continuations
- le positionnement produit de base est solide: libre-service avec contrôle sur disponibilites, files, et confirmations

### Main weaknesses
- plusieurs textes actuels parlent de la page ou de son nouveau format, pas du produit
- le titre `Reservations suit maintenant le même format narratif que les autres pages modules` est une note interne
- le body du flow actuel parle encore de ce que la page montre mieux, pas de ce que l'utilisateur vit réellement
- la preuve actuelle est trop meta et pas assez orientee valeur client / operations
- la page doit mieux faire sentir l'enchainement entre choix du creneau, preparation de la visite, accueil, et suite relationnelle

### Phase 6 decisions
- la page `reservations` doit être centree sur l'experience client et la fluidite operationnelle
- la reservation doit être presentee comme un vrai parcours, pas comme un simple agenda
- le copy doit relier disponibilite, confirmation, accueil, file, et suivi post-visite
- le CTA block doit pousser vers la solution plus detaillee ou le pricing
- la preuve doit montrer une experience plus simple pour le client et plus claire pour l'equipe

## Message architecture for reservations

### Primary message
Offrez la reservation en libre-service tout en gardant la maîtrise des disponibilites, des confirmations, de l'accueil, et du suivi apres visite.

### Supporting messages
- choix de creneau plus simple pour le client
- moins de flottement avant l'arrivee
- accueil et file plus fluides sur place
- relation plus facile a prolonger apres la visite

### CTA strategy
- flow section: CTA vers `solution-reservations-queues`, `marketing-loyalty`, et `command-center`
- CTA block: `Voir la solution Reservations & files` en principal, `Voir les tarifs` en secondaire
- proof block: pas besoin d'ajouter de nouveau CTA si la structure ne le porte pas

## Editorial guardrails

### Keep the page customer-journey led
Le coeur de la page doit rester:
- disponibilite
- choix du creneau
- confirmation
- accueil
- file / attente
- suivi apres visite

Pas:
- un simple discours outil / calendrier
- une note de refonte editoriale

### Do not describe the page, describe the experience
Ne pas publier des formulations comme:
- `la page montre mieux`
- `le module devient plus lisible`
- `même format narratif`
- `le recit`

Le texte doit toujours parler de ce que le client et l'equipe vivent dans le flux de reservation.

### Keep the message operational as well as customer-facing
La page doit parler a la fois:
- du confort client
- du contrôle equipe
- de la coordination sur place
- de la suite commerciale ou relationnelle apres la visite

## Section copy

### 1. Hero

#### Section goal
Faire comprendre très vite que `Reservations` aide a rendre la prise de rendez-vous plus simple pour le client, tout en gardant le contrôle operationnel cote equipe.

#### Source copy
- Title: Offer self-service booking without losing control over the visit
- Subtitle: Malikia Pro connects availability, confirmations, arrival handling, queues, and post-visit follow-up so the booking experience feels smoother for customers and easier to manage for teams.

#### FR final
- Title: Offrez la reservation en libre-service sans perdre le contrôle de la visite
- Subtitle: Malikia Pro relie disponibilites, confirmations, accueil, files, et suivi apres visite pour que l'experience de reservation soit plus fluide pour le client et plus simple a gerer pour l'equipe.

#### EN final
- Title: Offer self-service booking without losing control over the visit
- Subtitle: Malikia Pro connects availability, confirmations, arrival handling, queues, and post-visit follow-up so the booking experience feels smoother for customers and easier to manage for teams.

### 2. Reservations flow

#### Real section shape
La section `reservations-flow` contient:
- un kicker
- un titre
- un body
- quatre onglets principaux

#### Section goal
Montrer que la reservation est un parcours complet qui commence avant la visite, se joue a l'arrivee, et continue apres le passage du client.

#### Source copy
- Kicker: One booking workflow from availability to follow-up
- Title: Turn booking into a complete customer journey
- Body: Reservations connects slot selection, confirmation, arrival handling, queue flow, and post-visit follow-up so the experience stays clear from first booking to the next visit.

#### FR final
- Kicker: Un workflow de reservation de la disponibilite au suivi
- Title: Faites de la reservation un parcours client complet
- Body: Reservations relie le choix du creneau, la confirmation, l'accueil, la gestion de file, et le suivi apres visite pour que l'experience reste claire du premier rendez-vous jusqu'au prochain.

#### EN final
- Kicker: One booking workflow from availability to follow-up
- Title: Turn booking into a complete customer journey
- Body: Reservations connects slot selection, confirmation, arrival handling, queue flow, and post-visit follow-up so the experience stays clear from first booking to the next visit.

#### Recommended tab narrative

##### Tab 1
- Source label: Offer
- Source title: Make availability easier to understand and easier to book
- Source body: Turn live availability into a clearer entry point so customers can choose the right slot without friction.
- Source CTA: See the solution

- FR label: Proposer
- FR title: Rendez les disponibilites plus faciles a comprendre et a reserver
- FR body: Transformez la disponibilite en un point d'entree plus clair pour que le client puisse choisir le bon creneau sans friction.
- FR CTA: Voir la solution

- EN label: Offer
- EN title: Make availability easier to understand and easier to book
- EN body: Turn live availability into a clearer entry point so customers can choose the right slot without friction.
- EN CTA: See the solution

##### Tab 2
- Source label: Confirm
- Source title: Stabilize the visit before the customer arrives
- Source body: Keep reminders, recap, and preparation visible before the appointment so fewer visits drift into uncertainty.
- Source CTA: See Marketing & Loyalty

- FR label: Confirmer
- FR title: Stabilisez la visite avant l'arrivee du client
- FR body: Gardez rappels, recapitulatif, et preparation visibles avant le rendez-vous pour que moins de visites glissent dans l'incertitude.
- FR CTA: Voir Marketing & Loyalty

- EN label: Confirm
- EN title: Stabilize the visit before the customer arrives
- EN body: Keep reminders, recap, and preparation visible before the appointment so fewer visits drift into uncertainty.
- EN CTA: See Marketing & Loyalty

##### Tab 3
- Source label: Welcome
- Source title: Absorb arrivals and queues more smoothly on site
- Source body: Keep reception, queue handling, and handoff into service connected so on-site flow feels more controlled.
- Source CTA: See Command Center

- FR label: Accueillir
- FR title: Absorbez les arrivees et la file plus fluidement sur place
- FR body: Gardez l'accueil, la gestion de file, et le passage vers le service relies pour que le flux sur place reste plus maîtrise.
- FR CTA: Voir Command Center

- EN label: Welcome
- EN title: Absorb arrivals and queues more smoothly on site
- EN body: Keep reception, queue handling, and handoff into service connected so on-site flow feels more controlled.
- EN CTA: See Command Center

##### Tab 4
- Source label: Follow up
- Source title: Extend the relationship after the visit
- Source body: Keep the booking connected to reviews, reminders, offers, and the next appointment so the visit does not end at confirmation alone.
- Source CTA: See the marketing solution

- FR label: Suivre
- FR title: Prolongez la relation apres la visite
- FR body: Gardez la reservation reliee aux avis, rappels, offres, et prochains rendez-vous pour que la visite ne s'arrete pas a la simple confirmation.
- FR CTA: Voir la solution marketing

- EN label: Follow up
- EN title: Extend the relationship after the visit
- EN body: Keep the booking connected to reviews, reminders, offers, and the next appointment so the visit does not end at confirmation alone.
- EN CTA: See the marketing solution

### 3. CTA block

#### Real section shape
La section `reservations-cta` rend:
- kicker
- titre
- body
- badge
- CTA principal
- CTA secondaire
- lien secondaire editorial

#### Section goal
Donner une prochaine etape claire a un prospect qui a compris la valeur du module et veut aller vers la solution plus detaillee ou le pricing.

#### Source copy
- Kicker: Ready to make visits smoother
- Title: Offer convenient booking without losing operational control
- Body: Replace disconnected scheduling, confirmations, and arrival handling with one workflow that helps customers book more easily and helps teams stay aligned before, during, and after the visit.
- Badge label: Module
- Badge value: Reservations
- Badge note: Availability, confirmation, and reception in one connected flow
- Primary CTA: See the Reservations & Queues solution
- Secondary CTA: View pricing
- Aside link: See Marketing & Loyalty

#### FR final
- Kicker: Pret a fluidifier la visite
- Title: Offrez une reservation plus pratique sans perdre le contrôle operationnel
- Body: Remplacez une prise de rendez-vous, des confirmations, et un accueil deconnectes par un même workflow qui aide les clients a reserver plus facilement et aide l'equipe a rester alignee avant, pendant, et apres la visite.
- Badge label: Module
- Badge value: Reservations
- Badge note: Disponibilite, confirmation, et accueil dans un même flux connecte
- Primary CTA: Voir la solution Reservations & files
- Secondary CTA: Voir les tarifs
- Aside link: Voir Marketing & Loyalty

#### EN final
- Kicker: Ready to make visits smoother
- Title: Offer convenient booking without losing operational control
- Body: Replace disconnected scheduling, confirmations, and arrival handling with one workflow that helps customers book more easily and helps teams stay aligned before, during, and after the visit.
- Badge label: Module
- Badge value: Reservations
- Badge note: Availability, confirmation, and reception in one connected flow
- Primary CTA: See the Reservations & Queues solution
- Secondary CTA: View pricing
- Aside link: See Marketing & Loyalty

### 4. Proof block

#### Real section shape
La section `reservations-proof` est une `story_grid` avec trois cartes.

#### Section goal
Renforcer la confiance avec des preuves courtes autour du choix du creneau, de l'arrivee, et du suivi apres visite.

#### Source copy
- Kicker: Clear moments across the visit
- Title: Built to make booking, arrival, and follow-up feel smoother
- Body: Keep the key moments before, during, and after the visit visible as part of the same experience so customers feel guided and teams stay in control.

##### Card 1
- Title: A simpler choice at booking time
- Body: Help the customer understand how to choose the right moment without friction or uncertainty.

##### Card 2
- Title: A smoother arrival on site
- Body: Give reception, queue flow, and handoff a clearer place in the operating experience.

##### Card 3
- Title: A real follow-up after the visit
- Body: Keep the visit connected to the next message, the next reminder, or the next appointment instead of ending the journey too early.

#### FR final
- Kicker: Moments clairs autour de la visite
- Title: Conçu pour rendre la reservation, l'arrivee, et le suivi plus fluides
- Body: Gardez les moments clefs avant, pendant, et apres la visite visibles comme une même experience pour que le client se sente guide et que l'equipe reste en contrôle.

##### Carte 1
- Title: Un choix plus simple au moment de reserver
- Body: Aidez le client a comprendre comment choisir le bon moment sans friction ni hesitation.

##### Carte 2
- Title: Une arrivee plus fluide sur place
- Body: Donnez a l'accueil, a la file, et au passage vers le service une place plus claire dans l'experience operationnelle.

##### Carte 3
- Title: Un vrai suivi apres la visite
- Body: Gardez la visite reliee au prochain message, au prochain rappel, ou au prochain rendez-vous au lieu d'arreter le parcours trop tot.

#### EN final
- Kicker: Clear moments across the visit
- Title: Built to make booking, arrival, and follow-up feel smoother
- Body: Keep the key moments before, during, and after the visit visible as part of the same experience so customers feel guided and teams stay in control.

##### Card 1
- Title: A simpler choice at booking time
- Body: Help the customer understand how to choose the right moment without friction or uncertainty.

##### Card 2
- Title: A smoother arrival on site
- Body: Give reception, queue flow, and handoff a clearer place in the operating experience.

##### Card 3
- Title: A real follow-up after the visit
- Body: Keep the visit connected to the next message, the next reminder, or the next appointment instead of ending the journey too early.

## Final quality checklist for this page
- le hero doit faire sentir une reservation simple cote client mais maîtrisée cote equipe
- la section `feature_tabs` doit raconter un vrai parcours de reservation
- aucun texte ne doit sonner comme une note de redesign
- la page doit relier libre-service et contrôle operationnel
- la preuve doit rester concrete et centree sur la visite
- les CTA doivent mener logiquement vers solution, pricing, ou module connexe

## Recommendation before live application
Avant application live de cette phase:
- verifier si `Reservations` reste le bon nom editorial dans les deux langues
- verifier les CTA de chaque onglet pour s'assurer qu'ils pointent vers les pages publiques les plus pertinentes
- verifier si `Marketing & Loyalty` est bien le meilleur lien secondaire pour la suite relationnelle apres visite

