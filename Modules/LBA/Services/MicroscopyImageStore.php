<?php

namespace Modules\LBA\Services;

use App\Models\User;
use App\Platform\Tenancy\Concerns\RunsVerifiedTenantTransactions;
use DateTimeInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\LBA\Exceptions\ExaminationException;
use Modules\LBA\Models\Examination;
use Modules\LBA\Models\ExaminationImage;

/**
 * Phase 4 — private microscopy image intake (Modules/LBA).
 *
 * Images are validated by their ACTUAL content (real MIME + decodability +
 * dimensions), never by extension or client-supplied name. Accepted originals
 * are written ORIGINAL and IMMUTABLE to the private `lba` disk under a random,
 * opaque object key with a recorded SHA-256 checksum. Nothing is ever written
 * to `public/`, and no public URL is ever produced — images are only read back
 * through the authenticated streaming endpoint.
 *
 * WS6.1 hardening — intake is now IDEMPOTENT and RACE-SAFE on every path:
 *
 *  - the file is validated (size / MIME / decodability / dimensions) BEFORE any
 *    checksum is taken, so the in-memory read is bounded by the configured size
 *    cap and a malformed or oversized payload is refused without buffering it;
 *  - two uploads of identical content to the SAME examination resolve to the
 *    one immutable original — the second returns the existing row, creates no
 *    new record and writes no new object;
 *  - concurrent uploads are serialised by a row lock on the examination, and a
 *    lost race that still reaches the database is absorbed by the additive
 *    unique index on (examination_id, checksum): the orphan object is discarded
 *    and the winner's original is returned. An original is NEVER overwritten.
 */
final class MicroscopyImageStore
{
    use RunsVerifiedTenantTransactions;

    public function __construct(private readonly ExaminationWorkflow $workflow) {}

    /**
     * @param  array<string, mixed>  $origin  Non-clinical provenance for the still:
     *                                        `source` (one of {@see ExaminationImage::SOURCES}),
     *                                        `captured_at` (DateTimeInterface|null) and
     *                                        `instrument_label` (string|null). Unknown values
     *                                        fall back to a manual import.
     */
    public function store(Examination $examination, UploadedFile $file, ?User $actor = null, array $origin = []): ExaminationImage
    {
        return $this->storeIdempotent($examination, $file, $actor, $origin)['image'];
    }

    /**
     * Idempotent, race-safe intake.
     *
     * Same contract as {@see self::store()} plus a `duplicate` flag, so a caller
     * (e.g. the scoped agent ingest endpoint) can answer `200` for a repeat and
     * `201` for a genuinely new original without ever creating a second row for
     * identical content on the same examination.
     *
     * @param  array<string, mixed>  $origin
     * @return array{image: ExaminationImage, duplicate: bool}
     */
    public function storeIdempotent(Examination $examination, UploadedFile $file, ?User $actor = null, array $origin = []): array
    {
        $this->assertAcceptsImages($examination);

        [$mime, $width, $height, $extension] = $this->inspect($file);

        // The size cap in `inspect()` has already been enforced, so this read is
        // bounded by `lba.images.max_kilobytes` — we never buffer an unbounded
        // upload into PHP memory.
        $contents = (string) file_get_contents((string) $file->getRealPath());
        $checksum = hash('sha256', $contents);

        return $this->tenantTransaction(function () use ($examination, $file, $actor, $mime, $width, $height, $extension, $contents, $checksum, $origin): array {
            // Serialise concurrent uploads to the SAME examination with a row
            // lock (a real lock on MySQL; a no-op on SQLite) so the
            // check-then-insert below is atomic rather than a lost race.
            Examination::query()->whereKey($examination->getKey())->lockForUpdate()->first();

            $existing = $this->existingFor($examination, $checksum);

            if ($existing instanceof ExaminationImage) {
                return ['image' => $existing, 'duplicate' => true];
            }

            $disk = (string) config('lba.images.disk');

            $objectKey = sprintf(
                'examinations/%s/%s.%s',
                $examination->getKey(),
                (string) Str::ulid(),
                $extension,
            );

            Storage::disk($disk)->put($objectKey, $contents, ['visibility' => 'private']);

            try {
                $image = ExaminationImage::query()->create([
                    'examination_id' => $examination->getKey(),
                    'disk' => $disk,
                    'object_key' => $objectKey,
                    'original_name' => $file->getClientOriginalName(),
                    'checksum' => $checksum,
                    'mime_type' => $mime,
                    'width' => $width,
                    'height' => $height,
                    'size_bytes' => strlen($contents),
                    'uploaded_by' => $actor?->getKey(),
                    'source' => $this->normaliseSource($origin['source'] ?? null),
                    'captured_at' => $this->normaliseCapturedAt($origin['captured_at'] ?? null),
                    'instrument_label' => $this->normaliseInstrumentLabel($origin['instrument_label'] ?? null),
                ]);
            } catch (UniqueConstraintViolationException $exception) {
                // A concurrent request committed the same (examination, checksum)
                // first. Discard our now-orphan object and return theirs — never
                // a second immutable record and never an overwrite.
                Storage::disk($disk)->delete($objectKey);

                $winner = $this->existingFor($examination, $checksum);

                if ($winner instanceof ExaminationImage) {
                    return ['image' => $winner, 'duplicate' => true];
                }

                throw $exception;
            }

            if ($examination->status === Examination::STATUS_DRAFT) {
                $this->workflow->transition($examination, Examination::STATUS_UPLOADED, $actor);
            }

            return ['image' => $image, 'duplicate' => false];
        });
    }

