# Tips Feature - User Stories and Specs

## Goal
Add an optional, transparent, no-pressure tip flow for customer payments, with clear breakdowns for client, owner, and team member reporting.

## Scope
- Invoice payments (public signed link + client portal).
- Tip capture at checkout.
- Tip auditability in payment records.
- Tip visibility in customer confirmations.
- Owner/team reporting for tips.

## Principles
- Tip is optional and never pre-selected.
- Pricing is transparent and recalculated live.
- Tip is line-separated from invoice balance logic.
- Final charged amounts are snapshotted at payment time.

## Current Baseline (already implemented)
- `tip_amount` persisted on `payments`.
- Tip supported on manual and Stripe invoice payments.
- Tip displayed in payment details in portal invoice view.
- Stripe metadata carries `payment_amount` and `tip_amount`.

## User Stories

### US-1 - Optional tip toggle
As a customer, I can explicitly choose whether to add a tip before paying.

Acceptance criteria:
- Checkout shows `Add a tip? (optional)`.
- Two choices: `Yes, add a tip` and `No thanks`.
- Default is `No thanks`.
- If `No thanks`, tip inputs are hidden and total is unchanged.

### US-2 - Tip by percentage
As a customer, I can add a tip as a percentage.

Acceptance criteria:
- Percentage mode offers quick chips: `5%`, `10%`, `15%`, `20%`.
- `Other %` allows custom numeric percent.
- Tip amount updates live from base amount.
- Breakdown shows subtotal, tip, and final total before payment.

### US-3 - Tip by fixed amount
As a customer, I can add a fixed tip amount.

Acceptance criteria:
- Fixed mode offers quick chips: `$2`, `$5`, `$10`.
- `Other amount` allows custom value.
- Breakdown updates in real time.

### US-4 - Edit/remove before payment
As a customer, I can modify or remove the tip before paying.

Acceptance criteria:
- Tip controls remain editable until payment submit.
- UI text confirms: `You can edit or remove tip before payment`.

### US-5 - Correct amount computation
As the platform, I compute final charged amount correctly and consistently.

Acceptance criteria:
- `tip_amount` formula:
  - percent mode: `round(base_amount * tip_percent / 100, 2)`
  - fixed mode: `tip_fixed`
- `final_amount = base_amount + taxes + fees + tip_amount`.
- Server validates and recomputes tip from trusted inputs.

### US-6 - Snapshot and audit
As finance/admin, I need immutable payment snapshots.

Acceptance criteria:
- Payment record stores:
  - `amount` (applied to invoice)
  - `tip_amount`
  - `tip_type` (`none|percent|fixed`)
  - `tip_percent` (nullable)
  - `tip_base_amount` (amount used for computation)
  - `charged_total` (amount + tip + fees/taxes snapshot)
- Stripe metadata includes same breakdown values.

### US-7 - Customer confirmation breakdown
As a customer, I can see tip clearly on confirmation/receipt.

Acceptance criteria:
- Confirmation and receipt show separate lines:
  - subtotal
  - taxes/fees
  - tip
  - total paid
- If available, display `Tip assigned to: <team member>`.

### US-8 - Owner tips dashboard
As owner/admin, I can monitor all tips.

Acceptance criteria:
- New page: `Payments > Tips`.
- Columns:
  - datetime
  - reservation/invoice reference
  - customer
  - team member
  - tip mode
  - tip amount
  - total charged
  - payment status
- Filters:
  - period
  - team member
  - service
  - status
- KPI cards:
  - total tips
  - average tip per reservation
  - top 3 members by tips
- CSV export includes tip fields as separate columns.

### US-9 - Team member tips page
As a team member, I can view my own tips.

Acceptance criteria:
- New page: `My Earnings > Tips`.
- Columns:
  - date
  - reservation/invoice reference
  - customer (optional anonymized)
  - tip amount
  - status (`paid|pending|reversed`)
- KPI:
  - current month tips
  - period total
  - average tip per service

### US-10 - Refund and reversal behavior
As finance/admin, tip state follows refund behavior.

Acceptance criteria:
- Full refund marks tip as refunded/reversed.
- Partial refund can prorate tip reversal (configurable rule).
- Status reflected in owner/team tips pages.
- Audit log tracks original and reversed values.

### US-11 - Multi-member assignment
As owner/admin, I control how tips are attributed.

Acceptance criteria:
- Attribution strategy supports:
  - primary assignee
  - split by percentage (e.g. 70/30)
- Stored tip allocations per member.
- Reporting aggregates from allocations.

### US-12 - Anti-abuse limits
As platform operator, I enforce guardrails.

Acceptance criteria:
- Configurable limits:
  - percent max (e.g. 30%)
  - fixed max (e.g. $200)
- Validation errors are explicit and localized.

## Data Model Additions (proposed)

### payments
- `tip_amount decimal(10,2) default 0` (already present)
- `tip_type string nullable` (`none|percent|fixed`)
- `tip_percent decimal(5,2) nullable`
- `tip_base_amount decimal(10,2) nullable`
- `charged_total decimal(10,2) nullable`
- `tip_assignee_user_id bigint nullable` (for single assignee mode)

### payment_tip_allocations (for split mode)
- `id`
- `payment_id`
- `user_id`
- `amount`
- `percent`
- timestamps

## API / Payload Contract (proposed)

Input:
- `amount`
- `tip_mode` (`none|percent|fixed`)
- `tip_percent` (required if `percent`)
- `tip_amount` (required if `fixed`)

Server output:
- `amount`
- `tip_amount`
- `tip_type`
- `tip_percent`
- `charged_total`

## Validation Rules (proposed)
- `amount`: required, numeric, `>= 0.01`, `<= balance_due`
- `tip_mode`: enum (`none|percent|fixed`)
- `tip_percent`: numeric, `>= 0`, `<= tip_percent_max`
- `tip_amount`: numeric, `>= 0`, `<= tip_fixed_max`
- Computed `tip_amount` always rounded to 2 decimals.

## Rollout Plan

### Phase 1 - Checkout UX + trusted server compute
- Add tip toggle and mode selector in checkout UI.
- Compute and display live totals.
- Server computes/stores tip breakdown fields.
- Stripe metadata parity with stored values.

### Phase 2 - Confirmation and reporting
- Add detailed receipt/confirmation lines.
- Build owner tips dashboard with filters and export.
- Build team member tips view.

### Phase 3 - Advanced operations
- Refund/reversal workflows and statuses.
- Multi-member split allocation model.
- Configurable guardrails in settings.

## Open Decisions
- Proration rule for partial refund on tip.
- Split strategy default for multi-member services.
- Whether customer name is masked in team view by default.
- Whether tip limits are global or account-level settings.
