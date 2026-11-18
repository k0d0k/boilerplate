# TRAE Cloud Development, Backup and Recovery Runbook

This project had a prior TRAE Work Cloud session that became unresponsive, after which the Files panel no longer showed the earlier workspace. Treat new TRAE tasks as **potentially separate workspaces**; do not assume a task inherits local files or commits. Recovery ticket remains independent from new development. Do not delete the old session.

## New task setup

1. Download this baseline ZIP **outside** TRAE and keep an extra copy.
2. Create a **new TRAE Work task** (not a resume of the frozen task). Upload extracted Markdown/docs to that task's **Files / workspace** area. Chat attachment picker may accept images only; upload to workspace/file tree instead. Maintain folder structure.
3. Verify `README_START_HERE.md`, `LBA_CLOUD_ARCHITECTURE.md`, `docs/DECISIONS.md` and `docs/IMPLEMENTATION_PLAN.md` can be opened in TRAE Files.
4. Send `TRAE_NEW_TASK_START.md` as a **short chat command**; do not ask TRAE to regenerate docs or install immediately.
5. After inspection succeeds, send `TRAE_PHASE1A_PROMPT.md` for controlled coding.

## Checkpoint after every phase

- Create ZIP source + docs + lockfiles **excluding** `.env`, tokens, `vendor/`, `node_modules/`, PHI, or secret assets.
- Download archive to the user's own computer and inspect it; keep names like `lba-cloud-phase-1a-yyyy-mm-dd.zip`.
- Confirm the phase's key screenshots/tests are part of the checkpoint.
- Once authorized, upload/push to **private** `viacom-id/lba-cloud`; backup **before** risky install/migration. Technical direction: from TRAE to GitHub is a **push**; bringing GitHub changes into TRAE is **pull**.
- Do not overwrite `main` or existing history without a reviewed branch/PR.

## Incident response

If task freezes: avoid repeated Stop, preserve task URL/session ID/screenshots, inspect Files via separate tab, export intact artifacts, then file support ticket. Do **not** start an installer against an unverified or empty workspace; fail closed. Recovery from original session may still be possible independently.

---

# LBA Cloud v3.0 — Staging Deployment, Diagnostics and Rollback (WS5)

Operator runbook for deploying LBA Cloud v3.0 to **staging**. It is deliberately
read-only-first and never provisions or modifies a production database.

## Compatibility

- Laravel **13.40** / Filament **5** / Livewire **4** / `nwidart/laravel-modules` **13**.
- PHP **8.3+**. Constraints are pinned in `composer.json`
  (`laravel/framework ^13.17`, `filament/filament ^5.0`, `nwidart/laravel-modules ^13`).

## Database model

- **Central database** = registry only: identity, brands, tenants, memberships,
  RBAC catalog, module registry, subscriptions, payment intents and AI wallets.
- **Each tenant** owns its **isolated clinical database** (all of that tenant's
  branches share it). Its name is derived from the tenant ULID; the central
  `tenant_databases` row records readiness.
- The Clinic panel **fails closed** (HTTP 503, `lba.tenant_guard.database_unavailable`)
  until the tenant's database row is `migrated`.

## Pre-flight (read-only)

```sh
php artisan lba:staging:health          # read-only; add --json for machines
php artisan lba:localization:audit      # id / en / ms-MY catalogs must match
php artisan migrate:status              # central migrations
php artisan module:migrate-status       # module migrations
```

`lba:staging:health` never opens a tenant clinical connection and never prints
credentials, identities or patient data. It exits non-zero when any check FAILs.

## Deployment (staging)

The supported path is the audited script [`deploy-staging.sh`](../deploy-staging.sh).
The full, host-specific procedure (attestation, backups, verification and
rollback) is in the operator runbook
[`WS6_STAGING_RUNBOOK.md`](WS6_STAGING_RUNBOOK.md).

```sh
./deploy-staging.sh --dry-run --confirm-staging --attest-host "$(hostname -f)"   # preview
./deploy-staging.sh --confirm-staging --attest-host "$(hostname -f)" --ref origin/main
```

It runs preflight, an explicit staging-target attestation, a read-only backup
(`lba:staging:backup`: central + registered tenant databases, `APP_KEY` and
private media), a non-destructive pull of an immutable SHA, then
`composer install`, central `migrate --force`, `lba:tenant:migrate:registered`,
`npm ci && npm run build`, a cache strategy and `lba:staging:health`, followed by
a `/principal` `/clinic` `/patient` reachability check.

