# WS9 — SINA MEDCloud Knowledge Base & RAG: Staging UAT & Rollback

Acceptance checklist, PDF-ingestion instructions and non-destructive backout plan
for the WS9 milestone (secure knowledge registry + PDF intake, page-aware
retrieval and citation engine, RAG-integrated SINA chat, knowledge-ops UI and
synthetic evaluation). It complements
[WS6_STAGING_RUNBOOK.md](WS6_STAGING_RUNBOOK.md) (host facts, provisioning),
[OPERATIONS_RECOVERY.md](OPERATIONS_RECOVERY.md) and the locked specification
[WS9_SINA_KNOWLEDGE_RAG_SPEC.md](WS9_SINA_KNOWLEDGE_RAG_SPEC.md).

> **Never deploy from TRAE.** Nothing here is executed automatically. WS9 lands on
> `feature/v3-ws9-sina-knowledge-rag` behind a single Draft PR targeting
> `feature/v3-ws6-lba-capture`; it is deployed only after parent PR #33 merges and
> this PR is reviewed and merged into `main`.

> **RAG, NOT FINE-TUNING.** Approved PDFs are retrieved as quoted context; they
> **never** retrain the base model weights and **never** override the SINA system
> safety prompt. SINA must never claim a document "trained" the model.

> **SYNTHETIC / AUTHORIZED CONTENT ONLY.** UAT ingests public-domain or
> rights-cleared, selectable-text PDFs. No clinical records, no patient text and
> no private-property articles are uploaded.

---

## 1. Guardrails (unchanged, non-negotiable)

- Do **not** recreate, rename, drop or `migrate:fresh` the existing tenant
  `Babul_Jakarta` or its database.
- Do **not** reset or change `APP_KEY`; do **not** change Principal credentials.
- No destructive migrations. WS9 only ADDS the central `knowledge_*` tables and
  ONE additive, nullable column on the existing tenant databases —
  `consultation_messages.metadata` (JSON), migration
  `database/migrations/tenant/2026_12_01_600005_add_metadata_to_consultation_messages_table.php`
  (see §10). No existing column is altered, renamed or dropped.
- Do **not** run real-data seeds. Tests and UAT use synthetic data only.
- Keep the existing **five user categories** and all RBAC roles intact. WS9 adds
  the permission `platform.knowledge.manage` (Principal) and reuses the existing
  `knowledge.manage` (tenant) — it grants no clinical, wallet or report permission.
- SINA stays **OFF by default in production** unless a signed private gateway
  exists (`lba.sina.enabled` == false). Keep `PAYMENTS_LIVE_ENABLED=false`.
- Never copy patient records into the knowledge index; the knowledge tables are
  CENTRAL, non-patient reference metadata only.

---

## 2. Preflight (read-only)

```sh
php artisan lba:staging:health            # must exit 0
php artisan lba:localization:audit --strict
php artisan lba:localization:audit --baseline=en --strict
php artisan lba:localization:audit --baseline=ms-MY --strict
php artisan migrate:status                # central — new knowledge_* tables only
php artisan --version                     # Laravel 13.x
git status --porcelain                    # MUST be empty
git rev-parse --short HEAD                # record the SHA under review
```

Confirm `config('lba.sina.enabled')` is false in production unless an approved
private gateway is live, and that `lba.knowledge.enabled` is true
(it is the umbrella flag; per-tenant retrieval still additionally requires the
`knowledge_base` module to be active for that tenant).

---

## 3. Acceptance checklist

### WS9.A — Knowledge registry & secure PDF intake
- [ ] A document walks `uploaded → processing → review → published → archived/revoked`
      with an audit trail; it is **not retrievable** until an authorized human
      publishes it (no auto-publish).
- [ ] A scanned / non-extractable PDF fails **clearly** and is NOT recorded as
      parsed; the failure is visible in the review queue.
- [ ] A duplicate upload (same SHA-256) within one scope is de-duplicated instead
      of creating a second source.
- [ ] Source metadata (title, author, publication date, license, source URL,
      language, page count) and a full version history are retained.
- [ ] Raw PDFs live only on the private disk; there is no public URL, and the only
      read path is the authenticated, tenant-scoped stream.

### WS9.B — Chunking, retrieval & citations
- [ ] Chunks NEVER cross a page boundary; every chunk carries the REAL original
      PDF page and a chunk hash.
- [ ] Retrieval runs against the trusted server-side tenant context only; a
      client cannot choose the tenant and cannot supply `tenant_id`.
