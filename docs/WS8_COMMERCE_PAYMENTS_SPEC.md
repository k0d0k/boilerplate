# LBA Cloud v3.0 — WS8 Commerce, POS & Secure Payments

**Status:** Product-owner-directed next coding milestone after WS7. Implementation not yet begun.
**Repository:** `viacom-id/lba-cloud`
**Existing branch:** `feature/v3-ws8-commerce-payments`
**Starting baseline:** WS7 commit `b16f9a7`; dependent on open Draft PR #34, which itself depends on open Draft PR #33. The WS8 PR must target `feature/v3-ws7-practitioner-operations` until WS7 is merged; do NOT merge any PR or deploy from TRAE.

## Existing code to reuse, not rewrite

- `Modules/Commerce` already implements ProductCategory/Product/ProductPrice, InventoryStock/StockMovement, Order/OrderItem/Invoice/PaymentTransaction, `OrderService`, `InventoryService`, `PaymentSettlement`, `RefundService`, `ReconciliationService`, Filament POS, order, catalog, stock and invoices.
- Existing `PaymentManager` and adapters `MidtransDriver`, `IpaymuDriver`, `PaypalDriver`, centralized `PaymentGateway` / `PaymentIntent`, signed provider webhook routes and master payment config.
- Tenant-owned data MUST stay in isolated tenant DB; central only platform/provider configuration and opaque intent registry.
- Existing patient portal, booking, LBA and SINA wallets are separate contexts. Do not mix the tenant LBA AI-credit wallet, patient SINA wallet, subscriptions and product purchases.

## Business goal

Finish real operational sales for a multi-brand, multi-tenant clinic SaaS: authorized Clinic Admin / commerce staff manage catalogs, branch stock and POS; a patient can browse permitted products and place their own order; a verified payment (cash with recorded operator confirmation or sandbox provider webhook) settles the order once, creates accurate receipts and triggers controlled fulfillment. Merchant of record, legal invoice issuer and currency must be explicit so funds cannot be pooled across unrelated partners.

**Critical current gaps to audit** (do not assume code is safe):
1. `PaymentManager::charge()` calls the external provider before `PaymentIntent::firstOrCreate()`; this risks duplicate external charges on retries/races. Establish a durable idempotency barrier BEFORE the external call; verify gateway-specific idempotency references and recovery for unknown/timeouts.
2. `RefundService::refund()` creates a local `refunded` ledger row / returns stock without actually contacting the external payment provider. Never label a remote/card refund completed until provider confirmation and reconciliation; distinguish cash/manual refund and remote refund request / pending / succeeded / failed.
3. Monetary calculations use PHP `float` in several services: define a currency-safe amount strategy (integer minor units or reliable decimal arithmetic), with explicit IDR/MYR/USD precision and deterministic rounding. Provide a migration/compatibility path; do not silently rewrite existing financial amounts.
4. Configuration of payment credentials is platform-central, but tenant/brand merchant ownership is NOT inherently identical. Introduce explicit, auditable merchant mapping and settlement destination by tenant/brand/country. No automatic reuse of one partner's gateway credentials for another without explicit contractual authorization.
5. `PosTerminal` currently exposes cash and deferred tender; provider checkout + patient order payment needs an actual secure, testable end-to-end path; a browser return URL must NEVER independently mark an order paid.

## WS8.A — Commerce catalog, merchant ownership and inventory consistency

- Audit and extend existing product/price/stock models rather than duplicate.
- Define tenant/brand product visibility and merchant-of-record, legal entity, currency, invoice issuer and branch ownership. Keep one tenant DB for clinical/commerce data and explicit legal merchant mapping for payments; ensure a clinic cannot charge through another tenant's merchant.
- Products and prices: category/SKU/variant where required, active/inactive availability, tenant/brand-specific price, currency and taxes; never trust frontend amounts.
- Branch inventory: locked stock changes and race-safe first stock-row creation; audit receipts, adjustments, transfer between branches and returns with immutable movements. Never allow negative stock or stock access outside permitted branch.
- Order states: pending-payment, paid, cancelled, fulfilled, refunded/partial if supported; avoid premature irreversible stock consumption or duplicate restocking. Use existing workflows and backwards-compatible migrations.
- Patient self-service read only of published permitted products; restrict personal data, no advertising/marketing use without consent.

## WS8.B — End-to-end Clinic POS & Patient Checkout

