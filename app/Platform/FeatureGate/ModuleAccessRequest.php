<?php

namespace App\Platform\FeatureGate;

use App\Models\User;
use App\Platform\Access\AccessContext;
use App\Platform\Access\Contracts\VerifiesBrandScope;
use App\Platform\Branches\Contracts\VerifiesBranchScope;

/**
 * The verified inputs to a module access decision (Phase 1B-4R).
 *
 * Deliberately framework-agnostic: it carries the resolved identity and the
 * verified scope, never a raw request. An HTTP route, a Livewire action, an API
 * call or a queued job can all build the same request, so module authorization
 * is enforced identically on every surface.
 *
 * `context` must be the *verified* tenant/branch context — never a value taken
 * from browser input. The gate re-verifies the tenant against the identity's
 * active membership before honouring it.
 *
 * `brandId` is an *unverified claim*, not authority: it is only honoured after
 * {@see VerifiesBrandScope} proves an explicit
 * user -> tenant -> brand relationship. A nonempty brand id on its own never
 * authorizes anything. A branch is represented inside {@see AccessContext} and is
 * only honoured after {@see VerifiesBranchScope}
 * proves it is an active branch of the verified tenant (Phase 2B).
 */
final class ModuleAccessRequest
{
    public function __construct(
        public readonly string $moduleKey,
        public readonly string $panelId,
        public readonly ?User $user = null,
        public readonly ?AccessContext $context = null,
        public readonly ?string $brandId = null,
    ) {}
}
