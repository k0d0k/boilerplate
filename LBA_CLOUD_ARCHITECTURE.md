# LBA CLOUD — System Architecture v3.0 with v3.1 Addendum

**Status:** Canonical product architecture · **Owner:** Babul Syiffa · **Baseline date:** 7 November 2026

The [v3.1 TailAdmin and Principal-only CRUD Generator addendum](docs/ARCHITECTURE_ADDENDUM_V3_1_TAILADMIN_CRUD_GENERATOR.md) governs the staff UI transition and CRUD generation.

## 1. Product mission

A multi-brand, multi-clinic SaaS platform for patient management, laboratory/live-blood-analysis (LBA) microscopy image examinations, supervised AI-assisted image analysis, patient bookings, consultations, products and financial workflows. **Not** an automated medical diagnosis system. Three initial brands: **Babul Syiffa, NuBiom, Biodam**; the platform must permit more brands without a code fork.

## 2. Verified target stack

| Layer | Selection | Boundary |
|---|---|---|
| Backend | Laravel 13, PHP **8.4** target | Framework in `vendor/` remains untouched |
| Internal web UI | TailAdmin Laravel with Blade, Tailwind CSS and Vite for Principal and Clinic; Livewire 4 where needed | Existing Filament 5 screens remain available during the page-by-page transition. Patient stays on Filament in this phase. |
| Panels | `principal`, `clinic`, `patient` | Distinct route prefixes, navigation, dashboards and server-side access rules |
| UX | Shared TailAdmin staff components with a collapsible left sidebar; specialized Livewire/JS microscope viewer | Migrate the microscope UI as a workflow after its permissions and functional parity are verified. |
| CRUD Generator | Principal Super Admin only; allowlisted central master data | Server-authorized preview/export; code generation in development and review through Git. Generated screens use their resource policies. |
| Modular architecture | Modular monolith; `nwidart/laravel-modules` **^13**, subject to runtime integration tests | Business code in `Modules/*`, platform shared contracts in `app/Platform/*` |
| Tenancy | `stancl/tenancy` **^3.10** candidate; application database-per-tenant bridge | Separate clinical DB per independent tenant |
| Data | Central relational DB + tenant clinical DBs; Redis queues/cache | Do not cross-join tenant and central DBs |
| Images | Private S3-compatible object storage | No directly public patient-image paths |
| AI vision | Private FastAPI computer-vision service (Mac mini M4 trial) | No medical identity transferred to model; mock first |
| SINA | Separate private text assistant service | Does not substitute for CV model or human reviewer |
| API | Versioned `/api/v1` with scoped auth | Portal / GoodBarber / trusted integrations |
| Payments | Provider adapters: Midtrans, PayPal, iPaymu | Live transactions gated until contracts/security review |
| Locales | `id`, `ms-MY`, `en` | Independent of timezone and currency |

Dependency compatibility must be **verified and pinned by Composer/NPM lockfiles in Phase 1A**. A declared compatibility range is not a passing integration test.

## 3. Domain boundaries

**Principal** = Babul Syiffa as platform operator. **Brand** = marketing identity. **Tenant** = legally independent partner with its own isolated clinical database. **Branch** = tenant's operating clinic. **Service Location** = fixed/temporary/event/mobile booking venue and **is not automatically a branch**.

- One tenant may manage multiple brands and branches; its branches share **that tenant's** clinical DB and are separated by branch assignment/policy.
- Two independent tenants using the same brand never share a clinical DB.
- Principal dashboard sees aggregate business/usage only; no routine identifiable patient data.
- Tenant context is validated on every HTTP request, Livewire action, job, file read, API and cross-panel navigation.
- Patient identity is tenant-bound by default. No cross-tenant health-record sharing unless later authorized through an explicit privacy workflow.

## 4. Conceptual architecture

```text
                   ┌─────────────────────────────────────────┐
                   │ Laravel 13 LBA CLOUD PLATFORM           │
                   │  Identity | AuthZ | Tenancy | Audit     │
                   │  Modules | Entitlements | Billing API   │
                   └────────────────┬────────────────────────┘
                ┌───────────────────┼───────────────────┐
                │                   │                   │
           PRINCIPAL             CLINIC              PATIENT
        TailAdmin target    TailAdmin target     Filament portal
         platform data        tenant context       self scope only
                └───────────────────┼───────────────────┘
                            Domain Modules
           Patients | LBA | Booking | Consultation | Commerce
                ┌───────────────────┼──────────────────┐
             Central DB        Tenant DB A       Tenant DB B ...
          brands/plans/ledger  clinical/images   isolated clinical
                │                   │
          Payment Adapters   Private object store
                                    │
                             Queue + private API
                                    │
                            FastAPI CV / SINA
```

