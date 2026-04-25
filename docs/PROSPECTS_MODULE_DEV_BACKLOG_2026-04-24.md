# Module Prospects - plan de dev pas a pas

Derniere mise a jour: 2026-04-25

## 0. Etat d'avancement implementation

Suivi courant:

- `PROSPECT-000` fait: cahier des charges redige dans `docs/PROSPECTS_MODULE_CAHIER_DES_CHARGES_2026-04-24.md`
- `PROSPECT-001` a `PROSPECT-003` faits: couche logique `Prospect`, permissions alias et routes / navigation transitoires deja branchees
- `PROSPECT-101` a `PROSPECT-105` faits: creation inbound / API / backoffice et import CSV en mode prospect-first deja couverts dans le workspace
- `PROSPECT-201` a `PROSPECT-204` faits: workspace prospects V1, filtres, actions rapides et fiche detail sont consolides et couverts par tests
- `PROSPECT-301` fait: historique de statuts prospect journalise sur les creations et transitions principales, expose sur la fiche detail et couvert par tests
- `PROSPECT-302` fait: couche `prospect_interactions` branchee sur notes, documents, activites CRM et emails, exposee sur la fiche detail et couverte par tests
- `PROSPECT-303` fait: taches de relance prospects formalisees avec priorites, filtres today / overdue et liaison explicite des taches au prospect
- `PROSPECT-304` fait: rappels automatiques sur relances prospects livres avec commande planifiee, notification CRM et audit
- `PROSPECT-401` fait: moteur de detection de doublons prospects livre avec score, raisons et classement sur email, telephone, nom, entreprise et adresse
- `PROSPECT-402` fait: alertes de doublon branchees sur creation backoffice, import CSV, conversion et formulaire public avec confirmation explicite pour continuer
- `PROSPECT-403` fait: fusion non destructive des prospects livree avec transfert notes / interactions / documents, rattachement des taches ouvertes, archivage technique du doublon et audit
- `PROSPECT-501` fait: `quotes.prospect_id` ajoute, `quotes.customer_id` rendu nullable et retro-compatibilite de liaison prospect assuree
- `PROSPECT-502` fait: numerotation des devis scopee par tenant et relation `quote->prospect` ajoutees sans casser les anciens liens `request_id`
- `PROSPECT-702` partiel: archivage V1 livre avec filtre archives, restauration, audit et mode lecture seule; anonymisation V1 archive-only est maintenant branchee, la suppression logique controlee reste a faire
- le socle existant repose encore sur `Request` / `Lead`
- le socle `Quote / Prospect` est maintenant pret; les flux de creation prospect -> devis restent a rebrancher sans client force

Point cle:

- on peut commencer l'implementation sans attendre la refonte totale
- en revanche, on ne doit pas casser le flux actuel `request -> quote`
- le decouplage `quote / customer` doit etre traite avant la vraie conversion prospect-first de bout en bout

## 1. But du document

Ce document n'est pas un cahier des charges.

C'est un document d'execution dev.

Son but est de transformer la vision du module `Prospects` en ordre de travail concret:

1. quoi faire d'abord
2. quoi ne pas faire trop tot
3. quels fichiers toucher
4. quels tests ajouter
5. quels tickets cloturer avant de passer a l'etape suivante

## 2. Comment utiliser ce document

Regle de pilotage:

1. prendre une phase
2. prendre ses tickets dans l'ordre
3. livrer le scope V1 de la phase
4. lancer les verifications de la phase
5. seulement ensuite ouvrir la phase suivante

Statuts recommandes:

- `a faire`
- `en cours`
- `bloque`
- `fait`
- `partiel`
- `reporte`

Regle importante:

- un ticket ne passe en `fait` que si le code est livre, les acceptance criteria sont couverts, et les tests cibles sont executes

## 3. Regles de dev non negociables

- une demande entrante ne doit plus creer automatiquement un client
- le terme produit cible est `Prospects`, pas `Requests`, mais on garde une compatibilite technique transitoire
- on ne fait pas de big bang rename de la table `requests` en V1
- on ne confond jamais le futur module CRM `Prospects` avec `CampaignProspect`
- les routes et ecrans legacy `Request` peuvent rester actifs pendant la transition
- toute action sensible doit laisser une trace exploitable dans l'historique ou l'audit
- toute evolution doit rester tenant-safe
- avant de finaliser la conversion prospect -> client, il faut decoupler la creation de devis de l'obligation de creer un client

## 4. Ordre d'execution officiel

L'ordre recommande est:

1. Phase 0 - fondation, vocabulaire, permissions, compatibilite
2. Phase 1 - creation prospect-first sur les flux entrants
3. Phase 2 - workspace Prospects V1 dans l'UI
4. Phase 3 - historique, interactions et relances
5. Phase 4 - detection de doublons et fusion
6. Phase 5 - decouplage `Quote` / `Customer`
7. Phase 6 - conversion prospect -> client
8. Phase 7 - conformite, audit, archivage, perte
9. Phase 8 - dashboard et notifications
10. Phase 9 - migration des donnees existantes

