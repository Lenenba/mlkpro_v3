# Platform Architecture Audit

Date: 2026-03-06

## Current State

The platform is functionally stable at the moment:

- Full test suite is green: `168 passed`, `998 assertions`
- Frontend build is green: `npm run build` passes
- The project does not need a rewrite to become reliable

That said, the codebase is still carrying significant structural debt. The next gains will not come from bug fixing. They will come from architectural cleanup, responsibility separation, and payload/performance reduction.

This audit is based on the whole platform structure, the current green test/build baseline, and the largest technical hotspots that now limit maintainability, scalability, and development speed.

## 1. Main Architectural Problems

### 1.1 Oversized Controllers and Services

The application has grown into a large modular monolith, but several core files are now too large to remain safe change points.

Backend hotspots:

- `app/Services/Assistant/AssistantWorkflowService.php`: 4421 lines
- `app/Http/Controllers/DashboardController.php`: 1791 lines
- `app/Http/Controllers/SaleController.php`: 1649 lines
- `app/Http/Controllers/ProductController.php`: 1622 lines
- `app/Http/Controllers/Reservation/StaffReservationController.php`: 1385 lines
- `app/Http/Controllers/CustomerController.php`: 1208 lines
- `app/Services/ReservationAvailabilityService.php`: 1207 lines
- `app/Http/Controllers/RequestController.php`: 1165 lines
- `app/Http/Controllers/TaskController.php`: 1006 lines

This creates predictable failure modes:

- business rules are hard to locate
- changes become risky because flows are tightly coupled
- test setup becomes heavy
- new contributors need too much context before making safe edits
- “small” changes can produce broad regressions

The largest issue is not file length by itself. It is that validation, authorization, orchestration, persistence, side effects, and response shaping are often mixed in the same class.

### 1.2 Too Much Inline Validation

The platform currently has:

- `130` controllers
- only `23` Form Requests
- around `187` `validate(...)` occurrences in controllers

That ratio is too low for a codebase of this size. Validation rules are still scattered across write flows, especially in controllers handling sales, tasks, reservations, and operational workflows.

Consequences:

- duplicated rules between `store` and `update`
- inconsistent error behavior across similar endpoints
- harder controller testing
- brittle future changes when business rules evolve

### 1.3 Business Orchestration Lives in Delivery Layers

Several controllers are acting as delivery layer, business layer, and formatting layer at the same time.

Examples:

- `SaleController` handles request validation, stock checks, customer ownership, Stripe branching, pricing rules, persistence, inventory adjustments, and timeline logging.
- `CustomerController@show` assembles a complete customer business dashboard instead of delegating to dedicated queries or read assemblers.
- `DashboardController` combines role detection, metrics, caching, aggregation, and response shaping in a single controller.

This is the main maintainability bottleneck in the current architecture.

### 1.4 Read Models Are Too Heavy and Built Inside Controllers

The platform contains several heavy “screen queries” that are currently assembled inline in controllers.

Strong examples:

- customer detail screens
- dashboard screens
- reservation planning and slot generation
- task and work operational screens

This leads to:

- repeated aggregation logic
- oversized Inertia payloads
- poor visibility into expensive reads
- difficult optimization because query logic and UI shaping are intertwined

### 1.5 Frontend Pages Are Too Monolithic

Frontend hotspots:

- `resources/js/Pages/Task/UI/TaskTable.vue`: 2863 lines
- `resources/js/Pages/Public/Store.vue`: 2069 lines
- `resources/js/Pages/Planning/Index.vue`: 2066 lines
- `resources/js/Pages/Settings/Company.vue`: 1933 lines
- `resources/js/Pages/Product/UI/ProductTable.vue`: 1870 lines
- `resources/js/Pages/Settings/Billing.vue`: 1778 lines
- `resources/js/Pages/Customer/Show.vue`: 1631 lines

This is not just a readability problem. It reduces UI evolvability:

- the same patterns are reimplemented multiple times
- a small UI change requires navigating large files with many unrelated concerns
- view state, data transformation, and rendering logic are overly coupled

### 1.6 Payload Inflation and Eager Loading

Several flows still fetch too much data too early.

Concrete examples:

- `QuickCreateModals.vue` fetches customers or categories on component mount instead of on actual modal open
- `CustomerController@options` returns all customers with related properties for quick actions
- `ServiceController@index` injects all material products into the page
- `CustomerController@show` aggregates a very large amount of related business data in a single request

This hurts responsiveness when tenant data grows.

### 1.7 Route and Entry-Point Sprawl

The route surface is large:

- `711` routes in total
- `routes/web.php`: 708 lines
- `routes/api.php`: 415 lines

The issue is not the route count alone. The problem is that web, API, portal, public, and admin concerns are still difficult to read as clear bounded surfaces.

This increases:

- navigation cost
- duplication risk
- authorization inconsistency risk
- long-term onboarding difficulty

