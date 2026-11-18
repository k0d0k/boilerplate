# Localization Contract — Indonesian, Malaysian Malay, English

| Locale | Primary use | Example regional values (separate fields) |
|---|---|---|
| `id` | Babul Syiffa / Indonesia default | timezone `Asia/Jakarta`, currency `IDR` |
| `ms-MY` | NuBiom Malaysia default | timezone `Asia/Kuala_Lumpur`, currency `MYR` |
| `en` | General / international | tenant-defined timezone and currency |

## Resolution order

Authenticated **user choice** → permitted brand default → tenant default → platform default (`id`). Persist user choice and audit any privileged override. **Language does not determine jurisdiction, currency, payment merchant or timezone.** Those are independently configured.

Laravel catalogs must use stable module-owned translation keys, e.g. `lba.examination.mode_manual`, `patients.profile.title`, `booking.location.tentative`. In Laravel use `__()`, and published modules expose their translations in `lang/id`, `lang/ms-MY`, `lang/en` (or registered package equivalents). Validate fallback chain. All navigations, validation errors, buttons, status badges and emails in the scope of a launched module have translations.

Clinical report translations require qualified clinical review and language-specific disclaimers; translated AI chat output is not a clinically certified diagnosis. Product descriptions may use stored per-locale content; preserve an audit/history of edited translations. `html lang` tracks current locale; date/time/currency formatting uses explicit region settings.

**Phase 1A:** locale middleware/preferences and a functional language switcher plus basic translated menus. **Before each module ships:** translations for three locales, missing-key tests, mobile reflow tests and localized validation.

## Supported locales

Canonical codes are fixed and case-sensitive: `id` (Bahasa Indonesia), `ms-MY` (Bahasa Melayu Malaysia), `en` (English). The platform default is `id`. These live in `config/localization.php` (`supported`, `default`, `labels`).

Malaysian Malay must never silently fall back to Indonesian. The fallback chain registered in `App\Providers\LocalizationServiceProvider::applyFallbackChain()` is:

| Requested | Runtime chain |
|---|---|
| `id` | `id` → `en` |
| `ms-MY` | `ms-MY` → `ms` → `en` |
| `en` | `en` |

Filament ships `id` and `ms` internally (not `ms-MY`), so a bare `ms-MY` request resolves Filament chrome through the `ms` alias rather than through Indonesian. Laravel core ships `en` only, which is why every catalog always keeps `en` complete.

## Module localization contract (`Modules/*`)

Translation keys are namespaced per module and use stable, dotted, lowercase-with-underscores segments. The first segment is the owning module (or `lba` for platform-owned copy):

```
patients.profile.title
lba.examination.mode_manual
booking.location.tentative
consultation.session.title
commerce.order.status
```

Rules every module must follow:

1. Ship all three catalogs — `lang/id/{module}.php`, `lang/ms-MY/{module}.php`, `lang/en/{module}.php` (or registered package equivalents).
2. Key sets must be **identical across locales**; key order is irrelevant but presence is not.
3. Translate at render/Livewire time with `__()`, never at service-provider boot time — panel providers boot before the request locale is applied, so keys resolved during `panel()` would freeze the wrong language. Pass keys, resolve labels late.
4. Never substitute another locale's strings: an untranslated `ms-MY` string falls through `ms` to `en`, never to `id`.
5. Never translate clinical findings, patient records, disclaimers or AI medical output in this foundation; those require qualified clinical review.

### Graceful fallback and missing-key detection

Fallback is handled natively by Laravel's translator (see the chain table above). Missing keys surface through the audit command, which flattens every `lang/{locale}/*.php` catalog with `Arr::dot()` and diffs each locale against a baseline:

```sh
php artisan lba:localization:audit            # baseline id, report missing/extra
php artisan lba:localization:audit --strict   # non-zero exit on any drift (CI)
```

Exit code is non-zero when a locale is missing keys present in the baseline, so the command is CI-ready. This is the automated missing-key detection; the module registry itself is intentionally not built in Phase 1B-3.
