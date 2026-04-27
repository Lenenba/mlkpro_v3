# Module Prospects - Cahier des charges fonctionnel et technique

Derniere mise a jour: 2026-04-24

## 1. Objet du document

Ce document definit le cahier des charges fonctionnel et technique du futur module `Prospects`.

Le besoin metier est clair:

- une demande entrante ne doit plus creer automatiquement un client
- une demande entrante doit creer un prospect
- un client doit representer uniquement une personne ou une entreprise ayant reellement achete, commande, signe, paye, ou consomme un service
- l'historique pre-client doit rester consultable apres conversion

Le document tient compte de la base actuelle du projet et ne part pas d'une feuille blanche.

## 2. Contexte observe dans le codebase

La plateforme possede deja un socle CRM exploitable autour du module `Request` / `Lead`:

- `app/Models/Request.php`
  - statuts, assignee, `next_follow_up_at`, `lost_reason`, `converted_at`
  - relations `customer`, `quote`, `notes`, `media`, `tasks`
- `app/Http/Controllers/RequestController.php`
  - liste, detail, import CSV, assignation, merge, conversion en devis, bulk update
- `app/Http/Controllers/PublicRequestController.php`
  - creation de demandes publiques
  - logique actuelle de rattachement ou creation de `Customer` trop tot
- `app/Actions/Leads/ConvertLeadRequestToQuoteAction.php`
  - conversion `Request -> Quote`
  - creation d'un `Customer` si necessaire
- `app/Models/Quote.php`
  - depend encore d'un `customer_id` obligatoire pour creer un devis
- `app/Models/ActivityLog.php`
  - timeline polymorphique existante
- `routes/console.php`
  - rappels de suivi `leads:follow-up-reminders`

Le codebase contient aussi un autre concept de prospect:

- `app/Models/CampaignProspect.php`

Ce point est critique: il existe deja des prospects marketing/campagnes. Le nouveau module doit donc etre distingue clairement des `campaign_prospects`.

## 3. Probleme a resoudre

Aujourd'hui, une partie des flux entrants finit par polluer le module client:

- certains prospects sont rattaches a un `customer_id` des la creation de la demande
- le flux public de demande de soumission peut creer un client avant qu'il y ait une vraie conversion commerciale
- le module client devient un melange de vrais clients et de contacts qui n'ont jamais achete

Le resultat cible est le suivant:

- `Prospects` devient l'espace de travail commercial amont
- `Customers` reste le referentiel des relations post-conversion
- la conversion prospect -> client est explicite, journalisee, et conditionnee a des regles metier

## 4. Decision d'architecture recommandee

### 4.1 Decision produit

Le nouveau module produit doit s'appeler `Prospects`.

Dans l'UX:

- le menu, les pages, les widgets et les permissions visibles doivent parler de `Prospects`
- le terme `Request` doit sortir progressivement de l'interface metier

### 4.2 Decision technique

La strategie recommandee est une evolution de l'existant, pas un remplacement brutal.

Approche recommande:

- introduire une couche logique `Prospect` au-dessus du socle `Request`
- reutiliser d'abord la table `requests` comme stockage principal de transition
- garder la compatibilite technique avec le code existant pendant la migration

Concretement:

- nouveau modele recommande: `App\\Models\\CRM\\Prospect` ou `App\\Models\\Prospect`
- en phase de transition: ce modele pointe vers la table `requests`
- alias transitoire conserve pour le legacy: `App\\Models\\Request as LeadRequest`
- nouvelles classes recommandees:
  - `ProspectController`
  - `BuildProspectInboxIndexData`
  - `BuildProspectDashboardData`
  - `ProspectConversionService`
  - `ProspectMergeService`
  - `ProspectDuplicateDetectionService`

### 4.3 Pourquoi cette approche est la meilleure

Cette approche limite les regressions sur:

- les notes existantes (`lead_notes`)
- les documents existants (`lead_media`)
- les taches liees (`tasks.request_id`)
- les analytics CRM actuelles
- le pipeline `request -> quote -> work -> invoice`
- les integrations API et formulaires publics

