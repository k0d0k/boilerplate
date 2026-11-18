<?php

namespace App\Providers;

use App\Livewire\SupportTenantBanner;
use App\Livewire\TenantSwitcher;
use App\Platform\Access\Contracts\ManagesTenantContext;
use App\Platform\Access\SupportTenantSession;
use App\Platform\Access\TenantContextManager;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

/**
 * Phase 1B-2B tenant context bindings.
 *
 * Binds the secure tenant context contract and the WS9.E bounded support
 * context, registers the Clinic switcher and the Principal support banner
 * components, and clears BOTH stored contexts on every authentication boundary
 * so no tenant selection or support context can survive a logout or leak into a
 * different identity signing in on the same session.
 */
class TenantContextServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ManagesTenantContext::class, TenantContextManager::class);
        $this->app->singleton(SupportTenantSession::class);
    }

    public function boot(): void
    {
        Livewire::component('tenant-switcher', TenantSwitcher::class);
        Livewire::component('support-tenant-banner', SupportTenantBanner::class);

        $clear = function (): void {
            app(ManagesTenantContext::class)->clear();
            app(SupportTenantSession::class)->close(null);
        };

        Event::listen(Logout::class, $clear);
        Event::listen(Login::class, $clear);
    }
}
