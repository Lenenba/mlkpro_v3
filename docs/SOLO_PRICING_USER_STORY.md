# Solo Pricing - User Story

## Statut
Phase 1 figee.

Perimetre d implementation demarre:
- ajout des codes de plans `solo_essential`, `solo_pro`, `solo_growth` dans le billing applicatif
- ajout de la logique `owner-only` pour les quantites et la selection de plan
- adaptation onboarding / billing settings pour reconnaitre la gamme solo

Hors perimetre immediate:
- masquage complet des ecrans `team`, `presence` et des autres surfaces collaboratives
- simplification complete des ecrans metier qui reposent encore sur `team_members`
- rollout marketing public complet sur la page pricing

## Objectif
Concevoir une offre `solo` pour les travailleurs autonomes qui utilisent la plateforme sans equipe, avec une proposition claire, evolutive et plus pertinente que les forfaits centres sur les employes.

## Contexte
Le modele actuel de billing est surtout pense pour des structures avec equipe:
- logique de plans `free`, `starter`, `growth`, `scale`, `enterprise`
- vocabulaire oriente `employes`, `equipe`, `presence`, `permissions`
- comparaison publique principalement axee sur la taille d equipe

Pour un utilisateur seul, cette logique cree de la friction:
- il paie pour des capacites d equipe qu il n utilise pas
- il ne se reconnait pas dans le discours commercial
- il a surtout besoin de gagner du temps, convertir plus vite et se faire payer plus vite

## Vision produit
Creer une ligne de forfaits `solo` distincte de la ligne `team`, avec une progression basee sur la maturite d usage plutot que sur le nombre d employes.

Les 3 forfaits proposes:
- `solo_essential`
- `solo_pro`
- `solo_growth`

## Positionnement des forfaits

### 1. `solo_essential`
Positionnement:
- pour la personne seule qui veut paraitre pro rapidement
- focus sur devis, factures, paiements et presence en ligne
- forfait volontairement minimal

Valeur:
- centraliser les demandes entrantes
- envoyer des devis propres
- facturer sans friction
- recevoir les paiements plus facilement

Modules / capacites cibles:
- `quotes`
- `invoices`
- `requests`
- `services`
- `products`
- page publique / site vitrine simple

Promesse:
- "Avoir le minimum solide pour vendre et se faire payer."

Prix indicatif de travail:
- CAD `19`
- EUR `14`
- USD `16`

### 2. `solo_pro`
Positionnement:
- pour la personne seule qui veut un peu plus de structure sans entrer dans une logique avancee
- plan central a pousser comme `most popular`
- forfait encore volontairement simple

Valeur:
- ajouter un niveau de structure supplementaire
- mieux suivre le travail en cours
- garder une experience simple a exploiter seul

Modules / capacites cibles:
- tout `solo_essential`
- `jobs`
- `tasks`
- personnalisation plus avancee des devis

Promesse:
- "Passer d un fonctionnement artisanal a un fonctionnement plus cadre, sans complexifier l outil."

Prix indicatif de travail:
- CAD `39`
- EUR `29`
- USD `32`

### 3. `solo_growth`
Positionnement:
- pour la personne seule qui veut scaler avant d embaucher
- plan oriente automatisation, acquisition et productivite

Valeur:
- automatiser plus
- mieux exploiter les leads et la clientele existante
- produire plus seul avant de basculer sur une logique equipe
- ouvrir les parcours plus riches comme reservation et planning

Modules / capacites cibles:
- tout `solo_pro`
- `reservations`
- `planning`
- `assistant`
- `campaigns`
- `loyalty`
- `plan_scans`
- automatisations avancees

Promesse:
- "Scaler seul plus longtemps sans alourdir l operation."

Prix indicatif de travail:
- CAD `59`
- EUR `43`
- USD `48`

## Story principale
En tant que travailleur autonome,
je veux choisir un forfait concu pour une personne seule,
afin de gerer mes demandes, mes devis, mes jobs, mes factures et mes relances sans payer pour des fonctions d equipe inutiles ni ouvrir l acces a d autres utilisateurs.

## User Stories

### US-SOLO-1 - Choix d une offre adaptee au solo
As a solo operator, I can see plans designed for one person so I immediately understand which package fits my reality.

