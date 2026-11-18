# Dependency Reference (verified 7 November 2026)

These are initial **compatibility references**, not proof that the combined application has been installed/tested. Resolve exact package versions in `composer.lock` / `package-lock.json` during Phase 1A and smoke-test compatibility.

| Package | Constraint / status |
|---|---|
| Laravel 13 | PHP >=8.3; target **PHP 8.4** |
| Filament 5 | PHP >=8.2; Laravel >=11.28; Tailwind **>=4.1**; multi-panel supported |
| Livewire 4 | Companion for Filament 5; verify exact lockfile |
| `nwidart/laravel-modules` | Official ^13 line for Laravel 13; check with Filament module registration |
| `stancl/tenancy` | v3.10.x supports Laravel 13; **custom Filament tenant-DB bridge** is still required |

Source links:
- https://laravel.com/framework/docs/deployment
- https://github.com/laravel/docs/blob/13.x/releases.md
- https://filamentphp.com/docs/5.x/introduction/installation
- https://github.com/nWidart/laravel-modules
- https://laravelmodules.com/docs/13/getting-started/installation-and-setup
- https://tenancyforlaravel.com/docs/v3/installation/

Filament has multi-panel support; Filament's usual tenancy scoping does **not by itself** prove isolated clinical databases. A tenant connection and authorization bridge is a custom LBA CLOUD responsibility and must be tested before clinical data exists.