Regle:

- on peut preparer une phase suivante tant que la phase courante n'est pas fermee
- on n'implemente pas la vraie conversion finale avant la Phase 5
- on ne migre pas les donnees existantes avant d'avoir un flux cible stable

## 5. Vue d'ensemble des dependances

### Phase 0

Depend de:

- rien

Bloque:

- toutes les autres phases

### Phase 1

Depend de:

- Phase 0

Bloque:

- la bascule propre des flux entrants vers `Prospects`

### Phase 2

Depend de:

- Phase 0
- idealement Phase 1

Bloque:

- la mise en production d'un module visible et exploitable par l'equipe

### Phase 3

Depend de:

- Phase 1
- Phase 2

Bloque:

- la vraie valeur operationnelle du suivi prospect

### Phase 4

Depend de:

- Phase 1
- Phase 3

Bloque:

- la qualite de donnees
- la conversion propre

### Phase 5

Depend de:

- Phase 1

Bloque:

- Phase 6
- la promesse "pas de client avant vraie conversion"

### Phase 6

Depend de:

- Phase 4
- Phase 5

Bloque:

- la conversion prospect -> client

### Phase 7

Depend de:

- Phase 3
- Phase 6

Bloque:

- la conformite complete et l'industrialisation

### Phase 8

Depend de:

- Phase 3
- Phase 6

Bloque:

- le pilotage business et commercial

### Phase 9

Depend de:

- Phase 6
- Phase 7

Bloque:

- le nettoyage du stock historique

## 6. Fichiers coeur a proteger

Backend:

- `app/Models/Request.php`
- `app/Models/Quote.php`
- `app/Http/Controllers/PublicRequestController.php`
- `app/Http/Controllers/Api/Integration/RequestController.php`
- `app/Http/Controllers/RequestController.php`
- `app/Actions/Leads/ConvertLeadRequestToQuoteAction.php`
- `app/Queries/Requests/BuildRequestInboxIndexData.php`
- `app/Queries/Requests/BuildRequestAnalyticsData.php`
- `app/Support/CRM/OpportunitySchema.php`
- `app/Models/ActivityLog.php`
- `app/Http/Controllers/TeamMemberController.php`
- `routes/web.php`
- `routes/api.php`
- `routes/console.php`

Frontend:

- `resources/js/Pages/Request/Index.vue`
- `resources/js/Pages/Request/Show.vue`
- `resources/js/Pages/Request/UI/RequestTable.vue`
- `resources/js/Pages/Request/UI/RequestBoard.vue`
- `resources/js/Pages/Request/UI/RequestAnalytics.vue`
- `resources/js/Layouts/UI/Sidebar.vue`
- `resources/js/utils/workspaceHub.js`
- `resources/js/i18n/modules/en/team.json`
- `resources/js/i18n/modules/fr/team.json`
- `resources/js/i18n/modules/en/*requests*`
- `resources/js/i18n/modules/fr/*requests*`

Attention:

- `app/Models/CampaignProspect.php` existe deja et ne doit pas etre casse par le module CRM `Prospects`

## 7. Suites de tests a creer

Suites recommandees:

- `tests/Feature/ProspectsInboundPublicTest.php`
- `tests/Feature/ProspectsInboundApiTest.php`
- `tests/Feature/ProspectWorkspaceFeatureTest.php`
- `tests/Feature/ProspectStatusHistoryTest.php`
- `tests/Feature/ProspectInteractionTimelineTest.php`
- `tests/Feature/ProspectFollowUpTaskTest.php`
- `tests/Feature/ProspectDuplicateMergeTest.php`
- `tests/Feature/ProspectQuoteBridgeTest.php`
- `tests/Feature/ProspectConversionTest.php`
- `tests/Feature/ProspectAuditComplianceTest.php`
- `tests/Feature/ProspectMigrationCommandTest.php`

Principe:

- ne pas seulement patcher les anciens tests `Request`
- creer des suites dediees qui documentent la nouvelle intention produit

## 8. Sprint de depart recommande

Premier lot a lancer des maintenant:

1. `PROSPECT-001`
2. `PROSPECT-002`
3. `PROSPECT-101`
4. `PROSPECT-102`
5. `PROSPECT-103`
6. `PROSPECT-104`
7. `PROSPECT-105`

Resultat attendu a la fin de ce premier lot:

- les flux entrants creent toujours un enregistrement exploitable
- l'UI et les permissions commencent a parler de `Prospects`
- aucune creation automatique de client ne subsiste sur les entrees simples
- les bases du suivi historique sont posees

## 9. Phase 0 - fondation, vocabulaire, permissions, compatibilite

Objectif business:

- preparer le terrain sans casser l'existant

Objectif technique:

- introduire une couche `Prospect` visible dans le code et dans l'interface, tout en gardant le stockage et les flux legacy stables

### `PROSPECT-001` - Introduire la couche logique Prospect au-dessus de Request

