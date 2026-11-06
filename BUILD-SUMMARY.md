# MediStock Cloud — Build Summary

**Product:** MediStock Cloud — Pharmacy Management SaaS (Project 05)
**Stack:** Core PHP 8 + MySQL (PDO, no framework), Bootstrap 5, Chart.js
**Location:** `~/workspace/saas-products-batch2/medistock-cloud-saas/`

## Counts (verified at build time)

| Metric | Value |
|---|---|
| Total files | 92 (including this summary) |
| PHP files | 69 |
| PHP lint | **All 69 files pass `php -l`** (PHP 8.3.13, zero failures) |
| SQL tables | **40** — 23 platform/SaaS tables + 17 pharmacy tables |
| Public website pages | 21 (17 marketing/auth + POS preview etc.) |
| Super Admin pages | 11 |
| Pharmacy (tenant) pages | 24 + 1 JSON API endpoint |
| Vercel demo pages | 3 HTML (landing, dashboard, POS) + CSS + JS |

## What was built

- **Public SaaS website** — hero with dashboard preview, trusted-pharmacies
  carousel, features, live platform statistics (DB-computed with graceful
  fallback), inventory showcase, expiry tracking, low-stock alerts, POS
  preview, purchase workflow, supplier showcase, sales analytics preview,
  testimonials, pricing with monthly/yearly toggle, FAQ, blog, contact +
  newsletter, footer; pages for About, Features, Pricing, Solutions,
  Pharmacy Management, Inventory Management, POS System, Suppliers,
  Privacy, Terms.
- **Auth & tenancy** — pharmacy registration (business + license details,
  logo, trial activation, default branch), login with rate limiting
  (5 fails / 15 min), logout, forgot/reset password (hashed single-use
  tokens), email verification, employee invitations with activation links,
  secure sessions, CSRF on every form, RBAC (8 roles, 18 permissions),
  tenant_id isolation on every business query.
- **Super Admin** — live totals (tenants, active/suspended, users, active/
  expired subscriptions, MRR, ARR, transactions), plan + coupon CRUD,
  tenant suspend/reactivate, user activation, payment verification (the
  only path that marks a payment verified), invoices, analytics charts,
  support tickets with replies, announcements with notification fan-out,
  platform settings, activity + audit logs.
- **Pharmacy Admin** — Overview, Medicines, Categories, Brands, Batches,
  Inventory (+ CSV export), Stock Adjustments, Stock Movements ledger,
  Expiry Management (30/60/90-day radar + expired list), Low Stock Alerts,
  POS, Sales + printable invoices, Sales Returns with refunds, Purchases
  (transactional receiving), Suppliers, Customers, Prescriptions, Expenses,
  Staff & invitations, Branches, Reports & Analytics (+ CSV export),
  Subscription (usage bars, plan change with coupons, pending payments,
  invoices, payment history), Settings (branding, tax, policies, logo
  upload, support tickets).
- **Inventory engine** (`app/StockService.php`) — batch-level stock,
  FEFO allocation, expired stock un-sellable, negative stock blocked unless
  the tenant's authorized policy enables it, `SELECT … FOR UPDATE` batch
  locking, and **every sale/purchase/return/adjustment inside a DB
  transaction** writing the `stock_movements` audit trail.
- **Subscriptions** (`app/SubscriptionService.php`) — Trial/Starter/
  Professional/Business/Enterprise, monthly + yearly, upgrade/downgrade/
  cancel/renew, coupons, invoices; payments start **pending** and activate
  plans only on verified confirmation; plan limits enforced for branches,
  employees, medicine records and monthly transactions (storage tracked in
  `subscription_usage`).
- **Database** (`database/database.sql`) — 40 normalized InnoDB tables with
  FKs, unique constraints and tenant indexes + full demo seeds;
  `database/install.php` CLI/web-guarded installer.
- **Vercel demo** (`vercel-demo/`) — static landing + dashboard + working
  POS mock, mock data mirroring the seeded demo pharmacy, fully responsive
  with collapsible sidebar and charts.
- **Docs** — README (install, credentials, architecture), `docs/SECURITY.md`,
  `docs/API.md`, `docs/DEPLOYMENT.md` (incl. the honest note that the PHP
  app needs PHP/MySQL hosting; Vercel hosts the static demo),
  `tests/README.md` acceptance walkthrough, `.env.example` (placeholders
  only), LICENSE.

## Demo credentials (password for all: `password`)

| Role | Email |
|---|---|
| Super Admin | super@medistock.test |
| Pharmacy Owner | owner@demo.com |
| Pharmacy Admin | admin@demo.com |
| Pharmacist | pharmacist@demo.com |
| Cashier | cashier@demo.com |
| Inventory Manager | inventory@demo.com |
| Accountant | accountant@demo.com |
| Staff | staff@demo.com |

Coupon: **WELCOME10** (10% off). Demo tenants: Demo Pharmacy (Professional,
2 branches, 12 medicines, 15 batches incl. 1 expired + 2 expiring soon +
low-stock Cetirizine, 3 suppliers, 4 customers, 1 purchase, 1 sale) and
CityCare Pharmacy (trial).

## Brand palette (from the reference image)

| Token | Hex | Use |
|---|---|---|
| Emerald | `#059669` | Primary actions, accents, charts |
| Emerald dark | `#065F46` | Hover states, sidebar gradient end |
| Deep green | `#04382A` | Sidebar gradient start, mock window bar |
| Emerald light | `#D1FAE5` | Icon chips, badges |
| Navy | `#0F2A43` | Headings, footer |
| Sky tint | `#E0F2FE` | Secondary accents |
| Background | `#F4F8F7` | Dashboard canvas |
| White | `#FFFFFF` | Cards, website base |

## Notes

- No git, GitHub or Vercel commands were run — files only, per instructions.
- No live MySQL server was available in the build environment, so the app
  was verified by full-tree `php -l`, runtime smoke tests of the helper
  layer, and the seeded bcrypt hash was verified against the password
  `password`. Follow `tests/README.md` for the end-to-end acceptance
  walkthrough after installing on a PHP/MySQL host.
