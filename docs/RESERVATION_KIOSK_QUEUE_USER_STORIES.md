# Reservation Kiosk Queue - User Story and Implementation Plan

## Goal
Ajouter un parcours salon clair avec 2 modes:
- walk-in (ticket) pour personnes sur place, y compris invite non connecte
- reservation pour client enregistre

Le but est de garder une operation fluide, eviter les doublons, et conserver une trace fiable dans le back-office.

## Scope
- Kiosk public pour prise de ticket et check-in client sur place
- Espace client ajuste pour privilegier reservation
- Regles anti-doublons reservation/ticket
- Evolutions backend queue/reservation
- Ecrans equipe alignes avec le flux operationnel

## Current Baseline (already in code)
- Queue operationnelle avec statuts et ETA (`reservation_queue_items`)
- Gestion staff de la queue (`/app/reservations`)
- Parcours client connecte pour reservation + ticket
- Ecran queue live interne (`/app/reservations/screen`)

## Product Decisions
- Invite sur place: peut prendre un ticket sans compte
- Client enregistre:
  - parcours principal: reservation
  - si sur place sans reservation: ticket lie a son profil
- Un client ne peut pas avoir reservation active + ticket actif en meme temps
- Si reservation proche existe (fenetre configurable), on propose check-in au lieu d un nouveau ticket

## Roles and Channels

### Public Kiosk
- Accessible en salon (tablette/ecran)
- Actions:
  - `Je suis sur place (Walk-in)`
  - `Je suis deja client`
  - `Suivre mon ticket`

### Client Portal
- Client connecte
- Actions:
  - reserver, replanifier, annuler, review
  - check-in de reservation le jour J
- Le bouton `prendre un ticket` est retire de ce parcours principal

### Staff Back-office
- Vue unifiee queue + reservations
- Actions: check-in, pre-call, call, start, done, skip, no-show
- Priorisation par membre + ETA

## User Stories

### US-KQ-1 - Walk-in invite prend un ticket
As a visitor on site, I can create a walk-in ticket without account.

Acceptance criteria:
- Form kiosk: service, membre optionnel, telephone obligatoire, prenom optionnel
- Ticket cree avec numero, position, ETA
- Ticket visible dans la queue staff

### US-KQ-2 - Client reconnu sur kiosk
As a known client on site, I can identify with phone and continue quickly.

Acceptance criteria:
- Lookup par telephone
- Verification code SMS (si active)
- Si reservation proche: proposer `Check-in`
- Sinon: proposer `Prendre un ticket a mon nom`

### US-KQ-3 - Check-in reservation sans ticket
As a client with reservation today, I can check-in without creating walk-in ticket.

Acceptance criteria:
- Check-in met a jour etat operationnel de la reservation
- Aucun ticket `walk-in` supplementaire cree

### US-KQ-4 - Anti-doublon strict
As the system, I prevent overlapping active intents for same person.

Acceptance criteria:
- Blocage creation ticket si reservation active/proche existe
- Blocage creation reservation immediate si ticket actif existe
- Messages utilisateur explicites et action alternative proposee

### US-KQ-5 - Tracking ticket public
As a visitor, I can track my position and ETA from kiosk/public page.

Acceptance criteria:
- Entree telephone + ticket id/numero
- Retour: statut, position, ETA, horodatage mise a jour

### US-KQ-6 - Notifications queue
As a client/visitor, I receive queue progress notifications.

Acceptance criteria:
- Evenements: pre-call, called, grace expiry
- Canal SMS si telephone present
- Fallback email/in-app pour clients enregistres

### US-KQ-7 - Staff can operate mixed queue safely
As staff, I can process appointments and tickets in one lane model.

Acceptance criteria:
- Position/ETA calculees par lane membre
- Actions staff conservees
- Regles de permission conservees

## State Model

### Queue item (operational)
- `not_arrived`
- `checked_in`
- `pre_called`
- `called`
- `skipped`
- `in_service`
- `done`
- `no_show`
- `cancelled`
- `left`