Une creation greenfield de table `prospects` immediatement n'est pas recommandee en V1 car elle obligerait a rebrancher en une fois:

- `quotes.request_id`
- `tasks.request_id`
- `lead_notes.request_id`
- `lead_media.request_id`
- les queries CRM
- les scripts de rappel
- les APIs internes et publiques

## 5. Principes directeurs

- une demande entrante cree un prospect, jamais un client par defaut
- le module client doit rester propre et reserve aux relations converties
- l'historique du prospect est conserve integralement apres conversion
- les doublons sont detectes avant creation, conversion et fusion
- les donnees personnelles sont minimales, tracees et protegees
- les droits sont limites selon le role et le contexte
- les actions sensibles sont auditees

## 6. Perimetre fonctionnel

Le module `Prospects` doit couvrir:

- creation automatique a partir des demandes entrantes
- creation manuelle par un utilisateur interne
- import CSV
- enrichissement et qualification
- historique des interactions
- notes internes
- documents
- taches et relances
- assignation
- gestion des statuts
- gestion des prospects perdus
- gestion des doublons
- fusion
- conversion en client
- audit
- reporting
- notifications
- conformite donnees personnelles

Hors scope V1 recommande:

- scoring IA avance obligatoire
- enrichissement externe payant obligatoire
- automatisation omnicanale complexe
- suppression physique immediate par defaut

Ces points peuvent arriver en phases 2+.

## 7. Personae et roles

### 7.1 Administrateur

- voit tous les prospects
- cree, modifie, archive, anonymise, convertit, fusionne, exporte
- gere les regles de retention et les notifications
- voit l'audit complet

### 7.2 Gestionnaire

- voit les prospects de son perimetre
- assigne et re-assigne
- suit la performance de l'equipe
- convertit en client
- consulte les rapports

### 7.3 Representant commercial

- voit ses prospects et ceux partages avec lui selon droits
- met a jour les statuts autorises
- ajoute notes, interactions, relances, documents
- demande ou effectue une conversion selon permission

### 7.4 Agent support

- voit les informations utiles au traitement
- ajoute des notes et met a jour certaines informations
- n'exporte pas sans autorisation

## 8. User stories principales

### 8.1 Creation et intake

- US-01
  - En tant que visiteur public, quand je soumets un formulaire de demande, je veux qu'une fiche prospect soit creee afin que mon besoin soit suivi sans creer prematurement un client.
- US-02
  - En tant qu'utilisateur interne, je veux creer un prospect manuellement pour enregistrer un contact commercial recu par telephone, email ou rencontre.
- US-03
  - En tant qu'administrateur, je veux importer des prospects en CSV afin d'initialiser ou migrer des donnees.

### 8.2 Qualification et suivi

- US-04
  - En tant que commercial, je veux changer le statut d'un prospect pour refleter son avancement.
- US-05
  - En tant que commercial, je veux enregistrer une note interne, un appel, un email ou un rendez-vous afin de conserver l'historique.
- US-06
  - En tant que commercial, je veux planifier une relance avec date, heure, priorite et responsable.
- US-07
  - En tant que gestionnaire, je veux voir les prospects en retard de suivi ou a relancer aujourd'hui.

### 8.3 Collaboration et organisation

- US-08
  - En tant que gestionnaire, je veux assigner ou reassigner un prospect a un membre de l'equipe.
- US-09
  - En tant qu'utilisateur autorise, je veux joindre des documents a un prospect.
- US-10
  - En tant qu'utilisateur autorise, je veux fusionner deux prospects detectes comme doublons.

### 8.4 Conversion et cloture

- US-11
  - En tant que commercial autorise, je veux convertir un prospect en client quand une vraie conversion commerciale a eu lieu.
- US-12
  - En tant que commercial, je veux marquer un prospect comme perdu avec une raison et un commentaire.
- US-13
  - En tant qu'administrateur, je veux archiver ou anonymiser un prospect selon les regles de conservation.

### 8.5 Pilotage

- US-14
  - En tant que gestionnaire, je veux un tableau de bord prospects pour piloter le volume, le suivi, la conversion et les pertes.
- US-15
  - En tant qu'administrateur, je veux un journal d'audit des actions sensibles sur les prospects.

