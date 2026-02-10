# Business Types - User Stories (MLK Pro Context)

## Goal
Adapter le module reservations aux types d entreprise (salon, restaurant, etc.)
en partant de ce qui existe deja dans MLK Pro:
- planning equipe
- slots et disponibilites
- pages client/membre/owner
- notifications, reviews, paiements et tips

## Existing Baseline (Already Implemented)

### Planning and availability
- Planning module actif (`/planning`) pour les shifts equipe.
- Reservation settings actifs (`/settings/reservations`) avec:
  - buffer, slot interval, min notice, max advance
  - weekly availabilities
  - exceptions (open/closed)
  - client cancel/reschedule rules
  - reservation notifications settings

### Reservation operations
- Backoffice reservations page (`/app/reservations`) pour owner et membres autorises.
- Client booking page (`/client/reservations/book`) pour chercher des slots.
- Client reservations page (`/client/reservations`) pour suivre, annuler, replanifier, noter.
- Slot engine deja en place avec:
  - anti double-booking
  - respect disponibilites + exceptions
  - gestion status (`pending`, `confirmed`, `rescheduled`, `completed`, `no_show`, `cancelled`)

### Payment and tips linkage
- Paiement facture + tip deja en place.
- Dashboard tips owner (`/payments/tips`) et membre (`/my-earnings/tips`).
- Rules tip avancees deja supportees (allocation/split, reversal, guardrails).

## Product Principle (Adaptation)
- Ne pas recreer un nouveau module reservations.
- Etendre l existant par presets metier + ressources + UX role-based.
- Garder compatibilite totale avec planning, facturation et tips.

## Target Business Types (Phase 1)
- `salon`
- `restaurant`
- `service_general` (fallback)

## Pages and Role Scope to Cover

### Owner/Admin
- `/settings/reservations`: regles metier, capacite, depots/no-show, notifications.
- `/planning`: vision charge equipe + conflits de capacite.
- `/app/reservations`: vue globale, filtres, actions statut, pilotage operationnel.
- `/payments/tips`: suivi tips et performance service/equipe.

### Team Member
- `/app/reservations` (scope "mine"): gestion slots assignes, confirmations, no-show, completion.
- `/planning`: visibilite de son planning et de ses conflits.
- `/my-earnings/tips`: suivi des tips personnels.

### Client
- `/client/reservations/book`: choix service, recherche slots, booking.
- `/client/reservations`: suivi reservation, replanification, annulation, avis.
- `/portal/invoices/{invoice}`: paiement (complet/partiel), tip optionnel, recap clair.

## User Stories (Adapted to Existing Module)

### US-BT-1 - Business type preset on onboarding
As an owner, I can choose `salon` or `restaurant` so reservation defaults are preconfigured.

Acceptance criteria:
- `users.company_sector` supports normalized values `salon`, `restaurant`, `service_general`.
- Existing tenants are not modified automatically.
- Presets auto-fill reservation settings but remain editable in `/settings/reservations`.

### US-BT-2 - Owner controls business rules from existing settings page
As an owner, I can manage business-specific booking rules from the current reservation settings page.

Acceptance criteria:
- `/settings/reservations` adds optional fields (no regression for current fields):
  - business preset
  - late release minutes
  - waitlist enabled
  - deposit/no-show policy toggles
- Current fields (buffer, notice, advance, cutoff, weekly, exceptions) keep working as-is.

### US-BT-3 - Planning + reservations coherence
As an owner or member, I need slots to remain coherent with planning and availability.

Acceptance criteria:
- Slot generation continues to respect weekly availabilities and exceptions.
- Any new capacity/resource rule is additive and does not bypass current conflict checks.
- Planning page can expose reservation load indicators (busy slots, over-capacity risk).

### US-BT-4 - Client booking flow remains simple, with smarter slots
As a client, I can still book from the existing booking page but with more realistic availability.

Acceptance criteria:
- `/client/reservations/book` keeps current journey (service + slot + contact + notes).
- For salon: optional constraints by resource type (chair/wash/cabin).
- For restaurant: optional `party_size` to filter compatible capacity.
- If no slot: propose waitlist action (if enabled).

