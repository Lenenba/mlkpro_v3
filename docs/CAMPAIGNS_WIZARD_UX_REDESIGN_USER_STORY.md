# Campaigns Wizard UX Redesign - User Story

Derniere mise a jour: 2026-03-17

## Goal
Refondre l experience de creation, edition, verification et pilotage des campagnes dans Malikia Pro afin qu elle soit:
- plus simple a comprendre
- moins dense visuellement
- plus guidee
- plus rassurante
- plus premium

L objectif n est pas de retirer les capacites existantes. Il faut conserver la richesse fonctionnelle deja livree, tout en la reorganisant dans une experience produit plus claire, plus progressive et plus orientee action.

## Product Problem
Le module campagnes est deja puissant, mais l experience actuelle concentre trop d informations, de controles, de resumes et d actions sur les memes ecrans.

Conséquences:
- surcharge cognitive des la premiere ouverture
- difficulte a comprendre le bon ordre des actions
- trop de CTA concurrents
- confusion entre edition, verification, previsualisation et lancement
- vocabulaire parfois technique ou ambigu pour un utilisateur non technique
- etapes `Review` et `Results` qui ne jouent pas encore un vrai role de pilotage

## Scope
- audit UX strict du flow actuel
- redesign du wizard `Parameters -> Audience -> Message -> Review -> Results`
- navigation guidee avec `Previous / Next`
- auto-advance apres sauvegarde quand pertinent
- clarification des labels et actions
- refonte de l action `Appliquer snapshot`
- ajout du flux de suppression de campagne
- clarification des differences entre `delete`, `archive`, `duplicate`, `draft`
- progressive disclosure des options avancees
- meilleure hierarchie visuelle des zones denses
- standardisation des CTA et des emplacements d actions
- transformation de `Review` en vrai pre-flight
- transformation de `Results` en vraie continuation du cycle de vie
- refactor des composants Inertia existants

## Non-goals
- changer les regles metier de ciblage, de templates, de preview, de send ou de tracking
- reconstruire tout le module campagnes from scratch
- remplacer la stack existante
- casser la compatibilite avec les services backend deja relies au wizard

## Technical Constraint
Le brief parle de `Laravel + Inertia + React`, mais la base actuelle du module campagnes repose sur `Laravel + Inertia + Vue`.

La refonte doit donc s integrer proprement a la stack reelle du projet:
- backend Laravel
- pages Inertia
- composants Vue

## Product Principles
- guider avant d exposer toute la complexite
- toujours montrer l etape actuelle, la suivante et le niveau de completion
- ne montrer que les decisions utiles maintenant
- releguer les options secondaires dans des zones avancees explicites
- faire des resumes de contexte lisibles a chaque etape
- rendre les actions irreversibles rares, explicites et securisees
- harmoniser le langage metier sur tout le module
- garder une coherence premium SaaS: calme, claire, fiable

## Current UX Audit

### Audit 1 - Parameters
Pain points:
- trop de champs presentes d un bloc
- manque de hierarchie entre informations obligatoires, recommandees et avancees
- l action `Appliquer snapshot` n explique ni son but, ni sa consequence
- l utilisateur ne comprend pas toujours si il configure un brouillon, une campagne prete, ou un modele reutilisable
- les CTA melangent sauvegarde, edition et progression

### Audit 2 - Audience
Pain points:
- etape trop dense car elle agrège plusieurs logiques:
  - segment
  - mailing lists
  - contacts manuels
  - prospection
  - fournisseurs externes
- la relation entre source, estimation, filtres et audience finale n est pas toujours evidente
- les tableaux, compteurs, imports et actions sont trop proches et se concurrencent visuellement
- l utilisateur ne sait pas toujours quelle est la prochaine action recommandee

### Audit 3 - Message
Pain points:
- la zone edition, la preview, les tests, le choix de template et les options de rendu coexistent souvent sans priorite claire
- trop d outils visibles en meme temps
- manque de separation entre:
  - ecrire le message
  - choisir sa structure
  - previsualiser
  - tester