Because the cPanel staging host may intentionally run `APP_ENV=production`, the
script does **not** key off `APP_ENV`: it requires `--confirm-staging` plus a
`--attest-host` that matches the machine, and hard-refuses any hostname matching
the production denylist. It never takes a destructive schema action, never
modifies `APP_KEY`, never force-resets local changes, and stops safely on the
first failure. Every run is written to `storage/logs/deploy-staging.log`.

Caches are cleared **selectively** (`optimize:clear` / `config:clear` /
`route:clear` / `view:clear` + `filament:assets`) by default; full
`php artisan optimize` is opt-in (`--optimize`) because it can break Filament on
some cPanel/LiteSpeed hosts.

The equivalent manual steps are:

1. **Back up first:** `php artisan lba:staging:backup --dir=storage/app/deploy-backups`
   (central + registered tenant databases, `APP_KEY`, private media).
2. `git fetch && git checkout <immutable-SHA>` — pin an exact release (never a
   forced reset).
3. `composer install --no-dev --optimize-autoloader`.
4. **Central migrations:** `php artisan migrate --force`.
5. **Tenant databases** (do not recreate existing partners — provisioning is
   idempotent and re-runnable):
   - New / to-activate partner: `php artisan lba:clinic:setup --tenant=<slug>`
     (provisions + migrates the isolated DB, records readiness, activates modules).
   - Existing partner: `php artisan lba:tenant:migrate:registered` only.
6. `npm ci && npm run build`.
7. Cache safely: `php artisan optimize:clear` + `config:clear` + `route:clear` +
   `view:clear` + `filament:assets` (prefer the selective path over
   `php artisan optimize`, which can break Filament on some hosts).
8. Re-check `php artisan lba:staging:health`; reload queue workers and OPcache.

## Provisioning security posture

- **Tenant isolation is unchanged:** the central database is a registry only;
  every tenant keeps its own isolated clinical database derived from the tenant
  ULID. No provisioning step ever reads another tenant's database.
- **Separated privileges:** the Laravel runtime credential never holds global
  `CREATE`; database creation and per-database grants are performed server-side
  through the cPanel UAPI adapter with a per-account token. **No root/WHM
  credentials are ever stored in Laravel.**
- **Every provisioning operation is audited** to the central, redacted
  `module_audit_logs` (`platform.tenancy` / `provision_database`, and the
  per-tenant module `activate` rows). No credential or clinical payload is logged.
- **Concurrency-safe:** one-click setup holds a per-tenant lock; a concurrent
  request fails closed (`concurrent_setup`) instead of racing.
- **Non-destructive by design:** a failed step records a `failed` readiness state
  (fail-closed gate); nothing is dropped, no destructive rollback is performed and
  a retry converges idempotently.
- **RBAC:** setup and the Principal surfaces require the tenant-appropriate
  principal roles/permissions; clinic scoping remains enforced by the tenant guard.

## cPanel / LiteSpeed validation

- Document root **must** point at the app's `public/` directory (never the repo root).
- Required PHP extensions: `pdo_mysql`, `mbstring`, `openssl`, `tokenizer`, `xml`,
  `ctype`, `json`, `bcmath`, `gd` (PDF rendering), `fileinfo`.
- Production uses MySQL/MariaDB for both central and tenant databases; the SQLite
  tenant fallback is **local/testing only**.
- `.env`: `APP_ENV=production`, `APP_DEBUG=false`, a valid `APP_KEY`; `storage/`
  and `bootstrap/cache/` writable; run `php artisan storage:link` once.
- **Do not** provision or modify production databases automatically — every step
  above is explicit and per-tenant.

## Rollback

See [`WS6_STAGING_RUNBOOK.md`](WS6_STAGING_RUNBOOK.md) §5 for the full,
host-specific procedure (including restore commands). Summary:

1. **Code:** `git checkout --detach <previous-good-SHA>` → `composer install` → `npm ci && npm run build`.
2. **Central DB:** `php artisan migrate:rollback --step=1 --force` for reversible
   migrations, otherwise restore the pre-deploy dump (`<dir>/central-<stamp>.sql`).
3. **Tenant DBs:** restore each tenant from its own pre-deploy dump
   (`<dir>/tenants/<physical>-<stamp>.sql`; never `migrate:fresh`, never recreate).
4. `php artisan optimize:clear`, then confirm `php artisan lba:staging:health`.

## Guardrails

- Payments stay **sandbox-only** until manually approved
  (`config/payments.php` → `live_enabled = false`).
- **No** automatic AI diagnosis and **no** automatic clinical-report release.
- No automatic production database provisioning; `lba:tenant:drop` refuses to run
  outside local/testing.
- Principal Super Admin credentials are never changed by a deploy.