### US-BT-5 - Member day-to-day reservation operations
As a team member, I can manage my assigned reservations without seeing unrelated noise.

Acceptance criteria:
- `/app/reservations` default scope stays "mine" for members.
- Members can update status on assigned reservations only.
- New actions (check-in/walk-in/no-show finalization) are permission-based and optional.

### US-BT-6 - Owner global operations and dispatch
As an owner/admin, I can dispatch and optimize capacity from the existing reservations board.

Acceptance criteria:
- `/app/reservations` includes filters for business-specific signals:
  - capacity risk
  - waitlist demand
  - no-show risk
- Owner can override assignment and resolve slot conflicts.

### US-BT-7 - Client self-service after booking
As a client, I can manage my reservation from existing pages with clear modification windows.

Acceptance criteria:
- `/client/reservations` keeps cancel/reschedule/review behavior already implemented.
- Cutoff and policy messaging stays explicit and localized.
- For waitlist-enabled businesses, client can confirm released slots from same area.

### US-BT-8 - Deposit/no-show extension linked to payment
As an owner, I can enforce deposit/no-show policies for selected booking contexts.

Acceptance criteria:
- Deposit requirement can be attached to service/condition.
- Reservation status can reflect deposit state (requested, secured, waived).
- Payment data stays compatible with existing invoice and tip flows.

### US-BT-9 - Tips and reservation attribution continuity
As finance/admin, I need consistent tip attribution even with advanced reservation assignment.

Acceptance criteria:
- Existing tip allocation logic stays unchanged by default.
- If reservation has split execution, tip allocation can follow configured strategy.
- Owner/member tips pages keep net/gross/reversed visibility.

### US-BT-10 - Sector-value analytics by role
As an owner, I can measure added value per business type without losing current dashboards.

Acceptance criteria:
- Shared KPIs: occupancy, no-show, reschedule rate, avg ticket, tip rate.
- Salon KPIs: stylist utilization, resource utilization.
- Restaurant KPIs: table turnover, party-size mix, zone load.
- Member-facing KPIs stay focused on personal workload and earnings.

### US-BT-11 - Walk-in ticket intake from existing booking stack
As a client, I can join a walk-in queue without creating a fixed-time reservation.

Acceptance criteria:
- Client can create a `ticket` entry from kiosk/qr/mobile flow.
- Ticket captures service, optional preferred member, and estimated duration.
- Ticket receives a queue number and enters `waiting` state.
- Feature is optional per business preset (`salon` first, then `restaurant`).

### US-BT-12 - Unified queue state for appointments and walk-ins
As staff, I can see one operational queue that mixes fixed appointments and tickets.

Acceptance criteria:
- Queue supports both `appointment` and `ticket` item types.
- Presence/status model includes:
  - `not_arrived`, `checked_in`, `pre_called`, `called`, `skipped`, `in_service`, `done`, `no_show`, `cancelled`, `left`
- Reservation rows keep existing status lifecycle; queue states are additive and non-breaking.
- Members only operate queue items they are authorized to handle.

### US-BT-13 - Smart dispatch that protects appointments
As an owner/member, I need walk-ins inserted without breaking booked appointment times.

Acceptance criteria:
- Dispatch logic always protects next fixed appointment window.
- A walk-in is callable only if `estimated_duration + buffer <= time_before_next_appointment`.
- If not fit, item is deferred, rerouted to another eligible member, or proposed as next available reservation.
- Dispatch mode is configurable (`fifo`, `fifo_with_appointment_priority`, `skill_based`).

### US-BT-14 - Live client queue tracking and no-show control
As a client, I can track my queue progress and receive clear call notifications.

Acceptance criteria:
- Client view shows queue position, ETA range, and live status.
- Pre-call notification triggers when client is near turn (configurable threshold).
- Call notification includes grace timer.
- If grace timer expires, item moves to `skipped` or `no_show` based on policy.

### US-BT-15 - Role-based queue operations pages
As owner/member/client, I need queue actions on pages aligned with current module layout.

