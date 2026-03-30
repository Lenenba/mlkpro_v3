# Demo Workspaces V2 - User Story

Derniere mise a jour: 2026-03-30

## Goal
Faire evoluer le module admin de creation de comptes demo afin qu il devienne un vrai outil commercial et operationnel:
- plus rapide a utiliser
- plus reutilisable
- plus coherant d une demo a l autre
- plus simple a transmettre au prospect
- plus facile a piloter dans le temps

L objectif est de partir du module deja livre et d y ajouter les briques qui manquent pour industrialiser les demos prospects sans revenir a des seeders techniques.

## Current Baseline
Le module actuel couvre deja:
- creation admin-only d un demo workspace
- stepper de configuration
- choix des modules a activer
- generation d un environnement de demo realiste
- date d expiration
- purge complete des donnees du compte demo

Cette user story concerne la V2 du module, c est a dire les ameliorations qui doivent rendre ce workflow plus puissant, plus rapide et plus commercialement utile.

## Product Problem
Aujourd hui, meme avec le nouveau module, il reste plusieurs frictions:
- l admin doit encore refaire souvent des choix similaires pour des prospects comparables
- il n existe pas de systeme de templates reutilisables
- il n y a pas encore de mecanisme simple pour cloner ou reinitialiser une demo
- l envoi des acces au prospect reste manuel
- le parcours de demo n est pas encore guide par scenario
- l equipe commerciale n a pas de vue centralisee sur l historique et la valeur d une demo

Consequences:
- temps de preparation inutilement long
- experience demo variable selon la personne qui prepare le compte
- risque d oublier des modules, des acces ou des informations utiles
- manque de lisibilite sur les demos envoyees, prolongees, utilisees ou converties

## Scope
- bibliotheque de templates de demo reutilisables
- clonage de demo existante
- snapshot et reset d une demo vers un etat de reference
- kit d acces prospect pret a envoyer
- scenarios guides de demo par cas d usage
- gestion avancee de l expiration et des prolongations
- provisioning asynchrone avec progression
- personnalisation branding du workspace demo
- creation d acces multi-roles pour la meme demo
- historique, timeline et suivi de conversion
- pre-remplissage depuis formulaire commercial ou CRM

## Non-goals
- permettre au prospect de se creer lui-meme une demo sans validation interne
- remplacer le CRM commercial de l entreprise
- reconstruire tout le moteur de seed demo existant
- transformer ce module en outil marketing public
- gerer la facturation reelle du prospect depuis ce workflow

## Product Principles
- une demo doit pouvoir etre preparee vite sans sacrifier la qualite
- une demo doit etre personnalisee sans demander de travail technique
- la reutilisation doit etre privilegiee avant la reconfiguration manuelle
- les actions irreversibles doivent etre tres explicites
- l admin doit toujours savoir ou en est la demo: brouillon, prete, envoyee, expire bientot, expiree, purgee
- le module doit servir a la fois l equipe ops et l equipe sales

## Personas

### Persona 1 - Platform admin
Prepare les workspaces de demo, regle les modules, les donnees, l expiration et le nettoyage.

### Persona 2 - Sales / account executive
Veut un compte demo pret rapidement, conforme au besoin du prospect, facile a presenter et facile a relancer.

### Persona 3 - Prospect
Recoit un acces demo clair, comprend quoi tester, retrouve un environnement proche de son activite et peut revenir dessus sans confusion.

## Primary User Story

### US-DEMO-001 - Reusable and personalized demo workspaces
As a platform admin or sales ops manager,
I want to create personalized demo workspaces from reusable templates and guided configurations,
so I can prepare consistent, high-value prospect demos in minutes instead of rebuilding them manually each time.

Acceptance criteria:
- the admin can start from a blank demo or from a reusable template
- the template pre-fills company type, sector, modules, realism profile and recommended scenarios
- the admin can still customize the generated workspace before provisioning
- the created workspace keeps a clear status and lifecycle
- the system stores who created the demo, for which prospect, and when it expires

## Supporting User Stories

### US-DEMO-002 - Demo templates library
As a platform admin,
I want a library of demo templates by use case,
so I can launch standard demos faster and keep a coherent quality bar.

