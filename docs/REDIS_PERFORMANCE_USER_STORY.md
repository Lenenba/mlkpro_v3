# Redis Performance Layer - User Story

## Goal
Introduce Redis as an infrastructure layer to improve application responsiveness, reduce avoidable database load, and make queued workloads more reliable under concurrency.

This initiative should focus first on infrastructure concerns already present in the codebase:
- cache
- queue
- lightweight locks / cooldown keys
- optional session storage if traffic patterns justify it

## Non-goals
- rewrite business modules around Redis data structures
- use Redis as the source of truth for core business data
- assume Redis alone fixes slow SQL, missing indexes, or N+1 queries
- migrate every subsystem at once without a safe rollout path

## Current Baseline In This Repo
- Cache defaults to `database`.
- Queue defaults to `database`.
- Session storage defaults to `database`.
- The application already uses Laravel cache in performance-sensitive areas such as dashboards, assistant context loading, verification flows, and command cooldown locks.
- The application already dispatches many queued jobs and queued notifications.
- Redis configuration already exists in Laravel config and the PHP Redis extension is available in the runtime.

## Product Vision
As the platform grows, infrastructure-level reads and writes that do not need durable relational storage should stop competing with business data queries in MySQL.

Redis should become the fast-access layer for transient application state while MySQL remains the source of truth for business records.

## Primary User Story

### US-REDIS-001 - Faster application infrastructure with Redis
As a platform owner, I can enable Redis for cache and asynchronous infrastructure workloads so the application remains fast and stable when dashboard traffic, automation, and background jobs increase.

Acceptance criteria:
- The application supports Redis as a first-class cache backend.
- Existing cache keys continue to work without functional regression.
- The queue can run on Redis for jobs and notifications.
- Lock and cooldown keys use the Redis-backed cache store.
- The rollout can be enabled by configuration without deep business-code rewrites.

## Supporting User Stories

### US-REDIS-002 - Dashboard and assistant cache offload
As a user, I want repeated dashboard and assistant loads to reuse fast cached data so pages that aggregate many metrics feel more responsive.

Acceptance criteria:
- Expensive dashboard payloads can be read from Redis cache.
- Assistant context payloads can be read from Redis cache.
- Cache TTLs remain explicit and unchanged unless intentionally tuned.
- Cache invalidation behavior remains understandable and testable.

### US-REDIS-003 - Queue throughput improvement
As a platform operator, I want queued jobs to be processed from Redis so background processing scales better than the current database queue.

Acceptance criteria:
- The queue backend can be switched from `database` to `redis`.
- Existing jobs and notifications continue to run without payload regressions.
- Failed jobs remain observable through the existing Laravel failure handling.
- Worker commands and deployment docs are updated for the Redis queue path.

### US-REDIS-004 - Safer transient locking and throttling
As the system, I want cooldown, rate-limit, and lock-like keys to live in Redis so transient coordination does not add unnecessary database writes.

Acceptance criteria:
- Cache-backed locks and cooldown keys function with Redis.
- TTL-based keys expire automatically without manual cleanup tables.
- No business-critical permanent record depends only on Redis.

### US-REDIS-005 - Optional session migration with clear justification
As a platform owner, I want sessions to move to Redis only if real traffic or scaling needs justify it so we avoid unnecessary operational complexity.

Acceptance criteria:
- Session migration is treated as optional, not mandatory for phase 1.
- Session behavior is validated for login, logout, remember-me, and multi-tab usage before rollout.
- The rollout can stay on database sessions if Redis sessions provide no measurable benefit yet.

## Product Decisions

### Decision 1 - Start with infrastructure, not domain logic
- Prefer configuration-driven adoption before adding any custom Redis-specific module.
- Reuse Laravel cache and queue abstractions first.

### Decision 2 - Cache first, queue second, sessions third
- The safest and highest-value first move is cache migration.
- Queue migration is next because the codebase already uses many queued jobs.
- Session migration should happen only after confirming operational need.

### Decision 3 - Keep MySQL as source of truth
- Quotes, invoices, sales, customers, tasks, campaigns, and payments stay in relational storage.
- Redis stores transient, recomputable, or coordination-oriented data.

### Decision 4 - Roll out behind environment configuration
- Switching should primarily happen through `.env`.
- Production rollout must support quick rollback to the previous backend if needed.

## Suggested Technical Scope

### Phase 1 - Redis cache adoption
- Set `CACHE_STORE=redis` in target environments.
- Validate existing cached flows:
  - dashboards
  - assistant context
  - kiosk verification and challenge flows
  - observability cache store
  - command cooldown / lock keys
- Keep current TTL values initially.

Definition of done:
- Cache reads and writes use Redis successfully.
- No user-facing regression is observed on cached pages.
- Cache clear and config clear procedures are documented.

### Phase 2 - Redis queue adoption
- Set `QUEUE_CONNECTION=redis`.
- Update local/dev and deployment worker commands to use the Redis queue path.
- Prefer `queue:work` for worker execution instead of long-running `queue:listen` where appropriate.

Definition of done:
- Existing jobs and notifications are consumed correctly from Redis.
- Queue latency is reduced or at minimum not worse than the database queue.
- Failed job visibility remains intact.

### Phase 3 - Operational hardening
- Document Redis connection settings and required environment variables.
- Define health checks or at least basic operational verification steps.
- Confirm memory, TTL, and key prefix strategy.

Definition of done:
- Deployment documentation exists.
- Key prefixing avoids collisions across environments.
- Basic failure and rollback procedures are written down.

### Phase 4 - Optional session migration
- Evaluate whether `SESSION_DRIVER=redis` is worth the tradeoff.
- Validate auth flows, session lifetime behavior, and logout consistency.

Definition of done:
- A decision is recorded to keep database sessions or move to Redis sessions.
- If enabled, authentication flows are regression-tested.

## Environment and Rollout Requirements
- Redis must be available in each target environment.
- Connection details must be configurable via `.env`.
- Key prefixes must separate environments cleanly.
- Rollout should begin with a non-critical environment first.

## Risks
- Redis outage can impact cache and queue availability if no fallback plan exists.
- Session migration can create login instability if tested too late.
- A queue backend change without worker/process updates can produce false success in code but real delivery lag in operations.
- Redis can hide inefficient queries on warm cache while first-load performance remains poor.

## Success Metrics
- Lower average response time on repeated dashboard and assistant loads.
- Reduced database load from cache and queue tables.
- Lower queue wait time during campaign, notification, or automation bursts.
- Fewer transient coordination writes in relational tables.

## Recommended Future Deliverables
- short implementation note for local setup and production env vars
- rollout checklist
- before/after performance snapshot for dashboards and queue latency
- regression test coverage for the most critical cached and queued flows