## 9. Regles metier fondamentales

### 9.1 Regle de creation

- toute demande entrante cree un prospect
- aucune demande entrante ne doit creer automatiquement un client
- si un doublon potentiel est detecte a la creation, le systeme cree le prospect avec drapeau `possible_duplicate` ou rattache a une revue de doublon selon configuration

### 9.2 Regle de conversion

Un prospect peut etre converti en client si au moins une condition est vraie:

- une soumission a ete acceptee
- un contrat a ete signe
- un paiement a ete recu
- une commande a ete creee et validee
- un service / work a ete planifie ou demarre

Exception recommande:

- un administrateur peut forcer la conversion avec motif obligatoire
- cette conversion forcee doit etre auditee

### 9.3 Regle de conservation

- un prospect converti n'est jamais supprime automatiquement
- un prospect archive reste consultable avec les bons droits
- un prospect anonymise conserve les meta-donnees utiles a l'audit mais masque les donnees personnelles

### 9.4 Regle de doublon

Les doublons potentiels doivent etre detectes sur:

- email exact
- telephone normalise
- nom complet exact ou proche
- nom d'entreprise exact ou proche
- adresse ou region selon disponibilite

Un doublon ne doit pas bloquer silencieusement:

- il doit produire une alerte
- il doit permettre le choix entre poursuivre, lier, fusionner ou annuler

### 9.5 Regle de statut

- toute transition de statut est journalisee
- certains statuts imposent des champs obligatoires
- `Perdu` impose une raison
- `Doublon` impose une reference de doublon ou une justification
- `Converti en client` impose l'identite du client cible et l'acteur de conversion

## 10. Statuts prospect cibles

Statuts fonctionnels retenus:

- Nouveau
- A qualifier
- Qualifie
- Contacte
- En discussion
- Soumission a preparer
- Soumission envoyee
- En attente de reponse
- A relancer
- Gagne
- Perdu
- Non qualifie
- Doublon
- Archive
- Converti en client

### 10.1 Mapping recommande depuis les statuts actuels `REQ_*`

- `REQ_NEW` -> `Nouveau`
- `REQ_CALL_REQUESTED` -> `Contacte` ou `A qualifier` selon `meta.final_action`
- `REQ_CONTACTED` -> `Contacte`
- `REQ_QUALIFIED` -> `Qualifie`
- `REQ_QUOTE_SENT` -> `Soumission envoyee`
- `REQ_WON` -> `Gagne`
- `REQ_LOST` -> `Perdu`
- `REQ_CONVERTED` -> `Converti en client`

### 10.2 Historique de statut

Chaque changement de statut doit enregistrer:

- ancien statut
- nouveau statut
- utilisateur
- date et heure
- commentaire optionnel
- raison obligatoire si statut `Perdu`, `Doublon`, `Archive`, `Converti`

## 11. Fiche prospect detaillee

La page detail doit afficher:

- identite du prospect
- coordonnees
- entreprise
- source
- type de demande
- message initial
- consentements
- statut actuel
- priorite
- responsable
- prochaine action
- score ou niveau de risque si present
- historique complet
- notes internes
- taches de relance
- documents
- demandes / soumissions / devis lies
- client lie si conversion deja faite

Actions visibles depuis la fiche:

- modifier statut
- assigner
- ajouter note
- journaliser interaction
- planifier relance
- televerser document
- convertir en client
- marquer perdu
- archiver
- fusionner

## 12. Historique des interactions

### 12.1 Types minimum

- appel telephonique
- courriel envoye
- courriel recu
- SMS
- rendez-vous
- note interne
- soumission envoyee
- relance effectuee
- changement de statut
- assignation
- document ajoute
- conversion en client
- prospect perdu
- prospect archive

### 12.2 Modele recommande

Chaque interaction contient:

- `prospect_id`
- `type`
- `occurred_at`
- `user_id`
- `description`
- `payload` JSON optionnel
- `attachment_id` ou media lie si applicable
- `next_action_at` optionnel
- `next_action_note` optionnel

### 12.3 Recommandation technique

