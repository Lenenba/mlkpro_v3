# Analyse concurrentielle CRM - Malikia Pro

Derniere mise a jour: 2026-04-20

## 1. Objectif

Ce document evalue, point par point, l'efficacite actuelle du CRM de Malikia Pro et sa capacite a concurrencer les solutions deja bien etablies.

Le but n'est pas de produire un discours marketing flatteur.

Le but est de repondre clairement a ces questions:

1. Est-ce que le CRM actuel est deja utile et efficace ?
2. Sur quels points est-il deja competitif ?
3. Sur quels points reste-t-il en retard ?
4. Contre quels concurrents peut-on se positionner des maintenant ?
5. Quelles evolutions donneraient le plus de valeur le plus vite ?

## 2. Methode

Cette analyse s'appuie sur:

- audit du repo local et des modules exposes dans l'application
- lecture des guides et documents produit internes
- comparaison avec les pages officielles de HubSpot, Pipedrive, Jobber, Housecall Pro, et ServiceTitan au 2026-04-20

Sources internes principales:

- `app/Http/Controllers/RequestController.php`
- `resources/js/Pages/Request/UI/RequestBoard.vue`
- `app/Http/Controllers/CustomerController.php`
- `app/Queries/Customers/BuildCustomerDetailViewData.php`
- `app/Http/Controllers/PipelineController.php`
- `app/Services/Customers/CustomerBulkContactService.php`
- `app/Services/Campaigns/CampaignAutomationService.php`
- `app/Http/Controllers/CampaignProspectingController.php`
- `docs/CAMPAIGNS_MODULE.md`
- `docs/NEXT_HIGH_VALUE_MODULES_USER_STORY.md`

## 3. Reponse courte

Oui, le CRM est deja efficace.

Mais il est efficace surtout comme:

- CRM operationnel pour entreprises de services
- systeme relie au flux `lead -> devis -> job -> facture`
- base client + suivi + execution + marketing

Il n'est pas encore au niveau des meilleurs CRM "sales-first" purs comme HubSpot ou Pipedrive sur:

- inbox commerciale native
- synchro email et calendrier
- call logging / telephonie commerciale
- gestion native d'un objet `deal` / `opportunity`
- sequences commerciales avancees
- forecast commercial et pilotage pipeline de vente pur

Conclusion simple:

- oui pour concurrencer des outils SMB orientés services ou "all-in-one"
- non, pas encore, pour concurrencer de facon frontale un CRM de vente pure moderne

## 4. Positionnement actuel du produit

Aujourd'hui, Malikia Pro n'est pas seulement un CRM.

C'est un systeme metier relie a plusieurs couches:

- acquisition de leads
- qualification commerciale
- devis
- execution terrain ou vente
- facturation et paiement
- campagnes marketing
- fidelite
- operations et planning

Cette largeur est une force reelle.

Le risque, en revanche, est de se comparer aux mauvais acteurs.

Si on se compare a:

- HubSpot Sales Hub
- Pipedrive
- Close
- Attio

on entre dans une bataille "sales workflow / inbox / email sync / sales engagement".

Si on se compare a:

- Jobber
- Housecall Pro
- une partie du positionnement ServiceTitan SMB / mid-market

alors le produit devient beaucoup plus credible, parce que la force de Malikia Pro est justement dans la connexion entre vente, operations, et facturation.

## 5. Analyse point par point

## 5.1 Leads / requests

Etat actuel:

- module `Request` reel et deja exploitable
- vue table + vue board / kanban
- suivi de `next_follow_up_at`
- import de leads
- merge de leads
- conversion lead -> quote
- analytics sur delai de premiere reponse
- analytics sur conversion par source
- vues de risque sur leads ouverts

Indices repo:

- `app/Http/Controllers/RequestController.php`
- `resources/js/Pages/Request/UI/RequestBoard.vue`
- `docs/demo/module-requests-demo-20min.md`

Verdict:

- bon niveau
- nettement au-dessus d'un simple carnet de demandes
- credible pour des petites et moyennes equipes commerciales / service

Limites:

- pas encore une vraie `Lead SLA Inbox` centralisee et priorisee comme un cockpit de triage
- pas encore un moteur avance d'assignation et de priorisation commerciale equivalent aux meilleurs CRM sales

## 5.2 Pipeline commercial

Etat actuel:

- pipeline visuel sur les requests
- transitions de statut visibles
- relances datees
- pont request -> quote -> work -> invoice via un pipeline transversal

Indices repo:

- `app/Http/Controllers/PipelineController.php`
- `resources/js/Pages/Request/UI/RequestBoard.vue`

Verdict:

- fort pour un pipeline metier oriente services
- utile pour visualiser l'avancement reel d'une opportunite

Limites:

- pas d'objet `Deal` ou `Opportunity` generaliste identifie dans `app/Models`
- pas de pipeline de vente "enterprise style" separe des demandes et du delivery
- pas de forecast commercial avance visible dans l'audit

Inference importante:

- l'absence de modele `Deal.php` ou `Opportunity.php` dans `app/Models` suggere que le pipeline est encore centre sur `Request`, `Quote`, `Work`, et non sur un moteur de deal pur

## 5.3 Fiche client et contexte 360

Etat actuel:

- fiche client avec activite
- notes et tags modifiables
- historique quotes / works / requests / invoices
- taches a venir
- jobs a venir
- paiements recents
- logique VIP et bridge vers campagnes

Indices repo:

- `app/Http/Controllers/CustomerController.php`
- `app/Queries/Customers/BuildCustomerDetailViewData.php`

Verdict:

- tres bon niveau pour une PME de services
- meilleur que beaucoup d'outils trop etroits qui se limitent a des fiches contacts pauvres

Limites:

- pas de vue conversationnelle unifiee email / appels / meetings comparable a un Sales Inbox moderne
- pas de journal natif d'appels commerciaux detecte

## 5.4 Quotes / devis

Etat actuel:

- devis bien integres au workflow
- conversion quote -> work
- emails de devis
- structure suffisamment forte pour etre un vrai point de vente

Indices repo:

- `routes/web.php`
- `docs/APP_GUIDE.md`
- `docs/MALIKIA_PRO_WEBSITE_COPY_PHASE_4_SALES_CRM.md`

Verdict:

- grosse force produit
- tres bon point d'ancrage commercial pour entreprises de services

Limites:

- il manque encore un `Quote Recovery Cockpit` dedie pour faire remonter les devis en attente, les relances et la probabilite de closing
- pas encore le niveau de sophistication d'outils comme Jobber ou Housecall Pro sur la mecanique pure de quote recovery

## 5.5 Handoff ventes -> operations

Etat actuel:

- c'est probablement l'une des plus grosses forces du produit
- la bascule demande -> devis -> job -> task -> invoice est deja lisible
- planning, taches, jobs, presence, reservations, equipe et factures partagent un meme systeme

Indices repo:

- `app/Http/Controllers/PipelineController.php`
- `routes/web.php`
- `resources/js/Layouts/UI/Sidebar.vue`

Verdict:

- excellent angle de concurrence
- tres bon levier de vente contre les outils qui cassent le flux entre CRM, devis, terrain, et paiement

## 5.6 Marketing, audience, campagnes, retention

Etat actuel:

- segments
- mailing lists
- templates
- preview / test send
- A/B par canal
- consentement
- anti-fatigue
- tracking
- VIP tiers
- bulk contact client
- prospecting B2B avec providers
- review workspace sur batches de prospects

Indices repo:

- `docs/CAMPAIGNS_MODULE.md`
- `app/Services/Customers/CustomerBulkContactService.php`
- `app/Services/Campaigns/CampaignAutomationService.php`
- `app/Http/Controllers/CampaignProspectingController.php`

Verdict:

- module deja plus riche que beaucoup de CRM SMB qui n'ont qu'un emailing basique
- tres bon potentiel de differenciation

Limites:

- le moteur commercial outbound existe, mais il est encore plus proche d'un "campaign + prospecting workspace" que d'un vrai sales engagement hub type HubSpot Sales / Apollo / Outreach
- le produit est fort en orchestration marketing/prospecting, moins en inbox quotidienne des reps

## 5.7 Automatisation

Etat actuel:

- triggers campagne deja presents
- automations autour du marketing
- fatigue / consent / queue run
- scoring d'interet

Indices repo:

- `app/Services/Campaigns/CampaignAutomationService.php`
- `routes/console.php`
- `docs/CAMPAIGNS_MODULE.md`

Verdict:

- bon socle
- suffisamment fort pour une promesse de productivite et de routine commerciale

Limites:

- il manque encore des playbooks transverses reutilisables sur plusieurs modules
- le document interne reconnait lui-meme que `Saved Segments and Scheduled Playbooks` est une priorite haute

## 5.8 Communication commerciale native

Etat actuel:

- envoi emails et SMS present sur certaines briques
- relances et bulk contact disponibles
- webhooks campagne email / sms

Verdict:

- utile, mais pas encore un cockpit de communication commerciale complet

Limites observees dans l'audit:

- aucune synchro email native evidente type Gmail / Outlook bidirectionnelle
- aucun `sales inbox` natif trouve
- aucun module natif de meeting scheduler detecte
- aucun call logging commercial ou VoIP interne clairement present

Important:

- ceci ne veut pas dire "impossible"
- cela veut dire "non visible comme capacite produit mature a ce stade"

## 5.9 Reporting et pilotage

Etat actuel:

- analytics leads
- KPI marketing
- performance
- finance / accounting en progression
- pipeline derive pour entites metier