- Statut: `fait`
- But: creer le point d'entree logique du nouveau module sans migrer la table en V1
- Livrables:
- nouveau modele ou alias clair `App\\Models\\Prospect` ou `App\\Models\\CRM\\Prospect`
- conventions de statuts et labels centralisees
- premier mapping de vocabulaire `request -> prospect`
- Fichiers probables:
- `app/Models/Prospect.php` ou `app/Models/CRM/Prospect.php`
- `app/Models/Request.php`
- `app/Support/CRM/*`
- Notes d'implementation:
- le nouveau modele doit pointer vers `requests` en phase transitoire
- ne pas supprimer l'alias legacy `Request as LeadRequest`
- Definition de done:
- le code peut referencer un `Prospect` sans casser les usages `Request`

### `PROSPECT-002` - Ajouter les permissions `prospects.*` avec compatibilite legacy

- Statut: `fait`
- But: preparer les droits d'acces avant d'exposer le module dans l'UI
- Livrables:
- permissions `prospects.view`, `prospects.create`, `prospects.edit`, `prospects.assign`, `prospects.convert`, `prospects.merge`, `prospects.export`
- mapping transitoire possible depuis `requests.*` si necessaire
- Fichiers probables:
- `app/Http/Controllers/TeamMemberController.php`
- `tests/Feature/TeamMemberPermissionModulesTest.php`
- `resources/js/Pages/Team/UI/TeamTable.vue`
- `resources/js/i18n/modules/en/team.json`
- `resources/js/i18n/modules/fr/team.json`
- Definition de done:
- un compte equipe peut recevoir des permissions prospects sans regression sur les autres modules

### `PROSPECT-003` - Introduire la surface produit Prospects dans la navigation

- Statut: `fait`
- But: rendre le module visible sans couper l'acces a l'existant
- Livrables:
- entree menu `Prospects`
- alias de routes `prospects.*`
- labels de workspace mis a jour
- Fichiers probables:
- `routes/web.php`
- `resources/js/Layouts/UI/Sidebar.vue`
- `resources/js/utils/workspaceHub.js`
- `resources/js/i18n/modules/en/*`
- `resources/js/i18n/modules/fr/*`
- Notes d'implementation:
- garder les routes `requests.*` en parallele au debut
- Definition de done:
- un owner ou team member autorise voit `Prospects` dans l'UI et tombe sur le workspace existant sans rupture

Definition de done de phase:

- la terminologie `Prospects` existe dans le code et dans l'UI
- les permissions dediees existent
- l'existant `Request` reste operant

## 10. Phase 1 - creation prospect-first sur les flux entrants

Objectif business:

- toute nouvelle demande doit creer un prospect et non un client

Objectif technique:

- retirer les resolutions automatiques de `customer_id` des flux entrants qui n'ont pas encore de vraie conversion

### `PROSPECT-101` - Arreter la creation implicite de client dans le formulaire public

- Statut: `fait`
- But: faire de `PublicRequestController` un createur de prospect, pas de client
- Fichiers probables:
- `app/Http/Controllers/PublicRequestController.php`
- `app/Models/Request.php`
- `tests/Feature/ProspectsInboundPublicTest.php`
- Points d'attention:
- aujourd'hui `resolveCustomerId()` et `resolveOrCreateCustomerForLead()` polluent le flux
- la branche `receive_quote` devra etre isolee proprement pendant la transition
- Acceptance criteria:
- une demande "contact" cree un prospect sans `customer_id`
- le consentement et la source sont conserves
- une entree d'historique initiale est journalisee

### `PROSPECT-102` - Arreter la liaison client automatique dans l'API d'integration

- Statut: `fait`
- But: rendre l'API entrante coherente avec le flux public
- Fichiers probables:
- `app/Http/Controllers/Api/Integration/RequestController.php`
- `tests/Feature/ProspectsInboundApiTest.php`
- Acceptance criteria:
- l'API cree un prospect meme si email ou telephone correspondent a un client existant
- les doublons sont signales plus tard, pas absorbes silencieusement

### `PROSPECT-103` - Corriger la creation manuelle et l'import CSV cote backoffice

- Statut: `fait`
- But: empecher le backoffice de rattacher un client par defaut lors de la creation d'un prospect
- Fichiers probables:
- `app/Http/Controllers/RequestController.php`
- `app/Http/Requests/Leads/StoreLeadRequest.php`
- `app/Http/Requests/Leads/ImportLeadRequestsRequest.php`
- `tests/Feature/ProspectWorkspaceFeatureTest.php`
- Acceptance criteria:
- creation manuelle sans client obligatoire
- import CSV sans creation client implicite

### `PROSPECT-104` - Normaliser les champs metier minimum du prospect

