<?php

/**
 * LBA Cloud localization configuration (Phase 1B-3).
 *
 * Three canonical locales are supported. `id` is the platform default. The
 * regional settings a locale is *commonly* paired with (timezone, currency,
 * payment merchant, jurisdiction) are NOT defined here: language never alters
 * them (docs/LOCALIZATION.md, docs/DECISIONS.md UX-04).
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Supported locales
    |--------------------------------------------------------------------------
    |
    | Canonical, stable codes. `ms-MY` is Malaysian Malay and is resolved from
    | its own catalog — it is never silently substituted with Indonesian.
    |
    */
    'supported' => ['id', 'ms-MY', 'en'],

    /*
    |--------------------------------------------------------------------------
    | Platform default
    |--------------------------------------------------------------------------
    |
    | The last step of locale resolution. Mirrors APP_LOCALE (`.env.example`
    | ships `id`).
    |
    */
    'default' => env('APP_LOCALE', 'id'),

    /*
    |--------------------------------------------------------------------------
    | Language selector labels
    |--------------------------------------------------------------------------
    |
    | Endonyms: each language is always shown in its own language, so the
    | selector stays readable whatever the current locale is.
    |
    */
    'labels' => [
        'id' => 'Bahasa Indonesia',
        'ms-MY' => 'Bahasa Melayu Malaysia',
        'en' => 'English',
    ],

    /*
    |--------------------------------------------------------------------------
    | Starter brand defaults (ARCH-05)
    |--------------------------------------------------------------------------
    |
    | Shipped defaults for the named starter brands. A brand's own stored
    | `metadata.default_locale` overrides these. A brand that has neither a
    | stored setting nor an entry here (e.g. Biodam) intentionally has NO
    | default, so resolution falls through to the tenant default and then the
    | platform default rather than inventing a value.
    |
    */
    'brand_defaults' => [
        'babul-syiffa' => 'id',
        'nubiom' => 'ms-MY',
    ],

    /*
    |--------------------------------------------------------------------------
    | Fallback chain per locale
    |--------------------------------------------------------------------------
    |
    | Laravel resolves a missing key through this ordered chain. Malaysian Malay
    | falls back to Filament's shipped `ms` catalog and then `en` — never to
    | `id`, so an untranslated Malaysian string can never masquerade as
    | Indonesian.
    |
    */
    'fallback_chain' => [
        'id' => ['id', 'en'],
        'ms-MY' => ['ms-MY', 'ms', 'en'],
        'en' => ['en'],
    ],

];