Acceptance criteria:
- la page pricing distingue clairement une logique `solo` d une logique `team`
- les forfaits solo parlent de gain de temps, conversion et relances
- le vocabulaire `employes inclus` n est pas au centre de l offre solo
- le cadrage solo suppose un acces owner unique

### US-SOLO-2 - Experience allegee pour utilisateur seul
As a solo operator, I only see the features that matter to a one-person business so the product feels simpler and more relevant.

Acceptance criteria:
- les modules tres orientes equipe peuvent etre masques ou depriorises
- les forfaits solo ne mettent pas en avant `presence`, `permissions equipe` ou structure RH
- le module `team_members` n est pas disponible dans la gamme solo
- l UI d invitation d equipe et les ecrans collaboratifs ne sont pas exposes sur la gamme solo
- l utilisateur comprend clairement ce qu il gagne en passant de `solo_essential` a `solo_pro`, puis a `solo_growth`

### US-SOLO-3 - Parcours evolutif sans perte
As a solo operator, I can start on a solo plan and later move to a team plan without losing data or rebuilding my setup.

Acceptance criteria:
- les donnees business restent compatibles avec un futur passage sur un plan equipe
- le changement de plan ne casse ni les documents, ni les workflows, ni les integrations existantes
- la montee en gamme solo -> team est explicitement prevue

### US-SOLO-4 - Mise en avant du plan central
As a prospect, I can immediately identify the recommended solo plan so I do not spend too much time comparing packages.

Acceptance criteria:
- `solo_pro` est le plan mis en avant
- la promesse du plan central est simple et concrete
- la difference avec `solo_essential` et `solo_growth` est lisible en moins de 10 secondes

### US-SOLO-5 - Billing exploitable cote plateforme
As a platform admin, I can define and later provision solo plans as first-class billing plans so marketing intent and billing operations stay aligned.

Acceptance criteria:
- les 3 codes de plan sont stabilises:
  - `solo_essential`
  - `solo_pro`
  - `solo_growth`
- les prix de reference par devise sont definis
- l integration Stripe peut creer ou reutiliser les price IDs de ces 3 forfaits

## Regles produit

### 1. Ligne solo distincte
- un solo ne doit pas avoir l impression d acheter un plan d equipe "rabote"
- le discours commercial solo doit etre specifique

### 2. Progression par maturite
- `solo_essential` = vendre et facturer
- `solo_pro` = structurer un peu mieux l execution, sans modules avances
- `solo_growth` = automatiser et croitre seul, avec reservations et planning

### 3. Transition vers equipe
- quand le solo commence a deleguer ou embaucher, le passage vers `starter` ou `growth` doit etre naturel
- les forfaits solo ne doivent pas fermer la porte a la suite du parcours

### 4. Decision initiale de phase 1
Au depart, pour cette phase:
- on cadre l offre et la logique produit
- on ne pousse pas encore forcement ces plans dans toute l UI publique
- on ne modifie pas encore la commande Stripe

Note:
- ce point a depuis ete depasse
- la page pricing publique solo/team est active
- la commande Stripe supporte maintenant `--solo` et `--plans=solo`

## Conception fonctionnelle proposee

### Option recommandee - approche en 2 phases

#### Phase 1 - cadrage produit et readiness billing
- definir les 3 forfaits solo
- definir leur positionnement, leur prix indicatif et leurs modules cibles
- preparer les codes de plans et la logique Stripe attendue
- decider si la page pricing publique aura un switch `Solo` / `Equipe`

#### Phase 2 - activation produit
- ajouter les vrais plans au billing applicatif
- exposer les plans dans l experience publique ou onboarding
- adapter les comparatifs et le wording
- brancher le provisionnement Stripe

Etat:
- les 4 points ci-dessus sont maintenant couverts

## Decisions figees pour la phase 1

### Decision 1 - Les 3 codes de plan sont retenus
Les codes a conserver pour toute la suite:
- `solo_essential`
- `solo_pro`
- `solo_growth`