- Statut: `fait`
- But: assurer que chaque entree stocke les informations minimum du cahier des charges
- Livrables:
- source de la demande
- type de demande
- consentement contact
- consentement marketing
- date de derniere activite
- responsable assigne si present
- Fichiers probables:
- `app/Models/Request.php`
- migrations additives si besoin
- `app/Http/Controllers/PublicRequestController.php`
- `app/Http/Controllers/RequestController.php`
- Notes d'implementation:
- utiliser `meta` seulement pour ce qui n'a pas encore de colonne stable
- Definition de done:
- le prospect contient le minimum metier exploitable sans bricolage cote front

### `PROSPECT-105` - Ajouter les tests de non regression des flux entrants

- Statut: `fait`
- But: verrouiller la nouvelle regle "prospect d'abord"
- Fichiers probables:
- `tests/Feature/ProspectsInboundPublicTest.php`
- `tests/Feature/ProspectsInboundApiTest.php`
- `tests/Feature/WorkflowLeadTest.php`
- Definition de done:
- les cas public, API, creation manuelle et import sont couverts

Definition de done de phase:

- les demandes entrantes standard ne creent plus de client
- les prospects sont crees avec les champs minimums attendus
- le flux legacy n'est pas casse pour les autres usages

## 11. Phase 2 - workspace Prospects V1 dans l'UI

Objectif business:

- donner a l'equipe un vrai espace de travail prospect

Objectif technique:

- reutiliser l'UI `Request` existante en la faisant evoluer vers le vocabulaire, les filtres et les actions Prospects

### `PROSPECT-201` - Renommer l'experience `Request` en `Prospects`

- Statut: `fait`
- But: faire disparaitre le terme metier `Request` de l'interface principale
- Fichiers probables:
- `resources/js/Pages/Request/Index.vue`
- `resources/js/Pages/Request/Show.vue`
- `resources/js/i18n/modules/en/*`
- `resources/js/i18n/modules/fr/*`
- Definition de done:
- le workspace parle de prospects, fiche prospect, relance, conversion, archivage

### `PROSPECT-202` - Ajouter la liste prospects et les filtres V1 du cahier des charges

- Statut: `fait`
- But: offrir une liste directement exploitable par l'equipe commerciale
- Livrables:
- colonnes: nom, entreprise, courriel, telephone, source, type, statut, responsable, priorite, date creation, derniere activite, prochaine relance
- filtres: statut, responsable, source, type, priorite, sans responsable, a relancer, en retard, archives
- Fichiers probables:
- `app/Queries/Requests/BuildRequestInboxIndexData.php`
- `resources/js/Pages/Request/UI/RequestTable.vue`
- `resources/js/Pages/Request/Index.vue`
- Definition de done:
- la liste couvre les besoins de triage et de suivi V1

### `PROSPECT-203` - Ajouter les actions rapides Prospects

- Statut: `fait`
- But: agir depuis la liste sans ouvrir chaque fiche
- Livrables:
- voir la fiche
- ajouter une note
- planifier une relance
- changer le statut
- assigner
- convertir en client
- archiver
- Fichiers probables:
- `resources/js/Pages/Request/UI/RequestTableActionsMenu.vue`
- `app/Http/Controllers/RequestController.php`
- Definition de done:
- au moins les actions note, assignation, statut et relance sont exploitables en V1

### `PROSPECT-204` - Refaire la fiche detail prospect

- Statut: `fait`
- But: transformer la page detail actuelle en veritable cockpit prospect
- Livrables:
- informations generales
- coordonnees
- source et demande initiale
- statut actuel
- responsable
- priorite
- prochaine action
- historique
- notes
- taches ouvertes
- doublons detectes
- boutons convertir, archiver, fusionner
- Fichiers probables:
- `resources/js/Pages/Request/Show.vue`
- `app/Http/Controllers/RequestController.php`
- `app/Models/ActivityLog.php`
- Definition de done:
- la fiche detail couvre le quotidien de qualification sans passer par plusieurs ecrans

Definition de done de phase:

- le module `Prospects` est visible et utilisable en V1
- la liste et la fiche detail exposent les informations metier essentielles
- verification executee:
- `php artisan test tests/Feature/ProspectWorkspaceFeatureTest.php`
- `php artisan test tests/Feature/RequestInboxPhaseOneTest.php`

## 12. Phase 3 - historique, interactions et relances

Objectif business:

- permettre un vrai suivi commercial et administratif

Objectif technique:

- structurer les interactions, les changements de statut et les taches de relance

### `PROSPECT-301` - Journaliser l'historique de statuts

- Statut: `fait`
- But: garder la trace complete des transitions
- Livrables:
- table `prospect_status_histories` ou equivalent
- ancien statut
- nouveau statut
- utilisateur
- date
- commentaire optionnel
- Fichiers probables:
- migration additive
- modele dedie
- `app/Http/Controllers/RequestController.php`
- `tests/Feature/ProspectStatusHistoryTest.php`
- Definition de done:
- chaque changement de statut cree une ligne exploitable
- Verification executee:
- `php artisan test tests/Feature/ProspectStatusHistoryTest.php`

### `PROSPECT-302` - Creer la couche interactions prospects

