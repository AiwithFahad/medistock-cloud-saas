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