### Decision 2 - `solo_pro` devient le plan central
- c est le plan a mettre en avant
- c est lui qui porte la meilleure promesse de conversion pour un travailleur autonome actif
- c est le forfait a utiliser plus tard comme `most popular`
- il reste volontairement dans une logique simple, sans basculer trop tot vers les modules avances

### Decision 3 - Les forfaits solo restent en logique `1 operateur`
- la promesse marketing ne doit pas etre formulee en `employes inclus`
- au niveau produit, l acces au workspace reste reserve a l owner
- le module `team_members` ne fait pas partie de la gamme solo
- il n y a pas de capacite `team_members` a vendre sur ces forfaits

### Decision 4 - Les modules trop equipes sont exclus de l offre solo
En phase 1, on considere comme non prioritaires pour la gamme solo:
- `presence`
- `team_members`
- permissions d equipe complexes

Regle complementaire:
- `team_members` doit etre considere comme indisponible, et non comme une option "limitee"
- `presence` doit etre considere comme indisponible, et non comme une option cachee temporairement
- les permissions avancees ne font pas partie de la gamme solo

### Decision 4bis - Les forfaits `solo_essential` et `solo_pro` restent volontairement basiques
- `solo_essential` = socle commercial minimal
- `solo_pro` = socle commercial + execution simple
- les parcours plus riches comme `reservations`, `planning`, `assistant`, `campaigns` et `plan_scans` commencent seulement a `solo_growth`

### Decision 5 - Les surfaces collaboratives sont masquees en solo
Pour la gamme solo:
- aucune UI d invitation de membre n est exposee
- aucune UI de gestion d equipe n est exposee
- aucune UI de permissions / roles d equipe n est exposee
- les entrees de navigation liees a la collaboration sont absentes

Regle complementaire:
- si un ecran collaboratif est atteint par URL directe plus tard, il devra etre bloque et pas seulement masque

### Decision 6 - Le switch `Solo` / `Equipe` est recommande, mais pas obligatoire en phase 1
- phase 1: on fige la logique produit et billing
- phase 2: on pourra exposer un switch public ou onboarding si on valide la direction

## Matrice packaging - reference phase 1

| Plan | Cible | Promesse principale | Moment d upgrade |
| --- | --- | --- | --- |
| `solo_essential` | solo qui veut lancer proprement son operation | vendre, envoyer des devis, facturer et recevoir des paiements | quand le volume augmente et que le suivi manuel devient un frein |
| `solo_pro` | solo actif avec besoin de structure | mieux operer seul, mieux suivre, moins oublier | quand l acquisition et l automatisation deviennent prioritaires |
| `solo_growth` | solo qui veut scaler avant embauche | automatiser, accelerer, reengager et produire plus seul | quand il faut passer a une vraie logique equipe |

## Mapping modules cible - reference phase 1
Cette matrice est alignee sur les modules actuellement connus par la plateforme. Elle sert de base de travail pour la phase 2.

| Module | `solo_essential` | `solo_pro` | `solo_growth` | Note |
| --- | --- | --- | --- | --- |
| `quotes` | yes | yes | yes | coeur commercial |
| `requests` | yes | yes | yes | capture des demandes |
| `reservations` | no | no | yes | reserve uniquement au plus gros forfait solo |
| `invoices` | yes | yes | yes | montee en paiement |
| `products` | yes | yes | yes | lignes produits et catalogue |
| `services` | yes | yes | yes | catalogue principal pour un solo |
| `jobs` | no | yes | yes | operations terrain a partir du plan central |
| `tasks` | no | yes | yes | suivi plus propre et execution |
| `planning` | no | no | yes | garde pour le haut de gamme solo |
| `plan_scans` | no | no | yes | acceleration avancee reservee au haut de gamme |
| `assistant` | no | no | yes | inclus uniquement sur le plan haut |
| `campaigns` | no | no | yes | reactivation et croissance |
| `loyalty` | no | no | yes | retention / repeat business |
| `performance` | no | no | yes | reporting plus pousse |
| `presence` | no | no | no | module indisponible sur toute la gamme solo |
| `team_members` | no | no | no | module indisponible sur toute la gamme solo |

## Limites de reference - phase 1
Ces chiffres sont des seuils de travail pour preparer la phase 2. Ils servent a valider le packaging et le futur mapping `plan_limits`, pas encore a devenir une promesse commerciale figee.