Verdict:

- bon niveau global pour une plateforme en croissance
- meilleur en lecture operationnelle et transversale qu'en lecture purement commerciale forecast / rep productivity

Limites:

- pas encore de vrai forecast de pipeline commercial pur visible dans l'audit
- pas de pilotage avance par deal owner / meeting / activity / stage aging comme sur HubSpot ou Pipedrive

## 6. Comparaison par concurrent

## 6.1 HubSpot Sales Hub

HubSpot est plus fort sur:

- sales inbox
- sequences commerciales
- routage de leads
- synchro email / calendrier
- call logging
- meetings
- pipeline de deals
- reporting de vente pur

Malikia Pro est plus fort ou plus coherent sur:

- continuité metier services
- lien direct entre demande, devis, terrain et facture
- cohesion operations + marketing + billing dans une seule app

Conclusion:

- ne pas se vendre comme "nouveau HubSpot"
- se vendre comme systeme plus concret et plus operationnel pour entreprises de services

## 6.2 Pipedrive

Pipedrive est plus fort sur:

- pipeline de deals pur
- email sync
- activities commerciales quotidiennes
- automation centrée vente

Malikia Pro est plus fort sur:

- profondeur metier apres la vente
- execution et livraison
- connexion devis / jobs / taches / factures

Conclusion:

- Pipedrive gagne sur la pure vente
- Malikia Pro gagne si le client veut un systeme qui ne s'arrete pas au deal

## 6.3 Jobber

Jobber est tres proche du terrain cible.

Jobber est plus fort sur:

- quote follow-up mis en avant produit
- maturite verticale home services
- experience commerciale deja tres structuree autour des estimations et approbations

Malikia Pro est potentiellement plus fort sur:

- largeur plateforme
- marketing / campaigns / VIP / prospecting
- modularite multi-domaines

Conclusion:

- Jobber est un concurrent credible et dangereux sur le SMB service
- Malikia Pro peut concurrencer si le discours met en avant la couche plus large et plus modulaire

## 6.4 Housecall Pro

Housecall Pro est plus fort sur:

- pipeline vente visible
- voice / call tracking
- automatisation de suivis estimates / invoices
- experience field-service tres marketee

Malikia Pro est plus fort sur:

- profondeur structurelle globale du produit
- trajectoire plus riche sur marketing, prospecting, loyalty, finance, accounting

Conclusion:

- Housecall Pro est probablement plus lisible commercialement aujourd'hui
- Malikia Pro peut devenir plus complet si les manques de couche "commerciale quotidienne" sont combles

## 6.5 ServiceTitan

ServiceTitan est plus fort sur:

- CRM commercial pour contractors matures
- activity logging commercial
- task management commercial
- sales intelligence
- quoting connecte a des workflows plus industrialises

Malikia Pro est plus leger et plus flexible sur:

- approche SMB / mid-market plus accessible
- modularite plus simple a raconter

Conclusion:

- ne pas se comparer frontalement a ServiceTitan en haut de gamme
- se positionner plutot comme une plateforme plus accessible, plus simple, et plus large pour structures plus petites ou en croissance

## 7. Score synthese

Notation:

- Fort
- Moyen
- Faible

### 7.1 Evaluation de Malikia Pro aujourd'hui

- Leads / requests: Fort
- Board / visual pipeline sur les demandes: Fort
- Fiche client 360 pour services: Fort
- Devis et passage a l'execution: Fort
- Lien ventes -> operations -> facturation: Fort
- Segmentation / campagnes / retention: Fort
- Prospecting provider workspace: Moyen a Fort
- Automatisations metier: Moyen
- Reporting commercial pur: Moyen
- Sales engagement quotidien: Faible a Moyen
- Email sync / calendar sync / sales inbox: Faible
- Telephonie commerciale / call logging natif: Faible
- Deal management generaliste enterprise style: Faible

## 8. Ce que le produit peut deja vendre fort

Le produit peut deja promettre, de facon credible:

1. Centraliser la demande, le devis, le suivi client, et la facturation dans un meme flux.
2. Rendre visible le pipeline commercial de demandes et les relances.
3. Eviter les pertes de contexte entre bureau, terrain, et finance.
4. Donner une vraie base client exploitable avec activite, historique et prochaines actions.
5. Ajouter une couche marketing et outreach plus forte que beaucoup d'outils SMB.

## 9. Ce qu'il faut eviter de promettre aujourd'hui

Il faut eviter de promettre, sans nuance:

1. un remplaçant direct de HubSpot Sales Hub
2. un remplaçant direct de Pipedrive pour equipes de vente pures
3. une inbox commerciale moderne complete
4. une gestion native avancee des appels, meetings, et emails synchronises
5. un moteur complet de forecast commercial enterprise

## 10. Les plus gros trous produit a combler

