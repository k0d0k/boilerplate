<?php

namespace App\Providers;

use App\Platform\Settings\PlatformSettings;
use Illuminate\Support\ServiceProvider;

/**
 * Binds the single platform-settings reader (Phase 3 — Global Settings).
 *
 * {@see PlatformSettings} is the one place that resolves the EFFECTIVE platform
 * configuration (persisted overrides over the `.env` fallback). Binding it as a
 * singleton means a web request, an Artisan command and a queue job all read
 * the same effective configuration through the same object — the settings are
 * never spliced into `config()` in only some of those paths.
 */
class PlatformSettingsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PlatformSettings::class);
    }
}
