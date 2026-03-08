# Final Review Phase 2 - Manual Select Contract Audit (2026-03-07)

## Objective
Reduce the remaining risk of schema/code drift on critical manual `select(...)` contracts.

This phase targets the exact failure family already observed in runtime:
- a page can pass feature tests but still break if a manually selected column no longer exists
- these issues are most likely on explicit read builders and shared payload shaping

## Delivered

### Shared select contracts
- `app/Queries/Customers/CustomerReadSelects.php`
- `app/Support/Database/UserSelects.php`

### Refactored consumers
- `app/Queries/Customers/BuildCustomerDetailViewData.php`
- `app/Http/Controllers/CustomerController.php`
- `app/Http/Middleware/HandleInertiaRequests.php`
- `app/Services/Assistant/AssistantEntityResolver.php`
- `app/Http/Controllers/Portal/PortalProductOrderController.php`

### Executable audit
- `app/Support/SchemaAudit/ManualSelectContractAudit.php`
- `routes/console.php`
- `tests/Feature/ManualSelectContractAuditTest.php`

## What is audited
The audit verifies curated, high-risk select contracts against the live schema for:
- customer detail quotes
- customer detail works
- customer detail requests
- customer detail invoices
- customer detail tasks
- customer detail upcoming jobs
- customer detail payments
- customer detail activity logs
- customer detail loyalty ledger entries
- customer detail sales summary rows
- customer option customer payloads
- customer option property payloads
- shared authenticated user payload contracts

Current audited contract count:
- `19`

## Command
- `php artisan schema:audit-selects`
- `php artisan schema:audit-selects --json`

Example success payload:
- `ok: true`
- `checked: 19`
- `failures: []`

## Why this matters
This phase does not try to parse every query in the entire codebase.
It focuses on the most failure-prone explicit selects that shape major pages and shared app state.

That is the correct tradeoff here:
- meaningful risk reduction
- low implementation risk
- no fake confidence from a brittle static parser

## Verified state
Local verification completed on 2026-03-07:
- `php artisan schema:audit-selects --json` : OK
- `php artisan test --filter=ManualSelectContractAuditTest` : OK
- full quality chain rerun after this phase

## Limits
This does not eliminate all query drift risk.

Still outside this guard:
- raw expressions and aggregate projections
- non-curated manual selects elsewhere in the codebase
- browser-only failures unrelated to schema drift
- production-only load or data-shape issues

## Next logical step
Continue expanding confidence where it pays off most:
- extend browser E2E to reservation and quote/work/invoice flows
- enlarge the curated select audit to the next highest-risk read builders
- run staging load scenarios using the phase 8 and phase 9 tooling