- Statut: `fait`
- But: stocker appels, courriels, notes, rendez-vous, relances, documents ajoutes
- Livrables:
- table `prospect_interactions`
- type d'interaction
- utilisateur
- description
- fichier joint optionnel
- prochaine action optionnelle
- Fichiers probables:
- migration additive
- modele dedie
- service de journalisation
- `resources/js/Pages/Request/Show.vue`
- `tests/Feature/ProspectInteractionTimelineTest.php`
- Definition de done:
- la timeline n'est plus limitee au seul `ActivityLog` brut
- Verification executee:
- `php artisan test tests/Feature/ProspectInteractionTimelineTest.php`

### `PROSPECT-303` - Formaliser les taches de relance

- Statut: `fait`
- But: relier les taches existantes au suivi prospect sans ambiguite
- Livrables:
- statut de tache: a faire, en cours, completee, annulee
- priorite: basse, normale, haute, urgente
- filtres "aujourd'hui" et "en retard"
- Fichiers probables:
- `app/Models/Task.php`
- `app/Http/Controllers/TaskController.php`
- `routes/console.php`
- `tests/Feature/ProspectFollowUpTaskTest.php`
- Notes d'implementation:
- reutiliser `tasks.request_id` avant de penser a une table parallele
- Definition de done:
- un prospect peut avoir plusieurs relances planifiees et visibles
- Verification executee:
- `php artisan test tests/Feature/ProspectFollowUpTaskTest.php`
- `php artisan test tests/Feature/ProspectWorkspaceFeatureTest.php tests/Feature/ProspectStatusHistoryTest.php tests/Feature/ProspectInteractionTimelineTest.php tests/Feature/TaskLifecycleManagementTest.php tests/Feature/SoloOwnerOnlyAccessTest.php`
- `npm run build`

### `PROSPECT-304` - Mettre a niveau les rappels automatiques

- Statut: `fait`
- But: faire vivre le suivi sans surveillance manuelle
- Livrables:
- commande de rappel compatible `Prospects`
- notification pour relance du jour
- notification pour relance en retard
- Fichiers probables:
- `routes/console.php`
- `app/Notifications/ProspectFollowUpReminderNotification.php`
- `app/Services/ProspectFollowUpReminderService.php`
- eventuels nouveaux notifications/services
- Definition de done:
- le systeme detecte et signale les suivis a faire
- Verification executee:
- `php artisan test tests/Feature/ProspectFollowUpReminderCommandTest.php tests/Feature/ProspectFollowUpTaskTest.php tests/Feature/ProspectWorkspaceFeatureTest.php tests/Feature/TaskLifecycleManagementTest.php`

Definition de done de phase:

- l'historique prospect est exploitable
- les relances et interactions sont visibles et actionnables

## 13. Phase 4 - detection de doublons et fusion

Objectif business:

- assainir la base prospect avant conversion

Objectif technique:

- detecter, signaler et fusionner les doublons sans perte de contexte

### `PROSPECT-401` - Creer le moteur de detection de doublons

- Statut: `fait`
- But: detecter les collisions sur email, telephone, nom, entreprise, adresse
- Fichiers probables:
- `app/Services/Prospects/ProspectDuplicateDetectionService.php`
- `app/Http/Controllers/RequestController.php`
- `tests/Feature/ProspectDuplicateMergeTest.php`
- Definition de done:
- le systeme peut retourner des doublons potentiels classes par score ou raison
- Verification executee:
- `php artisan test tests/Feature/ProspectDuplicateMergeTest.php tests/Feature/ProspectWorkspaceFeatureTest.php`

### `PROSPECT-402` - Afficher les alertes de doublon dans les bons flux

- Statut: `fait`
- But: prevenir avant creation, edition, import ou conversion
- Fichiers probables:
- `resources/js/Components/QuickCreate/RequestQuickForm.vue`
- `resources/js/Components/Prospects/ProspectDuplicateAlert.vue`
- `resources/js/Pages/Public/RequestForm.vue`
- `resources/js/Pages/Request/UI/RequestTable.vue`
- `app/Http/Controllers/PublicRequestController.php`
- `app/Http/Controllers/RequestController.php`
- Definition de done:
- l'utilisateur voit clairement les doublons potentiels et peut agir
- Verification executee:
- `php artisan test tests/Feature/ProspectDuplicateMergeTest.php tests/Feature/ProspectWorkspaceFeatureTest.php tests/Feature/ProspectsInboundPublicTest.php tests/Feature/ProspectStatusHistoryTest.php`

### `PROSPECT-403` - Ajouter la fusion de prospects

- Statut: `fait`
- But: fusionner sans perdre notes, taches, documents, historique
- Livrables:
- choix d'un prospect principal
- transfert des notes
- transfert des interactions
- transfert des taches ouvertes
- archivage ou desactivation du doublon
- journalisation de l'action
- Fichiers probables:
- `app/Services/Prospects/ProspectMergeService.php`
- `database/migrations/2026_04_25_000004_add_prospect_merge_tracking_fields_to_requests_table.php`
- `app/Models/Request.php`
- `app/Http/Controllers/RequestController.php`
- `tests/Feature/ProspectDuplicateMergeTest.php`
- Definition de done:
- deux prospects peuvent etre fusionnes de facon sure et tracable
- Verification executee:
- `php artisan test tests/Feature/ProspectDuplicateMergeTest.php tests/Feature/ProspectWorkspaceFeatureTest.php`

