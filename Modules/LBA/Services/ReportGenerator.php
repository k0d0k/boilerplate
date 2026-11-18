<?php

namespace Modules\LBA\Services;

use App\Models\Brand;
use App\Models\User;
use App\Platform\Access\AccessContext;
use App\Platform\Access\Contracts\ChecksPermissions;
use App\Platform\Access\PermissionKey;
use App\Platform\Tenancy\Concerns\RunsVerifiedTenantTransactions;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\LBA\Exceptions\ReviewException;
use Modules\LBA\Models\Assessment;
use Modules\LBA\Models\ReportEvent;
use Modules\LBA\Models\ReportVersion;
use Modules\LBA\Models\Review;

/**
 * Phase 7B — immutable, versioned PDF report generation (Modules/LBA).
 *
 * A report may only be generated for an assessment a HUMAN reviewer has
 * approved. Generation produces ONE immutable PDF version:
 *
 *  - the brand/branch/reviewer identities are SNAPSHOT onto the version, so a
 *    later rename, a moved branch or a removed user cannot rewrite the document;
 *  - the bytes are written to the PRIVATE `lba-reports` disk with no public URL;
 *  - a SHA-256 checksum of the exact bytes is stored for integrity verification;
 *  - the write happens inside a VERIFIED tenant transaction, so a report row can
 *    never land on the central database or another tenant's database.
 *
 * A revision (return for changes → correct → re-approve) creates a NEW version;
 * an existing version is never overwritten.
 */
final class ReportGenerator
{
    use RunsVerifiedTenantTransactions;

    public function __construct(
        private readonly BrandDirectory $brands,
        private readonly ClinicalTerminologyGate $gate,
        private readonly ChecksPermissions $permissions,
    ) {}

    /**
     * Generate the next immutable report version for an approved assessment.
     *
     * Authorization is re-derived HERE, on the server, so a direct call that
     * bypasses the Filament UI (a job, a console command, another service) is
     * rejected exactly like an unauthorized UI action. The caller must hold
     * `report.approve` for the verified tenant.
     */
    public function generate(Assessment $assessment, string $tenantId, User $reviewer, ?string $locale = null): ReportVersion
    {
        $this->assertAuthorized($reviewer, $tenantId);

        if ((string) $assessment->status !== Assessment::STATUS_REVIEWED) {
            throw ReviewException::notApproved();
        }

        if ($assessment->brand_id === null || $assessment->brand_id === '') {
            throw ReviewException::brandRequired();
        }

        $brand = $this->brands->resolveConfigurable($tenantId, (string) $assessment->brand_id);

        $approval = $assessment->reviews()
            ->where('decision', Review::DECISION_APPROVED)
            ->orderByDesc('decided_at')
            ->first();

        if ($approval === null) {
            throw ReviewException::notApproved();
        }

        $examination = $assessment->examination()->first();

        if ($examination === null) {
            throw ReviewException::reportMissing();
        }

        $branch = $examination->branch()->first();

        $brandSnapshot = $this->brands->reportSnapshot($brand);
        $branchSnapshot = $this->branchSnapshot($branch);
        $reviewerSnapshot = $this->reviewerSnapshot($reviewer, $approval);

        $resolvedLocale = $this->resolveLocale($locale, $brandSnapshot);
        $version = ((int) $assessment->reportVersions()->max('version')) + 1;

        $data = $this->viewData(
            $assessment,
            $brandSnapshot,
            $branchSnapshot,
            $reviewerSnapshot,
            $resolvedLocale,
            $version,
        );

        $pdfBytes = $this->renderPdf($data, $resolvedLocale);

        $checksum = hash('sha256', $pdfBytes);
        $disk = (string) config('lba.reports.disk', 'lba-reports');
        $prefix = trim((string) config('lba.reports.prefix', 'reports'), '/');
        $objectKey = $prefix.'/'.$assessment->getKey().'/v'.$version.'-'.strtolower((string) Str::ulid()).'.pdf';

        Storage::disk($disk)->put($objectKey, $pdfBytes, ['visibility' => 'private']);

        return $this->tenantTransaction(function () use (
            $assessment, $reviewer, $approval, $resolvedLocale, $brand, $brandSnapshot,
            $branchSnapshot, $reviewerSnapshot, $checksum, $disk, $objectKey, $pdfBytes, $version
        ): ReportVersion {
            $record = ReportVersion::query()->create([
                'assessment_id' => $assessment->getKey(),
                'version' => $version,
                'status' => ReportVersion::STATUS_GENERATED,
                'locale' => $resolvedLocale,
                'brand_id' => (string) $brand->getKey(),
                'brand_snapshot' => $brandSnapshot,
                'branch_id' => $branchSnapshot['id'] ?? null,
                'branch_snapshot' => $branchSnapshot,
                'reviewer_id' => (string) $reviewer->getKey(),
                'reviewer_snapshot' => $reviewerSnapshot,
                'approved_at' => $approval->decided_at,
                'checksum' => $checksum,
                'disk' => $disk,
                'object_key' => $objectKey,
                'mime_type' => ReportVersion::MIME_PDF,
                'size_bytes' => strlen($pdfBytes),
                'generated_by' => (string) $reviewer->getKey(),
                'generated_at' => now(),
            ]);

            ReportEvent::query()->create([
                'assessment_id' => $assessment->getKey(),
                'report_version_id' => $record->getKey(),
                'action' => $version === 1 ? ReportEvent::ACTION_GENERATED : ReportEvent::ACTION_REVISED,
                'actor_id' => (string) $reviewer->getKey(),
                'note' => "v{$version} · {$resolvedLocale}",
                'occurred_at' => now(),
            ]);

            return $record;
        });
    }

