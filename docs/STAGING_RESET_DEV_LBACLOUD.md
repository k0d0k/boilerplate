# Staging reset — `dev.lbacloud.com` (v3.3 Principal manual-database lifecycle)

Operator procedure to **re-run onboarding from scratch** on the cPanel staging host.
It is written to be **reviewed and approved before it is executed**. Nothing here
is run automatically by the application; every destructive step is an explicit,
deliberate operator action.

> **Review gate.** This document is the deliverable for review. Do **not** execute
> it until the change under review is merged/deployed *or* the operator has been
> told to reset staging. The destructive examples below must only ever be run
> against the staging host — never against production, and never against the
> NuBiom/CodeCanyon HMS.

## 0. What this reset does and does not do

The application itself **never** runs `DROP DATABASE`, `migrate:fresh`, or empties
a cPanel schema:

- **Hapus Mitra** on Principal is a **soft delete** (`tenants.deleted_at`). The
  partner's access stops at once (every membership resolves only through an
  `active` tenant) while its **central child rows are preserved** — most
  importantly the `tenant_databases` registry row, so the physical database name
  stays **claimed**. That is what stops a retained clinical schema from being
  re-opened by a newly onboarded partner.
- **Hapus Brand** only deletes the commercial identity; it is **blocked** while any
  partner is still linked, and it never touches a partner, an account, a
  subscription or a clinical database.
- The only place a tenant database is ever physically dropped is the
  `lba:tenant:drop` command, which is **hard-disabled outside `local`/`testing`**
  and therefore cannot run on staging.

Consequently, a **full** reset (so names and slugs can be reused) needs a small
number of deliberate, central, staging-only purge steps — described below.

**Preserved across the reset (never touch):**

| Item | Where | Why |
| --- | --- | --- |
| Principal Super Admin account(s) | `users` + `principal_role_assignments` | the operator must still be able to sign in |
| `APP_KEY` | staging `.env` / server | rotating it destroys every encrypted credential and session |
| Hosting config | cPanel, document root, `.env` DB host/port | not part of onboarding |
| NuBiom/CodeCanyon HMS (production) | separate host/database | out of scope; never reachable from staging |

## 1. Pre-flight inventory (read-only)

Run these in the app root. They print **names and statuses only** — no passwords,
no clinical rows. Record the output and attach it to the reset ticket.

```sh
cd /home/lbacloud/lba-cloud-app

# Brand catalog
php artisan tinker --execute="
  Illuminate\Support\Facades\DB::table('brands')
    ->orderBy('name')
    ->get(['id','name','slug','status'])
    ->each(fn(\$r) => print(\$r->slug.' | '.\$r->name.' | '.\$r->status.PHP_EOL));
"

# Partners, including SOFT-DELETED ones (deleted_at must be shown)
php artisan tinker --execute="
  Illuminate\Support\Facades\DB::table('tenants')
    ->orderBy('name')
    ->get(['id','name','slug','status','deleted_at'])
    ->each(fn(\$r) => print(\$r->slug.' | '.\$r->name.' | '.\$r->status.' | deleted:'.(\$r->deleted_at ?? '-').PHP_EOL));
"

# Brand <-> partner links (which brand each partner uses, and the primary)
php artisan tinker --execute="
  Illuminate\Support\Facades\DB::table('tenant_brands as tb')
    ->join('tenants as t','t.id','=','tb.tenant_id')
    ->join('brands as b','b.id','=','tb.brand_id')
    ->get(['t.slug as tenant','b.slug as brand','tb.is_primary'])
    ->each(fn(\$r) => print(\$r->tenant.' -> '.\$r->brand.' | primary:'.(\$r->is_primary ? 'yes':'no').PHP_EOL));
"

# Database registry — every CLAIMED physical name and its owner
php artisan tinker --execute="
  Illuminate\Support\Facades\DB::table('tenant_databases')
    ->orderBy('database_name')
    ->get(['tenant_id','database_name','configured_database_name','driver','status'])
    ->each(fn(\$r) => print((\$r->configured_database_name ?? '(derived)').' | owner:'.\$r->tenant_id.' | '.\$r->driver.' | '.\$r->status.PHP_EOL));
"
```

