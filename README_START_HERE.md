# LBA CLOUD — Clean v3.0 Project Baseline

**Canonical project:** LBA CLOUD — AI-assisted multi-brand, multi-clinic SaaS  
**Technology:** Laravel 13 · PHP 8.4 · Filament 5 · Livewire 4 · Tailwind CSS 4.1+  
**Baseline date:** 7 November 2026  
**Owner / Principal:** Babul Syiffa  
**Development:** TRAE Work Cloud; **official source repository:** `viacom-id/lba-cloud` (sync when explicitly authorized)

## This package is the single source of truth for the new TRAE task

This package is a clean specification for *one new application*. It has no historic addenda, technology migration instructions, old Tabler implementation constraints, or conflicting UI baselines. It reconstructs the product requirements and design decisions previously agreed with the owner. Some business/legal values remain deliberately configurable and are not invented.

**Read in this order:**
1. `LBA_CLOUD_ARCHITECTURE.md` — boundaries and target stack.
2. `docs/DECISIONS.md` — locked decisions and unresolved commercial policy.
3. `docs/PRODUCT_REQUIREMENTS.md` — functional product scope and MVP.
4. `docs/DESIGN_SYSTEM.md`, `docs/PANELS_NAVIGATION.md` — unified Filament UI.
5. `docs/DATA_ARCHITECTURE.md`, `docs/SECURITY_PRIVACY.md` — tenancy and safety.
6. `docs/MODULE_SYSTEM.md`, `docs/LBA_WORKFLOW.md`, `docs/FEATURES_BOOKING_CONSULTATION_COMMERCE.md`.
7. `docs/ROLE_PERMISSION_MATRIX.md`, `docs/API_AI_INTEGRATIONS.md`, `docs/LOCALIZATION.md`.
8. `docs/IMPLEMENTATION_PLAN.md`, `docs/ACCEPTANCE_MATRIX.md`, `docs/OPERATIONS_RECOVERY.md`.
9. `TRAE_NEW_TASK_START.md` — paste into the first new task, then `TRAE_PHASE1A_PROMPT.md` after a successful file audit.

## Non-negotiable operating rules

- Preserve all docs; never change the locked stack without a documented owner decision.
- Always work with **synthetic** patients and synthetic microscopy images in development.
- Never override the unrelated existing CodeCanyon / NuBiom HMS installations.
- One TRAE phase per checkpoint. Each phase ends with a runnable result, tests, a change summary, and **STOP**.
- **No automatic GitHub push, force push, merge, staging deploy, or production deploy.**
- Keep an exportable ZIP/checkpoint *outside* TRAE Cloud after each phase; the previous cloud workspace became unavailable.
- The visual references inside `reference/` depict visual intent only, **not** a mandate to use Tabler/Bootstrap.
- Do not install `tabler`, `bootstrap`, `adminlte`, `flux`, React, Vue or a second Alpine as competing internal UI dependencies.

## Scope checkpoints

**First deliverable (Phase 1A):** installed Laravel + Filament, three separately guarded panels, design theme, synthetic dashboards, i18n readiness, no clinical modules, and automated panel-isolation tests.  
**First usable MVP:** patient registration, manual LBA, private image storage, asynchronous mock/private AI, review and signed reports, tenant billing skeleton, API, security/staging checks.  
**Planned later modules:** locations and self-booking, expert/SINA consultations, store/POS, payments, uploadable trusted Module Manager; they are product requirements, not promises for Phase 1A.

## Files and integrity

`MANIFEST_SHA256.txt` lists hashes of the source Markdown documents. When loading this package into a new TRAE workspace, verify that the filenames and contents are present before running installers. If `docs/` is empty or missing, **STOP** instead of regenerating guessed documents.