- [ ] With no embedding service configured, retrieval deterministically falls back
      to the lexical index (SINA still answers).
- [ ] An embedding-provider failure degrades to lexical search rather than
      failing the turn.
- [ ] Every citation resolves to an AUTHORIZED document/version and PDF page, or
      the answer abstains with "no approved source found" — never a fabricated
      bibliography or page number.
- [ ] A revoked / archived document disappears from retrieval; its chunks are
      purged.
- [ ] Platform sources are included ONLY when published and `include_platform` is
      on; tenant sources are visible only inside their own tenant.

### WS9.C — SINA integration (existing service)
- [ ] RAG composes into `SinaChatService` BEFORE provider completion; the existing
      system safety prompt and no-impersonation rules are preserved.
- [ ] Retrieved text is quoted/untrusted context: PDF content cannot override
      system instructions, request credentials, change billing or bypass clinical
      review.
- [ ] Citations appear as structured metadata in BOTH the Patient SINA Filament
      chat and the existing REST API (title + document date + original PDF page).
- [ ] A retry never double-charges: wallet reservation is released on provider
      failure/timeout and captured exactly once on success; no assistant reply is
      invented on failure.
- [ ] Consent, consultation history and tenant transcript behaviour are unchanged.
- [ ] A patient sees only their OWN consultations through the API.

### WS9.D — Knowledge ops, evaluation & handoff
- [ ] The Clinic knowledge console (`/clinic/knowledge/console`) requires the
      `knowledge_base` module AND `knowledge.manage`; a role without it is refused.
- [ ] The console probe returns provenance only (`document_id, version, page,
      title, score, url, platform`) — never patient text.
- [ ] A failed index build can be retried; retrying another tenant's document is a
      no-op.
- [ ] The Principal governance page (`/principal/knowledge-governance`) requires
      `platform.knowledge.manage`; a clinic identity cannot reach it.
- [ ] Principal manages PLATFORM-scope sources only and never sees or touches
      tenant documents (and vice-versa).
- [ ] The evaluation harness runs ≥20 synthetic prompts per id/en/ms-MY
      (grounded, no-answer, conflicting, injection, page-correctness) and reports
      pass/fail counts, NOT an invented accuracy percentage.

---

## 4. Automated evidence

```sh
php artisan lba:localization:audit --strict   # id / en / ms-MY
vendor/bin/pint --test                        # touched files clean
php artisan test                              # full PHPUnit suite
```

Focused WS9 suites: `KnowledgeBaseIngestionTest`, `KnowledgeBaseRetrievalTest`,
`KnowledgeBaseSinaRagTest`, `KnowledgeBaseEvaluationTest`,
`KnowledgeBaseGovernanceTest`.

The WS9 retrieval/citation evaluation is a first-class command:

```sh
# Seed a synthetic, NON-patient corpus for one tenant, run every locale, purge.
php artisan lba:knowledge:evaluate --tenant=<tenant-ulid> --actor=<central-user-id> --purge

# Single locale, machine-readable report.
php artisan lba:knowledge:evaluate --tenant=<tenant-ulid> --actor=<id> --locale=id --json
```

Exit code is `0` **iff** `failed == 0` across every evaluated locale. The report
prints `total / passed / failed` and the failing cases — it never prints an
accuracy percentage. There is no Python retrieval service in this workstream, so
no Python tests apply; retrieval is implemented in Laravel with an optional
embedding provider.

---

## 5. PDF ingestion instructions (operator)

Ingestion is **UI-driven and human-gated** — there is no batch import command and
no public upload endpoint.

**Prerequisites**
- A selectable-text PDF (an e-book / article / educational reference you have the
  rights to use). Scanned image-only PDFs are rejected by design (no OCR by
  default).
- ≤ 50 MB (`LBA_KNOWLEDGE_MAX_KB`, default 51200) and ≤ 1000 pages
  (`LBA_KNOWLEDGE_MAX_PAGES`).
- The `knowledge_base` module ACTIVE for the tenant, and the acting user holding
  `knowledge.manage` (clinic) or `platform.knowledge.manage` (Principal).

**Path A — Clinic / tenant knowledge (visible only inside that tenant)**
1. Sign in to the **Clinic** panel as an owner/admin/reviewer/consultant.
2. Open **Knowledge → Console** (`/clinic/knowledge/console`) and create a
   collection (e.g. a reference set). Collection keys are tenant-scoped and must
   be unique within the tenant.