Also confirm the two known staging facts:

```sh
# The legacy active partner and its ULID
php artisan tinker --execute="echo App\Models\Tenant::query()->where('slug','Babul_Jakarta')->value('id');"

# Who currently owns the hand-created physical database `lbacloud_tenant`?
php artisan tinker --execute="
  \$row = App\Platform\Tenancy\Models\TenantDatabase::query()
    ->where('configured_database_name','lbacloud_tenant')
    ->orWhere('database_name','lbacloud_tenant')
    ->first();
  echo \$row ? (\$row->tenant_id.' | '.\$row->status) : 'unowned';
"
```

Record: **`Babul_Jakarta`'s status**, and **the owner of `lbacloud_tenant`** (or
`unowned`). These drive §4.

Read-only health, as a baseline before anything changes:

```sh
php artisan lba:staging:health          # add --json for machines
php artisan migrate:status
php artisan module:migrate-status
```

## 2. Back up first (mandatory)

Every reset begins with a read-only backup of the central database, the
registered tenant databases, `APP_KEY` and private media. Nothing is written to
the app or to any served directory.

```sh
php artisan lba:staging:backup --dir=storage/app/reset-backups
php artisan lba:staging:backup --dry-run          # preview the plan only
```

Keep the resulting directory (default mode `0700`, artefacts `0600`) outside
Git. Do **not** proceed if any artefact is missing or empty — the command exits
non-zero on a failure.

### 2.1 Prove the per-partner credential path before a full reset

In MANUAL mode each partner authenticates with **its own** restricted MySQL
user, so its dump must use that user too — the shared `.env` tenant credential
would not be able to read another partner's schema. The backup already does
this: for every registered partner it overlays the partner's stored credential
(`tenant_database_credentials`) via
`App\Platform\Tenancy\TenantDatabaseManager::parametersFor()` and only falls
back to the `.env` template for a partner that has no stored credential.

Before relying on this for a full reset, prove it on a **scratch schema**, never
on a real partner:

1. **Automated (already green).** `php artisan test
   tests/Feature/Platform/Health/StagingTenantBackupCredentialTest.php` proves
   two partners are each dumped with **their own** credential and that a
   credential-less partner keeps the shared template.
2. **Operator round-trip.** Create one throwaway partner pointing at an empty
   scratch database, write a row into it, run `lba:staging:backup`, then restore
   that single dump into a **different** empty scratch database using the same
   credential and confirm the row is present. Only after this round-trip passes
   should the §4.3 purge + §8 restore be trusted for a full reset.

The restore half of the round-trip is exactly the scoped, per-partner restore in
§8 — it reads the credential from the partner's registry row, so backup and
restore always use the same identity.

## 3. The two states of `lbacloud_tenant`

`lbacloud_tenant` is the hand-created cPanel database used as the staging
example. There are exactly two cases, and they need different handling. Decide
which one you are in from §1.

### Case A — `lbacloud_tenant` is empty and available

- No `tenant_databases` row claims it, **and** the schema in cPanel holds no rows
  you need to keep.

You may register it to the **first new partner** in §6 with no purge step. The
app still refuses to *create* it — you create the empty database by hand in
cPanel if it does not exist (MySQL Databases), then register it in the form.

### Case B — `lbacloud_tenant` is still owned by `Babul_Jakarta` (or any old partner)

- A `tenant_databases` row claims it (status `pending`/`provisioned`/`migrated`),
  **or** the cPanel schema still contains that partner's data.

Do **not** simply re-register the name for a new partner. Two guards will refuse
it for safety:

- `registered_to_another_tenant` — the name is recorded for a different partner;
- `recorded_database_mismatch` — the partner already owns a provisioned/migrated
  database and cannot be re-pointed.

