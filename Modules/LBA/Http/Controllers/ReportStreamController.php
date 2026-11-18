<?php

namespace Modules\LBA\Http\Controllers;

use App\Models\User;
use App\Platform\Access\AccessContext;
use App\Platform\Access\Contracts\ChecksPermissions;
use App\Platform\Access\Contracts\ManagesTenantContext;
use App\Platform\Access\PanelAccessPolicy;
use App\Platform\Access\PermissionKey;
use App\Platform\Branches\Contracts\ManagesBranchContext;
use App\Platform\FeatureGate\ModuleGate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Modules\LBA\Models\Assessment;
use Modules\LBA\Models\ReportEvent;
use Modules\LBA\Models\ReportVersion;
use Modules\LBA\Services\AssessmentReviewer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Phase 7B — authenticated, tenant- and branch-scoped PDF report streaming.
 *
 * A generated report is a patient-facing clinical document: it is stored on the
 * PRIVATE `lba-reports` disk and is only ever read back through this endpoint.
 * There is no public URL and no signed URL. Access is authorised entirely on the
 * SERVER, never by the client:
 *
 *  - a signed-in identity is required;
 *  - the FeatureGate must allow the `lba` module in the Clinic panel;
 *  - the identity must hold `exam.read` in the verified tenant context;
 *  - the report's examination must belong to a branch the identity may operate
 *    in (cross-tenant and cross-branch access fail closed);
 *  - the stored object must exist AND still match its recorded SHA-256 checksum.
 *
 * Every preview / download is appended to the report audit trail.
 */
final class ReportStreamController
{
    public function __construct(
        private readonly ModuleGate $moduleGate,
        private readonly ManagesTenantContext $tenants,
        private readonly ManagesBranchContext $branches,
        private readonly ChecksPermissions $permissions,
        private readonly AssessmentReviewer $reviewer,
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

        abort_unless($user instanceof User, 403);

        abort_unless(
            $this->moduleGate->allows('lba', PanelAccessPolicy::PANEL_CLINIC),
            403,
        );

        $tenantId = $this->tenants->currentTenantId($user);

        abort_if($tenantId === null, 403);

        $branchId = $this->branches->currentBranchId($user);

        abort_unless(
            $this->permissions->allows(
                $user,
                PermissionKey::EXAM_READ,
                AccessContext::forTenant($tenantId, $branchId),
            ),
            403,
        );

        /** @var ReportVersion|null $version */
        $version = ReportVersion::query()->whereKey($report)->first();

        abort_if($version === null, 404);

        /** @var Assessment|null $assessment */
        $assessment = $version->assessment()->first();

        abort_if($assessment === null, 404);

        $examination = $assessment->examination()->first();

        abort_if($examination === null, 404);

        // Branch scope: the caller may only read reports of examinations in the
        // branches they are eligible to operate in.
        $accessible = array_map(
            static fn ($option): string => (string) $option->id,
            $this->branches->eligibleOptions($user),
        );

        abort_unless(in_array((string) $examination->branch_id, $accessible, true), 404);

        $disk = Storage::disk((string) $version->disk);

        abort_unless($disk->exists((string) $version->object_key), 404);

        // Integrity: refuse to serve a document that no longer matches the
        // SHA-256 checksum recorded at generation time.
        abort_unless($version->verifyChecksum(), 404);

        $stream = $disk->readStream((string) $version->object_key);

        abort_if($stream === false || $stream === null, 404);

        $this->reviewer->recordAccess($version, $user, $action);

        $disposition = ($inline ? 'inline' : 'attachment').'; filename="'.$version->filename().'"';

        return response()->stream(function () use ($stream): void {
            if (is_resource($stream)) {
                fpassthru($stream);
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => ReportVersion::MIME_PDF,
            'Content-Length' => (string) $version->size_bytes,
            'Content-Disposition' => $disposition,
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