Definition de done de phase:

- la base prospect peut etre nettoyee avant conversion

## 14. Phase 5 - decouplage Quote / Customer

Objectif business:

- autoriser un vrai cycle prospect-first sans creation client prematuree

Objectif technique:

- faire en sorte qu'un devis puisse exister pour un prospect sans `customer_id` obligatoire

### `PROSPECT-501` - Ajouter `quotes.prospect_id` et rendre `quotes.customer_id` nullable

- Statut: `fait`
- But: supprimer le verrou structurel principal
- Fichiers probables:
- migration additive sur `quotes`
- `app/Models/Quote.php`
- `tests/Feature/ProspectQuoteBridgeTest.php`
- Points d'attention:
- la generation du numero de devis depend aujourd'hui de `customer_id`
- Definition de done:
- un devis peut etre cree pour un prospect avec `prospect_id` et sans `customer_id`
- Verification executee:
- `php artisan test tests/Feature/ProspectQuoteBridgeTest.php tests/Feature/OpportunityLinkingPhaseSixTest.php tests/Feature/ProspectStatusHistoryTest.php`

### `PROSPECT-502` - Adapter la logique de numerotation et les relations Quote

- Statut: `fait`
- But: rendre `Quote` coherent avec le nouveau modele
- Livrables:
- relation `quote->prospect`
- numerotation scopee par tenant et non par client obligatoire
- compatibilite lecture des anciens devis lies a un client
- Fichiers probables:
- `app/Models/Quote.php`
- traits de numerotation
- `app/Models/Request.php` ou `Prospect.php`
- Definition de done:
- la creation de devis ne jette plus d'exception quand `customer_id` est vide
- Verification executee:
- `php artisan test tests/Feature/ProspectQuoteBridgeTest.php tests/Feature/OpportunityLinkingPhaseSixTest.php tests/Feature/ProspectStatusHistoryTest.php`

### `PROSPECT-503` - Corriger les flux de creation de devis depuis un prospect

- Statut: `fait`
- But: rendre le pont prospect -> devis fonctionnel sans client force
- Fichiers probables:
- `app/Actions/Leads/ConvertLeadRequestToQuoteAction.php`
- `app/Http/Controllers/PublicRequestController.php`
- `app/Actions/Quotes/UpsertQuoteAction.php`
- `app/Support/CRM/OpportunitySchema.php`
- `tests/Feature/ProspectQuoteBridgeTest.php`
- Definition de done:
- creation de devis possible depuis un prospect sans creation client automatique
- Verification executee:
- `php artisan test tests/Feature/ProspectQuoteBridgeTest.php tests/Feature/ProspectStatusHistoryTest.php tests/Feature/OpportunityLinkingPhaseSixTest.php`

### `PROSPECT-504` - Revoir les statuts prospect pilotes par le devis

- Statut: `fait`
- But: conserver la logique metier `soumission envoyee`, `gagne`, `perdu`
- Fichiers probables:
- `app/Models/Quote.php`
- `app/Models/Request.php`
- `tests/Feature/ProspectQuoteBridgeTest.php`
- Definition de done:
- l'etat d'un devis continue d'alimenter correctement l'etat commercial du prospect
- Verification executee:
- `php artisan test tests/Feature/ProspectQuoteBridgeTest.php tests/Feature/ProspectStatusHistoryTest.php tests/Feature/OpportunityLinkingPhaseSixTest.php`

Definition de done de phase:

- le systeme n'exige plus de client pour entrer dans le cycle devis
- le verrou technique central du module est leve

## 15. Phase 6 - conversion prospect -> client

Objectif business:

- ne creer un client qu'au bon moment

Objectif technique:

- ajouter une conversion explicite, securisee, journalisee et resistante aux doublons

### `PROSPECT-601` - Creer le wizard de conversion prospect -> client

- Statut: `fait`
- But: guider l'utilisateur dans une conversion claire
- Livrables:
- choisir creer un nouveau client ou lier un client existant
- afficher les doublons potentiels
- confirmer les donnees reprises
- Fichiers probables:
- `resources/js/Pages/Request/Show.vue`
- nouveau composant de modal ou page dediee
- `app/Http/Controllers/RequestController.php`
- Definition de done:
- la conversion est faisable sans bricolage manuel
- Verification executee:
- `php artisan test tests/Feature/ProspectConversionTest.php tests/Feature/ProspectQuoteBridgeTest.php tests/Feature/ProspectStatusHistoryTest.php tests/Feature/ProspectWorkspaceFeatureTest.php`

### `PROSPECT-602` - Ajouter le service de conversion et le lien prospect/client

