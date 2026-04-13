# Platform Reusable DataTable - Technical Design

Last updated: 2026-04-13

## Overview
The platform currently uses multiple table screens with similar structure but locally duplicated markup and interaction logic.

This document defines the target shared architecture for a reusable `DataTable` system that can be applied progressively across the platform without forcing a full frontend rewrite.

The implementation target is a server-driven, Inertia-friendly table shell with composable building blocks.

## Problem Statement
Current list pages often reimplement the same concerns:
- page toolbar with search and filters
- action buttons `Filters`, `Clear`, `Apply`
- overflow and scrollbar styling
- table head and rows
- row action dropdown
- empty state
- pagination links
- result count text

This creates:
- duplication
- visual drift
- inconsistent defaults
- slower maintenance
- more fragile changes when the platform design evolves

## Design Goals
- centralize the shared table shell
- keep backend queries server-driven
- work naturally with Inertia paginated responses
- allow simple adoption without overengineering
- preserve existing design language:
  - `rounded-sm`
  - light border
  - top border accent where relevant
  - compact action menus
  - current dark mode palette

## Non-goals
- introducing a client-side table engine for every screen
- replacing specialized views like kanban, calendar, board, scheduler, or gallery
- supporting every advanced feature in V1
- forcing all modules to migrate in one PR

## Proposed Shared Components

### 1. `resources/js/Components/DataTable/AdminDataTable.vue`
Primary shell component.

Responsibilities:
- render the table wrapper card
- render the scroll container
- render header row and body slot
- render a standard empty state
- render a standard pagination footer
- expose optional result count slot or prop

Suggested props:
- `rows`
- `links`
- `total`
- `columns`
- `emptyTitle`
- `emptyDescription`
- `loading`
- `striped`
- `dense`
- `rowKey`
- `resultLabel`
- `embedded`
- `showPagination`
- `containerClass`

Suggested slots:
- `toolbar`
- `head`
- `row`
- `empty`
- `pagination_prefix`

### 2. `resources/js/Components/DataTable/AdminDataTableToolbar.vue`
Shared toolbar shell.

Responsibilities:
- search input layout
- `Filters`, `Clear`, `Apply` button row
- optional expandable filter area

Suggested props:
- `showFilters`
- `searchPlaceholder`
- `busy`

Suggested emits:
- `toggle-filters`
- `apply`
- `clear`

Suggested slots:
- `search`
- `filters`
- `actions`

### 3. `resources/js/Components/DataTable/AdminDataTableActions.vue`
Shared row action dropdown.

Responsibilities:
- render the 3-dot trigger
- render menu container with shared styling
- expose actions as slot content

Suggested slots:
- default slot for action links/buttons

### 4. `resources/js/Components/DataTable/AdminPaginationLinks.vue`
Shared pagination footer.

Responsibilities:
- render Laravel / Inertia pagination links
- style active / inactive states consistently

Props:
- `links`

### 5. `resources/js/composables/useDataTableFilters.js`
Optional helper for repetitive filter form behavior.

Responsibilities:
- initialize filter form from props
- provide `apply` and `clear`
- standardize `only`, `preserveState`, `preserveScroll`

This composable is optional in V1. The shared UI components are higher priority.

## Recommended Data Shape

### Backend response
Use paginated Inertia responses whenever possible.

Recommended payload shape:
```php
return Inertia::render('...', [
    'rows' => $query->paginate(10)->withQueryString()->through(fn ($record) => [
        'id' => $record->id,
        'name' => $record->name,
        'status' => $record->status,
    ]),
    'filters' => [
        'search' => (string) request('search', ''),
        'status' => (string) request('status', ''),
    ],
    'choices' => [
        'statuses' => [
            ['value' => 'active', 'label' => 'Active'],
            ['value' => 'draft', 'label' => 'Draft'],
        ],
    ],
]);
```

### Frontend usage
```vue
<AdminDataTable
    :rows="rows.data"
    :links="rows.links"
    :total="rows.total"
    :empty-title="$t('module.empty')"
>
    <template #toolbar>
        <AdminDataTableToolbar
            :show-filters="showFilters"
            :search-placeholder="$t('module.filters.search_placeholder')"
            @toggle-filters="showFilters = !showFilters"
            @apply="applyFilters"
            @clear="clearFilters"
        >
            <template #search>
                <!-- search field -->
            </template>
            <template #filters>
                <!-- optional expanded filters -->
            </template>
        </AdminDataTableToolbar>
    </template>

    <template #head>
        <!-- shared column headings -->
    </template>

    <template #row="{ row }">
        <!-- page-specific row rendering -->
    </template>
</AdminDataTable>
```