| Limite | `solo_essential` | `solo_pro` | `solo_growth` |
| --- | --- | --- | --- |
| `quotes` | `25` | `100` | `500` |
| `requests` | `25` | `100` | `500` |
| `plan_scan_quotes` | `0` | `0` | `150` |
| `invoices` | `25` | `100` | `500` |
| `jobs` | `0` | `100` | `500` |
| `products` | `50` | `150` | `1000` |
| `services` | `50` | `150` | `1000` |
| `tasks` | `0` | `200` | `1200` |
| `assistant_requests` | `0` | `0` | `1000` |

## Decision UX recommandee - phase 1

### Option retenue
Exposer la gamme solo sur la page pricing publique sans creer une nouvelle page dediee.

### Recommandation
Le meilleur compromis semble etre:
- page pricing unique
- toggle `Solo` / `Equipe`
- `solo_pro` mis en avant

Implementation retenue:
- le toggle `Solo` / `Equipe` est maintenant en place sur la page pricing publique
- la vue solo affiche `solo_essential`, `solo_pro` et `solo_growth`
- la vue equipe conserve `free`, `starter`, `growth`, `scale` et `enterprise`
- les tableaux comparatifs sont distincts pour eviter une promesse marketing confuse

Cela evite:
- de dupliquer trop tot l acquisition publique
- de brouiller le billing
- de faire exploser les comparatifs trop vite

## Regles UI owner-only

### Navigation
En mode solo, les surfaces suivantes doivent etre considerees hors perimetre:
- invitation de membres
- liste des membres d equipe
- ecrans de roles et permissions
- ecrans de presence / pointage equipe

### Comportement attendu plus tard
Quand la phase d implementation commencera:
- ces surfaces ne devront pas apparaitre dans la navigation
- ces surfaces ne devront pas etre vendues sur les cartes pricing
- ces surfaces devront etre bloquees si elles sont appelees directement

### Principe produit
Un utilisateur solo ne doit jamais avoir l impression qu il manque une sous-option payante pour collaborer.
La collaboration doit etre comprise comme une bascule vers une autre gamme, pas comme une case cachee dans un forfait solo.

## Checklist implementation owner-only - base code actuelle
Cette section ancre la phase 1 sur les surfaces reelles du produit qui devront etre traitees en phase 2.

### A. Surfaces a masquer et bloquer sans ambiguite

#### Navigation principale
- sidebar:
  - entree `presence`
  - entree `team`

#### Routes web a sortir du perimetre solo
- `/presence`
- `/presence/clock-in`
- `/presence/clock-out`
- `/team`
- `POST /team`
- `PUT /team/{teamMember}`
- `DELETE /team/{teamMember}`

#### Routes API a sortir du perimetre solo
- `GET api/.../presence`
- `POST api/.../presence/clock-in`
- `POST api/.../presence/clock-out`
- `GET api/.../team`
- `POST api/.../team`
- `PUT api/.../team/{teamMember}`
- `DELETE api/.../team/{teamMember}`

#### Pages / composants directement concernes
- ecran equipe
- table equipe
- ecran presence
- logique controller des membres d equipe

### B. Surfaces ou le wording `team_members` doit etre retire

#### Billing et pricing interne
- onboarding: recommandation de plan basee aujourd hui sur `team_members`
- settings billing: exposition de `team_members_limit` et `team_members_min`
- dashboard: affichage de la limite `team_members`
- traduction / comparatifs: suppression des labels du type `Included employees` pour la gamme solo

#### Regle
- pour la gamme solo, on ne reformule pas `team_members` en `1 membre`
- on supprime la notion du discours solo

### C. Surfaces operationnelles a simplifier en owner-only
Ces ecrans ne sont pas forcement a supprimer, mais ils contiennent des selecteurs ou filtres equipe a retirer.

#### Jobs / work
- creation et edition de jobs: retrait des blocs d assignation de membres
- conservation d un mode `non assigne` ou `owner only` selon la decision finale

#### Tasks
- creation et edition de taches: retrait de l assignation a un membre
- retrait des colonnes et filtres lies aux assignees quand le workspace est solo