Conserver `ActivityLog` comme timeline transverse visible dans l'interface, mais introduire une table dediee `prospect_interactions` pour:

- les filtres plus riches
- le reporting
- la robustesse de l'historique metier

Chaque interaction importante doit aussi ecrire un evenement dans `ActivityLog`.

## 13. Gestion des relances et taches

### 13.1 Regles fonctionnelles

Chaque prospect peut porter plusieurs taches de suivi:

- titre
- description
- date prevue
- heure prevue
- responsable
- statut
- priorite
- date de completion
- commentaire de completion

### 13.2 Recommandation technique

Le codebase possede deja `tasks.request_id`.

Strategie recommandee:

- V1: reutiliser `tasks` avec le lien prospect existant via `request_id`
- V2: introduire un alias logique `prospect_id` dans le code applicatif
- V3 optionnelle: migration physique du nom de colonne si necessaire

### 13.3 Vues requises

- taches dues aujourd'hui
- taches en retard
- taches par responsable
- prochaines actions par prospect

## 14. Liste des prospects

### 14.1 Colonnes requises

- nom complet
- entreprise
- courriel
- telephone
- source
- type de demande
- statut
- responsable
- priorite
- date de creation
- derniere activite
- prochaine relance
- actions rapides

### 14.2 Filtres requis

- statut
- responsable
- source
- type de demande
- date de creation
- date de derniere activite
- priorite
- non assignes
- a relancer aujourd'hui
- en retard
- gagnes
- perdus
- archives
- doublons

### 14.3 Modes d'affichage

- table
- board / kanban par statut
- vues rapides personnelles
- segments enregistres

### 14.4 Recommandation technique

La base actuelle `RequestTable.vue` et `RequestBoard.vue` peuvent etre reutilisees comme socle V1.

Les queries existantes `BuildRequestInboxIndexData` et `BuildRequestAnalyticsData` doivent etre remplacees ou aliasees par une couche `Prospect`.

## 15. Conversion prospect -> client

### 15.1 Exigences fonctionnelles

L'action `Convertir en client` doit:

- etre reservee aux utilisateurs autorises
- verifier les doublons clients avant creation
- permettre de lier a un client existant
- permettre de creer un nouveau client
- copier les donnees pertinentes
- conserver le prospect
- lier le prospect au client
- enregistrer l'acteur et la date

### 15.2 Decision technique majeure

Le code actuel impose `customer_id` obligatoire sur `Quote`.

Pour respecter la regle metier "pas client avant vraie conversion", la solution cible recommandee est:

- ajouter `prospect_id` nullable sur `quotes`
- rendre `quotes.customer_id` nullable tant que le devis n'est pas converti ou rattache a un vrai client
- adapter la generation du numero de devis pour qu'elle ne depenne plus exclusivement de `customer_id`

Sans ce changement, le systeme sera force de creer un `Customer` trop tot pour tout prospect demandant une soumission.

### 15.3 Implementation recommande

#### Option cible recommandee

- `quotes.customer_id` devient nullable
- `quotes.prospect_id` devient disponible
- `Quote` peut appartenir a un prospect avant conversion
- lors de la conversion:
  - si client existant choisi, on renseigne `customer_id`
  - sinon on cree le client et on renseigne `customer_id`
  - `prospect_id` reste renseigne pour l'historique

#### Option de secours non recommandee

- creer un "customer fantome" masque du module client

Cette option va a l'encontre du besoin metier. Elle ne doit etre retenue qu'en solution temporaire de tres court terme.

## 16. Gestion des prospects perdus

### 16.1 Raisons standard

- pas interesse
- budget insuffisant
- hors zone de service
- mauvais contact
- doublon
- aucun retour
- besoin non pertinent
- a choisi un concurrent
- autre

### 16.2 Regles

- passage a `Perdu` -> raison obligatoire
- commentaire recommande
- fermeture optionnelle des taches ouvertes avec demande de confirmation
- entree d'historique + audit obligatoire

## 17. Gestion des doublons et fusion

### 17.1 Detection

Detection en creation, edition, import et conversion sur:

- email
- telephone normalise
- nom complet
- entreprise
- adresse / ville / region

### 17.2 Fusion