3. Upload the PDF to the collection. Ingestion runs on the queue: extract
   selectable text **per original page**, compute SHA-256, split into page-aware
   chunks. A duplicate within the scope is de-duplicated.
4. Open the document in the review queue, verify the extracted page count /
   metadata, then **Publish** as an authorized human. Publishing is what makes it
   retrievable — there is no auto-publish.
5. Build/refresh the index (page-aware chunks + lexical/embedding index). Confirm
   the document status shows indexed with a non-zero chunk count.
6. Use the console probe to confirm a question returns a citation with the
   correct **title + page**, and that a foreign-tenant probe returns nothing.

**Path B — Platform knowledge (visible to every tenant, curated by Principal)**
1. Sign in to the **Principal** panel with `platform.knowledge.manage`.
2. Open **Knowledge Governance** (`/principal/knowledge-governance`) and create a
   **platform-scope** collection.
3. Upload → review → **Publish** → build/reindex. Platform sources are included in
   retrieval only when published and `include_platform` is on.
4. To withdraw a source, **Revoke** it: it leaves retrieval and its chunks are
   purged. Platform documents never mix with tenant documents.

**Reading a source PDF:** open the citation link — it points at
`/lba/knowledge/documents/{document}/download`, an authenticated, tenant-scoped
stream. Every request re-derives the caller's scope; a link cannot leak another
tenant's document.

---

## 6. Model & index configuration

| Concern | Key | Default | Notes |
|---|---|---|---|
| Umbrella switch | `LBA_KNOWLEDGE_ENABLED` | `true` | Retrieval still needs the tenant module active. |
| Private disk | `LBA_KNOWLEDGE_DISK` | `lba-knowledge` | `storage/app/private/lba-knowledge`, no public URL. |
| Intake bounds | `LBA_KNOWLEDGE_MAX_KB` / `_MAX_PAGES` | `51200` / `1000` | Reject over-size / over-page earlier. |
| Chunking | `LBA_KNOWLEDGE_CHUNK_CHARS` / `_OVERLAP` / `_MIN_CHARS` | `1200` / `200` / `40` | Chunks never cross a page. |
| Retrieval | `LBA_KNOWLEDGE_TOP_K` / `_MIN_RELEVANCE` / `_MAX_PROMPT_CHARS` | `6` / `0.12` / `6000` | Bounds the answer surface. |
| Platform sources | `LBA_KNOWLEDGE_INCLUDE_PLATFORM` | `true` | Only published platform sources. |
| Embeddings | `LBA_KNOWLEDGE_EMBEDDING_PROVIDER` | `none` | `none` = deterministic lexical index. |

**Generation model** is the EXISTING SINA provider (`lba.sina.*`:
`ollama` / `openai_compatible`) — unchanged by WS9. **Embeddings** are a
SEPARATE, optional provider:
- `ollama` — default model `bge-m3`, dimensions **1024**
  (`LBA_KNOWLEDGE_EMBEDDING_OLLAMA_MODEL/_DIMS`).
- `openai_compatible` — default model `text-embedding-3-small`, dimensions
  **1536** (`LBA_KNOWLEDGE_EMBEDDING_OPENAI_MODEL/_DIMS`, key
  `LBA_KNOWLEDGE_EMBEDDING_OPENAI_KEY`).

The model id, version and dimensions are **pinned**, so a reindex is exact. Enable
an embedding provider ONLY after its availability, licence and dimension are
verified. An embedding provider is never required for an answer: on failure
retrieval falls back to lexical search.

---

## 7. Private gateway requirements

SINA and the optional embedding endpoint must stay **private**. Before enabling
SINA in production:

- No public Ollama bind and no unauthenticated `/search` endpoint. The AI host
  (trial: a Mac mini) is reached through a protected gateway.
- Gateway protected by service tokens or mTLS / Cloudflare Access, a network
  allowlist where appropriate, signed scope + expiry, a correlation ID and replay
  prevention.
- Raw PDFs and chunks stay on the private disk; a retrieval/index service (if
  ever added) must be independently authenticated and tenant-scoped and must never
  accept a client-chosen tenant DB.
- No secrets hard-coded: the gateway URL, key id and secret come from the
  environment and the host must be explicitly allowlisted.
- A kill switch / rate limits and bounded uploads/pages remain in force.

---

## 8. Optional Open WebUI interoperability (manual only)