#### Planning
- retrait des filtres `all members`
- retrait des selecteurs `team_member_id`
- affichage owner-only ou vue simplifiee sans notion d equipe

#### Tips / performance
- retrait des filtres `all team members`
- retrait ou adaptation des cartes `employees / members` quand le compte est solo

### D. Hotspot technique majeur - reservations
Le module reservations utilise actuellement `team_members` dans plusieurs parcours:
- back-office reservations
- reservation settings
- client booking
- client reschedule
- public kiosk
- queue screen

Conclusion phase 1:
- `reservations` reste reserve a `solo_growth`
- le fallback retenu est un `mode limite owner-only`

Decision retenue:
- back-office: consultation, waitlist et reglages globaux restent disponibles
- booking manuel staff et edition planning par membre restent bloques
- client booking par creneau et client reschedule restent bloques
- queue hybride et kiosk public restent desactives
- les reglages `team_settings` et `weekly_availabilities` restent masques tant que `team_members` est off

Later:
- une vraie logique `owner resource` pourra etre etudiee plus tard si on veut un vrai booking solo natif

### E. Hotspot technique secondaire - planning
Le planning manipule lui aussi `teamMembers` et `team_member_id`.

Conclusion phase 1:
- le planning reste reserve a `solo_growth`
- le MVP retenu est une vue owner-only sans selecteur de membre
- les jobs et taches restent visibles
- les absences, conges et shifts equipe historiques ne sont plus exposes
- la creation, modification, suppression et approbation de shifts restent bloquees tant qu un vrai fallback owner n existe pas

## Inventaire concret a traiter en phase 2

### Navigation / layout
- `resources/js/Layouts/UI/Sidebar.vue`

### Routes / controleurs primaires
- `routes/web.php`
- `routes/api.php`
- `app/Http/Controllers/TeamMemberController.php`

### Pages primaires a masquer
- `resources/js/Pages/Team/Index.vue`
- `resources/js/Pages/Team/UI/TeamTable.vue`
- `resources/js/Pages/Presence/Index.vue`

### Pages a simplifier
- `resources/js/Pages/Work/Create.vue`
- `app/Http/Controllers/WorkController.php`
- `resources/js/Pages/Task/Index.vue`
- `resources/js/Pages/Task/UI/TaskTable.vue`
- `app/Http/Controllers/TaskController.php`
- `resources/js/Pages/Planning/Index.vue`
- `resources/js/Pages/Reservation/Index.vue`
- `resources/js/Pages/Reservation/ClientBook.vue`
- `resources/js/Pages/Reservation/ClientIndex.vue`
- `resources/js/Pages/Reservation/Screen.vue`
- `app/Http/Controllers/Reservation/StaffReservationController.php`
- `app/Http/Controllers/Reservation/ClientReservationController.php`
- `app/Http/Controllers/Reservation/PublicKioskReservationController.php`
- `app/Http/Controllers/Reservation/ReservationSettingsController.php`

### Pages / controleurs a nettoyer cote discours billing
- `resources/js/Pages/Onboarding/Index.vue`
- `resources/js/Pages/Settings/Billing.vue`
- `app/Http/Controllers/Settings/BillingSettingsController.php`
- `resources/js/Pages/Dashboard.vue`

## Criteres de validation owner-only pour la phase 2
- aucun menu `team` ou `presence` n apparait pour un forfait solo
- aucune route `team` ou `presence` n est accessible pour un forfait solo
- aucune carte billing solo ne parle de `team_members`, `included employees` ou equivalent
- aucun selecteur de membre n apparait dans les flux jobs, tasks et planning en mode solo
- le module reservations a une strategie explicite de fallback owner-only avant mise en prod solo

## Regles de migration solo -> team

### Mapping de montee en gamme recommande
- `solo_essential` -> `starter`
- `solo_pro` -> `starter` ou `growth` selon besoin de permissions / equipe
- `solo_growth` -> `growth` par defaut

### Principe
- aucun objet business ne doit devenir incompatible lors du passage solo -> team
- les documents, workflows et catalogues doivent rester intacts
- la difference entre gamme solo et gamme team doit etre principalement une question de packaging, de modules et de limites
- l acces collaboratif n existe pas sur les forfaits solo et ne s ouvre qu au passage sur une gamme team

