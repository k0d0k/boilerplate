<?php

namespace App\Providers;

use App\Livewire\LanguageSwitcher;
use App\Localization\Console\AuditTranslations;
use App\Localization\Contracts\ResolvesLocale;
use App\Localization\LocaleResolver;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

/**
 * Phase 1B-3 localization foundation.
 *
 *  - binds the locale resolution contract;
 *  - installs the per-locale fallback chain on Laravel's translator so `ms-MY`
 *    resolves through `ms` then `en` — never through `id`;
 *  - registers the single shared topbar language selector for every panel;
 *  - registers the missing-key audit command.
 *
 * Language remains a presentation-only concern; nothing here grants or changes
 * authorization, membership or panel access.
 */
class LocalizationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ResolvesLocale::class, LocaleResolver::class);
    }

    public function boot(): void
    {
        $this->applyFallbackChain();

        Livewire::component('language-switcher', LanguageSwitcher::class);

        // One selector for all three panels, mounted in the topbar next to the
        // account menu — never duplicated inside individual pages.
        FilamentView::registerRenderHook(
            PanelsRenderHook::USER_MENU_BEFORE,
            fn (): View => view('filament.lba.language-switcher'),
        );

        if ($this->app->runningInConsole()) {
            $this->commands([AuditTranslations::class]);
        }
    }

    /**
     * Map the platform's per-locale fallback chain onto Laravel's translator.
     *
     * Laravel passes its default `[requested, fallback]` list; we replace it
     * with the configured chain (config/localization.php), so `ms-MY` becomes
     * `ms-MY → ms → en`.
     */
    private function applyFallbackChain(): void
    {
        $this->app['translator']->determineLocalesUsing(function (array $locales): array {
            $primary = $locales[0] ?? null;

            if (! is_string($primary)) {
                return $locales;
            }

            $chain = config("localization.fallback_chain.{$primary}");

            return is_array($chain) ? array_values($chain) : $locales;
        });
    }
}
