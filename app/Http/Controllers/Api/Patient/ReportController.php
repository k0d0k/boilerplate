<?php

namespace App\Http\Controllers\Api\Patient;

use App\Http\Controllers\Api\Patient\Concerns\PresentsPatientApi;
use App\Platform\Access\PermissionKey;
use App\Platform\Api\PatientApiAuthorizer;
use App\Platform\Api\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\LBA\Models\Assessment;
use Modules\LBA\Models\ReportEvent;
use Modules\LBA\Models\ReportVersion;
use Modules\LBA\Services\AssessmentReviewer;
use Modules\LBA\Services\PatientReportReader;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Phase 8B — approved and EXPLICITLY RELEASED patient reports.
 *
 * The list exposes only the acting identity's own, human-approved, explicitly
 * released report versions. The PDF download shares the SAME read gate as the
 * panel ({@see PatientReportReader}): approved + released for that exact version
 * + self-only + checksum-verified. An approved-but-unreleased report is
 * indistinguishable from a missing one, and every download is audited.
 */
final class ReportController extends PatientApiController
{
    use PresentsPatientApi;

    public function __construct(
        PatientApiAuthorizer $auth,
        private readonly PatientReportReader $reports,
        private readonly AssessmentReviewer $reviewer,
    ) {
        parent::__construct($auth);
    }

    public function index(Request $request): JsonResponse
    {
        $this->auth->authorize(PermissionKey::REPORT_READ_PUBLISHED);
        $patient = $this->auth->patient();

        $paginator = ReportVersion::query()
            ->with(['assessment.examination.branch'])
            ->whereNotNull('released_at')
            ->whereHas('assessment', function ($query) use ($patient): void {
                $query
                    ->where('status', Assessment::STATUS_REVIEWED)
                    ->whereHas('examination', fn ($examination) => $examination->where('patient_id', $patient->getKey()));
            })
            ->orderByDesc('generated_at')
            ->orderByDesc('created_at')
            ->paginate($this->perPage($request))
            ->through(fn (ReportVersion $version) => $this->reportArray($version));

        return ApiResponse::paginated($paginator);
    }

    public function download(Request $request, string $report): StreamedResponse
    {
        $this->auth->authorize(PermissionKey::REPORT_READ_PUBLISHED);
        $patient = $this->auth->patient();

        $version = $this->reports->requireReadable((string) $patient->getKey(), $report);

        $this->reviewer->recordPatientAccess($version, $this->auth->user(), ReportEvent::ACTION_DOWNLOADED);

        return $this->reports->response($version, inline: false);
    }
}
