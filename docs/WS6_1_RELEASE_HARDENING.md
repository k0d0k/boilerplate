# WS6.1 — Release Hardening & Real Microscope Import Agent

**Status:** follow-up implementation checkpoint for the *existing* WS6 Draft PR #33.
**Branch:** `feature/v3-ws6-lba-capture`, based on current head `c03e111`.
**Do not merge or deploy until these gates are independently verified.**

## Why this checkpoint exists

WS6 implemented browser capture, a secure server-side agent ingest endpoint, image origin metadata, password workflows, five role *display categories* and deployment tools. The GitHub change list contains no separately deployable Windows/macOS watched-folder application, so it must not be described as a complete end-to-end auto-import client. Also verify edge cases of the image duplicate path and large-backup behavior before release.

## P0 — Secure ingest across ALL paths

1. Review `Modules/LBA/Http/Controllers/IngestController.php` and `MicroscopyImageStore`. The early duplicate return must NOT bypass examination editability, currently granted image-processing consent, active/scoped device credential, branch/tenant access, image MIME/size/dimensions or module entitlement; return only minimum metadata permitted for the current operation.
2. Ensure input file is validated before checksum/duplicate lookup. Bound file read size and reject unsupported/incomplete images without storing or leaking contents.
3. Make duplicate/retry semantics race-safe: checksum + examination is idempotent; no silent overwrites, no duplicated immutable records on concurrent uploads. Introduce additive schema constraints when necessary, without destructive migrations and without silently deleting historical images.
4. Include tests: duplicate after review state, after consent withdrawal, expired/revoked credential, wrong branch, malicious MIME, over-limit file, concurrent identical requests and cross-tenant leakage.
5. Keep audit logs credential/PHI-safe and preserve manual non-AI workflow.

## P1 — Actual Windows/macOS export-folder companion

1. Implement a small portable agent in a dedicated `agents/lba-capture/` directory, preferably Python 3.11+ with minimal dependencies, and installable docs for Windows and macOS.
2. Watch a configured OptoEdu/manufacturer-software export directory for completed JPEG/PNG/WebP files. Support file stabilization (avoid reading half-written files), a bounded local spool, retry/backoff/offline recovery and idempotent per-examination upload.
3. Operator must explicitly select/bind **one authorized examination** through the current credential issuance flow. Secure short-lived/revocable credentials, never hard-code bearer tokens or put them into logs/config Git; use OS secure storage or a safe interactive provisioning mechanism. Do not accept a raw tenant/patient ID from file names.
4. UI/CLI: setup, status, start/stop, credentials/revoke instructions, useful error messages. Agent must not write to public directories, stream video, or sync clinical images to Google Drive by default.
5. No assumption that every OptoEdu model is UVC. The manual import fallback must always work.
6. Add agent unit/integration tests with a fake HTTP server and sample NON-PATIENT images; deliver packaged setup and usage instructions. No false hardware compatibility claims until the specific device has been tested.

## P2 — Large backup & staging safety

1. The current `StagingBackupCommand::mysqldump()` uses `Process::run(...)` then `$result->output()`, which may buffer an entire clinical SQL dump in PHP memory. Stream dumps to private files safely, without exposing credentials, and test with large generated output or a mock process.
2. Fail closed on partial dumps, full disk, missing tenant backup and inaccessible private media. Ensure backup artifacts are not inside a web-served directory, enforce secure file/directory permissions, validate restore instructions, and avoid printing secrets/PHI.
3. Verify deploy target by the expected exact `APP_URL=https://dev.lbacloud.com`, approved cPanel account and explicit staging attestation. Don't rely solely on an arbitrary host name's absence from a denylist.
4. Keep deploy read-only preflight, backups before migrations, immutable approved release, non-destructive rollback, no `migrate:fresh`, no `APP_KEY` change. Never deploy automatically from TRAE.

## P3 — Account / 5-category Clinic & Patient acceptance

1. Verify Principal own-password change, audited password-reset link, Clinic Admin, Medical Staff, Staff and Patient login journeys using distinct identities. Five categories are UI groupings; keep existing granular `RoleKey` and branch/tenant entitlements.
2. Ensure Clinic Admin can complete real invitation and activation, doctor/reviewer independent role assignment, patient account linking through patient flow, and errors when tenant database is not ready. Do NOT give Principal clinical access.
3. Confirm the existing tenant slug `Babul_Jakarta` and central DB `lbacloud_central`; no recreation/renaming or default/dummy credentials.
4. Validate that clinic/practitioner/marketing role groupings do not grant clinical authority by label alone. Medical practitioner qualification must be separately verified and audited before qualified review access.

## Execution & completion

- Read existing code and updated `docs/ROLE_PERMISSION_MATRIX.md` before editing.
- Fix P0 and commit/push. Implement P1 and commit/push. Fix P2 and commit/push. Test P3 and commit/push.
- Keep commits on the **same existing WS6 branch and PR #33**, no second PR and no merge/deploy without approval.
- Focused PHPUnit tests + id/en/ms-MY locale audit + Pint on touched files. If full-suite runner aborts, isolate root cause with a reproducible command and report it separately. Do not relabel an abort as a passing full suite.
- Report SHA per checkpoint, test counts, client packaging, deployment prerequisites and unresolved limitations.
