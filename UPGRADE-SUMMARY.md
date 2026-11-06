# MediStock Cloud — Upgrade Summary

Upgrade of the existing application **in place** (no rebuild, no redesign).
Branding, palette (emerald `#059669` / `#065F46`, deep-green sidebar
`#04382A`, navy `#0F2A43`), logo, typography, homepage, graphics, dashboard
structure, navigation style and responsive layouts are unchanged; new CSS
was appended to `public/assets/css/style.css`, never substituted.

## 1. Dashboard trees (separate, role-specific, DB-backed)

Sign-in now lands each role on its own workspace (`role_home()` in
`app/helpers.php`; `public/login.php` updated). Menus in
`app/View.php::admin_menu()` are role-aware. RBAC is enforced
**server-side** on every page (`require_role` / `require_permission` /
`can()`), and every query is tenant-scoped.

### `public/super-admin/` — platform console (Super Admin only)
| Page | What it does |
|---|---|
| `dashboard.php` | Tenant counts by status, verified-only revenue (total + month), pending payments awaiting verification, subscriptions expiring ≤ 7 days, open tickets, revenue chart (API), newest pharmacies, approval queue |
| `pharmacies.php` | Pharmacy lifecycle: **approve** (pending → active), activate, suspend; usage counts (staff, medicines), plan + subscription state; search + status filter |
| `users.php` | All platform users across pharmacies; role/search filters; activate/deactivate (never your own account) |
| `plans.php` | Plan CRUD (prices + usage limits: branches, staff, medicines, storage, monthly sales) |
| `subscriptions.php` | All subscriptions; extend +30 days/reactivate, cancel, expire |
| `payments.php` | Verify (only after confirmed receipt — activates the subscription), fail, refund; verified/pending/refunded summaries |
| `reports.php` | Verified revenue by month, MRR, tenant growth, plan distribution, subscription mix, top pharmacies, **payments CSV export** |
| `settings.php` | Platform settings incl. `require_tenant_approval` |

The legacy `public/superadmin/` pages (coupons, invoices, tickets,
announcements, analytics, logs, and the originals of the above) remain in
place and linked from the sidebar — nothing was removed.

