# Phase 5 email and calendar foundations dev backlog

Derniere mise a jour: 2026-04-21

## 0. Etat d'avancement implementation

Suivi courant:

- `P5-001` fait
- `P5-002` fait
- `P5-003` fait
- `P5-004` fait
- `P5-005` fait
- `P5-006` fait
- `P5-007` fait
- `P5-008` fait

Dernier bloc livre:

- contrat central de taxonomie pour les events message/email
- `ActivityLog` enrichi avec metadata `is_message_event` et `message_event`
- payload `Customer` detail aligne avec la nouvelle couche message
- suite feature dediee ajoutee pour verrouiller les mappings legacy/canoniques
- contrat central de taxonomie pour les events meeting/calendar
- `ActivityLog` enrichi avec metadata `is_meeting_event` et `meeting_event`
- payload `Customer` detail aligne avec la nouvelle couche meeting
- suite feature dediee ajoutee pour verrouiller les mappings legacy/canoniques
- strategie de linking CRM centralisee pour les events message/meeting
- `ActivityLog` et la fiche client exposent un contrat normalise `crm_links`
- priorite request-first explicite quand un event porte plusieurs rattachements CRM
- service central de logging email sortant ajoute pour les flux CRM coeur
- bascule des envois `quote / invoice / lead form / retry / assistant` vers les actions canoniques `message_email_*`
- metadata CRM homogenes `source / notification / customer_id / request_id / quote_id` sur les logs sortants
- projection UI unifiee des activites `sales / message / meeting` dans les timelines `Request / Quote / Customer`
- libelles et badges timeline alignes pour les emails sortants et les rendez-vous phase 5
- suite non regression dediee ajoutee pour verrouiller les payloads timeline coeur
- socle connector-ready ajoute pour `gmail / outlook`
- recorder canonique prepare pour les futurs events message et meeting issus de connecteurs
- resolution CRM `customer / request / quote` mutualisee entre logging sortant et couche connecteur
- endpoint API d ingestion connecteur CRM ajoute pour `gmail / outlook`
- support `occurred_at` ajoute pour preserver la chronologie reelle des events sync
- suite feature dediee ajoutee pour verrouiller permissions API et ingestion canonique
- suite finale end-to-end ajoutee pour verifier la projection des events connecteur dans `Request / Quote / Customer`
- ordre chronologique `occurred_at` et `lastInteraction` client verrouilles avant cloture de phase

Bloc actuellement en cours:

- sprint 9 ferme
- phase 5 email/calendrier terminee et prete pour l ouverture de la phase 6

## 1. But du document

Ce document transforme la phase 5 en backlog dev directement executable.

Le but est simple:

- poser une couche message et rendez-vous legere avant toute inbox complete
- reutiliser `ActivityLog` au maximum
- garder `Request / Customer / Quote` comme objets coeur de rattachement
- preparer une future integration Gmail / Outlook sans couplage premature

## 2. Rappel du scope phase 5

La phase 5 doit poser les primitives suivantes sans lancer trop tot:

- sync Gmail native lourde
- sync calendrier bi-directionnelle complete
- inbox commerciale full featured

Resultat V1 attendu:

- contrat message stable
- contrat meeting stable
- logs email sortants lisibles dans les timelines coeur
- point d'accroche clair pour les futurs connecteurs

## 3. Regles dev de la phase 5

Regles non negociables:

1. etendre `ActivityLog` avant d'inventer un objet message persistant
2. absorber les actions email legacy existantes dans une taxonomie commune
3. garder la distinction claire entre event message, event meeting et linking CRM
4. ne pas promettre une inbox complete avant la couche d'abstraction connecteur
5. proteger les payloads `Request / Customer / Quote` avec des tests explicites

## 4. Fichiers coeur a proteger

### Backend

- `app/Models/ActivityLog.php`
- `app/Support/CRM/MessageEventTaxonomy.php`
- `app/Queries/Customers/BuildCustomerDetailViewData.php`
- `app/Http/Controllers/RequestController.php`
- `app/Http/Controllers/QuoteController.php`
- `app/Http/Controllers/PublicRequestController.php`

### Tests existants a proteger

- `tests/Feature/CustomerShowLeadRequestsTest.php`
- `tests/Feature/QuoteRecoveryPhaseTwoTest.php`
- `tests/Feature/SalesActivityLogPhaseFourTest.php`

## 5. Tests a creer pour la phase 5

Suites recommandees:

- `tests/Feature/MessageEventContractPhaseFiveTest.php`
- `tests/Feature/MeetingEventContractPhaseFiveTest.php`
- `tests/Feature/OutgoingEmailLoggingPhaseFiveTest.php`
- `tests/Feature/ActivityTimelinePhaseFiveNonRegressionTest.php`
- `tests/Feature/CrmConnectorEventIngressPhaseFiveTest.php`
- `tests/Feature/ActivityTimelineConnectorIngressPhaseFiveTest.php`

