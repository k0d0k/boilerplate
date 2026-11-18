<?php

namespace Modules\LBA\Http\Controllers;

use App\Models\User;
use App\Platform\Access\AccessContext;
use App\Platform\Access\Contracts\ChecksPermissions;
use App\Platform\Access\Contracts\ManagesTenantContext;
use App\Platform\Access\PanelAccessPolicy;
use App\Platform\Access\PermissionKey;
use Illuminate\Http\Request;
use Modules\LBA\Models\ReportEvent;
use Modules\LBA\Services\AssessmentReviewer;
use Modules\LBA\Services\PatientReportReader;
use Modules\Patients\Services\PatientIdentity;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Phase 8A — patient self-service PDF report streaming.
 *
 * The patient-facing counterpart of {@see ReportStreamController}. A delivered
 * report is a private clinical document and is only ever read back through this
 * endpoint. Access is authorised entirely on the SERVER:
 *
 *  - a signed-in identity is required;
 *  - the identity must belong to the Patient panel;
 *  - the identity must hold `report.read.published` in the verified tenant;
 *  - the report's examination must belong to the acting identity's OWN patient
 *    record (cross-patient and cross-tenant access fail closed).
 *
 * The report read gate itself (approved + explicitly released for THIS exact
 * version + checksum-verified + self-only) is shared with the REST API through
 * {@see PatientReportReader}, so the two surfaces can never diverge.
 *
 * Every preview / download is appended to the report audit trail.
 */
final class PatientReportStreamController
{
    public function __construct(
        private readonly ManagesTenantContext $tenants,
        private readonly ChecksPermissions $permissions,
        private readonly PatientIdentity $identity,
        private readonly AssessmentReviewer $reviewer,
        private readonly PatientReportReader $reports,
    ) {}

    public function preview(Request $request, string $report): StreamedResponse
    {
        return $this->stream($request, $report, ReportEvent::ACTION_PREVIEWED, inline: true);
    }

    public function download(Request $request, string $report): StreamedResponse
    {
        return $this->stream($request, $report, ReportEvent::ACTION_DOWNLOADED, inline: false);
    }

    private function stream(Request $request, string $report, string $action, bool $inline): StreamedResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->is_active, 403);

        abort_unless(
            PanelAccessPolicy::roleCanAccessPanel($user->role, PanelAccessPolicy::PANEL_PATIENT),
            403,
        );

        $tenantId = $this->tenants->currentTenantId($user);

        abort_if($tenantId === null, 403);

        abort_unless(
            $this->permissions->allows(
                $user,
                PermissionKey::REPORT_READ_PUBLISHED,
                AccessContext::forTenant($tenantId, null),
            ),
            403,
        );

        // Resolve the caller's OWN patient record from the verified tenant
        // database; without a bound identity nothing is readable.
        $patient = $this->identity->current($user);

        abort_if($patient === null, 403);

        // Approved + explicitly released + self-only + integrity verified.
        $version = $this->reports->requireReadable((string) $patient->getKey(), $report);

        $this->reviewer->recordPatientAccess($version, $user, $action);

        return $this->reports->response($version, $inline);
    }
}