Open WebUI is **optional and separate**. Do **not** treat a shared Open WebUI
collection as a SaaS tenant-isolation boundary. The supported position is:

- Verify the actually-installed Open WebUI version and its API **before** relying
  on any import/export path (this was not verified here — treat it as unverified).
- Prefer the LBA Cloud-owned KB lifecycle (upload → approve → publish → revoke)
  and its RBAC/tenant scope; use Open WebUI, if at all, only as a private,
  manual import/export aid, never as the retrieval authority.
- Keep any existing Open WebUI on private access. No public exposure.

---

## 9. Manual sandbox UAT (operator, on https://dev.lbacloud.com)

1. Ensure `knowledge_base` is active for a **synthetic** staging tenant, and SINA
   is either OFF or backed by a signed private gateway.
2. Ingest one synthetic, public-domain, selectable-text PDF (see §5). Confirm the
   page count matches and metadata is captured.
3. Confirm a **scanned** PDF is rejected clearly and is not marked parsed.
4. Publish the document; confirm it becomes retrievable only after publish.
5. Ask a grounded question in the Patient SINA chat; confirm the answer cites the
   real **title + PDF page** and links to the private stream.
6. Ask an unanswerable question; confirm the "no approved source found" abstention
   (no fabricated citation).
7. Ask a prompt-injection question; confirm the system safety prompt holds and PDF
   text does not become an instruction.
8. As a second tenant, confirm the first tenant's document is not retrievable
   (cross-tenant probe returns nothing) and a foreign download link is refused.
9. Trigger a provider timeout; confirm the wallet reservation is released and no
   assistant reply is invented; retry confirms exactly-once capture.
10. Revoke the source; confirm it leaves retrieval and its chunks are purged.
11. Run `lba:knowledge:evaluate --tenant=<id> --actor=<id> --purge`; confirm exit 0
    and `failed == 0` for id/en/ms-MY.

---

## 10. Backout plan (non-destructive)

WS9 is additive on BOTH sides. Centrally it adds the `knowledge_*` tables. On the
existing tenant databases it adds a single nullable column,
`consultation_messages.metadata` (JSON), via the tenant migration
`database/migrations/tenant/2026_12_01_600005_add_metadata_to_consultation_messages_table.php`
(WS9.C). No tenant table is created, renamed or dropped and no existing tenant
column is altered; every existing transcript row stays valid (the column is
nullable and null means "no approved source"). Rollback is therefore a deployment
revert — never a data reset.

The tenant column is applied by the normal tenant migration path
(`php artisan lba:tenant:migrate:registered`), NOT by the central
`php artisan migrate`. The preflight `migrate:status` above covers the central
connection only; confirm the tenant column separately, e.g.
`Schema::connection('<tenant>')->hasColumn('consultation_messages', 'metadata')`
or inspect the tenant `migrations` table for
`2026_12_01_600005_add_metadata_to_consultation_messages_table`.

1. Record the failing SHA and the last known-good SHA.
2. Disable retrieval without a redeploy if needed: set `LBA_KNOWLEDGE_ENABLED=false`
   (SINA keeps working, just ungrounded), or disable the `knowledge_base` module
   for the affected tenant.
3. Redeploy the previous immutable release SHA (or `main`) via the existing
   `deploy-staging.sh` path. **Never** `migrate:fresh`.
4. The WS9 migrations are additive; leaving the central `knowledge_*` tables in
   place is safe. Do **not** roll the schema back destructively. If removing a
   table is ever required, do it as a reviewed, separate migration with a backup
   taken first.
5. To purge indexed content non-destructively, **Revoke** each document (chunks
   are purged) and archive collections. Delete any `LBA_KNOWLEDGE_EMBEDDING_*`
   credential from the environment.
6. Verify `php artisan lba:staging:health` and the read-only preflight again, and
   confirm `Babul_Jakarta` and `APP_KEY` are untouched.

---

## 11. Known blockers / open dependencies

- Draft PR **#33** (WS6) remains open against `main`; WS7 and WS8 are already in
  its branch. WS9's single PR targets `feature/v3-ws6-lba-capture` and cannot
  merge until #33 does.
- Any physical AI hardware / model (Ollama generation model, embedding model) is
  **unverified** here — availability, licence and dimensions must be confirmed on
  the real private host before enabling embeddings.
- Open WebUI interoperability is documented as **optional/manual only** and its
  installed version was not verified.
- Production SINA must stay OFF until a signed private gateway exists.