Acceptance criteria:
- the admin can create, edit, archive and duplicate demo templates
- each template can store:
  - company type
  - sector
  - selected modules
  - seed profile
  - team size
  - locale and timezone
  - recommended scenario pack
  - expiration default
- one template can be marked as default for a given use case
- archived templates stay visible in history but are hidden from new creation by default

### US-DEMO-003 - Clone an existing demo workspace
As a platform admin,
I want to clone an existing demo workspace,
so I can reuse a successful setup for another similar prospect without starting over.

Acceptance criteria:
- the admin can duplicate a demo from the listing page
- the clone copies configuration and selected modules
- the clone generates a new tenant owner, new credentials and a new expiration date
- the admin can choose whether to keep the same realism profile or regenerate fresh sample data

### US-DEMO-004 - Snapshot and reset
As a platform admin,
I want to save a reference snapshot and reset a demo to that state,
so I can recover quickly after a sales call or after the prospect changes too much data.

Acceptance criteria:
- a workspace can have one active baseline snapshot
- the admin can trigger `Reset to baseline`
- the reset removes runtime changes and restores the reference dataset
- the reset is confirmed by a destructive action modal
- the operation is audited with actor, timestamp and workspace id

### US-DEMO-005 - Prospect access kit
As a sales representative,
I want a ready-to-send access kit for each demo,
so I can share the environment with the prospect without manual copy-paste mistakes.

Acceptance criteria:
- after provisioning, the module exposes a share panel with:
  - login URL
  - email
  - password
  - expiration date
  - active modules
  - suggested testing path
- the admin can copy the full kit in one click
- the system can generate an email-ready summary block
- the admin can mark the demo as `sent to prospect`

### US-DEMO-006 - Guided scenario packs
As a sales representative,
I want guided scenarios attached to a demo,
so I can walk the prospect through a concrete value story instead of improvising the flow.

Acceptance criteria:
- a demo can be linked to one or more scenario packs
- example packs:
  - salon queue
  - retail checkout
  - service quote to invoice
  - reservation to in-service flow
- each scenario pack contains:
  - business objective
  - ordered actions
  - expected visible results
  - key screens to open
- the access kit can include the selected scenario pack

### US-DEMO-007 - Expiration management and extension
As a platform admin,
I want better lifecycle management around demo expiration,
so I can keep active opportunities alive without losing control over stale environments.

Acceptance criteria:
- the list shows statuses:
  - draft
  - provisioning
  - ready
  - sent
  - expires_soon
  - expired
  - purged
- the admin can extend a demo by quick actions:
  - +3 days
  - +7 days
  - +14 days
- the module highlights demos expiring soon
- expired demos can be filtered easily
- purge of expired demos remains supported

### US-DEMO-008 - Async provisioning with progress
As a platform admin,
I want long demo provisioning to run in background with progress feedback,
so the interface stays responsive and I know if a demo is still generating or failed.

Acceptance criteria:
- demo creation can run as a queued background job
- the UI shows statuses:
  - queued
  - provisioning
  - ready
  - failed
- the UI displays a progress indicator or at least stage-based feedback
- failures expose a readable error state for admin users
- partial failures do not leave hidden orphan workspaces without status

### US-DEMO-009 - Branding and realism customization
As a platform admin,
I want to customize the brand look and sample content of the demo,
so the environment feels closer to the prospect business.

Acceptance criteria:
- the admin can set:
  - company display name
  - logo
  - primary color
  - short company description
  - team naming style
  - sample services or products focus
- the branding is applied without touching the global platform theme
- the preview step summarizes the branding choices before provisioning

### US-DEMO-010 - Multi-role demo access
As a sales representative,
I want multiple role-based accounts in the same demo,
so I can show owner, staff, front desk or manager views during the same session.

Acceptance criteria:
- the admin can generate optional extra users for the same demo
- each extra access is role-based and tied to the same tenant
- the admin can revoke or regenerate these accesses independently
- the access kit can include one or many role logins

### US-DEMO-011 - Demo activity timeline and conversion visibility
As a sales manager,
I want to track the lifecycle and usage of demo workspaces,
so I can measure adoption, follow-up effectively and connect demos to revenue outcomes.