Lors d'une fusion:

- l'utilisateur choisit un prospect principal
- toutes les notes sont conservees
- toutes les interactions sont conservees
- les taches ouvertes sont rattachees au prospect principal
- les documents sont rattaches au prospect principal
- le prospect secondaire passe en `Doublon` puis `Archive` technique ou `merged_into_prospect_id`
- aucune suppression definitive en V1
- l'action est journalisee

### 17.3 Blocages de fusion

- prospects de tenants differents
- conversion deja faite vers des clients differents sans arbitrage admin
- liens contradictoires vers devis / commandes / clients incompatibles

## 18. Permissions et securite

### 18.1 Permissions recommandees

- `prospects.view`
- `prospects.create`
- `prospects.edit`
- `prospects.assign`
- `prospects.convert`
- `prospects.merge`
- `prospects.archive`
- `prospects.export`
- `prospects.anonymize`
- `prospects.audit.view`

### 18.2 Compatibilite avec l'existant

Le codebase utilise deja une convention de permissions type:

- `requests.view`
- `quotes.view`
- `quotes.edit`
- `social.manage`

Strategie recommandee:

- introduire les nouvelles permissions `prospects.*`
- supporter temporairement une equivalence legacy:
  - `requests.view` -> `prospects.view`
  - `requests.edit` -> `prospects.edit`
- migrer ensuite les seeds, controles d'acces, sidebar et pages equipe

### 18.3 Matrice de droits

- Administrateur
  - tous les droits prospects
- Gestionnaire
  - `view`, `create`, `edit`, `assign`, `convert`, `merge`, `archive`, `audit.view`
- Representant commercial
  - `view`, `create`, `edit` sur ses prospects
  - `assign` non
  - `convert` selon configuration
- Agent support
  - `view`, `edit` partiel
  - pas d'export, pas de fusion, pas de conversion sans droit explicite

## 19. Conformite et protection des donnees

### 19.1 Exigences

- collecte minimale
- finalite explicite
- consentement de contact clair
- consentement marketing separe
- historique de consentement
- restrictions d'acces
- retention configurable
- anonymisation ou suppression controlee
- export journalise

### 19.2 Formulaire public

Le formulaire public doit afficher un texte clair du type:

- les informations sont utilisees pour traiter la demande et effectuer le suivi
- le consentement marketing est optionnel et separe
- la politique de confidentialite est accessible

### 19.3 Recommandation technique

Introduire une table `prospect_consents` ou `prospect_consent_history` avec:

- `prospect_id`
- `consent_type`
- `status`
- `captured_at`
- `source`
- `ip_address`
- `evidence_payload`

## 20. Journal d'audit

### 20.1 Actions a auditer

- creation du prospect
- modification des champs principaux
- changement de statut
- ajout / suppression de note
- assignation / reassignation
- conversion en client
- fusion
- archivage
- anonymisation / suppression
- export

### 20.2 Recommandation technique

Le projet possede deja:

- `ActivityLog` pour la timeline
- `PlatformAuditLog` pour le superadmin

Il est recommande d'ajouter une couche d'audit metier tenant-level avec:

- `user_id`
- `subject_type`
- `subject_id`
- `action`
- `before_values`
- `after_values`
- `ip_address`
- `user_agent`
- `created_at`

Cette couche peut etre:

- une extension de `ActivityLog`
- ou une nouvelle table generique `audit_logs`

Recommendation:

- `ActivityLog` pour la lecture metier
- `audit_logs` pour la tracabilite de conformite

## 21. Modele de donnees recommande

### 21.1 Prospect

Modele logique:

- `id`
- `tenant_id` / `user_id`
- `first_name`
- `last_name`
- `company_name`
- `email`
- `phone`
- `country`
- `state`
- `city`
- `street1`
- `street2`
- `postal_code`
- `source_channel`
- `source_type`
- `source_label`
- `request_type`
- `initial_message`
- `status`
- `priority`
- `assigned_team_member_id`
- `contact_consent`
- `contact_consent_at`
- `marketing_consent`
- `marketing_consent_at`
- `next_action_at`
- `next_action_type`
- `last_activity_at`
- `status_updated_at`
- `lost_reason_code`
- `lost_reason_comment`
- `duplicate_of_prospect_id`
- `merged_into_prospect_id`
- `archived_at`
- `archived_by_user_id`
- `archive_reason`
- `converted_to_customer_id`
- `converted_by_user_id`
- `converted_at`
- `conversion_trigger_type`
- `conversion_trigger_id`
- `metadata`
- `created_at`
- `updated_at`