Les trous qui limitent aujourd'hui le plus la competitivite CRM sont:

1. Lead SLA Inbox and Smart Triage
2. Quote Recovery and Conversion Cockpit
3. Saved Segments and Scheduled Playbooks
4. objet `Deal` / `Opportunity` plus generaliste
5. journal commercial unifie: appels, emails, notes, meetings
6. sync email / calendrier
7. sales inbox et prochaines actions quotidiennes

Ces priorites sont coherentes avec le document interne:

- `docs/NEXT_HIGH_VALUE_MODULES_USER_STORY.md`

## 11. Recommandation produit

Si l'objectif est de renforcer la competitivite vite, l'ordre recommande est:

### Priorite 1

Construire `Lead SLA Inbox and Smart Triage`

Pourquoi:

- impact direct sur conversion
- valeur visible tres vite
- renforce le module Requests deja bon

### Priorite 2

Construire `Quote Recovery and Conversion Cockpit`

Pourquoi:

- impact revenu direct
- s'appuie sur le module Quote deja fort
- rapproche le produit de Jobber / Housecall Pro sur un point concret

### Priorite 3

Construire `Saved Segments and Scheduled Playbooks`

Pourquoi:

- multiplie la valeur des modules deja existants
- transforme des actions en routines
- avantage cross-module fort

### Priorite 4

Ajouter une couche "sales activity"

Par exemple:

- notes d'appels structurees
- prochaines actions
- file de suivi
- activite commerciale quotidienne

### Priorite 5

Ajouter sync email / calendrier et, si possible plus tard, call logging

Pourquoi:

- c'est la vraie marche a gravir pour concurrencer les CRM sales-first

## 12. Recommandation go-to-market

Le meilleur positionnement actuel n'est pas:

- "le meilleur CRM de vente du marche"

Le meilleur positionnement actuel est:

- "la plateforme qui relie la demande, le devis, les operations et le revenu"
- "un CRM operationnel pour equipes de services"
- "une alternative plus concrete aux outils qui gerent le lead mais cassent la suite du travail"

Message fort possible:

- "Moins de perte entre la demande, le devis, l'execution et la facture."

Message a eviter:

- "Le nouveau HubSpot pour toutes les equipes commerciales."

## 13. Conclusion finale

Le CRM de Malikia Pro est deja bon.

Il n'est pas "petit".

Il est deja utile, structure, et credible.

Sa force n'est pas d'etre le meilleur outil de vente pure.

Sa force est d'etre un systeme commercial et operationnel connecte, particulierement pertinent pour les entreprises de services qui ont besoin de:

- capter la demande
- suivre les opportunites
- envoyer des devis
- executer le travail
- facturer proprement
- relancer et fideliser

Le produit peut donc deja concurrencer certains acteurs du marche.

Mais la concurrence la plus realiste, aujourd'hui, est:

- Jobber
- Housecall Pro
- des outils SMB plus generalistes mal relies entre eux

La concurrence frontale contre HubSpot ou Pipedrive reste prematuree tant que la couche:

- inbox
- email sync
- calendar sync
- deal activity
- sales engagement quotidien

n'est pas encore montee d'un cran.

## 14. Sources externes

Sources officielles utilisees:

- HubSpot Sales Automation: `https://www.hubspot.com/products/sales/sales-automation`
- HubSpot Sales Tools: `https://www.hubspot.com/products/sales/sales-tools`
- HubSpot Call Logging: `https://www.hubspot.com/products/call-logging-app`
- Pipedrive CRM: `https://www.pipedrive.com/`
- Pipedrive Email Integration: `https://www.pipedrive.com/en/features/email-integration`
- Jobber Field Service CRM: `https://www.getjobber.com/features/field-service-crm/`
- Jobber Quotes: `https://www.getjobber.com/features/quotes/`
- Housecall Pro Features: `https://www.housecallpro.com/features/`
- Housecall Pro Pipeline: `https://www.housecallpro.com/features/pipeline/`
- Housecall Pro Field Service CRM: `https://www.housecallpro.com/features/field-service-crm-software/`
- ServiceTitan Field Service CRM: `https://www.servicetitan.com/features/field-service-crm`

## 15. Sources internes

- `app/Http/Controllers/RequestController.php`
- `resources/js/Pages/Request/UI/RequestBoard.vue`
- `app/Http/Controllers/CustomerController.php`
- `app/Queries/Customers/BuildCustomerDetailViewData.php`
- `app/Http/Controllers/PipelineController.php`
- `app/Services/Customers/CustomerBulkContactService.php`
- `app/Services/Campaigns/CampaignAutomationService.php`
- `app/Http/Controllers/CampaignProspectingController.php`
- `docs/CAMPAIGNS_MODULE.md`
- `docs/NEXT_HIGH_VALUE_MODULES_USER_STORY.md`