Acceptance criteria:
- each demo shows a timeline with key events:
  - created
  - provisioned
  - sent
  - login detected
  - extended
  - reset
  - expired
  - purged
- the module stores the related prospect identity
- the admin can mark a demo as:
  - discovery
  - active opportunity
  - converted
  - lost
- the listing can filter by lifecycle status and sales status

### US-DEMO-012 - CRM or intake prefill
As a sales or ops user,
I want the demo form to be pre-filled from a discovery form or CRM payload,
so I avoid retyping the same business context and reduce setup time.

Acceptance criteria:
- the module can accept a prefill payload for:
  - prospect name
  - company
  - email
  - business type
  - sector
  - requested modules
  - desired outcome
- the admin can still review and edit everything before provisioning
- the prefill source is recorded for audit

## UX Expectations

### Demo list
The listing should evolve from a simple admin table to an operational cockpit:
- clear statuses
- fast filters
- template badge
- sent / not sent badge
- expires soon highlight
- quick actions for extend, clone, reset, purge

### Creation stepper
The stepper should remain the central entry point but gain:
- `Start from template`
- `Branding`
- `Scenario`
- `Access kit`
- `Review and launch`

### Workspace details panel
The admin should have a dedicated detail view or drawer containing:
- workspace summary
- credentials
- active modules
- template used
- timeline
- extension actions
- reset action
- purge action

## Data and Architecture Expectations
- keep `demo_workspaces` as the main lifecycle entity
- introduce reusable `demo_workspace_templates`
- add event history or timeline records rather than only mutating the workspace state
- prefer queue-based provisioning when payload richness increases
- keep purge flow deterministic and auditable
- avoid hidden coupling with legacy public demo accounts

## Delivery Priority

### Phase 1 - Fast commercial wins
Highest short-term value:
- templates library
- prospect access kit
- expiration extension shortcuts
- lifecycle statuses in listing

### Phase 2 - Reuse and control
Operational leverage:
- clone demo
- snapshot/reset
- branding customization
- scenario packs

### Phase 3 - Scale and intelligence
Industrialization:
- async provisioning with progress
- multi-role accesses
- timeline and conversion tracking
- CRM / intake prefill

## Definition of Done
- the admin can create demos faster than the current baseline for repeated use cases
- the module offers at least one reusable path that avoids manual reconfiguration
- demo access sharing is standardized and less error-prone
- workspace lifecycle is visible and auditable end-to-end
- destructive actions remain protected and explicit
- the architecture stays compatible with the current Laravel + Inertia + Vue stack

## Suggested MVP for Next Iteration
Si on veut une V2 pragmatique et a fort impact, le meilleur lot suivant est:
1. templates reutilisables
2. kit d acces prospect
3. quick extend + statuts de lifecycle

Ce trio apporte tout de suite:
- gain de temps
- meilleure coherence des demos
- meilleur suivi commercial

## Implementation Checklist

Statut d avancement du module au 2026-03-30.

Legend:
- [x] delivered
- [-] partial
- [ ] remaining

### User Story Coverage

- [x] US-DEMO-001 - reusable and personalized demo workspaces
- [x] start from a blank demo or a reusable template
- [x] template pre-fills company type, sector, modules, realism profile, and scenario packs
- [x] admin can still customize before provisioning
- [x] workspace keeps a visible lifecycle status
- [x] creator, prospect, and expiration are stored

- [x] US-DEMO-002 - demo templates library
- [x] create templates
- [x] edit templates
- [x] archive templates
- [x] duplicate templates
- [x] mark one template as default for a use case
- [x] keep archived templates visible while hiding them from the active library

- [-] US-DEMO-003 - clone an existing demo workspace
- [x] clone a demo workspace
- [x] copy configuration and selected modules
- [x] generate a new tenant owner, new credentials, and a new expiration date
- [ ] add an explicit choice to keep current realism data or regenerate fresh sample data
- [ ] expose clone as a real quick action from the main listing cockpit

