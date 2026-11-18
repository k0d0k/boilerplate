<?php

namespace App\Providers;

use App\Livewire\BranchSwitcher;
use App\Platform\Branches\BranchAccessAuthorizer;
use App\Platform\Branches\BranchContextManager;
use App\Platform\Branches\Contracts\AuthorizesBranchAccess;
use App\Platform\Branches\Contracts\ManagesBranchContext;
use App\Platform\Branches\Contracts\VerifiesBranchScope;
use App\Platform\Branches\TenantBranchScope;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

/**
 * Phase 2B branch bindings.
 *
 * Binds the verified branch authorization / context contracts, registers the
 * Clinic branch switcher component, and clears the stored branch context on
 * every authentication boundary so no branch selection survives a logout or
 * leaks into a different identity signing in on the same session.
 */
class BranchServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    public array $singletons = [
        AuthorizesBranchAccess::class => BranchAccessAuthorizer::class,
        VerifiesBranchScope::class => TenantBranchScope::class,
        ManagesBranchContext::class => BranchContextManager::class,
    ];

    public function boot(): void
    {
        Livewire::component('branch-switcher', BranchSwitcher::class);

        Event::listen(Logout::class, fn () => app(ManagesBranchContext::class)->clear());
        Event::listen(Login::class, fn () => app(ManagesBranchContext::class)->clear());
    }
}
