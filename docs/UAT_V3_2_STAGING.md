# UAT — v3.2 Approved Scope (SINA retry · consultation↔availability · staging proof)

Acceptance evidence for the v3.2 scope carried on the branch
`feature/v3-2-sina-retry-availability`. It complements
[WS6_STAGING_RUNBOOK.md](WS6_STAGING_RUNBOOK.md) (host facts, deploy/rollback),
[WS7_PRACTITIONER_CLINIC_OPERATIONS.md](WS7_PRACTITIONER_CLINIC_OPERATIONS.md)
(scheduling chokepoints) and [ACCEPTANCE_MATRIX.md](ACCEPTANCE_MATRIX.md).

> **Verification posture.** Every status below is backed by evidence. Items that
> can only be proven on the cPanel staging host (`dev.lbacloud.com`) are marked
> **PENDING** — they were **not** proven here and must be completed by an
> operator on the host. Nothing is marked PASS without a re-runnable artefact.

## 1. Guardrails (do not weaken)

- Never recreate, rename, drop or `migrate:fresh` the tenant `Babul_Jakarta` or
  its database. Never change `APP_KEY`.
- Payments stay **sandbox-only**; signature verification stays **on**
  (`config/payments.php` → `live_enabled = false`).
- SINA stays a **private** endpoint: `openai_compatible` pointed at the private
  deterministic stub (§3), or `ollama` on the protected AI host. No public bind.
- AI Vision stays on the **synthetic mock** (`LBA_AI_PROVIDER=mock`); it runs no
  model and asserts no diagnosis.
- No real-data seeds; UAT uses synthetic data only.

## 2. Automated evidence (re-runnable in the sandbox — PASS)

| Concern | Command | Result |
|---|---|---|
| SINA retry end-to-end (reply id, message count, provider calls, ledger; fail→retry) | `vendor/bin/phpunit tests/Feature/Api/PatientConsultationsSinaApiTest.php` | **28/28** |
| Consultation ↔ practitioner availability wiring + regressions | same file (section 6) + `tests/Feature/Modules/Practitioners*Test.php`, `ClinicConsultationSurfacesTest`, `Filament/ConsultationsSinaUiTest` | **111/111** |
| Sandbox webhook signature + replay (Midtrans / PayPal / iPaymu) | `vendor/bin/phpunit tests/Feature/Modules/CommerceGatewayDriversTest.php tests/Feature/Modules/CommercePaymentHardeningTest.php tests/Feature/Modules/CommercePaymentsTest.php tests/Feature/Modules/CommerceSecurityTest.php` | **pass** (wrong signature → `webhook_rejected`; replay stays one transaction) |
| AI Vision mock job runs for real (inline) | `tests/Feature/Modules/LbaAiAnalysisTest.php`, `LbaAiWalletBillingTest.php` | **pass** (`provider = mock`, synthetic detections) |
| Localization catalogs match baseline | `php artisan lba:localization:audit --strict` | **`Localization audit passed`** |
| Formatting | `vendor/bin/pint --test` | **clean** |
| Front-end assets | `npm ci && npm run build` | **built** |
| Full test suite | `vendor/bin/phpunit` | **OK** (1117 tests, 5053 assertions) |

Notes:

- The suite must run with `APP_LOCALE=id` (the value shipped in `.env.example`).
  A checkout with **no** `.env` falls back to `config('app.locale') = en`, which
  makes the three `DashboardTest` KPI-label assertions fail purely because the
  labels render in English. This is an environment artifact, **not** a code
  defect: create `.env` from `.env.example` (`cp .env.example .env`) and the
  suite is green. No application code depends on that fallback.
- The 5 booking regressions originally failed because the branch timezone is
  `Asia/Jakarta` (DB column default) while the test helper built the slot in UTC.
  The helper was corrected to open the window in the branch timezone; the
  production path was already correct. No production timezone bug exists.
- Webhook **settlement** follows the existing settlement path: a verified
  notification settles through `PaymentManager`/`RefundService`; a contradictory
  currency or an over-amount is refused and settles nothing, and a replayed
  notification is a no-op (single transaction).

## 3. SINA staging stub (private, deterministic)

`services/sina-openai-stub/app.py` is the private deterministic
`openai_compatible` endpoint. It runs no model, returns clearly-marked SYNTHETIC
text, requires a bearer token, and returns the SAME reply and token counts for
the SAME input (so a retry/capture can be asserted exactly).

```sh
cd services/sina-openai-stub
SINA_STUB_TOKEN=<private-token> python3 app.py    # 127.0.0.1:8098 by default
```

Laravel (sandbox / staging only — never in production):

```env
LBA_SINA_ENABLED=true
LBA_SINA_PROVIDER=openai_compatible
LBA_SINA_OPENAI_URL=http://127.0.0.1:8098
LBA_SINA_OPENAI_MODEL=sina-stub-deterministic
```

Smoke-tested: `GET /health` → `{"status":"ok","synthetic":true}`; an
unauthenticated `POST /chat/completions` → `401`; two identical requests →
byte-identical `choices[0].message.content` and `usage`.