### 21.2 Prospect status history

- `id`
- `prospect_id`
- `from_status`
- `to_status`
- `changed_by_user_id`
- `comment`
- `created_at`

### 21.3 Prospect interaction

- `id`
- `prospect_id`
- `type`
- `description`
- `occurred_at`
- `user_id`
- `attachment_media_id`
- `next_action_at`
- `payload`
- `created_at`

### 21.4 Prospect document

V1 recommande:

- reutiliser `lead_media`

V2 cible:

- `prospect_documents`

### 21.5 Prospect task

V1 recommande:

- reutiliser `tasks` avec lien prospect existant

Champs minimum:

- `prospect_id` logique
- `title`
- `description`
- `planned_for`
- `assignee_team_member_id`
- `status`
- `priority`
- `completed_at`
- `completion_comment`

### 21.6 Prospect customer link

- `id`
- `prospect_id`
- `customer_id`
- `link_type`
- `created_by_user_id`
- `created_at`

### 21.7 Prospect merge log

- `id`
- `primary_prospect_id`
- `merged_prospect_id`
- `merged_by_user_id`
- `reason`
- `snapshot`
- `created_at`

## 22. Ecrans a creer ou faire evoluer

### 22.1 Liste prospects

Base recommandee:

- faire evoluer `resources/js/Pages/Request/Index.vue`
- faire evoluer `RequestTable.vue`
- faire evoluer `RequestBoard.vue`
- renommer l'UX en `Prospects`

### 22.2 Fiche prospect

Base recommandee:

- faire evoluer `resources/js/Pages/Request/Show.vue`

Ajouts majeurs:

- bloc consentements
- bloc interactions
- bloc conversion client
- bloc fusion doublon
- bloc historique de statut

### 22.3 Modale de conversion

Fonctions:

- recherche de client existant
- alerte doublon
- creation nouveau client
- confirmation et choix du mode

### 22.4 Modale de perte

- raison
- commentaire
- action sur taches ouvertes

### 22.5 Modale de fusion

- selection du prospect source
- comparaison des champs
- choix du prospect principal
- resume des impacts

### 22.6 Dashboard prospects

Widgets:

- total
- nouveaux semaine
- nouveaux mois
- par statut
- par source
- par responsable
- relances aujourd'hui
- relances en retard
- taux de conversion
- top raisons de perte

## 23. Workflows cibles

### 23.1 Workflow inbound

1. Un visiteur soumet un formulaire public.
2. Le systeme detecte les doublons potentiels.
3. Le systeme cree un prospect.
4. Le systeme cree une interaction `initial_request`.
5. Le systeme journalise l'evenement.
6. Le systeme assigne si une regle d'assignation existe.
7. Le systeme planifie une premiere relance si necessaire.
8. Le systeme notifie le responsable.

### 23.2 Workflow qualification

1. Le commercial ouvre la fiche prospect.
2. Il ajoute une note ou une interaction.
3. Il met a jour le statut.
4. Il planifie la prochaine action.
5. Le systeme met a jour `last_activity_at`.
6. Le systeme journalise le changement.

### 23.3 Workflow soumission / devis

1. Un prospect qualifie passe en `Soumission a preparer`.
2. Un devis peut etre cree sans `customer_id` obligatoire.
3. Le devis est lie au prospect.
4. Le statut prospect passe a `Soumission envoyee` quand le devis est envoye.
5. Si le devis est accepte, le systeme propose ou execute la conversion en client selon regle.

### 23.4 Workflow conversion

1. L'utilisateur lance `Convertir en client`.
2. Le systeme recherche les doublons clients.
3. L'utilisateur choisit:
   - lier a un client existant
   - creer un nouveau client
