<?php

namespace Modules\Practitioners\Services;

use App\Platform\Tenancy\Contracts\ResolvesTenantDatabase;
use Carbon\CarbonInterface;
use Modules\Practitioners\Models\Practitioner;

/**
 * WS7.C — the assignment gate shared by the appointment, consultation and LBA
 * examination layers.
 *
 * This is a READ-ONLY helper: it never writes and never widens authority. It
 * answers one question — "may this central user be assigned to a clinical role
 * at this branch?" — consistently:
 *
 *  - a REGISTERED practitioner must be bookable ({@see PractitionerRegistry::isEligibleForBooking()}:
 *    verified, not expired and still an active clinical member) AND authorized
 *    for the branch the work belongs to;
 *  - a staff member who is NOT on the roster (legacy / non-clinical staff) is not
 *    constrained here: the caller keeps its own historical membership rule, so
 *    existing records and workflows are never broken.
 *
 * Because it fails closed without a verified tenant context (the underlying
 * {@see ResolvesTenantDatabase} returns null), it can never read another tenant's
 * roster.
 */
final class PractitionerAssignment
{
    public function __construct(
        private readonly PractitionerRegistry $registry,
        private readonly ResolvesTenantDatabase $databases,
    ) {}

    /**
     * The practitioner profile behind a central user, or null when the user is
     * not on the current tenant's roster.
     */
    public function findByUserId(string $userId): ?Practitioner
    {
        if ($this->databases->current() === null) {
            return null;
        }

        return Practitioner::query()->where('user_id', $userId)->first();
    }

    /**
     * True when the central user is a registered practitioner of the current
     * tenant.
     */
    public function isRegistered(string $userId): bool
    {
        return $this->findByUserId($userId) !== null;
    }

    /**
     * True when the central user may be assigned to clinical work at the given
     * branch. A registered practitioner must be bookable and, when a branch is
     * supplied, branch-authorized. A user who is not on the roster is permitted
     * here (the caller applies its own membership rule).
     */
    public function allowsAssignment(string $userId, ?string $branchId = null, ?CarbonInterface $at = null): bool
    {
        $practitioner = $this->findByUserId($userId);

        if ($practitioner === null) {
            return true;
        }

        if (! $this->registry->isEligibleForBooking($practitioner, $at)) {
            return false;
        }

        if ($branchId !== null && $branchId !== ''
            && ! $this->registry->isAuthorizedForBranch($practitioner, $branchId, $at)) {
            return false;
        }

        return true;
    }
}
