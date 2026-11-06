# MediStock Cloud — API Notes

MediStock Cloud is a server-rendered Core PHP application. Integrations use
a small JSON API surface plus documented webhook-style events.

## Authentication
API endpoints require an authenticated dashboard session (the same hardened
session cookie used by the web app). Every response is scoped to the
signed-in user's tenant; cross-tenant access is impossible because queries
are filtered by the session tenant, never by a client-supplied tenant id.

## Endpoints

### GET `/api/medicines.php`
Search medicines for POS and integrations.

| Parameter   | Type   | Description                                   |
|-------------|--------|-----------------------------------------------|
| `q`         | string | Name fragment, exact barcode or exact SKU     |
| `branch_id` | int    | Optional branch scope for availability        |

Response:
```json
{
  "data": [
    {
      "id": 1,
      "name": "Paracetamol 500mg",
      "generic_name": "Paracetamol",
      "sku": "PCM-500",
      "barcode": "8901001",
      "strength": "500mg",
      "unit": "tablet",
      "available": 460,
      "fefo_batch": "PCM-2409",
      "fefo_expiry": "2027-01-15",
      "selling_price": "1.50"
    }
  ]
}
```
`available` counts only **in-date** batches. `fefo_batch` is the
first-expiry-first-out suggestion. Unauthenticated requests return `401`.

### GET `/api/stats.php` — dashboard KPI aggregates
Requires `dashboard.view`. Returns real, tenant-scoped aggregates:
medicine/category counts, stock quantity, inventory value, today's sales,
monthly revenue, pending purchase orders/transfers, supplier and customer
counts, pending prescriptions, low-stock list, expiry buckets
(`expired_batches`, `expiring_30/60/90`) and recent transactions.
Optional `?from=YYYY-MM-DD&to=YYYY-MM-DD` filters the sales/purchase
figures.

### GET `/api/charts.php` — Chart.js series
Returns `{ labels, datasets }` for the shared chart loader in
`assets/js/app.js`. `?series=` one of `daily_sales` (`&days=`),
`monthly_revenue` / `purchases` (`&months=`), `top_medicines`,
`movements` (stock in/out trends), `expiry` (expiry buckets),
`inventory_by_category`. Super Admin only: `series=platform_revenue`
counts **verified** subscription payments only.

### GET `/api/pos-search.php` — POS product + batch search
Requires `pos.access`. Parameters: `q`, `branch_id` (defaults to the main
branch). Returns each matching active medicine with **all** its batches at
that branch — batch number, expiry date, days left, quantity, selling
price and an `expired` flag. Expired batches are visible for transparency
but can never be checked out.

### POST `/api/pos-checkout.php` — server-authoritative checkout
Requires `pos.access` and the session CSRF token in the `X-CSRF-Token`
header. JSON body: `{ branch_id, customer_id, prescription_id, items:
[{ medicine_id, batch_id|null, quantity, discount }], payments:
[{ method, amount, reference }] }`. The server re-reads every price from
the batch rows, caps discounts at line value, applies the tenant tax
setting, enforces stock/expiry/prescription rules and the plan's
transaction limit, requires payments to cover the computed total, then
commits through `StockService::createSale()` in one transaction and
returns `{ sale_id, invoice_number, grand_total, change, receipt }`.
Client-sent prices are never accepted — the body has no price fields.

### GET `/api/customers.php` — customer typeahead
`?q=` name/phone/email fragment; returns up to 15 tenant customers.
Requires `pos.access`, `customers.manage` or `prescriptions.manage`.

### GET `/api/medicines-list.php` — paginated medicine explorer
Requires `medicines.view`. Parameters: `q`, `category_id`, `status`,
`sort` (`name|available|price`), `dir`, `page`, `per_page`. Returns rows
with photo URL, brand/manufacturer, category, availability, low-stock
flag, minimum sellable price and batch count, plus `total/page/pages`.

## Architecture notes
- **Services layer:** business rules live in `app/StockService.php`
  (transactions, FEFO, expiry, movements) and `app/SubscriptionService.php`
  (plans, limits, payments, invoices) so new endpoints reuse the same
  guarantees as the UI.
- **Webhooks (outbound, design):** the platform emits domain events through
  `activity_log()` (`sale.created`, `purchase.received`, `payment.verified`,
  `subscription.changed`, `stock.adjusted`). A webhook dispatcher can be
  attached at that single choke point to POST signed JSON payloads to
  tenant-configured URLs; payloads should include `event`, `tenant_id`,
  `reference` and `occurred_at`, signed with HMAC-SHA256.
- **Payments (inbound):** a gateway callback endpoint should call
  `SubscriptionService::verifyPayment($paymentId, 'gateway:<provider>')`
  after validating the provider signature — this is the only path that
  activates a subscription from a payment.
- **Rate limiting:** put API routes behind your reverse proxy limits; login
  attempts are already rate-limited in the application.

## Versioning
The JSON surface is versioned by URL convention when it grows
(`/api/v2/…`); the current endpoints are stable for the 1.x line.
