# Software Requirements Specification — v3.0

## Purpose and products

LBA CLOUD serves (1) the **Principal** operating the SaaS business, (2) independent **Clinic/Tenant** organizations with branches and authorized staff, and (3) patients/customers using self-service. This is operational microscopy and AI decision-support; **no unvalidated diagnosis**.

## Deliverable tiers

| Delivery | Required capability |
|---|---|
| **Foundation — Phase 1A/1B** | Laravel/Filament three panels, premium theme, auth, i18n, modular registry interfaces, permissions |
| **Clinical MVP — Phases 2–9** | Central/tenant DBs, onboarding, staff/branch, patients, consent, LBA manual/AI/hybrid, private uploads, asynchronous AI/mock, credit ledger, human review, reports, basic appointment, `/api/v1`, security and staging |
| **Full product modules — Phases 10–14** | Flexible location/event booking; shop/orders; Midtrans/PayPal/iPaymu; expert/SINA consultations with patient wallet; vetted module upload/install/activation |
| **Extensions later** | Full accounting/GL, CRM marketing automation, advanced POS beyond approved scope, full EHR interoperability, native/offline app, unapproved clinical integrations |

## Principal scope

- Create brands, independent tenants, domain mappings and authorized organizational memberships; configure plans and feature entitlements.
- Central subscription and AI usage/ledger analytics, module rollout, non-clinical aggregates, payment provider settings, audit and system health.
- No routine patient identifiable data access or cross-tenant query tools.

## Clinic scope

- Branch and staff management with multiple role memberships; default tenant and authorized branch context.
- Unified patient register with clinic number, consent, history, exam, appointment, reports and audit.
- LBA exam manual/AI/hybrid; multiple private images, capture metadata, reviewer inbox, immutable published report versions.
- AI analysis jobs through queue/mock/private FastAPI; failure/retry and metered credits.
- Daily schedule and appointments; future flexible event/session/location booking, expert consultations, shop, inventory, refunds, clinic financials.

## Patient scope

- Own account/profile, bookings, reports published for self only, expert/SINA consultations, patient credit wallet, product purchases, payment/order history.
- Find LBA exam locations by optional browser location or manual city/postcode; fixed branch, pop-up, event, mobile service.
- No implied patient access to pre-review AI results or raw clinical objects.

## Must-have process invariants

1. Role and tenant access checked server-side for **each** Filament resource, page, Livewire action, API, file and queue job.
2. Manual exam can save human annotations **without an AI job** and never debits AI credits.
3. AI/Hybrid uses separate asynchronously tracked jobs with schema/model version; report cannot publish before qualified human review.
4. Patient clinical data belongs to the tenant DB; no automatic sharing across independent tenants or brands.
5. Wallet ledger is immutable; available/reserved credits reconcile; duplicate callbacks never double-debit.
6. Booking cannot over-reserve slot capacity, and tentative event is never automatically confirmed.
7. Payment states rely on provider-verified server updates, not frontend redirect parameters.
8. Module enable/disable never silently deletes data, and disabled modules are blocked at backend level.
9. All modules have translation keys (`id`, `ms-MY`, `en`) and a consistent Filament UI; currency/timezone independent from language.
10. Use only synthetic data during development, screenshot QA, AI testing and demo.

## Non-functional acceptance

- Desktop 1440/1024 and mobile 390/360; no horizontal page overflow; keyboard-accessible menus/forms.
- Left staff sidebar collapsible at ~256/72px; dark microscopy workspace and explicit zoom/pan controls.
- Encryption at transit/rest for sensitive fields and objects, signed short-lived URLs, redacted structured logs.
- Tested restore per tenant; persistent queue and failed job recovery; monitor AI service offline behavior.
- Device, tenant and role changes must never bypass policy; admin MFA required before production.
