# WS8 — Commerce, POS & Secure Payments: Staging UAT & Rollback

Acceptance checklist and non-destructive backout plan for the WS8 milestone
(Commerce catalog & merchant ownership, Clinic POS & patient checkout, provider
payment hardening, operational reporting). It complements
[WS6_STAGING_RUNBOOK.md](WS6_STAGING_RUNBOOK.md) (host facts, provisioning) and
[OPERATIONS_RECOVERY.md](OPERATIONS_RECOVERY.md).

> **Never deploy from TRAE.** Nothing here is executed automatically. WS8 lands on
> `feature/v3-ws8-commerce-payments` behind a single Draft PR targeting
> `feature/v3-ws7-practitioner-operations`; it is deployed only after its parent
> PRs (#33, #34) merge and this PR is reviewed and merged into `main`.

> **SANDBOX ONLY.** `PAYMENTS_LIVE_ENABLED` must remain `false`. No live
> credential is accepted in code, fixtures or the staging environment. No real
> charge is performed by any automated test.

---

## 1. Guardrails (unchanged, non-negotiable)

- Do **not** recreate, rename, drop, or `migrate:fresh` the existing tenant
  `Babul_Jakarta` or its database.
- Do **not** reset or change `APP_KEY`; do **not** change Principal credentials.
- No destructive migrations. WS8 only ADDS nullable columns and indexes.
- Do **not** run real-data seeds. Tests and UAT use synthetic data only.
- Never mix the five ledgers: product orders, consultations, tenant LBA AI
  credits, patient SINA wallet, platform subscriptions. Commerce touches product
  orders only.

---

## 2. Preflight (read-only)

```sh
php artisan lba:staging:health            # must exit 0
php artisan lba:localization:audit --strict
php artisan lba:localization:audit --baseline=en --strict
php artisan lba:localization:audit --baseline=ms-MY --strict
php artisan migrate:status                # central
php artisan --version                     # Laravel 13.x
git status --porcelain                     # MUST be empty
git rev-parse --short HEAD                 # record the SHA under review
```

Confirm `PAYMENTS_LIVE_ENABLED=false` (config `payments.live_enabled`) and that
no gateway row is both `mode=live` and `verified`.

---

## 3. Acceptance checklist

### WS8.A — Multi-tenant commerce
- [ ] A product/prices are visible only inside their own tenant database; a
      foreign product id resolves to nothing.
- [ ] Order placement records an explicit merchant of record; an ambiguous
      (two-brand) mapping fails closed rather than guessing.
- [ ] Branch stock never goes negative; every change is an immutable
      `stock_movements` row; transfers are paired and balance.
- [ ] A merchant cannot settle a currency it does not own; a deactivated
      merchant cannot settle.

### WS8.B — Clinic POS & patient checkout
- [ ] POS cash tender settles the order, reserves stock and issues an invoice.
- [ ] POS provider tender leaves the order OPEN and surfaces the hosted redirect;
      it is settleable only by a verified webhook.
- [ ] A repeated checkout with the same idempotency key returns the ORIGINAL
      order without reserving stock twice.
- [ ] A patient sees only their OWN orders; a foreign order id is a 404.

### WS8.C — Provider-safe payments (sandbox)
- [ ] The intent is reserved BEFORE the provider call; a retry replays the stored
      redirect; a concurrent attempt is refused while in flight; only a stale
      reservation is retried.
- [ ] A webhook is rejected when its signature is wrong, its order is unknown,
      its currency contradicts the intent, or its amount exceeds the intent.
- [ ] A replayed settlement webhook settles exactly once (one `succeeded` row).
- [ ] A provider refund stays PENDING until the provider confirms; a cash refund
      completes immediately and carries evidence; over-refund is refused.
- [ ] Reconciliation separates totals by currency AND merchant.

### WS8.D — Operations & security
- [ ] Sales dashboard, POS and reconciliation show real DB values for the acting
      identity's tenant/branch only — no dummy numbers.
- [ ] A role without `order.manage` cannot reach the reconciliation dashboard.
- [ ] The commerce role grants no clinical, LBA-review or wallet permission.
- [ ] Gateway credentials are ciphertext at rest and never surfaced in any
      provider option list or API response.

---

## 4. Automated evidence

```sh
php artisan lba:localization:audit --strict
vendor/bin/pint --test          # touched files clean
php artisan test               # full PHPUnit suite
```

Focused WS8 suites: `CommerceMerchantTest`, `CommerceOrderTest`,
`CommerceInventoryTest`, `CommercePosTest`, `CommerceCheckoutTest`,
`CommercePaymentsTest`, `CommerceRefundTest`, `CommercePaymentHardeningTest`,
`CommerceSecurityTest`, `PatientCommerceApiTest`.

---

## 5. Manual sandbox UAT (operator, on staging)

1. Enable the `commerce` module for a **synthetic** staging tenant.
2. Enter a sandbox Midtrans/iPaymu/PayPal gateway (sandbox mode, sandbox keys).
3. Ring up a cash sale at the POS; confirm order PAID, invoice ISSUED, stock
   reduced by exactly the sold quantity.
4. Ring up a provider sale; open the hosted redirect; complete it with the
   provider's sandbox tooling; confirm the order settles ONLY after the webhook.
5. Replay the same webhook; confirm no second settlement.
6. Refund the provider sale; confirm it stays PENDING until the provider
   confirms, then completes once with stock returned.
7. As a patient, place and pay for an own order; confirm a foreign order id is
   not readable.
8. Confirm the reconciliation page lists collected/refunded per currency and per
   merchant with no cross-currency or cross-merchant pooling.

---

## 6. Backout plan (non-destructive)

WS8 adds nullable columns and indexes only, and every commerce table already
existed, so rollback is a deployment revert — not a data reset.

1. Record the failing SHA and the last known-good SHA.
2. Redeploy the previous immutable release SHA (or `main`) via the existing
   `deploy-staging.sh` path. **Never** `migrate:fresh`.
3. The WS8 migrations are additive; leaving them in place is safe. Do **not**
   roll the schema back destructively. If a revert of a specific additive column
   is ever required, do it as a reviewed, separate migration with a backup taken
   first.
4. Keep `PAYMENTS_LIVE_ENABLED=false` throughout; disable any sandbox gateway
   that is not needed.
5. Verify `php artisan lba:staging:health` and the read-only preflight again, and
   confirm `Babul_Jakarta` and `APP_KEY` are untouched.

---

## 7. Known blockers / open dependencies

- Draft PRs **#33** (WS6) and **#34** (WS7) remain open; WS8 cannot merge until
  they do. WS8's PR targets `feature/v3-ws7-practitioner-operations` meanwhile.
- No provider is declared production-ready: real sandbox acceptance and merchant
  verification are still required before any live switch.