## Backlog de preparation - phase 1

### Bloc produit
- figer les noms publics FR et EN des 3 forfaits solo
- valider si le mot `solo` reste public ou s il est remplace par un naming plus commercial
- valider les prix indicatifs avec la strategie de marge

### Bloc packaging
- confirmer la matrice des modules cibles
- confirmer les limites de reference
- confirmer que `presence` et `team_members` restent totalement indisponibles sur la gamme solo
- confirmer que les permissions avancees restent hors gamme solo

### Bloc billing readiness
- reserver les codes `solo_essential`, `solo_pro`, `solo_growth`
- documenter les variables env Stripe
- documenter et implementer la prise en charge dans la commande de provisionnement Stripe

### Bloc UX
- trancher entre `toggle` pricing, page dediee ou routage onboarding
- definir le wording principal du plan central `solo_pro`
- preparer les bullets marketing finales pour chaque carte
- lister les entrees de navigation et ecrans a masquer pour un workspace `owner only`
- valider le fallback owner-only pour reservations et planning avant implementation

## Impact technique couvert
L implementation a effectivement touche au minimum:
- `config/billing.php`
- la logique de comparaison publique
- le pricing public et le billing settings
- les limites et modules par plan
- le seed du catalogue de plans
- les tests qui supposent aujourd hui une liste fixe de forfaits publics
- la commande de provisionnement Stripe

## Stripe - implementation retenue
La commande de billing permet maintenant de provisionner les 3 forfaits solo.

Attendu fonctionnel minimal:
- raccourci `--solo`
- alias `--plans=solo`
- prise en charge explicite de `--plans=solo_essential,solo_pro,solo_growth`

Attendu de configuration:
- un prix CAD, EUR et USD pour chaque forfait solo
- un base price Stripe CAD par forfait pour permettre la creation ou la reutilisation des prix mensuels multi-devise

Variables env attendues:
- `STRIPE_PRICE_SOLO_ESSENTIAL_CAD`
- `STRIPE_PRICE_SOLO_ESSENTIAL_EUR`
- `STRIPE_PRICE_SOLO_ESSENTIAL_USD`
- `STRIPE_PRICE_SOLO_PRO_CAD`
- `STRIPE_PRICE_SOLO_PRO_EUR`
- `STRIPE_PRICE_SOLO_PRO_USD`
- `STRIPE_PRICE_SOLO_GROWTH_CAD`
- `STRIPE_PRICE_SOLO_GROWTH_EUR`
- `STRIPE_PRICE_SOLO_GROWTH_USD`

Variables de montant attendues:
- `STRIPE_PRICE_SOLO_ESSENTIAL_CAD_AMOUNT`
- `STRIPE_PRICE_SOLO_ESSENTIAL_EUR_AMOUNT`
- `STRIPE_PRICE_SOLO_ESSENTIAL_USD_AMOUNT`
- `STRIPE_PRICE_SOLO_PRO_CAD_AMOUNT`
- `STRIPE_PRICE_SOLO_PRO_EUR_AMOUNT`
- `STRIPE_PRICE_SOLO_PRO_USD_AMOUNT`
- `STRIPE_PRICE_SOLO_GROWTH_CAD_AMOUNT`
- `STRIPE_PRICE_SOLO_GROWTH_EUR_AMOUNT`
- `STRIPE_PRICE_SOLO_GROWTH_USD_AMOUNT`

Exemples d usage:
- `php artisan billing:stripe-plan-prices --solo --dry-run`
- `php artisan billing:stripe-plan-prices --plans=solo --currencies=CAD,USD --dry-run`
- `php artisan billing:stripe-plan-prices --plans=solo_essential,solo_pro,solo_growth`

## Definition of Done
- un document de cadrage solo existe
- les 3 forfaits sont nommes et positionnes
- la matrice modules est posee
- les limites de reference sont posees
- la decision UX de phase 1 est explicite
- les surfaces owner-only a masquer / simplifier sont inventoriees
- les user stories principales sont posees
- le support Stripe des plans solo est documente
- la suite de conception peut partir de ce document sans re-decider les bases