## 5. Three panel contract

- `/principal` (internal platform governance), `/clinic` (staff within verified tenant and allowed branches), `/patient` (self-service patient/customer); these paths are intended application route prefixes, subject to approved hosting topology.
- Principal and Clinic share TailAdmin components, with separate menus, dashboards and panel access. Patient uses its current Filament portal. Domain services and security contracts serve all panels.
- Only an active user with an active `principal_super_admin` assignment can open the CRUD Generator, preview, or export. The generator covers allowlisted central master data.
- One public **staff** login form without selecting Super Admin/Pemilik Klinik; server resolves role and authorized context. Patient authentication is separated by panel authorization/session boundaries; no privileged role selection on public forms.
- User with multiple authorized tenant roles can use a safe membership/context picker **after authentication**, never select arbitrary tenant ID.
- Route existence, menu visibility and UI feature gates are **not** authorization. `canAccessPanel()`, policies, gates and tenant scoping must reject forbidden requests server-side.

## 6. Modular monolith contract

```text
app/Platform/{Identity,Tenancy,Authorization,ModuleRegistry,FeatureGate,Audit,
              Contracts,Billing,Integrations,Localization}/
app/Providers/Filament/{PrincipalPanelProvider,ClinicPanelProvider,PatientPanelProvider}.php
Modules/{Patients,ClinicLocations,Bookings,LBA,Consultations,SINAConsult,
         Commerce,Payments,Wallet,Reports}/
```

Each module publishes explicit service interfaces, models, migrations, policies, events, jobs, translations, tests, and UI registrations per eligible panel. Filament registrations serve the existing screens. Migrated staff pages use TailAdmin routes and permission-aware navigation. The Principal Module Manager eventually manages trusted releases; upload is staged, signed/hash verified, reviewed and deployed via controlled release mechanism. No direct arbitrary PHP execution on upload.

Effective entitlement: `installed AND healthy AND entitled AND subscribed AND authorized AND panel_allowed`.

## 7. Key process rules

- LBA examinations support **Manual (0 AI credits)**, **AI**, and **Hybrid** in a **single patient/examination history**. Manual annotations are independent of AI job existence.
- Original images are immutable and private; overlays/detections are versioned separately. Publish requires authorized qualified human review, never silent auto-publish or auto-diagnosis.
- Tenant AI credits and patient consultation credits are **different wallets/ledgers**. Ledger is append-only and usage reserve/capture/release is idempotent. No double debit.
- Flexible booking venues and tentative events require explicit confirmation/capacity and atomic reservations.
- Payment state changes require **verified server-side gateway notification/status**, never a browser redirect alone.
- All data is synthetic in development and UI demos; non-production AI predictions must not be presented as clinical fact.

## 8. Phase boundaries

The following milestones record the v3.0 baseline. The staff UI migration follows the [v3.1 addendum](docs/ARCHITECTURE_ADDENDUM_V3_1_TAILADMIN_CRUD_GENERATOR.md).

**0:** one clean docs baseline. **0.5:** visual references already established; Filament implementation must validate visual equivalence. **1A:** real Laravel/Filament three-panel foundation. **1B:** auth, policy, localization, module registry & UI framework checks. **2:** central/tenant DB isolation. **3–9:** clinical MVP + AI, credit, report, API and staging. **10–14:** booking, commerce, payments, consultation/SINA, trusted module manager. Detailed order in `docs/IMPLEMENTATION_PLAN.md`.

## 9. Deployment and recovery

TRAE Cloud is the build environment, **not** the sole backup. Export each approved phase to an independent local ZIP before proceeding and eventually push to private `viacom-id/lba-cloud`. Do not touch the unrelated existing HMS. Later staging should run on a suitable VPS/cloud with persistent workers, private object storage and central/tenant DB backups; no production deployment without owner approval.