To reuse the name you must (i) remove the old partner (§4) and (ii) **either**
drop-and-recreate the database in cPanel so the schema is genuinely empty, **or**
keep the schema but only after a reviewed decision that the old data may be
discarded. Reusing a *non-empty* schema would re-open the old partner's data, so
the reviewed default is: **empty the schema before re-registering**.

> The physical drop is a **cPanel action**, not an application one. The
> application deliberately has no path to drop a staging database.

## 4. Remove partners, then brands (in that order)

Order matters: a brand cannot be removed while a partner is linked to it, and a
partner's database name can only be freed once the partner is gone.

### 4.1 Remove each partner (Hapus Mitra) — Principal UI

1. Sign in to `/principal` with the **Principal Super Admin** account.
2. Open **Brand & Mitra → Mitra**, use each partner's **Hapus** action and
   confirm in the dialog (it shows the partner name and the impact).
3. The backend is gated to Principal Super Admin and refuses while a **live
   subscription** exists — cancel or end it first. The reason message names the
   dependency and the step that clears it.

This step is a **soft delete**: access stops immediately and, crucially, the
partner's `tenant_databases` row is **preserved**, so the physical name stays
claimed. A shared account that also belongs to another partner keeps working
there.

> **Why the name stays claimed.** This is the safeguard that stops a retained
> schema from being re-opened. To *reuse* the name you must purge the retained
> row deliberately (4.3).

### 4.2 Remove each brand (Hapus Brand) — Principal UI