4. Le systeme copie les donnees eligibles.
5. Le systeme lie prospect et client.
6. Le systeme met le statut a `Converti en client`.
7. Le systeme conserve l'historique du prospect.
8. Le systeme journalise l'action.

### 23.5 Workflow perte

1. L'utilisateur choisit `Marquer perdu`.
2. Le systeme demande une raison.
3. L'utilisateur ajoute un commentaire si necessaire.
4. Le systeme cloture ou laisse ouvertes les taches selon choix.
5. Le systeme journalise la perte.

## 24. Validations et cas limites

### 24.1 Validations

- email format correct si renseigne
- telephone normalise pour detection de doublon
- au moins un identifiant de contact exploitable si creation manuelle ou import
- raison obligatoire si `Perdu`
- commentaire obligatoire si conversion forcee
- droits verifies pour assignation, fusion, export, anonymisation, conversion
- interdiction de fusion inter-tenant
- interdiction de convertir deux fois le meme prospect

### 24.2 Cas limites

- prospect sans email ni telephone mais avec entreprise et source
- prospect deja converti puis reouvert par erreur
- deux utilisateurs qui lancent une conversion en meme temps
- fusion d'un prospect ayant deja un devis et d'un prospect archive
- client existant avec email identique mais telephone different
- import CSV avec lignes tres incompletes
- relance en retard sur prospect deja perdu ou archive
- anonymisation d'un prospect lie a un audit legal

## 25. Migration des donnees existantes

### 25.1 Objectif

Reclasser comme prospects les "clients" qui n'ont jamais vraiment converti.

### 25.2 Regles de decision recommandees

Un enregistrement reste `Customer` s'il a au moins un des elements suivants:

- une commande / vente
- un paiement
- une facture payee
- un work / service cree ou execute
- une soumission acceptee
- un contrat signe

Un enregistrement devient `Prospect` s'il a seulement:

- des demandes
- des notes commerciales
- des devis brouillon / envoyes non acceptes
- aucun paiement
- aucun work / service
- aucune commande reellement convertie

Un cas ambigu doit etre classe en:

- `A qualifier`

### 25.3 Pre-requis techniques a la migration

Avant de migrer les pseudo-clients vers prospects, il faut:

- supporter `Quote` lie a un prospect sans client
- ajouter les nouvelles colonnes de conversion
- garantir la compatibilite des timelines et taches

### 25.4 Etapes de migration recommandees

1. Dry run d'analyse
   - compter les clients reels
   - compter les clients probablement prospects
   - compter les cas ambigus
2. Ajouter les champs et tables cibles
3. Introduire la couche `Prospect`
4. Modifier les flux entrants pour creer des prospects
5. Migrer les pseudo-clients vers prospects
6. Rebrancher les devis ouverts vers `prospect_id` si necessaire
7. Verifier les dashboards et rapports
8. Activer les nouveaux ecrans `Prospects`
9. Conserver une periode de compatibilite legacy

### 25.5 Trace et rollback

- produire un rapport CSV avant/apres
- stocker les mappings `ancien_customer_id -> prospect_id`
- prevoir une possibilite de rollback par lot

## 26. Rapports et tableaux de bord

Indicateurs minimum:

- nombre total de prospects
- nouveaux cette semaine
- nouveaux ce mois
- prospects par statut
- prospects par source
- prospects par responsable
- a relancer aujourd'hui
- en retard
- taux de conversion prospect -> client
- gagnes
- perdus
- raisons de perte
- delai moyen de conversion
- performance par responsable

Definition recommande du taux de conversion principal:

- `prospects convertis / prospects crees sur la periode`

Indicateur avance optionnel:

- `prospects qualifies convertis / prospects qualifies`

## 27. Notifications

Notifications internes requises:

- nouveau prospect cree
- prospect assigne
- relance due aujourd'hui
- relance en retard
- prospect sans activite depuis X jours
- prospect converti
- prospect perdu

Recommandation technique:

- reutiliser `NotificationDispatcher`
- faire evoluer ou remplacer `LeadFollowUpNotification`
- renommer progressivement les commandes et jobs `lead*` vers `prospect*`

## 28. Integrations avec les autres modules