Regle:

- garder les contracts phase 5 dans des suites dediees
- ne pas noyer les regressions message/calendar dans des tests generiques

## 6. Sprint 9

Objectif:

- poser la taxonomie et les contracts de base pour les messages avant la couche meeting

### P5-001 - Message event contract

#### But

Definir un contrat unique pour les events message/email de la phase 5.

#### Etat

- livre le `2026-04-21`
- taxonomie centralisee dans `app/Support/CRM/MessageEventTaxonomy.php`
- `ActivityLog` enrichi avec scope et metadata `message_event`
- payload `Customer` detail aligne avec les events message

#### Livrables

- definitions canoniques `message_email_*`
- mappings legacy `email_*` et `lead_email_*`
- metadata homogenes `channel / direction / delivery_state`
- exposition immediate sur `ActivityLog` et timeline `Customer`

#### Fichiers touches

- `app/Support/CRM/MessageEventTaxonomy.php`
- `app/Models/ActivityLog.php`
- `app/Queries/Customers/BuildCustomerDetailViewData.php`
- `tests/Feature/MessageEventContractPhaseFiveTest.php`

#### Notes d'implementation

- garder les actions canoniques pretes pour les futurs logs phase 5
- absorber les events email legacy deja emis par l'application
- ne pas melanger encore les rendez-vous dans ce contrat
- rester centre sur l'email sortant et la lecture timeline

#### Tests ajoutes

- contrat canonique et legacy de la taxonomie message
- serialization `ActivityLog` et scope `messageEvent`
- exposition metadata sur la fiche client

### P5-002 - Meeting event contract

#### But

Definir un contrat unique pour les events meeting/rendez-vous de la phase 5.

#### Etat

- livre le `2026-04-21`
- taxonomie centralisee dans `app/Support/CRM/MeetingEventTaxonomy.php`
- `ActivityLog` enrichi avec scope et metadata `meeting_event`
- payload `Customer` detail aligne avec les events meeting

#### Livrables

- definitions canoniques `meeting_*`
- mappings legacy `sales_meeting_*`
- metadata homogenes `provider / source / start_at / completed_at`
- exposition immediate sur `ActivityLog` et timeline `Customer`

#### Fichiers touches

- `app/Support/CRM/MeetingEventTaxonomy.php`
- `app/Models/ActivityLog.php`
- `app/Queries/Customers/BuildCustomerDetailViewData.php`
- `tests/Feature/MeetingEventContractPhaseFiveTest.php`

#### Notes d'implementation

- garder les rendez-vous separes de la taxonomie sales phase 4 meme si les actions legacy sont absorbees
- preparer un contrat lisible pour les futurs connecteurs calendrier sans creer encore un objet persistant
- rester centre sur la lecture timeline et la coherence metadata

#### Tests ajoutes

- contrat canonique et legacy de la taxonomie meeting
- serialization `ActivityLog` et scope `meetingEvent`
- exposition metadata sur la fiche client

### P5-003 - CRM object linking strategy for messages

#### But

Definir une strategie unique de rattachement CRM pour les events message et meeting avant de brancher le logging sortant et les connecteurs.

#### Etat

- livre le `2026-04-21`
- linking centralise dans `app/Support/CRM/CrmActivityLinking.php`
- `ActivityLog` expose un contrat normalise `crm_links`
- payload `Customer` detail aligne avec le meme contrat

#### Livrables

- resolution normalisee des liens `customer / request / quote`
- notion de `subject`, `primary` et `anchors` pour les events phase 5
- priorite request-first explicite quand le sujet n est pas un objet coeur
- contrat directement reutilisable pour le futur logging email et les connecteurs

#### Fichiers touches

- `app/Support/CRM/CrmActivityLinking.php`
- `app/Models/ActivityLog.php`
- `app/Queries/Customers/BuildCustomerDetailViewData.php`
- `tests/Feature/CrmActivityLinkingPhaseFiveTest.php`

#### Notes d'implementation

- ne pas faire de requete supplementaire pour resoudre le linking: rester base sur `subject_type / subject_id` et les ids portes dans `properties`
- garder `Request / Quote / Customer` comme objets coeur de rattachement
- preparer un contrat exploitable par les futurs connecteurs sans imposer encore un objet message persistant

#### Tests ajoutes

- normalisation `subject / primary / anchors` sur `ActivityLog`
- fallback request-first quand un event est porte par un sujet non coeur
- exposition du contrat `crm_links` sur `Request` et `Customer`

