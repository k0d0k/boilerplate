# Upgrade-Safe Modules, Entitlements, Trusted Distribution

## Platform vs modules

Keep Laravel `vendor/` untouched. `app/Platform/*` owns identity, tenancy, authorization, registry, entitlement, audit, shared contracts and integrations. Each `Modules/*` business package owns its service, migrations, policies, jobs, translations, resources, tests and public events. Package candidate `nwidart/laravel-modules` **^13**; its package/autoload/install behavior must be smoke-tested with **Filament 5** before treating it as selected implementation.

**Rule:** core framework upgrade to Laravel 14+ is a **tested migration**, not guaranteed zero change. Use staged Composer dependency review, optional Laravel Shift, integration tests, backup and rollback.

## Module-to-panel contract

Each module declares:

```yaml
key: patients
version: 1.0.0
min_platform: '3.0'
filament_panels: [clinic]
requires: [platform.identity, platform.tenancy, platform.authorization]
permissions: [patients.view, patients.manage]
translation_locales: [id, ms-MY, en]
```

An application-level `FilamentRegistration` implementation registers Resources, Pages, Widgets, navigation and policy requirements **per allowed panel**. Shared domain services and APIs are independent from Filament page implementations. No module is allowed to register directly into an unauthorized panel.

Effective feature access: `installed && healthy && entitled && subscribed && authorized && allowed_panel` with tenant/branch/brand scope. Fail closed at route/Livewire/action/API/job level; never rely only on sidebar visibility.

## Principal Module Manager — planned Phase 14

- Browse versions, dependencies, health, publisher and deployment history.
- Upload an approved **signed + checksum-verified** package to private quarantine/staging. Uploading does **not** execute PHP or perform migrations.
- Verify approved publisher, platform/Filament/Laravel compatibility, module dependencies, tests and a rollback plan.
- Execute install/update/migration through **isolated controlled release pipeline**, not ordinary HTTP request.
- Enable/disable globally and independently grant entitlements by brand/tenant/branch/plan/role.
- Disabling never silently deletes clinical, financial, or patient records. Failed activation can roll back.
- Audit all module installation/permission changes; block unsigned arbitrary packages by default.

## Early implementation required

Even though the UI Module Manager ships later, Phase 1/2 **must already implement** module boundaries, module registry interface, FilamentRegistration and a functioning server-side FeatureGate. Patients and LBA should be real modules, not dumped into monolithic `app/Http/Controllers` as ad-hoc core code. Phase 1A proves one sample module registers in only its approved panel.
