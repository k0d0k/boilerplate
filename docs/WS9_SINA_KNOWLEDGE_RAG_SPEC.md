# LBA Cloud v3.0 — WS9 SINA MEDCloud Knowledge Base & RAG

**Status:** Locked implementation specification. Coding has NOT begun.
**Repository:** `viacom-id/lba-cloud`
**Existing branch:** `feature/v3-ws9-sina-knowledge-rag`
**Parent:** `feature/v3-ws8-commerce-payments`, commit `e5803ba`.
**PR chain (verified after this specification was initially committed):** WS6 #33 is Draft against main; WS7 #34 and WS8 #35 were merged into the WS6 branch, NOT main. WS9 must open ONE Draft PR targeting `feature/v3-ws6-lba-capture`, until that branch is merged to main. No automatic merge/deploy.

## Goal

SINA MEDCloud must be able to answer questions **grounded in approved PDF e-books, journal articles and clinical educational references** with accurate source title, version and PDF page citations. The approved documents act as a **retrieval-augmented knowledge base (RAG)**: they do **not** retrain the base model's weights. The experience must coexist with the existing Ollama/OpenAI-compatible SINA chat, patient consent, patient-wallet billing, tenant isolation, medical safety constraints, human consultations and existing LBA image/vision workflows.

### Existing components to REUSE (read them first)

- `Modules/Consultations/Services/SinaChatService.php` — patient conversation, explicit AI assistance consent, reserved/captured/released wallet credits, audit, tenant transcript.
- `Modules/Consultations/Ai/SinaProviderResolver.php`, `OllamaChatProvider.php`, `OpenAiCompatibleChatProvider.php`, contracts and DTOs.
- `Modules/Consultations/Services/SinaConsentService.php`, `SinaWalletService.php`; Patient SINA Filament page + REST controller.
- `app/Filament/Principal/Pages/AiProviderSettings.php`; module feature gates, RBAC, tenant database binding.
- `services/lba-vision-gateway` handles image analysis and is NOT a substitute for a medical text retrieval/embedding pipeline.
- Existing PDF/medical report private storage pattern and `docs/API_AI_INTEGRATIONS.md`.

Do not replace SINA with a second chatbot or a public Open WebUI endpoint. Do not expose Ollama or the AI server publicly without a protected gateway. Do not copy patient records into a global knowledge index.

## Architecture and security invariants

1. **Source ownership:** Two explicit document scopes: (a) platform-approved general educational knowledge, (b) clinic/tenant-approved knowledge visible only within that tenant. No cross-tenant retrieval; Principal may manage non-patient platform sources without routine clinical PHI access. Patient-specific records do NOT become general RAG sources.
2. **Storage:** Document metadata, hashes, permissions, approval status and audit remain Laravel-controlled; raw PDFs/chunks/indexes remain private. A Python retrieval/indexing service MAY run on the private AI host (Mac mini for trial) but must be independently authenticated and tenant-scoped. No untrusted client may choose the tenant DB or retrieve raw private documents.
3. **Model usage:** Distinguish chat-generation model, embedding model and optional vision model. `sina-med-cloud`/MedGemma via Ollama is a configurable generation provider, not automatically a reliable embedding model. Pin embedding model/version, dimensions, chunking policy and index generation to support exact reindexing.
4. **Medical:** Provide education and supported source explanations, not autonomous diagnosis, prescriptions, dosing or report release. Explicit uncertainty, escalate red flags to qualified clinicians/emergency care; show sources when grounded. Unverified claims from a document must never be presented as clinically validated fact.
5. **Document processing is untrusted:** Harden for malicious PDFs, decompression bombs, embedded commands, links, prompt injection, and copyrighted/unauthorized material. Document text is DATA, never a system instruction. Do not OCR by default; begin with selectable-text PDFs. Don't upload proprietary articles without rights/approval.
6. **Operational control:** Kill switch and rate limits; job queue with retries, bounded PDF sizes/pages; checksum dedupe; versioning; deletion/revocation and eventual purge of indexed chunks; no model calls before retrieval authorization. Keep metadata and patient identifiers out of external embedding logs.
7. **Billing/consent:** Reuse *existing* per-patient SINA consent, consultation, wallet and usage ledger. Model query+retrieval+completion failures must release reservations, and per-turn billing must not silently double-charge on retry. Track retrieval operation metadata without logging private patient messages/PHI. Do not change LBA tenant-AI credits.

## WS9.A — Knowledge source registry, secure PDF intake

- Build `Modules/KnowledgeBase` (or a well-contained existing module namespace) with versioned knowledge collections, documents, scopes, title/author/publication/date/license/source URL, tenant owner when applicable, language (id/en/ms-MY), page count, SHA-256, uploader, verification/approval actor and timestamps.
- Each document goes through `uploaded → processing → review → published → archived/revoked` with audit. No document is retrievable until an authorized human publishes it. Reject ambiguous tenant ownership, unauthorized cross-scope promotion and public image/media routes.
- Secure Filament management: Principal can curate platform reference sources without gaining clinical data; Clinic Admin can manage tenant reference documents if explicitly entitled, and cannot make platform-global content. Use private auth streaming for PDFs and page references.
- Secure PDF text extraction preserving actual original page numbers. Verify text extractability; fail clearly on scanned/unreadable PDFs rather than pretending to have parsed them. Preserve immutable source; record source metadata and extraction errors without dumping full documents into logs.
- Idempotent queue ingestion with strict resource limits, checksum deduplication by scope, full version history, and clean archive/purge semantics.

