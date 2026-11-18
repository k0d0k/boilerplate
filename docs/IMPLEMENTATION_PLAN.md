# Development Roadmap and Phase Gates — v3.0 and v3.1

## v3.1 staff UI migration

The [v3.1 TailAdmin and Principal-only CRUD Generator addendum](ARCHITECTURE_ADDENDUM_V3_1_TAILADMIN_CRUD_GENERATOR.md) sets the staff UI target. Work on the existing Laravel application. Preserve existing model and schema contracts, staff login, tenant and branch authorization, and domain workflows. Patient remains on the existing portal in this phase.

| Order | Work | Acceptance |
|---|---|---|
| 1 | Establish the TailAdmin Principal layout, responsive left sidebar, topbar, shared Blade components and dedicated Vite entry. | Verify login, logout, locale, profile entry points and authorized navigation. |
| 2 | Build the Principal Super Admin generator and Master Brand pilot using the existing `App\Models\Brand` and `brands` table. | Validate allowlisted definitions and authorization on page, preview and export. Generate source in development for Git review. Verify Brand policy and preserve report identity, tenant relationships and Buka Tenant before switching the main menu. |
| 3 | Expand Principal to other approved central master data. | Verify parity and keep a route-level rollback for each migrated feature. |
| 4 | Migrate Clinic by operational workflow with verified tenant and branch scope. | Verify isolation and regression-test examinations, uploads, review, report release, payments and wallets against existing domain services. |

The TailAdmin edition and generator package remain open implementation choices.

## Rules

TRAE Cloud remains the development environment. Build **one phase at a time**, never claim completion without executable tests, screenshots/diff/evidence, and an off-cloud backup. **Do not publish or push automatically.** Preserve unrelated HMS.

## v3.0 baseline phase history

The original phase definitions below describe the v3.0 build. Use the v3.1 sequence above for the staff UI transition.

| Phase | Scope | Mandatory acceptance gate |
|---|---|---|
| **0 — Clean baseline** | Canonical documentation package; no addenda; architecture locked | Files verified in new workspace, no missing/corrupt docs |
| **0.5 — Visual baseline** | Prior premium screenshots remain visual reference | Filament theme to match after coding; not an HTML framework copy |
| **1A — Filament foundation** | Install Laravel 13 + Filament 5 + Livewire 4 + Tailwind 4.1+, three panel providers, shared premium theme, synthetic dashboards, rudimentary module-registration contract | Running app, per-panel route, screenshot + auth fail-closed tests, no Bootstrap/Tabler, version record |
| **1B — Identity/localization** | Staff login, patient portal auth boundary, allowed panel & tenant memberships, locale preference, central module registry/FeatureGate, test fixture users | No role selector, negative panel access tests, 3 locales, no privileged bypass |
| **2 — Tenancy & branch RBAC** | Central/tenant DB bridge, tenant provisioning, isolated roles, branch context & jobs, data boundaries | Tenant A cannot read B across web/Livewire/API/queue/storage; restore test |
| **3 — Patients & basic appointments** | Filament module Resources, staff, patient & consent, basic appointment | CRUD/policies/audit/role-scoped display |
| **4 — LBA & private upload** | Exam modes and metadata, immutable images, direct manual annotations | Manual without AI job/credits; private access tests |
| **5 — Async AI adapter** | Mock AI first; signed private FastAPI gateway; model metadata | Offline service graceful, retry/idempotency, no PII sent |
| **6 — AI credit wallet** | Subscription skeleton, tenant AI credits, immutable ledger | Reserve/capture/release concurrency + no double charge |
| **7 — Reviewer/report** | Approval inbox, vector overlay, report versions, downloads | Independent human review, published-only patient access |
| **8 — Patient API** | Scoped patient API and GoodBarber proof-of-concept | Identity/tenant scoping ADR before exposure; auth/privacy tests |
| **9 — Staging readiness** | Security/performance/backup/queue/recovery | Written go/no-go, permission and restore tests |
| **10 — Locations/booking** | Fixed/event/pop-up/mobile locations; sessions, capacity, waitlist, patient self-booking | No tentative false confirmation; concurrency slots |
| **11 — Commerce** | Products/catalog/cart/orders/stock/fulfillment | Correct merchant, tenant and inventory isolation |
| **12 — Gateway adapters** | Midtrans, PayPal, iPaymu | Webhook verification/replay/settlement tests; contracts before live |
| **13 — Consultations/SINA** | Human sessions, SINA chat, patient wallet, refunds | Separate wallets, tariff disclosure, failed AI no wrongful charge |
| **14 — Module Manager** | Signed upload, isolated deployment, activation/entitlements | No HTTP-code execution, rollback/data preservation, per-panel gates |

## Historical Phase 1A execution order

1. Audit workspace and verify file hashes; confirm not overwriting existing HMS/project resources.
2. Verify PHP 8.4, Composer, Node/NPM, extension requirements, current package compatibility.
3. Create Laravel 13 in a **dedicated clean application directory** without deleting baseline docs or old prototypes.
4. Install Filament 5 and a single custom Tailwind 4.1+ theme; no second Alpine/Bootstrap/Tabler.
5. Create `PrincipalPanelProvider`, `ClinicPanelProvider`, `PatientPanelProvider`; distinct routes/dashboards; deny unauthorized routes.
6. Implement minimal typed `app/Platform/*` contracts for identity/permissions/module registration and provisional tenant resolver that **fails closed** until Phase 2.
7. Add minimal sample module registration only on Clinic panel; not clinical data or new business feature implementation.
8. Configure `id/ms-MY/en` localization foundations and translated navigation sample.
9. Seed **synthetic non-clinical** users/content only; automated unit/feature negative panel tests and browser screenshots for principal/clinic/patient at 1440 and 390.
10. Record package versions, config choices, working URL/preview, test output, file diff; export phase ZIP; **STOP** for owner review.

## Definition of done

Each phase report states: DONE/FAILED/PARTIAL, actual commands, changed files, dependency versions, screenshots/preview, test results (including negative access tests), security notes, blockers, next step. A screenshot alone is not a functional acceptance test. If the tool cannot complete an operation, report honestly and stop; never regenerate all earlier work.
