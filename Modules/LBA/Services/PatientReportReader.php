<?php

namespace Modules\LBA\Services;

use Illuminate\Support\Facades\Storage;
use Modules\LBA\Http\Controllers\PatientReportStreamController;
use Modules\LBA\Models\Assessment;
use Modules\LBA\Models\ReportVersion;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Phase 8B — the single patient-facing report read gate.
 *
 * Both the Patient panel stream ({@see PatientReportStreamController})
 * and the REST API report download share this, so the release gate can never
 * drift between the two surfaces:
 *
 *  - the underlying assessment must be human-reviewed;
 *  - the exact version must be EXPLICITLY RELEASED (approval is not release);
 *  - the examination must belong to the acting patient;
 *  - the stored object must exist and still match its SHA-256 checksum.
 *
 * Every failure aborts with the same status the panel always used (403 for an
 * unreviewed assessment, 404 for everything unreadable/missing), so an
 * unreleased report is indistinguishable from a missing one.
 */
final class PatientReportReader
{
    public function requireReadable(string $patientId, string $reportId): ReportVersion
    {
        /** @var ReportVersion|null $version */
        $version = ReportVersion::query()->whereKey($reportId)->first();

        abort_if($version === null, 404);

        /** @var Assessment|null $assessment */
        $assessment = $version->assessment()->first();

        abort_if($assessment === null, 404);

        abort_unless($assessment->status === Assessment::STATUS_REVIEWED, 403);

        $examination = $assessment->examination()->first();

        abort_if($examination === null, 404);

        abort_unless($version->isReleased(), 404);

        abort_unless((string) $examination->patient_id === (string) $patientId, 404);

        $disk = Storage::disk((string) $version->disk);

        abort_unless($disk->exists((string) $version->object_key), 404);

        abort_unless($version->verifyChecksum(), 404);

        return $version;
    }

    /**
     * Stream a previously authorised version as a private PDF. No public URL,
     * no signed link and no caching.
     */
    public function response(ReportVersion $version, bool $inline): StreamedResponse
    {
        $disk = Storage::disk((string) $version->disk);

        $stream = $disk->readStream((string) $version->object_key);

        abort_if($stream === false || $stream === null, 404);

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