Le module Prospects doit s'integrer avec:

- formulaires publics de demande
- API integration requests
- clients
- devis / soumissions
- commandes / sales
- factures / paiements
- services / works
- taches
- documents
- notifications
- audit
- campagnes marketing

Attention forte:

- ne pas confondre `Prospect` CRM et `CampaignProspect`

Recommendation de nommage:

- `CRM Prospect` ou namespace `App\\Models\\CRM\\Prospect`
- `CampaignProspect` reste reserve a la prospection marketing

## 29. Impacts techniques majeurs

### 29.1 Backend

- nouveau service de domaine Prospect
- decouplage `PublicRequestController` de la creation client
- evolution `ConvertLeadRequestToQuoteAction`
- evolution `Quote` pour supporter `prospect_id`
- evolution des queries CRM et du pipeline
- nouveau moteur de detection de doublons
- nouvelles policies / permissions

### 29.2 Frontend

- nouvelle entree de navigation `Prospects`
- rename ou duplication progressive des pages `Request/*`
- nouveaux drawers / modales: conversion, perte, fusion
- filtres et vues additionnels
- dashboard prospects

### 29.3 Data

- nouvelles colonnes et tables
- nouveaux indexes sur:
  - `status`
  - `assigned_team_member_id`
  - `next_action_at`
  - `last_activity_at`
  - `email`
  - `phone_normalized`
  - `company_name`
  - `archived_at`

## 30. Plan de livraison recommande

### Phase 0 - Cadrage et compatibilite

- figer les definitions metier
- definir la nomenclature `Prospect`
- ajouter permissions `prospects.*`
- preparer les aliases legacy `requests.*`

### Phase 1 - Inbound prospect first

- modifier formulaires publics et API
- arreter la creation automatique de client
- creer / renommer les ecrans Prospects
- journaliser creation et statut initial

### Phase 2 - Detail, interactions et relances

- interactions
- historique de statut
- taches / relances
- notifications
- due today / overdue

### Phase 3 - Decouplage devis / client

- `quotes.prospect_id`
- `quotes.customer_id` nullable
- update `Quote` numbering
- rework des conversions actuelles

### Phase 4 - Conversion, perte, fusion

- wizard conversion
- wizard perdu
- wizard fusion
- duplicate detection

### Phase 5 - Migration data et dashboard

- analyse des clients existants
- migration pseudo-clients -> prospects
- dashboards et reporting

### Phase 6 - Conformite et audit avance

- retention
- anonymisation
- exports controles
- audit detaille avec before / after et IP

## 31. Strategie de tests et QA

Tests obligatoires:

- feature tests creation prospect publique
- feature tests API inbound
- tests de non-regression sur demandes existantes
- tests de permissions par role
- tests de conversion prospect -> client
- tests de fusion
- tests de doublons
- tests de migration dry run
- tests de reporting
- tests de notifications

Tests metiers critiques:

- une demande ne cree plus de client
- un devis prospect peut exister sans client
- un client n'apparait qu'au bon moment
- un prospect converti conserve son historique
- les rappels n'envoient pas de notification sur prospects fermes

## 32. Criteres d'acceptation globaux

Le module sera considere conforme si:

- les nouvelles demandes arrivent dans `Prospects`
- le module client n'est plus surcharge par des non-clients
- l'equipe commerciale peut qualifier, suivre, relancer et convertir
- les doublons sont visibles et gerables
- la conversion est tracable et reversible fonctionnellement
- les donnees restent conformes et auditees
- l'existant CRM / devis / taches ne regresse pas

## 33. Synthese executive

La meilleure trajectoire pour votre plateforme n'est pas de construire un module completement separe du socle CRM actuel.

La meilleure trajectoire est:

- de faire du module actuel `Request` le coeur logique du nouveau module `Prospects`
- de stopper toute creation prematuree de client
- de decoupler les devis du `customer_id` obligatoire
- de garder `Customer` comme referentiel post-conversion
- de migrer progressivement les faux clients vers des prospects

Autrement dit:

- `Prospects` doit devenir l'espace commercial amont
- `Customers` doit rester l'espace des relations reellement converties
