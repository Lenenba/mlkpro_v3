# Module Demandes - plan de transition en 3 etapes

Derniere mise a jour: 2026-04-25

## 1. Objet du document

Ce document definit un plan simple et efficace pour introduire un vrai module `Demandes` dans la plateforme, sans casser le travail deja livre sur `Prospects`.

Le point de depart est le suivant:

- le module `Prospects` doit rester un module CRM dedie au suivi commercial des non-clients
- le module `Demandes` doit devenir un module metier distinct pour toutes les demandes de service
- l'onglet `Demandes` dans la fiche client doit etre conserve

Ce document ne remplace pas le backlog prospects deja en place. Il sert a cadrer la separation `Prospects` / `Demandes` de maniere progressive.

## 2. Constat actuel dans le codebase

Aujourd'hui, le socle `requests` porte deja la logique prospect:

- `app/Models/Request.php`
  - pipeline CRM `REQ_NEW`, `REQ_CONTACTED`, `REQ_QUALIFIED`, `REQ_WON`, `REQ_LOST`, `REQ_CONVERTED`
  - assignee, relances, stale, conversion client
- `app/Models/Prospect.php`
  - alias logique sur la meme table `requests`
- `app/Http/Controllers/RequestController.php`
  - workspace prospects
- `app/Http/Controllers/PublicRequestController.php`
  - formulaire public qui cree encore un `LeadRequest`
- `app/Http/Controllers/Api/Integration/RequestController.php`
  - endpoint API qui cree encore un `LeadRequest`
- `app/Queries/Customers/BuildCustomerDetailViewData.php`
  - alimente l'onglet `Demandes` client avec des `requests` qui sont en realite des prospects
- `resources/js/Components/UI/CardNav.vue`
  - expose deja un onglet `Demandes` dans la fiche client
- `resources/js/Components/QuickCreate/QuickCreateModals.vue`
  - le `+` global du sidebar et du dashboard ouvre un modal `hs-quick-create-request`
- `resources/js/Components/QuickCreate/RequestQuickForm.vue`
  - la creation rapide "Nouvelle demande" poste encore vers `prospects.store`
  - elle sait aujourd'hui choisir un client existant ou creer un nouveau client
  - elle ne sait pas encore choisir un prospect existant
  - elle ne sait pas encore proposer clairement "nouveau prospect", "nouveau client" ou "demande sans rattachement"
- `resources/js/Pages/Customer/UI/Header.vue`
  - depuis la fiche client, le bouton demande pre-remplit le `customerId` dans le quick-create
- `app/Http/Controllers/CustomerController.php`
  - un client peut deja etre cree directement via le module client et via `storeQuick`
- `app/Services/Prospects/ProspectConversionService.php`
  - la transformation prospect -> client existe deja
  - elle supporte deux modes: creation d'un nouveau client ou liaison a un client existant

Conclusion:

- chez nous, `requests` ne doit plus etre considere comme le futur module `Demandes`
- `requests` doit rester le socle du module `Prospects`
- le module `Demandes` doit etre introduit comme un nouvel objet metier
- la creation rapide globale doit etre revue, car elle suppose encore trop qu'une demande passe par le flux prospect
- le cycle client doit accepter deux chemins legitimes:
  - creation directe de client
  - conversion d'un prospect en client

## 3. Regles de cadrage non negociables

- on conserve l'onglet `Demandes` dans la fiche client
- on ne fait pas de big bang rename de la table `requests`
- on ne casse pas le module `Prospects` deja livre
- une demande ne devient pas automatiquement un prospect
- une demande peut etre liee a un client, a un prospect, aux deux, ou a aucun au moment de la creation
- un client peut etre cree directement sans passer par un prospect
- la conversion `prospect -> client` est un chemin possible, mais pas le seul
- chaque demande doit garder une source claire: `customer_portal`, `public_form`, `campaign`, `manual_admin`, `api`
- la conversion `prospect -> client` reste une logique du module `Prospects`, pas du module `Demandes`
- le `+` global au-dessus du sidebar ne doit plus supposer qu'une "nouvelle demande" est un prospect
- le parcours "Nouvelle demande" doit proposer explicitement le contexte relationnel au moment de la creation

## 4. Architecture cible

### 4.1 Concepts

- `Prospect`
  - personne ou entreprise non encore cliente
  - suivie dans un pipeline commercial
- `ServiceRequest`
  - besoin, requete ou demande de service
  - independant du statut client ou prospect du demandeur

### 4.2 Relation attendue

Une demande de service pourra porter:

- `customer_id` nullable
- `prospect_id` nullable

Cela permet les cas suivants:

- un client connecte soumet une demande
- un prospect externe soumet une demande
- une demande manuelle est creee avant qualification
- une campagne marketing genere une demande qui doit ensuite etre rattachee

