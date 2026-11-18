# LBA Cloud v3.0 — WS7: Practitioner & Clinic Operations (Implementation Specification)

**Product Owner approved direction:** Continue coding after WS6.1; no duplication of existing Patients, Appointments, Consultations, LBA, branch tenancy or role systems.

**Repository:** `viacom-id/lba-cloud`
**Existing WS7 branch:** `feature/v3-ws7-practitioner-operations`
**Dependency:** based on WS6 PR #33 head `1271310`; WS7 is **stacked on unmerged WS6**. Create a Draft PR **targeting `feature/v3-ws6-lba-capture`**, not `main`, until WS6 merges; retarget to main after WS6 merges. Do not deploy either branch automatically.

## Product scope

One clinic operates permanent branches and temporary/event locations. Users belong to one of five understandable categories (Principal, Clinic Admin, Doctor/Medical Staff, Staff, Patient) with **existing granular roles**. WS7 adds verified practitioner identity, branch assignments, professional credentials and practice scheduling, then integrates it into already working appointment booking, consultations and LBA examinations. Patients see only bookable, appropriately authorized practitioners/slots. No auto-approval or clinical diagnosis.

### Architecture invariants

- Laravel 13, Filament 5, Livewire 4, existing navy/teal theme. Consistent left-sidebar navigation; mobile responsive.
- Central DB holds `User`, `TenantMembership`, `RoleKey`, `RbacCatalog`, `PrincipalRoleAssignment`; clinical/practitioner operations remain in each tenant's isolated database.
- A practitioner refers to a central user by opaque `user_id` (no cross-database FK); never replicate central passwords or identities.
- Tenant/branch context is server-verified, deny by default, across HTTP/Livewire/API/queue. No client-provided tenant DB selector.
- Reuse `TenantBranch`, `BranchStaffAssignment`, `Appointment.staff_user_id`, `AppointmentScheduler`, `BranchAvailability`, `Consultation.staff_user_id`, `Examination.operator_user_id`, LBA reviewer and private storage. No duplicate booking or patient record systems.
- Existing tenant `Babul_Jakarta` must not be renamed/recreated and no production data is seeded or reset.

## Phase WS7.A — Practitioner registry & professional authorization

1. Create a modular `Modules/Practitioners` (or existing compatible module convention) with tenant-owned practitioner profile, specialities/competencies, verified branch scope, status (pending/verified/suspended/inactive), and private credentials/license metadata. Avoid collecting unnecessary sensitive data. Store supporting documents on private disk; no public URLs, no raw licenses in logs.
2. A practitioner must first be an **active, authorized clinic membership** with the appropriate existing clinical role(s). Being listed as `medical_staff` / `consultant` / `reviewer` **does not itself attest professional qualifications**. Require an explicit, audited credential-verification operation by authorized clinic personnel, with verification actor, timestamp, expiry, jurisdiction/credential type and optional private evidence. Never auto-verify.
3. Suspension, expiry, membership revocation or loss of branch assignment immediately blocks new patient-facing practitioner bookings and qualified clinical actions, while preserving historical appointment/consultation/LBA records.
4. Enforce separation of duties: operator cannot approve own examination; only an independently authorized, verified and non-expired reviewer may exercise qualified approval. Avoid expanding principal admin to routine clinical records.
5. Idempotent tenant migrations, useful indexes, non-destructive archival, private evidence stream and explicit policies. Do not add global platform permissions if not required; add narrow practitioner permissions if necessary and update `PermissionKey`, `RbacCatalog`, `RbacSeeder`, policies, tests, translations, docs together.
6. Filament Clinic Admin/medical roster real CRUD/profile, verification workflow, permissions and branch assignment; show five-category labels without creating a new broad `Doctor` privilege. Principal governance can see only appropriate aggregate/status metadata, never medical documents by default.

## Phase WS7.B — Working hours, availability & leave

