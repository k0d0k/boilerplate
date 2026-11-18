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
use Modules\LBA\Models\Examination;
use Modules\LBA\Models\ExaminationImage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Phase 4 — authenticated, tenant- and examination-scoped microscopy image
 * streaming.
 *
 * An original microscopy image is a clinical record: it is stored on the PRIVATE
 * `lba` disk and is only ever read back through this endpoint. There is no public
 * URL and no signed URL. Access is authorised entirely on the SERVER, never by
 * the client:
 *
 *  - a signed-in identity is required;
 *  - the FeatureGate must allow the `lba` module in the Clinic panel for the
 *    verified tenant context;
 *  - the identity must hold `exam.read` in that verified context;
 *  - the image's examination must belong to a branch the identity may operate in;
 *  - the object must actually exist on the configured private disk.
 *
 * The verified tenant database is bound for the whole request by the `web`
 * middleware group before this controller runs, so the image is resolved inside
 * the caller's own tenant database and can never leak across partners.
 */
final class ImageStreamController
{
    public function __construct(
        private readonly ModuleGate $moduleGate,
        private readonly ManagesTenantContext $tenants,
        private readonly ManagesBranchContext $branches,
        private readonly ChecksPermissions $permissions,
    ) {}

    public function __invoke(Request $request, string $image): StreamedResponse
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

        /** @var ExaminationImage|null $record */
        $record = ExaminationImage::query()->whereKey($image)->first();

        abort_if($record === null, 404);

        /** @var Examination|null $examination */
        $examination = $record->examination()->first();

        abort_if($examination === null, 404);

        // Branch scope: the caller may only read examinations of the branches
        // they are eligible to operate in.
        $accessible = array_map(
            static fn ($option): string => (string) $option->id,
            $this->branches->eligibleOptions($user),
        );

        abort_unless(in_array((string) $examination->branch_id, $accessible, true), 404);

        $disk = Storage::disk((string) $record->disk);

        abort_unless($disk->exists((string) $record->object_key), 404);

        $stream = $disk->readStream((string) $record->object_key);

        abort_if($stream === false || $stream === null, 404);

        return response()->stream(function () use ($stream): void {
            if (is_resource($stream)) {
                fpassthru($stream);
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => (string) $record->mime_type,
            'Content-Length' => (string) $record->size_bytes,
            'Content-Disposition' => 'inline; filename="microscopy-'.$record->getKey().'"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