## WS9.B — Embeddings, retrieval and tenant-safe citation engine

- Index approved page-aware chunks with immutable doc/version/page linkage. No false page citations; store chunk hashes and embedding version. Choose a fit-for-purpose multilingual (Indonesian/Malay/English) embedding model; verify actual availability and license first. Allow deterministic fallback to lexical search when embedding service is disabled.
- Retrieval must be authenticated by trusted server-side tenant context and source visibility; add platform sources only when explicitly globally published. Enforce top-k, min relevance, maximum prompt budget, idempotent reindex, and version invalidation.
- Retrieval/index service contract: private HTTPS protected by service tokens or mTLS/Cloudflare Access, network allowlist as appropriate, signed scope/expiry, correlation ID, replay prevention; no public Ollama bind. Do not provide a raw unauthenticated `/search?tenant_id=...` API.
- Every answer citation resolves to an AUTHORIZED document/version and PDF page, or source not available. Do not fabricate bibliography/page numbers when no relevant chunk exists. A private citation link must authorize the acting user's scope on each request.
- Benchmarks and tests in id/en/ms-MY: exact-source citation, missing source, conflicting sources, revoked source, irrelevant source, cross-tenant attempt, prompt injection in PDF, and embedding provider failure.

## WS9.C — Integrate retrieval into EXISTING SINA service

- Extend `SinaChatService` composition before provider completion: tenant-approved and optional platform-approved retrieval, stable citation attachment, grounding instruction + provenance metadata, calibrated abstention if retrieval is weak. Keep existing system safety prompt and no patient/clinician impersonation.
- RAG must NEVER allow PDF content to override system instructions, collect credentials, auto-execute tools, request provider changes, change billing or bypass qualified clinical review. Treat all retrieved text as quoted/untrusted context.
- Source citations must be structured response metadata available in both Patient Filament SINA chat and existing REST API; display title + document date + original PDF page; links use private authenticated document routes only.
- Maintain original chat history/consent and wallet idempotency. Address failure atomicity: the current `SinaChatService` appends a patient message before reserving credits, so ensure retry-safe turn idempotency and clear failure status when reservation/provider/retrieval fails. Do not double-charge or invent an assistant reply.
- Provide `No approved source found` fallback when needed and a clear educational, non-diagnostic disclosure. Do not invent verified sources or claim the model was fine-tuned by uploading files.

## WS9.D — Knowledge ops, evaluation, safe Open WebUI interop, release handoff

- Create **Principal/Clinic knowledge-management UI**: collection table, approval, publish/revoke, indexing progress/status, retry failed jobs, version comparison, retrieval audit (no patient text), example Q&A and citation verification.
- Open WebUI integration is **optional** and separate: document a manual import/export interoperability path only AFTER verifying its actual installed version/API. Prefer LBA Cloud-owned KB lifecycle and access control; never trust a shared Open WebUI collection to enforce SaaS tenant isolation without proof. Keep existing Open WebUI on private access.
- Support structured evidence evaluation with at least 20 synthetic/non-patient Q&A prompts per id/en/ms-MY (including no-answer, conflicting source, citation page correctness, malicious prompt injection); publish test harness + pass/fail report, not invented accuracy percentages.
- End-to-end functional tests: uploads, approval, indexing, search, citations, private read, revoked/deleted docs, queue retry, cross-tenant, panel roles, patient self-only API, provider timeout, wallet reservation/capture/release, token-bounded responses.
- Provide staging UAT for `https://dev.lbacloud.com` with a synthetic public-domain/authorized PDF, no clinical records and no real patient uploads. Keep SINA OFF by default if no signed private gateway exists.
- Run focused PHPUnit, complete PHP suite, Pint on touched code, `lba:localization:audit --strict` (id/en/ms-MY), Python retrieval service tests when applicable.
- Deployment remains operator-initiated, backup central+tenant+private media+index before migration, **no automatic merge or deployment**, no APP_KEY reset, no dummy users, no live payments and no changes to existing `Babul_Jakarta` data.

## Git workflow — mandatory

1. Fetch and checkout the **existing** `feature/v3-ws9-sina-knowledge-rag` branch and read THIS document. Confirm HEAD is a descendant of WS8 `e5803ba` and clean before editing; do not recreate branch.
2. Implement WS9.A and commit/push. Then WS9.B, WS9.C, WS9.D, separately committing/pushing each with focused tests.
3. Create exactly **ONE Draft PR** targeting `feature/v3-ws6-lba-capture` (which now contains WS7 and WS8); do not target `main` until parent PR #33 is merged and the full diff is revalidated. Do not merge, deploy or rebase other teams' branches.
4. Final report: per-step SHA, files, tests, limits, PDF ingestion guide, model/index config, citation accuracy checks, UAT steps, known blockers and private gateway requirements. Label any untested physical hardware or model as unverified.

**Definition of done:** A clinic can upload and approve a text-based PDF (with rights to use it), SINA answers grounded questions with correctly authorized page citations, refuses to claim a citation without evidence, never leaks another tenant's documents, and does not double-charge on retry.