1. Tenant-local practice schedule per practitioner + branch: weekday recurrences, local timezone, slot length/service type, effective date range; exceptions for leave/holiday/cancelled session and branch/event operating windows. Validate overlapping rules and daylight-saving transitions where relevant.
2. Integrate practitioner-eligible slots with `BranchAvailability` and current `AppointmentScheduler`. A slot is bookable only when tenant, practitioner membership/role, verification, branch assignment, schedule, location and branch capacity all allow it.
3. Prevent double-booking and capacity races **at transaction/locking level**, not only a pre-submit query. Handle simultaneous booking on MySQL and idempotent retries; no silently confirmed booking without capacity. Preserve legacy unassigned bookings and existing booking statuses unless an explicit opt-in rule applies.
4. Expose patient-facing choice of location → appropriate service → verified practitioner (where required) → date/time, with correct availability and clear fallback for services that do not require a named clinician. Do not leak personal employee details, contact information, licenses, clinical notes or unpublished schedules.

## Phase WS7.C — Consultation and LBA links

1. Support checked-in appointments handed into an authorized consultation and/or an LBA examination using existing foreign keys and workflows, without creating duplicate cases.
2. Assign only an active eligible practitioner for `Consultation.staff_user_id` and an authorized operator for `Examination.operator_user_id`; avoid breaking historical records on later suspension.
3. Reviewer approval paths must verify current human authority/credentials and separation of duties; AI (SINA chat or LBA vision) cannot sign or publish reports.
4. Display workload/queue from actual tenant DB tables: upcoming appointments, today's sessions, pending examinations/reviews, completed consultations. No fake/demo statistics.

## Phase WS7.D — UI, integration QA and staging handoff

1. Clinic: practitioners, credentials, branch practice schedule, leave, today's queue and assignment interface; nav real route-backed and permission gated, following existing design. Patient: improved clinician/slot selection only for services needing it.
2. Add complete tenant/branch/role negative tests: Principal cannot read clinical docs; staff marketing cannot assign doctors or review LBA; credential revoked/expired/suspended blocks future booking; patient A never sees B; Clinic A never sees B; unauthorized upload/image access denied.
3. Specific concurrency tests: two patients selecting same final practitioner slot; two requests racing on branch/event capacity; rescheduling; time-off boundary; after-revoke/after-expiry; timezone boundaries.
4. Test integration with WS6 camera import where an examination's operator/practitioner account and selected branch must be authorized; don't change safe image upload behavior.
5. Localization parity (id/en/ms-MY), Pint on changed files, targeted PHP unit/feature tests, full PHPUnit suite with test-only memory limit from WS6.1. No defaults/demo clinical data, no destructive migrations. Include real staging UAT checklist and secure rollback notes. No direct staging changes from TRAE.

## Implementation order, commits and acceptance

1. **WS7.A**: database/models/services/permissions/credential verification with tests → commit & push.
2. **WS7.B**: schedule, capacity-safe booking and patient slot visibility with tests → commit & push.
3. **WS7.C**: consultation/LBA linkage and qualified-review enforcement → commit & push.
4. **WS7.D**: Filament + patient UI polish, negative tests, localization, deployment/runbook → commit & push.

All commits to `feature/v3-ws7-practitioner-operations`; create exactly **one Draft PR** targeting the parent WS6 branch until PR #33 merges. No merge, no automatic hosting deployment, no production database operations. At each checkpoint report SHA, changed areas, tests, blocking assumptions and whether it is safely reversible.

**Known dependencies:** WS6 #33 is still Draft/open at the time of this specification; credential data and booking behavior require real clinic operator validation. Credential verification is not medical-licensing certification by the platform—record and enforce human-reviewed proof, do not claim unverified individuals are licensed clinicians.

## Phase WS7.D — staging UAT checklist & rollback notes

