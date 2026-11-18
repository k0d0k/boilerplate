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
use Modules\Practitioners\Services\PractitionerWorkload;

/**
 * WS7.D — real practitioner workload / queue indicators.
 *
 * WS7.C.4 requires the clinic to see the CURRENT operational load from the
 * actual tenant database tables — upcoming appointments, today's sessions,
 * pending examinations, pending reviews and today's completed consultations —
 * never fabricated or demo figures.
 *
 * Every figure is re-derived server-side by {@see PractitionerWorkload} from the
 * VERIFIED tenant database, scoped to the acting identity's accessible branches.
 * A figure is `null` (rendered as an honest "—") whenever it may not be shown:
 * no verified tenant context, the Practitioners module is not activated for the
 * partner, or the acting identity lacks `practitioner.profile.read`.
 */
class PractitionerWorkloadWidget extends LbaStatsWidget
{
    protected static ?int $sort = 2;

    protected function getHeading(): ?string
    {
        return __('lba.widget.workload_heading');
    }

    protected function getDescription(): ?string
    {
        return __('lba.widget.workload_description');
    }

    protected function getStats(): array
    {
        $metrics = $this->metrics();

        return [
            $this->kpi(
                __('lba.kpi.workload.upcoming_label'),
                $this->value($metrics['upcoming_appointments']),
                __('lba.kpi.workload.upcoming_desc'),
                null,
                'primary',
                Heroicon::OutlinedClock,
            ),
            $this->kpi(
                __('lba.kpi.workload.today_sessions_label'),
                $this->value($metrics['today_sessions']),
                __('lba.kpi.workload.today_sessions_desc'),
                null,
                'primary',
                Heroicon::OutlinedCalendarDays,
            ),
            $this->kpi(
                __('lba.kpi.workload.pending_examinations_label'),
                $this->value($metrics['pending_examinations']),
                __('lba.kpi.workload.pending_examinations_desc'),
                null,
                'warning',
                Heroicon::OutlinedBeaker,
            ),
            $this->kpi(
                __('lba.kpi.workload.pending_reviews_label'),
                $this->value($metrics['pending_reviews']),
                __('lba.kpi.workload.pending_reviews_desc'),
                null,
                'warning',
                Heroicon::OutlinedClipboardDocumentCheck,
            ),
            $this->kpi(
                __('lba.kpi.workload.completed_consultations_label'),
                $this->value($metrics['completed_consultations']),
                __('lba.kpi.workload.completed_consultations_desc'),
                null,
                'success',
                Heroicon::OutlinedChatBubbleLeftRight,
            ),
        ];
    }

    /**
     * Resolve the workload indicators from the verified partner database.
     *
     * @return array{
     *     upcoming_appointments: int|null,
     *     today_sessions: int|null,
     *     pending_examinations: int|null,
     *     pending_reviews: int|null,
     *     completed_consultations: int|null
     * }
     */
    private function metrics(): array
    {
        $unavailable = [
            'upcoming_appointments' => null,
            'today_sessions' => null,
            'pending_examinations' => null,
            'pending_reviews' => null,
            'completed_consultations' => null,
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

        if (! app(ModuleGate::class)->allows('practitioners', PanelAccessPolicy::PANEL_CLINIC)
            || ! app(ChecksPermissions::class)->allows($user, PermissionKey::PRACTITIONER_PROFILE_READ, $context)) {
            return $unavailable;
        }

        // Scope to the branch the acting identity is operating in; a null branch
        // means the whole partner (the read model aggregates the tenant).
        $branchId = $branches->currentBranchId($user);

        return app(PractitionerWorkload::class)->snapshot(null, $branchId === null ? null : (string) $branchId);
    }

    private function value(?int $metric): string
    {
        return $metric === null ? '—' : (string) $metric;
    }
}