- Statut: `fait`
- But: centraliser la logique de conversion cote backend
- Livrables:
- creation ou rattachement du client
- copie des donnees pertinentes
- mise a jour du statut `converti en client`
- stockage du converti par / converti le
- lien durable entre prospect et client
- Fichiers probables:
- `app/Services/Prospects/ProspectConversionService.php`
- `app/Models/Request.php` ou `Prospect.php`
- `app/Models/Customer.php`
- `tests/Feature/ProspectConversionTest.php`
- Definition de done:
- un prospect converti reste consultable et lie au client final
- Verification executee:
- `php artisan test tests/Feature/ProspectConversionTest.php tests/Feature/ProspectQuoteBridgeTest.php tests/Feature/ProspectStatusHistoryTest.php tests/Feature/ProspectWorkspaceFeatureTest.php`

### `PROSPECT-603` - Ajouter les garde-fous de conversion

- Statut: `a faire`
- But: eviter les doublons et les conversions abusives
- Livrables:
- verification par email, telephone, entreprise
- verification des permissions
- historisation de la conversion
- Fichiers probables:
- `app/Http/Controllers/RequestController.php`
- policies / gates / services
- `tests/Feature/ProspectConversionTest.php`
- Definition de done:
- la conversion est bloquee ou avertie quand un doublon ou un manque de droit existe

Definition de done de phase:

- le prospect peut devenir client au bon moment
- le prospect n'est jamais supprime apres conversion

## 16. Phase 7 - conformite, audit, archivage, perte

Objectif business:

- rendre le module exploitable dans un cadre serieux de gestion de donnees

Objectif technique:

- ajouter les traces, les motifs et les mecanismes de retention attendus

### `PROSPECT-701` - Gerer les prospects perdus

- Statut: `a faire`
- But: capturer la raison business de perte
- Livrables:
- liste de raisons de perte
- commentaire libre
- journalisation
- Fichiers probables:
- `app/Models/Request.php`
- `resources/js/Pages/Request/Show.vue`
- `tests/Feature/ProspectAuditComplianceTest.php`
- Definition de done:
- un prospect peut etre marque perdu avec raison exploitable

### `PROSPECT-702` - Ajouter archivage et anonymisation

- Statut: `partiel`
- But: respecter la retention et nettoyer l'operationnel
- Livrables:
- archivage
- anonymisation
- eventuelle suppression logique controlee
- Fichiers probables:
- `app/Models/Request.php`
- controller de module
- commandes si necessaire
- `tests/Feature/ProspectAuditComplianceTest.php`
- Definition de done:
- les actions sensibles existent et sont protegees
- Note d'avancement:
- archivage/restauration et consultation en lecture seule deja branches sur le workspace prospects
- anonymisation V1 archive-only est branchee avec scrub des donnees personnelles, neutralisation des notes/fichiers/taches, masquage UI et blocage de restauration
- suppression logique controlee reste a implementer

### `PROSPECT-703` - Renforcer l'audit du module Prospects

- Statut: `a faire`
- But: tracer creation, edition, statut, assignation, merge, conversion, export
- Fichiers probables:
- `app/Models/ActivityLog.php`
- eventuel service d'audit prospects
- `tests/Feature/ProspectAuditComplianceTest.php`
- Definition de done:
- les actions sensibles laissent une trace consultable

### `PROSPECT-704` - Ajouter l'export controle des donnees prospects

- Statut: `a faire`
- But: permettre l'export sans ouvrir les vannes a tout le monde
- Fichiers probables:
- controller / action d'export
- permissions equipe
- `tests/Feature/ProspectAuditComplianceTest.php`
- Definition de done:
- seuls les utilisateurs autorises peuvent exporter, et l'export est journalise

Definition de done de phase:

- le module a un niveau de conformite et de tracabilite acceptable pour une V1 serieuse

## 17. Phase 8 - dashboard et notifications

Objectif business:

- permettre le pilotage quotidien et le management commercial

Objectif technique:

- construire les agragats et les alertes sans ralentir le workspace principal

### `PROSPECT-801` - Construire le dashboard data Prospects

- Statut: `a faire`
- But: exposer les KPI du cahier des charges
- Livrables:
- total prospects
- nouveaux cette semaine / mois
- par statut
- par source
- par responsable
- a relancer aujourd'hui
- en retard
- gagnes / perdus
- taux de conversion
- delai moyen de conversion
- Fichiers probables:
- `app/Queries/Prospects/BuildProspectDashboardData.php`
- `resources/js/Pages/Request/UI/RequestAnalytics.vue` ou equivalent
- Definition de done:
- les KPIs V1 sont disponibles pour un owner ou manager

### `PROSPECT-802` - Ajouter le dashboard visuel Prospects

- Statut: `a faire`
- But: rendre les donnees lisibles dans le workspace
- Fichiers probables:
- `resources/js/Pages/Request/Index.vue`
- `resources/js/Pages/Request/UI/RequestAnalytics.vue`
- Definition de done:
- l'ecran montre clairement les KPIs prioritaires