### Audit 4 - Review
Pain points:
- l etape ne joue pas encore assez le role de `pre-flight`
- les warnings, blocages et prerequis ne sont pas assez synthetises
- le niveau de readiness n est pas assez explicite
- on ne distingue pas clairement:
  - ce qui est configure
  - ce qui manque
  - ce qui bloque le lancement
  - ce qui est recommande avant envoi

### Audit 5 - Results
Pain points:
- l ecran peut sembler vide ou deconnecte du reste du parcours
- le lien entre campagne configuree, campagne envoyee et resultats reels n est pas assez fluide
- si des analytics detaillees vivent ailleurs, ce n est pas toujours assez bien explique

### Audit 6 - Cross-cutting
Pain points:
- absence d un pattern d actions persistent en bas d ecran
- progression encore trop dependante de tabs
- vocabulaire d actions pas completement unifie
- pas de strategie destructive lisible dans le parcours
- trop de contenu affiche en une fois sur certaines etapes

## Target UX Vision
Le module doit devenir un wizard guide par objectif.

Chaque etape doit repondre clairement a 4 questions:
1. Ou suis-je ?
2. Qu est-ce qui est deja fait ?
3. Qu est-ce qu il me reste a faire ?
4. Quelle est la meilleure prochaine action ?

Le wizard cible doit se comporter comme un assistant structure:
- rail ou stepper clair
- resume contextuel compact
- contenu principal concentre sur la tache courante
- panneau de contexte ou previsualisation secondaire
- sticky action bar avec actions cohérentes

## Redesigned Flow

### Step 1 - Parameters
Role:
Definir le cadre de la campagne.

Doit contenir:
- nom
- type
- canal
- langue
- calendrier / statut de travail
- configuration de base

Doit etre simplifie par:
- regroupement en cartes courtes
- options avancees repliees
- aide contextuelle sur chaque choix strategique

### Step 2 - Audience
Role:
Choisir d ou viennent les destinataires et verifier la qualite de la cible.

Doit contenir:
- source principale
- logique de selection
- imports / fournisseur / segment selon le contexte
- estimation audience
- warnings de couverture, consentement, fatigue, doublons

Doit etre simplifie par:
- affichage conditionnel selon le mode choisi
- separation nette entre `selection`, `validation`, `resume`
- cartes de source plutot qu un long formulaire plat

### Step 3 - Message
Role:
Construire le contenu et verifier le rendu.

Doit contenir:
- structure/template
- edition du contenu
- preview
- test send / preview

Doit etre simplifie par:
- edition principale a gauche
- contexte / preview a droite ou en panneau secondaire
- options avancees de rendu dans des zones repliees

### Step 4 - Review
Role:
Valider que la campagne est prete a etre envoyee.

Doit contenir:
- resume type / canal / audience / contenu
- readiness score ou statut
- warnings
- blockers
- configuration manquante
- action de lancement ou correction

### Step 5 - Results
Role:
Montrer la suite logique apres lancement.

Doit contenir:
- statut courant de la campagne
- dernier run
- progression haute-niveau
- lien clair vers analytics/detail
- empty state explicite si aucune execution

## Primary User Story

### US-CMP-UX-001 - Guided and reassuring campaign wizard
As a tenant owner or marketing manager,
I want the campaign module to guide me step by step with a clear, low-friction experience,
so I can create, review, update and launch campaigns confidently without feeling overwhelmed.

Acceptance criteria:
- the wizard presents a clear 5-step structure: `Parameters`, `Audience`, `Message`, `Review`, `Results`
- the user always sees the current step, completed steps and remaining steps
- each step has persistent `Previous` and `Next` actions
- the primary action at the bottom saves the step and can move forward automatically
- the UI hides non-essential options by default
- terminology is business-friendly and consistent
- destructive actions are protected and clearly separated
- the review step shows readiness, blockers and recommended next action
- the results step feels connected to the campaign lifecycle

## Supporting User Stories

