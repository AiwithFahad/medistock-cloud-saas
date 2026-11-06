# Testing MediStock Cloud

There is no framework test suite; the project is verified by linting,
schema checks and a scripted acceptance walkthrough.

## 1. Syntax — every PHP file must pass
```bash
find . -name '*.php' -print0 | xargs -0 -n1 php -l
```

## 2. Installation smoke test
```bash
mysql -u root -p -e "CREATE DATABASE medistock_test CHARACTER SET utf8mb4;"
DB_DATABASE=medistock_test php database/install.php
php -S localhost:8000 -t public
```

## 3. Acceptance walkthrough (from the project specification)
Use the demo credentials in `README.md` and verify:

1. **Registration** — register a new pharmacy at `/register.php`; a tenant,
   owner account, default branch and trial subscription are created.
2. **Tenant isolation** — sign in as the demo admin; confirm no data from
   any other tenant is visible anywhere (search, IDs in URLs).
3. **Roles** — sign in as `cashier@demo.com`: POS and Sales are available;
   Settings, Staff and Reports are not (menu hidden, direct URL returns 403).
4. **Super Admin** — sign in as `super@medistock.test`: totals for tenants,
   users, subscriptions, MRR/ARR match the database; suspend and reactivate
   the CityCare tenant and confirm its owner can no longer sign in while
   suspended.
5. **POS & FEFO** — sell Paracetamol: batch `PCM-2409` (earliest expiry) is
   suggested; quantities and a `sale` stock movement are written.
6. **Expiry protection** — batch `PCM-OLD` is expired: it never appears in
   the POS and cannot be sold; it is listed under Expiry Management.
7. **Purchases** — receive a purchase; batches are created/topped up and
   `purchase` movements appear in Stock Movements.
8. **Returns** — return an item from the demo sale; the refund is recorded
   and stock is restored (in-date batches only).
9. **Adjustments** — decrease a batch with reason "Damaged stock"; the
   adjustment appears with a reason and balance. Negative adjustments beyond
   available stock are rejected (policy off).
10. **Subscriptions** — as tenant admin, change plan with coupon `WELCOME10`:
    the invoice shows a 10% discount and the payment is **pending**; only
    after Super Admin verification does the plan activate.
11. **Plan limits** — on the Trial plan, creating branches/medicines beyond
    the plan limit is blocked with an upgrade message.
12. **Reports** — dashboard and Reports figures change immediately after a
    sale (they are computed from live queries).