Acceptance criteria:
- Owner/Admin (`/app/reservations` + optional screen mode): global queue, dispatch controls, fairness mode, congestion indicators.
- Team member (`/app/reservations` scope mine): `next`, `skip`, `start service`, `done`, with permission checks.
- Client (`/client/reservations`): ticket card, position/ETA, `still here`, `cancel/leave` actions.
- Existing planning and reservation pages remain source of truth for future fixed slots.

## Value Added (Pragmatic, Monetizable)

### Cross-sector
- Reduction no-show via deposits + reminders + waitlist recovery.
- Better capacity utilization via smarter slot filtering.
- Better basket via add-ons + tips at payment.
- Better team productivity via planning/reservation coherence.
- Walk-in monetization with controlled queue flow and lower idle time.

### Salon-specific
- Resource-aware chaining (chair -> wash -> finish).
- Rebook from last visit in one click.
- Preference-aware appointment notes for premium repeat experience.

### Restaurant-specific
- Party-size aware sloting and table assignment.
- Walk-in + check-in + late-release automation.
- Service window optimization (lunch/dinner) to improve turnover.

## Data Model Additions (Incremental, Non-breaking)

### Keep current tables as source of truth
- `reservations`
- `reservation_settings`
- `weekly_availabilities`
- `availability_exceptions`

### Optional additions
- `reservation_settings.business_preset` (`salon|restaurant|service_general`)
- `reservation_settings.late_release_minutes` (nullable int)
- `reservation_settings.waitlist_enabled` (bool default false)
- `reservation_resources` (new)
- `reservation_resource_allocations` (new)
- `reservation_waitlists` (new)
- `reservation_queue_items` (new, optional, unified appointment/ticket operational queue)
- `reservation_check_ins` (new, optional, arrival timestamps and grace deadlines)
- `service_stations` (new, optional, station/chair/table assignment for call display)

## API / Page Impact (Incremental)
- Keep existing endpoints untouched for baseline behavior.
- Extend `GET /app/reservations/slots` and `GET /client/reservations/slots` with optional:
  - `party_size`
  - `resource_filters`
- Add waitlist endpoints only when feature enabled.
- Add queue endpoints only when queue mode enabled:
  - `POST /client/reservations/tickets`
  - `PATCH /client/reservations/tickets/{ticket}/cancel`
  - `PATCH /app/reservations/queue/{item}/check-in`
  - `PATCH /app/reservations/queue/{item}/call`
  - `PATCH /app/reservations/queue/{item}/start`
  - `PATCH /app/reservations/queue/{item}/done`
  - `PATCH /app/reservations/queue/{item}/skip`

## Rollout Plan (Based on Existing Work)

### Phase 1 - Presets + UX alignment
- Add sector values and preset mapping.
- Expose new preset fields in `/settings/reservations`.
- Keep all current flows unchanged if preset not configured.

### Phase 2 - Capacity and waitlist
- Introduce resources and capacity checks.
- Add waitlist flows to client and owner/member operations.

#### Phase 2 - Implementation snapshot
- Added `reservation_resources` and `reservation_resource_allocations` to model optional capacity constraints.
- Added `reservation_waitlists` for client demand capture when no slot is available.
- Extended slot APIs with optional `party_size` and `resource_filters`.
- Added client waitlist actions:
  - create (`POST /client/reservations/waitlist`)
  - cancel (`PATCH /client/reservations/waitlist/{waitlist}/cancel`)
- Added owner/member waitlist operation:
  - update status (`PATCH /app/reservations/waitlist/{waitlist}/status`)
- Extended reservation settings page with resource configuration for owner/admin.

### Phase 3 - Payments and analytics depth
- Link deposit/no-show outcomes to reservation lifecycle.
- Deliver sector KPIs and role-specific performance cards.

#### Phase 3 - Implementation snapshot
- Added reservation payment-policy fields in settings:
  - `deposit_required`, `deposit_amount`
  - `no_show_fee_enabled`, `no_show_fee_amount`
- Booking and rescheduling now snapshot payment policy inside reservation metadata:
  - `metadata.payment_policy`
  - `metadata.payment_state`