1. Still in `/principal`, open **Brand & Mitra → Brand**.
2. Use a brand's **Hapus** action.
3. If a partner is still linked, the action is **refused** and the message lists
   those partners. Detach the link (partners' **Relasi** screen → Brand → Lepas)
   or move the **Brand utama** first, then retry.

A brand is only a commercial identity: removing it never removes a partner, an
account, or any clinical data.

> **Verified behaviour — the "still linked?" check ignores soft-deleted partners.**
> The block above reads the brand→partner relation (`Brand::tenants()`) through
> `Tenant`'s `SoftDeletes` global scope, so a partner removed in §4.1 is
> **invisible** there and no longer blocks the brand. Its `tenant_brands` pivot
> row is *not* deleted by the soft delete, but it stops counting.
> Consequently the documented order — **partners first (§4.1), then brands
> (§4.2)** — matches the code: after a partner is soft-deleted, its brand can be
> removed even though the pivot row lingers, and the pivot row is finally cleaned
> up when the partner is purged (§4.3, `ON DELETE CASCADE`).
>
> If instead you remove the **brand first**, the action is correctly refused
> while any **live** partner links it; detach the link or move **Brand utama**,
> then retry.

### 4.3 Purge ONE retained partner by its exact ULID (staging only — full reuse)

Only needed for a **full** reset so the **slug** and the **database name** can be
reused. This is a deliberate central-database purge on staging. Take the §2
backup first.

> **Guardrails (do not weaken).**
> * Purge identifies the partner by its **exact ULID** (`tenants.id`), never by
>   name or slug — a slug is editable and not guaranteed unique, a ULID is not.
> * The lookup uses `onlyTrashed()`, so the command can only ever select a
>   partner that is **already soft-deleted**. A live partner can never be purged
>   by accident; soft-delete it (§4.1) first if that is the intent.
> * Always print the resolved target and read it back before `forceDelete()`.

**Step 1 — resolve and DISPLAY the single target (no write).**

```sh
cd /home/lbacloud/lba-cloud-app

# Paste the EXACT ULID copied from §1 for ONE soft-deleted partner.
php artisan tinker --execute="
  \$id = '01HXXXXXXXXXXXXXXXXXXXXXXX';
  \$t = App\Models\Tenant::onlyTrashed()->find(\$id);
  if (! \$t) { echo 'ABORT: no SOFT-DELETED partner with that ULID.'.PHP_EOL; return; }
  echo 'target: '.\$t->id.' | '.\$t->name.' | slug:'.\$t->slug.' | deleted_at:'.\$t->deleted_at.PHP_EOL;
  echo 'registry rows: '.App\Platform\Tenancy\Models\TenantDatabase::query()->where('tenant_id',\$t->id)->count().PHP_EOL;
"
```

Confirm the printed ULID is the one you intend, that `deleted_at` is set, and
that this is the partner whose schema you have decided to discard. **If anything
does not match, stop.**

**Step 2 — purge THAT ONE partner (hard delete).**

`tenants.id` is the parent of `tenant_databases`, `tenant_database_credentials`,
`tenant_memberships` and `tenant_brands`, each declared with
`ON DELETE CASCADE`, so this single delete frees both the slug and the database
name without touching any other partner:

```sh
php artisan tinker --execute="
  \$id = '01HXXXXXXXXXXXXXXXXXXXXXXX';   // the SAME ULID you verified in step 1
  \$t = App\Models\Tenant::onlyTrashed()->find(\$id);
  if (! \$t) { echo 'ABORT: nothing to purge.'.PHP_EOL; return; }
  echo 'force deleting '.\$t->slug.' ('.\$t->id.')'.PHP_EOL;
  \$t->forceDelete();
"
```

**Step 3 — confirm the claim is gone.**

```sh
php artisan tinker --execute="
  \$id = '01HXXXXXXXXXXXXXXXXXXXXXXX';
  echo 'tenant rows:   '.App\Models\Tenant::withTrashed()->where('id',\$id)->count().PHP_EOL;
  echo 'registry rows: '.App\Platform\Tenancy\Models\TenantDatabase::query()->where('tenant_id',\$id)->count().PHP_EOL;
"
```

Both must print `0`. Repeat per partner that must be fully forgotten.

`Babul_Jakarta` is the legacy **active** partner, so `onlyTrashed()` can never
select it: **do not** purge it unless the reset explicitly intends to discard it
— and in that case it must first have been soft-deleted (§4.1). It is listed in
§1 only so the operator can confirm its current status.

## 5. Obtain an empty tenant schema

For each database you intend to re-register:

- **Preferred:** in cPanel → **MySQL Databases**, drop the database and recreate
  it (empty). The application can then migrate it from scratch in §6.
- If the database must be kept, verify it is genuinely empty before registering
  it to a new partner. The reviewed default is that a non-empty schema must not
  be handed to a new partner.

The application never creates the database in manual mode — it only records the
name and later **migrates** it.

## 6. Re-run onboarding (the target flow)

1. **Principal → Brand & Mitra → Brand → Tambah Brand** — create **NuBiom**
   (it should already exist; recreate only if removed).
2. **Principal → Brand & Mitra → Mitra → Tambah Mitra** — create
   **Klinik NuBiom Staging**, choose Brand **NuBiom** (tick **Brand utama**),
   and in the **Database Manual** fieldset enter:
   - **Nama Database MySQL** — `lbacloud_tenant`
   - **Username MySQL** / **Password MySQL** — the restricted cPanel account
   - The registry is saved as **Menunggu** (pending). The password is
     **write-only** and is never echoed back, even when validation fails.
3. Open the partner → **Relasi → Koneksi Database** → **Uji Koneksi**
   (read-only: it only proves the connection + database presence, and makes no
   cPanel UAPI call).
4. **Setup Klinik** (migrate) — runs the tenant migrations and activates the
   default modules. Status becomes **Siap** only after migrations succeed.
5. Recreate the Clinic Admin / patient accounts only as needed for UAT.

A **second** partner can select the **same** Brand **NuBiom** with a
**different** physical database (e.g. `lbacloud_tenant_2`); the backend refuses
the central database name and any name already claimed by another partner.

## 7. Post-reset verification

```sh
php artisan lba:staging:health
```

Then re-run §1 and confirm:

- [ ] Brand **NuBiom** exists; the stale brand(s) are gone and were not linked.
- [ ] The new partner(s) appear `active` with the intended Brand link / primary.
- [ ] Each partner shows status **Siap** and its own physical database name;
      none points at another partner's database.
- [ ] No leftover soft-deleted partner or retained `tenant_databases` row blocks
      the reused name (unless intentionally retained).
- [ ] `Babul_Jakarta` status is as expected (untouched unless the reset said so).
- [ ] Principal sign-in still works; `APP_KEY` unchanged (`git diff` shows no
      `.env` change; the key file is byte-identical to the §2 backup).

## 8. Abort / rollback

- **Before any §4.3 purge:** simply stop. Soft-deleted partners can be restored
  in place — `App\Models\Tenant::onlyTrashed()->find($ULID)->restore()` — and no
  clinical database was touched. This is the cheapest rollback; prefer it.
- **After a §4.3 purge:** the hard delete is irreversible at the application
  level, so restore from the §2 backup. Restore is scoped to **the single
  partner you purged** — never restore every tenant dump blindly, or you will
  overwrite partners that were never touched.

### 8.1 Restore the central metadata

```sh
cd /home/lbacloud/lba-cloud-app

# A password-free client config for the CENTRAL database (0600, outside Git).
# Create it once from the hosting credentials; never echo the password.
cat > /secure/central.cnf <<'CNF'
[client]
host=<central db host>
user=<central db user>
password=<central db password>
CNF
chmod 600 /secure/central.cnf

B=storage/app/reset-backups/<stamp>
mysql --defaults-extra-file=/secure/central.cnf <central_db_name> < "$B/central-<stamp>.sql"
```

This recreates the purged partner's rows — including its `tenant_databases`
claim and its encrypted `tenant_database_credentials` row — from the backup.

### 8.2 Restore ONLY the one partner's clinical schema, as THAT partner

The dump in `$B/tenants/` was written with the partner's own MANUAL credential
(§2.1), so the restore must use the **same** credential. Read it back from the
just-restored registry into a `0600` client file; nothing prints the password.

```sh
# Resolve the ONE partner's physical database + write its credential file.
php artisan tinker --execute="
  \$id  = '01HXXXXXXXXXXXXXXXXXXXXXXX';   // the single purged+restored partner
  \$cred = App\Platform\Tenancy\Models\TenantDatabaseCredential::query()
            ->where('tenant_id', \$id)->firstOrFail();
  \$name = app(App\Platform\Tenancy\TenantDatabaseName::class)->for(\$id);
  \$cnf  = tempnam(sys_get_temp_dir(), 'lba-restore-'); chmod(\$cnf, 0600);
  \$host = config('database.connections.tenant.host');
  \$port = config('database.connections.tenant.port');
  file_put_contents(\$cnf, \"[client]\nhost={\$host}\nport={\$port}\nuser={\$cred->username}\npassword={\$cred->password}\n\");
  echo \$name.PHP_EOL.\$cnf.PHP_EOL;
"
```

Then load that partner's own dump into its own schema (use the two paths printed
above):

```sh
mysql --defaults-extra-file=<printed .cnf path> <printed database name> \
      < "$B/tenants/<printed database name>-<stamp>.sql"
```

Verify only that partner changed:

```sh
php artisan tinker --execute="
  echo App\Models\Tenant::withTrashed()->count().' partner row(s)'.PHP_EOL;
"
php artisan lba:staging:health
```

Physical cPanel databases are only ever changed by the operator, per §5.

## 9. Safety recap

- Destructive examples in §4.3 are for **staging central metadata only**.
  Run destructive tests on **local fixtures or a test database**, never here.
- Never run `lba:tenant:drop` on staging (it is disabled there anyway).
- Never `migrate:fresh`, never change `APP_KEY`, never rename/recreate
  `Babul_Jakarta`, never touch the production HMS.
- Every step above is operator-initiated; the application performs no automatic
  reset, merge or deploy.