### 4.3 Table recommandee

Table recommandee: `service_requests`

Champs minimum:

- `id`
- `user_id`
- `customer_id`
- `prospect_id`
- `source`
- `channel`
- `status`
- `request_type`
- `service_type`
- `title`
- `description`
- `requester_name`
- `requester_email`
- `requester_phone`
- `street1`, `street2`, `city`, `state`, `postal_code`, `country`
- `source_ref`
- `source_meta`
- `submitted_at`
- `accepted_at`
- `completed_at`
- `cancelled_at`
- `meta`
- `created_at`, `updated_at`

Statuts recommandes:

- `new`
- `in_progress`
- `pending`
- `accepted`
- `refused`
- `completed`
- `cancelled`

### 4.4 Regle metier sur la creation d'un client

Chez nous, un client ne doit pas obligatoirement venir d'un prospect.

Les deux chemins doivent coexister:

- `creation directe client`
  - creation manuelle par un utilisateur interne
  - import
  - onboarding ou ouverture de compte
  - synchronisation API ou integration
- `conversion prospect -> client`
  - transformation d'un contact commercial qualifie
  - avec creation d'un nouveau client ou liaison a un client existant

Consequence importante:

- le module `Customers` ne doit pas etre reconstruit comme simple sous-produit des `Prospects`
- le module `Demandes` doit savoir se rattacher a un client cree directement
- l'historique doit permettre de distinguer si un client a ete cree directement ou issu d'une conversion prospect

Recommandation:

- garder une trace d'origine client dans `meta` ou dans l'audit, avec une valeur du type:
  - `direct_admin`
  - `prospect_conversion`
  - `import`
  - `portal`
  - `api`

### 4.5 Regle UX sur "Nouvelle demande" depuis le `+` global

Le `+` global au-dessus du sidebar, ainsi que les autres quick-create relies au meme modal, doivent etre alignes avec le nouveau modele.

Le parcours recommande pour `Nouvelle demande` est:

1. choisir le contexte relationnel
2. renseigner la demande
3. creer ou lier les entites necessaires

Choix minimum a proposer:

- lier a un `client existant`
- lier a un `prospect existant`
- `creer un nouveau client`
- `creer un nouveau prospect`
- `continuer sans rattachement` si la demande doit exister seule au depart

Regles de prefill recommandees:

- depuis la fiche client: preselectionner le client courant, tout en laissant la possibilite de changer
- depuis une future fiche prospect: preselectionner le prospect courant
- depuis le `+` global: partir sans hypothese par defaut

Point cle:

- le quick-create demande ne doit plus poster vers `prospects.store`
- il doit cibler le futur flux `service_requests`

## 5. Plan officiel en 3 etapes

### Etape 1 - Poser le nouveau socle sans casser l'existant

### Objectif

Creer le module `Demandes` en backend, tout en laissant l'UI actuelle stable.

### Travail attendu

- creer la table `service_requests`
- creer le modele `App\\Models\\ServiceRequest`
- ajouter les relations:
  - `Customer::serviceRequests()`
  - `Prospect::serviceRequests()`
- conserver `Customer::requests()` tel quel provisoirement pour compatibilite legacy
- ajouter les selects et queries necessaires pour pouvoir charger des demandes de service sur une fiche client
- cadrer des cette etape les modes de rattachement de demande:
  - client existant
  - prospect existant
  - nouveau client
  - nouveau prospect
  - aucun rattachement
- cadrer aussi la notion d'origine client directe vs conversion prospect
- ne pas encore debrancher les anciens flux `LeadRequest`

### Fichiers typiquement concernes

- `database/migrations/*create_service_requests_table*.php`
- `app/Models/ServiceRequest.php`
- `app/Models/Customer.php`
- `app/Models/Prospect.php`
- `app/Queries/Customers/BuildCustomerDetailViewData.php`
- `app/Queries/Customers/CustomerReadSelects.php`

### Resultat attendu

- le nouveau socle existe en base et en code
- l'onglet `Demandes` client existe toujours
- aucune regression sur le workspace `Prospects`

### Definition of done

- migration executee
- relations Eloquent disponibles
- tests modeles et lecture fiche client ajoutes

### Etape 2 - Faire entrer toutes les nouvelles demandes dans le nouveau module

### Objectif

Faire de `service_requests` le point d'entree de toutes les vraies demandes de service.

### Travail attendu

- remplacer la logique "Nouvelle demande" admin pour creer une `ServiceRequest`
- adapter le `+` global du sidebar et du dashboard pour ouvrir ce nouveau flux
- faire evoluer `RequestQuickForm.vue` en vrai quick-create demande avec choix:
  - client existant
  - prospect existant
  - nouveau client
  - nouveau prospect
  - sans rattachement
