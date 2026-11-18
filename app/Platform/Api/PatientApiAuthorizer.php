<?php

namespace App\Platform\Api;

use App\Models\User;
use App\Platform\Access\AccessContext;
use App\Platform\Access\Contracts\ChecksPermissions;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientIdentity;

/**
 * Phase 8B — server-side authorization for the patient REST API.
 *
 * Reuses the SAME building blocks the Patient panel uses, so the API and the UI
 * can never drift apart:
 *  - permissions resolve through {@see ChecksPermissions} against the token's
 *    VERIFIED clinic (never a client-supplied scope);
 *  - the acting identity's own patient record resolves through
 *    {@see PatientIdentity}, which reads the bound tenant database.
 *
 * Every check fails closed.
 */
final class PatientApiAuthorizer
{
    public function __construct(
        private readonly PatientApiContext $context,
        private readonly ChecksPermissions $permissions,
        private readonly PatientIdentity $identity,
    ) {}

    public function user(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User && $user->is_active, 401);

        return $user;
    }

    public function tenantId(): string
    {
        $tenantId = $this->context->tenantId();

        abort_if($tenantId === null, 401);

        return $tenantId;
    }

    public function allows(string $permission): bool
    {
        return $this->permissions->allows(
            $this->user(),
            $permission,
            AccessContext::forTenant($this->tenantId(), null),
        );
    }

    public function authorize(string $permission): void
    {
        abort_unless($this->allows($permission), 403);
    }

    /**
     * The acting identity's own patient record, or a 403. A patient account
     * that is not bound to a record has nothing to serve.
     */
    public function patient(): Patient
    {
        return $this->identity->require($this->user());
    }
}