### P5-004 - Outgoing email logging

#### But

Journaliser proprement les emails sortants CRM produits par l application avec un contrat canonique phase 5.

#### Etat

- livre le `2026-04-21`
- service central `app/Services/CRM/OutgoingEmailLogService.php` ajoute
- flux `quote / invoice / lead form / retry / assistant` branches sur les actions canoniques `message_email_*`
- retry email CRM aligne sur `message_email_retry_scheduled`

#### Livrables

- logging sortant centralise pour les emails CRM coeur
- bascule des actions legacy runtime `email_sent / email_failed / lead_email_failed / lead_email_retry_scheduled` vers les actions canoniques phase 5 sur les nouveaux flux
- metadata homogenes `email / source / notification / retry_attempt`
- linking CRM preserve via `customer_id / request_id / quote_id`

#### Fichiers touches

- `app/Services/CRM/OutgoingEmailLogService.php`
- `app/Http/Controllers/QuoteEmaillingController.php`
- `app/Http/Controllers/InvoiceController.php`
- `app/Http/Controllers/PublicRequestController.php`
- `app/Jobs/RetryLeadQuoteEmailJob.php`
- `app/Services/Assistant/AssistantWorkflowService.php`
- `tests/Feature/OutgoingEmailLoggingPhaseFiveTest.php`

#### Notes d'implementation

- centraliser le logging sans brancher un hook global sur tous les emails de l application
- limiter le scope aux flux CRM coeur avant d etendre aux futurs connecteurs
- garder un descriptif lisible dans la timeline tout en passant aux actions canoniques
- corriger le flux assistant pour ne plus logger un succes quand l envoi echoue

#### Tests ajoutes

- envoi manuel de devis avec action canonique et `crm_links`
- envoi manuel de facture avec action canonique et `crm_links`
- retry job avec `message_email_failed` et `message_email_retry_scheduled`

### P5-005 - Timeline activity projection

#### But

Rendre les timelines coeur `Request / Quote / Customer` coherentes avec les contrats phase 5 pour les emails et rendez-vous.

#### Etat

- livre le `2026-04-21`
- projection UI etendue dans `resources/js/Components/CRM/SalesActivityPanel.vue`
- traductions `Request / Quote / Customer` alignees pour la timeline CRM mixte
- suite `tests/Feature/ActivityTimelinePhaseFiveNonRegressionTest.php` ajoutee

#### Livrables

- rendu unifie des activites `sales_activity`, `message_event` et `meeting_event`
- badges et metadata timeline lisibles pour `email sent / failed / retry scheduled` et `meeting scheduled / completed`
- conservation des quick actions sales phase 4 sans casser l existant
- verification explicite des payloads detail `request / quote / customer`

#### Fichiers touches

- `resources/js/Components/CRM/SalesActivityPanel.vue`
- `resources/js/i18n/modules/fr/requests.json`
- `resources/js/i18n/modules/fr/customers.json`
- `resources/js/i18n/modules/fr/quotes.json`
- `resources/js/i18n/modules/en/requests.json`
- `resources/js/i18n/modules/en/customers.json`
- `resources/js/i18n/modules/en/quotes.json`
- `resources/js/i18n/modules/es/requests.json`
- `resources/js/i18n/modules/es/customers.json`
- `resources/js/i18n/modules/es/quotes.json`
- `tests/Feature/ActivityTimelinePhaseFiveNonRegressionTest.php`

#### Notes d'implementation

- etendre le composant timeline existant plutot que dupliquer une UI specifique email
- garder le logging manuel centre sur les actions sales existantes
- utiliser les contrats `message_event` et `meeting_event` deja exposes par `ActivityLog`
- conserver une lecture simple cote utilisateur avec badges, dates et metadata compactes

#### Tests ajoutes

- detail `Request` avec coexistence `sales / meeting / message`
- detail `Quote` avec email canonique et activite commerciale existante
- detail `Customer` aggregant timeline request quote customer sans regression

### P5-006 - Connector-ready abstraction layer

#### But

Poser une couche d abstraction legere pour les futurs connecteurs email/calendar sans lancer encore de sync OAuth ou de boite mail complete.

#### Etat

- livre le `2026-04-21`
- registry `gmail / outlook` ajoutee sous `app/Services/CRM/Connectors`
- service `app/Services/CRM/ConnectorActivityLogService.php` ajoute
- resolution CRM extraite dans `app/Services/CRM/CrmActivityContextResolver.php`

#### Livrables

- contrat d adapter connecteur pour normaliser des events `message` et `meeting`
- definitions connector-ready exploitables plus tard par une UI de connexion
- recorder canonique qui journalise des events connecteur vers `ActivityLog`
- metadata message enrichies avec `provider / message_id / provider_message_id / external_message_id`