### `public/admin/` — pharmacy management (owner/admin + permission-gated roles)
Existing modules kept (medicines, categories, brands, batches, inventory,
adjustments, movements, expiry, low stock, classic POS, sales, returns,
purchases, suppliers, customers, prescriptions, expenses, staff, branches,
subscription, settings, tickets). Added/upgraded:
| Page | What it does |
|---|---|
| `dashboard.php` | NEW full dashboard: date-filtered KPIs (sales, purchases, real gross profit, profit after expenses), stock value, plan usage, AJAX charts (daily sales, top medicines, inventory by category, expiry), quick actions, recent sales. `admin/index.php` classic overview retained |
| `medicines.php` | UPGRADED: photo upload, manufacturer, default supplier, default purchase/selling prices, status; search incl. manufacturer; **CSV import/export** (categories/brands matched by name, plan limit enforced) |
| `transfers.php` | NEW: branch transfers (shared body `app/partials/transfers.php`) |
| `purchase-orders.php` | NEW: purchase orders + partial receiving (shared body `app/partials/purchase_orders.php`) |
| `supplier-payments.php` | NEW: record supplier payments; per-supplier outstanding balances; payment history |
| `reports.php` | EXTENDED: daily cash report (today's takings by method − refunds − expenses), 6-month financial summary (revenue, COGS, gross profit, expenses, refunds, net), outstanding supplier total, purchases + expenses CSV exports (sales CSV retained) |
| `receipt.php` | NEW: shared printable receipt/invoice for any sale (screen + print-only sheet) |

### `public/pharmacist/` — pharmacist workspace (pharmacist/admin/owner)
| Page | What it does |
|---|---|
| `dashboard.php` | Prescription queue, dispensed-today, expiry warnings (≤30d + expired alert), recent dispensing |
| `prescriptions.php` | Create prescriptions **with line items** (medicine, dosage, qty, instructions), detail view, linked sales, **authorized dispensing** (role-gated; stamps dispensed_by/at), cancel |
| `medicines.php` | AJAX medicine explorer: live search, category/sort filters, pagination, per-medicine batch availability with expiry status (`api/medicines-list.php` + `api/pos-search.php`) |
| `dispensing.php` | Authorized dispensing records (who dispensed what, when) + sales rung against prescriptions, date-filtered |
| `sales.php` | Tenant sales history with Rx flags + receipt reprints |

### `public/cashier/` — cashier workspace
| Page | What it does |
|---|---|
| `dashboard.php` | My sales/takings today + month, pharmacy total today, my payment-method breakdown, my recent sales |
| `pos.php` | **JavaScript POS** (see §3) |
| `sales.php` | My own sales only, date filter, payment breakdown, receipt reprints |

### `public/inventory/` — inventory manager workspace
| Page | What it does |
|---|---|
| `dashboard.php` | Stock units + valuation, open POs, pending transfers, movement-trend + expiry charts (API), reorder suggestions, latest movements |
| `receiving.php` | Quick stock receiving (no PO) + **purchase returns** to suppliers with returnable-quantity validation |
| `transfers.php` | Branch transfers (shared body) |
| `purchase-orders.php` | Purchase orders + partial receiving (shared body) |
| `alerts.php` | Expiry buckets (expired / ≤30 / 31–60 / 61–90 days) with value-at-risk, **expired write-off** via audited adjustment, low-stock + reorder suggestions |
| `suppliers.php` | Supplier records with purchased/paid/returned/outstanding per supplier |

## 2. Backend — StockService extensions (`app/StockService.php`)

The existing transactional batch engine was extended, not replaced:
- `findOrCreateBatch()` — shared batch upsert (also back-fills
  `quantity_received`); `receivePurchase()` now uses it.
- `createPurchaseOrder()` / `receivePurchaseOrder()` — PO lifecycle with
  **partial deliveries**; receiving creates a real purchase (batches,
  valuation, movements) and updates ordered vs received per line.
- `createPurchaseReturn()` — returns stock to a supplier from a received
  purchase; validates returnable quantity (purchased − already returned,
  capped by on-hand batch stock), decrements the batch, writes movements.
- `supplierBalance()` — outstanding balance is **derived**
  (purchases − payments − returns), never stored. `recordSupplierPayment()`.
- `createTransfer()` / `completeTransfer()` / `cancelTransfer()` —
  transfers are pending until completed; completion is one transaction:
  FEFO out of the source branch (expired batches never move), find-or-
  create batch at the destination, two `transfer` movements per line.
- `dispensePrescription()` — stamps status + dispensed_by + dispensed_at
  and writes an audit-log entry.
- All operations keep the platform rules: expired stock can never be
  sold, no negative stock unless the tenant's authorized policy is on,
  every movement is a permanent `stock_movements` record, inventory and
  financial operations are audit-logged (`audit_logs`).

`app/Crud.php` gained a `file` field type (validated image upload to
`public/assets/uploads/`, path stored relative to assets) and JS confirm
dialogs on delete. `app/Auth.php`: tenants can register as `pending`
when the platform enables approval; pending/suspended tenants are
blocked at sign-in with a clear message.

## 3. JavaScript layer

- `public/assets/js/app.js` (loaded on every dashboard page): toast
  notifications, promise-based confirm modal (all `data-confirm` forms),
  CSRF-aware `msFetch()`, AJAX Chart.js loader for
  `canvas[data-chart-url]`, debounced live-search binding, money/escape
  helpers.
- `public/assets/js/pos.js` — the JS POS cart: product search with
  barcode auto-add, per-line **batch picker** (expired batches shown
  disabled; ≤30-day expiry warnings), quantity/discount controls,
  customer typeahead, prescription selection (enforced for Rx lines),
  **split payments** with change calculation, checkout via
  `api/pos-checkout.php`, on-screen + printable receipt.
- Dashboard charts across admin/inventory/super-admin are fed by the
  JSON APIs below — no dummy buttons and no placeholder statistics
  anywhere in the new pages.

## 4. JSON API endpoints (`public/api/`)

All endpoints require a dashboard session, are tenant-scoped, and check
permissions; errors are JSON `{ "error": "…" }` with proper HTTP codes.

| Endpoint | Purpose |
|---|---|
| `stats.php` | KPI aggregates: medicines, categories, stock qty, inventory value, today's sales, monthly revenue, period sales/purchases (`from`/`to`), pending POs/transfers, suppliers, customers, pending prescriptions, low-stock list, expiry buckets (expired, 30/60/90), recent transactions |
| `charts.php` | Chart.js series: `daily_sales`, `monthly_revenue`, `purchases`, `top_medicines`, `movements`, `expiry`, `inventory_by_category`; Super Admin `platform_revenue` (verified payments only) |
| `pos-search.php` | POS search returning every batch per medicine with expiry data + `expired` flags |
| `pos-checkout.php` | Server-authoritative checkout: prices re-read from batches, discounts capped, tax from tenant settings, Rx + stock + plan-limit validation, payments must cover the server total; commits via `StockService::createSale()` and returns the receipt payload. **Client prices are never trusted** |
| `customers.php` | Customer typeahead for POS/prescriptions |
| `medicines-list.php` | Paginated medicine explorer (search, category/status filters, sorting) |
| `medicines.php` | (pre-existing) POS medicine summary search — unchanged |

## 5. Database — migrations (`database/migrations/`)

Numbered, non-destructive; all folded into `database.sql` for fresh
installs, and `database/install.php` now applies them after import,
ignoring only "already exists" errors.

| Migration | Contents |
|---|---|
| `001_medicine_fields.sql` | `medicines.photo_path`, `manufacturer`, `default_supplier_id` (FK), `default_purchase_price`, `default_selling_price`, `status`; `batches.quantity_received` (+ backfill) |
| `002_purchase_orders.sql` | `purchase_orders`, `purchase_order_items`, `purchase_returns`, `purchase_return_items` |
| `003_supplier_payments.sql` | `supplier_payments` |
| `004_stock_transfers.sql` | `stock_transfers`, `stock_transfer_items` (split payments need no schema: `sale_payments` already stores one row per method) |
| `005_prescriptions_and_approval.sql` | `prescription_items`, `prescriptions.dispensed_by/dispensed_at`, `tenants.status` gains `pending`, `require_tenant_approval` setting (default off) |

Total tables: 40 → **48**. Demo seeds extended: prescription line items,
one open purchase order, a $500 supplier payment (leaves a real $578.00
outstanding balance on purchase #1), one pending branch transfer; batch
seeds carry `quantity_received`. All demo logins keep working
(password `password`): super@medistock.test, admin@demo.com,
pharmacist@demo.com, cashier@demo.com (+ owner/inventory/accountant/
staff @demo.com); coupon `WELCOME10`.

## 6. Vercel demo (`vercel-demo/`)

Design unchanged. The POS mock now mirrors the upgraded POS: multi-batch
products, batch picker with the expired batch disabled and ≤30/≤90-day
warnings, per-line discounts, subtotal/discount/total math, split
payments with change, Rx-prescription enforcement, and a full receipt
(lines, payments, change). The dashboard gained monthly-revenue and
expiry-statistics charts and a live stock-table search — all with mock
data, clearly labelled as a static demo.

## 7. Tests performed

- `php -l` on **every** PHP file in the project (PHP 8.3.13):
  **104 files, 0 failures** — including all new pages, APIs, partials and
  modified core files.
- `node --check` on `app.js`, `pos.js`, `main.js`, demo `demo.js`: all
  pass. Inline `<script>` blocks extracted from the new PHP pages and
  syntax-checked the same way: all pass.
- Static verification: required file trees exist; helper/service
  signatures cross-checked against call sites (`audit_log`, `fmt_date`,
  `require_role`, `SubscriptionService::current/usage/limitError`,
  `StockService` methods); database.sql contains 48 tables and the new
  seed rows are consistent with existing demo data (purchase #1 total
  $1,078.00 − $500 payment = $578.00 outstanding).
- **Not runtime-tested:** there is no MySQL/MariaDB server in this build
  environment, so DB-backed flows (checkout transaction, PO receiving,
  transfers, installer + migrations against a live database) could not be
  executed end-to-end here. They were written against the same PDO
  patterns as the pre-existing, previously verified code paths and
  lint-verified. First deployment should run `php database/install.php`
  on a staging database and walk `tests/README.md` plus a POS sale, a PO
  partial receive, a transfer completion and a supplier payment.

## 8. Known limitations

- The classic server-side POS (`admin/pos.php`) remains available
  alongside the new JS POS; both commit through the same
  `StockService::createSale()`.
- `pos-checkout.php` requires the payments to cover the total; partial
  payment / credit sales are not supported (matches the data model —
  sales are `completed` or `void`).
- Medicine photos are stored on the local filesystem
  (`public/assets/uploads/`); multi-server deployments should point that
  directory at shared storage.
- The installer still refuses web access unless `APP_DEBUG=true` with an
  `INSTALL_KEY`, and re-running it on a seeded database fails on
  duplicate seeds (unchanged behaviour); use the migrations for upgrades
  of existing installs.

## 9. Remaining tasks

- ~~Run the staging walkthrough in §7 (live MySQL) before production.~~
  **Done — see §10 (runtime verification against MariaDB 10.11).**
- Optional: point the topbar search at `api/medicines-list.php` for
  instant results (currently submits to the medicines page, as before).

## 10. Runtime verification (MariaDB 10.11, live HTTP + SQL assertions)

§7 was written before a database server existed in the build
environment, so its DB flows were verified by lint + review only.
This pass ran the **actual application** end-to-end: MariaDB
10.11.14, a fresh `database/install.php` import into a scratch
database (48 tables, migrations 001–005 applied), PHP's built-in
server with `public/` as web root, `APP_DEBUG=true`, and curl
sessions per role — asserting rendered page content (not just HTTP
status codes) and post-conditions in SQL after every write. The
scratch database, its user and the temporary `.env` were deleted
afterwards; MariaDB was left running for the other products'
verification passes.

**Schema cross-check:** a database imported from `database.sql`
alone is column-for-column identical (tables, columns, types) to
one installed via `install.php` (schema + migrations) — the
"migrations folded into database.sql" claim in §5 holds exactly.

**Verified working (selection — ~155 assertions, all passing):**

- All 16 public pages render with real content, no fatals.
- Logins for super admin, owner, pharmacy admin, pharmacist,
  cashier and inventory manager each land on their correct new
  tree; anonymous users are redirected to login; a cashier opening
  the Super Admin tree gets 403; every sensitive admin page
  (settings, staff, subscription, supplier payments, reports,
  expenses) 403s for a cashier. The admin *dashboard* itself is
  visible to the cashier by design — it is gated by the
  `dashboard.view` permission, which the cashier role is seeded
  with; every admin page is individually permission-gated.
- `api/stats.php`: 14 aggregates match hand-computed seed values
  exactly (12 medicines, stock quantity 2558, inventory value
  $3,023.70, today's sales $11.50, 1 expired batch, expiring
  30/60/90 = 0/1/2, low stock = Cetirizine only, 1 pending PO,
  1 pending transfer, 3 suppliers). All 7 tenant chart series +
  the Super Admin `platform_revenue` series return real data.
- JS POS (`api/pos-search.php` / `api/pos-checkout.php`): search
  flags the expired batch `PCM-OLD`; a checkout naming that batch
  is refused with 422 "EXPIRED" and writes nothing; a
  prescription-only medicine without a prescription is refused;
  a 130-unit FEFO sale ($195.00, split cash $100 + card $95)
  commits with batch #1 drained 120→0 and batch #2 340→330,
  matching `sale_items`, `sale_payments` and two `stock_movements`
  rows with correct running balances.
- Classic server-side POS (`admin/pos.php`): add ×2 + checkout
  produces a $1.80 sale, the placeholder payment row is corrected
  to the grand total, batch stock decrements, printable receipt
  renders the items.
- Supplier payments: seeded outstanding for supplier #1 confirmed
  at $578.00 ($1,078 purchase − $500 paid); recording a $78
  payment through the UI drops the derived balance to $500.00.
- Purchase orders: partial receive of PO-DEMO-0001 (40 of 100
  Cetirizine) creates the purchase row ($24.00, ref
  `PO:PO-DEMO-0001`), a new batch at the form's prices
  ($0.60/$2.25), a `purchase` movement, and flips the PO to
  `partially_received`.
- Branch transfers: completing the seeded transfer moves 20
  Paracetamol branch 2 → branch 1 (source batch 150→130,
  destination batch created at 20) with the expected pair of
  `transfer` movement rows; status becomes `completed`.
- Dispensing: pharmacist dispenses pending RX-1001; status,
  `dispensed_by` (= the pharmacist) and `dispensed_at` are all
  stamped; a cashier is 403'd from the pharmacist tree.
- Platform payments: a payment sits in `pending` (tenant's
  subscription stays `trial`) until a Super Admin verifies it —
  verification flips payment → `verified` (with timestamp),
  subscription → `active`, and the linked invoice → `paid`.
- Registration lifecycle, both modes: with
  `require_tenant_approval=0` a new pharmacy is instantly active
  with trial subscription, default MAIN branch and a working
  email-verification link; with the setting on `1` the tenant is
  created `pending`, its owner is blocked at login with the
  approval message, a Super Admin approval flips it to `active`,
  and the owner can then sign in to the admin dashboard.
- Full page sweep: all pages of the `super-admin/`, `admin/`,
  `pharmacist/`, `cashier/`, `inventory/` **and** legacy
  `superadmin/` trees render without fatals under valid sessions,
  including detail views (receipt, PO detail, transfer detail,
  prescription detail) and the token pages without parameters.
- Zero PHP warnings, notices, deprecations or fatals in the
  server log across the entire run (PHP 8.5.8 runtime).
- Final re-check after the run: `php -l` on all **104 PHP files —
  0 failures**; `node --check` passes on all 4 JS files
  (`public/assets/js/{main,app,pos}.js`, vercel-demo demo.js).

**Bugs found: none.** Unlike its sibling product, MediStock's
original build and the upgrade both ran correctly on first live
contact — no code fixes were required by this pass. Two initial
test failures were harness artifacts, investigated and cleared:
a grand-total comparison tripped on JSON number formatting
(`195` vs `195.0`; the database holds exactly 195.00), and a
Super Admin "approve" test used an expired test session (the
action itself was re-run with a valid session and verified in
SQL).

**Files changed by this verification pass:** only this file
(`UPGRADE-SUMMARY.md`, §9 note + this §10). No application code,
schema, seed or demo file was modified.