## Interaction Rules

### Server-driven by default
The component must not assume local sorting, local pagination, or local filtering.

Why:
- current screens already rely on backend query logic
- it keeps business logic and permissions server-side
- it avoids syncing duplicate state between client and server

### Default page size
Standard admin tables should default to `10` rows per page.

Allowed exceptions:
- asset/media grids
- highly operational tables where 15 or 25 is clearly justified
- reporting screens with a documented `per_page` selector

### Actions menu
Use one shared 3-dot dropdown component.

Rules:
- destructive actions are placed last
- destructive actions use red styling
- link actions and button actions can coexist

### Empty state
The default empty state should support:
- no records at all
- no results for current filters

V1 can use the same message for both cases if the module does not need distinction yet.

## Accessibility
- action trigger must include `aria-label`
- table headers must remain semantic `<th>`
- links and buttons in row actions must be keyboard reachable
- collapsed filter areas must remain accessible without pointer interaction
- search and filter fields must preserve visible labels or floating labels

## Theming and Visual Rules
- keep `rounded-sm`
- keep light border tokens used elsewhere
- reuse existing shadow levels
- reuse current scrollbar helper classes
- avoid module-specific custom styling inside the shared component
- pass only small variants through props when needed

## Migration Strategy

### Phase 1 - Shared primitives
Create:
- `AdminDataTable.vue`
- `AdminDataTableToolbar.vue`
- `AdminDataTableActions.vue`
- `AdminPaginationLinks.vue`

No broad migration yet.

### Phase 2 - Low-risk adoption
Migrate:
- `SuperAdmin/Pages/Index.vue`
- `SuperAdmin/Sections/Index.vue`

These pages are simple enough to validate the API.

### Phase 3 - Richer admin tables
Migrate:
- `SuperAdmin/Tenants/Index.vue`
- `SuperAdmin/Support/Index.vue`
- `SuperAdmin/Announcements/Index.vue`
- `SuperAdmin/MegaMenus/Index.vue`

This phase validates complex filters and action menus.

### Phase 4 - Module tables
Evaluate candidate module screens:
- customers
- quotes
- invoices
- team members
- services
- sales
- work
- tasks
- requests
- loyalty reports

Only migrate screens that really fit the standard table shell.

## Current Rollout Status

Last updated: 2026-04-13

Shared primitives implemented:
- `AdminDataTable.vue`
- `AdminDataTableToolbar.vue`
- `AdminDataTableActions.vue`
- `AdminPaginationLinks.vue`
- `useDataTableFilters.js`

Screens already migrated:
- `SuperAdmin/Pages/Index.vue`
- `SuperAdmin/Sections/Index.vue`
- `SuperAdmin/Tenants/Index.vue`
- `SuperAdmin/Support/Index.vue`
- `SuperAdmin/Announcements/Index.vue`
- `SuperAdmin/MegaMenus/Index.vue`
- `SuperAdmin/Admins/Index.vue`
- `SuperAdmin/DemoWorkspaces/Index.vue`
- `SuperAdmin/Assets/Index.vue`

Shared DataTable primitives now adopted on module screens:
- `Quote/UI/QuoteTable.vue`
- `Invoice/UI/InvoiceTable.vue`
- `Customer/UI/CustomerTable.vue`
- `Product/UI/ProductTable.vue`
- `Request/UI/RequestTable.vue`
- `Service/UI/ServiceTable.vue`
- `Sales/UI/SalesTable.vue`
- `Work/UI/WorkTable.vue`
- `Team/UI/TeamTable.vue`
- `Task/UI/TaskTable.vue`

