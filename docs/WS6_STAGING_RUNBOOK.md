# WS6.F — Staging Deployment & Rollback Runbook

Operator runbook for deploying LBA Cloud v3.0 to the **cPanel staging host**.
It complements [OPERATIONS_RECOVERY.md](OPERATIONS_RECOVERY.md) (architecture,
provisioning posture, guardrails) with the exact, host-specific procedure and a
tested rollback path.

> **Deployment is never automatic.** Nothing in TRAE or CI deploys to staging.
> Every deploy is an explicit, operator-initiated run of `deploy-staging.sh`.

> **WS6 is under active development.** Do **not** deploy the
> `feature/v3-ws6-lba-capture` branch to shared staging by default. Merge its
> reviewed PR into `main` first; until then deploy only approved `origin/main`.

---

## 1. Target host facts (do not deviate)

| Item | Value |
|---|---|
| App root | `/home/lbacloud/lba-cloud-app` |
| Public URL | `https://dev.lbacloud.com` (document root = the app's `public/`) |
| Central database | `lbacloud_central` (registry only) |
| Active tenant slug | `Babul_Jakarta` (its isolated clinical DB is derived from its ULID) |
| Approved revision | `origin/main` (or an immutable release SHA) |
| Runtime | Laravel 13 · Filament 5 · Livewire 4 · PHP 8.3+ · MySQL/MariaDB |

**Never** recreate, rename, drop or `migrate:fresh` the existing tenant
`Babul_Jakarta` or its database. **Never** change `APP_KEY`.

---

## 2. Required preflight checks (read-only)

Run these by hand **before** a deploy. All are read-only and print no secrets.

```sh
cd /home/lbacloud/lba-cloud-app

php artisan lba:staging:health          # add --json for machines; must exit 0
php artisan lba:localization:audit      # id / en / ms-MY catalogs must match
php artisan migrate:status              # central migrations
php artisan module:migrate-status       # module migrations
git status --porcelain                  # MUST be empty (script refuses a dirty tree)
git rev-parse --short HEAD              # record the current SHA
```

Also confirm:

- `APP_KEY` is set and matches the key encrypted data was written with.
- `APP_DEBUG=false` even when `APP_ENV=production` (cPanel staging may run
  `APP_ENV=production` to disable local-only features — that is expected and
  does **not** need to be weakened to `local`).
- `storage/` and `bootstrap/cache/` are writable; `php artisan storage:link`
  has run once.
- cPanel: tenant DB prefix (`TENANT_DB_PREFIX`, e.g. `lba_tenant_`) and the
  restricted runtime user (`CPANEL_RUNTIME_DB_USER`) are correct. The Laravel
  runtime credential must **not** hold a global `CREATE` privilege.

A successful backup and deploy will report these values (without secrets) in the
`inventory` step.

---

## 3. Deploy (staging)

```sh
cd /home/lbacloud/lba-cloud-app

# Rehearse first — writes nothing.
./deploy-staging.sh --dry-run \
  --confirm-staging --attest-host "$(hostname -f)" --operator "$USER"

# Real deploy of the approved revision.
./deploy-staging.sh \
  --confirm-staging --attest-host "$(hostname -f)" --operator "$USER" \
  --ref origin/main
```

### 3.1 Staging-target attestation (independent of `APP_ENV`)

Because the cPanel staging host may legitimately run `APP_ENV=production`, the
script does **not** rely on `APP_ENV` to identify staging. Instead:

- `--confirm-staging` — explicit operator approval (no accidental runs);
- `--attest-host <host>` — must equal the **OS hostname** of this machine, so the
  approval cannot be copy-pasted onto a different host;
- a hard **hostname denylist** (`LBA_PRODUCTION_HOST_PATTERN`, default
  `prod|production|live|www`) refuses any host that looks like true production,
  regardless of the other flags.

A run without a valid attestation exits `1` before touching anything.

### 3.2 What the pipeline does (in order)

1. **preflight** — binaries, clean tree, `APP_KEY` present, central DB reachable.
2. **attestation** — see 3.1.
3. **inventory** — read-only cPanel/tenancy posture (no secrets).
4. **backup** — `php artisan lba:staging:backup`
   (central DB + **every registered tenant DB** + `APP_KEY` + private media);
   fails closed if any artefact is missing/empty.
5. **pull** — `git fetch --prune` then a detached checkout of an **immutable
   SHA** (never `reset --hard`, never a forced pull).
6. **composer install** — `--no-dev --optimize-autoloader`.
7. **central migrations** — `php artisan migrate --force` (additive only).
8. **tenant migrations** — `php artisan lba:tenant:migrate:registered`
   (only already-registered tenants; **never** provisions a new one).
9. **assets** — `npm ci --include=dev && npm run build`.
10. **caches** — selective clears + Filament asset publishing (**safe default**).
11. **health** — `php artisan lba:staging:health` (must exit 0).
12. **verify-panels** — unauthenticated reachability of `/principal`, `/clinic`,
    `/patient` (a redirect to `*/login` is healthy; a 5xx is not).
13. **verify-login-assets** — `php artisan lba:staging:login-assets`: fetches the
    real `/login` markup and requires every local CSS/JS/font it references to be
    **served** (see 3.5). Any 404 or an HTML body on an asset URL fails the
    deploy and prints the offending URL(s).

Exit codes: `0` success · `1` usage/precondition failure · `2` a step failed.
On failure the script records the backup directory, the checked-out SHA and
exits **without** any destructive undo.

### 3.3 Filament / cache handling

Some cPanel/LiteSpeed hosts break when `php artisan optimize` caches a stale
config/route/view or a Filament asset manifest survives a deploy. The script
therefore defaults to the **safe** path:

```sh
php artisan optimize:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan filament:assets     # when available
```

Full `php artisan optimize` (config/route/view caching) is **opt-in** via
`--optimize` and is warned about. If a deploy breaks Filament, re-run with the
safe default and hard-refresh the browser.

### 3.4 Backups produced

`lba:staging:backup --dir=<dir>` (default `storage/app/deploy-backups`, a
git-ignored path) writes owner-only (`0600`) artefacts under a `0700` directory:

```
<dir>/central-<stamp>.sql            # or .sqlite
<dir>/tenants/<physical>-<stamp>.sql # one per registered tenant (or .sqlite)
<dir>/keys/app-key-<stamp>.key       # the APP_KEY value (restore-critical)
<dir>/media-<stamp>.tar.gz           # storage/app/private (microscopy originals, reports)
```

Preview without writing anything: `php artisan lba:staging:backup --dry-run`.
**Secrets never enter Git** — the default directory is inside `storage/` and the
artefacts are owner-only. Do not commit or copy the key file into the repo.

### 3.5 Login static-asset smoke check

A reachable `/login` page says nothing about whether the stylesheet, the Filament
JavaScript or the Livewire runtime are actually **served**. Staging once returned
a `200` HTML `/login` whose every hashed asset (`/build/assets/theme-*.css`,
`/js/filament/**`, `/fonts/filament/**`) answered with a **404 HTML** page — the
browser sees an unstyled page. Step 13 closes that gap:

```sh
# What the deploy runs, and what an operator can run by hand at any time:
php artisan lba:staging:login-assets --url=https://dev.lbacloud.com
php artisan lba:staging:login-assets --url=https://dev.lbacloud.com --json   # machines
```

The command:

- fetches `GET /login` and requires `200 text/html`;
- extracts every **local** (`/`-rooted) CSS/JS/font URL the page references —
  **no hashed filename is hard-coded**, so it keeps working after any rebuild;
- requires each to return a non-HTML `2xx` with a `Content-Type` matching its
  extension (`text/css`, `application/javascript`, or a font type);
- fails (exit `1`) on any `404`, or on any asset URL that returns an HTML body,
  and **prints each offending URL** — treat those as a deployment failure.

The families checked are the Vite theme/bundle CSS under `/build/assets/*.css`,
Filament JavaScript under `/js/filament/**`, and the Livewire runtime. Skip the
check only deliberately, with `--skip-login-assets`.

### 3.6 Hosting: serving Vite & Filament assets on cPanel/LiteSpeed

`public/build/**`, `public/css/filament/**`, `public/js/filament/**` and
`public/fonts/filament/**` are **generated** and **git-ignored**: they only exist
after `npm run build` (with dev dependencies) and `php artisan filament:assets`
have run **on the host**. When they are absent or unreadable, `public/.htaccess`
rewrites the missing path into Laravel, which returns a **404 HTML** page — the
exact login regression the smoke check now catches.

Diagnostic signature: the directory answers a `301` (LiteSpeed redirects
directories) while the asset file answers `404 text/html`, and git-tracked files
(`robots.txt`, `favicon.ico`) still serve fine. That means the document root is
correct but the generated files are not being served.

cPanel/LiteSpeed steps to correct it (do these **without** touching `.env`,
`APP_KEY`, the databases, or the Super Admin account):

1. **Document root** — confirm `dev.lbacloud.com` points at the app's `public/`
   (`/home/lbacloud/lba-cloud-app/public`), not the app root and not a separate
   `public_html`. A wrong docroot 404s every generated asset while routes still
   work.
2. **Produce the assets on the host** — from the app root:
   ```sh
   npm ci --include=dev && npm run build      # public/build/manifest.json + assets
   php artisan filament:assets                # public/css|js|fonts/filament/**
   ```
   Verify `public/build/manifest.json` and `public/build/assets/*.css` exist and
   that `public/hot` is absent (a stray `hot` file points Vite at a dev server).
3. **Permissions / ownership** — the generated trees must be readable by the
   LiteSpeed worker. Own them by the cPanel user and use `0755`/`0644`:
   ```sh
   chmod -R u=rwX,go=rX public/build public/css public/js public/fonts
   ```
   Avoid `0600`/`0700` here: an unreadable file falls through the front
   controller to a Laravel `404`, which is indistinguishable from "missing".
4. **Do not add a blanket rewrite** for `/build`, `/css`, `/js` or `/fonts` in
   `.htaccess`. Let LiteSpeed serve the real files; the front controller must
   only handle paths that do not exist.
5. **The hosting-local `public/.htaccess` change** — `deploy-staging.sh` refuses
   to run on a dirty tree, so keep that hosting-specific edit either committed to
   the repo, or ignored via `git update-index --skip-worktree public/.htaccess`,
   or otherwise excluded. **Never** `git checkout -- public/.htaccess` to silence
   preflight — that discards the hosting rules.
6. **Re-verify** — after any hosting change, re-run:
   ```sh
   php artisan lba:staging:login-assets --url=https://dev.lbacloud.com   # expect OK
   ```

### 3.7 One tenant on a hand-created MySQL database (staging `.env` override)

Normally a tenant's physical database is derived from its ULID. For **one**
staging tenant you may instead point the app at a MySQL database you created
**by hand in cPanel** (e.g. `lbacloud_tenant`). This optional mode:

- is **OFF** unless **both** `TENANT_DB` and `TENANT_DB_TENANT_ID` are set;
- affects **only** the tenant whose ULID equals `TENANT_DB_TENANT_ID` — every
  other tenant keeps its derived name and its own database (unchanged);
- **never** calls the cPanel UAPI and **never** creates the database.

**Never** commit the staging `.env`, and never change `APP_KEY`, the central
database (`lbacloud_central`) or any other tenant's database configuration.

1. **Select the tenant** and record its ULID (the value `TENANT_DB_TENANT_ID`
   must equal this exactly):

   ```sh
   cd /home/lbacloud/lba-cloud-app
   php artisan tinker --execute="echo App\Models\Tenant::query()->where('slug','Babul_Jakarta')->value('id');"
   ```

2. **Create the database by hand** in cPanel (MySQL Databases) — for this
   runbook, the physical name is `lbacloud_tenant`. In the app's staging `.env`
   (never in Git) set the manual block **and** the tenant connection:

   ```dotenv
   TENANT_DB_CONNECTION=mysql
   TENANT_DB_HOST=127.0.0.1            # the MySQL host cPanel gives you
   TENANT_DB_PORT=3306
   TENANT_DB_USERNAME=<staging-db-user>  # must already have rights on the DB
   TENANT_DB_PASSWORD=<staging-db-password>

   TENANT_DB=lbacloud_tenant           # the exact physical name in cPanel
   TENANT_DB_TENANT_ID=<the-ULID-from-step-1>
   ```

   A temporary staging MySQL user that already has rights on the database is
   fine for this stop-gap; use a **dedicated per-tenant MySQL account** for a
   permanent setup. The name must be a valid identifier and may never be the
   central database.

3. **Clear cached config** so the override is visible (a cached config would
   hide the change):

   ```sh
   php artisan config:clear
   ```

4. **Test the connection** — Principal → Master Tenants → open the tenant →
   Database section → **Uji Koneksi**. It is read-only, makes **no** UAPI call,
   and must report a **connected** database for `lbacloud_tenant`. This is the
   same resolver the runtime connection and migrations use.

5. **Migrate that one tenant** and reach **Siap**:

   ```sh
   # via Artisan (ULID or slug)…
   php artisan lba:tenant:migrate <the-ULID-from-step-1>
   # …or the tenant's Database section → Jalankan Migrasi
   ```

   Status on the tenant screen must read **Siap** (the central registry row
   `tenant_databases` is `migrated` with `database_name = lbacloud_tenant`).

6. **Confirm isolation**: the other tenants still show their own derived
   database in the same section — none of them points at `lbacloud_tenant`.

If the configured name names the **central database**, is already **registered
to another tenant**, or **differs from the name already recorded** for this
tenant, the operation is **refused before any migration** and an audit entry is
written. Inspect the target database and the central `tenant_databases` registry
before retrying — no database is created, migrated or re-pointed while the
conflict stands.

**Undo**: remove `TENANT_DB` and `TENANT_DB_TENANT_ID`, run
`php artisan config:clear`, and the tenant returns to its derived name (its
database is untouched).

---

## 4. Authenticated verification (manual, with independently authorized accounts)

The script's panel check is **unauthenticated** — it only proves the routes
answer. A human must still confirm, using separate accounts that are
**independently authorized**:

- `/principal` with a Principal Super Admin account;
- `/clinic` with a Clinic Admin account → only the tenant's authorized staff;
- `/patient` with a Patient account → only that patient's own records.

Reject the deployment if any panel returns data outside its tenant/branch scope
or reveals another patient's data.

---

## 5. Rollback

The deploy stops safely on the first failure and performs **no** destructive
undo, so a rollback is always an explicit, deliberate action.

### 5.1 Code

```sh
cd /home/lbacloud/lba-cloud-app
git fetch --prune origin
git checkout --detach <previous-good-SHA>   # the SHA printed before the deploy
composer install --no-interaction --no-progress --prefer-dist --no-dev --optimize-autoloader
npm ci && npm run build
php artisan optimize:clear && php artisan config:clear && php artisan route:clear && php artisan view:clear
php artisan lba:staging:health
```

### 5.2 Central database (only if a migration must be undone)

1. Prefer `php artisan migrate:rollback --step=1 --force` for **reversible**
   migrations.
2. Otherwise restore the pre-deploy dump taken in step 4:
   ```sh
   mysql --defaults-extra-file=<0600-creds-file> lbacloud_central < <dir>/central-<stamp>.sql
   ```
   Use a `0600` defaults-file — never put credentials on the command line.

### 5.3 Tenant database (`Babul_Jakarta` and others)

Restore **only** the affected tenant from its own pre-deploy dump:

```sh
mysql --defaults-extra-file=<0600-creds-file> <physical_db_name> < <dir>/tenants/<physical_db_name>-<stamp>.sql
```

**Never** `migrate:fresh`, never recreate the database, never drop the tenant.
The deployment script itself only ever applies migrations.

### 5.4 Private media / key (only if lost)

- Media: `tar -xzf <dir>/media-<stamp>.tar.gz -C storage/app`.
- Key: restore `<dir>/keys/app-key-<stamp>.key` into `APP_KEY` **only** if the
  original is genuinely lost — changing `APP_KEY` makes existing encrypted data
  unreadable.

### 5.5 After rollback

```sh
php artisan optimize:clear
php artisan lba:staging:health
```

Then re-verify the three panels per section 4 and inform the team.

---

## 6. Rollback/verification checklist

- [ ] Preflight commands (section 2) all green; tree clean; SHA recorded.
- [ ] Dry run reviewed.
- [ ] Attestation host matched this machine; no production hostname matched.
- [ ] Backup directory populated (central + tenants + key + media), fail-closed OK.
- [ ] Migrations additive only; `Babul_Jakarta` never recreated.
- [ ] `APP_KEY` unchanged.
- [ ] Health check exit 0; `/principal` `/clinic` `/patient` reachable.
- [ ] `lba:staging:login-assets` OK — `/login` and every CSS/JS/font it references
      are served (no 404 / HTML body); no hashed filename is assumed.
- [ ] Authenticated scoping verified with independent accounts.
- [ ] No secret or PII present in the audit log (`storage/logs/deploy-staging.log`).

---

## 7. Guardrails (do not weaken)

- Payments stay sandbox-only until manually approved
  (`config/payments.php` → `live_enabled = false`).
- No automatic AI diagnosis and no automatic clinical-report release.
- No automatic production database provisioning; `lba:tenant:drop` refuses to
  run outside local/testing.
- The deploy never grants a global `CREATE` privilege, never recreates data and
  never changes `APP_KEY`.
- Principal Super Admin credentials are never changed by a deploy.
- Real secrets/PII must never appear in the deploy log.
