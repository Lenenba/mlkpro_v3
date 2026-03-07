# Platform Full Transformation Plan

Date: 2026-03-06

## Objective

Build a platform that is:

- maintainable
- cleanly structured
- aligned with pragmatic SOLID principles
- scalable by design
- performant under growth
- fluid for end users
- safe to evolve without regressions

This plan covers all required dimensions:

- architecture
- code quality
- backend design
- frontend fluidity
- data performance
- async processing
- observability
- infrastructure readiness
- load validation
- security and operational quality

## 1. Target State

At the end of this plan, the platform should meet these conditions:

### Code and Architecture

- thin controllers
- Form Requests on all non-trivial write flows
- Actions for focused business operations
- Queries or read assemblers for heavy index/show pages
- fewer oversized services and controllers
- explicit enums for statuses and modes
- clearer policy coverage
- domain-oriented organization without over-engineering

### Performance and Fluidity

- reduced payload size on heavy pages
- lazy loading where data is optional or secondary
- no obvious N+1 queries on core screens
- heavy work moved to queues/jobs
- dashboards and listings optimized with targeted caching
- frontend chunks reduced and more intentional

### Scalability and Reliability

- queue workers for heavy asynchronous work
- measurable response time targets
- measurable query count targets
- measurable error rate targets
- logs, metrics, and alerts in place
- repeatable load testing
- infrastructure ready for horizontal growth

## 2. Non-Negotiable Rules

These rules stay active during the entire transformation:

- preserve behavior first
- never break the green test baseline
- never move to the next major phase with failing tests
- do not rewrite the platform from scratch
- do not add abstractions without clear payoff
- prefer Laravel-native solutions before custom architecture
- optimize based on measured hotspots, not assumptions

## 3. Transformation Strategy

The correct strategy is not one big refactor.

It is:

1. stabilize
2. simplify structure
3. isolate business logic
4. reduce read and UI weight
5. harden performance
6. harden infrastructure
7. validate under load

## 4. Phase Plan

## Phase 0 - Guardrails and Baseline

Goal:
Create a stable transformation baseline.

Work:

- keep `php artisan test` green at all times
- keep `npm run build` green at all times
- add CI enforcement for tests and build if missing
- add static analysis progressively: `Larastan/PHPStan`
- add lint enforcement if needed: `Pint`, `ESLint`
- define baseline metrics for:
  - slowest pages
  - slowest SQL queries
  - bundle size
  - queue latency
  - error rate

Deliverables:

- CI pipeline gates
- baseline architecture audit
- baseline performance snapshot

Exit criteria:

- test suite green
- build green
- baseline metrics collected

## Phase 1 - Normalize All Write Flows

Goal:
Make all write operations predictable, testable, and maintainable.

Priority scope:

- sales
- tasks
- reservations
- customers
- requests
- products and services
- workflow write paths

Work:

- replace heavy inline validation with Form Requests
- extract `store`, `update`, `change status`, `assign`, `convert`, `confirm`, `cancel` flows into Action classes
- introduce DTOs only where payloads are complex
- replace repeated status strings with enums
- centralize business transitions and repeated rules

Deliverables:

- thin controllers
- consistent request validation
- focused write Actions
- lower duplication in `store` and `update`

Exit criteria:

- all major write flows use Form Requests or equivalent structured validation
- controllers no longer hold multi-step business orchestration
- tests still green

## Phase 2 - Extract Heavy Read Models

Goal:
Make the heaviest pages easier to optimize and safer to change.

Priority scope:

- dashboard
- customer detail
- planning
- reservations
- task operational pages
- work operational pages

Work:

- create dedicated Query classes or read assemblers
- move aggregation logic out of controllers
- define smaller response contracts for screens
- add eager loading intentionally instead of ad hoc
- introduce targeted caching only around stable aggregates

Deliverables:

- `DashboardMetricsQuery`
- `BuildCustomerDetailViewData`
- reservation planning read builders
- cleaner controller response assembly

Exit criteria:

- main heavy screens no longer build all queries inside controllers
- read performance hotspots are explicit and measurable

## Phase 3 - Split the Largest Services

Goal:
Remove the biggest long-term maintenance bottlenecks.

Priority scope:

- `AssistantWorkflowService`
- `ReservationAvailabilityService`
- any remaining oversized orchestration service

Work:

- split by business capability, not by random helper extraction
- separate interpretation from execution
- separate validation from orchestration
- separate slot generation, capacity checks, booking logic, and rescheduling logic
- remove unrelated responsibilities from giant services

Deliverables:

- smaller cohesive services
- action families grouped by business purpose
- clearer tests by capability

Exit criteria:

- no giant service remains as the single change point for multiple unrelated flows
- the most critical orchestration paths are isolated and testable

## Phase 4 - Frontend Fluidity and Decomposition

Goal:
Keep the UI responsive as usage and data volume grow.

Priority scope:

- task tables
- customer detail page
- dashboards
- public store
- planning screens
- settings pages with heavy state

Work:

- split giant pages into meaningful business sections
- move option fetching to on-demand loading
- reduce global payload injection
- lazy-load secondary data and secondary translations
- standardize reusable filters, tables, cards, side panels, empty states, and loading states
- reduce unnecessary watchers and computed chains on huge pages

Deliverables:

- smaller page entry files
- reusable business UI sections
- reduced page payload weight
- reduced main JS pressure

Exit criteria:

