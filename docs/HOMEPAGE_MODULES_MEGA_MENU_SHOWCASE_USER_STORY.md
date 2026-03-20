# Mega Menu Manager - User Story

Derniere mise a jour: 2026-03-19

## Goal
Creer un module admin-only permettant aux administrateurs de concevoir, organiser, previsualiser, activer et maintenir des mega menus riches depuis l interface super-admin.

Le module doit servir de brique CMS reusable pour piloter la navigation avancee du site sans intervention directe dans le code.

## Product Vision
Le mega menu n est pas limite a une simple liste de liens.

Il devient un espace de contenu navigable capable de melanger:
- navigation structuree
- categories
- cartes produit ou fonctionnalite
- contenu editorial
- banniere promo
- CTA
- image
- raccourcis modules
- petit bloc de demo ou de preuve

L equipe admin peut ainsi faire evoluer l experience de navigation comme un vrai composant de site builder.

## Why This Matters
- la navigation marketing doit pouvoir evoluer sans deploy
- les admins ont besoin d un workflow de brouillon, preview, activation et duplication
- le header, le footer, la sidebar et les zones custom doivent partager une logique commune
- le systeme doit rester extensible pour les futurs blocs et variantes de menu

## Non-goals
- remplacer tout le systeme de pages ou de sections
- autoriser la gestion du module a des utilisateurs non admin
- construire un moteur de theming complet dans cette V1
- introduire une logique d A/B testing ou de personnalisation avancee dans la premiere iteration

## Primary User Story

### US-MEGA-001 - Admin builds and publishes a mega menu
As a super administrator or authorized platform administrator, I can create and manage a mega menu from the admin interface so that I can control rich frontend navigation without editing code.

Acceptance criteria:
- the module is available only in the super-admin area
- unauthorized users cannot access the list, builder, preview, or write actions
- an admin can create a mega menu with metadata, items, columns, and blocks
- an admin can preview the result before activation
- only one active menu is live for a given location and custom zone

## Supporting User Stories

### US-MEGA-002 - Draft and publish workflow
As an admin, I can keep a menu in draft, activate it, or deactivate it later so that I can control when it becomes visible.

Acceptance criteria:
- a menu supports `draft`, `active`, and `inactive`
- activating a menu for a location deactivates the previously active one in that same scope
- deactivated menus remain editable

### US-MEGA-003 - Reusable display locations
As an admin, I can assign a mega menu to a location or custom zone so that the frontend can resolve the right menu in the right place.

Acceptance criteria:
- a menu can target `header`, `footer`, `sidebar`, or `custom`
- `custom` requires a custom zone key
- frontend resolution supports location fallback when no custom-zone-specific menu exists

### US-MEGA-004 - Builder-style editing
As an admin, I can edit the menu in a builder layout with structure on the left, preview in the center, and settings on the right so that the editing experience remains understandable even for non-technical users.

Acceptance criteria:
- the left panel manages items, children, columns, and blocks
- the center panel renders a live preview
- the right panel shows contextual settings for the selected node
- the preview supports desktop and tablet modes

### US-MEGA-005 - Rich top-level items
As an admin, I can configure each top-level item as a link, a classic dropdown, or a mega panel so that I can adapt navigation depth to the content need.

Acceptance criteria:
- each item stores label, link type, target, icon, badge, visibility, and order
- a classic dropdown can contain child items
- a mega panel can contain columns and blocks

### US-MEGA-006 - Flexible content blocks
As an admin, I can insert different block types into menu columns so that the mega menu can promote content and not only navigation links.

Acceptance criteria:
- supported blocks include:
  - navigation group
  - category list
  - cards
  - featured content
  - image
  - promo banner
  - CTA
  - text
  - HTML
  - module shortcut
  - demo preview
- each block has its own payload and settings
- the system can be extended with new block types later

### US-MEGA-007 - Media selection and upload
As an admin, I can pick assets from the media library or upload a new file directly from the builder so that image-driven blocks can be completed in one workflow.

Acceptance criteria:
- the builder can open the asset library
- the builder can upload new media inline
- alt text is supported where relevant

### US-MEGA-008 - Reordering
As an admin, I can reorder menus, items, columns, and blocks with drag and drop so that structure changes feel fast and visual.

Acceptance criteria:
- menu list order is draggable
- top-level items are draggable
- classic children are draggable
- columns are draggable
- blocks inside columns are draggable

### US-MEGA-009 - Search and filters
As an admin, I can filter and search the mega menu list so that I can find the right menu quickly when the catalog grows.

Acceptance criteria:
- the list supports search by title, slug, or description
- the list filters by status
- the list filters by location

### US-MEGA-010 - Frontend fallback
As the system, I must return a safe fallback when no active menu exists for a location so that public pages do not break.

Acceptance criteria:
- resolving a missing menu returns a fallback payload
- the frontend can still display basic fallback links

## Core Business Rules
- only admins with `mega_menus.manage` or superadmins can manage this module
- slugs must be unique
- custom zones are required only when the display location is `custom`
- mega panels need at least one column
- classic dropdowns need at least one child item
- columns need at least one block
- routes, URLs, anchors, and internal page paths must be valid

## Given / When / Then

### A) Create and preview
- Given an authorized admin opens the builder
- When they create a menu with items, columns, and blocks
- Then the live preview updates immediately and the menu can be saved as draft

### B) Publish
- Given a location already has one active mega menu
- When the admin activates another menu for the same location and custom zone
- Then the new menu becomes active and the previous one becomes inactive

### C) Duplicate
- Given an admin wants to start from an existing menu
- When they click duplicate
- Then the system creates a draft copy with a new slug and the nested structure preserved

### D) Fallback
- Given no active menu exists for a requested location
- When the frontend resolves that location
- Then it receives a fallback payload instead of failing

## Suggested Future Stories

### US-MEGA-011 - Scheduled publication
As an admin, I can define start and end publication windows so that seasonal menus can go live automatically.

### US-MEGA-012 - Multilingual menus
As an admin, I can localize labels and blocks per locale so that the same location can serve translated navigation.

### US-MEGA-013 - Role-based visibility
As an admin, I can restrict menu items or blocks to certain audiences or roles so that navigation becomes contextual.

### US-MEGA-014 - Click analytics
As an admin, I can track item and block clicks so that I understand which navigation patterns perform best.

### US-MEGA-015 - A/B testing
As an admin, I can compare menu variants so that I can optimize engagement and conversion.

### US-MEGA-016 - Personalization by tenant or audience
As an admin, I can assign different menu variants to specific tenants, plans, or segments so that navigation becomes more relevant.

## Definition of Done
- admin-only route access is enforced
- full CRUD, duplicate, activate, deactivate, and preview are available
- nested builder data is persisted through migrations and models
- frontend rendering can resolve menus by location or slug
- a safe fallback exists
- demo seed data is available
- tests cover permissions and key business logic
- technical documentation explains structure, data model, permission model, rendering flow, and extension path