    /**
     * The admission gates every intake path must pass. Exposed so a caller can
     * re-check them BEFORE a duplicate lookup — a repeat upload must never
     * bypass editability, a processable mode or a currently-granted
     * image-processing consent.
     */
    public function assertAcceptsImages(Examination $examination): void
    {
        if (! $examination->isEditable()) {
            throw ExaminationException::notEditable((string) $examination->status);
        }

        if (! in_array($examination->mode, Examination::PROCESSABLE_MODES, true)) {
            throw ExaminationException::modeNotProcessable((string) $examination->mode);
        }

        if (! $examination->hasGrantedImageProcessingConsent()) {
            throw ExaminationException::consentRequired();
        }
    }

    /**
     * The immutable original already stored for this examination + checksum, if
     * any. Scoped to the examination, so a checksum can never resolve across
     * examinations or tenants.
     */
    public function existingFor(Examination $examination, string $checksum): ?ExaminationImage
    {
        return ExaminationImage::query()
            ->where('examination_id', $examination->getKey())
            ->where('checksum', $checksum)
            ->first();
    }

    /**
     * Validate an uploaded file by its real content WITHOUT storing anything.
     *
     * @return array{0: string, 1: int, 2: int, 3: string}
     */
    public function validate(UploadedFile $file): array
    {
        return $this->inspect($file);
    }

    /**
     * Constrain the origin onto a known source; anything else is treated as a
     * manual import, so an unknown value can never invent a new origin.
     */
    private function normaliseSource(mixed $source): string
    {
        return is_string($source) && in_array($source, ExaminationImage::SOURCES, true)
            ? $source
            : ExaminationImage::SOURCE_MANUAL_IMPORT;
    }

    /**
     * A capture time is only ever a real instant supplied server-side; an
     * arbitrary value is discarded rather than parsed.
     */
    private function normaliseCapturedAt(mixed $value): ?Carbon
    {
        return $value instanceof DateTimeInterface ? Carbon::instance($value) : null;
    }

    /**
     * A device/instrument label is untrusted client input used for display
     * only. Trim it, discard blanks and bound its length so it cannot be used
     * to smuggle PHI or oversized payloads into the workspace.
     */
    private function normaliseInstrumentLabel(mixed $label): ?string
    {
        if (! is_string($label)) {
            return null;
        }

        $label = trim($label);

        return $label === '' ? null : Str::limit($label, 120, '');
    }

    /**
     * Destructive deletion of an original microscopy image is DISABLED.
     *
     * An original is an immutable clinical record and annotations reference its
     * row; physically removing either would destroy evidence and could cascade
     * away existing annotations. Until an approved retention/archive policy
     * exists, this fails closed instead of deleting anything.
     */
    public function delete(Examination $examination, ExaminationImage $image): void
    {
        if ((string) $image->examination_id !== (string) $examination->getKey()) {
            throw ExaminationException::imageNotOnExamination();
        }

        throw ExaminationException::imageDeletionDisabled();
    }

    /**
     * Validate the uploaded file by its real content.
     *
     * @return array{0: string, 1: int, 2: int, 3: string}
     */
    private function inspect(UploadedFile $file): array
    {
        $limits = (array) config('lba.images');

        if (! $file->isValid()) {
            throw ExaminationException::invalidFile('the transfer did not complete');
        }

        $maxBytes = (int) ($limits['max_kilobytes'] ?? 20480) * 1024;

        if ($file->getSize() > $maxBytes) {
            throw ExaminationException::invalidFile('the file is larger than the configured limit');
        }

        $realPath = (string) $file->getRealPath();

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($realPath);

        if ($mime === '' || ! in_array($mime, (array) ($limits['allowed_mimes'] ?? []), true)) {
            throw ExaminationException::invalidFile('only JPEG, PNG or WebP images are accepted');
        }

        $extension = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => throw ExaminationException::invalidFile('unsupported image type'),
        };

        $dimensions = @getimagesize($realPath);

        if ($dimensions === false || $dimensions[0] <= 0 || $dimensions[1] <= 0) {
            throw ExaminationException::invalidFile('the file is not a decodable image');
        }

        $width = (int) $dimensions[0];
        $height = (int) $dimensions[1];

        // A guard against decompression bombs: a tiny file can decode to an
        // enormous bitmap.
        if ($width * $height > (int) ($limits['max_pixels'] ?? 40_000_000)) {
            throw ExaminationException::invalidFile('the image dimensions exceed the configured limit');
        }

        return [$mime, $width, $height, $extension];
    }
}