### 1.8 Authorization Structure Is Still Thin Relative to Platform Size

Current counts:

- `7` policy classes
- `93` authorization references detected in `app`

The platform does use policies in some areas, but the authorization surface is larger than the current policy structure suggests. Some authorization logic is still embedded in controllers or services rather than consistently centralized.

### 1.9 Frontend Bundle Still Too Heavy

Current build highlights:

- main app chunk: about `982.55 kB`
- schedule chunk: about `231.29 kB`
- `vuedraggable` vendor chunk: about `177.25 kB`

The frontend is stable, but not yet optimized for broad usage at scale. Large pages, eager translation loading, and heavyweight UI bundles will affect perceived performance as the platform grows.

## 2. All Refactoring Opportunities

### 2.1 High-Value, Low-Risk Opportunities

- Replace repeated inline validation with Form Requests on high-traffic write flows
- Extract repeated write use cases into focused Action classes
- Centralize repeated status logic and business transitions behind enums and named services/actions
- Introduce dedicated query objects or read assemblers for the heaviest screens
- Split oversized Vue pages into reusable business sections
- Move modal option loading to on-demand fetching
- Normalize API/JSON response shaping using Resources or dedicated transformers where repeated

These changes are powerful because they improve readability and testability without changing the platform model.

### 2.2 High-Pain Areas to Refactor Next

- `AssistantWorkflowService`
- `SaleController`
- `DashboardController`
- `CustomerController`
- `ReservationAvailabilityService`
- `StaffReservationController`
- `TaskController`

These are the files most likely to slow down future development and create regressions.

### 2.3 Performance-Focused Opportunities

- Reduce oversized Inertia payloads on dashboard, customer, reservation, and planning screens
- Lazy-load modal datasets and optional heavy UI data
- Split large frontend chunks where interaction frequency is lower
- Defer expensive secondary data on detail pages
- Introduce clearer caching boundaries for dashboard and operational read models

### 2.4 Reusability Opportunities

- Create reusable query builders for heavy listings and dashboards
- Standardize option endpoints for selects and quick-create flows
- Reuse structured page sections for cards, side panels, timelines, filters, and tables
- Consolidate repeated pricing, stock, status, and workflow transition logic

## 3. Proposed Target Architecture and Hierarchy

## 3.1 Target Model: Domain-Oriented Modular Monolith

The strongest long-term architecture for this platform is not a rewrite into microservices. It is a cleaner modular monolith with domain-oriented packaging and strict Laravel-first conventions.

Why this is the right target:

- the platform already shares a lot of transactional business logic
- most workflows need strong consistency inside one application
- the current pain is maintainability, not distributed scaling
- Laravel already gives a strong foundation for a modular monolith

### 3.2 Target Backend Structure

Recommended direction:

```text
app/
  Domain/
    Assistant/
      Actions/
      DTOs/
      Enums/
      Policies/
      Services/
      Support/
    Billing/
      Actions/
      DTOs/
      Enums/
      Jobs/
      Notifications/
      Queries/
      Services/
    Campaigns/
      Actions/
      DTOs/
      Enums/
      Queries/
      Services/
    Catalog/
      Actions/
      DTOs/
      Enums/
      Policies/
      Queries/
      Services/
    Customers/
      Actions/
      DTOs/
      Enums/
      Policies/
      Queries/
      Services/
    Reservations/
      Actions/
      DTOs/
      Enums/
      Policies/
      Queries/
      Services/
    Sales/
      Actions/
      DTOs/
      Enums/
      Queries/
      Services/
    Workflow/
      Actions/
      DTOs/
      Enums/
      Policies/
      Queries/
      Services/
  Http/
    Controllers/
      Api/
      Portal/
      Public/
      SuperAdmin/
      Web/
    Requests/
  Models/
  Notifications/
  Policies/
  Providers/
  Support/
```

This does not require moving everything at once. It defines the target. Migration can start by introducing new Actions, Queries, DTOs, and Services under domain folders while keeping existing controllers and models stable.

### 3.3 Responsibility Boundaries

The target boundaries should be:

- Controllers: receive request, authorize, delegate, return response
- Form Requests: validation and normalization
- Actions: focused write use cases
- Queries or read assemblers: heavy listing/detail read logic
- Services: only for broader orchestration across several collaborators
- DTOs: explicit payloads for non-trivial write or read flows
- Enums: statuses, types, modes, channels, transitions
- Policies: centralized authorization
- Jobs: non-blocking side effects
- Notifications: delivery concerns only

### 3.4 Target Frontend Structure

Keep Vue + Inertia, but move toward feature sections instead of page monoliths.

Recommended direction:

