Malikia pro
=======

Malikia pro is a multi-company business management platform for services and products.
It helps teams manage customers, quotes, jobs, tasks, invoices, and client payments.

Highlights
----------
- Multi-company accounts with roles (owner, admin, employee, client)
- Quotes, jobs (works), tasks, invoices, payments
- Client portal with invoice payment (Stripe)
- Stripe + Paddle billing (choose provider in .env)
- Stripe Connect for direct payouts to connected businesses
- AI Assistant to help create quotes/jobs/invoices (optional add-on)

Tech Stack
----------
- Laravel (PHP) + MySQL
- Vite + Vue
- Stripe / Paddle billing

Git Workflow
------------
- `develop` is the only base branch for day-to-day work.
- Feature and maintenance branches must be created from `develop`.
- Pull requests must target `develop`, never `main`.
- `main` is reserved for the repository owner, Jules Roger Sombangnen. Automated agents must never commit, push, merge, or open pull requests against `main`.

See [CONTRIBUTING.md](CONTRIBUTING.md) for the contributor workflow and [AGENTS.md](AGENTS.md) for the mandatory automation rules.

Documentation
-------------
The documentation catalog is maintained in [docs/00_INDEX.md](docs/00_INDEX.md). It lists the newest documents first and separates work in progress, planned work, completed work, active references, archives, and documents still requiring classification.

Improvement Program
-------------------
The complete Phase 0–4 roadmap, completed-work recap, current status, blockers, and next actions are maintained in the [global improvement tracker](docs/audits/mlkpro-benchmark-2026-07-16/execution/SUIVI_GLOBAL.md). The [execution cockpit](docs/audits/mlkpro-benchmark-2026-07-16/execution/README.md) and [validation log](docs/audits/mlkpro-benchmark-2026-07-16/execution/VALIDATION_LOG.md) provide the detailed controls and evidence.

Quick Start (Local)
------------------
Requirements:
- PHP 8.2+
- Composer
- Node.js 18+
- MySQL

Install:
1) composer install
2) npm install
3) copy .env.example -> .env
4) php artisan key:generate
5) update DB_*, APP_URL and mail settings in .env
6) php artisan migrate --seed
7) npm run dev
8) php artisan serve

Environment Basics
------------------
Billing provider:
- BILLING_PROVIDER=stripe or paddle

Stripe (plans):
- STRIPE_ENABLED=true
- STRIPE_PRICE_STARTER, STRIPE_PRICE_GROWTH, STRIPE_PRICE_SCALE

Paddle (plans):
- PADDLE_SANDBOX=true|false
- PADDLE_PRICE_STARTER, PADDLE_PRICE_GROWTH, PADDLE_PRICE_SCALE

Stripe Connect:
- STRIPE_CONNECT_ENABLED=true
- STRIPE_CONNECT_FEE_PERCENT=1.5

AI Assistant
------------
The assistant can be included in a plan or enabled as an add-on.

Two add-on modes:
1) Usage-based (metered):
   - STRIPE_AI_USAGE_PRICE=price_xxx
   - STRIPE_AI_USAGE_UNIT=requests|tokens
   - STRIPE_AI_USAGE_UNIT_SIZE=1

2) Credit packs (one-time):
   - STRIPE_AI_CREDIT_PRICE=price_xxx   (must be a one-time price)
   - STRIPE_AI_CREDIT_PACK=100          (credits per pack)

If STRIPE_AI_CREDIT_PRICE is set, credits mode is used.
Make sure Stripe webhooks are configured so credits are added after payment.

Webhooks
--------
- Stripe: /api/stripe/webhook
- Paddle: /{CASHIER_PATH}/webhook (set CASHIER_PATH in .env)

Common Commands
---------------
- php artisan migrate
- php artisan db:seed
- php artisan config:clear
- npm run dev
- npm run build

Notes
-----
- Use .env for all secrets (never commit them).
- For Stripe credits, use a one-time price (not recurring).
- For Stripe usage, use a metered price.
