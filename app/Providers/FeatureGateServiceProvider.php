<?php

namespace App\Providers;

use App\Platform\Access\Contracts\VerifiesBrandScope;
use App\Platform\Access\TenantBrandScope;
use App\Platform\FeatureGate\Contracts\EvaluatesModuleAccess;
use App\Platform\FeatureGate\Contracts\GrantsModuleEntitlements;
use App\Platform\FeatureGate\Contracts\ResolvesModuleState;
use App\Platform\FeatureGate\Contracts\VerifiesModuleSubscriptions;
use App\Platform\FeatureGate\FeatureGate;
use App\Platform\FeatureGate\Providers\DatabaseModuleEntitlements;
use App\Platform\FeatureGate\Providers\DatabaseModuleState;
use App\Platform\FeatureGate\Providers\DatabaseModuleSubscriptions;
use Illuminate\Support\ServiceProvider;

/**
 * Phase 1B-4 FeatureGate bindings.
 *
 * Every leg is bound to a central-table-backed implementation that fails
 * closed. The subscription verifier is {@see DatabaseModuleSubscriptions},
 * which honours a live tenant licence from verified central data: a module that
 * requires a subscription is denied unless a real, active subscription licenses
 * it — never because one was merely declared.
 *
 * Brand scope is verified from trusted central data via {@see TenantBrandScope};
 * branch scope remains unimplemented and therefore always denies.
 *
 * Tests may swap any leg for a synthetic, test-scoped provider via
 * `$this->app->instance(...)`.
 */
class FeatureGateServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    public array $singletons = [
        ResolvesModuleState::class => DatabaseModuleState::class,
        GrantsModuleEntitlements::class => DatabaseModuleEntitlements::class,
        VerifiesModuleSubscriptions::class => DatabaseModuleSubscriptions::class,
        VerifiesBrandScope::class => TenantBrandScope::class,
        EvaluatesModuleAccess::class => FeatureGate::class,
    ];
}
