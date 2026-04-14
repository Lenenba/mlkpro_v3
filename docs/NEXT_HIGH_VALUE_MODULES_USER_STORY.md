# Next High-Value Modules and Expansion Opportunities - User Story

Last updated: 2026-04-13

## Goal
Identifier les prochaines evolutions produit a plus forte valeur ajoutee apres les bases deja posees sur `Customer bulk contact`, `Platform bulk actions`, `Campaigns`, `Reservations`, `Plan Scan`, `Billing`, et les modules coeur de gestion.

Le but n est pas d empiler des features, mais de choisir les prochains paris qui:
- augmentent directement le revenu
- reduisent du temps operationnel repetitif
- creent un effet systeme entre plusieurs modules
- restent compatibles avec les limites de plans et les dependances de modules

## Product Vision
La prochaine etape du produit ne doit pas etre "un ecran de plus".

Elle doit transformer les modules deja existants en systemes de travail recurrent:
- des segments qui vivent dans le temps
- des actions bulk qui deviennent des playbooks
- des relances qui deviennent des routines
- des files d attente qui deviennent des centres de pilotage
- des signaux business qui deviennent des decisions actionnables

Autrement dit:
- moins de navigation
- plus de decisions guidees
- plus de workflows transverses
- plus de valeur par module deja livre

## Selection Criteria
Une opportunite est consideree prioritaire si elle coche plusieurs de ces points:
- effet direct sur cash, conversion, retention, ou capacite d execution
- reutilise des briques deja presentes
- cree un pont entre au moins 2 modules existants
- peut etre livree en V1 sans enorme refonte structurelle
- respecte les contraintes de plans solo / team et les feature flags

## Current Baseline
La plateforme dispose deja de briques qui augmentent fortement l effet de levier des prochaines stories:
- DataTables partagees
- bulk actions mutualisees
- registre d actions bulk
- feedback bulk standardise
- `Customer -> Campaigns` bridge
- moteur `Campaigns` avec templates, audience, mailing lists et send
- modules coeur: `Customer`, `Request`, `Quote`, `Invoice`, `Work`, `Task`, `Reservation`, `Product`, `Sales`
- structure de feature flags et de gating par plan

## Priority Stack

### Priority 1 - Saved Segments and Scheduled Playbooks
Modules:
- `Customer`
- `Request`
- `Campaigns`
- `Platform Bulk Actions`

Why this matters:
- transforme les actions bulk ponctuelles en systeme recurrent
- cree un multiplicateur pour les modules deja existants
- ouvre la voie a la planification, a la recurrence, et a l historique de runs

Primary user story:
As an owner or operator,
I want to save a segment and attach a scheduled bulk playbook to it,
so I can repeatedly run high-value actions without rebuilding the same selection every week.

V1 acceptance criteria:
- un utilisateur peut sauvegarder un filtre `Customer` ou `Request`
- un segment sauvegarde peut etre relie a une action bulk compatible
- le playbook peut etre lance maintenant ou planifie
- chaque run garde `selected / processed / success / failed / skipped`
- les actions dependantes de modules ne s affichent que si le module est disponible

Value signal:
- plus forte valeur transversale de toute la roadmap proche

### Priority 2 - Receivables Command Center
Modules:
- `Invoice`
- `Customer`
- `Payments`
- `Campaigns`

Why this matters:
- impact cash direct
- transforme les factures en pilotage de relance, pas seulement en historique

Primary user story:
As an owner or finance operator,
I want a receivables cockpit that groups overdue customers, failed payment patterns, and relaunch actions,
so I can recover cash faster from one place.

V1 acceptance criteria:
- vue par ageing bucket
- regroupement par client et montant en retard
- playbooks de relance bulk
- historique des relances et dernier contact
- priorisation des plus gros risques de retard

Value signal:
- tres forte valeur business immediate

### Priority 3 - Lead SLA Inbox and Smart Triage
Modules:
- `Request`
- `Customer`
- `Task`
- `Team`

Why this matters:
- evite la perte de leads par lenteur de traitement
- transforme `Request` en vraie inbox operationnelle

Primary user story:
As a sales or service manager,
I want to see unworked leads ordered by urgency, source quality, and SLA breach risk,
so I can assign and process the right requests first.

V1 acceptance criteria:
- file `new / stale / due soon / breached`
- bulk assign et bulk status
- SLA timers visibles
- vues par source, assignee, urgence
- creation rapide de tache de suivi

Value signal:
- tres forte valeur conversion

### Priority 4 - Quote Recovery and Conversion Cockpit
Modules:
- `Quote`
- `Customer`
- `Campaigns`
- `Invoice`

Why this matters:
- beaucoup de revenu se perd entre devis emis et devis signes
- le module `Quote` peut devenir un moteur de recovery

Primary user story:
As an owner or sales operator,
I want to track stale quotes and launch guided follow-up actions,
so I can recover more approved quotes without manual chasing.

V1 acceptance criteria:
- vue des devis sans reponse par anciennete
- playbooks `remind`, `call`, `convert to task`, `archive`
- timeline des suivis
- priorisation par montant et probabilite
- signaux `viewed / not viewed / expired`

Value signal:
- tres forte valeur revenu

### Priority 5 - Dispatch Exceptions Control Tower
Modules:
- `Work`
- `Task`
- `Planning`
- `Customer`

Why this matters:
- la valeur n est pas juste de planifier, mais de rattraper les exceptions
- utile surtout pour les comptes equipe

Primary user story:
As an operations manager,
I want a single board for late jobs, missing assignees, delayed tasks, and at-risk visits,
so I can correct field execution before customers escalate.

