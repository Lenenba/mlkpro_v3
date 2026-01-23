MLK Pro
=======

MLK Pro is a multi-company business management platform for services and products.
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
