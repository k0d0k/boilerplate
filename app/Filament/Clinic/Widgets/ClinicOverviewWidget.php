<?php

namespace App\Filament\Clinic\Widgets;

use App\Filament\Shared\Widgets\LbaStatsWidget;
use App\Models\User;
use App\Platform\Access\AccessContext;
use App\Platform\Access\Contracts\ChecksPermissions;
use App\Platform\Access\Contracts\ManagesTenantContext;
use App\Platform\Access\PanelAccessPolicy;
use App\Platform\Access\PermissionKey;
use App\Platform\Branches\Contracts\ManagesBranchContext;
use App\Platform\FeatureGate\ModuleGate;
use App\Platform\Tenancy\Contracts\ResolvesTenantDatabase;
use Filament\Support\Icons\Heroicon;
use Modules\Appointments\Models\Appointment;
use Modules\Patients\Models\Patient;

/**
 * Clinic KPI strip (Phase 1A-3; live data since Phase 3).
 *
 * The four operational indicators are read from the verified partner database:
 * the total registered patients, today's appointments, upcoming appointments
 * and currently checked-in patients. Each indicator is scoped to the verified
 * tenant/branch context AND to the acting identity's module entitlement and
 * permission — an unavailable figure renders as an honest empty state rather
 * than a fabricated number. Other clinic dashboard widgets remain clearly
 * marked previews.
 */
class ClinicOverviewWidget extends LbaStatsWidget
{
    protected function getHeading(): ?string
    {
        return __('lba.widget.clinic_heading');
    }

    protected function getDescription(): ?string
    {
        return __('lba.widget.clinic_description');
    }

    protected function getStats(): array
    {
        $metrics = $this->metrics();

        return [
            $this->kpi(
                __('lba.kpi.clinic.patients_label'),
                $this->value($metrics['patients']),
                __('lba.kpi.clinic.patients_desc'),
                null,
                'primary',
                Heroicon::OutlinedUsers,
            ),
            $this->kpi(
                __('lba.kpi.clinic.today_appointments_label'),
                $this->value($metrics['today']),
                __('lba.kpi.clinic.today_appointments_desc'),
                null,
                'primary',
                Heroicon::OutlinedCalendarDays,
            ),
            $this->kpi(
                __('lba.kpi.clinic.upcoming_label'),
                $this->value($metrics['upcoming']),
                __('lba.kpi.clinic.upcoming_desc'),
                null,
                'primary',
                Heroicon::OutlinedClock,
            ),
            $this->kpi(
                __('lba.kpi.clinic.checked_in_label'),
                $this->value($metrics['checked_in']),
                __('lba.kpi.clinic.checked_in_desc'),
                null,
                'success',
                Heroicon::OutlinedCheckBadge,
            ),
        ];
    }

    /**
     * Resolve the four live indicators from the verified partner database.
     *
     * A metric is `null` (rendered as an honest "—") whenever it may not be
     * shown: no verified tenant context, the owning module is not activated for
     * the partner, or the acting identity lacks the read permission. Counts are
     * re-derived server-side and never taken from client input.
     *
     * @return array{patients: int|null, today: int|null, upcoming: int|null, checked_in: int|null}
     */
    private function metrics(): array
    {
        $unavailable = [
            'patients' => null,
            'today' => null,
            'upcoming' => null,
            'checked_in' => null,
        ];

        $user = auth()->user();

        if (! $user instanceof User || ! $user->is_active) {
            return $unavailable;
        }

        $tenantId = app(ManagesTenantContext::class)->currentTenantId($user);

        if ($tenantId === null || app(ResolvesTenantDatabase::class)->current() === null) {
            return $unavailable;
        }

        $branches = app(ManagesBranchContext::class);
        $context = AccessContext::forTenant($tenantId, $branches->currentBranchId($user));
        $permissions = app(ChecksPermissions::class);
        $gate = app(ModuleGate::class);

        $metrics = $unavailable;

        if ($gate->allows('patients', PanelAccessPolicy::PANEL_CLINIC)
            && $permissions->allows($user, PermissionKey::PATIENT_PROFILE_READ, $context)) {
            // The patient registry is tenant-scoped (history spans branches).
            $metrics['patients'] = Patient::query()->count();
        }

        if ($gate->allows('appointments', PanelAccessPolicy::PANEL_CLINIC)
            && $permissions->allows($user, PermissionKey::BOOKING_READ, $context)) {
            $branchIds = array_map(
                static fn ($option): string => (string) $option->id,
                $branches->eligibleOptions($user),
            );

            if ($branchIds === []) {
                // No accessible branch: honest empty state, never another
                // branch's schedule.
                $metrics['today'] = 0;
                $metrics['upcoming'] = 0;
                $metrics['checked_in'] = 0;
            } else {
                $scoped = static fn () => Appointment::query()->whereIn('branch_id', $branchIds);

                $metrics['today'] = $scoped()->whereDate('starts_at', now()->toDateString())->count();
                $metrics['upcoming'] = $scoped()
                    ->where('starts_at', '>', now())
                    ->whereIn('status', Appointment::OCCUPYING_STATUSES)
                    ->count();
                $metrics['checked_in'] = $scoped()
                    ->where('status', Appointment::STATUS_CHECKED_IN)
                    ->whereDate('starts_at', now()->toDateString())
                    ->count();
            }
        }

        return $metrics;
    }

    private function value(?int $metric): string
    {
        return $metric === null ? '—' : (string) $metric;
    }
}