- [-] US-DEMO-004 - snapshot and reset
- [x] save one active baseline snapshot
- [x] trigger reset to baseline
- [x] reset back to the saved reference dataset
- [x] audit actor, timestamp, and workspace activity
- [ ] replace browser confirm with a dedicated destructive action modal

- [x] US-DEMO-005 - prospect access kit
- [x] expose login URL, email, password, expiration, modules, and suggested flow
- [x] copy the full access kit in one click
- [x] generate an email-ready summary block
- [x] mark the demo as sent to prospect

- [x] US-DEMO-006 - guided scenario packs
- [x] attach one or more scenario packs to a demo
- [x] provide business objective, ordered actions, expected results, and key screens
- [x] include selected scenario packs in the access kit

- [-] US-DEMO-007 - expiration management and extension
- [x] quick extend actions for +3, +7, and +14 days
- [x] highlight expiring soon demos
- [x] filter expired demos
- [x] keep purge support
- [ ] add missing lifecycle states `draft` and `purged`

- [-] US-DEMO-008 - async provisioning with progress
- [x] run demo creation in a queued background job
- [x] show queued, provisioning, ready, and failed statuses
- [x] show progress and stage-based feedback
- [x] persist failure state on the workspace
- [ ] surface readable provisioning errors in the admin UI

- [-] US-DEMO-009 - branding and realism customization
- [x] set company display name
- [x] set logo
- [x] set primary branding colors
- [x] set short company description
- [x] preview branding before launch
- [ ] add team naming style controls
- [ ] add sample services or products focus controls

- [-] US-DEMO-010 - multi-role demo access
- [x] generate optional extra users for the same demo
- [x] keep extra accesses role-based on the same tenant
- [x] include multiple role logins in the access kit
- [ ] revoke extra accesses independently
- [ ] regenerate extra accesses independently

- [-] US-DEMO-011 - demo activity timeline and conversion visibility
- [x] store prospect identity on the workspace
- [x] track sales lifecycle: discovery, active opportunity, converted, lost
- [x] filter by lifecycle status and sales status in the listing
- [x] record timeline events for queue, provisioning, ready, sent, login detected, extension, reset, clone, delete
- [ ] add an explicit expired event in the timeline
- [ ] preserve a visible purged state or history entry after deletion

- [-] US-DEMO-012 - CRM or intake prefill
- [x] accept a prefill payload for prospect context
- [x] let admin review and edit everything before provisioning
- [x] record prefill source for audit
- [ ] connect a real discovery form or CRM source instead of manual JSON only

### UX Checklist

- [-] demo list as an operational cockpit
- [x] clear statuses
- [x] fast filters
- [ ] template badge in the listing
- [ ] sent / not sent badge in the listing
- [x] expiring soon filter and status
- [ ] quick actions in listing for extend, clone, reset, and purge

- [-] creation stepper evolution
- [x] start from template
- [x] scenario selection
- [x] branding section
- [x] access kit / review step
- [ ] rename or restructure the steps to match the target wording exactly: `Branding`, `Scenario`, `Access kit`, `Review and launch`

- [x] workspace details panel
- [x] workspace summary
- [x] credentials
- [x] active modules
- [x] template used
- [x] timeline
- [x] extension actions
- [x] reset action
- [x] purge action

### Priority Remaining Work

- [ ] add real cockpit quick actions to the main demo listing
- [ ] show template and sent badges directly in the list
- [ ] expose readable provisioning errors in list and detail views
- [ ] replace destructive confirms with proper modal flows
- [ ] finish clone options for realism reuse vs fresh regeneration
- [ ] complete branding realism inputs still missing from the brief
- [ ] add revoke / regenerate controls for extra role logins
- [ ] connect a real CRM or discovery-form prefill entry point
- [ ] add missing lifecycle and timeline coverage for expired / purged states

## Technical Execution Plan

Objectif:
- convertir la checklist restante en lots de livraison concrets
- garder un ordre qui maximise la valeur visible rapidement
- limiter les regressions en concentrant chaque lot sur un perimetre clair

### Workstream 1 - Listing cockpit polish

Goal:
- faire de la liste le vrai cockpit operationnel attendu