### US-CMP-UX-002 - Safe campaign deletion flow
As a campaign manager,
I want to delete a campaign safely from the interface,
so I can remove unwanted drafts or obsolete campaigns without risking accidental data loss.

Acceptance criteria:
- delete action is visible only to authorized users
- delete is visually separated from archive and duplicate
- a confirmation modal is required
- the modal explains the consequence clearly
- deletion is blocked or additionally guarded for active/running campaigns when necessary
- success and failure feedback are explicit

### US-CMP-UX-003 - Clear lifecycle distinction
As a campaign manager,
I want to understand the difference between draft, archive, duplicate and delete,
so I know exactly what each action does.

Acceptance criteria:
- `Save draft` means keep editing later
- `Duplicate` means create a new campaign from the current one
- `Archive` means hide from active workflow without destroying history
- `Delete` means irreversible removal with safeguards
- the UI explains these differences where relevant

### US-CMP-UX-004 - Persistent step navigation
As a user,
I want to move through the wizard naturally,
so I am not forced to jump between tabs manually.

Acceptance criteria:
- each step exposes `Previous` and `Next`
- the primary action label is contextual:
  - `Save and continue`
  - `Update and continue`
  - `Review readiness`
  - `Open results`
- users can still navigate to another step, but guided navigation is primary
- the stepper indicates completion status

### US-CMP-UX-005 - Progressive disclosure
As a user,
I want to see only what matters for the current task,
so I can focus without visual overload.

Acceptance criteria:
- advanced options are moved into collapsible sections, drawers or secondary panels
- conditional fields appear only when the related feature is active
- tables and metrics are not shown before they are relevant
- helper text clarifies why hidden sections may matter later

### US-CMP-UX-006 - Snapshot action redesign
As a non-technical business user,
I want to understand what the snapshot action does,
so I can reuse configuration safely without guessing.

Acceptance criteria:
- `Appliquer snapshot` is removed or renamed
- replacement wording is explicit and business-friendly
- the concept is explained inline
- a user understands whether the action:
  - loads a saved campaign setup
  - restores recommended defaults
  - reuses an existing configuration preset
- the action explains what fields it will affect

Proposed wording direction:
- `Charger une configuration enregistree`
- or `Reutiliser une configuration de campagne`

### US-CMP-UX-007 - Audience step redesign
As a campaign manager,
I want the audience step to group selection, validation and estimation clearly,
so I can understand how the audience is built and whether it is viable.

Acceptance criteria:
- the user first chooses the audience source mode
- only the relevant source UI is shown
- estimation and quality indicators are grouped in a dedicated summary area
- imports, filters, preview and review actions are not all mixed in the same visual block
- prospecting and provider flows remain available without overwhelming customer marketing flows

### US-CMP-UX-008 - Message step redesign
As a campaign manager,
I want to write, preview and test the message in a calmer layout,
so I can focus on content quality and rendering.

Acceptance criteria:
- editing tools are clearly separated from preview tools
- test and preview actions are secondary to editing
- template selection is contextual and understandable
- advanced rendering options are collapsed by default
- the screen is easier to scan on desktop and mobile

### US-CMP-UX-009 - Review as pre-flight
As a campaign manager,
I want the review step to behave like a launch checklist,
so I know exactly whether the campaign is ready.

Acceptance criteria:
- review shows campaign type, channels, audience source and content status
- review highlights missing requirements
- review distinguishes warnings from blockers
- review displays launch readiness clearly
- review provides direct links back to steps needing correction

### US-CMP-UX-010 - Results as lifecycle continuation
As a campaign manager,
I want the results step to feel like the natural continuation after launch,
so I can move from configuration to monitoring without confusion.

Acceptance criteria:
- when no run exists, results explain what will appear after send
- when runs exist, results show a concise high-level summary
- if deep analytics live elsewhere, the UI explains where and why
- the user can move naturally from review/send into results

### US-CMP-UX-011 - Standardized CTA system
As a user,
I want consistent labels, priorities and placements for actions,
so I do not need to relearn the interface from one step to another.

