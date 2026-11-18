# LBA CLOUD Premium Design System — Staff UI Transition

## Intent

Premium, calm, clinical/technology presentation. TailAdmin Laravel provides the target Principal and Clinic interface under the [v3.1 TailAdmin and Principal-only CRUD Generator addendum](ARCHITECTURE_ADDENDUM_V3_1_TAILADMIN_CRUD_GENERATOR.md). Existing Filament screens keep their current theme during transition, including Patient. Historic HTML screenshots in `reference/` define the visual direction.

## Tokens

| Token | Value | Use |
|---|---|---|
| `lba.navy` | `#14263D` | Staff sidebar, primary text/dark branding |
| `lba.teal` | `#087F80` | Primary actions, active nav accents, data emphasis |
| `lba.canvas` | `#F6F8FB` | Light dashboard canvas |
| `lba.workspace` | `#0C1725` | LBA microscope page background |
| `lba.card` | `#FFFFFF` | Clean cards and tables |
| `lba.spacing` | 8px grid | Consistent page spacing |
| `lba.sidebar.open` | ~256px | Staff desktop, collapsible |
| `lba.sidebar.rail` | ~72px | Icon rail with tooltip |
| `lba.topbar` | ~64px | Staff panel topbar |
| `lba.radius` | ~12px | Cards; inputs/buttons consistent |

- Use shared Blade layouts and components with Tailwind CSS and a dedicated TailAdmin Vite entry for migrated staff pages. Existing Filament pages use the Filament theme entry. Load a single Alpine runtime on each page that uses Alpine and Livewire.
- Shared button/text/card/form/icon spacing tokens across Principal, Clinic and Patient; brand logos and **limited accent** configurable without fragmenting design.
- Light default admin panels; custom LBA viewer stays dark for microscopy. No forced global dark dashboard.
- Principal dashboard is **strategic governance** (tenants, subscriptions, usage, module health); Clinic is **operational** (patients, schedule, reviews, wallet); Patient is mobile-friendly consumer self-service.
- TailAdmin supplies staff layouts, forms, tables, filters, dashboards and notifications. The Principal-only CRUD Generator uses these components for allowlisted central master data. Clinic workflows use their module services and shared components. Existing Filament resources and custom pages serve features until their replacements are ready.
- Universal staff login has Email, Password, Remember Me, Forgot Password, Submit; **no role-selection or instant Super Admin buttons**.
- Localization via `__()` and module-owned translations; never hard-code front-end-only language IDs.
- A11y: WCAG 2.2 AA target; >=4.5:1 text contrast; focus visibility; accessible modal/tooltips; sidebar icon-only labels; touch controls that work at mobile sizes.

## Screen review checklist

- Capture actual TailAdmin Principal and Clinic screens as each panel migrates, plus the current Filament Patient portal when verifying the three-panel experience.
- Capture at 1440, 1024, 390 and 360; check table overflow within card only, not page.
- Confirm authorized sidebar items, nav collapse and header use keyboard, screen reader labels and reduced motion where appropriate.
- Verify that Principal and Clinic share staff components and typography while their navigation and dashboards remain distinct.
- Eliminate floating demo bubble and role-switcher from real UI. Prototype/synthetic warning may be unobtrusive in staging only.
