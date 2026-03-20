# Platform Reusable DataTable - User Story

Last updated: 2026-03-20

## Goal
Creer un composant `DataTable` reutilisable sur toute la plateforme afin d unifier les listes admin et metier, reduire les duplications de code, et garantir une UX stable pour la recherche, les filtres, la pagination, les actions et les etats vides.

## Product Vision
Aujourd hui, plusieurs ecrans utilisent le meme langage visuel, mais chaque table reste assemblee localement.

Le but de cette initiative est de passer d un simple "pattern copie-colle" a une vraie brique partagée:
- meme structure visuelle
- meme logique de toolbar
- meme pagination
- meme dropdown d actions
- meme empty state
- meme comportement responsive
- meme integration Inertia

Le composant doit servir autant aux ecrans `SuperAdmin` qu aux modules metier quand le besoin est une liste tabulaire standard.

## Why This Matters
- l uniformite visuelle est critique pour la perception de qualite de la plateforme
- aujourd hui, chaque nouvelle table demande du code repetitif
- les variations locales rendent les evolutions lentes et fragiles
- les bugs UX de pagination, filtres ou actions se corrigent trop souvent ecran par ecran
- les equipes doivent pouvoir ajouter une nouvelle table rapidement sans reinventer la structure

## Non-goals
- remplacer en une seule iteration toutes les tables specialisees de la plateforme
- forcer un composant unique pour des experiences non tabulaires comme kanban, cards ou calendars
- ajouter des features avancees non necessaires en V1 comme colonnes redimensionnables, pinning, export natif ou virtualisation
- migrer le backend vers du client-side sorting global

## Primary User Story

### US-DATATABLE-001 - Shared platform table shell
As a platform administrator or module user, I want all table-based lists to behave consistently so that I can search, filter, browse, and act on records without relearning each screen.

Acceptance criteria:
- all migrated pages use the same DataTable shell
- the shell includes toolbar, filters area, rows, actions menu, empty state, and pagination
- the shell accepts server-driven data and pagination links
- the shell supports dark mode and the existing rounded / border language of the platform

## Supporting User Stories

### US-DATATABLE-002 - Shared toolbar
As a user, I can always find search, filters, clear, and apply actions in the same place.

Acceptance criteria:
- the toolbar layout is identical across migrated screens
- the search field sits in the main row
- secondary filters can be collapsed or expanded
- clear and apply buttons use the same wording and hierarchy everywhere

### US-DATATABLE-003 - Shared actions menu
As a user, I can access row actions from the same compact dropdown on every list.

Acceptance criteria:
- row actions use the same trigger button and dropdown style
- action ordering is predictable
- dangerous actions are visually distinct

### US-DATATABLE-004 - Shared pagination
As a user, I can browse paginated records with the same footer and the same number of rows per page default.

Acceptance criteria:
- default page size is `10` for migrated admin tables unless a module explicitly needs another value
- pagination links use the same visual style
- total results can be shown in a standard slot or label

### US-DATATABLE-005 - Shared empty states
As a user, I always understand whether a table is empty because there is no data or because my filters returned no match.

Acceptance criteria:
- empty states support a title and optional description
- empty states use the same border and spacing rules

### US-DATATABLE-006 - Reusable filters
As a developer, I can define filters declaratively instead of rebuilding every form by hand.

Acceptance criteria:
- text, select, date, and custom filter slots are supported
- filters can bind cleanly to Inertia forms
- modules can opt into compact or expanded filter layouts

### US-DATATABLE-007 - Progressive migration
As a platform team, we can migrate screens gradually without blocking day-to-day delivery.

Acceptance criteria:
- the new DataTable can coexist with legacy pages during rollout
- the migration order is documented
- each migrated page keeps its current backend query model unless a real change is needed

## Target Screens for First Rollout
- `SuperAdmin/Tenants/Index.vue`
- `SuperAdmin/Support/Index.vue`
- `SuperAdmin/Announcements/Index.vue`
- `SuperAdmin/Pages/Index.vue`
- `SuperAdmin/Sections/Index.vue`
- `SuperAdmin/MegaMenus/Index.vue`

## Core Business Rules
- the DataTable is a presentation and interaction shell, not a data source
- data remains server-driven by default
- pagination remains backend-driven by default
- a standard admin table uses `10` rows per page unless a different default is justified and documented
- the shared component must preserve accessibility labels, keyboard navigation, and dark mode support

## Given / When / Then

### A) Reuse on a new screen
- Given a developer needs a new listing page
- When they use the reusable DataTable
- Then they configure columns, rows, filters, and actions without rebuilding the shell

### B) Apply filters
- Given a list screen exposes search and filters
- When the user changes one or more filter values and clicks apply
- Then the page refreshes through Inertia with the same table shell and updated rows

### C) Clear filters
- Given the user has active filters
- When they click clear
- Then the list returns to its default query state and the filter controls reset

### D) Empty result after filtering
- Given the dataset exists but no row matches the active filters
- When the response returns zero rows
- Then the DataTable shows the standard empty state instead of a broken or collapsed table

## Definition of Done
- a reusable DataTable shell exists in shared components
- the API is documented
- at least one admin screen is fully migrated
- row actions, filters, and pagination are handled through the shared component
- the standard page size default is `10`
- tests or smoke coverage validate at least one migrated screen
- technical documentation explains architecture, slots, props, and migration strategy

## Suggested Future Stories

### US-DATATABLE-008 - Column sorting
As a user, I can sort supported columns consistently across migrated tables.

### US-DATATABLE-009 - Bulk actions
As a user, I can select multiple rows and run supported actions in one step.

### US-DATATABLE-010 - Saved views
As a user, I can save a filter and column configuration as a reusable view.

### US-DATATABLE-011 - Export adapters
As a developer, I can plug CSV or XLS export actions into the shared DataTable toolbar.

