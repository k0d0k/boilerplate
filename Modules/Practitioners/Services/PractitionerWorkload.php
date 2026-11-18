<?php

namespace Modules\Practitioners\Services;

use App\Platform\Tenancy\Contracts\ResolvesTenantDatabase;
use Modules\Appointments\Models\Appointment;
use Modules\Consultations\Models\Consultation;
use Modules\LBA\Models\Assessment;
use Modules\LBA\Models\Examination;

/**
 * WS7.C — the clinic workload / queue read model.
 *
 * Every figure is counted LIVE from the CURRENT tenant's own tables (upcoming
 * appointments, today's sessions, pending examinations, pending reviews and
 * today's completed consultations) — there is no demo/synthetic statistic. The
 * counters may be narrowed to one practitioner and/or one branch, and they FAIL
 * CLOSED: without a verified tenant context every value is `null` (an honest
 * empty state) rather than a number read from the wrong database.
 *
 * This is a READ-ONLY helper: it never writes and never widens authority.
 */
final class PractitionerWorkload
{
    public function __construct(private readonly ResolvesTenantDatabase $databases) {}

    /**
     * @return array{
     *     upcoming_appointments: int|null,
     *     today_sessions: int|null,
     *     pending_examinations: int|null,
     *     pending_reviews: int|null,
     *     completed_consultations: int|null,
     * }
     */
    public function snapshot(?string $practitionerUserId = null, ?string $branchId = null): array
    {
        if ($this->databases->current() === null) {
            return $this->empty();
        }

        $today = now()->toDateString();

        return [
            'upcoming_appointments' => Appointment::query()
                ->when($practitionerUserId !== null, fn ($query) => $query->where('staff_user_id', $practitionerUserId))
                ->when($branchId !== null, fn ($query) => $query->where('branch_id', $branchId))
                ->where('starts_at', '>', now())
                ->whereIn('status', Appointment::OCCUPYING_STATUSES)
                ->count(),

            'today_sessions' => Appointment::query()
                ->when($practitionerUserId !== null, fn ($query) => $query->where('staff_user_id', $practitionerUserId))
                ->when($branchId !== null, fn ($query) => $query->where('branch_id', $branchId))
                ->whereDate('starts_at', $today)
                ->whereIn('status', Appointment::OCCUPYING_STATUSES)
                ->count(),

            'pending_examinations' => Examination::query()
                ->when($practitionerUserId !== null, fn ($query) => $query->where('operator_user_id', $practitionerUserId))
                ->when($branchId !== null, fn ($query) => $query->where('branch_id', $branchId))
                ->whereIn('status', [
                    Examination::STATUS_DRAFT,
                    Examination::STATUS_UPLOADED,
                    Examination::STATUS_MANUAL_ANNOTATION,
                    Examination::STATUS_RETURNED_FOR_CHANGES,
                ])
                ->count(),

            'pending_reviews' => Assessment::query()
                ->where('status', Assessment::STATUS_SUBMITTED_FOR_REVIEW)
                ->when($practitionerUserId !== null, fn ($query) => $query->whereHas(
                    'examination',
                    fn ($examination) => $examination->where('operator_user_id', $practitionerUserId),
                ))
                ->when($branchId !== null, fn ($query) => $query->whereHas(
                    'examination',
                    fn ($examination) => $examination->where('branch_id', $branchId),
                ))
                ->count(),

            'completed_consultations' => Consultation::query()
                ->when($practitionerUserId !== null, fn ($query) => $query->where('staff_user_id', $practitionerUserId))
                ->when($branchId !== null, fn ($query) => $query->where('branch_id', $branchId))
                ->where('status', Consultation::STATUS_COMPLETED)
                ->whereDate('ended_at', $today)
                ->count(),
        ];
    }

    /**
     * @return array{
     *     upcoming_appointments: null,
     *     today_sessions: null,
     *     pending_examinations: null,
     *     pending_reviews: null,
     *     completed_consultations: null,
     * }
     */
    private function empty(): array
    {
        return [
            'upcoming_appointments' => null,
            'today_sessions' => null,
            'pending_examinations' => null,
            'pending_reviews' => null,
            'completed_consultations' => null,
        ];
    }
}
