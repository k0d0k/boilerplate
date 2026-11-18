# Roles, Panel Access, Tenant and Branch Authorization

**Principle:** deny by default. UI hiding never substitutes backend policy. See the [v3.1 TailAdmin and Principal-only CRUD Generator addendum](ARCHITECTURE_ADDENDUM_V3_1_TAILADMIN_CRUD_GENERATOR.md) for the generator and staff UI transition.

## Actors

| Role | Panel | Allowed boundary |
|---|---|---|
| `principal_super_admin` | Principal | Brand/tenant/plan/module governance and Principal-only CRUD Generator; **no routine clinical records** |
| `principal_module_admin` | Principal | Approved module release, entitlements, audit |
| `principal_finance` | Principal | Subscriptions/billing aggregates; no clinical records |
| `brand_manager` | Principal (scoped) or delegated clinic | Only permitted brand/campaign/catalog metadata |
| `tenant_owner` | Clinic | Own tenant + authorized branches; may manage tenant billing |
| `tenant_admin` | Clinic | Own tenant operations, delegated billing rights |
| `branch_manager` | Clinic | Assigned branch(es) only |
| `receptionist`, `scheduler` | Clinic | Registration, booking/check-in, assigned branch |
| `location_event_coordinator` | Clinic | Event venue, session and capacity within tenant |
| `lba_operator` | Clinic | Create exams/upload/manual notes/request AI within scope |
| `reviewer` | Clinic | Qualified approval/publish of authorized exams; no default self-approval |
| `consultant` | Clinic | Own verified consultation sessions and permitted records |
| `commerce_manager` | Clinic | Own catalog, orders, stock/fulfillment |
| `tenant_finance` | Clinic | Own tenant charges/refunds; no unrestricted clinical detail |
| `patient_customer` | Patient | Self-owned bookings/reports/orders/wallet/conversations |
| `ai_service` | None | Machine-only opaque job; no credentials to either DB |

## Five user categories (WS6.E — UI / onboarding)

The user-management and onboarding screens present **exactly five**
understandable categories. These are a DISPLAY / ONBOARDING layer over the
canonical roles above — they never replace or widen the granular RBAC. The
class `App\Platform\Access\UserCategory` is the single source of the mapping.

| UI category | Panel | Underlying role(s) | Safety boundary |
|---|---|---|---|
| 1. Super Admin Principal | Principal | `principal_super_admin`, `principal_module_admin`, `principal_finance`, `brand_manager` | Platform governance only; **not** blanket clinical PHI access |
| 2. Clinic Admin | Clinic | `tenant_admin`, `tenant_owner` (owner only when explicitly authorized) | Own tenant and permitted branches |
| 3. Doctor / Medical Staff | Clinic | `consultant`, `lba_operator`, `reviewer` (each independently assigned) | Clinical work only when properly authorized; no self-approval; verify practitioner credentials first |
| 4. Staff (Marketing / Commerce / Reception / …) | Clinic | `branch_manager`, `receptionist`, `scheduler`, `location_event_coordinator`, `commerce_manager`, `tenant_finance` | No implicit LBA-review, clinical-record or cross-tenant permission; marketing must honour consent |
| 5. Patient | Patient | `patient_customer` | Own linked patient record only |

- `ai_service` is machine-only and belongs to no human category.
- The onboarding role picker is grouped by these five categories and shows each
  subrole together with the permissions it grants — no ambiguous catchall grant.
- Clinic Admin sees only its own tenant's staff groups (read-only roster); role
  provisioning stays a Principal capability.

## CRUD Generator — Principal Super Admin only

- The server requires an active account and an active assignment of the exact `principal_super_admin` role for the generator page, preview and export.
- Other Principal roles, Clinic users and Patient users are denied, including users grouped under the same onboarding label. The allowlist covers eligible central master data.
- Generated screens enforce their own resource policies. Master Brand uses `platform.brand.manage` independently of generator access.

## Resource permissions

| Resource | Principal Admin | Tenant Admin | Operator | Reviewer | Receptionist | Patient |
|---|---|---|---|---|---|---|
| Tenant/plan/brand | Manage metadata | Own read | — | — | — | — |
| Module release/feature gate | Manage | Own permitted feature view | — | — | — | — |
| Tenant patient profile | **No** | Own scope | Scoped read | Exam-related read | Scoped CRUD | Self-only when authorized |
| Exam + image upload | **No** | Own scope | Create/edit before review | Read/review | — | Approved reports only |
| AI job start | Usage analytics only | Own scope | Start if allowed | May request per policy | — | — |
| Manual annotation | **No** | Own scope | Create/edit own exam | Correct in review | — | — |
| Report approve/publish | **No** | By qualified reviewer role only | **No** | **Yes**, not self by default | — | Read published own |
| Booking/location/session | Aggregate no PII | Own scope | Schedule view | Schedule view | Manage assigned | Own bookings |
| Practitioner registry/profile | **No** | Own scope | — | — | Read-only (scheduling) | — |
| Practitioner credential verify | **No** | Own scope, **not self** | **No** | **No** | **No** | **No** |
| Human/SINA consultation | Aggregate no PII | Authorized management | — | — | Scheduling only | Own sessions |
| Product orders/payment | Aggregates | Own tenant | — | — | Limited checkout | Own orders |
| Patient wallet | Aggregate permitted billing | Authorized finance only | — | — | — | Own wallet |

## Practitioner permissions (WS7.A)

Practitioner identity, credentials and branch scope are **tenant-local** (they
live only in the tenant database) and gated by three granular permission keys.
The registry service (`Modules\Practitioners\Services\PractitionerRegistry`) is
the single write chokepoint: no Filament page, Livewire action or relation
manager writes around it.

| Permission key | Binding (RBAC catalog) | Notes |
|---|---|---|
| `practitioner.profile.read` | `tenant_owner`, `tenant_admin`, `branch_manager` | Branch-scoped; never exposes credential reference numbers (always masked). |
| `practitioner.profile.manage` | `tenant_owner`, `tenant_admin` | Register/update/suspend/archive/branch-authorize. |
| `practitioner.credential.verify` | `tenant_owner`, `tenant_admin` | Explicit, audited verification; **self-verification refused** (`assertNotSelf`). Never automatic — no AI path. |

A practitioner is bookable (`isEligibleForBooking`) **only** when the record is
`verified`, unexpired, un-suspended, un-archived **and** the linked user still
holds an active clinical tenant membership. Suspension/expiry/archival/
de-membership immediately block new bookings while preserving history
(non-destructive: nothing is deleted).

## Mandatory negative tests

- Tenant A staff requests Tenant B patient/booking/object/report by ID → 403/404 without data leakage.
- Clinic user requests `/principal` → denied, including direct URL and Livewire calls.
- Other Principal roles, Clinic or Patient users, and suspended accounts or revoked assignments request any generator endpoint → denied.
- Authorized generator user previews a definition outside the central allowlist → denied. Brand resource access is checked independently.
- Principal user requests raw tenant medical image without special audited permission → denied.
- Patient X requests Patient Y records, receipts, wallet, SINA or consultation → denied.
- Suspended tenant, invalid domain/branch, revoked membership → fail closed.
- User without a module entitlement calls page/API/queued action → denied.
- Operator approves own exam → rejected unless future explicit qualified, reviewed exception policy; no exception in MVP.
- Queue worker rehydrates exact tenant context and never leaks to next job.
- Switching tenant/branch/brand rejects unauthorized IDs and invalidates stale context.

Test authorization on TailAdmin and Filament routes, custom pages, Livewire actions, APIs, files and jobs, including direct requests.
