<?php

namespace App\Filament\Patient\Pages\Concerns;

use App\Models\User;
use App\Platform\Access\AccessContext;
use App\Platform\Access\Contracts\ChecksPermissions;
use App\Platform\Access\Contracts\ManagesTenantContext;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientIdentity;

/**
 * Shared patient-portal authorization (Phase 8A).
 *
 * The patient panel is self-service only. Two independent, server-derived
 * conditions gate every portal page:
 *  - a VERIFIED tenant context (an active membership in an active clinic), and
 *  - the acting identity's own patient record ({@see PatientIdentity}).
 *
 * Permission checks resolve against the verified tenant only, so a
 * patient-scoped permission (`booking.own`, `report.read.published`, …) can
 * never be satisfied by another tenant's membership. Nothing here trusts client
 * input: UI visibility is never the authorization boundary.
 */
trait AuthorizesPatientPortal
{
    protected static function patientUser(): ?User
    {
        $user = auth()->user();

        return $user instanceof User && $user->is_active ? $user : null;
    }

    /**
     * True only when the identity holds a verified clinic context.
     */
    protected static function hasPatientContext(): bool
    {
        $user = static::patientUser();

        return $user !== null
            && app(ManagesTenantContext::class)->hasVerifiedContext($user);
    }

    /**
     * Deny-by-default check of a canonical permission in the verified tenant.
     */
    protected static function allows(string $permission): bool
    {
        $user = static::patientUser();

        if ($user === null) {
            return false;
        }

        $tenantId = app(ManagesTenantContext::class)->currentTenantId($user);

        if ($tenantId === null) {
            return false;
        }

        return app(ChecksPermissions::class)->allows(
            $user,
            $permission,
            AccessContext::forTenant($tenantId, null),
        );
    }

    /**
     * The acting identity's own patient record, or null (fails closed).
     */
    protected function patient(): ?Patient
    {
        return app(PatientIdentity::class)->current();
    }

    /**
     * The acting identity's own patient record, or an outright denial.
     */
    protected function requirePatient(): Patient
    {
        return app(PatientIdentity::class)->require();
    }
}