Scope:
- ajouter badge template dans la liste
- ajouter badge sent / not sent dans la liste
- enrichir le menu actions avec quick actions:
  - extend +3 / +7 / +14
  - clone
  - reset
  - purge
- afficher un etat erreur si provisioning failed

Likely files:
- `resources/js/Pages/SuperAdmin/DemoWorkspaces/Index.vue`
- `app/Http/Controllers/SuperAdmin/DemoWorkspaceController.php`

Implementation notes:
- reutiliser les actions deja presentes dans `Show.vue` pour eviter de dupliquer la logique serveur
- etendre `workspacePayload()` si la liste a besoin de plus de metadata visibles
- garder le menu teleport deja corrige et lui ajouter des groupes d actions

Definition of done:
- un admin peut piloter une demo sans quitter la liste pour les actions courantes
- les badges template et sent sont visibles directement
- un demo failed affiche un retour lisible

### Workstream 2 - Destructive action UX

Goal:
- remplacer les confirmations navigateur par des flux explicites et coherents

Scope:
- modal pour reset to baseline
- modal pour purge
- modal de confirmation eventuelle pour archive / restore template si on veut harmoniser

Likely files:
- `resources/js/Pages/SuperAdmin/DemoWorkspaces/Show.vue`
- `resources/js/Pages/SuperAdmin/DemoWorkspaces/Create.vue`
- `resources/js/Pages/SuperAdmin/DemoWorkspaces/Index.vue`
- `resources/js/Components/Modal.vue` ou `resources/js/Components/UI/Modal.vue`

Implementation notes:
- factoriser le pattern de confirmation pour ne pas recreer plusieurs implementations differentes
- rappeler dans la modal ce qui sera perdu, l id de la demo, et l impact sur le tenant

Definition of done:
- plus aucun `window.confirm` sur les actions destructives du module demo
- toutes les actions irreversibles sont explicites et contextualisees

### Workstream 3 - Clone and reset completion

Goal:
- finir les manques fonctionnels autour de la reutilisation de demos

Scope:
- ajouter une option de clone:
  - garder la meme logique de realisme
  - ou regenerer des donnees fraiches
- clarifier l UX clone depuis la liste
- verifier que reset et clone gardent un audit et une timeline coherents

Likely files:
- `resources/js/Pages/SuperAdmin/DemoWorkspaces/Show.vue`
- `resources/js/Pages/SuperAdmin/DemoWorkspaces/Index.vue`
- `app/Http/Controllers/SuperAdmin/DemoWorkspaceController.php`
- `app/Services/Demo/DemoWorkspaceProvisioner.php`

Implementation notes:
- introduire un champ explicite de strategie de clone, par exemple `clone_data_mode`
- eviter de casser le comportement actuel par defaut
- si le mode "reuse current data" est retenu, bien definir si on clone la config seule ou aussi un snapshot de reference

Definition of done:
- le clone couvre les deux modes voulus par la story
- l admin comprend ce qui sera recopie et ce qui sera regenere

### Workstream 4 - Provisioning error visibility

Goal:
- rendre les echecs actionnables au lieu de juste les stocker

Scope:
- afficher `provisioning_error` dans la liste si failed
- afficher `provisioning_error` dans la page detail
- ajouter un CTA de retry ou au minimum une marche a suivre claire

Likely files:
- `resources/js/Pages/SuperAdmin/DemoWorkspaces/Index.vue`
- `resources/js/Pages/SuperAdmin/DemoWorkspaces/Show.vue`
- `app/Http/Controllers/SuperAdmin/DemoWorkspaceController.php`
- `app/Jobs/ProvisionDemoWorkspaceJob.php`

Implementation notes:
- commencer par l affichage simple avant d ajouter un vrai retry
- si on ajoute retry, preferer une route explicite qui requeue le workspace existant

Definition of done:
- un admin voit pourquoi la demo a echoue
- il sait quoi faire ensuite sans aller en base ou dans les logs

### Workstream 5 - Branding realism completion

Goal:
- finir les champs de personnalisation encore absents par rapport a la story

Scope:
- team naming style
- sample services focus
- sample products focus
- verifier l usage reel des champs branding avancés deja valides mais peu exposes

