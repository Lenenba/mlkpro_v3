# Social login: confirm before creating an account — design

Date: 2026-06-10
Status: Approved (pending spec review)

## Problem

On the login page, clicking a social provider (Google, Facebook, ...) authenticates
the visitor and, if the resolved email has **no existing account**, the app
**creates an owner account and starts onboarding immediately**
([SocialAuthAccountService::resolve()](../../../app/Services/Auth/SocialAuthAccountService.php)).

This is unwanted: a user who picked the wrong provider, or whose social email
differs from the one they expected, ends up with an unintended new account. From
the **login** context the app should instead ask the user to confirm account
creation ("No account exists for this email — create one?").

## Goals

- From the **login** context only, do **not** auto-create an account when no
  account matches the verified social profile. Show a confirmation dialog first.
- On confirmation, create the account directly from the already-verified profile
  (no second OAuth round-trip) and continue to onboarding.
- Keep `register` and `onboarding` contexts unchanged (direct creation is intended).
- Keep the existing "email matches an existing account, link the new provider and
  sign in" behavior unchanged (the provider already verified the email).

## Non-goals

- No change to register/onboarding creation flows.
- No confirmation step for the auto-link-by-email case.
- No new provider support; no change to the OAuth authorization/redirect step.

## Chosen approach (Option A)

`resolve()` gains a `createIfMissing` flag. When the controller is in the `login`
context it passes `false`. If resolution reaches the "no account at all" branch
with `createIfMissing === false`, it throws a dedicated exception carrying the
verified profile and tokens. The controller catches it, stashes the candidate in
the session (one-time token, short TTL) and redirects to login with a prompt prop.
A new confirm endpoint replays creation from the stashed candidate.

Rejected alternatives:
- **B** — split `resolve()` into `find()` + `create()`: cleaner conceptually but
  refactors more code and other call sites.
- **C** — return a status array: pollutes the return contract for all callers.

## Components & responsibilities

| Component | Change |
|---|---|
| `SocialAuthAccountService::resolve()` | New param `bool $createIfMissing = true`. When the create branch is reached and the flag is `false`, throw `SocialAccountConfirmationRequiredException` carrying `provider`, `profile`, `tokens`. All other behavior (existing social account, email-match auto-link) unchanged. |
| `App\Exceptions\Auth\SocialAccountConfirmationRequiredException` (new) | Plain exception (does **not** extend `ValidationException`). Holds the verified `provider`, `profile`, `tokens`. |
| `SocialAuthController::callback()` | Pass `createIfMissing: $source !== 'login'`. Catch the new exception → build a candidate, stash it in the session, redirect to `login`. |
| `SocialAuthController::confirmCreate()` (new) | Route `POST /auth/social/{provider}/confirm`. Validate the one-time token + TTL against the session candidate; replay `resolve(..., createIfMissing: true)` with the stashed profile/tokens; `Auth::login`; `WebLoginResponseService::respond`; clear the stash. |
| `AuthenticatedSessionController::create()` | Read the session candidate and expose a `socialCreatePrompt` prop with **safe fields only**: `provider`, `providerLabel`, masked email, `token`, `confirmUrl`. |
| `resources/js/Pages/Auth/Login.vue` + dialog | When `socialCreatePrompt` is present, show a modal: "No account is associated with `j***@gmail.com` via Google. Create an account?" with **[Create my account]** (POST confirm) and **[Cancel]** (client dismiss). |
| i18n fr/en/es | Dialog strings. |

## Data flow

```
Login → click Google (source=login) → OAuth → callback()
  └─ resolve(createIfMissing: false)
       ├─ social account found            → normal login
       ├─ email matches existing account  → auto-link provider + login   (unchanged)
       └─ no account at all → throw SocialAccountConfirmationRequiredException
            └─ stash session 'social_auth.create_candidate' =
                 { token, provider, profile, tokens, source, plan, billing_period, expires_at(now+10min) }
            └─ redirect to login → create() exposes socialCreatePrompt → DIALOG
                 ├─ Cancel  → client dismiss (stash expires on its own)
                 └─ Create  → POST /auth/social/{provider}/confirm { token }
                                → validate token + TTL
                                → resolve(createIfMissing: true) with stashed profile/tokens
                                → Auth::login → onboarding
                                → clear stash
```

## Session candidate shape

Stored under `social_auth.create_candidate`:

```
{
  token: string (random 64, opaque, one-time),
  provider: string,
  profile: array (verified provider profile),
  tokens: array (OAuth tokens),
  source: 'login',
  plan: ?string,
  billing_period: ?string,
  expires_at: ISO-8601 (now + 10 minutes)
}
```

Only `provider`, `providerLabel`, masked email and `token` are ever sent to the
frontend. OAuth tokens never leave the server.

## Security & error handling

- Candidate stored **server-side** (session), TTL **10 minutes**, **one-time**
  (cleared on successful confirm).
- Confirm endpoint is a web route (CSRF protected). If the token is missing, does
  not match the session candidate, the provider mismatches, or it is expired:
  redirect to login with an error flash and **create nothing**.
- Email is masked in the prompt (`j***@gmail.com`).
- `register` / `onboarding` paths are untouched: direct creation as today.

## Tests (feature)

- login + unknown email → **no User created**, redirect to login, prompt prop
  present and candidate stashed in session.
- confirm with a valid token → User created + authenticated + redirected to
  onboarding; candidate cleared.
- confirm with invalid/expired token → rejected, no User created.
- register / onboarding + unknown email → direct creation (non-regression).
- login + email matching an existing account → auto-link + login (non-regression).

## Out-of-scope follow-ups

- Optional: also confirm before auto-linking a new provider to an existing account.
- Optional: rate-limit the confirm endpoint.