- Clinic POS supports cart, patient association when explicitly authorized, true price server validation, cash payment record with actor and receipt, deferred payment and sandbox provider selection; safe idempotent repeated action.
- Patient can browse products, place an order for OWN linked identity, see clear totals and payment status, history and invoices/receipts. Patient never writes a foreign patient/order ID.
- Guest checkout only if explicitly approved; no synthetic patients. Booking/consultation order charges should remain separate from product order accounting unless product owner provides tariff/merchant rules.
- Optional fulfillment modes pickup / shipment only if merchant, tax/shipping policy and address handling are defined; no misleading checkout if not ready.
- Filament and Patient UI real route-backed with existing navy/teal, responsive, id/en/ms-MY; no duplicate frontend framework.

## WS8.C — Provider-safe payments, refunds & reconciliation (SANDBOX ONLY)

- Preserve current `MidtransDriver`, `IpaymuDriver`, `PaypalDriver` interfaces. Verify each protocol against its official provider specifications before implementing any new provider behavior.
- Enforce `PAYMENTS_LIVE_ENABLED=false` in staging and tests. Explicit approval/gate required before any live switch; never accept live credentials in code or test fixtures.
- Payment intent must be durable before calling external provider, with stable provider-order reference, unique constraints, per-merchant and per-tenant scope, retries/recovery, request signing, bounds/timeouts, and robust amount/currency/merchant verification.
- Webhooks: signature verification mandatory; reject stale/replay/unknown merchant/order/tenant, out-of-order events and contradictory amounts; never settle from a browser return, unverifiable callback or redirect.
- Idempotent, transaction-safe settlement: one capture per provider event, prevent overpayment/double capture; order paid only after VERIFIED settlement (or directly recorded in-person cash).
- Provider refunds are async/pending until verified by provider; no local-only fake completed provider refunds. Manual/cash refunds require separately authorized explicit approval and auditable payout evidence; reconcile refunds, partial payments and chargebacks correctly.
- Reconciliation by currency AND merchant, with discrepancy queue, raw redacted webhook audit, retry-safe resolution. Separate ledgers for (1) product orders, (2) consultations, (3) tenant LBA AI credits, (4) patient SINA usage/wallet, (5) platform subscriptions; connect only approved domains.
- Update old `RefundService` with tests proving cash vs remote refund semantics. Check double webhook, spoof, cross-tenant, currency mismatch, provider network timeout, duplicate checkout, refund after fulfillment, simultaneous stock and payment actions.

## WS8.D — Operational reporting, permissions and UAT

- Clinic dashboards and POS for own authorized tenant/branch only; Principal sees aggregate legally permitted billing, not clinical records.
- Five human categories unchanged: Principal, Clinic Admin, Doctor/Medical Staff, Staff (commerce/marketing/reception), Patient. Commerce role must not grant LBA reviewer or clinical data access.
- Real DB-driven revenue/orders/inventory metrics; no dummy numbers. No arbitrary foreign tenant id in Livewire or webhook body.
- Validate product cash and each provider SANDBOX flow with fake provider server/signed fixtures; no real charge performed by automated tests.
- Negative coverage: mixed tenant/brand/merchant, cross-patient order, price tampering, stock race, double-click checkout, stale webhook, over-refund, currency mismatch, delayed/missing provider response, credential leaks, privilege escalation.
- PHPUnit full suite + focused suites, Pint on touched files, `php artisan lba:localization:audit --strict` id/en/ms-MY; docs/UAT and non-destructive staging rollback.
- No `migrate:fresh`, no real data seeds, no production payment processing, no APP_KEY reset, and no overwrite/renaming of existing `Babul_Jakarta` tenant.
- Provide safe staging acceptance checklist and explicit backout plan before any deployment, but DO NOT deploy from TRAE.

## Delivery process

1. Checkout and pull **existing** `feature/v3-ws8-commerce-payments` (created with this spec). Confirm latest WS7 parent and clean working tree. Read docs and current code, write an audit/gap matrix first.
2. Implement WS8.A; focused tests; commit and push.
3. Implement WS8.B; focused tests; commit and push.
4. Implement WS8.C; focused + negative security/integration tests; commit and push.
5. Implement WS8.D; full tests/Pint/localization, UAT notes; commit and push.
6. Create **ONE Draft PR** targeting `feature/v3-ws7-practitioner-operations`, not `main`, while its parent is unmerged. Later retarget after upstream merges and revalidate full diff.
7. Report A/B/C/D commit SHAs, actual tests, scope and outstanding blockers. Do not declare any provider production-ready without real sandbox acceptance and merchant verification.

**Review gate:** WS6 PR #33 and WS7 PR #34 are both currently open Draft PRs and remain dependencies. Do not force-merge or push WS8 changes onto older branches to bypass review.