### Reservation (planning/business)
- `pending`
- `confirmed`
- `rescheduled`
- `completed`
- `no_show`
- `cancelled`

## Data Model Changes (planned)

### reservation_queue_items
Add fields (non-breaking):
- `guest_name` nullable string(120)
- `guest_phone` nullable string(30)
- `guest_phone_normalized` nullable string(30)
- index `(account_id, guest_phone_normalized, status)`

### reservation_check_ins
Keep existing table, add channel usage standardization:
- `channel`: `kiosk_guest`, `kiosk_client`, `client_portal`, `staff`

### reservation_settings
Add/confirm flags:
- `kiosk_enabled` bool
- `kiosk_require_sms_verification` bool
- `queue_duplicate_window_minutes` int (default 120)

## Backend Work Plan

### Phase 1 - Core Rules and Services
- Status: Partiellement livre (backend client + queue service)
- Service central anti-doublon ajoute: `ReservationIntentGuardService`
  - detecte ticket actif pour client (`client_id` / `client_user_id`)
  - detecte reservation active/proche (fenetre configurable, fallback 120 min)
- Integration du guard livree dans:
  - creation ticket client (`ReservationQueueService::createTicket`)
  - creation reservation client (`ClientReservationController::store`)
- Erreurs metier normalisees:
  - `queue` si conflit ticket/reservation cote file
  - `reservation` si conflit ticket actif cote booking
- Tests feature anti-doublon livres:
  - blocage second ticket actif pour meme client
  - blocage ticket si reservation proche active
  - blocage reservation si ticket actif
- Reste a faire en phase 1:
  - brancher le guard pour le futur parcours invite kiosk + check-in kiosk

### Phase 2 - Public Kiosk Endpoints
- Status: Livre (backend + tests feature)
- Routes publiques signees/rate-limited ajoutees:
  - `POST /kiosk/reservations/walk-in/tickets`
  - `POST /kiosk/reservations/clients/lookup`
  - `POST /kiosk/reservations/clients/verify`
  - `POST /kiosk/reservations/check-in`
  - `GET /kiosk/reservations/tickets/track`
- Note de securite:
  - routes protegees par signature + throttle
  - contexte compte passe via query signee `account`
- Controller livre: `PublicKioskReservationController`
- Request validation livree:
  - `KioskWalkInTicketRequest`
  - `KioskClientLookupRequest`
  - `KioskClientVerifyRequest`
  - `KioskCheckInRequest`
  - `KioskTicketTrackRequest`
- Couverture test feature livree:
  - creation ticket invite
  - blocage doublon ticket invite sur meme telephone
  - lookup client avec intention check-in
  - check-in reservation kiosk
  - tracking ticket public
  - lookup + verify SMS (mode verification active)

### Phase 3 - UI Kiosk and Client Portal Adjustments
- Status: Livre (UI + i18n + liens staff/client)
- Nouvelle page kiosk:
  - onglets Walk-in / Deja client / Suivre ticket
  - formulaire ticket invite
  - parcours client connu: lookup, verification optionnelle, check-in ou ticket
- Retrait du `create ticket` comme action principale du portail client
- Ajout d un lien explicite vers le kiosk depuis:
  - vue client (`ClientBook`/`ClientIndex`)
  - ecran queue staff (`Screen`)
- Durcissement:
  - fallback URL kiosk si route non disponible (evite erreur 500)

Deliverables:
- pages Vue kiosk + ajustements `ClientBook`/`ClientIndex` + `Screen`
- i18n FR/EN associee

### Phase 4 - Notifications and Ops Hardening
- Status: Livre (SMS queue + activity log kiosk + logs metier)
- Notifications queue:
  - canal SMS branche via `notification_settings.reservations.sms`
  - applique aux evenements `queue_pre_call`, `queue_called`, `queue_grace_expired`
  - respecte les toggles existants `notify_on_queue_*`