### `PROSPECT-803` - Ajouter les notifications internes Prospects

- Statut: `a faire`
- But: pousser les bons signaux sans bruit inutile
- Livrables:
- nouveau prospect
- prospect assigne
- relance du jour
- relance en retard
- prospect sans activite depuis X jours
- prospect converti
- prospect perdu
- Fichiers probables:
- `app/Notifications/*`
- services de declenchement
- dashboard widgets si necessaire
- Definition de done:
- les notifications majeures sont fonctionnelles et configurables selon le socle existant

Definition de done de phase:

- le module Prospects est pilotable et pas seulement consultable

## 18. Phase 9 - migration des donnees existantes

Objectif business:

- nettoyer le referentiel clients existant sans perdre l'historique

Objectif technique:

- reclasser les faux clients dans le module prospects avec un maximum de surete

### `PROSPECT-901` - Creer la commande de migration en mode dry-run

- Statut: `a faire`
- But: mesurer l'impact avant toute ecriture
- Livrables:
- comptage des clients eligibles a reclasser
- motifs de classement
- cas ambigus en `a qualifier`
- Fichiers probables:
- commande artisan dediee
- service de migration
- `tests/Feature/ProspectMigrationCommandTest.php`
- Definition de done:
- un dry-run donne un rapport fiable sans ecriture

### `PROSPECT-902` - Executer la migration reelle avec journal

- Statut: `a faire`
- But: migrer proprement et de facon reversible autant que possible
- Livrables:
- reclassement vers prospect
- conservation des notes et dates
- lien vers les demandes d'origine
- journal d'execution
- Fichiers probables:
- service de migration
- commande artisan
- `tests/Feature/ProspectMigrationCommandTest.php`
- Definition de done:
- la migration cree un historique verifiable de chaque reclassement

### `PROSPECT-903` - Ajouter la verification post-migration

- Statut: `a faire`
- But: controler la qualite du resultat
- Livrables:
- rapport de verification
- eventuels segments "a qualifier"
- checks de coherence apres migration
- Fichiers probables:
- commande ou query de verification
- dashboard/rapport temporaire
- Definition de done:
- l'equipe peut verifier et corriger les cas limites apres migration

Definition de done de phase:

- les faux clients historiques peuvent etre reclasses sans perte critique

## 19. Risques techniques a surveiller

Risques forts:

- `Quote` casse si `customer_id` devient nullable sans reprendre la numerotation
- le flux public `receive_quote` touche a beaucoup de pieces: devis, email, tracking, notifications
- l'UI actuelle `Request` est deja riche et peut subir une dette de renommage
- les permissions equipe doivent rester coherentes avec les feature flags tenant
- `CampaignProspect` ne doit pas etre impacte par les nouveaux services `Prospect*`

## 20. Definition de done globale du module V1

Le module `Prospects` pourra etre considere V1 quand:

- les flux entrants creent des prospects et non des clients
- les equipes peuvent lister, filtrer, assigner et suivre les prospects
- l'historique, les notes et les relances sont exploitables
- les doublons peuvent etre detectes et fusionnes
- un devis peut exister avant la creation d'un client
- la conversion prospect -> client est explicite et tracee
- les motifs de perte, l'archivage et l'audit sont disponibles
- les principaux KPIs et notifications sont en place

## 21. Journal de suivi

Template a reutiliser pendant l'implementation:

- `2026-04-24` - document cree - aucun ticket commence
- `2026-04-24` - `PROSPECT-001` livre:
  - modele `Prospect` transitoire ajoute sur la table `requests`
  - statuts et vocabulaire centraux branches
- `2026-04-24` - `PROSPECT-002` livre:
  - permissions `prospects.*` exposees avec compatibilite `requests.*`
  - tests permissions et onboarding ajoutes et verts
- `2026-04-24` - `PROSPECT-003` livre:
  - routes alias `prospects.*` ajoutees
  - navigation et labels principaux branches sur `Prospects`
- `2026-04-24` - `PROSPECT-101` livre:
  - `PublicRequestController` cree d'abord un prospect sans `customer_id`
  - la branche `receive_quote` legacy reste fonctionnelle
- `2026-04-24` - `PROSPECT-102` livre:
  - l'API d'integration ne relie plus automatiquement un client existant
  - les metadonnees d'entree `source / type / consentements` sont conservees
- `2026-04-24` - `PROSPECT-103` livre:
  - creation manuelle verifiee sans client obligatoire
  - import CSV corrige sans liaison client implicite
- `2026-04-24` - `PROSPECT-104` livre:
  - normalisation minimale via `intake_source`, `request_type`, `contact_consent`, `marketing_consent`
  - `last_activity_at` renseigne a l'entree
- `2026-04-24` - `PROSPECT-105` livre:
  - suites `ProspectsInboundPublicTest`, `ProspectsInboundApiTest`, `ProspectWorkspaceFeatureTest` ajoutees
  - verification executee avec `WorkflowLeadTest` et `PublicLeadServiceSuggestionTest`
