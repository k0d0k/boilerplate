# COPY INTO THE SAME NEW TRAE TASK AFTER BASELINE OK — PHASE 1A

LBA CLOUD v3.0 — IMPLEMENT PHASE 1A ONLY

Use the verified clean baseline currently loaded in this TRAE Cloud workspace. The owner approves implementation of Phase 1A **only**. Read docs/IMPLEMENTATION_PLAN.md and all relevant boundaries first.

INSTALL/BUILD:
1. Verify runtime/package compatibility and document exact versions.
2. Create a new Laravel 13 application targeting PHP 8.4 in a dedicated project subdirectory, preserving the baseline documents and reference screenshots.
3. Install Filament 5, Livewire 4, Tailwind CSS 4.1+ and a single LBA Cloud Premium custom theme. DO NOT install Bootstrap/Tabler/Flux/React/Vue or duplicate Alpine.
4. Register three DISTINCT panel providers with IDs and paths principal, clinic, patient. Make default panel access DENY BY DEFAULT; create only minimal synthetic permitted users/tests.
5. Shared color tokens navy #14263D / teal #087F80 / canvas #F6F8FB; dark microscope theme contract #0C1725. Distinct Principal governance dashboard, Clinic operational dashboard, Patient consumer placeholder; responsive left staff sidebar.
6. Establish Platform boundaries and a minimal FilamentRegistration contract for a sample module registered only to an allowed panel. No actual clinical module implementation yet.
7. Prepare language selection id/ms-MY/en and placeholder translated navigation.
8. Use synthetic demo data only; NO real patient data, live AI, paid gateway, tenant clinical DB migrations, or production credentials.

VERIFY:
- Application boots and all three panel routes load where authorized.
- Forbidden Principal/Clinic/Patient cross-access fails for direct requests and Livewire actions.
- Browser screenshots desktop 1440 and mobile 390 for Principal, Clinic, Patient; no page overflow; compare visual intent to reference PNGs.
- Verify no Bootstrap/Tabler duplicates; exact dependency versions recorded; asset build works.
- Export a complete Phase 1A ZIP for off-cloud backup excluding secrets/vendor/node_modules.

Do NOT push/merge to GitHub or deploy. Do NOT proceed to Phase 1B. Produce test logs, files changed, screenshots/preview and a concise phase completion report, then STOP for review.