Likely files:
- `resources/js/Pages/SuperAdmin/DemoWorkspaces/Create.vue`
- `resources/js/Pages/SuperAdmin/DemoWorkspaces/Show.vue`
- `app/Http/Controllers/SuperAdmin/DemoWorkspaceController.php`
- `app/Services/Demo/DemoWorkspaceProvisioner.php`
- `app/Services/Demo/DemoWorkspaceCatalog.php`

Implementation notes:
- distinguer clairement ce qui est purement cosmétique de ce qui influence vraiment le seed
- preferer un payload simple et stable dans `configuration` ou `branding_profile`

Definition of done:
- tous les champs cites dans la story sont parametrables
- la preview de creation resume ces choix avant lancement

### Workstream 6 - Multi-role access management

Goal:
- passer d une simple generation initiale a une vraie gestion des acces secondaires

Scope:
- revoke un acces secondaire
- regenerate un acces secondaire
- eventuellement masquer un acces du kit sans supprimer tout le demo

Likely files:
- `resources/js/Pages/SuperAdmin/DemoWorkspaces/Show.vue`
- `app/Http/Controllers/SuperAdmin/DemoWorkspaceController.php`
- `app/Services/Demo/DemoWorkspaceProvisioner.php`
- routes web superadmin demo workspaces

Implementation notes:
- definir si revoke signifie:
  - suppression du user associe
  - rotation du mot de passe
  - desactivation du team member
- journaliser chaque action dans la timeline

Definition of done:
- chaque acces secondaire peut etre gere individuellement
- le kit d acces reflète l etat reel des comptes secondaires

### Workstream 7 - Lifecycle and timeline completion

Goal:
- aligner totalement les statuts et l historique sur la story

Scope:
- ajouter ou clarifier l etat `draft`
- ajouter une vraie strategie pour `purged`
- ajouter un event timeline explicite pour `expired`

Likely files:
- `app/Models/DemoWorkspace.php`
- `app/Http/Controllers/SuperAdmin/DemoWorkspaceController.php`
- `app/Services/Demo/DemoWorkspaceTimelineService.php`
- `resources/js/Pages/SuperAdmin/DemoWorkspaces/Index.vue`
- `resources/js/Pages/SuperAdmin/DemoWorkspaces/Show.vue`

Implementation notes:
- `purged` peut etre:
  - un vrai statut persiste avant destruction finale
  - ou une archive legere de l historique si on ne veut pas garder le workspace
- choisir une seule approche avant de coder

Definition of done:
- les statuts de la story sont mappes clairement
- l historique lifecycle est lisible jusqu au bout

### Workstream 8 - CRM or intake integration

Goal:
- remplacer le prefill JSON manuel par un vrai point d entree produit

Scope:
- accepter un payload prefill depuis query params, route dediee, ou action CRM
- enregistrer la source et le payload utile
- laisser la review manuelle avant provisionnement

Likely files:
- `app/Http/Controllers/SuperAdmin/DemoWorkspaceController.php`
- `resources/js/Pages/SuperAdmin/DemoWorkspaces/Create.vue`
- eventuelle nouvelle route d entree superadmin

Implementation notes:
- commencer petit:
  - route de creation qui accepte un payload structure
  - ou query params normalises
- garder la version JSON manuelle comme fallback admin si utile

Definition of done:
- un sales ou ops peut arriver sur la creation avec le contexte deja injecte
- le prefill ne depend plus d un collage manuel

### Recommended Delivery Order

1. workstream 1 - listing cockpit polish
2. workstream 2 - destructive action UX
3. workstream 4 - provisioning error visibility
4. workstream 3 - clone and reset completion
5. workstream 6 - multi-role access management
6. workstream 7 - lifecycle and timeline completion
7. workstream 5 - branding realism completion
8. workstream 8 - CRM or intake integration

### Suggested First Dev Sprint

Sprint target:
- livrer un lot visible, utile, et peu risqué

Recommended bundle:
- listing cockpit polish
- destructive action UX
- provisioning error visibility

Expected outcome:
- meilleure pilotabilite quotidienne
- moins d erreurs admin
- meilleur alignement avec la V2 sans ouvrir trop de chantiers backend lourds