## 4. Acceptance status

| # | UAT item | Evidence available here | Status |
|---|---|---|---|
| 1 | SINA retry returns the stored reply without a new message / provider call / credit change; parallel + conflicting reuse handled; failed retrieval/provider releases the reservation and a successful retry bills once | `PatientConsultationsSinaApiTest` (28/28) | **PASS** |
| 2 | Consultation booking honours practitioner verification, branch authorization, schedule, leave and capacity; legacy no-practitioner payload unchanged; cancellation releases the slot; refund policy ≠ credit-ledger movement; patient/tenant isolation | `PatientConsultationsSinaApiTest` §6 + practitioner suites (111/111) | **PASS** |
| 3 | AI Vision uses the mock adapter | `config('lba.ai.provider') = mock`; `LbaAiAnalysisTest` | **PASS** |
| 4 | SINA uses a private deterministic OpenAI-compatible stub | `services/sina-openai-stub` smoke test | **PASS** (sandbox) / **PENDING** (live on host) |
| 5 | Payments sandbox with signature verification active | `config/payments.php` `live_enabled=false`; commerce suites | **PASS** |
| 6 | Sandbox webhook — wrong signature rejected, resend idempotent | commerce suites | **PASS** |
| 7 | Webhook follows the existing settlement path | commerce suites | **PASS** |
| 8 | A **real** queue worker processes a queued job (separate process) | not runnable here (`QUEUE_CONNECTION=sync`, in-memory sqlite) | **PENDING** — §5 |
| 9 | Manual tenant DB + central↔tenant bridge evidence | needs the host | **PENDING** — §5 |
| 10 | Restore into a separate test database | needs the host | **PENDING** — §5 |
| 11 | Private images resolved only via signed URL | needs the host | **PENDING** — §5 |
| 12 | Sidebar per role and locale id / ms-MY / en, desktop + mobile | needs the host (screenshots) | **PENDING** — §5 |
| 13 | Patient journey: login → sandbox payment → consultation → SINA | needs the host | **PENDING** — §5 |
| 14 | `Babul_Jakarta` registry status re-verified on the host | needs the host | **PENDING** — §5 |
| 15 | Existing DB, `APP_KEY` and `public/.htaccess` preserved | needs the host | **PENDING** — §5 |

## 5. Host-only steps (operator, on `https://dev.lbacloud.com`)

Run after deploying the approved SHA via `deploy-staging.sh` (see
[WS6_STAGING_RUNBOOK.md](WS6_STAGING_RUNBOOK.md)). Record raw output per item.

1. **Real queue worker** — enqueue a real job on the host and run one worker
   cycle, then confirm the job completed (not merely dispatched):
   ```sh
   php artisan queue:work --once --stop-when-empty -v
   ```
   Expected: the job is processed and its row reaches a terminal status; no
   duplicate side effect on re-run.
2. **Tenant DB + central↔tenant bridge** — capture `php artisan lba:staging:health --json`,
   confirm `Babul_Jakarta`'s isolated DB is reachable and that a central registry
   row resolves to it; attach the (secret-free) output.
3. **Restore into a separate test DB** — restore the pre-deploy dump into a
   scratch database (never the live one) and confirm it opens read-only:
   ```sh
   mysql --defaults-extra-file=<0600-creds-file> <scratch_db> < <dir>/tenants/<physical>-<stamp>.sql
   ```
4. **Private images & signed URL** — confirm a report/microscopy image has no
   public URL and opens only through the authenticated, tenant-scoped stream; a
   foreign/expired link is refused.
5. **Sidebar & locale matrix** — for each role (Principal / Clinic / Patient)
   capture the sidebar in `id`, `ms-MY`, `en` at desktop (≥1440) and mobile
   (≤390): no untranslated keys, correct links per role.
6. **Patient journey** — as a synthetic patient: log in → buy via a **sandbox**
   payment (Midtrans/PayPal/iPaymu) → book a consultation against an available
   practitioner → run a SINA turn against the private stub; confirm the wallet
   ledger moves exactly once and the consultation appears self-only.
7. **`Babul_Jakarta` registry** — `php artisan lba:tenant:list` (or the
   equivalent) must still show `Babul_Jakarta` active with its original DB.
8. **Preservation** — confirm `APP_KEY` is byte-identical to `storage/app/deploy-backups/keys/…`,
   the existing tenant DB was never recreated, and `public/.htaccess` is intact.

## 6. Backout

A v3.2 revert is a deployment revert of the approved SHA through
`deploy-staging.sh`; it is **not** a data reset. The scope is additive on the
tenant side only: ONE new tenant table, `consultation_sina_turns`
(`database/migrations/tenant/2026_12_02_600006_create_consultation_sina_turns_table.php`),
applied by the normal tenant migration path — **no** central migration, **no**
altered or dropped column (`consultations.appointment_id` already existed), and
no recreated table. Leaving the new table in place is safe. Never
`migrate:fresh`; never recreate the tenant.