```text
resources/js/
  Pages/
    Customers/
      Show/
        Index.vue
        OverviewSection.vue
        ActivitySection.vue
        BillingSection.vue
        LoyaltySection.vue
    Sales/
      Create/
      Show/
    Reservations/
      Index/
      Planning/
    Dashboard/
      Owner/
      Team/
      Client/
  Components/
    DataDisplay/
    Filters/
    Forms/
    Layout/
    Modals/
    Tables/
  Composables/
    useAsyncOptions.js
    usePaginationState.js
    useTenantPaymentMethods.js
```

The goal is not micro-components everywhere. The goal is to split large pages into meaningful business sections with stable public interfaces.

## 4. Recommended Patterns and Best Practices

### 4.1 Strongly Recommended

- Thin controllers
- Form Requests for non-trivial write flows
- Action classes for focused business operations
- Query objects or read assemblers for heavy index/show pages
- DTOs for complex payloads moving across layers
- Enums for statuses, types, channels, and transitions
- Policies for repeated authorization rules
- Jobs for exports, notifications, and heavy post-commit work
- API Resources or dedicated transformers for repeated response shaping
- Vue composables for repeated UI behavior and async loading

### 4.2 Patterns to Avoid

- generic repository layer everywhere
- giant “manager” or “helper” classes
- traits containing major business logic
- abstraction layers that only forward calls
- event/listener overuse for core synchronous business flows
- splitting code so aggressively that tracing the request becomes harder

### 4.3 Naming Principles

Prefer explicit names:

- `CreateSaleAction`
- `UpdateTaskAction`
- `BuildCustomerDetailViewData`
- `DashboardMetricsQuery`
- `ReservationSlotAvailabilityQuery`

Avoid vague names:

- `CommonService`
- `GlobalHelper`
- `DataManager`
- `UtilityService`

## 5. Refactoring and Migration Strategy

### Phase 0: Guardrails

Keep the current baseline as a safety net:

- maintain full test suite green
- keep frontend build green
- add CI enforcement if missing
- add static analysis progressively

No structural refactor should land without preserving that baseline.

### Phase 1: Normalize Write Flows

Target first:

- sales
- tasks
- reservations
- customer write operations
- public request qualification flows

Actions:

- replace inline validation with Form Requests
- extract `store` and `update` business logic into Actions
- keep controllers thin

Expected result:

- clearer write paths
- lower duplication
- better test granularity

### Phase 2: Extract Heavy Read Models

Target first:

- dashboard
- customer detail page
- reservation planning screen
- task/work operational pages

Actions:

- create dedicated Queries or view-data builders
- move aggregation and eager-loading strategy out of controllers
- define smaller payload boundaries

Expected result:

- easier performance tuning
- smaller controllers
- clearer read optimization points

### Phase 3: Split the Largest Services

Target first:

- `AssistantWorkflowService`
- `ReservationAvailabilityService`

Actions:

- split by use-case families, not by arbitrary helper methods
- separate command interpretation from concrete business execution
- isolate slot generation, capacity logic, rescheduling, and booking decisions

Expected result:

- safer change surface
- easier targeted tests
- more predictable business orchestration

### Phase 4: Frontend Decomposition and Payload Reduction

Actions:

- split giant pages into business sections
- move eager option loading to demand-driven fetches
- lazy-load secondary dictionaries or non-critical UI data
- trim oversized payloads from detail pages

Expected result:

- better perceived responsiveness
- easier page maintenance
- lower bundle pressure

### Phase 5: Authorization and Route Cleanup

Actions:

- expand policies where auth rules are repeated
- reduce auth logic hidden in services when policies are more appropriate
- split route surfaces more explicitly by delivery context and business area

Expected result:

- clearer security model
- easier route discovery
- lower permission drift

### Phase 6: Selective Performance Hardening

Actions:

- measure SQL hotspots on dashboard, customer, planning, and reservation views
- cache only stable aggregate boundaries
- keep writes transactional and reads optimized separately
- profile payload size and frontend interaction timing

Expected result:

- improvements based on evidence
- better scale behavior without premature complexity

## 6. Should the Platform Be Rewritten?

No full rewrite is justified now.

Reasons:

- the platform is already functional
- the test and build baseline is green
- the biggest problems are structural and incremental, not existential
- a rewrite would create high delivery risk without solving the domain complexity problem

A partial redesign is justified only in specific high-pain areas:

- assistant orchestration
- reservation availability engine
- the heaviest read models
- the largest UI screens

These should be redesigned incrementally inside the existing application, not rebuilt as a separate platform.

## 7. Final Recommendation

The platform should evolve toward a cleaner domain-oriented modular monolith built on native Laravel patterns.

The priority is not more features. The priority is to make the current product easier to change safely.

The most important next moves are:

- normalize validation with Form Requests
- extract write use cases into Action classes
- move heavy read logic into Queries or read assemblers
- split the largest services by coherent business responsibilities
- reduce large frontend pages and oversized payloads
- strengthen policy coverage and route clarity

This strategy preserves what already works, avoids over-engineering, and creates a realistic path toward a codebase that is easier to maintain, scale, and evolve over time.