#### Fichiers touches

- `app/Services/CRM/CrmActivityContextResolver.php`
- `app/Services/CRM/OutgoingEmailLogService.php`
- `app/Services/CRM/ConnectorActivityLogService.php`
- `app/Services/CRM/Connectors/Contracts/CrmConnectorAdapter.php`
- `app/Services/CRM/Connectors/AbstractCrmConnectorAdapter.php`
- `app/Services/CRM/Connectors/GmailConnectorAdapter.php`
- `app/Services/CRM/Connectors/OutlookConnectorAdapter.php`
- `app/Services/CRM/Connectors/CrmConnectorRegistry.php`
- `app/Support/CRM/MessageEventTaxonomy.php`
- `tests/Feature/ConnectorActivityAbstractionPhaseFiveTest.php`

#### Notes d'implementation

- reutiliser le pattern `registry + adapter` deja present dans les integrations campagnes
- ne pas ajouter encore de persistance de connexion dediee phase 5 CRM
- garder `ActivityLog` comme point d entree unique des events normalises
- mutualiser la resolution des ancres CRM pour eviter les divergences entre flux sortants et futurs connecteurs

#### Tests ajoutes

- definitions `gmail / outlook` exposees par la registry
- log canonique d un email recu via `gmail` avec linking CRM
- log canonique d un meeting complete via `outlook` avec linking CRM

### P5-007 - Connector event ingress endpoint

#### But

Brancher la couche connecteur phase 5 sur un premier flux reel d ingestion sans lancer encore une inbox ni une sync complete.

#### Etat

- livre le `2026-04-21`
- endpoint `api/v1/integrations/crm/connector-events` ajoute
- service `app/Services/CRM/ConnectorEventIngestionService.php` ajoute
- support `occurred_at` applique aux logs connecteur pour une timeline credible

#### Livrables

- endpoint protege par capacite `crm:write`
- ingestion unitaire d events `message` et `meeting` pour `gmail / outlook`
- resolution du sujet coeur `customer / request / quote`
- retour API directement exploitable avec `message_event / meeting_event / crm_links`

#### Fichiers touches

- `app/Services/CRM/ConnectorEventIngestionService.php`
- `app/Http/Controllers/Api/Integration/CrmConnectorEventController.php`
- `routes/api.php`
- `tests/Feature/CrmConnectorEventIngressPhaseFiveTest.php`

#### Notes d'implementation

- s appuyer sur `ConnectorActivityLogService` au lieu de dupliquer la normalisation
- rester sur un endpoint unitaire simple avant toute ingestion batch ou orchestration planifiee
- garder la validation focus sur les sujets coeur de la phase 5
- utiliser `occurred_at` ou des timestamps connecteur derives pour conserver l ordre metier dans la timeline

#### Tests ajoutes

- respect de la capacite API `crm:write`
- ingestion canonique d un event message `gmail` sur un devis
- ingestion canonique d un event meeting `outlook` sur une demande

### P5-008 - Non-regression activity timeline suite

#### But

Fermer la phase 5 avec une verification bout-en-bout de la timeline CRM apres ingestion connecteur.

#### Etat

- livre le `2026-04-21`
- suite finale `tests/Feature/ActivityTimelineConnectorIngressPhaseFiveTest.php` ajoutee
- verification directe de la projection `Request / Quote / Customer` apres ingestion API
- ordre `occurred_at` et `lastInteraction` verrouilles

#### Livrables

- couverture end-to-end entre endpoint d ingestion connecteur et fiches CRM coeur
- non-regression explicite sur la coexistence `sales / message / meeting`
- verification du linking CRM normalise sur les payloads timeline finaux
- cloture de la phase 5 sans ouvrir prematurement le scope inbox phase 6

#### Fichiers touches

- `tests/Feature/ActivityTimelineConnectorIngressPhaseFiveTest.php`
- `docs/PHASE_5_EMAIL_CALENDAR_FOUNDATIONS_DEV_BACKLOG_2026-04-21.md`

#### Notes d'implementation

- reposer sur l endpoint `api/v1/integrations/crm/connector-events` plutot que creer des logs en direct
- verifier les trois vues coeur `Request`, `Quote` et `Customer`
- couvrir des events postes hors ordre pour confirmer que `occurred_at` pilote bien la timeline
- garder la fermeture de phase 5 centree sur la non-regression et pas sur une nouvelle feature

#### Tests ajoutes

- detail `Request` avec events connecteur `message + meeting` et activite sales legacy
- detail `Quote` avec email connecteur plus ancien que l activite commerciale locale
- detail `Customer` avec aggregation mixte `Customer / Request / Quote` et `lastInteraction` correct