- Journalisation kiosk:
  - `kiosk_ticket_created` sur creation ticket kiosk
  - `kiosk_queue_transition` sur transitions queue via canal kiosk
- Observabilite:
  - logs metier sur endpoints kiosk (lookup, verify, check-in, tracking, creation ticket)
  - logs dispatch notifications queue (compteur par canal + erreurs SMS)

Deliverables:
- envoi notifications robuste (email/in-app/sms selon settings)
- dashboards operationnels inchanges mais enrichis

### Phase 5 - Team Member Operations and Queue Assignment Modes
- Status: Livre (backend + settings + UI staff)
- Mode d attribution de file ajoute (tenant setting):
  - `queue_assignment_mode = per_staff | global_pull`
  - stockage en `reservation_settings`
  - expose dans `resolveSettings` + settings UI
- Calcul position/ETA adapte:
  - `per_staff`: position calculee par lane employe (comportement salon)
  - `global_pull`: position calculee sur une file globale unique
- Action operationnelle staff ajoutee:
  - `POST /app/reservations/queue/call-next`
  - selectionne le prochain element callable selon mode + droits utilisateur
  - assigne automatiquement le ticket au membre dans le mode `global_pull`
- UX staff enrichie:
  - badge d origine `BOOKING` / `WALK-IN`
  - affichage du mode actif de file
  - bouton `Call next` dans la vue queue staff
- Compatibilite:
  - flux existants `check-in/pre-call/call/start/done/skip` conserves
  - aucun changement bloquant sur les tickets/reservations deja en base

## API Contract (planned)

### Public kiosk
- `POST /kiosk/reservations/walk-in/tickets`
- `POST /kiosk/reservations/clients/lookup`
- `POST /kiosk/reservations/clients/verify`
- `POST /kiosk/reservations/check-in`
- `GET /kiosk/reservations/tickets/track`
- Toutes les routes utilisent une URL signee avec `account` en query string.

### Existing client routes (kept)
- `POST /client/reservations/book`
- `POST /client/reservations/waitlist`
- ticket route conservee backend mais usage UI restreint selon decision produit

### Existing staff queue routes (extended)
- `POST /app/reservations/queue/call-next`
- `PATCH /app/reservations/queue/{item}/check-in`
- `PATCH /app/reservations/queue/{item}/pre-call`
- `PATCH /app/reservations/queue/{item}/call`
- `PATCH /app/reservations/queue/{item}/start`
- `PATCH /app/reservations/queue/{item}/done`
- `PATCH /app/reservations/queue/{item}/skip`

## Validation Rules (planned)
- Guest walk-in:
  - phone required, normalized E.164-like
  - service optional
  - member optional
- Client kiosk:
  - verification code required si setting active
- Anti-doublon:
  - one active intent max per person
  - duplicate window configurable

## Test Plan
- Feature tests:
  - guest can create ticket with phone
  - guest blocked if duplicate/active conflict
  - known client with reservation gets check-in path
  - known client without reservation can create linked ticket
  - client cannot have reservation active + ticket active simultaneously
  - staff queue flow still works with mixed item types
- UI tests:
  - kiosk forms and error states
  - client portal no ticket primary action

## Rollout Plan
- Flag by account (`kiosk_enabled`)
- Progressive rollout:
  1. salon pilotes
  2. all salon tenants
  3. other business presets if needed
- Backward compatible migrations

## Risks and Mitigations
- Risk: false duplicate detection
  - Mitigation: normalized phone + configurable time window
- Risk: abuse on public kiosk endpoint
  - Mitigation: throttle + signed URL + captcha option
- Risk: operational confusion staff side
  - Mitigation: explicit labels `appointment` vs `ticket` in queue rows

## Open Decisions
- Niveau de verification SMS obligatoire ou optionnel par tenant : optionnel par tenant
- Duree exacte de la fenetre anti-doublon (implementation courante: 120 min, configurable)
- Politique de fallback si SMS provider indisponible : application notification ou email 
