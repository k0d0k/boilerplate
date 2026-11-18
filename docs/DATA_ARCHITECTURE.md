# Central and Tenant Database Architecture

## Core invariants

- One **central DB** stores principal, brands, tenants, domains, memberships, subscriptions, AI usage, module release registry and cross-entity finance.
- Each independent tenant has **its own clinical database**. Many branches within one tenant use that DB, with `branch_id` authorization.
- No central SQL join to tenant patient tables; application links by opaque scoped references.
- Separate central patient consultation wallet/ledger from tenant LBA AI wallet/ledger; no wallet cross-spending.
- Clinical records, raw images and consultation messages are tenant-owned; opaque billing indexes have no patient identity or raw image data.
- Patient identity is tenant-bound by default; no cross-tenant clinical record merge or global searchable health profile.

## Central database — proposed tables

| Area | Tables / sample keys |
|---|---|
| Identity | `users`, `principals`, `brands`, `tenants`, `tenant_brands`, `tenant_domains`, `tenant_memberships`, `plans`, `plan_features`, `subscriptions` |
| Billing | `wallets`, `wallet_ledger_entries`, `ai_usage_records`, `ai_jobs_billing_index`, `invoices`, `payment_intents`, `payment_transactions`, `payment_webhook_events`, `refunds`, `merchant_gateway_configs`, `idempotency_keys` |
| Commerce | `products`, `product_variants`, `product_prices`, `carts`, `orders`, `order_items`, `fulfillments` (all brand/merchant/customer scoped) |
| Patient wallet | `patient_wallets`, `patient_wallet_ledger_entries` (identity-ref only, scoped; independent from tenant AI wallet) |
| Modules | `module_registry`, `module_versions`, `module_dependencies`, `module_installations`, `module_entitlements`, `module_feature_flags`, `module_audit_logs` |
| Audit | `audit_logs` with minimal redacted metadata |

## Tenant database — proposed tables

| Area | Tables / sample keys |
|---|---|
| Clinic/staff | `branches`, `staff_profiles`, `staff_branch_assignments`, `consultants`, `consultant_availabilities` |
| Patient | `patients`, `patient_consents`, `appointments` (basic MVP), `tenant_audit_logs` |
| LBA | `lba_examinations`, `lba_images`, `lba_annotations`, `lba_analysis_jobs`, `lba_detections`, `lba_reviews`, `lba_report_versions` |
| Booking | `service_locations`, `location_events`, `examination_sessions`, `appointment_slots`, `slot_reservations`, `bookings`, `booking_status_events`, `waitlist_entries` |
| Consultation | `consultation_sessions`, `consultation_messages`, `consultation_usage` |
| Inventory | `inventory_stocks`, `inventory_movements` (per branch/variant) |

## Key modeling rules

### Patient and LBA

`patients.id` = tenant-local ULID; `clinic_patient_number` unique per tenant; `branch_id` records registration branch but **does not restrict clinical history to one branch** when access policy permits. `lba_examinations` links patient, branch, operator, consent, mode (`manual|ai|hybrid`) and state. `lba_images` immutable private object reference + checksum. `lba_annotations` link **exam_id and image_id directly**, with `job_id` nullable, `provenance` (`human|ai`), vector geometry, model metadata when applicable, revision/actor. A human annotation **must** exist without a job. `lba_report_versions` immutable append-only.

### Booking cardinality

`service_locations` are distinct from branch and may be fixed/pop-up/event/mobile. `location_events` statuses include `tentative|confirmed|cancelled`. `examination_sessions` declare capacity. `appointment_slots` have integer `capacity`, `booked_count`, `held_count`; **one slot -> many reservations -> many bookings** constrained by capacity. `slot_reservations` have their own `expires_at`, idempotency and state. Acquire/confirm under transaction/row lock; recover expired holds before allocating; concurrent test must not overbook.

### Wallet and billing correctness

`wallets` tracks `available_projection`, `reserved_projection` separately. `reserve`: move available→reserved **once**. `capture`: decrease reserved / finalize billable use **once**. `release`: restore reserved→available **once**. A unique key per tenant/job/action stops duplicates. Immutable ledger entries + projections must reconcile; no negative available balance. Money uses integer currency minor units, units in integer AI credits; never float.

### Merchant and wallet boundaries

Four distinct payment flows: (1) product purchase; (2) patient consultation/top-up; (3) tenant AI top-up; (4) SaaS subscription. All have separate ownership/reference types and settlement mappings. Central payments may be recorded centrally, but patient clinical/consultation content must remain tenant-side. Payment provider callback events are stored and verified, idempotent and redacted.

## Schema gates

Final migration names and constraints are decided in their implementing phase. For Phase 1A no clinical migrations or real records. For Phase 2 test tenant DB provisioning, migrations, restore, deleted/suspended tenant and queue context; every tenant-scoped query must be policy-enforced.