WS7.D ships the operational UI (Clinic practitioner roster / credentials / branch schedule / leave / today's queue and assignment surface; the Clinic operational workload dashboard; the Patient clinician + slot picker) on top of a green feature suite, but it is **never merged or deployed automatically**. The generic host procedure, target facts and backup/restore mechanics live in [WS6_STAGING_RUNBOOK.md](WS6_STAGING_RUNBOOK.md) and [OPERATIONS_RECOVERY.md](OPERATIONS_RECOVERY.md); the items below are the WS7-specific acceptance checks an operator runs on staging **after** a reviewed WS7 merge, and the WS7-specific rollback notes.

### D.1 Preconditions (read-only, before any UAT)

- WS7 is merged into the approved deploy revision (`main`) and that immutable SHA is checked out; the tree is clean.
- `php artisan lba:localization:audit` (and `--strict`) exit `0` for `id` / `en` / `ms-MY`.
- `php artisan lba:staging:health` exits `0`.
- Central + tenant migrations are additive; the `Babul_Jakarta` tenant is present and untouched.
- No demo/seed clinical data exists in staging; UAT uses real operator-created identities and **synthetic** patients only.

### D.2 Clinic-operator UAT (independent accounts, per real role)

| # | Actor | Action | Expected |
|---|---|---|---|
| 1 | Clinic Admin (`practitioner.profile.manage`) | Register a clinical member as a practitioner | Profile created `pending`; listed on the practitioners page; no auto-verification |
| 2 | Clinic Admin (`practitioner.credential.verify`) | Verify the practitioner + attach/verify a credential | Status `verified`; masked reference shown; audit entry with actor + timestamp |
| 3 | Clinic Admin | Authorize the practitioner to one branch, then to a second | Bookable only at authorized branch(es) |
| 4 | Clinic Admin | Add a weekly window and a leave/day exception | Window `active`; exception recorded `leave`; slot list reflects both |
| 5 | Clinic Admin | Suspend, then reactivate the practitioner | Suspension immediately removes them from patient-bookable slots; history retained |
| 6 | Receptionist (`booking.manage`) | Check in an appointment and hand it to a consultation / LBA examination | Intake action offered; examination/consultation operator must be an authorized practitioner |
| 7 | Clinic operational dashboard | Open `/clinic` | Workload figures read from the tenant DB (no fake numbers); honest `—` empty state when data is absent |
| 8 | Principal Super Admin | Attempt to open a clinical practitioner/clinical record surface | Forbidden — Principal sees only aggregate/status metadata, never medical documents |

### D.3 Patient UAT (one patient account per record)

| # | Action | Expected |
|---|---|---|
| 1 | Open the patient booking page for a service that needs a clinician | Verified, branch-authorized, in-schedule clinicians offered; timezone-correct slots shown |
| 2 | Choose a clinician and book a free slot | One confirmed appointment; slot disappears for other patients |
| 3 | Race two patients on the same final slot | Exactly one booking is confirmed; the loser is refused (no silent double-booking) |
| 4 | Choose a suspended / lapsed / de-authorized clinician | Not offered; a forged id is refused |
| 5 | Services that do **not** require a named clinician | The clinician picker is hidden and the legacy "clinic assigns" path still works |
| 6 | Open another patient's booking/record by id | Not found / forbidden — patient A never sees patient B |

### D.4 Tenant / branch isolation & negative RBAC (spot checks)

- Clinic A practitioner/branch/schedule never appears in Clinic B, and a Clinic B reservation can never target Clinic A's rows (verified tenant transaction, fail-closed).
- A marketing/commerce or other non-clinical role cannot assign a doctor to a branch nor take any LBA review decision.
- A clinical image stream is denied to identities without `exam.read` (including a Principal, who holds no tenant context) and to any cross-clinic request.
- A revoked credential, an expired verification or a revoked branch authorization blocks **future** booking while every historical appointment / consultation / examination is retained.

### D.5 Rollback notes (WS7-specific)

The WS7 code is additive and no WS7 change is destructive, so rollback is always the generic, operator-initiated procedure from [WS6_STAGING_RUNBOOK.md](WS6_STAGING_RUNBOOK.md) §5:

1. **Code** — `git checkout --detach <previous-good-SHA>`, reinstall, rebuild, clear caches, re-run `lba:staging:health`.
2. **Databases** — WS7 adds **no destructive migration**: prefer `migrate:rollback --step=1 --force` for any reversible WS7 migration; otherwise restore the pre-deploy dump of the affected database only.
3. **Never** `migrate:fresh`, never recreate/rename/drop `Babul_Jakarta` or any tenant DB, never change `APP_KEY`.
4. **Permissions/seeds** — the WS7 `PermissionKey`/`RbacCatalog`/`RbacSeeder` additions are additive; rolling back the code without rolling back the (additive) permission rows is safe, and no existing role grant is weakened.
5. **Verify** — after rollback re-check `lba:localization:audit`, `lba:staging:health` and authenticated scoping of `/principal` `/clinic` `/patient` before informing the team.

Reversibility: each WS7 checkpoint (A–D) is independently revertible because it only adds migrations, permissions, services, Filament surfaces and tests; no existing Principal credential, tenant or clinical record is modified.
