# Locked Decisions & Explicitly Unresolved Policy — v3.0 and v3.1

All **LOCKED** items below are approved product direction. The [v3.1 TailAdmin and Principal-only CRUD Generator addendum](ARCHITECTURE_ADDENDUM_V3_1_TAILADMIN_CRUD_GENERATOR.md) supersedes the staff UI parts of ARCH-01 and ARCH-02. The original decisions are recorded under v3.0 history.

## LOCKED — implementation architecture

| ID | Decision |
|---|---|
| ARCH-01 | **v3.1 replaces the staff UI selection.** Laravel 13, PHP 8.4 target and Livewire 4 remain the application foundation. Principal and Clinic target TailAdmin Laravel with Blade, Tailwind CSS and Vite. Existing Filament pages serve the transition. |
| ARCH-02 | **v3.1 replaces the three-Filament-panel target.** One application with separate Principal, Clinic and Patient panels at `/principal`, `/clinic` and `/patient`. Staff UI migrates per page. Patient continues under ARCH-08. |
| ARCH-03 | Modular monolith; module business logic separate from Laravel `vendor/`, upgrade-tested for future Laravel versions |
| ARCH-04 | Central platform DB plus **isolated clinical DB for each independent tenant**; branches share the tenant DB |
| ARCH-05 | Brand ≠ tenant ≠ branch; add brands by configuration; three starters Babul Syiffa/NuBiom/Biodam |
| ARCH-06 | Feature entitlement and RBAC enforced on server, per panel/brand/tenant/branch and plan |
| ARCH-07 | Single **staff** login without role-selection shortcuts; verified membership and secure context choice |
| ARCH-08 | Patient self-service Filament Panel; GoodBarber later only via `/api/v1`; public marketing pages may use Blade |
| ARCH-09 | Private Mac mini M4 FastAPI trial for vision; distinct SINA text assistant; no public Ollama endpoint |
| ARCH-10 | CRUD Generator at Principal for active accounts with an active `principal_super_admin` assignment only. Allowlisted central master data, server-authorized preview/export, development-time code generation and Git review. Generated screens retain their resource policies. |
| UX-01 | Navy `#14263D`, teal `#087F80`, light bg `#F6F8FB`, dark LBA viewer `#0C1725`; premium responsive design |
| UX-02 | Principal ≠ Clinic ≠ Patient navigation and dashboards; collapsible LEFT sidebar on staff panels |
| UX-03 | Approved historic screenshots are aesthetic reference only, no Tabler code reused at runtime |
| UX-04 | Locales `id` / `ms-MY` / `en` with tenant/brand default and user override |
| LBA-01 | One patient history for Manual/AI/Hybrid; manual analysis uses **zero AI credits** |
| LBA-02 | Human reviewer approval mandatory before report publication; operator cannot self-approve by default |
| FIN-01 | Distinct tenant LBA-AI wallet and patient consultation wallet; append-only entries, no double charging |
| FIN-02 | Midtrans/PayPal/**iPaymu** only as three planned payment adapters; verify server notifications |
| PRV-01 | No real patient data/images/credentials in TRAE prompts/tests/demo; tenant clinical isolation and private storage |
| OPS-01 | Existing NuBiom/CodeCanyon HMS untouched; no automatic GitHub push/deploy/merge |
| OPS-02 | Every phase must end with tests, evidence, audit, rollback/checkpoint and owner acceptance |

## v3.0 history — superseded staff UI decisions

- ARCH-01 previously selected Filament 5, Livewire 4 and Tailwind 4.1+ for the internal UI, with Laravel 13 and PHP 8.4 as the foundation.
- ARCH-02 previously selected three independent Filament panels with a shared theme and separate navigation. The panel boundaries continue under the v3.1 revision.

## Open v3.1 implementation choices

Select the TailAdmin Laravel edition and generator package during technical review. TailAdmin Laravel Free and an internal Artisan/stub generator are options. Master Brand is the planned first pilot. See the [v3.1 implementation plan](IMPLEMENTATION_PLAN.md#v31-staff-ui-migration).

## Policy still to be configured before the relevant feature goes live

These are **not missing architectural decisions**, and are intentionally not given fabricated prices/terms:

| Topic | Default safety / required decision gate |
|---|---|
| Price of AI credit and Hybrid billing | Admin-configured; explicit billing schedule before paid execution |
| SINA tariff, consultation fees, refund/failed-response policy | Business owner must set before Phase 13 live billing |
| Booking deposit, event no-show, cancellation terms | Business owner must set before accepting paid booking |
| Provider merchant accounts, supported currencies, settlement, taxes/shipping | Contracts/legal verification before Phase 12 live gateways |
| Health record consent, retention/deletion, cross-tenant patient data sharing | Legal/compliance review before production; cross-tenant sharing off by default |
| Specific reviewer qualification/credential policy by jurisdiction | Authorized reviewer policy; no self-approval by default |
| Chart package, Fortify/permission package versions | Verify documented compatibility with the application and the affected panel |
| Third-party module publisher approval | Trusted signed packages only; unsigned/untrusted packages rejected |

The system must permit these policies to be configured rather than hard-code guessed values.