- faire evoluer le formulaire public pour creer une `ServiceRequest`
- faire evoluer l'API d'integration pour creer une `ServiceRequest`
- brancher les flux campagnes inbound sur `ServiceRequest`
- ajouter une couche de resolution metier:
  - si la personne est deja cliente, remplir `customer_id`
  - si elle n'est pas cliente mais doit entrer dans le pipeline commercial, lier ou creer `prospect_id`
  - si la demande reste purement operationnelle, ne pas forcer de prospect
- faire lire l'onglet `Demandes` client depuis `service_requests`

### Fichiers typiquement concernes

- `app/Http/Controllers/PublicRequestController.php`
- `app/Http/Controllers/Api/Integration/RequestController.php`
- nouveau controller type `ServiceRequestController`
- nouveaux `FormRequest` du module demandes
- service de resolution type `ServiceRequestPartyResolver`
- `app/Services/Campaigns/*`
- `app/Queries/Customers/BuildCustomerDetailViewData.php`
- `resources/js/Components/QuickCreate/QuickCreateModals.vue`
- `resources/js/Components/QuickCreate/RequestQuickForm.vue`
- `resources/js/Layouts/UI/Sidebar.vue`
- `resources/js/Pages/Dashboard.vue`
- `resources/js/Pages/Customer/UI/Header.vue`
- `resources/js/Components/UI/CardNav.vue`

### Resultat attendu

- toute nouvelle demande de service passe d'abord par `service_requests`
- le prospect devient une association optionnelle et explicite
- le `+` global permet enfin de choisir correctement entre client, prospect, creation ou absence de rattachement
- la fiche client conserve son onglet `Demandes`, mais il affiche enfin de vraies demandes

### Definition of done

- creation manuelle admin fonctionnelle
- creation publique fonctionnelle
- creation API fonctionnelle
- lecture fiche client fonctionnelle
- tests feature ajoutes sur admin, public, API et fiche client

### Etape 3 - Migrer l'existant et finaliser la separation

### Objectif

Terminer la transition en separant clairement les usages historiques.

### Travail attendu

- identifier dans les anciens `requests` ceux qui representent de vraies demandes de service
- backfiller ces enregistrements dans `service_requests`
- laisser dans `requests` uniquement le role de pipeline prospects legacy et CRM
- verifier que l'audit distingue bien:
  - les clients crees directement
  - les clients issus d'une conversion prospect
  - les demandes creees sans prospect
- ajuster les ecrans et labels pour que:
  - `Prospects` = suivi commercial
  - `Demandes` = demandes de service
- introduire si necessaire une section "historique legacy" temporaire dans la fiche client pour les anciens enregistrements non migrables
- preparer les adaptations futures sur devis, taches et timelines si elles doivent pointer vers `service_request_id`

### Fichiers typiquement concernes

- commande artisan de migration/backfill
- services de qualification legacy
- queries client et CRM
- ecrans Vue du detail client et des listes demandes
- documentation et backlog

### Resultat attendu

- separation metier nette entre `Prospects` et `Demandes`
- onglet client conserve
- dette de confusion semantique fortement reduite

### Definition of done

- script de backfill livre
- verification post-migration disponible
- fiche client stabilisee sur `service_requests`
- documentation mise a jour

## 6. Ordre d'execution recommande

Ordre recommande pour notre repo:

1. livrer l'etape 1 en entier
2. brancher d'abord la creation manuelle admin et la lecture fiche client
3. brancher ensuite le formulaire public et l'API
4. brancher ensuite les campagnes inbound
5. seulement apres, lancer la migration legacy

Pourquoi cet ordre:

- il preserve le module `Prospects` deja en production interne
- il permet de garder l'onglet client sans interruption
- il limite le risque de regression sur les formulaires publics et les integrations

## 7. Points de vigilance

- le nom `Request` est deja charge semantiquement et techniquement; il ne faut pas lui redonner un second sens
- l'onglet `Demandes` client doit etre conserve, mais sa source de donnees doit changer progressivement
- il faut distinguer `source` de la demande et `channel` de contact
- il faut conserver un snapshot demandeur meme quand `customer_id` ou `prospect_id` est rempli
- il ne faut pas forcer la creation d'un prospect pour toute demande
- il ne faut pas forcer la creation d'un prospect pour tout client non plus
- le quick-create global est aujourd'hui biaise vers client ou prospect legacy; il doit etre repense comme entree du module `Demandes`
- il faut garder l'attribution marketing existante reutilisable via `source_meta`

## 8. Recommandation immediate

Le meilleur prochain sprint est:

1. creer `service_requests`
2. brancher la relation sur la fiche client
3. conserver l'onglet `Demandes`
4. faire de la creation manuelle admin le premier flux pilote

Cela permet de valider la nouvelle architecture chez nous avec le plus faible risque possible.