- Reservation status transitions now update payment outcomes in metadata:
  - `no_show` can mark fee as `charge_required`
  - `cancelled` can move deposit to `refundable`
  - `completed` can mark no-show fee as `waived`
- Owner/member reservation board now exposes Phase 3 performance cards over a rolling window:
  - occupancy rate, no-show rate, reschedule/completion rate, avg service value, tip rate
  - salon extra: resource reservation rate
  - restaurant extra: table turnover and average party size
- Reservation settings UI now supports policy configuration directly in `/settings/reservations`.

### Phase 4 - Hybrid queue (walk-ins + appointments)
- Introduce optional queue mode by business preset (start with salon).
- Add ticket intake and check-in flow for walk-ins.
- Add unified queue board in `/app/reservations` for owner/member operations.
- Add client queue tracking in `/client/reservations` (position, ETA, call state).
- Implement smart dispatch rules that preserve upcoming appointments.
- Add notification templates for pre-call, call, and grace-expired events.

#### Phase 4 - Implementation snapshot
- Added reservation queue persistence:
  - `reservation_queue_items`
  - `reservation_check_ins`
- Added queue settings at account level:
  - `queue_mode_enabled`
  - `queue_dispatch_mode`
  - `queue_grace_minutes`
  - `queue_pre_call_threshold`
  - `queue_no_show_on_grace_expiry`
- Added queue service layer for:
  - appointment-to-queue sync (non-breaking with reservation lifecycle)
  - ticket creation for walk-ins
  - queue transitions (`check-in`, `pre-call`, `call`, `start`, `done`, `skip`, `still_here`, client leave)
  - queue position + ETA refresh and grace-expiry handling
- Added staff endpoints:
  - `PATCH /app/reservations/queue/{item}/check-in`
  - `PATCH /app/reservations/queue/{item}/pre-call`
  - `PATCH /app/reservations/queue/{item}/call`
  - `PATCH /app/reservations/queue/{item}/start`
  - `PATCH /app/reservations/queue/{item}/done`
  - `PATCH /app/reservations/queue/{item}/skip`
- Added client endpoints:
  - `POST /client/reservations/tickets`
  - `PATCH /client/reservations/tickets/{ticket}/cancel`
  - `PATCH /client/reservations/tickets/{ticket}/still-here`
- Extended UI:
  - Owner/member queue board in `/app/reservations`
  - Client queue ticket creation + tracking in `/client/reservations/book`
  - Client queue tracking and actions in `/client/reservations`

### Phase 5 - Advanced notifications + live operations screen
- Extend reservation notification settings with queue-specific events.
- Notify client/internal users on pre-call, call, and grace-expiry transitions.
- Add a dedicated live screen for salon/restaurant operations.
- Keep role boundaries: owner/admin global, members scoped by permissions.

#### Phase 5 - Implementation snapshot
- Added queue notification toggles in reservation notification preferences:
  - `notify_on_queue_pre_call`
  - `notify_on_queue_called`
  - `notify_on_queue_grace_expired`
- Queue transitions now trigger notification events:
  - `pre_call` -> `queue_pre_call`
  - `call` -> `queue_called`
  - called-item grace expiry -> `queue_grace_expired`
- Added live operations screen endpoints:
  - `GET /app/reservations/screen`
  - `GET /app/reservations/screen/data`
- Added live queue page:
  - `Reservation/Screen` (auto-refresh, now-serving card, up-next card, waiting list)
  - optional client-name anonymization toggle for display mode
- Added owner/member board shortcut to open live screen in a separate tab.
- Added guided QA runbook:
  - `docs/RESERVATION_PHASE5_QA.md`

## Open Decisions
- Default late-release value for restaurants.
- Deposit policy defaults per sector.
- Waitlist notification channels (email only vs email + sms).
- Packaging limits (what is Pro vs Premium at launch).
- Queue fairness default mode (`fifo_with_appointment_priority` recommended).
- Grace period default for called items (example: 5 minutes).
- Auto-reroute policy when walk-in duration does not fit before next appointment.