Current module adoption shape:
- shared toolbar
- shared row action dropdown
- shared pagination links where applicable
- quote, invoice, customer, product, request, service, service categories, sales, work, support, plan scan, loyalty, portal loyalty, tips, reservation table shells, the campaigns index, and the lighter campaign managers (segments, VIP tiers, prospect providers) now mount through embedded `AdminDataTable` instead of carrying a separate local table shell
- allow module-local wrappers on top of shared primitives when a screen needs a wider or more specialized action menu
- quote, invoice, customer, product, request, and task now use local action-menu wrappers built on top of `AdminDataTableActions`
- customer keeps its existing card view and now reuses a dedicated shared empty-state component across table and card layouts
- loyalty and portal loyalty keep their richer KPI/filter side panels local while the ledger table shell is centralized
- owner and member tips keep their dashboard KPIs and export/filter workflows local while the paginated payment table shell is centralized
- reservation staff/client pages plus the live screen waiting list now centralize their table shells while keeping calendar boards, chair cards, live timing, and modal action workflows local
- product keeps its inline edit, stock adjust, import, alert details, and richer row states local while the shared table shell is centralized
- request keeps its board view, status dropdown, bulk actions, import flow, and modal workflows local while the table shell is centralized
- service now shares the same embedded shell pattern as the main CRUD list modules while keeping its modal create/edit workflow local
- sales now shares the same embedded shell pattern while keeping fulfillment quick actions and status updates local to the module
- work now shares the same embedded shell pattern while keeping its create modal and invoice creation workflow local
- team now shares the same embedded shell pattern for its management list even though it remains a non-paginated local-data screen
- task no longer carries its inactive fallback list shell; the active board, schedule, and team views stay local because they do not map cleanly to `AdminDataTable`
- keep custom card views, board views, schedule and team views, bulk-selection flows, inline-edit flows, proof-upload flows, and specialized row cells local for now

Next recommended wave:
- move the remaining hybrid module screens further toward `AdminDataTable.vue` only after the inline and board-specific workflows are extracted cleanly
- prioritize the remaining campaign table islands next (`MailingListManager`, `Show`, `Wizard`, `ProspectBatchWorkspace`) and dashboard table islands after that, then revisit other hybrid screens only where the board-specific workflows can be separated cleanly
- prioritize task workflow cleanup around board, schedule, and team concerns before considering any deeper shared-shell pass

Follow-up wave after that:
- evaluate whether customer cards/table and request board/table need a lighter second pass once their specialized local flows are worth extracting
- evaluate whether product should get a lighter second pass once its inline workflows are worth extracting, and whether task should get any further shared extraction once board, schedule, and team workflows are better isolated
- evaluate whether quote and invoice card layouts should eventually share a lighter reusable summary-card shell

## Current Candidates and Fit

### Good fit
- paginated server-driven lists
- predictable columns
- small toolbar
- row-level actions

### Bad fit
- drag-and-drop tables with specialized row handles
- mixed card/table screens
- spreadsheet-like grids
- heavily interactive tables with inline editing across many cells

## Testing Strategy

### Frontend
- smoke test for shared rendering
- verify empty state
- verify pagination link rendering
- verify actions dropdown renders slot content

### Feature tests
Each migrated controller should validate:
- default page size
- search filters
- status filters when applicable
- pagination query persistence with `withQueryString()`

### Manual QA checklist
- desktop and laptop widths
- long labels in action menu
- dark mode
- empty dataset
- filtered empty dataset
- pagination after applying filters

## Risks
- overgeneralizing too early and creating a component that is harder to use than the duplicated markup
- pushing specialized tables into a shell that does not fit them
- mixing backend concerns into the visual component
- allowing too many style variants and losing uniformity again

## Recommended V1 API Boundaries
- keep column rendering slot-based
- keep row rendering slot-based
- centralize shell, toolbar, actions menu, and pagination
- avoid introducing declarative column config for every use case in the first iteration

This keeps the component simple:
- reuse the hard parts
- leave business-specific row cells to each page

## Definition of Done
- shared DataTable shell exists
- shared toolbar exists
- shared action dropdown exists
- shared pagination footer exists
- `SuperAdmin/Pages` and at least one other screen are migrated
- default page size for migrated admin tables is `10`
- migration usage is documented for future screens

## Suggested File Map
- `resources/js/Components/DataTable/AdminDataTable.vue`
- `resources/js/Components/DataTable/AdminDataTableToolbar.vue`
- `resources/js/Components/DataTable/AdminDataTableActions.vue`
- `resources/js/Components/DataTable/AdminPaginationLinks.vue`
- `resources/js/composables/useDataTableFilters.js`

## Short Recommendation
Do not start by trying to build a universal enterprise grid.

Build a narrow, strong, reusable shell for the 80 percent case already present in the platform:
- search
- filters
- paginated rows
- action menu
- empty state
- Inertia pagination

That is the fastest path to real reuse without adding a second layer of complexity.