Acceptance criteria:
- CTA wording is standardized across the module
- destructive, secondary and primary actions have consistent placement
- primary CTA is always visually obvious
- tertiary utility actions are demoted in emphasis

Proposed CTA vocabulary:
- `Save draft`
- `Save and continue`
- `Update and continue`
- `Back`
- `Preview`
- `Send test`
- `Approve audience`
- `Reject batch`
- `Review readiness`
- `Send now`
- `Schedule send`
- `Open results`
- `Duplicate campaign`
- `Archive campaign`
- `Delete campaign`

## UX Architecture Proposal

### Wizard shell
Create a reusable wizard shell that owns:
- step state
- completion state
- sticky action bar
- top progress rail
- helper banner
- dirty state
- save / continue logic

### New reusable UI patterns
- `CampaignWizardShell.vue`
- `CampaignStepRail.vue`
- `CampaignStickyActionBar.vue`
- `CampaignSectionCard.vue`
- `CampaignInlineHelp.vue`
- `CampaignEmptyState.vue`
- `CampaignReviewChecklist.vue`
- `CampaignReadinessBanner.vue`
- `CampaignDangerZone.vue`
- `CampaignConfirmDialog.vue`

### Layout model
Each step should follow the same composition:
- page header with title, status and compact meta
- main content column for the active task
- secondary summary panel or contextual side panel
- sticky action bar at bottom

## Refactor Strategy

### Existing files likely involved
- `resources/js/Pages/Campaigns/Wizard.vue`
- `resources/js/Pages/Campaigns/Show.vue`
- `resources/js/Pages/Campaigns/Index.vue`
- `app/Http/Controllers/CampaignController.php`
- `app/Policies/CampaignPolicy.php`
- campaign request classes and services already used by save/update/destroy

### Refactor principles
- preserve backend payload contracts where possible
- extract new shell and card components out of the current large wizard file
- move business logic into helpers/composables when presentational concerns dominate
- do not duplicate campaign rules already enforced server-side
- keep existing endpoints unless new UX needs additional dedicated actions

## Implementation Notes

### Delete flow
- use existing `destroy` endpoint if compatible
- add explicit permission gating in UI and policy checks in backend
- hide delete for unauthorized users
- show delete only in a dedicated danger zone or overflow menu

### Archive strategy
If archive does not exist yet, add a soft operational status distinct from delete.

Recommended strategy:
- `draft`: editable, not launched
- `active/scheduled/running/completed`: lifecycle states
- `archived`: removed from day-to-day views but kept for history
- `deleted`: irreversible removal

### Snapshot redesign
Replace technical wording with a business intent.

Preferred concept:
- `Use a saved setup`
- or `Reuse campaign configuration`

The UI must explain:
- where the configuration comes from
- what it will replace
- whether the action is reversible before save

## Delivery Phases

### Phase 1 - Foundation
- UX audit implementation note
- wizard shell
- stepper
- sticky action bar
- CTA normalization

### Phase 2 - Parameters redesign
- regroup fields
- clarify snapshot action
- move advanced settings behind disclosure

### Phase 3 - Audience redesign
- source-first flow
- grouped estimation and warnings
- cleaner provider/prospecting/customer targeting blocks

### Phase 4 - Message redesign
- calmer editing layout
- clearer preview/test hierarchy
- cleaner template and content grouping

### Phase 5 - Review and Results
- pre-flight checklist
- readiness banner
- improved empty/results states

### Phase 6 - Lifecycle actions
- delete
- archive
- duplicate
- refined draft handling

## Definition of Done
- campaign wizard is measurably easier to scan and navigate
- each step has consistent structure and actions
- snapshot concept is understandable to a non-technical user
- delete flow is safe and permission-aware
- review clearly communicates readiness
- results clearly communicate next lifecycle state
- existing campaign business logic remains intact
- the redesign ships inside the current Laravel + Inertia + Vue architecture
- targeted tests and smoke checks cover the new UX-critical behavior