- heavy pages are decomposed into maintainable sections
- obvious eager-loading UI patterns are removed
- perceived responsiveness improves on major workflows

## Phase 5 - Data and Query Performance

Goal:
Make growth in data volume sustainable.

Work:

- review indexes for high-read and high-write tables
- profile heavy dashboard and customer queries
- remove repeated aggregations where possible
- optimize pagination defaults
- ensure option endpoints return only required fields
- remove broad `get()` usage where pagination or limits are enough
- audit N+1 risks on core screens

Deliverables:

- index review report
- query optimization pass
- reduced query counts on heavy pages

Exit criteria:

- major slow pages have explicit query plans
- query count and latency are controlled on key screens

## Phase 6 - Async, Queue, and Background Processing

Goal:
Keep user-facing requests fast under load.

Work:

- move heavy notifications, exports, imports, PDF batches, media processing, AI processing, and other long-running work to jobs
- separate synchronous business-critical writes from asynchronous side effects
- ensure queue retry strategy is explicit
- monitor queue backlog and failure rate

Deliverables:

- queue-backed heavy operations
- worker strategy
- failure handling and retry policy

Exit criteria:

- expensive non-essential work is out of the HTTP request path
- queue health is measurable

## Phase 7 - Authorization, Security, and Governance

Goal:
Make platform behavior safer and more consistent.

Work:

- expand policy coverage where permissions are repeated
- reduce hidden authorization logic in services
- standardize audit-sensitive operations
- review rate limiting on public endpoints
- review file upload and signed/public access flows
- review tenant isolation boundaries

Deliverables:

- policy coverage map
- public endpoint protection checklist
- tenant-boundary review

Exit criteria:

- permissions are easier to reason about
- public and tenant-sensitive surfaces are explicitly protected

## Phase 8 - Observability and Operations

Goal:
Make performance and production issues visible before they become outages.

Work:

- centralize structured logging
- instrument slow queries and slow requests
- capture job failures and queue backlog
- track p95 and p99 latency on key endpoints
- add uptime and error alerts
- track frontend error reporting if needed

Deliverables:

- logs
- metrics
- alerts
- operational dashboards

Exit criteria:

- production behavior is measurable
- regressions can be detected quickly

## Phase 9 - Load Testing and Capacity Validation

Goal:
Prove the platform can handle the intended usage level.

Work:

- define realistic usage scenarios
- run load tests on:
  - dashboard usage
  - reservation creation
  - sales creation
  - customer detail access
  - public request submission
  - public store flows
- identify saturation points
- tune DB, queues, cache, and frontend based on findings

Deliverables:

- load test scenarios
- capacity report
- remediation list

Exit criteria:

- target traffic scenarios are validated
- bottlenecks are measured, not guessed

## 5. Work Order by Priority

If the objective is to get everything, this is the correct execution order:

1. keep tests and build green
2. normalize write flows
3. extract heavy read models
4. split giant services
5. reduce frontend payload and page complexity
6. optimize queries and indexing
7. move heavy work async
8. expand policy and public-surface hardening
9. add observability
10. run load tests and tune infrastructure

This order matters.

If you optimize infrastructure before fixing the code shape, you scale technical debt.

## 6. Success Metrics

To claim the platform is truly strong, the plan needs objective targets.

Recommended targets:

### Quality

- `100%` tests green on every merge
- static analysis at a clean or controlled level
- reduced average controller/service size in critical areas

### Backend Performance

- p95 response time target for major authenticated pages
- p95 response time target for public flows
- capped query counts on major pages
- no obvious N+1 on core workflows

### Frontend Performance

- reduced main app chunk size
- reduced payload size on dashboard and customer pages
- faster first usable state on planning, customer, and task screens

### Reliability

- low queue failure rate
- low application error rate
- measurable retry/recovery strategy

### Scalability

- validated load scenarios for peak workflows
- ability to scale workers and web nodes independently
- DB and cache behavior measured under stress

## 7. Architecture Principles to Keep

The future architecture should follow these principles:

- Laravel first
- domain-oriented organization
- explicit responsibilities
- composition over inheritance
- low duplication
- small and cohesive write actions
- separate read optimization from write orchestration
- no fake abstractions
- no generic repository layer by default
- no giant helpers

## 8. What "SOLID" Should Mean Here

The goal is not to force textbook SOLID everywhere.

The goal is practical SOLID:

- `S`: one clear responsibility per class where it matters
- `O`: new behavior added through focused extensions, not giant conditionals
- `L`: avoid clever inheritance hierarchies that make substitution unsafe
- `I`: no giant interfaces with unrelated methods
- `D`: depend on explicit collaborators where it improves clarity and testing

Applied correctly, this will improve maintainability.
Applied dogmatically, it will make the system harder to follow.

## 9. What This Plan Does Not Mean

This plan does not mean:

- rewriting the whole platform
- splitting into microservices
- creating repositories for every model
- introducing interfaces everywhere
- extracting tiny components with no meaningful reuse

The target is a strong modular monolith, not architectural theater.

## 10. Final Recommendation

If the objective is to have everything:

- clean architecture
- maintainable Laravel code
- better SOLID alignment
- scalability by design
- fluid user experience
- operational reliability

then this plan is the correct path.

But it only works if executed in sequence and validated with metrics.

The end state will not come from one refactor. It will come from disciplined phases, each one leaving the platform safer, cleaner, and more measurable than before.
