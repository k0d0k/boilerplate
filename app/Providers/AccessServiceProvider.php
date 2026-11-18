<?php

namespace App\Providers;

use App\Platform\Access\Contracts\AuthorizesPrincipal;
use App\Platform\Access\Contracts\AuthorizesTenantMembership;
use App\Platform\Access\Contracts\ChecksPermissions;
use App\Platform\Access\Contracts\ResolvesRoles;
use App\Platform\Access\Contracts\ValidatesTenantScope;
use App\Platform\Access\PermissionChecker;
use App\Platform\Access\PrincipalAuthorizer;
use App\Platform\Access\RoleResolver;
use App\Platform\Access\TenantMembershipAuthorizer;
use App\Platform\Access\TenantScopeValidator;
use Illuminate\Support\ServiceProvider;

/**
 * Phase 1B-2A authorization bindings.
 *
 * Binds the reusable authorization contracts to their central-table-backed
 * implementations so consumers depend on the contract, not the concrete class.
 */
class AccessServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    public array $singletons = [
        ResolvesRoles::class => RoleResolver::class,
        ChecksPermissions::class => PermissionChecker::class,
        AuthorizesPrincipal::class => PrincipalAuthorizer::class,
        AuthorizesTenantMembership::class => TenantMembershipAuthorizer::class,
        ValidatesTenantScope::class => TenantScopeValidator::class,
    ];
}