    /**
     * @param  array<string, mixed>  $brandSnapshot
     * @param  array<string, mixed>  $branchSnapshot
     * @param  array<string, mixed>  $reviewerSnapshot
     */
    private function resolveLocale(?string $requested, array $brandSnapshot): string
    {
        $supported = (array) config('lba.reports.locales', ['id', 'ms-MY', 'en']);

        foreach ([$requested, $brandSnapshot['language'] ?? null, 'id'] as $candidate) {
            if (is_string($candidate) && in_array($candidate, $supported, true)) {
                return $candidate;
            }
        }

        return 'id';
    }

    /**
     * The private brand logo, embedded as a self-contained data URI so the PDF
     * carries the official mark WITHOUT exposing a public URL or a filesystem
     * path.
     *
     * @param  array<string, mixed>  $brandSnapshot
     */
    private function logoDataUri(array $brandSnapshot): ?string
    {
        $diskName = $brandSnapshot['logo_disk'] ?? null;
        $path = $brandSnapshot['logo_path'] ?? null;

        if (! is_string($diskName) || ! is_string($path) || $path === '') {
            return null;
        }

        $disk = Storage::disk($diskName);

        if (! $disk->exists($path)) {
            return null;
        }

        $bytes = (string) $disk->get($path);
        $mime = $this->imageMime($path);

        return 'data:'.$mime.';base64,'.base64_encode($bytes);
    }

    private function imageMime(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            default => 'image/png',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function branchSnapshot(?object $branch): array
    {
        if ($branch === null) {
            return [];
        }

        return [
            'id' => (string) $branch->getKey(),
            'name' => (string) $branch->name,
            'code' => (string) $branch->code,
            'address' => $branch->address,
            'city' => $branch->city,
            'region' => $branch->region,
            'country' => $branch->country,
            'phone' => $branch->phone,
            'email' => $branch->email,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reviewerSnapshot(User $reviewer, Review $approval): array
    {
        $snapshot = (array) ($approval->reviewer_snapshot ?? []);

        return [
            'id' => (string) $reviewer->getKey(),
            'name' => $snapshot['name'] ?? (string) $reviewer->name,
            'email' => $snapshot['email'] ?? (string) $reviewer->email,
            'decided_at' => $approval->decided_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $brandSnapshot
     * @param  array<string, mixed>  $branchSnapshot
     * @param  array<string, mixed>  $reviewerSnapshot
     * @return array<string, mixed>
     */
    private function viewData(
        Assessment $assessment,
        array $brandSnapshot,
        array $branchSnapshot,
        array $reviewerSnapshot,
        string $locale,
        int $version,
    ): array {
        $examination = $assessment->examination()->first();
        $patient = $examination?->patient()->first();
        $items = $assessment->items()->get();

        $evidence = [];
        foreach ($assessment->evidenceLinks()->get() as $link) {
            $evidence[$link->indicator_key][] = [
                'type' => (string) $link->evidence_type,
                'provenance' => (string) $link->provenance,
            ];
        }

        return [
            'locale' => $locale,
            'version' => $version,
            'brand' => $brandSnapshot + ['logo_data' => $this->logoDataUri($brandSnapshot)],
            'branch' => $branchSnapshot,
            'reviewer' => $reviewerSnapshot,
            'patient' => [
                'name' => (string) ($patient?->full_name ?? '—'),
                'number' => (string) ($patient?->patient_number ?? '—'),
            ],
            'examination' => [
                'id' => (string) ($examination?->getKey() ?? '—'),
                'mode' => (string) ($examination?->mode ?? '—'),
                'captured_at' => $examination?->captured_at?->toIso8601String(),
            ],
            'summary' => [
                'findings' => $this->gate->clinicalFindings($assessment),
                'neutral' => $this->gate->neutralObservations($assessment),
                'has_reviewed_terminology' => $this->gate->hasReviewedTerminology($assessment),
                'pending' => $this->gate->pendingTerminologyCount($assessment),
            ],
            'program_phase' => (string) $assessment->program_phase,
            'evidence' => $evidence,
            'item_count' => $items->count(),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Reject a report-generation call by an identity that does not hold
     * `report.approve` for the verified tenant — even outside the Filament UI.
     */
    private function assertAuthorized(User $reviewer, string $tenantId): void
    {
        if ($tenantId === '') {
            throw ReviewException::missingContext();
        }

        $allowed = $this->permissions->allows(
            $reviewer,
            PermissionKey::REPORT_APPROVE,
            AccessContext::forTenant($tenantId),
        );

        if (! $allowed) {
            throw ReviewException::notAuthorized();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function renderPdf(array $data, string $locale): string
    {
        $previous = app()->getLocale();
        app()->setLocale($locale);

        try {
            $html = view('lba::reports.assessment', $data)->render();

            /** @var \Barryvdh\DomPDF\PDF $pdf */
            $pdf = Pdf::loadHtml($html)->setPaper('a4', 'portrait');

            return $pdf->output();
        } finally {
            app()->setLocale($previous);
        }
    }
}
