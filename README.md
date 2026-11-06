# MediStock Cloud — Pharmacy Management SaaS

A complete, commercial, **multi-tenant Pharmacy Management SaaS** built with
**Core PHP 8 + MySQL** (PDO, no framework), **HTML5/CSS3/JavaScript**,
**Bootstrap 5** and **Chart.js**.

Independent pharmacies, medical stores, pharmacy chains and distributors
register their own tenant account, subscribe to a plan, invite employees and
run batch-level inventory, FEFO point-of-sale, purchases, suppliers,
prescriptions and reports — while the platform owner runs everything from a
Super Admin dashboard.

> Design language: white + emerald green + navy (light-blue accents), with a
> dark-green sidebar dashboard — inspired by premium MediStock-style
> pharmacy dashboards. Fully responsive on mobile, tablet and desktop.

---

## Screenshots / live demo

- **Static demo (Vercel-ready):** the `vercel-demo/` folder replicates the
  landing page, pharmacy dashboard and POS screen with mock data in the
  production layout. Deploy that folder to Vercel as a static project.
- **Full application:** serve `public/` from any PHP 8 + MySQL host
  (see `docs/DEPLOYMENT.md` for the honest architecture note: Vercel does
  not natively host Core PHP).

## Feature overview

**Public SaaS website** — hero with dashboard preview, trusted-pharmacies
carousel, features, live platform statistics, inventory showcase, expiry &
low-stock previews, POS preview, purchase workflow, supplier showcase,
sales analytics preview, testimonials, pricing with monthly/yearly toggle,
FAQ, blog, contact + newsletter, footer. Pages: Home, About, Features,
Pricing, Solutions, Pharmacy Management, Inventory Management, POS System,
Suppliers, Blog, Contact, Login, Register, Forgot Password, Privacy, Terms.

**Multi-tenant SaaS core**
- Tenant registration with business + pharmacy license details, logo,
  branches, 14-day trial activation and employee invitations.
- `tenant_id` isolation on every business table and tenant-aware
  authorization on every protected operation.
- Auth: registration, login, logout, password recovery, email verification,
  secure `password_hash`, CSRF protection, hardened sessions, login rate
  limiting (5 attempts / 15 min), RBAC with 8 roles and 18 permissions,
  audit + activity logs.
- Subscriptions: Trial / Starter / Professional / Business / Enterprise,
  monthly + yearly billing, upgrades, downgrades, cancellation, renewal,
  coupons (`WELCOME10` = 10%), invoices, payment history, expiry + grace
  handling, and enforced plan limits for branches, employees, medicine
  records, storage and monthly transactions.
- **Payments are recorded as `pending` and only become `verified` after
  verified confirmation** (gateway or Super Admin action) — never
  automatically.

**Super Admin dashboard** — totals for pharmacies (active/suspended), users,
active/expired subscriptions, MRR, ARR and transactions; plan & coupon
management; tenant suspension/reactivation; user management; payment
verification; invoices; support tickets with replies; announcements;
platform settings; activity & audit logs; revenue and subscription
analytics. All statistics are computed from real database queries.

**Pharmacy dashboard** — Overview, Medicines, Categories, Brands, Batches,
Inventory, Stock Adjustments, Stock Movement History, Expiry Management,
Low Stock Alerts, Pharmacy POS, Sales, Sales Returns, Purchases, Suppliers,
Customers, Prescriptions, Expenses, Staff, Branches, Reports & Analytics,
Subscription, Settings.

**Pharmacy rules enforced in the service layer** (`app/StockService.php`)
- Batch-level inventory: batch number, manufacturing + expiry dates,
  purchase/selling price, quantity, supplier.
- **FEFO** (first-expiry-first-out) suggestion on every sale.
- **Expired stock can never be sold** (validated at allocation time).
- **No negative stock** unless the tenant's authorized policy allows it.
- **Every sale, purchase, return and adjustment runs in a DB transaction**
  and writes a complete `stock_movements` audit trail (batches locked with
  `SELECT … FOR UPDATE`).
- POS: medicine + barcode search, batch selection, expiry validation, cart,
  per-line discounts, taxes, multiple payment methods, invoice generation,
  printable thermal-style receipt, returns with refund recording.

## Technology

HTML5, CSS3, JavaScript, Bootstrap 5, Core PHP 8+, MySQL (PDO prepared
statements everywhere), Chart.js, Bootstrap Icons. No Laravel, React, Vue
or Node.js.

## Installation

```bash
# 1. Create the database
mysql -u root -p -e "CREATE DATABASE medistock_cloud CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 2. Configure
cp .env.example .env        # set DB credentials + APP_URL

# 3. Import schema + demo seeds
php database/install.php    # or: mysql -u root -p medistock_cloud < database/database.sql

# 4. Serve the public/ directory
php -S localhost:8000 -t public
```

Then open `http://localhost:8000`. Full guide: `docs/DEPLOYMENT.md`.
Security model: `docs/SECURITY.md`. API notes: `docs/API.md`.
Testing walkthrough: `tests/README.md`.

## Demo credentials

All demo accounts use the password **`password`** (bcrypt-hashed in the
seed data — change them before any real production use).

| Role              | Email                  | Lands on            |
|-------------------|------------------------|---------------------|
| Super Admin       | `super@medistock.test` | Platform dashboard  |
| Pharmacy Owner    | `owner@demo.com`       | Pharmacy dashboard  |
| Pharmacy Admin    | `admin@demo.com`       | Pharmacy dashboard  |
| Pharmacist        | `pharmacist@demo.com`  | Pharmacy dashboard  |
| Cashier           | `cashier@demo.com`     | Pharmacy dashboard  |
| Inventory Manager | `inventory@demo.com`   | Pharmacy dashboard  |
| Accountant        | `accountant@demo.com`  | Pharmacy dashboard  |
| Staff             | `staff@demo.com`       | Pharmacy dashboard  |

Demo tenant: **Demo Pharmacy** (Professional plan) with a second branch,
12 medicines, 15 batches (including one expired batch `PCM-OLD`, two
expiring soon, and low-stock Cetirizine), 3 suppliers, 4 customers, a
received purchase, a completed sale, prescriptions, expenses and a support
ticket. A second tenant, **CityCare Pharmacy**, is on a free trial.

Coupon: **`WELCOME10`** — 10% off a plan change.

## Project structure

```
medistock-cloud-saas/
├── app/            Core: bootstrap, Database (PDO), Auth, helpers,
│                   SubscriptionService, StockService, Crud engine, View layouts
├── config/         app.php, database.php (env-driven)
├── database/       database.sql (schema + seeds), install.php
├── public/         Web root: website, auth, admin/ (pharmacy), superadmin/, api/
│   └── assets/     css, js, images, uploads
├── docs/           SECURITY.md, API.md, DEPLOYMENT.md
├── storage/        logs, uploads, backups
├── tests/          Acceptance walkthrough
├── vercel-demo/    Static demo for Vercel (landing + dashboard + POS)
├── .env.example    Environment template (placeholders only)
└── composer.json   Autoload map (no runtime dependencies)
```

## Deployment architecture

GitHub holds the complete source. The PHP application runs on any PHP/MySQL
host; the `vercel-demo/` static build is the Vercel showcase. Vercel does
not provide native Core PHP hosting — see `docs/DEPLOYMENT.md` for details
and the production checklist, including backups (`mysqldump`) and restore
procedures.

## License

MIT — see `LICENSE` (add your own license file before commercial release if
you prefer different terms).
