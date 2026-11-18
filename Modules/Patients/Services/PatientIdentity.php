<?php

namespace Modules\Patients\Services;

use App\Models\User;
use App\Platform\Tenancy\Contracts\ResolvesTenantDatabase;
use Modules\Patients\Models\Patient;

/**
 * Phase 8A — secure patient-portal identity resolution.
 *
 * A signed-in patient reaches the patient panel through a verified tenant
 * membership, and their own clinical record is reached ONLY through the
 * `patients.user_id` binding (a central user id stored in the tenant database).
 *
 * Resolution FAILS CLOSED:
 *  - no verified tenant database is active            => no identity;
 *  - no active patient row is bound to the user        => no identity;
 *  - always scoped to the CURRENT tenant database, so a patient account bound
 *    in tenant A can never resolve a patient record in tenant B.
 *
 * The result is cached per (user, tenant) for the request, because navigation
 * rendering asks for the identity on every page.
 */
final class PatientIdentity
{
    /** @var array<string, Patient|null> */
    private array $cache = [];

    public function __construct(private readonly ResolvesTenantDatabase $databases) {}

    public function current(?User $user = null): ?Patient
    {
        $user ??= auth()->user();

        if (! $user instanceof User || ! $user->is_active) {
            return null;
        }

        $tenantId = $this->databases->current();

        if ($tenantId === null) {
            return null;
        }

        $key = $tenantId.'|'.$user->getKey();

        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        return $this->cache[$key] = Patient::query()
            ->where('user_id', $user->getKey())
            ->where('status', Patient::STATUS_ACTIVE)
            ->first();
    }

    /**
     * Resolve the acting identity's own patient record or deny outright.
     */
    public function require(?User $user = null): Patient
    {
        return $this->current($user) ?? abort(403, __('lba.patient_portal.identity_required'));
    }
}
