# WS6 Addendum — Password Security, Five User Groups, Staging Handoff

Status: implementation requirements added by Product Owner, 2026-11-12.
Branch: `feature/v3-ws6-lba-capture`. Preserve `docs/WS6_LBA_CAPTURE_SPEC.md` and build WS6 camera functionality as planned.

## A. Password management (WS6.D)

1. Principal Super Admin can change THEIR OWN password from a real authenticated account/profile page in Principal. Require the current password, new password + confirmation, Laravel password validation, hashed storage, rate limiting, CSRF protection, security audit entry and session regeneration/invalidation of other sessions where supported. Never display, log, email or return a plaintext password. Preserve the existing `APP_KEY` and central `User` identity.
2. Authorized Principal Super Admin may start a password RESET for another human user through a short-lived, one-time, hashed recovery token delivered to the verified account email (or an approved recovery channel). The Super Admin MUST NOT learn or set the user's new password, must not take over the target's existing session, and must never repurpose a staff-invitation token as a password-reset token. Require an explicit permission check and audit for both initiate/redeem. Prefer existing Laravel password broker if suitable; adapt to both Principal/Clinic/Patient identities securely.
3. Ordinary Principal, Clinic staff and Patient accounts may change their OWN password only when authenticated and current password is verified. Support self-recovery as a separate verified flow, as appropriate. Throttle requests; handle expired/replayed tokens; revoke sessions/tokens according to approved policy.
4. Add negative tests for wrong current password, self-service cross-user ID, revoked/suspended users, missing privileges, expired/replayed reset tokens, CSRF, and simultaneous reset requests. No default passwords, hard-coded credentials or plaintext tokens in logs.

## B. Five primary user categories in the UI (WS6.E)

The Product Owner expects exactly FIVE understandable categories in user-management screens and onboarding choices. These are DISPLAY/ONBOARDING CATEGORIES, NOT a replacement of the existing canonical `RoleKey` / `RbacCatalog` permission architecture:

| UI category | Panel | Underlying existing role(s) | Safety boundary |
|---|---|---|---|
| 1. Super Admin Principal | Principal | `principal_super_admin` | Platform governance only; NOT blanket clinical PHI access |
| 2. Admin Klinik | Clinic | `tenant_admin` (tenant_owner separately when explicitly authorized) | Own tenant and permitted branches |
| 3. Dokter / Medical Staff | Clinic | `consultant`, `lba_operator`, `reviewer` as independently assigned capabilities | Clinical work only when properly authorized; no self-approval |
| 4. Staff (Marketing / Commerce / etc.) | Clinic | `commerce_manager`, `receptionist`, `scheduler`, `location_event_coordinator`, `tenant_finance`, `branch_manager`; add scoped `marketing_staff` ONLY if an audited real permission need exists | No implicit LBA-review, clinical-record or cross-tenant permissions; marketing must honor consent |
| 5. Pasien | Patient | `patient_customer` | Own linked clinic patient record only |

Implementation requirements:
- One identity may have multiple narrow roles within a single authorized tenant, but panel routing and membership/branch checks stay fail-closed. Platform identities never become clinical users simply by navigating to `/clinic`.
- Use grouped role picker/presets for onboarding; explicitly show each subrole and its permissions, not ambiguous catchall grants. `Dokter` is a profession: verify approved practitioner credentials/authorization before any role needing qualified clinical activity; do NOT claim qualification solely from `consultant`.
- On creation/invitation, bind the proper panel role and central `TenantMembership` (and actual branch assignment when required). Patient uses existing `PatientAccountLinker` / patient portal flow; do NOT use staff invitations for patients.
- Any new marketing permission must be minimal, tenant-scoped, consent-aware, tested and updated across `RoleKey`, `PanelAccessPolicy`, `RbacCatalog`, `RbacSeeder`, translations, tests and docs. No unnecessary new role if existing permission scopes suffice.
- UI must show the five groups clearly on Principal > Users/Staff. Clinic Admin sees only their tenant's authorized staff groups, not Principal role provisioning.
- Tests cover each category's login landing page, access to allowed resources, rejection from other panels, suspended membership, cross-tenant ID, branch boundaries, patient data privacy, role escalation and independent approval.

## C. Staging pull and deployment (WS6.F)

- IMPORTANT: WS6 is under development; DO NOT deploy the WS6 branch to shared staging by default. Merge its reviewed PR first; until then deploy only approved `origin/main` (PR #32 is merged).
- Existing staging host: `/home/lbacloud/lba-cloud-app`, `https://dev.lbacloud.com`, central DB `lbacloud_central`, existing active tenant slug `Babul_Jakarta`. Never recreate or rename the existing tenant.
- Existing deployment script `deploy-staging.sh` refuses `APP_ENV=production`. cPanel staging may intentionally use `APP_ENV=production` to disable risky local-only features; provide a secure explicit staging-target attestation independent of `APP_ENV` (hostname/site environment plus required operator approval), rather than telling operators to weaken `APP_ENV` to `local` or disable guards. Must refuse true production.
- Check prior Filament cache issues before calling `php artisan optimize`; when unsafe, use selective `optimize:clear`, `config:clear`, `route:clear`, `view:clear` and Filament asset publishing as appropriate.
- Backup BOTH central and registered tenant DBs, encryption keys and user-uploaded private media as applicable (securely; secrets must not enter Git). The current script only backs up the central DB. Add safe backup validation, dry run, fail-closed abort, and restoration documentation.
- Pull latest approved main via non-destructive `git fetch` / `git pull --ff-only` or immutable release SHA, never force-reset local changes. Run Composer, additive central migration, safe per-registered-tenant migration, assets, health checks, and verify `/principal`, `/clinic`, `/patient` with independently authorized accounts.
- Review cPanel UAPI adapter configuration and credentials, ensure tenant database prefix and runtime tenant credentials are correct. Never grant global CREATE, never recreate data, never change APP_KEY; exclude real secrets/PII from logs.
- Add an operator runbook specific to current host, with rollback and required preflight checks; deployment itself must NOT happen automatically from TRAE.

## Deliverable checkpoints

1. WS6.A/B/C microscope capture work as defined in original spec; commit/push per component.
2. WS6.D change-password and controlled reset implementation with tests; commit/push.
3. WS6.E five-category user onboarding/UI mapping + per-role tests; commit/push.
4. WS6.F safe staging deployment script improvements and runbook; commit/push.
5. ONE Draft PR from existing WS6 branch to main; no merge/deployment; full focused tests, Pint and id/en/ms-MY localization parity.