V1 acceptance criteria:
- vues `unassigned / late / today at risk / blocked`
- actions bulk de reassignment
- contact rapide client
- creation d alertes et notes d execution
- journal des corrections

Value signal:
- tres forte valeur ops pour les teams

### Priority 6 - Reservation Recovery and Waitlist Revenue
Modules:
- `Reservation`
- `Queue`
- `Customer`
- `Campaigns`

Why this matters:
- augmente le remplissage sans acquisition supplementaire
- tres fort pour salon, beauty, restauration, hospitality

Primary user story:
As a reservation-based business,
I want to automatically refill cancelled slots and recover no-show revenue,
so I can keep capacity full with less manual coordination.

V1 acceptance criteria:
- waitlist priorisee
- relance auto des clients eligibles
- no-show follow-up
- suggestions de rebooking
- vue des slots perdus et recuperes

Value signal:
- forte valeur revenu sectorielle

### Priority 7 - Stock Replenishment and Reorder Suggestions
Modules:
- `Product`
- `Sales`
- `Supplier`

Why this matters:
- transforme `Products` et `Sales` en moteur d achat intelligent
- reduit les ruptures et le surstock

Primary user story:
As a commerce operator,
I want replenishment suggestions based on sales velocity and minimum stock,
so I can reorder the right items before stockouts hurt revenue.

V1 acceptance criteria:
- vue `critical / reorder soon / overstock`
- suggestion quantite de reorder
- export fournisseur
- regroupement par categorie ou fournisseur
- historique des alertes critiques

Value signal:
- forte valeur retail / catalog

### Priority 8 - Loyalty Lifecycle Programs
Modules:
- `Loyalty`
- `Customer`
- `Campaigns`
- `Sales`

Why this matters:
- relie la fidelite a des actions concretes
- donne une vraie boucle retention, pas juste un compteur de points

Primary user story:
As an owner or marketer,
I want lifecycle triggers based on loyalty tier, inactivity, and points balance,
so I can drive repeat business with targeted campaigns.

V1 acceptance criteria:
- segments par tiers VIP / balance / inactivite
- playbooks de reactivation
- campagne vers audience prefiltree
- suivi du retour sur campagne de retention

Value signal:
- forte valeur retention

## Potential New Modules With Strong Upside

### A) Service Agreements / Recurring Work
Best fit:
- services recurrentes
- maintenance
- contrats annuels

Why it matters:
- revenu recurrent
- meilleure visibilite pipeline
- lien naturel avec `Quote`, `Invoice`, `Planning`, `Task`

### B) Procurement and Supplier Hub
Best fit:
- commerce
- services avec achat frequent de materiaux

Why it matters:
- prolonge `Product` et `Sales`
- ouvre une vraie boucle `stock -> achat -> marge`

### C) Approvals and Exception Governance
Best fit:
- equipes en croissance
- operations sensibles

Why it matters:
- ajoute du controle sans ralentir les workflows courants
- utile pour remises, annulations, remboursements, suppressions, campagnes

### D) Customer Health and Renewal Signals
Best fit:
- businesses avec relation longue
- comptes VIP
- retention forte

Why it matters:
- permet de passer de CRM passif a retention proactive

### E) Unified Ops Command Center
Best fit:
- owners multi-modules
- managers d exploitation

Why it matters:
- unifie signaux `cash / leads / jobs / reservations / campaigns`
- forte valeur executive mais a construire apres les cockpits metier

### F) Expenses Control and Accounting Bridge
Best fit:
- toutes les activites qui veulent piloter la marge reelle
- services avec achats terrain et remboursements
- retail avec besoin de distinguer stock, charges et cash-out

Why it matters:
- complete enfin la lecture `revenu -> cout -> marge -> cash`
- pose une base robuste avant toute vraie couche comptable
- cree un pont naturel entre operations, finance, taxes et collaboration comptable

Recommended sequencing:
1. ship `Expenses` first
2. layer `Accounting` on top

Related docs:
- `docs/EXPENSES_MODULE_USER_STORY.md`
- `docs/ACCOUNTING_MODULE_USER_STORY.md`

## Recommended Delivery Order
1. `Saved Segments and Scheduled Playbooks`
2. `Receivables Command Center`
3. `Lead SLA Inbox and Smart Triage`
4. `Quote Recovery and Conversion Cockpit`
5. `Reservation Recovery and Waitlist Revenue` ou `Dispatch Exceptions Control Tower` selon vertical principal
6. `Stock Replenishment and Reorder Suggestions`
7. `Loyalty Lifecycle Programs`

## Guardrails
- toute nouvelle valeur cross-module doit reutiliser les feature flags existants
- une action dependante d un module ne doit jamais fuiter cote client si le module est absent
- les plans `solo` et `team` ne doivent pas exposer la meme promesse si le workflow depend d equipe, de presence, ou de performance
- preferer les stories qui reutilisent des briques deja posees avant d ouvrir un module entierement neuf

## Best Next Bet
Si on ne devait choisir qu une seule suite a forte valeur:

### US-NEXT-001 - Saved Segments and Scheduled Playbooks
As an owner or operations lead,
I want reusable segments and scheduled playbooks across `Customer`, `Request`, and `Campaigns`,
so I can turn repeated manual actions into repeatable business routines.

Pourquoi c est le meilleur pari:
- meilleur effet de levier sur le travail deja livre
- multiplie la valeur des bulk actions, des segments et des campagnes
- cree une vraie plateforme de routine metier plutot qu une simple addition d actions
